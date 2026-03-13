<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

$errors  = [];
$success = '';
$edit_pet = null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $pet_id = intval($_POST['pet_id'] ?? 0);
    // Verify ownership
    $stmt = $conn->prepare("SELECT id FROM pets WHERE id = ? AND client_id = ?");
    $stmt->execute([$pet_id, $client_id]);
    if ($stmt->fetch()) {
        $stmt = $conn->prepare("DELETE FROM pets WHERE id = ? AND client_id = ?");
        $stmt->execute([$pet_id, $client_id]);
        logClientActivity($client_id, 'pet_delete', 'Deleted pet ID ' . $pet_id, $conn);
        setFlashMessage('Pet removed.', 'success');
        redirect(PORTAL_URL . 'pets.php');
    }
}

// Handle add/edit submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'save')) {
    $pet_id          = safe_int($_POST['pet_id'] ?? 0);
    $name            = trim(scalar_string($_POST['name'] ?? ''));
    $species         = trim(scalar_string($_POST['species'] ?? ''));
    $breed           = trim(scalar_string($_POST['breed'] ?? ''));
    $date_of_birth   = scalar_string($_POST['date_of_birth'] ?? '') ?: null;
    $spayed_neutered = isset($_POST['spayed_neutered']) ? 1 : 0;
    $vaccine_notes   = trim(scalar_string($_POST['vaccine_notes'] ?? ''));
    $behavior_notes  = trim(scalar_string($_POST['behavior_notes'] ?? ''));
    $medical_notes   = trim(scalar_string($_POST['medical_notes'] ?? ''));
    $notes           = trim(scalar_string($_POST['notes'] ?? ''));

    if (empty($name)) $errors[] = 'Pet name is required.';

    if (empty($errors)) {
        if ($pet_id) {
            // Verify ownership
            $stmt = $conn->prepare("SELECT id FROM pets WHERE id = ? AND client_id = ?");
            $stmt->execute([$pet_id, $client_id]);
            if ($stmt->fetch()) {
                $stmt = $conn->prepare("UPDATE pets SET name=?, species=?, breed=?, date_of_birth=?, spayed_neutered=?, vaccine_notes=?, behavior_notes=?, medical_notes=?, notes=? WHERE id=? AND client_id=?");
                $stmt->execute([$name, $species, $breed, $date_of_birth, $spayed_neutered, $vaccine_notes, $behavior_notes, $medical_notes, $notes, $pet_id, $client_id]);
                logClientActivity($client_id, 'pet_update', 'Updated pet: ' . $name, $conn);
                $success = 'Pet updated successfully.';
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO pets (client_id, name, species, breed, date_of_birth, spayed_neutered, vaccine_notes, behavior_notes, medical_notes, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$client_id, $name, $species, $breed, $date_of_birth, $spayed_neutered, $vaccine_notes, $behavior_notes, $medical_notes, $notes]);
            logClientActivity($client_id, 'pet_add', 'Added pet: ' . $name, $conn);
            $success = 'Pet added successfully.';
        }
    }
}

