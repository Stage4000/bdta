<?php
/**
 * Cloudflare Turnstile helpers for public-facing forms.
 */

function bdta_turnstile_scalar(mixed $value): string
{
    if (function_exists('scalar_string')) {
        return scalar_string($value);
    }

    if (is_string($value)) {
        return $value;
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return '';
}

function bdta_turnstile_setting(string $key): string
{
    if (class_exists('Settings') && method_exists('Settings', 'get')) {
        return trim(bdta_turnstile_scalar(Settings::get($key, '')));
    }

    return '';
}

function bdta_get_turnstile_site_key(): string
{
    return bdta_turnstile_setting('turnstile_site_key');
}

function bdta_get_turnstile_secret_key(): string
{
    return bdta_turnstile_setting('turnstile_secret_key');
}

function bdta_turnstile_is_enabled(): bool
{
    return bdta_get_turnstile_site_key() !== '' && bdta_get_turnstile_secret_key() !== '';
}

function bdta_get_turnstile_assets_html(): string
{
    if (!bdta_turnstile_is_enabled()) {
        return '';
    }

    return '<script src="/assets/js/public/turnstile.js"></script>' . "\n"
        . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=bdtaRenderTurnstileWidgets&render=explicit" async defer></script>';
}

/**
 * @param array<string, string> $options
 */
function bdta_get_turnstile_widget_markup(array $options = []): string
{
    if (!bdta_turnstile_is_enabled()) {
        return '';
    }

    $wrapper_class = trim('bdta-turnstile-wrapper ' . bdta_turnstile_scalar($options['wrapper_class'] ?? 'mt-3'));
    $theme = trim(bdta_turnstile_scalar($options['theme'] ?? 'auto'));
    if ($theme === '') {
        $theme = 'auto';
    }

    return sprintf(
        '<div class="%s"><div class="bdta-turnstile" data-sitekey="%s" data-theme="%s"></div></div>',
        htmlspecialchars($wrapper_class, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(bdta_get_turnstile_site_key(), ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($theme, ENT_QUOTES, 'UTF-8')
    );
}

function bdta_inject_turnstile_widgets_into_forms(string $html): string
{
    if (!bdta_turnstile_is_enabled()) {
        return $html;
    }

    if (stripos($html, '<form') !== false) {
        $widget_markup = bdta_get_turnstile_widget_markup();
        $html = preg_replace_callback(
            '/<form\b[^>]*>.*?<\/form>/is',
            static function (array $matches) use ($widget_markup): string {
                $form_html = bdta_turnstile_scalar($matches[0] ?? '');
                if (
                    $form_html === ''
                    || stripos($form_html, 'bdta-turnstile') !== false
                    || stripos($form_html, 'cf-turnstile') !== false
                    || stripos($form_html, 'data-bdta-turnstile="skip"') !== false
                ) {
                    return $form_html;
                }

                return preg_replace('/<\/form>/i', $widget_markup . "\n</form>", $form_html, 1) ?? $form_html;
            },
            $html
        ) ?? $html;
        return $html;
    }

    return $html;
}

function bdta_prepare_public_html_with_turnstile(string $html): string
{
    $html = bdta_inject_turnstile_widgets_into_forms($html);

    if (
        !bdta_turnstile_is_enabled()
        || stripos($html, 'bdta-turnstile') === false
        || stripos($html, 'challenges.cloudflare.com/turnstile') !== false
    ) {
        return $html;
    }

    $assets_html = bdta_get_turnstile_assets_html();
    if ($assets_html === '') {
        return $html;
    }

    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $assets_html . "\n</body>", $html, 1) ?? ($html . $assets_html);
    }

    return $html . "\n" . $assets_html;
}

/**
 * @param array<string, mixed> $payload
 */
function bdta_get_turnstile_response_token(array $payload): string
{
    foreach (['cf-turnstile-response', 'turnstile_token', 'turnstileToken'] as $key) {
        $token = trim(bdta_turnstile_scalar($payload[$key] ?? ''));
        if ($token !== '') {
            return $token;
        }
    }

    return '';
}

/**
 * Verify a public form payload that may include Turnstile response tokens under
 * keys like cf-turnstile-response, turnstile_token, or turnstileToken.
 *
 * @param array<string, mixed> $payload
 * @return array{success:bool,error?:string}
 */
function bdta_verify_turnstile_submission(array $payload, ?string $remote_ip = null): array
{
    if (!bdta_turnstile_is_enabled()) {
        return ['success' => true];
    }

    $response_token = bdta_get_turnstile_response_token($payload);
    if ($response_token === '') {
        return ['success' => false, 'error' => 'Please confirm you are not a robot and try again.'];
    }

    $post_fields = [
        'secret' => bdta_get_turnstile_secret_key(),
        'response' => $response_token,
    ];

    $remote_ip = trim((string) $remote_ip);
    if ($remote_ip !== '') {
        $post_fields['remoteip'] = $remote_ip;
    }

    $response_body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($post_fields),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $curl_response = curl_exec($ch);
            if (is_string($curl_response)) {
                $response_body = $curl_response;
            }
            curl_close($ch);
        }
    }

    if ($response_body === null) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($post_fields),
                'timeout' => 10,
            ],
        ]);
        $stream_response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if (is_string($stream_response)) {
            $response_body = $stream_response;
        }
    }

    if (!is_string($response_body) || trim($response_body) === '') {
        error_log('Turnstile verification failed: empty response from siteverify endpoint.');
        return ['success' => false, 'error' => 'Please confirm you are not a robot and try again.'];
    }

    $decoded = json_decode($response_body, true);
    if (!is_array($decoded)) {
        error_log('Turnstile verification failed: invalid JSON response.');
        return ['success' => false, 'error' => 'Please confirm you are not a robot and try again.'];
    }

    if (!empty($decoded['success'])) {
        return ['success' => true];
    }

    $error_codes = isset($decoded['error-codes']) && is_array($decoded['error-codes'])
        ? implode(', ', array_map('bdta_turnstile_scalar', $decoded['error-codes']))
        : 'unknown_error';
    error_log('Turnstile verification failed: ' . $error_codes);

    return ['success' => false, 'error' => 'Please confirm you are not a robot and try again.'];
}
