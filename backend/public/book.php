<?php
/**
 * Public Booking Page
 * Allows clients to book appointments directly
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

// Get appointment type from URL if specified
$appointment_type_id = isset($_GET['type']) ? intval($_GET['type']) : 0;

// Get all active appointment types
$stmt = $conn->query("SELECT * FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appointment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get specific appointment type if specified
$selected_type = null;
if ($appointment_type_id > 0) {
    foreach ($appointment_types as $type) {
        if ($type['id'] == $appointment_type_id) {
            $selected_type = $type;
            break;
        }
    }
}

$page_title = "Book an Appointment";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Brook's Dog Training Academy</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #9a0073;
            --primary-dark: #7a005a;
            --secondary-color: #0a9a9c;
            --accent-color: #a39f89;
            --dark-color: #1f2937;
            --light-color: #f9fafb;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--light-color) 0%, #e5e7eb 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .booking-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .booking-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .booking-header h1 {
            color: var(--primary-color);
            font-family: 'Montserrat', sans-serif;
            margin-bottom: 0.5rem;
        }
        
        .booking-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e5e7eb;
            z-index: 0;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
        }
        
        .step.active .step-circle {
            background: var(--primary-color);
            color: white;
        }
        
        .step.completed .step-circle {
            background: var(--secondary-color);
            color: white;
        }
        
        .step-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .appointment-type-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .appointment-type-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }
        
        .appointment-type-card.selected {
            border-color: var(--primary-color);
            background: #eff6ff;
        }
        
        .time-slot {
            padding: 0.75rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            background: white;
        }
        
        .time-slot:hover {
            border-color: var(--primary-color);
            background: #eff6ff;
        }
        
        .time-slot.selected {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        
        .time-slot.unavailable {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }
        
        .form-step {
            display: none;
        }
        
        .form-step.active {
            display: block;
        }
        
        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .alert-info {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }
        
        .loading-spinner {
            display: none;
        }
        
        .loading-spinner.active {
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="booking-container">
        <div class="booking-header">
            <h1><i class="fas fa-calendar-check me-2"></i>Book Your Appointment</h1>
            <p class="text-muted mb-0">Schedule your dog training session with Brook's Dog Training Academy</p>
        </div>
        
        <div class="booking-card">
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step active" data-step="1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Service</div>
                </div>
                <div class="step" data-step="2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Date</div>
                </div>
                <div class="step" data-step="3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Time</div>
                </div>
                <div class="step" data-step="4">
                    <div class="step-circle">4</div>
                    <div class="step-label">Your Info</div>
                </div>
                <div class="step" data-step="5">
                    <div class="step-circle">5</div>
                    <div class="step-label">Confirm</div>
                </div>
            </div>
            
            <!-- Alert Area -->
            <div id="alertArea"></div>
            
            <!-- Booking Form -->
            <form id="bookingForm">
                <!-- Step 1: Select Service -->
                <div class="form-step active" data-step="1">
                    <h3 class="mb-4">Select Your Service</h3>
                    
                    <?php foreach ($appointment_types as $type): ?>
                        <div class="appointment-type-card" data-type-id="<?= $type['id'] ?>" 
                             data-duration="<?= $type['duration_minutes'] ?>"
                             data-requires-forms="<?= $type['requires_forms'] ?>"
                             data-requires-contract="<?= $type['requires_contract'] ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h5 class="mb-2"><?= escape($type['name']) ?></h5>
                                    <p class="text-muted mb-2"><?= escape($type['description']) ?></p>
                                    <div class="d-flex gap-3">
                                        <span class="badge bg-primary"><i class="fas fa-clock me-1"></i><?= $type['duration_minutes'] ?> minutes</span>
                                        <?php if ($type['requires_forms']): ?>
                                            <span class="badge bg-info">Forms Required</span>
                                        <?php endif; ?>
                                        <?php if ($type['is_group_class']): ?>
                                            <span class="badge bg-success">Group Class</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="appointment_type" 
                                           value="<?= $type['id'] ?>" id="type<?= $type['id'] ?>"
                                           <?= ($selected_type && $selected_type['id'] == $type['id']) ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="d-flex justify-content-end mt-4">
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 2: Select Date -->
                <div class="form-step" data-step="2">
                    <h3 class="mb-4">Choose Your Date</h3>
                    
                    <div class="row">
                        <div class="col-md-8 mx-auto mb-3">
                            <label class="form-label fw-bold">Select Date *</label>
                            <input type="date" class="form-control form-control-lg" id="appointmentDate" 
                                   name="appointment_date" required min="<?= date('Y-m-d') ?>">
                            <small class="text-muted mt-2 d-block">Choose a date for your appointment. You'll select a time in the next step.</small>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()" id="step2Next">
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: Select Time -->
                <div class="form-step" data-step="3">
                    <h3 class="mb-4">Choose Your Time</h3>
                    
                    <div class="row">
                        <div class="col-12">
                            <p class="text-muted mb-3">
                                <i class="fas fa-calendar me-2"></i>
                                Selected date: <strong id="selectedDateDisplay">-</strong>
                            </p>
                            <label class="form-label fw-bold">Select Time *</label>
                            <div class="alert alert-info" id="loadingSlots">
                                <div class="spinner-border spinner-border-sm me-2"></div>
                                Loading available times...
                            </div>
                            <div id="timeSlotsContainer" class="row g-2" style="display: none;"></div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()" id="step3Next" disabled>
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 4: Your Information -->
                <div class="form-step" data-step="4">
                    <h3 class="mb-4">Your Information</h3>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Your Name *</label>
                            <input type="text" class="form-control form-control-lg" name="client_name" 
                                   id="clientName" required placeholder="John Doe">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" class="form-control form-control-lg" name="client_email" 
                                   id="clientEmail" required placeholder="john@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control form-control-lg" name="client_phone" 
                                   id="clientPhone" placeholder="(555) 123-4567">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Dog's Name(s)</label>
                            <input type="text" class="form-control form-control-lg" name="dog_names" 
                                   id="dogNames" placeholder="e.g., Max, Bella">
                            <small class="text-muted">If you have multiple dogs, separate with commas</small>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" name="notes" id="notes" rows="3" 
                                      placeholder="Tell us about your dog's needs, behavior concerns, or any special requirements..."></textarea>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 5: Confirmation -->
                <div class="form-step" data-step="5">
                    <h3 class="mb-4">Confirm Your Booking</h3>
                    
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Appointment Summary</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Service:</dt>
                                <dd class="col-sm-8" id="confirmService">-</dd>
                                
                                <dt class="col-sm-4">Date:</dt>
                                <dd class="col-sm-8" id="confirmDate">-</dd>
                                
                                <dt class="col-sm-4">Time:</dt>
                                <dd class="col-sm-8" id="confirmTime">-</dd>
                                
                                <dt class="col-sm-4">Name:</dt>
                                <dd class="col-sm-8" id="confirmName">-</dd>
                                
                                <dt class="col-sm-4">Email:</dt>
                                <dd class="col-sm-8" id="confirmEmail">-</dd>
                                
                                <dt class="col-sm-4">Phone:</dt>
                                <dd class="col-sm-8" id="confirmPhone">-</dd>
                                
                                <dt class="col-sm-4">Dog(s):</dt>
                                <dd class="col-sm-8" id="confirmDogs">-</dd>
                            </dl>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-circle-info me-2"></i>
                        You will receive a confirmation email with your appointment details and calendar links.
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                            <span class="loading-spinner spinner-border spinner-border-sm me-2"></span>
                            <i class="fas fa-check-circle me-2"></i> Confirm Booking
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h2 class="mb-3">Booking Confirmed!</h2>
                    <p class="text-muted mb-4">Your appointment has been successfully booked. Check your email for confirmation details and calendar links.</p>
                    <a href="/" class="btn btn-primary btn-lg">Back to Home</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStep = 1;
        let selectedType = null;
        let selectedDate = null;
        let selectedTime = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-select type if provided in URL
            const urlParams = new URLSearchParams(window.location.search);
            const typeParam = urlParams.get('type');
            if (typeParam) {
                const radio = document.querySelector(`input[name="appointment_type"][value="${typeParam}"]`);
                if (radio) {
                    radio.checked = true;
                    selectAppointmentType(typeParam);
                }
            }
            
            // Appointment type selection
            document.querySelectorAll('.appointment-type-card').forEach(card => {
                card.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    radio.checked = true;
                    selectAppointmentType(radio.value);
                });
            });
            
            // Date selection
            document.getElementById('appointmentDate').addEventListener('change', function() {
                selectedDate = this.value;
                // Enable the continue button on step 2
                document.getElementById('step2Next').disabled = false;
            });
            
            // Form submission
            document.getElementById('bookingForm').addEventListener('submit', submitBooking);
        });
        
        function selectAppointmentType(typeId) {
            selectedType = typeId;
            document.querySelectorAll('.appointment-type-card').forEach(card => {
                card.classList.remove('selected');
            });
            const selectedCard = document.querySelector(`.appointment-type-card[data-type-id="${typeId}"]`);
            if (selectedCard) {
                selectedCard.classList.add('selected');
            }
        }
        
        function nextStep() {
            // Validation
            if (currentStep === 1) {
                if (!selectedType) {
                    showAlert('Please select an appointment type', 'warning');
                    return;
                }
            } else if (currentStep === 2) {
                if (!selectedDate) {
                    showAlert('Please select a date', 'warning');
                    return;
                }
                // Load available slots for step 3
                updateSelectedDateDisplay();
                loadAvailableSlots();
            } else if (currentStep === 3) {
                if (!selectedTime) {
                    showAlert('Please select a time', 'warning');
                    return;
                }
            } else if (currentStep === 4) {
                const name = document.getElementById('clientName').value.trim();
                const email = document.getElementById('clientEmail').value.trim();
                if (!name || !email) {
                    showAlert('Please fill in your name and email', 'warning');
                    return;
                }
            }
            
            if (currentStep < 5) {
                currentStep++;
                updateSteps();
                if (currentStep === 5) {
                    updateConfirmation();
                }
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                updateSteps();
            }
        }
        
        function updateSteps() {
            // Update step indicators
            document.querySelectorAll('.step').forEach(step => {
                const stepNum = parseInt(step.dataset.step);
                step.classList.remove('active', 'completed');
                if (stepNum === currentStep) {
                    step.classList.add('active');
                } else if (stepNum < currentStep) {
                    step.classList.add('completed');
                }
            });
            
            // Update form steps
            document.querySelectorAll('.form-step').forEach(step => {
                step.classList.remove('active');
            });
            document.querySelector(`.form-step[data-step="${currentStep}"]`).classList.add('active');
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function loadAvailableSlots() {
            if (!selectedDate || !selectedType) return;
            
            const loadingSlots = document.getElementById('loadingSlots');
            const slotsContainer = document.getElementById('timeSlotsContainer');
            
            loadingSlots.style.display = 'block';
            loadingSlots.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Loading available times...';
            slotsContainer.style.display = 'none';
            slotsContainer.innerHTML = '';
            
            fetch(`api_bookings.php?date=${selectedDate}`)
                .then(r => r.json())
                .then(data => {
                    loadingSlots.style.display = 'none';
                    slotsContainer.style.display = 'flex';
                    
                    if (data.available_slots && data.available_slots.length > 0) {
                        data.available_slots.forEach(slot => {
                            const slotDiv = document.createElement('div');
                            slotDiv.className = 'col-6 col-md-3';
                            slotDiv.innerHTML = `<div class="time-slot" data-time="${slot}" onclick="selectTimeSlot('${slot}')">${formatTime(slot)}</div>`;
                            slotsContainer.appendChild(slotDiv);
                        });
                    } else {
                        slotsContainer.innerHTML = '<div class="col-12"><div class="alert alert-warning">No available time slots for this date. Please try another date.</div></div>';
                    }
                })
                .catch(err => {
                    loadingSlots.style.display = 'block';
                    loadingSlots.className = 'alert alert-danger';
                    loadingSlots.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i> Error loading time slots. Please try again.';
                });
        }
        
        function selectTimeSlot(time) {
            selectedTime = time;
            document.querySelectorAll('.time-slot').forEach(slot => {
                slot.classList.remove('selected');
            });
            event.target.classList.add('selected');
            document.getElementById('step3Next').disabled = false;
        }
        
        function updateSelectedDateDisplay() {
            if (selectedDate) {
                const dateObj = new Date(selectedDate + 'T00:00');
                const formatted = dateObj.toLocaleDateString('en-US', { 
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
                });
                document.getElementById('selectedDateDisplay').textContent = formatted;
            }
        }
        
        function formatTime(time) {
            const [hours, minutes] = time.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
            return `${hour12}:${minutes} ${ampm}`;
        }
        
        function updateConfirmation() {
            const typeCard = document.querySelector(`.appointment-type-card[data-type-id="${selectedType}"]`);
            const typeName = typeCard ? typeCard.querySelector('h5').textContent : '-';
            
            document.getElementById('confirmService').textContent = typeName;
            document.getElementById('confirmDate').textContent = new Date(selectedDate + 'T00:00').toLocaleDateString('en-US', { 
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
            });
            document.getElementById('confirmTime').textContent = formatTime(selectedTime);
            document.getElementById('confirmName').textContent = document.getElementById('clientName').value;
            document.getElementById('confirmEmail').textContent = document.getElementById('clientEmail').value;
            document.getElementById('confirmPhone').textContent = document.getElementById('clientPhone').value || 'Not provided';
            document.getElementById('confirmDogs').textContent = document.getElementById('dogNames').value || 'Not specified';
        }
        
        function submitBooking(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const spinner = submitBtn.querySelector('.loading-spinner');
            
            submitBtn.disabled = true;
            spinner.classList.add('active');
            
            const typeCard = document.querySelector(`.appointment-type-card[data-type-id="${selectedType}"]`);
            const typeName = typeCard ? typeCard.querySelector('h5').textContent : 'Appointment';
            
            const bookingData = {
                appointment_type_id: selectedType,
                service_type: typeName,
                appointment_date: selectedDate,
                appointment_time: selectedTime,
                client_name: document.getElementById('clientName').value,
                client_email: document.getElementById('clientEmail').value,
                client_phone: document.getElementById('clientPhone').value,
                notes: document.getElementById('notes').value,
                duration_minutes: parseInt(typeCard.dataset.duration)
            };
            
            fetch('api_bookings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bookingData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const modal = new bootstrap.Modal(document.getElementById('successModal'));
                    modal.show();
                } else {
                    showAlert(data.error || 'Booking failed. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    spinner.classList.remove('active');
                }
            })
            .catch(err => {
                showAlert('Network error. Please check your connection and try again.', 'danger');
                submitBtn.disabled = false;
                spinner.classList.remove('active');
            });
        }
        
        function showAlert(message, type) {
            const alertArea = document.getElementById('alertArea');
            alertArea.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>
