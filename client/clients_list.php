<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle client deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        setFlashMessage('Invalid request.', 'danger');
        redirect('clients_list.php');
    }
    $id = intval($_POST['delete_id']);
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
        <h2><i class="fas fa-users me-2"></i>Client Management</h2>
        <a href="clients_edit.php" class="btn btn-primary">
            <i class="fas fa-circle-plus"></i> Add New Client
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
                <table class="table table-hover client-list-table">
                    <thead>
                        <tr>
                            <th class="d-none d-md-table-cell">ID</th>
                            <th>Name</th>
                            <th class="d-none d-sm-table-cell">Email</th>
                            <th class="d-none d-md-table-cell">Phone</th>
                            <th class="d-none d-lg-table-cell">Created</th>
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
                                    <td class="d-none d-md-table-cell"><?= escape($client['id']) ?></td>
                                    <td>
                                        <strong><?= escape($client['name']) ?></strong>
                                        <?php if (!empty($client['is_admin'])): ?>
                                            <span class="badge bg-primary ms-2" title="Has admin access">
                                                <i class="fas fa-shield-check"></i> Admin
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-sm-table-cell"><?= escape($client['email']) ?></td>
                                    <td class="d-none d-md-table-cell"><?= escape($client['phone'] ?? 'N/A') ?></td>
                                    <td class="d-none d-lg-table-cell"><?= formatDate($client['created_at']) ?></td>
                                    <td class="text-nowrap">
                                        <!-- Desktop: individual icon buttons (hidden on mobile) -->
                                        <div class="d-none d-md-inline-flex gap-1 client-action-btns">
                                            <a href="clients_view.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-info" title="View Profile">
                                                <i class="fa-solid fa-address-book"></i>
                                            </a>
                                            <a href="clients_edit.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-pencil"></i>
                                            </a>
                                            <a href="pets_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-success" title="View Pets">
                                                <i class="fa-solid fa-dog"></i>
                                            </a>
                                            <a href="time_entries_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Time Entries">
                                                <i class="fas fa-clock"></i>
                                            </a>
                                            <?php if (empty($client['is_admin'])): ?>
                                            <a href="impersonate_client.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-warning" title="View Portal as Client"
                                               onclick="return confirm('View the client portal as this client?')">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php endif; ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this client? This cannot be undone.')">
                                                <input type="hidden" name="delete_id" value="<?= $client['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <!-- Mobile: compact dropdown menu -->
                                        <div class="d-md-none client-action-dropdown">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item" href="clients_view.php?id=<?= $client['id'] ?>">
                                                            <i class="fa-solid fa-address-book me-2 text-info"></i>View Profile
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="clients_edit.php?id=<?= $client['id'] ?>">
                                                            <i class="fas fa-pencil me-2 text-primary"></i>Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="pets_list.php?client_id=<?= $client['id'] ?>">
                                                            <i class="fa-solid fa-dog me-2 text-success"></i>View Pets
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="time_entries_list.php?client_id=<?= $client['id'] ?>">
                                                            <i class="fas fa-clock me-2 text-secondary"></i>Time Entries
                                                        </a>
                                                    </li>
                                                    <?php if (empty($client['is_admin'])): ?>
                                                    <li>
                                                        <a class="dropdown-item" href="impersonate_client.php?id=<?= $client['id'] ?>"
                                                           onclick="return confirm('View the client portal as this client?')">
                                                            <i class="fas fa-eye me-2 text-warning"></i>View Portal as Client
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="post" onsubmit="return confirm('Are you sure you want to delete this client? This cannot be undone.')">
                                                            <input type="hidden" name="delete_id" value="<?= $client['id'] ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                            <button type="submit" class="dropdown-item text-danger w-100 text-start border-0 bg-transparent">
                                                                <i class="fas fa-trash me-2"></i>Delete
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
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
