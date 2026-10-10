#!/usr/bin/env php
<?php

/**
 * Render the real intake/location template without bootstrapping the app, a
 * database, mail, or payment services. The first location_value input is what
 * both submitBooking() and getLocationSummary() read for single/fixed locations.
 */
function locationValueSource(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Cannot read source: ' . $path);
    }
    return $source;
}

/** @return resource */
function locationValueFixture(string $source)
{
    $fixture = tmpfile();
    if ($fixture === false) {
        throw new RuntimeException('Cannot create isolated template fixture.');
    }
    if (fwrite($fixture, $source) !== strlen($source)) {
        fclose($fixture);
        throw new RuntimeException('Cannot write isolated template fixture.');
    }
    return $fixture;
}

/** @param resource $fixture */
function locationValueFixturePath($fixture): string
{
    $path = stream_get_meta_data($fixture)['uri'] ?? null;
    if (!is_string($path)) {
        throw new RuntimeException('Cannot locate isolated template fixture.');
    }
    return $path;
}

/** @param list<string> $names */
function loadLocationValuePureFunctions(string $source, array $names): void
{
    foreach ($names as $name) {
        // These top-level declarations end at an unindented closing brace.
        // Load their actual bodies, never config.php or book.php's bootstrap.
        $pattern = '/^function ' . preg_quote($name, '/') . '\([\s\S]*?^}/m';
        if (preg_match_all($pattern, $source, $matches) !== 1) {
            throw new RuntimeException('Cannot isolate pure function: ' . $name);
        }
        $fixture = locationValueFixture("<?php\n" . $matches[0][0]);
        try {
            require locationValueFixturePath($fixture);
        } finally {
            fclose($fixture); // tmpfile() removes this owned fixture on close.
        }
    }
}

function locationValueTemplate(string $source): string
{
    $startMarker = '<?php if ($booking_intake_form): ?>';
    $endMarker = '<?php if (!empty($required_forms)): ?>';
    if (substr_count($source, $startMarker) !== 1 || substr_count($source, $endMarker) !== 1) {
        throw new RuntimeException('Cannot uniquely isolate intake and location template.');
    }
    $start = strpos($source, $startMarker);
    $end = strpos($source, $endMarker);
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Intake and location template boundaries are invalid.');
    }
    return substr($source, $start, $end - $start);
}

/**
 * @param list<array<string, mixed>> $fields
 * @param array<string, mixed> $selected_type
 */
function renderLocationValueTemplate(string $template, array $fields, array $selected_type): string
{
    $booking_intake_form = ['fields' => $fields];
    $portal_prefill_profile = ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];
    $fixture = locationValueFixture($template);
    ob_start();
    try {
        require locationValueFixturePath($fixture);
        $html = ob_get_contents();
        if ($html === false) {
            throw new RuntimeException('Cannot capture location template.');
        }
        return $html;
    } finally {
        ob_end_clean();
        fclose($fixture);
    }
}

/** @return list<array<string, string>> */
function locationValueInputs(string $html, string $enteredValue): array
{
    preg_match_all('/<input\b[^>]*>/i', $html, $tags);
    $inputs = [];
    foreach ($tags[0] as $tag) {
        preg_match_all('/([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag, $attributes, PREG_SET_ORDER);
        $input = [];
        foreach ($attributes as $attribute) {
            $input[$attribute[1]] = html_entity_decode($attribute[3] ?? $attribute[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (($input['name'] ?? '') !== 'location_value') {
            continue;
        }
        // Simulate typing only into the actual editable location control.
        if (($input['id'] ?? '') === 'publicLocationValueInput') {
            $input['value'] = $enteredValue;
        }
        $inputs[] = $input;
    }
    return $inputs;
}

try {
    $root = dirname(__DIR__);
    $bookSource = locationValueSource($root . '/backend/public/book.php');
    loadLocationValuePureFunctions(locationValueSource($root . '/backend/includes/database.php'), ['scalar_string']);
    loadLocationValuePureFunctions(locationValueSource($root . '/backend/includes/config.php'), [
        'escape', 'array_string_value', 'assoc_row', 'assoc_rows',
        'decode_json_assoc', 'decode_json_assoc_list',
    ]);
    loadLocationValuePureFunctions($bookSource, [
        'public_book_string', 'public_book_assoc_rows', 'public_book_string_list',
        'public_book_portal_prefill_value',
    ]);
    loadLocationValuePureFunctions(locationValueSource($root . '/backend/includes/form_types.php'), [
        'bdta_pet_info_group_field_type',
    ]);
    $template = locationValueTemplate($bookSource);

    $cases = [
        'custom address' => [
            'type' => ['location_types' => '["custom_address"]'],
            'value' => '123 Example Road, Unit "B" & Annex',
        ],
        'video call' => [
            'type' => ['location_types' => '["webcall"]'],
            'value' => 'https://video.example.test/meeting?room=training&guest=1',
        ],
        'mini session' => [
            'type' => ['is_mini_session' => 1, 'mini_session_location' => 'Training Hall "A" & Garden'],
            'value' => 'Training Hall "A" & Garden',
        ],
        'field rental' => [
            'type' => ['is_field_rental' => 1, 'field_rental_location' => 'North Field, Gate 2'],
            'value' => 'North Field, Gate 2',
        ],
        'group class' => [
            'type' => ['is_group_class' => 1, 'group_class_location' => 'Indoor Training Room'],
            'value' => 'Indoor Training Room',
        ],
    ];
    $intakes = [
        'missing description' => [['label' => 'Name', 'type' => 'text']],
        'empty description' => [['label' => 'Name', 'type' => 'text', 'description' => '']],
        'multiple undescribed fields' => [
            ['label' => 'Name', 'type' => 'text'],
            ['label' => 'Notes', 'type' => 'textarea', 'description' => ''],
        ],
        'described field control' => [['label' => 'Name', 'type' => 'text', 'description' => 'Your full name']],
    ];
    $failures = [];
    foreach ($intakes as $intakeLabel => $fields) {
        foreach ($cases as $caseLabel => $case) {
            $label = $intakeLabel . ' / ' . $caseLabel;
            $html = renderLocationValueTemplate($template, $fields, $case['type']);
            $inputs = locationValueInputs($html, $case['value']);
            $firstValue = $inputs[0]['value'] ?? '';
            if (count($inputs) !== 1 || $firstValue !== $case['value']) {
                $failures[] = $label . ': expected one location_value preserving ' . var_export($case['value'], true)
                    . '; found ' . count($inputs) . ', first value ' . var_export($firstValue, true);
            } else {
                echo 'PASS: ' . $label . ' preserves its legitimate location value.' . PHP_EOL;
            }
        }
    }
    if ($failures !== []) {
        throw new RuntimeException(implode(PHP_EOL, $failures));
    }
    echo 'Public booking location value regression tests passed (20 cases).' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
