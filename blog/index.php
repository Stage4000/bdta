<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/blog_cover_photo.php';

$db = new Database();
$conn = $db->getConnection();

$now = (new DateTime())->format('Y-m-d H:i:s');
$stmt = $conn->prepare("
    SELECT id, title, slug, excerpt, author, publish_date, created_at, cover_photo
    FROM blog_posts 
    WHERE published = 1 AND publish_date <= :now 
    ORDER BY publish_date DESC
");
$stmt->execute([':now' => $now]);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Blog';
require_once 'includes/header.php';
?>
        <div class="container py-5">
            <h1 class="display-4 fw-bold mb-5">Training Tips &amp; News</h1>
            
            <div class="row g-4">
                <?php if (count($posts) > 0): ?>
                    <?php foreach ($posts as $post): ?>
                    <?php
                    $post_title = array_string_value($post, 'title');
                    $post_author = array_string_value($post, 'author');
                    $post_publish_date = array_string_value($post, 'publish_date');
                    $post_excerpt = array_string_value($post, 'excerpt');
                    $post_slug = array_string_value($post, 'slug');
                    $post_cover_photo = array_string_value($post, 'cover_photo');
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm hover-lift">
                            <?php if (bdta_is_blog_cover_photo_upload_path($post_cover_photo)): ?>
                            <img src="<?php echo escape($post_cover_photo); ?>" class="card-img-top" alt="<?php echo escape($post_title); ?>" style="aspect-ratio: 16 / 9; object-fit: cover;">
                            <?php endif; ?>
                            <div class="card-body p-4">
                                <h5 class="card-title fw-bold"><?php echo escape($post_title); ?></h5>
                                <p class="text-muted small mb-2">
                                    <i class="fas fa-user me-1"></i> <?php echo escape($post_author); ?> | 
                                    <i class="fas fa-calendar me-1"></i> <?php echo formatDate($post_publish_date); ?>
                                </p>
                                <?php if ($post_excerpt !== ''): ?>
                                <p class="card-text"><?php echo escape(substr($post_excerpt, 0, 150)); ?>...</p>
                                <?php endif; ?>
                                <a href="post.php?slug=<?php echo escape($post_slug); ?>" class="btn btn-primary btn-sm">Read More</a>
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
