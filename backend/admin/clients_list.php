<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle client deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    setFlashMessage('Client deleted successfully!', 'success');
    redirect('clients_list.php');
}

// Fetch all clients
$stmt = $conn->query("SELECT * FROM clients ORDER BY created_at DESC");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Client Management</h2>
        <a href="clients_edit.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Add New Client
        </a>
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
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Dog Name</th>
                            <th>Dog Breed</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <p class="text-muted">No clients found. Add your first client to get started!</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td><?= $client['id'] ?></td>
                                    <td><strong><?= escape($client['name']) ?></strong></td>
                                    <td><?= escape($client['email']) ?></td>
                                    <td><?= escape($client['phone'] ?? 'N/A') ?></td>
                                    <td><?= escape($client['dog_name'] ?? 'N/A') ?></td>
                                    <td><?= escape($client['dog_breed'] ?? 'N/A') ?></td>
                                    <td><?= formatDate($client['created_at']) ?></td>
                                    <td>
                                        <a href="clients_edit.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <a href="time_entries_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-clock"></i> Time
                                        </a>
                                        <a href="invoices_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-file-text"></i> Invoices
                                        </a>
                                        <a href="clients_list.php?delete=<?= $client['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Are you sure you want to delete this client? This cannot be undone.')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
