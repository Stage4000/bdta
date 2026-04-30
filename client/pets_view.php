<?php
/**
 * Pet Profile - View pet details without entering edit mode
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$pet_id = safe_int($_GET['id'] ?? 0);
if ($pet_id <= 0) {
    setFlashMessage('Pet not found.', 'danger');
    redirect('pets_list.php');
}

$stmt = $conn->prepare("
    SELECT p.*, c.name AS client_name, COUNT(pf.id) AS file_count
    FROM pets p
    JOIN clients c ON p.client_id = c.id
    LEFT JOIN pet_files pf ON pf.pet_id = p.id
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$pet_id]);
$pet = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

if ($pet === []) {
    setFlashMessage('Pet not found.', 'danger');
    redirect('pets_list.php');
}

$stmt = $conn->prepare("
    SELECT *
    FROM pet_files
    WHERE pet_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->execute([$pet_id]);
$pet_files = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));

$age_parts = [];
if (array_string_value($pet, 'age_years') !== '' && array_int_value($pet, 'age_years') > 0) {
    $age_parts[] = array_int_value($pet, 'age_years') . 'y';
}
if (array_string_value($pet, 'age_months') !== '' && array_int_value($pet, 'age_months') > 0) {
    $age_parts[] = array_int_value($pet, 'age_months') . 'm';
}
$age_display = $age_parts !== [] ? implode(' ', $age_parts) : '—';

$ownership_parts = [];
if (array_string_value($pet, 'ownership_length_years') !== '' && array_int_value($pet, 'ownership_length_years') > 0) {
    $ownership_parts[] = array_int_value($pet, 'ownership_length_years') . 'y';
}
if (array_string_value($pet, 'ownership_length_months') !== '' && array_int_value($pet, 'ownership_length_months') > 0) {
    $ownership_parts[] = array_int_value($pet, 'ownership_length_months') . 'm';
}
$ownership_display = $ownership_parts !== [] ? implode(' ', $ownership_parts) : '—';

$page_title = array_string_value($pet, 'name', 'Pet Profile');
include '../backend/includes/header.php';
?>

<?php
function render_read_only_text(string $value, string $empty = 'No notes added yet.'): string
{
    $value = trim($value);
    if ($value === '') {
        return '<span class="text-muted">' . escape($empty) . '</span>';
    }

    return nl2br(escape($value));
}
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-lg-8">
            <h2 class="mb-1">
                <i class="fas fa-dog me-2"></i><?= escape(array_string_value($pet, 'name', 'Pet Profile')) ?>
                <?php if (array_int_value($pet, 'is_active', 1) !== 1): ?>
                    <span class="badge bg-secondary ms-2">Archived</span>
                <?php endif; ?>
            </h2>
            <p class="text-muted mb-0">
                <a href="clients_view.php?id=<?= (int) array_int_value($pet, 'client_id') ?>"><?= escape(array_string_value($pet, 'client_name')) ?></a>
                <?php if (array_string_value($pet, 'species') !== ''): ?>
                    <span class="mx-2">•</span><?= escape(array_string_value($pet, 'species')) ?>
                <?php endif; ?>
                <?php if (array_string_value($pet, 'breed') !== ''): ?>
                    <span class="mx-2">•</span><?= escape(array_string_value($pet, 'breed')) ?>
                <?php endif; ?>
            </p>
            <p class="text-muted mt-2 mb-0">
                <a href="clients_view.php?id=<?= (int) array_int_value($pet, 'client_id') ?>"><i class="fas fa-arrow-left me-1"></i>Back to Client Profile</a>
            </p>
        </div>
        <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
            <a href="pets_edit.php?id=<?= (int) $pet_id ?>" class="btn btn-primary">
                <i class="fas fa-pencil me-1"></i>Edit Pet
            </a>
            <a href="form_requests_create.php?form_type=pet_form&amp;pet_id=<?= (int) $pet_id ?>" class="btn btn-outline-success">
                <i class="fas fa-file-medical me-1"></i>Pet Form
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Basic Information</h5>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Owner</dt>
                        <dd class="col-sm-8">
                            <a href="clients_view.php?id=<?= (int) array_int_value($pet, 'client_id') ?>"><?= escape(array_string_value($pet, 'client_name')) ?></a>
                        </dd>

                        <dt class="col-sm-4">Species</dt>
                        <dd class="col-sm-8"><?= escape(array_string_value($pet, 'species', '—')) ?></dd>

                        <dt class="col-sm-4">Breed</dt>
                        <dd class="col-sm-8"><?= escape(array_string_value($pet, 'breed', '—')) ?></dd>

                        <dt class="col-sm-4">Date of Birth</dt>
                        <dd class="col-sm-8"><?= escape(array_string_value($pet, 'date_of_birth', '—')) ?></dd>

                        <dt class="col-sm-4">Age</dt>
                        <dd class="col-sm-8"><?= escape($age_display) ?></dd>

                        <dt class="col-sm-4">Source</dt>
                        <dd class="col-sm-8"><?= escape(array_string_value($pet, 'source', '—')) ?></dd>

                        <dt class="col-sm-4">Ownership</dt>
                        <dd class="col-sm-8"><?= escape($ownership_display) ?></dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            <?php if (array_int_value($pet, 'is_active', 1) === 1): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Health Information</h5>
                    <dl class="row mb-4">
                        <dt class="col-sm-5">Spayed/Neutered</dt>
                        <dd class="col-sm-7">
                            <?php if (array_int_value($pet, 'spayed_neutered') === 1): ?>
                                <span class="badge bg-success">Yes</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-5">Vaccines Current</dt>
                        <dd class="col-sm-7">
                            <?php if (array_int_value($pet, 'vaccines_current', 1) === 1): ?>
                                <span class="badge bg-success">Current</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Needs Update</span>
                            <?php endif; ?>
                        </dd>
                    </dl>

                    <div class="mb-3">
                        <label class="text-muted small text-uppercase fw-semibold d-block mb-1">Vaccine Notes</label>
                        <div><?= render_read_only_text(array_string_value($pet, 'vaccine_notes')) ?></div>
                    </div>

                    <div>
                        <label class="text-muted small text-uppercase fw-semibold d-block mb-1">Medical Notes</label>
                        <div><?= render_read_only_text(array_string_value($pet, 'medical_notes')) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Behavior Notes</h5>
                    <div><?= render_read_only_text(array_string_value($pet, 'behavior_notes')) ?></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Training Notes</h5>
                    <div><?= render_read_only_text(array_string_value($pet, 'training_notes')) ?></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Pet Sitting Notes</h5>
                    <div><?= render_read_only_text(array_string_value($pet, 'pet_sitting_notes')) ?></div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="card-title mb-0">Documents &amp; Photos</h5>
                        <span class="badge bg-info text-dark"><?= count($pet_files) ?> file(s)</span>
                    </div>

                    <?php if ($pet_files === []): ?>
                        <p class="text-muted mb-0">No files uploaded yet.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($pet_files as $file): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= escape(array_string_value($file, 'original_name')) ?></div>
                                        <?php if (array_string_value($file, 'description') !== ''): ?>
                                            <div class="text-muted small"><?= escape(array_string_value($file, 'description')) ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small"><?= escape(array_string_value($file, 'uploaded_at')) ?></div>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <a href="pet_files_view.php?id=<?= (int) array_int_value($file, 'id') ?>" target="_blank" class="btn btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i>View
                                        </a>
                                        <a href="pet_files_view.php?id=<?= (int) array_int_value($file, 'id') ?>&download=1" class="btn btn-outline-secondary">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
