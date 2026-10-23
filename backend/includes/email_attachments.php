<?php
/** Private email attachment storage. Never accepts client-supplied filesystem paths. */
require_once __DIR__ . '/env_loader.php';

const BDTA_EMAIL_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;
const BDTA_EMAIL_ATTACHMENT_TOTAL_BYTES = 15 * 1024 * 1024;
const BDTA_EMAIL_ATTACHMENT_MAX_COUNT = 10;

function bdta_email_attachment_name(string $name): string {
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\p{C}]/u', '', $name) ?? '';
    $name = trim(str_replace(['"', '<', '>'], '_', $name), " .\t\r\n");
    if ($name === '') {
        return 'attachment.bin';
    }
    // Avoid invalid UTF-8 and header/log control sequences. Display name is never a path.
    if (preg_match('//u', $name) !== 1) {
        return 'attachment.bin';
    }
    // Bound work as well as bytes: trim once, then remove at most a partial UTF-8 code point.
    $name = substr($name, 0, 180);
    while (preg_match('//u', $name) !== 1) {
        $name = substr($name, 0, -1);
    }
    return $name !== '' ? $name : 'attachment.bin';
}

function bdta_email_attachment_directory(bool $create = false): string {
    $path = EnvLoader::get('EMAIL_ATTACHMENTS_DIRECTORY', dirname(__DIR__, 3) . '/bdta-private/email-attachments');
    if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR
        || str_contains($path, "\0") || str_contains($path, '\\')
        || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
        throw new RuntimeException('Email attachment storage must be an absolute private directory.');
    }
    $path = rtrim($path, '/');
    $ancestor = $path;
    $suffix = '';
    while (!file_exists($ancestor)) {
        if (is_link($ancestor) || dirname($ancestor) === $ancestor) {
            throw new RuntimeException('Invalid email attachment storage.');
        }
        $suffix = '/' . basename($ancestor) . $suffix;
        $ancestor = dirname($ancestor);
    }
    $resolved = realpath($ancestor);
    if ($resolved === false || !is_dir($resolved)) {
        throw new RuntimeException('Invalid email attachment storage.');
    }
    $path = rtrim($resolved, '/') . $suffix;
    $roots = [dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? ''];
    foreach ($roots as $root) {
        $root = is_string($root) && $root !== '' ? realpath($root) : false;
        if ($root !== false && ($path === $root || str_starts_with($path, rtrim($root, '/') . '/'))) {
            throw new RuntimeException('Email attachments must be stored outside every web document root.');
        }
    }
    // No input-derived path components: this is a server configuration value only.
    if ($create && !is_dir($path) && !mkdir($path, 0700, true)) {
        throw new RuntimeException('Unable to create private email attachment storage.');
    }
    if (is_dir($path)) {
        $actual = realpath($path);
        if ($actual !== $path) {
            throw new RuntimeException('Email attachment storage changed unexpectedly.');
        }
    }
    return $path;
}

function bdta_email_attachment_path(string $key): ?string {
    if (preg_match('/^[a-f0-9]{64}\.bin$/D', $key) !== 1) {
        throw new InvalidArgumentException('Invalid attachment storage key.');
    }
    $directory = bdta_email_attachment_directory();
    $candidate = $directory . '/' . $key;
    if (is_link($candidate)) {
        throw new RuntimeException('Attachment links are not permitted.');
    }
    $path = realpath($candidate);
    if ($path === false) {
        return null;
    }
    if (dirname($path) !== $directory || !is_file($path)) {
        throw new RuntimeException('Invalid attachment path.');
    }
    return $path;
}

/** @return array<string, mixed> */
function bdta_email_store_attachment(string $name, string $bytes): array {
    $size = strlen($bytes);
    if ($size > BDTA_EMAIL_ATTACHMENT_MAX_BYTES) {
        throw new InvalidArgumentException('Each attachment must be 10 MB or smaller.');
    }
    $directory = bdta_email_attachment_directory(true);
    $key = bin2hex(random_bytes(32)) . '.bin';
    $path = $directory . '/' . $key;
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Unable to save attachment.');
    }
    try {
        if (!chmod($path, 0600)) {
            throw new RuntimeException('Unable to secure attachment.');
        }
        $written = 0;
        while ($written < $size) {
            $count = fwrite($handle, substr($bytes, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Unable to save complete attachment.');
            }
            $written += $count;
        }
    } catch (Throwable $e) {
        fclose($handle);
        unlink($path);
        throw $e;
    }
    fclose($handle);
    return [
        'file_name' => $key, 'original_name' => bdta_email_attachment_name($name),
        'file_size' => $size, 'sha256' => hash('sha256', $bytes),
        'mime_type' => 'application/octet-stream', 'status' => 'available', 'error_message' => null,
    ];
}

