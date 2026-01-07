<?php
require_once '../includes/config.php';
requireLogin();

$page_title = 'Test Page';
include '../includes/header.php';
?>

<div class="container-fluid">
    <h1>Test Page</h1>
    <p>If you see this, header and footer work!</p>
</div>

<?php include '../includes/footer.php'; ?>
