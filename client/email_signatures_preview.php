<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/email_signature_helper.php';

requireLogin();

$id = safe_int($_GET['id'] ?? 0);

if (!$id) {
    setFlashMessage('Signature ID is required.', 'danger');
    redirect('email_signatures_list.php');
}

$signature = EmailSignatureHelper::getSignature($id);

if (!$signature) {
    setFlashMessage('Signature not found or inactive.', 'danger');
    redirect('email_signatures_list.php');
}

// Render signature with sample data
$sample_data = [
    'name' => 'Brook Lefkowitz',
    'email' => 'bookings@brooksdogtrainingacademy.com',
    'phone' => '(555) 123-4567',
    'business_name' => "Brook's Dog Training Academy",
    'business_address' => 'Sebring, Florida'
];

$signature_row = $signature;
$rendered_html = EmailSignatureHelper::replaceCustomFields(array_string_value($signature_row, 'html_content'), $sample_data);
$page_title = 'Preview Email Signature';

include '../backend/includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="mx-auto" style="max-width: 800px;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0">
                        <i class="fas fa-eye me-2"></i> Signature Preview: <?= htmlspecialchars(array_string_value($signature_row, 'name')) ?>
                    </h2>
                    <a href="email_signatures_list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Signatures
                    </a>
                </div>

                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-envelope"></i> Email Preview
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-circle-info"></i>
                            <strong>Preview Mode:</strong> This shows how your signature will appear in emails with sample data.
                            Custom fields like {{name}}, {{email}}, etc. are replaced with actual values.
                        </div>
                        
                        <div class="bg-white border rounded-3 p-4 p-md-5 mt-4">
                            <div style="font-family: Arial, Helvetica, sans-serif;">
                                <?= $rendered_html ?>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h6>Signature Details:</h6>
                            <ul>
                                <li><strong>Name:</strong> <?= htmlspecialchars(array_string_value($signature_row, 'name')) ?></li>
                                <li><strong>Status:</strong> <?= array_int_value($signature_row, 'is_active') === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></li>
                                <li><strong>Default:</strong> <?= array_int_value($signature_row, 'is_default') === 1 ? '<span class="badge bg-primary">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></li>
                                <li><strong>Max Image Width:</strong> <?= array_int_value($signature_row, 'max_image_width') ?>px</li>
                                <li><strong>Max Image Height:</strong> <?= array_int_value($signature_row, 'max_image_height') ?>px</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <a href="email_signatures_edit.php?id=<?= $id ?>" class="btn btn-primary">
                                <i class="fas fa-pencil"></i> Edit Signature
                            </a>
                            <a href="email_signatures_export.php?id=<?= $id ?>" class="btn btn-outline-secondary" target="_blank">
                                <i class="fas fa-download"></i> Export as HTML
                            </a>
                            <button onclick="window.close()" class="btn btn-secondary">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