/** Only cleans server-generated files that have not been committed to email history.
 * @param list<array<string, mixed>> $attachments
 */
function bdta_email_discard_staged_attachments(array $attachments): void {
    foreach ($attachments as $attachment) {
        $key = $attachment['file_name'] ?? '';
        if (!is_string($key) || $key === '') {
            continue;
        }
        $path = bdta_email_attachment_path($key);
        if ($path !== null && !unlink($path)) {
            error_log('Unable to remove a staged email attachment.');
        }
    }
}

/**
 * @param array<string, mixed> $files
 * @return list<array<string, mixed>>
 */
function bdta_email_collect_uploads(array $files, ?int $expected_count = null): array {
    $upload = $files['attachments'] ?? null;
    if ($upload === null) {
        if ($expected_count !== null && $expected_count !== 0) {
            throw new InvalidArgumentException('One or more attachments were rejected by the server upload limit. No email was sent.');
        }
        return [];
    }
    if ($expected_count === null || $expected_count < 0 || $expected_count > BDTA_EMAIL_ATTACHMENT_MAX_COUNT) {
        throw new InvalidArgumentException('Refresh the page and choose no more than 10 attachments.');
    }
    if (!is_array($upload) || !is_array($upload['name'] ?? null) || !is_array($upload['tmp_name'] ?? null)
        || !is_array($upload['error'] ?? null) || count($upload['name']) > BDTA_EMAIL_ATTACHMENT_MAX_COUNT) {
        throw new InvalidArgumentException('Choose no more than 10 attachments.');
    }
    $actual_count = count(array_filter($upload['error'], static fn($error): bool => $error !== UPLOAD_ERR_NO_FILE));
    if ($actual_count !== $expected_count) {
        throw new InvalidArgumentException('One or more attachments were rejected by the server upload limit. No email was sent.');
    }
    $attachments = [];
    $total = 0;
    try {
        foreach ($upload['name'] as $index => $name) {
            $error = $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('An attachment upload failed or exceeded the server upload limit.');
            }
            $temporary = $upload['tmp_name'][$index] ?? null;
            if (!is_string($name) || !is_string($temporary) || !is_uploaded_file($temporary)) {
                throw new InvalidArgumentException('Invalid attachment upload.');
            }
            $size = filesize($temporary);
            if ($size === false || $size > BDTA_EMAIL_ATTACHMENT_MAX_BYTES || $total + $size > BDTA_EMAIL_ATTACHMENT_TOTAL_BYTES) {
                throw new InvalidArgumentException('Attachments must be at most 10 MB each and 15 MB combined.');
            }
            $bytes = file_get_contents($temporary, false, null, 0, BDTA_EMAIL_ATTACHMENT_MAX_BYTES + 1);
            if ($bytes === false || strlen($bytes) !== $size) {
                throw new RuntimeException('Unable to read complete attachment upload.');
            }
            $attachments[] = bdta_email_store_attachment($name, $bytes);
            $total += $size;
        }
    } catch (Throwable $e) {
        bdta_email_discard_staged_attachments($attachments);
        throw $e;
    }
    return $attachments;
}

function bdta_email_attachment_kind(string $kind): string {
    if (!in_array($kind, ['client', 'unmatched'], true)) {
        throw new InvalidArgumentException('Invalid email history kind.');
    }
    return $kind;
}

/** @param list<array<string, mixed>> $attachments */
function bdta_email_attach_records(PDO $conn, string $kind, int $email_id, array $attachments): void {
    bdta_email_attachment_kind($kind);
    if ($email_id <= 0) {
        throw new InvalidArgumentException('Invalid email history ID.');
    }
    $stmt = $conn->prepare('INSERT INTO email_attachments (email_kind, email_id, file_name, original_name, file_size, sha256, mime_type, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($attachments as $attachment) {
        $stmt->execute([$kind, $email_id, $attachment['file_name'] ?? null, $attachment['original_name'],
            $attachment['file_size'], $attachment['sha256'] ?? null, $attachment['mime_type'] ?? 'application/octet-stream',
            $attachment['status'], $attachment['error_message'] ?? null]);
    }
}

