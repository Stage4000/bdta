#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/public_contact_form.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sample_html = <<<HTML
<section id="contact" class="homepage-section">
    <div class="container homepage-section-shell">
        <div class="row">
            <div class="col-lg-6 mb-5 mb-lg-0">
                <h2>Get In Touch</h2>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg">
                    <div class="card-body">
                        <h4>Send Us a Message</h4>
                        <form id="contactForm">
                            <input id="name">
                            <button type="submit">Send</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
HTML;

$sanitized_html = bdta_strip_public_contact_form_markup($sample_html);
assertTrue(!str_contains($sanitized_html, 'id="contactForm"'), 'Expected homepage contact form markup to be removed.');
assertTrue(!str_contains($sanitized_html, 'Send Us a Message'), 'Expected contact form card content to be removed.');
assertTrue(
    preg_match('/class="row[^"]*\bjustify-content-center\b[^"]*"/', $sanitized_html) === 1,
    'Expected the contact row to stay centered after removing the form column.'
);

$unchanged_html = '<div class="row"><div class="col-lg-6"><p>No contact form here.</p></div></div>';
assertTrue(
    bdta_strip_public_contact_form_markup($unchanged_html) === $unchanged_html,
    'Expected unrelated markup to remain unchanged.'
);

$homepage_html = file_get_contents(dirname(__DIR__) . '/index.html');
if ($homepage_html === false) {
    throw new RuntimeException('Failed to read static homepage.');
}

assertTrue(!str_contains($homepage_html, 'id="contactForm"'), 'Expected static homepage not to include the public contact form.');
assertTrue(!str_contains($homepage_html, 'Send Us a Message'), 'Expected static homepage not to include the contact form card.');

$site_js = file_get_contents(dirname(__DIR__) . '/assets/js/public/site.js');
if ($site_js === false) {
    throw new RuntimeException('Failed to read public site JavaScript.');
}

assertTrue(!str_contains($site_js, 'initContactForm'), 'Expected public site JavaScript not to initialize the removed contact form.');
assertTrue(!str_contains($site_js, '/backend/public/api_contact.php'), 'Expected public site JavaScript not to call the removed public contact API.');

$api_path = dirname(__DIR__) . '/backend/public/api_contact.php';
ob_start();
require $api_path;
$api_json = trim((string) ob_get_clean());

assertTrue(str_contains($api_json, '"success":false'), 'Expected disabled public contact API to reject submissions.');
assertTrue(str_contains($api_json, 'currently unavailable'), 'Expected disabled public contact API to explain that the form is unavailable.');

echo "Public homepage contact form removal test passed.\n";
