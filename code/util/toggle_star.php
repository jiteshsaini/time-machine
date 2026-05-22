<?php
/*
 * Toggle "star" state on a single image file by appending or removing the
 * "~star" marker before the extension (e.g. photo.jpg ↔ photo~star.jpg).
 * The marker survives moves / restore because it's part of the filename;
 * no external index to keep in sync.
 *
 * POST: path  — absolute filesystem path inside images_root()
 *       token — CSRF token
 *
 * Returns: { success, starred, oldName, newName, newPath }
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

if (is_starred_name($name)) {
    $newName = remove_star_from_name($name);
    $starred = false;
} else {
    $newName = add_star_to_name($name);
    $starred = true;
}

if ($newName === $name) {
    echo json_encode(['success' => false, 'error' => 'no-op']); exit;
}

$dest = $dir . '/' . $newName;
if (file_exists($dest)) {
    echo json_encode(['success' => false, 'error' => 'a file with that name already exists']); exit;
}

// Preserve mtime — rename usually doesn't change it, but be defensive so the
// per-image cache-busting in get_images.php / grid view doesn't re-fetch.
$st = @stat($real);
if (!@rename($real, $dest)) {
    echo json_encode(['success' => false, 'error' => 'rename failed']); exit;
}
if ($st) @touch($dest, $st['mtime'], $st['atime']);

audit($starred ? 'image.star' : 'image.unstar', $real . ' -> ' . $dest);

// Keep starred_paths.txt consistent with the file's star state.
// - Unstar: drop the OLD path if it was explicitly in the slideshow set.
// - Star (going from foo.jpg → foo~star.jpg): if the OLD name was somehow in
//   the file (shouldn't be — only starred files are valid entries), drop it.
$starFile = state_file('starred_paths.txt');
if (file_exists($starFile)) {
    $lines = file($starFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $clean = array_values(array_filter($lines, fn($p) => rtrim($p, '/') !== $real));
    if (count($clean) !== count($lines)) {
        $out = implode(PHP_EOL, $clean);
        if ($out !== '') $out .= PHP_EOL;
        @file_put_contents($starFile, $out, LOCK_EX);
    }
}

// Slideshow doesn't filter by star, but the file's URL changed — bump status
// so any open viewer picks up the new name on the next 5s tick. Gated:
// only signal when the file is actually part of the play set.
bump_change_status_if_affects_($real, $dest);

echo json_encode([
    'success' => true,
    'starred' => $starred,
    'oldName' => $name,
    'newName' => $newName,
    'newPath' => $dest,
]);
