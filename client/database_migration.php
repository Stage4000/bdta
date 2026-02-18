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
                    <ol>
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
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plug"></i> Test Database Connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <h6><i class="fas fa-circle-check"></i> Connection Successful</h6>
                    <p class="mb-0">Currently connected to: <strong><?= strtoupper($current_db_type) ?></strong></p>
                    <p class="mb-0">Tables: <?= $table_count ?></p>
                </div>
                <p class="text-muted small">
                    To test a different database configuration, update your settings first, then restart your web server.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
