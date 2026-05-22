<?php
/*
 * manage.php — front-end management UI.
 *
 * The single page users open to browse, organize, and curate their photo
 * library. Default layout is a grid of thumbnails; an in-page Grid↔List
 * toggle (in the ctrl-row) swaps to a tabular list view of the same data
 * without a page reload. Both layouts share the same selection set, drag-
 * drop machinery, sidebar tree, and action strip.
 *
 * No destructive logic lives here — every mutating operation (toggle,
 * rename, delete, copy, move, upload, playlist) AJAX-posts to
 * manage_ops.php which owns the actual filesystem work.
 *
 * Companion files:
 *   manage_ops.php   — backend endpoints (this page's POST/AJAX target)
 *   manage_util.php  — shared rendering helpers (header, status bar, modals,
 *                      sidebar chrome, slideshow toggle JS)
 *   var.php          — config + low-level helpers (paths, state files, …)
 */

ini_set('display_errors', '1');
include_once "var.php";
include_once "manage_util.php";

// ─── Session & CSRF (mirrors manage_ops.php so the token is shared) ──────────
define('FM_SESSION_ID', 'filemanager');
session_cache_limiter('nocache');
session_name(FM_SESSION_ID);
session_start();

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(32))
        : bin2hex(openssl_random_pseudo_bytes(32));
}

global $appName;
$root_path = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');

// ─── Local handlers: delay save + new folder ─────────────────────────────────
// These mirror manage.php's handlers so the Settings/New-Folder modals in the
// grid view work without bouncing the user to the list view on submit.
if (isset($_POST['save_delay'], $_POST['delay'], $_POST['token'])
    && hash_equals($_SESSION['token'], $_POST['token'])) {
    $newDelay = (int) $_POST['delay'];
    if ($newDelay > 0) {
        file_put_contents(state_file('delay.txt'), $newDelay);
        file_put_contents(state_file('change_status.txt'), '1');
        audit('settings.delay', (string) $newDelay);
    }
    header("Location: " . $_SERVER['REQUEST_URI']); exit;
}
// AJAX-only folder add/remove (called by the Slideshow modal's pill × button).
// Mirrors manage.php's logic for the ajax=1 path. URL-driven (GET).
if (isset($_GET['folder_path'], $_GET['folder_path_action']) && !empty($_GET['ajax'])) {
    $folderPath = rtrim($_GET['folder_path'], '/');
    $paths = file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if ($_GET['folder_path_action'] === 'remove') {
        $paths = array_values(array_filter($paths, fn($x) => rtrim(trim($x), '/') !== $folderPath));
        file_put_contents(state_file('paths.txt'), implode(PHP_EOL, $paths) . PHP_EOL, LOCK_EX);
        file_put_contents(state_file('change_status.txt'), '1');
        audit('folder.remove', $folderPath);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]); exit;
}

// Clear-all selected folders from Slideshow modal.
if (isset($_GET['clear_file']) && $_GET['clear_file'] == 1) {
    file_put_contents(state_file('paths.txt'), '');
    file_put_contents(state_file('change_status.txt'), '1');
    // Drop any playlist association — wiping the selection breaks the link.
    @file_put_contents(state_file('current_playlist.txt'), '');
    audit('folder.clear_all', '');
    $rp = $_GET; unset($rp['clear_file']);
    header("Location: manage.php" . (!empty($rp) ? '?' . http_build_query($rp) : '')); exit;
}

if (isset($_POST['newfilename'], $_POST['newfile'], $_POST['token'])
    && hash_equals($_SESSION['token'], $_POST['token'])) {
    $new = trim(strip_tags($_POST['newfilename']));
    $new = str_replace('/', '', $new);
    if ($new !== '' && strpbrk($new, '/?%*:|"<>') === false) {
        $folderAbs = $root_path . '/' . ltrim(($_GET['p'] ?? ''), '/');
        if (is_dir($folderAbs) && !file_exists($folderAbs . '/' . $new)) {
            $old = umask(0); @mkdir($folderAbs . '/' . $new, 0777, true); umask($old);
            audit('fs.mkdir', $folderAbs . '/' . $new);
        }
    }
    header("Location: " . $_SERVER['REQUEST_URI']); exit;
}

// ─── Path & query params ─────────────────────────────────────────────────────
$p_raw = isset($_GET['p']) ? $_GET['p'] : '';
$p     = trim(str_replace(['../','..\\'], '', $p_raw), '/');
if ($p === '' || !preg_match("#^$appName/(images|trash)(/.*)?$#", $p)) {
    $p = "$appName/images";
}
// Trash is its own dedicated view at pages/trash.php now. The grid view
// doesn't render a special "trash mode" — $inTrash stays false here.
$inTrash = false;
trash_root(); // ensure trash dir exists if we're about to browse it

// Recursive count of image files under a directory — local to this page.
function countDirImages_($dir) {
    static $exts = ['jpg','jpeg','png','gif','bmp','webp','avif'];
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full)) { $n += countDirImages_($full); continue; }
        if (is_file($full) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) $n++;
    }
    return $n;
}
$absPath = $root_path . '/' . $p;
if (!is_dir($absPath)) {
    header("Location: manage.php?p=" . urlencode("$appName/images")); exit;
}

// ─── Left sidebar: recursive folder tree of images/ ─────────────────────────
// Plain server-side render on every page load. Each row carries data-path in
// the same "appName/images/foo/bar" format that the top chips use, so the
// existing drag-drop handler picks them up as drop targets automatically.
function render_folder_tree_html_($absRoot, $pathPrefix, $currentPath, $existingPaths) {
    $entries = @scandir($absRoot);
    if (!$entries) return '';
    $folders = [];
    foreach ($entries as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.') continue;
        if (is_dir($absRoot . '/' . $f)) $folders[] = $f;
    }
    if (!$folders) return '';
    sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    $out = '<ul class="ft-list">';
    foreach ($folders as $name) {
        $full     = $absRoot . '/' . $name;
        $fullPath = $pathPrefix . '/' . $name;
        $skipped  = ($name[0] === '_');
        // Detect grandchildren without a full recurse — just one cheap scan.
        $hasKids = false;
        foreach (@scandir($full) ?: [] as $sub) {
            if ($sub === '.' || $sub === '..' || $sub[0] === '.') continue;
            if (is_dir($full . '/' . $sub)) { $hasKids = true; break; }
        }
        $isCurrent  = ($fullPath === $currentPath);
        $isAncestor = (strpos($currentPath . '/', $fullPath . '/') === 0);

        // Same three-state slideshow logic the per-row toggle uses:
        // IN_LIST (direct), VIA (covered by an ancestor in paths.txt), OUT.
        $absTrim   = rtrim($full, '/');
        $inList    = in_array($absTrim, $existingPaths, true);
        $viaParent = null;
        $descInListCount = 0;
        if (!$inList && !$skipped) {
            foreach ($existingPaths as $ep) {
                $ep = rtrim($ep, '/');
                if ($ep === '') continue;
                if ($viaParent === null && strpos($absTrim . '/', $ep . '/') === 0) { $viaParent = $ep; }
                if (strpos($ep . '/', $absTrim . '/') === 0 && $ep !== $absTrim) { $descInListCount++; }
            }
        }

        $liCls = 'ft-node ft-collapsed';
        if ($isAncestor) $liCls = 'ft-node';                // auto-expand the chain
        $rowCls = 'ft-row drop-target';
        if ($skipped)   $rowCls .= ' skipped';
        if ($isCurrent) $rowCls .= ' ft-current';
        $out .= '<li class="' . $liCls . '" data-path="' . htmlspecialchars($fullPath) . '">';
        $out .= '<div class="' . $rowCls . '"'
              . ' data-path="' . htmlspecialchars($fullPath) . '"'
              . ' data-label="' . htmlspecialchars($name) . '">';
        if ($hasKids) {
            $out .= '<button type="button" class="ft-chev" tabindex="-1" aria-label="Toggle children">'
                  . '<i class="fa fa-caret-right"></i></button>';
        } else {
            $out .= '<span class="ft-chev ft-chev-blank"></span>';
        }
        // Trim long names so the right-aligned toggle isn't pushed off-screen.
        // Full name remains in the title attribute and tooltip. Uses plain
        // strlen/substr since mbstring may not be installed.
        $displayName = strlen($name) > 20 ? substr($name, 0, 19) . '…' : $name;
        $out .= '<a class="ft-name" href="?p=' . urlencode($fullPath) . '" title="' . htmlspecialchars($name) . '">';
        $out .= '<i class="fa fa-folder-o"></i><span class="ft-name-text">' . htmlspecialchars($displayName) . '</span>';
        if ($skipped) $out .= ' <span class="ft-skip-tag">SKIP</span>';
        $out .= '</a>';

        // "Something deeper is in the slideshow" dot — only when the folder
        // itself isn't directly in the list and isn't covered via an
        // ancestor (those cases already convey "plays").
        if ($descInListCount > 0 && !$inList && $viaParent === null) {
            $out .= ' <span class="in-show-hint ft-desc-hint" title="'
                  . $descInListCount . ' descendant folder'
                  . ($descInListCount === 1 ? '' : 's')
                  . ' included in the slideshow">&bull;</span>';
        }

        // Hover-revealed Rename icon (right side, before the toggle). Delete
        // intentionally lives only in the list/grid row actions and the
        // in-folder action strip — keeping a permanent-delete this close to
        // the slideshow toggle made for too-easy mis-clicks.
        $parentForOps = dirname($fullPath);                       // appName/images[/foo]
        $out .= '<span class="ft-row-actions" '
              . 'data-name="' . htmlspecialchars($name) . '" '
              . 'data-parent="' . htmlspecialchars($parentForOps) . '" '
              . 'data-abs="' . htmlspecialchars($full) . '">'
              . '<button type="button" class="ft-act ft-act-rename" tabindex="-1" title="Rename folder">'
              .   '<i class="fa fa-pencil"></i></button>'
              . '</span>';

        // Right-aligned tiny toggle — same shared toggleSlideshowFolder
        // handler as everywhere else, including the "via parent" disabled
        // state with diagonal-hatch styling. Skipped folders get no toggle
        // (the add-handler rejects them anyway).
        if (!$skipped) {
            if ($viaParent !== null) {
                $viaTip = 'Included via ' . htmlspecialchars(basename($viaParent), ENT_QUOTES, 'UTF-8')
                        . ' — remove the parent to exclude this folder.';
                $out .= '<label class="fm-toggle fm-toggle-tiny fm-via ft-toggle-cell" title="' . $viaTip . '">'
                      . '<input type="checkbox" data-folder-path="' . htmlspecialchars($full) . '" checked disabled>'
                      . '<span class="fm-toggle-slider"></span></label>';
            } else {
                $titleAttr = $inList ? 'Remove from slideshow' : 'Add to slideshow';
                $out .= '<label class="fm-toggle fm-toggle-tiny ft-toggle-cell" title="' . $titleAttr . '">'
                      . '<input type="checkbox" data-folder-path="' . htmlspecialchars($full) . '"'
                      . ($inList ? ' checked' : '')
                      . ' onchange="toggleSlideshowFolder(this)">'
                      . '<span class="fm-toggle-slider"></span></label>';
            }
        }
        $out .= '</div>';
        if ($hasKids) {
            $out .= '<div class="ft-children">'
                  . render_folder_tree_html_($full, $fullPath, $currentPath, $existingPaths)
                  . '</div>';
        }
        $out .= '</li>';
    }
    return $out . '</ul>';
}
// Load paths.txt early so the sidebar render can compute per-folder state.
$sidebarExistingPaths = file_exists(state_file('paths.txt'))
    ? file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$imagesAbs    = $root_path . '/' . $appName . '/images';
$imagesPrefix = "$appName/images";
$folderTreeHtml = render_folder_tree_html_($imagesAbs, $imagesPrefix, $p, $sidebarExistingPaths);

// Per-page, cell size, sort — read from URL, fall back to cookie, then defaults.
$pp     = (int)($_GET['pp']   ?? $_COOKIE['grid_pp']   ?? 48);
$size   = (int)($_GET['size'] ?? $_COOKIE['grid_size'] ?? 180);
$sort   = $_GET['sort'] ?? $_COOKIE['grid_sort'] ?? 'date_desc';
$page   = max(1, (int)($_GET['page'] ?? 1));
$showSkippedMode = show_skipped_mode();        // 'off' | 'all' | 'only'
$showSkipped     = $showSkippedMode !== 'off';
$starredOnly     = starred_only_active();

if (!in_array($pp, [24, 48, 96, 144, 240, 9999])) $pp = 48;
$size = max(80, min(400, $size));
if (!in_array($sort, ['name_asc','name_desc','date_asc','date_desc','size_asc','size_desc'])) {
    $sort = 'date_desc';
}

// Persist for next visit.
setcookie('grid_pp',   (string)$pp,   time() + 86400 * 365, '/');
setcookie('grid_size', (string)$size, time() + 86400 * 365, '/');
setcookie('grid_sort', $sort,         time() + 86400 * 365, '/');

// ─── List images in this folder (non-recursive) ──────────────────────────────
$img_exts = ['jpg','jpeg','png','gif','bmp','webp','avif'];
$entries  = [];
$skippedCount = 0;      // count of "_" files+folders in this folder, for the toggle badge
$activeImgCount = 0;    // non-skipped images in current folder
$skippedImgCount = 0;   // skipped images in current folder
$starredCount    = 0;   // starred images in current folder — drives the filter visibility
foreach (scandir($absPath) as $f) {
    if ($f === '.' || $f === '..' || $f[0] === '.') continue;
    $full = $absPath . '/' . $f;
    if (!is_file($full)) continue;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (!in_array($ext, $img_exts)) continue;
    $isSkipped = is_skipped_name($f);
    if ($isSkipped) { $skippedCount++; $skippedImgCount++; }
    else            { $activeImgCount++; }
    if (is_starred_name($f)) $starredCount++;
    if ($showSkippedMode === 'off'  &&  $isSkipped) continue;
    if ($showSkippedMode === 'only' && !$isSkipped) continue;
    if ($starredOnly && !is_starred_name($f)) continue;
    $entries[] = [
        'name'    => $f,
        'full'    => $full,
        'rel'     => '/' . $p . '/' . $f,
        'size'    => filesize($full),
        'mtime'   => image_date_ts($full),
        'skipped' => $isSkipped,
        'starred' => is_starred_name($f),
    ];
}

// Sort
usort($entries, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'name_asc':  return strnatcasecmp($a['name'], $b['name']);
        case 'name_desc': return strnatcasecmp($b['name'], $a['name']);
        case 'date_asc':  return $a['mtime'] <=> $b['mtime'];
        case 'date_desc': return $b['mtime'] <=> $a['mtime'];
        case 'size_asc':  return $a['size']  <=> $b['size'];
        case 'size_desc': return $b['size']  <=> $a['size'];
    }
    return 0;
});

$total = count($entries);
$totalPages = $pp >= 9999 ? 1 : max(1, (int)ceil($total / $pp));
if ($page > $totalPages) $page = $totalPages;
$offset = $pp >= 9999 ? 0 : ($page - 1) * $pp;
$slice  = $pp >= 9999 ? $entries : array_slice($entries, $offset, $pp);

