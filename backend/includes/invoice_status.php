<?php

/**
 * @return array<string, string>
 */
function bdta_invoice_status_colors(): array
{
    return [
        'draft' => 'secondary',
        'sent' => 'info',
        'partial' => 'warning',
        'paid' => 'success',
        'settled' => 'primary',
        'overdue' => 'danger',
        'cancelled' => 'dark',
        'void' => 'dark',
        'refunded' => 'warning',
    ];
}

/**
 * @return list<string>
 */
function bdta_invoice_closed_statuses(): array
{
    return ['paid', 'settled', 'refunded', 'cancelled', 'void'];
}

/**
 * @return list<string>
 */
function bdta_invoice_receipt_statuses(): array
{
    return ['paid', 'settled', 'refunded'];
}

function bdta_invoice_status_closes_balance(string $status): bool
{
    return in_array(strtolower(trim($status)), bdta_invoice_closed_statuses(), true);
}

function bdta_invoice_status_color(string $status): string
{
    $status = strtolower(trim($status));
    $colors = bdta_invoice_status_colors();

    return $colors[$status] ?? 'secondary';
}

if (!function_exists('array_string_value')) {
    function array_string_value(array $row, string|int $key, string $default = ''): string
    {
        return scalar_string($row[$key] ?? $default);
    }
}

const BDTA_INVOICE_PAY_TOKEN_BYTES = 32;

/**
 * @param array<string, mixed> $invoice
 * @return array{paid_total: float, remaining_amount: float, status: string}
 */
function bdta_invoice_calculate_payment_progress(array $invoice, float $paid_total): array
{
    $total_amount = round(max(0, safe_float($invoice['total_amount'] ?? 0)), 2);
    $paid_total = round(max(0, min($total_amount, $paid_total)), 2);
    $remaining_amount = round(max(0, $total_amount - $paid_total), 2);
    $current_status = strtolower(trim(scalar_string($invoice['status'] ?? 'draft')));

    if ($remaining_amount <= 0.0 && $total_amount > 0) {
        $status = 'paid';
    } elseif ($paid_total > 0) {
        $status = 'partial';
    } else {
        $status = $current_status !== '' ? $current_status : 'draft';
    }

    return [
        'paid_total' => $paid_total,
        'remaining_amount' => $remaining_amount,
        'status' => $status,
    ];
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_is_payable(array $invoice): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    return !bdta_invoice_status_closes_balance($status);
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_can_void(array $invoice): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    return !in_array($status, [...bdta_invoice_closed_statuses(), 'partial'], true);
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_can_adjust_due_date(array $invoice): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    return in_array($status, ['draft', 'sent', 'partial', 'overdue'], true);
}

function bdta_generate_invoice_pay_token(int $bytes = BDTA_INVOICE_PAY_TOKEN_BYTES): string
{
    if ($bytes < 1) {
        $bytes = BDTA_INVOICE_PAY_TOKEN_BYTES;
    }

    return bin2hex(random_bytes($bytes));
}

