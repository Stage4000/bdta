<?php
require_once __DIR__ . '/settings.php';

function bdta_get_tawk_to_widget_script(): string {
    if (!Settings::get('tawk_to_enabled', false)) {
        return '';
    }

    $property_id = trim(scalar_string(Settings::get('tawk_to_property_id', '')));
    $widget_id = trim(scalar_string(Settings::get('tawk_to_widget_id', 'default')));

    if ($property_id === '' || preg_match('/^[A-Za-z0-9]+$/', $property_id) !== 1) {
        return '';
    }

    if ($widget_id === '') {
        $widget_id = 'default';
    }

    if (preg_match('/^[A-Za-z0-9_-]+$/', $widget_id) !== 1) {
        return '';
    }

    $embed_url = 'https://embed.tawk.to/' . rawurlencode($property_id) . '/' . rawurlencode($widget_id);

    return <<<HTML
<script>
var Tawk_API = Tawk_API || {};
var Tawk_LoadStart = new Date();
(function () {
    'use strict';
    var s1 = document.createElement('script');
    var s0 = document.getElementsByTagName('script')[0];
    s1.async = true;
    s1.src = '{$embed_url}';
    s1.charset = 'UTF-8';
    s1.setAttribute('crossorigin', '*');
    if (s0 && s0.parentNode) {
        s0.parentNode.insertBefore(s1, s0);
    } else {
        document.head.appendChild(s1);
    }
}());
</script>
HTML;
}

function bdta_render_tawk_to_widget(): void {
    echo bdta_get_tawk_to_widget_script();
}
