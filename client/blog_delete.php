<?php
require_once '../backend/includes/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('blog_list.php');
}

if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token'] ?? ''))) {
    setFlashMessage('Invalid request.', 'danger');
    redirect('blog_list.php');
}

$post_id = safe_int($_POST['id'] ?? 0);

if ($post_id > 0) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("DELETE FROM blog_posts WHERE id = ?");
    $stmt->execute([$post_id]);
    
    setFlashMessage('Blog post deleted.', 'info');
}

redirect('blog_list.php');
?>
