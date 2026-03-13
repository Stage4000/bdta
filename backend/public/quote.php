<?php
/**
 * Public Quote View (Client-facing)
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

$quote_id = safe_int($_GET['id'] ?? 0);
$action = scalar_string($_POST['action'] ?? '');

// Get quote
$stmt = $conn->prepare("
    SELECT q.*, c.name as client_name, c.email as client_email
    FROM quotes q
    INNER JOIN clients c ON q.client_id = c.id
    WHERE q.id = ?
");
$stmt->execute([$quote_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    die("Quote not found");
}

$quote_status = array_string_value($quote, 'status');
$quote_expiration_date = array_string_value($quote, 'expiration_date');
$quote_quote_number = array_string_value($quote, 'quote_number');
$quote_title = array_string_value($quote, 'title');
$quote_description = array_string_value($quote, 'description');
$quote_amount = safe_float($quote['amount'] ?? 0);

// Get line items
$items_stmt = $conn->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id");
$items_stmt->execute([$quote_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if expired
$is_expired = $quote_expiration_date !== '' && strtotime($quote_expiration_date) < time();
$can_respond = $quote_status === 'sent' || $quote_status === 'viewed';

// Mark as viewed if first time
if ($quote_status === 'sent') {
    $stmt = $conn->prepare("UPDATE quotes SET status = 'viewed', viewed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$quote_id]);
    $quote_status = 'viewed';
    $quote['status'] = 'viewed';
}

// Handle accept/decline
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_respond && !$is_expired) {
    if ($action == 'accept') {
        $stmt = $conn->prepare("UPDATE quotes SET status = 'accepted', accepted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$quote_id]);
        $quote_status = 'accepted';
        $quote['status'] = 'accepted';
        $message = '<div class="alert alert-success">Quote accepted! We will contact you shortly.</div>';
    } elseif ($action == 'decline') {
        $stmt = $conn->prepare("UPDATE quotes SET status = 'declined', declined_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$quote_id]);
        $quote_status = 'declined';
        $quote['status'] = 'declined';
        $message = '<div class="alert alert-info">Quote declined. Thank you for your response.</div>';
    }
}
$page_title = 'Quote ' . $quote_quote_number;
?>
<?php require_once __DIR__ . '/includes/public_head.php'; ?>
    <?php
    $theme_primary = scalar_string(Settings::get('theme_primary_color', '#9a0073'));
    $theme_secondary = scalar_string(Settings::get('theme_secondary_color', '#0a9a9c'));
    $tc_primary   = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary) ? $theme_primary : '#9a0073';
    $tc_secondary = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_secondary) ? $theme_secondary : '#0a9a9c';
    ?>
    <style>
        .bg-primary {
            background-color: <?= $tc_primary ?> !important;
        }
        .btn-success {
            background-color: <?= $tc_secondary ?>;
            border-color: <?= $tc_secondary ?>;
        }
        .btn-success:hover {
            background-color: color-mix(in srgb, <?= $tc_secondary ?> 85%, black);
            border-color: color-mix(in srgb, <?= $tc_secondary ?> 85%, black);
        }
        .bg-info {
            background-color: <?= $tc_secondary ?> !important;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="fas fa-file-invoice me-2"></i>
                                Quote <?= htmlspecialchars($quote_quote_number) ?>
                            </h4>
                            <?php
                            $badge_classes = [
                                'sent' => 'bg-secondary',
                                'viewed' => 'bg-info',
                                'accepted' => 'bg-success',
                                'declined' => 'bg-danger'
                            ];
                            $display_status = $is_expired ? 'expired' : $quote_status;
                            ?>
                            <span class="badge <?= $badge_classes[$display_status] ?? 'bg-secondary' ?> fs-6">
                                <?= ucfirst($display_status) ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?= $message ?>
                        
                        <?php if ($is_expired && $can_respond): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-triangle-exclamation me-2"></i>
                                This quote has expired. Please contact us if you're still interested.
                            </div>
                        <?php endif; ?>

                        <h3 class="mb-3"><?= htmlspecialchars($quote_title) ?></h3>
                        
                        <?php if ($quote_description !== ''): ?>
                            <p class="mb-4"><?= nl2br(htmlspecialchars($quote_description)) ?></p>
                        <?php endif; ?>

                        <!-- Line Items -->
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th class="text-center" width="80">Qty</th>
                                    <th class="text-end" width="120">Unit Price</th>
                                    <th class="text-end" width="120">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $item_description = array_string_value($item, 'description');
                                    $item_quantity = array_int_value($item, 'quantity');
                                    $item_unit_price = safe_float($item['unit_price'] ?? 0);
                                    $item_amount = safe_float($item['amount'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item_description) ?></td>
                                        <td class="text-center"><?= $item_quantity ?></td>
                                        <td class="text-end">$<?= number_format($item_unit_price, 2) ?></td>
                                        <td class="text-end">$<?= number_format($item_amount, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <th colspan="3" class="text-end">Total:</th>
                                    <th class="text-end">$<?= number_format($quote_amount, 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>

                        <?php if ($quote_expiration_date !== ''): ?>
                            <p class="text-muted mb-4">
                                <small>
                                    <i class="fas fa-calendar-days me-1"></i>
                                    Expires: <?= date('F j, Y', safe_timestamp(strtotime($quote_expiration_date))) ?>
                                </small>
                            </p>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <?php if ($can_respond && !$is_expired): ?>
                            <form method="POST" class="mt-4" onsubmit="return confirm('Are you sure?')">
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="submit" name="action" value="decline" class="btn btn-outline-secondary">
                                        <i class="fas fa-circle-xmark me-1"></i>Decline
                                    </button>
                                    <button type="submit" name="action" value="accept" class="btn btn-success">
                                        <i class="fas fa-check-circle me-1"></i>Accept Quote
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center text-muted">
                        <small>Brook's Dog Training Academy</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
