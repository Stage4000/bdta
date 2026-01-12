<?php
require_once '../includes/config.php';
require_once '../includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get all email templates
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$templates = $conn->query("SELECT * FROM email_templates ORDER BY template_type, name LIMIT $per_page OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
$total = $conn->query("SELECT COUNT(*) FROM email_templates")->fetchColumn();
$total_pages = ceil($total / $per_page);

include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">Email Templates</h1>
                <a href="email_templates_edit.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> New Template
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($templates)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    No email templates found. Create your first template to customize emails.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php 
                    $template_types = [
                        'booking_confirmation' => ['icon' => 'calendar-check', 'color' => 'primary'],
                        'booking_reminder' => ['icon' => 'bell', 'color' => 'warning'],
                        'payment_receipt' => ['icon' => 'receipt', 'color' => 'success'],
                        'contract_request' => ['icon' => 'file-earmark-text', 'color' => 'info'],
                        'form_request' => ['icon' => 'file-earmark', 'color' => 'secondary'],
                        'quote_notification' => ['icon' => 'currency-dollar', 'color' => 'primary'],
                        'admin_notification' => ['icon' => 'exclamation-triangle', 'color' => 'danger']
                    ];
                    
                    foreach ($templates as $template): 
                        $type_info = $template_types[$template['template_type']] ?? ['icon' => 'envelope', 'color' => 'secondary'];
                    ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-<?php echo $type_info['icon']; ?> text-<?php echo $type_info['color']; ?>"></i>
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </h5>
                                        <?php if ($template['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="text-muted small mb-2">
                                        <strong>Type:</strong> <?php echo ucwords(str_replace('_', ' ', $template['template_type'])); ?>
                                    </p>
                                    
                                    <p class="text-muted small mb-2">
                                        <strong>Subject:</strong> <?php echo htmlspecialchars($template['subject']); ?>
                                    </p>
                                    
                                    <?php if ($template['variables']): ?>
                                        <p class="text-muted small mb-3">
                                            <strong>Variables:</strong> <?php echo htmlspecialchars($template['variables']); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex gap-2">
                                        <a href="email_templates_edit.php?id=<?php echo $template['id']; ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav>
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
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
