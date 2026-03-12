<?php
/**
 * Simple .env file loader
 * Loads environment variables from .env file if it exists
 */

class EnvLoader {
    public static function load(?string $filePath = null): void {
        // Default to .env in the root directory
        if ($filePath === null) {
            $filePath = __DIR__ . '/../../.env';
        }
        
        // If .env doesn't exist, skip loading
        if (!file_exists($filePath)) {
            return;
        }
        
        // Read and parse .env file
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                    $value = $matches[2];
                }
                
                // Set environment variable if not already set
                if (!getenv($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
    
    public static function get(string $key, mixed $default = null): mixed {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}
