#!/usr/bin/env php
<?php
/** Real cross-process HTTP races against a newly-created, loopback-only MySQL schema. */
if (getenv('BDTA_SCHEDULE_MYSQL_TEST')!=='1') { echo "SKIP: enable BDTA_SCHEDULE_MYSQL_TEST with a disposable loopback MySQL instance.\n"; exit; }
$port=getenv('BDTA_SCHEDULE_MYSQL_PORT');
$user=getenv('BDTA_SCHEDULE_MYSQL_USER');
$password=getenv('BDTA_SCHEDULE_MYSQL_PASSWORD');
if ($port===false || !ctype_digit($port) || (int)$port<1 || (int)$port>65535 || $user===false || $user==='' || $password===false) {
    throw new RuntimeException('Explicit disposable MySQL port/user/password required.');
}
require_once dirname(__DIR__).'/backend/includes/database.php';
function scheduleCheck(bool $condition,string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    echo 'PASS: '.$label.PHP_EOL;
}
/** @return array<string,mixed> */
function scheduleRequest(string $base,string $path,string &$cookie): array {
    $context=stream_context_create(['http'=>['header'=>$cookie===''?'':'Cookie: '.$cookie,"timeout"=>10,'ignore_errors'=>true]]);
    $stream=@fopen($base.$path,'r',false,$context);
    if ($stream===false) { throw new RuntimeException('Loopback worker unavailable.'); }
    $meta=stream_get_meta_data($stream); $body=stream_get_contents($stream); fclose($stream);
    foreach (is_array($meta['wrapper_data']??null)?$meta['wrapper_data']:[] as $line) {
        if (is_string($line) && str_starts_with($line,'Set-Cookie: ')) { $cookie=explode(';',substr($line,12))[0]; }
    }
    if ($body==='ready') { return ['ready'=>true]; }
    return assoc_row(json_decode($body===false?'':$body,true,512,JSON_THROW_ON_ERROR));
}
/** @param list<string> $addresses
 * @param list<string> $cookies
 * @param list<array{path:string,payload:array<string,mixed>}> $requests
 * @return list<array<string,mixed>> */
