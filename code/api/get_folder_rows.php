<?php
/*
 * api/get_folder_rows.php — lazy subfolder rows for the (retired) list-view
 * inline tree expansion. Kept for completeness; not called by the current
 * front-end (manage.php) since the sidebar tree drawer replaces inline
 * caret expansion. Safe to delete in a future cleanup if nothing else
 * references it.
 *
 * GET params:
 *   p     — folder rel-path under DOCUMENT_ROOT (e.g. "time_machine/images/foo")
 *   depth — depth value to stamp on the rendered rows
 *
 * Response: text/html — concatenated <tr>…</tr> rows.
 */

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');

include_once __DIR__ . '/../var.php';
include_once __DIR__ . '/../manage_util.php';

// ─── Session — shared with manage.php so the token / existingPaths read works.
define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();

// FM_DATETIME_FORMAT is normally defined in manage.php; mirror it here so the
// helper's date() call produces matching output.
if (!defined('FM_DATETIME_FORMAT')) {
    define('FM_DATETIME_FORMAT', isset($datetime_format) ? $datetime_format : 'd M Y H:i');
}
if (!defined('FM_ROOT_PATH')) {
    define('FM_ROOT_PATH', rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/'));
}

$rel   = isset($_GET['p']) ? trim($_GET['p'], '/') : '';
$depth = isset($_GET['depth']) ? max(1, (int)$_GET['depth']) : 1;

if ($rel === '') { http_response_code(400); echo ''; exit; }

// Resolve and sandbox under images_root().
$absParent = realpath(FM_ROOT_PATH . '/' . $rel);
$imgRoot   = realpath(images_root());
if ($absParent === false || $imgRoot === false ||
    strpos($absParent . '/', $imgRoot . '/') !== 0) {
    http_response_code(400); echo ''; exit;
}

// Read the slideshow paths so the toggle in each row reflects current state.
$existingPaths = file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$existingPaths = array_map(fn($p) => rtrim($p, '/'), $existingPaths);

// In trash view manage.php hides the toggle; the AJAX endpoint never serves
// trash rows (tree expansion only happens in the normal list view), so
// $inTrash is hard-set to false here.
$inTrash = false;

$entries = @scandir($absParent) ?: [];
natcasesort($entries);

$out = '';
$ii  = 4400 + ($depth * 100);
foreach ($entries as $f) {
    if ($f === '.' || $f === '..' || $f[0] === '.') continue;
    if (!is_dir($absParent . '/' . $f)) continue;
    $out .= render_folder_row($f, $rel, $absParent, $depth, $existingPaths, $inTrash, $ii++);
}

echo $out;
