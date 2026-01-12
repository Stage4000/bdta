<?php
/**
 * Contract Templates List
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_stmt = $conn->query("SELECT COUNT(*) FROM contract_templates");
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get templates
$stmt = $conn->query("
    SELECT * FROM contract_templates 
    ORDER BY name
    LIMIT $per_page OFFSET $offset
");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Contract Templates";
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3"><i class="bi bi-file-earmark-medical me-2"></i>Contract Templates</h1>
            <p class="text-muted">Reusable contract templates for different service types</p>
        </div>
        <div class="col-auto">
            <a href="contract_templates_edit.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>Create Template
            </a>
        </div>
    </div>

    <?php if (count($templates) > 0): ?>
        <div class="row">
            <?php foreach ($templates as $template): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?= htmlspecialchars($template['name']) ?></h5>
                                <span class="badge <?= $template['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $template['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            
                            <?php if ($template['description']): ?>
                                <p class="card-text text-muted small"><?= htmlspecialchars($template['description']) ?></p>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <?php if ($template['service_type']): ?>
                                    <span class="badge bg-info me-2"><?= htmlspecialchars($template['service_type']) ?></span>
                                <?php endif; ?>
                                <span class="badge bg-secondary">Renews: <?= $template['renewal_period_months'] ?> months</span>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="contract_templates_edit.php?id=<?= $template['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                            <a href="contracts_create.php?template_id=<?= $template['id'] ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-plus-circle me-1"></i>Use Template
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            No contract templates found. <a href="contract_templates_edit.php">Create your first template</a>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
