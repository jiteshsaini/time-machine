<?php
/*
 * Toggle "skip" state on a single image file by renaming it with a leading
 * underscore. Files starting with "_" are skipped by:
 *   - the slideshow scan (get_images.php)
 *   - the management UI default (manage.php), unless ?show_skipped=1
 *
 * POST: path  — absolute filesystem path inside images_root()
 *       token — CSRF token
 *
 * Returns: { success, skipped, oldName, newName }
 */

ini_set('display_errors', '0');
header('Content-Type: application/json');

include_once __DIR__ . '/../var.php';

define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();

if (!isset($_SESSION['token']) || !hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'invalid token']); exit;
}

$path = $_POST['path'] ?? '';
$real = safe_under($path, images_root());
if ($real === false || !is_file($real)) {
    echo json_encode(['success' => false, 'error' => 'invalid path']); exit;
}

$dir   = dirname($real);
$name  = basename($real);
$first = substr($name, 0, 1);

if ($first === '_') {
    $newName = ltrim($name, '_');
    if ($newName === '') { echo json_encode(['success' => false, 'error' => 'bad name']); exit; }
    $skipped = false;
} else {
    $newName = '_' . $name;
    $skipped = true;
}

$dest = $dir . '/' . $newName;
if (file_exists($dest)) {
    echo json_encode(['success' => false, 'error' => 'a file with that name already exists']); exit;
}

// Preserve mtime — rename doesn't change it on Linux, but be defensive.
$st = @stat($real);
if (!@rename($real, $dest)) {
    echo json_encode(['success' => false, 'error' => 'rename failed']); exit;
}
if ($st) @touch($dest, $st['mtime'], $st['atime']);

audit($skipped ? 'image.skip' : 'image.unskip', $real . ' -> ' . $dest);
bump_change_status_if_affects_($real, $dest);

echo json_encode([
    'success' => true,
    'skipped' => $skipped,
    'oldName' => $name,
    'newName' => $newName,
]);
