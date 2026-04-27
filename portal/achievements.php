<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/achievements.php';
requirePortalLogin();

$client_id = portalClientId();
$db = new Database();
$conn = $db->getConnection();

$achievements = bdta_get_client_achievement_rows($conn, $client_id, false);

$page_title = 'Achievements';
include '../portal/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="mb-0">Achievements</h2>
        <small class="text-muted">View earned badges and graduation certificates.</small>
    </div>
    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
        <i class="fas fa-award me-1"></i><?= count($achievements) ?> achievement<?= count($achievements) === 1 ? '' : 's' ?>
    </span>
</div>

<?php if (empty($achievements)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="display-6 text-muted mb-3"><i class="fas fa-award"></i></div>
            <p class="mb-0 text-muted">Your badges and certificates will appear here after they are awarded by the training team.</p>
        </div>
    </div>
<?php else: ?>
    <div class="accordion" id="portalAchievementsAccordion">
        <?php foreach ($achievements as $achievement): ?>
            <?php
            $assignment_id = array_int_value($achievement, 'id');
            $award_mode = bdta_normalize_achievement_mode(array_string_value($achievement, 'award_mode'));
            $badge_icon_path = array_string_value($achievement, 'badge_icon_path');
            ?>
            <div class="accordion-item mb-3 border rounded overflow-hidden">
                <h2 class="accordion-header" id="portal-achievement-heading-<?= $assignment_id ?>">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#portal-achievement-<?= $assignment_id ?>">
                        <span class="me-3">
                            <?php if (bdta_achievement_mode_supports_badge($award_mode) && $badge_icon_path !== ''): ?>
                                <img src="<?= escape($badge_icon_path) ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <span class="badge bg-primary rounded-circle p-3"><i class="fas <?= bdta_achievement_mode_supports_badge($award_mode) ? 'fa-award' : 'fa-certificate' ?>"></i></span>
                            <?php endif; ?>
                        </span>
                        <span class="flex-grow-1">
                            <strong><?= escape(array_string_value($achievement, 'achievement_title')) ?></strong>
                            <small class="d-block text-muted">
                                Awarded <?= escape(array_string_value($achievement, 'awarded_on')) ?>
                                <?php if (array_string_value($achievement, 'program_name') !== ''): ?>
                                    · <?= escape(array_string_value($achievement, 'program_name')) ?>
                                <?php endif; ?>
                            </small>
                        </span>
                    </button>
                </h2>
                <div id="portal-achievement-<?= $assignment_id ?>" class="accordion-collapse collapse" data-bs-parent="#portalAchievementsAccordion">
                    <div class="accordion-body">
                        <div class="row g-4">
                            <?php if (bdta_achievement_mode_supports_badge($award_mode)): ?>
                                <div class="col-lg-4">
                                    <div class="border rounded p-3 text-center h-100">
                                        <h6 class="mb-3">Badge</h6>
                                        <?php if ($badge_icon_path !== ''): ?>
                                            <img src="<?= escape($badge_icon_path) ?>" alt="" style="width:88px;height:88px;object-fit:cover;border-radius:50%;">
                                        <?php else: ?>
                                            <div class="display-5 text-primary"><i class="fas fa-award"></i></div>
                                        <?php endif; ?>
                                        <p class="small text-muted mb-0 mt-3">Shown on your dashboard and achievements page.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="<?= bdta_achievement_mode_supports_badge($award_mode) ? 'col-lg-8' : 'col-12' ?>">
                                <h5><?= escape(array_string_value($achievement, 'achievement_title')) ?></h5>
                                <p><?= nl2br(escape(array_string_value($achievement, 'achievement_description', 'No description provided.'))) ?></p>
                                <dl class="row small">
                                    <dt class="col-sm-4">Date</dt>
                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'awarded_on')) ?></dd>
                                    <dt class="col-sm-4">Dog</dt>
                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'dog_name', '—')) ?></dd>
                                    <dt class="col-sm-4">Program</dt>
                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'program_name', '—')) ?></dd>
                                    <dt class="col-sm-4">Status</dt>
                                    <dd class="col-sm-8"><?= escape(bdta_achievement_modes()[$award_mode] ?? 'Achievement') ?></dd>
                                </dl>

                                <?php if (bdta_achievement_mode_supports_certificate($award_mode)): ?>
                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <a href="achievement_certificate.php?id=<?= $assignment_id ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-print me-1"></i>Print certificate
                                        </a>
                                        <a href="achievement_certificate.php?id=<?= $assignment_id ?>&amp;download=1" class="btn btn-primary btn-sm">
                                            <i class="fas fa-download me-1"></i>Download PDF
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.location.hash || !window.location.hash.startsWith('#portal-achievement-')) {
        return;
    }

    const targetId = window.location.hash.slice(1);
    if (!/^portal-achievement-\d+$/.test(targetId)) {
        return;
    }

    const target = document.getElementById(targetId);
    const trigger = target ? document.querySelector('[data-bs-target="#' + targetId + '"]') : null;
    if (target && trigger && window.bootstrap && bootstrap.Collapse) {
        bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).show();
        trigger.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>

<?php include '../portal/includes/footer.php'; ?>