function scheduleRace(array $addresses,array $cookies,array $requests): array {
    $race=bin2hex(random_bytes(8)); $streams=[];
    foreach ($requests as $index=>$request) {
        $socket=stream_socket_client('tcp://'.$addresses[$index],$errno,$errstr,10);
        if ($socket===false) { throw new RuntimeException('Cannot connect to race worker.'); }
        stream_set_timeout($socket,15);
        $body=scalar_string(json_encode($request['payload']));
        fwrite($socket,'POST '.$request['path']." HTTP/1.1\r\nHost: ".$addresses[$index]."\r\nContent-Type: application/json\r\nCookie: ".$cookies[$index]
            ."\r\nX-Synthetic-Race: ".$race."\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
        $streams[]=$socket;
    }
    $results=[];
    foreach ($streams as $socket) {
        $response=stream_get_contents($socket); fclose($socket);
        if ($response===false || !str_contains($response,"\r\n\r\n")) { throw new RuntimeException('Missing race response.'); }
        [$headers,$body]=explode("\r\n\r\n",$response,2);
        if (!str_starts_with($headers,'HTTP/1.1 200')) { throw new RuntimeException('Race controller failed: '.$body); }
        $results[]=assoc_row(json_decode($body,true,512,JSON_THROW_ON_ERROR));
    }
    return $results;
}
$schema='bdta_schedule_test_'.bin2hex(random_bytes(8));
$temp=sys_get_temp_dir().'/'.$schema;
if (!mkdir($temp,0700)) { throw new RuntimeException('Cannot create private race directory.'); }
$root=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
// The only schema created/dropped is this test's random, fixed-prefix identifier.
$root->exec('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$servers=[]; $addresses=[]; $cookies=[];
try {
    foreach (['DB_TYPE'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>$schema,'DB_USER'=>$user,'DB_PASSWORD'=>$password] as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
    $conn=(new Database())->getConnection();
    session_start();
    require_once dirname(__DIR__).'/backend/includes/config.php';
    $conn->exec("UPDATE settings SET setting_value='' WHERE is_secret=1 OR setting_key IN ('smtp_host','google_calendar_credentials_file','google_oauth_client_id')");
    $conn->exec("UPDATE settings SET setting_value='0' WHERE setting_key IN ('google_calendar_enabled','stripe_enabled')");
    $conn->exec("INSERT INTO clients (id,name,email,address) VALUES (1,'Synthetic owner','owner@example.invalid','Synthetic venue')");
    $conn->exec("UPDATE admin_users SET username='synthetic-one',password_hash='unused',email='one@example.invalid' WHERE id=1");
    $conn->exec("INSERT INTO admin_users (id,username,password_hash,email) VALUES (2,'synthetic-two','unused','two@example.invalid')");
    $conn->exec("INSERT INTO pets (id,client_id,name) VALUES (1,1,'Synthetic dog')");
    $conn->exec("INSERT INTO appointment_types (id,name,admin_user_id,is_active,portal_available,public_available,available_days,available_start_time,available_end_time,time_slot_interval,advance_booking_min_days,advance_booking_max_days,location_types,duration_minutes)
        VALUES (7,'Ordinary',1,1,1,1,'[0,1,2,3,4,5,6]','09:00','17:00',60,1,30,'[\"client_address\"]',60),
        (8,'Other trainer',2,1,1,1,'[0,1,2,3,4,5,6]','09:00','17:00',60,1,30,'[\"client_address\"]',60),
        (9,'Class',1,1,1,1,'[0,1,2,3,4,5,6]','09:00','17:00',60,1,30,'[\"client_address\"]',60),
        (10,'Resource',1,1,1,1,'[0,1,2,3,4,5,6]','09:00','17:00',60,1,30,'[\"client_address\"]',60)");
    $conn->exec("UPDATE appointment_types SET is_group_class=1,max_participants=2,group_class_location='Synthetic venue' WHERE id=9");
    $conn->exec('UPDATE appointment_types SET uses_resource=1,resource_capacity=2 WHERE id=10');
    // Widen the check/write race deterministically; this trigger touches synthetic rows only.
    $conn->exec('CREATE TRIGGER slow_booking_insert BEFORE INSERT ON bookings FOR EACH ROW DO SLEEP(0.3)');
    $conn->exec('CREATE TRIGGER slow_booking_move BEFORE UPDATE ON bookings FOR EACH ROW DO SLEEP(0.3)');
    $config=$temp.'/config.json';
    file_put_contents($config,json_encode(['schema'=>$schema,'port'=>(int)$port,'user'=>$user,'password'=>$password,'temp'=>$temp],JSON_THROW_ON_ERROR));
    for ($index=0;$index<2;$index++) {
        $listener=stream_socket_server('tcp://127.0.0.1:0');
        if ($listener===false) { throw new RuntimeException('Cannot reserve race port.'); }
        $address=stream_socket_get_name($listener,false); fclose($listener);
        if ($address===false) { throw new RuntimeException('Cannot resolve race port.'); }
        $addresses[]=$address; mkdir($temp.'/worker'.$index,0700);
        putenv('BDTA_SCHEDULE_WORKER=1'); putenv('BDTA_SCHEDULE_CONFIG='.$config); putenv('BDTA_SCHEDULE_INDEX='.$index);
        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed executable/router and loopback address, no shell.
        $server=proc_open([PHP_BINARY,'-d','extension=pdo_mysql','-d','session.save_path='.$temp.'/worker'.$index,
            '-d','allow_url_fopen=0','-d','display_errors=stderr','-d','disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open',
            '-S',$address,'-t',dirname(__DIR__),__DIR__.'/support/booking_schedule_mysql_fixture.php'],
            [0=>['pipe','r'],1=>['file',$temp.'/worker'.$index.'.log','a'],2=>['file',$temp.'/worker'.$index.'.log','a']],$pipes);
        if (!is_resource($server)) { throw new RuntimeException('Cannot start race worker.'); }
        fclose($pipes[0]); $servers[]=$server; $cookie=''; $ready=false;
        for ($i=0;$i<60;$i++) { try { $ready=(scheduleRequest('http://'.$address,'/health',$cookie)['ready']??false)===true; } catch(RuntimeException $e) {} if($ready){break;} usleep(50000); }
        scheduleCheck($ready,'independent loopback worker starts');
        scheduleRequest('http://'.$address,'/identity',$cookie); $cookies[]=$cookie;
    }
    $public='/backend/public/api_bookings.php'; $portal='/portal/api_book_credit.php'; $move='/portal/api_appointments.php';
    $day=(new DateTimeImmutable('+7 days'))->format('Y-m-d');
    $payload=['action'=>'book','client_name'=>'Synthetic owner','client_email'=>'owner@example.invalid','appointment_type_id'=>7,'service_type'=>'Ordinary',
        'appointment_date'=>$day,'appointment_time'=>'09:00','location_type'=>'client_address','use_credit'=>false,'pet_ids'=>[1]];
    foreach ([[$public,$public],[$public,$portal],[$portal,$portal]] as $paths) {
        $results=scheduleRace($addresses,$cookies,[['path'=>$paths[0],'payload'=>$payload],['path'=>$paths[1],'payload'=>$payload]]);
        $successes=count(array_filter($results,static fn(array $row):bool=>($row['success']??false)===true));
        if ($successes!==1) { fwrite(STDERR,scalar_string(json_encode($results)).PHP_EOL); }
        scheduleCheck($successes===1 && safe_int($conn->query('SELECT COUNT(*) FROM bookings')->fetchColumn())===1,'concurrent '.implode(' / ',$paths).' stores exactly one ordinary booking');
        $conn->exec('DELETE FROM appointment_pets'); $conn->exec('DELETE FROM bookings');
    }
    foreach ([[7,9], [9,7]] as $types) {
        $results=scheduleRace($addresses,$cookies,[['path'=>$public,'payload'=>array_replace($payload,['appointment_type_id'=>$types[0]])],
            ['path'=>$portal,'payload'=>array_replace($payload,['appointment_type_id'=>$types[1]])]]);
        scheduleCheck(count(array_filter($results,static fn(array $row):bool=>($row['success']??false)===true))===1
            && safe_int($conn->query('SELECT COUNT(*) FROM bookings')->fetchColumn())===1,'concurrent ordinary/class requests store exactly one trainer booking');
        $conn->exec('DELETE FROM appointment_pets'); $conn->exec('DELETE FROM bookings');
    }
    foreach ([9,10] as $type) {
        $seed=$conn->prepare("INSERT INTO bookings (client_id,appointment_type_id,admin_user_id,client_name,client_email,service_type,appointment_date,appointment_time,duration_minutes,status) VALUES (1,?,1,'Synthetic owner','owner@example.invalid','Synthetic',?,'09:00',60,'confirmed')");
        $seed->execute([$type,$day]);
        $candidate=array_replace($payload,['appointment_type_id'=>$type]);
        $results=scheduleRace($addresses,$cookies,[['path'=>$public,'payload'=>$candidate],['path'=>$portal,'payload'=>$candidate]]);
        scheduleCheck(count(array_filter($results,static fn(array $row):bool=>($row['success']??false)===true))===1
            && safe_int($conn->query('SELECT COUNT(*) FROM bookings')->fetchColumn())===2,'concurrent requests preserve the last class/resource capacity unit');
        $conn->exec('DELETE FROM appointment_pets'); $conn->exec('DELETE FROM bookings');
    }
    $seed=$conn->prepare("INSERT INTO bookings (id,client_id,appointment_type_id,admin_user_id,client_name,client_email,service_type,appointment_date,appointment_time,duration_minutes,status) VALUES (?,1,7,1,'Synthetic owner','owner@example.invalid','Ordinary',?,?,60,'confirmed')");
    $seed->execute([100,$day,'13:00']); $seed->execute([101,$day,'15:00']);
    $results=scheduleRace($addresses,$cookies,[['path'=>$move,'payload'=>['action'=>'reschedule','booking_id'=>100,'new_date'=>$day,'new_time'=>'11:00']],
        ['path'=>$move,'payload'=>['action'=>'reschedule','booking_id'=>101,'new_date'=>$day,'new_time'=>'11:00']]]);
    scheduleCheck(count(array_filter($results,static fn(array $row):bool=>($row['success']??false)===true))===1
        && safe_int($conn->query("SELECT COUNT(*) FROM bookings WHERE appointment_time='11:00'")->fetchColumn())===1,'concurrent reschedules store exactly one winner and preserve the other booking');
    $results=scheduleRace($addresses,$cookies,[['path'=>$public,'payload'=>array_replace($payload,['appointment_time'=>'10:00'])],
        ['path'=>$move,'payload'=>['action'=>'reschedule','booking_id'=>100,'new_date'=>$day,'new_time'=>'10:00']]]);
    scheduleCheck(count(array_filter($results,static fn(array $row):bool=>($row['success']??false)===true))===1
        && safe_int($conn->query("SELECT COUNT(*) FROM bookings WHERE appointment_time='10:00'")->fetchColumn())===1,'booking and reschedule share duplicate-slot protection');
    $results=scheduleRace($addresses,$cookies,[['path'=>$public,'payload'=>array_replace($payload,['appointment_time'=>'16:00'])],
        ['path'=>$portal,'payload'=>array_replace($payload,['appointment_time'=>'16:00','appointment_type_id'=>8])]]);
    scheduleCheck(count(array_filter($results,static fn(array $row):bool=>($row['success']??false)===true))===2,'different assigned trainers retain concurrent availability');
    echo "Booking schedule concurrency regressions passed.\n";
} catch(Throwable $e) {
    foreach(glob($temp.'/*.log')?:[] as $log) { fwrite(STDERR,(string)file_get_contents($log)); }
    throw $e;
} finally {
    foreach($servers as $server) { proc_terminate($server); proc_close($server); }
    session_write_close();
    // Fixed prefix plus random identifier created above; never an application DB name.
    $root->exec('DROP DATABASE '.$schema);
    foreach(glob($temp.'/worker*')?:[] as $file) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- PHP session files in this test's newly-created private worker directories only.
        if(is_dir($file)) { foreach(glob($file.'/*')?:[] as $child) { unlink($child); } rmdir($file); }
    }
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- only files under this test's private random directory.
    foreach(glob($temp.'/*')?:[] as $file) { if(is_file($file)){unlink($file);} }
    rmdir($temp);
    foreach(['BDTA_SCHEDULE_WORKER','BDTA_SCHEDULE_CONFIG','BDTA_SCHEDULE_INDEX'] as $key) { putenv($key); }
}
