<?php
/**
 * Brook's Dog Training Academy - Portal Homepage Editor
 */

require_once '../backend/includes/config.php';
requireLogin();

$db   = new Database();
$conn = $db->getConnection();

// Fetch current content
$content = $conn->query("SELECT * FROM portal_content WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_html = $_POST['content_html'] ?? '';
    $notice_html  = $_POST['notice_html'] ?? '';
    $updated_by   = $_SESSION['admin_id'];

    $stmt = $conn->prepare("UPDATE portal_content SET content_html = ?, notice_html = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = 1");
    $stmt->execute([$content_html, $notice_html, $updated_by]);

    setFlashMessage('Portal homepage updated successfully.', 'success');
    redirect('portal_homepage.php');
}

$page_title = 'Portal Homepage';
include '../backend/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">Portal Homepage Editor</h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-4">
            Edit the content displayed on the client portal homepage.
            HTML is supported. You may use headings, lists, links, and formatted text.
        </p>
        <form method="POST">
            <div class="mb-4">
                <label for="notice_html" class="form-label fw-semibold">
                    Notice / Announcement
                    <span class="text-muted fw-normal small">(shown in a highlighted box at the top)</span>
                </label>
                <textarea id="notice_html" name="notice_html"><?php echo htmlspecialchars($content['notice_html'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="form-text">Leave blank to hide. HTML supported.</div>
            </div>

            <div class="mb-4">
                <label for="content_html" class="form-label fw-semibold">
                    Main Content
                </label>
                <textarea id="content_html" name="content_html"><?php echo htmlspecialchars($content['content_html'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                <div class="form-text">HTML supported. Leave blank to hide.</div>
            </div>

            <?php if (!empty($content['updated_at'])): ?>
            <p class="text-muted small">Last updated: <?php echo escape($content['updated_at']); ?></p>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Save Changes
            </button>
            <a href="index.php" class="btn btn-secondary ms-2">Cancel</a>
        </form>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>

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
    Table,
    TableToolbar,
    Alignment,
    SourceEditing,
    GeneralHtmlSupport
} from './js/ckeditor5/ckeditor5.js';

const editorConfig = {
    licenseKey: 'GPL',
    plugins: [
        Essentials, Bold, Italic, Underline, Strikethrough,
        Paragraph, Heading, Link, List, Table, TableToolbar,
        Alignment, SourceEditing, GeneralHtmlSupport
    ],
    toolbar: [
        'undo', 'redo', '|',
        'heading', '|',
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'link', 'insertTable', '|',
        'bulletedList', 'numberedList', '|',
        'alignment', '|',
        'sourceEditing'
    ],
    heading: {
        options: [
            { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
            { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
            { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
            { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
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
};

// Initialize CKEditor 5 for Notice / Announcement field
ClassicEditor
    .create(document.querySelector('#notice_html'), editorConfig)
    .then(editor => {
        window.noticeEditor = editor;
        editor.model.document.on('change:data', () => {
            document.querySelector('#notice_html').value = editor.getData();
        });
    })
    .catch(error => {
        console.error('CKEditor initialization error (notice_html):', error);
    });

// Initialize CKEditor 5 for Main Content field
ClassicEditor
    .create(document.querySelector('#content_html'), editorConfig)
    .then(editor => {
        window.contentEditor = editor;
        editor.model.document.on('change:data', () => {
            document.querySelector('#content_html').value = editor.getData();
        });
    })
    .catch(error => {
        console.error('CKEditor initialization error (content_html):', error);
    });
</script>
