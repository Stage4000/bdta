<?php
/**
 * Active Time Tracker - Start/Stop Timer
 */
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Fetch clients for quick select
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'start') {
        $_SESSION['active_timer'] = [
            'start_time' => time(),
            'client_id' => intval($_POST['client_id'] ?? 0),
            'service_type' => trim($_POST['service_type'] ?? ''),
            'description' => trim($_POST['description'] ?? '')
        ];
        echo json_encode(['success' => true, 'start_time' => $_SESSION['active_timer']['start_time']]);
        exit;
    }
    
    if ($action === 'stop') {
        if (isset($_SESSION['active_timer'])) {
            $timer = $_SESSION['active_timer'];
            $start_time = $timer['start_time'];
            $end_time = time();
            $duration_seconds = $end_time - $start_time;
            $duration_minutes = round($duration_seconds / 60);
            
            // Save to database
            $date = date('Y-m-d', $start_time);
            $start_time_str = date('H:i:s', $start_time);
            $end_time_str = date('H:i:s', $end_time);
            
            // Get default hourly rate
            $rate_stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'default_hourly_rate'");
            $hourly_rate = floatval($rate_stmt->fetchColumn() ?: 75);
            
            $total_amount = ($duration_minutes / 60) * $hourly_rate;
            
            $stmt = $conn->prepare("
                INSERT INTO time_entries (client_id, service_type, description, date, start_time, end_time, 
                                         duration_minutes, hourly_rate, total_amount, billable) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $timer['client_id'],
                $timer['service_type'],
                $timer['description'],
                $date,
                $start_time_str,
                $end_time_str,
                $duration_minutes,
                $hourly_rate,
                $total_amount
            ]);
            
            $entry_id = $conn->lastInsertId();
            unset($_SESSION['active_timer']);
            
            echo json_encode([
                'success' => true,
                'duration_minutes' => $duration_minutes,
                'entry_id' => $entry_id
            ]);
            exit;
        }
    }
    
    if ($action === 'status') {
        if (isset($_SESSION['active_timer'])) {
            echo json_encode([
                'active' => true,
                'start_time' => $_SESSION['active_timer']['start_time'],
                'elapsed' => time() - $_SESSION['active_timer']['start_time']
            ]);
        } else {
            echo json_encode(['active' => false]);
        }
        exit;
    }
}

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-lg-8 offset-lg-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-stopwatch me-2"></i>Time Tracker</h2>
                <div>
                    <a href="time_entries_list.php" class="btn btn-outline-primary">
                        <i class="bi bi-list"></i> View All Entries
                    </a>
                </div>
            </div>

            <!-- Timer Card -->
            <div class="card shadow-lg">
                <div class="card-body p-5">
                    <!-- Timer Display -->
                    <div class="text-center mb-4">
                        <div id="timerDisplay" class="display-1 fw-bold text-primary mb-3" style="font-variant-numeric: tabular-nums;">
                            00:00:00
                        </div>
                        <div id="timerStatus" class="text-muted">Ready to start</div>
                    </div>

                    <!-- Timer Form -->
                    <div id="timerForm">
                        <div class="mb-3">
                            <label for="client_id" class="form-label">Client *</label>
                            <select class="form-select form-select-lg" id="client_id" required>
                                <option value="">Select Client...</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>"><?= escape($client['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="service_type" class="form-label">Service Type *</label>
                            <input type="text" class="form-control form-control-lg" id="service_type" 
                                   placeholder="e.g., Training Session, Consultation" required>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" rows="2" 
                                      placeholder="What are you working on?"></textarea>
                        </div>
                    </div>

                    <!-- Timer Controls -->
                    <div class="d-grid gap-2 mt-4">
                        <button id="startBtn" class="btn btn-success btn-lg" onclick="startTimer()">
                            <i class="bi bi-play-fill me-2"></i>Start Timer
                        </button>
                        <button id="stopBtn" class="btn btn-danger btn-lg" onclick="stopTimer()" style="display: none;">
                            <i class="bi bi-stop-fill me-2"></i>Stop Timer
                        </button>
                    </div>
                </div>
            </div>

            <!-- Recent Entries -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">Today's Time Entries</h5>
                </div>
                <div class="card-body">
                    <div id="todayEntries">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let timerInterval = null;
let startTime = null;

// Check for active timer on page load
document.addEventListener('DOMContentLoaded', function() {
    checkTimerStatus();
    loadTodayEntries();
});

function checkTimerStatus() {
    fetch('time_tracker.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=status'
    })
    .then(r => r.json())
    .then(data => {
        if (data.active) {
            startTime = data.start_time * 1000;
            showRunningState();
            startTimerUpdate();
        }
    });
}

function startTimer() {
    const clientId = document.getElementById('client_id').value;
    const serviceType = document.getElementById('service_type').value;
    const description = document.getElementById('description').value;
    
    if (!clientId || !serviceType) {
        alert('Please fill in required fields (Client and Service Type)');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'start');
    formData.append('client_id', clientId);
    formData.append('service_type', serviceType);
    formData.append('description', description);
    
    fetch('time_tracker.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            startTime = data.start_time * 1000;
            showRunningState();
            startTimerUpdate();
        }
    });
}

function stopTimer() {
    if (!confirm('Stop timer and save entry?')) return;
    
    const formData = new FormData();
    formData.append('action', 'stop');
    
    fetch('time_tracker.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            stopTimerUpdate();
            showStoppedState();
            alert(`Time entry saved! Duration: ${formatDuration(data.duration_minutes * 60)}`);
            resetForm();
            loadTodayEntries();
        }
    });
}

function showRunningState() {
    document.getElementById('timerForm').style.opacity = '0.5';
    document.getElementById('timerForm').querySelectorAll('input, select, textarea').forEach(el => el.disabled = true);
    document.getElementById('startBtn').style.display = 'none';
    document.getElementById('stopBtn').style.display = 'block';
    document.getElementById('timerStatus').textContent = 'Timer running...';
}

function showStoppedState() {
    document.getElementById('timerForm').style.opacity = '1';
    document.getElementById('timerForm').querySelectorAll('input, select, textarea').forEach(el => el.disabled = false);
    document.getElementById('startBtn').style.display = 'block';
    document.getElementById('stopBtn').style.display = 'none';
    document.getElementById('timerStatus').textContent = 'Ready to start';
    document.getElementById('timerDisplay').textContent = '00:00:00';
}

function startTimerUpdate() {
    updateTimerDisplay();
    timerInterval = setInterval(updateTimerDisplay, 1000);
}

function stopTimerUpdate() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

function updateTimerDisplay() {
    const elapsed = Math.floor((Date.now() - startTime) / 1000);
    document.getElementById('timerDisplay').textContent = formatDuration(elapsed);
}

function formatDuration(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function resetForm() {
    document.getElementById('client_id').value = '';
    document.getElementById('service_type').value = '';
    document.getElementById('description').value = '';
}

function loadTodayEntries() {
    // This would fetch today's entries via AJAX
    // For now, just show a link
    document.getElementById('todayEntries').innerHTML = `
        <p class="text-muted">
            <a href="time_entries_list.php" class="btn btn-sm btn-primary">View All Time Entries</a>
        </p>
    `;
}
</script>

<?php include '../includes/footer.php'; ?>
