<?php
require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/template_duplication.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: appointment_types_list.php');
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid request.'];
    header('Location: appointment_types_list.php');
    exit;
}

$appointment_type_id = safe_int($_POST['id'] ?? 0);
if ($appointment_type_id < 1) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Appointment type not found.'];
    header('Location: appointment_types_list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    duplicateAppointmentType($conn, $appointment_type_id);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type duplicated successfully!'];
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Error duplicating appointment type: ' . $e->getMessage()];
}

header('Location: appointment_types_list.php');
exit;
