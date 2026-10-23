"""Offline controller integration, no server/provider/customer data.

Requires PHP session + pdo_sqlite. Real controllers/auth/CSRF/PHPMailer run under
CLI, with mail replaced by a local MIME capture and is_uploaded_file restricted
to one generated synthetic upload. This does NOT validate HTTP multipart parsing,
web-server headers or browser behavior; those require staging acceptance.
Set BDTA_TEST_PHP and BDTA_TEST_PHP_EXTENSIONS (os.pathsep-separated) if necessary.
"""
import base64
from email import policy
from email.parser import BytesParser
import json
import os
from pathlib import Path
import secrets
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PHP = [os.getenv('BDTA_TEST_PHP', 'php')]
for extension in os.getenv('BDTA_TEST_PHP_EXTENSIONS', '').split(os.pathsep):
    if extension:
        PHP += ['-d', 'extension=' + extension]
DISABLED = 'mail,is_uploaded_file,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open'
PHP += ['-d', 'allow_url_fopen=0', '-d', 'disable_functions=' + DISABLED]
checks = 0

def check(value, label):
    global checks
    assert value, label
    checks += 1
    print('PASS: ' + label)

with tempfile.TemporaryDirectory(prefix='bdta-email-controllers-') as tmp:
    work = Path(tmp)
    token = secrets.token_hex(32)
    db = sqlite3.connect(work / 'fixture.sqlite')
    db.executescript('''
        CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,setting_type TEXT);
        INSERT INTO settings VALUES ('timezone','UTC','text'),('email_service','mail','text'),('email_from_address','sender@example.invalid','text'),('email_from_name','Synthetic Sender','text'),('enable_email_signatures','0','checkbox');
        CREATE TABLE clients(id INTEGER PRIMARY KEY,email TEXT,name TEXT,is_archived INTEGER DEFAULT 0);
        INSERT INTO clients VALUES(1,'client@example.invalid','Synthetic Client',0);
        CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT,account_type TEXT,is_active INTEGER);
        INSERT INTO admin_users VALUES(1,'Synthetic Admin','main',1),(2,'Synthetic Accountant','accountant',1);
        CREATE TABLE email_templates(id INTEGER PRIMARY KEY,name TEXT);
        CREATE TABLE notifications(id INTEGER PRIMARY KEY,audience TEXT,recipient_id INTEGER,entity_type TEXT,entity_id INTEGER,title TEXT,message TEXT,url TEXT,is_read INTEGER,created_at TEXT,deleted_at TEXT);
        CREATE TABLE client_emails(id INTEGER PRIMARY KEY AUTOINCREMENT,client_id INTEGER,direction TEXT,status TEXT,message_id TEXT,from_email TEXT,to_email TEXT,subject TEXT,body_html TEXT,body_text TEXT,template_id INTEGER,mail_type TEXT,scheduled_at TEXT,sent_at TEXT,failed_at TEXT,error_message TEXT,created_by INTEGER,created_at TEXT,updated_at TEXT,attachments_processed INTEGER DEFAULT 0);
        CREATE TABLE unmatched_emails(id INTEGER PRIMARY KEY AUTOINCREMENT,message_id TEXT,from_email TEXT,from_name TEXT,to_email TEXT,subject TEXT,body_html TEXT,body_text TEXT,received_at TEXT,direction TEXT DEFAULT 'incoming',is_assigned INTEGER DEFAULT 0,assigned_to_client_id INTEGER,assigned_at TEXT,assigned_by INTEGER,is_archived INTEGER DEFAULT 0,archived_at TEXT,created_at TEXT,attachments_processed INTEGER DEFAULT 0,delivery_status TEXT);
        CREATE TABLE email_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,email_kind TEXT,email_id INTEGER,file_name TEXT,original_name TEXT,file_size INTEGER,sha256 TEXT,mime_type TEXT,status TEXT,error_message TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    ''')
    db.commit()
    (work / 'imap_fake.php').write_text(r'''<?php
namespace IMAP { class Connection {} }
namespace {
const FT_PEEK = 2;
function imap_headerinfo($connection,$number) {
 $request=$GLOBALS['request'];
 return (object)['subject'=>'Synthetic inbound','date'=>'2026-01-01 12:00:00 +0000','message_id'=>$request['data']['message_id'],
 'from'=>[(object)['mailbox'=>$request['data']['known']?'client':'unknown','host'=>'example.invalid','personal'=>'Synthetic sender']]];
}
function imap_fetchstructure($connection,$number) {
 return json_decode('graft'.'');
}
function imap_fetchbody($connection,$number,$part,$flags=0) {
 if($flags!==FT_PEEK)throw new \RuntimeException('Message must not be marked seen before attachment commit');
 return $part==='1'?base64_encode("Synthetic inbound attachment\x00bytes"):"Hello message body";
}
function imap_mime_header_decode($header) { return [(object)['text'=>$header]]; }
}
'''.replace("return json_decode('graft'.'');", "return json_decode(" + json.dumps(json.dumps({'type':1,'parts':[{'type':0,'subtype':'PLAIN','encoding':3,'bytes':50,'disposition':'ATTACHMENT','dparameters':[{'attribute':'filename','value':'inbound.txt'}]},{'type':0,'subtype':'PLAIN','encoding':0,'bytes':18}]})) + ");"))
    worker = work / 'worker.php' 
    worker.write_text(r'''<?php
if(PHP_SAPI!=='cli')exit(99);
$work=getenv('BDTA_EMAIL_TEST_WORK');
$root=getenv('BDTA_EMAIL_TEST_ROOT');
if(!is_string($work)||!is_string($root)||!str_starts_with(basename($work),'bdta-email-controllers-'))exit(98);
$request=json_decode(file_get_contents($work.'/request.json'),true);
register_shutdown_function(function()use($work){file_put_contents($work.'/response.json',json_encode(['status'=>http_response_code()?:200]));});
function mail($to,$subject,$message,$headers='',$parameters='') {
    if(!str_ends_with($to,'@example.invalid'))throw new RuntimeException('Non-synthetic recipient prohibited');
    file_put_contents(getenv('BDTA_EMAIL_TEST_WORK').'/capture.eml',$headers."\r\nSubject: ".$subject."\r\n\r\n".$message);
    return getenv('BDTA_EMAIL_TEST_FAIL')!=='1';
}
function is_uploaded_file($filename) {return $filename===getenv('BDTA_EMAIL_TEST_WORK').'/upload.tmp' && is_file($filename);}
require_once $root.'/backend/includes/database.php';
$conn=new SafePDO('sqlite:'.$work.'/fixture.sqlite');
$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SafePDOStatement::class]);
(new ReflectionProperty(Database::class,'sharedConnection'))->setValue(null,$conn);
session_save_path($work);session_start();
$_SESSION=['csrf_token'=>getenv('BDTA_EMAIL_TEST_TOKEN')];
if(($request['role']??'admin')!=='anonymous'){
 $accountant=($request['role']??'')==='accountant';
 $_SESSION+=['user_type'=>'admin','user_id'=>$accountant?2:1,'admin_id'=>$accountant?2:1,'admin_account_type'=>$accountant?'accountant':'main','admin_account_type_refreshed_at'=>time()];
}
$_SERVER['REQUEST_METHOD']=$request['method']??'POST';
$_SERVER['CONTENT_TYPE']='multipart/form-data; boundary=synthetic';
$_SERVER['SCRIPT_NAME']='/client/'.($request['route']??'client_emails_api.php');
$_SERVER['HTTP_HOST']='synthetic.example.invalid';
if(isset($request['csrf']))$_SERVER['HTTP_X_CSRF_TOKEN']=$request['csrf'];
$_POST=$request['data']??[];$_GET=$request['get']??[];$_FILES=[];
if(!empty($request['upload']))$_FILES=['attachments'=>['name'=>[$request['filename']??'synthetic.txt'],'tmp_name'=>[$work.'/upload.tmp'],'error'=>[$request['upload_error']??0],'size'=>[filesize($work.'/upload.tmp')]]];
if(($request['route']??'')==='scheduled'){
 require_once $root.'/backend/includes/config.php';
 require_once $root.'/backend/cron/tasks/scheduled_email_sender.php';
 echo json_encode((new ScheduledEmailSenderTask($conn))->execute());exit;
}
if(($request['route']??'')==='inbound'){
 require_once $work.'/imap_fake.php';
 require_once $root.'/backend/includes/imap_receiver.php';
 $receiver=new ImapEmailReceiver();
 (new ReflectionProperty(ImapEmailReceiver::class,'imap_connection'))->setValue($receiver,new \IMAP\Connection());
 try {
  (new ReflectionMethod(ImapEmailReceiver::class,'processEmail'))->invoke($receiver,1);
  echo json_encode(['success'=>true]);
 } catch(Throwable $e) { http_response_code(500); echo json_encode(['success'=>false]); }
 exit;
}
$allowed=['client_emails_api.php','unmatched_emails_api.php','email_attachments_download.php'];
if(!in_array($request['route'],$allowed,true))exit(97);
chdir($root.'/client');require $request['route'];
''')
    env = os.environ.copy()
    env.update(BDTA_EMAIL_TEST_WORK=str(work), BDTA_EMAIL_TEST_ROOT=str(ROOT), BDTA_EMAIL_TEST_TOKEN=token,
               EMAIL_ATTACHMENTS_DIRECTORY=str(work / 'private'), DB_HOST='127.0.0.1', DB_PORT='1', DB_NAME='synthetic', DB_USER='synthetic', DB_PASSWORD='')
    payload = b'Synthetic attachment\x00exact bytes\n'
    (work / 'upload.tmp').write_bytes(payload)

    def run(route='client_emails_api.php', method='POST', data=None, get=None, csrf=token, role='admin', upload=False, fail=False, filename='synthetic.txt', upload_error=0):
        request = dict(route=route, method=method, data=dict(data or {}), get=dict(get or {}), role=role, upload=upload, filename=filename, upload_error=upload_error)
        if upload:
            request['data'].setdefault('attachment_count', 1)
        if csrf is not None:
            request['csrf'] = csrf
        (work / 'request.json').write_text(json.dumps(request))
        env['BDTA_EMAIL_TEST_FAIL'] = '1' if fail else '0'
        proc = subprocess.run(PHP + [str(worker)], env=env, capture_output=True, timeout=30)
        assert proc.returncode == 0, proc.stderr.decode() + proc.stdout.decode(errors='replace')
        status = json.loads((work / 'response.json').read_text())['status']
        try: data_out = json.loads(proc.stdout)
        except (ValueError, UnicodeError): data_out = None
        return status, data_out, proc.stdout

    message = {'client_id': 1, 'subject': 'Synthetic attachment test', 'body_html': '<p>hello</p>'}
    for csrf in [None, 'wrong']:
        status, _, _ = run(data=message, csrf=csrf, upload=True)
        check(status == 403, 'Client compose rejects missing/wrong CSRF')
    for action in ['compose','reply','assign','archive','unarchive','cleanup_missing_timestamps']:
        status, _, _ = run('unmatched_emails_api.php', data={'action':action,'id':1}, csrf='wrong')
        check(status == 403, 'Inbox '+action+' rejects invalid CSRF')
    for route in ['client_emails_api.php','unmatched_emails_api.php']:
        status, _, _ = run(route,method='DELETE',get={'id':1},csrf='wrong')
        check(status == 403, route+' DELETE rejects invalid CSRF')
    check(db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0] == 0, 'Rejected requests created no email or upload records')

    status, result, _ = run(data=message, upload=True, filename='../../synthetic\r\n.txt')
    check(status == 200 and result['success'], 'Client compose sends with stored attachment')
    client_email_id = result['email_id']
    parsed = BytesParser(policy=policy.default).parsebytes((work / 'capture.eml').read_bytes())
    attachments = list(parsed.iter_attachments())
    check(len(attachments) == 1 and attachments[0].get_payload(decode=True) == payload, 'Real PHPMailer MIME preserves exact attachment bytes')
    check(attachments[0].get_filename() == 'synthetic.txt', 'Transport uses sanitized filename')
    attachment_id, key = db.execute("SELECT id,file_name FROM email_attachments WHERE email_kind='client' AND email_id=?", (client_email_id,)).fetchone()
    for role in ['anonymous','accountant']:
        _, _, output = run('email_attachments_download.php', method='GET', get={'id':attachment_id}, role=role)
        check(payload not in output, role+' cannot download private bytes')
    status, _, output = run('email_attachments_download.php', method='GET', get={'id':attachment_id})
    check(status == 200 and output == payload, 'Authenticated parent-bound download returns exact bytes')
    for bad_id in ['../file','1&path=/etc/passwd',99999]:
        status, _, output = run('email_attachments_download.php', method='GET', get={'id':bad_id})
        check(status == 404 and payload not in output, 'Invalid/foreign attachment ID rejected')
    status, result, _ = run(method='GET', get={'client_id':1})
    check(result['emails'][0]['attachments'][0]['name'] == 'synthetic.txt' and 'file_name' not in result['emails'][0]['attachments'][0], 'Client GET exposes safe attachment metadata')

    status, result, _ = run(data=message, upload=True, fail=True)
    failed_id = result['email_id']
    failed_key = db.execute("SELECT file_name FROM email_attachments WHERE email_kind='client' AND email_id=?",(failed_id,)).fetchone()[0]
    check(status == 500 and db.execute('SELECT status FROM client_emails WHERE id=?',(failed_id,)).fetchone()[0] == 'failed' and (work/'private'/failed_key).exists(), 'Failed send preserves history and attachment bytes')

    inbox={'action':'compose','to':'unknown@example.invalid','subject':'Inbox compose','body_html':'<p>test</p>'}
    status,failed_inbox,_=run('unmatched_emails_api.php',data=inbox,upload=True,fail=True)
    status,listing,_=run('unmatched_emails_api.php',method='GET')
    failed_row=next(row for row in listing['emails'] if row['id']==failed_inbox['email_id'])
    check(failed_row['delivery_status']=='failed','Inbox list reports failed sends rather than defaulting to sent')
    status,result,_=run('unmatched_emails_api.php',data=inbox,upload=True)
    unmatched_id=result['email_id']
    check(status==200 and result['email_kind']=='unmatched','Inbox compose stores unknown-recipient attachment owner')
    before=db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]
    status,result,_=run('unmatched_emails_api.php',data={**inbox,'to':'client@example.invalid'},upload=True)
    check(status==200 and result['email_kind']=='client' and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==before+1,'Inbox compose to known client creates exactly one client history row')
    db.execute("UPDATE unmatched_emails SET direction='incoming',from_email='unknown@example.invalid' WHERE id=?",(unmatched_id,));db.commit()
    status,result,_=run('unmatched_emails_api.php',data={'action':'reply','id':unmatched_id,'subject':'Reply','body_html':'Reply'},upload=True)
    check(status==200 and result['email_kind']=='unmatched','Inbox reply attaches and persists response')
    status,result,_=run('unmatched_emails_api.php',data={'action':'assign','id':unmatched_id,'client_id':1,'create_client_email':True})
    check(status==200 and result['success'],'Assignment copies attachment metadata')
    before=db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]
    status,_,_=run('unmatched_emails_api.php',data={'action':'assign','id':unmatched_id,'client_id':1,'create_client_email':True})
    check(status==409 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==before,'Repeated assignment does not duplicate mail or attachment ownership')
    copied_id=db.execute("SELECT MAX(id) FROM client_emails").fetchone()[0]
    copied_attachment_id=db.execute("SELECT id FROM email_attachments WHERE email_kind='client' AND email_id=?",(copied_id,)).fetchone()[0]
    run('unmatched_emails_api.php',method='DELETE',get={'id':unmatched_id})
    status,_,output=run('email_attachments_download.php',method='GET',get={'id':copied_attachment_id})
    check(status==200 and output==payload,'Assigned copy downloads after original unmatched deletion')

    status,result,_=run(data={**message,'scheduled_at':'2000-01-01T12:00'},upload=True)
    scheduled_id=result['email_id']
    status,result,_=run('scheduled')
    check(result['items_processed']==1 and db.execute('SELECT status FROM client_emails WHERE id=?',(scheduled_id,)).fetchone()[0]=='sent','Scheduled sender reloads persisted attachments')
    status,repeat,_=run('scheduled')
    check(repeat['items_processed']==0,'Repeated scheduled worker does not resend completed attachment mail')
    parsed=BytesParser(policy=policy.default).parsebytes((work/'capture.eml').read_bytes())
    check(list(parsed.iter_attachments())[0].get_payload(decode=True)==payload,'Scheduled MIME contains selected bytes')
    status,result,_=run(data={**message,'scheduled_at':'2000-01-01T12:00'},upload=True)
    missing_id=result['email_id'];missing_key=db.execute("SELECT file_name FROM email_attachments WHERE email_kind='client' AND email_id=?",(missing_id,)).fetchone()[0]
    (work/'private'/missing_key).unlink(); (work/'capture.eml').unlink()
    status,result,_=run('scheduled')
    check(not (work/'capture.eml').exists() and db.execute('SELECT status FROM client_emails WHERE id=?',(missing_id,)).fetchone()[0]=='failed','Missing scheduled attachment fails closed without sending mail')
    status,_,_=run(data={**message,'attachment_count':2},upload=True)
    check(status==400,'Truncated HTTP upload count fails closed before message creation')
    count=db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]
    status,_,_=run(data=message,upload=True,upload_error=3)
    check(status==400 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==count,'Partial upload creates no message')
    files_before=len(list((work/'private').glob('*.bin')))
    emails_before=db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]
    db.execute("CREATE TRIGGER fail_attachment_insert BEFORE INSERT ON email_attachments BEGIN SELECT RAISE(ABORT,'synthetic persistence failure'); END")
    db.commit()
    status,_,_=run(data=message,upload=True)
    check(status==500 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==emails_before and len(list((work/'private').glob('*.bin')))==files_before,'Outbound attachment DB failure rolls back message and staged bytes')
    status,_,_=run('inbound',data={'known':True,'message_id':'<retry-after-failure@example.invalid>'})
    check(status==500 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==emails_before and len(list((work/'private').glob('*.bin')))==files_before,'Inbound attachment DB failure rolls back message and staged bytes')
    db.execute('DROP TRIGGER fail_attachment_insert');db.commit()
    status,result,_=run('inbound',data={'known':True,'message_id':'<retry-after-failure@example.invalid>'})
    check(status==200 and result['success'],'Inbound processing safely retries after rolled-back persistence failure')
    db.execute("INSERT INTO client_emails(client_id,direction,status,message_id,subject,created_at,attachments_processed) VALUES(1,'incoming','received','<legacy@example.invalid>','Legacy','2020-01-01',0)")
    db.commit();before=db.execute('SELECT COUNT(*) FROM email_attachments').fetchone()[0]
    run('inbound',data={'known':True,'message_id':'<legacy@example.invalid>'})
    check(db.execute('SELECT COUNT(*) FROM email_attachments').fetchone()[0]==before and db.execute("SELECT attachments_processed FROM client_emails WHERE message_id='<legacy@example.invalid>'").fetchone()[0]==0,'Historical duplicate is explicitly not represented as recovered')
    for known in [True,False]:
        kind='client' if known else 'unmatched'
        table='client_emails' if known else 'unmatched_emails'
        mid=f'<synthetic-{kind}@example.invalid>'
        status,result,_=run('inbound',data={'known':known,'message_id':mid})
        row=db.execute(f'SELECT id,body_text,attachments_processed FROM {table} WHERE message_id=?',(mid,)).fetchone()
        check(status==200 and result['success'] and row[1]=='Hello message body' and row[2]==1, 'Incoming '+kind+' email excludes attached text from body and records import marker')
        attachment=db.execute('SELECT id,file_name FROM email_attachments WHERE email_kind=? AND email_id=?',(kind,row[0])).fetchone()
        check((work/'private'/attachment[1]).read_bytes()==b'Synthetic inbound attachment\x00bytes','Incoming '+kind+' attachment persisted with exact bytes')
        before=db.execute('SELECT COUNT(*) FROM email_attachments').fetchone()[0]
        run('inbound',data={'known':known,'message_id':mid})
        check(db.execute('SELECT COUNT(*) FROM email_attachments').fetchone()[0]==before,'Duplicate '+kind+' message does not duplicate attachments or backfill historical records')
    print(f'{checks} offline controller assertions passed. HTTP parsing/headers and browser QA are not covered.')
