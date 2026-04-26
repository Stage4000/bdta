<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get all email templates
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$limit_clause = $db->buildLimitClause($per_page, $offset);

// Pagination clause is built from safe_int()-bounded integers only.
// nosemgrep
$stmt = $conn->prepare("SELECT * FROM email_templates ORDER BY template_type, name" . $limit_clause);
$stmt->execute();
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = safe_int($conn->query("SELECT COUNT(*) FROM email_templates")->fetchColumn());
$total_pages = ceil($total / $per_page);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i class="fas fa-envelope me-2"></i>Email Templates</h2>
                <a href="email_templates_edit.php" class="btn btn-primary">
                    <i class="fas fa-circle-plus"></i> New Template
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo escape($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo escape($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <i class="fas fa-circle-info me-1"></i>
                Booking-related templates can use
                <code>{{booking_link}}</code>,
                <code>{{booking_reschedule_link}}</code>, and
                <code>{{booking_cancel_link}}</code>
                to send clients directly to the portal booking actions.
            </div>

            <?php if (empty($templates)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    No email templates found. Create your first template to customize emails.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php 
                    $template_types = [
                        'booking_confirmation' => ['label' => 'Booking Confirmation', 'icon' => 'calendar-check', 'color' => 'primary'],
                        'booking_request' => ['label' => 'Booking Request', 'icon' => 'hourglass-half', 'color' => 'secondary'],
                        'booking_reminder' => ['label' => 'Booking Reminder', 'icon' => 'bell', 'color' => 'warning'],
                        'booking_cancellation' => ['label' => 'Booking Cancellation', 'icon' => 'calendar-xmark', 'color' => 'danger'],
                        'invoice' => ['label' => 'Invoice', 'icon' => 'file-invoice-dollar', 'color' => 'info'],
                        'payment_receipt' => ['label' => 'Payment Receipt', 'icon' => 'receipt', 'color' => 'success'],
                        'contract_request' => ['label' => 'Contract Request', 'icon' => 'file-invoice', 'color' => 'info'],
                        'form_request' => ['label' => 'Form Request', 'icon' => 'file', 'color' => 'secondary'],
                        'quote_notification' => ['label' => 'Quote Notification', 'icon' => 'dollar-sign', 'color' => 'primary'],
                        'workflow' => ['label' => 'Workflow Emails', 'icon' => 'sitemap', 'color' => 'dark'],
                        'other' => ['label' => 'Other', 'icon' => 'folder-open', 'color' => 'secondary'],
                        'admin_notification' => ['label' => 'Admin Notification', 'icon' => 'triangle-exclamation', 'color' => 'danger']
                    ];
                    
                    foreach ($templates as $template): 
                        $type_info = $template_types[$template['template_type']] ?? ['icon' => 'envelope', 'color' => 'secondary'];
                    ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-<?php echo $type_info['icon']; ?> text-<?php echo $type_info['color']; ?>"></i>
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </h5>
                                        <?php if ($template['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="text-muted small mb-2">
                                        <strong>Type:</strong> <?php echo htmlspecialchars($type_info['label'] ?? ucwords(str_replace('_', ' ', $template['template_type']))); ?>
                                    </p>
                                    
                                    <p class="text-muted small mb-2">
                                        <strong>Subject:</strong> <?php echo htmlspecialchars($template['subject']); ?>
                                    </p>
                                    
                                    <?php if ($template['variables']): ?>
                                        <p class="text-muted small mb-3">
                                            <strong>Variables:</strong> <?php echo htmlspecialchars($template['variables']); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="email_templates_edit.php?id=<?php echo $template['id']; ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                            <i class="fas fa-pencil"></i> Edit
                                        </a>
                                        <form method="POST" action="email_templates_duplicate.php" class="flex-fill">
                                            <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION['csrf_token'] ?? ''); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                                <i class="fas fa-copy"></i> Duplicate
                                            </button>
                                        </form>
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

<?php include '../backend/includes/footer.php'; ?>
