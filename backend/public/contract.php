<?php
/**
 * Public Contract View and Signing Page
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

$contract_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Get contract
$stmt = $conn->prepare("
    SELECT co.*, c.name as client_name, c.email as client_email
    FROM contracts co
    INNER JOIN clients c ON co.client_id = c.id
    WHERE co.id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    die("Contract not found");
}

// Check if contract is viewable
$can_sign = in_array($contract['status'], ['sent']);
$already_signed = $contract['status'] === 'signed';

// Handle signature submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sign' && $can_sign) {
    $signature_data = trim($_POST['signature_data'] ?? '');
    $client_confirmation = isset($_POST['client_confirmation']);
    
    if (!$client_confirmation) {
        $message = '<div class="alert alert-danger">You must check the confirmation box to sign.</div>';
    } elseif (empty($signature_data)) {
        $message = '<div class="alert alert-danger">Please provide your signature.</div>';
    } else {
        // Get client IP address
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        // Update contract with signature
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status = 'signed', 
                signature_data = ?, 
                signed_date = CURRENT_TIMESTAMP,
                ip_address = ?
            WHERE id = ?
        ");
        $stmt->execute([$signature_data, $ip_address, $contract_id]);
        
        $contract['status'] = 'signed';
        $already_signed = true;
        $message = '<div class="alert alert-success">Contract signed successfully! Thank you.</div>';
        
        // TODO: Send notification email to admin
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract <?= htmlspecialchars($contract['contract_number']) ?> - Brook's Dog Training Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
        }
        .contract-content {
            background: white;
            padding: 2rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            line-height: 1.8;
        }
        .signature-pad {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            background: white;
            cursor: crosshair;
        }
        .signature-pad:hover {
            border-color: #9a0073;
        }
        .bg-primary {
            background-color: #9a0073 !important;
        }
        .btn-primary {
            background-color: #9a0073;
            border-color: #9a0073;
        }
        .btn-primary:hover {
            background-color: #7a005a;
            border-color: #7a005a;
        }
        .btn-success {
            background-color: #0a9a9c;
            border-color: #0a9a9c;
        }
        .btn-success:hover {
            background-color: #088587;
            border-color: #088587;
        }
        .bg-info {
            background-color: #0a9a9c !important;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-lg">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0">
                                <i class="bi bi-file-earmark-check me-2"></i>
                                Contract <?= htmlspecialchars($contract['contract_number']) ?>
                            </h4>
                            <?php
                            $badge_classes = [
                                'draft' => 'bg-secondary',
                                'sent' => 'bg-info',
                                'signed' => 'bg-success',
                                'expired' => 'bg-danger'
                            ];
                            $display_status = $contract['status'];
                            ?>
                            <span class="badge <?= $badge_classes[$display_status] ?? 'bg-secondary' ?> fs-6">
                                <?= ucfirst($display_status) ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <?= $message ?>
                        
                        <h3 class="mb-3"><?= htmlspecialchars($contract['title']) ?></h3>
                        
                        <div class="mb-3">
                            <strong>For:</strong> <?= htmlspecialchars($contract['client_name']) ?><br>
                            <?php if ($contract['effective_date']): ?>
                                <strong>Effective Date:</strong> <?= date('F j, Y', strtotime($contract['effective_date'])) ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($contract['description']): ?>
                            <p class="text-muted mb-4"><?= htmlspecialchars($contract['description']) ?></p>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <!-- Contract Content -->
                        <div class="contract-content mb-4">
                            <?= $contract['contract_text'] ?>
                        </div>
                        
                        <?php if ($already_signed): ?>
                            <!-- Already Signed -->
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                This contract has been signed.
                            </div>
                            
                            <?php if ($contract['signature_data']): ?>
                                <div class="mt-4">
                                    <h5>Signature</h5>
                                    <img src="<?= htmlspecialchars($contract['signature_data']) ?>" 
                                         alt="Signature" class="border p-2" style="max-width: 400px;">
                                    <p class="text-muted small mt-2">
                                        <i class="bi bi-calendar-event me-1"></i>
                                        Signed on <?= date('F j, Y \a\t g:i A', strtotime($contract['signed_date'])) ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                        <?php elseif ($can_sign): ?>
                            <!-- Signature Form -->
                            <div class="mt-4">
                                <h5 class="mb-3">Sign Contract</h5>
                                <p class="text-muted">Please draw your signature below and click "Sign Contract" to proceed.</p>
                                
                                <form method="POST" id="signatureForm">
                                    <input type="hidden" name="action" value="sign">
                                    <input type="hidden" name="signature_data" id="signatureData">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Your Signature</label>
                                        <div>
                                            <canvas id="signaturePad" class="signature-pad" width="600" height="200"></canvas>
                                        </div>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSignature()">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>Clear
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="client_confirmation" id="clientConfirmation" required>
                                        <label class="form-check-label" for="clientConfirmation">
                                            I have read and agree to the terms and conditions outlined in this contract.
                                        </label>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-success btn-lg" id="signBtn">
                                            <i class="bi bi-pen me-2"></i>Sign Contract
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                This contract is not currently available for signing.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-center text-muted">
                        <small>Brook's Dog Training Academy</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Signature Pad Implementation
        const canvas = document.getElementById('signaturePad');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            let isDrawing = false;
            let lastX = 0;
            let lastY = 0;

            canvas.addEventListener('mousedown', startDrawing);
            canvas.addEventListener('mousemove', draw);
            canvas.addEventListener('mouseup', stopDrawing);
            canvas.addEventListener('mouseout', stopDrawing);

            // Touch events for mobile
            canvas.addEventListener('touchstart', handleTouchStart);
            canvas.addEventListener('touchmove', handleTouchMove);
            canvas.addEventListener('touchend', stopDrawing);

            function startDrawing(e) {
                isDrawing = true;
                const rect = canvas.getBoundingClientRect();
                lastX = e.clientX - rect.left;
                lastY = e.clientY - rect.top;
            }

            function draw(e) {
                if (!isDrawing) return;
                
                const rect = canvas.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
                ctx.lineTo(x, y);
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.stroke();

                lastX = x;
                lastY = y;
            }

            function stopDrawing() {
                isDrawing = false;
            }

            function handleTouchStart(e) {
                e.preventDefault();
                const touch = e.touches[0];
                const rect = canvas.getBoundingClientRect();
                lastX = touch.clientX - rect.left;
                lastY = touch.clientY - rect.top;
                isDrawing = true;
            }

            function handleTouchMove(e) {
                e.preventDefault();
                if (!isDrawing) return;
                
                const touch = e.touches[0];
                const rect = canvas.getBoundingClientRect();
                const x = touch.clientX - rect.left;
                const y = touch.clientY - rect.top;

                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
                ctx.lineTo(x, y);
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.stroke();

                lastX = x;
                lastY = y;
            }

            window.clearSignature = function() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            };

            // On form submit, save signature as data URL
            document.getElementById('signatureForm')?.addEventListener('submit', function(e) {
                const signatureData = canvas.toDataURL('image/png');
                document.getElementById('signatureData').value = signatureData;
                
                // Check if canvas is blank
                const blankCanvas = document.createElement('canvas');
                blankCanvas.width = canvas.width;
                blankCanvas.height = canvas.height;
                
                if (canvas.toDataURL() === blankCanvas.toDataURL()) {
                    e.preventDefault();
                    alert('Please provide your signature before signing.');
                    return false;
                }
            });
        }
    </script>
</body>
</html>
