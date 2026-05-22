<?php
/*
 * pages/trash.php — dedicated trash view.
 *
 * Cards (thumbnail + filename + original location + trashed-when) grouped by
 * recency (Today / This week / This month / Older) OR by source folder
 * (?group=folder). Optional left-sidebar filter (?folder=<rel>) narrows to
 * one source folder so bulk Restore / Delete acts on that subset only.
 *
 * Bulk-select with sticky bottom action bar. Restore returns each file to
 * its original location under images/; Delete forever removes irrecoverably.
 *
 * Backend reused:
 *   util/restore_from_trash.php   (POST paths[], token)
 *   util/delete_permanent.php     (POST paths[] or empty=1, token)
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

$trashRoot = rtrim(trash_root(), '/');
$imgExts   = ['gif','jpg','jpeg','png','bmp','webp','avif','ico','svg'];

// ── Recursively gather trashed image files. ────────────────────────────────
$entries = [];
$walk = function($dir) use (&$walk, $trashRoot, $imgExts, &$entries) {
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full)) { $walk($full); continue; }
        if (!is_file($full)) continue;
        if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $imgExts)) continue;
        $entries[] = [
            'abs'   => $full,
            'rel'   => ltrim(substr($full, strlen($trashRoot)), '/'),
            'name'  => $f,
            'size'  => @filesize($full) ?: 0,
            'mtime' => @filemtime($full) ?: 0,
        ];
    }
};
if (is_dir($trashRoot)) $walk($trashRoot);

// Sort newest-trashed first.
usort($entries, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

// ── Sidebar folder index (built from the FULL entry list, before filter). ──
// Map of folder → ['count','bytes']. Folders are "where this file was when
// it was trashed", normalized so a parent folder roll-up shows the right
// totals (e.g., 2024/jan/foo also contributes to 2024/jan and 2024).
$sbFolderIndex = [];
foreach ($entries as $e) {
    $folder = dirname($e['rel']);
    if ($folder === '.' || $folder === '') $folder = '(root)';
    if (!isset($sbFolderIndex[$folder])) $sbFolderIndex[$folder] = ['count' => 0, 'bytes' => 0];
    $sbFolderIndex[$folder]['count']++;
    $sbFolderIndex[$folder]['bytes'] += $e['size'];
}
// Sort by count desc, then alphabetical.
uksort($sbFolderIndex, function ($a, $b) use ($sbFolderIndex) {
    $cmp = $sbFolderIndex[$b]['count'] <=> $sbFolderIndex[$a]['count'];
    if ($cmp !== 0) return $cmp;
    return strcasecmp($a, $b);
});

// ── Apply the optional folder filter. ──────────────────────────────────────
// ?folder=<rel> shows only files whose rel-path is under that folder (exact
// or descendant). Empty / missing = no filter (show all).
$folderFilter = trim((string)($_GET['folder'] ?? ''));
if ($folderFilter !== '') {
    $needle = rtrim($folderFilter, '/');
    $entries = array_values(array_filter($entries, function ($e) use ($needle) {
        $f = dirname($e['rel']);
        if ($f === '.' || $f === '') $f = '(root)';
        return $f === $needle || strpos($f . '/', $needle . '/') === 0;
    }));
}

$totalCount  = count($entries);
$totalBytes  = array_sum(array_column($entries, 'size'));

// ── Bucket by grouping mode. ───────────────────────────────────────────────
$groupMode = (($_GET['group'] ?? '') === 'folder') ? 'folder' : 'date';
$buckets = [];

if ($groupMode === 'folder') {
    // Group by original folder (path under images/). Natural unit for
    // "restore everything I trashed from this shoot" workflows.
    foreach ($entries as $e) {
        $folder = dirname($e['rel']);
        if ($folder === '.' || $folder === '') $folder = '(root)';
        $key = 'f:' . $folder;
        if (!isset($buckets[$key])) $buckets[$key] = ['label' => $folder, 'items' => []];
        $buckets[$key]['items'][] = $e;
    }
    // Sort buckets by label alphabetically.
    uksort($buckets, fn($a, $b) => strcasecmp($a, $b));
} else {
    $now    = time();
    $today  = strtotime('today');
    $week   = $now - 86400 * 7;
    $month  = $now - 86400 * 30;
    $buckets = [
        'today'   => ['label' => 'Today',        'items' => []],
        'week'    => ['label' => 'Past 7 days',  'items' => []],
        'month'   => ['label' => 'Past 30 days', 'items' => []],
        'older'   => ['label' => 'Older',        'items' => []],
    ];
    foreach ($entries as $e) {
        $t = $e['mtime'];
        if      ($t >= $today) $buckets['today']['items'][]  = $e;
        elseif  ($t >= $week)  $buckets['week']['items'][]   = $e;
        elseif  ($t >= $month) $buckets['month']['items'][]  = $e;
        else                   $buckets['older']['items'][]  = $e;
    }
}

function tr_relative_time($ts) {
    $diff = time() - $ts;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    $d = round($diff / 86400);
    if ($d < 7)        return $d . 'd ago';
    return date('j M Y', $ts);
}
function tr_human_size($n) {
    if ($n < 1024)             return $n . ' B';
    if ($n < 1048576)          return number_format($n / 1024, 0)       . ' KB';
    if ($n < 1073741824)       return number_format($n / 1048576, 1)    . ' MB';
    return number_format($n / 1073741824, 2) . ' GB';
}

// URL prefix for serving trash image thumbnails over HTTP.
$urlPrefix = '/' . trim($appName, '/') . '/trash';

// Back link — use HTTP_REFERER if it points to anything other than this page
// itself (avoids looping when the group toggle ever pushes a history entry).
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
  <title>Trash — Time Machine</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { background:#fff5f5; color:#222; margin:0; font-size:14px; padding-bottom:80px;
           font-family:system-ui,-apple-system,Segoe UI,sans-serif; }
    <?php page_chrome_styles(); ?>
    /* Trash-tinted page header. */
    .page-header { background:#fff5f5; border-bottom-color:#f5c2c7; }
    .page-header .ph-title { color:#a02431; }
    .page-substrip { background:#fff8f8; border-bottom-color:#f5c2c7; color:#7a3030; }
    .page-substrip b { color:#a02431; }

    .danger-btn  { background:#dc3545; color:#fff; border:1px solid #dc3545; padding:5px 12px;
                   border-radius:4px; cursor:pointer; font-weight:500; }
    .danger-btn:hover  { background:#bb2d3b; }
    .neutral-btn { background:#fff; color:#333; border:1px solid #ccc; padding:5px 12px;
                   border-radius:4px; cursor:pointer; }
    .neutral-btn:hover { background:#f1f5fb; }
    .ph-primary .neutral-btn, .ph-primary .danger-btn { margin-left:6px; }

    .bucket { margin:18px 14px 6px; }
    .bucket-head { display:flex; align-items:center; gap:10px; margin-bottom:8px;
                   color:#7a3030; font-weight:600; }
    .bucket-head .count { color:#999; font-size:12px; font-weight:400; }
    .bucket-select { display:inline-flex; align-items:center; gap:6px; cursor:pointer;
                     user-select:none; padding:2px 6px; margin:-2px -6px; border-radius:4px; }
    .bucket-select:hover { background:#fff0f0; }

    /* Group-by toggle in the page substrip. */
    .page-substrip .group-toggle { display:inline-flex; align-items:center; gap:6px; }
    .page-substrip .gt-btn { padding:2px 9px; border:1px solid #d8a3a8; border-radius:3px;
                             color:#7a3030; text-decoration:none; font-size:11px; background:#fff; }
    .page-substrip .gt-btn.active { background:#a02431; color:#fff; border-color:#a02431; }
    .page-substrip .hint { color:#999; font-size:11px; margin-left:auto; }
    .page-substrip .hint code { background:#fff; padding:1px 4px; border:1px solid #eee; border-radius:3px; color:#555; }

    :root { --tr-thumb-size: 180px; }
    .tr-grid { display:grid; gap:10px;
               grid-template-columns:repeat(auto-fill, minmax(var(--tr-thumb-size), 1fr)); }

    /* Controls row — thumbnail size slider. Red-tinted to match the page. */
    .ctrl-row { display:flex; flex-wrap:wrap; gap:14px; align-items:center;
                padding:8px 14px; background:#fff5f5; border-bottom:1px solid #f5c2c7;
                font-size:13px; color:#7a3030; }
    .ctrl-row label { color:#7a3030; margin-right:4px; }
    .ctrl-row input[type=range] { width:140px; vertical-align:middle; }
    .ctrl-row .sz-val { font-variant-numeric:tabular-nums; min-width:46px; display:inline-block; color:#555; font-size:12px; }
    .tr-card { position:relative; background:#fff; border:1px solid #f1d0d3; border-radius:6px;
               overflow:hidden; display:flex; flex-direction:column;
               transition:border-color .15s, box-shadow .15s; }
    .tr-card.selected { border-color:#dc3545; box-shadow:0 0 0 2px rgba(220,53,69,.18); }
    .tr-card .tr-img-wrap { width:100%; aspect-ratio:1/1; background:#222; overflow:hidden; cursor:pointer; }
    .tr-card .tr-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }

    .tr-meta { padding:6px 9px 4px; font-size:11px; line-height:1.35; }
    .tr-meta .tr-name { font-weight:600; color:#222; word-break:break-all;
                        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .tr-meta .tr-folder { color:#7a8088; font-size:10px; margin-top:1px;
                          overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:help; }
    .tr-meta .tr-folder i { color:#a02431; margin-right:2px; font-size:9px; opacity:.8; }
    .tr-meta .tr-time { color:#999; font-size:10px; margin-top:1px; }

    .tr-bar { padding:5px 9px; background:#fafafa; border-top:1px solid #eee;
              display:flex; align-items:center; gap:6px; }
    .tr-bar label { font-size:11px; color:#555; cursor:pointer; user-select:none; flex:1; }
    .tr-bar button { background:transparent; border:none; padding:3px 6px; cursor:pointer;
                     border-radius:3px; color:#666; font-size:14px; line-height:1; }
    .tr-bar .tr-restore:hover { color:#0157b3; background:#e4ecf7; }
    .tr-bar .tr-delete:hover  { color:#dc3545; background:#fde8eb; }

    .empty-state { text-align:center; padding:80px 20px; color:#999; }
    .empty-state .big-icon { font-size:60px; color:#f1c0c4; margin-bottom:14px; }

    .bulk-bar { position:fixed; bottom:0; left:0; right:0;
                background:#fff; border-top:1px solid #ddd;
                padding:10px 14px; display:none; align-items:center; gap:12px;
                box-shadow:0 -2px 8px rgba(0,0,0,.06); z-index:50; }
    .bulk-bar.visible { display:flex; }
    .bulk-bar .count { font-weight:600; color:#dc3545; }
    .bulk-bar .spacer { flex:1; }

    @media (max-width: 600px) {
        .tr-grid { grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:6px; }
        .tr-card .tr-folder { display:none; }
        .tr-meta { padding:4px 6px 3px; font-size:10px; }
        .tr-bar { padding:4px 6px; }
        .bucket { margin:14px 8px 6px; }
    }
  </style>
</head>
<body class="<?php echo !empty($sbFolderIndex) ? 'has-sidebar' : '' ?>">

<?php if (!empty($sbFolderIndex)):
    $sbItems = pages_render_folder_tree($sbFolderIndex, $folderFilter, $_GET, 'All trashed', 'fa-inbox');
?>
<div id="sidebarBackdrop"></div>
<aside id="folderSidebar" aria-label="Filter trash by folder">
  <div class="sb-head"><span class="sb-head-title"><i class="fa fa-filter"></i> Filter by folder</span></div>
  <div class="sb-scroll"><?php echo $sbItems ?></div>
</aside>
<?php endif; ?>
<?php
$primary = '';
if ($totalCount > 0) {
    $allLabel   = $folderFilter !== '' ? 'Restore visible' : 'Restore all';
    $emptyLabel = $folderFilter !== '' ? 'Delete visible'  : 'Empty trash';
    $emptyTip   = $folderFilter !== ''
        ? 'Permanently delete the currently-filtered files — cannot be undone'
        : 'Permanently delete everything in trash — cannot be undone';
    $primary  = '<button type="button" class="neutral-btn" onclick="restoreAll()" '
              . 'title="Restore the currently-visible trashed files to their original location">'
              . '<i class="fa fa-undo"></i> ' . $allLabel . '</button>'
              . '<button type="button" class="danger-btn" onclick="emptyTrash()" '
              . 'title="' . htmlspecialchars($emptyTip) . '">'
              . '<i class="fa fa-trash"></i> ' . $emptyLabel . '</button>';
}
$groupToggle = '';
if ($totalCount > 0) {
    $byDate   = $groupMode === 'date'   ? 'active' : '';
    $byFolder = $groupMode === 'folder' ? 'active' : '';
    // Use location.replace so toggling group doesn't push a new history entry
    // each time (which would make the browser Back button cycle within trash
    // instead of leaving it).
    $groupToggle = '<span class="group-toggle">'
                 .   'Group:'
                 .   '<a class="gt-btn ' . $byDate . '"   href="?group=date"   onclick="location.replace(this.href);return false;">Date</a>'
                 .   '<a class="gt-btn ' . $byFolder . '" href="?group=folder" onclick="location.replace(this.href);return false;">Folder</a>'
                 . '</span>';
}
$stats = '<span><b>' . number_format($totalCount) . '</b> item' . ($totalCount === 1 ? '' : 's') . '</span>'
       . '<span><b>' . tr_human_size($totalBytes) . '</b></span>'
       . $groupToggle
       . '<span class="hint"><i class="fa fa-info-circle"></i> Restore returns files to their original location under <code>images/</code></span>';

page_header([
    'title'       => 'Trash',
    'backHref'    => $backHref,
    'primaryHtml' => $primary,
    'statsHtml'   => $totalCount > 0 ? $stats : '',
]);
?>

<?php if ($totalCount === 0): ?>
  <div class="empty-state">
    <div class="big-icon"><i class="fa fa-check-circle"></i></div>
    <h4 style="color:#888">Trash is empty</h4>
    <p>Trashed images appear here. They stay until you empty the trash.</p>
  </div>
<?php else: ?>
  <div class="ctrl-row">
    <span><label>Size:</label>
      <input type="range" id="trSizeSlider" min="100" max="320" step="10">
      <span id="trSizeVal" class="sz-val">180px</span>
    </span>
  </div>

  <form id="trForm">
    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['token']) ?>">
    <?php foreach ($buckets as $key => $b): if (empty($b['items'])) continue; ?>
      <section class="bucket" data-bucket="<?php echo htmlspecialchars($key) ?>">
        <div class="bucket-head">
          <label class="bucket-select" title="Select all in this group">
            <input type="checkbox" class="bucket-cb" onchange="onBucketCheck(this)">
            <span><?php echo htmlspecialchars($b['label']) ?></span>
          </label>
          <span class="count"><?php echo count($b['items']) ?> item<?php echo count($b['items']) === 1 ? '' : 's' ?></span>
        </div>
        <div class="tr-grid">
          <?php foreach ($b['items'] as $e):
              $folder = dirname($e['rel']);
              if ($folder === '.' || $folder === '') $folder = '(root)';
              $thumbUrl = $urlPrefix . '/' . str_replace('%2F', '/', rawurlencode($e['rel']));
              $restoreTip = 'Will be restored to: images/' . $folder;
          ?>
            <div class="tr-card" data-abs="<?php echo htmlspecialchars($e['abs']) ?>" data-size="<?php echo (int)$e['size'] ?>">
              <a class="tr-img-wrap" href="<?php echo htmlspecialchars($thumbUrl) ?>" target="_blank">
                <img loading="lazy" src="<?php echo htmlspecialchars($thumbUrl) ?>" alt="<?php echo htmlspecialchars($e['name']) ?>">
              </a>
              <div class="tr-meta">
                <div class="tr-name" title="<?php echo htmlspecialchars($e['name']) ?>"><?php echo htmlspecialchars($e['name']) ?></div>
                <div class="tr-folder" title="<?php echo htmlspecialchars($restoreTip) ?>">
                  <i class="fa fa-undo"></i> <?php echo htmlspecialchars($folder) ?>
                </div>
                <div class="tr-time"><?php echo tr_relative_time($e['mtime']) ?> &middot; <?php echo tr_human_size($e['size']) ?></div>
              </div>
              <div class="tr-bar">
                <label>
                  <input type="checkbox" class="tr-cb" onchange="onCheck(this)">
                  Select
                </label>
                <button type="button" class="tr-restore" title="Restore to original location" onclick="restoreOne(this.closest('.tr-card'))">
                  <i class="fa fa-undo"></i>
                </button>
                <button type="button" class="tr-delete" title="Delete forever — cannot be undone" onclick="deleteOne(this.closest('.tr-card'))">
                  <i class="fa fa-times"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </form>

  <div class="bulk-bar" id="bulkBar">
    <span><span class="count" id="bulkCount">0</span> selected</span>
    <span class="spacer"></span>
    <button type="button" class="neutral-btn" onclick="bulkRestore()">
      <i class="fa fa-undo"></i> Restore selected
    </button>
    <button type="button" class="danger-btn" onclick="bulkDelete()">
      <i class="fa fa-trash"></i> Delete selected forever
    </button>
  </div>
<?php endif; ?>

<script>
const token = <?php echo json_encode($_SESSION['token']) ?>;

// ── Thumbnail size slider — persists in localStorage (separate key from
// the starred-page slider so each remembers its own setting).
(function() {
    const slider = document.getElementById('trSizeSlider');
    if (!slider) return;
    const valEl  = document.getElementById('trSizeVal');
    const stored = parseInt(localStorage.getItem('trashThumbSize') || '180', 10);
    function apply(v) {
        document.documentElement.style.setProperty('--tr-thumb-size', v + 'px');
        if (valEl) valEl.textContent = v + 'px';
    }
    slider.value = stored;
    apply(stored);
    slider.addEventListener('input', () => {
        const v = parseInt(slider.value, 10) || 180;
        apply(v);
        localStorage.setItem('trashThumbSize', v);
    });
})();

function onCheck(cb) {
    cb.closest('.tr-card').classList.toggle('selected', cb.checked);
    // Sync the bucket-level "select all" checkbox so it reflects state.
    const bucket = cb.closest('.bucket');
    if (bucket) {
        const bcb   = bucket.querySelector('.bucket-cb');
        const cards = bucket.querySelectorAll('.tr-cb');
        const sel   = bucket.querySelectorAll('.tr-cb:checked');
        if (bcb) {
            bcb.checked       = (sel.length === cards.length);
            bcb.indeterminate = (sel.length > 0 && sel.length < cards.length);
        }
    }
    refreshBar();
}
function onBucketCheck(bcb) {
    const bucket = bcb.closest('.bucket');
    if (!bucket) return;
    bucket.querySelectorAll('.tr-cb').forEach(cb => {
        cb.checked = bcb.checked;
        cb.closest('.tr-card').classList.toggle('selected', bcb.checked);
    });
    bcb.indeterminate = false;
    refreshBar();
}
function refreshBar() {
    const checked = document.querySelectorAll('.tr-cb:checked');
    const bar = document.getElementById('bulkBar');
    if (!bar) return;
    document.getElementById('bulkCount').textContent = checked.length;
    bar.classList.toggle('visible', checked.length > 0);
}

function post(url, paths, extra) {
    const fd = new FormData();
    fd.append('token', token);
    (paths || []).forEach(p => fd.append('paths[]', p));
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch(url, { method: 'POST', body: fd }).then(r => r.json());
}

function removeCards(absPaths) {
    const set = new Set(absPaths);
    document.querySelectorAll('.tr-card').forEach(card => {
        if (set.has(card.dataset.abs)) card.remove();
    });
    // Drop any bucket that's now empty.
    document.querySelectorAll('.bucket').forEach(b => {
        if (!b.querySelector('.tr-card')) b.remove();
    });
    // If nothing left at all, reload to show the empty state.
    if (!document.querySelector('.tr-card')) location.reload();
    refreshBar();
}

function restoreOne(card) {
    const abs = card.dataset.abs;
    post('../util/restore_from_trash.php', [abs])
        .then(d => {
            if (d.restored > 0) removeCards([abs]);
            else alert('Restore failed.');
        })
        .catch(() => alert('Network error.'));
}
function deleteOne(card) {
    if (!confirm('Permanently delete this file?\nThis cannot be undone.')) return;
    const abs = card.dataset.abs;
    post('../util/delete_permanent.php', [abs])
        .then(d => {
            if (d.deleted > 0) removeCards([abs]);
            else alert('Delete failed.');
        })
        .catch(() => alert('Network error.'));
}

function selectedAbs() {
    return Array.from(document.querySelectorAll('.tr-cb:checked'))
        .map(cb => cb.closest('.tr-card').dataset.abs);
}
function bulkRestore() {
    const abs = selectedAbs();
    if (abs.length === 0) return;
    if (!confirm('Restore ' + abs.length + ' file' + (abs.length === 1 ? '' : 's') + ' to their original location?')) return;
    post('../util/restore_from_trash.php', abs)
        .then(d => {
            if (d.restored > 0) {
                removeCards(abs);
                if (d.errors > 0) alert(d.errors + ' file(s) could not be restored.');
            } else {
                alert('Restore failed.');
            }
        })
        .catch(() => alert('Network error.'));
}
function bulkDelete() {
    const abs = selectedAbs();
    if (abs.length === 0) return;
    if (!confirm('PERMANENTLY delete ' + abs.length + ' file' + (abs.length === 1 ? '' : 's') + '?\nThis cannot be undone.')) return;
    post('../util/delete_permanent.php', abs)
        .then(d => {
            if (d.deleted > 0) {
                removeCards(abs);
                if (d.errors > 0) alert(d.errors + ' file(s) could not be deleted.');
            } else {
                alert('Delete failed.');
            }
        })
        .catch(() => alert('Network error.'));
}

function restoreAll() {
    const abs = Array.from(document.querySelectorAll('.tr-card')).map(c => c.dataset.abs);
    if (abs.length === 0) return;
    if (!confirm('Restore ALL ' + abs.length + ' trashed files to their original locations?')) return;
    post('../util/restore_from_trash.php', abs)
        .then(d => {
            location.reload();
        })
        .catch(() => alert('Network error.'));
}
function emptyTrash() {
    const cards = Array.from(document.querySelectorAll('.tr-card'));
    if (cards.length === 0) return;
    // If a folder filter is active, only the filtered cards are present in
    // the DOM — purge just those by path list. Without a filter, send
    // empty=1 to nuke the whole trash directory (cheaper, no path list).
    const filtered = new URLSearchParams(location.search).has('folder');
    const n = cards.length;
    const verb = filtered ? 'visible' : 'in trash';
    if (!confirm('PERMANENTLY delete ALL ' + n + ' files ' + verb + '?\nThis cannot be undone.')) return;
    if (!confirm('Are you really sure?')) return;
    const promise = filtered
        ? post('../util/delete_permanent.php', cards.map(c => c.dataset.abs))
        : post('../util/delete_permanent.php', [], { empty: '1' });
    promise.then(() => location.reload()).catch(() => alert('Network error.'));
}
</script>
<?php pages_emit_sidebar_drawer_js(); ?>
<?php echo app_credit_html(); ?>
</body>
</html>
