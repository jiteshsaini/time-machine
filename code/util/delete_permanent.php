<?php
/*
 * Permanently delete files from trash/. The path-safety guard refuses any
 * path that doesn't resolve inside trash_root() — so this endpoint can
 * never delete from images/ even if someone passes a bogus path.
 *
 * POST: paths[]  — absolute paths inside trash_root(), OR
 *       empty=1  — wipe everything under trash_root()
 *       token    — CSRF token
 *
 * Returns: { success, deleted, errors }
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

$trashRoot = trash_root();
$deleted = 0; $errors = 0;

if (!empty($_POST['empty'])) {
    // Empty the entire trash. Use RecursiveIteratorIterator + CHILD_FIRST so
    // we delete files before their parent directories.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($trashRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (safe_under($p, $trashRoot) === false) { $errors++; continue; }
        if ($f->isDir()) {
            if (@rmdir($p)) $deleted++; else $errors++;
        } else {
            if (@unlink($p)) $deleted++; else $errors++;
        }
    }
    audit('fs.purge', "emptied trash ($deleted entries removed)");
    echo json_encode(['success' => $errors === 0, 'deleted' => $deleted, 'errors' => $errors]);
    exit;
}

$paths = $_POST['paths'] ?? [];
if (!is_array($paths) || empty($paths)) {
    echo json_encode(['success' => false, 'error' => 'No paths supplied']); exit;
}

// Recursive delete — used when the path is a directory (folders trashed
// via send_to_trash.php arrive intact and need rmdir+contents to purge).
function _purge_rec($p) {
    if (is_file($p) || is_link($p)) return @unlink($p);
    if (!is_dir($p)) return false;
    $ok = true;
    foreach (@scandir($p) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!_purge_rec($p . '/' . $f)) $ok = false;
    }
    return @rmdir($p) && $ok;
}

foreach ($paths as $abs) {
    if (!is_string($abs)) { $errors++; continue; }
    $real = safe_under($abs, $trashRoot);
    if ($real === false || (!is_file($real) && !is_dir($real))) { $errors++; continue; }
    if (_purge_rec($real)) {
        audit('fs.purge', $real);
        $deleted++;
    } else {
        $errors++;
    }
}

echo json_encode([
    'success' => $errors === 0,
    'deleted' => $deleted,
    'errors'  => $errors,
]);
