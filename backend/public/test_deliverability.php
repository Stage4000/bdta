<?php
/**
 * Email Deliverability Test Script
 * 
 * This script sends a test email to ping@tools.mxtoolbox.com to test email deliverability.
 * MXToolbox will analyze the email and provide a deliverability report.
 * 
 * USAGE:
 * 1. Configure your email settings in Admin Panel → Settings → Email
 * 2. Run this script from command line: php test_deliverability.php
 *    OR access it via browser: http://yoursite.com/backend/public/test_deliverability.php
 * 3. Check MXToolbox for deliverability results
 * 
 * SECURITY: This file should be deleted after testing is complete or restricted to localhost.
 */

// Allow access from localhost only for web access
if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
    die('Access denied. This test script can only be run from localhost or command line.');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/email_service.php';
require_once __DIR__ . '/../includes/settings.php';

$result = null;
$is_cli = php_sapi_name() === 'cli';

// Handle test execution
if ($is_cli || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test']))) {
    $emailService = new EmailService();
    
    // MXToolbox ping address
    $to = 'ping@tools.mxtoolbox.com';
    $subject = 'Email Deliverability Test - Brook\'s Dog Training Academy - ' . date('Y-m-d H:i:s');
    
    // Create HTML email body
    $html_body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #9a0073; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #0a9a9c; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🐕 Email Deliverability Test</h1>
        </div>
        <div class="content">
            <h2>Test Email from Brook\'s Dog Training Academy</h2>
            
            <div class="info-box">
                <h3>Email Configuration Details</h3>
                <p><strong>Email Service:</strong> ' . htmlspecialchars(Settings::get('email_service', 'mail')) . '</p>
                <p><strong>From Address:</strong> ' . htmlspecialchars(Settings::get('email_from_address', 'N/A')) . '</p>
                <p><strong>From Name:</strong> ' . htmlspecialchars(Settings::get('email_from_name', 'N/A')) . '</p>
                <p><strong>Test Time:</strong> ' . date('Y-m-d H:i:s T') . '</p>
                <p><strong>Purpose:</strong> Testing email deliverability with MXToolbox</p>
            </div>
            
            <h3>About This Test</h3>
            <p>This email is being sent to MXToolbox\'s ping service to test email deliverability. 
            MXToolbox will analyze this email and provide a report on:</p>
            <ul>
                <li>SPF (Sender Policy Framework) validation</li>
                <li>DKIM (DomainKeys Identified Mail) signature</li>
                <li>DMARC (Domain-based Message Authentication) policy</li>
                <li>Blacklist status of sending server</li>
                <li>Email headers and authentication</li>
                <li>Overall deliverability score</li>
            </ul>
            
            <h3>System Information</h3>
            <p><strong>Application:</strong> Brook\'s Dog Training Academy CRM</p>
            <p><strong>Website:</strong> https://brooksdogtrainingacademy.com</p>
            <p><strong>Tagline:</strong> "Teaching Humans to Speak Dog"</p>
            
            <p style="margin-top: 30px;">
                If you\'re seeing this in MXToolbox, the email was successfully delivered. 
                Check the deliverability report for detailed analysis.
            </p>
        </div>
        <div class="footer">
            <p>© 2024 Brook\'s Dog Training Academy</p>
            <p>This is an automated test email for deliverability testing.</p>
        </div>
    </div>
</body>
</html>
    ';
    
    // Create plain text alternative
    $text_body = '
EMAIL DELIVERABILITY TEST
Brook\'s Dog Training Academy

This is a test email to verify email deliverability using MXToolbox\'s ping service.

Email Configuration Details:
----------------------------
Email Service: ' . Settings::get('email_service', 'mail') . '
From Address: ' . Settings::get('email_from_address', 'N/A') . '
From Name: ' . Settings::get('email_from_name', 'N/A') . '
Test Time: ' . date('Y-m-d H:i:s T') . '
Purpose: Testing email deliverability with MXToolbox

About This Test:
---------------
This email is being sent to MXToolbox\'s ping service to test email deliverability.
MXToolbox will analyze this email and provide a report on:
- SPF (Sender Policy Framework) validation
- DKIM (DomainKeys Identified Mail) signature
- DMARC (Domain-based Message Authentication) policy
- Blacklist status of sending server
- Email headers and authentication
- Overall deliverability score

System Information:
------------------
Application: Brook\'s Dog Training Academy CRM
Website: https://brooksdogtrainingacademy.com
Tagline: "Teaching Humans to Speak Dog"

If you\'re seeing this in MXToolbox, the email was successfully delivered.
Check the deliverability report for detailed analysis.

---
© 2024 Brook\'s Dog Training Academy
This is an automated test email for deliverability testing.
    ';
    
    // Send the email
    $result = $emailService->sendGenericEmail($to, $subject, $html_body, $text_body);
    
    // CLI output
    if ($is_cli) {
        echo "\n===========================================\n";
        echo "Email Deliverability Test\n";
        echo "===========================================\n\n";
        
        if ($result['success']) {
            echo "✅ SUCCESS: Email sent to ping@tools.mxtoolbox.com\n\n";
            echo "Next Steps:\n";
            echo "1. Visit https://mxtoolbox.com/EmailHealth.aspx\n";
            echo "2. Enter the email address you configured: " . Settings::get('email_from_address', 'N/A') . "\n";
            echo "3. Check the deliverability report\n\n";
            echo "Email Configuration:\n";
            echo "- Service: " . Settings::get('email_service', 'mail') . "\n";
            echo "- From: " . Settings::get('email_from_address', 'N/A') . "\n";
            echo "- Name: " . Settings::get('email_from_name', 'N/A') . "\n";
        } else {
            echo "❌ ERROR: Failed to send email\n\n";
            echo "Error Message: " . $result['message'] . "\n\n";
            echo "Troubleshooting:\n";
            echo "1. Check your email settings in Admin Panel → Settings → Email\n";
            echo "2. Verify SMTP credentials if using SMTP\n";
            echo "3. Check server error logs for detailed error messages\n";
            echo "4. See backend/EMAIL_CONFIGURATION.md for help\n";
        }
        
        echo "\n===========================================\n\n";
        exit($result['success'] ? 0 : 1);
    }
}

