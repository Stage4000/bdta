<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/achievements.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();
$page_url = 'achievement_types.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && scalar_string($_POST['achievement_action'] ?? '') === 'save_type') {
    requireValidCsrfToken($page_url);

    $admin_id = safe_int($_SESSION['admin_id'] ?? 0);
    $type_id = safe_int($_POST['type_id'] ?? 0);
    $badge_icon_upload = isset($_FILES['badge_icon']) && is_array($_FILES['badge_icon'])
        ? assoc_row($_FILES['badge_icon'])
        : [];
    $certificate_template_upload = isset($_FILES['certificate_template']) && is_array($_FILES['certificate_template'])
        ? assoc_row($_FILES['certificate_template'])
        : [];

    try {
        bdta_save_achievement_type(
            $conn,
            [
                'type_id' => $type_id,
                'title' => scalar_string($_POST['title'] ?? ''),
                'description' => scalar_string($_POST['description'] ?? ''),
                'scope_type' => 'general',
                'award_mode' => scalar_string($_POST['award_mode'] ?? 'badge_certificate'),
                'certificate_body_html' => scalar_string($_POST['certificate_body_html'] ?? ''),
            ],
            $badge_icon_upload,
            $certificate_template_upload,
            $admin_id
        );
        setFlashMessage($type_id > 0 ? 'Achievement template updated.' : 'Achievement template created.', 'success');
    } catch (Throwable $e) {
        setFlashMessage($e->getMessage(), 'danger');
    }

    redirect($page_url);
}

$achievement_types = bdta_get_achievement_types($conn, 'general', true);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-medal me-2"></i>Achievement Templates
            </h2>
            <p class="text-muted mb-0">Create and manage reusable badge/certificate templates that can be assigned from a client profile.</p>
        </div>
        <a href="clients_list.php" class="btn btn-outline-secondary">
            <i class="fas fa-users me-1"></i>Back to clients
        </a>
    </div>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header bg-secondary text-white">
                    <strong><i class="fas fa-plus-circle me-2"></i>Create reusable achievement type</strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?= escape($page_url) ?>" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                        <input type="hidden" name="achievement_action" value="save_type">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="achievementTypeTitle" class="form-label">Title</label>
                                <input type="text" class="form-control" id="achievementTypeTitle" name="title" required>
                            </div>
                            <div class="col-md-6">
                                <label for="achievementAwardMode" class="form-label">Visibility</label>
                                <select class="form-select" id="achievementAwardMode" name="award_mode">
                                    <?php foreach (bdta_achievement_modes() as $mode_value => $mode_label): ?>
                                        <option value="<?= escape($mode_value) ?>"><?= escape($mode_label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="achievementTypeDescription" class="form-label">Description</label>
                                <textarea class="form-control" id="achievementTypeDescription" name="description" rows="2"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="achievementBadgeIcon" class="form-label">Badge icon</label>
                                <input type="file" class="form-control" id="achievementBadgeIcon" name="badge_icon" accept="image/png,image/jpeg,image/gif,image/webp">
                            </div>
                            <div class="col-md-6">
                                <label for="achievementCertificateTemplate" class="form-label">Certificate PDF template</label>
                                <input type="file" class="form-control" id="achievementCertificateTemplate" name="certificate_template" accept="application/pdf">
                            </div>
                            <div class="col-12">
                                <label for="achievementCertificateBody" class="form-label">Certificate body HTML</label>
                                <textarea class="form-control" id="achievementCertificateBody" name="certificate_body_html" rows="4" placeholder="Use placeholders like {{client_name}}, {{dog_name}}, {{program_name}}, {{award_date}}, and {{achievement_title}}"><?= escape(bdta_default_certificate_body_html()) ?></textarea>
                                <small class="text-muted">Reusable templates appear in the Achievements tab on each client profile for quick assignment.</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-secondary mt-3">
                            <i class="fas fa-save me-1"></i>Create template
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header">
                    <strong>Configured reusable achievement types</strong>
                </div>
                <div class="card-body">
                    <?php if (empty($achievement_types)): ?>
                        <p class="text-muted mb-0">No reusable achievement templates have been configured yet.</p>
                    <?php else: ?>
                        <div class="accordion" id="achievementTypeAccordion">
                            <?php foreach ($achievement_types as $achievement_type): ?>
                                <?php
                                $type_mode = bdta_normalize_achievement_mode(array_string_value($achievement_type, 'award_mode'));
                                $type_icon_path = array_string_value($achievement_type, 'badge_icon_path');
                                ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="achievement-type-heading-<?= (int) $achievement_type['id'] ?>">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#achievement-type-<?= (int) $achievement_type['id'] ?>">
                                            <span class="me-3">
                                                <?php if ($type_icon_path !== ''): ?>
                                                    <img src="<?= escape($type_icon_path) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:50%;">
                                                <?php else: ?>
                                                    <span class="badge bg-secondary rounded-circle p-3"><i class="fas <?= bdta_achievement_mode_supports_badge($type_mode) ? 'fa-award' : 'fa-certificate' ?>"></i></span>
                                                <?php endif; ?>
                                            </span>
                                            <span>
                                                <strong><?= escape(array_string_value($achievement_type, 'title')) ?></strong>
                                                <small class="d-block text-muted">Reusable template · <?= escape(bdta_achievement_modes()[$type_mode] ?? 'Achievement') ?></small>
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="achievement-type-<?= (int) $achievement_type['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#achievementTypeAccordion">
                                        <div class="accordion-body">
                                            <form method="POST" action="<?= escape($page_url) ?>" enctype="multipart/form-data">
                                                <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                                <input type="hidden" name="achievement_action" value="save_type">
                                                <input type="hidden" name="type_id" value="<?= (int) $achievement_type['id'] ?>">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Title</label>
                                                        <input type="text" class="form-control" name="title" value="<?= escape(array_string_value($achievement_type, 'title')) ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Visibility</label>
                                                        <select class="form-select" name="award_mode">
                                                            <?php foreach (bdta_achievement_modes() as $mode_value => $mode_label): ?>
                                                                <option value="<?= escape($mode_value) ?>" <?= $type_mode === $mode_value ? 'selected' : '' ?>><?= escape($mode_label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Description</label>
                                                        <textarea class="form-control" name="description" rows="2"><?= escape(array_string_value($achievement_type, 'description')) ?></textarea>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Replace badge icon</label>
                                                        <input type="file" class="form-control" name="badge_icon" accept="image/png,image/jpeg,image/gif,image/webp">
                                                        <?php if ($type_icon_path !== ''): ?>
                                                            <small class="text-muted d-block mt-1">Current icon: <?= escape(basename($type_icon_path)) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Replace certificate PDF template</label>
                                                        <input type="file" class="form-control" name="certificate_template" accept="application/pdf">
                                                        <?php if (array_string_value($achievement_type, 'certificate_template_path') !== ''): ?>
                                                            <small class="text-muted d-block mt-1">Template on file: <?= escape(basename(array_string_value($achievement_type, 'certificate_template_path'))) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Certificate body HTML</label>
                                                        <textarea class="form-control" name="certificate_body_html" rows="4"><?= escape(array_string_value($achievement_type, 'certificate_body_html', bdta_default_certificate_body_html())) ?></textarea>
                                                    </div>
                                                </div>
                                                <button type="submit" class="btn btn-outline-secondary mt-3">
                                                    <i class="fas fa-save me-1"></i>Update template
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
