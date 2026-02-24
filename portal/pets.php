<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
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
    $pet_id          = intval($_POST['pet_id'] ?? 0);
    $name            = trim($_POST['name'] ?? '');
    $species         = trim($_POST['species'] ?? '');
    $breed           = trim($_POST['breed'] ?? '');
    $date_of_birth   = $_POST['date_of_birth'] ?: null;
    $spayed_neutered = isset($_POST['spayed_neutered']) ? 1 : 0;
    $vaccine_notes   = trim($_POST['vaccine_notes'] ?? '');
    $behavior_notes  = trim($_POST['behavior_notes'] ?? '');
    $medical_notes   = trim($_POST['medical_notes'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');

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
    $pet_id = intval($_GET['edit']);
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
            <input type="hidden" name="pet_id" value="<?php echo $edit_pet ? intval($edit_pet['id']) : 0; ?>">
            <div class="row g-3">
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
                               <?php echo !empty($edit_pet['spayed_neutered']) ? 'checked' : ''; ?>>
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

<?php include '../portal/includes/footer.php'; ?>
