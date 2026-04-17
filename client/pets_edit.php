<?php
/**
 * Pet Edit - Add or edit a pet
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$pet_id_value = safe_int($_GET['id'] ?? 0);
$pet_id = $pet_id_value > 0 ? $pet_id_value : null;
$client_id_value = safe_int($_GET['client_id'] ?? 0);
$client_id = $client_id_value > 0 ? $client_id_value : null;
$pet = null;
$clients = [];

function pets_edit_return_url(string $url): string {
    $fallback = 'pets_list.php';
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $fallback;
    }

    // Reject any URL that contains credentials or a fragment.
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return $fallback;
    }

    // If a scheme or host is present, only allow same-origin URLs under ADMIN_URL.
    if (isset($parts['scheme']) || isset($parts['host'])) {
        $currentHost = scalar_string($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
        // Strip an optional port and normalize case for comparison.
        $currentHost = preg_replace('/:\d+$/', '', $currentHost);
        if ($currentHost === null) {
            return $fallback;
        }
        $currentHost = strtolower($currentHost);
        $targetHost  = strtolower(scalar_string($parts['host'] ?? ''));

        if ($currentHost === '' || $targetHost === '' || $currentHost !== $targetHost) {
            return $fallback;
        }

        $absolutePath = scalar_string($parts['path'] ?? '');
        if ($absolutePath === '' || strpos($absolutePath, ADMIN_URL) !== 0) {
            return $fallback;
        }
    }

    $path = scalar_string($parts['path'] ?? '');
    if ($path === '') {
        return $fallback;
    }

    if (strpos($path, '/') === 0 && strpos($path, ADMIN_URL) !== 0) {
        return $fallback;
    }

    $basename = basename($path);
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $basename)) {
        return $fallback;
    }

    $query = scalar_string($parts['query'] ?? '');
    return $query !== '' ? $basename . '?' . $query : $basename;
}

// Get all clients for dropdown
$stmt = $conn->query("SELECT id, name, email FROM clients WHERE COALESCE(is_archived, 0) = 0 ORDER BY name");
$clients = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));

// If editing, get pet data
if ($pet_id) {
    $stmt = $conn->prepare("SELECT * FROM pets WHERE id = ?");
    $stmt->execute([$pet_id]);
    $pet = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    
    if ($pet === []) {
        $_SESSION['flash_error'] = "Pet not found.";
        header('Location: pets_list.php');
        exit;
    }
    $client_id = array_int_value($pet, 'client_id');
}
$pet_row = is_array($pet) ? $pet : [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = safe_int($_POST['client_id'] ?? 0);
    $name = trim(scalar_string($_POST['name'] ?? ''));
    $species = trim(scalar_string($_POST['species'] ?? ''));
    $breed = trim(scalar_string($_POST['breed'] ?? ''));
    $date_of_birth_input = scalar_string($_POST['date_of_birth'] ?? '');
    $date_of_birth = $date_of_birth_input !== '' ? $date_of_birth_input : null;
    $age_years_input = scalar_string($_POST['age_years'] ?? '');
    $age_years = $age_years_input !== '' ? safe_int($age_years_input) : null;
    $age_months_input = scalar_string($_POST['age_months'] ?? '');
    $age_months = $age_months_input !== '' ? safe_int($age_months_input) : null;
    $source = trim(scalar_string($_POST['source'] ?? ''));
    $ownership_length_years_input = scalar_string($_POST['ownership_length_years'] ?? '');
    $ownership_length_years = $ownership_length_years_input !== '' ? safe_int($ownership_length_years_input) : null;
    $ownership_length_months_input = scalar_string($_POST['ownership_length_months'] ?? '');
    $ownership_length_months = $ownership_length_months_input !== '' ? safe_int($ownership_length_months_input) : null;
    $spayed_neutered = isset($_POST['spayed_neutered']) ? 1 : 0;
    $vaccines_current = isset($_POST['vaccines_current']) ? 1 : 0;
    $vaccine_notes = trim(scalar_string($_POST['vaccine_notes'] ?? ''));
    $behavior_notes = trim(scalar_string($_POST['behavior_notes'] ?? ''));
    $medical_notes = trim(scalar_string($_POST['medical_notes'] ?? ''));
    $training_notes = trim(scalar_string($_POST['training_notes'] ?? ''));
    $pet_sitting_notes = trim(scalar_string($_POST['pet_sitting_notes'] ?? ''));
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    $errors = [];
    if (empty($client_id)) $errors[] = "Client is required.";
    if (empty($name)) $errors[] = "Pet name is required.";
    if ($client_id > 0 && bdta_fetch_active_client($conn, $client_id) === []) $errors[] = "Selected client was not found.";
    
    if (empty($errors)) {
        try {
            if ($pet_id) {
                // Update existing pet
                $stmt = $conn->prepare("
                    UPDATE pets SET
                        client_id = ?,
                        name = ?,
                        species = ?,
                        breed = ?,
                        date_of_birth = ?,
                        age_years = ?,
                        age_months = ?,
                        source = ?,
                        ownership_length_years = ?,
                        ownership_length_months = ?,
                        spayed_neutered = ?,
                        vaccines_current = ?,
                        vaccine_notes = ?,
                        behavior_notes = ?,
                        medical_notes = ?,
                        training_notes = ?,
                        pet_sitting_notes = ?,
                        is_active = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $client_id, $name, $species, $breed, $date_of_birth,
                    $age_years, $age_months, $source,
                    $ownership_length_years, $ownership_length_months,
                    $spayed_neutered, $vaccines_current,
                    $vaccine_notes, $behavior_notes, $medical_notes, $training_notes, $pet_sitting_notes,
                    $is_active, $pet_id
                ]);
                
                $_SESSION['flash_message'] = "Pet updated successfully!";
            } else {
                // Insert new pet
                $stmt = $conn->prepare("
                    INSERT INTO pets (
                        client_id, name, species, breed, date_of_birth,
                        age_years, age_months, source,
                        ownership_length_years, ownership_length_months,
                        spayed_neutered, vaccines_current,
                        vaccine_notes, behavior_notes, medical_notes, training_notes, pet_sitting_notes,
                        is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $client_id, $name, $species, $breed, $date_of_birth,
                    $age_years, $age_months, $source,
                    $ownership_length_years, $ownership_length_months,
                    $spayed_neutered, $vaccines_current,
                    $vaccine_notes, $behavior_notes, $medical_notes, $training_notes, $pet_sitting_notes,
                    $is_active
                ]);
                
                $_SESSION['flash_message'] = "Pet added successfully!";
            }
            
            // Redirect back to client profile or pets list
            $return_url = pets_edit_return_url(scalar_string($_POST['return_to'] ?? ''));
            redirect($return_url);
            
            
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

/** @var list<string> $errors */
$errors = isset($errors) ? string_list($errors) : [];

