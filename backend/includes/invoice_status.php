<?php

/**
 * @return array<string, string>
 */
function bdta_invoice_status_colors(): array
{
    return [
        'draft' => 'secondary',
        'sent' => 'info',
        'paid' => 'success',
        'overdue' => 'danger',
        'cancelled' => 'dark',
        'void' => 'dark',
        'refunded' => 'warning',
    ];
}

function bdta_invoice_status_color(string $status): string
{
    $status = strtolower(trim($status));
    $colors = bdta_invoice_status_colors();

    return $colors[$status] ?? 'secondary';
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_is_payable(array $invoice): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    return !in_array($status, ['paid', 'refunded', 'cancelled', 'void'], true);
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_can_void(array $invoice): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    return !in_array($status, ['paid', 'refunded', 'cancelled', 'void'], true);
}

function bdta_invoice_get_refunded_total(PDO $conn, int $invoice_id): float
{
    $stmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) FROM invoice_refunds WHERE invoice_id = ?');
    $stmt->execute([$invoice_id]);

    return safe_float($stmt->fetchColumn());
}

/**
 * @return list<array<string, mixed>>
 */
function bdta_invoice_get_refunds(PDO $conn, int $invoice_id): array
{
    $stmt = $conn->prepare('SELECT * FROM invoice_refunds WHERE invoice_id = ? ORDER BY refund_date DESC, id DESC');
    $stmt->execute([$invoice_id]);

    /** @var list<array<string, mixed>> $refunds */
    $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $refunds;
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_get_net_amount(array $invoice, float $refunded_total): float
{
    return max(0, safe_float($invoice['total_amount'] ?? 0) - $refunded_total);
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_can_refund(array $invoice, float $refunded_total): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    if (!in_array($status, ['paid', 'refunded'], true)) {
        return false;
    }

    return bdta_invoice_get_net_amount($invoice, $refunded_total) > 0;
}

function bdta_void_invoice(PDO $conn, int $invoice_id, string $reason = ''): void
{
    $stmt = $conn->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($invoice)) {
        throw new RuntimeException('Only unpaid invoices can be voided.');
    }
    /** @var array<string, mixed> $invoice */

    if (!bdta_invoice_can_void($invoice)) {
        throw new RuntimeException('Only unpaid invoices can be voided.');
    }

    $reason = trim($reason);
    $reason_value = $reason !== '' ? $reason : null;

    $started_transaction = !$conn->inTransaction();
    if ($started_transaction) {
        $conn->beginTransaction();
    }

    try {
        $conn->prepare("
            UPDATE time_entries
            SET invoiced = 0
            WHERE id IN (
                SELECT reference_id
                FROM invoice_items
                WHERE invoice_id = ?
                  AND item_type = 'time_entry'
                  AND reference_id IS NOT NULL
            )
        ")->execute([$invoice_id]);

        $conn->prepare("
            UPDATE invoice_installments
            SET status = 'cancelled',
                updated_at = CURRENT_TIMESTAMP
            WHERE invoice_id = ?
              AND status = 'unpaid'
        ")->execute([$invoice_id]);

        $conn->prepare("
            UPDATE invoices
            SET status = 'void',
                void_reason = ?,
                voided_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$reason_value, $invoice_id]);

        if ($started_transaction) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($started_transaction && $conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $e;
    }
}

/**
 * @return array{refunded_total: float, remaining_amount: float, status: string}
 */
function bdta_record_invoice_refund(
    PDO $conn,
    int $invoice_id,
    float $amount,
    string $refund_date,
    string $refund_method,
    string $note = '',
    ?string $stripe_refund_id = null
): array {
    $stmt = $conn->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($invoice)) {
        throw new RuntimeException('Invoice not found.');
    }
    /** @var array<string, mixed> $invoice */

    $refunded_total = bdta_invoice_get_refunded_total($conn, $invoice_id);
    if (!bdta_invoice_can_refund($invoice, $refunded_total)) {
        throw new RuntimeException('This invoice cannot be refunded.');
    }

    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Refund amount must be greater than zero.');
    }

    $remaining_amount = bdta_invoice_get_net_amount($invoice, $refunded_total);
    if ($amount > $remaining_amount) {
        throw new RuntimeException('Refund amount cannot exceed the remaining paid balance.');
    }

    $refund_method = trim($refund_method);
    if ($refund_method === '') {
        $refund_method = array_string_value($invoice, 'payment_method', 'other');
    }

    $note = trim($note);
    $stripe_refund_id = $stripe_refund_id !== null && trim($stripe_refund_id) !== ''
        ? trim($stripe_refund_id)
        : null;
    $note_value = $note !== '' ? $note : null;

    $started_transaction = !$conn->inTransaction();
    if ($started_transaction) {
        $conn->beginTransaction();
    }

    try {
        $conn->prepare("
            INSERT INTO invoice_refunds (invoice_id, amount, refund_date, refund_method, stripe_refund_id, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$invoice_id, $amount, $refund_date, $refund_method, $stripe_refund_id, $note_value]);

        $updated_total = bdta_invoice_get_refunded_total($conn, $invoice_id);
        $new_status = $updated_total >= safe_float($invoice['total_amount'] ?? 0) ? 'refunded' : 'paid';
        $remaining_amount = max(0, safe_float($invoice['total_amount'] ?? 0) - $updated_total);

        $conn->prepare("
            UPDATE invoices
            SET status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$new_status, $invoice_id]);

        if ($started_transaction) {
            $conn->commit();
        }

        return [
            'refunded_total' => $updated_total,
            'remaining_amount' => $remaining_amount,
            'status' => $new_status,
        ];
    } catch (Throwable $e) {
        if ($started_transaction && $conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $e;
    }
}
