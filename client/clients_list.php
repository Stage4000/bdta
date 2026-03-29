<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();
$view = scalar_string($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';

// Handle client actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'danger');
        redirect('clients_list.php');
    }

    $return_view = scalar_string($_POST['return_view'] ?? $view) === 'archived' ? 'archived' : 'active';

    if (isset($_POST['archive_id'])) {
        $id = safe_int($_POST['archive_id']);
        $archived = $db->archiveClient($id);
        setFlashMessage($archived ? 'Client archived successfully!' : 'Client could not be archived.', $archived ? 'success' : 'warning');
        redirect('clients_list.php?view=archived');
    }

    if (isset($_POST['unarchive_id'])) {
        $id = safe_int($_POST['unarchive_id']);
        $unarchived = $db->unarchiveClient($id);
        setFlashMessage($unarchived ? 'Client unarchived successfully!' : 'Client could not be unarchived.', $unarchived ? 'success' : 'warning');
        redirect($return_view === 'archived' ? 'clients_list.php?view=archived' : 'clients_list.php');
    }

    if (isset($_POST['delete_id'])) {
        $id = safe_int($_POST['delete_id']);
        $stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        setFlashMessage('Client deleted successfully!', 'success');
        redirect($return_view === 'archived' ? 'clients_list.php?view=archived' : 'clients_list.php');
    }
}

