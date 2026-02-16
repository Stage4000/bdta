<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
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
    $id = (int)$_GET['id'];
    
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
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$signatures = $conn->query("SELECT * FROM email_signature_templates ORDER BY is_default DESC, name LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
$total = $conn->query("SELECT COUNT(*) FROM email_signature_templates")->fetchColumn();
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
                                            <td><?= date('M j, Y', strtotime($sig['created_at'])) ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="email_signatures_edit.php?id=<?= $sig['id'] ?>" 
                                                       class="btn btn-outline-primary" 
                                                       title="Edit">
                                                        <i class="fas fa-pencil"></i>
                                                    </a>
                                                    <a href="email_signatures_preview.php?id=<?= $sig['id'] ?>" 
                                                       class="btn btn-outline-info" 
                                                       title="Preview"
                                                       target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (!$sig['is_default']): ?>
                                                        <a href="?action=delete&id=<?= $sig['id'] ?>" 
                                                           class="btn btn-outline-danger" 
                                                           title="Delete"
                                                           onclick="return confirm('Are you sure you want to delete this signature?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
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
