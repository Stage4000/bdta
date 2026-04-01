<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token']), scalar_string($_POST['csrf_token']))) {
        $_SESSION['error'] = 'Invalid request.';
        redirect(ADMIN_URL . 'email_signatures_list.php');
    }
    $id = safe_int($_POST['delete_id']);

    // Check if this is the default signature
    $stmt = $conn->prepare("SELECT is_default FROM email_signature_templates WHERE id = ?");
    $stmt->execute([$id]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($signature && $signature['is_default']) {
        $_SESSION['error'] = 'Cannot delete the default signature. Please set another signature as default first.';
    } else {
        $stmt = $conn->prepare("DELETE FROM email_signature_templates WHERE id = ?");
        if ($stmt->execute([$id])) {
            $_SESSION['success'] = 'Signature deleted successfully!';
        } else {
            $_SESSION['error'] = 'Failed to delete signature.';
        }
    }
    redirect(ADMIN_URL . 'email_signatures_list.php');
}

// Handle set default action
if (isset($_GET['action']) && $_GET['action'] === 'set_default' && isset($_GET['id'])) {
    $id = safe_int($_GET['id']);
    
    // First, unset all defaults
    $conn->exec("UPDATE email_signature_templates SET is_default = 0");
    
    // Then set this one as default
    $stmt = $conn->prepare("UPDATE email_signature_templates SET is_default = 1 WHERE id = ?");
    if ($stmt->execute([$id])) {
        
        // Update the default_email_signature_id setting
        require_once '../backend/includes/settings.php';
        Settings::set('default_email_signature_id', $id);
        
        $_SESSION['success'] = 'Default signature updated successfully!';
    } else {
        $_SESSION['error'] = 'Failed to update default signature.';
    }
    redirect(ADMIN_URL . 'email_signatures_list.php');
}

// Get all signatures
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build a MySQL LIMIT clause from safe integer inputs.
$limit_clause = $db->buildLimitClause($per_page, $offset);
// Pagination clause is built from safe_int()-bounded integers only.
// nosemgrep
$stmt = $conn->prepare("SELECT * FROM email_signature_templates ORDER BY is_default DESC, name" . $limit_clause);
$stmt->execute();
$signatures = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = safe_int($conn->query("SELECT COUNT(*) FROM email_signature_templates")->fetchColumn());
$total_pages = ceil($total / $per_page);

$page_title = 'Email Signatures';
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i class="fas fa-signature me-2"></i>Email Signatures</h2>
                <a href="email_signatures_edit.php" class="btn btn-primary">
                    <i class="fas fa-circle-plus"></i> New Signature
                </a>
            </div>

            <?php 
            $flash = getFlashMessage();
            if ($flash): 
            ?>
                <div class="alert alert-<?= escape($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= escape($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($signatures)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    No email signatures found. Create your first signature template to include in outgoing emails.
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Default</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($signatures as $sig): ?>
                                        <tr>
                                            <td>
                                                <code><?= $sig['id'] ?></code>
                                            </td>
                                            <td>
                                                <strong><?= escape($sig['name']) ?></strong>
                                            </td>
                                            <td><?= escape($sig['description'] ?? '') ?></td>
                                            <td>
                                                <?php if ($sig['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($sig['is_default']): ?>
                                                    <span class="badge bg-primary">
                                                        <i class="fas fa-check"></i> Default
                                                    </span>
                                                <?php else: ?>
                                                    <a href="?action=set_default&id=<?= $sig['id'] ?>" 
                                                       class="btn btn-sm btn-outline-secondary"
                                                       onclick="return confirm('Set this as the default signature?')">
                                                        Set Default
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= escape(formatDate(array_string_value($sig, 'created_at'), 'M j, Y')) ?></td>
                                            <td>
                                                <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                                    <a href="email_signatures_edit.php?id=<?= $sig['id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary table-action-btn" 
                                                       title="Edit">
                                                        <i class="fas fa-pencil"></i>
                                                    </a>
                                                    <a href="email_signatures_preview.php?id=<?= $sig['id'] ?>" 
                                                       class="btn btn-sm btn-outline-info table-action-btn" 
                                                       title="Preview"
                                                       target="_blank"
                                                       rel="noopener noreferrer">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (!$sig['is_default']): ?>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this signature?')">
                                                            <input type="hidden" name="delete_id" value="<?= $sig['id'] ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger table-action-btn" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="d-md-none table-action-dropdown">
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a class="dropdown-item" href="email_signatures_edit.php?id=<?= $sig['id'] ?>">
                                                                    <i class="fas fa-pencil me-2 text-primary"></i>Edit
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" href="email_signatures_preview.php?id=<?= $sig['id'] ?>" target="_blank" rel="noopener noreferrer">
                                                                    <i class="fas fa-eye me-2 text-info"></i>Preview
                                                                </a>
                                                            </li>
                                                            <?php if (!$sig['is_default']): ?>
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li>
                                                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this signature?')">
                                                                        <input type="hidden" name="delete_id" value="<?= $sig['id'] ?>">
                                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                                        <button type="submit" class="dropdown-item text-danger w-100 text-start border-0 bg-transparent">
                                                                            <i class="fas fa-trash me-2"></i>Delete
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>

            <div class="mt-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-circle-info"></i> About Email Signatures</h5>
                        <p class="card-text">
                            Email signatures are automatically appended to outgoing emails. You can create multiple signatures
                            and set one as the default. Signatures support rich HTML formatting, images, and hyperlinks.
                        </p>
                        <p class="card-text">
                            <strong>Using signatures in email templates:</strong><br>
                            • <code>{{signature}}</code> - Inserts the default signature<br>
                            • <code>{{signature:ID}}</code> - Inserts a specific signature (use the ID from the table above)
                        </p>
                        <p class="card-text">
                            <strong>Available custom fields:</strong> {{name}}, {{email}}, {{phone}}, {{business_name}}, {{business_address}}
                        </p>
                        <p class="card-text small text-muted">
                            <i class="fas fa-shield-halved"></i> All HTML content is sanitized to prevent XSS attacks.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