function bdta_ensure_invoice_pay_token(PDO $conn, int $invoice_id, mixed $existing_token = null): string
{
    if ($invoice_id <= 0) {
        return '';
    }

    $token = trim(scalar_string($existing_token));
    if ($token !== '') {
        return $token;
    }

    while (true) {
        $token = bdta_generate_invoice_pay_token();

        $check_stmt = $conn->prepare('SELECT COUNT(*) FROM invoices WHERE pay_token = ?');
        $check_stmt->execute([$token]);
        if ((int) $check_stmt->fetchColumn() > 0) {
            continue;
        }

        $update_stmt = $conn->prepare("
            UPDATE invoices
            SET pay_token = ?
            WHERE id = ?
              AND COALESCE(NULLIF(pay_token, ''), '') = ''
        ");
        $update_stmt->execute([$token, $invoice_id]);

        if ($update_stmt->rowCount() > 0) {
            return $token;
        }

        $existing_stmt = $conn->prepare('SELECT pay_token FROM invoices WHERE id = ?');
        $existing_stmt->execute([$invoice_id]);
        $existing = trim(scalar_string($existing_stmt->fetchColumn()));
        if ($existing !== '') {
            return $existing;
        }
    }
}

function bdta_get_public_invoice_pay_url(PDO $conn, int $invoice_id, mixed $existing_token = null, ?string $base_url = null): string
{
    $token = bdta_ensure_invoice_pay_token($conn, $invoice_id, $existing_token);
    if ($token === '') {
        $path = '/portal/invoice_view.php?id=' . rawurlencode((string) $invoice_id);
    } else {
        $path = '/portal/invoice_pay.php?token=' . urlencode($token);
    }

    $base = trim((string) $base_url);
    return $base === '' ? $path : rtrim($base, '/') . $path;
}

function bdta_get_public_invoice_checkout_url(PDO $conn, int $invoice_id, mixed $existing_token = null, ?string $base_url = null): string
{
    $token = bdta_ensure_invoice_pay_token($conn, $invoice_id, $existing_token);
    if ($token === '') {
        $path = '/portal/invoice_checkout.php?id=' . rawurlencode((string) $invoice_id);
    } else {
        $path = '/portal/invoice_checkout.php?token=' . urlencode($token);
    }

    $base = trim((string) $base_url);
    return $base === '' ? $path : rtrim($base, '/') . $path;
}

function bdta_invoice_is_valid_date_string(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    [$year, $month, $day] = array_pad(array_map('intval', explode('-', $value)), 3, 0);
    return checkdate($month, $day, $year);
}

/**
 * @return array<string, mixed>
 */
function bdta_invoice_fetch_row(PDO $conn, int $invoice_id): array
{
    if ($invoice_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($invoice) ? $invoice : [];
}

/**
 * @return array{payment_date: string, payment_method: string}
 */
function bdta_invoice_get_latest_payment_details(PDO $conn, int $invoice_id): array
{
    if ($invoice_id <= 0) {
        return ['payment_date' => '', 'payment_method' => ''];
    }

    $stmt = $conn->prepare("
        SELECT payment_date, payment_method
        FROM (
            SELECT payment_date, payment_method
            FROM invoice_payments
            WHERE invoice_id = ?
              AND TRIM(COALESCE(payment_date, '')) <> ''

            UNION ALL

            SELECT payment_date, payment_method
            FROM invoice_installments
            WHERE invoice_id = ?
              AND status = 'paid'
              AND TRIM(COALESCE(payment_date, '')) <> ''
        ) payment_events
        ORDER BY payment_date DESC
        LIMIT 1
    ");
    $stmt->execute([$invoice_id, $invoice_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return ['payment_date' => '', 'payment_method' => ''];
    }

    return [
        'payment_date' => array_string_value($row, 'payment_date'),
        'payment_method' => array_string_value($row, 'payment_method'),
    ];
}

/**
 * @return array<string, mixed>
 */
function bdta_update_invoice_due_date(PDO $conn, int $invoice_id, string $due_date): array
{
    $due_date = trim($due_date);
    if (!bdta_invoice_is_valid_date_string($due_date)) {
        throw new RuntimeException('Please enter a valid due date.');
    }

    $invoice = bdta_invoice_fetch_row($conn, $invoice_id);
    if ($invoice === []) {
        throw new RuntimeException('Invoice not found.');
    }

    if (!bdta_invoice_can_adjust_due_date($invoice)) {
        throw new RuntimeException('Only draft, sent, partial, or overdue invoices can have their due date updated.');
    }

    $conn->prepare("
        UPDATE invoices
        SET due_date = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$due_date, $invoice_id]);

    return bdta_invoice_fetch_row($conn, $invoice_id);
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
function bdta_invoice_get_net_amount(array $invoice, float $refunded_total, ?float $base_amount = null): float
{
    $amount = $base_amount ?? safe_float($invoice['total_amount'] ?? 0);

    return round(max(0, $amount - $refunded_total), 2);
}

function bdta_invoice_get_recorded_payment_total(PDO $conn, int $invoice_id): float
{
    $stmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ?');
    $stmt->execute([$invoice_id]);

    return round(safe_float($stmt->fetchColumn()), 2);
}

/**
 * @return list<array<string, mixed>>
 */
function bdta_invoice_get_payments(PDO $conn, int $invoice_id): array
{
    $stmt = $conn->prepare('SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY payment_date DESC, id DESC');
    $stmt->execute([$invoice_id]);

    /** @var list<array<string, mixed>> $payments */
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $payments;
}

function bdta_invoice_income_event_union_sql(): string
{
    return "
        SELECT
            ip.invoice_id AS invoice_id,
            ip.payment_date AS payment_date,
            ip.amount AS amount,
            ip.payment_method AS payment_method,
            'payment' AS source
        FROM invoice_payments ip
        WHERE TRIM(COALESCE(ip.payment_date, '')) <> ''
          AND ip.payment_date BETWEEN ? AND ?

        UNION ALL

        SELECT
            ii.invoice_id AS invoice_id,
            ii.payment_date AS payment_date,
            ii.amount AS amount,
            ii.payment_method AS payment_method,
            'installment' AS source
        FROM invoice_installments ii
        WHERE ii.status = 'paid'
          AND TRIM(COALESCE(ii.payment_date, '')) <> ''
          AND ii.payment_date BETWEEN ? AND ?

        UNION ALL

        SELECT
            i.id AS invoice_id,
            i.payment_date AS payment_date,
            i.total_amount AS amount,
            i.payment_method AS payment_method,
            'invoice' AS source
        FROM invoices i
        WHERE TRIM(COALESCE(i.payment_date, '')) <> ''
          AND TRIM(COALESCE(i.payment_method, '')) <> ''
          AND i.payment_date BETWEEN ? AND ?
          AND i.status NOT IN ('draft', 'sent', 'overdue', 'cancelled', 'void')
          AND NOT EXISTS (
              SELECT 1
              FROM invoice_payments ip
              WHERE ip.invoice_id = i.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM invoice_installments ii
              WHERE ii.invoice_id = i.id
                AND ii.status = 'paid'
                AND TRIM(COALESCE(ii.payment_date, '')) <> ''
          )
    ";
}

/**
 * @return list<array{invoice_id: int, payment_date: string, amount: float, payment_method: string, source: string}>
 */
function bdta_invoice_get_income_events(PDO $conn, string $start_date, string $end_date): array
{
    $stmt = $conn->prepare("
        SELECT invoice_id, payment_date, amount, payment_method, source
        FROM (" . bdta_invoice_income_event_union_sql() . ") income_events
        ORDER BY payment_date ASC, invoice_id ASC, source ASC
    ");
    $stmt->execute([
        $start_date,
        $end_date,
        $start_date,
        $end_date,
        $start_date,
        $end_date,
    ]);

    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $events = [];

    foreach ($rows as $row) {
        $events[] = [
            'invoice_id' => safe_int($row['invoice_id'] ?? 0),
            'payment_date' => scalar_string($row['payment_date'] ?? ''),
            'amount' => round(safe_float($row['amount'] ?? 0), 2),
            'payment_method' => scalar_string($row['payment_method'] ?? ''),
            'source' => scalar_string($row['source'] ?? ''),
        ];
    }

    return $events;
}

/**
 * @param array<string, mixed> $invoice
 * @param list<array<string, mixed>>|null $installments
 * @return array{paid_total: float, remaining_amount: float, uncollected_amount: float, closed_balance_amount: float, status: string}
 */
function bdta_invoice_get_payment_summary(PDO $conn, array $invoice, ?array $installments = null): array
{
    $invoice_id = safe_int($invoice['id'] ?? 0);
    if ($invoice_id <= 0) {
        $empty_summary = bdta_invoice_calculate_payment_progress($invoice, 0);

        return [
            'paid_total' => $empty_summary['paid_total'],
            'remaining_amount' => $empty_summary['remaining_amount'],
            'uncollected_amount' => $empty_summary['remaining_amount'],
            'closed_balance_amount' => 0.0,
            'status' => $empty_summary['status'],
        ];
    }

    $recorded_total = bdta_invoice_get_recorded_payment_total($conn, $invoice_id);
    $installment_total = 0.0;

    if ($installments !== null) {
        foreach ($installments as $installment) {
            if (strtolower(trim(scalar_string($installment['status'] ?? ''))) === 'paid') {
                $installment_total += safe_float($installment['amount'] ?? 0);
            }
        }
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoice_installments WHERE invoice_id = ? AND status = 'paid'");
        $stmt->execute([$invoice_id]);
        $installment_total = safe_float($stmt->fetchColumn());
    }

    $summary = bdta_invoice_calculate_payment_progress($invoice, $recorded_total + $installment_total);
    $status = strtolower(trim(scalar_string($invoice['status'] ?? '')));

    if ($summary['paid_total'] <= 0.0 && in_array($status, ['paid', 'refunded'], true)) {
        $summary = bdta_invoice_calculate_payment_progress($invoice, safe_float($invoice['total_amount'] ?? 0));
    }

    $effective_status = $summary['status'];
    if ($status !== '' && bdta_invoice_status_closes_balance($status)) {
        $effective_status = $status;
    }

    $uncollected_amount = round(max(0, safe_float($summary['remaining_amount'] ?? 0)), 2);
    $remaining_amount = $uncollected_amount;
    $closed_balance_amount = 0.0;

    if (in_array($effective_status, ['paid', 'settled'], true) && $uncollected_amount > 0.0) {
        $closed_balance_amount = $uncollected_amount;
        $remaining_amount = 0.0;
    } elseif (in_array($effective_status, ['refunded', 'cancelled', 'void'], true)) {
        $remaining_amount = 0.0;
    }

    return [
        'paid_total' => round(max(0, safe_float($summary['paid_total'] ?? 0)), 2),
        'remaining_amount' => $remaining_amount,
        'uncollected_amount' => $uncollected_amount,
        'closed_balance_amount' => $closed_balance_amount,
        'status' => $effective_status,
    ];
}

/**
 * @return array{paid_total: float, remaining_amount: float, uncollected_amount: float, closed_balance_amount: float, status: string}
 */
function bdta_close_invoice_at_current_amount(PDO $conn, int $invoice_id, string $target_status): array
{
    $target_status = strtolower(trim($target_status));
    if (!in_array($target_status, ['paid', 'settled'], true)) {
        throw new RuntimeException('Invalid invoice closeout status.');
    }

    $invoice = bdta_invoice_fetch_row($conn, $invoice_id);
    if ($invoice === []) {
        throw new RuntimeException('Invoice not found.');
    }

    if (!bdta_invoice_is_payable($invoice)) {
        throw new RuntimeException('Only open invoices can be closed at the current amount.');
    }

    $summary = bdta_invoice_get_payment_summary($conn, $invoice);
    if (safe_float($summary['paid_total']) <= 0.0) {
        throw new RuntimeException('Only invoices with recorded partial payments can be closed at the current amount.');
    }

    $conn->prepare("
        UPDATE invoice_installments
        SET status = 'cancelled'
        WHERE invoice_id = ?
          AND status = 'unpaid'
    ")->execute([$invoice_id]);

    $latest_payment = bdta_invoice_get_latest_payment_details($conn, $invoice_id);
    $payment_method = array_string_value($invoice, 'payment_method');
    $payment_date = array_string_value($invoice, 'payment_date');

    if ($payment_method === '') {
        $payment_method = $latest_payment['payment_method'];
    }

    if ($payment_date === '') {
        $payment_date = $latest_payment['payment_date'];
    }

    $conn->prepare("
        UPDATE invoices
        SET status = ?,
            payment_method = ?,
            payment_date = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([
        $target_status,
        $payment_method !== '' ? $payment_method : null,
        $payment_date !== '' ? $payment_date : null,
        $invoice_id,
    ]);

    return bdta_invoice_get_payment_summary($conn, bdta_invoice_fetch_row($conn, $invoice_id));
}

/**
 * @param array<string, mixed> $invoice
 */
function bdta_invoice_can_refund(array $invoice, float $refunded_total, ?float $collected_total = null): bool
{
    $status = strtolower(array_string_value($invoice, 'status', 'draft'));

    if (!in_array($status, ['paid', 'settled', 'refunded'], true)) {
        return false;
    }

    return bdta_invoice_get_net_amount($invoice, $refunded_total, $collected_total) > 0;
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
        if ($started_transaction) {
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

    $payment_summary = bdta_invoice_get_payment_summary($conn, $invoice);
    $collected_total = safe_float($payment_summary['paid_total']);
    $refunded_total = bdta_invoice_get_refunded_total($conn, $invoice_id);
    if (!bdta_invoice_can_refund($invoice, $refunded_total, $collected_total)) {
        throw new RuntimeException('This invoice cannot be refunded.');
    }

    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new RuntimeException('Refund amount must be greater than zero.');
    }

    $remaining_amount = bdta_invoice_get_net_amount($invoice, $refunded_total, $collected_total);
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
        $new_status = $updated_total >= $collected_total
            ? 'refunded'
            : (strtolower(array_string_value($invoice, 'status')) === 'settled' ? 'settled' : 'paid');
        $remaining_amount = bdta_invoice_get_net_amount($invoice, $updated_total, $collected_total);

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
        if ($started_transaction) {
            $conn->rollBack();
        }

        throw $e;
    }
}
