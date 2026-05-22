<?php
/*
 * util/manage_starred_paths.php — add or remove absolute file paths in
 * txt/starred_paths.txt. This is the file-set companion to paths.txt: the
 * slideshow serves the union of (folders in paths.txt) + (files listed here).
 *
 * Keeping it a separate file means paths.txt's folder-only semantics stay
 * intact — every existing walker / dedup-stripper / pill-renderer keeps
 * working without an audit.
 *
 * POST: action  — 'add' | 'remove'
 *       paths[] — absolute filesystem paths inside images_root()
 *       token   — CSRF token
 *
 * Returns: { success, action, applied, total, error? }
 *   applied = count of paths actually added or removed (dedup-aware)
 *   total   = number of entries in starred_paths.txt after the operation
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

$action = $_POST['action'] ?? '';
$paths  = $_POST['paths']  ?? [];
if (!in_array($action, ['add', 'remove'], true)) {
    echo json_encode(['success' => false, 'error' => 'invalid action']); exit;
}
if (!is_array($paths)) $paths = [];

$file = state_file('starred_paths.txt');
$existing = file_exists($file)
    ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$existing = array_map(fn($p) => rtrim($p, '/'), $existing);
$set      = array_flip($existing);
$applied  = 0;

if ($action === 'add') {
    foreach ($paths as $p) {
        if (!is_string($p)) continue;
        $real = safe_under($p, images_root());
        if ($real === false || !is_file($real)) continue;
        // Only files that are actually starred — keeps starred_paths.txt
        // semantically valid (an entry implies the file is currently a favorite).
        if (!is_starred_name(basename($real))) continue;
        if (isset($set[$real])) continue;
        $set[$real] = true;
        $applied++;
    }
} else { // remove
    foreach ($paths as $p) {
        if (!is_string($p)) continue;
        $p = rtrim($p, '/');
        if (!isset($set[$p])) continue;
        unset($set[$p]);
        $applied++;
    }
}

if ($applied > 0) {
    $out = implode(PHP_EOL, array_keys($set));
    if ($out !== '') $out .= PHP_EOL;
    @file_put_contents($file, $out, LOCK_EX);
    @file_put_contents(state_file('change_status.txt'), '1');
    audit('starred_paths.' . $action, $applied . ' applied');
}

echo json_encode([
    'success' => true,
    'action'  => $action,
    'applied' => $applied,
    'total'   => count($set),
]);
