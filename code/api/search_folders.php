<?php
/*
 * api/search_folders.php — folder-name AJAX search.
 *
 * Case-insensitive substring match against every directory under
 * images_root(). Returns HTML ready to drop into the search-results pane
 * of manage.php. Toggle/rename/delete on each row work via the absolute
 * paths embedded in the markup.
 *
 * GET params:
 *   q     — query string (min 2 chars; shorter requests return empty)
 *   limit — optional cap on results (default 200, bounded 10..500)
 *   fmt   — 'tiles' (default for manage.php's grid-view search; emits
 *           sidebar-row-style HTML with per-row slideshow toggles)
 *         OR 'rows' (legacy <tr> rows for the retired list-view table)
 *
 * Response: text/html. First element is a "results truncated" banner if
 * the cap was hit. Skips '_*' and '.*' subtrees during the walk — they
 * never play in the slideshow, so surfacing them in search would mislead.
 */

ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');

include_once __DIR__ . '/../var.php';
include_once __DIR__ . '/../manage_util.php';

define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();
if (!defined('FM_DATETIME_FORMAT')) {
    define('FM_DATETIME_FORMAT', isset($datetime_format) ? $datetime_format : 'd M Y H:i');
}
if (!defined('FM_ROOT_PATH')) {
    define('FM_ROOT_PATH', rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/'));
}

$q     = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = isset($_GET['limit']) ? max(10, min(500, (int)$_GET['limit'])) : 200;
$fmt   = isset($_GET['fmt']) && $_GET['fmt'] === 'tiles' ? 'tiles' : 'rows';

if (strlen($q) < 2) exit;

// Don't insist on realpath() — some hosts (symlinked DocumentRoot, open_basedir)
// return false even for valid dirs. is_dir is enough; we'll work with the
// literal path images_root() gives us.
$root = rtrim(images_root(), '/');
if (!is_dir($root)) {
    echo '<tr><td colspan="6" style="color:#c00;padding:8px">Search root not found: ' . htmlspecialchars($root) . '</td></tr>';
    exit;
}

$existingPaths = file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$existingPaths = array_map(fn($p) => rtrim($p, '/'), $existingPaths);

$matches   = [];
$truncated = false;

// Iterative DFS so we can hard-cap early.
$stack = [$root];
while ($stack && !$truncated) {
    $dir     = array_pop($stack);
    $entries = @scandir($dir);
    if (!$entries) continue;
    foreach ($entries as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.' || $f[0] === '_') continue;
        $full = $dir . '/' . $f;
        if (!is_dir($full)) continue;
        if (stripos($f, $q) !== false) {
            $matches[] = $full;
            if (count($matches) >= $limit) { $truncated = true; break; }
        }
        $stack[] = $full;
    }
}

$out = '';
$rootStrip = rtrim($root, '/') . '/';   // for the user-facing path-hint label

if ($fmt === 'tiles') {
    // Grid view's search-result row format. Each row carries the same
    // class+data-attribute shape as a sidebar tree row, so:
    //   - the shared toggleSlideshowFolder handles add/remove
    //   - refreshSidebarTreeStates re-renders the sidebar's "via parent" /
    //     in-list / descendant-dot state automatically after every toggle
    //   - drag-drop drop-target machinery picks them up via data-path
    if ($truncated) {
        $out .= '<div class="search-results-banner">'
              . 'Showing first ' . count($matches) . ' matches — refine the query to narrow down.'
              . '</div>';
    }
    foreach ($matches as $abs) {
        $folderName     = basename($abs);
        $relFromDocRoot = ltrim(substr($abs, strlen(FM_ROOT_PATH)), '/');
        $hint = '';
        if (strpos(dirname($abs) . '/', $rootStrip) === 0) {
            $rel = substr(dirname($abs), strlen($rootStrip));
            if ($rel !== '') $hint = $rel;
        }
        $skipped = ($folderName[0] === '_');

        // Three-state slideshow status (mirrors sidebar tree render).
        $absTrim = rtrim($abs, '/');
        $inList  = in_array($absTrim, $existingPaths, true);
        $viaParent = null;
        if (!$inList && !$skipped) {
            foreach ($existingPaths as $ep) {
                $ep = rtrim($ep, '/');
                if ($ep !== '' && strpos($absTrim . '/', $ep . '/') === 0) { $viaParent = $ep; break; }
            }
        }

        $out .= '<div class="ft-row sr-row drop-target' . ($skipped ? ' skipped' : '') . '"'
              . ' data-name="'  . htmlspecialchars($folderName) . '"'
              . ' data-abs="'   . htmlspecialchars($abs) . '"'
              . ' data-path="'  . htmlspecialchars($relFromDocRoot) . '"'
              . ' data-label="' . htmlspecialchars($folderName) . '">'
              . '<a class="ft-name" href="?p=' . urlencode($relFromDocRoot) . '" title="' . htmlspecialchars($hint !== '' ? $hint . '/' . $folderName : $folderName) . '">'
              . '<i class="fa fa-folder-o"></i>'
              . '<span class="ft-name-text">' . htmlspecialchars($folderName) . '</span>'
              . ($hint !== '' ? ' <span class="sr-hint">' . htmlspecialchars($hint) . '</span>' : '')
              . '</a>';

        // Hover-revealed pencil — rename, wired up by manage.php's
        // #searchResults click handler. Uses the same .ft-row-actions
        // shell as the sidebar so existing CSS (display:none → inline-flex
        // on row hover) applies automatically. Skipped folders get no
        // button — rename on a skipped folder is rare and slightly fiddly.
        if (!$skipped) {
            // parent must be a doc-root-relative path (e.g.
            // "time_machine/images/foo") to match the sidebar's
            // rename handler, which feeds it into manage_ops.php's `p` param.
            $parentRel = dirname($relFromDocRoot);
            $out .= '<span class="ft-row-actions" '
                  . 'data-name="'   . htmlspecialchars($folderName) . '" '
                  . 'data-parent="' . htmlspecialchars($parentRel) . '" '
                  . 'data-abs="'    . htmlspecialchars($abs) . '">'
                  . '<button type="button" class="ft-act ft-act-rename" tabindex="-1" title="Rename folder">'
                  .   '<i class="fa fa-pencil"></i></button>'
                  . '</span>';
        }

        // Right-aligned tiny toggle — only when not skipped (the add
        // handler rejects skipped folders anyway).
        if (!$skipped) {
            if ($viaParent !== null) {
                $viaTip = 'Included via ' . htmlspecialchars(basename($viaParent), ENT_QUOTES, 'UTF-8')
                        . ' — remove the parent to exclude this folder.';
                $out .= '<label class="fm-toggle fm-toggle-tiny fm-via ft-toggle-cell" title="' . $viaTip . '">'
                      . '<input type="checkbox" data-folder-path="' . htmlspecialchars($abs) . '" checked disabled>'
                      . '<span class="fm-toggle-slider"></span></label>';
            } else {
                $titleAttr = $inList ? 'Remove from slideshow' : 'Add to slideshow';
                $out .= '<label class="fm-toggle fm-toggle-tiny ft-toggle-cell" title="' . $titleAttr . '">'
                      . '<input type="checkbox" data-folder-path="' . htmlspecialchars($abs) . '"'
                      . ($inList ? ' checked' : '')
                      . ' onchange="toggleSlideshowFolder(this)">'
                      . '<span class="fm-toggle-slider"></span></label>';
            }
        }
        $out .= '</div>';
    }
    echo $out;
    exit;
}

// Default — table-row format for manage.php's list view.
if ($truncated) {
    $out .= '<tr><td colspan="6" style="text-align:center;color:#7a5b00;font-style:italic;'
          . 'background:#fff8e1;padding:8px">'
          . 'Showing first ' . count($matches) . ' matches — refine the query to narrow down.'
          . '</td></tr>';
}

$ii = 7700;
foreach ($matches as $abs) {
    $absParent  = dirname($abs);
    $folderName = basename($abs);
    $parentRel  = ltrim(substr($absParent, strlen(FM_ROOT_PATH)), '/');
    $hint = '';
    if (strpos($absParent . '/', $rootStrip) === 0) {
        $rel = substr($absParent, strlen($rootStrip));
        if ($rel !== '') $hint = $rel;
    }
    $out .= render_folder_row($folderName, $parentRel, $absParent, 0, $existingPaths, false, $ii++, $hint);
}

echo $out;
