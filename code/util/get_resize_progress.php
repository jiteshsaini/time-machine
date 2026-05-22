<?php
/*
 * Returns the live resize progress JSON written by img_resize.py.
 * Called by img_resize.php's polling loop.
 *
 * Query: ?folder=<absolute path that was passed to the worker>
 */

ini_set('display_errors', '0');
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$folder = $_GET['folder'] ?? '';
$root   = $_SERVER['DOCUMENT_ROOT'];

// Restrict to inside DOCUMENT_ROOT for safety.
$folder = realpath($folder) ?: '';
if ($folder === '' || strpos($folder, $root) !== 0) {
    echo json_encode(['error' => 'invalid folder']);
    exit;
}

$file = $folder . '/.resize_progress.json';
if (!is_file($file)) {
    echo json_encode([
        'phase'    => 'pending',
        'total'    => 0,
        'done'     => 0,
        'current'  => '',
        'finished' => false,
    ]);
    exit;
}

$raw  = @file_get_contents($file);
$data = $raw ? json_decode($raw, true) : null;
if (!is_array($data)) {
    echo json_encode([
        'phase'    => 'pending',
        'total'    => 0,
        'done'     => 0,
        'current'  => '',
        'finished' => false,
    ]);
    exit;
}

echo json_encode($data);
?>
