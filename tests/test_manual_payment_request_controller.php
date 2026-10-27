<?php
define('BDTA_MANUAL_FIXTURE_ONLY', true);
require __DIR__ . '/test_manual_payment_requests.php';
function requireLogin(): void {}
function escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function formatDate($value): string { return (string)$value; }
function setFlashMessage(string $message, string $kind): void { $GLOBALS['fixture_flash'] = [$kind,$message]; }
function redirect(string $location): void {
    if(ob_get_level()>0)ob_end_clean();
    echo json_encode(['redirect'=>$location,'flash'=>$GLOBALS['fixture_flash']??null],JSON_THROW_ON_ERROR);exit;
}
class Database {
    public function getConnection(): PDO { return connection('sqlite',$GLOBALS['fixture_db']); }
}
class EmailService {
    public function __construct(...$args) {}
    public function sendPaymentReceipt(...$args): array {
        file_put_contents($GLOBALS['fixture_receipts'], "synthetic receipt\n",FILE_APPEND);return ['success'=>true];
    }
}
if (($argv[1]??'') === 'worker') {
    $root=$argv[2];$data=json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
    $GLOBALS['fixture_db']=$root.'/fixture.sqlite';$GLOBALS['fixture_receipts']=$root.'/receipts.txt';
    $_SESSION=$data['session'];$_GET=$data['get'];$_POST=$data['post'];$_SERVER['REQUEST_METHOD']='POST';
    chdir($root.'/client');ob_start();include dirname(__DIR__).'/client/invoices_payment.php';ob_end_clean();
    echo json_encode(['flash'=>$GLOBALS['fixture_flash']??null],JSON_THROW_ON_ERROR);exit;
}
$root=sys_get_temp_dir().'/bdta-controller-synthetic-'.bin2hex(random_bytes(8));
mkdir($root.'/backend/includes',0700,true);mkdir($root.'/client',0700,true);
foreach(['config.php','email_service.php','invoice_payment.php','header.php','footer.php'] as $file)file_put_contents($root.'/backend/includes/'.$file,'<?php');
$db=connection('sqlite',$root.'/fixture.sqlite');seed($db,'sqlite');
$session=['csrf_token'=>'synthetic-csrf','admin_id'=>1];
$token=bdta_manual_payment_form_token($session,20,0,1);$next=bdta_manual_payment_form_token($session,20,0,1);
function controller(string $root,array $session,string $token,string $amount,int $invoice=20,int $installment=0,string $csrf='synthetic-csrf'): array {
    $payload=$root.'/request-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($payload,json_encode(['session'=>$session,'get'=>['id'=>$invoice,'installment_id'=>$installment],
        'post'=>['csrf_token'=>$csrf,'payment_request_token'=>$token,'payment_amount'=>$amount,'payment_method'=>'cash','payment_date'=>'2026-10-27']],JSON_THROW_ON_ERROR));
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker',$root,$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);unlink($payload);
    if($status!==0)throw new RuntimeException($err.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
try {
    $a=controller($root,$session,$token,'40');$b=controller($root,$session,$token,'40');
    check($a['flash'][0]==='success'&&str_contains($b['flash'][1],'already recorded'),'Real controller handles partial replay');
    check((float)$db->query('SELECT SUM(amount) FROM invoice_payments WHERE invoice_id=20')->fetchColumn()===40.0,'Controller partial double click records forty');
    $bad=controller($root,$session,$token,'41');check($bad['flash'][0]==='danger','Controller rejects conflicting payload');
    $a=controller($root,$session,$next,'60');$b=controller($root,$session,$next,'60');
    check($a['flash'][0]==='success'&&str_contains($b['flash'][1],'already recorded'),'Closed invoice controller replay reaches durable result');
    check(count(file($root.'/receipts.txt'))===1,'Controller replay does not resend full receipt');
    $token21=bdta_manual_payment_form_token($session,21,1,1);
    $db->exec("INSERT INTO invoice_installments(id,invoice_id,installment_number,amount,status) VALUES(1,21,1,100,'unpaid')");
    $a=controller($root,$session,$token21,'100',21,1);$b=controller($root,$session,$token21,'100',21,1);
    check($a['flash'][0]==='success'&&str_contains($b['flash'][1],'already recorded'),'Paid installment controller replay accepted');
    check(count(file($root.'/receipts.txt'))===3,'Installment plus full receipts emitted only on first commit');
    $before=countRows($db,'invoice_payments');
    foreach([['unissued','40',22,0,'synthetic-csrf'],[$next,'40',22,0,'synthetic-csrf'],[$next,'40',22,0,'wrong-csrf'],[$next,'40.001',22,0,'synthetic-csrf']] as [$t,$amount,$invoice,$inst,$csrf]) {
        $r=controller($root,$session,$t,$amount,$invoice,$inst,$csrf);check($r['flash'][0]==='danger','Invalid issuance/CSRF/amount rejected by controller');
    }
    check(countRows($db,'invoice_payments')===$before,'Rejected HTTP inputs add no payments');
    echo json_encode(['controller_checks'=>$checks,'failures'=>0,'synthetic_only'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db=null;
    foreach(glob($root.'/backend/includes/*') as $file)unlink($file);
    foreach(glob($root.'/*') as $file)if(is_file($file))unlink($file);
    rmdir($root.'/backend/includes');rmdir($root.'/backend');rmdir($root.'/client');rmdir($root);
}
