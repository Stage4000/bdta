<?php
require_once '../backend/includes/config.php';
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

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-people me-2"></i>Client Management</h2>
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
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <p class="text-muted">No clients found. Add your first client to get started!</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                                <tr>
                                    <td><?= escape($client['id']) ?></td>
                                    <td>
                                        <strong><?= escape($client['name']) ?></strong>
                                        <?php if (!empty($client['is_admin'])): ?>
                                            <span class="badge bg-primary ms-2" title="Has admin access">
                                                <i class="bi bi-shield-check"></i> Admin
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= escape($client['email']) ?></td>
                                    <td><?= escape($client['phone'] ?? 'N/A') ?></td>
                                    <td><?= formatDate($client['created_at']) ?></td>
                                    <td>
                                        <a href="clients_view.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-info" title="View Profile">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="clients_edit.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="pets_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-success" title="View Pets">
                                            <i class="fa-solid fa-dog"></i>
                                        </a>
                                        <a href="time_entries_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Time Entries">
                                            <i class="bi bi-clock"></i>
                                        </a>
                                        <a href="?delete=<?= $client['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Are you sure you want to delete this client? This cannot be undone.')" title="Delete">
                                            <i class="bi bi-trash"></i>
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

<?php include '../backend/includes/footer.php'; ?>
