<?php
require_once dirname(__DIR__) . '/backend/includes/email_read_receipts.php';
require_once dirname(__DIR__) . '/backend/includes/email_read_receipt_parser.php';
$count = 0;
function receiptCheck(bool $ok, string $label): void {
    global $count;
    if (!$ok) { throw new RuntimeException($label); }
    ++$count;
}
foreach ([null, false, 0, '0', ''] as $off) {
    receiptCheck(!bdta_email_read_receipt_requested($off), 'Explicit off representations');
}
foreach ([true, 1, '1'] as $on) {
    receiptCheck(bdta_email_read_receipt_requested($on), 'Explicit on representations');
}
foreach (['true', 'yes', 2, [], new stdClass(), 1.0] as $bad) {
    try { bdta_email_read_receipt_requested($bad); throw new RuntimeException('Invalid flag accepted'); }
    catch (InvalidArgumentException $e) { ++$count; }
}
$id = bdta_email_receipt_message_id('sender@example.invalid');
receiptCheck($id !== bdta_email_receipt_message_id('sender@example.invalid'), 'Distinct generated IDs');
receiptCheck(bdta_email_receipt_options(['read_receipt_requested'=>1,'message_id'=>$id])['message_id'] === $id, 'Saved ID retained');
try { bdta_email_receipt_options(['read_receipt_requested'=>1,'message_id'=>"<bad\r\nid>"]); throw new RuntimeException('Injected ID accepted'); }
catch (InvalidArgumentException $e) { ++$count; }
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE client_emails(id INTEGER PRIMARY KEY,direction TEXT,read_receipt_requested INTEGER,message_id TEXT COLLATE NOCASE,to_email TEXT,status TEXT,sent_at TEXT,read_receipt_reported_at TEXT,read_receipt_source_message_id TEXT,updated_at TEXT)');
$insert = $db->prepare("INSERT INTO client_emails(id,direction,read_receipt_requested,message_id,to_email,status,sent_at) VALUES(?,'outgoing',?,?,?,'sent','2026-11-02 12:00:00')");
$insert->execute([1,1,$id,'client@example.invalid']);
$body = "Final-Recipient: rfc822; client@example.invalid\r\nOriginal-Message-ID: $id\r\nDisposition: manual-action/MDN-sent-manually; displayed\r\n";
$report = BdtaReadReceiptParser::parse($body);
if ($report === null) { throw new RuntimeException('Fixture invalid'); }
receiptCheck(!bdta_email_record_read_receipt($db,$report,'client@example.invalid','','2026-11-02 12:01:00'),'Missing incoming identity not correlated');
$db->exec('UPDATE client_emails SET read_receipt_requested=0');
receiptCheck(!bdta_email_record_read_receipt($db,$report,'client@example.invalid','<r@example.invalid>','2026-11-02 12:01:00'),'Non-opted-in mail unchanged');
$db->exec("UPDATE client_emails SET read_receipt_requested=1,status='pending',sent_at=NULL");
receiptCheck(!bdta_email_record_read_receipt($db,$report,'client@example.invalid','<r@example.invalid>','2026-11-02 12:01:00'),'Unsent mail unchanged');
$db->exec("UPDATE client_emails SET status='sent',sent_at='2026-11-02 12:00:00'");
$insert->execute([2,1,strtoupper($id),'client@example.invalid']);
receiptCheck(!bdta_email_record_read_receipt($db,$report,'client@example.invalid','<r@example.invalid>','2026-11-02 12:01:00'),'Ambiguous case-insensitive SQL identity rejected');
$db->exec('DELETE FROM client_emails WHERE id=1');
receiptCheck(!bdta_email_record_read_receipt($db,$report,'client@example.invalid','<r@example.invalid>','2026-11-02 12:01:00'),'Case-only SQL match rejected by exact comparator');
$db->exec('DELETE FROM client_emails');$insert->execute([1,1,$id,'client@example.invalid']);
receiptCheck(bdta_email_record_read_receipt($db,$report,'client@example.invalid','<r@example.invalid>','2026-11-02 12:01:00'),'First report recorded');
receiptCheck(!bdta_email_record_read_receipt($db,$report,'client@example.invalid','<r2@example.invalid>','2026-11-02 12:02:00'),'Repeated report idempotent');
$stored = $db->query('SELECT read_receipt_source_message_id FROM client_emails');
if ($stored === false) { throw new RuntimeException('Fixture query failed'); }
receiptCheck($stored->fetchColumn()==='<r@example.invalid>','First source retained');
echo "PASS: $count read-receipt option/correlation checks; synthetic database only.\n";
