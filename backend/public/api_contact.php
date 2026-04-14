<?php
/**
 * Public contact form API endpoint.
 *
 * The homepage contact form is temporarily unavailable.
 */

header('Content-Type: application/json');
http_response_code(410);

echo json_encode([
    'success' => false,
    'error' => 'The public contact form is currently unavailable.',
]);
