#!/usr/bin/env php
<?php
require_once dirname(__DIR__) . '/backend/includes/email_mime_attachments.php';
function check_attachment(bool $value, string $label): void {
    if (!$value) throw new RuntimeException($label);
    echo "PASS: {$label}\n";
}
function reject_attachment(callable $call, string $label): void {
    try { $call(); } catch (InvalidArgumentException | RuntimeException $e) { echo "PASS: {$label}\n"; return; }
    throw new RuntimeException($label);
}
/** @param array<string, mixed> $values */
function mime_part(array $values): stdClass {
    $part = json_decode((string) json_encode($values));
    if (!$part instanceof stdClass) throw new RuntimeException('Invalid synthetic MIME fixture.');
    return $part;
}
$temporary = sys_get_temp_dir() . '/bdta-email-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$prior = getenv('EMAIL_ATTACHMENTS_DIRECTORY');
putenv('EMAIL_ATTACHMENTS_DIRECTORY=' . $temporary . '/private');
$stored = [];
try {
    check_attachment(bdta_email_attachment_name("../../test\r\n.pdf") === 'test.pdf', 'Traversal and header-control display name stripped');
    check_attachment(bdta_email_attachment_name('C:\\temp\\invoice.pdf') === 'invoice.pdf', 'Windows display path stripped');
    check_attachment(bdta_email_attachment_name('<img onerror=alert(1)>.html') === '_img onerror=alert(1)_.html', 'HTML filename kept inert');
    check_attachment(strlen(bdta_email_attachment_name(str_repeat('é',10000)))===180,'Long Unicode display name truncated in bounded work');
    check_attachment(bdta_email_attachment_name("invoice\u{202E}fdp.exe")==='invoicefdp.exe','Bidirectional filename control stripped');
    $stored[] = $a = bdta_email_store_attachment('document.pdf', "synthetic\0payload");
    check_attachment(preg_match('/^[a-f0-9]{64}\.bin$/', bdta_email_string($a['file_name'])) === 1, 'Opaque random disk key');
    $path = bdta_email_verified_attachment_path($a);
    check_attachment(file_get_contents($path) === "synthetic\0payload", 'Exact bytes preserved');
    check_attachment((fileperms($path) & 0777) === 0600, 'Private file permissions');
    foreach (['../outside', '/etc/passwd', 'https://example.invalid/x', 'php://filter', "name\0.bin"] as $key) {
        reject_attachment(fn() => bdta_email_attachment_path($key), 'Reject arbitrary path/key: ' . str_replace("\0", '', $key));
    }
    file_put_contents($path, 'changed');
    reject_attachment(fn() => bdta_email_verified_attachment_path($a), 'Changed bytes fail before send/download');
    $outside = $temporary . '/outside'; file_put_contents($outside, 'outside');
    $linkkey = str_repeat('a', 64) . '.bin'; symlink($outside, $temporary . '/private/' . $linkkey);
    reject_attachment(fn() => bdta_email_attachment_path($linkkey), 'Symlink escape rejected');
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- unlink the test-created symlink itself in this random private fixture directory, never its target.
    unlink($temporary . '/private/' . $linkkey);
    foreach ([dirname(__DIR__) . '/backend/uploads/mail', $temporary . '/../escaped', 'relative/files'] as $bad) {
        putenv('EMAIL_ATTACHMENTS_DIRECTORY=' . $bad);
        reject_attachment(fn() => bdta_email_attachment_directory(true), 'Unsafe storage root rejected');
    }
    symlink(dirname(__DIR__), $temporary . '/public-link');
    putenv('EMAIL_ATTACHMENTS_DIRECTORY=' . $temporary . '/public-link/new');
    reject_attachment(fn() => bdta_email_attachment_directory(true), 'Ancestor symlink into document root rejected');
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- unlink this test-created symlink itself in the random private fixture, never its target.
    unlink($temporary . '/public-link');
    putenv('EMAIL_ATTACHMENTS_DIRECTORY=' . $temporary . '/private');

    $structure = mime_part(['type'=>1,'parts'=>[
        ['type'=>1,'parts'=>[['type'=>0,'subtype'=>'PLAIN'],['type'=>0,'subtype'=>'HTML']]],
        ['type'=>3,'subtype'=>'PDF','encoding'=>3,'bytes'=>24,'disposition'=>'ATTACHMENT','dparameters'=>[['attribute'=>'filename*','value'=>"UTF-8''caf%C3%A9.pdf"]]],
        ['type'=>0,'subtype'=>'PLAIN','encoding'=>4,'bytes'=>20,'disposition'=>'attachment','dparameters'=>[['attribute'=>'filename','value'=>'notes.txt']]],
        ['type'=>5,'subtype'=>'PNG','encoding'=>3,'bytes'=>4,'id'=>'<synthetic-inline>']
    ]]);
    $requested=[];
    $parts=['2'=>base64_encode('%PDF-synthetic'), '3'=>'hello=20world', '4'=>base64_encode('PNG')];
    $received=bdta_email_extract_attachments($structure, function($number)use(&$requested,$parts){$requested[]=$number;return $parts[$number];});
    $stored=array_merge($stored,$received);
    check_attachment($requested === ['2','3','4'], 'Nested message body skipped, named text and inline CID captured');
    check_attachment($received[0]['original_name']==='café.pdf', 'RFC2231 Unicode name decoded');
    check_attachment(file_get_contents(bdta_email_verified_attachment_path($received[1]))==='hello world','Quoted printable bytes decoded');
    check_attachment(bdta_email_mime_is_attachment($structure->parts[2]), 'Attached text excluded from message body');
    $encoded = mime_part(['dparameters'=>[['attribute'=>'filename','value'=>'=?UTF-8?B?Y2Fmw6kucGRm?=']]]);
    check_attachment(bdta_email_mime_parameter($encoded,'filename')==='café.pdf','Encoded-word filename decoded');
    $continuation = mime_part(['dparameters'=>[['attribute'=>'filename*0*','value'=>"UTF-8''caf%C3"],['attribute'=>'filename*1*','value'=>'%A9.pdf']]]);
    check_attachment(bdta_email_mime_parameter($continuation,'filename')==='café.pdf','RFC2231 continuation decoded');
    $mixed_continuation = mime_part(['dparameters'=>[['attribute'=>'filename*0*','value'=>"UTF-8''report-"],['attribute'=>'filename*1','value'=>'%20.txt']]]);
    check_attachment(bdta_email_mime_parameter($mixed_continuation,'filename')==='report-%20.txt','Unencoded RFC2231 segment preserves literal percent sequences');
    $unnamed=bdta_email_extract_attachments(mime_part(['type'=>3,'subtype'=>'PDF','bytes'=>3]),fn()=>'PDF');
    $stored=array_merge($stored,$unnamed);
    check_attachment($unnamed[0]['original_name']==='attachment-1.bin','Unnamed non-text MIME body is retained as a downloadable file');
    $large=mime_part(['type'=>3,'disposition'=>'attachment','bytes'=>BDTA_EMAIL_ATTACHMENT_MAX_BYTES*2]);
    $oversized_fetches = 0;
    $large_result=bdta_email_extract_attachments($large,function() use (&$oversized_fetches): string { $oversized_fetches++; return ''; });
    check_attachment($large_result[0]['status']==='unavailable' && $oversized_fetches === 0,'Oversized MIME part gets visible marker without fetching');
    $bad=mime_part(['type'=>3,'disposition'=>'attachment','encoding'=>3,'bytes'=>8]);
    $bad_result=bdta_email_extract_attachments($bad,fn()=>'invalid!!!');
    check_attachment($bad_result[0]['status']==='unavailable','Invalid Base64 gets visible marker');
    $many=mime_part(['type'=>1,'parts'=>array_fill(0,12,['type'=>3,'disposition'=>'attachment','encoding'=>0,'bytes'=>1])]);
    $count=0;$many_result=bdta_email_extract_attachments($many,function()use(&$count){$count++;return 'x';});
    $stored=array_merge($stored,$many_result);
    check_attachment($count===10 && count($many_result)===11 && $many_result[10]['status']==='unavailable','Count bounded with overflow marker');
    $before=count(glob($temporary.'/private/*.bin') ?: []);
    $two=mime_part(['type'=>1,'parts'=>array_fill(0,2,['type'=>3,'disposition'=>'attachment','encoding'=>0,'bytes'=>1])]);
    $count=0;reject_attachment(function()use($two,&$count){bdta_email_extract_attachments($two,function()use(&$count){if(++$count===2)throw new RuntimeException('Synthetic fetch failure');return 'x';});},'Failed fetch rolls back staged bytes');
    check_attachment(count(glob($temporary.'/private/*.bin') ?: [])===$before,'No orphan blob after MIME failure');
    reject_attachment(fn()=>bdta_email_store_attachment('large.bin',str_repeat('x',BDTA_EMAIL_ATTACHMENT_MAX_BYTES+1)),'Per-file decoded size enforced');
    reject_attachment(fn()=>bdta_email_collect_uploads(['attachments'=>['name'=>array_fill(0,11,'a'),'tmp_name'=>[],'error'=>[]]],11),'Upload file count enforced');
    $total_structure=mime_part(['type'=>1,'parts'=>array_fill(0,2,['type'=>3,'disposition'=>'attachment','encoding'=>0,'bytes'=>8*1024*1024])]);
    $total_result=bdta_email_extract_attachments($total_structure,fn()=>str_repeat('x',8*1024*1024));
    $stored=array_merge($stored,$total_result);
    check_attachment($total_result[0]['status']==='available' && $total_result[1]['status']==='unavailable','Combined decoded size enforced without losing first attachment');
    $deep=mime_part(['type'=>0,'subtype'=>'PLAIN']);
    for($i=0;$i<22;$i++) $deep=(object)['type'=>1,'parts'=>[$deep]];
    reject_attachment(fn()=>bdta_email_extract_attachments($deep,fn()=>''),'Excessive MIME depth rejected');
    reject_attachment(fn()=>bdta_email_collect_uploads(['attachments'=>['name'=>['/etc/passwd'],'tmp_name'=>['/etc/passwd'],'error'=>[0]]],1),'Reject non-HTTP temporary upload path');

    if (in_array('sqlite',PDO::getAvailableDrivers(),true)) {
        $conn=new PDO('sqlite::memory:');$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $conn->exec('CREATE TABLE client_emails(id INTEGER PRIMARY KEY);CREATE TABLE unmatched_emails(id INTEGER PRIMARY KEY);CREATE TABLE email_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,email_kind TEXT,email_id INTEGER,file_name TEXT,original_name TEXT,file_size INTEGER,sha256 TEXT,mime_type TEXT,status TEXT,error_message TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);INSERT INTO unmatched_emails VALUES(1);INSERT INTO client_emails VALUES(2)');
        bdta_email_attach_records($conn,'unmatched',1,[$received[0]]);
        bdta_email_attach_records($conn,'client',2,bdta_email_attachments($conn,'unmatched',1));
        $conn->exec('DELETE FROM unmatched_emails WHERE id=1');
        check_attachment(file_get_contents(bdta_email_verified_attachment_path(bdta_email_attachments($conn,'client',2)[0]))==='%PDF-synthetic','Assignment copy survives original email deletion');
        $metadata=bdta_email_attachment_metadata($conn,'client',2);
        check_attachment(!isset($metadata[0]['file_name']) && !isset($metadata[0]['sha256']), 'API metadata hides storage and content hashes');
        $conn->exec("UPDATE email_attachments SET created_at='2000-01-01 00:00:00'");
        touch(bdta_email_verified_attachment_path($received[0]),time()-172800);
        $cleanup=bdta_email_cleanup_attachments($conn,true);
        check_attachment($cleanup['orphan_records']===1 && is_file(bdta_email_verified_attachment_path($received[0])),'Cleanup preserves shared live reference');
    } else { echo "SKIP: SQLite persistence tests require pdo_sqlite.\n"; }
    echo "Email attachment unit tests passed.\n";
} finally {
    putenv('EMAIL_ATTACHMENTS_DIRECTORY=' . $temporary . '/private');
    bdta_email_discard_staged_attachments($stored);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- only generated files in this test's newly-created private random directory.
    foreach (glob($temporary . '/private/*') ?: [] as $file) unlink($file);
    if(is_dir($temporary.'/private'))rmdir($temporary.'/private');
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- only the literal synthetic outside file created inside this test's private random directory.
    if(is_file($temporary.'/outside'))unlink($temporary.'/outside');
    rmdir($temporary);
    putenv($prior===false?'EMAIL_ATTACHMENTS_DIRECTORY':'EMAIL_ATTACHMENTS_DIRECTORY='.$prior);
}