// Load pet for editing
if (isset($_GET['edit'])) {
    $pet_id = safe_int($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM pets WHERE id = ? AND client_id = ?");
    $stmt->execute([$pet_id, $client_id]);
    $edit_pet = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Load all pets
$stmt = $conn->prepare("SELECT * FROM pets WHERE client_id = ? ORDER BY name");
$stmt->execute([$client_id]);
$pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Pets';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">My Pets</h2>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo escape($e); ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?php echo escape($success); ?></div>
<?php endif; ?>

<!-- Add/Edit form -->
<div class="card mb-4">
    <div class="card-header">
        <strong><?php echo $edit_pet ? 'Edit Pet' : 'Add New Pet'; ?></strong>
        <?php if ($edit_pet): ?>
            <a href="pets.php" class="btn btn-sm btn-outline-secondary float-end">Cancel Edit</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="pet_id" value="<?php echo $edit_pet ? intval($edit_pet['id']) : 0; ?>">            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required
                           value="<?php echo escape($edit_pet['name'] ?? ($_POST['name'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Species</label>
                    <input type="text" class="form-control" name="species"
                           value="<?php echo escape($edit_pet['species'] ?? ($_POST['species'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Breed</label>
                    <input type="text" class="form-control" name="breed"
                           value="<?php echo escape($edit_pet['breed'] ?? ($_POST['breed'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" class="form-control" name="date_of_birth"
                           value="<?php echo escape($edit_pet['date_of_birth'] ?? ($_POST['date_of_birth'] ?? '')); ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="spayed_neutered" name="spayed_neutered"
                               <?php echo (!empty($edit_pet['spayed_neutered']) || (!empty($errors) && isset($_POST['spayed_neutered']))) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="spayed_neutered">Spayed / Neutered</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Vaccine Notes</label>
                    <textarea class="form-control" name="vaccine_notes" rows="2"><?php echo escape($edit_pet['vaccine_notes'] ?? ($_POST['vaccine_notes'] ?? '')); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Behavior Notes</label>
                    <textarea class="form-control" name="behavior_notes" rows="2"><?php echo escape($edit_pet['behavior_notes'] ?? ($_POST['behavior_notes'] ?? '')); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Medical Notes</label>
                    <textarea class="form-control" name="medical_notes" rows="2"><?php echo escape($edit_pet['medical_notes'] ?? ($_POST['medical_notes'] ?? '')); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Other Notes</label>
                    <textarea class="form-control" name="notes" rows="2"><?php echo escape($edit_pet['notes'] ?? ($_POST['notes'] ?? '')); ?></textarea>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><?php echo $edit_pet ? 'Update Pet' : 'Add Pet'; ?></button>
            </div>
        </form>

        <?php if ($edit_pet): ?>
        <hr>
        <h6 class="mb-3">Documents &amp; Photos</h6>
        <p class="text-muted small">Upload vaccination records, medical documents, photos, or other files.</p>
        <div class="mb-3">
            <input type="file" id="pet-file-input" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf">
            <small class="form-text text-muted">Supported formats: JPG, PNG, GIF, PDF (Max 10MB)</small>
        </div>
        <div class="mb-3">
            <input type="text" id="file-description-input" class="form-control" placeholder="Description (optional, e.g., 'Rabies vaccine 2024')">
        </div>
        <button type="button" id="upload-file-btn" class="btn btn-sm btn-primary" disabled>
            <i class="fas fa-cloud-upload-alt me-1"></i> Upload File
        </button>
        <div id="upload-status" class="mt-2"></div>
        <div id="uploaded-files-list" class="mt-3">
            <h6 class="mb-2">Uploaded Files</h6>
            <div id="files-container" class="row g-2"></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pet list -->
<?php if (empty($pets)): ?>
    <div class="alert alert-info">No pets on file. Add one above!</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><strong>Your Pets</strong></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Species</th><th>Breed</th><th>DOB</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pets as $pet): ?>
                <tr>
                    <td><?php echo escape($pet['name']); ?></td>
                    <td><?php echo escape($pet['species'] ?? ''); ?></td>
                    <td><?php echo escape($pet['breed'] ?? ''); ?></td>
                    <td><?php echo escape($pet['date_of_birth'] ?? ''); ?></td>
                    <td>
                        <a href="pets.php?edit=<?php echo intval($pet['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this pet?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="pet_id" value="<?php echo intval($pet['id']); ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($edit_pet): ?>
<script>
(function() {
    const petId = <?php echo intval($edit_pet['id']); ?>;
    const fileInput = document.getElementById('pet-file-input');
    const descriptionInput = document.getElementById('file-description-input');
    const uploadBtn = document.getElementById('upload-file-btn');
    const uploadStatus = document.getElementById('upload-status');
    const filesContainer = document.getElementById('files-container');

    fileInput.addEventListener('change', function() {
        uploadBtn.disabled = !this.files.length;
    });

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showStatus(message, type) {
        uploadStatus.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">
            ${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
    }

    function loadFiles() {
        fetch('pet_files_list.php?pet_id=' + petId)
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const files = data.files;
                if (!files || files.length === 0) {
                    filesContainer.innerHTML = '<div class="col-12"><p class="text-muted small">No files uploaded yet.</p></div>';
                    return;
                }
                filesContainer.innerHTML = '';
                files.forEach(file => {
                    if (!Number.isInteger(file.id) || file.id <= 0) return;
                    const isImage = file.file_type === 'photo';
                    const icon = isImage ? 'fa-image' : 'fa-file-pdf';
                    const iconColor = isImage ? 'text-success' : 'text-danger';
                    const fileSize = formatFileSize(file.file_size);
                    const uploadDate = new Date(file.uploaded_at).toLocaleDateString();
                    const col = document.createElement('div');
                    col.className = 'col-md-6 col-lg-4 mb-2';
                    col.innerHTML = `<div class="card h-100">
                        ${isImage ? `<div class="card-img-top" style="height:120px;overflow:hidden;background:#f8f9fa;">
                            <img src="pet_files_view.php?id=${file.id}" alt="${escapeHtml(file.original_name)}"
                                 style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                                 onclick="window.open('pet_files_view.php?id=${file.id}','_blank')">
                        </div>` : `<div class="card-body text-center pt-3 pb-0">
                            <i class="fas ${icon} ${iconColor}" style="font-size:2.5rem;"></i>
                        </div>`}
                        <div class="card-body pt-2 pb-2">
                            <div class="small fw-semibold text-truncate" title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</div>
                            ${file.description ? `<div class="text-muted small">${escapeHtml(file.description)}</div>` : ''}
                            <div class="text-muted" style="font-size:0.75rem;">${fileSize} &bull; ${uploadDate}</div>
                            <div class="btn-group btn-group-sm w-100 mt-1">
                                <a href="pet_files_view.php?id=${file.id}" target="_blank" class="btn btn-outline-primary"><i class="fas fa-eye"></i></a>
                                <a href="pet_files_view.php?id=${file.id}&download=1" class="btn btn-outline-secondary"><i class="fas fa-download"></i></a>
                                <button type="button" class="btn btn-outline-danger" onclick="portalDeleteFile(${file.id})"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    </div>`;
                    filesContainer.appendChild(col);
                });
            })
            .catch(err => console.error('Error loading files:', err));
    }

    uploadBtn.addEventListener('click', function() {
        const file = fileInput.files[0];
        if (!file) return;
        if (file.size > 10 * 1024 * 1024) { showStatus('File is too large. Maximum size is 10MB.', 'danger'); return; }
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        const formData = new FormData();
        formData.append('file', file);
        formData.append('pet_id', petId);
        formData.append('description', descriptionInput.value);
        fetch('pet_files_upload.php', { method: 'POST', body: formData })
            .then(r => r.json())
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
            .catch(err => showStatus('Upload failed: ' + err.message, 'danger'))
            .finally(() => {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="fas fa-cloud-upload-alt me-1"></i> Upload File';
            });
    });

    window.portalDeleteFile = function(fileId) {
        fileId = parseInt(fileId, 10);
        if (!Number.isInteger(fileId) || fileId <= 0) { showStatus('Invalid file ID', 'danger'); return; }
        if (!confirm('Delete this file? This cannot be undone.')) return;
        const formData = new FormData();
        formData.append('file_id', fileId);
        fetch('pet_files_delete.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) { loadFiles(); } else { showStatus(data.message || 'Delete failed', 'danger'); }
            })
            .catch(err => showStatus('Delete failed: ' + err.message, 'danger'));
    };

    loadFiles();
})();
</script>
<?php endif; ?>

<?php include '../portal/includes/footer.php'; ?>
