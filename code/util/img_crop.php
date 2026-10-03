<?php
/*
 * Thin wrapper that hands off cropping to img_crop.py.
 * Python preserves EXIF, ICC profile, and mtime — see img_crop.py.
 *
 * POST: path  — absolute filesystem path (validated to be inside images/)
 *       x, y, width, height — crop rect in image pixel space
 *       token — CSRF token (session-shared with manage.php)
 *
 * Returns plain text "ok" or "error: ...".
 */

ini_set('display_errors', '0');
header('Content-Type: text/plain');

include_once __DIR__ . '/../var.php';

define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();

if (!isset($_SESSION['token']) || !hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
    http_response_code(403);
    echo "error: invalid token"; exit;
}

$path = $_POST['path'] ?? '';
$real = safe_under($path, images_root());
if ($real === false || !is_file($real)) {
    http_response_code(400);
    echo "error: invalid path"; exit;
}

foreach (['x', 'y', 'width', 'height'] as $k) {
    if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) {
        http_response_code(400);
        echo "error: missing $k"; exit;
    }
}
$x = (int) $_POST['x'];
$y = (int) $_POST['y'];
$w = (int) $_POST['width'];
$h = (int) $_POST['height'];

$script = __DIR__ . '/img_crop.py';
// Run as root so the original mtime can be put back — see img_rot.php.
$cmd = 'sudo python3 ' . escapeshellarg($script) . ' '
     . escapeshellarg($real) . ' '
     . escapeshellarg($x) . ' ' . escapeshellarg($y) . ' '
     . escapeshellarg($w) . ' ' . escapeshellarg($h) . ' 2>&1';

$output = [];
$rc = 0;
exec($cmd, $output, $rc);

if ($rc !== 0) {
    http_response_code(500);
    echo "error: crop failed (rc=$rc): " . implode(' | ', $output);
    exit;
}

// Bump change_status so live slideshow viewers refresh — file's ctime has
// changed, so per-image `v` in get_images.php picks up only this file.
// Gated: silent if the file isn't part of the play set.
bump_change_status_if_affects_($real);
audit('image.crop', "$real [$x,$y,$w,$h]");

echo "ok";
?>
