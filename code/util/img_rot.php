<?php
/*
 * Thin wrapper that hands off rotation to img_rot.py.
 * Python preserves EXIF, ICC profile, and mtime — see img_rot.py.
 *
 * Returns plain text "ok" or "error: ...".
 * The caller in manage.php only checks status code, not body.
 */

ini_set('display_errors', '0');
header('Content-Type: text/plain');

include_once __DIR__ . '/../var.php';

$img_path = $_GET['path'] ?? '';
if ($img_path === '' || strpos($img_path, '..') !== false) {
    http_response_code(400);
    echo "error: bad path"; exit;
}

$sourcePath = $_SERVER['DOCUMENT_ROOT'] . $img_path;
if (!is_file($sourcePath)) {
    http_response_code(404);
    echo "error: not found"; exit;
}

$script = __DIR__ . '/img_rot.py';
// No root needed: the script writes a new file beside the photo, gives it the
// photo's dates and swaps it in, so it works whoever owns the photo.
$cmd    = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($sourcePath) . ' 2>&1';

$output = [];
$rc     = 0;
exec($cmd, $output, $rc);

if ($rc !== 0) {
    http_response_code(500);
    echo "error: rotation failed (rc=$rc): " . implode(' | ', $output);
    exit;
}

// Bump change_status so live viewers refresh within 5s. get_images.php
// now returns a per-file `v` (filectime); since rotation rewrites the file,
// only the rotated image's URL changes — other images stay cached. Gated:
// silent if the file isn't part of the play set.
bump_change_status_if_affects_($sourcePath);
audit('image.rotate', $img_path);

echo "ok";
?>
