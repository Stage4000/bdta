#!/usr/bin/env php
<?php
/** Real HTTP controller regressions with disposable synthetic SQLite data. */

/** @return array<array-key, mixed> */
function identityRequest(string $base, string $path, string &$cookie, ?string $post = null): array
{
    $is_login = str_starts_with($path, '/portal/login.php');
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST', 'content' => $post ?? '',
        'header' => 'Content-Type: ' . ($is_login ? 'application/x-www-form-urlencoded' : 'application/json') . "\r\n"
            . ($cookie === '' ? '' : 'Cookie: ' . $cookie . "\r\n"),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5,
    ]]);
    $stream = @fopen($base . $path, 'r', false, $context);
    if ($stream === false) { throw new RuntimeException('Loopback server unavailable.'); }
    $metadata = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);
    $status = 0;
    $location = '';
    foreach (is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [] as $line) {
        if (!is_string($line)) { continue; }
        if (preg_match('/^HTTP\/\S+ (\d+)/', $line, $match) === 1) { $status = (int) $match[1]; }
        if (stripos($line, 'Set-Cookie: ') === 0) { $cookie = explode(';', substr($line, 12), 2)[0]; }
        if (stripos($line, 'Location: ') === 0) { $location = substr($line, 10); }
    }
    if ($is_login) { return ['status' => $status, 'body' => $body, 'location' => $location]; }
    if ($status !== 200) { throw new RuntimeException('HTTP ' . $status . ': ' . $body); }
    if ($body === 'ready') { return ['ready' => true]; }
    $result = json_decode($body === false ? '' : $body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) { throw new RuntimeException('Expected a JSON object.'); }
    return $result;
}

function identityCheck(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
    echo 'PASS: ' . $label . PHP_EOL;
}

/** @param array<array-key, mixed> $response
 *  @return array<string, int|string> */
function identityRow(array $response, string $key): array
{
    $value = $response[$key] ?? [];
    $row = [];
    foreach (is_array($value) ? $value : [] as $field => $item) {
        if (is_string($field) && (is_string($item) || is_int($item))) { $row[$field] = $item; }
    }
    return $row;
}

/** @param array<array-key, mixed> $response
 *  @return list<array<string, int|string>> */
function identityRows(array $response, string $key): array
{
    $value = $response[$key] ?? [];
    $rows = [];
    foreach (is_array($value) ? $value : [] as $row) { $rows[] = identityRow(['row' => $row], 'row'); }
    return $rows;
}

/** @return array<array-key, mixed> */
function identityLogin(string $base, string $email, string $password, string &$cookie): array
{
    identityRequest($base, '/identity?id=0', $cookie);
    $page = identityRequest($base, '/portal/login.php', $cookie);
    $html = is_string($page['body']) ? $page['body'] : '';
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('Login form missing CSRF token.');
    }
    return identityRequest($base, '/portal/login.php', $cookie, http_build_query([
        'csrf_token' => html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),
        'email' => $email, 'password' => $password, 'return_to' => '/backend/public/book.php?type_id=7',
    ]));
}

$listener = stream_socket_server('tcp://127.0.0.1:0');
if ($listener === false) { throw new RuntimeException('Cannot reserve loopback port.'); }
$address = stream_socket_get_name($listener, false);
fclose($listener);
if ($address === false) { throw new RuntimeException('Cannot resolve loopback port.'); }
$temp = sys_get_temp_dir() . '/bdta-booking-identity-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) { throw new RuntimeException('Cannot create private test directory.'); }
$base = 'http://' . $address;
putenv('BDTA_BOOKING_IDENTITY_TEST=1');
// Fixed executable/router, reserved loopback port, shell-free arguments, no external transports.
// nosemgrep: php.lang.security.exec-use.exec-use
$server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temp, '-d', 'allow_url_fopen=0',
    '-d', 'display_errors=stderr',
    '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open',
    '-S', $address, '-t', dirname(__DIR__), __DIR__ . '/support/public_booking_identity_fixture.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes);
putenv('BDTA_BOOKING_IDENTITY_TEST');
if (!is_resource($server)) { throw new RuntimeException('Cannot start loopback server.'); }
fclose($pipes[0]);

