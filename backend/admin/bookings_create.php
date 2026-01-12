<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get clients for dropdown
$stmt = $conn->query("SELECT id, name, email FROM clients ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active appointment types
$stmt = $conn->query("SELECT * FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appointment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = (int)$_POST['client_id'];
    $appointment_type_id = (int)$_POST['appointment_type_id'];
    $booking_date = $_POST['booking_date'];
    $booking_time = $_POST['booking_time'];
    $pets = isset($_POST['pets']) ? $_POST['pets'] : [];
    $notes = trim($_POST['notes'] ?? '');
    $override_forms = isset($_POST['override_forms']) ? 1 : 0;
    $override_contract = isset($_POST['override_contract']) ? 1 : 0;
    $override_credits = isset($_POST['override_credits']) ? 1 : 0;
    
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
            // Check if client has submitted required forms
            $stmt = $conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE client_id = ? AND status = 'submitted'");
            $stmt->execute([$client_id]);
            $forms_count = $stmt->fetchColumn();
            if ($forms_count == 0) {
                $errors[] = "Client must submit required forms before booking (or override)";
            }
        }
        
        // Check required contract
        if ($apt_type['requires_contract'] && !$override_contract) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'signed'");
            $stmt->execute([$client_id]);
            $contract_count = $stmt->fetchColumn();
            if ($contract_count == 0) {
                $errors[] = "Client must sign required contract before booking (or override)";
            }
        }
        
        // Check credits
        if ($apt_type['consumes_credits'] && !$override_credits) {
            $stmt = $conn->prepare("SELECT credit_balance FROM client_credits WHERE client_id = ?");
            $stmt->execute([$client_id]);
            $credit_balance = $stmt->fetchColumn();
            if ($credit_balance === false) {
                $credit_balance = 0;
            }
            if ($credit_balance < $apt_type['credit_count']) {
                $errors[] = "Insufficient credits (need {$apt_type['credit_count']}, have $credit_balance) - override to proceed";
            }
        }
        
        if (!empty($errors) && !$override_forms && !$override_contract && !$override_credits) {
            setFlashMessage(implode('<br>', $errors), 'danger');
        } else {
            // Create booking
            $datetime = $booking_date . ' ' . $booking_time;
            $pets_json = json_encode($pets);
            
            $stmt = $db->prepare("INSERT INTO bookings (client_id, appointment_type_id, name, email, phone, booking_date, service_type, notes, status, pets, override_forms, override_contract, override_credits, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, datetime('now'))");
            $stmt->execute([$client_id, $appointment_type_id, $client['name'], $client['email'], $client['phone'], $datetime, $apt_type['name'], $notes, $pets_json, $override_forms, $override_contract, $override_credits]);
            
            $booking_id = $db->lastInsertId();
            
            // Consume credits if applicable
            if ($apt_type['consumes_credits'] && !$override_credits) {
                // Deduct credits
                $db->exec("UPDATE client_credits SET credit_balance = credit_balance - {$apt_type['credit_count']}, total_consumed = total_consumed + {$apt_type['credit_count']}, updated_at = datetime('now') WHERE client_id = $client_id");
                
                // Log transaction
                $balance_before = $credit_balance;
                $balance_after = $balance_before - $apt_type['credit_count'];
                $stmt = $db->prepare("INSERT INTO credit_transactions (client_id, transaction_type, amount, balance_before, balance_after, booking_id, notes, created_by, created_at) VALUES (?, 'consume', ?, ?, ?, ?, ?, ?, datetime('now'))");
                $stmt->execute([$client_id, -$apt_type['credit_count'], $balance_before, $balance_after, $booking_id, "Consumed by booking #{$booking_id}", $_SESSION['admin_id']]);
            }
            
            // Auto-invoice if configured
            if ($apt_type['auto_invoice']) {
                // TODO: Create invoice
            }
            
            // Link pets to appointment
            if (!empty($pets)) {
                foreach ($pets as $pet_id) {
                    $stmt = $db->prepare("INSERT INTO appointment_pets (booking_id, pet_id, created_at) VALUES (?, ?, datetime('now'))");
                    $stmt->execute([$booking_id, $pet_id]);
                }
            }
            
            $_SESSION['success'] = "Booking created successfully!";
            header('Location: bookings_list.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error creating booking: " . $e->getMessage();
    }
}

// Get pets for selected client via AJAX
if (isset($_GET['client_id']) && isset($_GET['ajax'])) {
    $client_id = (int)$_GET['client_id'];
    $pets = $db->query("SELECT id, name, species, breed FROM pets WHERE client_id = $client_id AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($pets);
    exit;
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">Create Booking</h1>
                <a href="bookings_list.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Bookings
                </a>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
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
                                            <?php echo htmlspecialchars($client['name']); ?> (<?php echo htmlspecialchars($client['email']); ?>)
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
                                                data-requires-contract="<?php echo $type['requires_contract']; ?>"
                                                data-consumes-credits="<?php echo $type['consumes_credits']; ?>"
                                                data-credit-count="<?php echo $type['credit_count']; ?>">
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

                            <!-- Rule Overrides -->
                            <div class="col-12 mb-3">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title">Admin Overrides</h6>
                                        <p class="text-muted small mb-3">Check these to bypass rule enforcement</p>
                                        
                                        <div class="form-check mb-2" id="overrideFormsContainer" style="display:none;">
                                            <input type="checkbox" class="form-check-input" name="override_forms" id="overrideForms">
                                            <label class="form-check-label" for="overrideForms">
                                                <strong>Override Required Forms</strong>
                                                <small class="text-danger d-block">Client may not have submitted required forms</small>
                                            </label>
                                        </div>
                                        
                                        <div class="form-check mb-2" id="overrideContractContainer" style="display:none;">
                                            <input type="checkbox" class="form-check-input" name="override_contract" id="overrideContract">
                                            <label class="form-check-label" for="overrideContract">
                                                <strong>Override Required Contract</strong>
                                                <small class="text-danger d-block">Client may not have signed required contract</small>
                                            </label>
                                        </div>
                                        
                                        <div class="form-check mb-2" id="overrideCreditsContainer" style="display:none;">
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
                                    <i class="bi bi-check-circle"></i> Create Booking
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
document.addEventListener('DOMContentLoaded', function() {
    const clientSelect = document.getElementById('clientSelect');
    const appointmentTypeSelect = document.getElementById('appointmentTypeSelect');
    const petsContainer = document.getElementById('petsContainer');
    const typeInfo = document.getElementById('typeInfo');
    
    // Load pets when client selected
    clientSelect.addEventListener('change', function() {
        const clientId = this.value;
        if (!clientId) {
            petsContainer.innerHTML = '<p class="text-muted mb-0">Select a client to see their pets</p>';
            return;
        }
        
        fetch(`?client_id=${clientId}&ajax=1`)
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
    });
    
    // Update type info and show override options
    appointmentTypeSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (!option.value) {
            typeInfo.textContent = '';
            document.getElementById('noOverridesMsg').style.display = 'block';
            document.getElementById('overrideFormsContainer').style.display = 'none';
            document.getElementById('overrideContractContainer').style.display = 'none';
            document.getElementById('overrideCreditsContainer').style.display = 'none';
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
        document.getElementById('noOverridesMsg').style.display = anyOverrides ? 'none' : 'block';
        document.getElementById('overrideFormsContainer').style.display = requiresForms ? 'block' : 'none';
        document.getElementById('overrideContractContainer').style.display = requiresContract ? 'block' : 'none';
        document.getElementById('overrideCreditsContainer').style.display = consumesCredits ? 'block' : 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