$page_title = $pet_id ? "Edit Pet" : "Add Pet";
include '../backend/includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="fas fa-dog me-2"></i><?= htmlspecialchars($page_title) ?></h2>
            <p class="text-muted">
                <?php if ($client_id): ?>
                    <a href="clients_view.php?id=<?= $client_id ?>"><i class="fas fa-arrow-left me-1"></i>Back to Client Profile</a>
                <?php else: ?>
                    <a href="pets_list.php"><i class="fas fa-arrow-left me-1"></i>Back to Pets List</a>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <form method="POST" action="" class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Basic Information</h5>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="client_id" class="form-label">Client Owner *</label>
                            <select name="client_id" id="client_id" class="form-select" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                     <option value="<?= array_int_value($client, 'id') ?>" <?= $client_id == array_int_value($client, 'id') ? 'selected' : '' ?>>
                                         <?= htmlspecialchars(array_string_value($client, 'name')) ?> (<?= htmlspecialchars(array_string_value($client, 'email')) ?>)
                                     </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="name" class="form-label">Pet Name *</label>
                            <input type="text" name="name" id="name" class="form-control" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'name')) ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="species" class="form-label">Species</label>
                            <input type="text" name="species" id="species" class="form-control" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'species', 'Dog')) ?>">
                            <small class="form-text text-muted">e.g., Dog, Cat, etc.</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="breed" class="form-label">Breed</label>
                            <input type="text" name="breed" id="breed" class="form-control" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'breed')) ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="date_of_birth" class="form-control" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'date_of_birth')) ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="age_years" class="form-label">Age (Years)</label>
                            <input type="number" name="age_years" id="age_years" class="form-control" min="0" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'age_years')) ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="age_months" class="form-label">Age (Months)</label>
                            <input type="number" name="age_months" id="age_months" class="form-control" min="0" max="11" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'age_months')) ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="source" class="form-label">Source</label>
                            <input type="text" name="source" id="source" class="form-control" 
                                   value="<?= htmlspecialchars(array_string_value($pet_row, 'source')) ?>">
                            <small class="form-text text-muted">Where acquired (breeder, rescue, etc.)</small>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="ownership_length_years" class="form-label">Ownership (Years)</label>
                            <input type="number" name="ownership_length_years" id="ownership_length_years" 
                                   class="form-control" min="0" value="<?= htmlspecialchars(array_string_value($pet_row, 'ownership_length_years')) ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="ownership_length_months" class="form-label">Ownership (Months)</label>
                            <input type="number" name="ownership_length_months" id="ownership_length_months" 
                                   class="form-control" min="0" max="11" value="<?= htmlspecialchars(array_string_value($pet_row, 'ownership_length_months')) ?>">
                        </div>
                    </div>

                    <hr class="my-4">
                    <h5 class="card-title mb-4">Health Information</h5>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="spayed_neutered" 
                                       id="spayed_neutered" <?= !empty($pet['spayed_neutered']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="spayed_neutered">
                                    Spayed/Neutered
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="vaccines_current" 
                                       id="vaccines_current" <?= !isset($pet) || $pet['vaccines_current'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="vaccines_current">
                                    Vaccines Current
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="vaccine_notes" class="form-label">Vaccine Notes</label>
                        <textarea name="vaccine_notes" id="vaccine_notes" class="form-control" rows="2"><?= htmlspecialchars(array_string_value($pet_row, 'vaccine_notes')) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="medical_notes" class="form-label">Medical Notes</label>
                        <textarea name="medical_notes" id="medical_notes" class="form-control" rows="3"><?= htmlspecialchars(array_string_value($pet_row, 'medical_notes')) ?></textarea>
                        <small class="form-text text-muted">Any medical conditions, allergies, medications, etc.</small>
                    </div>

                    <hr class="my-4">
                    <h5 class="card-title mb-4">Behavior & Training</h5>

                    <div class="mb-3">
                        <label for="behavior_notes" class="form-label">Behavior Notes</label>
                        <textarea name="behavior_notes" id="behavior_notes" class="form-control" rows="3"><?= htmlspecialchars(array_string_value($pet_row, 'behavior_notes')) ?></textarea>
                        <small class="form-text text-muted">Temperament, behavior issues, triggers, etc.</small>
                    </div>

                    <div class="mb-3">
                        <label for="training_notes" class="form-label">Training Notes</label>
                        <textarea name="training_notes" id="training_notes" class="form-control" rows="3"><?= htmlspecialchars(array_string_value($pet_row, 'training_notes')) ?></textarea>
                        <small class="form-text text-muted">Training history, commands known, goals, etc.</small>
                    </div>

                    <div class="mb-3">
                        <label for="pet_sitting_notes" class="form-label">Pet Sitting Notes</label>
                        <textarea name="pet_sitting_notes" id="pet_sitting_notes" class="form-control" rows="3"><?= htmlspecialchars(array_string_value($pet_row, 'pet_sitting_notes')) ?></textarea>
                        <small class="form-text text-muted">Feeding amounts, routine details, and any other notes relevant to pet sitting.</small>
                    </div>

                    <?php if ($pet_id): ?>
                        <hr class="my-4">
                        <h5 class="card-title mb-3">Pet Forms</h5>
                        <p class="text-muted">Generate an object-linked pet form for <?= htmlspecialchars(array_string_value($pet_row, 'name'), ENT_QUOTES, 'UTF-8') ?>.</p>
                        <p>
                            <a href="form_requests_create.php?form_type=pet_form&amp;pet_id=<?= (int) $pet_id ?>" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-file-medical me-1"></i> Pet Form Link
                            </a>
                        </p>

                        <hr class="my-4">
                        <h5 class="card-title mb-4">Documents & Photos</h5>
                        
                        <div id="pet-files-section" class="mb-4">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label for="pet-file-input" class="form-label">Upload Files</label>
                                    <p class="text-muted small">Upload vaccination records, medical documents, photos, or other files related to <?= htmlspecialchars(array_string_value($pet_row, 'name'), ENT_QUOTES, 'UTF-8') ?>.</p>
                                    
                                    <div class="mb-3">
                                        <input type="file" id="pet-file-input" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf">
                                        <small class="form-text text-muted">Supported formats: JPG, PNG, GIF, PDF (Max 10MB)</small>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <input type="text" id="file-description-input" class="form-control" placeholder="Description (optional, e.g., 'Rabies vaccine 2024')">
                                    </div>
                                    
                                    <button type="button" id="upload-file-btn" class="btn btn-sm btn-primary" disabled>
                                        <i class="fas fa-cloud-upload"></i> Upload File
                                    </button>
                                    <div id="upload-status" class="mt-2"></div>
                                </div>
                            </div>
                            
                            <div id="uploaded-files-list" class="mt-3">
                                <h6 class="mb-3">Uploaded Files</h6>
                                <div id="files-container" class="row">
                                    <!-- Files will be loaded here via JavaScript -->
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr class="my-4">

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" 
                                   id="is_active" <?= !isset($pet) || $pet['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active Pet
                            </label>
                            <small class="form-text text-muted d-block">Uncheck if the pet is no longer with the client (passed away, rehomed, etc.)</small>
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars(pets_edit_return_url(scalar_string($_SERVER['HTTP_REFERER'] ?? ''))) ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> <?= $pet_id ? 'Update' : 'Add' ?> Pet
                    </button>
                    <a href="<?= $client_id ? 'clients_view.php?id=' . $client_id : 'pets_list.php' ?>" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($pet_id): ?>
<script>
// Pet File Upload Handler
(function() {
    const petId = <?= $pet_id ?>;
    const fileInput = document.getElementById('pet-file-input');
    const descriptionInput = document.getElementById('file-description-input');
    const uploadBtn = document.getElementById('upload-file-btn');
    const uploadStatus = document.getElementById('upload-status');
    const filesContainer = document.getElementById('files-container');
    
    // Enable upload button when file is selected
    fileInput.addEventListener('change', function() {
        uploadBtn.disabled = !this.files.length;
    });
    
    // Load existing files
    function loadFiles() {
        fetch('pet_files_list.php?pet_id=' + petId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayFiles(data.files);
                }
            })
            .catch(error => {
                console.error('Error loading files:', error);
            });
    }
    
    // Display files
    function displayFiles(files) {
        if (!files || files.length === 0) {
            filesContainer.innerHTML = '<div class="col-12"><p class="text-muted">No files uploaded yet.</p></div>';
            return;
        }
        
        filesContainer.innerHTML = '';
        
        files.forEach(file => {
            // Validate file.id is a number
            if (!Number.isInteger(file.id) || file.id <= 0) {
                console.error('Invalid file ID:', file.id);
                return;
            }
            
            const fileCard = createFileCard(file);
            filesContainer.appendChild(fileCard);
        });
    }
    
    // Create file card
    function createFileCard(file) {
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4 mb-3';
        
        const isImage = file.file_type === 'photo';
        const icon = isImage ? 'fa-image' : 'fa-file-pdf';
        const iconColor = isImage ? 'text-success' : 'text-danger';
        
        const fileSize = formatFileSize(file.file_size);
        const uploadDate = new Date(file.uploaded_at).toLocaleDateString();
        
        col.innerHTML = `
            <div class="card h-100">
                ${isImage ? `
                    <div class="card-img-top" style="height: 150px; overflow: hidden; background: #f8f9fa;">
                        <img src="pet_files_view.php?id=${file.id}" 
                             alt="${escapeHtml(file.original_name)}"
                             style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;"
                             onclick="window.open('pet_files_view.php?id=${file.id}', '_blank')">
                    </div>
                ` : `
                    <div class="card-body text-center pt-4">
                        <i class="fas ${icon} ${iconColor}" style="font-size: 3rem;"></i>
                    </div>
                `}
                <div class="card-body d-flex flex-column ${isImage ? 'pt-2' : ''}">
                    <h6 class="card-title text-truncate" title="${escapeHtml(file.original_name)}">
                        ${escapeHtml(file.original_name)}
                    </h6>
                    ${file.description ? `<p class="card-text small text-muted">${escapeHtml(file.description)}</p>` : ''}
                    <p class="card-text small">
                        <small class="text-muted">
                            ${fileSize} • ${uploadDate}
                        </small>
                    </p>
                    <div class="d-grid gap-1 mt-auto col-10 mx-auto">
                        <a href="pet_files_view.php?id=${file.id}" target="_blank" class="btn btn-sm btn-outline-primary text-nowrap">
                            <i class="fas fa-eye me-1"></i> View
                        </a>
                        <a href="pet_files_view.php?id=${file.id}&download=1" class="btn btn-sm btn-outline-secondary text-nowrap">
                            <i class="fas fa-download me-1"></i> Download
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger text-nowrap" onclick="deleteFile(${file.id})">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        return col;
    }
    
    // Format file size
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    
    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Show status message
    function showStatus(message, type) {
        uploadStatus.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
    }
    
    // Upload file
    uploadBtn.addEventListener('click', function() {
        const file = fileInput.files[0];
        if (!file) return;
        
        // Validate file size (10MB)
        if (file.size > 10 * 1024 * 1024) {
            showStatus('File is too large. Maximum size is 10MB.', 'danger');
            return;
        }
        
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        
        const formData = new FormData();
        formData.append('file', file);
        formData.append('pet_id', petId);
        formData.append('description', descriptionInput.value);
        
        fetch('pet_files_upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showStatus('File uploaded successfully!', 'success');
                fileInput.value = '';
                descriptionInput.value = '';
                loadFiles();
            } else {
                showStatus(data.message || 'Upload failed', 'danger');
            }
        })
        .catch(error => {
            showStatus('Upload failed: ' + error.message, 'danger');
        })
        .finally(() => {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fas fa-cloud-upload"></i> Upload File';
        });
    });
    
    // Delete file (global function)
    window.deleteFile = function(fileId) {
        // Validate fileId is a positive integer
        fileId = parseInt(fileId, 10);
        if (!Number.isInteger(fileId) || fileId <= 0) {
            showStatus('Invalid file ID', 'danger');
            return;
        }
        
        if (!confirm('Are you sure you want to delete this file? This action cannot be undone.')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('file_id', fileId);
        
        fetch('pet_files_delete.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showStatus('File deleted successfully!', 'success');
                loadFiles();
            } else {
                showStatus(data.message || 'Delete failed', 'danger');
            }
        })
        .catch(error => {
            showStatus('Delete failed: ' + error.message, 'danger');
        });
    };
    
    // Load files on page load
    loadFiles();
})();
</script>
<?php endif; ?>

<?php include '../backend/includes/footer.php'; ?>