/** @return list<array<string, mixed>> */
function bdta_email_attachments(PDO $conn, string $kind, int $email_id): array {
    bdta_email_attachment_kind($kind);
    $stmt = $conn->prepare('SELECT * FROM email_attachments WHERE email_kind = ? AND email_id = ? ORDER BY id');
    $stmt->execute([$kind, $email_id]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) {
            $rows[] = ['id' => $row['id'] ?? 0, 'file_name' => $row['file_name'] ?? null,
                'original_name' => $row['original_name'] ?? 'attachment.bin', 'file_size' => $row['file_size'] ?? 0,
                'sha256' => $row['sha256'] ?? null, 'mime_type' => $row['mime_type'] ?? 'application/octet-stream',
                'status' => $row['status'] ?? 'unavailable', 'error_message' => $row['error_message'] ?? null];
        }
    }
    return $rows;
}

/** API-safe metadata: never expose storage keys, server paths or raw bytes.
 * @return list<array<string, mixed>>
 */
function bdta_email_attachment_metadata(PDO $conn, string $kind, int $email_id): array {
    return array_map(static function(array $row): array {
        return ['id' => bdta_email_int($row['id']), 'name' => $row['original_name'], 'size' => bdta_email_int($row['file_size']),
            'status' => $row['status'], 'message' => $row['error_message']];
    }, bdta_email_attachments($conn, $kind, $email_id));
}

/** Fail closed: never send a message silently missing a selected attachment.
 * @param array<string, mixed> $attachment
 */
function bdta_email_verified_attachment_path(array $attachment): string {
    $key = $attachment['file_name'] ?? null;
    if (($attachment['status'] ?? '') !== 'available' || !is_string($key)) {
        throw new RuntimeException('An attachment is unavailable. No email was sent.');
    }
    $path = bdta_email_attachment_path($key);
    if ($path === null || filesize($path) !== bdta_email_int($attachment['file_size'] ?? -1)
        || !hash_equals(bdta_email_string($attachment['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
        throw new RuntimeException('An attachment is missing or changed. No email was sent.');
    }
    return $path;
}

/** @return array<string, mixed> */
function bdta_email_request_data(): array {
    if (str_starts_with(strtolower(bdta_email_string($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
        return $_POST;
    }
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/** Remove stale metadata and unreferenced private files after a grace period.
 * Dry run is the default; no filenames or message content are returned.
 * @return array{orphan_records: int, orphan_files: int, removed_files: int}
 */
function bdta_email_cleanup_attachments(PDO $conn, bool $apply = false): array {
    $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
    $orphan_condition = "created_at < ? AND ((email_kind = 'client' AND NOT EXISTS (SELECT 1 FROM client_emails ce WHERE ce.id = email_attachments.email_id)) OR (email_kind = 'unmatched' AND NOT EXISTS (SELECT 1 FROM unmatched_emails ue WHERE ue.id = email_attachments.email_id)))";
    $stmt = $conn->prepare('SELECT COUNT(*) FROM email_attachments WHERE ' . $orphan_condition);
    $stmt->execute([$cutoff]);
    $records = (int) $stmt->fetchColumn();
    if ($apply) {
        $conn->prepare('DELETE FROM email_attachments WHERE ' . $orphan_condition)->execute([$cutoff]);
    }
    $orphan_files = 0;
    $removed_files = 0;
    $directory = bdta_email_attachment_directory();
    if (!is_dir($directory)) {
        return ['orphan_records' => $records, 'orphan_files' => 0, 'removed_files' => 0];
    }
    foreach (new DirectoryIterator($directory) as $entry) {
        $key = $entry->getFilename();
        if (!$entry->isFile() || $entry->isLink() || preg_match('/^[a-f0-9]{64}\.bin$/D', $key) !== 1 || $entry->getMTime() >= time() - 86400) {
            continue;
        }
        $stmt = $conn->prepare('SELECT COUNT(*) FROM email_attachments WHERE file_name = ?');
        $stmt->execute([$key]);
        if ((int) $stmt->fetchColumn() !== 0) {
            continue;
        }
        $orphan_files++;
        if ($apply) {
            $path = bdta_email_attachment_path($key);
            if ($path !== null && unlink($path)) {
                $removed_files++;
            }
        }
    }
    return ['orphan_records' => $records, 'orphan_files' => $orphan_files, 'removed_files' => $removed_files];
}

function bdta_email_string(mixed $value): string {
    return is_string($value) ? $value : '';
}
function bdta_email_int(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
}

/** @param array<string, mixed> $data */
function bdta_email_expected_upload_count(array $data): ?int {
    if (!array_key_exists('attachment_count', $data)) {
        return null;
    }
    $count = filter_var($data['attachment_count'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => BDTA_EMAIL_ATTACHMENT_MAX_COUNT]]);
    if ($count === false) {
        throw new InvalidArgumentException('Invalid attachment count.');
    }
    return $count;
}
