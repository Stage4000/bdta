<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('BDTA_MANUAL_FIXTURE_ONLY', true);
require __DIR__ . '/test_manual_payment_requests.php';
function requireLogin(): void {}
function escape(mixed $value): string { return htmlspecialchars(scalar_string($value), ENT_QUOTES, 'UTF-8'); }
function formatDate(mixed $value): string { return scalar_string($value); }
function setFlashMessage(string $message, string $kind): void { $GLOBALS['fixture_flash'] = [$kind,$message]; }
function redirect(string $location): void {
    if(ob_get_level()>0)ob_end_clean();
    echo json_encode(['redirect'=>$location,'flash'=>$GLOBALS['fixture_flash']??null],JSON_THROW_ON_ERROR);exit;
}
class Database {
    public function getConnection(): PDO { return connection('sqlite',scalar_string($GLOBALS['fixture_db']??'')); }
}
class EmailService {
    public function __construct(mixed ...$args) { if(count($args)>2)throw new RuntimeException('Unexpected fixture constructor arguments'); }
    /** @return array{success: true} */
    public function sendPaymentReceipt(mixed ...$args): array {
        file_put_contents(scalar_string($GLOBALS['fixture_receipts']??''), "synthetic receipt\n",FILE_APPEND);return ['success'=>true];
    }
}
if (($argv[1]??'') === 'worker') {
    $input=stream_get_contents(STDIN);
    if($input===false)throw new RuntimeException('Missing synthetic controller input');
    $packet=assoc_row(json_decode($input,true,512,JSON_THROW_ON_ERROR));
    $root=scalar_string($packet['root']??'');
    $data=assoc_row($packet['data']??null);
    $GLOBALS['fixture_db']=$root.'/fixture.sqlite';$GLOBALS['fixture_receipts']=$root.'/receipts.txt';
    $_SESSION=assoc_row($data['session']??null);$_GET=assoc_row($data['get']??null);$_POST=assoc_row($data['post']??null);$_SERVER['REQUEST_METHOD']='POST';
    chdir($root.'/client');ob_start();include dirname(__DIR__).'/client/invoices_payment.php';ob_end_clean();
    echo json_encode(['flash'=>$GLOBALS['fixture_flash']??null],JSON_THROW_ON_ERROR);exit;
}
$checks=0;
$root=fixtureDirectory();
$originalCwd=getcwd();if($originalCwd===false)throw new RuntimeException('Missing working directory');
mkdir($root.'/backend/includes',0700,true);mkdir($root.'/client',0700,true);
foreach(['config.php','email_service.php','invoice_payment.php','header.php','footer.php'] as $file)file_put_contents($root.'/backend/includes/'.$file,'<?php');
$db=connection('sqlite',$root.'/fixture.sqlite');seed($db,'sqlite');
$session=['csrf_token'=>'synthetic-csrf','admin_id'=>1];
$token=bdta_manual_payment_form_token($session,20,0,1);$next=bdta_manual_payment_form_token($session,20,0,1);
/** @param array<string, mixed> $session
 * @return array{flash: array{string, string}}
 */
function controller(string $root,array $session,string $token,string $amount,int $invoice=20,int $installment=0,string $csrf='synthetic-csrf'): array {
    $data=['session'=>$session,'get'=>['id'=>$invoice,'installment_id'=>$installment],
        'post'=>['csrf_token'=>$csrf,'payment_request_token'=>$token,'payment_amount'=>$amount,'payment_method'=>'cash','payment_date'=>'2026-10-27']];
    $pipes=[];
    // Constant executable/script; JSON fixture data is stdin, not a shell argument.
    $process=proc_open('php tests/test_manual_payment_request_controller.php worker',[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process))throw new RuntimeException('Unable to start synthetic controller');
    fwrite($pipes[0],json_encode(['root'=>$root,'data'=>$data],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    if($status!==0||$out===false)throw new RuntimeException($err.$out);
    $result=assoc_row(json_decode($out,true,512,JSON_THROW_ON_ERROR));
    $flash=$result['flash']??null;
    if(!is_array($flash)||count($flash)!==2||!isset($flash[0],$flash[1])||!is_string($flash[0])||!is_string($flash[1]))throw new RuntimeException('Malformed fixture flash');
    return ['flash'=>[$flash[0],$flash[1]]];
}
try {
    $a=controller($root,$session,$token,'40');$b=controller($root,$session,$token,'40');
    check($a['flash'][0]==='success'&&str_contains($b['flash'][1],'already recorded'),'Real controller handles partial replay');
    check((float)$db->query('SELECT SUM(amount) FROM invoice_payments WHERE invoice_id=20')->fetchColumn()===40.0,'Controller partial double click records forty');
    $bad=controller($root,$session,$token,'41');check($bad['flash'][0]==='danger','Controller rejects conflicting payload');
    $a=controller($root,$session,$next,'60');$b=controller($root,$session,$next,'60');
    check($a['flash'][0]==='success'&&str_contains($b['flash'][1],'already recorded'),'Closed invoice controller replay reaches durable result');
    check(count(explode("\n",trim(fixtureFile($root.'/receipts.txt'))))===1,'Controller replay does not resend full receipt');
    $token21=bdta_manual_payment_form_token($session,21,1,1);
    $db->exec("INSERT INTO invoice_installments(id,invoice_id,installment_number,amount,status) VALUES(1,21,1,100,'unpaid')");
    $a=controller($root,$session,$token21,'100',21,1);$b=controller($root,$session,$token21,'100',21,1);
    check($a['flash'][0]==='success'&&str_contains($b['flash'][1],'already recorded'),'Paid installment controller replay accepted');
    check(count(explode("\n",trim(fixtureFile($root.'/receipts.txt'))))===3,'Installment plus full receipts emitted only on first commit');
    $before=countRows($db,'invoice_payments');
    foreach([['unissued','40',22,0,'synthetic-csrf'],[$next,'40',22,0,'synthetic-csrf'],[$next,'40',22,0,'wrong-csrf'],[$next,'40.001',22,0,'synthetic-csrf']] as [$t,$amount,$invoice,$inst,$csrf]) {
        $r=controller($root,$session,$t,$amount,$invoice,$inst,$csrf);check($r['flash'][0]==='danger','Invalid issuance/CSRF/amount rejected by controller');
    }
    check(countRows($db,'invoice_payments')===$before,'Rejected HTTP inputs add no payments');
    echo json_encode(['controller_checks'=>$checks,'failures'=>0,'synthetic_only'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db=null;
    chdir($root.'/backend/includes');
    unlink('config.php');unlink('email_service.php');unlink('invoice_payment.php');unlink('header.php');unlink('footer.php');
    chdir($root);if(is_file('fixture.sqlite'))unlink('fixture.sqlite');if(is_file('receipts.txt'))unlink('receipts.txt');
    rmdir('backend/includes');rmdir('backend');rmdir('client');chdir($originalCwd);rmdir($root);
}
