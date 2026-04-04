#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/session_config.php';

ob_start();

function bdta_set_session_lifetime_env(string|false $value): void {
    if ($value === false) {
        putenv('SESSION_LIFETIME_SECONDS');
        unset($_ENV['SESSION_LIFETIME_SECONDS'], $_SERVER['SESSION_LIFETIME_SECONDS']);
        return;
    }

    putenv('SESSION_LIFETIME_SECONDS=' . $value);
    $_ENV['SESSION_LIFETIME_SECONDS'] = $value;
    $_SERVER['SESSION_LIFETIME_SECONDS'] = $value;
}

$cases = [
    [
        'env' => false,
        'expected' => BDTA_DEFAULT_SESSION_LIFETIME_SECONDS,
        'label' => 'uses default lifetime when env is missing',
    ],
    [
        'env' => '3600',
        'expected' => 3600,
        'label' => 'uses configured positive lifetime',
    ],
    [
        'env' => 'invalid',
        'expected' => BDTA_DEFAULT_SESSION_LIFETIME_SECONDS,
        'label' => 'falls back on invalid lifetime',
    ],
    [
        'env' => '0',
        'expected' => BDTA_DEFAULT_SESSION_LIFETIME_SECONDS,
        'label' => 'falls back on non-positive lifetime',
    ],
];

$passed_labels = [];

foreach ($cases as $case) {
    bdta_set_session_lifetime_env($case['env']);

    $resolved = bdta_get_session_lifetime_seconds();
    if ($resolved !== $case['expected']) {
        fwrite(STDERR, "Failed: {$case['label']} (got {$resolved})\n");
        exit(1);
    }

    $applied = bdta_apply_session_ini_settings();
    if ($applied !== $case['expected']) {
        fwrite(STDERR, "Failed to apply: {$case['label']} (got {$applied})\n");
        exit(1);
    }

    if ((int) ini_get('session.gc_maxlifetime') !== $case['expected']) {
        fwrite(STDERR, "Failed gc_maxlifetime assertion for {$case['label']}\n");
        exit(1);
    }

    if ((int) ini_get('session.cookie_lifetime') !== $case['expected']) {
        fwrite(STDERR, "Failed cookie_lifetime assertion for {$case['label']}\n");
        exit(1);
    }

    $passed_labels[] = $case['label'];
}

bdta_set_session_lifetime_env(false);

ob_end_clean();

echo "=== Session Config Tests ===\n\n";
if (count($passed_labels) !== count($cases)) {
    fwrite(STDERR, "Failed: not all session config cases completed successfully.\n");
    exit(1);
}

foreach ($passed_labels as $label) {
    echo "✓ {$label}\n";
}
echo "\nAll session config tests passed.\n";
