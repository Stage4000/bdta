<?php
/**
 * Quotes List - View all quotes
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

$db = new Database();
$conn = $db->getConnection();

// Handle filters
$client_filter = scalar_string($_GET['client'] ?? '');
$status_filter = scalar_string($_GET['status'] ?? '');

// Pagination
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;
$limit_clause = $db->buildLimitClause($per_page, $offset);

// Build query
$client_filter_id = $client_filter !== '' ? safe_int($client_filter) : 0;
$client_filter_param = $client_filter_id > 0 ? $client_filter_id : null;
$status_filter_param = $status_filter !== '' ? $status_filter : null;
$bind_nullable_param = static function (PDOStatement $stmt, int $position, mixed $value, int $type): void {
    $stmt->bindValue($position, $value, $value === null ? PDO::PARAM_NULL : $type);
};

// Get total count
$count_sql = "SELECT COUNT(*)
              FROM quotes q
              WHERE (? IS NULL OR q.client_id = ?)
                AND (? IS NULL OR q.status = ?)";
$count_stmt = $conn->prepare($count_sql);
$bind_nullable_param($count_stmt, 1, $client_filter_param, PDO::PARAM_INT);
$bind_nullable_param($count_stmt, 2, $client_filter_param, PDO::PARAM_INT);
$bind_nullable_param($count_stmt, 3, $status_filter_param, PDO::PARAM_STR);
$bind_nullable_param($count_stmt, 4, $status_filter_param, PDO::PARAM_STR);
$count_stmt->execute();
$total = safe_int($count_stmt->fetchColumn());
$total_pages = ceil($total / $per_page);

// Get quotes
// Pagination literals come from safe_int()-bounded integers via buildLimitClause(); filters remain parameterized.
// nosemgrep
$stmt = $conn->prepare("SELECT q.*, c.name as client_name FROM quotes q INNER JOIN clients c ON q.client_id = c.id WHERE (? IS NULL OR q.client_id = ?) AND (? IS NULL OR q.status = ?) ORDER BY q.created_at DESC " . $limit_clause);
$bind_nullable_param($stmt, 1, $client_filter_param, PDO::PARAM_INT);
$bind_nullable_param($stmt, 2, $client_filter_param, PDO::PARAM_INT);
$bind_nullable_param($stmt, 3, $status_filter_param, PDO::PARAM_STR);
$bind_nullable_param($stmt, 4, $status_filter_param, PDO::PARAM_STR);
$stmt->execute();
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients for filter
$clients_stmt = $conn->query("SELECT id, name FROM clients WHERE COALESCE(is_archived, 0) = 0 ORDER BY name");
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
                    <select name="client" class="form-select" onchange="this.form.submit()" data-searchable-select="client" data-search-placeholder="Search clients...">
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
                                    <a href="clients_view.php?id=<?= $quote['client_id'] ?>">
                                        <?= htmlspecialchars($quote['client_name']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($quote['title']) ?></td>
                                <td>$<?= number_format(safe_float($quote['amount'] ?? 0), 2) ?></td>
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
                                    <?= $quote['expiration_date'] ? escape(formatDate(array_string_value($quote, 'expiration_date'), 'M j, Y')) : 'No expiration' ?>
                                </td>
                                <td><?= escape(formatDate(array_string_value($quote, 'created_at'), 'M j, Y')) ?></td>
                                <td>
                                    <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                        <a href="quotes_view.php?id=<?= $quote['id'] ?>" class="btn btn-sm btn-outline-primary table-action-btn" title="View Quote">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                    <div class="d-md-none table-action-dropdown">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="quotes_view.php?id=<?= $quote['id'] ?>">
                                                        <i class="fas fa-eye me-2 text-primary"></i>View Quote
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
