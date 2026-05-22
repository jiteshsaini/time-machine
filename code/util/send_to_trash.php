<?php
/*
 * Move one or more image files OR folders from images/<rel> → trash/<rel>,
 * preserving the relative folder structure so restore is a simple move-back.
 *
 * POST: paths[] — absolute filesystem paths inside images_root() (files or dirs)
 *       token   — CSRF token shared with manage.php
 *
 * Returns: { success, moved, errors, details: [...] }
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
$moved = 0; $errors = 0; $details = [];
$anyAffected = false;

foreach ($paths as $abs) {
    if (!is_string($abs)) { $errors++; continue; }
    $real = safe_under($abs, $imgRoot);
    // Accept files AND directories — PHP's rename() handles both on the
    // same filesystem, and a folder dropped into trash/<rel> mirrors the
    // file case for restore.
    if ($real === false || (!is_file($real) && !is_dir($real))) {
        $errors++; $details[] = ['path' => $abs, 'error' => 'invalid or missing']; continue;
    }
    $rel  = substr($real, strlen($imgRoot) + 1);          // path inside images/
    $dest = $trashRoot . '/' . $rel;
    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
        $old = umask(0); @mkdir($destDir, 0775, true); umask($old);
    }
    $dest = unique_target_path($dest);                    // handle name collision in trash
    // Remember whether this path was part of the slideshow BEFORE we move
    // it — after the rename the path won't match, and we want the bump.
    $wasAffected = path_affects_slideshow_($real);
    $kind = is_dir($real) ? 'folder' : 'file';
    if (@rename($real, $dest)) {
        audit('fs.trash', $kind . ': ' . $real . ' -> ' . $dest);
        $moved++;
        if ($wasAffected) $anyAffected = true;
        $details[] = ['path' => $abs, 'trashed_to' => $dest];
    } else {
        $errors++;
        $details[] = ['path' => $abs, 'error' => 'rename failed'];
    }
}

if ($anyAffected) {
    @file_put_contents(state_file('change_status.txt'), '1');
}

echo json_encode([
    'success' => $errors === 0,
    'moved'   => $moved,
    'errors'  => $errors,
    'details' => $details,
]);
