<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/blog_content.php';
require_once '../backend/includes/blog_cover_photo.php';

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
$post_cover_photo = bdta_normalize_blog_cover_photo_path(array_string_value($post, 'cover_photo'));
$post_plain_text = strip_tags($post_content);
$post_plain_text_compact = preg_replace('/\s+/', ' ', $post_plain_text);
$post_plain_text = trim($post_plain_text_compact === null ? $post_plain_text : $post_plain_text_compact);
$meta_description = $post_excerpt !== '' ? $post_excerpt : mb_substr($post_plain_text, 0, 160);
$seo_title = $post_title;
$og_description = $meta_description;
$og_image = bdta_get_blog_cover_photo_absolute_url($post_cover_photo);
$og_image_alt = $post_title;
$og_type = 'article';
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
                        <?php if ($post_cover_photo !== ''): ?>
                        <img src="<?php echo escape($post_cover_photo); ?>" class="img-fluid rounded shadow-sm mb-4 w-100" alt="<?php echo escape($post_title); ?>" style="max-height: 420px; object-fit: cover;">
                        <?php endif; ?>
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
