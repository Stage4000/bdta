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
    $notes = trim($_POST['notes'] ?? '');
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    
    if (empty($name) || empty($email)) {
        setFlashMessage('Name and email are required!', 'danger');
    } else {
        if ($id > 0) {
            // Update existing client
            $stmt = $conn->prepare("
                UPDATE clients 
                SET name = ?, email = ?, phone = ?, address = ?, notes = ?, is_admin = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $address, $notes, $is_admin, $id]);
            setFlashMessage('Client updated successfully!', 'success');
        } else {
            // Create new client
            $stmt = $conn->prepare("
                INSERT INTO clients (name, email, phone, address, notes, is_admin) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email, $phone, $address, $notes, $is_admin]);
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
                <h2><i class="fas fa-users me-2"></i><?= $id > 0 ? 'Edit Client' : 'Add New Client' ?></h2>
                <div>
                    <?php if ($id > 0): ?>
                        <a href="pets_edit.php?client_id=<?= $id ?>" class="btn btn-success me-2">
                            <i class="fa-solid fa-dog"></i> Add Pet
                        </a>
                        <a href="credits_manage.php?client_id=<?= $id ?>" class="btn btn-info me-2">
                            <i class="fas fa-wallet"></i> Manage Credits
                        </a>
                        <a href="client_set_password.php?client_id=<?= $id ?>" class="btn btn-warning me-2">
                            <i class="fas fa-key"></i> Set Password
                        </a>
                    <?php endif; ?>
                    <a href="clients_list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Clients
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

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="4"><?= escape($client['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" 
                                       <?= !empty($client['is_admin']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_admin">
                                    <strong>Admin Access</strong>
                                </label>
                                <div class="form-text">
                                    <i class="fas fa-shield-check"></i> Grant this client administrative access to the system.
                                    Admin clients can manage all clients, bookings, and settings.
                                </div>
                            </div>
                        </div>

                        <?php if ($id > 0): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-circle-info"></i> To manage pets for this client, use the 
                                <a href="pets_list.php?client_id=<?= $id ?>" class="alert-link">Pets page</a>.
                            </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-floppy-disk"></i> <?= $id > 0 ? 'Update Client' : 'Create Client' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
