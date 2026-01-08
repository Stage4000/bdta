<?php
require_once '../includes/config.php';

$slug = $_GET['slug'] ?? '';

if (!$slug) {
    header('Location: blog.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT * FROM blog_posts WHERE slug = ? AND published = 1");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: blog.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($post['title']); ?> - Brook's Dog Training Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white fixed-top shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../../index.html">
                <i class="bi bi-paw-fill text-primary me-2"></i>Brook's Dog Training Academy
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="../../index.html">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="blog.php">Blog</a></li>
                    <li class="nav-item"><a class="nav-link" href="../../index.html#contact">Contact</a></li>
                </ul>
            </div>
        </div>
    </nav>
    
    <main style="margin-top: 80px;">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <a href="blog.php" class="btn btn-outline-primary mb-4">
                        <i class="bi bi-arrow-left me-1"></i> Back to Blog
                    </a>
                    
                    <article>
                        <h1 class="display-5 fw-bold mb-3"><?php echo escape($post['title']); ?></h1>
                        <p class="text-muted mb-4">
                            <i class="bi bi-person me-1"></i> <?php echo escape($post['author']); ?> | 
                            <i class="bi bi-calendar me-1"></i> <?php echo formatDate($post['created_at']); ?>
                        </p>
                        
                        <?php if ($post['excerpt']): ?>
                        <p class="lead"><?php echo escape($post['excerpt']); ?></p>
                        <?php endif; ?>
                        
                        <hr class="my-4">
                        
                        <div class="blog-content">
                            <?php echo nl2br(escape($post['content'])); ?>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