// Fetch clients for selected view
$archiveFilter = $view === 'archived' ? 1 : 0;
$stmt = $conn->prepare("
    SELECT *
    FROM clients
    WHERE COALESCE(is_archived, 0) = ?
    ORDER BY
        CASE WHEN COALESCE(is_archived, 0) = 1 THEN archived_at ELSE created_at END DESC,
        created_at DESC
");
$stmt->bindValue(1, $archiveFilter, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-users me-2"></i><?= $view === 'archived' ? 'Archived Clients' : 'Client Management' ?>
        </h2>
        <div class="d-flex gap-2">
            <a href="moxie_import.php" class="btn btn-outline-primary">
                <i class="fas fa-cloud-arrow-down"></i> Import from Moxie
            </a>
            <?php if ($view !== 'archived'): ?>
                <a href="clients_edit.php" class="btn btn-primary">
                    <i class="fas fa-circle-plus"></i> Add New Client
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a href="clients_list.php" class="btn btn-sm <?= $view === 'archived' ? 'btn-outline-secondary' : 'btn-secondary' ?>">
                    <i class="fas fa-users me-1"></i> Active Clients
                </a>
                <a href="clients_list.php?view=archived" class="btn btn-sm <?= $view === 'archived' ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                    <i class="fas fa-box-archive me-1"></i> Archived Clients
                </a>
            </div>
            <?php if (!empty($clients)): ?>
                <div class="mb-3">
                    <label for="searchClients" class="form-label">Search Clients</label>
                    <input
                        type="text"
                        class="form-control"
                        id="searchClients"
                        placeholder="Search by name, email, or phone..."
                        autocomplete="off"
                    >
                </div>
            <?php endif; ?>
            <?php
            $clientTableColumns = [
                ['label' => 'ID', 'class' => 'd-none d-md-table-cell'],
                ['label' => 'Name'],
                ['label' => 'Email', 'class' => 'd-none d-sm-table-cell'],
                ['label' => 'Phone', 'class' => 'd-none d-md-table-cell'],
                ['label' => 'Created', 'class' => 'd-none d-lg-table-cell'],
                ['label' => 'Actions'],
            ];
            $clientTableColumnCount = count($clientTableColumns);
            ?>
            <div class="table-responsive">
                <table class="table table-hover client-list-table" id="clientsTable">
                    <thead>
                        <tr>
                            <?php foreach ($clientTableColumns as $column): ?>
                                <th<?= !empty($column['class']) ? ' class="' . escape($column['class']) . '"' : '' ?>>
                                    <?= escape($column['label']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)): ?>
                            <tr>
                                <td colspan="<?= $clientTableColumnCount ?>" class="text-center py-4">
                                    <p class="text-muted">
                                        <?= $view === 'archived' ? 'No archived clients found.' : 'No clients found. Add your first client to get started!' ?>
                                    </p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                                <tr data-search-text="<?= escape(implode(' ', [
                                    $client['name'] ?? '',
                                    $client['email'] ?? '',
                                    $client['phone'] ?? '',
                                ])) ?>">
                                    <td class="d-none d-md-table-cell"><?= escape($client['id']) ?></td>
                                    <td>
                                        <strong><?= escape($client['name']) ?></strong>
                                        <?php if (!empty($client['is_admin'])): ?>
                                            <span class="badge bg-primary ms-2" title="Has admin access">
                                                <i class="fas fa-shield-check"></i> Admin
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($client['is_archived'])): ?>
                                            <span class="badge bg-secondary ms-2">
                                                <i class="fas fa-box-archive"></i> Archived
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-sm-table-cell"><?= escape($client['email']) ?></td>
                                    <td class="d-none d-md-table-cell"><?= escape($client['phone'] ?? 'N/A') ?></td>
                                    <td class="d-none d-lg-table-cell"><?= formatDate($client['created_at']) ?></td>
                                    <td class="text-nowrap">
                                        <!-- Desktop: individual icon buttons (hidden on mobile) -->
                                        <div class="d-none d-md-inline-flex gap-1 client-action-btns table-action-buttons">
                                            <a href="clients_view.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-info table-action-btn" title="View Profile">
                                                <i class="fas fa-address-book"></i>
                                            </a>
                                            <a href="clients_edit.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary table-action-btn" title="Edit">
                                                <i class="fas fa-pencil"></i>
                                            </a>
                                            <a href="pets_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-success table-action-btn" title="View Pets">
                                                <i class="fas fa-dog"></i>
                                            </a>
                                             <a href="time_entries_list.php?client_id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-secondary table-action-btn" title="Time Entries">
                                                 <i class="fas fa-clock"></i>
                                             </a>
                                            <?php if (empty($client['is_admin']) && empty($client['is_archived'])): ?>
                                            <a href="impersonate_client.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-warning table-action-btn" title="View Portal as Client"
                                               onclick="return confirm('View the client portal as this client?')">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php endif; ?>
                                             <?php if ($view === 'archived'): ?>
                                             <form method="post" class="d-inline" onsubmit="return confirm('Unarchive this client and return them to the active client list?')">
                                                 <input type="hidden" name="unarchive_id" value="<?= $client['id'] ?>">
                                                 <input type="hidden" name="return_view" value="<?= escape($view) ?>">
                                                 <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                 <button type="submit" class="btn btn-sm btn-outline-info table-action-btn" title="Unarchive">
                                                     <i class="fas fa-box-open"></i>
                                                 </button>
                                             </form>
                                             <?php else: ?>
                                             <form method="post" class="d-inline" onsubmit="return confirm('Archive this client? Pending items such as quotes, contracts, invoices, forms, workflows, and bookings will be cancelled or voided.')">
                                                 <input type="hidden" name="archive_id" value="<?= $client['id'] ?>">
                                                 <input type="hidden" name="return_view" value="<?= escape($view) ?>">
                                                 <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                 <button type="submit" class="btn btn-sm btn-outline-secondary table-action-btn" title="Archive">
                                                     <i class="fas fa-box-archive"></i>
                                                 </button>
                                             </form>
                                             <?php endif; ?>
                                             <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this client? This cannot be undone.')">
                                                 <input type="hidden" name="delete_id" value="<?= $client['id'] ?>">
                                                 <input type="hidden" name="return_view" value="<?= escape($view) ?>">
                                                 <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                 <button type="submit" class="btn btn-sm btn-outline-danger table-action-btn" title="Delete">
                                                     <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <!-- Mobile: compact dropdown menu -->
                                        <div class="d-md-none client-action-dropdown table-action-dropdown">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item" href="clients_view.php?id=<?= $client['id'] ?>">
                                                            <i class="fas fa-address-book me-2 text-info"></i>View Profile
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="clients_edit.php?id=<?= $client['id'] ?>">
                                                            <i class="fas fa-pencil me-2 text-primary"></i>Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="pets_list.php?client_id=<?= $client['id'] ?>">
                                                            <i class="fas fa-dog me-2 text-success"></i>View Pets
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="time_entries_list.php?client_id=<?= $client['id'] ?>">
                                                            <i class="fas fa-clock me-2 text-secondary"></i>Time Entries
                                                        </a>
                                                    </li>
                                                    <?php if (empty($client['is_admin']) && empty($client['is_archived'])): ?>
                                                    <li>
                                                        <a class="dropdown-item" href="impersonate_client.php?id=<?= $client['id'] ?>"
                                                           onclick="return confirm('View the client portal as this client?')">
                                                             <i class="fas fa-eye me-2 text-warning"></i>View Portal as Client
                                                         </a>
                                                     </li>
                                                     <?php endif; ?>
                                                     <li>
                                                         <?php if ($view === 'archived'): ?>
                                                             <form method="post" onsubmit="return confirm('Unarchive this client and return them to the active client list?')">
                                                                 <input type="hidden" name="unarchive_id" value="<?= $client['id'] ?>">
                                                                 <input type="hidden" name="return_view" value="<?= escape($view) ?>">
                                                                 <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                                 <button type="submit" class="dropdown-item w-100 text-start border-0 bg-transparent">
                                                                     <i class="fas fa-box-open me-2 text-info"></i>Unarchive
                                                                 </button>
                                                             </form>
                                                         <?php else: ?>
                                                             <form method="post" onsubmit="return confirm('Archive this client? Pending items such as quotes, contracts, invoices, forms, workflows, and bookings will be cancelled or voided.')">
                                                                 <input type="hidden" name="archive_id" value="<?= $client['id'] ?>">
                                                                 <input type="hidden" name="return_view" value="<?= escape($view) ?>">
                                                                 <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                                 <button type="submit" class="dropdown-item w-100 text-start border-0 bg-transparent">
                                                                     <i class="fas fa-box-archive me-2 text-secondary"></i>Archive
                                                                 </button>
                                                             </form>
                                                         <?php endif; ?>
                                                     </li>
                                                     <li><hr class="dropdown-divider"></li>
                                                     <li>
                                                         <form method="post" onsubmit="return confirm('Are you sure you want to delete this client? This cannot be undone.')">
                                                             <input type="hidden" name="delete_id" value="<?= $client['id'] ?>">
                                                             <input type="hidden" name="return_view" value="<?= escape($view) ?>">
                                                             <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                             <button type="submit" class="dropdown-item text-danger w-100 text-start border-0 bg-transparent">
                                                                 <i class="fas fa-trash me-2"></i>Delete
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="clientsSearchNoResults" hidden>
                                <td colspan="<?= $clientTableColumnCount ?>" class="text-center py-4">
                                    <p class="text-muted mb-0">No clients match your search.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('searchClients')?.addEventListener('input', function() {
    const searchTerm = this.value.trim().toLowerCase();
    const rows = document.querySelectorAll('#clientsTable tbody tr[data-search-text]');
    const noResultsRow = document.getElementById('clientsSearchNoResults');
    let visibleRows = 0;

    rows.forEach(row => {
        if (row.dataset.searchTextLower === undefined) {
            row.dataset.searchTextLower = (row.dataset.searchText || '').toLowerCase();
        }
        const searchText = row.dataset.searchTextLower;
        const matches = searchTerm === '' || searchText.includes(searchTerm);

        row.hidden = !matches;

        if (matches) {
            visibleRows++;
        }
    });

    if (noResultsRow) {
        noResultsRow.hidden = visibleRows !== 0;
    }
});
</script>

<?php include '../backend/includes/footer.php'; ?>
