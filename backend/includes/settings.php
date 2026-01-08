<?php
/**
 * Settings Helper Functions
 * Functions to retrieve and update settings from the database
 */

require_once __DIR__ . '/database.php';

class Settings {
    private static $db = null;
    private static $cache = [];
    
    private static function getDB() {
        if (self::$db === null) {
            $db = new Database();
            self::$db = $db->getConnection();
        }
        return self::$db;
    }
    
    /**
     * Get a setting value by key
     */
    public static function get($key, $default = null) {
        // Check cache first - use array_key_exists to handle falsy values
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        
        $db = self::getDB();
        $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $value = self::castValue($result['setting_value'], $result['setting_type']);
            self::$cache[$key] = $value;
            return $value;
        }
        
        return $default;
    }
    
    /**
     * Set a setting value
     */
    public static function set($key, $value) {
        $db = self::getDB();
        
        // Get the setting type for proper caching
        $stmt = $db->prepare("SELECT setting_type FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $type = $stmt->fetchColumn();
        
        if (!$type) {
            // Setting doesn't exist
            return false;
        }
        
        $stmt = $db->prepare("
            UPDATE settings 
            SET setting_value = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE setting_key = ?
        ");
        $result = $stmt->execute([$value, $key]);
        
        // Update cache with properly cast value
        if ($result) {
            self::$cache[$key] = self::castValue($value, $type);
        } else {
            // Clear cache on failure to ensure consistency
            unset(self::$cache[$key]);
        }
        
        return $result;
    }
    
    /**
     * Get all settings in a category
     */
    public static function getCategory($category) {
        $db = self::getDB();
        $stmt = $db->prepare("
            SELECT setting_key, setting_value, setting_type, label, description, is_secret 
            FROM settings 
            WHERE category = ? 
            ORDER BY id
        ");
        $stmt->execute([$category]);
        $settings = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[] = [
                'key' => $row['setting_key'],
                'value' => $row['is_secret'] ? '••••••••' : $row['setting_value'],
                'actual_value' => $row['setting_value'],
                'type' => $row['setting_type'],
                'label' => $row['label'],
                'description' => $row['description'],
                'is_secret' => $row['is_secret']
            ];
        }
        
        return $settings;
    }
    
    /**
     * Get all categories
     */
    public static function getCategories() {
        $db = self::getDB();
        $stmt = $db->query("SELECT DISTINCT category FROM settings ORDER BY category");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Cast value to appropriate type
     */
    private static function castValue($value, $type) {
        switch ($type) {
            case 'checkbox':
                return (bool)$value;
            case 'number':
                return is_numeric($value) ? (float)$value : 0;
            default:
                return $value;
        }
    }
    
    /**
     * Get Stripe configuration based on mode
     */
    public static function getStripeConfig() {
        $mode = self::get('stripe_mode', 'test');
        $enabled = self::get('stripe_enabled', false);
        
        if (!$enabled) {
            return null;
        }
        
        if ($mode === 'live') {
            return [
                'publishable_key' => self::get('stripe_live_publishable_key'),
                'secret_key' => self::get('stripe_live_secret_key'),
                'currency' => self::get('stripe_currency', 'usd'),
                'mode' => 'live'
            ];
        } else {
            return [
                'publishable_key' => self::get('stripe_test_publishable_key'),
                'secret_key' => self::get('stripe_test_secret_key'),
                'currency' => self::get('stripe_currency', 'usd'),
                'mode' => 'test'
            ];
        }
    }
    
    /**
     * Get email configuration
     */
    public static function getEmailConfig() {
        return [
            'from_address' => self::get('email_from_address'),
            'from_name' => self::get('email_from_name'),
            'service' => self::get('email_service', 'mail'),
            'smtp_host' => self::get('smtp_host'),
            'smtp_port' => self::get('smtp_port', 587),
            'smtp_username' => self::get('smtp_username'),
            'smtp_password' => self::get('smtp_password'),
            'sendgrid_api_key' => self::get('sendgrid_api_key'),
            'mailgun_api_key' => self::get('mailgun_api_key'),
            'mailgun_domain' => self::get('mailgun_domain'),
        ];
    }
}
?>
