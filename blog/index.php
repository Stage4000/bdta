<?php
require_once '../backend/includes/config.php';

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT id, title, slug, excerpt, author, created_at FROM blog_posts WHERE published = 1 ORDER BY created_at DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog - Brook's Dog Training Academy</title>
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
            <h1 class="display-4 fw-bold mb-5">Training Tips & News</h1>
            
            <div class="row g-4">
                <?php if (count($posts) > 0): ?>
                    <?php foreach ($posts as $post): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm hover-lift">
                            <div class="card-body p-4">
                                <h5 class="card-title fw-bold"><?php echo escape($post['title']); ?></h5>
                                <p class="text-muted small mb-2">
                                    <i class="fas fa-user me-1"></i> <?php echo escape($post['author']); ?> | 
                                    <i class="fas fa-calendar me-1"></i> <?php echo formatDate($post['created_at']); ?>
                                </p>
                                <?php if ($post['excerpt']): ?>
                                <p class="card-text"><?php echo escape(substr($post['excerpt'], 0, 150)); ?>...</p>
                                <?php endif; ?>
                                <a href="post.php?slug=<?php echo escape($post['slug']); ?>" class="btn btn-primary btn-sm">Read More</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="fas fa-inbox fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No blog posts yet. Check back soon!</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
