<?php
/**
 * Mailjet newsletter opt-in helpers.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/form_types.php';

class MailjetNewsletterService
{
    private const API_BASE_URL = 'https://api.mailjet.com/v3/REST';
    private const REQUEST_TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 10;

    /**
     * @return array{success: bool, message: string}
     */
    public function subscribeContact(string $email, string $name = ''): array
    {
        $email = trim($email);
        $name = trim($name);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'A valid email address is required for newsletter subscriptions.'];
        }

        $api_key = trim(scalar_string(Settings::get('mailjet_api_key', '')));
        $api_secret = trim(scalar_string(Settings::get('mailjet_api_secret', '')));
        $list_id = safe_int(Settings::get('mailjet_newsletter_list_id', 0));
        if ($api_key === '' || $api_secret === '' || $list_id <= 0) {
            return ['success' => false, 'message' => 'Mailjet newsletter settings are incomplete.'];
        }

        $contact_payload = ['Email' => $email];
        if ($name !== '') {
            $contact_payload['Name'] = $name;
        }

        try {
            $this->requestJson(
                'POST',
                self::API_BASE_URL . '/contact',
                $api_key,
                $api_secret,
                $contact_payload
            );
            $this->requestJson(
                'POST',
                self::API_BASE_URL . '/contactslist/' . $list_id . '/managecontact',
                $api_key,
                $api_secret,
                [
                    'Email' => $email,
                    // Mailjet's addforce action subscribes the contact even if they had previously unsubscribed.
                    'Action' => 'addforce',
                ]
            );
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Contact subscribed to the Mailjet newsletter list.'];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<int|string, mixed>
     */
    protected function requestJson(
        string $method,
        string $url,
        string $api_key,
        string $api_secret,
        ?array $payload = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required for Mailjet newsletter subscriptions.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for the Mailjet request.');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $encoded_payload = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payload !== null && $encoded_payload === false) {
            curl_close($ch);
            throw new RuntimeException('Unable to encode the Mailjet newsletter request payload.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $api_key . ':' . $api_secret,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        ]);

        if ($encoded_payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_payload);
        }

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Mailjet request failed: ' . $curl_error);
        }

        if ($http_code < 200 || $http_code >= 300) {
            throw new RuntimeException('Mailjet request failed with HTTP status ' . $http_code . '.');
        }

        $decoded = json_decode(scalar_string($response), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Mailjet response could not be decoded as JSON.');
        }

        /** @var array<int|string, mixed> $decoded */
        return $decoded;
    }
}

/**
 * @return array{success: bool, message: string}
 */
function bdta_subscribe_mailjet_contact_to_newsletter(string $email, string $name = ''): array
{
    $service = new MailjetNewsletterService();
    return $service->subscribeContact($email, $name);
}
