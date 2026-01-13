<?php
/**
 * Quotes List - View all quotes
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

$db = new Database();
$conn = $db->getConnection();

// Handle filters
$client_filter = isset($_GET['client']) ? $_GET['client'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where = [];
$params = [];

if ($client_filter) {
    $where[] = "q.client_id = ?";
    $params[] = $client_filter;
}

if ($status_filter) {
    $where[] = "q.status = ?";
    $params[] = $status_filter;
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
$count_sql = "SELECT COUNT(*) FROM quotes q $where_sql";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get quotes
$sql = "SELECT q.*, c.name as client_name 
        FROM quotes q
        INNER JOIN clients c ON q.client_id = c.id
        $where_sql
        ORDER BY q.created_at DESC
        LIMIT $per_page OFFSET $offset";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients for filter
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Quotes";
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="fas fa-file-invoice me-2"></i>Quotes</h2>
        </div>
        <div class="col-auto">
            <a href="quotes_create.php" class="btn btn-primary">
                <i class="fas fa-circle-plus me-1"></i>Create Quote
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Client</label>
                    <select name="client" class="form-select">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" <?= $client_filter == $client['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="sent" <?= $status_filter == 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="viewed" <?= $status_filter == 'viewed' ? 'selected' : '' ?>>Viewed</option>
                        <option value="accepted" <?= $status_filter == 'accepted' ? 'selected' : '' ?>>Accepted</option>
                        <option value="declined" <?= $status_filter == 'declined' ? 'selected' : '' ?>>Declined</option>
                        <option value="expired" <?= $status_filter == 'expired' ? 'selected' : '' ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-secondary me-2">Filter</button>
                    <a href="quotes_list.php" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Quotes Table -->
    <?php if (count($quotes) > 0): ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Quote #</th>
                            <th>Client</th>
                            <th>Title</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Expiration</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotes as $quote): 
                            $is_expired = $quote['expiration_date'] && strtotime($quote['expiration_date']) < time() && $quote['status'] == 'sent';
                            $display_status = $is_expired ? 'expired' : $quote['status'];
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($quote['quote_number']) ?></strong></td>
                                <td>
                                    <a href="clients_edit.php?id=<?= $quote['client_id'] ?>">
                                        <?= htmlspecialchars($quote['client_name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($quote['title']) ?></td>
                                <td>$<?= number_format($quote['amount'], 2) ?></td>
                                <td>
                                    <?php
                                    $badge_classes = [
                                        'sent' => 'bg-secondary',
                                        'viewed' => 'bg-info',
                                        'accepted' => 'bg-success',
                                        'declined' => 'bg-danger',
                                        'expired' => 'bg-warning'
                                    ];
                                    ?>
                                    <span class="badge <?= $badge_classes[$display_status] ?? 'bg-secondary' ?>">
                                        <?= ucfirst($display_status) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $quote['expiration_date'] ? date('M j, Y', strtotime($quote['expiration_date'])) : 'No expiration' ?>
                                </td>
                                <td><?= date('M j, Y', strtotime($quote['created_at'])) ?></td>
                                <td>
                                    <a href="quotes_view.php?id=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): 
                        $params_arr = $_GET;
                        $params_arr['page'] = $i;
                        $url = 'quotes_list.php?' . http_build_query($params_arr);
                    ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= $url ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-circle-info me-2"></i>
            No quotes found. <a href="quotes_create.php">Create your first quote</a>
        </div>
    <?php endif; ?>
</div>

<?php include '../backend/includes/footer.php'; ?>