// Subfolders shown above the grid for navigation. Skipped folders ("_*") are
// always listed so the user knows they exist, with dashed-orange styling for
// visual distinction. The "Show skipped" toggle only affects files.
$subfolders = [];
foreach (scandir($absPath) as $f) {
    if ($f === '.' || $f === '..' || $f[0] === '.') continue;
    if ($f === 'code') continue;
    if (!is_dir($absPath . '/' . $f)) continue;
    $subfolders[] = ['name' => $f, 'skipped' => is_skipped_name($f)];
}
usort($subfolders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

$parent = '';
$parts  = explode('/', $p);
if (count($parts) > 2) { array_pop($parts); $parent = implode('/', $parts); }

// Helper for preserving query state across links.
function gridUrl($overrides = []) {
    $base = ['p' => $_GET['p'] ?? '', 'page' => 1];
    foreach (['pp','size','sort','show_skipped'] as $k) if (isset($_GET[$k])) $base[$k] = $_GET[$k];
    $merged = array_merge($base, $overrides);
    return 'manage.php?' . http_build_query($merged);
}

// Breadcrumb + status bar are shared with list view; build via manage_util.php.
$crumb     = mgr_breadcrumb_html($p);
$returnUrl = $_SERVER['REQUEST_URI'];

$returnUrl = $_SERVER['REQUEST_URI']; // for bulk action redirects back here
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex, nofollow">
  <title>Time Machine — Grid</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/js/bootstrap.bundle.min.js" defer></script>
  <script>window.csrf = '<?php echo $_SESSION['token'] ?>';</script>
  <?php emit_action_password_js(); ?>
  <style>
    body { background:#F7F7F7; color:#222; margin:0; font-size:15px; }
    a, a:hover, a:visited, a:focus { text-decoration:none; }
    .top-strip { background:#fff; box-shadow:0 4px 5px 0 rgba(0,0,0,.14); position:fixed; top:0; left:0; right:0; z-index:1030; }
    <?php mgr_style_chrome(); ?>
    .ctrl-row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; padding:8px 14px; background:#fafafa; border-top:1px solid #eee; font-size:13px; }
    .ctrl-row label { color:#555; margin-right:4px; }
    .ctrl-row input[type=range] { width:140px; vertical-align:middle; }
    select { font-size:13px; padding:2px 6px; }

    .container-grid { padding:14px; }
    .folder-img-count {
        text-align:right; margin:6px 2px 4px; font-size:14px; color:#555;
    }
    .folder-img-count .skipped-count {
        color:#aaa; font-size:.85em; font-style:italic; margin-left:4px;
    }
    .subfolders { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    /* Classic file-manager folder tile — bold folder glyph on top, name below.
       Kept under the .subfolder-chip class name so existing drag-drop, hover
       and selector code continues to work without changes. */
    .subfolder-chip {
        position:relative;                                /* anchors .sf-check */
        background:#fff; border:1px solid #ddd; border-radius:8px;
        padding:10px 8px 8px; width:96px;
        font-size:12px; color:#333; text-decoration:none;
        display:flex; flex-direction:column; align-items:center; justify-content:flex-start;
        text-align:center; gap:6px;
        transition:background .1s, border-color .1s, transform .08s;
    }
    /* Tiny select overlay on each folder tile — mirrors the per-image
       .check-overlay. Hidden by default to keep the tile clean; revealed
       on hover, and always visible once selected. */
    .subfolder-chip .sf-check {
        position:absolute; top:4px; left:4px;
        width:18px; height:18px; border-radius:50%;
        background:rgba(255,255,255,0.9); border:2px solid #ccc;
        display:none; align-items:center; justify-content:center; z-index:3;
        cursor:pointer; transition:background .12s, border-color .12s;
    }
    .subfolder-chip .sf-check i { color:transparent; font-size:9px; pointer-events:none; }
    .subfolder-chip:hover .sf-check { display:flex; }
    .subfolder-chip.selected .sf-check { display:flex; background:#1a73e8; border-color:#1a73e8; }
    .subfolder-chip.selected .sf-check i { color:#fff; }
    .subfolder-chip.selected { border-color:#1a73e8; box-shadow:0 0 0 2px rgba(26,115,232,.25); }
    .subfolder-chip:hover { background:#fffaf0; border-color:#e8a838; transform:translateY(-1px); }
    .subfolder-chip i {
        font-size:36px; color:#f0b429;             /* warm folder yellow */
        text-shadow:0 1px 0 rgba(0,0,0,.06);
        line-height:1;
    }
    .subfolder-chip .sf-name {
        width:100%;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        font-weight:500; color:#333;
    }
    .subfolder-chip.skipped { background:#fff8f1; border:2px dashed #fd7e14; color:#9c5400; }
    .subfolder-chip.skipped i { color:#fd7e14; }
    .subfolder-chip.skipped .sf-name { color:#9c5400; }
    /* Path-context hint shown on search-result tiles ("vacation/2024"). */
    .subfolder-chip .sf-hint {
        width:100%; font-size:10px; color:#888;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    /* Search results header + clear button + truncation banner. */
    #searchResults { padding:14px; max-width:900px; }
    .search-results-header {
        display:flex; align-items:center; gap:10px;
        padding:6px 0 12px; font-size:14px; color:#333;
        border-bottom:1px solid #e3e6ea; margin-bottom:6px;
    }
    #searchResultsTitle { flex:1; font-weight:600; }
    .search-clear-btn {
        background:#fff; border:1px solid #ccc; color:#555;
        padding:4px 10px; border-radius:4px; cursor:pointer; font-size:13px;
    }
    .search-clear-btn:hover { background:#f1f5fb; color:#222; }
    .search-results-banner {
        padding:6px 10px; margin:0 0 8px;
        background:#fff8e1; color:#7a5b00; font-style:italic; font-size:12px;
        border:1px solid #f3e2a0; border-radius:4px;
    }
    .search-no-results {
        padding:40px 20px; text-align:center; color:#999; font-style:italic;
    }
    /* Search results list — vertical rows reusing the sidebar's .ft-row
       shape so .fm-toggle / .fm-via / drop-target machinery all apply. */
    #searchResultsBody { display:flex; flex-direction:column; gap:2px; }
    #searchResultsBody .sr-row { padding:4px 8px; border-radius:4px; }
    #searchResultsBody .sr-row:hover { background:#eef2f8; }
    #searchResultsBody .sr-row .ft-name { flex:1; min-width:0; }
    #searchResultsBody .sr-row .ft-name-text { font-weight:500; }
    #searchResultsBody .sr-hint {
        color:#888; font-size:11px; margin-left:8px;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    /* Parent ".." tile gets a subdued look so it doesn't compete with real
       folders, while still keeping the same tile footprint for alignment. */
    .subfolder-chip.sf-parent { background:#f6f8fa; border-style:dashed; }
    .subfolder-chip.sf-parent i { color:#888; font-size:30px; }
    .subfolder-chip.sf-parent .sf-name { color:#666; font-style:italic; }

    .grid {
        display:grid;
        grid-template-columns: repeat(auto-fill, minmax(<?php echo $size ?>px, 1fr));
        gap:8px;
    }
    .cell {
        position:relative; background:#fff; border:1px solid #e0e0e0; border-radius:6px;
        overflow:hidden; transition:border-color .15s, box-shadow .15s;
    }
    .cell-img-wrap { display:block; }
    .cell.selected { border-color:#1a73e8; box-shadow:0 0 0 2px rgba(26,115,232,.25); }

    /* Drag-to-select marquee + drop-target visuals */
    #dragRect {
        position: fixed;
        border: 1.5px solid #1a73e8;
        background: rgba(26,115,232,.12);
        pointer-events: none;
        z-index: 9999;
        display: none;
    }
    body.dragging-select, body.dragging-select .grid { user-select: none; touch-action: none; }
    body.dragging-select .cell-img-wrap { pointer-events: none; }
    .subfolder-chip.drop-ready {
        outline: 2px dashed #1a73e8;
        outline-offset: 2px;
        background: #eaf2ff;
    }
    .subfolder-chip.drop-hover {
        outline: 2px solid #1a73e8 !important;
        background: #d6e4f8 !important;
        transform: scale(1.06);
        transition: transform .08s ease-out;
    }
    .cell-img-wrap {
        width:100%;
        aspect-ratio: 1 / 1;
        background:#222 url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="2" fill="%23444"/></svg>') center/auto no-repeat;
        overflow:hidden;
    }
    .cell-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
    .cell-info {
        padding:5px 7px; font-size:11px; color:#555; line-height:1.35;
        display:flex; justify-content:space-between; gap:6px;
        background:#fff; border-top:1px solid #eee;
    }
    .cell-info .name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1 1 auto; }
    .cell-info .meta { color:#999; font-size:10px; flex-shrink:0; }

    /* Per-image select overlay — kept subtle. Hidden by default, revealed
       on cell hover, always visible once selected. Mirrors the .sf-check
       pattern on folder tiles for consistency. */
    .check-overlay {
        position:absolute; top:5px; left:5px;
        width:18px; height:18px; border-radius:50%;
        background:rgba(255,255,255,0.9); border:1.5px solid #bbb;
        display:none; align-items:center; justify-content:center;
        z-index:3; transition:background .12s, border-color .12s;
        cursor:pointer; user-select:none;
    }
    .cell:hover .check-overlay { display:flex; }
    .check-overlay i { color:transparent; font-size:10px; pointer-events:none; }
    .check-overlay:hover { border-color:#1a73e8; background:#fff; }
    .cell.selected .check-overlay { display:flex; background:#1a73e8; border-color:#1a73e8; }
    .cell.selected .check-overlay i { color:#fff; }

    /* Right-edge icon clusters: destructive (trash, crop) at top-right;
       per-image utility (rotate, skip) at bottom-right. Splitting across two
       rows keeps icons from overlapping when the tile-size slider is low. */
    .quick-trash, .quick-crop, .quick-rotate, .quick-skip, .quick-star {
        position:absolute;
        width:24px; height:24px; border-radius:50%;
        background:rgba(0,0,0,0.55); color:#fff; border:none;
        display:flex; align-items:center; justify-content:center;
        font-size:11px; opacity:0; transition:opacity .12s, background .12s;
        cursor:pointer; padding:0; text-decoration:none; z-index:3;
    }
    /* Top-right: per-image utilities (rotate, hide). */
    .quick-rotate { top:6px; right:6px;  font-size:15px; line-height:1; }
    .quick-skip   { top:6px; right:34px; }
    /* Bottom-right: destructive actions (trash, crop). Offset clears the
       .cell-info strip (~28px) so they sit inside the image area. */
    .quick-trash  { bottom:34px; right:6px;  }
    .quick-crop   { bottom:34px; right:34px; }
    /* Bottom-left: star marker, same vertical row as crop/trash but on the
       opposite edge — left side stays empty otherwise (select check is at
       top-left). */
    .quick-star   { bottom:34px; left:6px; }
    /* Starred state: yellow ring + always-visible star (otherwise the
       hover-only icon hides what's actually flagged). */
    .quick-star.active { opacity:1!important; background:#f0ad4e; }
    .quick-star.active i { color:#fff; }
    .cell.starred { box-shadow: inset 0 0 0 2px #f0ad4e; }
    .quick-trash i, .quick-crop i, .quick-skip i { line-height:1; }
    .cell:hover .quick-trash,
    .cell:hover .quick-crop,
    .cell:hover .quick-rotate,
    .cell:hover .quick-skip,
    .cell:hover .quick-star { opacity:1; }
    .quick-star:hover { background:#ec9a3a; }
    .quick-trash:hover  { background:#dc3545; }
    .quick-crop:hover   { background:#1a73e8; color:#fff; }
    .quick-rotate:hover { background:#0157b3; }
    .quick-skip:hover   { background:#fd7e14; }
    .quick-rotate:disabled, .quick-skip:disabled { opacity:.5!important; cursor:wait; }

    /* Skipped cells get an orange dashed border + dimmed thumbnail so you can
       tell at a glance which photos are excluded from the slideshow. */
    .cell.skipped { border:2px dashed #fd7e14; }
    .cell.skipped .cell-img-wrap img { opacity:0.55; filter:grayscale(0.3); }
    .skipped-badge {
        position:absolute; left:50%; top:50%;
        transform:translate(-50%, -50%);
        background:rgba(253,126,20,0.92); color:#fff;
        font-size:10px; font-weight:700; letter-spacing:1px;
        padding:3px 8px; border-radius:3px;
        pointer-events:none; z-index:2;
    }

    .pagination-bar {
        margin-top:18px; display:flex; justify-content:center; align-items:center;
        gap:6px; flex-wrap:wrap; font-size:13px;
    }
    .pagination-bar a, .pagination-bar span {
        padding:5px 10px; border:1px solid #ddd; border-radius:4px;
        background:#fff; color:#333;
    }
    .pagination-bar .current { background:#1a73e8; color:#fff; border-color:#1a73e8; }
    .pagination-bar .disabled { color:#bbb; cursor:not-allowed; }

    .bulk-bar {
        position:fixed; bottom:0; left:0; right:0;
        background:#fff; border-top:1px solid #ddd;
        padding:10px 14px; display:none;
        box-shadow:0 -2px 8px rgba(0,0,0,.08); z-index:50;
    }
    .bulk-bar.visible { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .bulk-bar .count { font-weight:600; color:#1a73e8; }
    .bulk-bar button { font-size:13px; padding:6px 14px; }

    /* Copy/Move modal — same look as manage.php */
    .cm-row{display:flex;align-items:center;gap:10px;padding:11px 16px;cursor:pointer;border-bottom:1px solid #f5f5f5;font-size:13px;transition:background .1s;}
    .cm-row:hover{background:#f0f4ff;}
    .cm-row:last-child{border-bottom:0;}
    .cm-up{color:#555;}

    @media (max-width: 600px) {
        .ctrl-row { font-size:12px; gap:8px; padding:6px 10px; }
        /* Drop ctrl-row text labels — selects/buttons are self-explanatory. */
        .ctrl-row label { display:none; }
        /* "Select all on page · Clear" — niche on mobile; per-tile ✓ is the
           primary path. Hides the entire trailing span. */
        .ctrl-row .ctrl-bulk-links { display:none; }
        .cell-info { font-size:10px; }
    }

    /* ── Sidebar overrides specific to grid view ──────────────────────────
       Shared sidebar chrome (drawer behavior, backdrop, trigger button,
       desktop column layout) lives in mgr_style_chrome() in manage_util.
       This view sets --sidebar-top to clear the fixed top-strip, and
       owns the .sidebar-collapsed (thin-strip) mode unique to it. */
    body.has-sidebar { --sidebar-top: 95px; }
    @media (min-width: 1024px) {
        body.sidebar-collapsed { padding-left:28px; }
        body.sidebar-collapsed .bulk-bar { left:28px; }
        body.sidebar-collapsed #folderSidebar { width:28px; }
        body.sidebar-collapsed #folderSidebar .ft-head-title,
        body.sidebar-collapsed #folderSidebar .ft-scroll { display:none; }
        body.sidebar-collapsed #folderSidebar .ft-toggle { transform:rotate(180deg); }
    }
    .ft-head {
        display:flex; align-items:center; gap:6px; padding:8px 10px;
        border-bottom:1px solid #e6e6ea; background:#fff;
        font-size:12px; color:#666; font-weight:600; letter-spacing:.3px; text-transform:uppercase;
        flex:0 0 auto;
    }
    .ft-head-title { flex:1; display:flex; align-items:center; gap:6px; }
    .ft-head-title i { color:#0157b3; }
    .ft-toggle {
        background:transparent; border:1px solid #d8dde5; color:#666;
        width:22px; height:22px; border-radius:4px; cursor:pointer;
        display:flex; align-items:center; justify-content:center; padding:0;
        font-size:11px;
    }
    .ft-toggle:hover { background:#eef2f8; color:#333; }
    .ft-scroll { flex:1; overflow-y:auto; padding:6px 4px 80px; }
    .ft-list { list-style:none; padding:0 0 0 12px; margin:0; }
    .ft-list .ft-list { padding-left:14px; border-left:1px dashed #e3e3e8; margin-left:8px; }
    .ft-node { position:relative; }
    .ft-node.ft-collapsed > .ft-children { display:none; }
    .ft-row {
        display:flex; align-items:center; gap:2px; min-height:24px;
        border-radius:4px; padding:1px 4px; margin:1px 0;
        transition: background .1s;
    }
    .ft-row:hover { background:#eef2f8; }
    .ft-row.ft-current { background:#dbeafe; }
    .ft-row.ft-current .ft-name { color:#0157b3; font-weight:600; }
    .ft-row.skipped .ft-name { color:#9c5400; }
    .ft-chev {
        background:transparent; border:none; padding:0; cursor:pointer;
        width:18px; height:18px; display:flex; align-items:center; justify-content:center;
        color:#888; font-size:11px; flex-shrink:0;
        transition:transform .12s;
    }
    .ft-chev:hover { color:#333; }
    .ft-node:not(.ft-collapsed) > .ft-row > .ft-chev:not(.ft-chev-blank) { transform:rotate(90deg); }
    .ft-chev-blank { width:18px; height:18px; flex-shrink:0; cursor:default; }
    .ft-name {
        flex:1; min-width:0; display:flex; align-items:center; gap:5px;
        text-decoration:none; color:#333; font-size:12.5px; padding:2px 0;
        overflow:hidden; white-space:nowrap;
    }
    .ft-name i { color:#bba56b; font-size:12px; flex-shrink:0; }
    .ft-row.skipped .ft-name i { color:#fd7e14; }
    .ft-row.ft-current .ft-name i { color:#0157b3; }
    .ft-name-text { overflow:hidden; text-overflow:ellipsis; }
    .ft-skip-tag {
        font-size:9px; font-weight:600; color:#fd7e14; letter-spacing:.5px; flex-shrink:0;
    }
    /* Drop-target highlight on sidebar rows — mirrors the chip behavior. */
    .ft-row.drop-ready { outline:2px dashed #1a73e8; outline-offset:-2px; background:#eaf2ff; }
    .ft-row.drop-hover { outline:2px solid #1a73e8; background:#d6e4f8; }

    /* Tiny variant of the slider toggle, right-aligned per sidebar row.
       Same green / hatched (.fm-via) states as the full-size toggle. */
    .ft-toggle-cell { margin-left:auto; flex-shrink:0; }
    .fm-toggle.fm-toggle-tiny { width:30px; height:16px; }
    .fm-toggle.fm-toggle-tiny .fm-toggle-slider { border-radius:16px; }
    .fm-toggle.fm-toggle-tiny .fm-toggle-slider:before {
        height:12px; width:12px; left:2px; bottom:2px; }
    .fm-toggle.fm-toggle-tiny input:checked + .fm-toggle-slider:before {
        transform:translateX(14px); }

    /* ── View-mode toggle + container swap ─────────────────────────────── */
    .view-mode-toggle { display:inline-flex; border:1px solid #ccc; border-radius:4px; overflow:hidden; }
    .vm-btn {
        background:#fff; border:none; padding:4px 10px; cursor:pointer;
        color:#555; font-size:14px; line-height:1; transition:background .1s;
    }
    .vm-btn + .vm-btn { border-left:1px solid #ccc; }
    .vm-btn:hover { background:#eef2f8; }
    .vm-btn.active { background:#0157b3; color:#fff; }
    body.view-mode-grid .view-list { display:none; }
    body.view-mode-list .view-grid { display:none; }
    body.view-mode-list .vm-grid-only { display:none; }

    /* ── List view table ───────────────────────────────────────────────── */
    .view-list { padding:14px; }
    .vl-table {
        width:100%; border-collapse:collapse; background:#fff;
        border:1px solid #e3e6ea; font-size:14px;
    }
    .vl-table thead th {
        background:#f7f9fc; color:#555; font-size:12px; font-weight:600;
        text-transform:uppercase; letter-spacing:.5px;
        padding:8px 10px; text-align:left; border-bottom:1px solid #e3e6ea;
        white-space:nowrap;
    }
    .vl-table tbody td {
        padding:6px 10px; border-bottom:1px solid #f0f2f5;
        vertical-align:middle; color:#333;
    }
    .vl-table tbody tr:last-child td { border-bottom:none; }
    .vl-row { transition:background .08s; }
    .vl-row:hover { background:#f7f9fc; }
    .vl-row.selected { background:#dbeafe; }
    .vl-row.selected:hover { background:#cfe1fa; }
    .vl-row .vl-check {
        position:relative; top:0; left:0;
        width:18px; height:18px; border:2px solid #ccc; border-radius:50%;
        background:rgba(255,255,255,0.9);
        display:none; align-items:center; justify-content:center;       /* hover-revealed */
        cursor:pointer; z-index:auto;
    }
    .vl-row:hover .vl-check    { display:flex; }
    .vl-row.selected .vl-check { display:flex; background:#1a73e8; border-color:#1a73e8; }
    .vl-row .vl-check i { color:transparent; font-size:9px; pointer-events:none; }
    .vl-row.selected .vl-check i { color:#fff; }
    .vl-details { color:#666; font-size:13px; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .vl-skip-count { color:#aaa; font-size:.85em; font-style:italic; }

    /* Hover image preview for list-view thumbnails — vanilla version of the
       previewImage plugin from manage.php. */
    #vlHoverPreview {
        position:fixed; pointer-events:none; z-index:10000; display:none;
        background:#fff; border:1px solid #ccc; padding:4px; border-radius:4px;
        box-shadow:0 6px 20px rgba(0,0,0,.35); max-width:320px; max-height:320px;
    }
    #vlHoverPreview img { display:block; max-width:312px; max-height:312px; }
    .vl-name { min-width:200px; }
    .vl-name a {
        color:#222; text-decoration:none; display:inline-flex; align-items:center; gap:8px;
        max-width:100%;
    }
    .vl-name a:hover { color:#0157b3; }
    .vl-name a i { color:#f0b429; font-size:18px; flex-shrink:0; }
    .vl-name .vl-thumb {
        width:32px; height:32px; object-fit:cover; border-radius:3px;
        background:#222; flex-shrink:0;
    }
    .vl-row.vl-skipped { background:#fff8f1; }
    .vl-row.vl-skipped .vl-name a { color:#9c5400; }
    .vl-row.vl-skipped > td:first-child { border-left:3px dashed #fd7e14; }
    .vl-row.vl-starred > td:first-child { border-left:3px solid #f0ad4e; }
    .vl-tag {
        display:inline-block; margin-left:6px; padding:1px 6px; font-size:10px;
        font-weight:600; letter-spacing:.5px; color:#fd7e14;
        background:#fff8f1; border:1px solid #fcd9b4; border-radius:3px;
    }
    .vl-tag-star { color:#f0ad4e; background:#fffbe8; border-color:#f0d97a; }
    .vl-num { text-align:right; color:#666; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .vl-actions { white-space:nowrap; text-align:right; }
    .vl-actions .fm-toggle { margin-right:8px; }
    .vl-act {
        background:transparent; border:none; padding:3px 6px; cursor:pointer;
        color:#666; font-size:13px; line-height:1; border-radius:3px;
        text-decoration:none; display:inline-block;
    }
    .vl-act:hover { background:#eef2f8; color:#0157b3; }
    .vl-act.vl-act-trash:hover, .vl-act.vl-act-delete:hover { background:#fde8eb; color:#dc3545; }
    .vl-hover-actions { display:none; }
    .vl-row:hover .vl-hover-actions { display:inline; }
    @media (max-width: 600px) {
        .vl-table { font-size:13px; }
        .vl-table thead th, .vl-table tbody td { padding:5px 4px; }
        /* On phones: keep Details (col 4) visible; hide Modified (5) and
           Actions (6). Per-row actions are reachable from grid view; on
           list view the row's icons + counts are the at-a-glance value. */
        .vl-table th:nth-child(5), .vl-table td:nth-child(5),
        .vl-table th:nth-child(6), .vl-table td:nth-child(6) { display:none; }
        .vl-name .vl-thumb { width:26px; height:26px; }
        .vl-details { font-size:12px; white-space:normal; }
    }

    /* "Descendant in slideshow" hint dot — made prominent so the user
       can spot at a glance which branches play. Larger glyph, brighter
       glow, sits right before the toggle. */
    .ft-desc-hint {
        font-size:2em; line-height:.7; margin:0 6px 0 4px; flex-shrink:0;
        color:#28a745; text-shadow:0 0 7px rgba(40,167,69,.65);
    }

    /* Hover-reveal Rename + Delete icons. Single wrapper element so a
       future upgrade to a ⋮-popover can move this without changing JS. */
    .ft-row-actions {
        display:none; align-items:center; gap:2px; flex-shrink:0; margin-right:4px;
    }
    .ft-row:hover .ft-row-actions { display:inline-flex; }
    .ft-act {
        background:transparent; border:none; padding:2px 5px; cursor:pointer;
        color:#888; font-size:12px; line-height:1; border-radius:3px;
    }
    .ft-act:hover { color:#222; background:#e9ecef; }
    .ft-act.ft-act-delete:hover { color:#dc3545; background:#fde8eb; }
  </style>
</head>
<body class="has-sidebar">
<script>
// Pre-apply the saved view mode to the body class BEFORE any content
// renders, so the user never sees both view-grid and view-list briefly
// flash. initViewModeToggle below wires the buttons; this just sets state.
(function() {
    try {
        var m = localStorage.getItem('gridViewMode') === 'list' ? 'list' : 'grid';
        document.body.classList.add(m === 'list' ? 'view-mode-list' : 'view-mode-grid');
    } catch (e) {
        document.body.classList.add('view-mode-grid');
    }
})();
</script>

<!-- Backdrop for the mobile drawer mode (display:none on desktop). -->
<div id="sidebarBackdrop" onclick="closeSidebarDrawer()"></div>

<aside id="folderSidebar" aria-label="Folder tree">
  <div class="ft-head">
    <span class="ft-head-title"><i class="fa fa-sitemap"></i> Folders</span>
    <button type="button" class="ft-toggle" id="ftToggleBtn" title="Collapse / expand sidebar">
      <i class="fa fa-angle-left"></i>
    </button>
  </div>
  <div class="ft-scroll">
    <?php echo $folderTreeHtml ?: '<div style="padding:16px;color:#aaa;font-style:italic;font-size:12px">No subfolders.</div>'; ?>
  </div>
</aside>

<?php $trashCount = countDirImages_($root_path . '/' . $appName . '/trash'); ?>
<?php
// Shared top header (brand bar + search + breadcrumb + Add-to-slideshow action
// + trash banner) — identical in both views via manage_util.php.
$existingPathsForHeader = file_exists(state_file('paths.txt'))
    ? file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
mgr_render_top_header('grid', $p, $inTrash, $absPath, $existingPathsForHeader,
                      $trashCount, 'Time Machine', true);
?>
<?php mgr_render_status_bar(); ?>
  <div class="ctrl-row">
    <span class="view-mode-toggle" role="group" aria-label="View mode">
      <button type="button" class="vm-btn" data-mode="grid" title="Grid view"><i class="fa fa-th"></i></button>
      <button type="button" class="vm-btn" data-mode="list" title="List view"><i class="fa fa-list"></i></button>
    </span>
    <span class="vm-grid-only"><label>Cell size:</label>
      <input type="range" id="sizeSlider" min="80" max="400" step="10" value="<?php echo $size ?>">
      <span id="sizeVal" style="font-variant-numeric:tabular-nums; min-width:40px; display:inline-block;"><?php echo $size ?>px</span>
    </span>
    <span><label>Per page:</label>
      <select id="ppSelect">
        <?php foreach ([24, 48, 96, 144, 240, 9999] as $opt): ?>
          <option value="<?php echo $opt ?>" <?php echo $pp == $opt ? 'selected' : '' ?>>
            <?php echo $opt == 9999 ? 'All' : $opt ?>
          </option>
        <?php endforeach; ?>
      </select>
    </span>
    <span><label>Sort:</label>
      <select id="sortSelect">
        <option value="date_desc" <?php echo $sort=='date_desc'?'selected':'' ?>>Date — newest first</option>
        <option value="date_asc"  <?php echo $sort=='date_asc' ?'selected':'' ?>>Date — oldest first</option>
        <option value="name_asc"  <?php echo $sort=='name_asc' ?'selected':'' ?>>Name — A→Z</option>
        <option value="name_desc" <?php echo $sort=='name_desc'?'selected':'' ?>>Name — Z→A</option>
        <option value="size_asc"  <?php echo $sort=='size_asc' ?'selected':'' ?>>Size — small→large</option>
        <option value="size_desc" <?php echo $sort=='size_desc'?'selected':'' ?>>Size — large→small</option>
      </select>
    </span>
    <?php if (!$inTrash && ($starredCount > 0 || $starredOnly)):
        $uStar = $_GET;
        if ($starredOnly) unset($uStar['starred']); else $uStar['starred'] = '1';
        $starHref = 'manage.php?' . http_build_query($uStar);
        $starCol  = $starredOnly ? '#f0ad4e' : '#666';
        $starBdr  = $starredOnly ? '#f0ad4e' : '#ccc';
        $starIcon = $starredOnly ? 'fa-star' : 'fa-star-o';
        $starTip  = ($starredOnly ? 'Show all images' : 'Show only starred images')
                  . ($starredCount > 0 ? ' (' . $starredCount . ' starred)' : '');
    ?>
      <span>
        <a href="<?php echo htmlspecialchars($starHref) ?>"
           title="<?php echo htmlspecialchars($starTip) ?>"
           style="font-size:12px;color:<?php echo $starCol ?>;border:1px solid <?php echo $starBdr ?>;padding:2px 8px;border-radius:4px;text-decoration:none">
          <i class="fa <?php echo $starIcon ?>"></i>
        </a>
      </span>
    <?php endif; ?>
    <?php if (!$inTrash && ($skippedCount > 0 || $showSkippedMode !== 'off')):
        // Two-state cycle: off ↔ only.
        $u = $_GET;
        if ($showSkippedMode === 'off') { $u['show_skipped'] = 'only'; $sLabel = 'Show skipped'; }
        else                            { unset($u['show_skipped']);   $sLabel = 'Hide skipped'; }
        $toggleHref = 'manage.php?' . http_build_query($u);
        $sCol = $showSkippedMode === 'off' ? '#666' : '#fd7e14';
        $sBdr = $showSkippedMode === 'off' ? '#ccc' : '#fd7e14';
        $sIcon = $showSkippedMode === 'off' ? 'fa-eye-slash' : 'fa-eye';
    ?>
      <span>
        <a href="<?php echo htmlspecialchars($toggleHref) ?>"
           style="font-size:12px;color:<?php echo $sCol ?>;border:1px solid <?php echo $sBdr ?>;padding:2px 8px;border-radius:4px;text-decoration:none">
          <i class="fa <?php echo $sIcon ?>"></i>
          <?php echo $sLabel ?>
          <?php if ($skippedCount > 0) echo " ($skippedCount)" ?>
        </a>
      </span>
    <?php endif; ?>
    <span class="ctrl-bulk-links" style="margin-left:auto;">
      <a href="javascript:void(0)" onclick="selectAllOnPage()" style="font-size:12px; color:#1a73e8;">Select all on page</a>
      &middot;
      <a href="javascript:void(0)" onclick="clearSelection()" style="font-size:12px; color:#666;">Clear</a>
    </span>
  </div>
</div>

<?php
// Current-folder action strip — sits directly above the grid so it reads
// as part of the content (not part of the info/status bar at top).
mgr_render_folder_action_bar($absPath, $existingPathsForHeader, $inTrash, basename($absPath));
?>

<!-- Search results — shown only while a query is active; the two view
     containers below are hidden during search. -->
<div id="searchResults" style="display:none">
  <div class="search-results-header">
    <span id="searchResultsTitle">Search results</span>
    <button type="button" class="search-clear-btn" onclick="clearGridSearch()" title="Clear search (Esc)">
      <i class="fa fa-times"></i> Clear
    </button>
  </div>
  <div id="searchResultsBody"></div>
</div>

<!-- view-grid wraps the existing folder-tiles + image-grid layout. The
     parallel .view-list below renders the same data as a table. body class
     view-mode-grid / view-mode-list (set by JS from localStorage) hides
     the inactive one. -->
<div class="view-grid">
<div class="container-grid">
  <?php if ($parent !== '' || !empty($subfolders)): ?>
    <div class="subfolders" style="margin-top:6px;">
      <?php if ($parent !== ''): ?>
        <a class="subfolder-chip sf-parent drop-target" href="?p=<?php echo urlencode($parent) ?>"
           data-path="<?php echo htmlspecialchars($parent) ?>"
           data-label="<?php echo htmlspecialchars('.. (parent)') ?>"
           title="Up to parent folder">
          <i class="fa fa-folder-open-o"></i>
          <span class="sf-name">.. parent</span>
        </a>
      <?php endif; ?>
      <?php foreach ($subfolders as $sf): ?>
        <?php $sfPath = ($p !== '' ? $p . '/' : '') . $sf['name']; ?>
        <a class="subfolder-chip drop-target<?php echo $sf['skipped'] ? ' skipped' : '' ?>"
           href="?p=<?php echo urlencode($sfPath) ?>"
           data-name="<?php echo htmlspecialchars($sf['name']) ?>"
           data-abs="<?php echo htmlspecialchars($absPath . '/' . $sf['name']) ?>"
           data-path="<?php echo htmlspecialchars($sfPath) ?>"
           data-label="<?php echo htmlspecialchars($sf['name']) ?>"
           title="<?php echo htmlspecialchars($sf['name']) . ($sf['skipped'] ? ' (skipped)' : '') ?>">
          <span class="check-overlay sf-check" onclick="toggleSubfolder(this.parentElement, event)" title="Select / deselect">
            <i class="fa fa-check"></i>
          </span>
          <i class="fa fa-folder"></i>
          <span class="sf-name"><?php echo htmlspecialchars($sf['name']) ?></span>
          <?php if ($sf['skipped']) echo '<span style="color:#fd7e14;font-weight:600;font-size:9px;letter-spacing:.5px">SKIPPED</span>'; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($activeImgCount > 0 || $skippedImgCount > 0): ?>
    <div class="folder-img-count" data-active="<?php echo $activeImgCount ?>" data-skipped="<?php echo $skippedImgCount ?>">
      <i class="fa fa-picture-o" style="color:#5a8fc2"></i>
      <span class="fic-active"><?php echo $activeImgCount ?></span>
      <span class="fic-active-label"> image<?php echo $activeImgCount === 1 ? '' : 's' ?></span>
      <span class="fic-skipped-wrap"<?php echo $skippedImgCount === 0 ? ' style="display:none"' : '' ?>>
        <span class="skipped-count">(<span class="fic-skipped"><?php echo $skippedImgCount ?></span> skipped)</span>
      </span>
    </div>
  <?php endif; ?>

  <?php if (empty($slice)): ?>
    <p style="color:#888; font-style:italic; padding:20px;">No images in this folder.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($slice as $e):
          $sizeStr = $e['size'] >= 1048576
              ? number_format($e['size']/1048576, 1).'MB'
              : number_format($e['size']/1024, 0).'KB';
          $dateStr = $e['mtime'] ? date('j M Y', $e['mtime']) : '';
      ?>
        <div class="cell<?php echo $e['skipped'] ? ' skipped' : '' ?><?php echo $e['starred'] ? ' starred' : '' ?>"
             data-name="<?php echo htmlspecialchars($e['name']) ?>"
             data-abs="<?php echo htmlspecialchars($e['full']) ?>"
             data-rel="<?php echo htmlspecialchars($e['rel']) ?>"
             data-skipped="<?php echo $e['skipped'] ? '1' : '0' ?>"
             data-starred="<?php echo $e['starred'] ? '1' : '0' ?>">
          <div class="check-overlay" onclick="toggleCell(this.parentElement, event)"
               title="Select / deselect">
            <i class="fa fa-check"></i>
          </div>
          <button class="quick-trash" onclick="cellTrashAction(this)"
                  title="<?php echo $inTrash ? 'Restore to original location' : 'Move to trash' ?>">
            <i class="fa <?php echo $inTrash ? 'fa-undo' : 'fa-trash-o' ?>"></i>
          </button>
          <?php if (!$inTrash): ?>
          <a class="quick-crop" href="pages/crop.php?path=<?php echo urlencode($e['rel']) ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']) ?>"
             title="Crop this image">
            <i class="fa fa-crop"></i>
          </a>
          <button class="quick-rotate" onclick="cellRotate(this)"
                  title="Rotate 90° clockwise">↻</button>
          <button class="quick-skip" onclick="cellSkipToggle(this)"
                  title="<?php echo $e['skipped'] ? 'Unskip — include in slideshow again' : 'Skip — keep this file but exclude from slideshow' ?>">
            <i class="fa <?php echo $e['skipped'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
          </button>
          <button class="quick-star<?php echo $e['starred'] ? ' active' : '' ?>" onclick="cellStarToggle(this)"
                  data-starred="<?php echo $e['starred'] ? '1' : '0' ?>"
                  title="<?php echo $e['starred'] ? 'Unstar' : 'Star this image' ?>">
            <i class="fa <?php echo $e['starred'] ? 'fa-star' : 'fa-star-o' ?>"></i>
          </button>
          <?php endif; ?>
          <?php if ($e['skipped']): ?>
          <span class="skipped-badge">SKIPPED</span>
          <?php endif; ?>
          <?php $cacheBust = '?_=' . @filemtime($e['full']); ?>
          <a class="cell-img-wrap" href="<?php echo htmlspecialchars($e['rel'] . $cacheBust) ?>" target="_blank"
             title="Open full size">
            <img loading="lazy" src="<?php echo htmlspecialchars($e['rel'] . $cacheBust) ?>" alt="<?php echo htmlspecialchars($e['name']) ?>">
          </a>
          <div class="cell-info">
            <span class="name" title="<?php echo htmlspecialchars($e['name']) ?>"><?php echo htmlspecialchars($e['name']) ?></span>
            <span class="meta"><?php echo $sizeStr ?> &middot; <?php echo $dateStr ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pagination-bar">
        <?php
        $linkOr = function($targetPage, $label, $disabled = false) {
            if ($disabled) {
                echo "<span class='disabled'>$label</span> ";
            } else {
                echo '<a href="' . htmlspecialchars(gridUrl(['page' => $targetPage])) . "\">$label</a> ";
            }
        };
        $linkOr(1,        '« First',  $page == 1);
        $linkOr($page-1,  '‹ Prev',   $page == 1);
        echo "<span class='current'>Page $page of $totalPages</span> ";
        $linkOr($page+1,  'Next ›',   $page == $totalPages);
        $linkOr($totalPages, 'Last »', $page == $totalPages);
        ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</div><!-- /.view-grid -->

<!-- ──────────────────────────────────────────────────────────────────────
     view-list: same data as the grid above, rendered as a flat table.
     Folder rows first (alphabetical), then image rows (current sort).
     Each row carries the same data-name / data-abs / data-rel / data-skipped
     / data-starred attributes used by selection + drag-drop, so the existing
     handlers work on rows without rewrites.
     ────────────────────────────────────────────────────────────────────── -->
<div class="view-list">
  <?php if (empty($subfolders) && empty($slice)): ?>
    <p style="color:#888; font-style:italic; padding:20px;">This folder is empty.</p>
  <?php else: ?>
    <table class="vl-table">
      <thead>
        <tr>
          <th style="width:30px"></th>
          <th>Name</th>
          <th class="vl-num">Size</th>
          <th>Details</th>
          <th>Modified</th>
          <th class="vl-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php // ── Folder rows ────────────────────────────────────────────
        // Per-folder slideshow toggle dropped — the always-visible sidebar
        // tree carries the same toggle for every folder, so duplicating it
        // here just causes sync drift and clutter.
        foreach ($subfolders as $sf):
            $sfAbs   = $absPath . '/' . $sf['name'];
            $sfPath  = ($p !== '' ? $p . '/' : '') . $sf['name'];
            // Count subfolders and images (split) + size for the Details cell.
            $sfImgCounts = countDirImagesSplit($sfAbs);
            $sfSubCount  = 0;
            foreach (@scandir($sfAbs) ?: [] as $cf) {
                if ($cf === '.' || $cf === '..' || $cf[0] === '.') continue;
                if (is_dir($sfAbs . '/' . $cf)) $sfSubCount++;
            }
            $sfSizeBytes = getDirectorySize($sfAbs);
            $sfSizeStr   = $sfSizeBytes !== false ? mgr_human_size_($sfSizeBytes) : '';
            $sfMtime  = @filemtime($sfAbs) ?: 0;
            // Size lives in its own column now — Details just shows the
            // counts breakdown for folders, using icons instead of words
            // so wide tables stay scannable. Tooltips spell it out.
            $detailParts = [];
            if ($sfSubCount > 0)             $detailParts[] = '<span title="Subfolders (recursive)"><i class="fa fa-folder-o" style="color:#e8a838"></i> ' . $sfSubCount . '</span>';
            if ($sfImgCounts['active'] > 0)  $detailParts[] = '<span title="Images"><i class="fa fa-picture-o" style="color:#5a8fc2"></i> ' . $sfImgCounts['active'] . '</span>';
            if ($sfImgCounts['skipped'] > 0) $detailParts[] = '<span class="vl-skip-count" title="Skipped images">(' . $sfImgCounts['skipped'] . ' skipped)</span>';
        ?>
        <tr class="vl-row vl-folder drop-target<?php echo $sf['skipped'] ? ' vl-skipped' : '' ?>"
            data-name="<?php echo htmlspecialchars($sf['name']) ?>"
            data-abs="<?php echo htmlspecialchars($sfAbs) ?>"
            data-path="<?php echo htmlspecialchars($sfPath) ?>"
            data-label="<?php echo htmlspecialchars($sf['name']) ?>"
            data-kind="folder">
          <td>
            <span class="check-overlay vl-check" onclick="toggleVlRow(this.closest('tr'), event)" title="Select / deselect">
              <i class="fa fa-check"></i>
            </span>
          </td>
          <td class="vl-name">
            <a href="?p=<?php echo urlencode($sfPath) ?>" title="<?php echo htmlspecialchars($sf['name']) ?>">
              <i class="fa fa-folder" style="color:#f0b429"></i>
              <?php echo htmlspecialchars($sf['name']) ?>
            </a>
            <?php if ($sf['skipped']): ?><span class="vl-tag">SKIP</span><?php endif; ?>
          </td>
          <td class="vl-num"><?php echo $sfSizeStr; ?></td>
          <td class="vl-details"><?php echo implode(' &nbsp; ', $detailParts); ?></td>
          <td><?php echo $sfMtime ? date('Y-m-d H:i', $sfMtime) : ''; ?></td>
          <td class="vl-actions">
            <span class="vl-hover-actions"
                  data-name="<?php echo htmlspecialchars($sf['name']) ?>"
                  data-parent="<?php echo htmlspecialchars($p) ?>"
                  data-abs="<?php echo htmlspecialchars($sfAbs) ?>">
              <button type="button" class="vl-act vl-act-rename" title="Rename folder"><i class="fa fa-pencil"></i></button>
              <button type="button" class="vl-act vl-act-delete" title="Delete folder (permanent)"><i class="fa fa-trash-o"></i></button>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php // ── Image rows ─────────────────────────────────────────────
        foreach ($slice as $e):
            $sizeStr = mgr_human_size_($e['size']);
            $dateStr = $e['mtime'] ? date('Y-m-d H:i', $e['mtime']) : '';
            $cacheBust = '?_=' . @filemtime($e['full']);
            // Dimensions: cheap header read; getimagesize is fast on jpeg/png.
            $dims = @getimagesize($e['full']);
            $dimStr = ($dims && isset($dims[0], $dims[1])) ? $dims[0] . '×' . $dims[1] : '';
            // Size lives in its own column now — Details just shows dimensions.
            $detailParts = array_filter([$dimStr]);
        ?>
        <tr class="vl-row vl-image<?php echo $e['skipped'] ? ' vl-skipped' : '' ?><?php echo $e['starred'] ? ' vl-starred' : '' ?>"
            data-name="<?php echo htmlspecialchars($e['name']) ?>"
            data-abs="<?php echo htmlspecialchars($e['full']) ?>"
            data-rel="<?php echo htmlspecialchars($e['rel']) ?>"
            data-skipped="<?php echo $e['skipped'] ? '1' : '0' ?>"
            data-starred="<?php echo $e['starred'] ? '1' : '0' ?>"
            data-kind="image">
          <td>
            <span class="check-overlay vl-check" onclick="toggleVlRow(this.closest('tr'), event)" title="Select / deselect">
              <i class="fa fa-check"></i>
            </span>
          </td>
          <td class="vl-name">
            <a class="vl-thumb-link" href="<?php echo htmlspecialchars($e['rel'] . $cacheBust) ?>" target="_blank" title="Open full size"
               data-preview-src="<?php echo htmlspecialchars($e['rel'] . $cacheBust) ?>">
              <img class="vl-thumb" loading="lazy" src="<?php echo htmlspecialchars($e['rel'] . $cacheBust) ?>" alt="">
              <?php echo htmlspecialchars($e['name']) ?>
            </a>
            <?php if ($e['skipped']): ?><span class="vl-tag">SKIP</span><?php endif; ?>
            <?php if ($e['starred']): ?><span class="vl-tag vl-tag-star"><i class="fa fa-star"></i></span><?php endif; ?>
          </td>
          <td class="vl-num"><?php echo $sizeStr; ?></td>
          <td class="vl-details"><?php echo implode(' &middot; ', $detailParts); ?></td>
          <td><?php echo $dateStr; ?></td>
          <td class="vl-actions">
            <?php if (!$inTrash): ?>
              <button type="button" class="vl-act" onclick="cellStarToggle(this)" title="<?php echo $e['starred'] ? 'Unstar' : 'Star this image' ?>" data-starred="<?php echo $e['starred'] ? '1' : '0' ?>"><i class="fa <?php echo $e['starred'] ? 'fa-star' : 'fa-star-o' ?>" style="<?php echo $e['starred'] ? 'color:#f0ad4e' : '' ?>"></i></button>
              <button type="button" class="vl-act" onclick="cellSkipToggle(this)" title="<?php echo $e['skipped'] ? 'Unskip' : 'Skip from slideshow' ?>"><i class="fa <?php echo $e['skipped'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button>
              <button type="button" class="vl-act" onclick="cellRotate(this)" title="Rotate 90° clockwise">↻</button>
              <a class="vl-act" href="pages/crop.php?path=<?php echo urlencode($e['rel']) ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']) ?>" title="Crop this image"><i class="fa fa-crop"></i></a>
            <?php endif; ?>
            <button type="button" class="vl-act vl-act-trash" onclick="cellTrashAction(this)" title="<?php echo $inTrash ? 'Restore' : 'Move to trash' ?>"><i class="fa <?php echo $inTrash ? 'fa-undo' : 'fa-trash-o' ?>"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <div class="pagination-bar">
        <?php
        $linkOrL = function($targetPage, $label, $disabled = false) {
            if ($disabled) echo "<span class='disabled'>$label</span> ";
            else echo '<a href="' . htmlspecialchars(gridUrl(['page' => $targetPage])) . "\">$label</a> ";
        };
        $linkOrL(1,           '« First',  $page == 1);
        $linkOrL($page - 1,   '‹ Prev',   $page == 1);
        echo "<span class='current'>Page $page of $totalPages</span> ";
        $linkOrL($page + 1,   'Next ›',   $page == $totalPages);
        $linkOrL($totalPages, 'Last »',   $page == $totalPages);
        ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div><!-- /.view-list -->

<!-- Copy / Move browser modal — mirrors manage.php's pattern -->
<div class="modal fade" id="copyMoveModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="fa fa-files-o"></i> Copy / Move to&hellip;</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div style="padding:10px 14px;background:#f8f9fa;border-bottom:1px solid #dee2e6">
          <div style="font-size:11px;color:#888;margin-bottom:3px;text-transform:uppercase;letter-spacing:.5px">Destination</div>
          <div id="cm-breadcrumb" style="font-size:13px;color:#333;word-break:break-all;min-height:18px"></div>
        </div>
        <div style="padding:10px 14px;border-bottom:1px solid #dee2e6;display:flex;gap:8px">
          <button id="cm-copy-btn" class="btn btn-success btn-sm">
            <i class="fa fa-copy"></i> Copy here
          </button>
          <button id="cm-move-btn" class="btn btn-warning btn-sm">
            <i class="fa fa-scissors"></i> Move here
          </button>
        </div>
        <div id="cm-folder-list" style="max-height:320px;overflow-y:auto"></div>
      </div>
    </div>
  </div>
</div>

<!-- Drag-select marquee rectangle (positioned in JS) -->
<div id="dragRect"></div>

<!-- Drop confirmation modal — appears after dropping selection on a folder chip -->
<div class="modal fade" id="dropConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="fa fa-hand-paper-o"></i> Move or copy?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div style="font-size:14px;">
          <span id="dropConfirmWhat">0 items</span>
          &rarr; <strong id="dropConfirmDest" style="color:#0157b3;word-break:break-all"></strong>
        </div>
        <p style="font-size:12px;color:#888;margin-top:8px;margin-bottom:0">
          Files with the same name already in the destination will be skipped.
        </p>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-warning" id="dropCopyBtn"><i class="fa fa-copy"></i> Copy</button>
        <button type="button" class="btn btn-sm btn-primary" id="dropMoveBtn"><i class="fa fa-arrow-right"></i> Move</button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk action bar (slides up when something is selected) -->
<div class="bulk-bar" id="bulkBar">
  <span><span class="count" id="bulkCount">0</span> selected</span>
  <?php if ($inTrash): ?>
    <button class="btn btn-sm btn-success" onclick="bulkRestore()"><i class="fa fa-undo"></i> Restore</button>
    <button class="btn btn-sm btn-danger"  onclick="bulkPurge()"><i class="fa fa-trash"></i> Delete permanently</button>
  <?php else: ?>
    <button class="btn btn-sm btn-danger"  onclick="bulkTrash()"><i class="fa fa-trash"></i> Trash</button>
    <button class="btn btn-sm btn-warning" onclick="bulkCopyMove()"><i class="fa fa-files-o"></i> Copy / Move</button>
  <?php endif; ?>
  <button class="btn btn-sm btn-light" onclick="clearSelection()">Cancel</button>
</div>

<?php if ($inTrash): ?>
<div style="position:fixed; bottom:0; right:0; padding:10px 14px; z-index:49;">
  <button class="btn btn-sm btn-outline-danger" onclick="emptyTrash()">
    <i class="fa fa-fire"></i> Empty trash
  </button>
</div>
<?php endif; ?>

<script>
const folderPath = <?php echo json_encode($p) ?>;
const returnUrl  = <?php echo json_encode($returnUrl) ?>;
const showSkippedMode = <?php echo json_encode($showSkippedMode) ?>; // 'off' | 'all' | 'only'
const csrfToken  = window.csrf;

// Two parallel selection sets so per-image and per-folder operations stay
// distinguishable in code, even though both end up in the same `file[]`
// POST array (manage.php's bulk handler uses fm_rename/fm_rcopy which work
// for files AND directories — no backend change).
const selected        = new Set();      // image filenames in current folder
const selectedFolders = new Set();      // subfolder names in current folder

// Total selection size — used by anything that just needs "is anything selected?".
function totalSelected() { return selected.size + selectedFolders.size; }

// Human-readable badge text for the drag ghost ("3 images + 2 folders").
function ghostBadgeText() {
    const parts = [];
    if (selected.size)        parts.push(selected.size        + ' image'  + (selected.size !== 1 ? 's' : ''));
    if (selectedFolders.size) parts.push(selectedFolders.size + ' folder' + (selectedFolders.size !== 1 ? 's' : ''));
    return parts.join(' + ');
}

// Combined names for Copy/Move POST (file[]= takes both files and dirs).
function selectedNamesCombined() {
    return Array.from(selected).concat(Array.from(selectedFolders));
}

// Mirror a name's selected-state to every element with the same data-name
// across views (grid cell, folder tile, list row). Keeps the two layouts
// visually in sync as the user toggles from either side.
function _mirrorSelection(name, isOn) {
    const sel = (window.CSS && CSS.escape) ? CSS.escape(name) : name;
    document.querySelectorAll('[data-name="' + sel + '"]').forEach(function(el) {
        if (el.classList.contains('cell') || el.classList.contains('subfolder-chip') || el.classList.contains('vl-row')) {
            el.classList.toggle('selected', isOn);
        }
    });
}

// Per-image selection (top-left ✓ on each thumbnail).
function toggleCell(el, ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    const name = el.dataset.name;
    const on = !selected.has(name);
    if (on) selected.add(name); else selected.delete(name);
    _mirrorSelection(name, on);
    refreshBulkBar();
}

// Per-folder selection (top-left ✓ on each folder tile).
function toggleSubfolder(el, ev) {
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    const name = el.dataset.name;
    if (!name) return;
    const on = !selectedFolders.has(name);
    if (on) selectedFolders.add(name); else selectedFolders.delete(name);
    _mirrorSelection(name, on);
    refreshBulkBar();
}

function refreshBulkBar() {
    const bar = document.getElementById('bulkBar');
    const cnt = document.getElementById('bulkCount');
    cnt.textContent = totalSelected();
    bar.classList.toggle('visible', totalSelected() > 0);
}

function selectAllOnPage() {
    document.querySelectorAll('.cell').forEach(c => {
        selected.add(c.dataset.name);
        c.classList.add('selected');
    });
    refreshBulkBar();
}

function clearSelection() {
    selected.clear();
    selectedFolders.clear();
    document.querySelectorAll('.cell.selected').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.subfolder-chip.selected').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.vl-row.selected').forEach(c => c.classList.remove('selected'));
    refreshBulkBar();
}

// Unified row selection for the list view. Dispatches to the existing
// per-image / per-folder Sets based on the row's data-kind, then mirrors
// the visual state to grid cells / folder tiles via _mirrorSelection.
function toggleVlRow(tr, ev) {
    if (!tr) return;
    if (ev) { ev.preventDefault(); ev.stopPropagation(); }
    const name = tr.dataset.name;
    if (!name) return;
    if (tr.dataset.kind === 'folder') {
        const on = !selectedFolders.has(name);
        if (on) selectedFolders.add(name); else selectedFolders.delete(name);
        _mirrorSelection(name, on);
    } else {
        const on = !selected.has(name);
        if (on) selected.add(name); else selected.delete(name);
        _mirrorSelection(name, on);
    }
    refreshBulkBar();
}

function buildBulkForm(extraFields) {
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'manage_ops.php?p=' + encodeURIComponent(folderPath);
    selected.forEach(name => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'file[]'; i.value = name;
        form.appendChild(i);
    });
    const tok = document.createElement('input');
    tok.type='hidden'; tok.name='token'; tok.value=csrfToken;
    form.appendChild(tok);
    const ret = document.createElement('input');
    ret.type='hidden'; ret.name='return'; ret.value=returnUrl;
    form.appendChild(ret);
    Object.entries(extraFields || {}).forEach(([k,v]) => {
        const i = document.createElement('input');
        i.type='hidden'; i.name=k; i.value=v;
        form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}

function bulkCopyMove() {
    if (totalSelected() === 0) return;
    if (!requireActionPassword('copy / move')) return;
    cmOpenModal(selectedNamesCombined());
}

// Build a list of absolute paths from the current selection. Reads from
// the names in `selected` / `selectedFolders` then looks up the abs path
// on whichever rendered element has the data-abs (grid cell, folder tile,
// or list row — whichever is currently in the active view).
function selectedAbsPaths() {
    var paths = [];
    function lookupAbs(name) {
        var sel = (window.CSS && CSS.escape) ? CSS.escape(name) : name;
        var el  = document.querySelector('[data-name="' + sel + '"][data-abs]');
        return el ? el.dataset.abs : null;
    }
    selected.forEach(function(n)        { var a = lookupAbs(n); if (a) paths.push(a); });
    selectedFolders.forEach(function(n) { var a = lookupAbs(n); if (a) paths.push(a); });
    return paths;
}

function trashFetch(url, paths, extra) {
    var fd = new FormData();
    fd.append('token', csrfToken);
    paths.forEach(function(p) { fd.append('paths[]', p); });
    if (extra) Object.entries(extra).forEach(function(kv){ fd.append(kv[0], kv[1]); });
    return fetch(url, { method: 'POST', body: fd }).then(function(r) { return r.json(); });
}

function bulkTrash() {
    var paths = selectedAbsPaths();
    if (paths.length === 0) return;
    if (!requireActionPassword('moving ' + paths.length + ' item' + (paths.length!=1?'s':'') + ' to trash')) return;
    trashFetch('util/send_to_trash.php', paths)
        .then(function(d) { location.reload(); })
        .catch(function() { alert('Network error.'); });
}

function bulkRestore() {
    var paths = selectedAbsPaths();
    if (paths.length === 0) return;
    if (!requireActionPassword('restoring ' + paths.length + ' item' + (paths.length!=1?'s':''))) return;
    trashFetch('util/restore_from_trash.php', paths)
        .then(function(d) { location.reload(); })
        .catch(function() { alert('Network error.'); });
}

function bulkPurge() {
    var paths = selectedAbsPaths();
    if (paths.length === 0) return;
    if (!requireActionPassword('permanent deletion of ' + paths.length + ' item' + (paths.length!=1?'s':''))) return;
    if (!confirm('Permanently delete ' + paths.length + ' file' + (paths.length!=1?'s':'') + '? This cannot be undone.')) return;
    trashFetch('util/delete_permanent.php', paths)
        .then(function(d) { location.reload(); })
        .catch(function() { alert('Network error.'); });
}

function emptyTrash() {
    if (!requireActionPassword('emptying the entire trash')) return;
    if (!confirm('Permanently empty the entire trash? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('token', csrfToken);
    fd.append('empty', '1');
    fetch('util/delete_permanent.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) { location.reload(); })
        .catch(function() { alert('Network error.'); });
}

// Rotate a single image 90° clockwise (in-place via util/img_rot.php).
// Refreshes the cell's <img> src on success so the rotated result shows
// without a full page reload.
function cellRotate(btn) {
    var card = btn.closest('.cell, .vl-row.vl-image');
    if (!card || !card.dataset.rel) return;
    var rel = card.dataset.rel;
    var name = card.dataset.name || 'this image';
    if (!requireActionPassword('rotating "' + name + '"')) return;

    btn.disabled = true;
    var prevHtml = btn.innerHTML;
    btn.innerHTML = '⏳';

    var url = 'util/img_rot.php?path=' + encodeURIComponent(rel)
            + '&p=' + encodeURIComponent(folderPath);
    fetch(url)
        .then(function(r) { return r.text(); })
        .then(function(t) {
            btn.disabled = false;
            btn.innerHTML = prevHtml;
            if (t !== 'ok') { alert('Rotate failed: ' + t); return; }
            // Cache-bust both the thumbnail src AND the open-in-new-tab href
            // so clicking after a rotate doesn't reopen the stale orientation.
            var cb  = '?_=' + Date.now();
            var img = card.querySelector('img');
            if (img) img.src = (img.src || rel).split('?')[0] + cb;
            var link = card.querySelector('a.cell-img-wrap');
            if (link) link.href = (link.href || rel).split('?')[0] + cb;
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = prevHtml;
            alert('Network error.');
        });
}

// Toggle "skip" state on a single file. Renames it with/without a leading
// underscore — visible in the file manager either way, but excluded from
// the slideshow scan when prefixed. After toggle, the page reloads so the
// cell appears or disappears according to the "show skipped" mode.
function cellSkipToggle(btn) {
    var card = btn.closest('.cell, .vl-row.vl-image');
    if (!card || !card.dataset.abs) return;
    var oldName = card.dataset.name;
    var oldRel  = card.dataset.rel || '';
    var oldAbs  = card.dataset.abs;
    // Skip is reversible (renames in place); no password gate.
    btn.disabled = true;
    var fd = new FormData();
    fd.append('token', csrfToken);
    fd.append('path',  oldAbs);
    fetch('util/toggle_skip.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (!d.success) { alert('Failed: ' + (d.error || 'unknown')); return; }
            applySkipInPlace(d, oldName, oldRel, oldAbs);
        })
        .catch(function() { btn.disabled = false; alert('Network error.'); });
}

// In-place update for a single skip-toggle response — no page reload, so
// the current selection survives. Updates BOTH the grid cell AND the list
// row for the same image (whichever are in the DOM).
function applySkipInPlace(d, oldName, oldRel, oldAbs) {
    var newName = d.newName;
    var newRel  = oldRel.replace(/[^/]+$/, newName);
    var newAbs  = oldAbs.replace(/[^/]+$/, newName);
    var sel = (window.CSS && CSS.escape) ? CSS.escape(oldName) : oldName;
    document.querySelectorAll('[data-name="' + sel + '"]').forEach(function(el) {
        if (!el.classList.contains('cell') && !el.classList.contains('vl-row')) return;
        // Identity attributes follow the rename.
        el.dataset.name    = newName;
        el.dataset.rel     = newRel;
        el.dataset.abs     = newAbs;
        el.dataset.skipped = d.skipped ? '1' : '0';
        el.classList.toggle('skipped',    d.skipped);
        el.classList.toggle('vl-skipped', d.skipped);

        var cb = '?_=' + Date.now();
        // Refresh thumbnail src + open-link href so they don't 404.
        el.querySelectorAll('img').forEach(function(img) {
            var bare = (img.getAttribute('src') || '').split('?')[0];
            img.src = bare.replace(/[^/]+$/, newName) + cb;
        });
        el.querySelectorAll('a.cell-img-wrap, a.vl-thumb-link').forEach(function(a) {
            var bare = (a.getAttribute('href') || '').split('?')[0];
            a.href = bare.replace(/[^/]+$/, newName) + cb;
            if (a.dataset.previewSrc) {
                a.dataset.previewSrc = bare.replace(/[^/]+$/, newName) + cb;
            }
        });
        // Crop links carry the rel path in the query string.
        el.querySelectorAll('a.quick-crop, a.vl-act[href*="crop.php"]').forEach(function(a) {
            a.href = 'pages/crop.php?path=' + encodeURIComponent(newRel)
                   + '&return=' + encodeURIComponent(location.href);
        });
        // Skip button icon + title flip.
        el.querySelectorAll('button.quick-skip, .vl-actions button[onclick*="cellSkipToggle"]').forEach(function(b) {
            var icon = b.querySelector('i');
            if (icon) icon.className = 'fa ' + (d.skipped ? 'fa-eye' : 'fa-eye-slash');
            b.title = d.skipped ? 'Unskip — include in slideshow again'
                                : 'Skip from slideshow';
        });
        // Filename label in grid cell-info.
        var nameSpan = el.querySelector('.cell-info .name');
        if (nameSpan) { nameSpan.textContent = newName; nameSpan.title = newName; }
        // Filename label in list view (last text node after the thumbnail).
        var listLink = el.querySelector('.vl-name a');
        if (listLink) {
            listLink.title = newName;
            var img = listLink.querySelector('img');
            if (img && img.nextSibling && img.nextSibling.nodeType === Node.TEXT_NODE) {
                img.nextSibling.textContent = ' ' + newName;
            }
        }
    });
    // Selection state keyed by name — migrate the entry if present.
    if (selected.has(oldName)) { selected.delete(oldName); selected.add(newName); }

    // Add/remove the skip badge (grid) and SKIP tag (list) to match state.
    document.querySelectorAll('[data-name="' + (window.CSS && CSS.escape ? CSS.escape(newName) : newName) + '"].cell').forEach(function(el) {
        var badge = el.querySelector('.skipped-badge');
        if (d.skipped && !badge) {
            badge = document.createElement('span');
            badge.className = 'skipped-badge';
            badge.textContent = 'SKIPPED';
            el.appendChild(badge);
        } else if (!d.skipped && badge) {
            badge.remove();
        }
    });
    document.querySelectorAll('[data-name="' + (window.CSS && CSS.escape ? CSS.escape(newName) : newName) + '"].vl-row').forEach(function(el) {
        var nm  = el.querySelector('.vl-name');
        if (!nm) return;
        var tag = nm.querySelector('.vl-tag:not(.vl-tag-star)');
        if (d.skipped && !tag) {
            tag = document.createElement('span');
            tag.className = 'vl-tag';
            tag.textContent = 'SKIP';
            nm.appendChild(tag);
        } else if (!d.skipped && tag) {
            tag.remove();
        }
    });
    // Hide the cell/row when the current filter mode wouldn't show it after
    // the toggle. Matches manage.php's PHP-side filter and gives the
    // user the same "disappear on action" feedback as Delete provides.
    var nowHidden = false;
    if (showSkippedMode === 'off'  &&  d.skipped) nowHidden = true;
    if (showSkippedMode === 'only' && !d.skipped) nowHidden = true;
    if (nowHidden) {
        document.querySelectorAll('[data-name="' + (window.CSS && CSS.escape ? CSS.escape(newName) : newName) + '"]').forEach(function(el) {
            if (el.classList.contains('cell') || el.classList.contains('vl-row')) {
                el.style.display = 'none';
            }
        });
        // Drop from selection if present — can't act on something off-screen.
        if (selected.has(newName)) selected.delete(newName);
        refreshBulkBar();
    }

    // Update the folder-img-count caption: shift one image between active
    // and skipped based on the new state. Skipped wrap hides at zero.
    var cap = document.querySelector('.folder-img-count');
    if (cap) {
        var active  = parseInt(cap.dataset.active  || '0', 10);
        var skipped = parseInt(cap.dataset.skipped || '0', 10);
        if (d.skipped) { active--; skipped++; }
        else           { active++; skipped--; }
        if (active  < 0) active  = 0;
        if (skipped < 0) skipped = 0;
        cap.dataset.active  = active;
        cap.dataset.skipped = skipped;
        var aEl = cap.querySelector('.fic-active');
        var aLb = cap.querySelector('.fic-active-label');
        var sEl = cap.querySelector('.fic-skipped');
        var sWp = cap.querySelector('.fic-skipped-wrap');
        if (aEl) aEl.textContent = active;
        if (aLb) aLb.textContent = active === 1 ? ' image' : ' images';
        if (sEl) sEl.textContent = skipped;
        if (sWp) sWp.style.display = skipped > 0 ? '' : 'none';
    }

    if (typeof toast === 'function') toast(d.skipped ? 'Skipped' : 'Unskipped');
}

// Star toggle — appends/removes "~star" before the extension. Renames the
// file in place; updates the cell in-place (no reload) so the user can star
// many images in a row without scroll/context loss.
function cellStarToggle(btn) {
    var card = btn.closest('.cell, .vl-row.vl-image');
    if (!card || !card.dataset.abs) return;
    var oldRel = card.dataset.rel || '';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('token', csrfToken);
    fd.append('path',  card.dataset.abs);
    fetch('util/toggle_star.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (!d.success) { alert('Failed: ' + (d.error || 'unknown')); return; }

            // Swap the identifying attributes to the new file path.
            card.dataset.abs = d.newPath;
            var newRel = oldRel.replace(/[^/]+$/, d.newName);
            card.dataset.rel = newRel;

            // Visual state — star icon + cell ring.
            card.classList.toggle('starred', d.starred);
            btn.classList.toggle('active',   d.starred);
            btn.dataset.starred = d.starred ? '1' : '0';
            btn.title = d.starred ? 'Unstar' : 'Star this image';
            var i = btn.querySelector('i');
            if (i) i.className = 'fa ' + (d.starred ? 'fa-star' : 'fa-star-o');

            // Refresh URLs that embed the filename: thumbnail src, open-link
            // href, and the Crop link. Without this they'd 404 on the next click.
            var cb  = '?_=' + Date.now();
            var img = card.querySelector('img');
            if (img) img.src = newRel + cb;
            var link = card.querySelector('a.cell-img-wrap');
            if (link) link.href = newRel + cb;
            var cropLink = card.querySelector('a.quick-crop');
            if (cropLink) {
                cropLink.href = 'pages/crop.php?path=' + encodeURIComponent(newRel)
                              + '&return=' + encodeURIComponent(location.href);
            }
        })
        .catch(function() { btn.disabled = false; alert('Network error.'); });
}

// Per-cell quick action — trash (in normal mode) or restore (in trash mode).
function cellTrashAction(btn) {
    var card = btn.closest('.cell, .vl-row.vl-image');
    if (!card || !card.dataset.abs) return;
    var path = card.dataset.abs;
    var name = card.dataset.name || 'this image';
    var inTrash = <?php echo $inTrash ? 'true' : 'false' ?>;

    if (inTrash) {
        if (!requireActionPassword('restoring "' + name + '"')) return;
        trashFetch('util/restore_from_trash.php', [path])
            .then(function(d) { if (d.success || d.restored > 0) card.style.display = 'none'; else alert('Restore failed.'); })
            .catch(function() { alert('Network error.'); });
    } else {
        if (!requireActionPassword('moving "' + name + '" to trash')) return;
        trashFetch('util/send_to_trash.php', [path])
            .then(function(d) { if (d.success || d.moved > 0) card.style.display = 'none'; else alert('Move to trash failed.'); })
            .catch(function() { alert('Network error.'); });
    }
}

// ── Copy/Move folder browser (modal) — uses manage.php's ajax_folders endpoint ─
const APP_NAME_JS = <?php echo json_encode($appName) ?>;
let cmCurrentPath = '';

function cmOpenModal(files) {
    cmSelectedFiles = files;            // shadow array; actual submit pulls from `selected`
    cmCurrentPath = folderPath;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('copyMoveModal')).show();
    cmLoadFolders(cmCurrentPath);
}

function cmLoadFolders(path) {
    const list = document.getElementById('cm-folder-list');
    list.innerHTML = '<div style="padding:20px;text-align:center;color:#aaa"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';
    fetch('manage_ops.php?ajax_folders=1&path=' + encodeURIComponent(path))
        .then(r => r.json())
        .then(data => {
            if (data.error) { list.innerHTML = '<div style="padding:16px;color:red">' + data.error + '</div>'; return; }
            cmCurrentPath = data.path;
            cmUpdateBreadcrumb(data.path);
            let html = '';
            if (data.parent !== null && data.parent !== undefined) {
                html += '<div class="cm-row cm-up" onclick="cmLoadFolders(\'' + cmEscJs(data.parent) + '\')">'
                      + '<i class="fa fa-arrow-up" style="color:#999"></i>'
                      + '<span style="color:#555">..</span></div>';
            }
            if (data.folders.length === 0) {
                html += '<div style="padding:14px 16px;color:#bbb;font-style:italic;font-size:13px">No subfolders here</div>';
            }
            data.folders.forEach(folder => {
                const fp = (data.path ? data.path + '/' : '') + folder;
                html += '<div class="cm-row" onclick="cmLoadFolders(\'' + cmEscJs(fp) + '\')">'
                      + '<i class="fa fa-folder-o" style="color:#0157b3"></i>'
                      + '<span>' + cmEscHtml(folder) + '</span>'
                      + '<i class="fa fa-chevron-right" style="margin-left:auto;color:#ccc;font-size:11px"></i>'
                      + '</div>';
            });
            list.innerHTML = html;
        })
        .catch(() => { list.innerHTML = '<div style="padding:16px;color:red">Error loading folders.</div>'; });
}

function cmUpdateBreadcrumb(path) {
    const imgRoot  = APP_NAME_JS + '/images';
    const relative = path.startsWith(imgRoot) ? path.slice(imgRoot.length).replace(/^\//, '') : path;
    const parts    = relative ? relative.split('/') : [];
    let builtPath  = imgRoot;
    let html = '<a href="#" onclick="cmLoadFolders(\'' + cmEscJs(imgRoot) + '\');return false;" style="color:#007bff">images</a>';
    parts.forEach(part => {
        builtPath += '/' + part;
        const bp = builtPath;
        html += ' <i class="fa fa-caret-right" style="color:#ccc"></i> '
              + '<a href="#" onclick="cmLoadFolders(\'' + cmEscJs(bp) + '\');return false;" style="color:#333">'
              + cmEscHtml(part) + '</a>';
    });
    document.getElementById('cm-breadcrumb').innerHTML = html;
}

// Posts the copy/move form to manage.php. `names` is an array of filenames
// (relative to the current folder); `dest` is the destination folder path
// (the same format used by ajax_folders, e.g. "time_machine/images/foo").
// Shared by cmSubmit (modal browser) and the drag-drop handler below.
function submitCopyMove(names, dest, move) {
    if (!names || names.length === 0) { alert('No files selected.'); return; }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'manage_ops.php?p=' + encodeURIComponent(folderPath);
    names.forEach(name => {
        const i = document.createElement('input');
        i.type='hidden'; i.name='file[]'; i.value=name;
        form.appendChild(i);
    });
    const fields = [
        ['copy_to', dest],
        ['finish',  '1'],
        ['token',   csrfToken],
        ['return',  returnUrl],
    ];
    if (move) fields.push(['move', '1']);
    fields.forEach(([k, v]) => {
        const i = document.createElement('input');
        i.type='hidden'; i.name=k; i.value=v;
        form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}

function cmSubmit(move) {
    submitCopyMove(selectedNamesCombined(), cmCurrentPath, move);
}

function cmEscJs(s)   { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function cmEscHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('cm-copy-btn').addEventListener('click', () => cmSubmit(false));
    document.getElementById('cm-move-btn').addEventListener('click', () => cmSubmit(true));
    initViewModeToggle();
    initFolderSidebar();
    initDragSelectAndDrop();
    initVlRowActions();
    initGridFolderSearch();
});

// ── Folder search (grid view) — swaps the content container with library-
// wide folder match tiles. Esc / empty / clear button restores the
// original view. Mirrors manage.php's search but with tile output instead
// of table rows.
let _gridSearchSeq = 0;
function clearGridSearch() {
    const input = document.getElementById('folderSearchInput');
    if (input) input.value = '';
    const sr = document.getElementById('searchResults');
    if (sr) sr.style.display = 'none';
    document.querySelectorAll('.view-grid, .view-list').forEach(el => el.style.removeProperty('display'));
}
function initGridFolderSearch() {
    const input = document.getElementById('folderSearchInput');
    if (!input) return;
    const sr   = document.getElementById('searchResults');
    const body = document.getElementById('searchResultsBody');
    const titleEl = document.getElementById('searchResultsTitle');
    if (!sr || !body) return;
    let timer = null;

    function showOriginal() {
        sr.style.display = 'none';
        document.querySelectorAll('.view-grid, .view-list').forEach(el => el.style.removeProperty('display'));
    }
    function showSearch() {
        sr.style.display = 'block';
        document.querySelectorAll('.view-grid, .view-list').forEach(el => el.style.display = 'none');
    }
    function run(q) {
        if (q.length < 2) { showOriginal(); return; }
        const mySeq = ++_gridSearchSeq;
        titleEl.textContent = 'Searching for "' + q + '"…';
        showSearch();
        fetch('api/search_folders.php?fmt=tiles&q=' + encodeURIComponent(q))
            .then(r => r.text())
            .then(html => {
                if (mySeq !== _gridSearchSeq) return;   // a newer request superseded
                titleEl.textContent = 'Search results for "' + q + '"';
                if (!html.trim()) {
                    body.innerHTML = '<div class="search-no-results">No folders match "' + q.replace(/[<>&]/g, '') + '".</div>';
                    return;
                }
                body.innerHTML = html;
            })
            .catch(() => {
                if (typeof toast === 'function') toast('Search failed.');
                showOriginal();
            });
    }
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => run(input.value.trim()), 250);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') { e.preventDefault(); clearGridSearch(); input.blur(); }
    });

    // Hover pencil on each result row → rename. Mirrors the sidebar's
    // behaviour using the shared top-level doSidebarRenameFromVl helper.
    sr.addEventListener('click', (ev) => {
        const renameBtn = ev.target.closest('.ft-act-rename');
        if (!renameBtn) return;
        ev.preventDefault();
        ev.stopPropagation();
        const wrap = renameBtn.closest('.ft-row-actions');
        if (!wrap) return;
        doSidebarRenameFromVl(wrap.dataset.name, wrap.dataset.parent);
    });
}

// ── View-mode toggle (Grid ↔ List inside the same content container) ──────
// Default 'grid'; persisted in localStorage. Toggling only flips the body
// class — both layouts are server-rendered, CSS hides the inactive one.
function initViewModeToggle() {
    const KEY = 'gridViewMode';
    let mode = localStorage.getItem(KEY) === 'list' ? 'list' : 'grid';
    function apply(m) {
        mode = m;
        document.body.classList.toggle('view-mode-grid', m === 'grid');
        document.body.classList.toggle('view-mode-list', m === 'list');
        document.querySelectorAll('.vm-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.mode === m);
        });
        try { localStorage.setItem(KEY, m); } catch (_) {}
    }
    apply(mode);
    document.querySelectorAll('.vm-btn').forEach(b => {
        b.addEventListener('click', () => apply(b.dataset.mode));
    });
}

// ── List-view row actions (Rename + Delete on hover, folder rows only) ────
// Reuses the same handlers wired up for the sidebar — same prompt/confirm
// flow, same backend, same reload. Also wires up the hover image preview.
function initVlRowActions() {
    const list = document.querySelector('.view-list');
    if (!list) return;
    list.addEventListener('click', (ev) => {
        const renameBtn = ev.target.closest('.vl-act-rename');
        const deleteBtn = ev.target.closest('.vl-act-delete');
        if (!renameBtn && !deleteBtn) return;
        ev.preventDefault();
        ev.stopPropagation();
        const wrap = (renameBtn || deleteBtn).closest('.vl-hover-actions');
        if (!wrap) return;
        const name   = wrap.dataset.name;
        const parent = wrap.dataset.parent;
        if (renameBtn) doSidebarRenameFromVl(name, parent);
        else           doSidebarDeleteFromVl(name, parent);
    });

    // Floating preview popup on thumbnail hover — same idea as manage.php's
    // previewImage plugin, but vanilla and scoped to list-view rows.
    const preview = document.createElement('div');
    preview.id = 'vlHoverPreview';
    preview.innerHTML = '<img alt="">';
    document.body.appendChild(preview);
    const previewImg = preview.querySelector('img');

    function positionPreview(ev) {
        const pw = preview.offsetWidth  || 320;
        const ph = preview.offsetHeight || 320;
        let x = ev.clientX + 20;
        let y = ev.clientY + 20;
        if (x + pw > window.innerWidth)  x = ev.clientX - pw - 20;
        if (y + ph > window.innerHeight) y = ev.clientY - ph - 20;
        preview.style.left = Math.max(4, x) + 'px';
        preview.style.top  = Math.max(4, y) + 'px';
    }
    list.addEventListener('mouseover', (ev) => {
        const link = ev.target.closest('.vl-thumb-link');
        if (!link || !link.dataset.previewSrc) return;
        previewImg.src = link.dataset.previewSrc;
        preview.style.display = 'block';
        positionPreview(ev);
    });
    list.addEventListener('mousemove', (ev) => {
        if (preview.style.display === 'block') positionPreview(ev);
    });
    list.addEventListener('mouseout', (ev) => {
        const link = ev.target.closest('.vl-thumb-link');
        const into = ev.relatedTarget ? ev.relatedTarget.closest('.vl-thumb-link') : null;
        if (link && !into) preview.style.display = 'none';
    });
}
function doSidebarRenameFromVl(name, parent) {
    const newName = prompt('Rename folder "' + name + '" to:', name);
    if (!newName || newName === name) return;
    const fd = new FormData();
    fd.append('rename_from', name);
    fd.append('rename_to',   newName);
    fd.append('rename_path', parent);
    fd.append('token',       window.csrf);
    fd.append('ajax',        '1');
    fetch('manage_ops.php?p=' + encodeURIComponent(parent), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            toast(data.msg || (data.success ? 'Renamed.' : 'Rename failed.'));
            if (data.success) location.reload();
        })
        .catch(() => toast('Rename failed.'));
}
function doSidebarDeleteFromVl(name, parent) {
    if (!confirm('Permanently delete folder "' + name + '" and ALL its contents?\nThis cannot be undone.')) return;
    const fd = new FormData();
    fd.append('token', window.csrf);
    fd.append('ajax',  '1');
    fetch('manage_ops.php?p=' + encodeURIComponent(parent) + '&del=' + encodeURIComponent(name) + '&ajax=1',
          { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            toast(data.msg || (data.success ? 'Deleted.' : 'Delete failed.'));
            if (data.success) location.reload();
        })
        .catch(() => toast('Delete failed.'));
}

// ── Folder sidebar: chevron expand/collapse + full sidebar collapse ────────
// Expansion is persisted in localStorage keyed by folder path. On load we
// overlay stored state on the server-rendered defaults, but ancestors of
// the current folder are always forced open so you can see where you are.
function initFolderSidebar() {
    const sidebar = document.getElementById('folderSidebar');
    if (!sidebar) return;

    const OPEN_KEY = 'gridSidebarOpen';      // array of paths user explicitly opened
    const CLOSED_KEY = 'gridSidebarClosed';  // array of paths user explicitly closed
    const COLLAPSE_KEY = 'gridSidebarCollapsed';

    const loadSet = (k) => { try { return new Set(JSON.parse(localStorage.getItem(k) || '[]')); } catch (_) { return new Set(); } };
    const saveSet = (k, s) => { try { localStorage.setItem(k, JSON.stringify(Array.from(s))); } catch (_) {} };

    const opened = loadSet(OPEN_KEY);
    const closed = loadSet(CLOSED_KEY);

    // Build the ancestor chain of the current folder so we can force-open it.
    const ancestors = new Set();
    if (typeof folderPath === 'string' && folderPath) {
        const parts = folderPath.split('/');
        for (let i = parts.length; i > 0; i--) {
            ancestors.add(parts.slice(0, i).join('/'));
        }
    }

    sidebar.querySelectorAll('li.ft-node').forEach(li => {
        const path = li.dataset.path;
        if (!path) return;
        if (ancestors.has(path)) {
            li.classList.remove('ft-collapsed');         // force-open ancestor
        } else if (opened.has(path)) {
            li.classList.remove('ft-collapsed');
        } else if (closed.has(path)) {
            li.classList.add('ft-collapsed');
        }
    });

    // Scroll the current row into view if it's off-screen.
    const cur = sidebar.querySelector('.ft-row.ft-current');
    if (cur) {
        const r = cur.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight) {
            cur.scrollIntoView({ block: 'center' });
        }
    }

    // Chevron toggles. Click the chevron only — clicking the folder name
    // still navigates via its <a href>.
    sidebar.addEventListener('click', (ev) => {
        const chev = ev.target.closest('.ft-chev');
        if (!chev || chev.classList.contains('ft-chev-blank')) return;
        ev.preventDefault();
        ev.stopPropagation();
        const li = chev.closest('li.ft-node');
        if (!li) return;
        const path = li.dataset.path;
        const nowCollapsed = !li.classList.contains('ft-collapsed');
        li.classList.toggle('ft-collapsed', nowCollapsed);
        if (nowCollapsed) {
            opened.delete(path); closed.add(path);
        } else {
            closed.delete(path); opened.add(path);
        }
        saveSet(OPEN_KEY, opened);
        saveSet(CLOSED_KEY, closed);
    });

    // Rename-on-hover. Plain prompt() for v1; the wrapper element makes a
    // future ⋮-popover upgrade a CSS/markup move with no handler changes.
    sidebar.addEventListener('click', (ev) => {
        const renameBtn = ev.target.closest('.ft-act-rename');
        if (!renameBtn) return;
        ev.preventDefault();
        ev.stopPropagation();
        const wrap = renameBtn.closest('.ft-row-actions');
        if (!wrap) return;
        doSidebarRename(wrap.dataset.name, wrap.dataset.parent);
    });

    function doSidebarRename(name, parent) {
        const newName = prompt('Rename folder "' + name + '" to:', name);
        if (!newName || newName === name) return;
        const fd = new FormData();
        fd.append('rename_from', name);
        fd.append('rename_to',   newName);
        fd.append('rename_path', parent);
        fd.append('token',       window.csrf);
        fd.append('ajax',        '1');
        fetch('manage_ops.php?p=' + encodeURIComponent(parent), { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                toast(data.msg || (data.success ? 'Renamed.' : 'Rename failed.'));
                if (data.success) location.reload();
            })
            .catch(() => toast('Rename failed.'));
    }

    // Drawer open/close + swipe/Esc/resize/nav-click handlers live in
    // mgr_render_shared_js() now — shared across grid view, trash, and
    // starred pages. Nothing grid-specific to add here.

    // Whole-sidebar collapse toggle.
    const toggleBtn = document.getElementById('ftToggleBtn');
    if (toggleBtn) {
        if (localStorage.getItem(COLLAPSE_KEY) === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
        toggleBtn.addEventListener('click', () => {
            const collapsed = document.body.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0'); } catch (_) {}
        });
    }
}

// ── Drag-rectangle selection + drop-on-folder move/copy ────────────────────
// Desktop: pointerdown on the grid background starts marquee on >5px movement.
// Touch: long-press (~400ms) on the grid initiates marquee, since plain touch
//        drag should still scroll the page. While dragging, drop-target chips
//        light up; releasing over one opens a confirm modal (Move/Copy/Cancel).
function initDragSelectAndDrop() {
    // Listen on .container-grid (wraps both folder chips AND image grid) so
    // marquee can be initiated from anywhere in that block — including the
    // area above the first row of images, between chips, or below the grid.
    const containerEl = document.querySelector('.container-grid');
    const gridEl      = document.querySelector('.grid');
    if (!containerEl) return;

    const rectEl     = document.getElementById('dragRect');
    // .drop-target matches BOTH the top folder chips AND the left-sidebar
    // tree rows — they share the class so this handler covers both surfaces.
    const dropChips  = () => document.querySelectorAll('.drop-target');
    const DRAG_THRESHOLD = 5;        // px before mouse pointerdown becomes a drag
    const LONG_PRESS_MS  = 400;      // touch long-press to initiate drag
    const EDGE_SCROLL_PX = 40;       // auto-scroll when cursor near viewport edge

    // Floating badge shown while dragging an existing selection toward a folder.
    const ghostEl = document.createElement('div');
    ghostEl.id = 'dragGhost';
    ghostEl.style.cssText =
        'position:fixed;pointer-events:none;z-index:10000;display:none;' +
        'background:#1a73e8;color:#fff;padding:5px 11px;border-radius:14px;' +
        'font-size:12px;font-weight:600;box-shadow:0 3px 10px rgba(0,0,0,.35);' +
        'transform:translate(14px,14px);white-space:nowrap;';
    document.body.appendChild(ghostEl);

    let startX = 0, startY = 0;            // pointerdown coords (page-relative)
    let isDragging = false;
    let armed      = false;                // pointer is down but not yet a drag
    let armedOnSelectedCell = false;       // pointerdown landed on a .cell.selected
    let dragMode   = null;                 // 'marquee' | 'dragdrop' once drag begins
    let pointerId  = null;
    let longPressTimer = null;
    let baseSelection  = null;             // selection snapshot when marquee started
    let scrollRaf  = null;
    let lastPointerEvent = null;
    let suppressNextClick = false;

    // Targets that have their own meaningful click behavior — pointerdown on
    // these should NOT arm marquee/drag. Cells AND folder tiles are NOT
    // listed: marquee can start over a thumbnail / tile, drag-to-drop can
    // start from a selected one, and a plain click without drag still falls
    // through to the <a>'s navigation (handled by suppressNextClick after
    // drags). The .check-overlay inside both IS listed, so the per-item
    // select buttons keep working without arming a drag.
    function isInteractive(target) {
        if (!target) return false;
        return !!target.closest('button, .check-overlay, input, select, textarea');
    }

    // startX/startY are in DOCUMENT coordinates (viewport + scrollY) so the
    // marquee's start edge stays glued to the page even when auto-scroll
    // moves the viewport — letting the rectangle GROW instead of slide.
    function setRect(x1Doc, y1Doc, x2Doc, y2Doc) {
        const left = Math.min(x1Doc, x2Doc);
        const top  = Math.min(y1Doc, y2Doc);
        const w = Math.abs(x2Doc - x1Doc), h = Math.abs(y2Doc - y1Doc);
        // Convert top to viewport for the position:fixed rectangle. May be
        // negative once the start point has scrolled off above the viewport;
        // that's fine — the visible portion is clipped naturally.
        rectEl.style.left   = left + 'px';
        rectEl.style.top    = (top - window.scrollY) + 'px';
        rectEl.style.width  = w + 'px';
        rectEl.style.height = h + 'px';
    }

    function rectsIntersect(a, b) {
        return !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom);
    }

    // Cursor args come in as viewport coords; converted to document coords
    // here so hit-testing against (also-converted) cell rects is stable
    // across scrolls.
    function updateMarqueeSelection(curViewX, curViewY, additive) {
        const sy = window.scrollY;
        const curDocX = curViewX;
        const curDocY = curViewY + sy;
        const marquee = {
            left:   Math.min(startX, curDocX),
            right:  Math.max(startX, curDocX),
            top:    Math.min(startY, curDocY),
            bottom: Math.max(startY, curDocY),
        };
        setRect(startX, startY, curDocX, curDocY);
        // Diff against baseSelection snapshots for both Sets so additive
        // (Shift-drag) preserves prior selections of either kind.
        const baseImg = (additive && baseSelection.img)    ? baseSelection.img    : new Set();
        const baseFol = (additive && baseSelection.folder) ? baseSelection.folder : new Set();
        const wantImg = new Set(baseImg);
        const wantFol = new Set(baseFol);

        document.querySelectorAll('.cell').forEach(cell => {
            const r = cell.getBoundingClientRect();
            const box = { left:r.left, right:r.right, top:r.top + sy, bottom:r.bottom + sy };
            if (rectsIntersect(marquee, box)) wantImg.add(cell.dataset.name);
        });
        document.querySelectorAll('.subfolder-chip').forEach(chip => {
            if (!chip.dataset.name) return;
            const r = chip.getBoundingClientRect();
            const box = { left:r.left, right:r.right, top:r.top + sy, bottom:r.bottom + sy };
            if (rectsIntersect(marquee, box)) wantFol.add(chip.dataset.name);
        });

        // Apply diffs for both kinds.
        document.querySelectorAll('.cell').forEach(cell => {
            const name = cell.dataset.name;
            const want = wantImg.has(name);
            const has  = selected.has(name);
            if (want && !has) { selected.add(name);    cell.classList.add('selected'); }
            else if (!want && has) { selected.delete(name); cell.classList.remove('selected'); }
        });
        document.querySelectorAll('.subfolder-chip').forEach(chip => {
            const name = chip.dataset.name;
            if (!name) return;
            const want = wantFol.has(name);
            const has  = selectedFolders.has(name);
            if (want && !has) { selectedFolders.add(name);    chip.classList.add('selected'); }
            else if (!want && has) { selectedFolders.delete(name); chip.classList.remove('selected'); }
        });
        refreshBulkBar();
    }

    function updateDropTargetVisuals(show) {
        // Google-Drive-style behavior: don't light up every potential drop
        // target during a drag (too noisy on screens with many folders).
        // Only the chip the cursor is currently over gets a highlight —
        // applied later in pointermove via the .drop-hover class. Here we
        // just make sure both classes are cleared when the drag ends.
        if (!show) {
            dropChips().forEach(chip => {
                chip.classList.remove('drop-ready');
                chip.classList.remove('drop-hover');
            });
        }
    }

    function chipUnderPoint(x, y) {
        const el = document.elementFromPoint(x, y);
        return el ? el.closest('.drop-target') : null;
    }

    // Continuous auto-scroll while pointer is near top/bottom of viewport.
    function autoScrollLoop() {
        scrollRaf = null;
        if (!isDragging || !lastPointerEvent) return;
        const y = lastPointerEvent.clientY;
        const vh = window.innerHeight;
        let dy = 0;
        if (y < EDGE_SCROLL_PX)        dy = -Math.ceil((EDGE_SCROLL_PX - y) / 3);
        else if (y > vh - EDGE_SCROLL_PX) dy = Math.ceil((y - (vh - EDGE_SCROLL_PX)) / 3);
        if (dy !== 0) {
            window.scrollBy(0, dy);
            // After scrolling, re-evaluate selection at the new pointer position
            // because cell rects have shifted.
            updateMarqueeSelection(
                lastPointerEvent.clientX,
                lastPointerEvent.clientY,
                lastPointerEvent.shiftKey
            );
        }
        scrollRaf = requestAnimationFrame(autoScrollLoop);
    }

    function startDrag(ev) {
        isDragging = true;
        armed = false;
        document.body.classList.add('dragging-select');
        if (armedOnSelectedCell) {
            // Drag-to-drop: don't touch the selection, just show a ghost badge.
            dragMode = 'dragdrop';
            ghostEl.textContent = ghostBadgeText();
            ghostEl.style.left = ev.clientX + 'px';
            ghostEl.style.top  = ev.clientY + 'px';
            ghostEl.style.display = 'block';
        } else {
            // Marquee: paint a rectangle and grow the selection as it sweeps.
            dragMode = 'marquee';
            rectEl.style.display = 'block';
            setRect(startX, startY, startX, startY);
        }
        updateDropTargetVisuals(true);
        if (!scrollRaf) scrollRaf = requestAnimationFrame(autoScrollLoop);
    }

    function endDrag(ev, droppedOnChip) {
        if (!isDragging) {
            cancelArm();
            return;
        }
        isDragging = false;
        const mode = dragMode;
        dragMode = null;
        document.body.classList.remove('dragging-select');
        rectEl.style.display = 'none';
        ghostEl.style.display = 'none';
        updateDropTargetVisuals(false);
        if (scrollRaf) { cancelAnimationFrame(scrollRaf); scrollRaf = null; }

        if (droppedOnChip && totalSelected() > 0) {
            openDropConfirm(droppedOnChip);
        }
        // The pointerup that ended a drag would otherwise fire a click on
        // whatever element we released over — suppress one click to avoid
        // accidental navigation (folder chip = <a>, cell-img-wrap = <a>).
        suppressNextClick = true;
        setTimeout(() => { suppressNextClick = false; }, 250);
    }

    function cancelArm() {
        armed = false;
        pointerId = null;
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    }

    // Suppress the browser's native HTML5 drag-and-drop on images and links
    // inside the grid. Without this, pressing on a thumbnail and moving the
    // mouse starts a native image-drag (with a translucent ghost of the
    // image), which steals pointer events from us — so our drop logic
    // would never fire on pointerup.
    containerEl.addEventListener('dragstart', (ev) => {
        if (ev.target.closest('.cell, .subfolder-chip')) ev.preventDefault();
    });

    containerEl.addEventListener('pointerdown', (ev) => {
        if (ev.button !== undefined && ev.button !== 0) return;        // left/primary only
        if (isInteractive(ev.target)) return;                          // let buttons / check-overlays work
        // Landed on something already selected (image cell OR folder tile)?
        // That's the signal to enter drag-to-drop mode instead of starting
        // a new marquee.
        const cellHit = ev.target.closest('.cell');
        const chipHit = ev.target.closest('.subfolder-chip');
        const rowHit  = ev.target.closest('.vl-row');
        const onSelCell = !!(cellHit && cellHit.classList.contains('selected'));
        const onSelChip = !!(chipHit && chipHit.classList.contains('selected'));
        const onSelRow  = !!(rowHit  && rowHit.classList.contains('selected'));
        armedOnSelectedCell = (onSelCell || onSelChip || onSelRow) && totalSelected() > 0;

        baseSelection = { img: new Set(selected), folder: new Set(selectedFolders) };
        // Document coordinates so the start edge stays anchored during scroll.
        startX = ev.clientX;
        startY = ev.clientY + window.scrollY;
        armed = true;
        pointerId = ev.pointerId;
        lastPointerEvent = ev;

        if (ev.pointerType === 'touch') {
            // Touch: wait for long-press so normal taps + scrolls still work.
            longPressTimer = setTimeout(() => {
                longPressTimer = null;
                if (!armed) return;
                if (navigator.vibrate) try { navigator.vibrate(15); } catch (_) {}
                startDrag(ev);
                try { containerEl.setPointerCapture(pointerId); } catch (_) {}
            }, LONG_PRESS_MS);
        }
        // Mouse/pen: don't start until movement exceeds threshold (handled in pointermove).
    });

    document.addEventListener('pointermove', (ev) => {
        if (!armed && !isDragging) return;
        if (pointerId !== null && ev.pointerId !== pointerId) return;
        lastPointerEvent = ev;

        if (armed && !isDragging) {
            // Compare in document space too (startX/Y are document coords).
            const dx = ev.clientX - startX;
            const dy = (ev.clientY + window.scrollY) - startY;
            if (ev.pointerType === 'touch') {
                if (Math.abs(dx) > 8 || Math.abs(dy) > 8) cancelArm();
                return;
            }
            if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
            startDrag(ev);
            try { containerEl.setPointerCapture(pointerId); } catch (_) {}
        }

        if (isDragging) {
            ev.preventDefault();
            if (dragMode === 'marquee') {
                // setRect is called inside updateMarqueeSelection now —
                // single source of truth for the doc-coord math.
                updateMarqueeSelection(ev.clientX, ev.clientY, ev.shiftKey);
            } else {
                ghostEl.style.left = ev.clientX + 'px';
                ghostEl.style.top  = ev.clientY + 'px';
            }
            // Highlight chip under cursor (both modes).
            const chip = chipUnderPoint(ev.clientX, ev.clientY);
            dropChips().forEach(c => {
                c.classList.toggle('drop-hover', c === chip && totalSelected() > 0);
            });
        }
    }, { passive: false });

    document.addEventListener('pointerup', (ev) => {
        if (pointerId !== null && ev.pointerId !== pointerId) return;
        const chip = isDragging ? chipUnderPoint(ev.clientX, ev.clientY) : null;
        endDrag(ev, chip);
        pointerId = null;
    });

    document.addEventListener('pointercancel', () => {
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
        if (isDragging) endDrag(null, null);
        armed = false; pointerId = null;
    });

    // While ANY cell is selected, tapping a photo toggles its selection
    // instead of opening it in a new tab. Tap on the ✓ overlay still works
    // the same. When the selection empties out, this stops intercepting and
    // photo taps open normally again. Runs in the bubble phase so the
    // post-drag click-suppression (capture phase) gets first dibs.
    // When ANY selection is active, a click on a folder tile toggles its
    // selection instead of navigating. Click on the tile's ✓ overlay still
    // works the same. Bound on containerEl since chips live above the grid.
    containerEl.addEventListener('click', (ev) => {
        if (totalSelected() === 0) return;
        if (ev.target.closest('.check-overlay')) return;       // overlay handles its own click
        const chip = ev.target.closest('.subfolder-chip');
        if (!chip || !chip.dataset.name) return;
        ev.preventDefault();
        ev.stopPropagation();
        toggleSubfolder(chip, ev);
    });

    if (gridEl) {
        gridEl.addEventListener('click', (ev) => {
            if (totalSelected() === 0) return;
            const wrap = ev.target.closest('.cell-img-wrap');
            if (!wrap) return;
            const cell = wrap.closest('.cell');
            if (!cell) return;
            ev.preventDefault();
            toggleCell(cell, ev);
        });
    }

    // Two roles:
    //   1. Suppress the click that fires immediately after a drag ends (it
    //      would otherwise navigate the chip <a> or open the cell image).
    //   2. Plain click on empty space (not on a cell, chip, bulk-bar, or
    //      modal) clears the current selection — a quick way to reset.
    document.addEventListener('click', (ev) => {
        if (suppressNextClick) {
            suppressNextClick = false;
            ev.preventDefault();
            ev.stopPropagation();
            return;
        }
        if (totalSelected() === 0) return;
        if (ev.target.closest('.cell, .subfolder-chip, .vl-row, .ft-row, .bulk-bar, .modal, button, input, select, textarea, label, a')) return;
        clearSelection();
    }, true);

    // ── Drop confirm modal wiring ─────────────────────────────────────────
    let pendingDest = null;     // {path, label}
    const modal     = bootstrap.Modal.getOrCreateInstance(document.getElementById('dropConfirmModal'));
    const whatEl    = document.getElementById('dropConfirmWhat');
    const destEl    = document.getElementById('dropConfirmDest');

    function openDropConfirm(chip) {
        const dest = chip.dataset.path;
        if (!dest) return;
        if (dest === folderPath) return;          // dropping on current folder = no-op
        // Self-drop guard — don't allow a selected folder to land on itself.
        if (chip.dataset.name && selectedFolders.has(chip.dataset.name)) return;
        pendingDest = { path: dest, label: chip.dataset.label || dest };
        whatEl.textContent = ghostBadgeText() || (totalSelected() + ' items');
        destEl.textContent = pendingDest.label;
        modal.show();
    }

    document.getElementById('dropMoveBtn').addEventListener('click', () => {
        if (!pendingDest) return;
        const t = totalSelected();
        if (!requireActionPassword('moving ' + t + ' item' + (t!=1?'s':'') + ' to "' + pendingDest.label + '"')) return;
        modal.hide();
        submitCopyMove(selectedNamesCombined(), pendingDest.path, true);
    });
    document.getElementById('dropCopyBtn').addEventListener('click', () => {
        if (!pendingDest) return;
        const t = totalSelected();
        if (!requireActionPassword('copying ' + t + ' item' + (t!=1?'s':'') + ' to "' + pendingDest.label + '"')) return;
        modal.hide();
        submitCopyMove(selectedNamesCombined(), pendingDest.path, false);
    });
}

// Live controls — change URL on adjustment so refresh is meaningful.
function applyControlChange(key, value) {
    const u = new URL(location.href);
    u.searchParams.set(key, value);
    u.searchParams.set('page', '1');
    location.href = u.toString();
}

document.getElementById('sizeSlider').addEventListener('input', function () {
    document.getElementById('sizeVal').textContent = this.value + 'px';
    document.documentElement.style.setProperty('--cell-min', this.value + 'px');
    // Apply visually without reload by tweaking grid-template-columns inline:
    document.querySelectorAll('.grid').forEach(g => {
        g.style.gridTemplateColumns = `repeat(auto-fill, minmax(${this.value}px, 1fr))`;
    });
});
// Cell-size is a pure-CSS preference — the 'input' handler above already
// applies it live. On release, persist the choice via the existing
// grid_size cookie so the next page load picks it up server-side at the
// initial grid-template-columns paint (manage.php:466). No reload needed.
document.getElementById('sizeSlider').addEventListener('change', function () {
    document.cookie = 'grid_size=' + encodeURIComponent(this.value)
                    + '; path=/; max-age=' + (60 * 60 * 24 * 365) + '; SameSite=Lax';
});
document.getElementById('ppSelect').addEventListener('change', function () {
    applyControlChange('pp', this.value);
});
document.getElementById('sortSelect').addEventListener('change', function () {
    applyControlChange('sort', this.value);
});

// (Shared modal JS — setShuffle/setFit/setDate toggle handlers, .fm-remove-pill
//  AJAX listener, sendSystemAction — emitted by mgr_render_shared_js() below.)
</script>
<?php mgr_render_shared_js(); ?>

<!-- Shared modals from manage_util.php (New Folder + Settings + Actions). -->
<?php mgr_render_new_folder_modal(); ?>
<?php mgr_render_settings_modal(); ?>
<?php mgr_render_actions_modal($p, $inTrash); ?>

<!-- Slideshow Modal -->
<div class="modal fade" id="slideshowModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-tv"></i> Slideshow</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" role="tablist">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#sm-active">Active folders</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sm-playlists">Playlists</a></li>
        </ul>
        <div class="tab-content">
          <div id="sm-active" class="tab-pane fade show active">
            <?php displayFileContentsWithRemoveOption_grid(state_file('paths.txt')); ?>
          </div>
          <div id="sm-playlists" class="tab-pane fade">
            <?php displayPlaylists_grid(); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php echo app_credit_html(); ?>
</body>
</html>

<?php
// ─── Selected Folders panel (Slideshow modal, Active tab) ─────────────────────
// Same UI as manage.php's displayFileContentsWithRemoveOption(). The remove
// pill's URL points to manage.php's own ajax=1 handler at the top of
// this file. Save/Clear actions target manage.php (the canonical handler).
function displayFileContentsWithRemoveOption_grid($file_path) {
    global $appName;
    $lines    = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $base     = $_SERVER['DOCUMENT_ROOT'] . "/" . $appName . "/images/";
    $img_exts = ['jpg','jpeg','png','gif','bmp','webp','avif','svg','ico'];

    $folder_count = count($lines);
    $total_images = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if (!is_dir($line)) continue;
        foreach (scandir($line) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $img_exts) && is_file($line . '/' . $f))
                $total_images++;
        }
    }

    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
    echo   '<div style="display:flex;align-items:center;gap:8px">';
    echo     '<b style="font-size:14px">Selected Folders</b>';
    if ($folder_count > 0) {
        echo '<span id="fm-active-counts" data-folders="' . $folder_count . '" data-images="' . $total_images . '" '
           . 'style="background:#6c757d;color:#fff;padding:2px 9px;border-radius:10px;font-size:12px">'
           . $folder_count . ' folder' . ($folder_count != 1 ? 's' : '')
           . ' &middot; ' . $total_images . ' image' . ($total_images != 1 ? 's' : '')
           . '</span>';
    }
    echo   '</div>';
    if ($folder_count > 0) {
        echo '<a href="?clear_file=1&p=' . urlencode($_GET['p'] ?? '') . '" onclick="return confirm(\'Remove all selected folders from the slideshow?\');"'
           . ' style="font-size:12px;color:#aaa;border:1px solid #ddd;padding:2px 8px;border-radius:3px;text-decoration:none">Clear all</a>';
    }
    echo '</div>';

    echo '<div style="font-size:12px;color:#888;margin-bottom:10px">Files inside these folders appear in the slideshow</div>';

    if ($folder_count === 0) {
        echo '<p style="color:#bbb;font-style:italic;margin:6px 0 2px">'
           . '<i class="fa fa-folder-open-o"></i>&nbsp;No folders selected — open any folder and toggle the green switch in the action strip (or in the sidebar tree) to add it.</p>';
        return;
    }

    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
    foreach ($lines as $line) {
        $line     = trim($line);
        $name     = basename($line);
        $relative = str_replace($base, '', $line);
        $img_count = 0;
        if (is_dir($line)) {
            foreach (scandir($line) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $img_exts) && is_file($line . '/' . $f))
                    $img_count++;
            }
        }
        // Remove URL points at THIS page (manage.php) — the local AJAX
        // handler near the top processes it and returns JSON.
        $qp = $_GET;
        $qp['folder_path']        = $line;
        $qp['folder_path_action'] = 'remove';
        $remove_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($qp);

        echo '<span class="fm-active-pill" data-path="' . htmlspecialchars($line) . '" data-imgcount="' . $img_count . '" '
           . 'style="display:inline-flex;align-items:center;gap:6px;background:#e8f5e9;border:1px solid #a5d6a7;padding:5px 10px 5px 9px;border-radius:12px;font-size:13px;color:#2e7d32">';
        echo   '<i class="fa fa-folder" style="color:#43a047;font-size:12px;flex-shrink:0"></i>';
        echo   '<span style="display:flex;flex-direction:column;line-height:1.35">';
        echo     '<span style="font-weight:600">' . htmlspecialchars($name) . '</span>';
        echo     '<span style="font-size:10px;color:#71a878;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . htmlspecialchars($relative) . '">' . htmlspecialchars($relative) . '</span>';
        echo   '</span>';
        if ($img_count > 0) echo '<span style="color:#888;font-size:11px;flex-shrink:0">(' . $img_count . ')</span>';
        echo   '<a class="fm-remove-pill" href="' . htmlspecialchars($remove_url) . '" title="Remove from slideshow" '
           .   'style="color:#e53935;font-weight:bold;font-size:15px;line-height:1;margin-left:2px;text-decoration:none;flex-shrink:0">&times;</a>';
        echo '</span>';
    }
    echo '</div>';
}

// ─── Playlists panel (Slideshow modal, Playlists tab) ────────────────────────
// Read-only view in grid view (Save / Load / Delete are list-view actions).
// Shows what playlists exist; user switches to list view to manage them.
function displayPlaylists_grid() {
    $playlists = (file_exists(state_file('playlists.json')) && ($j = file_get_contents(state_file('playlists.json'))))
        ? json_decode($j, true) : [];
    if (!is_array($playlists)) $playlists = [];

    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
    echo   '<div style="display:flex;align-items:center;gap:8px">';
    echo     '<b style="font-size:14px"><i class="fa fa-bookmark-o" style="color:#888"></i> Saved Playlists</b>';
    if (count($playlists) > 0)
        echo '<span style="background:#6c757d;color:#fff;padding:2px 9px;border-radius:10px;font-size:12px">' . count($playlists) . '</span>';
    echo   '</div>';
    echo '</div>';
    echo '<div style="font-size:12px;color:#888;margin-bottom:10px">Saved folder combinations.</div>';

    if (empty($playlists)) {
        echo '<p style="color:#bbb;font-style:italic;margin:6px 0 2px"><i class="fa fa-bookmark-o"></i>&nbsp;No playlists saved yet.</p>';
        return;
    }
    echo '<div style="display:flex;flex-direction:column;gap:6px">';
    foreach ($playlists as $pl_name => $paths) {
        $names = array_map(fn($pp) => basename(trim($pp)), $paths);
        $subtitle = implode(', ', array_slice($names, 0, 4));
        if (count($names) > 4) $subtitle .= ', …';
        echo '<div style="padding:8px 12px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px">';
        echo   '<div style="font-weight:600;font-size:13px;color:#333">' . htmlspecialchars($pl_name) . '</div>';
        echo   '<div style="font-size:11px;color:#888;margin-top:1px">' . htmlspecialchars($subtitle) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}
?>
