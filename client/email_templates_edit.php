<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$template = null;

if ($id) {
    $stmt = $conn->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        $_SESSION['error'] = "Template not found";
        header('Location: email_templates_list.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $template_type = $_POST['template_type'];
    $subject = trim($_POST['subject']);
    $body_html = trim($_POST['body_html']);
    $body_text = trim($_POST['body_text']);
    $variables = trim($_POST['variables']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE email_templates SET name = ?, template_type = ?, subject = ?, body_html = ?, body_text = ?, variables = ?, is_active = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$name, $template_type, $subject, $body_html, $body_text, $variables, $is_active, date('Y-m-d H:i:s'), $id]);
            $_SESSION['success'] = "Template updated successfully!";
        } else {
            $stmt = $conn->prepare("INSERT INTO email_templates (name, template_type, subject, body_html, body_text, variables, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $template_type, $subject, $body_html, $body_text, $variables, $is_active, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $_SESSION['success'] = "Template created successfully!";
        }
        
        header('Location: email_templates_list.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "Error saving template: " . $e->getMessage();
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i class="fas fa-envelope me-2"></i><?php echo $id ? 'Edit' : 'Create'; ?> Email Template</h2>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                        <i class="fas fa-eye"></i> Preview Email
                    </button>
                    <a href="email_templates_list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Templates
                    </a>
                </div>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Template Name *</label>
                                    <input type="text" name="name" class="form-control" required 
                                           value="<?php echo htmlspecialchars($template['name'] ?? ''); ?>"
                                           placeholder="e.g., Booking Confirmation Email">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Template Type *</label>
                                    <select name="template_type" class="form-select" required>
                                        <option value="">Select type...</option>
                                        <option value="booking_confirmation" <?php echo ($template['template_type'] ?? '') === 'booking_confirmation' ? 'selected' : ''; ?>>Booking Confirmation</option>
                                        <option value="booking_reminder" <?php echo ($template['template_type'] ?? '') === 'booking_reminder' ? 'selected' : ''; ?>>Booking Reminder</option>
                                        <option value="booking_cancellation" <?php echo ($template['template_type'] ?? '') === 'booking_cancellation' ? 'selected' : ''; ?>>Booking Cancellation</option>
                                        <option value="payment_receipt" <?php echo ($template['template_type'] ?? '') === 'payment_receipt' ? 'selected' : ''; ?>>Payment Receipt</option>
                                        <option value="contract_request" <?php echo ($template['template_type'] ?? '') === 'contract_request' ? 'selected' : ''; ?>>Contract Request</option>
                                        <option value="form_request" <?php echo ($template['template_type'] ?? '') === 'form_request' ? 'selected' : ''; ?>>Form Request</option>
                                        <option value="quote_notification" <?php echo ($template['template_type'] ?? '') === 'quote_notification' ? 'selected' : ''; ?>>Quote Notification</option>
                                        <option value="admin_notification" <?php echo ($template['template_type'] ?? '') === 'admin_notification' ? 'selected' : ''; ?>>Admin Notification</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Email Subject *</label>
                                    <input type="text" name="subject" class="form-control" required 
                                           value="<?php echo htmlspecialchars($template['subject'] ?? ''); ?>"
                                           placeholder="e.g., Your Booking Confirmation - {{appointment_date}}">
                                    <small class="text-muted">Use variables like {{client_name}}, {{appointment_date}}</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Email Body (HTML) *</label>
                                    <textarea name="body_html" id="body_html" class="form-control" rows="12" required 
                                              style="font-family: monospace;"><?php echo htmlspecialchars($template['body_html'] ?? ''); ?></textarea>
                                    <small class="text-muted">HTML content with variable support</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Plain Text Version</label>
                                    <textarea name="body_text" class="form-control" rows="8" 
                                              style="font-family: monospace;"><?php echo htmlspecialchars($template['body_text'] ?? ''); ?></textarea>
                                    <small class="text-muted">Plain text fallback (optional, will use HTML if empty)</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Variables Used</label>
                                    <input type="text" name="variables" class="form-control" 
                                           value="<?php echo htmlspecialchars($template['variables'] ?? ''); ?>"
                                           placeholder="e.g., client_name, appointment_date, booking_link">
                                    <small class="text-muted">Comma-separated list for reference</small>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="is_active" id="isActive" 
                                               <?php echo ($template['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="isActive">
                                            Active (use this template for emails)
                                        </label>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check-circle"></i> <?php echo $id ? 'Update' : 'Create'; ?> Template
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Available Variables</h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">Use these variables in your template:</p>
                            
                            <h6 class="small mb-2">Client Variables:</h6>
                            <ul class="small">
                                <li><code>{{client_name}}</code></li>
                                <li><code>{{client_email}}</code></li>
                                <li><code>{{client_phone}}</code></li>
                            </ul>
                            
                            <h6 class="small mb-2">Appointment Variables:</h6>
                            <ul class="small">
                                <li><code>{{appointment_date}}</code></li>
                                <li><code>{{appointment_time}}</code></li>
                                <li><code>{{appointment_type}}</code></li>
                                <li><code>{{duration}}</code></li>
                                <li><code>{{appointment_location}}</code> — resolves to the appointment address, or <code>Video call: &lt;url&gt;</code>, <code>Phone call — you will call us</code>, <code>Phone call — we will call you</code>, etc.</li>
                            </ul>
                            
                            <h6 class="small mb-2">Link Variables:</h6>
                            <ul class="small">
                                <li><code>{{booking_link}}</code></li>
                                <li><code>{{invoice_link}}</code></li>
                                <li><code>{{contract_link}}</code></li>
                                <li><code>{{quote_link}}</code></li>
                                <li><code>{{form_link}}</code></li>
                            </ul>

                            <h6 class="small mb-2">Calendar Variables <span class="text-muted">(booking emails only)</span>:</h6>
                            <ul class="small">
                                <li><code>{{google_calendar_link}}</code> — URL to add the appointment to Google Calendar</li>
                                <li><code>{{ical_link}}</code> — URL to download an iCal (.ics) file (works with Apple Calendar, Outlook, etc.)</li>
                            </ul>
                            
                            <h6 class="small mb-2">Business Variables:</h6>
                            <ul class="small">
                                <li><code>{{business_name}}</code></li>
                                <li><code>{{business_email}}</code></li>
                                <li><code>{{business_phone}}</code></li>
                            </ul>
                            
                            <h6 class="small mb-2 mt-3">Email Signature:</h6>
                            <ul class="small">
                                <li><code>{{signature}}</code> - Inserts the default email signature</li>
                                <li><code>{{signature:ID}}</code> - Inserts a specific signature by ID</li>
                            </ul>
                            <p class="small text-muted">
                                <i class="fas fa-info-circle"></i> The default signature is set in 
                                <a href="email_signatures_list.php" target="_blank">Email Signatures</a>. 
                                To use a specific signature, replace ID with the signature's ID number (e.g., <code>{{signature:1}}</code>).
                            </p>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">Example Template</h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">
                                Your template body is automatically wrapped in a
                                professional email container matching the system default
                                emails — no extra HTML or CSS required.
                            </p>
                            <pre class="small" style="font-size: 11px;"><code>&lt;p&gt;Hi {{client_name}},&lt;/p&gt;

&lt;p&gt;Your appointment is confirmed!&lt;/p&gt;

&lt;div class="details-box"&gt;
  &lt;p&gt;&lt;strong&gt;Service:&lt;/strong&gt; {{appointment_type}}&lt;/p&gt;
  &lt;p&gt;&lt;strong&gt;Date:&lt;/strong&gt; {{appointment_date}}&lt;/p&gt;
  &lt;p&gt;&lt;strong&gt;Time:&lt;/strong&gt; {{appointment_time}}&lt;/p&gt;
  &lt;p&gt;&lt;strong&gt;Duration:&lt;/strong&gt; {{duration}} minutes&lt;/p&gt;
  &lt;p&gt;&lt;strong&gt;Location:&lt;/strong&gt; {{appointment_location}}&lt;/p&gt;
&lt;/div&gt;

&lt;p&gt;
  &lt;a href="{{google_calendar_link}}" class="button"&gt;
    &#128197; Add to Google Calendar
  &lt;/a&gt;
  &lt;a href="{{ical_link}}" class="button button-secondary"&gt;
    &#128242; Download iCal File
  &lt;/a&gt;
&lt;/p&gt;

&lt;p&gt;&lt;a href="{{booking_link}}"&gt;View Booking&lt;/a&gt;&lt;/p&gt;

&lt;p&gt;Thanks,&lt;br&gt;
{{business_name}}&lt;/p&gt;

{{signature}}</code></pre>
                            <p class="small text-muted mt-2 mb-0">
                                <i class="fas fa-circle-info"></i>
                                Use <code>class="details-box"</code> on a <code>&lt;div&gt;</code>
                                for a highlighted details panel, and
                                <code>class="button"</code> / <code>class="button button-secondary"</code>
                                on links for styled call-to-action buttons — these match
                                the built-in email styles.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewModalLabel">
                    <i class="fas fa-eye me-2"></i>Email Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="previewLoading" class="text-center py-5 d-none">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Rendering preview…</p>
                </div>
                <div id="previewError" class="alert alert-danger m-3 d-none"></div>
                <iframe id="previewIframe"
                        style="width:100%; height:600px; border:none;"
                        title="Email preview"
                        sandbox="allow-same-origin"></iframe>
            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto">
                    <i class="fas fa-circle-info"></i>
                    This preview shows how the email will look when delivered to recipients,
                    with the standard styling applied. Variable placeholders (e.g.
                    <code>{{client_name}}</code>) are shown as-is until the email is sent.
                </small>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- CKEditor 5 Rich Text Editor (Self-Hosted, GPL License) - Email Preset -->
<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />
<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Paragraph,
    Heading,
    Link,
    List,
    Alignment,
    SourceEditing,
    GeneralHtmlSupport
} from './js/ckeditor5/ckeditor5.js';

// Initialize CKEditor 5 for email HTML editor (email-optimized preset)
ClassicEditor
    .create(document.querySelector('#body_html'), {
        licenseKey: 'GPL',
        plugins: [
            Essentials, Bold, Italic, Underline,
            Paragraph, Heading, Link, List,
            Alignment, SourceEditing, GeneralHtmlSupport
        ],
        toolbar: [
            'undo', 'redo', '|',
            'heading', '|',
            'bold', 'italic', 'underline', '|',
            'link', '|',
            'bulletedList', 'numberedList', '|',
            'alignment', '|',
            'sourceEditing'
        ],
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
            ]
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
        window.emailEditor = editor;
        // Sync with textarea on change
        editor.model.document.on('change:data', () => {
            document.querySelector('#body_html').value = editor.getData();
        });
    })
    .catch(error => {
        console.error('CKEditor initialization error:', error);
    });
</script>

<script>
document.getElementById('previewBtn').addEventListener('click', async () => {
    const html = window.emailEditor
        ? window.emailEditor.getData()
        : document.getElementById('body_html').value;

    const loading  = document.getElementById('previewLoading');
    const errorDiv = document.getElementById('previewError');
    const iframe   = document.getElementById('previewIframe');

    loading.classList.remove('d-none');
    errorDiv.classList.add('d-none');
    iframe.srcdoc = '';

    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();

    try {
        const response = await fetch('email_templates_api.php?action=preview_styled', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ body_html: html })
        });
        const data = await response.json();

        loading.classList.add('d-none');

        if (data.success) {
            iframe.srcdoc = data.html;
        } else {
            errorDiv.textContent = data.error || 'Failed to render preview.';
            errorDiv.classList.remove('d-none');
        }
    } catch (err) {
        loading.classList.add('d-none');
        errorDiv.textContent = 'Network error: ' + err.message;
        errorDiv.classList.remove('d-none');
    }
});
</script>

<?php include '../backend/includes/footer.php'; ?>
