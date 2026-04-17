<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/invoice_status.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get parameters
$type = scalar_string($_GET['type'] ?? 'income_summary');
$start_date = scalar_string($_GET['start'] ?? date('Y-m-01'));
$end_date = scalar_string($_GET['end'] ?? date('Y-m-t'));

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    die('Invalid date format');
}

// Validate that dates are valid calendar dates
$start_parts = explode('-', $start_date);
$end_parts = explode('-', $end_date);
if (!checkdate((int)$start_parts[1], (int)$start_parts[2], (int)$start_parts[0]) || 
    !checkdate((int)$end_parts[1], (int)$end_parts[2], (int)$end_parts[0])) {
    die('Invalid date values');
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $type . '_' . $start_date . '_to_' . $end_date . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');
if ($output === false) {
    throw new RuntimeException('Unable to open CSV output stream.');
}

switch ($type) {
    case 'income_summary':
        // Income summary by date
        fputcsv($output, ['Financial Report - Income Summary']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Date', 'Number of Payments', 'Total Amount']);

        $income_events = bdta_invoice_get_income_events($conn, $start_date, $end_date);
        $summary_rows = [];
        $grand_total = 0;
        $total_payments = 0;
        foreach ($income_events as $income_event) {
            $payment_date = scalar_string($income_event['payment_date'] ?? '');
            if ($payment_date === '') {
                continue;
            }

            if (!isset($summary_rows[$payment_date])) {
                $summary_rows[$payment_date] = [
                    'count' => 0,
                    'total' => 0.0,
                ];
            }

            $event_amount = safe_float($income_event['amount'] ?? 0);
            $summary_rows[$payment_date]['count']++;
            $summary_rows[$payment_date]['total'] = round($summary_rows[$payment_date]['total'] + $event_amount, 2);
        }
        ksort($summary_rows);

        foreach ($summary_rows as $date => $row) {
            fputcsv($output, [
                $date,
                $row['count'],
                number_format(safe_float($row['total']), 2)
            ]);
            $grand_total += safe_float($row['total']);
            $total_payments += safe_int($row['count']);
        }
        
        fputcsv($output, []);
        $refund_total_stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM invoice_refunds
            WHERE refund_date BETWEEN ? AND ?
        ");
        $refund_total_stmt->execute([$start_date, $end_date]);
        $refund_total = safe_float($refund_total_stmt->fetchColumn());

        fputcsv($output, ['Total Collected', $total_payments, number_format($grand_total, 2)]);
        fputcsv($output, ['Total Refunds', '', number_format($refund_total, 2)]);
        fputcsv($output, ['Net Collected', '', number_format($grand_total - $refund_total, 2)]);
        break;

    case 'income_detail':
        // Detailed income by invoice
        fputcsv($output, ['Financial Report - Income Detail']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Invoice #', 'Client', 'Issue Date', 'Payment Date', 'Payment Method', 'Payment Amount', 'Invoice Total', 'Refunded (In Range)', 'Invoice Net (In Range)']);

        $income_events = bdta_invoice_get_income_events($conn, $start_date, $end_date);
        $invoice_details = [];
        if ($income_events !== []) {
            $invoice_ids = [];
            foreach ($income_events as $income_event) {
                $invoice_id = safe_int($income_event['invoice_id'] ?? 0);
                if ($invoice_id > 0) {
                    $invoice_ids[$invoice_id] = $invoice_id;
                }
            }

            if ($invoice_ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($invoice_ids), '?'));
                $stmt = $conn->prepare("
                    SELECT
                        i.id,
                        i.invoice_number,
                        c.name as client_name,
                        i.issue_date,
                        i.total_amount,
                        COALESCE(rt.total_refunded, 0) as refunded_total
                    FROM invoices i
                    JOIN clients c ON i.client_id = c.id
                    LEFT JOIN (
                        SELECT invoice_id, SUM(amount) as total_refunded
                        FROM invoice_refunds
                        WHERE refund_date BETWEEN ? AND ?
                        GROUP BY invoice_id
                    ) rt ON rt.invoice_id = i.id
                    WHERE i.id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$start_date, $end_date], array_values($invoice_ids)));

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $invoice_details[safe_int($row['id'] ?? 0)] = $row;
                }
            }
        }

        $grand_total = 0;
        $distinct_refunds = [];
        foreach ($income_events as $income_event) {
            $invoice_id = safe_int($income_event['invoice_id'] ?? 0);
            $invoice_detail = $invoice_details[$invoice_id] ?? [];
            $payment_amount = safe_float($income_event['amount'] ?? 0);
            $payment_method = scalar_string($income_event['payment_method'] ?? '');
            $row_refunded = safe_float($invoice_detail['refunded_total'] ?? 0);
            $invoice_total = safe_float($invoice_detail['total_amount'] ?? 0);
            fputcsv($output, [
                scalar_string($invoice_detail['invoice_number'] ?? ''),
                scalar_string($invoice_detail['client_name'] ?? ''),
                scalar_string($invoice_detail['issue_date'] ?? ''),
                scalar_string($income_event['payment_date'] ?? ''),
                $payment_method !== '' ? $payment_method : 'N/A',
                number_format($payment_amount, 2),
                number_format($invoice_total, 2),
                number_format($row_refunded, 2),
                number_format(max(0, $invoice_total - $row_refunded), 2)
            ]);
            $grand_total += $payment_amount;
            if ($invoice_id > 0 && !isset($distinct_refunds[$invoice_id])) {
                $distinct_refunds[$invoice_id] = $row_refunded;
            }
        }
        
        fputcsv($output, []);
        $grand_refunded = array_sum($distinct_refunds);
        fputcsv($output, ['Total', '', '', '', '', number_format($grand_total, 2), '', number_format($grand_refunded, 2), number_format(max(0, $grand_total - $grand_refunded), 2)]);
        break;

    case 'expense_summary':
        // Expense summary by date
        fputcsv($output, ['Financial Report - Expense Summary']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Date', 'Number of Expenses', 'Total Amount']);
        
        $stmt = $conn->prepare("
            SELECT 
                DATE(expense_date) as date,
                COUNT(*) as count,
                SUM(amount) as total
            FROM expenses
            WHERE expense_date BETWEEN ? AND ?
            GROUP BY DATE(expense_date)
            ORDER BY date
        ");
        $stmt->execute([$start_date, $end_date]);
        
        $grand_total = 0;
        $total_expenses = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['date'],
                $row['count'],
                number_format(safe_float($row['total']), 2)
            ]);
            $grand_total += safe_float($row['total']);
            $total_expenses += safe_int($row['count']);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['Total', $total_expenses, number_format($grand_total, 2)]);
        break;

    case 'expense_detail':
        // Detailed expenses
        fputcsv($output, ['Financial Report - Expense Detail']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Date', 'Category', 'Description', 'Client', 'Amount', 'Billable', 'Invoiced']);
        
        $stmt = $conn->prepare("
            SELECT 
                e.expense_date,
                e.category,
                e.description,
                c.name as client_name,
                e.amount,
                e.billable,
                e.invoiced
            FROM expenses e
            LEFT JOIN clients c ON e.client_id = c.id
            WHERE e.expense_date BETWEEN ? AND ?
            ORDER BY e.expense_date, e.id
        ");
        $stmt->execute([$start_date, $end_date]);
        
        $grand_total = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['expense_date'],
                $row['category'],
                $row['description'],
                $row['client_name'] ?? 'General',
                number_format(safe_float($row['amount']), 2),
                $row['billable'] ? 'Yes' : 'No',
                $row['invoiced'] ? 'Yes' : 'No'
            ]);
            $grand_total += safe_float($row['amount']);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['Total', '', '', '', number_format($grand_total, 2), '', '']);
        break;

    case 'profit_loss':
        // Profit and loss summary
        fputcsv($output, ['Financial Report - Profit & Loss Statement']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        // Get total income
        $income_stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total
            FROM invoices
            WHERE payment_date BETWEEN ? AND ?
              AND payment_method IS NOT NULL
              AND status NOT IN ('draft', 'sent', 'overdue', 'cancelled', 'void')
        ");
        $income_stmt->execute([$start_date, $end_date]);
        $total_income = safe_float($income_stmt->fetchColumn());

        $refund_stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM invoice_refunds
            WHERE refund_date BETWEEN ? AND ?
        ");
        $refund_stmt->execute([$start_date, $end_date]);
        $total_refunds = safe_float($refund_stmt->fetchColumn());
        $net_revenue = $total_income - $total_refunds;
        
        // Get income by category (using invoice line items if available, or just totals)
        fputcsv($output, ['INCOME']);
        fputcsv($output, ['Category', 'Amount']);
        fputcsv($output, ['Total Collected', number_format($total_income, 2)]);
        fputcsv($output, ['Refunds Issued', number_format($total_refunds, 2)]);
        fputcsv($output, ['Net Revenue', number_format($net_revenue, 2)]);
        fputcsv($output, []);
        
        // Get total expenses
        $expense_stmt = $conn->prepare("
            SELECT 
                category,
                SUM(amount) as total
            FROM expenses
            WHERE expense_date BETWEEN ? AND ?
            GROUP BY category
            ORDER BY total DESC
        ");
        $expense_stmt->execute([$start_date, $end_date]);
        
        fputcsv($output, ['EXPENSES']);
        fputcsv($output, ['Category', 'Amount']);
        
        $total_expenses = 0;
        while ($row = $expense_stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['category'],
                number_format(safe_float($row['total']), 2)
            ]);
            $total_expenses += safe_float($row['total']);
        }
        
        fputcsv($output, ['Total Expenses', number_format($total_expenses, 2)]);
        fputcsv($output, []);
        
        // Calculate profit/loss
        $profit_loss = $net_revenue - $total_expenses;
        fputcsv($output, ['NET PROFIT/LOSS', number_format($profit_loss, 2)]);
        fputcsv($output, []);
        
        if ($profit_loss >= 0) {
            fputcsv($output, ['Status', 'PROFIT']);
        } else {
            fputcsv($output, ['Status', 'LOSS']);
        }
        break;

    default:
        fputcsv($output, ['Error: Invalid export type']);
}

fclose($output);
exit;
