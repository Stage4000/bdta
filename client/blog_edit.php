<?php
require_once '../backend/includes/config.php';
requireLogin();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$db = new Database();
$conn = $db->getConnection();

$post_id = safe_int($_GET['id'] ?? 0);
$post = null;

if ($post_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$post_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token']), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'error');
        redirect('blog_list.php');
    }

    $title = scalar_string($_POST['title'] ?? '');
    $slug = scalar_string($_POST['slug'] ?? '');
    $content = scalar_string($_POST['content'] ?? '');
    $excerpt = scalar_string($_POST['excerpt'] ?? '');
    $published = isset($_POST['published']) ? 1 : 0;
    $publish_date_input = scalar_string($_POST['publish_date'] ?? '');
    $publish_date = $post ? array_string_value($post, 'publish_date', array_string_value($post, 'created_at', date('Y-m-d H:i:s'))) : date('Y-m-d H:i:s');
    $hasError = false;

    if ($publish_date_input) {
        $formats = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d'];
        $dt = false;
        foreach ($formats as $fmt) {
            $candidate = DateTime::createFromFormat($fmt, $publish_date_input);
            $errors = DateTime::getLastErrors();
            $warning_count = is_array($errors) ? safe_int($errors['warning_count']) : 0;
            $error_count = is_array($errors) ? safe_int($errors['error_count']) : 0;
            if ($candidate !== false && $warning_count === 0 && $error_count === 0) {
                $dt = $candidate;
                break;
            }
        }
        if ($dt !== false) {
            $publish_date = $dt->format('Y-m-d H:i:s');
        } else {
            setFlashMessage('Invalid publish date format. Use the date/time picker.', 'error');
            $hasError = true;
        }
    }
    if (!$hasError) {
        $author = scalar_string($_SESSION['admin_username'] ?? '');
        
        try {
            if ($post_id) {
                $stmt = $conn->prepare("
                    UPDATE blog_posts 
                    SET title = ?, slug = ?, content = ?, excerpt = ?, published = ?, publish_date = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$title, $slug, $content, $excerpt, $published, $publish_date, $post_id]);
                setFlashMessage('Blog post updated successfully!', 'success');
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO blog_posts (title, slug, content, excerpt, author, published, publish_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $slug, $content, $excerpt, $author, $published, $publish_date]);
                setFlashMessage('Blog post created successfully!', 'success');
            }
            redirect('blog_list.php');
        } catch (PDOException $e) {
            setFlashMessage('Error: ' . $e->getMessage(), 'error');
        }
    }
}

$page_title = $post ? 'Edit Post' : 'New Post';
$post_title = $post ? array_string_value($post, 'title') : '';
$post_slug = $post ? array_string_value($post, 'slug') : '';
$post_excerpt = $post ? array_string_value($post, 'excerpt') : '';
$post_content = $post ? array_string_value($post, 'content') : '';
$post_published = $post ? array_int_value($post, 'published') === 1 : false;
$publish_date_value = $post ? array_string_value($post, 'publish_date', array_string_value($post, 'created_at', date('Y-m-d H:i:s'))) : date('Y-m-d H:i:s');
$publish_date_value = date('Y-m-d\\TH:i', safe_timestamp(strtotime($publish_date_value)));
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <h2><i class="fas fa-blog me-2"></i><?php echo $post ? 'Edit Post' : 'New Post'; ?></h2>
    
    <div class="card">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" 
                           value="<?php echo escape($post_title); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="slug" class="form-label">Slug (URL-friendly)</label>
                    <input type="text" class="form-control" id="slug" name="slug" 
                           value="<?php echo escape($post_slug); ?>" required>
                    <small class="text-muted">e.g., dog-training-tips</small>
                </div>
                
                <div class="mb-3">
                    <label for="excerpt" class="form-label">Excerpt</label>
                    <textarea class="form-control" id="excerpt" name="excerpt" rows="3"><?php echo escape($post_excerpt); ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label for="content" class="form-label">Content</label>
                    <textarea class="form-control" id="content" name="content" rows="15" required><?php echo escape($post_content); ?></textarea>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="published" name="published" 
                           <?php echo $post_published ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="published">
                        Publish (will show when publish date is reached)
                    </label>
                </div>
                
                <div class="mb-3">
                    <label for="publish_date" class="form-label">Publish Date</label>
                    <input type="datetime-local" class="form-control" id="publish_date" name="publish_date"
                           value="<?php echo escape($publish_date_value); ?>" required>
                    <small class="text-muted">Set a past date to backdate or a future date to schedule publication.</small>
                </div>
                
                <div>
                    <button type="submit" class="btn btn-primary">Save Post</button>
                    <a href="blog_list.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CKEditor 5 Rich Text Editor (Self-Hosted, GPL License) -->
<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />
<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Strikethrough,
    Paragraph,
    Heading,
    Link,
    List,
    Image,
    ImageToolbar,
    ImageUpload,
    ImageCaption,
    ImageStyle,
    ImageResize,
    Table,
    TableToolbar,
    Alignment,
    BlockQuote,
    MediaEmbed,
    SourceEditing,
    GeneralHtmlSupport
} from './js/ckeditor5/ckeditor5.js';

// Initialize CKEditor 5 for blog content editor (full-featured)
ClassicEditor
    .create(document.querySelector('#content'), {
        licenseKey: 'GPL',
        plugins: [
            Essentials, Bold, Italic, Underline, Strikethrough, 
            Paragraph, Heading, Link, List, Image, ImageToolbar, 
            ImageUpload, ImageCaption, ImageStyle, ImageResize,
            Table, TableToolbar, Alignment, BlockQuote, MediaEmbed,
            SourceEditing, GeneralHtmlSupport
        ],
        toolbar: [
            'undo', 'redo', '|',
            'heading', '|',
            'bold', 'italic', 'underline', 'strikethrough', '|',
            'link', 'uploadImage', 'insertTable', 'blockQuote', 'mediaEmbed', '|',
            'bulletedList', 'numberedList', '|',
            'alignment', '|',
            'sourceEditing'
        ],
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
                { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' }
            ]
        },
        image: {
            toolbar: [
                'imageStyle:inline', 'imageStyle:block', 'imageStyle:side', '|',
                'toggleImageCaption', 'imageTextAlternative', '|',
                'linkImage'
            ]
        },
        table: {
            contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
        },
        htmlSupport: {
            allow: [
                {
                    name: /.*/,
                    attributes: true,
                    classes: true,
                    styles: true
                }
            ]
        }
    })
    .then(editor => {
        window.blogEditor = editor;
        // Sync with textarea on change
        editor.model.document.on('change:data', () => {
            document.querySelector('#content').value = editor.getData();
        });
    })
    .catch(error => {
        console.error('CKEditor initialization error:', error);
    });
</script>
<script>

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
