<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get parameters
$type = $_GET['type'] ?? 'income_summary';
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    die('Invalid date format');
}

// Validate that dates are valid calendar dates
$start_parts = explode('-', $start_date);
$end_parts = explode('-', $end_date);
if (!checkdate($start_parts[1], $start_parts[2], $start_parts[0]) || 
    !checkdate($end_parts[1], $end_parts[2], $end_parts[0])) {
    die('Invalid date values');
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $type . '_' . $start_date . '_to_' . $end_date . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

switch ($type) {
    case 'income_summary':
        // Income summary by date
        fputcsv($output, ['Financial Report - Income Summary']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Date', 'Number of Invoices', 'Total Amount']);
        
        $stmt = $conn->prepare("
            SELECT 
                DATE(payment_date) as date,
                COUNT(*) as count,
                SUM(total_amount) as total
            FROM invoices
            WHERE status = 'paid'
            AND payment_date BETWEEN ? AND ?
            GROUP BY DATE(payment_date)
            ORDER BY date
        ");
        $stmt->execute([$start_date, $end_date]);
        
        $grand_total = 0;
        $total_invoices = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['date'],
                $row['count'],
                number_format($row['total'], 2)
            ]);
            $grand_total += $row['total'];
            $total_invoices += $row['count'];
        }
        
        fputcsv($output, []);
        fputcsv($output, ['Total', $total_invoices, number_format($grand_total, 2)]);
        break;

    case 'income_detail':
        // Detailed income by invoice
        fputcsv($output, ['Financial Report - Income Detail']);
        fputcsv($output, ['Date Range:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, ['Invoice #', 'Client', 'Issue Date', 'Payment Date', 'Payment Method', 'Subtotal', 'Tax', 'Total']);
        
        $stmt = $conn->prepare("
            SELECT 
                i.invoice_number,
                c.name as client_name,
                i.issue_date,
                i.payment_date,
                i.payment_method,
                i.subtotal,
                i.tax_amount,
                i.total_amount
            FROM invoices i
            JOIN clients c ON i.client_id = c.id
            WHERE i.status = 'paid'
            AND i.payment_date BETWEEN ? AND ?
            ORDER BY i.payment_date, i.invoice_number
        ");
        $stmt->execute([$start_date, $end_date]);
        
        $grand_total = 0;
        $total_tax = 0;
        $total_subtotal = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['invoice_number'],
                $row['client_name'],
                $row['issue_date'],
                $row['payment_date'],
                $row['payment_method'] ?? 'N/A',
                number_format($row['subtotal'], 2),
                number_format($row['tax_amount'], 2),
                number_format($row['total_amount'], 2)
            ]);
            $total_subtotal += $row['subtotal'];
            $total_tax += $row['tax_amount'];
            $grand_total += $row['total_amount'];
        }
        
        fputcsv($output, []);
        fputcsv($output, ['Total', '', '', '', '', number_format($total_subtotal, 2), number_format($total_tax, 2), number_format($grand_total, 2)]);
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
                number_format($row['total'], 2)
            ]);
            $grand_total += $row['total'];
            $total_expenses += $row['count'];
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
                number_format($row['amount'], 2),
                $row['billable'] ? 'Yes' : 'No',
                $row['invoiced'] ? 'Yes' : 'No'
            ]);
            $grand_total += $row['amount'];
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
            WHERE status = 'paid'
            AND payment_date BETWEEN ? AND ?
        ");
        $income_stmt->execute([$start_date, $end_date]);
        $total_income = $income_stmt->fetchColumn();
        
        // Get income by category (using invoice line items if available, or just totals)
        fputcsv($output, ['INCOME']);
        fputcsv($output, ['Category', 'Amount']);
        fputcsv($output, ['Total Revenue', number_format($total_income, 2)]);
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
                number_format($row['total'], 2)
            ]);
            $total_expenses += $row['total'];
        }
        
        fputcsv($output, ['Total Expenses', number_format($total_expenses, 2)]);
        fputcsv($output, []);
        
        // Calculate profit/loss
        $profit_loss = $total_income - $total_expenses;
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
