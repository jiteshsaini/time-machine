<?php
// Synchronous worker: runs the python resizer to completion for one batch
// (cap of N files) and returns. The UI driver (img_resize.php) polls the
// progress JSON during the run and re-invokes us for the next batch if
// the python side reports remaining > 0.

include_once __DIR__ . '/../var.php';

@set_time_limit(0);          // batch may run for a few minutes on slow disks
@ignore_user_abort(true);    // don't kill mid-batch if the user navigates away

$defaultBatch = defined('RESIZE_BATCH_SIZE') ? RESIZE_BATCH_SIZE : 500;
$path  = $_GET['p']     ?? '';
$batch = (int)($_GET['batch'] ?? $defaultBatch);
if ($batch < 1) $batch = $defaultBatch;

if ($path === '') {
    echo 'No path provided.';
    exit;
}

// The path comes from the request, so check it: only folders inside this
// app's images/ are resized.
$real = realpath($path);
$root = realpath(images_root());
if ($real === false || $root === false || !is_dir($real)
        || ($real !== $root && safe_under($real, $root) === false)) {
    http_response_code(400);
    echo 'Not a folder inside images.';
    exit;
}

$cmd = 'python3 img_resize.py '
     . escapeshellarg($path)
     . ' --batch ' . (int)$batch
     . ' 2>&1';
$out = [];
$rc  = 0;
exec($cmd, $out, $rc);

// Resize rewrites image files in place — content + ctime change, so any
// open slideshow viewer holding cache-busted URLs for these images would
// keep showing the old pixels until the per-file `v` (filectime) bumps
// trigger a re-fetch. A change_status bump speeds that up. Gated so an
// out-of-slideshow folder doesn't ping the TV. Each batch bumps once;
// the 5s viewer poll coalesces successive batches.
if ($rc === 0) bump_change_status_if_affects_($path);

echo "<h3>✅ Batch complete</h3>";
