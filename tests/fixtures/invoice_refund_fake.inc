<?php
// Run only with php -n: the cURL extension must be absent and socket functions disabled.
if (extension_loaded('curl') || ini_get('allow_url_fopen') !== '0') {
    throw new RuntimeException('Refund fixture requires network-disabled PHP without cURL');
}
foreach (['CURLOPT_RETURNTRANSFER', 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_USERPWD', 'CURLOPT_HTTPHEADER', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLINFO_HTTP_CODE'] as $i => $name) {
    define($name, 1000 + $i);
}
class InvoiceRefundFakeCurl {
    public array $options = [];
    public int $code = 200;
    public function __construct(public string $url) {}
}
function curl_init(?string $url = null): InvoiceRefundFakeCurl { return new InvoiceRefundFakeCurl($url ?? ''); }
function curl_setopt_array(InvoiceRefundFakeCurl $c, array $options): bool { $c->options = $options; return true; }
function curl_exec(InvoiceRefundFakeCurl $c): string|false {
    if (!str_starts_with($c->url, 'https://api.stripe.com/v1/refunds')) {
        throw new RuntimeException('Fake rejects all non-refund endpoints');
    }
    $handle = fopen(getenv('BDTA_REFUND_FAKE_STORE'), 'c+');
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle), true) ?: ['refunds' => [], 'calls' => []];
    $fields = [];
    parse_str($c->options[CURLOPT_POSTFIELDS] ?? parse_url($c->url, PHP_URL_QUERY) ?? '', $fields);
    $key = '';
    foreach ($c->options[CURLOPT_HTTPHEADER] ?? [] as $header) {
        if (str_starts_with($header, 'Idempotency-Key: ')) $key = substr($header, 17);
    }
    $post = !empty($c->options[CURLOPT_POST]);
    $state['calls'][] = ['post' => $post, 'key' => $key, 'fields' => $fields];
    $mode = $state['mode'] ?? '';
    if ($post) {
        $existing = $key !== '' ? ($state['keys'][$key] ?? null) : null;
        if ($existing !== null) {
            $result = $state['refunds'][$existing];
            if (($state['params'][$key] ?? null) !== $fields) {
                $c->code = 400;
                $result = ['error' => ['message' => 'idempotency parameters changed']];
            }
        } else {
            $id = 're_fake_' . (count($state['refunds']) + 1);
            $result = ['id' => $id, 'status' => $mode === 'failed' ? 'failed' : 'succeeded', 'amount' => (int)($fields['amount'] ?? 0), 'currency' => 'usd', 'payment_intent' => $fields['payment_intent'], 'metadata' => $fields['metadata'] ?? []];
            $state['refunds'][$id] = $result;
            if ($key !== '') { $state['keys'][$key] = $id; $state['params'][$key] = $fields; }
        }
    } elseif ($mode === 'list_failure') {
        $c->code = 503; $result = ['error' => ['message' => 'synthetic list failure']];
    } else {
        $refunds = array_values(array_filter($state['refunds'], fn($r) => $r['payment_intent'] === ($fields['payment_intent'] ?? '')));
        if (isset($fields['starting_after'])) {
            $offset = array_search($fields['starting_after'], array_column($refunds, 'id'), true);
            $refunds = array_slice($refunds, $offset === false ? 0 : $offset + 1);
        }
        // One item/page exercises cursor handling even for a short history.
        $result = ['data' => array_slice($refunds, 0, 1), 'has_more' => count($refunds) > 1];
    }
    if ($mode === 'drop_response') $state['mode'] = '';
    rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state)); fflush($handle);
    flock($handle, LOCK_UN); fclose($handle);
    if ($post && isset($state['hold_file'])) {
        file_put_contents($state['hold_file'] . '.entered', 'provider accepted');
        $deadline = microtime(true) + 15;
        while (file_exists($state['hold_file'])) {
            if (microtime(true) > $deadline) throw new RuntimeException('Provider hold timed out');
            usleep(10000); clearstatcache();
        }
    }
    if ($mode === 'delay') usleep(200000);
    return $mode === 'drop_response' && $post ? false : json_encode($result);
}
function curl_getinfo(InvoiceRefundFakeCurl $c, ?int $option = null): mixed { return $option === CURLINFO_HTTP_CODE ? $c->code : []; }
function curl_error(InvoiceRefundFakeCurl $c): string { return 'synthetic response loss'; }
function curl_close(InvoiceRefundFakeCurl $c): void {}
