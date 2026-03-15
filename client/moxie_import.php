<?php
require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/settings.php';
require_once __DIR__ . '/../backend/includes/moxie.php';

requireLogin();

$page_title = 'Import Clients from Moxie';
$base_url = MoxieClientSync::getConfiguredBaseUrl();
$api_key_saved = MoxieClientSync::getConfiguredApiKey() !== '';
$last_summary = null;

if (isset($_SESSION['moxie_import_last_summary']) && is_array($_SESSION['moxie_import_last_summary'])) {
    $summary = $_SESSION['moxie_import_last_summary'];
    $last_summary = [
        'fetched' => safe_int($summary['fetched'] ?? 0),
        'created' => safe_int($summary['created'] ?? 0),
        'updated' => safe_int($summary['updated'] ?? 0),
        'unchanged' => safe_int($summary['unchanged'] ?? 0),
        'skipped_archived' => safe_int($summary['skipped_archived'] ?? 0),
        'skipped_missing_email' => safe_int($summary['skipped_missing_email'] ?? 0),
    ];
    unset($_SESSION['moxie_import_last_summary']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'danger');
        redirect('moxie_import.php');
    }

    $submitted_base_url = '';
    $submitted_base_url_error = '';
    try {
        $submitted_base_url = MoxieClientSync::normalizeBaseUrl(scalar_string($_POST['moxie_base_url'] ?? ''));
    } catch (InvalidArgumentException $e) {
        $submitted_base_url_error = $e->getMessage();
    }
    $submitted_api_key = trim(scalar_string($_POST['moxie_api_key'] ?? ''));
    $action = scalar_string($_POST['action'] ?? '');

    if ($action === 'save_credentials') {
        if ($submitted_base_url_error !== '') {
            setFlashMessage($submitted_base_url_error, 'danger');
        } elseif ($submitted_base_url === '') {
            setFlashMessage('Please enter your Moxie workspace base URL.', 'danger');
        } else {
            $saved_base_url = Settings::set('moxie_base_url', $submitted_base_url);
            $saved_api_key = true;
            if ($submitted_api_key !== '') {
                $saved_api_key = Settings::set('moxie_api_key', $submitted_api_key);
            }

            if ($saved_base_url && $saved_api_key) {
                setFlashMessage('Moxie credentials saved successfully.', 'success');
            } else {
                setFlashMessage('Unable to save Moxie credentials. Please verify the settings are available and try again.', 'danger');
            }
        }

        redirect('moxie_import.php');
    }

    if ($action === 'run_sync') {
        if ($submitted_base_url_error !== '') {
            setFlashMessage($submitted_base_url_error, 'danger');
        } else {
            $persist_errors = [];
            if ($submitted_base_url !== '') {
                if (Settings::set('moxie_base_url', $submitted_base_url)) {
                    $base_url = $submitted_base_url;
                } else {
                    $persist_errors[] = 'workspace URL';
                }
            }

            if ($submitted_api_key !== '') {
                if (!Settings::set('moxie_api_key', $submitted_api_key)) {
                    $persist_errors[] = 'API key';
                }
            }

            if (!empty($persist_errors)) {
                setFlashMessage('Unable to save Moxie settings (' . implode(' and ', $persist_errors) . '). Sync was not started.', 'danger');
                redirect('moxie_import.php');
            }

            $api_key = $submitted_api_key !== '' ? $submitted_api_key : MoxieClientSync::getConfiguredApiKey();
            $base_url = $base_url !== '' ? $base_url : MoxieClientSync::getConfiguredBaseUrl();

            try {
                $sync = new MoxieClientSync();
                $last_summary = $sync->sync($base_url, $api_key);
                $api_key_saved = $api_key !== '';
                $_SESSION['moxie_import_last_summary'] = $last_summary;
                setFlashMessage(
                    'Moxie sync complete. '
                    . $last_summary['created'] . ' created, '
                    . $last_summary['updated'] . ' updated, '
                    . $last_summary['unchanged'] . ' unchanged.',
                    'success'
                );
                MoxieClientSync::log('Moxie sync run from admin UI.', ['admin_id' => safe_int($_SESSION['admin_id'] ?? 0)] + $last_summary);
            } catch (Throwable $e) {
                MoxieClientSync::log('Moxie sync UI error.', ['error' => $e->getMessage()]);
                setFlashMessage('Moxie sync failed: ' . $e->getMessage(), 'danger');
            }
        }

        redirect('moxie_import.php');
    }
}

include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-lg-9 col-xl-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-cloud-arrow-down me-2"></i>Import Clients from Moxie</h2>
                <a href="clients_list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Clients
                </a>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Connect your Moxie workspace, save the API key, and import clients into the existing BDTA client list.
                        Sync activity is written to <code>backend/logs/moxie.log</code> for debugging.
                    </p>

                    <form method="post" class="mb-4">
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                        <div class="mb-3">
                            <label for="moxie_base_url" class="form-label">Moxie Workspace Base URL</label>
                            <input
                                type="text"
                                class="form-control"
                                id="moxie_base_url"
                                name="moxie_base_url"
                                placeholder="pod00.withmoxie.dev or https://pod00.withmoxie.dev"
                                value="<?= escape($base_url) ?>"
                                required
                            >
                            <div class="form-text">
                                Example: <code>pod00.withmoxie.dev</code> or <code>https://pod00.withmoxie.dev</code>. The importer stores the HTTPS workspace origin and calls <code>/api/public/clients/list</code> on it.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="moxie_api_key" class="form-label">Moxie API Key</label>
                            <input
                                type="password"
                                class="form-control"
                                id="moxie_api_key"
                                name="moxie_api_key"
                                autocomplete="new-password"
                                placeholder="<?= $api_key_saved ? 'Leave blank to keep the saved API key' : 'Paste your Moxie API key' ?>"
                            >
                            <div class="form-text">
                                <?= $api_key_saved ? 'An API key is already saved. Leave this blank to reuse it.' : 'The API key will be saved in BDTA settings for future syncs.' ?>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="action" value="save_credentials" class="btn btn-outline-primary">
                                <i class="fas fa-floppy-disk me-1"></i> Save Credentials
                            </button>
                            <button type="submit" name="action" value="run_sync" class="btn btn-primary">
                                <i class="fas fa-rotate me-1"></i> Run Client Sync
                            </button>
                        </div>
                    </form>

                    <?php if (is_array($last_summary)): ?>
                        <div class="alert alert-info mb-0">
                            <div class="row g-3">
                                <div class="col-sm-6 col-lg-4"><strong>Fetched:</strong> <?= escape($last_summary['fetched']) ?></div>
                                <div class="col-sm-6 col-lg-4"><strong>Created:</strong> <?= escape($last_summary['created']) ?></div>
                                <div class="col-sm-6 col-lg-4"><strong>Updated:</strong> <?= escape($last_summary['updated']) ?></div>
                                <div class="col-sm-6 col-lg-4"><strong>Unchanged:</strong> <?= escape($last_summary['unchanged']) ?></div>
                                <div class="col-sm-6 col-lg-4"><strong>Skipped archived:</strong> <?= escape($last_summary['skipped_archived']) ?></div>
                                <div class="col-sm-6 col-lg-4"><strong>Skipped missing email:</strong> <?= escape($last_summary['skipped_missing_email']) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>How the importer works</h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Imports active Moxie clients from the public API client list endpoint.</li>
                        <li>Matches existing BDTA clients by saved Moxie client ID first, then email, then exact name + phone.</li>
                        <li>Creates new clients when no match exists and updates existing records when Moxie data changes.</li>
                        <li>Skips archived Moxie clients and entries that do not contain an email address.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
