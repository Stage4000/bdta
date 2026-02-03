<?php
require_once '../backend/includes/config.php';

$slug = $_GET['slug'] ?? '';

if (!$slug) {
    header('Location: index.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT * FROM blog_posts WHERE slug = ? AND published = 1");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: index.php');
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="../css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-white fixed-top shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.html">
                <i class="fas fa-paw text-primary me-2"></i>Brook's Dog Training Academy
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="../index.html#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#services">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#events">Events</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#testimonials">Testimonials</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#contact">Contact</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php">Blog</a></li>
                </ul>
            </div>
        </div>
    </nav>
    
    <main style="margin-top: 80px;">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <a href="index.php" class="btn btn-outline-primary mb-4">
                        <i class="fas fa-arrow-left me-1"></i> Back to Blog
                    </a>
                    
                    <article>
                        <h1 class="display-5 fw-bold mb-3"><?php echo escape($post['title']); ?></h1>
                        <p class="text-muted mb-4">
                            <i class="fas fa-user me-1"></i> <?php echo escape($post['author']); ?> | 
                            <i class="fas fa-calendar me-1"></i> <?php echo formatDate($post['created_at']); ?>
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
