<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$post_id = $_GET['id'] ?? null;
$post = null;

if ($post_id) {
    $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $slug = $_POST['slug'] ?? '';
    $content = $_POST['content'] ?? '';
    $excerpt = $_POST['excerpt'] ?? '';
    $published = isset($_POST['published']) ? 1 : 0;
    $author = $_SESSION['admin_username'];
    
    try {
        if ($post_id) {
            $stmt = $conn->prepare("
                UPDATE blog_posts 
                SET title = ?, slug = ?, content = ?, excerpt = ?, published = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$title, $slug, $content, $excerpt, $published, $post_id]);
            setFlashMessage('Blog post updated successfully!', 'success');
        } else {
            $stmt = $conn->prepare("
                INSERT INTO blog_posts (title, slug, content, excerpt, author, published) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $slug, $content, $excerpt, $author, $published]);
            setFlashMessage('Blog post created successfully!', 'success');
        }
        redirect('blog_list.php');
    } catch (PDOException $e) {
        setFlashMessage('Error: ' . $e->getMessage(), 'error');
    }
}

$page_title = $post ? 'Edit Post' : 'New Post';
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <h2 class="mb-4"><?php echo $post ? 'Edit Post' : 'New Post'; ?></h2>
    
    <div class="card">
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" 
                           value="<?php echo $post ? escape($post['title']) : ''; ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="slug" class="form-label">Slug (URL-friendly)</label>
                    <input type="text" class="form-control" id="slug" name="slug" 
                           value="<?php echo $post ? escape($post['slug']) : ''; ?>" required>
                    <small class="text-muted">e.g., dog-training-tips</small>
                </div>
                
                <div class="mb-3">
                    <label for="excerpt" class="form-label">Excerpt</label>
                    <textarea class="form-control" id="excerpt" name="excerpt" rows="3"><?php echo $post ? escape($post['excerpt']) : ''; ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label for="content" class="form-label">Content</label>
                    <textarea class="form-control" id="content" name="content" rows="15" required><?php echo $post ? escape($post['content']) : ''; ?></textarea>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="published" name="published" 
                           <?php echo ($post && $post['published']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="published">
                        Publish immediately
                    </label>
                </div>
                
                <div>
                    <button type="submit" class="btn btn-primary">Save Post</button>
                    <a href="blog_list.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- TinyMCE Rich Text Editor (Self-Hosted) -->
<script src="node_modules/tinymce/tinymce.min.js"></script>
<script>
// Initialize TinyMCE for content editor
tinymce.init({
    selector: '#content',
    height: 500,
    menubar: false,
    plugins: [
        'lists', 'link', 'image', 'charmap', 'preview', 'searchreplace', 'code',
        'fullscreen', 'table', 'help', 'wordcount'
    ],
    toolbar: 'undo redo | formatselect | bold italic underline | ' +
             'bullist numlist | alignleft aligncenter alignright | ' +
             'link image | removeformat | code | help',
    content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 14px; }',
    setup: function(editor) {
        editor.on('init', function() {
            // Ensure form validation works with TinyMCE
            editor.on('change', function() {
                tinymce.triggerSave();
            });
        });
    }
});

// Auto-generate slug from title
document.getElementById('title').addEventListener('input', function() {
    if (!document.getElementById('slug').value || <?php echo $post ? 'false' : 'true'; ?>) {
        const slug = this.value.toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        document.getElementById('slug').value = slug;
    }
});
</script>

<?php require_once '../backend/includes/footer.php'; ?>
