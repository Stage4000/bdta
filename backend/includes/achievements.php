<?php

if (!function_exists('bdta_achievement_row_string')) {
    /**
     * @param array<string|int, mixed> $row
     */
    function bdta_achievement_row_string(array $row, string|int $key, string $default = ''): string
    {
        $value = $row[$key] ?? $default;
        return is_scalar($value) ? (string) $value : $default;
    }
}

if (!function_exists('bdta_achievement_row_int')) {
    /**
     * @param array<string|int, mixed> $row
     */
    function bdta_achievement_row_int(array $row, string|int $key, int $default = 0): int
    {
        $value = $row[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bdta_achievement_modes')) {
    /**
     * @return array<string, string>
     */
    function bdta_achievement_modes(): array
    {
        return [
            'badge_only' => 'Badge only',
            'certificate_only' => 'Certificate only',
            'badge_certificate' => 'Badge + certificate',
        ];
    }
}

if (!function_exists('bdta_achievement_scopes')) {
    /**
     * @return array<string, string>
     */
    function bdta_achievement_scopes(): array
    {
        return [
            'general' => 'General / reusable',
            'custom' => 'Custom / one-off',
        ];
    }
}

if (!function_exists('bdta_normalize_achievement_mode')) {
    function bdta_normalize_achievement_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return array_key_exists($mode, bdta_achievement_modes()) ? $mode : 'badge_certificate';
    }
}

if (!function_exists('bdta_normalize_achievement_scope')) {
    function bdta_normalize_achievement_scope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        return array_key_exists($scope, bdta_achievement_scopes()) ? $scope : 'general';
    }
}

if (!function_exists('bdta_achievement_mode_supports_badge')) {
    function bdta_achievement_mode_supports_badge(string $mode): bool
    {
        return in_array(bdta_normalize_achievement_mode($mode), ['badge_only', 'badge_certificate'], true);
    }
}

if (!function_exists('bdta_achievement_mode_supports_certificate')) {
    function bdta_achievement_mode_supports_certificate(string $mode): bool
    {
        return in_array(bdta_normalize_achievement_mode($mode), ['certificate_only', 'badge_certificate'], true);
    }
}

if (!function_exists('bdta_achievement_logo_url')) {
    function bdta_achievement_logo_url(): string
    {
        return '/assets/images/bdta-logo.png';
    }
}

if (!function_exists('bdta_default_certificate_body_html')) {
    function bdta_default_certificate_body_html(): string
    {
        return <<<HTML
<p style="font-size:1.2rem;margin-bottom:0.75rem;">This certifies that</p>
<p style="font-size:2.1rem;font-weight:700;color:#9a0073;margin:0.25rem 0;">{{client_name}}</p>
<p style="font-size:1.1rem;margin:0.75rem 0;">with {{dog_name}} has earned</p>
<p style="font-size:1.7rem;font-weight:700;color:#0a9a9c;margin:0.5rem 0;">{{achievement_title}}</p>
<p style="font-size:1.05rem;margin:0.75rem 0;">Program: <strong>{{program_name}}</strong></p>
<p style="font-size:1.05rem;margin:0.75rem 0;">Awarded on <strong>{{award_date}}</strong></p>
HTML;
    }
}

if (!function_exists('bdta_achievement_certificate_placeholders')) {
    /**
     * @param array<string, mixed> $assignment
     * @return array<string, string>
     */
    function bdta_achievement_certificate_placeholders(array $assignment): array
    {
        $client_name = trim(bdta_achievement_row_string($assignment, 'client_name'));
        $dog_name = trim(bdta_achievement_row_string($assignment, 'dog_name'));
        $program_name = trim(bdta_achievement_row_string($assignment, 'program_name'));
        $award_date = trim(bdta_achievement_row_string($assignment, 'awarded_on'));
        $achievement_title = trim(bdta_achievement_row_string($assignment, 'achievement_title'));

        return [
            '{{client_name}}' => htmlspecialchars($client_name !== '' ? $client_name : 'Client', ENT_QUOTES, 'UTF-8'),
            '{{dog_name}}' => htmlspecialchars($dog_name !== '' ? $dog_name : 'their dog', ENT_QUOTES, 'UTF-8'),
            '{{program_name}}' => htmlspecialchars($program_name !== '' ? $program_name : 'Training Program', ENT_QUOTES, 'UTF-8'),
            '{{award_date}}' => htmlspecialchars($award_date !== '' ? $award_date : date('Y-m-d'), ENT_QUOTES, 'UTF-8'),
            '{{achievement_title}}' => htmlspecialchars($achievement_title !== '' ? $achievement_title : 'Achievement', ENT_QUOTES, 'UTF-8'),
            '{{notes}}' => nl2br(htmlspecialchars(trim(bdta_achievement_row_string($assignment, 'notes')), ENT_QUOTES, 'UTF-8')),
        ];
    }
}

