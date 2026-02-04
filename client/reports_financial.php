<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Date range handling
$range = $_GET['range'] ?? 'this_month';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Calculate date ranges
switch ($range) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'this_week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d');
        break;
    case 'last_week':
        $start_date = date('Y-m-d', strtotime('monday last week'));
        $end_date = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'this_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('first day of last month'));
        $end_date = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'this_quarter':
        $quarter = ceil(date('n') / 3);
        $start_date = date('Y-m-01', strtotime(date('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01'));
        $end_date = date('Y-m-t', strtotime(date('Y') . '-' . ($quarter * 3) . '-01'));
        break;
    case 'this_year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    case 'last_year':
        $start_date = date('Y-01-01', strtotime('last year'));
        $end_date = date('Y-12-31', strtotime('last year'));
        break;
    case 'custom':
        // Use the provided dates
        if (empty($start_date)) $start_date = date('Y-m-01');
        if (empty($end_date)) $end_date = date('Y-m-d');
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Get income data (from invoices that are paid)
$income_stmt = $conn->prepare("
    SELECT 
        DATE(payment_date) as date,
        SUM(total_amount) as amount
    FROM invoices
    WHERE status = 'paid'
    AND payment_date BETWEEN ? AND ?
    GROUP BY DATE(payment_date)
    ORDER BY date
");
$income_stmt->execute([$start_date, $end_date]);
$income_data = $income_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total income
$total_income_stmt = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as total
    FROM invoices
    WHERE status = 'paid'
    AND payment_date BETWEEN ? AND ?
");
$total_income_stmt->execute([$start_date, $end_date]);
$total_income = $total_income_stmt->fetchColumn();

// Get expense data
$expense_stmt = $conn->prepare("
    SELECT 
        DATE(expense_date) as date,
        SUM(amount) as amount
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    GROUP BY DATE(expense_date)
    ORDER BY date
");
$expense_stmt->execute([$start_date, $end_date]);
$expense_data = $expense_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total expenses
$total_expense_stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
");
$total_expense_stmt->execute([$start_date, $end_date]);
$total_expenses = $total_expense_stmt->fetchColumn();

// Calculate profit/loss
$profit_loss = $total_income - $total_expenses;

// Prepare data for charts
$all_dates = array_unique(array_merge(
    array_column($income_data, 'date'),
    array_column($expense_data, 'date')
));
sort($all_dates);

// Create indexed arrays for chart data
$income_by_date = array_column($income_data, 'amount', 'date');
$expense_by_date = array_column($expense_data, 'amount', 'date');

$chart_labels = [];
$chart_income = [];
$chart_expenses = [];
$chart_profit = [];

foreach ($all_dates as $date) {
    $chart_labels[] = date('M j', strtotime($date));
    $income_val = isset($income_by_date[$date]) ? floatval($income_by_date[$date]) : 0;
    $expense_val = isset($expense_by_date[$date]) ? floatval($expense_by_date[$date]) : 0;
    
    $chart_income[] = $income_val;
    $chart_expenses[] = $expense_val;
    $chart_profit[] = $income_val - $expense_val;
}

$page_title = 'Financial Reports';
require_once '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-chart-line me-2"></i>Financial Reports</h2>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-download me-1"></i> Export
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="reports_export.php?type=income_summary&start=<?= $start_date ?>&end=<?= $end_date ?>">
                    <i class="fas fa-file-csv me-1"></i> Income Summary (CSV)
                </a></li>
                <li><a class="dropdown-item" href="reports_export.php?type=income_detail&start=<?= $start_date ?>&end=<?= $end_date ?>">
                    <i class="fas fa-file-csv me-1"></i> Income Detail (CSV)
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="reports_export.php?type=expense_summary&start=<?= $start_date ?>&end=<?= $end_date ?>">
                    <i class="fas fa-file-csv me-1"></i> Expense Summary (CSV)
                </a></li>
                <li><a class="dropdown-item" href="reports_export.php?type=expense_detail&start=<?= $start_date ?>&end=<?= $end_date ?>">
                    <i class="fas fa-file-csv me-1"></i> Expense Detail (CSV)
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="reports_export.php?type=profit_loss&start=<?= $start_date ?>&end=<?= $end_date ?>">
                    <i class="fas fa-file-csv me-1"></i> Profit/Loss Summary (CSV)
                </a></li>
            </ul>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date Range</label>
                    <select class="form-select" name="range" id="rangeSelect">
                        <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="this_week" <?= $range === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="last_week" <?= $range === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                        <option value="this_month" <?= $range === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $range === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                        <option value="this_quarter" <?= $range === 'this_quarter' ? 'selected' : '' ?>>This Quarter</option>
                        <option value="this_year" <?= $range === 'this_year' ? 'selected' : '' ?>>This Year</option>
                        <option value="last_year" <?= $range === 'last_year' ? 'selected' : '' ?>>Last Year</option>
                        <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                <div class="col-md-3" id="customDates" style="<?= $range === 'custom' ? '' : 'display:none;' ?>">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?= escape($start_date) ?>">
                </div>
                <div class="col-md-3" id="customDatesEnd" style="<?= $range === 'custom' ? '' : 'display:none;' ?>">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?= escape($end_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block w-100">
                        <i class="fas fa-filter me-1"></i> Apply Filter
                    </button>
                </div>
            </form>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="fas fa-calendar me-1"></i>
                    Viewing: <?= formatDate($start_date) ?> to <?= formatDate($end_date) ?>
                </small>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <h6 class="card-title"><i class="fas fa-arrow-trend-up me-1"></i> Total Income</h6>
                    <h2>$<?= number_format($total_income, 2) ?></h2>
                    <small>Revenue from paid invoices</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <h6 class="card-title"><i class="fas fa-arrow-trend-down me-1"></i> Total Expenses</h6>
                    <h2>$<?= number_format($total_expenses, 2) ?></h2>
                    <small>All recorded expenses</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white <?= $profit_loss >= 0 ? 'bg-primary' : 'bg-warning' ?>">
                <div class="card-body">
                    <h6 class="card-title"><i class="fas fa-chart-line me-1"></i> Net Profit/Loss</h6>
                    <h2><?= $profit_loss >= 0 ? '+' : '' ?>$<?= number_format($profit_loss, 2) ?></h2>
                    <small><?= $profit_loss >= 0 ? 'Profit' : 'Loss' ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row mb-4">
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Income Over Time</h5>
                </div>
                <div class="card-body">
                    <canvas id="incomeChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Expenses Over Time</h5>
                </div>
                <div class="card-body">
                    <canvas id="expenseChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-area me-2"></i>Profit/Loss Over Time</h5>
                </div>
                <div class="card-body">
                    <canvas id="profitChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Toggle custom date fields
document.getElementById('rangeSelect').addEventListener('change', function() {
    const customDates = document.getElementById('customDates');
    const customDatesEnd = document.getElementById('customDatesEnd');
    if (this.value === 'custom') {
        customDates.style.display = 'block';
        customDatesEnd.style.display = 'block';
    } else {
        customDates.style.display = 'none';
        customDatesEnd.style.display = 'none';
    }
});

// Chart data
const labels = <?= json_encode($chart_labels) ?>;
const incomeData = <?= json_encode($chart_income) ?>;
const expenseData = <?= json_encode($chart_expenses) ?>;
const profitData = <?= json_encode($chart_profit) ?>;

// Income Chart
new Chart(document.getElementById('incomeChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Income',
            data: incomeData,
            borderColor: '#0a9a9c',
            backgroundColor: 'rgba(10, 154, 156, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Expense Chart
new Chart(document.getElementById('expenseChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Expenses',
            data: expenseData,
            borderColor: '#dc3545',
            backgroundColor: 'rgba(220, 53, 69, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Profit/Loss Chart
new Chart(document.getElementById('profitChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Profit/Loss',
            data: profitData,
            backgroundColor: profitData.map(val => val >= 0 ? 'rgba(10, 154, 156, 0.8)' : 'rgba(220, 53, 69, 0.8)'),
            borderColor: profitData.map(val => val >= 0 ? '#0a9a9c' : '#dc3545'),
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>

<?php require_once '../backend/includes/footer.php'; ?>
