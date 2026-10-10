<?php
require_once __DIR__ . '/invoice_status.php';

/** @return array<string, mixed> */
function bdta_invoice_lock_for_payment(PDO $conn, int $invoice_id): array
{
    if (!$conn->inTransaction()) {
        throw new LogicException('Invoice payment requires a transaction.');
    }
    // SQLite is used only by isolated fixtures. A write takes its database lock.
    if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $conn->prepare('UPDATE invoices SET id = id WHERE id = ?')->execute([$invoice_id]);
    }
    $sql = 'SELECT * FROM invoices WHERE id = ?';
    if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute([$invoice_id]);
    $invoice = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($invoice === []) {
        throw new RuntimeException('Invoice not found.');
    }
    return $invoice;
}

/** Fulfillment and its unique per-line/unit identity commit with the payment.
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_fulfill_packages(PDO $conn, array $invoice, ?int $admin_id = null): void
{
    if (!$conn->inTransaction() || array_string_value($invoice, 'status') !== 'paid') {
        throw new LogicException('Package fulfillment requires a paid invoice transaction.');
    }
    $client_id = array_int_value($invoice, 'client_id');
    $items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? AND item_type = 'package' AND reference_id IS NOT NULL ORDER BY id");
    $items_stmt->execute([array_int_value($invoice, 'id')]);
    foreach (assoc_rows($items_stmt->fetchAll(PDO::FETCH_ASSOC)) as $item) {
        $item_id = array_int_value($item, 'id');
        $quantity = max(1, array_int_value($item, 'quantity', 1));
        $stmt = $conn->prepare('SELECT * FROM packages WHERE id = ? AND is_active = 1');
        $stmt->execute([array_int_value($item, 'reference_id')]);
        $package = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        $stmt = $conn->prepare('SELECT * FROM package_items WHERE package_id = ?');
        $stmt->execute([array_int_value($item, 'reference_id')]);
        $package_items = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($package === [] || $package_items === []) {
            throw new RuntimeException('Invoice package is unavailable or has no credits. Please contact an administrator.');
        }
        for ($unit = 1; $unit <= $quantity; $unit++) {
            $stmt = $conn->prepare('SELECT client_package_id FROM invoice_package_fulfillments WHERE invoice_item_id = ? AND unit_number = ?');
            $stmt->execute([$item_id, $unit]);
            if ($stmt->fetchColumn() !== false) {
                continue;
            }
            $expiration_days = array_int_value($package, 'expiration_days');
            $expires_at = $expiration_days > 0 ? date('Y-m-d H:i:s', safe_timestamp(strtotime('+' . $expiration_days . ' days'))) : null;
            $package_name = array_string_value($package, 'name');
            $conn->prepare('INSERT INTO client_packages (client_id, package_id, package_name, expires_at, is_active, notes, created_by) VALUES (?, ?, ?, ?, 1, ?, ?)')
                ->execute([$client_id, array_int_value($package, 'id'), $package_name, $expires_at, 'Auto-applied from invoice payment', $admin_id]);
            $client_package_id = (int) $conn->lastInsertId();
            foreach ($package_items as $package_item) {
                $type_id = array_int_value($package_item, 'appointment_type_id');
                $credits = array_int_value($package_item, 'quantity');
                $conn->prepare('INSERT INTO client_package_credits (client_package_id, client_id, appointment_type_id, total_credits, used_credits) VALUES (?, ?, ?, ?, 0)')
                    ->execute([$client_package_id, $client_id, $type_id, $credits]);
                $credit_id = (int) $conn->lastInsertId();
                $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, notes, created_by) VALUES (?, ?, ?, 'purchase', ?, ?, ?)")
                    ->execute([$credit_id, $client_id, $type_id, $credits, "Package '{$package_name}' from invoice payment", $admin_id]);
            }
            $conn->prepare('INSERT INTO invoice_package_fulfillments (invoice_item_id, unit_number, client_package_id) VALUES (?, ?, ?)')
                ->execute([$item_id, $unit, $client_package_id]);
            bdta_create_notification($conn, 'portal', $client_id, 'package', $client_package_id, 'Package credits added',
                "Your '{$package_name}' package credits are now available.", '/portal/credits.php');
        }
    }
}

/** @param array<string, mixed> $invoice
 * @return array<string, mixed>
 */
