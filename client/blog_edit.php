<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/blog_content.php';
require_once '../backend/includes/blog_cover_photo.php';
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
    $content = bdta_sanitize_blog_post_content(scalar_string($_POST['content'] ?? ''));
    $excerpt = scalar_string($_POST['excerpt'] ?? '');
    $published = isset($_POST['published']) ? 1 : 0;
    $remove_cover_photo = isset($_POST['remove_cover_photo']);
    $publish_date_input = scalar_string($_POST['publish_date'] ?? '');
    $publish_date = $post ? array_string_value($post, 'publish_date', array_string_value($post, 'created_at', date('Y-m-d H:i:s'))) : date('Y-m-d H:i:s');
    $cover_photo = $post ? array_string_value($post, 'cover_photo') : '';
    $new_cover_photo = '';
    $hasError = false;

    if ($publish_date_input) {
        $formats = ['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d'];
        $dt = false;
        foreach ($formats as $fmt) {
            $candidate = DateTime::createFromFormat($fmt, $publish_date_input);
            $errors = DateTime::getLastErrors();
            $warning_count = is_array($errors) ? array_int_value($errors, 'warning_count') : 0;
            $error_count = is_array($errors) ? array_int_value($errors, 'error_count') : 0;
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
    $cover_photo_upload = $_FILES['cover_photo'] ?? null;
    $cover_photo_upload_error = is_array($cover_photo_upload) ? array_int_value($cover_photo_upload, 'error', UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if (!$hasError && is_array($cover_photo_upload) && $cover_photo_upload_error !== UPLOAD_ERR_NO_FILE) {
        if ($cover_photo_upload_error !== UPLOAD_ERR_OK) {
            $err_messages = [
                UPLOAD_ERR_INI_SIZE   => 'Cover photo exceeds server upload size limit.',
                UPLOAD_ERR_FORM_SIZE  => 'Cover photo exceeds form upload size limit.',
                UPLOAD_ERR_PARTIAL    => 'Cover photo was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder for cover photo upload.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write the cover photo to disk.',
                UPLOAD_ERR_EXTENSION  => 'Cover photo upload blocked by a server extension.',
            ];
            setFlashMessage($err_messages[$cover_photo_upload_error] ?? 'Unable to upload the cover photo.', 'error');
            $hasError = true;
        } else {
            $tmp_path = array_string_value($cover_photo_upload, 'tmp_name');
            $orig_name = array_string_value($cover_photo_upload, 'name');
            $file_size = array_int_value($cover_photo_upload, 'size');
            $mime_to_ext = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            if ($file_size > (5 * 1024 * 1024)) {
                setFlashMessage('Cover photo must be smaller than 5 MB.', 'error');
                $hasError = true;
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = $finfo === false ? false : finfo_file($finfo, $tmp_path);
                if ($finfo !== false) {
                    finfo_close($finfo);
                }

                if (!is_string($mime_type) || !array_key_exists($mime_type, $mime_to_ext)) {
                    setFlashMessage('Only JPG, PNG, WebP, or GIF cover photos are allowed.', 'error');
                    $hasError = true;
                } else {
                    $upload_dir = __DIR__ . '/../backend/uploads/blog_covers/';
                    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                        setFlashMessage('Failed to create the blog cover photo upload directory.', 'error');
                        $hasError = true;
                    }

                    $filename = 'blog_cover_' . ($post_id > 0 ? (int) $post_id . '_' : '') . str_replace('.', '_', uniqid('', true)) . '.' . $mime_to_ext[$mime_type];
                    $dest_path = $upload_dir . $filename;

                    if (!$hasError) {
                        if (!move_uploaded_file($tmp_path, $dest_path)) {
                            setFlashMessage('Failed to save the uploaded cover photo.', 'error');
                            $hasError = true;
                        } else {
                            $new_cover_photo = '/backend/uploads/blog_covers/' . $filename;
                        }
                    }
                }
            }
        }
    }
    if (!$hasError) {
        $author = scalar_string($_SESSION['admin_username'] ?? '');
        $old_cover_photo = $post ? array_string_value($post, 'cover_photo') : '';
        if ($new_cover_photo !== '') {
            $cover_photo = $new_cover_photo;
        } elseif ($remove_cover_photo) {
            $cover_photo = '';
        }
        
        try {
            if ($post_id) {
                $stmt = $conn->prepare("
                    UPDATE blog_posts 
                    SET title = ?, slug = ?, content = ?, excerpt = ?, cover_photo = ?, published = ?, publish_date = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$title, $slug, $content, $excerpt, $cover_photo, $published, $publish_date, $post_id]);
                setFlashMessage('Blog post updated successfully!', 'success');
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO blog_posts (title, slug, content, excerpt, cover_photo, author, published, publish_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $slug, $content, $excerpt, $cover_photo, $author, $published, $publish_date]);
                setFlashMessage('Blog post created successfully!', 'success');
            }
            $replaced_existing_cover_photo = $new_cover_photo !== '' && $old_cover_photo !== '' && $old_cover_photo !== $new_cover_photo;
            $removed_existing_cover_photo = $remove_cover_photo && $old_cover_photo !== '' && $new_cover_photo === '';
            if ($replaced_existing_cover_photo || $removed_existing_cover_photo) {
                $old_cover_photo_path = bdta_get_blog_cover_photo_filesystem_path($old_cover_photo);
                if ($old_cover_photo_path !== '' && is_file($old_cover_photo_path)) {
                    // old_cover_photo_path is constrained to the fixed blog cover upload directory.
                    // nosemgrep
                    unlink($old_cover_photo_path);
                }
            }
            redirect('blog_list.php');
        } catch (PDOException $e) {
            if ($new_cover_photo !== '') {
                $new_cover_photo_path = bdta_get_blog_cover_photo_filesystem_path($new_cover_photo);
                if ($new_cover_photo_path !== '' && is_file($new_cover_photo_path)) {
                    // new_cover_photo_path is constrained to the fixed blog cover upload directory.
                    // nosemgrep
                    unlink($new_cover_photo_path);
                }
            }
            setFlashMessage('Error: ' . $e->getMessage(), 'error');
        }
    }
}

