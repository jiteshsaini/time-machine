<?php
/*
 * pages/starred.php — dedicated starred view.
 *
 * Recursively scans images_root() for files marked with the "~star" suffix
 * (added/removed by util/toggle_star.php). Cards show thumbnail, clean
 * filename, source folder, and time since starred. Grouped by Folder
 * (default) or Date. Optional left-sidebar filter (?folder=<rel>) narrows
 * to one source folder.
 *
 * Per-card actions: ★ Unstar (in place) and 📍 Open folder (jumps to the
 * image's containing folder in manage.php).
 *
 * Backend: util/toggle_star.php (POST path, token).
 */

ini_set('display_errors', '1');
include_once __DIR__ . "/../var.php";
include_once __DIR__ . "/pages_util.php";

define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(16)) : md5(uniqid('', true));
}

$imgRoot = rtrim(images_root(), '/');
$imgExts = ['gif','jpg','jpeg','png','bmp','webp','avif','ico','svg'];

// ── Recursively gather starred image files, skipping _*/.* subtrees. ───────
$entries = [];
$walk = function($dir) use (&$walk, $imgRoot, $imgExts, &$entries) {
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.' || $f[0] === '_') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full)) { $walk($full); continue; }
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $imgExts)) continue;
        if (!is_starred_name($f)) continue;
        $entries[] = [
            'abs'   => $full,
            'rel'   => ltrim(substr($full, strlen($imgRoot)), '/'),
            'name'  => $f,
            'size'  => @filesize($full) ?: 0,
            'mtime' => @filemtime($full) ?: 0,
        ];
    }
};
if (is_dir($imgRoot)) $walk($imgRoot);

