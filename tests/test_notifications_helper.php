#!/usr/bin/env php
<?php

function assertSameValue(string $label, mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed.\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . " failed.\n");
        exit(1);
    }
}

function csrfToken(): string {
    return 'test-csrf-token';
}

function formatDateTime(mixed $date_time, string $format = 'M j, Y g:i A'): string {
    return (string) $date_time;
}

function array_string_value(array $row, string|int $key, string $default = ''): string {
    return isset($row[$key]) ? (string) $row[$key] : $default;
}

require_once dirname(__DIR__) . '/backend/includes/notifications.php';

$conn = new PDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$conn->exec("
    CREATE TABLE admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL
    );
    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        audience TEXT NOT NULL,
        recipient_id INTEGER NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER DEFAULT 0,
        title TEXT NOT NULL,
        message TEXT,
        url TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        read_at TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE quotes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        quote_number TEXT NOT NULL,
        title TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE contracts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        contract_number TEXT NOT NULL,
        title TEXT NOT NULL,
        access_token TEXT NULL,
        status TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
    CREATE TABLE invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        invoice_number TEXT NOT NULL,
        status TEXT NOT NULL,
        due_date TEXT NULL,
        total_amount REAL NOT NULL,
        created_at TEXT NOT NULL
    );
");

$conn->exec("INSERT INTO admin_users (username) VALUES ('one'), ('two')");
bdta_create_admin_notifications(
    $conn,
    'booking',
    11,
    'New appointment request',
    'Taylor booked Puppy Basics.',
    '/client/bookings_list.php'
);

$admin_count = (int) $conn->query("SELECT COUNT(*) FROM notifications WHERE audience = 'admin'")->fetchColumn();
assertSameValue('create admin notifications for each admin user', 2, $admin_count);

bdta_create_notification($conn, 'portal', 7, 'credit', 15, 'Credits updated', 'Your credits were updated.', '/portal/credits.php');
bdta_create_notification($conn, 'portal', 7, 'credit', 16, 'Read notification', 'Already read.', '/portal/credits.php');
bdta_mark_notification_read($conn, 'portal', 7, 4);
bdta_create_notification($conn, 'portal', 7, 'credit', 17, 'Delete me', 'Delete this one.', '/portal/credits.php');
bdta_delete_notification($conn, 'portal', 7, 5);
$conn->exec("
    UPDATE notifications
    SET created_at = CASE id
        WHEN 3 THEN '2026-04-10 08:00:00'
        WHEN 4 THEN '2026-04-10 07:00:00'
        WHEN 5 THEN '2026-04-10 06:00:00'
        ELSE created_at
    END
    WHERE id IN (3, 4, 5)
");

$conn->exec("
    INSERT INTO quotes (client_id, quote_number, title, status, created_at)
    VALUES
        (7, 'Q-100', 'Board & Train', 'sent', '2026-04-10 10:00:00'),
        (7, 'Q-101', 'Follow Up', 'accepted', '2026-04-09 10:00:00');
");
$conn->exec("
    INSERT INTO contracts (client_id, contract_number, title, access_token, status, created_at)
    VALUES
        (7, 'C-200', 'Training Agreement', 'securetoken', 'sent', '2026-04-10 09:00:00'),
        (7, 'C-201', 'Old Contract', NULL, 'signed', '2026-04-08 08:00:00');
");
$conn->exec("
    INSERT INTO invoices (client_id, invoice_number, status, due_date, total_amount, created_at)
    VALUES
        (7, 'INV-300', 'sent', '2026-04-15', 120.50, '2026-04-10 09:30:00'),
        (7, 'INV-301', 'draft', '2026-04-16', 75.00, '2026-04-10 09:45:00'),
        (7, 'INV-302', 'paid', '2026-04-17', 33.00, '2026-04-10 09:50:00');
");

$portal_notifications = bdta_get_notifications($conn, 'portal', 7, 10);
assertSameValue('portal notifications include stored and sticky items', 5, count($portal_notifications));
assertSameValue('latest sticky quote notification sorts first', 'quote', $portal_notifications[0]['entity_type']);
assertSameValue('sent unpaid invoices remain sticky for the portal client', 'invoice', $portal_notifications[1]['entity_type']);
assertSameValue('sent invoice notification has correct URL', '/portal/invoice_view.php?id=1', $portal_notifications[1]['url']);
assertTrue(!str_contains((string) json_encode($portal_notifications), 'INV-301'), 'draft invoices are excluded from sticky notifications');
assertTrue($portal_notifications[0]['is_read'] === false, 'sticky quote remains unread');
assertTrue($portal_notifications[0]['can_delete'] === false, 'sticky quote cannot be manually deleted');
assertSameValue('sticky contract notification uses token link', '/backend/public/contract.php?token=securetoken', $portal_notifications[2]['url']);

$limited_persistent_notifications = bdta_get_persistent_notifications($conn, 'portal', 7, 1);
assertSameValue('persistent notifications query honors limit', 1, count($limited_persistent_notifications));

$portal_unread = bdta_get_unread_notification_count($conn, 'portal', 7);
assertSameValue('portal unread count includes sticky and persistent unread items', 4, $portal_unread);

$read_notification = bdta_get_notification_by_id($conn, 'portal', 7, 4);
assertTrue($read_notification !== null, 'matching notification can be loaded for redirect');
assertSameValue('notification URLs stay internal', '/portal/credits.php', bdta_notification_sanitize_path('/portal/credits.php'));
assertSameValue('external notification URLs are rejected', '/portal/index.php', bdta_notification_sanitize_path('https://example.com/bad', '/portal/index.php'));
assertSameValue('protocol-relative notification URLs are rejected', '/portal/index.php', bdta_notification_sanitize_path('//example.com/escape', '/portal/index.php'));
assertSameValue('backslash-prefixed notification URLs are rejected', '/portal/index.php', bdta_notification_sanitize_path('\\example.com\\escape', '/portal/index.php'));

$_SERVER['REQUEST_URI'] = '/portal/credits.php';
ob_start();
bdta_render_notification_widget(
    $conn,
    'portal',
    7,
    '/portal/notification_action.php',
    '/portal/notification_redirect.php',
    '/portal/index.php'
);
$html = ob_get_clean();

assertTrue(is_string($html) && str_contains($html, 'app-notification-toggle'), 'rendered widget includes toggle button');
assertTrue(is_string($html) && str_contains($html, 'app-notification-panel'), 'rendered widget includes slide-out panel');
assertTrue(is_string($html) && str_contains($html, '/portal/notification_action.php'), 'rendered widget includes action endpoint');
assertTrue(is_string($html) && str_contains($html, '/portal/notification_redirect.php?id=3'), 'rendered widget includes redirect endpoint');
assertTrue(is_string($html) && str_contains($html, '/portal/invoice_view.php?id=1'), 'rendered widget includes sticky invoice destination');

echo "Notification helper tests passed.\n";
