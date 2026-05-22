<?php
/*
 * Find duplicate (byte-identical) images recursively under a given folder.
 *
 * Detection strategy:
 *   1. Walk the tree, group files by exact byte size.
 *   2. For sizes shared by 2+ files, compute SHA1.
 *   3. Files sharing both size and hash are real duplicates.
 *
 * UI shows each group with thumbnails side-by-side; per-group quick-pick
 * buttons mark all-but-{oldest|newest|first} for deletion. The Delete
 * handler at the top of this file processes the marked paths.
 */

ini_set('display_errors', '1');
@set_time_limit(600);

include_once __DIR__ . "/../var.php";
include_once __DIR__ . "/pages_util.php";

// ─── Session & CSRF (shared with manage.php) ─────────────────────────────────
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
$imgRoot   = $root_path . '/' . $appName . '/images';

// ─── Path param ──────────────────────────────────────────────────────────────
$p_raw = $_GET['p'] ?? $_POST['p'] ?? '';
$p = trim(str_replace(['../','..\\'], '', $p_raw), '/');
if ($p === '' || !preg_match("#^$appName/images(/.*)?$#", $p)) {
    $p = "$appName/images";
}
$absRoot = $root_path . '/' . $p;
if (!is_dir($absRoot)) {
    header("Location: find_duplicates.php?p=" . urlencode("$appName/images")); exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function fd_is_safe_path($abs, $imgRoot) {
    $real = @realpath($abs);
    if ($real === false) return false;
    return strpos($real, $imgRoot . '/') === 0;
}

function fd_set_msg($msg, $status = 'ok') {
    $_SESSION['fd_msg']    = $msg;
    $_SESSION['fd_status'] = $status;
}

function fd_pop_msg() {
    if (!isset($_SESSION['fd_msg'])) return null;
    $m = ['msg' => $_SESSION['fd_msg'], 'status' => $_SESSION['fd_status'] ?? 'ok'];
    unset($_SESSION['fd_msg'], $_SESSION['fd_status']);
    return $m;
}

function fd_human_size($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

// ─── Trash handler (routes through util/send_to_trash.php logic inline) ─────
if (isset($_POST['action']) && $_POST['action'] === 'trash_dups') {
    if (!hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
        fd_set_msg('Invalid token.', 'error');
        header("Location: " . $_SERVER['PHP_SELF'] . '?p=' . urlencode($p)); exit;
    }
    $paths = $_POST['del'] ?? [];
    if (!is_array($paths) || empty($paths)) {
        fd_set_msg('Nothing selected.', 'alert');
        header("Location: " . $_SERVER['PHP_SELF'] . '?p=' . urlencode($p)); exit;
    }
    $trashRoot = trash_root();
    $moved = 0; $errors = 0;
    foreach ($paths as $abs) {
        if (!is_string($abs)) { $errors++; continue; }
        $real = safe_under($abs, $imgRoot);
        if ($real === false || !is_file($real)) { $errors++; continue; }
        $rel  = substr($real, strlen($imgRoot) + 1);
        $dest = $trashRoot . '/' . $rel;
        $destDir = dirname($dest);
        if (!is_dir($destDir)) { $old = umask(0); @mkdir($destDir, 0775, true); umask($old); }
        $dest = unique_target_path($dest);
        if (@rename($real, $dest)) {
            audit('fs.trash', $real . ' -> ' . $dest);
            $moved++;
        } else {
            $errors++;
        }
    }
    if ($moved > 0) @file_put_contents(state_file('change_status.txt'), '1');
    if ($moved > 0 && $errors === 0) fd_set_msg("Moved $moved duplicate" . ($moved != 1 ? 's' : '') . " to trash.", 'ok');
    elseif ($moved > 0)               fd_set_msg("Moved $moved file" . ($moved != 1 ? 's' : '') . ", $errors error" . ($errors != 1 ? 's' : '') . '.', 'alert');
    else                              fd_set_msg("No files moved ($errors error" . ($errors != 1 ? 's' : '') . ').', 'error');
    header("Location: " . $_SERVER['PHP_SELF'] . '?p=' . urlencode($p)); exit;
}

// ─── Scan ────────────────────────────────────────────────────────────────────
$IMG_EXTS = ['jpg','jpeg','png','gif','bmp','webp','avif'];

function fd_scan($dir, $imgExts, &$bySize) {
    $entries = @scandir($dir);
    if ($entries === false) return;
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        // Skip hidden (.) and user-marked-as-skipped (_) entries — both files
        // and folders. Consistent with the slideshow scan and manage views.
        if ($e[0] === '.' || $e[0] === '_') continue;
        $full = $dir . '/' . $e;
        if (is_link($full)) continue;
        if (is_dir($full)) {
            fd_scan($full, $imgExts, $bySize);
            continue;
        }
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        if (!in_array($ext, $imgExts)) continue;
        $sz = @filesize($full);
        if ($sz === false || $sz === 0) continue;
        $bySize[$sz][] = $full;
    }
}

$bySize = [];
$scanStart = microtime(true);
fd_scan($absRoot, $IMG_EXTS, $bySize);

// Pass 2: hash the size collisions.
$byHash = [];
$hashCount = 0;
foreach ($bySize as $sz => $files) {
    if (count($files) < 2) continue;
    foreach ($files as $f) {
        $h = @sha1_file($f);
        if ($h === false) continue;
        $byHash[$h][] = ['path' => $f, 'size' => $sz];
        $hashCount++;
    }
}

// Real duplicate groups = hash buckets with > 1 file.
$groups = [];
foreach ($byHash as $hash => $files) {
    if (count($files) < 2) continue;
    // Sort within group by path for stable display.
    usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
    $groups[] = ['hash' => $hash, 'files' => $files, 'size' => $files[0]['size']];
}

// Sort groups by size desc (biggest savings first).
usort($groups, fn($a, $b) => $b['size'] <=> $a['size']);

$scanSecs    = round(microtime(true) - $scanStart, 2);
$totalImages = array_sum(array_map('count', $bySize));
$dupCount    = array_sum(array_map(fn($g) => count($g['files']) - 1, $groups));
$wasted      = array_sum(array_map(fn($g) => ($g['size'] * (count($g['files']) - 1)), $groups));

$flash = fd_pop_msg();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex, nofollow">
  <title>Find Duplicates — Time Machine</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <?php emit_action_password_js(); ?>
  <style>
    body { background:#f7f7f7; color:#222; margin:0; padding-bottom:80px; font-size:14px; }
    a { text-decoration:none; }
    <?php page_chrome_styles(); ?>

    .container-dup { padding:14px; max-width:1400px; margin:0 auto; }
    .group {
        background:#fff; border:1px solid #e0e0e0; border-radius:8px;
        margin-bottom:18px; padding:12px 14px;
    }
    .group-header { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:10px; }
    .group-title { font-weight:600; }
    .group-meta  { color:#888; font-size:12px; font-family:ui-monospace,Menlo,Consolas,monospace; }
    .group-quick { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
    .group-quick button {
        font-size:12px; padding:3px 9px; border:1px solid #ccd; border-radius:4px;
        background:#f4f7ff; color:#1a4f8b; cursor:pointer;
    }
    .group-quick button:hover { background:#e7eefc; }

    .global-actions {
        position:sticky; top:0; z-index:5;
        display:flex; flex-wrap:wrap; align-items:center; gap:8px;
        padding:10px 14px; margin-bottom:14px;
        background:#fffbea; border:1px solid #f1d97a; border-radius:6px;
    }
    .global-actions .lbl { font-weight:600; color:#7a5b00; margin-right:4px; }
    .global-actions button {
        font-size:13px; padding:5px 12px; border:1px solid #d0a93a; border-radius:4px;
        background:#fff; color:#7a5b00; cursor:pointer; font-weight:500;
    }
    .global-actions button:hover { background:#fff4c2; }
    .global-actions .sep { color:#c9a236; margin:0 4px; }

    .dup-cards {
        display:grid; gap:10px;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
    .dup-card {
        border:1px solid #e0e0e0; border-radius:6px; overflow:hidden;
        background:#fff; display:flex; flex-direction:column;
        transition: border-color .15s, box-shadow .15s;
    }
    .dup-card.marked { border-color:#dc3545; box-shadow:0 0 0 2px rgba(220,53,69,.15); }
    .dup-card .img-wrap { width:100%; aspect-ratio:1/1; background:#222; overflow:hidden; }
    .dup-card .img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
    .dup-card .meta { padding:6px 9px 8px; font-size:11px; line-height:1.4; }
    .dup-card .folder { color:#666; word-break:break-all; }
    .dup-card .name   { font-weight:600; color:#222; word-break:break-all; }
    .dup-card .stats  { color:#999; margin-top:2px; }
    .dup-card .check  { padding:6px 9px; background:#fafafa; border-top:1px solid #eee; }
    .dup-card .check label { font-size:12px; cursor:pointer; user-select:none; }

    /* On phones, force the group cards side-by-side so duplicates are easy to
       compare without scrolling. Smaller thumbs + tighter type to fit. */
    @media (max-width: 600px) {
        .dup-cards { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap:6px; }
        .dup-card .meta { padding:4px 5px 5px; font-size:10px; line-height:1.3; }
        .dup-card .folder { display:none; }                /* save vertical space */
        .dup-card .name { font-size:10px; }
        .dup-card .check { padding:4px 5px; }
        .dup-card .check label { font-size:10px; }
        .group { padding:8px 8px; margin-bottom:12px; }
        .group-header { gap:6px; margin-bottom:6px; font-size:12px; }
        .group-quick button { font-size:10px; padding:2px 6px; }
    }

    .empty-state { text-align:center; padding:60px 20px; color:#888; }
    .empty-state .big-icon { font-size:48px; color:#cde; margin-bottom:14px; }

    .bulk-bar {
        position:fixed; bottom:0; left:0; right:0;
        background:#fff; border-top:1px solid #ddd;
        padding:10px 14px; display:none;
        box-shadow:0 -2px 8px rgba(0,0,0,.08); z-index:50;
    }
    .bulk-bar.visible { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .bulk-bar .count { font-weight:600; color:#dc3545; }

    .message { padding:8px 12px; border:1px solid #ddd; background:#fff; margin-bottom:14px; border-radius:4px; }
    .message.ok    { border-color:#28a745; color:#28a745; }
    .message.error { border-color:#dc3545; color:#dc3545; }
    .message.alert { border-color:#fd7e14; color:#fd7e14; }
  </style>
</head>
<body>

<?php
$urlP = urlencode($p);
$stats = '<span>Scanned: <b>' . number_format($totalImages) . '</b> images</span>'
       . '<span>Duplicate groups: <b>' . count($groups) . '</b></span>'
       . '<span>Removable copies: <b>' . $dupCount . '</b></span>'
       . ($wasted > 0 ? '<span class="savings">Reclaimable: ' . fd_human_size($wasted) . '</span>' : '')
       . '<span style="margin-left:auto; color:#999; font-size:11px;">scan: ' . $scanSecs . 's</span>';

page_header([
    'title'    => 'Find Duplicates',
    'context'  => $p,
    'backHref' => '../manage.php?p=' . $urlP,
    'statsHtml'=> $stats,
]);
?>

<div class="container-dup">

<?php if ($flash): ?>
  <div class="message <?php echo htmlspecialchars($flash['status']) ?>">
    <?php echo $flash['msg'] ?>
  </div>
<?php endif; ?>

<?php if (empty($groups)): ?>
  <div class="empty-state">
    <div class="big-icon"><i class="fa fa-check-circle"></i></div>
    <h4>No byte-identical duplicates found</h4>
    <p>Scanned <?php echo number_format($totalImages) ?> images under <code><?php echo htmlspecialchars($p) ?></code>.</p>
    <p><a class="btn btn-outline-primary" href="../manage.php?p=<?php echo urlencode($p) ?>">&larr; Back to manager</a></p>
  </div>
<?php else: ?>

<form method="post" id="dupForm">
  <input type="hidden" name="action" value="trash_dups">
  <input type="hidden" name="token"  value="<?php echo $_SESSION['token'] ?>">
  <input type="hidden" name="p"      value="<?php echo htmlspecialchars($p) ?>">

  <div class="global-actions" title="Apply the same selection rule to every duplicate group below">
    <span class="lbl">Apply to all <?php echo count($groups) ?> group<?php echo count($groups) === 1 ? '' : 's' ?>:</span>
    <button type="button" onclick="markAll('oldest')">Keep oldest</button>
    <button type="button" onclick="markAll('newest')">Keep newest</button>
    <button type="button" onclick="markAll('first')">Keep first</button>
    <span class="sep">|</span>
    <button type="button" onclick="markAll('clear')">Clear all</button>
  </div>

  <?php foreach ($groups as $gi => $g):
      $count = count($g['files']);
      $totalThis = $g['size'] * ($count - 1);
  ?>
  <div class="group" data-group="<?php echo $gi ?>">
    <div class="group-header">
      <div>
        <span class="group-title"><?php echo $count ?> duplicates · <?php echo fd_human_size($g['size']) ?> each</span>
        <div class="group-meta">SHA1: <?php echo substr($g['hash'], 0, 12) ?>… &middot; saves <?php echo fd_human_size($totalThis) ?> if all but one removed</div>
      </div>
      <div class="group-quick">
        <button type="button" onclick="markGroup(<?php echo $gi ?>, 'oldest')">Mark all but oldest</button>
        <button type="button" onclick="markGroup(<?php echo $gi ?>, 'newest')">Mark all but newest</button>
        <button type="button" onclick="markGroup(<?php echo $gi ?>, 'first')">Mark all but first</button>
        <button type="button" onclick="markGroup(<?php echo $gi ?>, 'clear')">Clear group</button>
      </div>
    </div>
    <div class="dup-cards">
      <?php foreach ($g['files'] as $fi => $f):
          $abs = $f['path'];
          $rel = ltrim(str_replace($root_path, '', $abs), '/');
          $folder = dirname(str_replace($appName . '/images/', '', $rel));
          $name   = basename($abs);
          $ts     = image_date_ts($abs);
          $dateStr = $ts ? date('j M Y', $ts) : '—';
          $cardId = "card_{$gi}_{$fi}";
      ?>
        <div class="dup-card" id="<?php echo $cardId ?>" data-ts="<?php echo $ts ?>">
          <div class="img-wrap">
            <a href="/<?php echo htmlspecialchars($rel) ?>" target="_blank">
              <img loading="lazy" src="/<?php echo htmlspecialchars($rel) ?>" alt="<?php echo htmlspecialchars($name) ?>">
            </a>
          </div>
          <div class="meta">
            <div class="folder"><i class="fa fa-folder-o" style="color:#0157b3"></i> <?php echo htmlspecialchars($folder ?: '(root)') ?></div>
            <div class="name"><?php echo htmlspecialchars($name) ?></div>
            <div class="stats"><?php echo $dateStr ?></div>
          </div>
          <div class="check">
            <label>
              <input type="checkbox" name="del[]" value="<?php echo htmlspecialchars($abs) ?>"
                     onchange="onCheckChange(this)" data-group="<?php echo $gi ?>">
              Mark for deletion
            </label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</form>

<div class="bulk-bar" id="bulkBar">
  <span><span class="count" id="bulkCount">0</span> marked to move to trash</span>
  <button type="button" class="btn btn-sm btn-warning" onclick="submitDelete()">
    <i class="fa fa-trash"></i> Move marked to trash
  </button>
  <button type="button" class="btn btn-sm btn-light" onclick="clearAll()">Clear all</button>
</div>

<?php endif; ?>

</div>

<script>
function refreshBulkBar() {
    const checked = document.querySelectorAll('input[name="del[]"]:checked');
    const bar = document.getElementById('bulkBar');
    const cnt = document.getElementById('bulkCount');
    if (!bar) return;
    cnt.textContent = checked.length;
    bar.classList.toggle('visible', checked.length > 0);
}

function onCheckChange(cb) {
    const card = cb.closest('.dup-card');
    if (card) card.classList.toggle('marked', cb.checked);
    refreshBulkBar();
}

function clearAll() {
    document.querySelectorAll('input[name="del[]"]:checked').forEach(cb => {
        cb.checked = false;
        const card = cb.closest('.dup-card');
        if (card) card.classList.remove('marked');
    });
    refreshBulkBar();
}

/**
 * mode: 'oldest' | 'newest' | 'first' | 'clear'
 *  - oldest/newest: keep card with smallest/largest data-ts
 *  - first:        keep first card in DOM order
 *  - clear:        unmark all in this group
 */
function markGroup(groupIdx, mode) {
    const group = document.querySelector(`.group[data-group="${groupIdx}"]`);
    if (!group) return;
    const cards = Array.from(group.querySelectorAll('.dup-card'));
    if (cards.length === 0) return;

    if (mode === 'clear') {
        cards.forEach(c => {
            const cb = c.querySelector('input[name="del[]"]');
            if (cb) { cb.checked = false; c.classList.remove('marked'); }
        });
        refreshBulkBar();
        return;
    }

    let keepCard = null;
    if (mode === 'first') {
        keepCard = cards[0];
    } else {
        const sorted = cards.slice().sort((a, b) => {
            const ta = parseInt(a.dataset.ts || '0');
            const tb = parseInt(b.dataset.ts || '0');
            return ta - tb;
        });
        keepCard = (mode === 'oldest') ? sorted[0] : sorted[sorted.length - 1];
    }

    cards.forEach(c => {
        const cb = c.querySelector('input[name="del[]"]');
        if (!cb) return;
        const shouldDelete = (c !== keepCard);
        cb.checked = shouldDelete;
        c.classList.toggle('marked', shouldDelete);
    });
    refreshBulkBar();
}

function markAll(mode) {
    document.querySelectorAll('.group').forEach(g => {
        markGroup(parseInt(g.dataset.group, 10), mode);
    });
}

function submitDelete() {
    const checked = document.querySelectorAll('input[name="del[]"]:checked');
    if (checked.length === 0) return;
    if (!requireActionPassword('moving ' + checked.length + ' file' + (checked.length != 1 ? 's' : '') + ' to trash')) return;
    if (!confirm('Move ' + checked.length + ' duplicate file' + (checked.length != 1 ? 's' : '') + ' to trash? They will be recoverable from the Trash view.')) return;
    document.getElementById('dupForm').submit();
}

document.addEventListener('DOMContentLoaded', refreshBulkBar);
</script>

<?php echo app_credit_html(); ?>
</body>
</html>
