<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$template = null;

if ($id) {
    $template = $conn->query("SELECT * FROM email_templates WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
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
            $stmt = $conn->prepare("UPDATE email_templates SET name = ?, template_type = ?, subject = ?, body_html = ?, body_text = ?, variables = ?, is_active = ?, updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$name, $template_type, $subject, $body_html, $body_text, $variables, $is_active, $id]);
            $_SESSION['success'] = "Template updated successfully!";
        } else {
            $stmt = $conn->prepare("INSERT INTO email_templates (name, template_type, subject, body_html, body_text, variables, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))");
            $stmt->execute([$name, $template_type, $subject, $body_html, $body_text, $variables, $is_active]);
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
                <h1 class="h3 mb-0"><?php echo $id ? 'Edit' : 'Create'; ?> Email Template</h1>
                <a href="email_templates_list.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Templates
                </a>
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
                                    <textarea name="body_html" class="form-control" rows="12" required 
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
                                    <i class="bi bi-check-circle"></i> <?php echo $id ? 'Update' : 'Create'; ?> Template
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
                            </ul>
                            
                            <h6 class="small mb-2">Link Variables:</h6>
                            <ul class="small">
                                <li><code>{{booking_link}}</code></li>
                                <li><code>{{invoice_link}}</code></li>
                                <li><code>{{contract_link}}</code></li>
                                <li><code>{{quote_link}}</code></li>
                                <li><code>{{form_link}}</code></li>
                            </ul>
                            
                            <h6 class="small mb-2">Business Variables:</h6>
                            <ul class="small">
                                <li><code>{{business_name}}</code></li>
                                <li><code>{{business_email}}</code></li>
                                <li><code>{{business_phone}}</code></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0">Example Template</h6>
                        </div>
                        <div class="card-body">
                            <pre class="small" style="font-size: 11px;"><code>&lt;p&gt;Hi {{client_name}},&lt;/p&gt;

&lt;p&gt;Your appointment is confirmed!&lt;/p&gt;

&lt;p&gt;&lt;strong&gt;Details:&lt;/strong&gt;&lt;br&gt;
Date: {{appointment_date}}&lt;br&gt;
Time: {{appointment_time}}&lt;br&gt;
Type: {{appointment_type}}&lt;br&gt;
Duration: {{duration}} minutes&lt;/p&gt;

&lt;p&gt;&lt;a href="{{booking_link}}"&gt;View Booking&lt;/a&gt;&lt;/p&gt;

&lt;p&gt;Thanks,&lt;br&gt;
{{business_name}}&lt;/p&gt;</code></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
