<?php

require_once __DIR__ . '/env_loader.php';

const BDTA_DEFAULT_SESSION_LIFETIME_SECONDS = 1209600;

function bdta_get_session_lifetime_seconds(): int {
    $configured = EnvLoader::get('SESSION_LIFETIME_SECONDS', (string) BDTA_DEFAULT_SESSION_LIFETIME_SECONDS);
    $lifetime = is_numeric($configured) ? (int) $configured : 0;

    return $lifetime > 0 ? $lifetime : BDTA_DEFAULT_SESSION_LIFETIME_SECONDS;
}

function bdta_apply_session_ini_settings(): int {
    $lifetime = bdta_get_session_lifetime_seconds();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);

    return $lifetime;
}