if (!function_exists('bdta_render_achievement_certificate_body')) {
    /**
     * @param array<string, mixed> $assignment
     */
    function bdta_render_achievement_certificate_body(string $template, array $assignment): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = bdta_default_certificate_body_html();
        }

        return strtr($template, bdta_achievement_certificate_placeholders($assignment));
    }
}

if (!function_exists('bdta_render_achievement_certificate_html')) {
    /**
     * @param array<string, mixed> $assignment
     * @param list<array{label: string, href: string, class?: string}> $extra_actions
     */
    function bdta_render_achievement_certificate_html(array $assignment, array $extra_actions = []): string
    {
        $title = htmlspecialchars(bdta_achievement_row_string($assignment, 'achievement_title', 'Achievement Certificate'), ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(trim(bdta_achievement_row_string($assignment, 'achievement_description')), ENT_QUOTES, 'UTF-8');
        $body_html = bdta_render_achievement_certificate_body(bdta_achievement_row_string($assignment, 'certificate_body_html'), $assignment);
        $logo_url = htmlspecialchars(bdta_achievement_logo_url(), ENT_QUOTES, 'UTF-8');
        $assignment_id = bdta_achievement_row_int($assignment, 'id');
        $download_href = htmlspecialchars('?id=' . $assignment_id . '&download=1', ENT_QUOTES, 'UTF-8');

        $description_block = $description === ''
            ? ''
            : '<p style="margin:1rem auto 0;max-width:640px;color:#5b5661;">' . $description . '</p>';

        $action_html = '';
        foreach ($extra_actions as $action) {
            $action_label = htmlspecialchars(trim($action['label']), ENT_QUOTES, 'UTF-8');
            $action_href = htmlspecialchars(trim($action['href']), ENT_QUOTES, 'UTF-8');
            $action_class = trim((string)($action['class'] ?? ''));
            $action_class = $action_class === 'secondary' ? 'secondary' : '';
            $action_class_attr = $action_class !== ''
                ? ' class="' . htmlspecialchars($action_class, ENT_QUOTES, 'UTF-8') . '"'
                : '';
            if ($action_label === '' || $action_href === '') {
                continue;
            }

            $action_html .= '<a' . $action_class_attr . ' href="' . $action_href . '">' . $action_label . '</a>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f5ef;
            color: #2f2a32;
        }
        .certificate-shell {
            max-width: 1100px;
            margin: 2rem auto;
            padding: 1.5rem;
        }
        .certificate-card {
            background: #ffffff;
            border: 12px solid #0a9a9c;
            outline: 8px solid #9a0073;
            border-radius: 24px;
            padding: 3rem 4rem;
            text-align: center;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.12);
        }
        .certificate-logo {
            max-width: 180px;
            margin-bottom: 1.25rem;
        }
        .certificate-ribbon {
            height: 10px;
            width: 180px;
            margin: 1.25rem auto 2rem;
            border-radius: 999px;
            background: linear-gradient(90deg, #0a9a9c 0%, #a39f89 50%, #9a0073 100%);
        }
        .certificate-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .certificate-actions a,
        .certificate-actions button {
            border: 0;
            border-radius: 999px;
            padding: 0.8rem 1.3rem;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            color: #ffffff;
            background: #9a0073;
        }
        .certificate-actions a.secondary,
        .certificate-actions button.secondary {
            background: #0a9a9c;
        }
        @media print {
            .certificate-actions {
                display: none !important;
            }
            .certificate-shell {
                margin: 0;
                padding: 0;
            }
            .certificate-card {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="certificate-shell">
        <div class="certificate-actions">
            {$action_html}
            <button type="button" onclick="window.print()">Print certificate</button>
            <a class="secondary" href="{$download_href}">Download PDF</a>
        </div>
        <div class="certificate-card">
            <img class="certificate-logo" src="{$logo_url}" alt="Brook's Dog Training Academy logo">
            <div class="certificate-ribbon" aria-hidden="true"></div>
            <h1 style="font-size:2.6rem;margin:0;color:#2f2a32;">Certificate of Achievement</h1>
            <h2 style="font-size:1.4rem;font-weight:600;margin:0.75rem 0 0;color:#a39f89;">Brook's Dog Training Academy</h2>
            {$description_block}
            <div style="margin-top:2rem;">{$body_html}</div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}

if (!function_exists('bdta_achievement_certificate_filename')) {
    /**
     * @param array<string, mixed> $assignment
     */
    function bdta_achievement_certificate_filename(array $assignment): string
    {
        $parts = [
            bdta_achievement_row_string($assignment, 'client_name', 'client'),
            bdta_achievement_row_string($assignment, 'achievement_title', 'achievement'),
            bdta_achievement_row_string($assignment, 'awarded_on', date('Y-m-d')),
        ];
        $base = strtolower(trim(implode('-', $parts)));
        $base = preg_replace('/[^a-z0-9]+/i', '-', $base) ?? 'achievement-certificate';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'achievement-certificate';
        }

        return $base . '.pdf';
    }
}

if (!function_exists('bdta_generate_achievement_certificate_pdf')) {
    /**
     * @param array<string, mixed> $assignment
     */
    function bdta_generate_achievement_certificate_pdf(array $assignment): string
    {
        $lines = [
            "Brook's Dog Training Academy",
            'Certificate of Achievement',
            '',
            'Achievement: ' . bdta_achievement_row_string($assignment, 'achievement_title', 'Achievement'),
            'Client: ' . bdta_achievement_row_string($assignment, 'client_name', 'Client'),
            'Dog: ' . bdta_achievement_row_string($assignment, 'dog_name', 'their dog'),
            'Program: ' . bdta_achievement_row_string($assignment, 'program_name', 'Training Program'),
            'Awarded: ' . bdta_achievement_row_string($assignment, 'awarded_on', date('Y-m-d')),
        ];

        $description = trim(bdta_achievement_row_string($assignment, 'achievement_description'));
        if ($description !== '') {
            $lines[] = '';
            $lines[] = 'Description: ' . $description;
        }

        $notes = trim(bdta_achievement_row_string($assignment, 'notes'));
        if ($notes !== '') {
            $lines[] = '';
            $lines[] = 'Notes: ' . preg_replace('/\s+/', ' ', $notes);
        }

        $stream = "0.604 0.000 0.451 rg\n";
        $stream .= "36 742 540 14 re f\n";
        $stream .= "0.039 0.604 0.612 rg\n";
        $stream .= "36 36 540 14 re f\n";
        $stream .= "BT\n/F2 24 Tf\n72 700 Td\n(" . bdta_pdf_escape("Certificate of Achievement") . ") Tj\nET\n";
        $stream .= "BT\n/F1 16 Tf\n72 668 Td\n(" . bdta_pdf_escape("Brook's Dog Training Academy") . ") Tj\nET\n";

        $y = 620;
        foreach ($lines as $line) {
            $font = $line === '' ? 'F1' : 'F1';
            $stream .= "BT\n/{$font} 13 Tf\n72 {$y} Td\n(" . bdta_pdf_escape($line === '' ? ' ' : $line) . ") Tj\nET\n";
            $y -= ($line === '' ? 10 : 22);
        }

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream\nendobj\n";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $objects[] = "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref_position = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref_position}\n%%EOF";

        return $pdf;
    }
}

if (!function_exists('bdta_pdf_escape')) {
    function bdta_pdf_escape(string $value): string
    {
        $value = str_replace(["\r", "\n"], [' ', ' '], $value);
        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $value);
    }
}

if (!function_exists('bdta_get_client_achievement_rows')) {
    /**
     * @return list<array<string, mixed>>
     */
    function bdta_get_client_achievement_rows(PDO $conn, int $client_id, bool $include_revoked = true): array
    {
        $sql = "
            SELECT ca.*,
                   at.title AS achievement_title,
                   at.description AS achievement_description,
                   at.award_mode,
                   at.scope_type,
                   at.badge_icon_path,
                   at.certificate_template_path,
                   at.certificate_body_html,
                   COALESCE(assigner.username, CONCAT('Admin #', ca.awarded_by)) AS awarded_by_name,
                   COALESCE(updater.username, CONCAT('Admin #', ca.updated_by)) AS updated_by_name,
                   COALESCE(revoker.username, CONCAT('Admin #', ca.revoked_by)) AS revoked_by_name
            FROM client_achievements ca
            INNER JOIN achievement_types at ON at.id = ca.achievement_type_id
            LEFT JOIN admin_users assigner ON assigner.id = ca.awarded_by
            LEFT JOIN admin_users updater ON updater.id = ca.updated_by
            LEFT JOIN admin_users revoker ON revoker.id = ca.revoked_by
            WHERE ca.client_id = ?
        ";
        if (!$include_revoked) {
            $sql .= " AND ca.status = 'awarded'";
        }
        $sql .= " ORDER BY ca.awarded_on DESC, ca.id DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$client_id]);
        return assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('bdta_get_achievement_logs_grouped')) {
    /**
     * @param list<int> $assignment_ids
     * @return array<int, list<array<string, mixed>>>
     */
    function bdta_get_achievement_logs_grouped(PDO $conn, array $assignment_ids): array
    {
        if ($assignment_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
        $stmt = $conn->prepare("
            SELECT aal.*,
                   au.username AS admin_name
            FROM achievement_assignment_log aal
            LEFT JOIN admin_users au ON au.id = aal.admin_user_id
            WHERE aal.client_achievement_id IN ($placeholders)
            ORDER BY aal.created_at DESC, aal.id DESC
        ");
        $stmt->execute($assignment_ids);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignment_id = bdta_achievement_row_int($row, 'client_achievement_id');
            if (!isset($grouped[$assignment_id])) {
                $grouped[$assignment_id] = [];
            }
            $grouped[$assignment_id][] = $row;
        }

        return $grouped;
    }
}
