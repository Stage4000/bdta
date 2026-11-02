<?php
require_once dirname(__DIR__) . '/backend/includes/email_read_receipt_parser.php';
$count = 0;
function check(bool $ok, string $name): void {
    global $count;
    if (!$ok) { throw new RuntimeException($name); }
    $count++;
}
$body = "Reporting-UA: Example Reader\r\nFinal-Recipient: rfc822; client@example.test\r\nOriginal-Message-ID: <fixed.123@example.test>\r\nDisposition: manual-action/MDN-sent-manually; displayed\r\n";
$r = BdtaReadReceiptParser::parse($body);
check($r !== null && $r['displayed'], 'valid displayed');
check($r !== null && BdtaReadReceiptParser::matches($r, '<fixed.123@example.test>', 'CLIENT@example.test', 'client@example.test'), 'exact correlation');
check($r !== null && !BdtaReadReceiptParser::matches($r, '<fixed.124@example.test>', 'client@example.test', 'client@example.test'), 'foreign ID');
check($r !== null && !BdtaReadReceiptParser::matches($r, '<FIXED.123@example.test>', 'client@example.test', 'client@example.test'), 'ID case sensitive');
check($r !== null && !BdtaReadReceiptParser::matches($r, '<fixed.123@example.test>', 'other@example.test', 'other@example.test'), 'foreign recipient');
check($r !== null && !BdtaReadReceiptParser::matches($r, '<fixed.123@example.test>', 'client@example.test', 'forger@example.test'), 'from mismatch');
check($r !== null && !BdtaReadReceiptParser::matches($r, '<fixed.123@example.test>', 'client@example.test', 'client@example.test, forger@example.test'), 'multiple From rejected');
check(BdtaReadReceiptParser::parse($body . 'Final-Recipient: rfc822; other@example.test') === null, 'duplicate recipient');
check(BdtaReadReceiptParser::parse($body . 'Original-Message-ID: <other@example.test>') === null, 'duplicate ID');
check(BdtaReadReceiptParser::parse(str_replace('Original-Message-ID:', 'X-Original-Message-ID:', $body)) === null, 'missing ID');
check(BdtaReadReceiptParser::parse(str_replace('rfc822;', 'x400;', $body)) === null, 'non mailbox recipient');
check(BdtaReadReceiptParser::parse(str_replace('client@example.test', 'Client <client@example.test>', $body)) === null, 'display address rejected');
check(BdtaReadReceiptParser::parse($body . "\0") === null, 'NUL rejected');
check(BdtaReadReceiptParser::parse(str_repeat('x', 16385)) === null, 'oversize rejected');
check(BdtaReadReceiptParser::parse($body . "\nInjected body") === null, 'trailing body rejected');
check(BdtaReadReceiptParser::parse(str_replace('Final-Recipient: rfc822;', "Final-Recipient:\r\n rfc822;", $body)) !== null, 'folding accepted');
foreach (['deleted', 'processed', 'dispatched', 'denied', 'failed', 'extension'] as $type) {
    $other = BdtaReadReceiptParser::parse(str_replace('; displayed', '; ' . $type, $body));
    check($other !== null && !$other['displayed'], $type . ' not displayed');
}
$error = BdtaReadReceiptParser::parse(str_replace('; displayed', '; displayed/error', $body));
check($error !== null && !$error['displayed'], 'displayed with modifier not read');
class ReceiptFixturePart {
    public int $type = 2;
    public string $subtype = 'DISPOSITION-NOTIFICATION';
    public int $encoding = 0;
    public int $bytes = 0;
    /** @var list<self>|null */
    public ?array $parts = null;
}
class ReceiptFixtureStructure {
    public int $type = 1;
    public string $subtype = 'REPORT';
    /** @var list<ReceiptFixturePart> */
    public array $parts;
    /** @var list<stdClass> */
    public array $parameters;
    public function __construct(int $bytes) {
        $text = new ReceiptFixturePart(); $text->type = 0; $text->subtype = 'PLAIN';
        $report = new ReceiptFixturePart(); $report->bytes = $bytes;
        $this->parts = [$text, $report];
        $this->parameters = [(object)['attribute'=>'report-type','value'=>'disposition-notification']];
    }
}
function structure(int $bytes): ReceiptFixtureStructure {
    return new ReceiptFixtureStructure($bytes);
}
$s = structure(strlen($body));
$calls = [];
$got = BdtaReadReceiptParser::extract($s, function(string $part) use ($body, &$calls): string { $calls[]=$part; return $body; });
check($got === $r && $calls === ['2'], 'only second part fetched');
$s->subtype='mixed';
check(BdtaReadReceiptParser::extract($s, function(): string { throw new RuntimeException('must not fetch'); }) === null, 'mixed wrapper rejected');
$s=structure(strlen($body)); $s->parts[1]->type=0;
check(BdtaReadReceiptParser::extract($s, function(): string { throw new RuntimeException('must not fetch'); }) === null, 'text fake rejected');
$s=structure(32769);
check(BdtaReadReceiptParser::extract($s, function(): string { throw new RuntimeException('must not fetch'); }) === null, 'declared oversize no fetch');
$s=structure(strlen($body));
check(BdtaReadReceiptParser::extract($s, fn(): string => str_repeat('x',32769)) === null, 'actual oversize rejected');
$s->parts[1]->encoding=3; $s->parts[1]->bytes=strlen(base64_encode($body));
check(BdtaReadReceiptParser::extract($s, fn(): string => base64_encode($body)) === $r, 'base64');
check(BdtaReadReceiptParser::extract($s, fn(): string => '!invalid base64!') === null, 'bad base64');
$s->parts[1]->encoding=4; $s->parts[1]->bytes=strlen(quoted_printable_encode($body));
check(BdtaReadReceiptParser::extract($s, fn(): string => quoted_printable_encode($body)) === $r, 'quoted printable');
$s=structure(strlen($body)); $s->parameters[]=(object)['attribute'=>'report-type','value'=>'disposition-notification'];
check(BdtaReadReceiptParser::extract($s, function(): string { throw new RuntimeException('must not fetch'); }) === null, 'duplicate report-type');
$s=structure(strlen($body)); $s->parts[1]->parts=[];
check(BdtaReadReceiptParser::extract($s, function(): string { throw new RuntimeException('must not fetch'); }) === null, 'nested report not accepted');
check(BdtaReadReceiptParser::parse($body) === $r, 'deterministic duplicate fingerprint');
echo "PASS: {$count} read receipt parser assertions (no transport or database).\n";
