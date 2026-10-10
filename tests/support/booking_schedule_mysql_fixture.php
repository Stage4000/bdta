<?php
/** Two independent loopback workers for the real MySQL booking controllers. */
if (PHP_SAPI !== 'cli-server' || getenv('BDTA_SCHEDULE_WORKER') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!in_array($path, ['/health', '/identity', '/backend/public/api_bookings.php', '/portal/api_book_credit.php', '/portal/api_appointments.php'], true)) { http_response_code(404); exit; }
if ($path === '/health') { echo 'ready'; return; }
$file = getenv('BDTA_SCHEDULE_CONFIG');
if ($file === false) { throw new RuntimeException('Missing private fixture configuration.'); }
$config = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($config) || !is_string($config['schema']??null) || !preg_match('/^bdta_schedule_test_[a-f0-9]{16}$/D', $config['schema'])) { throw new RuntimeException('Invalid synthetic schema.'); }
define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
Settings::seedCacheForTesting(['timezone'=>'UTC','base_url'=>'http://127.0.0.1','email_service'=>'smtp','smtp_host'=>'',
    'email_from_address'=>'bookings@example.invalid','google_calendar_enabled'=>false,'google_oauth_client_id'=>'',
    'google_oauth_client_secret'=>'','turnstile_site_key'=>'','turnstile_secret_key'=>'']);
$conn = new SafePDO('mysql:host=127.0.0.1;port='.safe_int($config['port']??0).';dbname='.$config['schema'].';charset=utf8mb4',
    scalar_string($config['user']??''),scalar_string($config['password']??''));
$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SafePDOStatement::class]);
(new ReflectionProperty(Database::class,'sharedConnection'))->setValue(null,$conn);
session_start();
if ($path==='/identity') { $_SESSION=['portal_client_id'=>1]; echo 'ready'; return; }
$race=scalar_string($_SERVER['HTTP_X_SYNTHETIC_RACE']??'');
if ($race!=='') {
    if (!preg_match('/^[a-f0-9]{16}$/D',$race)) { throw new RuntimeException('Invalid race identifier.'); }
    $root=scalar_string($config['temp']??'');
    $worker=scalar_string(getenv('BDTA_SCHEDULE_INDEX'));
    file_put_contents($root.'/'.$race.'-'.$worker,'ready');
    $deadline=microtime(true)+5;
    while (!is_file($root.'/'.$race.'-0') || !is_file($root.'/'.$race.'-1')) {
        if (microtime(true)>$deadline) { throw new RuntimeException('Both race workers did not arrive.'); }
        usleep(10000);
    }
}
chdir(dirname(__DIR__,2).($path==='/backend/public/api_bookings.php'?'/backend/public':'/portal'));
require dirname(__DIR__,2).$path;
