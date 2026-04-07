<?php
/**
 * Database Tools - Admin Panel
 * MySQL-only database utilities and connection diagnostics.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

$page_title = 'Database Tools';

$db = new Database();
$conn = $db->getConnection();

$table_stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
$table_count = safe_int($table_stmt->fetchColumn());

$counts = [];
$tables_to_check = ['clients', 'bookings', 'blog_posts', 'invoices'];
foreach ($tables_to_check as $table) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM $table");
        $counts[$table] = safe_int($stmt->fetchColumn());
    } catch (Throwable $e) {
        $counts[$table] = 0;
    }
}

include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= ADMIN_URL ?>index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= ADMIN_URL ?>settings.php?category=database">Database Settings</a></li>
                    <li class="breadcrumb-item active">Database Tools</li>
                </ol>
            </nav>
            <h2><i class="fas fa-database"></i> Database Tools</h2>
            <p class="text-muted">Monitor the active MySQL database and run export or backup utilities.</p>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-circle-info"></i> Current Database Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Database Type:</strong> <span class="badge bg-success">MYSQL</span></p>
                            <p><strong>MySQL Host:</strong> <?= escape(getenv('DB_HOST') ?: 'localhost') ?></p>
                            <p><strong>Database Name:</strong> <?= escape(getenv('DB_NAME') ?: 'bdta') ?></p>
                            <p class="mb-0"><strong>MySQL Port:</strong> <?= escape(getenv('DB_PORT') ?: '3306') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Total Tables:</strong> <?= escape((string) $table_count) ?></p>
                            <p class="mb-0"><strong>Record Counts:</strong></p>
                            <ul class="mb-0">
                                <?php foreach ($counts as $table => $count): ?>
                                    <li><?= escape(ucfirst($table)) ?>: <?= escape((string) $count) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-server"></i> MySQL Runtime</h5>
                </div>
                <div class="card-body">
                    <p class="card-text">The application now assumes MySQL everywhere in runtime code, config, and admin tooling.</p>
                    <div class="alert alert-info mb-0">
                        <small><i class="fas fa-circle-info"></i> Restart PHP/FPM or your web server after editing database environment variables.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="card-title mb-0"><i class="fas fa-triangle-exclamation"></i> Legacy SQLite Data</h5>
                </div>
                <div class="card-body">
                    <p class="card-text">SQLite runtime support has been removed. If you still have an old SQLite file, import that data into MySQL before starting the app.</p>
                    <div class="alert alert-warning mb-0">
                        <small><strong>Note:</strong> The old in-app SQLite migration path is no longer available.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="d-grid">
                                <a href="database_backup.php?action=backup_mysql" class="btn btn-success">
                                    <i class="fas fa-download"></i> Backup MySQL
                                </a>
                            </div>
                            <small class="text-muted d-block mt-2">Download a `mysqldump` backup.</small>
                        </div>

                        <div class="col-md-3">
                            <div class="d-grid">
                                <a href="database_export.php?format=sql" class="btn btn-primary">
                                    <i class="fas fa-file-export"></i> Export SQL
                                </a>
                            </div>
                            <small class="text-muted d-block mt-2">Download a SQL export of the active MySQL database.</small>
                        </div>

                        <div class="col-md-3">
                            <div class="d-grid">
                                <a href="settings.php?category=database" class="btn btn-secondary">
                                    <i class="fas fa-gear"></i> Database Settings
                                </a>
                            </div>
                            <small class="text-muted d-block mt-2">Edit MySQL host, port, database, user, and password.</small>
                        </div>

                        <div class="col-md-3">
                            <div class="d-grid">
                                <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#testConnectionModal">
                                    <i class="fas fa-plug"></i> Test Connection
                                </button>
                            </div>
                            <small class="text-muted d-block mt-2">Verify the configured MySQL connection.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="testConnectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plug"></i> Test MySQL Connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="connectionTestResults">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Testing connection...</span>
                        </div>
                        <p class="mt-2">Testing MySQL connection...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="testConnections()">
                    <i class="fas fa-rotate"></i> Retest
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('testConnectionModal').addEventListener('show.bs.modal', function () {
    testConnections();
});

function testConnections() {
    const resultsDiv = document.getElementById('connectionTestResults');
    resultsDiv.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Testing connection...</span>
            </div>
            <p class="mt-2">Testing MySQL connection...</p>
        </div>
    `;

    fetch('database_test_connection.php')
        .then(response => response.json())
        .then(data => {
            let html = '';

            data.tests.forEach(test => {
                const badgeClass = test.status === 'success' ? 'success' : 'danger';
                const iconClass = test.status === 'success' ? 'circle-check' : 'circle-xmark';

                html += `
                    <div class="alert alert-${badgeClass} mb-3">
                        <h6>
                            <i class="fas fa-${iconClass}"></i>
                            ${test.type.toUpperCase()}
                            ${test.details.active ? '<span class="badge bg-primary ms-2">Active</span>' : ''}
                        </h6>
                        <p class="mb-1"><strong>${test.message}</strong></p>
                `;

                if (test.details.host) {
                    html += `<p class="mb-0 small">Host: ${test.details.host}</p>`;
                }
                if (test.details.database) {
                    html += `<p class="mb-0 small">Database: ${test.details.database}</p>`;
                }
                if (test.details.port) {
                    html += `<p class="mb-0 small">Port: ${test.details.port}</p>`;
                }
                if (test.details.tables !== undefined) {
                    html += `<p class="mb-0 small">Tables: ${test.details.tables}</p>`;
                }
                if (test.details.error) {
                    html += `<p class="mb-0 small text-danger">Error: ${test.details.error}</p>`;
                }

                html += `</div>`;
            });

            resultsDiv.innerHTML = html;
        })
        .catch(error => {
            resultsDiv.innerHTML = `
                <div class="alert alert-danger">
                    <h6><i class="fas fa-circle-xmark"></i> Test Failed</h6>
                    <p class="mb-0">Error: ${error.message}</p>
                </div>
            `;
        });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
