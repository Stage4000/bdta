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

$page_title = $post['title'];
require_once 'includes/header.php';
?>
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
<?php require_once 'includes/footer.php'; ?>