try {
    $reads_only = ($argv[1] ?? '') === '--reads-only';
    $cookie = '';
    $ready = false;
    for ($i = 0; $i < 60; $i++) {
        try { $ready = identityRequest($base, '/health', $cookie)['ready'] === true; }
        catch (RuntimeException $e) { /* Wait for startup. */ }
        if ($ready) { break; }
        usleep(50000);
    }
    identityCheck($ready, 'loopback fixture starts');
    $endpoint = '/backend/public/api_bookings.php';
    $payload = [
        'client_name' => 'Synthetic Alice', 'client_email' => 'alice@example.invalid',
        'service_type' => 'Synthetic Training', 'appointment_type_id' => 7,
        'appointment_date' => gmdate('Y-m-d', time() + 7 * 86400), 'appointment_time' => '09:00',
        'location_type' => 'client_address', 'client_address' => 'Synthetic replacement venue',
        'overwrite_profile' => true, 'use_credit' => true,
    ];
    $state = identityRequest($base, '/state', $cookie);

    if (!$reads_only) {
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($payload));
        $after = identityRequest($base, '/state', $cookie);
        identityCheck(isset($result['error']) && $after === $state,
            'anonymous credit/address submission must be rejected with zero writes; observed '
            . json_encode(['success' => $result['success'] ?? false, 'error' => $result['error'] ?? '',
                'used_credits' => identityRows($after, 'credits')[0]['used_credits'], 'address' => identityRows($after, 'clients')[0]['address']]));
    }

    foreach ([0 => 'anonymous', 2 => 'different portal owner', 999 => 'deleted session owner', 3 => 'archived owner'] as $id => $label) {
        identityRequest($base, '/identity?id=' . $id, $cookie);
        $email = $id === 3 ? 'archived@example.invalid' : 'alice@example.invalid';
        $profile = identityRequest($base, $endpoint . '?action=profile&email=' . $email . '&dog_names=Buddy', $cookie);
        identityCheck($profile === ['client' => null, 'pets' => []], $label . ' gets no stored profile or pets');
        $credits = identityRequest($base, $endpoint . '?action=credits&email=' . $email . '&appointment_type_id=7', $cookie);
        identityCheck($credits === ['credits' => []], $label . ' gets no credit details');
        if ($reads_only) { continue; }
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, ['client_email' => $email])));
        identityCheck(isset($result['error']) && !isset($result['booking_id']), $label . ' cannot adopt an existing client');
        identityCheck(identityRequest($base, '/state', $cookie) === $state, $label . ' causes zero database writes');
    }
    identityRequest($base, '/identity?id=0&admin=1', $cookie);
    $profile = identityRequest($base, $endpoint . '?action=profile&email=alice@example.invalid&dog_names=Buddy', $cookie);
    $credits = identityRequest($base, $endpoint . '?action=credits&email=alice@example.invalid&appointment_type_id=7', $cookie);
    identityCheck($profile === ['client' => null, 'pets' => []] && $credits === ['credits' => []],
        'admin-only session does not authorize client profile or credit lookup');
    identityRequest($base, '/identity?id=4', $cookie);
    $profile = identityRequest($base, $endpoint . '?action=profile&email=alice@example.invalid&dog_names=Buddy', $cookie);
    identityCheck((identityRow($profile, 'client')['address'] ?? '') === 'Duplicate-email owner venue'
        && $profile['pets'] === [null], 'duplicate-email owner reads only their own profile and pets');
    $credits = identityRequest($base, $endpoint . '?action=credits&email=alice@example.invalid&appointment_type_id=7', $cookie);
    identityCheck($credits === ['credits' => []], 'duplicate-email owner cannot read the other owner credits');
    identityRequest($base, '/identity?id=1', $cookie);
    $profile = identityRequest($base, $endpoint . '?action=profile&email=bob@example.invalid&dog_names=Other', $cookie);
    $credits = identityRequest($base, $endpoint . '?action=credits&email=bob@example.invalid&appointment_type_id=7', $cookie);
    identityCheck($profile === ['client' => null, 'pets' => []] && $credits === ['credits' => []],
        'active owner cannot query another email');
    if ($reads_only) {
        identityRequest($base, '/identity?id=1', $cookie);
        $profile = identityRequest($base, $endpoint . '?action=profile&email=alice@example.invalid&dog_names=Buddy', $cookie);
        identityCheck((identityRow($profile, 'client')['address'] ?? '') === 'Synthetic original venue'
            && (identityRows($profile, 'pets')[0]['breed'] ?? '') === 'Original breed', 'owner retains profile and pet prefill');
        $credits = identityRequest($base, $endpoint . '?action=credits&email=alice@example.invalid&appointment_type_id=7', $cookie);
        identityCheck((int) (identityRows($credits, 'credits')[0]['remaining'] ?? 0) === 10, 'owner retains credit lookup');
        echo "Public booking read authorization regressions passed.\n";
        return;
    }

    identityRequest($base, '/identity?id=0&admin=1', $cookie);
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, ['client_id' => 1, 'portal_client_id' => 1])));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $state,
        'admin-only session and posted identity hints cannot claim client ownership');

    identityRequest($base, '/identity?id=0', $cookie);
    foreach ([['use_credit' => false, 'overwrite_profile' => false, 'client_address' => ''],
        ['use_credit' => false, 'form_responses' => [8 => ['Changed name', 'Changed address', 'Changed breed']], 'dog_names' => 'Buddy'],
        ['booking_form_id' => 9, 'booking_intake_fields' => ['Forged name', 'alice@example.invalid'], 'client_email' => 'new-intake@example.invalid'],
        ['client_email' => '', 'form_responses' => [9 => ['Forged name', 'alice@example.invalid']]]] as $changes) {
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, $changes)));
        identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $state,
            'guest cannot reuse stored address or modify identity through mapped forms');
    }

    foreach ([
        [0, ['client_email' => 'conflict-guest@example.invalid', 'form_responses' => [9 => ['Guest', 'alice@example.invalid']]]],
        [1, ['form_responses' => [9 => ['Alice', 'bob@example.invalid']]]],
        [0, ['client_email' => '', 'form_responses' => [10 => ['conflict-guest@example.invalid', 'alice@example.invalid']]]],
        [1, ['form_responses' => [10 => ['alice@example.invalid', 'bob@example.invalid']]]],
        [1, ['form_responses' => [9 => ['Alice', 'alice@example.invalid'], 10 => ['alice@example.invalid', 'bob@example.invalid']]]],
    ] as [$id, $changes]) {
        identityRequest($base, '/identity?id=' . $id, $cookie);
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, $changes)));
        identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $state,
            'every conflicting mapped email is rejected before any database writes: ' . json_encode($changes));
    }

    $login = identityLogin($base, 'bob@example.invalid', 'synthetic-booking-password', $cookie);
    identityCheck($login['status'] === 302, 'existing login authenticates another synthetic client');
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($payload));
    // Login activity is expected; booking/profile/credit state must stay unchanged.
    $after = identityRequest($base, '/state', $cookie);
    $before_login = $state;
    unset($after['activity'], $before_login['activity']);
    identityCheck(isset($result['error']) && $after === $before_login, 'real different-client login cannot claim Alice');
    $login = identityLogin($base, 'alice@example.invalid', 'incorrect-password', $cookie);
    identityCheck($login['status'] === 200, 'incorrect password does not authenticate');
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($payload));
    identityCheck(isset($result['error']), 'failed login does not authorize existing-email booking');
    $login = identityLogin($base, 'alice@example.invalid', 'synthetic-booking-password', $cookie);
    identityCheck($login['status'] === 302 && $login['location'] === '/backend/public/book.php?type_id=7',
        'existing owner login returns to public booking');
    $profile = identityRequest($base, $endpoint . '?action=profile&email=alice@example.invalid&dog_names=Buddy', $cookie);
    identityCheck((identityRow($profile, 'client')['address'] ?? '') === 'Synthetic original venue'
        && (identityRows($profile, 'pets')[0]['breed'] ?? '') === 'Original breed', 'owner retains profile and pet prefill');
    $credits = identityRequest($base, $endpoint . '?action=credits&email=alice@example.invalid&appointment_type_id=7', $cookie);
    identityCheck((int) (identityRows($credits, 'credits')[0]['remaining'] ?? 0) === 10, 'owner retains credit lookup');
    $wrong = identityRequest($base, $endpoint . '?action=profile&email=bob@example.invalid&dog_names=Other', $cookie);
    identityCheck($wrong === ['client' => null, 'pets' => []], 'owner cannot query another email');

    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, [
        'pet_ids' => [1, 2], 'form_responses' => [9 => ['Alice', 'alice@example.invalid']],
    ])));
    identityCheck(($result['success'] ?? false) === true && ($result['credit_applied'] ?? false) === true,
        'owner can book, overwrite address, and use credit');
    $state = identityRequest($base, '/state', $cookie);
    identityCheck((int) identityRows($state, 'credits')[0]['used_credits'] === 1 && $state['transactions'] === 1,
        'owner booking debits one credit and records one transaction');
    identityCheck(identityRows($state, 'clients')[0]['address'] === 'Synthetic replacement venue'
        && (int) identityRows($state, 'links')[0]['pet_id'] === 1 && count(identityRows($state, 'links')) === 1,
        'owner address changes and foreign pet ID is excluded');

    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, [
        'use_credit' => false, 'appointment_time' => '10:00', 'overwrite_profile' => false, 'client_address' => 'Ignored replacement',
    ])));
    identityCheck(($result['success'] ?? false) === true, 'owner can decline profile overwrite');
    $state = identityRequest($base, '/state', $cookie);
    identityCheck(identityRows($state, 'clients')[0]['address'] === 'Synthetic replacement venue'
        && identityRows($state, 'bookings')[1]['location'] === 'Synthetic replacement venue', 'declined overwrite keeps saved address');

    identityRequest($base, '/identity?id=0', $cookie);
    $guest = array_replace($payload, ['appointment_time' => '11:00', 'client_name' => 'New Guest', 'client_email' => 'new-guest@example.invalid', 'use_credit' => false, 'dog_names' => 'Guest dog',
        'form_responses' => [9 => ['New Guest', 'new-guest@example.invalid']]]);
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($guest));
    identityCheck(($result['success'] ?? false) === true && ($result['credit_applied'] ?? true) === false, 'new-email guest can still book without login');
    $state = identityRequest($base, '/state', $cookie);
    identityCheck(count(identityRows($state, 'clients')) === 5 && count(identityRows($state, 'pets')) === 3
        && (int) identityRows($state, 'bookings')[2]['client_id'] === (int) identityRows($state, 'clients')[4]['id']
        && (int) identityRows($state, 'credits')[0]['used_credits'] === 1, 'guest gets a separate new client and pet without touching existing credits');
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($guest));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $state,
        'anonymous retry cannot mutate the client created by the first request');

    identityRequest($base, '/identity?id=4', $cookie);
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, [
        'appointment_time' => '12:00', 'client_address' => '', 'overwrite_profile' => false, 'dog_names' => 'Buddy',
    ])));
    identityCheck(($result['success'] ?? false) === true && ($result['credit_applied'] ?? true) === false,
        'duplicate-email owner can book without claiming another owner credit');
    $state = identityRequest($base, '/state', $cookie);
    identityCheck((int) identityRows($state, 'bookings')[3]['client_id'] === 4
        && identityRows($state, 'bookings')[3]['location'] === 'Duplicate-email owner venue'
        && (int) identityRows($state, 'credits')[0]['used_credits'] === 1,
        'duplicate-email owner booking uses their own client ID and stored address');

    identityRequest($base, '/identity?id=0', $cookie);
    identityRequest($base, '/fault?enabled=1', $cookie);
    $failed_guest = array_replace($guest, ['appointment_time' => '13:00', 'client_email' => '', 'form_responses' => [9 => ['New Guest', 'rollback-guest@example.invalid']]]);
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($failed_guest));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $state,
        'failed new-guest booking rolls back client and pet writes');
    identityRequest($base, '/fault?enabled=0', $cookie);
    $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($failed_guest));
    identityCheck(($result['success'] ?? false) === true, 'new guest can retry after rolled-back failure');
    echo "Public booking identity regressions passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, (string) file_get_contents($temp . '/server.log'));
    throw $e;
} finally {
    proc_terminate($server);
    proc_close($server);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- only test-created files in a random private directory.
    foreach (glob($temp . '/*') ?: [] as $file) { unlink($file); }
    rmdir($temp);
}
