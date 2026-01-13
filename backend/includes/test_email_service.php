<?php
/**
 * Test script to verify email functionality
 * This tests the email service with different configurations
 */

// Prevent direct access from web
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../email_service.php';
require_once __DIR__ . '/../settings.php';

echo "\n=== BDTA Email Service Test ===\n\n";

// Test 1: Check if email settings exist
echo "Test 1: Checking email settings configuration...\n";
$email_config = [
    'email_service' => Settings::get('email_service', 'mail'),
    'smtp_host' => Settings::get('smtp_host', ''),
    'smtp_port' => Settings::get('smtp_port', 587),
    'smtp_encryption' => Settings::get('smtp_encryption', 'tls'),
    'smtp_username' => Settings::get('smtp_username', ''),
    'smtp_password' => !empty(Settings::get('smtp_password', '')) ? '****' : '(empty)',
    'smtp_debug' => Settings::get('smtp_debug', false) ? 'enabled' : 'disabled',
    'email_from_address' => Settings::get('email_from_address', ''),
    'email_from_name' => Settings::get('email_from_name', '')
];

echo "Current configuration:\n";
foreach ($email_config as $key => $value) {
    echo "  $key: $value\n";
}

// Test 2: Validate SMTP configuration
echo "\nTest 2: Validating SMTP configuration...\n";
$issues = [];

if ($email_config['email_service'] === 'smtp') {
    if (empty($email_config['smtp_host'])) {
        $issues[] = "SMTP Host is empty";
    }
    
    if (!in_array($email_config['smtp_encryption'], ['tls', 'ssl', 'none'])) {
        $issues[] = "Invalid encryption type: " . $email_config['smtp_encryption'];
    }
    
    if ($email_config['smtp_port'] !== 587 && $email_config['smtp_port'] !== 465 && $email_config['smtp_port'] !== 25) {
        echo "  Warning: Uncommon SMTP port " . $email_config['smtp_port'] . "\n";
    }
    
    if ($email_config['smtp_encryption'] === 'tls' && $email_config['smtp_port'] === 465) {
        echo "  Warning: Port 465 typically uses SSL, not TLS\n";
    }
    
    if ($email_config['smtp_encryption'] === 'ssl' && $email_config['smtp_port'] === 587) {
        echo "  Warning: Port 587 typically uses TLS, not SSL\n";
    }
}

if (empty($issues)) {
    echo "  ✓ Configuration looks good\n";
} else {
    echo "  ✗ Configuration issues found:\n";
    foreach ($issues as $issue) {
        echo "    - $issue\n";
    }
}

// Test 3: Test email service instantiation
echo "\nTest 3: Testing EmailService instantiation...\n";
try {
    $emailService = new EmailService();
    echo "  ✓ EmailService created successfully\n";
} catch (Exception $e) {
    echo "  ✗ Failed to create EmailService: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Check if we can get a test booking
echo "\nTest 4: Creating sample booking data...\n";
$sample_booking = [
    'id' => 999,
    'client_name' => 'Test User',
    'client_email' => 'test@example.com',
    'client_phone' => '(555) 123-4567',
    'service_type' => 'Test Consultation',
    'appointment_date' => date('Y-m-d', strtotime('+7 days')),
    'appointment_time' => '14:00:00',
    'duration_minutes' => 60,
    'notes' => 'This is a test booking'
];
echo "  ✓ Sample booking created\n";
echo "    Client: {$sample_booking['client_name']}\n";
echo "    Email: {$sample_booking['client_email']}\n";
echo "    Date: {$sample_booking['appointment_date']} at {$sample_booking['appointment_time']}\n";

// Test 5: Prompt for actual email test
echo "\nTest 5: Send test email?\n";
echo "Do you want to send a test booking confirmation email?\n";
echo "This will send to: {$sample_booking['client_email']}\n";
echo "Enter a different email address or press Enter to skip: ";

$handle = fopen("php://stdin", "r");
$input = trim(fgets($handle));
fclose($handle);

if (!empty($input)) {
    // Validate email
    if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
        echo "  ✗ Invalid email address\n";
    } else {
        $sample_booking['client_email'] = $input;
        echo "\nSending test email to: {$sample_booking['client_email']}...\n";
        
        // Enable debug mode temporarily if not already enabled
        $original_debug = Settings::get('smtp_debug', false);
        if (!$original_debug) {
            echo "  (Temporarily enabling debug mode)\n";
            Settings::set('smtp_debug', '1');
        }
        
        try {
            $result = $emailService->sendBookingConfirmation($sample_booking);
            
            if ($result['success']) {
                echo "  ✓ Email sent successfully!\n";
                echo "    Message: {$result['message']}\n";
                echo "\nCheck your inbox (and spam folder) for the email.\n";
            } else {
                echo "  ✗ Email sending failed\n";
                echo "    Error: {$result['message']}\n";
                echo "\nCheck the server error log for more details:\n";
                echo "  tail -f /var/log/apache2/error.log\n";
                echo "  tail -f /var/log/php-fpm/error.log\n";
            }
        } catch (Exception $e) {
            echo "  ✗ Exception occurred: " . $e->getMessage() . "\n";
        }
        
        // Restore original debug setting
        if (!$original_debug) {
            Settings::set('smtp_debug', '0');
        }
    }
} else {
    echo "  Skipping email test\n";
}

echo "\n=== Test Complete ===\n\n";

echo "Summary:\n";
echo "- Email service: " . $email_config['email_service'] . "\n";
if ($email_config['email_service'] === 'smtp') {
    echo "- SMTP host: " . $email_config['smtp_host'] . "\n";
    echo "- SMTP port: " . $email_config['smtp_port'] . "\n";
    echo "- SMTP encryption: " . $email_config['smtp_encryption'] . "\n";
}
echo "\nFor more information, see:\n";
echo "  backend/EMAIL_CONFIGURATION.md\n";
echo "\n";
?>
