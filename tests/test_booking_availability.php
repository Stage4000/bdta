#!/usr/bin/env php
<?php
/** Real booking/rescheduling HTTP regressions with disposable synthetic data. */

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
$temp = sys_get_temp_dir() . '/bdta-booking-availability-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) { throw new RuntimeException('Cannot create private test directory.'); }
$base = 'http://' . $address;
putenv('BDTA_BOOKING_AVAILABILITY_TEST=1');
// Fixed router/executable, reserved loopback port, shell-free arguments, disabled external transports.
// nosemgrep: php.lang.security.exec-use.exec-use
$server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temp, '-d', 'allow_url_fopen=0',
    '-d', 'display_errors=stderr',
    '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open',
    '-S', $address, '-t', dirname(__DIR__), __DIR__ . '/support/booking_availability_fixture.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes);
putenv('BDTA_BOOKING_AVAILABILITY_TEST');
if (!is_resource($server)) { throw new RuntimeException('Cannot start loopback server.'); }
fclose($pipes[0]);
try {
    $cookie = '';
    $ready = false;
    for ($i = 0; $i < 60; $i++) {
        try { $ready = identityRequest($base, '/health', $cookie)['ready'] === true; }
        catch (RuntimeException $e) { /* Wait for startup. */ }
        if ($ready) { break; }
        usleep(50000);
    }
    identityCheck($ready, 'loopback fixture starts');
    identityRequest($base, '/identity?id=1', $cookie);
    $public = '/backend/public/api_bookings.php';
    $portal = '/portal/api_book_credit.php';
    $reschedule = '/portal/api_appointments.php';
    $day = date('Y-m-d', strtotime('next Monday +7 days'));
    $payload = ['action' => 'book', 'client_name' => 'Alice', 'client_email' => 'alice@example.invalid',
        'appointment_type_id' => 7, 'service_type' => 'Synthetic Training',
        'appointment_date' => $day, 'appointment_time' => '09:00',
        'location_type' => 'client_address', 'use_credit' => false];
    $slots = identityRequest($base, $public . '?appointment_type_id=7&date=' . $day, $cookie);
    identityCheck(!in_array('03:11', is_array($slots['available_slots'] ?? null) ? $slots['available_slots'] : [], true),
        'configured hours do not advertise the audit off-hours slot');
    foreach ([$public, $portal] as $endpoint) {
        foreach ([
            ['appointment_time' => '03:11'], ['appointment_time' => '09:17'],
            ['appointment_time' => '24:00'], ['appointment_time' => '09:00:59'],
            ['appointment_date' => date('Y-m-d', strtotime('-1 day'))],
            ['appointment_date' => '2026-02-30'],
            ['appointment_date' => date('Y-m-d', strtotime('next Saturday +7 days'))],
            ['appointment_date' => date('Y-m-d', strtotime('+40 days'))],
            ['appointment_date' => date('Y-m-d')],
            ['appointment_type_id' => 11], ['appointment_type_id' => 99999],
            ['location_type' => 'fixed', 'client_address' => '', 'location_value' => ''],
            ['location_type' => 'webcall', 'location_value' => ''],
        ] as $changes) {
            $before = identityRequest($base, '/state', $cookie);
            $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, $changes)));
            identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
                $endpoint . ' rejects invalid schedule/type/venue before writes: ' . json_encode($changes));
        }
    }
    $result = identityRequest($base, $public, $cookie, (string) json_encode($payload));
    identityCheck(($result['success'] ?? false) === true, 'public booking accepts an advertised slot');
    $booking_id = is_int($result['booking_id'] ?? null) ? $result['booking_id'] : 0;
    foreach ([$public, $portal] as $endpoint) {
        $before = identityRequest($base, '/state', $cookie);
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, ['duration_minutes'=>1])));
        identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
            'ordinary occupied slot is rejected across booking APIs despite caller duration');
    }
    $slots = identityRequest($base, $public . '?appointment_type_id=7&date=' . $day, $cookie);
    identityCheck(!in_array('09:00', is_array($slots['available_slots'] ?? null) ? $slots['available_slots'] : [], true),
        'stored ordinary booking removes its slot from availability');
    $result = identityRequest($base, $portal, $cookie, (string) json_encode(array_replace($payload, ['appointment_type_id'=>12])));
    identityCheck(($result['success'] ?? false) === true, 'another assigned admin retains the same valid slot');

    foreach ([['new_time'=>'03:11'], ['new_time'=>'24:00'], ['new_time'=>'09:00:59'],
        ['new_date'=>'2026-02-30'], ['new_date'=>date('Y-m-d', strtotime('next Saturday +7 days'))],
        ['new_date'=>date('Y-m-d', strtotime('+40 days'))], ['new_time'=>'10:00']] as $changes) {
        $before = identityRequest($base, '/state', $cookie);
        $result = identityRequest($base, $reschedule, $cookie, (string) json_encode(array_replace([
            'action'=>'reschedule', 'booking_id'=>$booking_id, 'new_date'=>$day, 'new_time'=>'16:00',
        ], $changes)));
        identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
            'reschedule rejects invalid or reserved slots without moving the original: ' . json_encode($changes));
    }
    $result = identityRequest($base, $reschedule, $cookie, (string) json_encode([
        'action'=>'reschedule', 'booking_id'=>$booking_id, 'new_date'=>$day, 'new_time'=>'09:00:00',
    ]));
    identityCheck(($result['success'] ?? false) === true, 'reschedule excludes the current booking and accepts zero seconds');
    $result = identityRequest($base, $reschedule, $cookie, (string) json_encode([
        'action'=>'reschedule', 'booking_id'=>$booking_id, 'new_date'=>$day, 'new_time'=>'16:00',
    ]));
    identityCheck(($result['success'] ?? false) === true, 'reschedule accepts an advertised free slot');
    $state = identityRequest($base, '/state', $cookie);
    identityCheck((identityRows($state, 'schedule')[0]['appointment_time'] ?? '') === '16:00'
        && (int) identityRows($state, 'credits')[0]['used_credits'] === 0, 'reschedule changes the slot without another credit debit');

    $tuesday = (new DateTimeImmutable($day))->modify('+1 days')->format('Y-m-d');
    $buffered = array_replace($payload, ['appointment_type_id'=>17, 'appointment_date'=>$tuesday, 'appointment_time'=>'11:00']);
    $result = identityRequest($base, $public, $cookie, (string) json_encode($buffered));
    identityCheck(($result['success'] ?? false) === true, 'configured buffered appointment can be booked');
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $portal, $cookie, (string) json_encode(array_replace($payload, [
        'appointment_date'=>$tuesday, 'appointment_time'=>'10:00',
    ])));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'existing appointment buffers block otherwise adjacent slots');

    $wednesday = (new DateTimeImmutable($day))->modify('+2 days')->format('Y-m-d');
    $group = array_replace($payload, ['appointment_type_id'=>15, 'appointment_date'=>$wednesday]);
    foreach ([$public, $portal] as $endpoint) {
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode($group));
        identityCheck(($result['success'] ?? false) === true, 'group class permits participants up to configured capacity');
    }
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $public, $cookie, (string) json_encode($group));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'full group class rejects another participant without writes');

    $ordinary = array_replace($payload, ['appointment_date'=>$wednesday, 'appointment_time'=>'11:00']);
    $result = identityRequest($base, $public, $cookie, (string) json_encode($ordinary));
    identityCheck(($result['success'] ?? false) === true, 'ordinary booking occupies the class trainer');
    $occupied_class = array_replace($group, ['appointment_time'=>'11:00']);
    foreach ([$public, $portal] as $endpoint) {
        foreach ([15, 16] as $class_type) {
            $slots = identityRequest($base, $public . '?appointment_type_id=' . $class_type . '&date=' . $wednesday, $cookie);
            $class_slots = is_array($slots['available_slots'] ?? null) ? $slots['available_slots'] : [];
            $before = identityRequest($base, '/state', $cookie);
            $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($occupied_class, ['appointment_type_id'=>$class_type])));
            identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
                $endpoint . ' rejects class/ordinary trainer overlap: ' . json_encode([
                    'class_type'=>$class_type, 'advertised'=>in_array('11:00', $class_slots, true),
                    'accepted'=>($result['success'] ?? false) === true,
                ]));
            identityCheck(!in_array('11:00', $class_slots, true), 'class availability excludes its trainer occupied by an ordinary booking');
        }
    }
    $class_booking_id = 0;
    foreach (identityRows($before, 'schedule') as $row) {
        if (($row['appointment_type_id'] ?? 0) === 15 && ($row['appointment_date'] ?? '') === $wednesday) {
            $class_booking_id = (int) $row['id']; break;
        }
    }
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $reschedule, $cookie, (string) json_encode([
        'action'=>'reschedule', 'booking_id'=>$class_booking_id, 'new_date'=>$wednesday, 'new_time'=>'11:00',
    ]));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'class reschedule rejects an ordinary trainer conflict without moving participants');
    identityRequest($base, '/type?id=15&start=11:00&end=12:00', $cookie);
    $dates = identityRequest($base, $public . '?action=available_dates&appointment_type_id=15&from=' . $wednesday . '&to=' . $wednesday, $cookie);
    identityCheck(!in_array($wednesday, is_array($dates['available_dates'] ?? null) ? $dates['available_dates'] : [], true), 'month availability excludes a class date with only an ordinary-occupied slot');
    identityRequest($base, '/type?id=15&start=09:00&end=17:00', $cookie);
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $portal, $cookie, (string) json_encode(array_replace($payload, ['appointment_date'=>$wednesday])));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'ordinary booking also rejects the trainer occupied by a class');
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $portal, $cookie, (string) json_encode(array_replace($group, ['appointment_date'=>$tuesday, 'appointment_time'=>'10:00'])));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'ordinary booking buffers also block class participants');
    $result = identityRequest($base, $public, $cookie, (string) json_encode(array_replace($ordinary, ['appointment_type_id'=>12, 'appointment_time'=>'12:00'])));
    identityCheck(($result['success'] ?? false) === true, 'other trainer ordinary booking can coexist with the class trainer');
    $other_trainer_id = is_int($result['booking_id'] ?? null) ? $result['booking_id'] : 0;
    identityRequest($base, '/snapshot?id=' . $other_trainer_id . '&booking_admin=0', $cookie);
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $portal, $cookie, (string) json_encode(array_replace($group, ['appointment_time'=>'12:00'])));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'legacy shared trainer booking also blocks the class trainer');
    identityRequest($base, '/snapshot?id=' . $other_trainer_id . '&booking_admin=2', $cookie);
    identityRequest($base, '/type?id=15&start=12:00&end=13:00', $cookie);
    $slots = identityRequest($base, $public . '?appointment_type_id=15&date=' . $wednesday, $cookie);
    identityCheck(in_array('12:00', is_array($slots['available_slots'] ?? null) ? $slots['available_slots'] : [], true), 'class slot at exact ordinary end boundary remains available');
    $dates = identityRequest($base, $public . '?action=available_dates&appointment_type_id=15&from=' . $wednesday . '&to=' . $wednesday, $cookie);
    identityCheck(in_array($wednesday, is_array($dates['available_dates'] ?? null) ? $dates['available_dates'] : [], true), 'month availability preserves a class date served by a different trainer');
    identityRequest($base, '/type?id=15&start=09:00&end=17:00', $cookie);
    foreach ([$public, $portal] as $endpoint) {
        $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($group, ['appointment_time'=>'12:00'])));
        identityCheck(($result['success'] ?? false) === true, 'different trainer conflict preserves class participant capacity');
    }
    $point = array_replace($payload, ['appointment_type_id'=>14, 'appointment_time'=>'10:00',
        'location_type'=>'fixed', 'location_value'=>'Caller venue']);
    $result = identityRequest($base, $public, $cookie, (string) json_encode($point));
    identityCheck(($result['success'] ?? false) === true, 'configured specific point remains bookable');
    $state = identityRequest($base, '/state', $cookie);
    $bookings = identityRows($state, 'bookings');
    identityCheck(($bookings[count($bookings)-1]['location'] ?? '') === 'Synthetic point venue',
        'fixed event venue comes from trusted appointment configuration');
    $per_day = identityRequest($base, $public . '?appointment_type_id=13&date=' . $day, $cookie);
    $per_day_slots = is_array($per_day['available_slots'] ?? null) ? $per_day['available_slots'] : [];
    identityCheck(!in_array('09:00', $per_day_slots, true) && in_array('11:00', $per_day_slots, true),
        'per-day override replaces the recurring slot grid');
    foreach ([$public, $portal] as $endpoint) {
        foreach ([['appointment_type_id'=>13, 'appointment_time'=>'09:00'],
            ['appointment_type_id'=>14, 'appointment_time'=>'11:00']] as $changes) {
            $before = identityRequest($base, '/state', $cookie);
            $result = identityRequest($base, $endpoint, $cookie, (string) json_encode(array_replace($payload, $changes)));
            identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
                'mutations enforce per-day and specific-date custom slots');
        }
    }
    $thursday = (new DateTimeImmutable($day))->modify('+3 days')->format('Y-m-d');
    $resource = array_replace($payload, ['appointment_type_id'=>16, 'appointment_date'=>$thursday, 'pet_ids'=>[1,3]]);
    $result = identityRequest($base, $public, $cookie, (string) json_encode($resource));
    identityCheck(($result['success'] ?? false) === true, 'per-pet resource capacity accepts exactly two owned pets');
    $before = identityRequest($base, '/state', $cookie);
    $result = identityRequest($base, $portal, $cookie, (string) json_encode(array_replace($resource, ['pet_ids'=>[1]])));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'resource capacity rejects another pet independently of class participant count');
    identityRequest($base, '/type?id=7&active=0', $cookie);
    $before = identityRequest($base, '/state', $cookie);
    $move = ['action'=>'reschedule', 'booking_id'=>$booking_id, 'new_date'=>$tuesday, 'new_time'=>'14:00'];
    $result = identityRequest($base, $reschedule, $cookie, (string) json_encode($move));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'reschedule refuses a now-inactive appointment type without moving its booking');
    identityRequest($base, '/type?id=7&active=1', $cookie);
    identityRequest($base, '/fault?enabled=2', $cookie);
    $result = identityRequest($base, $reschedule, $cookie, (string) json_encode($move));
    identityCheck(isset($result['error']) && identityRequest($base, '/state', $cookie) === $before,
        'failed reschedule rolls back booking and change-log state');
    identityRequest($base, '/fault?enabled=0', $cookie);
    $result = identityRequest($base, $reschedule, $cookie, (string) json_encode($move));
    identityCheck(($result['success'] ?? false) === true, 'reschedule can retry after rolled-back failure');
    identityCheck(identityRequest($base, '/calendar_check', $cookie) === ['overlap'=>false, 'adjacent'=>true, 'buffered'=>false],
        'synthetic calendar busy periods honor overlap and configured buffers without provider calls');
    $friday = (new DateTimeImmutable($day))->modify('+4 days')->format('Y-m-d');
    $resource = array_replace($payload, ['appointment_type_id'=>18,'appointment_date'=>$friday]);
    foreach ([$public,$portal] as $endpoint) {
        $result = identityRequest($base,$endpoint,$cookie,(string)json_encode($resource));
        identityCheck(($result['success']??false)===true, 'non-group resource bookings share configured capacity');
    }
    $before=identityRequest($base,'/state',$cookie);
    $result=identityRequest($base,$public,$cookie,(string)json_encode($resource));
    identityCheck(isset($result['error']) && identityRequest($base,'/state',$cookie)===$before, 'non-group resource capacity stops the third booking');
    $result=identityRequest($base,$public,$cookie,(string)json_encode(array_replace($payload,['appointment_date'=>$friday,'appointment_time'=>'14:00'])));
    $long_id=is_int($result['booking_id']??null)?$result['booking_id']:0;
    identityCheck(($result['success']??false)===true, 'snapshot fixture booking is created');
    identityRequest($base,'/snapshot?id='.$long_id.'&duration=120',$cookie);
    $result=identityRequest($base,$public,$cookie,(string)json_encode(array_replace($payload,['appointment_date'=>$friday,'appointment_time'=>'11:00'])));
    identityCheck(($result['success']??false)===true, 'adjacent snapshot blocker is created');
    $before=identityRequest($base,'/state',$cookie);
    $result=identityRequest($base,$reschedule,$cookie,(string)json_encode(['action'=>'reschedule','booking_id'=>$long_id,'new_date'=>$friday,'new_time'=>'10:00']));
    identityCheck(isset($result['error']) && identityRequest($base,'/state',$cookie)===$before, 'reschedule enforces the saved longer duration');
    identityRequest($base,'/snapshot?id='.$long_id.'&duration=120&admin=2',$cookie);
    $before=identityRequest($base,'/state',$cookie);
    $result=identityRequest($base,$reschedule,$cookie,(string)json_encode(['action'=>'reschedule','booking_id'=>$long_id,'new_date'=>$friday,'new_time'=>'11:00']));
    identityCheck(isset($result['error']) && identityRequest($base,'/state',$cookie)===$before, 'reschedule enforces the saved trainer after type reassignment');
    identityRequest($base,'/snapshot?id='.$long_id.'&duration=120&admin=1',$cookie);
    $result=identityRequest($base,$public,$cookie,(string)json_encode(array_replace($payload,['appointment_type_id'=>12,'appointment_date'=>$friday,'appointment_time'=>'12:00'])));
    identityCheck(($result['success']??false)===true, 'other trainer snapshot blocker is created');
    identityRequest($base,'/snapshot?id='.$long_id.'&duration=120&booking_admin=0',$cookie);
    $before=identityRequest($base,'/state',$cookie);
    $result=identityRequest($base,$reschedule,$cookie,(string)json_encode(['action'=>'reschedule','booking_id'=>$long_id,'new_date'=>$friday,'new_time'=>'12:00']));
    identityCheck(isset($result['error']) && identityRequest($base,'/state',$cookie)===$before, 'legacy explicit shared trainer zero blocks every trainer during reschedule');
    identityRequest($base,'/snapshot?id='.$long_id.'&duration=120&booking_admin=1',$cookie);
    $result=identityRequest($base,$public,$cookie,(string)json_encode(array_replace($payload,['appointment_date'=>$thursday,'appointment_time'=>'11:00'])));
    $calendar_id=is_int($result['booking_id']??null)?$result['booking_id']:0;
    identityCheck(($result['success']??false)===true, 'linked Calendar fixture booking is created');
    identityRequest($base,'/snapshot?id='.$calendar_id.'&duration=120',$cookie);
    identityRequest($base,'/calendar?id='.$calendar_id,$cookie);
    foreach (['11:00','12:00'] as $time) {
        $result=identityRequest($base,$reschedule,$cookie,(string)json_encode(['action'=>'reschedule','booking_id'=>$calendar_id,'new_date'=>$thursday,'new_time'=>$time]));
        identityCheck(($result['success']??false)===true, 'reschedule excludes its identified Calendar event at the same or overlapping time');
    }
    identityRequest($base,'/calendar?id='.$calendar_id.'&external='.$thursday.'&external_time=12:00',$cookie);
    $before=identityRequest($base,'/state',$cookie);
    $result=identityRequest($base,$reschedule,$cookie,(string)json_encode(['action'=>'reschedule','booking_id'=>$calendar_id,'new_date'=>$thursday,'new_time'=>'12:00']));
    identityCheck(isset($result['error']) && identityRequest($base,'/state',$cookie)===$before, 'external Calendar event in the exact same window on a later page still blocks reschedule');
    identityRequest($base,'/calendar?id='.$calendar_id,$cookie);
    $class=array_replace($payload,['appointment_type_id'=>15,'appointment_date'=>$thursday,'appointment_time'=>'14:00']);
    foreach ([$public,$portal] as $endpoint) {
        $result=identityRequest($base,$endpoint,$cookie,(string)json_encode($class));
        identityCheck(($result['success']??false)===true, 'linked group events permit subsequent participants below capacity');
    }
    echo "Booking availability regressions passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, (string) file_get_contents($temp . '/server.log'));
    throw $e;
} finally {
    proc_terminate($server);
    proc_close($server);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-created files in a private random directory only.
    foreach (glob($temp . '/*') ?: [] as $file) { unlink($file); }
    rmdir($temp);
}
