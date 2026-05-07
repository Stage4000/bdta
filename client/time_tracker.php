<?php
/**
 * Active Time Tracker - Start/Stop Timer
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/time_tracker_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'requires_login' => true,
            'message' => 'Your session expired. Please sign in again. Your timer is still saved on this device.',
        ]);
        exit;
    }

    bdta_refresh_session_admin_account_type();
    if (bdta_session_admin_is_accountant($_SESSION) && !bdta_is_accountant_allowed_admin_path(scalar_string($_SERVER['SCRIPT_NAME'] ?? ''))) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied.',
        ]);
        exit;
    }
} else {
    requireLogin();
}

$db = new Database();
$conn = $db->getConnection();

// Fetch clients for quick select
$clients_stmt = $conn->query("SELECT id, name FROM clients WHERE COALESCE(is_archived, 0) = 0 ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = scalar_string($_POST['action'] ?? '');

    if (in_array($action, ['start', 'restore', 'stop'], true) && !isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
        exit;
    }
    
    if ($action === 'start') {
        $requested_start_time = safe_int($_POST['start_time'] ?? 0);
        $active_timer = bdta_normalize_active_timer([
            'start_time' => $requested_start_time > 0 ? $requested_start_time : time(),
            'client_id' => safe_int($_POST['client_id'] ?? 0),
            'service_type' => trim(scalar_string($_POST['service_type'] ?? '')),
            'description' => trim(scalar_string($_POST['description'] ?? '')),
        ]);

        if ($active_timer === null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please fill in required fields (Client and Service Type).']);
            exit;
        }

        if (!bdta_active_timer_has_valid_start_time($active_timer)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unable to start the timer because the start time is invalid.']);
            exit;
        }

        $_SESSION['active_timer'] = $active_timer;

        echo json_encode([
            'success' => true,
            'start_time' => $active_timer['start_time'],
            'timer' => $active_timer,
        ]);
        exit;
    }

    if ($action === 'restore') {
        $active_timer = bdta_normalize_active_timer($_POST['timer_state'] ?? null);

        if ($active_timer === null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unable to restore the active timer.']);
            exit;
        }

        if (!bdta_active_timer_has_valid_start_time($active_timer)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unable to restore the active timer because the saved start time is invalid.']);
            exit;
        }

        $_SESSION['active_timer'] = $active_timer;
        echo json_encode(['success' => true] + bdta_active_timer_status_payload($active_timer));
        exit;
    }
    
    if ($action === 'stop') {
        $timer = bdta_normalize_active_timer($_SESSION['active_timer'] ?? null);
        if ($timer === null) {
            $timer = bdta_normalize_active_timer($_POST['timer_state'] ?? null);
        }

        if ($timer !== null) {
            $end_time = time();
            if (!bdta_active_timer_has_valid_start_time($timer, $end_time)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Unable to stop the timer because the saved start time is invalid.']);
                exit;
            }

            // Small client/server clock skew is tolerated, so clamp slightly-future start times down to the stop time before saving.
            $start_time = min($timer['start_time'], $end_time);
            $duration_seconds = max(0, $end_time - $start_time);
            $duration_minutes = round($duration_seconds / 60);
            
            // Save to database
            $date = date('Y-m-d', $start_time);
            $start_time_str = date('H:i:s', $start_time);
            $end_time_str = date('H:i:s', $end_time);
            
            // Get default hourly rate
            $default_hourly_rate = 75.0;
            $rate_stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'default_hourly_rate'");
            $rate_value = $rate_stmt->fetchColumn();
            $hourly_rate = is_numeric($rate_value) && (float) $rate_value !== 0.0 ? (float) $rate_value : $default_hourly_rate;
            
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

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No active timer was found to stop.']);
        exit;
    }
    
    if ($action === 'status') {
        $active_timer = bdta_normalize_active_timer($_SESSION['active_timer'] ?? null);
        if ($active_timer !== null && bdta_active_timer_has_valid_start_time($active_timer)) {
            echo json_encode(bdta_active_timer_status_payload($active_timer));
        } else {
            unset($_SESSION['active_timer']);
            echo json_encode(['active' => false]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown time tracker action.']);
    exit;
}

include '../backend/includes/header.php';

$active_timer_storage_key = bdta_active_timer_storage_key($_SESSION['user_type'] ?? 'admin', $_SESSION['admin_id'] ?? 0);
$time_tracker_csrf_token = csrfToken();
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-lg-8 offset-lg-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-stopwatch me-2"></i>Time Tracker</h2>
                <div>
                    <a href="time_entries_list.php" class="btn btn-outline-primary">
                        <i class="fas fa-list"></i> View All Entries
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
                            <i class="fas fa-play me-2"></i>Start Timer
                        </button>
                        <button id="stopBtn" class="btn btn-danger btn-lg" onclick="stopTimer()" style="display: none;">
                            <i class="fas fa-stop me-2"></i>Stop Timer
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
const ACTIVE_TIMER_STORAGE_KEY = <?= json_encode($active_timer_storage_key) ?>;
const TIME_TRACKER_CSRF_TOKEN = <?= json_encode($time_tracker_csrf_token) ?>;
let timerInterval = null;
let startTime = null;

// Check for active timer on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeTimer();
    loadTodayEntries();
});

function initializeTimer() {
    const storedTimer = getStoredActiveTimer();
    if (storedTimer) {
        applyActiveTimer(storedTimer);
    }

    checkTimerStatus();
}

function checkTimerStatus() {
    const formData = new FormData();
    formData.append('action', 'status');

    fetchTimerJson(formData)
    .then(data => {
        if (data.active && data.timer) {
            saveActiveTimer(data.timer);
            applyActiveTimer(data.timer);
            return;
        }

        const storedTimer = getStoredActiveTimer();
        if (storedTimer) {
            applyActiveTimer(storedTimer);
            restoreActiveTimer(storedTimer);
        }
    })
    .catch(error => {
        if (error.message !== 'Authentication required') {
            const storedTimer = getStoredActiveTimer();
            if (storedTimer) {
                applyActiveTimer(storedTimer);
            }
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

    const pendingTimer = normalizeActiveTimer({
        start_time: Math.floor(Date.now() / 1000),
        client_id: clientId,
        service_type: serviceType,
        description: description
    });
    if (!pendingTimer) {
        alert('Unable to start the timer right now.');
        return;
    }

    saveActiveTimer(pendingTimer);
    applyActiveTimer(pendingTimer);
    
    const formData = new FormData();
    formData.append('action', 'start');
    formData.append('csrf_token', TIME_TRACKER_CSRF_TOKEN);
    formData.append('start_time', String(pendingTimer.start_time));
    formData.append('client_id', String(pendingTimer.client_id));
    formData.append('service_type', pendingTimer.service_type);
    formData.append('description', pendingTimer.description);
    
    fetchTimerJson(formData)
    .then(data => {
        if (data.success) {
            saveActiveTimer(data.timer);
            applyActiveTimer(data.timer);
        }
    })
    .catch(error => {
        clearActiveTimer();
        stopTimerUpdate();
        showStoppedState();
        handleTimerError(error);
    });
}

function restoreActiveTimer(timer) {
    const formData = new FormData();
    formData.append('action', 'restore');
    formData.append('csrf_token', TIME_TRACKER_CSRF_TOKEN);
    formData.append('timer_state', JSON.stringify(timer));

    fetchTimerJson(formData)
    .then(data => {
        if (data.timer) {
            saveActiveTimer(data.timer);
            applyActiveTimer(data.timer);
        }
    })
    .catch(error => {
        if (error.message !== 'Authentication required') {
            console.warn(error);
        }
    });
}

function stopTimer() {
    if (!confirm('Stop timer and save entry?')) return;
    
    const formData = new FormData();
    formData.append('action', 'stop');
    formData.append('csrf_token', TIME_TRACKER_CSRF_TOKEN);
    const storedTimer = getStoredActiveTimer();
    if (storedTimer) {
        formData.append('timer_state', JSON.stringify(storedTimer));
    }

    fetchTimerJson(formData)
    .then(data => {
        if (data.success) {
            clearActiveTimer();
            stopTimerUpdate();
            showStoppedState();
            alert(`Time entry saved! Duration: ${formatDuration(data.duration_minutes * 60)}`);
            resetForm();
            loadTodayEntries();
        }
    })
    .catch(handleTimerError);
}

function fetchTimerJson(formData) {
    return fetch('time_tracker.php', {
        method: 'POST',
        body: formData,
        keepalive: true
    })
    .then(async response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (response.redirected && response.url) {
                window.location.href = response.url;
                throw new Error('Authentication required');
            }
            throw new Error('Unexpected response while tracking time.');
        }

        const data = await response.json();
        if (data.requires_login || response.status === 401) {
            alert(data.message || 'Your session expired. Please sign in again. Your timer is still saved on this device.');
            window.location.href = 'login.php';
            throw new Error('Authentication required');
        }

        if (!response.ok) {
            throw new Error(data.message || 'Unable to complete the time tracking request.');
        }

        return data;
    });
}

function handleTimerError(error) {
    if (error.message !== 'Authentication required') {
        alert(error.message || 'Unable to complete the time tracking request.');
    }
}

function normalizeActiveTimer(timer) {
    if (!timer || typeof timer !== 'object') {
        return null;
    }

    const startTimeValue = Number(timer.start_time);
    const clientId = Number(timer.client_id);
    const serviceType = typeof timer.service_type === 'string' ? timer.service_type.trim() : '';
    const description = typeof timer.description === 'string' ? timer.description.trim() : '';

    const hasValidStartTime = Number.isFinite(startTimeValue) && startTimeValue > 0;
    const hasValidClientId = Number.isFinite(clientId) && clientId > 0;
    const hasServiceType = serviceType !== '';

    if (!hasValidStartTime || !hasValidClientId || !hasServiceType) {
        return null;
    }

    return {
        start_time: Math.floor(startTimeValue),
        client_id: Math.floor(clientId),
        service_type: serviceType,
        description: description
    };
}

function getStoredActiveTimer() {
    try {
        const storedTimer = localStorage.getItem(ACTIVE_TIMER_STORAGE_KEY);
        return normalizeActiveTimer(storedTimer ? JSON.parse(storedTimer) : null);
    } catch (error) {
        clearActiveTimer();
        return null;
    }
}

function saveActiveTimer(timer) {
    const normalizedTimer = normalizeActiveTimer(timer);
    if (!normalizedTimer) {
        return;
    }

    try {
        localStorage.setItem(ACTIVE_TIMER_STORAGE_KEY, JSON.stringify(normalizedTimer));
    } catch (error) {
        console.warn(error);
    }

    if (window.bdtaActiveTimerIndicator && typeof window.bdtaActiveTimerIndicator.setActiveTimer === 'function') {
        window.bdtaActiveTimerIndicator.setActiveTimer(normalizedTimer, { persist: false });
    }
}

function clearActiveTimer() {
    try {
        localStorage.removeItem(ACTIVE_TIMER_STORAGE_KEY);
    } catch (error) {
        console.warn(error);
    }

    if (window.bdtaActiveTimerIndicator && typeof window.bdtaActiveTimerIndicator.clearActiveTimer === 'function') {
        window.bdtaActiveTimerIndicator.clearActiveTimer({ clearStorage: false });
    }
}

function applyActiveTimer(timer) {
    const normalizedTimer = normalizeActiveTimer(timer);
    if (!normalizedTimer) {
        return;
    }

    startTime = normalizedTimer.start_time * 1000;
    document.getElementById('client_id').value = String(normalizedTimer.client_id);
    document.getElementById('service_type').value = normalizedTimer.service_type;
    document.getElementById('description').value = normalizedTimer.description;
    showRunningState();
    startTimerUpdate();
}

function showRunningState() {
    document.getElementById('timerForm').style.opacity = '0.5';
    document.getElementById('timerForm').querySelectorAll('input, select, textarea').forEach(el => el.disabled = true);
    document.getElementById('startBtn').style.display = 'none';
    document.getElementById('stopBtn').style.display = 'block';
    document.getElementById('timerStatus').textContent = 'Timer running...';
}

function showStoppedState() {
    startTime = null;
    document.getElementById('timerForm').style.opacity = '1';
    document.getElementById('timerForm').querySelectorAll('input, select, textarea').forEach(el => el.disabled = false);
    document.getElementById('startBtn').style.display = 'block';
    document.getElementById('stopBtn').style.display = 'none';
    document.getElementById('timerStatus').textContent = 'Ready to start';
    document.getElementById('timerDisplay').textContent = '00:00:00';
}

function startTimerUpdate() {
    stopTimerUpdate();
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
    if (!startTime) {
        document.getElementById('timerDisplay').textContent = '00:00:00';
        return;
    }

    const elapsed = Math.floor((Date.now() - startTime) / 1000);
    document.getElementById('timerDisplay').textContent = formatDuration(elapsed);
}

function formatDuration(seconds) {
    const totalSeconds = Math.max(0, seconds);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const secs = totalSeconds % 60;
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

<?php include '../backend/includes/footer.php'; ?>
