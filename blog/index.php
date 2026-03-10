<?php
require_once '../backend/includes/config.php';

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT id, title, slug, excerpt, author, created_at FROM blog_posts WHERE published = 1 ORDER BY created_at DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Blog';
require_once 'includes/header.php';
?>
        <div class="container py-5">
            <h1 class="display-4 fw-bold mb-5">Training Tips &amp; News</h1>
            
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
<?php require_once 'includes/footer.php'; ?>
