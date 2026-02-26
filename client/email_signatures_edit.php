<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$signature = null;

if ($id) {
    $stmt = $conn->prepare("SELECT * FROM email_signature_templates WHERE id = ?");
    $stmt->execute([$id]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$signature) {
        $_SESSION['error'] = "Signature not found";
        redirect(ADMIN_URL . 'email_signatures_list.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $html_content = trim($_POST['html_content']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $max_image_width = (int)($_POST['max_image_width'] ?? 600);
    $max_image_height = (int)($_POST['max_image_height'] ?? 200);
    
    // Sanitize HTML content
    require_once '../backend/includes/email_signature_helper.php';
    $html_content = EmailSignatureHelper::sanitizeHTML($html_content);
    
    try {
        // If setting as default, unset all others first
        if ($is_default) {
            $conn->exec("UPDATE email_signature_templates SET is_default = 0");
        }
        
        if ($id) {
            $stmt = $conn->prepare("
                UPDATE email_signature_templates 
                SET name = ?, description = ?, html_content = ?, is_active = ?, is_default = ?,
                    max_image_width = ?, max_image_height = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $html_content, $is_active, $is_default, 
                           $max_image_width, $max_image_height, $id]);
            $_SESSION['success'] = "Signature updated successfully!";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO email_signature_templates 
                (name, description, html_content, is_active, is_default, max_image_width, max_image_height, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $description, $html_content, $is_active, $is_default, 
                           $max_image_width, $max_image_height, $_SESSION['user_id'] ?? null]);
            $_SESSION['success'] = "Signature created successfully!";
            
            // Get the new signature ID
            $id = $conn->lastInsertId();
        }
        
        // Update settings if this is the default
        if ($is_default) {
            require_once '../backend/includes/settings.php';
            Settings::set('default_email_signature_id', $id);
        }
        
        redirect(ADMIN_URL . 'email_signatures_list.php');
    } catch (Exception $e) {
        $_SESSION['error'] = "Error saving signature: " . $e->getMessage();
    }
}

$page_title = ($id ? 'Edit' : 'Create') . ' Email Signature';
include '../backend/includes/header.php';
?>