// HTML interface for browser access (only if not CLI)
if (!$is_cli):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Deliverability Test - BDTA</title>
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
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card test-card">
                    <div class="card-header">
                        <h3 class="mb-0">📧 Email Deliverability Test</h3>
                        <small>Test email delivery to MXToolbox</small>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-info">
                            <strong>ℹ️ About This Test:</strong> This script sends a test email to 
                            <code>ping@tools.mxtoolbox.com</code>. MXToolbox will analyze your email 
                            for deliverability issues including SPF, DKIM, DMARC, and blacklist status.
                        </div>
                        
                        <div class="alert alert-warning">
                            <strong>⚠️ Security Notice:</strong> This test file should be deleted after testing 
                            or restricted to localhost access only.
                        </div>
                        
                        <?php if ($result): ?>
                            <div class="alert alert-<?= $result['success'] ? 'success' : 'danger' ?>">
                                <strong><?= $result['success'] ? '✅ Success!' : '❌ Error:' ?></strong>
                                <?= htmlspecialchars($result['message']) ?>
                                
                                <?php if ($result['success']): ?>
                                    <hr>
                                    <h5>Next Steps:</h5>
                                    <ol>
                                        <li>Visit <a href="https://mxtoolbox.com/EmailHealth.aspx" target="_blank">MXToolbox Email Health</a></li>
                                        <li>Enter your email address: <code><?= htmlspecialchars(Settings::get('email_from_address', 'N/A')) ?></code></li>
                                        <li>Review the deliverability report and recommendations</li>
                                    </ol>
                                    <p class="mb-0">
                                        <small>The email was sent to <code>ping@tools.mxtoolbox.com</code> at <?= date('H:i:s') ?></small>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <h5 class="mb-3">Current Email Configuration</h5>
                        <?php
                        $email_config = Settings::getEmailConfig();
                        ?>
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
                        <?php endif; ?>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Send Deliverability Test</h5>
                        <p>Click the button below to send a test email to MXToolbox's ping service.</p>
                        
                        <form method="POST">
                            <input type="hidden" name="send_test" value="1">
                            <button type="submit" class="btn btn-primary btn-lg">
                                📨 Send Test Email to MXToolbox
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">What MXToolbox Will Check</h5>
                        <ul>
                            <li><strong>SPF Record:</strong> Verifies your domain authorizes your mail server to send emails</li>
                            <li><strong>DKIM Signature:</strong> Checks if your emails are cryptographically signed</li>
                            <li><strong>DMARC Policy:</strong> Validates your domain's email authentication policy</li>
                            <li><strong>Blacklist Status:</strong> Checks if your mail server is on any blacklists</li>
                            <li><strong>Email Headers:</strong> Analyzes email headers for proper configuration</li>
                            <li><strong>Spam Score:</strong> Evaluates likelihood of email being marked as spam</li>
                        </ul>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Troubleshooting</h5>
                        <ul>
                            <li>Ensure email settings are properly configured in Admin Panel</li>
                            <li>For SMTP, verify host, port, and credentials are correct</li>
                            <li>Check that your domain has proper SPF, DKIM, and DMARC records</li>
                            <li>Review server error logs for detailed error messages</li>
                            <li>See <code>backend/EMAIL_CONFIGURATION.md</code> for detailed setup instructions</li>
                        </ul>
                        
                        <div class="mt-4">
                            <a href="../../client/settings.php?category=email" class="btn btn-outline-primary">
                                ⚙️ Configure Email Settings
                            </a>
                            <a href="https://mxtoolbox.com/EmailHealth.aspx" class="btn btn-outline-success" target="_blank">
                                🔍 View MXToolbox Results
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <p class="text-white">
                        <small>
                            You can also run this test from command line:<br>
                            <code style="background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 5px;">
                                php /path/to/bdta/backend/public/test_deliverability.php
                            </code>
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php endif; ?>
