<?php
/**
 * Database Migration Tool - Admin Panel
 * Helps migrate data between SQLite and MySQL
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

$page_title = 'Database Migration Tool';

// Get current database info
$db = new Database();
$conn = $db->getConnection();
$current_db_type = $db->getDatabaseType();

include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= ADMIN_URL ?>index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= ADMIN_URL ?>settings.php?category=database">Database Settings</a></li>
                    <li class="breadcrumb-item active">Migration Tool</li>
                </ol>
            </nav>
            <h2><i class="fas fa-arrow-right-arrow-left"></i> Database Migration Tool</h2>
            <p class="text-muted">Migrate data between SQLite and MySQL databases</p>
        </div>
    </div>

    <!-- Current Database Status -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-circle-info"></i> Current Database Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Current Database Type:</strong> 
                                <span class="badge bg-<?= $current_db_type === 'mysql' ? 'success' : 'info' ?>">
                                    <?= strtoupper($current_db_type) ?>
                                </span>
                            </p>
                            <?php if ($current_db_type === 'sqlite'): ?>
                                <p><strong>Database File:</strong> backend/bdta.db</p>
                            <?php else: ?>
                                <p><strong>MySQL Host:</strong> <?= getenv('DB_HOST') ?: 'localhost' ?></p>
                                <p><strong>Database Name:</strong> <?= getenv('DB_NAME') ?: 'bdta' ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <?php
                            // Get table count
                            if ($current_db_type === 'sqlite') {
                                $stmt = $conn->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                            } else {
                                $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
                            }
                            $table_count = $stmt->fetchColumn();
                            
                            // Get sample record counts
                            $counts = [];
                            $tables_to_check = ['clients', 'bookings', 'blog_posts', 'invoices'];
                            foreach ($tables_to_check as $table) {
                                try {
                                    $stmt = $conn->query("SELECT COUNT(*) FROM $table");
                                    $counts[$table] = $stmt->fetchColumn();
                                } catch (Exception $e) {
                                    $counts[$table] = 0;
                                }
                            }
                            ?>
                            <p><strong>Total Tables:</strong> <?= $table_count ?></p>
                            <p class="mb-0"><strong>Record Counts:</strong></p>
                            <ul class="mb-0">
                                <?php foreach ($counts as $table => $count): ?>
                                    <li><?= ucfirst($table) ?>: <?= $count ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Migration Instructions -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-upload"></i> Migrating to MySQL</h5>
                </div>
                <div class="card-body">
                    <p class="card-text">To migrate from SQLite to MySQL:</p>
                    <?php if ($current_db_type === 'sqlite'): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-bolt"></i> Quick Migration Available!</h6>
                            <p class="mb-2 small">You're currently using SQLite. Use the button below to automatically migrate all your data to MySQL.</p>
                            <button class="btn btn-sm btn-success" onclick="performAutoMigration()">
                                <i class="fas fa-magic"></i> Auto-Migrate to MySQL
                            </button>
                        </div>
                        <hr>
                    <?php endif; ?>
                    <p class="small">Manual migration steps:</p>
                    <ol class="small">
                        <li>Create a MySQL database and user</li>
                        <li>Configure MySQL credentials in <a href="settings.php?category=database">Database Settings</a></li>
                        <li>Export your SQLite data using the button below</li>
                        <li>Follow the on-screen instructions to import</li>
                    </ol>
                    <div class="alert alert-warning mt-3">
                        <small><i class="fas fa-triangle-exclamation"></i> <strong>Important:</strong> Always backup your SQLite database before migrating!</small>
                    </div>
                    <a href="<?= ADMIN_URL ?>../backend/MYSQL_MIGRATION.md" target="_blank" class="btn btn-outline-primary">
                        <i class="fas fa-book"></i> View Detailed Migration Guide
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0"><i class="fas fa-download"></i> Migrating to SQLite</h5>
                </div>
                <div class="card-body">
                    <p class="card-text">To migrate from MySQL to SQLite:</p>
                    <ol>
                        <li>Export your MySQL data using mysqldump</li>
                        <li>Convert the SQL to SQLite format</li>
                        <li>Update Database Settings to use SQLite</li>
                        <li>Restart your web server</li>
                    </ol>
                    <div class="alert alert-info mt-3">
                        <small><i class="fas fa-circle-info"></i> <strong>Note:</strong> SQLite is recommended for development only. Use MySQL for production.</small>
                    </div>
                    <a href="<?= ADMIN_URL ?>../backend/MYSQL_MIGRATION.md" target="_blank" class="btn btn-outline-info">
                        <i class="fas fa-book"></i> View Detailed Migration Guide
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if ($current_db_type === 'sqlite'): ?>
                            <div class="col-md-4">
                                <div class="d-grid">
                                    <a href="database_backup.php?action=backup_sqlite" class="btn btn-success">
                                        <i class="fas fa-download"></i> Backup SQLite Database
                                    </a>
                                </div>
                                <small class="text-muted d-block mt-2">Download a copy of your SQLite database file</small>
                            </div>
                            <div class="col-md-4">
                                <div class="d-grid">
                                    <a href="database_export.php?format=sql" class="btn btn-primary">
                                        <i class="fas fa-file-export"></i> Export as SQL
                                    </a>
                                </div>
                                <small class="text-muted d-block mt-2">Export database as SQL statements for MySQL import</small>
                            </div>
                        <?php else: ?>
                            <div class="col-md-4">
                                <div class="d-grid">
                                    <a href="database_backup.php?action=backup_mysql" class="btn btn-success">
                                        <i class="fas fa-download"></i> Backup MySQL Database
                                    </a>
                                </div>
                                <small class="text-muted d-block mt-2">Download a MySQL dump of your database</small>
                            </div>
                        <?php endif; ?>
                        
                        <div class="col-md-4">
                            <div class="d-grid">
                                <a href="settings.php?category=database" class="btn btn-secondary">
                                    <i class="fas fa-gear"></i> Database Settings
                                </a>
                            </div>
                            <small class="text-muted d-block mt-2">Configure database connection settings</small>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="d-grid">
                                <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#testConnectionModal">
                                    <i class="fas fa-plug"></i> Test Connection
                                </button>
                            </div>
                            <small class="text-muted d-block mt-2">Test database connectivity</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Test Connection Modal -->
<div class="modal fade" id="testConnectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plug"></i> Test Database Connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="connectionTestResults">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Testing connections...</span>
                        </div>
                        <p class="mt-2">Testing database connections...</p>
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
// Test connections when modal is opened
document.getElementById('testConnectionModal').addEventListener('show.bs.modal', function () {
    testConnections();
});

function testConnections() {
    const resultsDiv = document.getElementById('connectionTestResults');
    resultsDiv.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Testing connections...</span>
            </div>
            <p class="mt-2">Testing database connections...</p>
        </div>
    `;
    
    fetch('database_test_connection.php')
        .then(response => response.json())
        .then(data => {
            let html = '';
            
            data.tests.forEach(test => {
                const badgeClass = test.status === 'success' ? 'success' : 
                                  test.status === 'error' ? 'danger' : 'warning';
                const iconClass = test.status === 'success' ? 'circle-check' : 
                                 test.status === 'error' ? 'circle-xmark' : 'triangle-exclamation';
                
                html += `
                    <div class="alert alert-${badgeClass === 'success' ? 'success' : badgeClass === 'danger' ? 'danger' : 'warning'} mb-3">
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
                if (test.details.file) {
                    html += `<p class="mb-0 small">File: ${test.details.file}</p>`;
                }
                if (test.details.tables !== undefined) {
                    html += `<p class="mb-0 small">Tables: ${test.details.tables}</p>`;
                }
                if (test.details.error) {
                    html += `<p class="mb-0 small text-danger">Error: ${test.details.error}</p>`;
                }
                if (test.details.note) {
                    html += `<p class="mb-0 small fst-italic">${test.details.note}</p>`;
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

<script>
// Auto-migration function
function performAutoMigration() {
    if (!confirm('This will migrate all data from SQLite to MySQL. Make sure you have configured MySQL settings correctly.\n\nContinue with migration?')) {
        return;
    }
    
    const btn = event.target;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Migrating...';
    
    fetch('database_auto_migrate.php')
        .then(response => response.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            
            if (data.success) {
                let message = `Migration completed successfully!\n\n`;
                message += `Tables migrated: ${data.migrated_tables}/${data.total_tables}\n`;
                message += `Total rows migrated: ${data.migrated_rows}\n`;
                
                if (data.errors && data.errors.length > 0) {
                    message += `\nWarnings:\n` + data.errors.join('\n');
                }
                
                alert(message);
                
                // Ask if user wants to switch to MySQL now
                if (confirm('Migration successful! Do you want to switch to MySQL now?\n\n(This will update your database settings and reload the page)')) {
                    // Update setting to use MySQL
                    const formData = new FormData();
                    formData.append('category', 'database');
                    formData.append('db_type', 'mysql');
                    formData.append('save_settings', '1');
                    
                    fetch('settings.php?category=database', {
                        method: 'POST',
                        body: formData
                    }).then(() => {
                        window.location.reload();
                    });
                }
            } else {
                alert('Migration failed: ' + data.error);
            }
        })
        .catch(error => {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert('Migration error: ' + error.message);
        });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
