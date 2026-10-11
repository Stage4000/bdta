<?php

require_once __DIR__ . '/env_loader.php';

/** Resolve private storage outside the application document root, including symlinks. */
function bdta_pet_files_base_directory(): string
{
    $path = EnvLoader::get('PET_FILES_DIRECTORY', dirname(__DIR__, 3) . '/bdta-private/pets');
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($path, '/\\'));
    if ($path === '' || str_contains($path, "\0")
        || (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1)
        || in_array('..', explode(DIRECTORY_SEPARATOR, $path), true)
        || in_array('.', explode(DIRECTORY_SEPARATOR, $path), true)) {
        throw new RuntimeException('Pet file storage must be an absolute directory outside the document root.');
    }

    // Resolve the existing ancestor before creating directories, so a symlink cannot
    // redirect even a not-yet-created private directory into the document root.
    $ancestor = $path;
    $suffix = '';
    while (!file_exists($ancestor)) {
        $parent = dirname($ancestor);
        if ($parent === $ancestor) {
            throw new RuntimeException('Unable to resolve pet file storage.');
        }
        $suffix = DIRECTORY_SEPARATOR . basename($ancestor) . $suffix;
        $ancestor = $parent;
    }
    $resolved = realpath($ancestor);
    $document_root = realpath(dirname(__DIR__, 2));
    if ($resolved === false || !is_dir($resolved) || $document_root === false) {
        throw new RuntimeException('Unable to resolve pet file storage.');
    }
    $path = rtrim($resolved, DIRECTORY_SEPARATOR) . $suffix;
    $comparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    $root_comparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($document_root) : $document_root;
    if ($comparison === $root_comparison || str_starts_with($comparison, $root_comparison . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Pet file storage must be outside the document root.');
    }
    return $path;
}

function bdta_pet_files_directory(int $pet_id, bool $create = false): string
{
    if ($pet_id <= 0) {
        throw new InvalidArgumentException('Invalid pet ID.');
    }
    $base = bdta_pet_files_base_directory();
    $directory = $base . DIRECTORY_SEPARATOR . $pet_id;
    // Fixed private base and validated integer ID; no submitted path components.
    // nosemgrep: php.lang.security.mkdir-use.mkdir-use
    if ($create && !is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Unable to create pet file storage.');
    }
    if (file_exists($directory) || is_link($directory)) {
        $resolved_base = realpath($base);
        $resolved_directory = realpath($directory);
        if ($resolved_base === false || $resolved_directory === false || !is_dir($resolved_directory)
            || $resolved_directory !== $resolved_base . DIRECTORY_SEPARATOR . $pet_id) {
            throw new RuntimeException('Invalid pet file storage directory.');
        }
        return $resolved_directory;
    }
    return $directory;
}

/** Return only existing regular files contained in this pet's private directory. */
function bdta_pet_file_path(int $pet_id, string $filename): ?string
{
    if ($filename === '' || preg_match('/^[A-Za-z0-9._-]+$/D', $filename) !== 1 || str_contains($filename, '..')) {
        throw new InvalidArgumentException('Invalid pet file name.');
    }
    $directory = bdta_pet_files_directory($pet_id);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($path === false) {
        return null;
    }
    if (dirname($path) !== $directory || !is_file($path)) {
        throw new RuntimeException('Invalid pet file path.');
    }
    return $path;
}
