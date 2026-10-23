<?php
/** CLI-only; dry-run unless --apply is explicitly supplied. No mailbox/provider access. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_attachments.php';
$apply = in_array('--apply', $argv, true);
$result = bdta_email_cleanup_attachments((new Database())->getConnection(), $apply);
echo ($apply ? 'Applied' : 'Dry run') . ': ' . json_encode($result) . PHP_EOL;
