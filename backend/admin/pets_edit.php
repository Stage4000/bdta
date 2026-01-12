<?php
/**
 * Pet Edit - Add or edit a pet
 */

require_once '../includes/config.php';
require_once '../includes/database.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$pet_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$pet = null;
$clients = [];

// Get all clients for dropdown
$stmt = $conn->query("SELECT id, name, email FROM clients ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing, get pet data
if ($pet_id) {
    $stmt = $conn->prepare("SELECT * FROM pets WHERE id = ?");
    $stmt->execute([$pet_id]);
    $pet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pet) {
        $_SESSION['flash_error'] = "Pet not found.";
        header('Location: pets_list.php');
        exit;
    }
    
    $client_id = $pet['client_id'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = (int)$_POST['client_id'];
    $name = trim($_POST['name']);
    $species = trim($_POST['species']);
    $breed = trim($_POST['breed']);
    $date_of_birth = $_POST['date_of_birth'] ?: null;
    $age_years = $_POST['age_years'] ? (int)$_POST['age_years'] : null;
    $age_months = $_POST['age_months'] ? (int)$_POST['age_months'] : null;
    $source = trim($_POST['source']);
    $ownership_length_years = $_POST['ownership_length_years'] ? (int)$_POST['ownership_length_years'] : null;
    $ownership_length_months = $_POST['ownership_length_months'] ? (int)$_POST['ownership_length_months'] : null;
    $spayed_neutered = isset($_POST['spayed_neutered']) ? 1 : 0;
    $vaccines_current = isset($_POST['vaccines_current']) ? 1 : 0;
    $vaccine_notes = trim($_POST['vaccine_notes']);
    $behavior_notes = trim($_POST['behavior_notes']);
    $medical_notes = trim($_POST['medical_notes']);
    $training_notes = trim($_POST['training_notes']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    $errors = [];
    if (empty($client_id)) $errors[] = "Client is required.";
    if (empty($name)) $errors[] = "Pet name is required.";
    
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
                        is_active = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $client_id, $name, $species, $breed, $date_of_birth,
                    $age_years, $age_months, $source,
                    $ownership_length_years, $ownership_length_months,
                    $spayed_neutered, $vaccines_current,
                    $vaccine_notes, $behavior_notes, $medical_notes, $training_notes,
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
                        vaccine_notes, behavior_notes, medical_notes, training_notes,
                        is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $client_id, $name, $species, $breed, $date_of_birth,
                    $age_years, $age_months, $source,
                    $ownership_length_years, $ownership_length_months,
                    $spayed_neutered, $vaccines_current,
                    $vaccine_notes, $behavior_notes, $medical_notes, $training_notes,
                    $is_active
                ]);
                
                $_SESSION['flash_message'] = "Pet added successfully!";
            }
            
            // Redirect back to client profile or pets list
            $return_url = isset($_POST['return_to']) ? $_POST['return_to'] : 'pets_list.php';
            header("Location: $return_url");
            exit;
            
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title = $pet_id ? "Edit Pet" : "Add Pet";
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1><i class="bi bi-heart-fill"></i> <?= htmlspecialchars($page_title) ?></h1>
            <p class="text-muted">
                <?php if ($client_id): ?>
                    <a href="clients_edit.php?id=<?= $client_id ?>">← Back to Client Profile</a>
                <?php else: ?>
                    <a href="pets_list.php">← Back to Pets List</a>
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
            <form method="POST" class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">Basic Information</h5>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="client_id" class="form-label">Client Owner *</label>
                            <select name="client_id" id="client_id" class="form-select" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>" <?= $client_id == $client['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($client['name']) ?> (<?= htmlspecialchars($client['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="name" class="form-label">Pet Name *</label>
                            <input type="text" name="name" id="name" class="form-control" 
                                   value="<?= htmlspecialchars($pet['name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="species" class="form-label">Species</label>
                            <input type="text" name="species" id="species" class="form-control" 
                                   value="<?= htmlspecialchars($pet['species'] ?? 'Dog') ?>">
                            <small class="form-text text-muted">e.g., Dog, Cat, etc.</small>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="breed" class="form-label">Breed</label>
                            <input type="text" name="breed" id="breed" class="form-control" 
                                   value="<?= htmlspecialchars($pet['breed'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="date_of_birth" class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="date_of_birth" class="form-control" 
                                   value="<?= htmlspecialchars($pet['date_of_birth'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="age_years" class="form-label">Age (Years)</label>
                            <input type="number" name="age_years" id="age_years" class="form-control" min="0" 
                                   value="<?= htmlspecialchars($pet['age_years'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="age_months" class="form-label">Age (Months)</label>
                            <input type="number" name="age_months" id="age_months" class="form-control" min="0" max="11" 
                                   value="<?= htmlspecialchars($pet['age_months'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="source" class="form-label">Source</label>
                            <input type="text" name="source" id="source" class="form-control" 
                                   value="<?= htmlspecialchars($pet['source'] ?? '') ?>">
                            <small class="form-text text-muted">Where acquired (breeder, rescue, etc.)</small>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="ownership_length_years" class="form-label">Ownership (Years)</label>
                            <input type="number" name="ownership_length_years" id="ownership_length_years" 
                                   class="form-control" min="0" value="<?= htmlspecialchars($pet['ownership_length_years'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-4">
                            <label for="ownership_length_months" class="form-label">Ownership (Months)</label>
                            <input type="number" name="ownership_length_months" id="ownership_length_months" 
                                   class="form-control" min="0" max="11" value="<?= htmlspecialchars($pet['ownership_length_months'] ?? '') ?>">
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
                        <textarea name="vaccine_notes" id="vaccine_notes" class="form-control" rows="2"><?= htmlspecialchars($pet['vaccine_notes'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="medical_notes" class="form-label">Medical Notes</label>
                        <textarea name="medical_notes" id="medical_notes" class="form-control" rows="3"><?= htmlspecialchars($pet['medical_notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted">Any medical conditions, allergies, medications, etc.</small>
                    </div>

                    <hr class="my-4">
                    <h5 class="card-title mb-4">Behavior & Training</h5>

                    <div class="mb-3">
                        <label for="behavior_notes" class="form-label">Behavior Notes</label>
                        <textarea name="behavior_notes" id="behavior_notes" class="form-control" rows="3"><?= htmlspecialchars($pet['behavior_notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted">Temperament, behavior issues, triggers, etc.</small>
                    </div>

                    <div class="mb-3">
                        <label for="training_notes" class="form-label">Training Notes</label>
                        <textarea name="training_notes" id="training_notes" class="form-control" rows="3"><?= htmlspecialchars($pet['training_notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted">Training history, commands known, goals, etc.</small>
                    </div>

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
                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'pets_list.php') ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> <?= $pet_id ? 'Update' : 'Add' ?> Pet
                    </button>
                    <a href="<?= $client_id ? 'clients_edit.php?id=' . $client_id : 'pets_list.php' ?>" class="btn btn-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
