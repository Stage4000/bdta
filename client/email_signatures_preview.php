<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/email_signature_helper.php';

requireLogin();

$id = safe_int($_GET['id'] ?? 0);

if (!$id) {
    die('Signature ID is required');
}

$signature = EmailSignatureHelper::getSignature($id);

if (!$signature) {
    die('Signature not found or inactive');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: <?= htmlspecialchars(array_string_value($signature_row, 'name')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            padding: 20px;
        }
        .preview-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .signature-preview {
            background: white;
            border: 1px solid #dee2e6;
            padding: 30px;
            margin-top: 20px;
            border-radius: 8px;
        }
        .signature-content {
            font-family: Arial, Helvetica, sans-serif;
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-eye"></i> Signature Preview: <?= htmlspecialchars(array_string_value($signature_row, 'name')) ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    <strong>Preview Mode:</strong> This shows how your signature will appear in emails with sample data.
                    Custom fields like {{name}}, {{email}}, etc. are replaced with actual values.
                </div>
                
                <div class="signature-preview">
                    <div class="signature-content">
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
</body>
</html>
