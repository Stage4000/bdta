<?php
/**
 * Portal Credit Booking Page
 * Authenticated booking flow for logged-in portal clients using package credits.
 * Provides contact/pet selection, add-pet capability, and intelligent form/contract skipping.
 */
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// ── Resolve appointment type from URL (link= or type=) ──────────────────────
$appointment_type_id = 0;
$selected_type       = null;

if (!empty($_GET['link'])) {
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE unique_link = ? AND is_active = 1");
    $stmt->execute([trim($_GET['link'])]);
    $selected_type = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selected_type) {
        $appointment_type_id = (int)$selected_type['id'];
    }
} elseif (!empty($_GET['type'])) {
    $appointment_type_id = intval($_GET['type']);
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ? AND is_active = 1");
    $stmt->execute([$appointment_type_id]);
    $selected_type = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$selected_type) {
    setFlashMessage('Invalid or unavailable appointment type.', 'error');
    redirect(PORTAL_URL . 'credits.php');
}

// ── Load client profile ──────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    setFlashMessage('Client record not found.', 'error');
    redirect(PORTAL_URL . 'credits.php');
}

// ── Load contacts (additional people linked to this account) ─────────────────
$stmt = $conn->prepare("
    SELECT * FROM client_contacts
    WHERE client_id = ?
    ORDER BY is_primary DESC, name
");
$stmt->execute([$client_id]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Load active pets ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, name, species, breed
    FROM pets
    WHERE client_id = ? AND is_active = 1
    ORDER BY name
");
$stmt->execute([$client_id]);
$pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Required forms for this appointment type ─────────────────────────────────
$all_required_forms = [];
$stmt = $conn->prepare("
    SELECT ft.id, ft.name, ft.description, ft.fields, ft.required_frequency
    FROM appointment_type_forms atf
    JOIN form_templates ft ON atf.form_template_id = ft.id
    WHERE atf.appointment_type_id = ? AND ft.is_active = 1
    ORDER BY ft.name
");
$stmt->execute([$appointment_type_id]);
$form_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($form_rows as &$fr) {
    $fr['fields'] = json_decode($fr['fields'], true) ?: [];
}
unset($fr);
$all_required_forms = $form_rows;

// ── Required contract for this appointment type ──────────────────────────────
$required_contract = null;
if (!empty($selected_type['contract_template_id'])) {
    $stmt = $conn->prepare("
        SELECT id, name, template_text, renewal_period_months
        FROM contract_templates
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$selected_type['contract_template_id']]);
    $required_contract = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Contract skip logic ──────────────────────────────────────────────────────
// Check whether the client already has a valid signed contract for this template
// within the configured renewal period. We match on the contract template ID
// (not the appointment type) so any prior signing of this same template counts,
// regardless of which appointment type it was booked under.
$skip_contract = false;
$contract_skip_reason = '';
if ($required_contract) {
    $renewal_months = max(1, intval($required_contract['renewal_period_months'] ?? 12));
    $stmt = $conn->prepare("
        SELECT b.contract_accepted_at
        FROM bookings b
        JOIN appointment_types apt ON apt.id = b.appointment_type_id
        WHERE b.client_id = ?
          AND apt.contract_template_id = ?
          AND b.contract_accepted = 1
          AND b.contract_accepted_at IS NOT NULL
        ORDER BY b.contract_accepted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$client_id, $required_contract['id']]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prev) {
        $expiry = strtotime($prev['contract_accepted_at'] . " +{$renewal_months} months");
        if ($expiry >= time()) {
            $skip_contract   = true;
            $contract_skip_reason = 'Your ' . escape($required_contract['name']) .
                ' is current and does not need to be re-signed until ' .
                date('F j, Y', $expiry) . '.';
        }
    }
}

// ── Form skip logic ──────────────────────────────────────────────────────────
// Only include forms that genuinely need to be completed again.
$forms_needing_completion = [];
foreach ($all_required_forms as $form) {
    $freq = trim($form['required_frequency'] ?? '');
    if ($freq === 'per_booking' || $freq === '') {
        $forms_needing_completion[] = $form;
        continue;
    }
    // Determine look-back window based on frequency keyword
    $cutoff = match ($freq) {
        'annual'      => strtotime('-1 year'),
        'semi_annual' => strtotime('-6 months'),
        'monthly'     => strtotime('-1 month'),
        default       => null, // 'once' or unknown — check any prior submission
    };
    $stmt = $conn->prepare("
        SELECT submitted_at FROM form_submissions
        WHERE client_id = ? AND template_id = ?
        ORDER BY submitted_at DESC LIMIT 1
    ");
    $stmt->execute([$client_id, $form['id']]);
    $last_sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$last_sub) {
        $forms_needing_completion[] = $form; // Never submitted
    } elseif ($cutoff !== null && strtotime($last_sub['submitted_at']) < $cutoff) {
        $forms_needing_completion[] = $form; // Due for renewal
    }
    // If $cutoff is null ('once') and there IS a prior submission, skip the form.
}

// ── Location types config (same logic as book.php) ───────────────────────────
$is_fixed_type = !empty($selected_type['is_mini_session']) || !empty($selected_type['is_field_rental']) || !empty($selected_type['is_group_class']);
$loc_types_all = [
    'client_address' => ['label' => 'My registered address',                'needsValue' => false],
    'custom_address' => ['label' => 'A different address',                  'needsValue' => true,  'placeholder' => 'Enter full address',         'valueLabel' => 'Address *',        'type' => 'text'],
    'phone_inbound'  => ['label' => 'Phone call — I will call the trainer', 'needsValue' => false],
    'phone_outbound' => ['label' => 'Phone call — Trainer will call me',    'needsValue' => false],
    'webcall'        => ['label' => 'Video call (Zoom, Google Meet, etc.)', 'needsValue' => true,  'placeholder' => 'https://zoom.us/j/...',      'valueLabel' => 'Video call URL *', 'type' => 'url'],
];
$allowed_loc = [];
if (!$is_fixed_type && !empty($selected_type['location_types'])) {
    $decoded = json_decode($selected_type['location_types'], true);
    if (is_array($decoded) && !empty($decoded)) {
        $allowed_loc = array_filter($decoded, fn($t) => isset($loc_types_all[$t]));
    }
}
if (empty($allowed_loc) && !$is_fixed_type) {
    $allowed_loc = array_keys($loc_types_all);
}

$page_title = 'Book ' . escape($selected_type['name']);
include '../portal/includes/header.php';
?>

<style>
    .step-indicator { display: flex; justify-content: space-between; margin-bottom: 2rem; position: relative; }
    .step-indicator::before { content: ''; position: absolute; top: 20px; left: 0; right: 0; height: 2px; background: #e5e7eb; z-index: 0; }
    .step { flex: 1; text-align: center; position: relative; z-index: 1; }
    .step-circle { width: 40px; height: 40px; border-radius: 50%; background: #e5e7eb; color: #6b7280; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.5rem; font-weight: 600; }
    .step.active .step-circle { background: #9a0073; color: white; }
    .step.completed .step-circle { background: #0a9a9c; color: white; }
    .step-label { font-size: 0.875rem; color: #6b7280; }
    .step.active .step-label { color: #9a0073; font-weight: 600; }
    .form-step { display: none; }
    .form-step.active { display: block; }
    .time-slot { padding: 0.75rem 1rem; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; text-align: center; transition: all .2s; background: white; }
    .time-slot:hover { border-color: #9a0073; background: #fdf4ff; }
    .time-slot.selected { border-color: #9a0073; background: #9a0073; color: white; font-weight: 600; }
    .pet-option { border: 2px solid #e5e7eb; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.5rem; cursor: pointer; transition: all .2s; }
    .pet-option:hover { border-color: #9a0073; }
    .pet-option.selected { border-color: #9a0073; background: #fdf4ff; }
    .font-dancing     { font-family: 'Dancing Script', cursive; }
    .font-pacifico    { font-family: 'Pacifico', cursive; }
    .font-satisfy     { font-family: 'Satisfy', cursive; }
    .font-great-vibes { font-family: 'Great Vibes', cursive; }
    .font-allura      { font-family: 'Allura', cursive; }
    .sig-preview { font-size: 2.2rem; color: #1a1a2e; min-height: 3.5rem; border-bottom: 2px solid #495057; padding-bottom: 0.25rem; line-height: 1.2; }
    .font-option-btn { cursor: pointer; border: 2px solid #dee2e6; border-radius: 8px; padding: 0.5rem 1rem; font-size: 1.5rem; background: white; transition: border-color .2s; }
    .font-option-btn.selected, .font-option-btn:hover { border-color: #9a0073; background: #fdf0f9; }
</style>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Pacifico&family=Satisfy&family=Great+Vibes&family=Allura&display=swap" rel="stylesheet">

<h2 class="mb-1"><i class="fas fa-calendar-plus me-2"></i>Book <?= escape($selected_type['name']) ?></h2>
<?php if (!empty($selected_type['description'])): ?>
<p class="text-muted mb-4"><?= escape($selected_type['description']) ?></p>
<?php else: ?>
<div class="mb-4"></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" data-step="1"><div class="step-circle">1</div><div class="step-label">Date</div></div>
            <div class="step" data-step="2"><div class="step-circle">2</div><div class="step-label">Time</div></div>
            <div class="step" data-step="3"><div class="step-circle">3</div><div class="step-label">Details</div></div>
            <div class="step" data-step="4"><div class="step-circle">4</div><div class="step-label">Confirm</div></div>
        </div>

        <div id="alertArea"></div>

        <form id="bookingForm">
            <input type="hidden" id="appointmentTypeId" value="<?= intval($selected_type['id']) ?>">

            <!-- ── Step 1: Select Date ─────────────────────────────────────── -->
            <div class="form-step active" data-step="1">
                <h5 class="mb-3">Choose a Date</h5>
                <div class="row">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Date *</label>
                        <input type="date" class="form-control form-control-lg" id="appointmentDate"
                               min="<?= date('Y-m-d') ?>" required>
                        <small class="text-muted mt-1 d-block">Select a date; available times load in the next step.</small>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-4">
                    <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">
                        Continue <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>

            <!-- ── Step 2: Select Time ─────────────────────────────────────── -->
            <div class="form-step" data-step="2">
                <h5 class="mb-3">Choose a Time</h5>
                <p class="text-muted mb-3"><i class="fas fa-calendar me-1"></i>Selected date: <strong id="selectedDateDisplay">—</strong></p>
                <div class="alert alert-info" id="loadingSlots"><div class="spinner-border spinner-border-sm me-2"></div> Loading available times…</div>
                <div id="timeSlotsContainer" class="row g-2" style="display:none;"></div>
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()"><i class="fas fa-arrow-left me-2"></i> Back</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()" id="step3Next" disabled>Continue <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
            </div>

            <!-- ── Step 3: Details (contact, pet, info, forms, contract) ───── -->
            <div class="form-step" data-step="3">
                <h5 class="mb-3">Your Details</h5>

                <!-- Contact selection -->
                <?php if (!empty($contacts)): ?>
                <div class="mb-4">
                    <label class="form-label fw-bold">Who is booking? *</label>
                    <select class="form-select form-select-lg" id="contactSelect" onchange="applyContact()">
                        <option value=""
                            data-name="<?= escape($client['name']) ?>"
                            data-email="<?= escape($client['email']) ?>"
                            data-phone="<?= escape($client['phone'] ?? '') ?>">
                            <?= escape($client['name']) ?> (account holder)
                        </option>
                        <?php foreach ($contacts as $ct): ?>
                        <option value="<?= intval($ct['id']) ?>"
                            data-name="<?= escape($ct['name']) ?>"
                            data-email="<?= escape($ct['email']) ?>"
                            data-phone="<?= escape($ct['phone']) ?>">
                            <?= escape($ct['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Manage contacts in your <a href="profile.php">Profile</a>.</small>
                </div>
                <?php endif; ?>

                <!-- Name / Email / Phone (pre-filled, editable) -->
                <div class="row mb-3">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <label class="form-label">Name *</label>
                        <input type="text" class="form-control form-control-lg" id="clientName"
                               value="<?= escape($client['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email *</label>
                        <input type="email" class="form-control form-control-lg" id="clientEmail"
                               value="<?= escape($client['email']) ?>" required>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="tel" class="form-control form-control-lg" id="clientPhone"
                               value="<?= escape($client['phone'] ?? '') ?>">
                    </div>
                </div>

                <!-- Pet selection -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Which pet(s) is this booking for?</label>
                    <?php if (empty($pets)): ?>
                        <div class="alert alert-info py-2">
                            <i class="fas fa-info-circle me-1"></i>
                            No pets on file. Add one below or manage pets in <a href="pets.php">My Pets</a>.
                        </div>
                    <?php else: ?>
                        <div id="petList">
                            <?php foreach ($pets as $pet): ?>
                            <div class="pet-option d-flex align-items-center gap-2" data-pet-id="<?= intval($pet['id']) ?>"
                                 onclick="togglePet(this)">
                                <input type="checkbox" class="form-check-input pet-checkbox"
                                       data-pet-id="<?= intval($pet['id']) ?>" style="pointer-events:none;">
                                <span class="fw-semibold"><?= escape($pet['name']) ?></span>
                                <?php if (!empty($pet['breed'])): ?><span class="text-muted small">(<?= escape($pet['breed']) ?>)</span><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Add new pet inline -->
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addPetToggleBtn"
                            onclick="document.getElementById('addPetForm').classList.toggle('d-none');this.classList.toggle('active');">
                        <i class="fas fa-plus me-1"></i> Add a New Pet
                    </button>
                    <div id="addPetForm" class="d-none mt-3 p-3 border rounded bg-light">
                        <h6 class="mb-3">New Pet Details</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Name *</label>
                                <input type="text" class="form-control" id="newPetName" placeholder="e.g. Buddy">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Species</label>
                                <input type="text" class="form-control" id="newPetSpecies" placeholder="Dog" value="Dog">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Breed</label>
                                <input type="text" class="form-control" id="newPetBreed" placeholder="e.g. Labrador">
                            </div>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-primary" onclick="addNewPet()">
                                <i class="fas fa-save me-1"></i> Save &amp; Select Pet
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-2"
                                    onclick="document.getElementById('addPetForm').classList.add('d-none');document.getElementById('addPetToggleBtn').classList.remove('active');">
                                Cancel
                            </button>
                        </div>
                        <div id="addPetStatus" class="mt-2"></div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="mb-4">
                    <label class="form-label">Notes / Special Requests</label>
                    <textarea class="form-control" id="notes" rows="3"
                              placeholder="Tell us about your pet's needs, behavior concerns, or any special requirements…"></textarea>
                </div>

                <!-- Location -->
                <div class="mb-4">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white py-2">
                            <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Appointment Location</h6>
                        </div>
                        <div class="card-body">
                        <?php if ($is_fixed_type):
                            if (!empty($selected_type['is_mini_session'])) {
                                $fixed_loc = $selected_type['mini_session_location'] ?? '';
                            } elseif (!empty($selected_type['is_field_rental'])) {
                                $fixed_loc = $selected_type['field_rental_location'] ?? '';
                            } else {
                                $fixed_loc = $selected_type['group_class_location'] ?? '';
                            }
                        ?>
                            <p class="mb-1 text-muted small">This appointment has a fixed location:</p>
                            <p class="mb-0 fw-bold"><i class="fas fa-location-dot me-2"></i><?= escape($fixed_loc) ?></p>
                            <input type="hidden" id="locationType" value="fixed">
                            <input type="hidden" id="locationValue" value="<?= escape($fixed_loc) ?>">
                        <?php elseif (count($allowed_loc) === 1):
                            $only_key = reset($allowed_loc);
                            $only_def = $loc_types_all[$only_key];
                        ?>
                            <p class="mb-2 text-muted small">Location for this appointment:</p>
                            <p class="mb-2 fw-bold"><i class="fas fa-map-marker-alt me-2"></i><?= htmlspecialchars($only_def['label']) ?></p>
                            <input type="hidden" id="locationType" value="<?= htmlspecialchars($only_key) ?>">
                            <?php if (!empty($only_def['needsValue'])): ?>
                            <div>
                                <label class="form-label"><?= htmlspecialchars($only_def['valueLabel']) ?></label>
                                <input type="<?= htmlspecialchars($only_def['type']) ?>" class="form-control form-control-lg"
                                       id="locationValue" placeholder="<?= htmlspecialchars($only_def['placeholder']) ?>" required>
                            </div>
                            <?php else: ?>
                            <input type="hidden" id="locationValue" value="">
                            <?php endif; ?>
                        <?php else: ?>
                            <label class="form-label">Where will this appointment take place? *</label>
                            <select class="form-select form-select-lg" id="locationType" onchange="handleLocationTypeChange()" required>
                                <option value="">— Select location type —</option>
                                <?php foreach ($allowed_loc as $lk): ?>
                                    <option value="<?= htmlspecialchars($lk) ?>"><?= htmlspecialchars($loc_types_all[$lk]['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="locationValueWrapper" class="mt-2" style="display:none;">
                                <label class="form-label" id="locationValueLabel">Value *</label>
                                <input type="text" class="form-control form-control-lg" id="locationValue" placeholder="">
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Required Forms (only if not skippable) -->
                <?php if (!empty($forms_needing_completion)): ?>
                <hr class="my-4">
                <h6><i class="fas fa-file-alt me-2"></i>Required Forms</h6>
                <p class="text-muted mb-3">Please complete the following forms as part of your booking.</p>
                <?php foreach ($forms_needing_completion as $form): ?>
                <div class="card mb-4" data-form-id="<?= $form['id'] ?>">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><?= htmlspecialchars($form['name']) ?></h6>
                        <?php if (!empty($form['description'])): ?>
                            <small class="text-muted"><?= htmlspecialchars($form['description']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php foreach ($form['fields'] as $fi => $field):
                            $fn = 'form_resp_' . $form['id'] . '_' . $fi;
                            $is_req = !empty($field['required']);
                            $ph = htmlspecialchars($field['placeholder'] ?? '');
                        ?>
                        <div class="mb-3">
                            <label class="form-label">
                                <?= htmlspecialchars($field['label']) ?>
                                <?php if ($is_req): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <?php if (!empty($field['description'])): ?>
                            <div class="form-text text-muted mb-1" id="field-desc-<?= $form['id'] ?>-<?= $fi ?>"><?= htmlspecialchars($field['description']) ?></div>
                            <?php endif; ?>
                            <?php
                            $aria = !empty($field['description']) ? 'aria-describedby="field-desc-' . $form['id'] . '-' . $fi . '"' : '';
                            switch ($field['type']):
                                case 'textarea': ?>
                                <textarea class="form-control" data-form-field="<?= $fi ?>"
                                          placeholder="<?= $ph ?>"
                                          <?= $aria ?>
                                          <?= $is_req ? 'required' : '' ?>></textarea>
                                <?php break; case 'select': ?>
                                <select class="form-select" data-form-field="<?= $fi ?>" <?= $aria ?> <?= $is_req ? 'required' : '' ?>>
                                    <option value="">— Select —</option>
                                    <?php foreach ($field['options'] ?? [] as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php break; case 'radio': ?>
                                <?php foreach ($field['options'] ?? [] as $oi => $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio"
                                           data-form-field="<?= $fi ?>"
                                           name="<?= $fn ?>" id="<?= $fn ?>_<?= $oi ?>"
                                           value="<?= htmlspecialchars($opt) ?>"
                                           <?= $aria ?>
                                           <?= ($is_req && $oi === 0) ? 'required' : '' ?>>
                                    <label class="form-check-label" for="<?= $fn ?>_<?= $oi ?>"><?= htmlspecialchars($opt) ?></label>
                                </div>
                                <?php endforeach;
                                break; case 'checkbox': ?>
                                <?php foreach ($field['options'] ?? [] as $oi => $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           data-form-field="<?= $fi ?>" id="<?= $fn ?>_<?= $oi ?>"
                                           value="<?= htmlspecialchars($opt) ?>"
                                           <?= $aria ?>>
                                    <label class="form-check-label" for="<?= $fn ?>_<?= $oi ?>"><?= htmlspecialchars($opt) ?></label>
                                </div>
                                <?php endforeach;
                                break; default: ?>
                                <input type="<?= htmlspecialchars($field['type']) ?>"
                                       class="form-control" data-form-field="<?= $fi ?>"
                                       placeholder="<?= $ph ?>"
                                       <?= $aria ?>
                                       <?= $is_req ? 'required' : '' ?>>
                                <?php break; endswitch; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php elseif (!empty($all_required_forms)): ?>
                <!-- All required forms are already current -->
                <div class="alert alert-success mb-4">
                    <i class="fas fa-circle-check me-2"></i>
                    Your required form(s) are already on file and up to date. No re-submission needed.
                </div>
                <?php endif; ?>

                <!-- Required Contract (only if not skippable) -->
                <?php if ($required_contract && !$skip_contract): ?>
                <hr class="my-4">
                <h6><i class="fas fa-file-contract me-2"></i>Required Contract</h6>
                <p class="text-muted mb-3">Please review and sign the following contract to continue.</p>
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><?= htmlspecialchars($required_contract['name']) ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 mb-4 bg-white"
                             style="max-height:300px;overflow-y:auto;font-size:0.9rem;"><?= $required_contract['template_text'] ?></div>
                        <!-- Typed name -->
                        <div class="mb-4">
                            <label for="contractTypedName" class="form-label fw-semibold">
                                Type your full legal name to sign <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-lg"
                                   id="contractTypedName" placeholder="Your full name"
                                   autocomplete="name" maxlength="200">
                        </div>
                        <!-- Font selector -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Choose a signature style</label>
                            <div class="d-flex flex-wrap gap-3" id="contractFontOptions">
                                <?php foreach (['font-dancing','font-pacifico','font-satisfy','font-great-vibes','font-allura'] as $i => $font): ?>
                                <button type="button" class="font-option-btn <?= $font ?> <?= $i === 0 ? 'selected' : '' ?>"
                                        data-font="<?= $font ?>">
                                    <span class="contract-font-preview">Your Name</span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <!-- Live preview -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Signature preview</label>
                            <div class="sig-preview font-dancing" id="contractSigPreview">&nbsp;</div>
                        </div>
                        <!-- Confirmation checkbox -->
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="contractConfirmation">
                            <label class="form-check-label" for="contractConfirmation">
                                I have read and agree to the terms outlined in this contract.
                            </label>
                        </div>
                        <input type="hidden" id="contractTemplateId" value="<?= intval($required_contract['id']) ?>">
                        <input type="hidden" id="contractSignatureFont" value="font-dancing">
                    </div>
                </div>
                <?php elseif ($skip_contract && $required_contract): ?>
                <!-- Contract is current — show informational notice -->
                <div class="alert alert-success mb-4">
                    <i class="fas fa-circle-check me-2"></i>
                    <?= $contract_skip_reason ?>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()"><i class="fas fa-arrow-left me-2"></i> Back</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">Continue <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
            </div>

            <!-- ── Step 4: Confirm ─────────────────────────────────────────── -->
            <div class="form-step" data-step="4">
                <h5 class="mb-4">Confirm Your Booking</h5>
                <div class="card mb-3">
                    <div class="card-body">
                        <h6 class="card-title mb-3">Appointment Summary</h6>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Service:</dt>
                            <dd class="col-sm-8"><?= escape($selected_type['name']) ?></dd>
                            <dt class="col-sm-4">Date:</dt>
                            <dd class="col-sm-8" id="confirmDate">—</dd>
                            <dt class="col-sm-4">Time:</dt>
                            <dd class="col-sm-8" id="confirmTime">—</dd>
                            <dt class="col-sm-4">Contact:</dt>
                            <dd class="col-sm-8" id="confirmName">—</dd>
                            <dt class="col-sm-4">Email:</dt>
                            <dd class="col-sm-8" id="confirmEmail">—</dd>
                            <dt class="col-sm-4">Pet(s):</dt>
                            <dd class="col-sm-8" id="confirmPets">—</dd>
                            <dt class="col-sm-4">Location:</dt>
                            <dd class="col-sm-8" id="confirmLocation">—</dd>
                        </dl>
                    </div>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info me-2"></i>
                    A confirmation email will be sent to your address with appointment details and calendar links.
                </div>
                <!-- Credit auto-applied notice -->
                <div class="alert alert-success">
                    <i class="fas fa-ticket me-2"></i>
                    A credit from your package will be applied automatically to this booking.
                </div>
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()"><i class="fas fa-arrow-left me-2"></i> Back</button>
                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                        <span class="spinner-border spinner-border-sm me-2 d-none" id="submitSpinner"></span>
                        <i class="fas fa-check-circle me-2"></i> Confirm Booking
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Success message (shown after booking) -->
<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-5">
                <i class="fas fa-circle-check text-success mb-3" style="font-size:4rem;"></i>
                <h3 class="mb-2">Booking Confirmed!</h3>
                <p class="text-muted mb-4">Your appointment has been successfully booked. Check your email for details and calendar links.</p>
                <a href="<?= PORTAL_URL ?>appointments.php" class="btn btn-primary btn-lg">View My Appointments</a>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    /* ─── State ─────────────────────────────────────────────────── */
    let currentStep = 1;
    const maxSteps  = 4;
    let selectedDate = null;
    let selectedTime = null;
    const apptTypeId   = <?= intval($selected_type['id']) ?>;
    const apptTypeName = <?= json_encode($selected_type['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const skipContract = <?= $skip_contract ? 'true' : 'false' ?>;
    // Pet names map built from PHP
    const petNames = {};
    <?php foreach ($pets as $pet): ?>
    petNames[<?= intval($pet['id']) ?>] = <?= json_encode($pet['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    <?php endforeach; ?>

    /* ─── Helpers ─────────────────────────────────────────────────── */
    function showAlert(msg, type) {
        const area = document.getElementById('alertArea');
        area.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">
            ${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function formatTime(t) {
        const [h, m] = t.split(':');
        const hour = parseInt(h);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const h12  = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
        return `${h12}:${m} ${ampm}`;
    }

    function formatDateLong(d) {
        return new Date(d + 'T00:00').toLocaleDateString('en-US', {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    /* ─── Step navigation ─────────────────────────────────────────── */
    window.prevStep = function () {
        if (currentStep > 1) { currentStep--; renderStep(); }
    };

    window.nextStep = function () {
        if (!validateStep()) return;
        if (currentStep < maxSteps) {
            currentStep++;
            renderStep();
            if (currentStep === maxSteps) populateConfirm();
        }
    };

    function renderStep() {
        document.querySelectorAll('.step').forEach(s => {
            const n = parseInt(s.dataset.step);
            s.classList.toggle('active', n === currentStep);
            s.classList.toggle('completed', n < currentStep);
        });
        document.querySelectorAll('.form-step').forEach(s => {
            s.classList.toggle('active', parseInt(s.dataset.step) === currentStep);
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (currentStep === 2) loadTimeSlots();
    }

    /* ─── Validation per step ─────────────────────────────────────── */
    function validateStep() {
        if (currentStep === 1) {
            selectedDate = document.getElementById('appointmentDate').value;
            if (!selectedDate) { showAlert('Please select a date.', 'warning'); return false; }
            document.getElementById('selectedDateDisplay').textContent = formatDateLong(selectedDate);
        } else if (currentStep === 2) {
            if (!selectedTime) { showAlert('Please select a time.', 'warning'); return false; }
        } else if (currentStep === 3) {
            const name  = document.getElementById('clientName').value.trim();
            const email = document.getElementById('clientEmail').value.trim();
            if (!name || !email) { showAlert('Please fill in your name and email.', 'warning'); return false; }
            // Location validation
            const locEl = document.getElementById('locationType');
            if (locEl && locEl.tagName === 'SELECT') {
                if (!locEl.value) { showAlert('Please select a location type.', 'warning'); return false; }
                if (['custom_address', 'webcall'].includes(locEl.value)) {
                    const lv = document.getElementById('locationValue');
                    if (!lv || !lv.value.trim()) {
                        showAlert(locEl.value === 'webcall' ? 'Please enter the video call URL.' : 'Please enter the address.', 'warning');
                        return false;
                    }
                }
            } else if (locEl && locEl.tagName === 'INPUT' && locEl.required && !locEl.value.trim()) {
                showAlert('Please enter the required location information.', 'warning');
                return false;
            }
            // Contract validation (only if not skipped)
            if (!skipContract) {
                const ctEl = document.getElementById('contractTypedName');
                if (ctEl) {
                    if (!ctEl.value.trim()) {
                        showAlert('Please type your full name to sign the required contract.', 'warning');
                        ctEl.focus(); return false;
                    }
                    if (!document.getElementById('contractConfirmation').checked) {
                        showAlert('You must check the confirmation box to accept the contract.', 'warning');
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /* ─── Time slots ──────────────────────────────────────────────── */
    function loadTimeSlots() {
        const loading   = document.getElementById('loadingSlots');
        const container = document.getElementById('timeSlotsContainer');
        loading.style.display = 'block';
        loading.className = 'alert alert-info';
        loading.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Loading available times…';
        container.style.display = 'none';
        container.innerHTML = '';
        fetch(`/backend/public/api_bookings.php?date=${selectedDate}&appointment_type_id=${apptTypeId}`)
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                container.style.display = 'flex';
                if (data.available_slots && data.available_slots.length) {
                    data.available_slots.forEach(slot => {
                        const col = document.createElement('div');
                        col.className = 'col-6 col-md-3';
                        col.innerHTML = `<div class="time-slot" data-time="${slot}">${formatTime(slot)}</div>`;
                        col.querySelector('.time-slot').addEventListener('click', function () {
                            document.querySelectorAll('.time-slot').forEach(s => s.classList.remove('selected'));
                            this.classList.add('selected');
                            selectedTime = slot;
                            document.getElementById('step3Next').disabled = false;
                        });
                        container.appendChild(col);
                    });
                } else {
                    container.innerHTML = `<div class="col-12"><div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>${data.message || 'No available times for this date.'} Please try another date.</div></div>`;
                }
            })
            .catch(() => {
                loading.className = 'alert alert-danger';
                loading.style.display = 'block';
                loading.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i> Error loading times. Please try again.';
            });
    }

    /* ─── Pet toggle ──────────────────────────────────────────────── */
    window.togglePet = function (el) {
        el.classList.toggle('selected');
        const cb = el.querySelector('.pet-checkbox');
        if (cb) cb.checked = el.classList.contains('selected');
    };

    function getSelectedPetIds() {
        return [...document.querySelectorAll('.pet-checkbox')]
            .filter(cb => cb.checked)
            .map(cb => parseInt(cb.dataset.petId));
    }

    function getSelectedPetNames() {
        return getSelectedPetIds().map(id => petNames[id] || 'Pet #' + id);
    }

    /* ─── Add new pet inline ──────────────────────────────────────── */
    window.addNewPet = function () {
        const name    = document.getElementById('newPetName').value.trim();
        const species = document.getElementById('newPetSpecies').value.trim() || 'Dog';
        const breed   = document.getElementById('newPetBreed').value.trim();
        const status  = document.getElementById('addPetStatus');
        if (!name) { status.innerHTML = '<div class="text-danger small">Pet name is required.</div>'; return; }

        fetch('/portal/api_book_credit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_pet', name, species, breed })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.pet_id) {
                petNames[data.pet_id] = name;
                // Add to pet list and pre-select
                const list = document.getElementById('petList');
                if (!list) {
                    // Create pet list if it didn't exist
                    const noMsg = document.querySelector('#addPetForm').parentElement.querySelector('.alert-info');
                    if (noMsg) noMsg.remove();
                    const newList = document.createElement('div');
                    newList.id = 'petList';
                    document.getElementById('addPetForm').before(document.getElementById('addPetToggleBtn'));
                    document.getElementById('addPetToggleBtn').before(newList);
                }
                const petList = document.getElementById('petList');
                const div = document.createElement('div');
                div.className = 'pet-option d-flex align-items-center gap-2 selected';
                div.dataset.petId = data.pet_id;
                div.onclick = function () { window.togglePet(this); };
                div.innerHTML = `<input type="checkbox" class="form-check-input pet-checkbox" data-pet-id="${data.pet_id}" checked style="pointer-events:none;">
                    <span class="fw-semibold">${escapeHtml(name)}</span>
                    ${breed ? `<span class="text-muted small">(${escapeHtml(breed)})</span>` : ''}`;
                petList.appendChild(div);
                // Reset form
                document.getElementById('newPetName').value = '';
                document.getElementById('newPetBreed').value = '';
                document.getElementById('newPetSpecies').value = 'Dog';
                document.getElementById('addPetForm').classList.add('d-none');
                document.getElementById('addPetToggleBtn').classList.remove('active');
                status.innerHTML = '';
            } else {
                status.innerHTML = `<div class="text-danger small">${escapeHtml(data.error || 'Failed to add pet.')}</div>`;
            }
        })
        .catch(() => { status.innerHTML = '<div class="text-danger small">Network error. Please try again.</div>'; });
    };

    function escapeHtml(s) {
        const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
    }

    /* ─── Contact pre-fill ─────────────────────────────────────────── */
    window.applyContact = function () {
        const sel = document.getElementById('contactSelect');
        if (!sel) return;
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('clientName').value  = opt.dataset.name  || '';
        document.getElementById('clientEmail').value = opt.dataset.email || '';
        document.getElementById('clientPhone').value = opt.dataset.phone || '';
    };

    /* ─── Location type dropdown ───────────────────────────────────── */
    window.handleLocationTypeChange = function () {
        const type    = document.getElementById('locationType')?.value;
        const wrapper = document.getElementById('locationValueWrapper');
        const label   = document.getElementById('locationValueLabel');
        const input   = document.getElementById('locationValue');
        if (!wrapper || !input) return;
        const needsValue = ['custom_address', 'webcall'].includes(type);
        wrapper.style.display = needsValue ? 'block' : 'none';
        if (type === 'custom_address') {
            label.textContent = 'Address *';
            input.placeholder = 'Enter full address';
            input.type = 'text';
            input.required = true;
        } else if (type === 'webcall') {
            label.textContent = 'Video call URL *';
            input.placeholder = 'https://zoom.us/j/…';
            input.type = 'url';
            input.required = true;
        } else {
            input.required = false;
            input.value = '';
        }
    };

    /* ─── Confirmation summary ─────────────────────────────────────── */
    function populateConfirm() {
        document.getElementById('confirmDate').textContent = formatDateLong(selectedDate);
        document.getElementById('confirmTime').textContent = formatTime(selectedTime);
        document.getElementById('confirmName').textContent  = document.getElementById('clientName').value;
        document.getElementById('confirmEmail').textContent = document.getElementById('clientEmail').value;
        const petNames2 = getSelectedPetNames();
        document.getElementById('confirmPets').textContent = petNames2.length ? petNames2.join(', ') : 'Not specified';
        document.getElementById('confirmLocation').textContent = getLocationSummary();
    }

    function getLocationSummary() {
        const locEl = document.getElementById('locationType');
        if (!locEl) return 'Not specified';
        if (locEl.tagName === 'INPUT') return locEl.value || 'Fixed location';
        const type = locEl.value;
        const labels = {
            client_address: 'My registered address',
            phone_inbound:  'Phone call (I call the trainer)',
            phone_outbound: 'Phone call (trainer calls me)',
            custom_address: document.getElementById('locationValue')?.value || 'Custom address',
            webcall:        document.getElementById('locationValue')?.value || 'Video call',
        };
        return labels[type] || type || 'Not specified';
    }

    /* ─── Collect form responses ───────────────────────────────────── */
    function collectFormResponses() {
        const responses = {};
        document.querySelectorAll('[data-form-id]').forEach(section => {
            const fid = section.dataset.formId;
            const fields = {};
            section.querySelectorAll('input:not([type=checkbox]):not([type=radio]), textarea, select').forEach(el => {
                if (el.dataset.formField !== undefined) fields[el.dataset.formField] = el.value;
            });
            const radioSeen = {};
            section.querySelectorAll('input[type=radio]').forEach(el => {
                const fi = el.dataset.formField;
                if (fi !== undefined && radioSeen[fi] === undefined) radioSeen[fi] = '';
                if (el.checked) radioSeen[fi] = el.value;
            });
            Object.assign(fields, radioSeen);
            const cbGroups = {};
            section.querySelectorAll('input[type=checkbox]').forEach(el => {
                const fi = el.dataset.formField;
                if (fi !== undefined) {
                    if (!cbGroups[fi]) cbGroups[fi] = [];
                    if (el.checked) cbGroups[fi].push(el.value);
                }
            });
            Object.assign(fields, cbGroups);
            responses[fid] = fields;
        });
        return responses;
    }

    /* ─── Submit booking ───────────────────────────────────────────── */
    document.getElementById('bookingForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const btn     = document.getElementById('submitBtn');
        const spinner = document.getElementById('submitSpinner');
        btn.disabled = true;
        spinner.classList.remove('d-none');

        let location_type  = '';
        let location_value = '';
        const locEl = document.getElementById('locationType');
        if (locEl && locEl.tagName === 'SELECT') {
            location_type  = locEl.value;
            location_value = document.getElementById('locationValue')?.value.trim() || '';
        } else if (locEl) {
            location_type  = locEl.value;
            location_value = document.getElementById('locationValue')?.value.trim() || '';
        }

        const payload = {
            action:               'book',
            appointment_type_id:  apptTypeId,
            appointment_date:     selectedDate,
            appointment_time:     selectedTime,
            client_name:          document.getElementById('clientName').value,
            client_email:         document.getElementById('clientEmail').value,
            client_phone:         document.getElementById('clientPhone').value,
            pet_ids:              getSelectedPetIds(),
            notes:                document.getElementById('notes').value,
            location_type:        location_type,
            location_value:       location_value,
            form_responses:       collectFormResponses(),
            // Contract fields (null when skipped)
            contract_template_id: skipContract ? null : (document.getElementById('contractTemplateId') ? parseInt(document.getElementById('contractTemplateId').value) : null),
            contract_typed_name:  skipContract ? null : (document.getElementById('contractTypedName')?.value.trim() || null),
            contract_signature_font: skipContract ? null : (document.getElementById('contractSignatureFont')?.value || null),
        };

        fetch('/portal/api_book_credit.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                new bootstrap.Modal(document.getElementById('successModal')).show();
            } else {
                showAlert(escapeHtml(data.error || 'Booking failed. Please try again.'), 'danger');
                btn.disabled = false;
                spinner.classList.add('d-none');
            }
        })
        .catch(() => {
            showAlert('Network error. Please check your connection and try again.', 'danger');
            btn.disabled = false;
            spinner.classList.add('d-none');
        });
    });

    /* ─── Contract signature UI ────────────────────────────────────── */
    (function () {
        const nameEl    = document.getElementById('contractTypedName');
        const preview   = document.getElementById('contractSigPreview');
        const fontInput = document.getElementById('contractSignatureFont');
        const fontBtns  = document.querySelectorAll('#contractFontOptions .font-option-btn');
        if (!nameEl) return;
        nameEl.addEventListener('input', function () {
            const v = this.value.trim() || '\u00a0';
            preview.textContent = v;
            document.querySelectorAll('.contract-font-preview').forEach(s => {
                s.textContent = this.value.trim() || 'Your Name';
            });
        });
        fontBtns.forEach(btn => btn.addEventListener('click', function () {
            fontBtns.forEach(b => b.classList.remove('selected'));
            this.classList.add('selected');
            fontInput.value = this.dataset.font;
            preview.className = 'sig-preview ' + this.dataset.font;
        }));
    })();

})();
</script>

<?php include '../portal/includes/footer.php'; ?>
