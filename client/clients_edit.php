<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$client = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        setFlashMessage('Client not found!', 'danger');
        redirect('clients_list.php');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $dog_name = trim($_POST['dog_name'] ?? '');
    $dog_breed = trim($_POST['dog_breed'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($name) || empty($email)) {
        setFlashMessage('Name and email are required!', 'danger');
    } else {
        if ($id > 0) {
            // Update existing client
            $stmt = $conn->prepare("
                UPDATE clients 
                SET name = ?, email = ?, phone = ?, address = ?, dog_name = ?, dog_breed = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $address, $dog_name, $dog_breed, $notes, $id]);
            setFlashMessage('Client updated successfully!', 'success');
        } else {
            // Create new client
            $stmt = $conn->prepare("
                INSERT INTO clients (name, email, phone, address, dog_name, dog_breed, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email, $phone, $address, $dog_name, $dog_breed, $notes]);
            setFlashMessage('Client created successfully!', 'success');
        }
        redirect('clients_list.php');
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-people me-2"></i><?= $id > 0 ? 'Edit Client' : 'Add New Client' ?></h2>
                <div>
                    <?php if ($id > 0): ?>
                        <a href="credits_manage.php?client_id=<?= $id ?>" class="btn btn-success me-2">
                            <i class="bi bi-wallet2"></i> Manage Credits
                        </a>
                    <?php endif; ?>
                    <a href="clients_list.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Clients
                    </a>
                </div>
            </div>

            <?php
            $flash = getFlashMessage();
            if ($flash):
            ?>
                <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
                    <?= escape($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Client Name *</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       value="<?= escape($client['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email *</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?= escape($client['email'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?= escape($client['phone'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address" 
                                       value="<?= escape($client['address'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="dog_name" class="form-label">Dog Name</label>
                                <input type="text" class="form-control" id="dog_name" name="dog_name" 
                                       value="<?= escape($client['dog_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="dog_breed" class="form-label">Dog Breed</label>
                                <input type="text" class="form-control" id="dog_breed" name="dog_breed" 
                                       value="<?= escape($client['dog_breed'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="4"><?= escape($client['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> <?= $id > 0 ? 'Update Client' : 'Create Client' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