<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fas fa-signature me-2"></i><?= $id ? 'Edit' : 'Create' ?> Email Signature
                </h2>
                <a href="email_signatures_list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Signatures
                </a>
            </div>

            <?php 
            $flash = getFlashMessage();
            if ($flash): 
            ?>
                <div class="alert alert-<?= escape($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= escape($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <form method="POST" id="signatureForm">
                                <div class="mb-3">
                                    <label class="form-label">Signature Name *</label>
                                    <input type="text" name="name" class="form-control" required 
                                           value="<?= escape($signature['name'] ?? '') ?>"
                                           placeholder="e.g., Professional Signature">
                                    <div class="form-text">A descriptive name for this signature template</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <input type="text" name="description" class="form-control" 
                                           value="<?= escape($signature['description'] ?? '') ?>"
                                           placeholder="Optional description">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Signature Content *</label>
                                    <div id="editor-container">
                                        <textarea name="html_content" id="html_content" style="display:none;"><?= escape($signature['html_content'] ?? '') ?></textarea>
                                    </div>
                                    <div class="form-text">
                                        Use the editor to create your signature. You can add images, links, and formatting.
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Max Image Width (px)</label>
                                        <input type="number" name="max_image_width" class="form-control" 
                                               value="<?= escape($signature['max_image_width'] ?? '600') ?>"
                                               min="100" max="1200">
                                        <div class="form-text">Recommended: 600px</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Max Image Height (px)</label>
                                        <input type="number" name="max_image_height" class="form-control" 
                                               value="<?= escape($signature['max_image_height'] ?? '200') ?>"
                                               min="50" max="400">
                                        <div class="form-text">Recommended: 200px</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                               value="1" <?= ($signature['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">
                                            Active
                                        </label>
                                        <div class="form-text">Inactive signatures cannot be used in emails</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_default" id="is_default" 
                                               value="1" <?= ($signature['is_default'] ?? 0) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_default">
                                            Set as Default Signature
                                        </label>
                                        <div class="form-text">The default signature is automatically used in all outgoing emails</div>
                                    </div>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                                    <button type="button" class="btn btn-outline-info" id="previewBtn">
                                        <i class="fas fa-eye"></i> Preview
                                    </button>
                                    <div>
                                        <a href="email_signatures_list.php" class="btn btn-secondary me-2">Cancel</a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-check-lg"></i> Save Signature
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0"><i class="fas fa-circle-info"></i> Help</h5>
                        </div>
                        <div class="card-body">
                            <h6>Custom Fields</h6>
                            <p class="small">Use these placeholders in your signature. They will be replaced with actual values when the email is sent:</p>
                            <ul class="small">
                                <li><code>{{name}}</code> - Your name</li>
                                <li><code>{{email}}</code> - Your email address</li>
                                <li><code>{{phone}}</code> - Your phone number</li>
                                <li><code>{{business_name}}</code> - Business name</li>
                                <li><code>{{business_address}}</code> - Business address</li>
                            </ul>
                            
                            <h6 class="mt-3">Best Practices</h6>
                            <ul class="small">
                                <li>Keep signatures concise and professional</li>
                                <li>Use web-friendly fonts (Arial, Verdana, etc.)</li>
                                <li>Optimize images for email (compress before uploading)</li>
                                <li>Test in multiple email clients</li>
                                <li>Include essential contact information only</li>
                            </ul>
                            
                            <h6 class="mt-3">Security</h6>
                            <p class="small text-muted">
                                <i class="fas fa-shield-halved"></i> All HTML content is automatically sanitized to prevent XSS attacks and ensure security.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Signature Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewContent" style="border: 1px solid #dee2e6; padding: 20px; background: #f8f9fa;">
                    <!-- Preview will be inserted here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Font,
    Paragraph,
    Heading,
    Link,
    List,
    Image,
    ImageToolbar,
    ImageCaption,
    ImageStyle,
    ImageResize,
    ImageUpload,
    LinkImage,
    Table,
    TableToolbar,
    Alignment,
    HorizontalLine,
    SourceEditing
} from './js/ckeditor5/ckeditor5.js';

let editorInstance;

ClassicEditor
    .create(document.querySelector('#editor-container'), {
        plugins: [
            Essentials, Bold, Italic, Underline, Font, Paragraph, Heading, Link, List,
            Image, ImageToolbar, ImageCaption, ImageStyle, ImageResize, ImageUpload, LinkImage,
            Table, TableToolbar, Alignment, HorizontalLine, SourceEditing
        ],
        toolbar: {
            items: [
                'undo', 'redo',
                '|', 'heading',
                '|', 'bold', 'italic', 'underline',
                '|', 'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor',
                '|', 'link', 'insertImage', 'insertTable',
                '|', 'alignment', 'bulletedList', 'numberedList',
                '|', 'horizontalLine',
                '|', 'sourceEditing'
            ]
        },
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
                { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' }
            ]
        },
        image: {
            toolbar: [
                'imageStyle:inline',
                'imageStyle:block',
                '|',
                'imageResize',
                '|',
                'imageTextAlternative',
                'linkImage'
            ],
            resizeOptions: [
                {
                    name: 'imageResize:original',
                    label: 'Original',
                    value: null
                },
                {
                    name: 'imageResize:25',
                    label: '25%',
                    value: '25'
                },
                {
                    name: 'imageResize:50',
                    label: '50%',
                    value: '50'
                },
                {
                    name: 'imageResize:75',
                    label: '75%',
                    value: '75'
                }
            ]
        },
        table: {
            contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
        },
        link: {
            decorators: {
                openInNewTab: {
                    mode: 'manual',
                    label: 'Open in a new tab',
                    attributes: {
                        target: '_blank',
                        rel: 'noopener noreferrer'
                    }
                }
            }
        }
    })
    .then(editor => {
        editorInstance = editor;
        
        // Set initial content if exists
        const textarea = document.querySelector('#html_content');
        if (textarea.value) {
            editor.setData(textarea.value);
        }
        
        // Update textarea when editor content changes
        editor.model.document.on('change:data', () => {
            textarea.value = editor.getData();
        });
    })
    .catch(error => {
        console.error(error);
    });

// Preview functionality
document.getElementById('previewBtn').addEventListener('click', function() {
    const content = editorInstance.getData();
    
    // Replace custom fields with sample data for preview
    const previewContent = content
        .replace(/\{\{name\}\}/g, 'Brook Lefkowitz')
        .replace(/\{\{email\}\}/g, 'bookings@brooksdogtrainingacademy.com')
        .replace(/\{\{phone\}\}/g, '(555) 123-4567')
        .replace(/\{\{business_name\}\}/g, "Brook's Dog Training Academy")
        .replace(/\{\{business_address\}\}/g, 'Sebring, Florida');
    
    document.getElementById('previewContent').innerHTML = previewContent;
    
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();
});
</script>

<?php include '../backend/includes/footer.php'; ?>
