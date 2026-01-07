<?php
/**
 * Pets Management - List all pets
 */

require_once '../includes/config.php';
require_once '../includes/database.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get client filter if provided
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where = $client_id ? "WHERE p.client_id = :client_id" : "";
$count_query = "SELECT COUNT(*) FROM pets p $where";
$stmt = $conn->prepare($count_query);
if ($client_id) {
    $stmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
}
$stmt->execute();
$total_pets = $stmt->fetchColumn();
$total_pages = ceil($total_pets / $per_page);

// Get pets
$query = "
    SELECT p.*, c.name as client_name, c.email as client_email
    FROM pets p
    JOIN clients c ON p.client_id = c.id
    $where
    ORDER BY p.name ASC
    LIMIT :limit OFFSET :offset
";
$stmt = $conn->prepare($query);
if ($client_id) {
    $stmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get client info if filtering
$client = null;
if ($client_id) {
    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title = $client ? "Pets for " . htmlspecialchars($client['name']) : "All Pets";
include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><i class="bi bi-heart-fill"></i> <?= htmlspecialchars($page_title) ?></h1>
            <?php if ($client): ?>
                <p class="text-muted">
                    <a href="clients_edit.php?id=<?= $client_id ?>">← Back to Client Profile</a>
                </p>
            <?php endif; ?>
        </div>
        <div class="col-md-4 text-end">
            <a href="pets_edit.php?<?= $client_id ? 'client_id=' . $client_id : '' ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Add Pet
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($pets)): ?>
                <p class="text-muted text-center py-5">
                    <i class="bi bi-heart" style="font-size: 3rem;"></i><br>
                    No pets found. <a href="pets_edit.php?<?= $client_id ? 'client_id=' . $client_id : '' ?>">Add your first pet</a>
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Species</th>
                                <th>Breed</th>
                                <th>Age</th>
                                <?php if (!$client_id): ?>
                                    <th>Owner</th>
                                <?php endif; ?>
                                <th>Spayed/Neutered</th>
                                <th>Vaccines</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pets as $pet): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($pet['name']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($pet['species']) ?></td>
                                    <td><?= htmlspecialchars($pet['breed'] ?: '—') ?></td>
                                    <td>
                                        <?php
                                        $age_parts = [];
                                        if ($pet['age_years']) $age_parts[] = $pet['age_years'] . 'y';
                                        if ($pet['age_months']) $age_parts[] = $pet['age_months'] . 'm';
                                        echo $age_parts ? implode(' ', $age_parts) : '—';
                                        ?>
                                    </td>
                                    <?php if (!$client_id): ?>
                                        <td>
                                            <a href="clients_edit.php?id=<?= $pet['client_id'] ?>">
                                                <?= htmlspecialchars($pet['client_name']) ?>
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($pet['spayed_neutered']): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pet['vaccines_current']): ?>
                                            <span class="badge bg-success">Current</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Needs Update</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pet['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="pets_edit.php?id=<?= $pet['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="pets_delete.php?id=<?= $pet['id'] ?><?= $client_id ? '&client_id=' . $client_id : '' ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Are you sure you want to delete this pet?')" 
                                           title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?><?= $client_id ? '&client_id=' . $client_id : '' ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