$page_title = $post ? 'Edit Post' : 'New Post';
$post_title = $post ? array_string_value($post, 'title') : '';
$post_slug = $post ? array_string_value($post, 'slug') : '';
$post_excerpt = $post ? array_string_value($post, 'excerpt') : '';
$post_content = $post ? array_string_value($post, 'content') : '';
$post_cover_photo = $post ? array_string_value($post, 'cover_photo') : '';
$post_published = $post ? array_int_value($post, 'published') === 1 : false;
$publish_date_value = $post ? array_string_value($post, 'publish_date', array_string_value($post, 'created_at', date('Y-m-d H:i:s'))) : date('Y-m-d H:i:s');
$publish_date_value = date('Y-m-d\\TH:i', safe_timestamp(strtotime($publish_date_value)));
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <h2><i class="fas fa-blog me-2"></i><?php echo $post ? 'Edit Post' : 'New Post'; ?></h2>
    
    <div class="card">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
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
                    <label for="cover_photo" class="form-label">Cover Photo</label>
                    <input type="file" class="form-control" id="cover_photo" name="cover_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small class="text-muted">Optional. JPG, PNG, WebP, or GIF up to 5 MB.</small>
                    <?php if (bdta_is_blog_cover_photo_upload_path($post_cover_photo)): ?>
                    <div class="mt-3">
                        <img src="<?php echo escape($post_cover_photo); ?>" alt="<?php echo escape($post_title); ?>" class="img-fluid rounded border" style="max-height: 220px; object-fit: cover;">
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="remove_cover_photo" name="remove_cover_photo">
                        <label class="form-check-label" for="remove_cover_photo">
                            Remove existing cover photo
                        </label>
                    </div>
                    <?php endif; ?>
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
