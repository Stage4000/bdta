<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// Get client email
$stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_email = $stmt->fetchColumn();

// Business contact for restriction messages
$business_email = \Settings::get('business_email', '');
$business_phone = \Settings::get('business_phone', '');

$now_date = date('Y-m-d');
$now_time = date('H:i:s');

// Upcoming appointments (joined with appointment_types for notice period)
$stmt = $conn->prepare("
    SELECT b.*, at.cancellation_notice_hours, at.name AS apt_type_display_name,
           at.portal_available, at.advance_booking_min_days
    FROM bookings b
    LEFT JOIN appointment_types at ON b.appointment_type_id = at.id
    WHERE b.client_email = ?
      AND (b.appointment_date > ?
           OR (b.appointment_date = ? AND b.appointment_time > ?))
      AND b.status NOT IN ('cancelled', 'completed')
    ORDER BY b.appointment_date ASC, b.appointment_time ASC
");
$stmt->execute([$client_email, $now_date, $now_date, $now_time]);
$upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Past appointments
$stmt = $conn->prepare("
    SELECT b.*, at.name AS apt_type_display_name
    FROM bookings b
    LEFT JOIN appointment_types at ON b.appointment_type_id = at.id
    WHERE b.client_email = ?
      AND (b.appointment_date < ?
           OR (b.appointment_date = ? AND b.appointment_time <= ?)
           OR b.status IN ('cancelled', 'completed'))
    ORDER BY b.appointment_date DESC, b.appointment_time DESC
");
$stmt->execute([$client_email, $now_date, $now_date, $now_time]);
$past = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bookable appointment types (portal_available = 1)
$stmt = $conn->query("SELECT * FROM appointment_types WHERE portal_available = 1 AND is_active = 1 ORDER BY name");
$bookable_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also include appointment types matching client's package credits (not already portal_available)
$stmt = $conn->prepare("
    SELECT DISTINCT cpc.appointment_type_id
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    WHERE cpc.client_id = ?
      AND (cpc.total_credits - cpc.used_credits) > 0
      AND cp.is_active = 1
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
");
$stmt->execute([$client_id]);
$credited_type_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

$extra_types = [];
if (!empty($credited_type_ids)) {
    $placeholders = implode(',', array_fill(0, count($credited_type_ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE is_active = 1 AND id IN ($placeholders) AND (portal_available = 0 OR portal_available IS NULL) ORDER BY name");
    $stmt->execute($credited_type_ids);
    $extra_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pre-compute per-booking action eligibility for upcoming appointments
$now_ts = time();
foreach ($upcoming as &$b) {
    $apt_ts       = strtotime($b['appointment_date'] . ' ' . $b['appointment_time']);
    $hours_until  = ($apt_ts - $now_ts) / 3600.0;
    $notice_hours = intval($b['cancellation_notice_hours'] ?? 0);

    $b['_hours_until']     = $hours_until;
    $b['_notice_hours']    = $notice_hours;
    // can_change: appointment is in the future AND within the allowed notice window (or no window set)
    $b['_can_change']      = ($hours_until > 0) && ($notice_hours === 0 || $hours_until >= $notice_hours);
    // can_reschedule: change allowed AND appointment type ID is known (portal_available not required)
    $b['_can_reschedule']  = $b['_can_change'] && !empty($b['appointment_type_id']);
}
unset($b);

$page_title = 'Appointments';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Appointments</h2>

<!-- Book New Appointment -->
<?php if (!empty($bookable_types) || !empty($extra_types)): ?>
<div class="card mb-4">
    <div class="card-header"><strong><i class="fas fa-calendar-plus me-2"></i>Book New Appointment</strong></div>
    <div class="card-body">
        <div class="row g-2">
        <?php foreach (array_merge($bookable_types, $extra_types) as $atype): ?>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h6><?php echo escape($atype['name']); ?></h6>
                        <?php if (!empty($atype['description'])): ?>
                            <p class="text-muted small mb-2"><?php echo escape($atype['description']); ?></p>
                        <?php endif; ?>
                        <?php
                        if (!empty($atype['unique_link'])) {
                            $book_url = '/backend/public/book.php?link=' . urlencode($atype['unique_link']);
                        } else {
                            $book_url = '/backend/public/book.php?type=' . intval($atype['id']);
                        }
                        ?>
                        <a href="<?php echo escape($book_url); ?>" class="btn btn-sm btn-primary" target="_blank">
                            Book Now
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="appointmentTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#upcoming">
            Upcoming <span class="badge bg-secondary"><?php echo count($upcoming); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#past">
            Past <span class="badge bg-secondary"><?php echo count($past); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="upcoming">
        <?php if (empty($upcoming)): ?>
            <div class="alert alert-info">No upcoming appointments.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($upcoming as $b): ?>
                    <tr>
                        <td><?php echo escape(date('M j, Y', strtotime($b['appointment_date']))); ?></td>
                        <td><?php echo escape(date('g:i A', strtotime($b['appointment_time']))); ?></td>
                        <td><?php echo escape($b['apt_type_display_name'] ?: $b['service_type'] ?? ''); ?></td>
                        <td>
                            <?php
                            $status = $b['status'] ?? 'pending';
                            $badge = match($status) {
                                'confirmed' => 'bg-success',
                                'pending'   => 'bg-warning text-dark',
                                default     => 'bg-secondary',
                            };
                            ?>
                            <span class="badge <?php echo $badge; ?>"><?php echo escape(ucfirst($status)); ?></span>
                        </td>
                        <td class="text-muted small"><?php echo escape($b['notes'] ?? ''); ?></td>
                        <td>
                            <?php if ($b['_can_change']): ?>
                                <button class="btn btn-sm btn-outline-danger me-1"
                                        data-booking-id="<?php echo intval($b['id']); ?>"
                                        data-type-name="<?php echo escape($b['apt_type_display_name'] ?: $b['service_type'] ?? ''); ?>"
                                        data-datetime="<?php echo escape(date('M j, Y', strtotime($b['appointment_date'])) . ' at ' . date('g:i A', strtotime($b['appointment_time']))); ?>"
                                        onclick="showCancelModal(this)">
                                    <i class="fas fa-times-circle me-1"></i>Cancel
                                </button>
                                <?php if ($b['_can_reschedule']): ?>
                                <button class="btn btn-sm btn-outline-primary"
                                        data-booking-id="<?php echo intval($b['id']); ?>"
                                        data-type-id="<?php echo intval($b['appointment_type_id']); ?>"
                                        data-type-name="<?php echo escape($b['apt_type_display_name'] ?: $b['service_type'] ?? ''); ?>"
                                        data-min-days="<?php echo intval($b['advance_booking_min_days'] ?? 1); ?>"
                                        onclick="showRescheduleModal(this)">
                                    <i class="fas fa-calendar-alt me-1"></i>Reschedule
                                </button>
                                <?php endif; ?>
                                <?php if ($b['_notice_hours'] > 0): ?>
                                    <div class="text-muted small mt-1">
                                        <i class="fas fa-info-circle"></i>
                                        Changes allowed up to <?php echo $b['_notice_hours']; ?>h before
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">
                                    <i class="fas fa-lock me-1"></i>
                                    <?php if ($b['_hours_until'] <= 0): ?>
                                        In progress / past
                                    <?php else: ?>
                                        Within <?php echo $b['_notice_hours']; ?>h cutoff
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="past">
        <?php if (empty($past)): ?>
            <div class="alert alert-info">No past appointments.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($past as $b): ?>
                    <tr>
                        <td><?php echo escape(date('M j, Y', strtotime($b['appointment_date']))); ?></td>
                        <td><?php echo escape(date('g:i A', strtotime($b['appointment_time']))); ?></td>
                        <td><?php echo escape($b['apt_type_display_name'] ?: $b['service_type'] ?? ''); ?></td>
                        <td>
                            <?php
                            $status = $b['status'] ?? '';
                            $badge = match($status) {
                                'completed'  => 'bg-success',
                                'cancelled'  => 'bg-danger',
                                'confirmed'  => 'bg-info',
                                default      => 'bg-secondary',
                            };
                            ?>
                            <span class="badge <?php echo $badge; ?>"><?php echo escape(ucfirst($status)); ?></span>
                        </td>
                        <td class="text-muted small"><?php echo escape($b['notes'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Cancel Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cancelModalLabel"><i class="fas fa-times-circle me-2 text-danger"></i>Cancel Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to cancel your appointment?</p>
                <p><strong id="cancelApptDetails"></strong></p>
                <div class="mb-3">
                    <label for="cancelReason" class="form-label">Reason <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control" id="cancelReason" rows="3" maxlength="1000"
                              placeholder="Let us know why you're cancelling..."></textarea>
                </div>
                <div id="cancelError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Appointment</button>
                <button type="button" class="btn btn-danger" id="confirmCancelBtn" onclick="submitCancel()">
                    <span id="cancelBtnSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    Yes, Cancel It
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Reschedule Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rescheduleModalLabel"><i class="fas fa-calendar-alt me-2 text-primary"></i>Reschedule Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Select a new date and time for <strong id="rescheduleApptName"></strong>.</p>

                <div class="mb-3">
                    <label for="rescheduleDate" class="form-label">New Date</label>
                    <input type="date" class="form-control" id="rescheduleDate"
                           onchange="loadRescheduleSlots()">
                </div>

                <div class="mb-3" id="rescheduleTimesSection" style="display:none;">
                    <label class="form-label">Available Times</label>
                    <div id="rescheduleTimesGrid" class="d-flex flex-wrap gap-2"></div>
                    <div id="rescheduleNoSlots" class="text-muted small d-none">No available times on this date.</div>
                </div>

                <div class="mb-3">
                    <label for="rescheduleReason" class="form-label">Reason <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control" id="rescheduleReason" rows="2" maxlength="1000"
                              placeholder="Let us know why you're rescheduling..."></textarea>
                </div>
                <div id="rescheduleError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Current Time</button>
                <button type="button" class="btn btn-primary" id="confirmRescheduleBtn" disabled onclick="submitReschedule()">
                    <span id="rescheduleBtnSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    Confirm Reschedule
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Outcome Toast ──────────────────────────────────────────────────── -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1060">
    <div id="outcomeToast" class="toast align-items-center border-0" role="alert" aria-live="assertive">
        <div class="d-flex">
            <div class="toast-body" id="outcomeToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
var _activeCancelId     = null;
var _activeRescheduleId = null;
var _activeTypeId       = null;
var _selectedTime       = null;

// ── Cancel ────────────────────────────────────────────────────────────
function showCancelModal(btn) {
    _activeCancelId = parseInt(btn.dataset.bookingId, 10);
    document.getElementById('cancelApptDetails').textContent = btn.dataset.typeName + ' on ' + btn.dataset.datetime;
    document.getElementById('cancelReason').value = '';
    document.getElementById('cancelError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('cancelModal')).show();
}

function submitCancel() {
    if (!_activeCancelId) return;
    var reason  = document.getElementById('cancelReason').value.trim();
    var btn     = document.getElementById('confirmCancelBtn');
    var spinner = document.getElementById('cancelBtnSpinner');
    var errBox  = document.getElementById('cancelError');

    btn.disabled = true;
    spinner.classList.remove('d-none');
    errBox.classList.add('d-none');

    fetch('api_appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'cancel', booking_id: _activeCancelId, reason: reason })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        spinner.classList.add('d-none');
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('cancelModal')).hide();
            showToast(data.message, 'success');
            setTimeout(function() { location.reload(); }, 1800);
        } else if (data.error === 'restriction') {
            errBox.textContent = data.message;
            errBox.classList.remove('d-none');
        } else {
            errBox.textContent = data.error || 'An error occurred. Please try again.';
            errBox.classList.remove('d-none');
        }
    })
    .catch(function() {
        btn.disabled = false;
        spinner.classList.add('d-none');
        errBox.textContent = 'Network error. Please try again.';
        errBox.classList.remove('d-none');
    });
}

// ── Reschedule ────────────────────────────────────────────────────────
function showRescheduleModal(btn) {
    _activeRescheduleId = parseInt(btn.dataset.bookingId, 10);
    _activeTypeId       = parseInt(btn.dataset.typeId, 10);
    var minDays         = parseInt(btn.dataset.minDays, 10) || 1;
    _selectedTime       = null;
    document.getElementById('rescheduleApptName').textContent = btn.dataset.typeName;
    // Compute minimum date from advance_booking_min_days
    var minDate = new Date();
    minDate.setDate(minDate.getDate() + (minDays > 0 ? minDays : 1));
    var minDateStr = minDate.toISOString().split('T')[0];
    document.getElementById('rescheduleDate').min   = minDateStr;
    document.getElementById('rescheduleDate').value = '';
    document.getElementById('rescheduleTimesSection').style.display = 'none';
    document.getElementById('rescheduleTimesGrid').innerHTML = '';
    document.getElementById('rescheduleNoSlots').classList.add('d-none');
    document.getElementById('rescheduleReason').value = '';
    document.getElementById('rescheduleError').classList.add('d-none');
    document.getElementById('confirmRescheduleBtn').disabled = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rescheduleModal')).show();
}

function loadRescheduleSlots() {
    var date    = document.getElementById('rescheduleDate').value;
    var grid    = document.getElementById('rescheduleTimesGrid');
    var noSlots = document.getElementById('rescheduleNoSlots');
    var section = document.getElementById('rescheduleTimesSection');

    _selectedTime = null;
    document.getElementById('confirmRescheduleBtn').disabled = true;
    grid.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary me-2"></div> Loading...';
    section.style.display = 'block';
    noSlots.classList.add('d-none');

    if (!date || !_activeTypeId) return;

    fetch('/backend/public/api_bookings.php?date=' + encodeURIComponent(date) + '&appointment_type_id=' + _activeTypeId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        grid.innerHTML = '';
        var slots = data.available_slots || [];
        if (!Array.isArray(slots) || slots.length === 0) {
            noSlots.classList.remove('d-none');
            return;
        }
        slots.forEach(function(slot) {
            var time = typeof slot === 'object' ? (slot.time || '') : slot;
            var label = formatTime12(time);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-primary btn-sm';
            btn.textContent = label;
            btn.dataset.time = time;
            btn.addEventListener('click', function() {
                document.querySelectorAll('#rescheduleTimesGrid .btn').forEach(function(b) {
                    b.classList.remove('btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-primary');
                _selectedTime = time;
                document.getElementById('confirmRescheduleBtn').disabled = false;
            });
            grid.appendChild(btn);
        });
    })
    .catch(function() {
        grid.innerHTML = '<span class="text-danger">Could not load available times.</span>';
    });
}

function formatTime12(t) {
    if (!t) return t;
    var parts = t.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1] || '00';
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + m + ' ' + ampm;
}

function submitReschedule() {
    if (!_activeRescheduleId || !_selectedTime) return;
    var date    = document.getElementById('rescheduleDate').value;
    var reason  = document.getElementById('rescheduleReason').value.trim();
    var btn     = document.getElementById('confirmRescheduleBtn');
    var spinner = document.getElementById('rescheduleBtnSpinner');
    var errBox  = document.getElementById('rescheduleError');

    btn.disabled = true;
    spinner.classList.remove('d-none');
    errBox.classList.add('d-none');

    fetch('api_appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:     'reschedule',
            booking_id: _activeRescheduleId,
            new_date:   date,
            new_time:   _selectedTime,
            reason:     reason
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        spinner.classList.add('d-none');
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('rescheduleModal')).hide();
            showToast(data.message, 'success');
            setTimeout(function() { location.reload(); }, 1800);
        } else {
            btn.disabled = false;
            errBox.textContent = (data.error === 'restriction') ? data.message : (data.error || 'An error occurred.');
            errBox.classList.remove('d-none');
        }
    })
    .catch(function() {
        btn.disabled = false;
        spinner.classList.add('d-none');
        errBox.textContent = 'Network error. Please try again.';
        errBox.classList.remove('d-none');
    });
}

// ── Toast helper ──────────────────────────────────────────────────────
function showToast(msg, type) {
    var toast   = document.getElementById('outcomeToast');
    var msgEl   = document.getElementById('outcomeToastMsg');
    toast.className = 'toast align-items-center border-0 text-white bg-' + (type === 'success' ? 'success' : 'danger');
    msgEl.textContent = msg;
    new bootstrap.Toast(toast, { delay: 4000 }).show();
}
</script>

<?php include '../portal/includes/footer.php'; ?>

