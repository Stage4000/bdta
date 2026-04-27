<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/achievements.php';
requirePortalLogin();

$client_id = portalClientId();
$db = new Database();
$conn = $db->getConnection();

$assignment_id = safe_int($_GET['id'] ?? 0);
if ($assignment_id <= 0) {
    http_response_code(400);
    die('Invalid achievement certificate ID');
}

$stmt = $conn->prepare("
    SELECT ca.*,
           c.name AS client_name,
           at.title AS achievement_title,
           at.description AS achievement_description,
           at.award_mode,
           at.certificate_body_html
    FROM client_achievements ca
    INNER JOIN clients c ON c.id = ca.client_id
    INNER JOIN achievement_types at ON at.id = ca.achievement_type_id
    WHERE ca.id = ?
      AND ca.client_id = ?
    LIMIT 1
");
$stmt->execute([$assignment_id, $client_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($assignment)) {
    http_response_code(404);
    die('Achievement certificate not found');
}

if (
    array_string_value($assignment, 'status', 'awarded') !== 'awarded'
    || !bdta_achievement_mode_supports_certificate(array_string_value($assignment, 'award_mode'))
) {
    http_response_code(404);
    die('This achievement does not currently have a printable certificate.');
}

if (isset($_GET['download']) && scalar_string($_GET['download']) === '1') {
    $pdf = bdta_generate_achievement_certificate_pdf($assignment);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: attachment; filename="' . bdta_achievement_certificate_filename($assignment) . '"');
    header('Cache-Control: private, max-age=0, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $pdf;
    exit;
}

$html = bdta_render_achievement_certificate_html($assignment);
$back_button = '<a class="secondary" href="achievements.php">Back to achievements</a>';
$html = str_replace('<div class="certificate-actions">', '<div class="certificate-actions">' . $back_button, $html);

echo $html;
