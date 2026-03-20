<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/survey_results.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$template_id = safe_int($_GET['template_id'] ?? 0);
if ($template_id === 0) {
    setFlashMessage('Invalid survey template.', 'danger');
    redirect('form_templates_list.php');
}

$template_stmt = $conn->prepare("
    SELECT id, name, description, form_type, fields, is_active
    FROM form_templates
    WHERE id = ?
");
$template_stmt->execute([$template_id]);
$template = $template_stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($template) || bdta_normalize_form_type(array_string_value($template, 'form_type')) !== 'survey_form') {
    setFlashMessage('Survey template not found.', 'danger');
    redirect('form_templates_list.php');
}

$submissions_stmt = $conn->prepare("
    SELECT fs.id, fs.responses, fs.submitted_at, c.name AS client_name
    FROM form_submissions fs
    LEFT JOIN clients c ON fs.client_id = c.id
    WHERE fs.template_id = ?
    ORDER BY fs.submitted_at DESC, fs.id DESC
");
$submissions_stmt->execute([$template_id]);
$submissions = assoc_rows($submissions_stmt->fetchAll(PDO::FETCH_ASSOC));

$fields = decode_json_assoc_list(array_string_value($template, 'fields'));
$survey_results = bdta_build_survey_results($fields, $submissions);

$latest_submission_at = '';
if ($submissions !== []) {
    $latest_submission_at = array_string_value($submissions[0], 'submitted_at');
}

$page_title = 'Survey Results';
require_once '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-square-poll-vertical me-2"></i>Survey Results</h2>
            <p class="text-muted mb-0">
                <?= escape(array_string_value($template, 'name')) ?>
                <?php if (array_string_value($template, 'description') !== ''): ?>
                    — <?= escape(array_string_value($template, 'description')) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="form_templates_list.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Templates
            </a>
            <a href="form_templates_edit.php?id=<?= array_int_value($template, 'id') ?>" class="btn btn-primary">
                <i class="fas fa-pencil me-1"></i> Edit Template
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Total Submissions</div>
                    <div class="fs-3 fw-bold"><?= (int) $survey_results['total_submissions'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Visualized Questions</div>
                    <div class="fs-3 fw-bold"><?= (int) $survey_results['visualized_field_count'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase mb-1">Latest Submission</div>
                    <div class="fw-semibold">
                        <?= $latest_submission_at !== '' ? escape(formatDateTime($latest_submission_at)) : 'No submissions yet' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($survey_results['total_submissions'] === 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-circle-info me-1"></i> No survey submissions have been collected yet.
        </div>
    <?php endif; ?>

    <?php if ($fields === []): ?>
        <div class="alert alert-warning">
            <i class="fas fa-triangle-exclamation me-1"></i> This survey does not have any configured questions.
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($survey_results['fields'] as $field_summary): ?>
            <?php /** @var array<string, mixed> $field_summary */ ?>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <h5 class="mb-0"><?= escape(array_string_value($field_summary, 'label')) ?></h5>
                        <span class="badge <?= !empty($field_summary['supports_visualization']) ? 'bg-primary' : 'bg-secondary' ?>">
                            <?= escape(ucfirst(str_replace('_', ' ', array_string_value($field_summary, 'type')))) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted">Responses received</span>
                            <span class="fw-semibold"><?= array_int_value($field_summary, 'response_count') ?> / <?= (int) $survey_results['total_submissions'] ?></span>
                        </div>

                        <?php if (!empty($field_summary['supports_visualization'])): ?>
                            <?php $options = is_array($field_summary['options'] ?? null) ? $field_summary['options'] : []; ?>
                            <?php if ($options === []): ?>
                                <p class="text-muted mb-0">No answer choices are configured for this question.</p>
                            <?php else: ?>
                                <?php foreach ($options as $option): ?>
                                    <?php /** @var array<string, mixed> $option */ ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1 gap-3">
                                            <span><?= escape(array_string_value($option, 'label')) ?></span>
                                            <small class="text-muted text-nowrap">
                                                <?= array_int_value($option, 'count') ?> · <?= array_int_value($option, 'percentage') ?>%
                                            </small>
                                        </div>
                                        <div class="progress" style="height: 16px;">
                                            <div
                                                class="progress-bar bg-primary"
                                                role="progressbar"
                                                style="width: <?= array_int_value($option, 'percentage') ?>%;"
                                                aria-valuenow="<?= array_int_value($option, 'percentage') ?>"
                                                aria-valuemin="0"
                                                aria-valuemax="100"
                                            >
                                                <?= array_int_value($option, 'percentage') ?>%
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php $recent_responses = is_array($field_summary['recent_responses'] ?? null) ? $field_summary['recent_responses'] : []; ?>
                            <?php if ($recent_responses === []): ?>
                                <p class="text-muted mb-0">No open-ended responses yet.</p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_responses as $response_row): ?>
                                        <?php /** @var array<string, mixed> $response_row */ ?>
                                        <div class="list-group-item px-0">
                                            <div class="mb-1"><?= nl2br(escape(array_string_value($response_row, 'value'))) ?></div>
                                            <small class="text-muted">
                                                <?= escape(array_string_value($response_row, 'client_name', 'Unknown client')) ?>
                                                <?php if (array_string_value($response_row, 'submitted_at') !== ''): ?>
                                                    · <?= escape(formatDateTime(array_string_value($response_row, 'submitted_at'))) ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
