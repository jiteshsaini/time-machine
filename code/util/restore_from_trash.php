<?php
/*
 * Move files from trash/<rel> back to images/<rel>. If a file with the same
 * name already exists at the original location, append a timestamp.
 *
 * POST: paths[] — absolute paths inside trash_root()
 *       token   — CSRF token
 *
 * Returns: { success, restored, errors, details: [...] }
 */

ini_set('display_errors', '0');
header('Content-Type: application/json');

include_once __DIR__ . '/../var.php';

define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();

if (!isset($_SESSION['token']) || !hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']); exit;
}

$paths = $_POST['paths'] ?? [];
if (!is_array($paths) || empty($paths)) {
    echo json_encode(['success' => false, 'error' => 'No paths supplied']); exit;
}

$imgRoot   = images_root();
$trashRoot = trash_root();
$restored = 0; $errors = 0; $details = [];
$anyAffected = false;

foreach ($paths as $abs) {
    if (!is_string($abs)) { $errors++; continue; }
    $real = safe_under($abs, $trashRoot);
    // Accept files AND folders, matching send_to_trash.php — both round-trip
    // cleanly via PHP's rename() on the same filesystem.
    if ($real === false || (!is_file($real) && !is_dir($real))) {
        $errors++; $details[] = ['path' => $abs, 'error' => 'invalid or missing']; continue;
    }
    $rel  = substr($real, strlen($trashRoot) + 1);
    $dest = $imgRoot . '/' . $rel;
    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
        $old = umask(0); @mkdir($destDir, 0775, true); umask($old);
    }
    $dest = unique_target_path($dest);
    if (@rename($real, $dest)) {
        audit('fs.restore', $real . ' -> ' . $dest);
        $restored++;
        // Gate by destination — the file is now under images/, so we check
        // whether its restored location falls inside any slideshow folder.
        if (path_affects_slideshow_($dest)) $anyAffected = true;
        $details[] = ['path' => $abs, 'restored_to' => $dest];
    } else {
        $errors++;
        $details[] = ['path' => $abs, 'error' => 'rename failed'];
    }
}

if ($anyAffected) {
    @file_put_contents(state_file('change_status.txt'), '1');
}

echo json_encode([
    'success'  => $errors === 0,
    'restored' => $restored,
    'errors'   => $errors,
    'details'  => $details,
]);
