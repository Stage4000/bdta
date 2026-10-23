"""Synthetic real HTTP multipart/auth/download tests, bound only to 127.0.0.1.

No mailbox, SMTP or customer data. Native HTTP upload/session parsing is used.
Only PHP mail() is replaced with a local capture; outbound transports are disabled.
Requires PHP session + pdo_sqlite. Supports BDTA_TEST_PHP/BDTA_TEST_PHP_EXTENSIONS.
"""
import http.client
import json
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = [os.getenv('BDTA_TEST_PHP', 'php')]
for extension in os.getenv('BDTA_TEST_PHP_EXTENSIONS', '').split(os.pathsep):
    if extension:
        PHP += ['-d', 'extension=' + extension]
checks = 0

def check(condition, label):
    global checks
    assert condition, label
    checks += 1
    print('PASS: ' + label)

with tempfile.TemporaryDirectory(prefix='bdta-email-http-') as temporary:
    work = Path(temporary)
    token = secrets.token_hex(32)
    sessions = {}
    for role, identity in [('admin',1),('standard',2),('accountant',3)]:
        sid=secrets.token_hex(16);sessions[role]=sid
        account_type={'admin':'main','standard':'standard','accountant':'accountant'}[role]
        data=f'csrf_token|s:64:"{token}";user_type|s:5:"admin";user_id|i:{identity};admin_id|i:{identity};admin_account_type|s:{len(account_type)}:"{account_type}";admin_account_type_refreshed_at|i:{int(time.time())};'
        (work/('sess_'+sid)).write_text(data)
    db=sqlite3.connect(work/'fixture.sqlite')
    db.executescript('''
        CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,setting_type TEXT);
        INSERT INTO settings VALUES('timezone','UTC','text'),('email_service','mail','text'),('email_from_address','sender@example.invalid','text'),('email_from_name','Synthetic Sender','text'),('enable_email_signatures','0','checkbox');
        CREATE TABLE clients(id INTEGER PRIMARY KEY,email TEXT,name TEXT,is_archived INTEGER DEFAULT 0);
        INSERT INTO clients VALUES(1,'client@example.invalid','Synthetic Client',0);
        CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT,account_type TEXT,is_active INTEGER);
        INSERT INTO admin_users VALUES(1,'Synthetic Main','main',1),(2,'Synthetic Standard','standard',1),(3,'Synthetic Accountant','accountant',1);
        CREATE TABLE email_templates(id INTEGER PRIMARY KEY,name TEXT);
        CREATE TABLE client_emails(id INTEGER PRIMARY KEY AUTOINCREMENT,client_id INTEGER,direction TEXT,status TEXT,message_id TEXT,from_email TEXT,to_email TEXT,subject TEXT,body_html TEXT,body_text TEXT,template_id INTEGER,mail_type TEXT,scheduled_at TEXT,sent_at TEXT,failed_at TEXT,error_message TEXT,created_by INTEGER,created_at TEXT,updated_at TEXT,attachments_processed INTEGER DEFAULT 0);
        CREATE TABLE unmatched_emails(id INTEGER PRIMARY KEY AUTOINCREMENT,message_id TEXT,from_email TEXT,from_name TEXT,to_email TEXT,subject TEXT,body_html TEXT,body_text TEXT,received_at TEXT,direction TEXT DEFAULT 'incoming',is_assigned INTEGER DEFAULT 0,assigned_to_client_id INTEGER,assigned_at TEXT,assigned_by INTEGER,is_archived INTEGER DEFAULT 0,archived_at TEXT,created_at TEXT,attachments_processed INTEGER DEFAULT 0,delivery_status TEXT);
        CREATE TABLE email_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,email_kind TEXT,email_id INTEGER,file_name TEXT,original_name TEXT,file_size INTEGER,sha256 TEXT,mime_type TEXT,status TEXT,error_message TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    ''');db.commit()
    prepend=work/'fixture.php'
    prepend.write_text(r'''<?php
if(PHP_SAPI!=='cli-server')exit('Synthetic fixture requires loopback test server.');
$work=getenv('BDTA_EMAIL_TEST_WORK');
if(!is_string($work)||!str_starts_with(basename($work),'bdta-email-http-'))exit;
require_once getenv('BDTA_EMAIL_TEST_ROOT').'/backend/includes/database.php';
$conn=new SafePDO('sqlite:'.$work.'/fixture.sqlite');
$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SafePDOStatement::class]);
(new ReflectionProperty(Database::class,'sharedConnection'))->setValue(null,$conn);
session_save_path($work);session_start();
function mail($to,$subject,$message,$headers='',$parameters='') {
 if(!str_ends_with($to,'@example.invalid'))throw new RuntimeException('Non-synthetic recipient prohibited');
 file_put_contents(getenv('BDTA_EMAIL_TEST_WORK').'/capture.eml',$headers."\r\nSubject: ".$subject."\r\n\r\n".$message);
 return true;
}
''')
    env=os.environ.copy();env.update(BDTA_EMAIL_TEST_WORK=str(work),BDTA_EMAIL_TEST_ROOT=str(ROOT),EMAIL_ATTACHMENTS_DIRECTORY=str(work/'private'),DB_HOST='127.0.0.1',DB_PORT='1',DB_NAME='synthetic',DB_USER='synthetic',DB_PASSWORD='')
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1',0));port=reservation.getsockname()[1]
    disabled='mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open'
    log=open(work/'server.log','wb')
    server=subprocess.Popen(PHP+['-d','auto_prepend_file='+str(prepend),'-d','display_errors=0','-d','allow_url_fopen=0','-d','disable_functions='+disabled,'-d','upload_max_filesize=2M','-d','post_max_size=4M','-d','max_file_uploads=2','-S',f'127.0.0.1:{port}','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
    def request(method,path,body=None,content_type=None,role='admin',csrf=token):
        headers={}
        if role in sessions:headers['Cookie']='PHPSESSID='+sessions[role]
        if csrf is not None:headers['X-CSRF-Token']=csrf
        if content_type:headers['Content-Type']=content_type
        conn=http.client.HTTPConnection('127.0.0.1',port,timeout=20)
        conn.request(method,path,body,headers);response=conn.getresponse();result=(response.status,dict(response.getheaders()),response.read());conn.close();return result
    def multipart(fields,files=()):
        boundary='bdta'+secrets.token_hex(16);parts=[]
        for name,value in fields.items():
            parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n').encode())
        for name,data in files:
            assert '\r' not in name and '\n' not in name and '"' not in name
            parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="attachments[]"; filename="{name}"\r\nContent-Type: text/html\r\n\r\n').encode()+data+b'\r\n')
        parts.append((f'--{boundary}--\r\n').encode());return b''.join(parts),'multipart/form-data; boundary='+boundary
    message={'client_id':1,'subject':'Synthetic multipart','body_html':'<p>Synthetic</p>'}
    payload=b'Synthetic HTTP attachment\x00exact bytes\n'
    try:
        for _ in range(100):
            if server.poll() is not None:raise RuntimeError((work/'server.log').read_text())
            try:
                request('GET','/client/email_attachments_download.php?id=0',role='anonymous');break
            except (ConnectionError,OSError):time.sleep(.05)
        else:raise RuntimeError('Loopback fixture did not become ready')
        for csrf in [None,'incorrect']:
            body,typ=multipart({**message,'attachment_count':1},[('synthetic.txt',payload)])
            status,_,_=request('POST','/client/client_emails_api.php',body,typ,csrf=csrf)
            check(status==403,'Real multipart missing/wrong CSRF rejected')
        check(db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==0,'Rejected real HTTP uploads created no email')
        body,typ=multipart({**message,'attachment_count':1},[('../../synthetic.txt',payload)])
        status,_,data=request('POST','/client/client_emails_api.php',body,typ)
        result=json.loads(data);check(status==200 and result['success'],'Native PHP multipart upload sends successfully')
        email_id=result['email_id'];attachment_id,key,name=db.execute("SELECT id,file_name,original_name FROM email_attachments WHERE email_kind='client' AND email_id=?",(email_id,)).fetchone()
        check(name=='synthetic.txt' and (work/'private'/key).read_bytes()==payload,'Multipart filename traversal stripped; bytes stored privately')
        for role in ['admin','standard']:
            status,headers,data=request('GET',f'/client/email_attachments_download.php?id={attachment_id}',role=role)
            check(status==200 and data==payload,role+' real HTTP download returns exact bytes')
            check(headers.get('Content-Type')=='application/octet-stream','Download MIME ignores untrusted upload MIME')
            check(headers.get('Content-Disposition','').startswith('attachment;') and '\r' not in headers['Content-Disposition'] and '\n' not in headers['Content-Disposition'],'Forced download disposition is header-safe')
            check(headers.get('X-Content-Type-Options')=='nosniff' and 'no-store' in headers.get('Cache-Control',''),'Download uses nosniff and private no-store')
            check("sandbox" in headers.get('Content-Security-Policy','') and headers.get('Referrer-Policy')=='no-referrer','Download isolation/referrer headers set')
        for role in ['anonymous','accountant']:
            status,headers,data=request('GET',f'/client/email_attachments_download.php?id={attachment_id}',role=role)
            check(status==302 and payload not in data and headers.get('Location'),'Real '+role+' download is denied by auth/role redirect')
        for identifier in ['999999','..%2F..%2Fetc%2Fpasswd','1%26path%3D%2Fetc%2Fpasswd']:
            status,_,data=request('GET','/client/email_attachments_download.php?id='+identifier)
            check(status==404 and payload not in data,'Malformed or unknown HTTP download ID denied')
        status,_,data=request('GET','/backend/uploads/email-attachments/'+key)
        check(status==404 and payload not in data,'No public static copy under legacy upload root')
        # PHP max_file_uploads is deliberately below application cap: reject truncation.
        before=db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]
        body,typ=multipart({**message,'attachment_count':3},[(str(i)+'.txt',payload) for i in range(3)])
        status,_,data=request('POST','/client/client_emails_api.php',body,typ)
        check(status==400 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==before,'Actual PHP max_file_uploads truncation fails closed')
        body,typ=multipart({**message,'attachment_count':1},[('too-large.txt',b'x'*(2*1024*1024+1))])
        status,_,_=request('POST','/client/client_emails_api.php',body,typ)
        check(status==400 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==before,'Actual upload_max_filesize failure creates no message')
        body,typ=multipart({**message,'attachment_count':1},[('post-too-large.txt',b'x'*(4*1024*1024+1))])
        status,_,_=request('POST','/client/client_emails_api.php',body,typ)
        check(status==400 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==before,'Actual post_max_size truncation cannot send incomplete message')
        # Existing JSON no-attachment clients remain supported with CSRF.
        status,_,data=request('POST','/client/client_emails_api.php',json.dumps(message),'application/json')
        check(status==200 and json.loads(data)['success'],'Authenticated JSON no-attachment compose remains compatible')
        # Changed or symlinked file is never downloaded.
        path=work/'private'/key;path.write_bytes(b'changed')
        status,_,data=request('GET',f'/client/email_attachments_download.php?id={attachment_id}')
        check(status==404 and b'changed' not in data,'Tampered file rejected over HTTP')
        path.unlink();outside=work/'outside.txt';outside.write_bytes(b'outside secret');path.symlink_to(outside)
        status,_,data=request('GET',f'/client/email_attachments_download.php?id={attachment_id}')
        check(status==404 and b'outside secret' not in data,'Symlink escape rejected over HTTP')
        path.unlink();path.write_bytes(payload)
        db.execute('DELETE FROM client_emails WHERE id=?',(email_id,));db.commit()
        status,_,data=request('GET',f'/client/email_attachments_download.php?id={attachment_id}')
        check(status==404 and payload not in data,'Orphan attachment cannot be downloaded after parent deletion')
        # Inbox compose and reply use real multipart with the same transport/storage.
        inbox={'action':'compose','to':'unknown@example.invalid','subject':'HTTP inbox','body_html':'test','attachment_count':1}
        body,typ=multipart(inbox,[('inbox.txt',payload)]);status,_,data=request('POST','/client/unmatched_emails_api.php',body,typ)
        result=json.loads(data);check(status==200 and result['email_kind']=='unmatched','Inbox native multipart compose succeeds')
        unmatched_id=result['email_id'];db.execute("UPDATE unmatched_emails SET direction='incoming',from_email='unknown@example.invalid' WHERE id=?",(unmatched_id,));db.commit()
        body,typ=multipart({'action':'reply','id':unmatched_id,'subject':'HTTP reply','body_html':'test','attachment_count':1},[('reply.txt',payload)])
        status,_,data=request('POST','/client/unmatched_emails_api.php',body,typ)
        check(status==200 and json.loads(data)['success'],'Inbox native multipart reply succeeds')
        for action in ['assign','archive','unarchive']:
            status,_,_=request('POST','/client/unmatched_emails_api.php',json.dumps({'action':action,'id':unmatched_id,'client_id':1}),'application/json',csrf='incorrect')
            check(status==403,'Real HTTP '+action+' rejects invalid CSRF')
        status,_,_=request('DELETE',f'/client/unmatched_emails_api.php?id={unmatched_id}',csrf=None)
        check(status==403,'Real HTTP delete rejects missing CSRF')
        # No filesystem persistence may occur when server storage is unavailable.
        saved=work/'private-saved';(work/'private').rename(saved);(work/'private').write_text('not a directory')
        before=db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]
        body,typ=multipart({**message,'attachment_count':1},[('blocked.txt',payload)])
        status,_,_=request('POST','/client/client_emails_api.php',body,typ)
        check(status==500 and db.execute('SELECT COUNT(*) FROM client_emails').fetchone()[0]==before,'Unavailable private storage fails before send/message creation')
        (work/'private').unlink();saved.rename(work/'private')
        print(f'{checks} real loopback HTTP assertions passed; no external mail was sent.')
    finally:
        server.terminate()
        try:server.wait(timeout=5)
        except subprocess.TimeoutExpired:server.kill();server.wait()
        log.close()
