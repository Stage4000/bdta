<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/blog_content.php';

$slug = scalar_string($_GET['slug'] ?? '');

if (!$slug) {
    header('Location: index.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$now = (new DateTime())->format('Y-m-d H:i:s');
$stmt = $conn->prepare("
    SELECT * FROM blog_posts 
    WHERE slug = ? AND published = 1 AND publish_date <= ?
");
$stmt->execute([$slug, $now]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    header('Location: index.php');
    exit;
}

$post_title = array_string_value($post, 'title');
$post_author = array_string_value($post, 'author');
$post_publish_date = array_string_value($post, 'publish_date');
$post_excerpt = array_string_value($post, 'excerpt');
$post_content = bdta_sanitize_blog_post_content(array_string_value($post, 'content'));
$page_title = $post_title;
require_once 'includes/header.php';
?>
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <a href="index.php" class="btn btn-outline-primary mb-4">
                        <i class="fas fa-arrow-left me-1"></i> Back to Blog
                    </a>
                    
                    <article>
                        <h1 class="display-5 fw-bold mb-3"><?php echo escape($post_title); ?></h1>
                        <p class="text-muted mb-4">
                            <i class="fas fa-user me-1"></i> <?php echo escape($post_author); ?> | 
                            <i class="fas fa-calendar me-1"></i> <?php echo formatDate($post_publish_date); ?>
                        </p>
                        
                        <?php if ($post_excerpt !== ''): ?>
                        <p class="lead"><?php echo escape($post_excerpt); ?></p>
                        <?php endif; ?>
                        
                        <hr class="my-4">
                        
                        <div class="blog-content">
                            <?php echo $post_content; ?>
                        </div>
                    </article>
                </div>
            </div>
        </div>
<?php require_once 'includes/footer.php'; ?>
