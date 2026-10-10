#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/pet_files.php';

function pet_path_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pet_path_rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $e) {
        return;
    }
    throw new RuntimeException($message);
}

$original = getenv('PET_FILES_DIRECTORY');
$temporary = sys_get_temp_dir() . '/bdta-pet-paths-' . bin2hex(random_bytes(12));
mkdir($temporary, 0700);
try {
    putenv('PET_FILES_DIRECTORY');
    pet_path_check(bdta_pet_files_base_directory() === dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bdta-private' . DIRECTORY_SEPARATOR . 'pets', 'Default storage must be beside the document root.');
    foreach ([dirname(__DIR__), dirname(__DIR__) . '/backend/uploads/pets', $temporary . '/../escape', 'relative/pets'] as $invalid) {
        putenv('PET_FILES_DIRECTORY=' . $invalid);
        pet_path_rejected(fn() => bdta_pet_files_base_directory(), 'Unsafe storage root accepted: ' . $invalid);
    }
    putenv('PET_FILES_DIRECTORY=' . $temporary . '/private/pets');
    pet_path_rejected(fn() => bdta_pet_files_directory(0, true), 'Invalid pet ID accepted.');
    $directory = bdta_pet_files_directory(1, true);
    pet_path_check(is_dir($directory), 'Private pet directory not created.');
    $fixture = $directory . '/pet_1_synthetic.png';
    file_put_contents($fixture, 'synthetic fixture bytes');
    pet_path_check(bdta_pet_file_path(1, 'pet_1_synthetic.png') === realpath($fixture), 'Existing private file not resolved.');
    pet_path_check(bdta_pet_file_path(1, 'missing.png') === null, 'Missing file should return null.');
    foreach (['../outside.png', '..\\outside.png', 'C:outside.png', 'a/../b.png', '.hidden..png', "file\0.png"] as $name) {
        pet_path_rejected(fn() => bdta_pet_file_path(1, $name), 'Unsafe stored filename accepted.');
    }
    $outside = $temporary . '/outside.png';
    file_put_contents($outside, 'outside bytes');
    if (@symlink($outside, $directory . '/linked.png')) {
        pet_path_rejected(fn() => bdta_pet_file_path(1, 'linked.png'), 'File symlink escaped the pet directory.');
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated link in the private random test directory
        unlink($directory . '/linked.png');
    } else {
        echo "SKIP: file symlink creation unavailable on this platform.\n";
    }
    if (@symlink($temporary, $temporary . '/private/pets/2')) {
        pet_path_rejected(fn() => bdta_pet_files_directory(2, true), 'Pet directory symlink escaped storage.');
        if (DIRECTORY_SEPARATOR === '\\') {
            rmdir($temporary . '/private/pets/2');
        } else {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated link in the private random test directory
            unlink($temporary . '/private/pets/2');
        }
    } else {
        echo "SKIP: directory symlink creation unavailable on this platform.\n";
    }
    if (@symlink(dirname(__DIR__), $temporary . '/public-link')) {
        putenv('PET_FILES_DIRECTORY=' . $temporary . '/public-link/not-created');
        pet_path_rejected(fn() => bdta_pet_files_directory(1, true), 'Ancestor symlink redirected storage into document root.');
        pet_path_check(!is_dir(dirname(__DIR__) . '/not-created'), 'Invalid configuration wrote inside the document root.');
        if (DIRECTORY_SEPARATOR === '\\') {
            rmdir($temporary . '/public-link');
        } else {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated link in the private random test directory
            unlink($temporary . '/public-link');
        }
    } else {
        echo "SKIP: ancestor symlink creation unavailable on this platform.\n";
    }
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated fixture in the private random test directory
    unlink($fixture);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated fixture in the private random test directory
    unlink($outside);
    rmdir($directory);
    rmdir($temporary . '/private/pets');
    rmdir($temporary . '/private');
    echo "Pet-file path regression passed.\n";
} finally {
    putenv($original === false ? 'PET_FILES_DIRECTORY' : 'PET_FILES_DIRECTORY=' . $original);
    rmdir($temporary);
}
