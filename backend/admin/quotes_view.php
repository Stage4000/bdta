<?php
/**
 * View Quote
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

$quote_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("
    SELECT q.*, c.name as client_name, c.email as client_email
    FROM quotes q
    INNER JOIN clients c ON q.client_id = c.id
    WHERE q.id = ?
");
$stmt->execute([$quote_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    $_SESSION['error'] = "Quote not found";
    header('Location: quotes_list.php');
    exit;
}

// Get line items
$items_stmt = $conn->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id");
$items_stmt->execute([$quote_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if expired
$is_expired = $quote['expiration_date'] && strtotime($quote['expiration_date']) < time() && $quote['status'] == 'sent';
$display_status = $is_expired ? 'expired' : $quote['status'];

// Generate public link
$settings_stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'base_url'");
$base_url = $settings_stmt->fetchColumn();
$public_link = $base_url . '/public/quote.php?id=' . $quote_id;

$page_title = "Quote " . htmlspecialchars($quote['quote_number']);
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">
                <i class="bi bi-file-earmark-text me-2"></i>
                Quote <?= htmlspecialchars($quote['quote_number']) ?>
            </h1>
        </div>
        <div class="col-auto">
            <a href="quotes_list.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left me-1"></i>Back to Quotes
            </a>
            <?php if ($display_status == 'sent' || $display_status == 'viewed'): ?>
                <a href="quotes_create.php?id=<?= $quote_id ?>" class="btn btn-primary">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <!-- Quote Details -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-4">
                        <div>
                            <h4><?= htmlspecialchars($quote['title']) ?></h4>
                            <p class="text-muted mb-0">For: <strong><?= htmlspecialchars($quote['client_name']) ?></strong></p>
                        </div>
                        <div class="text-end">
                            <?php
                            $badge_classes = [
                                'sent' => 'bg-secondary',
                                'viewed' => 'bg-info',
                                'accepted' => 'bg-success',
                                'declined' => 'bg-danger',
                                'expired' => 'bg-warning'
                            ];
                            ?>
                            <span class="badge <?= $badge_classes[$display_status] ?? 'bg-secondary' ?> fs-6">
                                <?= ucfirst($display_status) ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($quote['description']): ?>
                        <p class="mb-4"><?= nl2br(htmlspecialchars($quote['description'])) ?></p>
                    <?php endif; ?>

                    <!-- Line Items -->
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['description']) ?></td>
                                    <td class="text-center"><?= $item['quantity'] ?></td>
                                    <td class="text-end">$<?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end">$<?= number_format($item['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" class="text-end">Total:</th>
                                <th class="text-end">$<?= number_format($quote['amount'], 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Internal Notes -->
            <?php if ($quote['notes']): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Internal Notes</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0"><?= nl2br(htmlspecialchars($quote['notes'])) ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <!-- Details Sidebar -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Quote Details</h5>
                </div>
                <div class="list-group list-group-flush">
                    <div class="list-group-item">
                        <strong>Quote Number:</strong><br>
                        <?= htmlspecialchars($quote['quote_number']) ?>
                    </div>
                    <div class="list-group-item">
                        <strong>Client:</strong><br>
                        <a href="clients_edit.php?id=<?= $quote['client_id'] ?>">
                            <?= htmlspecialchars($quote['client_name']) ?>
                        </a>
                    </div>
                    <div class="list-group-item">
                        <strong>Created:</strong><br>
                        <?= date('M j, Y g:i A', strtotime($quote['created_at'])) ?>
                    </div>
                    <?php if ($quote['expiration_date']): ?>
                        <div class="list-group-item">
                            <strong>Expiration:</strong><br>
                            <?= date('M j, Y', strtotime($quote['expiration_date'])) ?>
                            <?php if ($is_expired): ?>
                                <span class="badge bg-warning ms-2">Expired</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($quote['viewed_at']): ?>
                        <div class="list-group-item">
                            <strong>Viewed:</strong><br>
                            <?= date('M j, Y g:i A', strtotime($quote['viewed_at'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($quote['accepted_at']): ?>
                        <div class="list-group-item">
                            <strong>Accepted:</strong><br>
                            <?= date('M j, Y g:i A', strtotime($quote['accepted_at'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($quote['declined_at']): ?>
                        <div class="list-group-item">
                            <strong>Declined:</strong><br>
                            <?= date('M j, Y g:i A', strtotime($quote['declined_at'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Public Link -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Share Quote</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Send this link to the client to view and respond to the quote:</p>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm" id="publicLink" 
                               value="<?= htmlspecialchars($public_link) ?>" readonly>
                        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyLink()">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const input = document.getElementById('publicLink');
    input.select();
    document.execCommand('copy');
    alert('Link copied to clipboard!');
}
</script>

<?php include '../includes/footer.php'; ?>