function bdta_invoice_finish_payment(PDO $conn, array $invoice, string $method, string $date, ?int $admin_id = null, ?string $intent = null): array
{
    $summary = bdta_invoice_get_payment_summary($conn, $invoice);
    $invoice['status'] = $summary['status'];
    $invoice['payment_method'] = $method;
    $invoice['payment_date'] = $date;
    $conn->prepare('UPDATE invoices SET status = ?, payment_method = ?, payment_date = ?, stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id), updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$summary['status'], $method, $date, $intent, array_int_value($invoice, 'id')]);
    if ($intent !== null) {
        $invoice['stripe_payment_intent_id'] = $intent;
    }
    if ($summary['status'] === 'paid') {
        bdta_invoice_fulfill_packages($conn, $invoice, $admin_id);
    }
    return $invoice;
}

/** Record a verified paid provider session without treating excess as invoice income.
 * @return array{invoice: array<string, mixed>, replayed: bool, excess_cents: int, applied_cents: int}
 */
function bdta_invoice_record_checkout_payment(PDO $conn, int $invoice_id, int $client_id, int $received_cents, string $session_id, string $intent, string $currency): array
{
    if ($received_cents <= 0 || $session_id === '' || $intent === '') {
        throw new RuntimeException('Invalid confirmed payment.');
    }
    $conn->beginTransaction();
    try {
        $invoice = bdta_invoice_lock_for_payment($conn, $invoice_id);
        if (array_int_value($invoice, 'client_id') !== $client_id) {
            throw new RuntimeException('Invoice client changed. Payment requires review.');
        }
        $stmt = $conn->prepare('SELECT * FROM invoice_checkout_receipts WHERE payment_intent_id = ? OR checkout_session_id = ?');
        $stmt->execute([$intent, $session_id]);
        $receipt = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        if ($receipt !== []) {
            if (array_int_value($receipt, 'invoice_id') !== $invoice_id || array_int_value($receipt, 'received_cents') !== $received_cents
                || array_string_value($receipt, 'payment_intent_id') !== $intent || array_string_value($receipt, 'checkout_session_id') !== $session_id
                || array_string_value($receipt, 'currency') !== $currency) {
                throw new RuntimeException('Confirmed payment conflicts with its recorded receipt.');
            }
            $conn->commit();
            return ['invoice' => $invoice, 'replayed' => true, 'excess_cents' => array_int_value($receipt, 'excess_cents'), 'applied_cents' => array_int_value($receipt, 'applied_cents')];
        }
        // Keep historical provider payments stable; fulfillment cannot be safely inferred
        // for old paid invoices whose manual credits have no per-line identity.
        $stmt = $conn->prepare('SELECT invoice_id FROM invoice_payments WHERE stripe_payment_intent_id = ?');
        $stmt->execute([$intent]);
        $existing_invoice_id = safe_int($stmt->fetchColumn());
        if ($existing_invoice_id > 0) {
            if ($existing_invoice_id !== $invoice_id) {
                throw new RuntimeException('Payment was already recorded for another invoice.');
            }
            $conn->commit();
            return ['invoice' => $invoice, 'replayed' => true, 'excess_cents' => 0, 'applied_cents' => 0];
        }
        $summary = bdta_invoice_get_payment_summary($conn, $invoice);
        $balance_cents = bdta_invoice_is_payable($invoice) ? (int) round($summary['remaining_amount'] * 100) : 0;
        $applied_cents = min($received_cents, max(0, $balance_cents));
        $excess_cents = $received_cents - $applied_cents;
        $conn->prepare('INSERT INTO invoice_checkout_receipts (payment_intent_id, checkout_session_id, invoice_id, received_cents, applied_cents, excess_cents, currency) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$intent, $session_id, $invoice_id, $received_cents, $applied_cents, $excess_cents, $currency]);
        if ($applied_cents > 0) {
            $conn->prepare("INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes) VALUES (?, ?, CURRENT_DATE, 'credit_card', ?, ?)")
                ->execute([$invoice_id, $applied_cents / 100, $intent, 'Stripe Checkout session ' . $session_id]);
            $invoice = bdta_invoice_finish_payment($conn, $invoice, 'credit_card', date('Y-m-d'), null, $intent);
        }
        if ($excess_cents > 0) {
            bdta_create_admin_notifications($conn, 'invoice', $invoice_id, 'Invoice payment needs reconciliation',
                'Invoice #' . array_string_value($invoice, 'invoice_number') . ': received ' . strtoupper($currency) . ' ' . number_format($received_cents / 100, 2)
                . '; applied ' . number_format($applied_cents / 100, 2) . '; excess ' . number_format($excess_cents / 100, 2) . ' requires reconciliation. Payment ' . $intent,
                '/client/invoices_view.php?id=' . $invoice_id);
        }
        $conn->commit();
        return ['invoice' => $invoice, 'replayed' => false, 'excess_cents' => $excess_cents, 'applied_cents' => $applied_cents];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        throw $e;
    }
}

/** @return array{invoice: array<string, mixed>, installment: array<string, mixed>|null} */
function bdta_invoice_record_manual_payment(PDO $conn, int $invoice_id, float $amount, string $method, string $date, ?int $admin_id, int $installment_id = 0): array
{
    $conn->beginTransaction();
    try {
        $invoice = bdta_invoice_lock_for_payment($conn, $invoice_id);
        if (!bdta_invoice_is_payable($invoice)) {
            throw new RuntimeException('Invoice no longer accepts payment.');
        }
        $summary = bdta_invoice_get_payment_summary($conn, $invoice);
        $installment = null;
        $stmt = $conn->prepare('SELECT COUNT(*) FROM invoice_installments WHERE invoice_id = ?');
        $stmt->execute([$invoice_id]);
        if ($installment_id > 0) {
            $stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE id = ? AND invoice_id = ? AND status = 'unpaid'");
            $stmt->execute([$installment_id, $invoice_id]);
            $installment = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
            if ($installment === []) { throw new RuntimeException('Installment not found or already paid.'); }
            $amount = round(safe_float($installment['amount'] ?? 0), 2);
        } elseif ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Use the installment payment actions for this invoice.');
        }
        $amount_cents = (int) round($amount * 100);
        $remaining_cents = (int) round($summary['remaining_amount'] * 100);
        if ($amount_cents <= 0 || $amount_cents > $remaining_cents) {
            throw new RuntimeException('Payment amount must be positive and cannot exceed the current remaining balance.');
        }
        if ($installment !== null) {
            $conn->prepare("UPDATE invoice_installments SET status = 'paid', payment_method = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'unpaid'")
                ->execute([$method, $date, $installment_id]);
            $installment['status'] = 'paid';
            $installment['payment_method'] = $method;
            $installment['payment_date'] = $date;
        } else {
            $conn->prepare('INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method) VALUES (?, ?, ?, ?)')
                ->execute([$invoice_id, $amount_cents / 100, $date, $method]);
        }
        $invoice = bdta_invoice_finish_payment($conn, $invoice, $method, $date, $admin_id);
        $conn->commit();
        return ['invoice' => $invoice, 'installment' => $installment];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        throw $e;
    }
}
