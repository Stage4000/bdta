<?php
/**
 * Email Configuration Test Script
 * 
 * This script allows you to test your email configuration without
 * needing to create actual bookings or trigger other system events.
 * 
 * SECURITY: This file should be deleted or moved outside the web root
 * after testing is complete.
 */

// Only allow access from localhost for security
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
    die('Access denied. This test script can only be run from localhost.');
}

require_once '../includes/config.php';
require_once '../includes/email_service.php';
require_once '../includes/settings.php';

$result = null;
$test_email = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $test_email = filter_var($_POST['test_email'], FILTER_VALIDATE_EMAIL);
    
    if ($test_email) {
        $emailService = new EmailService();
        
        $subject = "Test Email from BDTA - " . date('Y-m-d H:i:s');
        $html_body = "
            <html>
            <body style='font-family: Arial, sans-serif; padding: 20px;'>
                <h2 style='color: #9a0073;'>🎉 Email Configuration Test Successful!</h2>
                <p>If you're reading this, your email configuration is working correctly.</p>
                <hr style='border: 1px solid #ddd; margin: 20px 0;'>
                <h3>Configuration Details:</h3>
                <ul>
                    <li><strong>Email Service:</strong> " . Settings::get('email_service', 'mail') . "</li>
                    <li><strong>From Address:</strong> " . Settings::get('email_from_address', 'N/A') . "</li>
                    <li><strong>From Name:</strong> " . Settings::get('email_from_name', 'N/A') . "</li>
                    <li><strong>Test Time:</strong> " . date('Y-m-d H:i:s') . "</li>
                </ul>
                <p style='color: #666; margin-top: 30px;'>
                    <small>This is an automated test email from Brook's Dog Training Academy CRM.</small>
                </p>
            </body>
            </html>
        ";
        
        $text_body = "
EMAIL CONFIGURATION TEST SUCCESSFUL!

If you're reading this, your email configuration is working correctly.

Configuration Details:
- Email Service: " . Settings::get('email_service', 'mail') . "
- From Address: " . Settings::get('email_from_address', 'N/A') . "
- From Name: " . Settings::get('email_from_name', 'N/A') . "
- Test Time: " . date('Y-m-d H:i:s') . "

This is an automated test email from Brook's Dog Training Academy CRM.
        ";
        
        $result = $emailService->sendGenericEmail($test_email, $subject, $html_body, $text_body);
    } else {
        $result = [
            'success' => false,
            'message' => 'Invalid email address'
        ];
    }
}

// Get current email configuration
$email_config = Settings::getEmailConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Configuration Test - BDTA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            min-height: 100vh;
            padding: 40px 0;
        }
        .test-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .card-header {
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
        }
        .config-item {
            background: #f8f9fa;
            padding: 10px 15px;
            margin: 5px 0;
            border-radius: 5px;
            border-left: 3px solid #9a0073;
        }
        .password-masked {
            color: #999;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card test-card">
                    <div class="card-header">
                        <h3 class="mb-0">📧 Email Configuration Test</h3>
                        <small>Brook's Dog Training Academy CRM</small>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-warning">
                            <strong>⚠️ Security Notice:</strong> This test file should be deleted after testing is complete.
                        </div>
                        
                        <h5 class="mb-3">Current Email Configuration</h5>
                        <div class="config-item">
                            <strong>Email Service:</strong> 
                            <span class="badge bg-primary"><?= htmlspecialchars($email_config['service']) ?></span>
                        </div>
                        <div class="config-item">
                            <strong>From Address:</strong> <?= htmlspecialchars($email_config['from_address'] ?: 'Not configured') ?>
                        </div>
                        <div class="config-item">
                            <strong>From Name:</strong> <?= htmlspecialchars($email_config['from_name'] ?: 'Not configured') ?>
                        </div>
                        
                        <?php if ($email_config['service'] === 'smtp'): ?>
                            <div class="config-item">
                                <strong>SMTP Host:</strong> <?= htmlspecialchars($email_config['smtp_host'] ?: 'Not configured') ?>
                            </div>
                            <div class="config-item">
                                <strong>SMTP Port:</strong> <?= htmlspecialchars($email_config['smtp_port']) ?>
                            </div>
                            <div class="config-item">
                                <strong>SMTP Username:</strong> <?= htmlspecialchars($email_config['smtp_username'] ?: 'Not configured') ?>
                            </div>
                            <div class="config-item">
                                <strong>SMTP Password:</strong> 
                                <span class="password-masked"><?= $email_config['smtp_password'] ? '••••••••' : 'Not configured' ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Send Test Email</h5>
                        
                        <?php if ($result): ?>
                            <div class="alert alert-<?= $result['success'] ? 'success' : 'danger' ?>">
                                <strong><?= $result['success'] ? '✅ Success!' : '❌ Error:' ?></strong>
                                <?= htmlspecialchars($result['message']) ?>
                                
                                <?php if ($result['success']): ?>
                                    <hr>
                                    <p class="mb-0">
                                        <small>Check your inbox at <strong><?= htmlspecialchars($test_email) ?></strong></small><br>
                                        <small>If you don't see the email, check your spam/junk folder.</small>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="test_email" class="form-label">Test Email Address</label>
                                <input 
                                    type="email" 
                                    class="form-control" 
                                    id="test_email" 
                                    name="test_email" 
                                    value="<?= htmlspecialchars($test_email) ?>"
                                    placeholder="your-email@example.com"
                                    required
                                >
                                <div class="form-text">
                                    Enter your email address to receive a test email
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                📨 Send Test Email
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Troubleshooting Tips</h5>
                        <ul>
                            <li>If using SMTP, verify your credentials are correct</li>
                            <li>Check that port 587 (or 465) is not blocked by your firewall</li>
                            <li>For Gmail, make sure to use an App Password (not your main password)</li>
                            <li>For Zoho, use an App-Specific Password</li>
                            <li>Check server error logs for detailed error messages</li>
                            <li>See <code>backend/EMAIL_CONFIGURATION.md</code> for detailed setup instructions</li>
                        </ul>
                        
                        <div class="mt-4">
                            <a href="../../client/settings.php?category=email" class="btn btn-outline-primary">
                                ⚙️ Configure Email Settings
                            </a>
                            <a href="../../backend/EMAIL_CONFIGURATION.md" class="btn btn-outline-secondary" target="_blank">
                                📖 View Documentation
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
