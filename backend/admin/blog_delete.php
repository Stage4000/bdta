<?php
require_once '../includes/config.php';
requireLogin();

$post_id = $_GET['id'] ?? null;

if ($post_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("DELETE FROM blog_posts WHERE id = ?");
    $stmt->execute([$post_id]);
    
    setFlashMessage('Blog post deleted.', 'info');
}

redirect('blog_list.php');
?>
