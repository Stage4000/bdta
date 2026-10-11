<?php
require_once __DIR__ . '/invoice_status.php';
require_once __DIR__ . '/stripe_config.php';

/** @return array{refunded_total: float, remaining_amount: float, status: string} */
function bdta_refund_operation_summary(PDO $conn, int $invoice_id): array
{
    $invoice = bdta_invoice_fetch_row($conn, $invoice_id);
    $payments = bdta_invoice_get_payment_summary($conn, $invoice);
    $total = bdta_invoice_get_refunded_total($conn, $invoice_id);
    return ['refunded_total' => $total, 'remaining_amount' => bdta_invoice_get_net_amount($invoice, $total, safe_float($payments['paid_total'])), 'status' => array_string_value($invoice, 'status')];
}

/** @return array<string, mixed> */
function bdta_invoice_pending_refund(PDO $conn, int $invoice_id): array
{
    $stmt = $conn->prepare('SELECT * FROM invoice_refund_operations WHERE invoice_id = ? AND completed_at IS NULL ORDER BY created_at LIMIT 1');
    $stmt->execute([$invoice_id]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($pending) ? $pending : [];
}

/**
 * Serialize this invoice across durable intent, provider call and local completion.
 * The advisory lock survives commits and is released automatically on disconnect.
 * Never wrap this function in a caller transaction: intent must survive a crash.
 *
 * @return array{refunded_total: float, remaining_amount: float, status: string}
 */
function bdta_refund_invoice(PDO $conn, int $invoice_id, string $operation_key, float $amount, string $refund_date, string $note = ''): array
{
    if ($conn->inTransaction()) throw new RuntimeException('Refund requires its own durable operation.');
    $amount = round($amount, 2);
    $note = trim($note);
    if (preg_match('/^[a-f0-9]{64}$/', $operation_key) !== 1) throw new RuntimeException('Reload the invoice before requesting a refund.');
    if ($amount <= 0 || !is_finite($amount)) throw new RuntimeException('Refund amount must be greater than zero.');
    if (!bdta_invoice_is_valid_date_string($refund_date)) throw new RuntimeException('Please enter a valid refund date.');
    $schema_stmt = $conn->query('SELECT DATABASE()');
    if ($schema_stmt === false) throw new RuntimeException('Unable to identify refund database.');
    $schema = scalar_string($schema_stmt->fetchColumn());
    $lock_name = 'bdta-refund-' . hash('sha256', $schema . ':' . $invoice_id);
    $lock_name = substr($lock_name, 0, 64);
    $lock = $conn->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lock_name]);
    if (safe_int($lock->fetchColumn()) !== 1) throw new RuntimeException('Another refund is in progress. Retry the same request.');
    try {
        $invoice = bdta_invoice_fetch_row($conn, $invoice_id);
        if ($invoice === []) throw new RuntimeException('Invoice not found.');
        $stmt = $conn->prepare('SELECT * FROM invoice_refund_operations WHERE operation_key = ?');
        $stmt->execute([$operation_key]);
        $operation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($operation)) {
            if (safe_int($operation['invoice_id']) !== $invoice_id || safe_float($operation['amount']) !== $amount
                || array_string_value($operation, 'refund_date') !== $refund_date || array_string_value($operation, 'notes') !== $note) {
                throw new RuntimeException('Refund retry details changed. Reconcile the original request first.');
            }
            if (array_string_value($operation, 'completed_at') !== '') return bdta_refund_operation_summary($conn, $invoice_id);
        } else {
            $pending = $conn->prepare('SELECT operation_key FROM invoice_refund_operations WHERE invoice_id = ? AND completed_at IS NULL LIMIT 1');
            $pending->execute([$invoice_id]);
            if ($pending->fetchColumn() !== false) throw new RuntimeException('An earlier refund needs reconciliation. Retry its original request.');
            $payments = bdta_invoice_get_payment_summary($conn, $invoice);
            $paid = safe_float($payments['paid_total']);
            $refunded = bdta_invoice_get_refunded_total($conn, $invoice_id);
            if (!bdta_invoice_can_refund($invoice, $refunded, $paid)) throw new RuntimeException('This invoice cannot be refunded.');
            if ($amount > bdta_invoice_get_net_amount($invoice, $refunded, $paid)) throw new RuntimeException('Refund amount cannot exceed the remaining paid balance.');
            $conn->prepare('INSERT INTO invoice_refund_operations (operation_key, invoice_id, amount, refund_date, refund_method, notes, payment_intent_id, invoice_number, provider_key_hash, currency) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$operation_key, $invoice_id, $amount, $refund_date, array_string_value($invoice, 'payment_method', 'other'), $note, array_string_value($invoice, 'stripe_payment_intent_id'), array_string_value($invoice, 'invoice_number'), hash('sha256', scalar_string(STRIPE_SECRET_KEY)), scalar_string(STRIPE_CURRENCY)]);
            $stmt->execute([$operation_key]);
            $operation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($operation)) throw new RuntimeException('Unable to persist refund operation.');
        }
        $refund_id = array_string_value($operation, 'stripe_refund_id');
        $payment_intent = array_string_value($operation, 'payment_intent_id');
        if ($payment_intent !== '' && $refund_id === '') {
            if (!hash_equals(array_string_value($operation, 'provider_key_hash'), hash('sha256', scalar_string(STRIPE_SECRET_KEY)))
                || array_string_value($operation, 'currency') !== scalar_string(STRIPE_CURRENCY)) {
                throw new RuntimeException('Stripe configuration changed; manual refund reconciliation required.');
            }
            $attempt_at = safe_int($operation['first_attempt_at'] ?? 0);
            if ($attempt_at > 0 && time() - $attempt_at >= 23 * 3600) {
                $result = findStripeRefundForOperation($payment_intent, $operation_key, $amount, array_string_value($operation, 'currency'));
            } else {
                if ($attempt_at === 0) {
                    // Autocommit BEFORE contacting Stripe, even if the response is lost.
                    $conn->prepare('UPDATE invoice_refund_operations SET first_attempt_at = ? WHERE operation_key = ?')->execute([time(), $operation_key]);
                }
                $result = createStripeRefund($payment_intent, $amount, ['invoice_id' => $invoice_id, 'invoice_number' => array_string_value($operation, 'invoice_number'), 'bdta_refund_operation' => $operation_key], 'bdta-refund-' . $operation_key);
            }
            if (!($result['success'] ?? false)) throw new RuntimeException('Refund unresolved: ' . array_string_value($result, 'error', 'manual reconciliation required'));
            $refund_id = array_string_value($result, 'refund_id');
            if ($refund_id === '') throw new RuntimeException('Refund response missing identity; retry the original operation.');
            // Retain provider identity even if the following ledger transaction fails.
            $conn->prepare('UPDATE invoice_refund_operations SET stripe_refund_id = ? WHERE operation_key = ?')->execute([$refund_id, $operation_key]);
        }
        $conn->beginTransaction();
        try {
            $result = bdta_record_invoice_refund($conn, $invoice_id, $amount, $refund_date, array_string_value($operation, 'refund_method'), $note, $refund_id !== '' ? $refund_id : null);
            $conn->prepare('UPDATE invoice_refund_operations SET completed_at = CURRENT_TIMESTAMP WHERE operation_key = ?')->execute([$operation_key]);
            $conn->commit();
            return $result;
        } catch (Throwable $e) {
            // PDO transaction state can change during commit failure.
            // @phpstan-ignore if.alwaysFalse
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    } finally {
        $conn->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock_name]);
    }
}
