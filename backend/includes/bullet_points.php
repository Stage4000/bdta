<?php
/**
 * Shared helpers for newline-delimited public-facing bullet points.
 */

/**
 * @return list<string>
 */
function bdta_parse_bullet_points(?string $value): array
{
    $normalized = bdta_normalize_bullet_point_text($value);
    if ($normalized === '') {
        return [];
    }

    return explode("\n", $normalized);
}

function bdta_normalize_bullet_point_text(?string $value): string
{
    if ($value === null) {
        return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $value);
    if (!is_array($lines)) {
        return '';
    }

    $clean_lines = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $clean_lines[] = $line;
        }
    }

    return $clean_lines === [] ? '' : implode("\n", $clean_lines);
}
