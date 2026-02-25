<?php
/**
 * Public Contract View and Signing Page
 *
 * IP Address Note (Cloudflare):
 * This site is behind Cloudflare. The real client IP is forwarded in the
 * CF-Connecting-IP header. We use this value as the authoritative client IP
 * for signature records. REMOTE_ADDR would only return the Cloudflare proxy IP.
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

/**
 * Retrieve the real client IP address.
 * Cloudflare sets CF-Connecting-IP to the original visitor's IP.
 * Fall back to REMOTE_ADDR only if that header is absent.
 */
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Handle signature submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sign' && $can_sign) {
    $typed_name      = trim($_POST['typed_name'] ?? '');
    $signature_font  = trim($_POST['signature_font'] ?? 'font-dancing');
    $client_confirmation = isset($_POST['client_confirmation']);

    $allowed_fonts = ['font-dancing', 'font-pacifico', 'font-satisfy', 'font-great-vibes', 'font-allura'];

    if (!$client_confirmation) {
        $message = '<div class="alert alert-danger">You must check the confirmation box to sign.</div>';
    } elseif (empty($typed_name)) {
        $message = '<div class="alert alert-danger">Please type your full name as your signature.</div>';
    } elseif (!in_array($signature_font, $allowed_fonts)) {
        $message = '<div class="alert alert-danger">Invalid signature style selected.</div>';
    } else {
        $ip_address = getClientIp();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $signed_at  = date('Y-m-d H:i:s'); // America/New_York already set in config

        // Update contract with signature (store typed name in signature_typed_name;
        // keep signature_data NULL for typed-only flow)
        $stmt = $conn->prepare("
            UPDATE contracts
            SET status               = 'signed',
                signature_typed_name = ?,
                signature_font       = ?,
                signature_data       = NULL,
                signed_date          = ?,
                ip_address           = ?
            WHERE id = ?
        ");
        $stmt->execute([$typed_name, $signature_font, $signed_at, $ip_address, $contract_id]);

        // Log the signature event for the audit trail
        $stmt = $conn->prepare("
            INSERT INTO contract_signature_log
                (contract_id, event_type, details, ip_address, user_agent, created_at)
            VALUES (?, 'signed', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $contract_id,
            "Contract signed electronically by \"{$typed_name}\" using style {$signature_font}.",
            $ip_address,
            $user_agent,
            $signed_at,
        ]);

        // Reload contract data
        $stmt = $conn->prepare("
            SELECT co.*, c.name as client_name, c.email as client_email
            FROM contracts co
            INNER JOIN clients c ON co.client_id = c.id
            WHERE co.id = ?
        ");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        $already_signed = true;
        $can_sign       = false;
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Contract signed successfully! Thank you.</div>';
    }
}

// Map font class → Google Font name for display
$font_labels = [
    'font-dancing'    => 'Dancing Script',
    'font-pacifico'   => 'Pacifico',
    'font-satisfy'    => 'Satisfy',
    'font-great-vibes'=> 'Great Vibes',
    'font-allura'     => 'Allura',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract <?= htmlspecialchars($contract['contract_number']) ?> - Brook's Dog Training Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Script-style fonts for signature display -->
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Pacifico&family=Satisfy&family=Great+Vibes&family=Allura&display=swap" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .contract-content {
            background: white;
            padding: 2rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            line-height: 1.8;
        }
        .bg-primary  { background-color: #9a0073 !important; }
        .btn-primary { background-color: #9a0073; border-color: #9a0073; }
        .btn-primary:hover { background-color: #7a005a; border-color: #7a005a; }
        .btn-success { background-color: #0a9a9c; border-color: #0a9a9c; }
        .btn-success:hover { background-color: #088587; border-color: #088587; }
        .bg-info     { background-color: #0a9a9c !important; }

        /* Signature font styles */
        .font-dancing     { font-family: 'Dancing Script', cursive; }
        .font-pacifico    { font-family: 'Pacifico', cursive; }
        .font-satisfy     { font-family: 'Satisfy', cursive; }
        .font-great-vibes { font-family: 'Great Vibes', cursive; }
        .font-allura      { font-family: 'Allura', cursive; }

        .sig-preview {
            font-size: 2.2rem;
            color: #1a1a2e;
            min-height: 3.5rem;
            border-bottom: 2px solid #495057;
            padding-bottom: 0.25rem;
            line-height: 1.2;
        }
        .font-option-btn {
            cursor: pointer;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 1.5rem;
            background: white;
            transition: border-color .2s;
        }
        .font-option-btn.selected,
        .font-option-btn:hover { border-color: #9a0073; background: #fdf0f9; }
        .signed-sig {
            font-size: 2.4rem;
            color: #1a1a2e;
            border-bottom: 2px solid #495057;
            display: inline-block;
            padding-bottom: 0.2rem;
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
                            <i class="fas fa-file-circle-check me-2"></i>
                            Contract <?= htmlspecialchars($contract['contract_number']) ?>
                        </h4>
                        <?php
                        $badge_classes = [
                            'draft'   => 'bg-secondary',
                            'sent'    => 'bg-info',
                            'signed'  => 'bg-success',
                            'expired' => 'bg-danger',
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
                            <i class="fas fa-check-circle me-2"></i>
                            This contract has been signed and is locked.
                        </div>

                        <?php if ($contract['signature_typed_name']): ?>
                            <div class="mt-4">
                                <h5>Electronic Signature</h5>
                                <div class="signed-sig <?= htmlspecialchars($contract['signature_font'] ?? 'font-dancing') ?>">
                                    <?= htmlspecialchars($contract['signature_typed_name']) ?>
                                </div>
                                <p class="text-muted small mt-2">
                                    <i class="fas fa-calendar-days me-1"></i>
                                    Signed on <?= date('F j, Y \a\t g:i A T', strtotime($contract['signed_date'])) ?>
                                </p>
                            </div>
                        <?php elseif ($contract['signature_data']): ?>
                            <!-- Legacy drawn signature -->
                            <div class="mt-4">
                                <h5>Signature</h5>
                                <img src="<?= htmlspecialchars($contract['signature_data']) ?>"
                                     alt="Signature" class="border p-2" style="max-width: 400px;">
                                <p class="text-muted small mt-2">
                                    <i class="fas fa-calendar-days me-1"></i>
                                    Signed on <?= date('F j, Y \a\t g:i A', strtotime($contract['signed_date'])) ?>
                                </p>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($can_sign): ?>
                        <!-- Signature Form -->
                        <div class="mt-4">
                            <h5 class="mb-3">Sign Contract</h5>

                            <div class="alert alert-warning">
                                <i class="fas fa-shield-halved me-2"></i>
                                <strong>Privacy Notice:</strong> By signing this contract, your IP address
                                (<code><?= htmlspecialchars(getClientIp()) ?></code>) and the date/time of signing
                                will be permanently recorded as part of the legal signature record.
                            </div>

                            <form method="POST" id="signatureForm" novalidate>
                                <input type="hidden" name="action" value="sign">
                                <input type="hidden" name="signature_font" id="signatureFont" value="font-dancing">

                                <!-- Typed Name -->
                                <div class="mb-4">
                                    <label for="typedName" class="form-label fw-semibold">
                                        Type your full legal name
                                    </label>
                                    <input type="text" class="form-control form-control-lg"
                                           id="typedName" name="typed_name"
                                           placeholder="Your full name"
                                           autocomplete="name"
                                           maxlength="200" required>
                                </div>

                                <!-- Font Style Selector -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Choose a signature style</label>
                                    <div class="d-flex flex-wrap gap-3" id="fontOptions">
                                        <button type="button" class="font-option-btn font-dancing selected"
                                                data-font="font-dancing" title="Dancing Script">
                                            <span id="preview-font-dancing">Your Name</span>
                                        </button>
                                        <button type="button" class="font-option-btn font-pacifico"
                                                data-font="font-pacifico" title="Pacifico">
                                            <span id="preview-font-pacifico">Your Name</span>
                                        </button>
                                        <button type="button" class="font-option-btn font-satisfy"
                                                data-font="font-satisfy" title="Satisfy">
                                            <span id="preview-font-satisfy">Your Name</span>
                                        </button>
                                        <button type="button" class="font-option-btn font-great-vibes"
                                                data-font="font-great-vibes" title="Great Vibes">
                                            <span id="preview-font-great-vibes">Your Name</span>
                                        </button>
                                        <button type="button" class="font-option-btn font-allura"
                                                data-font="font-allura" title="Allura">
                                            <span id="preview-font-allura">Your Name</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Live Preview -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Signature preview</label>
                                    <div class="sig-preview font-dancing" id="sigPreview">&nbsp;</div>
                                </div>

                                <!-- Confirmation -->
                                <div class="form-check mb-4">
                                    <input class="form-check-input" type="checkbox"
                                           name="client_confirmation" id="clientConfirmation" required>
                                    <label class="form-check-label" for="clientConfirmation">
                                        I have read and agree to the terms and conditions outlined in this contract,
                                        and I understand my IP address will be stored as part of this legal record.
                                    </label>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-success btn-lg" id="signBtn">
                                        <i class="fas fa-pen me-2"></i>Sign Contract
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-circle-info me-2"></i>
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
(function () {
    const typedNameEl  = document.getElementById('typedName');
    const sigPreviewEl = document.getElementById('sigPreview');
    const fontInput    = document.getElementById('signatureFont');
    const fontButtons  = document.querySelectorAll('.font-option-btn');

    if (!typedNameEl) return; // not on signing form

    // Update the live signature preview text
    function updatePreview() {
        const name = typedNameEl.value.trim() || '\u00a0';
        sigPreviewEl.textContent = name;

        // Also update each font-option button's preview text
        document.querySelectorAll('.font-option-btn span').forEach(function (span) {
            span.textContent = typedNameEl.value.trim() || 'Your Name';
        });
    }

    // Switch selected font
    function selectFont(btn) {
        fontButtons.forEach(function (b) { b.classList.remove('selected'); });
        btn.classList.add('selected');
        const font = btn.dataset.font;
        fontInput.value = font;
        // Update preview class
        sigPreviewEl.className = 'sig-preview ' + font;
    }

    typedNameEl.addEventListener('input', updatePreview);

    fontButtons.forEach(function (btn) {
        btn.addEventListener('click', function () { selectFont(btn); });
    });

    // Validate before submit
    document.getElementById('signatureForm').addEventListener('submit', function (e) {
        const name = typedNameEl.value.trim();
        if (!name) {
            e.preventDefault();
            typedNameEl.classList.add('is-invalid');
            typedNameEl.focus();
            return false;
        }
        typedNameEl.classList.remove('is-invalid');
    });
})();
</script>
</body>
</html>