// ── Which starred files are already explicitly in the slideshow? Loaded
// early so the ?inslideshow=1 filter (below) and the in-show / not-in-show
// count badges can both use it.
$activeStarred = file_exists(state_file('starred_paths.txt'))
    ? file(state_file('starred_paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$activeSet = array_flip(array_map(fn($p) => rtrim($p, '/'), $activeStarred));

// Counts BEFORE any filter, for the View toggle pill ("All / In slideshow").
$totalAllStarred  = count($entries);
$totalInSlideshow = 0;
foreach ($entries as $e) if (isset($activeSet[$e['abs']])) $totalInSlideshow++;

// ── Optional ?inslideshow=1 filter — narrow to starred files currently
// in the slideshow. Applied BEFORE sidebar build so the folder tree on
// the left reflects only folders that contain in-slideshow starred files.
$inSlideshowFilter = !empty($_GET['inslideshow']);
if ($inSlideshowFilter) {
    $entries = array_values(array_filter($entries, fn($e) => isset($activeSet[$e['abs']])));
}

// ── Sidebar folder index (built from the (possibly filtered) entry list). ──
// Map of folder → ['count','bytes']. Used by the left-sidebar filter list.
$sbFolderIndex = [];
foreach ($entries as $e) {
    $folder = dirname($e['rel']);
    if ($folder === '.' || $folder === '') $folder = '(root)';
    if (!isset($sbFolderIndex[$folder])) $sbFolderIndex[$folder] = ['count' => 0, 'bytes' => 0];
    $sbFolderIndex[$folder]['count']++;
    $sbFolderIndex[$folder]['bytes'] += $e['size'];
}
uksort($sbFolderIndex, function ($a, $b) use ($sbFolderIndex) {
    $cmp = $sbFolderIndex[$b]['count'] <=> $sbFolderIndex[$a]['count'];
    if ($cmp !== 0) return $cmp;
    return strcasecmp($a, $b);
});

// ── Apply optional folder filter from ?folder=<rel>. ───────────────────────
$folderFilter = trim((string)($_GET['folder'] ?? ''));
if ($folderFilter !== '') {
    $needle = rtrim($folderFilter, '/');
    $entries = array_values(array_filter($entries, function ($e) use ($needle) {
        $f = dirname($e['rel']);
        if ($f === '.' || $f === '') $f = '(root)';
        return $f === $needle || strpos($f . '/', $needle . '/') === 0;
    }));
}

$totalCount = count($entries);
$totalBytes = array_sum(array_column($entries, 'size'));

// ($activeStarred / $activeSet loaded earlier — needed before sidebar build.)
// Initial counts here are for the FULL set; we recompute against the visible
// page once pagination is applied (below). The page-level "Add all (N)" and
// "Remove all (N)" badges only ever act on currently-visible cards.

// ── Pagination. ────────────────────────────────────────────────────────────
// Paginate the FLAT entry list first, then bucket whatever made it onto the
// current page. Keeps things fast on huge libraries while still letting
// folder/date grouping work on the visible slice.
$pp   = (int)($_GET['pp']   ?? $_COOKIE['starred_pp']   ?? 96);
if (!in_array($pp, [24, 48, 96, 144, 240, 9999], true)) $pp = 96;
$page = max(1, (int)($_GET['page'] ?? 1));

// Always sort newest-trashed-first for consistent page slicing.
usort($entries, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
$totalPages = $pp >= 9999 ? 1 : max(1, (int)ceil($totalCount / $pp));
if ($page > $totalPages) $page = $totalPages;
$offset = $pp >= 9999 ? 0 : ($page - 1) * $pp;
$pagedEntries = $pp >= 9999 ? $entries : array_slice($entries, $offset, $pp);

setcookie('starred_pp', (string)$pp, time() + 86400 * 365, '/');

// Now recompute the in-show / not-in-show counts against the PAGED slice so
// the page-level Add/Remove buttons reflect just the visible cards.
$notInShowCount = 0;
foreach ($pagedEntries as $e) if (!isset($activeSet[$e['abs']])) $notInShowCount++;

// ── Bucketing. ─────────────────────────────────────────────────────────────
$groupMode = (($_GET['group'] ?? '') === 'date') ? 'date' : 'folder';
$buckets   = [];

if ($groupMode === 'folder') {
    // Group by original parent folder — natural unit for "favorites from this shoot".
    $pagedSorted = $pagedEntries;
    usort($pagedSorted, fn($a, $b) => strcasecmp($a['rel'], $b['rel']));
    foreach ($pagedSorted as $e) {
        $folder = dirname($e['rel']);
        if ($folder === '.' || $folder === '') $folder = '(root)';
        $key = 'f:' . $folder;
        if (!isset($buckets[$key])) {
            $folderRel = ($folder === '(root)') ? $appName . '/images'
                                                : $appName . '/images/' . $folder;
            $buckets[$key] = [
                'label' => $folder,
                'href'  => '../manage.php?p=' . urlencode($folderRel),
                'items' => [],
            ];
        }
        $buckets[$key]['items'][] = $e;
    }
} else {
    $now    = time();
    $today  = strtotime('today');
    $week   = $now - 86400 * 7;
    $month  = $now - 86400 * 30;
    $buckets = [
        'today' => ['label' => 'Today',        'items' => []],
        'week'  => ['label' => 'Past 7 days',  'items' => []],
        'month' => ['label' => 'Past 30 days', 'items' => []],
        'older' => ['label' => 'Older',        'items' => []],
    ];
    foreach ($pagedEntries as $e) {
        $t = $e['mtime'];
        if      ($t >= $today) $buckets['today']['items'][] = $e;
        elseif  ($t >= $week)  $buckets['week']['items'][]  = $e;
        elseif  ($t >= $month) $buckets['month']['items'][] = $e;
        else                   $buckets['older']['items'][] = $e;
    }
}

function st_relative_time($ts) {
    $diff = time() - $ts;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    $d = round($diff / 86400);
    if ($d < 7)        return $d . 'd ago';
    return date('j M Y', $ts);
}
function st_human_size($n) {
    if ($n < 1024)       return $n . ' B';
    if ($n < 1048576)    return number_format($n / 1024, 0)    . ' KB';
    if ($n < 1073741824) return number_format($n / 1048576, 1) . ' MB';
    return number_format($n / 1073741824, 2) . ' GB';
}

// URL prefix to serve image thumbnails over HTTP.
$urlPrefix = '/' . trim($appName, '/') . '/images';

// Back link — referrer if it's not this page itself, otherwise images root.
$selfUrl  = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$referer  = $_SERVER['HTTP_REFERER'] ?? '';
$refPath  = $referer ? parse_url($referer, PHP_URL_PATH) : '';
$backHref = ($refPath && $refPath !== $selfUrl) ? $referer
          : '../manage.php?p=' . urlencode($appName . '/images');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Starred — Time Machine</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { background:#fffbe8; color:#222; margin:0; font-size:14px; padding-bottom:80px;
           font-family:system-ui,-apple-system,Segoe UI,sans-serif; }
    <?php page_chrome_styles(); ?>
    /* Yellow-tinted page header (mirrors the red trash treatment). */
    .page-header { background:#fff8d8; border-bottom-color:#f0d97a; }
    .page-header .ph-title { color:#7a5b00; }
    .page-substrip { background:#fffbe8; border-bottom-color:#f0d97a; color:#7a5b00; }
    .page-substrip b { color:#7a5b00; }

    .bucket { margin:18px 14px 6px; }
    .bucket-head { display:flex; align-items:center; gap:10px; margin-bottom:8px;
                   color:#7a5b00; font-weight:600; }
    .bucket-head .count { color:#999; font-size:12px; font-weight:400; }
    .bucket-link { color:#7a5b00; text-decoration:none; border-bottom:1px dashed #d8b14a;
                   padding-bottom:1px; transition:color .12s, border-color .12s; }
    .bucket-link:hover { color:#0157b3; border-bottom-color:#0157b3; }
    /* Per-bucket "Add (N) to slideshow" button — only renders when grouped
       by folder and at least one item isn't already in the slideshow. */
    .bucket-add { margin-left:auto; font-size:12px; font-weight:500;
                  padding:3px 9px; border:1px solid #b6d7f5; border-radius:4px;
                  background:#fff; color:#0157b3; cursor:pointer; }
    .bucket-add:hover { background:#e4ecf7; }
    .bucket-add .bucket-add-n { color:#7a8088; font-weight:400; }
    .bucket-remove { font-size:12px; font-weight:500;
                     padding:3px 9px; border:1px solid #f5c2c7; border-radius:4px;
                     background:#fff; color:#dc3545; cursor:pointer; margin-left:6px; }
    .bucket-remove:hover { background:#fde8eb; }
    .bucket-remove .bucket-remove-n { color:#7a8088; font-weight:400; }
    /* When there's no add button in a bucket but a remove one, push it right. */
    .bucket-head .bucket-add ~ .bucket-remove { margin-left:6px; }
    .bucket-head > .bucket-remove:first-of-type:not(:first-child) { margin-left:auto; }

    .page-substrip .group-toggle { display:inline-flex; align-items:center; gap:6px; }
    .page-substrip .gt-btn { padding:2px 9px; border:1px solid #d8b14a; border-radius:3px;
                             color:#7a5b00; text-decoration:none; font-size:11px; background:#fff; }
    .page-substrip .gt-btn.active { background:#f0ad4e; color:#fff; border-color:#f0ad4e; }

    :root { --st-thumb-size: 180px; }
    .st-grid { display:grid; gap:10px;
               grid-template-columns:repeat(auto-fill, minmax(var(--st-thumb-size), 1fr)); }
    /* Controls row — size slider, page-size selector, page navigator. */
    .ctrl-row { display:flex; flex-wrap:wrap; gap:14px; align-items:center;
                padding:8px 14px; background:#fff8d8; border-bottom:1px solid #f0d97a;
                font-size:13px; color:#7a5b00; }
    .ctrl-row label { color:#7a5b00; margin-right:4px; }
    .ctrl-row input[type=range] { width:140px; vertical-align:middle; }
    .ctrl-row select { padding:2px 6px; border:1px solid #d8b14a; border-radius:3px; background:#fff; }
    .ctrl-row .sz-val { font-variant-numeric:tabular-nums; min-width:46px; display:inline-block; color:#555; font-size:12px; }
    .pager { display:inline-flex; align-items:center; gap:4px; margin-left:auto; }
    .pager .pg-link { display:inline-flex; align-items:center; justify-content:center;
                      min-width:24px; padding:2px 7px; border:1px solid #d8b14a; border-radius:3px;
                      color:#7a5b00; text-decoration:none; background:#fff; }
    .pager .pg-link:hover { background:#fff0c8; }
    .pager .pg-link.disabled { opacity:.4; pointer-events:none; }
    .pager .pg-info { color:#555; font-size:12px; padding:0 6px; }
    /* Cards match grid-view styling: every card on this page is starred, so
       the yellow inset ring is always on. */
    .st-card { position:relative; background:#fff; border:1px solid #e0e0e0; border-radius:6px;
               overflow:hidden; display:flex; flex-direction:column;
               box-shadow: inset 0 0 0 2px #f0ad4e; }
    .st-card .st-img-wrap { width:100%; aspect-ratio:1/1; background:#222; overflow:hidden; cursor:pointer; display:block; }
    .st-card .st-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }

    /* Star button overlaid on the image, top-left — clearly on the photo
       (not on the filename strip below). */
    .st-star {
        position:absolute; top:8px; left:8px;
        width:26px; height:26px; border-radius:50%;
        background:#f0ad4e; color:#fff; border:none;
        display:flex; align-items:center; justify-content:center;
        font-size:12px; cursor:pointer; padding:0; z-index:3;
        box-shadow:0 1px 3px rgba(0,0,0,.3);
        transition:background .12s, transform .12s;
    }
    .st-star:hover { background:#ec9a3a; transform:scale(1.08); }
    .st-star i { line-height:1; }

    /* Add/remove-from-slideshow button — top-RIGHT of the image. Blue +
       when not in slideshow, green ✓ when in. */
    .st-slide {
        position:absolute; top:8px; right:8px;
        width:26px; height:26px; border-radius:50%;
        background:rgba(255,255,255,0.92); color:#0157b3; border:1px solid #0157b3;
        display:flex; align-items:center; justify-content:center;
        font-size:13px; cursor:pointer; padding:0; z-index:3;
        box-shadow:0 1px 3px rgba(0,0,0,.25);
        transition:background .12s, color .12s, transform .12s;
    }
    .st-slide:hover { background:#0157b3; color:#fff; transform:scale(1.08); }
    .st-card.in-show .st-slide { background:#28a745; color:#fff; border-color:#28a745; }
    .st-card.in-show .st-slide:hover { background:#dc3545; border-color:#dc3545; }
    .st-card.in-show { box-shadow: inset 0 0 0 2px #f0ad4e, inset 0 0 0 4px #28a745; }

    /* Header "Add all" button. */
    .neutral-btn { background:#fff; color:#333; border:1px solid #ccc; padding:5px 12px;
                   border-radius:4px; cursor:pointer; }
    .neutral-btn:hover { background:#fff8d8; }
    .neutral-btn .hint-n,
    .danger-outline-btn .hint-n { color:#888; font-weight:400; font-size:.92em; }
    .danger-outline-btn { background:#fff; color:#dc3545; border:1px solid #f5c2c7; padding:5px 12px;
                   border-radius:4px; cursor:pointer; margin-left:6px; }
    .danger-outline-btn:hover { background:#fde8eb; }

    .st-meta { padding:6px 9px 8px; font-size:11px; line-height:1.35; }
    .st-meta .st-name { font-weight:600; color:#222; word-break:break-all;
                        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .st-meta .st-folder {
        font-size:10px; margin-top:1px;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .st-meta .st-folder { color:#7a8088; }
    .st-meta .st-folder i { color:#f0ad4e; margin-right:2px; font-size:9px; opacity:.85; }
    .st-meta .st-time { color:#999; font-size:10px; margin-top:1px; }

    .empty-state { text-align:center; padding:80px 20px; color:#999; }
    .empty-state .big-icon { font-size:60px; color:#f0d97a; margin-bottom:14px; }

    @media (max-width: 600px) {
        .st-grid { grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:6px; }
        .st-card .st-folder { display:none; }
        .st-meta { padding:4px 6px 5px; font-size:10px; }
        .bucket { margin:14px 8px 6px; }
    }
  </style>
</head>
<body class="<?php echo !empty($sbFolderIndex) ? 'has-sidebar' : '' ?>">

<?php if (!empty($sbFolderIndex)):
    $sbItems = pages_render_folder_tree($sbFolderIndex, $folderFilter, $_GET, 'All starred', 'fa-star-o');
?>
<div id="sidebarBackdrop"></div>
<aside id="folderSidebar" aria-label="Filter starred by folder">
  <div class="sb-head"><span class="sb-head-title"><i class="fa fa-filter"></i> Filter by folder</span></div>
  <div class="sb-scroll"><?php echo $sbItems ?></div>
</aside>
<?php endif; ?>
<?php
$groupToggle = '';
if ($totalCount > 0) {
    $byFolder = $groupMode === 'folder' ? 'active' : '';
    $byDate   = $groupMode === 'date'   ? 'active' : '';
    // location.replace keeps the back button useful (no history pile-up on toggle).
    $groupToggle = '<span class="group-toggle">Group:'
                 .   '<a class="gt-btn ' . $byFolder . '" href="?group=folder" onclick="location.replace(this.href);return false;">Folder</a>'
                 .   '<a class="gt-btn ' . $byDate   . '" href="?group=date"   onclick="location.replace(this.href);return false;">Date</a>'
                 . '</span>';
}

// View toggle — All starred vs In slideshow. Builds URLs from current $_GET
// so other filters (folder, page, group, pp) carry through.
$viewToggle = '';
if ($totalAllStarred > 0) {
    $qpAll = $_GET; unset($qpAll['inslideshow'], $qpAll['page']);
    $qpIn  = $_GET; $qpIn['inslideshow'] = '1'; unset($qpIn['page']);
    $allHref = '?' . http_build_query($qpAll);
    $inHref  = '?' . http_build_query($qpIn);
    $allCls  = $inSlideshowFilter ? '' : 'active';
    $inCls   = $inSlideshowFilter ? 'active' : '';
    $viewToggle = '<span class="group-toggle">View:'
                .   '<a class="gt-btn ' . $allCls . '" href="' . htmlspecialchars($allHref) . '" onclick="location.replace(this.href);return false;">All <span class="hint-n">(' . number_format($totalAllStarred) . ')</span></a>'
                .   '<a class="gt-btn ' . $inCls  . '" href="' . htmlspecialchars($inHref)  . '" onclick="location.replace(this.href);return false;">In slideshow <span id="stViewInCount" class="hint-n" data-total="' . (int)$totalInSlideshow . '">(' . number_format($totalInSlideshow) . ')</span></a>'
                . '</span>';
}

$stats = '<span><b id="stTotalCount">' . number_format($totalCount) . '</b> starred '
       .   '<span id="stTotalUnit">item' . ($totalCount === 1 ? '' : 's') . '</span></span>'
       . '<span><b id="stTotalSize" data-bytes="' . (int)$totalBytes . '">'
       .   st_human_size($totalBytes) . '</b></span>'
       . $viewToggle
       . $groupToggle;

$inShowCount = count($pagedEntries) - $notInShowCount;
$primary = '';
if ($totalCount > 0) {
    // Both buttons always emitted (display:none when their set is empty) so
    // refreshAddAllBadge() can show/hide them as state changes without a reload.
    $addHidden = $notInShowCount === 0 ? ' style="display:none"' : '';
    $remHidden = $inShowCount    === 0 ? ' style="display:none"' : '';
    $primary .= '<button type="button" class="neutral-btn" id="stAddAll"' . $addHidden
              . ' title="Add starred images that aren\'t in the slideshow yet">'
              . '<i class="fa fa-plus-circle"></i> Add all to slideshow '
              . '<span class="hint-n">(' . $notInShowCount . ')</span></button>';
    $primary .= '<button type="button" class="danger-outline-btn" id="stRemoveAll"' . $remHidden
              . ' title="Remove starred images from the slideshow">'
              . '<i class="fa fa-minus-circle"></i> Remove all from slideshow '
              . '<span class="hint-n">(' . $inShowCount . ')</span></button>';
}
page_header([
    'title'       => 'Starred',
    'backHref'    => $backHref,
    'primaryHtml' => $primary,
    'statsHtml'   => $totalCount > 0 ? $stats : '',
]);
?>

<?php if ($totalCount === 0): ?>
  <div class="empty-state">
    <div class="big-icon"><i class="fa fa-star-o"></i></div>
    <h4 style="color:#888">No starred images yet</h4>
    <p>Click the ★ icon on any image (list or grid view) to mark it as a favorite. They'll appear here.</p>
  </div>
<?php else: ?>

  <div class="ctrl-row">
    <span><label>Size:</label>
      <input type="range" id="stSizeSlider" min="100" max="320" step="10">
      <span id="stSizeVal" class="sz-val">180px</span>
    </span>
    <span><label>Per page:</label>
      <select id="stPpSelect" onchange="(function(s){const u=new URL(location.href);u.searchParams.set('pp',s.value);u.searchParams.set('page','1');location.href=u.toString();})(this)">
        <?php foreach ([24, 48, 96, 144, 240, 9999] as $opt): ?>
          <option value="<?php echo $opt ?>" <?php echo $pp == $opt ? 'selected' : '' ?>>
            <?php echo $opt == 9999 ? 'All' : $opt ?>
          </option>
        <?php endforeach; ?>
      </select>
    </span>
    <?php if ($totalPages > 1): ?>
    <span class="pager">
      <?php
        $pageUrl = function($targetPage) {
            $qp = $_GET;
            $qp['page'] = $targetPage;
            return '?' . http_build_query($qp);
        };
      ?>
      <a class="pg-link<?php echo $page == 1 ? ' disabled' : '' ?>" href="<?php echo htmlspecialchars($pageUrl(1)) ?>" title="First page">«</a>
      <a class="pg-link<?php echo $page == 1 ? ' disabled' : '' ?>" href="<?php echo htmlspecialchars($pageUrl(max(1, $page - 1))) ?>" title="Previous page">‹</a>
      <span class="pg-info">Page <b><?php echo $page ?></b> of <?php echo $totalPages ?></span>
      <a class="pg-link<?php echo $page == $totalPages ? ' disabled' : '' ?>" href="<?php echo htmlspecialchars($pageUrl(min($totalPages, $page + 1))) ?>" title="Next page">›</a>
      <a class="pg-link<?php echo $page == $totalPages ? ' disabled' : '' ?>" href="<?php echo htmlspecialchars($pageUrl($totalPages)) ?>" title="Last page">»</a>
    </span>
    <?php endif; ?>
  </div>

  <?php foreach ($buckets as $key => $b): if (empty($b['items'])) continue; ?>
    <?php
    // For folder buckets, count slideshow membership both ways so we can
    // show "Add (N)" and "Remove (M)" buttons independently.
    $bucketNotIn = 0;
    $bucketIn    = 0;
    if ($groupMode === 'folder') {
        foreach ($b['items'] as $it) {
            if (isset($activeSet[$it['abs']])) $bucketIn++;
            else                               $bucketNotIn++;
        }
    }
    ?>
    <section class="bucket" data-bucket="<?php echo htmlspecialchars($key) ?>">
      <div class="bucket-head">
        <?php if (!empty($b['href'])): ?>
          <a class="bucket-link" href="<?php echo htmlspecialchars($b['href']) ?>"
             title="Open this folder in grid view"><?php echo htmlspecialchars($b['label']) ?></a>
        <?php else: ?>
          <span><?php echo htmlspecialchars($b['label']) ?></span>
        <?php endif; ?>
        <span class="count" data-count="<?php echo count($b['items']) ?>">
          <span class="count-n"><?php echo count($b['items']) ?></span>
          item<?php echo count($b['items']) === 1 ? '' : 's' ?>
        </span>
        <?php if ($groupMode === 'folder'): ?>
        <button type="button" class="bucket-add"
                onclick="addBucketToShow(this.closest('.bucket'))"
                <?php echo $bucketNotIn === 0 ? 'style="display:none"' : '' ?>
                title="Add this folder's not-yet-in-slideshow starred images">
          <i class="fa fa-plus-circle"></i> Add to slideshow <span class="bucket-add-n">(<?php echo $bucketNotIn ?>)</span>
        </button>
        <button type="button" class="bucket-remove"
                onclick="removeBucketFromShow(this.closest('.bucket'))"
                <?php echo $bucketIn === 0 ? 'style="display:none"' : '' ?>
                title="Remove this folder's in-slideshow starred images">
          <i class="fa fa-minus-circle"></i> Remove from slideshow <span class="bucket-remove-n">(<?php echo $bucketIn ?>)</span>
        </button>
        <?php endif; ?>
      </div>
      <div class="st-grid">
        <?php foreach ($b['items'] as $e):
            $folder = dirname($e['rel']);
            if ($folder === '.' || $folder === '') $folder = '(root)';
            $cb         = '?_=' . $e['mtime'];
            $thumbUrl   = $urlPrefix . '/' . str_replace('%2F', '/', rawurlencode($e['rel'])) . $cb;
            $displayName = display_name_strip_star($e['name']);
        ?>
          <?php $inShow = isset($activeSet[$e['abs']]); ?>
          <div class="st-card<?php echo $inShow ? ' in-show' : '' ?>"
               data-abs="<?php echo htmlspecialchars($e['abs']) ?>"
               data-rel="<?php echo htmlspecialchars($e['rel']) ?>"
               data-size="<?php echo (int)$e['size'] ?>"
               data-in-show="<?php echo $inShow ? '1' : '0' ?>">
            <a class="st-img-wrap" href="<?php echo htmlspecialchars($thumbUrl) ?>" target="_blank">
              <img loading="lazy" src="<?php echo htmlspecialchars($thumbUrl) ?>" alt="<?php echo htmlspecialchars($displayName) ?>">
            </a>
            <button type="button" class="st-star" title="Unstar — remove the ★ marker"
                    onclick="unstarOne(this.closest('.st-card'))">
              <i class="fa fa-star"></i>
            </button>
            <button type="button" class="st-slide" onclick="toggleSlideshow(this.closest('.st-card'))"
                    title="<?php echo $inShow ? 'In slideshow — click to remove' : 'Add to slideshow' ?>">
              <i class="fa <?php echo $inShow ? 'fa-check-circle' : 'fa-plus-circle' ?>"></i>
            </button>
            <div class="st-meta">
              <div class="st-name" title="<?php echo htmlspecialchars($e['name']) ?>"><?php echo htmlspecialchars($displayName) ?></div>
              <?php if ($groupMode !== 'folder'): /* in folder mode the bucket head already names it */ ?>
              <div class="st-folder" title="in: images/<?php echo htmlspecialchars($folder) ?>">
                <i class="fa fa-folder-o"></i> <?php echo htmlspecialchars($folder) ?>
              </div>
              <?php endif; ?>
              <div class="st-time"><?php echo st_relative_time($e['mtime']) ?> &middot; <?php echo st_human_size($e['size']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<script>
const token = <?php echo json_encode($_SESSION['token']) ?>;

// ── Thumbnail size slider — persists in localStorage. ────────────────────
(function() {
    const slider = document.getElementById('stSizeSlider');
    if (!slider) return;
    const valEl  = document.getElementById('stSizeVal');
    const stored = parseInt(localStorage.getItem('starredThumbSize') || '180', 10);
    function apply(v) {
        document.documentElement.style.setProperty('--st-thumb-size', v + 'px');
        if (valEl) valEl.textContent = v + 'px';
    }
    slider.value = stored;
    apply(stored);
    slider.addEventListener('input', () => {
        const v = parseInt(slider.value, 10) || 180;
        apply(v);
        localStorage.setItem('starredThumbSize', v);
    });
})();

function humanSize(n) {
    if (n < 1024)       return n + ' B';
    if (n < 1048576)    return Math.round(n / 1024) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
}

// ── Slideshow membership (Part 3) ─────────────────────────────────────────
// Per-card toggle: add this single starred file to the slideshow set (or
// remove if it's already there). Updates the button + card class in place.
function toggleSlideshow(card) {
    if (!card || !card.dataset.abs) return;
    const inShow = card.dataset.inShow === '1';
    const action = inShow ? 'remove' : 'add';
    const fd = new FormData();
    fd.append('token',     token);
    fd.append('action',    action);
    fd.append('paths[]',   card.dataset.abs);
    fetch('../util/manage_starred_paths.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success || !d.applied) { alert('Could not update slideshow set.'); return; }
            applyInShow(card, !inShow);
            refreshAddAllBadge();
        })
        .catch(() => alert('Network error.'));
}

function applyInShow(card, isIn) {
    const wasIn = card.dataset.inShow === '1';
    card.dataset.inShow = isIn ? '1' : '0';
    card.classList.toggle('in-show', isIn);
    const btn = card.querySelector('.st-slide');
    if (btn) {
        btn.title = isIn ? 'In slideshow — click to remove' : 'Add to slideshow';
        const i = btn.querySelector('i');
        if (i) i.className = 'fa ' + (isIn ? 'fa-check-circle' : 'fa-plus-circle');
    }
    // Keep the View toggle's "In slideshow (N)" badge live without a reload.
    if (wasIn !== isIn) {
        const span = document.getElementById('stViewInCount');
        if (span) {
            let total = parseInt(span.dataset.total || '0', 10) + (isIn ? 1 : -1);
            if (total < 0) total = 0;
            span.dataset.total = String(total);
            span.textContent = '(' + total.toLocaleString() + ')';
        }
    }
}

// Keep both header buttons + every per-bucket button counter in sync with
// the current card state. Idempotent — runs after every membership change.
// Buttons stay in the DOM; this just toggles display + updates counts. That
// way Add/Remove can swap visibility as the user shuttles cards in and out
// of the slideshow without ever requiring a reload.
function showHide(btn, n, badgeSel) {
    if (!btn) return;
    btn.style.display = n > 0 ? '' : 'none';
    const h = btn.querySelector(badgeSel);
    if (h) h.textContent = '(' + n + ')';
}
function refreshAddAllBadge() {
    const notIn = document.querySelectorAll('.st-card:not(.in-show)').length;
    const inSh  = document.querySelectorAll('.st-card.in-show').length;
    showHide(document.getElementById('stAddAll'),    notIn, '.hint-n');
    showHide(document.getElementById('stRemoveAll'), inSh,  '.hint-n');
    document.querySelectorAll('.bucket').forEach(b => {
        showHide(b.querySelector('.bucket-add'),
                 b.querySelectorAll('.st-card:not(.in-show)').length,
                 '.bucket-add-n');
        showHide(b.querySelector('.bucket-remove'),
                 b.querySelectorAll('.st-card.in-show').length,
                 '.bucket-remove-n');
    });
}

function bulkSlideshow(cards, action) {
    if (cards.length === 0) return Promise.resolve(null);
    const fd = new FormData();
    fd.append('token',  token);
    fd.append('action', action);
    cards.forEach(c => fd.append('paths[]', c.dataset.abs));
    return fetch('../util/manage_starred_paths.php', { method: 'POST', body: fd })
        .then(r => r.json());
}

function addBucketToShow(bucket) {
    if (!bucket) return;
    const cards = Array.from(bucket.querySelectorAll('.st-card:not(.in-show)'));
    if (cards.length === 0) return;
    bulkSlideshow(cards, 'add')
        .then(d => {
            if (!d || !d.success) { alert('Add failed.'); return; }
            cards.forEach(c => applyInShow(c, true));
            refreshAddAllBadge();
        })
        .catch(() => alert('Network error.'));
}

function removeBucketFromShow(bucket) {
    if (!bucket) return;
    const cards = Array.from(bucket.querySelectorAll('.st-card.in-show'));
    if (cards.length === 0) return;
    if (!confirm('Remove ' + cards.length + ' starred image' + (cards.length === 1 ? '' : 's')
               + ' from the slideshow?')) return;
    bulkSlideshow(cards, 'remove')
        .then(d => {
            if (!d || !d.success) { alert('Remove failed.'); return; }
            cards.forEach(c => applyInShow(c, false));
            refreshAddAllBadge();
        })
        .catch(() => alert('Network error.'));
}

document.getElementById('stRemoveAll')?.addEventListener('click', function() {
    const cards = Array.from(document.querySelectorAll('.st-card.in-show'));
    if (cards.length === 0) return;
    if (!confirm('Remove ' + cards.length + ' starred image' + (cards.length === 1 ? '' : 's')
               + ' from the slideshow?')) return;
    bulkSlideshow(cards, 'remove')
        .then(d => {
            if (!d || !d.success) { alert('Remove failed.'); return; }
            cards.forEach(c => applyInShow(c, false));
            refreshAddAllBadge();
        })
        .catch(() => alert('Network error.'));
});

// Bulk add (page-level) — uses the same helper as the per-bucket Add.
document.getElementById('stAddAll')?.addEventListener('click', function() {
    const cards = Array.from(document.querySelectorAll('.st-card:not(.in-show)'));
    if (cards.length === 0) return;
    if (!confirm('Add ' + cards.length + ' starred image' + (cards.length === 1 ? '' : 's')
               + ' to the slideshow?')) return;
    bulkSlideshow(cards, 'add')
        .then(d => {
            if (!d || !d.success) { alert('Add all failed.'); return; }
            cards.forEach(c => applyInShow(c, true));
            refreshAddAllBadge();
        })
        .catch(() => alert('Network error.'));
});

function unstarOne(card) {
    if (!card || !card.dataset.abs) return;
    const name = (card.querySelector('.st-name') || {}).textContent || 'this image';
    if (!confirm('Unstar "' + name.trim() + '"?')) return;

    const cardSize = parseInt(card.dataset.size || '0', 10) || 0;
    const fd = new FormData();
    fd.append('token', token);
    fd.append('path',  card.dataset.abs);
    fetch('../util/toggle_star.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success || d.starred !== false) { alert('Unstar failed.'); return; }

            const bucket = card.closest('.bucket');
            card.remove();

            // Live-update the bucket's own item count.
            if (bucket) {
                const countEl = bucket.querySelector('.bucket-head .count');
                if (countEl) {
                    const newN = (parseInt(countEl.dataset.count, 10) || 1) - 1;
                    if (newN <= 0) {
                        bucket.remove();
                    } else {
                        countEl.dataset.count = newN;
                        const n   = countEl.querySelector('.count-n');
                        if (n) n.textContent = newN;
                        // Refresh "item" / "items" suffix without rebuilding the rest.
                        countEl.innerHTML = '<span class="count-n">' + newN + '</span> '
                                          + 'item' + (newN === 1 ? '' : 's');
                    }
                }
            }

            // Live-update page totals (count + total size).
            const totEl = document.getElementById('stTotalCount');
            if (totEl) {
                const newTot = (parseInt(totEl.textContent.replace(/[^0-9]/g, ''), 10) || 1) - 1;
                totEl.textContent = newTot.toLocaleString();
                const unit = document.getElementById('stTotalUnit');
                if (unit) unit.textContent = (newTot === 1 ? 'item' : 'items');
            }
            const sizeEl = document.getElementById('stTotalSize');
            if (sizeEl) {
                const curBytes = Math.max(0, (parseInt(sizeEl.dataset.bytes, 10) || 0) - cardSize);
                sizeEl.dataset.bytes = curBytes;
                sizeEl.textContent  = humanSize(curBytes);
            }

            refreshAddAllBadge();
            if (!document.querySelector('.st-card')) location.reload();
        })
        .catch(() => alert('Network error.'));
}
</script>
<?php pages_emit_sidebar_drawer_js(); ?>
<?php echo app_credit_html(); ?>
</body>
</html>
