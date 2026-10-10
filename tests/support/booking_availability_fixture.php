<?php
/** Disposable loopback router for the real public booking controller. */
if (PHP_SAPI !== 'cli-server' || getenv('BDTA_BOOKING_AVAILABILITY_TEST') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!in_array($path, ['/health', '/identity', '/state', '/fault', '/type', '/snapshot', '/calendar', '/calendar_check', '/portal/login.php', '/portal/api_book_credit.php', '/portal/api_appointments.php', '/backend/public/api_bookings.php'], true)) {
    http_response_code(404);
    exit;
}
if ($path === '/health') { echo 'ready'; return; }
define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
Settings::seedCacheForTesting([
    'timezone' => 'UTC', 'base_url' => 'http://127.0.0.1',
    'email_service' => 'smtp', 'smtp_host' => '', 'smtp_username' => '', 'smtp_password' => '',
    'email_from_address' => 'bookings@example.invalid', 'email_from_name' => 'Synthetic Booking',
    'google_calendar_enabled' => false, 'turnstile_site_key' => '', 'turnstile_secret_key' => '',
    'google_oauth_client_id' => is_file(session_save_path() . '/calendar') ? 'synthetic-client' : '',
    'google_oauth_client_secret' => is_file(session_save_path() . '/calendar') ? 'synthetic-secret' : '',
]);
$db_file = session_save_path() . '/fixture.sqlite';
$is_new = !is_file($db_file);
$conn = new SafePDO('sqlite:' . $db_file);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
if ($is_new) {
    $conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
    $conn->exec('CREATE TABLE email_signature_templates (id INTEGER, is_default INTEGER, is_active INTEGER)');
    $conn->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
    $conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, address TEXT, notes TEXT, is_archived INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
    $conn->exec('ALTER TABLE clients ADD COLUMN password_hash TEXT');
    $conn->exec('ALTER TABLE clients ADD COLUMN is_admin INTEGER DEFAULT 0');
    $conn->exec('ALTER TABLE clients ADD COLUMN last_login TEXT');
    $conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
    $conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, admin_user_id INTEGER, client_name TEXT, client_email TEXT NOT NULL, client_phone TEXT, service_type TEXT, appointment_date TEXT, appointment_time TEXT, notes TEXT, duration_minutes INTEGER, location TEXT, location_type TEXT, package_credit_id INTEGER, contract_accepted INTEGER, contract_accepted_at TEXT, contract_signature_name TEXT, contract_signature_font TEXT, status TEXT, google_event_id TEXT, ical_token TEXT)');
    $conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, admin_user_id INTEGER, duration_minutes INTEGER DEFAULT 60, buffer_before_minutes INTEGER DEFAULT 0, buffer_after_minutes INTEGER DEFAULT 0, requires_admin_confirmation INTEGER DEFAULT 0, confirmation_template_id INTEGER, booking_request_template_id INTEGER, reminder_template_id INTEGER, cancellation_template_id INTEGER, is_mini_session INTEGER DEFAULT 0, mini_session_location TEXT, is_field_rental INTEGER DEFAULT 0, field_rental_location TEXT, is_group_class INTEGER DEFAULT 0, group_class_location TEXT, location_types TEXT, contract_template_id INTEGER, uses_resource INTEGER DEFAULT 0, resource_name TEXT, resource_capacity INTEGER DEFAULT 1, resource_allocation TEXT DEFAULT \'per_appointment\')');
    $conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, breed TEXT, date_of_birth TEXT, source TEXT, spayed_neutered INTEGER DEFAULT 0, vaccines_current INTEGER DEFAULT 0, vaccine_notes TEXT, behavior_notes TEXT, medical_notes TEXT, training_notes TEXT, pet_sitting_notes TEXT, is_active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE appointment_pets (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, pet_id INTEGER, created_at TEXT)');
    $conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, fields TEXT, form_type TEXT, is_active INTEGER, required_frequency TEXT)');
    $conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, pet_id INTEGER, responses TEXT, status TEXT, submitted_at TEXT)');
    $conn->exec('CREATE TABLE workflow_triggers (id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER, trigger_type TEXT, appointment_type_id INTEGER, form_template_id INTEGER, is_active INTEGER)');
    $conn->exec('CREATE TABLE client_package_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, client_package_id INTEGER, total_credits INTEGER, used_credits INTEGER, updated_at TEXT)');
    $conn->exec('CREATE TABLE client_packages (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER, expires_at TEXT, package_name TEXT)');
    $conn->exec('CREATE TABLE package_credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_package_credit_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, transaction_type TEXT, amount INTEGER, booking_id INTEGER, notes TEXT, created_by INTEGER)');
    $conn->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT, recipient_id INTEGER, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, url TEXT, is_read INTEGER DEFAULT 0, read_at TEXT, deleted_at TEXT, created_at TEXT)');
    $conn->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, template_type TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT NOT NULL, body_text TEXT, variables TEXT, is_active INTEGER DEFAULT 1)');
    $conn->exec('CREATE TABLE client_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, direction TEXT NOT NULL, status TEXT NOT NULL, message_id TEXT, from_email TEXT NOT NULL, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, template_id INTEGER, mail_type TEXT, scheduled_at TEXT, sent_at TEXT, delivered_at TEXT, failed_at TEXT, error_message TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE unmatched_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id TEXT, from_email TEXT NOT NULL, from_name TEXT, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, received_at TEXT, direction TEXT DEFAULT \'incoming\', is_assigned INTEGER DEFAULT 0, assigned_to_client_id INTEGER, assigned_at TEXT, assigned_by INTEGER, is_archived INTEGER DEFAULT 0, archived_at TEXT, created_at TEXT)');
    $conn->exec('CREATE TABLE client_activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, action TEXT, description TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

    $stmt = $conn->prepare('INSERT INTO clients (id, name, email, phone, address, is_archived) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([1 => 'alice', 2 => 'bob', 3 => 'archived', 4 => 'alice'] as $id => $name) {
        $stmt->execute([$id, ucfirst($name), $name . '@example.invalid', '555-0100',
            $id === 4 ? 'Duplicate-email owner venue' : 'Synthetic original venue', $id === 3 ? 1 : 0]);
    }
    $conn->prepare('UPDATE clients SET password_hash = ? WHERE id IN (1, 2)')
        ->execute([password_hash('synthetic-booking-password', PASSWORD_DEFAULT)]);
    $conn->exec("INSERT INTO appointment_types (id, name, location_types) VALUES (7, 'Synthetic Training', '[\"client_address\",\"custom_address\"]')");
    $conn->exec("INSERT INTO client_packages VALUES (1, 1, NULL, 'Synthetic Package')");
    $conn->exec('INSERT INTO client_package_credits VALUES (1, 1, 7, 1, 10, 0, NULL)');
    $conn->exec("INSERT INTO pets (id, client_id, name, breed, is_active) VALUES (1, 1, 'Buddy', 'Original breed', 1), (2, 2, 'Other', 'Other breed', 1)");
    $stmt = $conn->prepare("INSERT INTO form_templates (id, fields, form_type, is_active) VALUES (?, ?, ?, 1)");
    $stmt->execute([8, json_encode([
        ['label' => 'Name', 'type' => 'text', 'profile_mapping' => 'client.name'],
        ['label' => 'Address', 'type' => 'text', 'profile_mapping' => 'client.address'],
        ['label' => 'Breed', 'type' => 'text', 'profile_mapping' => 'pet_1.breed'],
    ]), 'client_form']);
    $stmt->execute([9, json_encode([
        ['label' => 'Name', 'type' => 'text', 'profile_mapping' => 'client.name'],
        ['label' => 'Email', 'type' => 'text', 'profile_mapping' => 'client.email'],
    ]), 'booking_form']);
    $stmt->execute([10, json_encode([
        ['label' => 'First email', 'type' => 'text', 'profile_mapping' => 'client.email'],
        ['label' => 'Second email', 'type' => 'text', 'profile_mapping' => 'client.email'],
    ]), 'client_form']);
    foreach ([
        'portal_available' => 'INTEGER DEFAULT 1', 'public_available' => 'INTEGER DEFAULT 1',
        'available_days' => "TEXT DEFAULT '[1,2,3,4,5]'", 'available_start_time' => "TEXT DEFAULT '09:00'",
        'available_end_time' => "TEXT DEFAULT '17:00'", 'time_slot_interval' => 'INTEGER DEFAULT 60',
        'schedule_type' => "TEXT DEFAULT 'recurring'", 'specific_date' => 'TEXT', 'specific_dates' => 'TEXT',
        'per_day_schedule' => 'TEXT', 'max_participants' => 'INTEGER DEFAULT 1',
        'advance_booking_min_days' => 'INTEGER DEFAULT 1', 'advance_booking_max_days' => 'INTEGER DEFAULT 30',
        'cancellation_notice_hours' => 'INTEGER DEFAULT 0', 'auto_invoice' => 'INTEGER DEFAULT 0',
    ] as $column => $definition) {
        // Fixed test-owned schema identifiers; no request values enter this SQL.
        $conn->exec("ALTER TABLE appointment_types ADD COLUMN {$column} {$definition}");
    }
    $conn->exec('ALTER TABLE bookings ADD COLUMN created_at TEXT');
    $conn->exec('ALTER TABLE bookings ADD COLUMN updated_at TEXT');
    $conn->exec('CREATE TABLE booking_change_log (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, client_id INTEGER, change_type TEXT, reason TEXT, old_date TEXT, old_time TEXT, new_date TEXT, new_time TEXT, initiated_by TEXT, ip_address TEXT)');
    $conn->exec('CREATE TABLE google_oauth_tokens (admin_user_id INTEGER, access_token TEXT, expires_at TEXT, calendar_id TEXT)');
    $conn->prepare('INSERT INTO google_oauth_tokens VALUES (1, ?, ?, ?)')->execute(['synthetic-token', (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')))->format(DateTime::RFC3339), 'primary']);
    $conn->exec('INSERT INTO admin_users (id, email) VALUES (1, NULL), (2, NULL)');
    $conn->exec('UPDATE appointment_types SET admin_user_id=1 WHERE id=7');
    $day = date('Y-m-d', strtotime('next Monday +7 days'));
    $conn->exec("INSERT INTO appointment_types (id, name, admin_user_id, location_types, is_active) VALUES
        (11, 'Inactive', 1, '[\"client_address\"]', 0),
        (12, 'Other admin', 2, '[\"client_address\"]', 1),
        (13, 'Per day', 1, '[\"client_address\"]', 1),
        (14, 'Specific points', 1, '[\"client_address\"]', 1),
        (15, 'Group class', 1, '[\"client_address\"]', 1),
        (16, 'Resource class', 1, '[\"client_address\"]', 1),
        (17, 'Buffered', 1, '[\"client_address\"]', 1),
        (18, 'Non-group resource', 1, '[\"client_address\"]', 1)");
    $conn->prepare('UPDATE appointment_types SET per_day_schedule=? WHERE id=13')->execute([json_encode(['1'=>['start'=>'11:00','end'=>'14:00']])]);
    $conn->prepare("UPDATE appointment_types SET schedule_type='specific_date', specific_dates=?, is_mini_session=1, mini_session_location='Synthetic point venue' WHERE id=14")
        ->execute([json_encode([['date'=>$day,'timeslots'=>[['type'=>'point','time'=>'10:00'],['type'=>'range','start'=>'13:00','end'=>'15:00']]]])]);
    $conn->exec("UPDATE appointment_types SET is_group_class=1, max_participants=2, group_class_location='Synthetic class venue' WHERE id IN (15,16)");
    $conn->exec("UPDATE appointment_types SET uses_resource=1, resource_capacity=2, resource_allocation='per_pet' WHERE id=16");
    $conn->exec('UPDATE appointment_types SET buffer_before_minutes=15, buffer_after_minutes=15 WHERE id=17');
    $conn->exec("UPDATE appointment_types SET uses_resource=1, resource_capacity=2 WHERE id=18");
    $conn->exec("INSERT INTO pets (id, client_id, name, is_active) VALUES (3, 1, 'Second synthetic dog', 1)");
}
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
// curl_exec is disabled in the child. This substitute returns synthetic pages
// through the real Calendar integration without any provider request.
if (function_exists('curl_exec')) { throw new RuntimeException('Fixture requires disabled curl_exec.'); } else {
function curl_exec(CurlHandle $handle): string|bool {
    global $conn;
    $url = scalar_string(curl_getinfo($handle, CURLINFO_EFFECTIVE_URL));
    if ($url === '') { return false; }
    if (!str_starts_with($url, 'https://www.googleapis.com/calendar/v3/')) { throw new RuntimeException('Unexpected fixture transport.'); }
    $events = [];
    foreach ($conn->query("SELECT * FROM bookings WHERE google_event_id IS NOT NULL AND status!='cancelled'")->fetchAll(PDO::FETCH_ASSOC) as $booking) {
        $start = new DateTimeImmutable(array_string_value($booking, 'appointment_date') . ' ' . array_string_value($booking, 'appointment_time'), new DateTimeZone('UTC'));
        $events[] = ['id'=>array_string_value($booking, 'google_event_id'), 'start'=>['dateTime'=>$start->format(DateTime::RFC3339)],
            'end'=>['dateTime'=>$start->modify('+' . max(1,array_int_value($booking,'duration_minutes')) . ' minutes')->format(DateTime::RFC3339)]];
    }
    $external = decode_json_assoc(scalar_string(@file_get_contents(session_save_path() . '/calendar')));
    if (array_string_value($external, 'date') !== '') {
        $start = new DateTimeImmutable(array_string_value($external,'date').' '.array_string_value($external,'time','09:00'),new DateTimeZone('UTC'));
        $events[] = ['id'=>'external-same-window', 'start'=>['dateTime'=>$start->format(DateTime::RFC3339)],
            'end'=>['dateTime'=>$start->modify('+1 hour')->format(DateTime::RFC3339)]];
    }
    if (str_contains($url, '/events?')) {
        return scalar_string(json_encode(str_contains($url, 'pageToken=second')
            ? ['items'=>array_slice($events, 1)] : ['items'=>array_slice($events,0,1), 'nextPageToken'=>'second']));
    }
    if (str_ends_with($url, '/freeBusy')) {
        $busy=[];
        foreach ($events as $event) { $busy[]=['start'=>$event['start']['dateTime'], 'end'=>$event['end']['dateTime']]; }
        return scalar_string(json_encode(['calendars'=>['primary'=>['busy'=>$busy]]]));
    }
    return '{"id":"synthetic-calendar-event"}';
}
}
// Start test file sessions before config.php can register the production DB session handler.
session_start();
if ($path === '/calendar') {
    $conn->prepare('UPDATE bookings SET google_event_id=? WHERE id=?')->execute(['synthetic-event-'.safe_int($_GET['id']??0), safe_int($_GET['id']??0)]);
    file_put_contents(session_save_path().'/calendar', json_encode(['date'=>scalar_string($_GET['external']??''),'time'=>scalar_string($_GET['external_time']??'09:00')]));
    echo 'ready'; return;
}
if ($path === '/snapshot') {
    $conn->prepare('UPDATE bookings SET duration_minutes=? WHERE id=?')->execute([safe_int($_GET['duration']??60),safe_int($_GET['id']??0)]);
    if (isset($_GET['admin'])) { $conn->prepare('UPDATE appointment_types SET admin_user_id=? WHERE id=7')->execute([safe_int($_GET['admin'])]); }
    if (isset($_GET['booking_admin'])) { $conn->prepare('UPDATE bookings SET admin_user_id=? WHERE id=?')->execute([safe_int($_GET['booking_admin']),safe_int($_GET['id']??0)]); }
    echo 'ready'; return;
}
if ($path === '/type') {
    $conn->prepare('UPDATE appointment_types SET is_active=? WHERE id=?')->execute([safe_int($_GET['active'] ?? 1), safe_int($_GET['id'] ?? 0)]);
    echo 'ready';
    return;
}
if ($path === '/calendar_check') {
    require dirname(__DIR__, 2) . '/backend/includes/booking_availability.php';
    $date = date('Y-m-d', strtotime('+7 days'));
    $busy = [['start'=>$date . 'T10:00:00+00:00', 'end'=>$date . 'T11:00:00+00:00']];
    echo json_encode([
        'overlap'=>bdta_booking_slot_passes_calendar($date, '10:00', 60, 0, 0, $busy),
        'adjacent'=>bdta_booking_slot_passes_calendar($date, '11:00', 60, 0, 0, $busy),
        'buffered'=>bdta_booking_slot_passes_calendar($date, '11:00', 60, 15, 0, $busy),
    ]);
    return;
}
if ($path === '/identity') {
    $_SESSION = [];
    $id = safe_int($_GET['id'] ?? 0);
    if ($id !== 0) { $_SESSION['portal_client_id'] = $id; }
    // Session email and posted identity hints are deliberately untrusted.
    $_SESSION['portal_client_email'] = 'alice@example.invalid';
    if (isset($_GET['admin'])) { $_SESSION['admin_id'] = 1; }
    echo 'ready';
    return;
}
if ($path === '/state') {
    echo json_encode([
        'clients' => $conn->query('SELECT id, name, email, address FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'credits' => $conn->query('SELECT used_credits FROM client_package_credits')->fetchAll(PDO::FETCH_ASSOC),
        'pets' => $conn->query('SELECT id, client_id, name, breed FROM pets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'bookings' => $conn->query('SELECT id, client_id, location, package_credit_id, status FROM bookings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'schedule' => $conn->query('SELECT id, appointment_type_id, appointment_date, appointment_time, duration_minutes, admin_user_id FROM bookings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'links' => $conn->query('SELECT booking_id, pet_id FROM appointment_pets ORDER BY booking_id, pet_id')->fetchAll(PDO::FETCH_ASSOC),
        'transactions' => safe_int($conn->query('SELECT COUNT(*) FROM package_credit_transactions')->fetchColumn()),
        'forms' => safe_int($conn->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn()),
        'notifications' => safe_int($conn->query('SELECT COUNT(*) FROM notifications')->fetchColumn()),
        'activity' => safe_int($conn->query('SELECT COUNT(*) FROM client_activity_log')->fetchColumn()),
        'changes' => safe_int($conn->query('SELECT COUNT(*) FROM booking_change_log')->fetchColumn()),
    ]);
    return;
}
if ($path === '/fault') {
    if (($_GET['enabled'] ?? '') === '2') {
        $conn->exec("CREATE TRIGGER fail_reschedule BEFORE UPDATE ON bookings BEGIN SELECT RAISE(ABORT, 'synthetic reschedule failure'); END");
    } elseif (($_GET['enabled'] ?? '') === '1') {
        $conn->exec("CREATE TRIGGER fail_booking BEFORE INSERT ON bookings BEGIN SELECT RAISE(ABORT, 'synthetic booking failure'); END");
    } else {
        $conn->exec('DROP TRIGGER IF EXISTS fail_booking');
        $conn->exec('DROP TRIGGER IF EXISTS fail_reschedule');
    }
    echo 'ready';
    return;
}
if ($path === '/portal/api_book_credit.php' || $path === '/portal/api_appointments.php') {
    chdir(dirname(__DIR__, 2) . '/portal');
    require dirname(__DIR__, 2) . $path;
    return;
}
if ($path === '/portal/login.php') {
    chdir(dirname(__DIR__, 2) . '/portal');
    require dirname(__DIR__, 2) . '/portal/login.php';
    return;
}
chdir(dirname(__DIR__, 2) . '/backend/public');
require dirname(__DIR__, 2) . '/backend/public/api_bookings.php';
