<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/google_calendar.php';
require_once '../backend/includes/workflow_helper.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// AJAX: get eligible package credits for a client + appointment type
if (isset($_GET['ajax']) && $_GET['ajax'] === 'credits') {
    $client_id  = safe_int($_GET['client_id']  ?? 0);
    $type_id    = safe_int($_GET['type_id']    ?? 0);
    $result = [];
    if ($type_id && $client_id) {
        // Fetch active, non-expired package credits matching this appointment type
        $stmt = $conn->prepare("
            SELECT cpc.id, cpc.client_package_id, cpc.appointment_type_id,
                   (cpc.total_credits - cpc.used_credits) AS remaining,
                   cp.package_name, cp.expires_at
            FROM client_package_credits cpc
            JOIN client_packages cp ON cpc.client_package_id = cp.id
            WHERE cpc.client_id = ?
              AND cpc.appointment_type_id = ?
              AND cp.is_active = 1
              AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
              AND (cpc.total_credits - cpc.used_credits) > 0
            ORDER BY cp.expires_at ASC, cp.purchased_at ASC
        ");
        $stmt->execute([$client_id, $type_id]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Get clients for dropdown
$stmt = $conn->query("SELECT id, name, email FROM clients ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active appointment types
$stmt = $conn->query("SELECT * FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appointment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = safe_int($_POST['client_id'] ?? 0);
    $appointment_type_id = safe_int($_POST['appointment_type_id'] ?? 0);
    $booking_date = $_POST['booking_date'];
    $booking_time = $_POST['booking_time'];
    $pets = isset($_POST['pets']) ? $_POST['pets'] : [];
    $notes = trim(scalar_string($_POST['notes'] ?? ''));
    $override_forms = isset($_POST['override_forms']) ? 1 : 0;
    $override_contract = isset($_POST['override_contract']) ? 1 : 0;
    $override_credits = isset($_POST['override_credits']) ? 1 : 0;
    // Package credit row ID selected by admin
    $package_credit_id = safe_int($_POST['package_credit_id'] ?? 0);
    // Location fields
    $location_type = trim(scalar_string($_POST['location_type'] ?? ''));
    $location_value = trim(scalar_string($_POST['location_value'] ?? ''));
    if (!is_array($pets)) {
        $pets = [];
    }
    
    try {
        // Get appointment type details
        $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ?");
        $stmt->execute([$appointment_type_id]);
        $apt_type = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$apt_type) {
            throw new Exception("Invalid appointment type");
        }
        
        // Get client details
        $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) {
            throw new Exception("Invalid client");
        }
        
        // Rule enforcement (unless overridden)
        $errors = [];
        
        // Check required forms
        if ($apt_type['requires_forms'] && !$override_forms) {
            $stmt = $conn->prepare("
                SELECT atf.form_template_id FROM appointment_type_forms atf
                WHERE atf.appointment_type_id = ?
            ");
            $stmt->execute([$apt_type['id']]);
            $required_form_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($required_form_ids)) {
                $stmt2 = $conn->prepare("
                    SELECT DISTINCT fs.template_id
                    FROM form_submissions fs
                    JOIN appointment_type_forms atf ON atf.form_template_id = fs.template_id
                    WHERE fs.client_id = ? AND fs.status = 'submitted' AND atf.appointment_type_id = ?
                ");
                $stmt2->execute([$client_id, $apt_type['id']]);
                $submitted_form_ids = $stmt2->fetchAll(PDO::FETCH_COLUMN);
                $missing = array_diff($required_form_ids, $submitted_form_ids);
                if (!empty($missing)) {
                    $errors[] = "Client must submit required forms before booking (or override)";
                }
            }
        }
        
        // Check required contract
        if (!empty($apt_type['contract_template_id']) && !$override_contract) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'signed'");
            $stmt->execute([$client_id]);
            $contract_count = $stmt->fetchColumn();
            if ($contract_count == 0) {
                $errors[] = "Client must sign required contract before booking (or override)";
            }
        }
        
        // Resolve which credit source to use
        $use_package_credit  = false;
        $package_credit_row  = null;

        if ($apt_type['consumes_credits'] && !$override_credits) {
            if ($package_credit_id > 0) {
                // Validate the selected package credit for this appointment type
                $stmt = $conn->prepare("
                    SELECT cpc.* FROM client_package_credits cpc
                    JOIN client_packages cp ON cpc.client_package_id = cp.id
                    WHERE cpc.id = ? AND cpc.client_id = ?
                      AND cpc.appointment_type_id = ?
                      AND cp.is_active = 1
                      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$package_credit_id, $client_id, $appointment_type_id]);
                $package_credit_row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$package_credit_row) {
                    $errors[] = "Selected package credit is not valid for this appointment type.";
                } elseif ((safe_int($package_credit_row['total_credits']) - safe_int($package_credit_row['used_credits'])) < 1) {
                    $errors[] = "Selected package credit has no remaining balance.";
                } else {
                    $use_package_credit = true;
                }
            } else {
                $errors[] = "No credit source selected — select a package credit or override to proceed";
            }
        }
        
        if (!empty($errors) && !$override_forms && !$override_contract && !$override_credits) {
            setFlashMessage(implode('<br>', $errors), 'danger');
        } else {
            // Resolve location type and value
            $is_fixed = !empty($apt_type['is_mini_session']) || !empty($apt_type['is_field_rental']);
            if ($is_fixed) {
                $location_type = 'fixed';
                $location_value = !empty($apt_type['is_mini_session'])
                    ? ($apt_type['mini_session_location'] ?? '')
                    : ($apt_type['field_rental_location'] ?? '');
            }

            // Determine which location types are allowed for this appointment type
            $allowed_location_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall', 'fixed'];
            if (!$is_fixed && !empty($apt_type['location_types'])) {
                $configured = json_decode($apt_type['location_types'], true);
                if (is_array($configured) && !empty($configured)) {
                    $allowed_location_types = array_merge($configured, ['fixed']);
                }
            }

            // Validate location selection
            if (!in_array($location_type, $allowed_location_types)) {
                $errors[] = "Please select a valid location type for the appointment.";
            } elseif (in_array($location_type, ['custom_address', 'webcall']) && empty($location_value)) {
                $errors[] = $location_type === 'webcall'
                    ? "Please enter the webcall URL."
                    : "Please enter the custom address.";
            }

            // For client_address type, resolve the actual address from the client profile
            if (empty($errors) && $location_type === 'client_address') {
                $location_value = trim($client['address'] ?? '');
                if (empty($location_value)) {
                    $errors[] = "The selected client does not have an address on file. Please add an address to their profile or choose a different location type.";
                }
            }

            if (!empty($errors)) {
                setFlashMessage(implode('<br>', $errors), 'danger');
            } else {
            // Create booking
            $pets_json = json_encode($pets);
            $pkg_cred_col = $use_package_credit && is_array($package_credit_row)
                ? array_int_value($package_credit_row, 'id')
                : null;
            
            $stmt = $conn->prepare("
                INSERT INTO bookings (
                    client_id, appointment_type_id, client_name, client_email, client_phone,
                    appointment_date, appointment_time, service_type, notes, status,
                    pets, override_forms, override_contract, override_credits,
                    package_credit_id, location_type, location, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $client_id, $appointment_type_id,
                $client['name'], $client['email'], $client['phone'],
                $booking_date, $booking_time, $apt_type['name'], $notes,
                $pets_json, $override_forms, $override_contract, $override_credits,
                $pkg_cred_col, $location_type, $location_value
            ]);
            
            $booking_id = scalar_string($conn->lastInsertId());
            
            // Trigger auto-enrollment for matching workflow triggers
            $workflow_helper = new WorkflowHelper($conn);
            $workflow_helper->checkAppointmentTriggers($booking_id);
            
            // Consume credits
            if ($apt_type['consumes_credits'] && !$override_credits && $use_package_credit && $package_credit_row) {
                // Deduct from package credit
                $conn->prepare("
                    UPDATE client_package_credits
                    SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ")->execute([$package_credit_row['id']]);

                // Log package credit transaction
                $conn->prepare("
                    INSERT INTO package_credit_transactions
                        (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                    VALUES (?, ?, ?, 'consume', -1, ?, ?, ?)
                ")->execute([
                    $package_credit_row['id'], $client_id,
                    $package_credit_row['appointment_type_id'],
                    $booking_id,
                    "Consumed by booking #{$booking_id}",
                    $_SESSION['admin_id']
                ]);
            }
            
            // Auto-invoice if configured
            if ($apt_type['auto_invoice']) {
                $default_amount = floatval($apt_type['default_amount'] ?? 0);
                $invoice_due_days = (int)($apt_type['invoice_due_days'] ?? 7);
                $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $issue_date = date('Y-m-d');
                $due_date = date('Y-m-d', safe_timestamp(strtotime("+{$invoice_due_days} days")));
                $invoice_stmt = $conn->prepare("
                    INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, subtotal, tax_rate, tax_amount, total_amount, notes, status)
                    VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, 'draft')
                ");
                $invoice_notes = "Auto-generated for booking #{$booking_id} ({$apt_type['name']})";
                $invoice_stmt->execute([$invoice_number, $client_id, $issue_date, $due_date, $default_amount, $default_amount, $invoice_notes]);
                $invoice_id = $conn->lastInsertId();
                $item_stmt = $conn->prepare("
                    INSERT INTO invoice_items (invoice_id, item_type, description, quantity, rate, amount)
                    VALUES (?, 'custom', ?, 1, ?, ?)
                ");
                $item_stmt->execute([$invoice_id, $apt_type['name'], $default_amount, $default_amount]);
            }
            
            // Link pets to appointment
            if (!empty($pets)) {
                foreach ($pets as $pet_id) {
                    $stmt = $conn->prepare("INSERT INTO appointment_pets (booking_id, pet_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                    $stmt->execute([$booking_id, safe_int($pet_id)]);
                }
            }

            // Fetch the full booking row for email/calendar
            $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $new_booking = $stmt->fetch(PDO::FETCH_ASSOC);

            // Send confirmation email to the client
            if ($new_booking) {
                $email_service = new EmailService(null, $conn);
                $email_service->sendBookingConfirmation($new_booking);
            }

            // Push to Google Calendar (OAuth first, then service account fallback)
            if ($new_booking) {
                $google_synced    = false;
                $gcal_event_id    = null;
                if (GoogleCalendarIntegration::isOAuthConfigured()) {
                    $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
                    while ($admin_row = $stmt_admins->fetch(PDO::FETCH_ASSOC)) {
                        $cal_result = GoogleCalendarIntegration::addEventOAuth($new_booking, (int)$admin_row['admin_user_id']);
                        if ($cal_result['success']) {
                            $google_synced = true;
                            $gcal_event_id = $cal_result['event_id'] ?? null;
                            break;
                        }
                    }
                }
                if (!$google_synced) {
                    $google_calendar = new GoogleCalendarIntegration();
                    if ($google_calendar->isConfigured()) {
                        $svc_result = $google_calendar->addEvent($new_booking);
                        $gcal_event_id = $svc_result['event_id'] ?? null;
                    }
                }
                // Persist the Google event ID so we can delete it later if cancelled
                if ($gcal_event_id) {
                    $conn->prepare("UPDATE bookings SET google_event_id = ? WHERE id = ?")->execute([$gcal_event_id, $booking_id]);
                }
            }

            $_SESSION['success'] = "Booking created successfully!";
            header('Location: bookings_list.php');
            exit;
            } // end location validation else
        } // end outer errors else
    } catch (Exception $e) {
        $_SESSION['error'] = "Error creating booking: " . $e->getMessage();
    }
}

// Get pets for selected client via AJAX
if (isset($_GET['client_id']) && isset($_GET['ajax']) && $_GET['ajax'] === 'pets') {
    $client_id = safe_int($_GET['client_id']);
    $stmt = $conn->prepare("SELECT id, name, species, breed FROM pets WHERE client_id = ? AND is_active = 1");
    $stmt->execute([$client_id]);
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($pets);
    exit;
}

include '../backend/includes/header.php';
?>

<style>
    .booking-location-value,
    .booking-override-field,
    .booking-dynamic-panel {
        display: none;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
                <h2 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Create Booking</h2>
                <a href="bookings_list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Bookings
                </a>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo escape($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST" id="bookingForm">
                        <div class="row">
                            <!-- Client Selection -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client *</label>
                                <select name="client_id" id="clientSelect" class="form-select" required>
                                    <option value="">Select client...</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>">
                                            <?php echo escape($client['name']); ?> (<?php echo escape($client['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Appointment Type -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Appointment Type *</label>
                                <select name="appointment_type_id" id="appointmentTypeSelect" class="form-select" required>
                                    <option value="">Select type...</option>
                                    <?php foreach ($appointment_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                                data-duration="<?php echo $type['duration_minutes']; ?>"
                                                data-requires-forms="<?php echo $type['requires_forms']; ?>"
                                                data-requires-contract="<?php echo !empty($type['contract_template_id']) ? '1' : '0'; ?>"
                                                data-consumes-credits="<?php echo $type['consumes_credits']; ?>"
                                                data-credit-count="<?php echo $type['credit_count']; ?>"
                                                data-is-mini="<?php echo !empty($type['is_mini_session']) ? '1' : '0'; ?>"
                                                data-is-field="<?php echo !empty($type['is_field_rental']) ? '1' : '0'; ?>"
                                                data-is-group="<?php echo !empty($type['is_group_class']) ? '1' : '0'; ?>"
                                                data-fixed-location="<?php
                                                    if (!empty($type['is_mini_session'])) {
                                                        echo htmlspecialchars($type['mini_session_location'] ?? '');
                                                    } elseif (!empty($type['is_field_rental'])) {
                                                        echo htmlspecialchars($type['field_rental_location'] ?? '');
                                                    } elseif (!empty($type['is_group_class'])) {
                                                        echo htmlspecialchars($type['group_class_location'] ?? '');
                                                    }
                                                ?>"
                                                data-location-types="<?php echo htmlspecialchars($type['location_types'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($type['name']); ?> (<?php echo $type['duration_minutes']; ?> min)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="typeInfo"></small>
                            </div>

                            <!-- Date -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date *</label>
                                <input type="date" name="booking_date" class="form-control" required 
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <!-- Time -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Time *</label>
                                <input type="time" name="booking_time" class="form-control" required>
                            </div>

                            <!-- Pets Selection -->
                            <div class="col-12 mb-3">
                                <label class="form-label">Pets Involved</label>
                                <div id="petsContainer" class="border rounded p-3">
                                    <p class="text-muted mb-0">Select a client to see their pets</p>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="col-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Booking notes..."></textarea>
                            </div>

                            <!-- Location — rendered dynamically by JS based on appointment type config -->
                            <div class="col-12 mb-3 booking-dynamic-panel" id="locationSection">
                                <div class="card border-primary">
                                    <div class="card-header bg-primary text-white py-2">
                                        <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2" aria-hidden="true"></i>Appointment Location <span class="text-warning">*</span></h6>
                                    </div>
                                    <div class="card-body" id="locationCardBody">
                                        <!-- Populated by renderLocationSelector() -->
                                    </div>
                                </div>
                            </div>

                            <!-- Package Credits Selector -->
                            <div class="col-12 mb-3 booking-dynamic-panel" id="packageCreditsContainer">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white py-2">
                                        <h6 class="mb-0"><i class="fas fa-box-open me-2"></i>Package Credits</h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-muted small mb-2">
                                            This appointment type consumes credits. Select a package credit to use, or leave on "Legacy Credits" to use the client's general credit balance.
                                        </p>
                                        <select name="package_credit_id" id="packageCreditSelect" class="form-select">
                                            <option value="0">— Use Legacy Credits —</option>
                                        </select>
                                        <div id="noPkgCreditsMsg" class="text-muted small mt-2 booking-dynamic-panel">
                                            <i class="fas fa-info-circle"></i> No eligible package credits found for this client and appointment type.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Rule Overrides -->
                            <div class="col-12 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Admin Overrides</h6>
                                        <p class="text-muted small mb-3">Check these to bypass rule enforcement</p>
                                        
                                        <div class="form-check mb-2 booking-override-field" id="overrideFormsContainer">
                                            <input type="checkbox" class="form-check-input" name="override_forms" id="overrideForms">
                                            <label class="form-check-label" for="overrideForms">
                                                <strong>Override Required Forms</strong>
                                                <small class="text-danger d-block">Client may not have submitted required forms</small>
                                            </label>
                                        </div>
                                        
                                        <div class="form-check mb-2 booking-override-field" id="overrideContractContainer">
                                            <input type="checkbox" class="form-check-input" name="override_contract" id="overrideContract">
                                            <label class="form-check-label" for="overrideContract">
                                                <strong>Override Required Contract</strong>
                                                <small class="text-danger d-block">Client may not have signed required contract</small>
                                            </label>
                                        </div>
                                        
                                        <div class="form-check mb-2 booking-override-field" id="overrideCreditsContainer">
                                            <input type="checkbox" class="form-check-input" name="override_credits" id="overrideCredits">
                                            <label class="form-check-label" for="overrideCredits">
                                                <strong>Override Credit Requirement</strong>
                                                <small class="text-danger d-block">Client may not have sufficient credits</small>
                                            </label>
                                        </div>
                                        
                                        <p class="text-muted small mb-0 mt-2" id="noOverridesMsg">No overrides needed for this appointment type</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit -->
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-check-circle"></i> Create Booking
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const LOC_TYPE_DEFS = {
    'client_address': { label: "Client's Registered Address",     icon: 'fa-home',           needsValue: false },
    'custom_address': { label: 'Custom Address',                   icon: 'fa-map-marker-alt', needsValue: true,  valuePlaceholder: 'Enter full address',           valueLabel: 'Address *',      valueType: 'text' },
    'phone_inbound':  { label: 'Phone Call (Inbound)',             icon: 'fa-phone',          needsValue: false },
    'phone_outbound': { label: 'Phone Call (Outbound)',            icon: 'fa-phone',          needsValue: false },
    'webcall':        { label: 'Webcall (Zoom, Google Meet, etc.)',icon: 'fa-video',          needsValue: true,  valuePlaceholder: 'https://zoom.us/j/...', valueLabel: 'Webcall URL *',  valueType: 'url' },
};

function setHidden(element, hidden) {
    if (element) {
        element.style.display = hidden ? 'none' : 'block';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const clientSelect = document.getElementById('clientSelect');
    const appointmentTypeSelect = document.getElementById('appointmentTypeSelect');
    const petsContainer = document.getElementById('petsContainer');
    const typeInfo = document.getElementById('typeInfo');
    const packageCreditsContainer = document.getElementById('packageCreditsContainer');
    const packageCreditSelect = document.getElementById('packageCreditSelect');
    const noPkgCreditsMsg = document.getElementById('noPkgCreditsMsg');
    const locationSection = document.getElementById('locationSection');
    const locationCardBody = document.getElementById('locationCardBody');

    // Render location selector based on allowed types for the selected appointment type
    function renderLocationSelector(option) {
        if (!option || !option.value) {
            setHidden(locationSection, true);
            locationCardBody.innerHTML = '';
            return;
        }

        const isMini = option.dataset.isMini === '1';
        const isField = option.dataset.isField === '1';
        const isGroup = option.dataset.isGroup === '1';
        const fixedLoc = option.dataset.fixedLocation || '';

        if (isMini || isField || isGroup) {
            // Fixed location: display it prominently
            setHidden(locationSection, false);
            locationCardBody.innerHTML = `
                <p class="text-muted small mb-1">This appointment type has a fixed location:</p>
                <p class="mb-0 fw-bold"><i class="fas fa-location-dot me-2 text-primary" aria-hidden="true"></i>${escapeHtml(fixedLoc || '(No location set)')}</p>
                <input type="hidden" name="location_type" value="fixed">
                <input type="hidden" name="location_value" value="${escapeHtml(fixedLoc)}">`;
            return;
        }

        // Determine allowed types from data attribute
        let allowed = [];
        const raw = option.dataset.locationTypes || '';
        if (raw) {
            try { allowed = JSON.parse(raw); } catch(e) {}
        }
        if (!Array.isArray(allowed) || allowed.length === 0) {
            allowed = Object.keys(LOC_TYPE_DEFS); // Default: all types
        }

        setHidden(locationSection, false);

        if (allowed.length === 1) {
            // Single option: display prominently without dropdown
            const lt = allowed[0];
            const def = LOC_TYPE_DEFS[lt] || { label: lt, icon: 'fa-map-marker-alt', needsValue: false };
            let html = `
                <p class="text-muted small mb-2">Location for this appointment:</p>
                <p class="mb-2 fw-bold"><i class="fas ${def.icon} me-2 text-primary" aria-hidden="true"></i>${escapeHtml(def.label)}</p>
                <input type="hidden" name="location_type" value="${escapeHtml(lt)}">`;
            if (def.needsValue) {
                html += `
                <div class="mt-2">
                    <label class="form-label">${escapeHtml(def.valueLabel)}</label>
                    <input type="text" name="location_value" id="locationValueInput" class="form-control"
                           placeholder="${escapeHtml(def.valuePlaceholder)}" required>
                </div>`;
            }
            locationCardBody.innerHTML = html;
        } else {
            // Multiple options: dropdown + conditional value field
            let opts = `<option value="">— Select location type —</option>`;
            allowed.forEach(lt => {
                const def = LOC_TYPE_DEFS[lt] || { label: lt };
                opts += `<option value="${escapeHtml(lt)}">${escapeHtml(def.label)}</option>`;
            });
            locationCardBody.innerHTML = `
                <label class="form-label">Location Type *</label>
                <select name="location_type" id="locationTypeSelect" class="form-select" required onchange="onLocationTypeChange(this)">
                    ${opts}
                </select>
                <div id="locationValueWrapper" class="mt-2 booking-location-value">
                    <label class="form-label" id="locationValueLabel">Value *</label>
                    <input type="text" name="location_value" id="locationValueInput" class="form-control" placeholder="">
                </div>`;
        }
    }

    function loadPkgCredits() {
        const clientId = clientSelect.value;
        const typeId   = appointmentTypeSelect.value;
        const option   = appointmentTypeSelect.options[appointmentTypeSelect.selectedIndex];
        const consumesCredits = option && option.dataset.consumesCredits === '1';

        if (!consumesCredits || !clientId || !typeId) {
            setHidden(packageCreditsContainer, true);
            return;
        }

        setHidden(packageCreditsContainer, false);

        fetch(`?ajax=credits&client_id=${clientId}&type_id=${typeId}`)
            .then(r => r.json())
            .then(credits => {
                packageCreditSelect.innerHTML = '<option value="0">— Use Legacy Credits —</option>';
                if (credits.length === 0) {
                    setHidden(noPkgCreditsMsg, false);
                } else {
                    setHidden(noPkgCreditsMsg, true);
                    credits.forEach(c => {
                        const expiry = c.expires_at ? ` (expires ${c.expires_at.substring(0,10)})` : '';
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.textContent = `${c.package_name} — ${c.remaining} remaining${expiry}`;
                        packageCreditSelect.appendChild(opt);
                    });
                }
            });
    }

    // Load pets when client selected
    clientSelect.addEventListener('change', function() {
        const clientId = this.value;
        if (!clientId) {
            petsContainer.innerHTML = '<p class="text-muted mb-0">Select a client to see their pets</p>';
            setHidden(packageCreditsContainer, true);
            return;
        }
        
        fetch(`?client_id=${clientId}&ajax=pets`)
            .then(r => r.json())
            .then(pets => {
                if (pets.length === 0) {
                    petsContainer.innerHTML = '<p class="text-muted mb-0">No pets found for this client</p>';
                } else {
                    let html = '';
                    pets.forEach(pet => {
                        html += `
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="pets[]" value="${pet.id}" id="pet${pet.id}">
                                <label class="form-check-label" for="pet${pet.id}">
                                    ${pet.name} - ${pet.species}${pet.breed ? ' (' + pet.breed + ')' : ''}
                                </label>
                            </div>
                        `;
                    });
                    petsContainer.innerHTML = html;
                }
            });

        loadPkgCredits();
    });
    
    // Update type info, show override options, location selector, and refresh package credits
    appointmentTypeSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        renderLocationSelector(option);
        if (!option.value) {
            typeInfo.textContent = '';
            setHidden(document.getElementById('noOverridesMsg'), false);
            setHidden(document.getElementById('overrideFormsContainer'), true);
            setHidden(document.getElementById('overrideContractContainer'), true);
            setHidden(document.getElementById('overrideCreditsContainer'), true);
            setHidden(packageCreditsContainer, true);
            return;
        }
        
        const duration = option.dataset.duration;
        const requiresForms = option.dataset.requiresForms === '1';
        const requiresContract = option.dataset.requiresContract === '1';
        const consumesCredits = option.dataset.consumesCredits === '1';
        const creditCount = option.dataset.creditCount;
        
        let info = `Duration: ${duration} minutes. `;
        if (requiresForms) info += 'Requires forms. ';
        if (requiresContract) info += 'Requires contract. ';
        if (consumesCredits) info += `Consumes ${creditCount} credit(s). `;
        
        typeInfo.textContent = info;
        
        // Show override checkboxes
        const anyOverrides = requiresForms || requiresContract || consumesCredits;
        setHidden(document.getElementById('noOverridesMsg'), anyOverrides);
        setHidden(document.getElementById('overrideFormsContainer'), !requiresForms);
        setHidden(document.getElementById('overrideContractContainer'), !requiresContract);
        setHidden(document.getElementById('overrideCreditsContainer'), !consumesCredits);

        loadPkgCredits();
    });
});

// Handle location type dropdown change (called via onchange attribute in dynamically rendered HTML)
function onLocationTypeChange(sel) {
    const type = sel.value;
    const wrapper = document.getElementById('locationValueWrapper');
    const label = document.getElementById('locationValueLabel');
    const input = document.getElementById('locationValueInput');
    if (!wrapper) return;
    const def = LOC_TYPE_DEFS[type];
    if (def && def.needsValue) {
        setHidden(wrapper, false);
        if (label) label.textContent = def.valueLabel;
        if (input) {
            input.placeholder = def.valuePlaceholder;
            input.type = def.valueType || 'text';
            input.required = true;
        }
    } else {
        setHidden(wrapper, true);
        if (input) { input.required = false; input.value = ''; }
    }
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}
</script>

<?php include '../backend/includes/footer.php'; ?>
