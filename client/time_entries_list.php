<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$client_filter = safe_int($_GET['client_id'] ?? 0);

// Handle time entry deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $csrf_token = scalar_string($_POST['csrf_token'] ?? '');
    if ($csrf_token === '' || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), $csrf_token)) {
        setFlashMessage('Invalid request.', 'danger');
        redirect($_SERVER['PHP_SELF'] . ($client_filter > 0 ? "?client_id=$client_filter" : ''));
    }
    $id = safe_int($_POST['delete_id']);
    $post_client_filter = safe_int($_POST['client_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM time_entries WHERE id = ?");
    $stmt->execute([$id]);
    setFlashMessage('Time entry deleted successfully!', 'success');
    redirect($_SERVER['PHP_SELF'] . ($post_client_filter > 0 ? "?client_id=$post_client_filter" : ''));
}

// Fetch clients for filter
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch time entries
$sql = "
    SELECT te.*, c.name as client_name 
    FROM time_entries te
    JOIN clients c ON te.client_id = c.id
";
$params = [];

if ($client_filter > 0) {
    $sql .= " WHERE te.client_id = ?";
    $params[] = $client_filter;
}

$sql .= " ORDER BY te.date DESC, te.start_time DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$time_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_hours = 0;
$total_amount = 0;
$billable_hours = 0;
$billable_amount = 0;

foreach ($time_entries as $entry) {
    $hours = safe_float($entry['duration_minutes'] ?? 0) / 60;
    $total_hours += $hours;
    $total_amount += safe_float($entry['total_amount'] ?? 0);
    
    if (safe_int($entry['billable'] ?? 0) === 1) {
        $billable_hours += $hours;
        $billable_amount += safe_float($entry['total_amount'] ?? 0);
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-stopwatch me-2"></i>Time Tracking</h2>
        <div>
            <a href="time_tracker.php" class="btn btn-success me-2">
                <i class="fas fa-circle-play"></i> Start Timer
            </a>
            <a href="time_entries_edit.php<?= $client_filter > 0 ? "?client_id=$client_filter" : '' ?>" class="btn btn-primary">
                <i class="fas fa-circle-plus"></i> Add Time Entry
            </a>
        </div>
    </div>

    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h6 class="card-title">Total Hours</h6>
                    <h3><?= number_format($total_hours, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <h6 class="card-title">Billable Hours</h6>
                    <h3><?= number_format($billable_hours, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <h6 class="card-title">Total Amount</h6>
                    <h3>$<?= number_format($total_amount, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h6 class="card-title">Billable Amount</h6>
                    <h3>$<?= number_format($billable_amount, 2) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="client_filter" class="form-label">Filter by Client</label>
                    <select class="form-select" id="client_filter" name="client_id" onchange="this.form.submit()">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" <?= $client_filter == $client['id'] ? 'selected' : '' ?>>
                                <?= escape($client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($client_filter > 0): ?>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="time_entries_list.php" class="btn btn-secondary d-block">Clear Filter</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Time Entries Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Service</th>
                            <th>Description</th>
                            <th>Time</th>
                            <th>Duration</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($time_entries)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    <p class="text-muted">No time entries found. Add your first time entry!</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($time_entries as $entry): ?>
                                <tr>
                                    <td><?= formatDate($entry['date']) ?></td>
                                    <td><strong><?= escape($entry['client_name']) ?></strong></td>
                                    <td><?= escape($entry['service_type']) ?></td>
                                    <td><?= escape($entry['description'] ?? '-') ?></td>
                                     <td><?= date('g:i A', safe_timestamp(strtotime(array_string_value($entry, 'start_time')))) ?> - <?= date('g:i A', safe_timestamp(strtotime(array_string_value($entry, 'end_time')))) ?></td>
                                     <td><?= number_format(safe_float($entry['duration_minutes'] ?? 0) / 60, 2) ?> hrs</td>
                                     <td>$<?= number_format(safe_float($entry['hourly_rate'] ?? 0), 2) ?>/hr</td>
                                     <td><strong>$<?= number_format(safe_float($entry['total_amount'] ?? 0), 2) ?></strong></td>
                                    <td>
                                        <?php if ($entry['invoiced']): ?>
                                            <span class="badge bg-secondary">Invoiced</span>
                                        <?php elseif ($entry['billable']): ?>
                                            <span class="badge bg-success">Billable</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Non-Billable</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="time_entries_edit.php?id=<?= $entry['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-pencil"></i>
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this time entry?')">
                                            <input type="hidden" name="delete_id" value="<?= $entry['id'] ?>">
                                            <?php if ($client_filter > 0): ?>
                                            <input type="hidden" name="client_id" value="<?= $client_filter ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
