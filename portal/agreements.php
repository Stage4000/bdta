<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/follow_up_notes.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/public_access_links.php';
require_once '../backend/includes/public_portal_return.php';
requirePortalLogin();

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

// Contracts
$stmt = $conn->prepare("
    SELECT *
    FROM contracts c
    WHERE c.client_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$client_id]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Form submissions
$stmt = $conn->prepare("
    SELECT fs.*, ft.name as form_title, ft.form_type,
           COALESCE(ft.is_internal, 0) AS template_is_internal,
           ft.show_in_client_portal AS template_show_in_client_portal
    FROM form_submissions fs
    LEFT JOIN form_templates ft ON fs.template_id = ft.id
    WHERE fs.client_id = ?
      AND fs.status IN ('submitted', 'reviewed')
    ORDER BY fs.submitted_at DESC
");
$stmt->execute([$client_id]);
$submissions = array_values(array_filter(
    $stmt->fetchAll(PDO::FETCH_ASSOC),
    static fn (array $submission): bool => bdta_form_submission_is_client_portal_visible($submission)
));

$page_title = 'Agreements';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Agreements &amp; Forms</h2>

<!-- Contracts -->
<div class="card mb-4">
    <div class="card-header"><strong>Contracts</strong></div>
    <?php if (empty($contracts)): ?>
    <div class="card-body"><p class="text-muted mb-0">No contracts on file.</p></div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Title</th><th>Status</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($contracts as $c): ?>
                <?php
                $status = strtolower($c['status'] ?? 'draft');
                $badge = match($status) {
                    'signed'    => 'success',
                    'pending'   => 'warning',
                    'draft'     => 'secondary',
                    'cancelled' => 'dark',
                    default     => 'secondary',
                };
                $can_view = in_array($status, ['sent', 'signed']);
                $contract_url = bdta_append_public_portal_return(
                    bdta_get_public_contract_path($conn, intval($c['id']), $c['access_token'] ?? null),
                    PORTAL_URL . 'agreements.php'
                );
                ?>
                <tr>
                    <td><?php echo escape($c['title'] ?? ''); ?></td>
                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo escape(ucfirst($status)); ?></span></td>
                    <td><?php echo escape($c['created_at'] ?? ''); ?></td>
                    <td>
                        <?php if ($can_view): ?>
                            <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                <a href="<?php echo escape($contract_url); ?>"
                                   class="btn btn-sm btn-outline-primary table-action-btn">
                                    <i class="fas fa-eye me-1"></i><?php echo $status === 'signed' ? 'View' : 'View &amp; Sign'; ?>
                                </a>
                            </div>
                            <div class="d-md-none table-action-dropdown">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="<?php echo escape($contract_url); ?>">
                                                <i class="fas fa-eye me-2 text-primary"></i><?php echo $status === 'signed' ? 'View' : 'View &amp; Sign'; ?>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Form submissions -->
<div class="card">
    <div class="card-header"><strong>Form Submissions</strong></div>
    <?php if (empty($submissions)): ?>
    <div class="card-body"><p class="text-muted mb-0">No form submissions on file.</p></div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Form</th><th>Submitted</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($submissions as $fs): ?>
                <?php
                $client_review_submission = bdta_form_submission_requires_client_review(scalar_string($fs['form_type'] ?? ''));
                $submission_url = bdta_get_client_portal_form_submission_url($fs);
                ?>
                <tr>
                    <td><?php echo escape($fs['form_title'] ?? 'Unknown Form'); ?></td>
                    <td><?php echo escape($fs['submitted_at'] ?? ''); ?></td>
                    <td><?php echo escape($fs['status'] ?? ''); ?></td>
                    <td>
                        <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                            <a href="<?php echo escape($submission_url); ?>"
                               class="btn btn-sm btn-outline-primary table-action-btn">
                                <i class="fas fa-eye me-1"></i><?php echo $client_review_submission ? 'Review' : 'View'; ?>
                            </a>
                        </div>
                        <div class="d-md-none table-action-dropdown">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="<?php echo escape($submission_url); ?>">
                                            <i class="fas fa-eye me-2 text-primary"></i><?php echo $client_review_submission ? 'Review' : 'View'; ?>
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include '../portal/includes/footer.php'; ?>
