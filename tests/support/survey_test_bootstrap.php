<?php
// Load the real configuration helpers without opening a database or mail transport.
if (!defined('BDTA_TEST_MODE')) {
    define('BDTA_TEST_MODE', true);
}
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
Settings::seedCacheForTesting(['timezone' => 'UTC']);
if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
    session_start();
    register_shutdown_function(static function (): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    });
}
require_once dirname(__DIR__, 2) . '/backend/includes/config.php';
