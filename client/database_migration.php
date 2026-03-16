<?php
/**
 * Database Migration Tool (deprecated)
 * SQLite support has been removed; MySQL is now required.
 */

require_once __DIR__ . '/../backend/includes/config.php';

requireLogin();

$page_title = 'Database Migration';

include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= ADMIN_URL ?>index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= ADMIN_URL ?>settings.php?category=database">Database Settings</a></li>
                    <li class="breadcrumb-item active">Migration</li>
                </ol>
            </nav>
            <h2><i class="fas fa-database"></i> Database Migration</h2>
            <p class="text-muted">SQLite compatibility has been removed. Configure MySQL to continue.</p>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle"></i>
                        MySQL is now the only supported database. If you previously used SQLite,
                        export your data manually and import it into MySQL following the deployment guide.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
