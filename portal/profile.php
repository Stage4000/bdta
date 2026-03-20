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

$stmt = $conn->prepare("
    SELECT id, name, email, phone, is_primary
    FROM client_contacts
    WHERE client_id = ?
    ORDER BY is_primary DESC, name ASC
");
$stmt->execute([$client_id]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Additional Contacts</strong>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#contactModal" onclick="showAddContactModal()">
            <i class="fas fa-plus"></i> Add Contact
        </button>
    </div>
    <div class="card-body" id="contactsList">
        <?php if (empty($contacts)): ?>
            <p class="text-muted mb-0">No additional contacts</p>
        <?php else: ?>
            <?php foreach ($contacts as $contact): ?>
                <div class="border-bottom pb-2 mb-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?php echo escape($contact['name']); ?></strong>
                            <?php if (!empty($contact['is_primary'])): ?>
                                <span class="badge bg-primary ms-1">Primary</span>
                            <?php endif; ?>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-envelope"></i>
                                <a href="mailto:<?php echo escape($contact['email']); ?>"><?php echo escape($contact['email']); ?></a>
                            </small>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-phone"></i> <?php echo escape($contact['phone']); ?>
                            </small>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary edit-contact-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#contactModal"
                                    data-contact-id="<?php echo (int)$contact['id']; ?>"
                                    data-contact-name="<?php echo escape($contact['name']); ?>"
                                    data-contact-email="<?php echo escape($contact['email']); ?>"
                                    data-contact-phone="<?php echo escape($contact['phone']); ?>"
                                    data-contact-primary="<?php echo !empty($contact['is_primary']) ? 1 : 0; ?>">
                                <i class="fas fa-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger delete-contact-btn"
                                    data-contact-id="<?php echo (int)$contact['id']; ?>"
                                    data-contact-name="<?php echo escape($contact['name']); ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contactModalLabel" aria-live="polite"><i class="fas fa-user-plus" aria-hidden="true"></i> Add Contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="contactForm">
                    <div class="mb-3">
                        <label for="contactName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="contactName" required>
                    </div>
                    <div class="mb-3">
                        <label for="contactEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="contactEmail" required>
                    </div>
                    <div class="mb-3">
                        <label for="contactPhone" class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="contactPhone" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="contactPrimary">
                        <label class="form-check-label" for="contactPrimary">Set as primary contact</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveContact()">Save Contact</button>
            </div>
        </div>
    </div>
</div>

<script>
let editingContactId = null;

document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-contact-btn')) {
            const btn = e.target.closest('.edit-contact-btn');
            editContact(
                btn.dataset.contactId,
                btn.dataset.contactName,
                btn.dataset.contactEmail,
                btn.dataset.contactPhone,
                btn.dataset.contactPrimary
            );
        }

        if (e.target.closest('.delete-contact-btn')) {
            const btn = e.target.closest('.delete-contact-btn');
            deleteContact(btn.dataset.contactId, btn.dataset.contactName);
        }
    });
});

function showAddContactModal() {
    editingContactId = null;
    document.getElementById('contactModalLabel').textContent = 'Add Contact';
    document.getElementById('contactForm').reset();
}

function editContact(id, name, email, phone, isPrimary) {
    editingContactId = id;
    document.getElementById('contactModalLabel').textContent = 'Edit Contact';
    document.getElementById('contactName').value = name;
    document.getElementById('contactEmail').value = email;
    document.getElementById('contactPhone').value = phone;
    document.getElementById('contactPrimary').checked = isPrimary == 1;
}

function saveContact() {
    const name = document.getElementById('contactName').value.trim();
    const email = document.getElementById('contactEmail').value.trim();
    const phone = document.getElementById('contactPhone').value.trim();
    const isPrimary = document.getElementById('contactPrimary').checked ? 1 : 0;

    if (!name || !email || !phone) {
        alert('Please fill in all required fields');
        return;
    }

    const url = editingContactId
        ? `client_contacts_api.php?action=update&id=${editingContactId}`
        : 'client_contacts_api.php?action=add';

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            name: name,
            email: email,
            phone: phone,
            is_primary: isPrimary
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error saving contact: ' + error);
    });
}

function deleteContact(id, name) {
    if (!confirm('Are you sure you want to delete contact: ' + name + '?')) {
        return;
    }

    fetch(`client_contacts_api.php?action=delete&id=${id}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error deleting contact: ' + error);
    });
}
</script>

<?php include '../portal/includes/footer.php'; ?>
