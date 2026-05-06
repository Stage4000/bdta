#!/usr/bin/env php
<?php

define('BDTA_TEST_MODE', true);

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/settings.php';

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

Settings::seedCacheForTesting([
    'timezone' => 'UTC',
    'theme_primary_color' => '#9a0073',
    'theme_primary_dark_color' => '#7a005a',
    'theme_secondary_color' => '#0a9a9c',
    'theme_accent_color' => '#a39f89',
    'turnstile_site_key' => '',
    'turnstile_secret_key' => '',
]);

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
$conn->exec("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES ('default_booking_form_id', '0', 'number')");
$conn->exec('CREATE TABLE appointment_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    unique_link TEXT,
    is_active INTEGER DEFAULT 1,
    name TEXT,
    description TEXT,
    default_amount REAL DEFAULT 0,
    duration_minutes INTEGER DEFAULT 60,
    schedule_type TEXT DEFAULT "weekly",
    specific_dates TEXT,
    specific_date TEXT,
    contract_template_id INTEGER DEFAULT 0,
    is_mini_session INTEGER DEFAULT 0,
    mini_session_topic TEXT,
    mini_session_location TEXT,
    is_group_class INTEGER DEFAULT 0,
    group_class_location TEXT,
    is_field_rental INTEGER DEFAULT 0,
    field_rental_location TEXT,
    location_types TEXT
)');
$conn->exec("INSERT INTO appointment_types (unique_link, is_active, name, description, default_amount, duration_minutes, schedule_type, specific_date, location_types) VALUES ('demo-price', 1, 'Private Training Session', 'One-on-one support for your dog with a trainer.', 125.00, 60, 'specific_date', '2026-05-10', '[\"client_address\"]')");
$conn->exec('CREATE TABLE appointment_type_forms (appointment_type_id INTEGER, form_template_id INTEGER)');
$conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, fields TEXT, is_active INTEGER DEFAULT 1, form_type TEXT)');
$conn->exec('CREATE TABLE contract_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, template_text TEXT, is_active INTEGER DEFAULT 1)');

$database_reflection = new ReflectionClass(Database::class);
$shared_connection = $database_reflection->getProperty('sharedConnection');
$shared_connection->setAccessible(true);
$shared_connection->setValue(null, $conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$book_page = file_get_contents(dirname(__DIR__) . '/backend/public/book.php');

if ($book_page === false) {
    fwrite(STDERR, "Failed to read backend/public/book.php\n");
    exit(1);
}

$original_get = $_GET;
$original_post = $_POST;
$original_request = $_REQUEST;
$original_server = $_SERVER;
$original_cwd = getcwd();

$_GET = ['link' => 'demo-price'];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/backend/public/book.php?link=demo-price';
$_SERVER['SCRIPT_NAME'] = '/backend/public/book.php';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['HTTPS'] = '';

ob_start();
chdir(dirname(__DIR__) . '/backend/public');
require dirname(__DIR__) . '/backend/public/book.php';
$rendered_page = ob_get_clean();

if ($original_cwd !== false) {
    chdir($original_cwd);
}
$_GET = $original_get;
$_POST = $original_post;
$_REQUEST = $original_request;
$_SERVER = $original_server;

bdta_assert(
    is_string($rendered_page)
        && str_contains($rendered_page, 'Session Cost:')
        && str_contains($rendered_page, '$125.00'),
    'Direct-link booking pages should render the selected appointment type cost in the header.'
);

bdta_assert(
    str_contains($book_page, 'id="confirmPrice"')
        && str_contains($book_page, 'let selectedTypePrice =')
        && str_contains($book_page, "document.getElementById('confirmPrice').textContent = formatBookingPrice(selectedTypePrice);"),
    'Public booking confirmation should include the selected appointment type cost.'
);

echo "Public booking direct-link price checks passed.\n";
