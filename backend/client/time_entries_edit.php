<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$entry = null;
$preset_client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM time_entries WHERE id = ?");
    $stmt->execute([$id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$entry) {
        setFlashMessage('Time entry not found!', 'danger');
        redirect('time_entries_list.php');
    }
}

// Fetch clients
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $service_type = trim($_POST['service_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $hourly_rate = floatval($_POST['hourly_rate'] ?? 0);
    $billable = isset($_POST['billable']) ? 1 : 0;
    
    if (empty($client_id) || empty($service_type) || empty($date) || empty($start_time) || empty($end_time)) {
        setFlashMessage('All required fields must be filled!', 'danger');
    } else {
        // Calculate duration
        $start = new DateTime($date . ' ' . $start_time);
        $end = new DateTime($date . ' ' . $end_time);
        $duration_minutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        
        if ($duration_minutes <= 0) {
            setFlashMessage('End time must be after start time!', 'danger');
        } else {
            $total_amount = ($duration_minutes / 60) * $hourly_rate;
            
            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE time_entries 
                    SET client_id = ?, service_type = ?, description = ?, date = ?, 
                        start_time = ?, end_time = ?, duration_minutes = ?, 
                        hourly_rate = ?, total_amount = ?, billable = ?
                    WHERE id = ?
                ");
                $stmt->execute([$client_id, $service_type, $description, $date, $start_time, $end_time, 
                               $duration_minutes, $hourly_rate, $total_amount, $billable, $id]);
                setFlashMessage('Time entry updated successfully!', 'success');
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO time_entries (client_id, service_type, description, date, start_time, end_time, 
                                             duration_minutes, hourly_rate, total_amount, billable) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$client_id, $service_type, $description, $date, $start_time, $end_time, 
                               $duration_minutes, $hourly_rate, $total_amount, $billable]);
                setFlashMessage('Time entry created successfully!', 'success');
            }
            redirect('time_entries_list.php');
        }
    }
}

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><?= $id > 0 ? 'Edit Time Entry' : 'Add Time Entry' ?></h2>
                <a href="time_entries_list.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
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

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="client_id" class="form-label">Client *</label>
                            <select class="form-select" id="client_id" name="client_id" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>" 
                                        <?= (($entry['client_id'] ?? $preset_client_id) == $client['id']) ? 'selected' : '' ?>>
                                        <?= escape($client['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="service_type" class="form-label">Service Type *</label>
                                <input type="text" class="form-control" id="service_type" name="service_type" 
                                       value="<?= escape($entry['service_type'] ?? '') ?>" 
                                       placeholder="e.g., Pet Manners Training" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="date" class="form-label">Date *</label>
                                <input type="date" class="form-control" id="date" name="date" 
                                       value="<?= $entry['date'] ?? date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="start_time" class="form-label">Start Time *</label>
                                <input type="time" class="form-control" id="start_time" name="start_time" 
                                       value="<?= $entry['start_time'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="end_time" class="form-label">End Time *</label>
                                <input type="time" class="form-control" id="end_time" name="end_time" 
                                       value="<?= $entry['end_time'] ?? '' ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="hourly_rate" class="form-label">Hourly Rate ($) *</label>
                                <input type="number" step="0.01" class="form-control" id="hourly_rate" name="hourly_rate" 
                                       value="<?= $entry['hourly_rate'] ?? '75.00' ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?= escape($entry['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="billable" name="billable" 
                                   <?= ($entry['billable'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="billable">
                                Billable
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> <?= $id > 0 ? 'Update Entry' : 'Create Entry' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
