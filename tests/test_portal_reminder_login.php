#!/usr/bin/env php
<?php
/** Real HTTP redirects and login controllers, with disposable synthetic SQLite data. */
require_once dirname(__DIR__) . '/backend/includes/booking_action_links.php';
$actions = ['cancel', 'reschedule'];
if (isset($argv[1])) {
    if (!in_array($argv[1], $actions, true)) { throw new RuntimeException('Unknown reminder action.'); }
    $actions = [$argv[1]];
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function reminderRequest(string $base, string $path, string &$cookie, string $post = ''): array
{
    $context = stream_context_create(['http' => [
        'method' => $post === '' ? 'GET' : 'POST', 'content' => $post,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n" . ($cookie === '' ? '' : 'Cookie: ' . $cookie . "\r\n"),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 3,
    ]]);
    $stream = @fopen($base . $path, 'r', false, $context);
    if ($stream === false) {
        throw new RuntimeException('Loopback test server is unavailable.');
    }
    $metadata = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);
    $headers = [];
    $status = 0;
    $lines = $metadata['wrapper_data'] ?? [];
    foreach (is_array($lines) ? $lines : [] as $line) {
        if (!is_string($line)) { continue; }
        if (preg_match('/^HTTP\/\S+ (\d+)/', $line, $match) === 1) {
            $status = (int) $match[1];
        } elseif (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower($key)] = trim($value);
        }
    }
    if (isset($headers['set-cookie'])) {
        $cookie = explode(';', $headers['set-cookie'], 2)[0];
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body];
}

function reminderCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS: ' . $message . PHP_EOL;
}

function reminderHidden(string $html, string $name): string
{
    if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $match) !== 1) {
        return '';
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

$listener = stream_socket_server('tcp://127.0.0.1:0');
if ($listener === false) { throw new RuntimeException('Unable to reserve loopback port.'); }
$address = stream_socket_get_name($listener, false);
fclose($listener);
if ($address === false) { throw new RuntimeException('Unable to resolve loopback port.'); }
$temp = sys_get_temp_dir() . '/bdta-reminder-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) { throw new RuntimeException('Unable to create test session directory.'); }
$base = 'http://' . $address;
putenv('BDTA_PORTAL_REMINDER_TEST=1');
// Fixed executable/router, loopback address and shell-free arguments; child transports are disabled.
// nosemgrep: php.lang.security.exec-use.exec-use
$server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temp,
    '-d', 'allow_url_fopen=0', '-d', 'display_errors=stderr',
    '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open',
    '-S', $address, '-t', dirname(__DIR__), __DIR__ . '/support/portal_reminder_login_fixture.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes);
putenv('BDTA_PORTAL_REMINDER_TEST');
if (!is_resource($server)) { throw new RuntimeException('Unable to start isolated HTTP server.'); }
fclose($pipes[0]);

try {
    $cookie = '';
    $ready = false;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        try { $ready = reminderRequest($base, '/health', $cookie)['body'] === 'ready'; } catch (RuntimeException $e) { /* Wait for startup. */ }
        if ($ready) { break; }
        usleep(50000);
    }
    reminderCheck($ready, 'isolated HTTP server starts');
    foreach ($actions as $action) {
        $cookie = '';
        $target = substr(bdta_build_portal_booking_link($base, 42, $action), strlen($base))
            . '&source=reminder%2Bfollowup&tag=a%26b';
        $response = reminderRequest($base, $target, $cookie);
        $login = $response['headers']['location'] ?? '';
        echo $action . ' redirect: ' . $login . PHP_EOL;
        reminderCheck($response['status'] === 302 && $login === '/portal/login.php?return_to=' . rawurlencode($target),
            $action . ': logged-out reminder preserves the complete booking/action query');
        $response = reminderRequest($base, $login, $cookie);
        reminderCheck($response['status'] === 200 && reminderHidden($response['body'], 'return_to') === $target,
            $action . ': login form carries the original return target');
        $post = ['csrf_token' => reminderHidden($response['body'], 'csrf_token'),
            'email' => 'reminder@example.invalid', 'password' => 'wrong-password', 'return_to' => $target];
        $response = reminderRequest($base, '/portal/login.php', $cookie, http_build_query($post));
        reminderCheck($response['status'] === 200 && str_contains($response['body'], 'Invalid email address or password.')
            && reminderHidden($response['body'], 'return_to') === $target, $action . ': login retry retains the target');
        $post['password'] = 'synthetic-reminder-password';
        $response = reminderRequest($base, '/portal/login.php', $cookie, http_build_query($post));
        reminderCheck($response['status'] === 302 && ($response['headers']['location'] ?? '') === $target,
            $action . ': successful login returns to the exact reminder URL');
        $response = reminderRequest($base, $target, $cookie);
        reminderCheck($response['status'] === 200 && str_contains($response['body'], 'data-booking-id="42"')
            && !str_contains($response['body'], 'Fatal error'), $action . ': authenticated client reaches the real booking controls');
        $response = reminderRequest($base, $login, $cookie);
        reminderCheck($response['status'] === 302 && ($response['headers']['location'] ?? '') === $target,
            $action . ': already-authenticated login preserves its destination');
    }
    foreach (['not-a-number', '999', '43', '44', '45'] as $booking) {
        $response = reminderRequest($base, '/portal/appointments.php?booking_id=' . $booking . '&action=cancel', $cookie);
        reminderCheck($response['status'] === 200 && !str_contains($response['body'], 'data-booking-id="' . $booking . '"'),
            'unavailable booking ' . $booking . ' exposes no action control');
    }
    foreach (['', 'https://external.invalid/path', '//external.invalid/path', '\\external.invalid/path',
        '/portal/../client/index.php', '/portal/%2e%2e/client/index.php', '/portal/%5cexternal.invalid'] as $unsafe) {
        $cookie = '';
        $response = reminderRequest($base, '/guard?target=' . rawurlencode($unsafe), $cookie);
        reminderCheck($response['status'] === 302 && ($response['headers']['location'] ?? '') === '/portal/login.php',
            'guard rejects unsafe or empty request target: ' . $unsafe);
        $response = reminderRequest($base, '/portal/login.php?return_to=' . rawurlencode($unsafe), $cookie);
        reminderCheck($response['status'] === 200 && reminderHidden($response['body'], 'return_to') === '',
            'login form rejects unsafe or empty return target: ' . $unsafe);
        $post = ['csrf_token' => reminderHidden($response['body'], 'csrf_token'), 'email' => 'reminder@example.invalid',
            'password' => 'synthetic-reminder-password', 'return_to' => $unsafe];
        $response = reminderRequest($base, '/portal/login.php', $cookie, http_build_query($post));
        reminderCheck($response['status'] === 302 && ($response['headers']['location'] ?? '') === '/portal/index.php',
            'login POST rejects unsafe or empty return target: ' . $unsafe);
    }
    echo "Reminder login regressions passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, (string) file_get_contents($temp . '/server.log'));
    throw $e;
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach (glob($temp . '/*') ?: [] as $file) { unlink($file); }
    rmdir($temp);
}
