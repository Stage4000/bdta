<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

$errors   = [];
$success  = '';

// Fetch current client data
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    redirect(PORTAL_URL . 'logout.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim(scalar_string($_POST['name'] ?? ''));
    $email   = trim(scalar_string($_POST['email'] ?? ''));
    $phone   = trim(scalar_string($_POST['phone'] ?? ''));
    $address = trim(scalar_string($_POST['address'] ?? ''));

    $new_password     = scalar_string($_POST['new_password'] ?? '');
    $confirm_password = scalar_string($_POST['confirm_password'] ?? '');
    $current_password = scalar_string($_POST['current_password'] ?? '');

    // Validate
    if (empty($name))  $errors[] = 'Name is required.';
    if (empty($email)) $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

    // Check if email changed and is already taken by another client
    if (empty($errors) && $email !== array_string_value($client, 'email')) {
        $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ? AND id != ?");
        $stmt->execute([$email, $client_id]);
        if ($stmt->fetch()) {
            $errors[] = 'That email address is already in use by another account.';
        }
    }

    $change_password = !empty($new_password) || !empty($confirm_password);
    if ($change_password) {
        if (empty($current_password)) {
            $errors[] = 'Current password is required to change your password.';
        } elseif (!password_verify($current_password, array_string_value($client, 'password_hash'))) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new_password) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        }
    }

    if (empty($errors)) {
        if ($change_password) {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, address = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $address, $hash, $client_id]);
            logClientActivity($client_id, 'password_change', 'Password changed via profile', $conn);
        } else {
            $stmt = $conn->prepare("UPDATE clients SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $email, $phone, $address, $client_id]);
        }

        logClientActivity($client_id, 'profile_update', 'Profile information updated', $conn);

        // Update session
        $_SESSION['portal_client_name']  = $name;
        $_SESSION['portal_client_email'] = $email;

        // Reload client data
        $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            redirect(PORTAL_URL . 'logout.php');
        }

        $success = 'Profile updated successfully.';
    }
}

$page_title = 'Profile';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">My Profile</h2>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?php echo escape($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo escape($success); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><strong>Personal Information</strong></div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?php echo escape($client['name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?php echo escape($client['email']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone"
                           value="<?php echo escape($client['phone'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label for="address" class="form-label">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="2"><?php echo escape($client['address'] ?? ''); ?></textarea>
                </div>
            </div>

            <hr class="my-4">
            <h5>Change Password <small class="text-muted fs-6">(leave blank to keep current)</small></h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="current_password" class="form-label">Current Password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password">
                </div>
                <div class="col-md-4">
                    <label for="new_password" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" minlength="8">
                    <small class="form-text text-muted">Minimum 8 characters.</small>
                </div>
                <div class="col-md-4">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include '../portal/includes/footer.php'; ?>
