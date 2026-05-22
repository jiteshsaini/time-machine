<?php
/*
 * manage_util.php — shared rendering + helper library.
 *
 * Used by manage.php (the front-end UI), manage_ops.php (backend handlers
 * that emit small HTML fragments), api/search_folders.php, and the pages
 * under pages/ (upload, trash, starred, crop, find_duplicates, set_order).
 *
 * No URL of its own — included for its function definitions. Side effect
 * on include: sweep_dead_paths_() runs once, pruning stale entries from
 * paths.txt / starred_paths.txt.
 *
 * Include AFTER var.php (these helpers call state_file(), is_skipped_name(),
 * path_affects_slideshow_(), …).
 *
 * Major surfaces:
 *   mgr_style_chrome()              — CSS for top-strip, breadcrumb, status bar,
 *                                     sidebar drawer, snackbar, toggles, etc.
 *   mgr_render_top_header()         — fixed top-strip (nav + breadcrumb)
 *   mgr_render_status_bar()         — info-bar pills (folder/img counts, settings)
 *   mgr_render_folder_action_bar()  — current-folder name + stats + slideshow toggle
 *   mgr_render_sidebar_chrome()     — drawer aside + backdrop shell
 *   mgr_render_shared_js()          — toast, toggleSlideshowFolder, drawer JS,
 *                                     slideshow-modal refresh, pill setup
 *   mgr_render_*modal               — New Folder / Settings / Actions modals
 *   render_folder_row()             — single-row HTML used by search results
 *                                     and the get_folder_rows AJAX endpoint
 *   mgr_count_starred / mgr_count_dir_images / mgr_human_size_ / …
 */

/**
 * CSS block for the shared chrome. Call inside <style>…</style> in <head>.
 */
function mgr_style_chrome() { ?>
    /* App credit footer — present on all management pages. */
    .app-credit { text-align:center; padding:16px 12px; margin-top:24px;
                  font-size:12px; color:#999; border-top:1px solid #eee; }
    .app-credit a { color:#888; text-decoration:none; }
    .app-credit a:hover { color:#555; text-decoration:underline; }

    /* Trash view tint — overrides for top strip and breadcrumb. */
    /* Fallback offset for the fixed top-strip — JS refines to the exact value
       once layout is computed. Close enough to avoid content-behind-header. */
    body{padding-top:95px;}
    .top-strip-trash{box-shadow:0 2px 0 0 #dc3545 inset, 0 2px 6px rgba(0,0,0,.06);}
    .top-strip-trash .main-nav.bg-trash{background:#fff5f5!important;}
    .top-strip-trash .main-nav{background:#fff5f5!important;}
    .top-strip-trash .breadcrumb-bar{background:#fff5f5;}
    .top-strip-trash .breadcrumb-bar .bread-crumb{color:#dc3545;}
    .trash-banner{
        background:#fff;border-top:1px solid #f5c2c7;
        padding:6px 16px;font-size:12.5px;color:#842029;
        display:flex;align-items:center;gap:8px;
    }
    .trash-banner i{color:#dc3545}

    /* Breadcrumb bar — chevrons, bold current, blue ancestors. */
    .breadcrumb-bar{background:#f1f5fb;border-bottom:1px solid #d8e3f1;padding:8px 16px;font-size:15px;line-height:1.4;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .breadcrumb-bar .bc-path{flex:1 1 auto;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
    .breadcrumb-bar .bc-actions{flex:0 0 auto;}
    /* Shared brand sizing so list view and grid view match. */
    .main-nav{padding:0.4rem 1rem;}
    .main-nav .navbar-brand{font-weight:bold;font-size:17px;}
    /* Compact menu trigger — Bootstrap's default toggler is oversized. Hidden
       on desktop (lg+); shown only on smaller screens. */
    .nav-toggle-sm{padding:4px 8px;background:transparent;border:1px solid #ccc;border-radius:4px;line-height:1;cursor:pointer;}
    .nav-toggle-sm:hover{background:#f1f5fb;}
    .nav-toggle-sm .navbar-toggler-icon{display:inline-block;width:18px;height:18px;background-size:contain;background-repeat:no-repeat;background-position:center;background-image:url("data:image/svg+xml;charset=utf8,%3Csvg viewBox='0 0 30 30' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath stroke='rgba(0,0,0,0.7)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E");}

    /* Desktop: items inline. Bootstrap's navbar-expand-lg already handles this
       for the collapse, so we only need to style the nav-links. */
    .main-nav .navbar-nav .nav-link{padding:6px 10px;color:#333;border-radius:4px;}
    .main-nav .navbar-nav .nav-link:hover{background:#f1f5fb;}
    .main-nav .navbar-nav .nav-link i{margin-right:5px;color:#555;}

    /* Mobile (below lg): collapse opens as a floating popover anchored to the
       right of the navbar, instead of pushing the brand-row's height. */
    @media (max-width: 991.98px) {
        .main-nav .navbar-collapse{
            position:absolute; top:100%; right:0;
            min-width:200px; max-width:90vw;
            background:#fff; border:1px solid #d8e3f1; border-radius:6px;
            box-shadow:0 6px 18px rgba(0,0,0,0.08);
            padding:6px; margin-top:4px;
            z-index:1031;
        }
        .main-nav .navbar-collapse .navbar-nav{flex-direction:column;align-items:stretch;}
        .main-nav .navbar-collapse .nav-link{padding:8px 10px;}
    }
    /* The Grid/List quick-toggle stays visible at all sizes — never collapsed
       into the hamburger. */
    .view-toggle{font-size:13px;padding:4px 10px;border:1px solid #d8e3f1;border-radius:4px;color:#0157b3;text-decoration:none;white-space:nowrap;}
    .view-toggle:hover{background:#e4ecf7;text-decoration:none;}
    .view-toggle i{margin-right:4px;}
    /* Status-bar pill variants for Starred + Trash links. Same shape as the
       other pills; subtle color cues on the icon so they're discoverable
       but quiet — not the loud red/yellow badges they used to be. */
    .status-bar .sb-link{ text-decoration:none; color:inherit; cursor:pointer; }
    .status-bar .sb-link:hover{ background:#eef3fb; text-decoration:none; }
    .status-bar .sb-star-link i{ color:#f0ad4e; }
    .status-bar .sb-trash-link i{ color:#dc3545; }
    /* Search shrinks on narrow viewports so the hamburger stays on the same
       row instead of wrapping below. */
    .nav-search{padding:4px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;min-width:120px;max-width:240px;flex:1 1 160px;}
    /* The Add-to-slideshow action lives in the breadcrumb bar. Compact, icon-led;
       label collapses to icon-only on very narrow screens. */
    .bc-action{font-size:13px;}
    .bc-action i{font-size:14px;}
    @media (max-width: 600px){
        .nav-search{min-width:0;max-width:none;flex:1 1 0;}
        .view-toggle .vt-label{display:none;}
        .view-toggle{padding:4px 8px;}
        .bc-action .bca-label{display:none;}
        /* Brand text takes premium horizontal space on phones — keep it for
           tablet/desktop, drop on phones so search + actions fit on one row. */
        .main-nav .navbar-brand{display:none;}
        /* Sitemap button: tighter padding so it doesn't dominate. */
        .sidebar-drawer-btn{padding:4px 7px;margin-right:4px;}
    }
    .breadcrumb-bar .bc-home{color:#0157b3;padding:2px 6px;border-radius:4px;}
    .breadcrumb-bar .bc-home:hover{background:#e4ecf7;}
    .breadcrumb-bar .bc-link{color:#0157b3;font-weight:500;padding:2px 4px;border-radius:3px;}
    .breadcrumb-bar .bc-link:hover{background:#e4ecf7;text-decoration:underline!important;}
    .breadcrumb-bar .bc-current{color:#222;font-weight:700;cursor:default;padding:2px 4px;}
    .bread-crumb{color:#9aacc3;font-size:12px;margin:0 4px;}

    /* Status bar. */
    .status-bar{background:#fff;border-top:1px solid #e3e6ea;padding:6px 16px;font-size:13px;color:#444;display:flex;flex-wrap:wrap;gap:18px;align-items:center;}
    .status-bar .sb-item{display:flex;align-items:center;gap:5px;}
    .status-bar .sb-val{font-weight:600;color:#222;}
    .status-bar .sb-on{color:#28a745;font-weight:700;}
    .status-bar .sb-off{color:#999;}
    /* Clickable status pills — direct toggles for shuffle / fit / date and a
       shortcut into the delay modal. Hover bg makes the affordance visible. */
    .status-bar .sb-clickable{cursor:pointer;border-radius:4px;padding:2px 6px;margin:-2px -6px;transition:background .12s;user-select:none;}
    .status-bar .sb-clickable:hover{background:#eef3fb;}

    /* Mobile: tighten status bar so 7-8 items wrap cleanly to 2 rows
       without orphaned items. The date pill is the least-changed setting —
       hidden on phones to free room. */
    @media (max-width: 600px) {
        .status-bar{gap:10px;padding:6px 10px;font-size:12px;}
        .status-bar .sb-item{gap:3px;}
        .status-bar .sb-clickable{padding:2px 4px;margin:-2px -4px;}
        #sbDatePill{display:none;}
    }

    /* Folder action strip — current folder + Add-to-slideshow toggle. Sits
       above the table (list view) and above the chips/ctrl-row (grid view). */
    .folder-action-bar{background:#fff;border-bottom:1px solid #e3e6ea;
        padding:8px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;
        font-size:14px;color:#333;}
    .folder-action-bar .fab-info{display:flex;align-items:center;gap:8px;min-width:0;flex:1;}
    .folder-action-bar .fab-icon{color:#e8a838;font-size:18px;flex-shrink:0;}
    .folder-action-bar .fab-name{font-weight:600;color:#222;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .folder-action-bar .fab-stats{display:inline-flex;gap:10px;margin-left:6px;
        color:#666;font-size:13px;white-space:nowrap;flex-wrap:wrap;}
    .folder-action-bar .fab-stat{display:inline-flex;align-items:center;gap:4px;}
    .folder-action-bar .fab-stat i{font-size:13px;}
    .folder-action-bar .fab-skip{color:#aaa;font-size:.85em;font-style:italic;margin-left:2px;}
    .folder-action-bar .fab-action{display:flex;align-items:center;gap:10px;flex-shrink:0;}
    .folder-action-bar .fab-label{color:#555;font-size:13px;}
    .folder-action-bar .fab-via{color:#6c757d;font-size:13px;
        background:#f1f3f5;border:1px solid #dee2e6;border-radius:4px;padding:3px 9px;}
    /* Mobile: intentional two-row layout — name + toggle on row 1
       (toggle right-aligned), stats as a subtitle on row 2. Drops the
       "Add to slideshow" label since the slider's color tells the same
       story without eating horizontal space. */
    @media (max-width: 600px) {
        .folder-action-bar{padding:6px 10px;gap:6px;align-items:flex-start;}
        .folder-action-bar .fab-info{flex-wrap:wrap;flex:1;gap:6px;}
        .folder-action-bar .fab-name{flex:1;min-width:0;font-size:14px;}
        .folder-action-bar .fab-stats{flex:0 0 100%;margin-left:26px;
            font-size:12px;gap:8px;}
        .folder-action-bar .fab-action{align-self:flex-start;}
        .folder-action-bar .fab-label{display:none;}
        .folder-action-bar .fab-via,
        .folder-action-bar .fab-disabled{font-size:12px;padding:2px 6px;}
    }
    .folder-action-bar .fab-disabled{color:#9c5400;font-size:13px;
        background:#fff8f1;border:1px solid #fcd9b4;border-radius:4px;padding:3px 9px;}

    /* Green slider toggle — used by the per-folder-row toggle in list view
       AND by the current-folder toggle in the action strip (both views). */
    .fm-toggle{position:relative;display:inline-block;width:46px;height:26px;cursor:pointer;vertical-align:middle;margin:0;}
    .fm-toggle input{opacity:0;width:0;height:0;position:absolute;}
    .fm-toggle-slider{position:absolute;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:26px;transition:.25s;}
    .fm-toggle-slider:before{content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.3);}
    .fm-toggle input:checked + .fm-toggle-slider{background:#28a745;}
    .fm-toggle input:checked + .fm-toggle-slider:before{transform:translateX(20px);}
    /* "Via parent" state — toggle is ON (folder IS in slideshow via an
       ancestor) but disabled. Diagonal hatching makes it visually distinct
       from a directly-toggled ON, so the user understands the difference. */
    .fm-toggle.fm-via{cursor:not-allowed;}
    .fm-toggle.fm-via .fm-toggle-slider{
        background:repeating-linear-gradient(45deg,#28a745,#28a745 4px,#5fc775 4px,#5fc775 8px);
        opacity:.85;
    }
    .fm-toggle.fm-via input{cursor:not-allowed;}
    /* Small "via PARENT" badge inline with folder name. */
    .via-badge{display:inline-block;margin-left:8px;padding:1px 7px;font-size:11px;
        color:#1e7a36;background:#e8f5ec;border:1px solid #b4dfc1;border-radius:3px;
        cursor:help;vertical-align:middle;white-space:nowrap;}
    .via-badge i{margin-right:2px;color:#28a745;font-size:10px;}
    .via-badge b{color:#155a26;font-weight:600;}

    /* "Descendant in slideshow" hint dot on list-view rows. */
    .in-show-hint{display:inline-block;color:#28a745;font-size:1.4em;line-height:.9;
        margin-left:8px;cursor:help;vertical-align:middle;text-shadow:0 0 6px rgba(40,167,69,.45);}

    /* ── Shared sidebar drawer chrome ──────────────────────────────────────
       Used by manage.php (folder tree), pages/trash.php and
       pages/starred.php (filter-by-folder lists). Drawer on mobile,
       fixed column on desktop. Pages set --sidebar-top to clear their
       own fixed/sticky header — defaults to 0. */
    :root { --sidebar-top: 0; }
    #folderSidebar {
        position:fixed; top:var(--sidebar-top); left:0; bottom:0;
        width:280px; max-width:85vw; background:#fbfbfd;
        border-right:1px solid #e0e0e0;
        z-index:1040;
        display:flex; flex-direction:column;
        box-shadow:1px 0 4px rgba(0,0,0,.04);
        transform:translateX(-100%);
        transition:transform .2s ease-out;
    }
    body.sidebar-open #folderSidebar { transform:translateX(0); }
    #sidebarBackdrop {
        position:fixed; top:var(--sidebar-top); left:0; right:0; bottom:0;
        background:rgba(0,0,0,.35);
        z-index:1035; opacity:0; pointer-events:none;
        transition:opacity .2s;
    }
    body.sidebar-open #sidebarBackdrop { opacity:1; pointer-events:auto; }
    .sidebar-drawer-btn {
        display:none;
        background:transparent; border:1px solid #d8dde5; color:#0157b3;
        padding:4px 9px; margin-right:8px; border-radius:4px; cursor:pointer;
        font-size:15px; line-height:1;
    }
    .sidebar-drawer-btn:hover { background:#eef2f8; }
    body.has-sidebar .sidebar-drawer-btn { display:inline-flex; align-items:center; }
    /* Shared sidebar inner header (title + optional collapse toggle). */
    .sb-head {
        display:flex; align-items:center; gap:6px; padding:8px 10px;
        border-bottom:1px solid #e6e6ea; background:#fff;
        font-size:12px; color:#666; font-weight:600; letter-spacing:.3px;
        text-transform:uppercase; flex:0 0 auto;
    }
    .sb-head-title { flex:1; display:flex; align-items:center; gap:6px; }
    .sb-head-title i { color:#0157b3; }
    .sb-scroll { flex:1; overflow-y:auto; padding:6px 4px 80px; }
    /* Flat folder-filter list (used by trash + starred sidebars). */
    .sb-flist { list-style:none; padding:0; margin:0; }
    .sb-flist li { margin:0; }
    .sb-flist a {
        display:flex; align-items:center; gap:8px; padding:7px 12px;
        color:#333; font-size:13px; text-decoration:none;
        border-left:3px solid transparent;
    }
    .sb-flist a:hover { background:#eef2f8; }
    .sb-flist a.active { background:#dbeafe; border-left-color:#0157b3; font-weight:600; color:#0157b3; }
    .sb-flist a i { color:#bba56b; font-size:13px; flex-shrink:0; }
    .sb-flist a .sb-flist-name { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-flist a .sb-flist-count { color:#888; font-size:11px; font-variant-numeric:tabular-nums; flex-shrink:0; }
    .sb-flist a.active .sb-flist-count { color:#0157b3; }

    @media (min-width: 1024px) {
        #folderSidebar {
            width:250px; max-width:none; transform:none; transition:none;
            z-index:60; box-shadow:1px 0 4px rgba(0,0,0,.04);
        }
        #sidebarBackdrop { display:none; }
        .sidebar-drawer-btn { display:none !important; }
        body.has-sidebar { padding-left:250px; }
        body.has-sidebar .bulk-bar { left:250px; }
    }

    /* Toast / snackbar — used by toast() in shared JS (both views). */
    #snackbar{visibility:hidden;min-width:250px;margin-left:-125px;background:#333;color:#fff;text-align:center;border-radius:2px;padding:16px;position:fixed;z-index:9999;left:50%;bottom:30px;font-size:17px;}
    #snackbar.show{visibility:visible;animation:fadein .5s,fadeout .5s 2.5s;}
    @keyframes fadein{from{bottom:0;opacity:0}to{bottom:30px;opacity:1}}
    @keyframes fadeout{from{bottom:30px;opacity:1}to{bottom:0;opacity:0}}
<?php }

/**
 * Build the styled breadcrumb HTML for $path (e.g. "time_machine/images/2024").
 * Skips the app-name prefix automatically.
 */
function mgr_breadcrumb_html($path) {
    global $appName;
    $sep    = '<i class="bread-crumb fa fa-angle-right"></i>';
    $crumb  = "<a href='?p=" . urlencode($appName . '/images') . "' class='bc-home' title='Home'><i class='fa fa-home'></i></a>";
    if ($path === '') return $crumb;
    $parts  = explode('/', $path);
    $walk   = '';
    $crumbs = [];
    $lastIdx = count($parts) - 1;
    foreach ($parts as $idx => $part) {
        $walk = ltrim($walk . '/' . $part, '/');
        if ($idx === 0 && $part === $appName) continue;
        $cls = ($idx === $lastIdx) ? 'bc-current' : 'bc-link';
        $crumbs[] = "<a href='?p=" . urlencode($walk) . "' class='$cls'>" . htmlspecialchars($part, ENT_QUOTES) . "</a>";
    }
    if (!empty($crumbs)) $crumb .= $sep . implode($sep, $crumbs);
    return $crumb;
}

/**
 * Helper used by status bar — recursive image count under a directory.
 * (Defined here so it works even when called from grid view without manage.php.)
 */
// Recursive count of starred images under $dir. Drives the navbar star
// quick-icon's count badge.
function mgr_count_starred($dir) {
    static $exts = ['gif','jpg','jpeg','png','bmp','ico','svg','webp','avif'];
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.' || $f[0] === '_') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full)) { $n += mgr_count_starred($full); continue; }
        if (!is_file($full)) continue;
        if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) continue;
        if (is_starred_name($f)) $n++;
    }
    return $n;
}

// Unfiltered recursive image count — used for trash, where every trashed
// image counts regardless of whether its name starts with '_'.
function mgr_count_dir_images_raw($dir) {
    static $exts = ['gif','jpg','jpeg','png','bmp','ico','svg','webp','avif'];
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full))  { $n += mgr_count_dir_images_raw($full); continue; }
        if (!is_file($full)) continue;
        if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) $n++;
    }
    return $n;
}

// Counts images the slideshow will actually serve. Mirrors get_images.php:
// skips hidden (.*) AND skipped (_*) names at every level — without this the
// status bar / modal show a higher number than the slideshow actually plays.
function mgr_count_dir_images($dir) {
    static $exts = ['gif','jpg','jpeg','png','bmp','ico','svg','webp','avif'];
    if (!is_dir($dir)) return 0;
    // Skip the entire subtree if the root path is itself skipped (any segment
    // begins with '_' or '.'), so counts match what get_images.php serves.
    if (is_skipped_path($dir)) return 0;
    $n = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.' || $f[0] === '_') continue;
        $full = $dir . '/' . $f;
        if (is_dir($full)) { $n += mgr_count_dir_images($full); continue; }
        if (is_file($full) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts)) $n++;
    }
    return $n;
}

/**
 * Render a small "N starred images also in the slideshow" note for the
 * Slideshow modal body. The slideshow plays the union of paths.txt folders
 * and starred_paths.txt files — without this note, users can't tell why the
 * TV is showing photos beyond what the Active folders tab lists.
 *
 * Returns an empty string when starred_paths.txt is empty or all entries
 * point at missing files.
 */
function mgr_render_starred_banner() {
    $file = state_file('starred_paths.txt');
    if (!file_exists($file)) return '';
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return '';
    $count = 0;
    foreach ($lines as $p) { if (is_file(trim($p))) $count++; }
    if ($count === 0) return '';
    $noun = $count === 1 ? 'starred image' : 'starred images';
    return '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">'
         . '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;'
         . 'background:#eef4fb;border:1px solid #cfe0f3;border-radius:999px;font-size:12px;color:#0157b3">'
         .   '<i class="fa fa-star" style="color:#f0ad4e;font-size:11px"></i>'
         .   '<b>' . $count . '</b>&nbsp;' . $noun
         .   '<a href="pages/starred.php?inslideshow=1" '
         .   'style="color:#0157b3;text-decoration:none;font-weight:600;margin-left:4px;'
         .   'padding-left:8px;border-left:1px solid #cfe0f3" '
         .   'title="Open the Starred page filtered to only files currently in the slideshow">Manage &rsaquo;</a>'
         . '</span>'
         . '</div>';
}

/**
 * Render the status bar (active folders / images / delay / shuffle / fit / date).
 */
function mgr_render_status_bar() {
    $existingPaths = file_exists(state_file('paths.txt'))
        ? file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $sb_folder_count = count($existingPaths);
    $sb_img_count    = 0;
    foreach ($existingPaths as $ep) {
        if (is_dir(trim($ep))) $sb_img_count += mgr_count_dir_images(trim($ep));
    }
    $sb_delay   = (file_exists(state_file('delay.txt')) && trim(file_get_contents(state_file('delay.txt'))) !== '')
                ? (int) trim(file_get_contents(state_file('delay.txt'))) : '—';
    $sb_shuffle = (file_exists(state_file('shuffle.txt')) && trim(file_get_contents(state_file('shuffle.txt'))) === '1');
    $sb_fit     = (file_exists(state_file('fit_mode.txt')) && trim(file_get_contents(state_file('fit_mode.txt'))) === 'cover') ? 'cover' : 'contain';
    $sb_date    = (file_exists(state_file('show_date.txt')) && trim(file_get_contents(state_file('show_date.txt'))) === '1');
    // Starred: no count here — mgr_count_starred would walk the entire
    // images/ tree on every page load (1-3 s on libraries of tens of
    // thousands of files). The starred page itself shows the full stat.
    // Trash: cheap to count (only walks the trash dir, typically small),
    // and the count doubles as a "you have stuff to clean up" cue.
    $sb_trash_count = mgr_count_dir_images_raw(trash_root());
?>
<div class="status-bar">
  <span class="sb-item" title="Active folders in slideshow">
    <i class="fa fa-folder-open-o" style="color:#0157b3"></i>
    <span class="sb-val" id="sb-folder-count"><?php echo $sb_folder_count ?></span>
  </span>
  <span class="sb-item sb-clickable" data-bs-toggle="modal" data-bs-target="#slideshowModal"
        title="Click to open the slideshow modal">
    <i class="fa fa-picture-o" style="color:#26b99a"></i>
    <span class="sb-val" id="sb-img-count"><?php echo number_format($sb_img_count) ?></span>
  </span>
  <span class="sb-item sb-clickable" id="sbDelayPill"
        title="Click to change the delay between images">
    <i class="fa fa-hourglass-half" style="color:#888"></i>
    <span class="sb-val" id="sbDelayVal"><?php echo is_int($sb_delay) ? $sb_delay . 's' : $sb_delay ?></span>
  </span>
  <span class="sb-item sb-clickable" id="sbShufflePill"
        title="Click to toggle shuffle">
    <i class="fa fa-random" style="color:#888"></i>
    <span id="sbShuffleVal" class="<?php echo $sb_shuffle ? 'sb-on' : 'sb-off' ?>"><?php echo $sb_shuffle ? 'ON' : 'OFF' ?></span>
  </span>
  <span class="sb-item sb-clickable" id="sbFitPill"
        title="Click to switch fit mode (contain ↔ cover)">
    <i class="fa fa-arrows-alt" style="color:#888"></i>
    <span id="sbFitVal" class="sb-val"><?php echo $sb_fit ?></span>
  </span>
  <span class="sb-item sb-clickable" id="sbDatePill"
        title="Click to toggle the date overlay on the slideshow">
    <i class="fa fa-calendar-o" style="color:#888"></i>
    <span id="sbDateVal" class="<?php echo $sb_date ? 'sb-on' : 'sb-off' ?>"><?php echo $sb_date ? 'ON' : 'OFF' ?></span>
  </span>
  <a class="sb-item sb-link sb-star-link" href="pages/starred.php"
     title="Open starred — stats and filter on that page">
    <i class="fa fa-star"></i>
  </a>
  <?php if ($sb_trash_count > 0): ?>
  <a class="sb-item sb-link sb-trash-link" href="pages/trash.php"
     title="Open trash (<?php echo $sb_trash_count ?> item<?php echo $sb_trash_count === 1 ? '' : 's' ?>)">
    <i class="fa fa-trash-o"></i>
    <span class="sb-val"><?php echo number_format($sb_trash_count) ?></span>
  </a>
  <?php endif; ?>
</div>
<?php
}

/**
 * Output the "You're in the Trash" banner if $inTrash is true; otherwise no-op.
 */
function mgr_render_trash_banner_if($inTrash) {
    if (!$inTrash) return;
?>
<div class="trash-banner">
  <i class="fa fa-trash"></i>
  <span><b>You're in the Trash.</b> Items here aren't in the slideshow. Restore to recover, or delete permanently to free disk space.</span>
</div>
<?php
}

/**
 * Render the Actions modal (#actionsModal) — hub for folder-level mutating
 * operations: New Folder / Resize / Re-Order / Find Duplicates.
 *
 * Each item is context-gated:
 *   - In trash view, nothing renders (returns immediately)
 *   - Resize / Find Duplicates only show when folder has images (recursive)
 *   - Re-Order only shows when folder has direct (visible) files
 */
function mgr_render_actions_modal($path, $inTrash) {
    if ($inTrash) return;
    $absPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $path;
    $imgCount = mgr_count_dir_images($absPath);

    // "Files directly here" check — for Re-Order, which is single-folder
    $hasFilesHere = false;
    if (is_dir($absPath)) {
        foreach (scandir($absPath) as $f) {
            if ($f === '.' || $f === '..' || $f[0] === '.' || $f[0] === '_') continue;
            if (is_file($absPath . '/' . $f)) { $hasFilesHere = true; break; }
        }
    }

    $returnUrl = htmlspecialchars($_SERVER['REQUEST_URI']);
    $confirmMsg = htmlspecialchars(
        "$imgCount image" . ($imgCount != 1 ? 's' : '')
        . " in $path (and subfolders) will be checked. Anything above 1920x1080 will be resized. Proceed?",
        ENT_QUOTES
    );
?>
<div class="modal fade" id="actionsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-bolt"></i> Actions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body d-flex flex-column gap-2">

        <a href="#createNewItem" data-bs-toggle="modal" data-bs-target="#createNewItem"
           data-bs-dismiss="modal"
           class="btn btn-outline-primary text-start">
          <i class="fa fa-folder-o"></i> New Folder
        </a>

        <?php if ($imgCount > 0 && $imgCount <= RESIZE_MAX_RECURSIVE): ?>
        <a href="util/img_resize.php?p=<?php echo htmlspecialchars($absPath) ?>&t=<?php echo time() ?>"
           onclick="return confirm('<?php echo $confirmMsg ?>');"
           class="btn btn-outline-primary text-start"
           title="Recursively resize anything above 1920×1080 to that limit. Files already small get skipped. Processed in batches of <?php echo RESIZE_BATCH_SIZE ?>.">
          <i class="fa fa-compress"></i> Resize <span class="text-muted">(<?php echo $imgCount ?> img<?php echo $imgCount != 1 ? 's' : '' ?>, recursive)</span>
        </a>
        <?php elseif ($imgCount > RESIZE_MAX_RECURSIVE): ?>
        <?php
        $tooMany = "This folder has " . number_format($imgCount)
                 . " images — more than the " . number_format(RESIZE_MAX_RECURSIVE) . " a single resize session can handle.\n\n"
                 . "Open a subfolder that has fewer images and resize that one, or split this folder's contents into subfolders first.";
        ?>
        <span class="btn btn-outline-secondary text-start disabled"
              style="cursor:not-allowed;opacity:.7;pointer-events:auto"
              title="<?php echo htmlspecialchars($tooMany) ?>">
          <i class="fa fa-compress"></i> Resize
          <span class="text-muted">(<?php echo number_format($imgCount) ?> &mdash; over the <?php echo number_format(RESIZE_MAX_RECURSIVE) ?> limit; open a subfolder)</span>
        </span>
        <?php endif; ?>

        <?php if ($hasFilesHere): ?>
        <a href="pages/set_order.php?dir=<?php echo htmlspecialchars($absPath) ?>&return=<?php echo $returnUrl ?>"
           class="btn btn-outline-primary text-start"
           title="Drag-and-drop to set play order for files in this folder">
          <i class="fa fa-sort"></i> Re-Order this folder
        </a>
        <?php endif; ?>

        <?php if ($imgCount > 0): ?>
        <a href="pages/find_duplicates.php?p=<?php echo urlencode($path) ?>"
           class="btn btn-outline-primary text-start"
           title="Scan this folder and all subfolders for byte-identical duplicate images">
          <i class="fa fa-clone"></i> Find Duplicates
        </a>
        <?php endif; ?>

        <?php if ($imgCount === 0 && !$hasFilesHere): ?>
        <div class="text-muted text-center py-3" style="font-size:13px">
          <i class="fa fa-info-circle"></i> No images here yet — upload some first.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php
}

/**
 * Render the shared navbar items inside the navbar's collapsible <ul>.
 *
 * @param string $currentView  'list' or 'grid' — controls which view-toggle is shown
 * @param string $path         FM_PATH (list) or $p (grid) — used to build URLs
 * @param bool   $inTrash      whether we're in the trash view (hides Upload / New Folder)
 * @param int    $trashCount   number of items currently in trash (for the badge)
 */
/**
 * Render the full top header shared by manage.php and the standalone
 * pages: top-strip, brand bar (with search), breadcrumb
 * bar (with the 3-state "Add to slideshow" action on the right), trash banner.
 *
 * Keeping this in one place means visual / behavioral tweaks land in both
 * views in one edit. Both views were already sharing the breadcrumb and the
 * status bar via this file; this just finishes the consolidation.
 *
 * @param string $currentView  'list' or 'grid' — passed through to navbar items
 * @param string $relPath      FM_PATH (list) or $p (grid)
 * @param bool   $inTrash      drives the trash-tinted styling + hides search/action
 * @param string $absPath      absolute filesystem path of the current folder
 * @param array  $existingPaths paths.txt entries — for the in-list / ancestor check
 * @param int    $trashCount   item count for the trash badge in nav items
 * @param string $brandTitle   navbar brand text (e.g. APP_TITLE)
 * @param bool   $sticky       fixed-top behavior — grid is always sticky; list
 *                             follows the $sticky_navbar config flag
 */
function mgr_render_top_header($currentView, $relPath, $inTrash, $absPath,
                               $existingPaths, $trashCount, $brandTitle, $sticky) {
    $stickyClass = $sticky ? 'fixed-top' : '';
    $crumb       = mgr_breadcrumb_html($relPath);
    // The Add-to-slideshow action that used to live here is now rendered by
    // mgr_render_folder_action_bar() in the content area, above the table
    // / grid. Keeps the breadcrumb compact and avoids accidental taps on
    // mobile, where the hamburger sits directly below it.
?>
<div class="top-strip <?php echo $stickyClass ?><?php echo $inTrash ? ' top-strip-trash' : '' ?>">
<nav class="navbar navbar-expand-lg navbar-light <?php echo $inTrash ? 'bg-trash' : 'bg-white' ?> main-nav">
  <button type="button" class="sidebar-drawer-btn" onclick="toggleSidebarDrawer()"
          title="Show folder tree" aria-label="Show folder tree">
    <i class="fa fa-sitemap"></i>
  </button>
  <a class="navbar-brand ms-auto"><?php echo htmlspecialchars($brandTitle) ?></a>
  <?php
    // Grid↔List view-toggle removed from the navbar — the swap is now
    // in-page (ctrl-row .view-mode-toggle on manage.php). The list
    // view as a dedicated destination has been retired.
    // Starred and Trash counts live in the status pills row.
  ?>
  <?php if (!$inTrash): ?>
  <input type="search" id="folderSearchInput" class="nav-search me-2"
         placeholder="Search folders…" autocomplete="off"
         title="Find folders by name anywhere under images/. Esc to clear.">
  <?php endif; ?>
  <button class="navbar-toggler nav-toggle-sm" type="button" data-bs-toggle="collapse" data-bs-target="#navContent" aria-label="Toggle menu" aria-expanded="false">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navContent">
    <ul class="navbar-nav ms-auto align-items-lg-center">
      <?php mgr_render_navbar_items($currentView, $relPath, $inTrash, $trashCount); ?>
    </ul>
  </div>
</nav>
<div class="breadcrumb-bar">
  <span class="bc-path"><?php echo $crumb ?></span>
</div>
<?php mgr_render_trash_banner_if($inTrash); ?>
</div><!-- /.top-strip -->
<script>
/* Keep the body offset equal to the fixed top-strip's real height. Beats
   guessing a fixed margin — works when the breadcrumb wraps to two lines
   on narrow viewports or when the trash banner is shown. */
(function() {
    function sizeBody() {
        var ts = document.querySelector('.top-strip.fixed-top');
        if (ts && ts.offsetHeight) document.body.style.paddingTop = ts.offsetHeight + 'px';
    }
    requestAnimationFrame(sizeBody);
    document.addEventListener('DOMContentLoaded', sizeBody);
    window.addEventListener('load',   sizeBody);
    window.addEventListener('resize', sizeBody);
})();
</script>
<?php
}

/**
 * Render the "current folder + add-to-slideshow" strip that sits above the
 * content in both list and grid views. Three states, mirroring the prior
 * breadcrumb-bar action:
 *   - already in paths.txt        → toggle ON
 *   - included via an ancestor    → read-only "via PARENT" indicator
 *   - not in slideshow            → toggle OFF
 * Skipped folders (leading _) get a disabled indicator since the add-handler
 * rejects them anyway.
 *
 * Emits nothing if we're in trash mode or the folder has zero playable images.
 */
function mgr_render_folder_action_bar($absPath, $existingPaths, $inTrash, $folderName) {
    if ($inTrash || $absPath === '' || !is_dir($absPath)) return;
    if (mgr_count_dir_images($absPath) === 0) return;

    $pTrim    = rtrim($absPath, '/');
    $existing = array_map(fn($x) => rtrim(trim($x), '/'), $existingPaths);
    $inList   = in_array($pTrim, $existing, true);
    $ancestor = null;
    if (!$inList) {
        foreach ($existing as $e) {
            if ($e !== '' && strpos($pTrim . '/', $e . '/') === 0) { $ancestor = $e; break; }
        }
    }
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $isSkipped = is_skipped_path($absPath);
    if ($folderName === '' || $folderName === null) $folderName = basename($absPath);

    // Icon-only stats: size + image count + (recursive) subfolder count.
    // Mirrors the .folder-summary row in manage.php's list view.
    // Size covers EVERY file on disk (including skipped + hidden), since
    // the metric is "what this tree is costing you in storage".
    $sizeBytes = getDirectorySize($absPath);
    $sizeStr   = $sizeBytes !== false ? mgr_human_size_($sizeBytes) : '';
    $imgSplit  = countDirImagesSplit($absPath);
    $subCount  = mgr_count_subdirs_recursive_($absPath);
?>
<div class="folder-action-bar">
  <div class="fab-info">
    <i class="fa fa-folder-open-o fab-icon"></i>
    <span class="fab-name" title="<?php echo $h($absPath) ?>"><?php echo $h($folderName) ?></span>
    <span class="fab-stats">
      <?php if ($sizeStr !== ''): ?>
        <span class="fab-stat" title="Total size on disk"><i class="fa fa-database" style="color:#888"></i><?php echo $sizeStr ?></span>
      <?php endif; ?>
      <span class="fab-stat" title="Images that will play in the slideshow">
        <i class="fa fa-picture-o" style="color:#5a8fc2"></i><?php echo $imgSplit['active'] ?>
        <?php if ($imgSplit['skipped'] > 0): ?><span class="fab-skip">(<?php echo $imgSplit['skipped'] ?> skipped)</span><?php endif; ?>
      </span>
      <?php if ($subCount > 0): ?>
        <span class="fab-stat" title="Direct subfolders"><i class="fa fa-folder-o" style="color:#e8a838"></i><?php echo $subCount ?></span>
      <?php endif; ?>
    </span>
  </div>
  <div class="fab-action">
    <?php if ($ancestor !== null): ?>
      <span class="fab-via" title="Included via <?php echo $h($ancestor) ?>">
        <i class="fa fa-level-up fa-rotate-90"></i>
        Included via <b><?php echo $h(basename($ancestor)) ?></b>
      </span>
    <?php elseif ($isSkipped): ?>
      <span class="fab-disabled" title="Skipped folders can't be added to the slideshow. Rename to remove the leading underscore.">
        <i class="fa fa-ban"></i> Cannot add — skipped folder
      </span>
    <?php else: ?>
      <span class="fab-label">
        <?php echo $inList ? 'In slideshow' : 'Add to slideshow'; ?>
      </span>
      <label class="fm-toggle fab-toggle" title="<?php echo $inList ? 'Remove from slideshow' : 'Add to slideshow' ?>">
        <input type="checkbox" data-folder-path="<?php echo $h($absPath) ?>"
               data-current-folder="1"
               <?php echo $inList ? 'checked' : '' ?>
               onchange="toggleSlideshowFolder(this)">
        <span class="fm-toggle-slider"></span>
      </label>
    <?php endif; ?>
  </div>
</div>
<?php
}

/**
 * Render the shared sidebar drawer chrome (aside + backdrop). Page-specific
 * content goes inside via $innerHtml — manage_grid passes a folder tree,
 * trash/starred pages pass a flat filter-by-folder list.
 *
 * Pages must also add the 'has-sidebar' class to <body> for the navbar
 * drawer-trigger button to appear on narrow viewports.
 *
 * @param string $innerHtml  HTML to put inside the .sb-scroll area.
 * @param string $titleHtml  Visible title in the sidebar header (HTML).
 */
function mgr_render_sidebar_chrome($innerHtml, $titleHtml = '<i class="fa fa-folder-o"></i> Folders') {
?>
<div id="sidebarBackdrop"></div>
<aside id="folderSidebar" aria-label="Sidebar">
  <div class="sb-head">
    <span class="sb-head-title"><?php echo $titleHtml ?></span>
  </div>
  <div class="sb-scroll"><?php echo $innerHtml ?></div>
</aside>
<?php
}

function mgr_render_navbar_items($currentView, $path, $inTrash, $trashCount) {
    global $appName;
    $urlPath = urlencode($path);
?>
        <?php /* Grid/List toggle and Trash quick-icon are rendered outside this
                 menu in mgr_render_top_header so they're always reachable. */ ?>
        <li class="nav-item">
          <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#slideshowModal"
             title="Folders currently playing in the slideshow + saved playlists">
            <i class="fa fa-tv"></i> Slideshow
          </a>
        </li>
        <?php if (!$inTrash): ?>
        <li class="nav-item">
          <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#actionsModal"
             title="New Folder, Resize, Re-Order, Find Duplicates">
            <i class="fa fa-bolt"></i> Actions
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal"
             title="Delay, shuffle, fit mode, date overlay, system commands">
            <i class="fa fa-cog"></i> Settings
          </a>
        </li>
        <?php if (!$inTrash): ?>
        <li class="nav-item">
          <a class="nav-link" href="pages/upload.php?p=<?php echo $urlPath ?>"
             title="Upload photos to the current folder">
            <i class="fa fa-cloud-upload"></i> Upload
          </a>
        </li>
        <?php endif; ?>
<?php
}

/**
 * Render the New Folder modal (#createNewItem).
 */
function mgr_render_new_folder_modal() {
?>
<div class="modal fade" id="createNewItem" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-sm">
    <form class="modal-content" method="post">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-folder-o"></i> New Folder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="newfile" value="folder">
        <label for="newfilename" class="form-label">Folder name</label>
        <input type="text" name="newfilename" id="newfilename" class="form-control"
               placeholder="Enter folder name..." required autocomplete="off">
      </div>
      <div class="modal-footer flex-nowrap p-0">
        <input type="hidden" name="token" value="<?php echo $_SESSION['token'] ?>">
        <button type="button" class="btn btn-lg btn-link fs-6 col-6 m-0 rounded-0 border-end" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-lg btn-link fs-6 col-6 m-0 rounded-0">
          <strong><i class="fa fa-check"></i> Create</strong></button>
      </div>
    </form>
  </div>
</div>
<?php
}

/**
 * Render the Settings modal (Slideshow + System sections).
 */
function mgr_render_settings_modal() {
    $delay   = (file_exists(state_file('delay.txt')) && trim(file_get_contents(state_file('delay.txt'))) !== '')
             ? (int) trim(file_get_contents(state_file('delay.txt'))) : 5;
    $shuffle = (file_exists(state_file('shuffle.txt')) && trim(file_get_contents(state_file('shuffle.txt'))) === '1');
    $fit     = (file_exists(state_file('fit_mode.txt')) && trim(file_get_contents(state_file('fit_mode.txt'))) === 'cover') ? 'cover' : 'contain';
    $showDate= (file_exists(state_file('show_date.txt')) && trim(file_get_contents(state_file('show_date.txt'))) === '1');
    $token   = $_SESSION['token'];
?>
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-cog"></i> Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <h6 class="text-muted text-uppercase" style="font-size:11px;letter-spacing:.5px;margin-bottom:12px">Slideshow</h6>
        <form method="post" id="delayForm" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
          <label for="delay-input" class="form-label mb-0" style="flex:1">Delay between slides</label>
          <input type="number" name="delay" id="delay-input" class="form-control form-control-sm"
                 value="<?php echo $delay ?>" min="1" max="3600" required style="width:80px">
          <span class="text-muted" style="font-size:12px">sec</span>
          <input type="hidden" name="save_delay" value="1">
          <input type="hidden" name="token" value="<?php echo $token ?>">
          <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-check"></i></button>
        </form>

        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="setShuffle" <?php echo $shuffle ? 'checked' : '' ?>>
          <label class="form-check-label" for="setShuffle">
            <i class="fa fa-random text-muted"></i> Shuffle photos
          </label>
        </div>

        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="setFit" <?php echo $fit === 'cover' ? 'checked' : '' ?>>
          <label class="form-check-label" for="setFit">
            <i class="fa fa-arrows-alt text-muted"></i> Fill screen (<span id="setFitLabel"><?php echo $fit ?></span>) — when ON, zooms to fill (crops edges); when OFF, fits whole image (may letterbox)
          </label>
        </div>

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" id="setDate" <?php echo $showDate ? 'checked' : '' ?>>
          <label class="form-check-label" for="setDate">
            <i class="fa fa-calendar-o text-muted"></i> Show date overlay (camera-style)
          </label>
        </div>

        <hr style="margin:18px 0">

        <h6 class="text-muted text-uppercase" style="font-size:11px;letter-spacing:.5px;margin-bottom:10px">System</h6>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <button onclick="sendSystemAction('reboot')"       class="btn btn-sm btn-outline-warning">🔄 Restart</button>
          <button onclick="sendSystemAction('shutdown')"     class="btn btn-sm btn-outline-danger">⏻ Shutdown</button>
          <button onclick="sendSystemAction('closeBrowser')" class="btn btn-sm btn-outline-secondary">❌ Close Browser</button>
          <!-- Password settings — right-aligned key icon. Opens the
               dedicated password modal so the form only appears when
               actually wanted. -->
          <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                  data-bs-toggle="modal" data-bs-target="#passwordModal"
                  data-bs-dismiss="modal"
                  title="Change the action-confirmation password">
            <i class="fa fa-key"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Action-password modal — opened from the System row's key icon. -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="fa fa-key"></i> Action password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:12px;color:#888;margin-bottom:12px">
          Soft confirmation step before destructive actions (delete, move
          to trash, bulk operations). <b>Not real security</b> — anyone with
          browser DevTools can read it. Don't reuse a real password here.
        </p>
        <form id="apForm" autocomplete="off" onsubmit="return saveActionPassword(event)">
          <div class="mb-3">
            <label for="apCurrent" class="form-label" style="font-size:12px;margin-bottom:2px">Current password <span style="color:#888;font-weight:normal">— required for any change</span></label>
            <input type="password" id="apCurrent" class="form-control form-control-sm" autocomplete="current-password">
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="apRequire" <?php echo require_password_active() ? 'checked' : '' ?>>
            <label class="form-check-label" for="apRequire">Require password for destructive actions</label>
          </div>
          <div class="mb-2">
            <a href="#apChangeBlock" class="small" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="apChangeBlock">
              <i class="fa fa-pencil"></i> Change password
            </a>
          </div>
          <div class="collapse" id="apChangeBlock">
            <div class="mb-2">
              <label for="apNew" class="form-label" style="font-size:12px;margin-bottom:2px">New password</label>
              <input type="password" id="apNew" class="form-control form-control-sm" autocomplete="new-password">
            </div>
            <div class="mb-3">
              <label for="apConfirm" class="form-label" style="font-size:12px;margin-bottom:2px">Confirm new password</label>
              <input type="password" id="apConfirm" class="form-control form-control-sm" autocomplete="new-password">
            </div>
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
          <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
          <span id="apStatus" style="font-size:12px;margin-left:8px;color:#888"></span>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
}

/**
 * Output the JS block shared by both managers:
 *   - setShuffle / setFit / setDate toggle handlers (POST to util/toggle_*.php,
 *     update status-bar pills live)
 *   - .fm-remove-pill click → AJAX remove (Slideshow modal pills)
 *   - sendSystemAction(action) — from Settings modal System buttons
 */
function mgr_render_shared_js() {
?>
<!-- Snackbar host — toast() (below) animates this for short messages. -->
<div id="snackbar"></div>
<script>
/* ─── Toast (shared) ────────────────────────────────────────────────────────
   Tiny snackbar driver. Lives in shared JS so both list and grid views show
   server-returned messages from toggles, etc. Safe no-op if #snackbar isn't
   on the page. */
function toast(txt) {
    var x = document.getElementById("snackbar");
    if (!x) return;
    x.innerHTML = txt; x.className = "show";
    setTimeout(function(){ x.className = x.className.replace("show",""); }, 3000);
}

/* ─── Folder slideshow toggle (shared) ──────────────────────────────────────
   Handles both the per-row toggles (list view) and the current-folder toggle
   in the action strip (both views). The server's ?ajax=1 branch returns
   {inList, msg, stats, paths, stripped} — we update the checkbox, flash a
   toast, prune stripped descendants, and update list-view descendant hints.
   The strip's wording is also rewritten if the changed toggle lives inside
   a .folder-action-bar. Side-effect calls (applySlideshowStats /
   refreshDescendantHints) operate on DOM that only exists in list view —
   they're loop-safe no-ops in grid view. */
function toggleSlideshowFolder(cb) {
    var folderPath = cb.dataset.folderPath;
    var action     = cb.checked ? 'add' : 'remove';
    cb.disabled = true;
    fetch('manage_ops.php?ajax=1'
          + '&folder_path=' + encodeURIComponent(folderPath)
          + '&folder_path_action=' + action)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            cb.disabled = false;
            // Server state wins (handles "already exists", ancestor-blocks-add).
            cb.checked = !!data.inList;
            var label = cb.closest('.fm-toggle');
            if (label) label.title = data.inList ? 'Remove from slideshow' : 'Add to slideshow';

            // Mirror the new in-list state to EVERY other toggle on the page
            // that references the same folder path — row toggle, sidebar
            // toggle, action-strip toggle. Without this, toggling from one
            // surface leaves the others stale.
            document.querySelectorAll('input[data-folder-path]').forEach(function(other) {
                if (other === cb) return;
                if (other.dataset.folderPath !== folderPath) return;
                if (other.disabled) return;        // "via" toggles — refresh* sets these
                other.checked = !!data.inList;
                var oLbl = other.closest('.fm-toggle');
                if (oLbl) oLbl.title = data.inList ? 'Remove from slideshow' : 'Add to slideshow';
                // If the matched input is in the action strip, re-word its label too.
                var oStrip = other.closest('.folder-action-bar');
                if (oStrip) {
                    var oTxt = oStrip.querySelector('.fab-label');
                    if (oTxt) oTxt.textContent = data.inList ? 'In slideshow' : 'Add to slideshow';
                }
            });

            // Re-word the strip's label when the changed toggle is in the strip.
            var strip = cb.closest('.folder-action-bar');
            if (strip) {
                var txt = strip.querySelector('.fab-label');
                if (txt) txt.textContent = data.inList ? 'In slideshow' : 'Add to slideshow';
            }
            if (data.msg) toast(data.msg);
            // If the server stripped redundant descendants (a newly-added
            // parent now covers them), flip their row toggles off so the UI
            // matches paths.txt. No-op in grid view (no rows present).
            if (Array.isArray(data.stripped) && data.stripped.length) {
                data.stripped.forEach(function(p) {
                    var t = document.querySelector('input[data-folder-path="' + (window.CSS && CSS.escape ? CSS.escape(p) : p) + '"]');
                    if (t) {
                        t.checked = false;
                        var lbl = t.closest('.fm-toggle');
                        if (lbl) lbl.title = 'Add to slideshow';
                    }
                });
            }
            applySlideshowStats(data.stats);
            refreshDescendantHints(data.paths);
            var modalEl = document.getElementById('slideshowModal');
            if (modalEl) {
                modalEl.dataset.stale = '1';
                // If the modal is currently open, refresh its body in place
                // so toggles outside the modal reflect inside immediately.
                if (modalEl.classList.contains('show')) refreshSlideshowModalBody();
            }
        })
        .catch(function() {
            cb.disabled = false;
            cb.checked  = !cb.checked;     // revert
            toast('Toggle failed.');
        });
}

function applySlideshowStats(stats) {
    if (!stats) return;
    var f = document.getElementById('sb-folder-count');
    var i = document.getElementById('sb-img-count');
    if (f) f.textContent = stats.folderCount;
    if (i) i.textContent = Number(stats.imageCount).toLocaleString();
}

/* Recompute the "descendant in slideshow" hint AND the "via parent" state
   on every visible folder row from the authoritative paths list returned by
   the server. Called after any toggle so the visuals stay in sync without
   a reload. Iterates 0 rows in grid view (no tr.folder-row) — safe. */
function refreshDescendantHints(paths) {
    if (!Array.isArray(paths)) return;
    document.querySelectorAll('tr.folder-row').forEach(function(row) {
        var toggleCb = row.querySelector('input[data-folder-path]');
        if (!toggleCb) return;
        var abs = toggleCb.dataset.folderPath;
        if (!abs) return;

        // Compute three pieces of state for this row from the fresh paths list.
        var inList = paths.indexOf(abs) >= 0;
        var descCount = 0;
        var ancestor = null;
        if (!inList) {
            var prefix = abs + '/';
            for (var i = 0; i < paths.length; i++) {
                var p = paths[i];
                if (p.indexOf(prefix) === 0) descCount++;
                if (ancestor === null && p !== '' && (abs + '/').indexOf(p.replace(/\/+$/, '') + '/') === 0) {
                    ancestor = p.replace(/\/+$/, '');
                }
            }
        }
        applyRowViaState(row, toggleCb, inList, ancestor);
        updateRowHint(row, descCount);
    });
    // Also refresh sidebar tree node states (grid view's left sidebar).
    refreshSidebarTreeStates(paths);
}

/* Live-refresh for the grid-view sidebar tree. Each .ft-row carries its own
   toggle with data-folder-path (absolute filesystem path). Same three-state
   logic as list-view rows. No-op on pages without a sidebar. */
function refreshSidebarTreeStates(paths) {
    if (!Array.isArray(paths)) return;
    document.querySelectorAll('#folderSidebar .ft-row').forEach(function(row) {
        var cb = row.querySelector('input[data-folder-path]');
        if (!cb) return;                                  // skipped folders carry no toggle
        var abs = cb.dataset.folderPath;
        if (!abs) return;
        var inList   = paths.indexOf(abs) >= 0;
        var ancestor = null;
        var descCount = 0;
        var prefix = abs + '/';
        for (var i = 0; i < paths.length; i++) {
            var p = paths[i].replace(/\/+$/, '');
            if (p === '') continue;
            if (!inList && ancestor === null && (abs + '/').indexOf(p + '/') === 0) ancestor = p;
            if (p !== abs && (p + '/').indexOf(prefix) === 0) descCount++;
        }
        var label = cb.closest('.fm-toggle');
        if (ancestor !== null && !inList) {
            if (label) {
                label.classList.add('fm-via');
                label.title = 'Included via ' + ancestor.split('/').pop()
                            + ' — remove the parent to exclude this folder.';
            }
            cb.checked  = true;
            cb.disabled = true;
            cb.onchange = null;
        } else {
            if (label) {
                label.classList.remove('fm-via');
                label.title = inList ? 'Remove from slideshow' : 'Add to slideshow';
            }
            cb.checked  = inList;
            cb.disabled = false;
            cb.onchange = function() { toggleSlideshowFolder(this); };
        }
        // Descendant-included dot — only when the row itself is OUT (not
        // inList, not via-ancestor). Otherwise the row's own toggle state
        // already conveys "plays".
        var ftName = row.querySelector('.ft-name');
        var hint   = row.querySelector('.ft-desc-hint');
        var showHint = (descCount > 0 && !inList && ancestor === null);
        if (showHint) {
            if (!hint && ftName) {
                hint = document.createElement('span');
                hint.className = 'in-show-hint ft-desc-hint';
                hint.innerHTML = '&bull;';
                ftName.insertAdjacentElement('afterend', hint);
            }
            if (hint) hint.title = descCount + ' descendant folder' + (descCount === 1 ? '' : 's') + ' included in the slideshow';
        } else if (hint) {
            hint.remove();
        }
    });
}

/* Apply / clear the "via parent" visual state on one row given the freshly
   computed booleans. Handles the toggle's class + checked + disabled and
   the inline "via PARENT" badge. */
function applyRowViaState(row, cb, inList, ancestor) {
    var label = cb.closest('.fm-toggle');
    var filenameDiv = row.querySelector('.filename');
    var badge = filenameDiv ? filenameDiv.querySelector('.via-badge') : null;

    if (ancestor !== null && !inList) {
        if (label) {
            label.classList.add('fm-via');
            label.title = 'Included via ' + ancestor.split('/').pop()
                        + ' — remove the parent to exclude this folder.';
        }
        cb.checked  = true;
        cb.disabled = true;
        cb.onchange = null;             // prevent toggle handler firing
        if (!badge && filenameDiv) {
            badge = document.createElement('span');
            badge.className = 'via-badge';
            filenameDiv.appendChild(document.createTextNode(' '));
            filenameDiv.appendChild(badge);
        }
        if (badge) {
            badge.title = 'Included via ' + ancestor;
            badge.innerHTML = '<i class="fa fa-level-up fa-rotate-90"></i> via <b>'
                            + (ancestor.split('/').pop().replace(/[&<>]/g, '')) + '</b>';
        }
    } else {
        if (label) {
            label.classList.remove('fm-via');
            label.title = inList ? 'Remove from slideshow' : 'Add to slideshow';
        }
        cb.disabled = false;
        // Re-attach the change handler in case it was cleared by a prior
        // "via" application; the inline onchange attribute is the source of
        // truth in the initial render.
        cb.onchange = function() { toggleSlideshowFolder(this); };
        if (badge) badge.remove();
    }
}

function updateRowHint(row, count) {
    var filenameDiv = row.querySelector('.filename');
    if (!filenameDiv) return;
    var hint = filenameDiv.querySelector('.in-show-hint');
    if (count > 0) {
        if (!hint) {
            hint = document.createElement('span');
            hint.className = 'in-show-hint';
            hint.innerHTML = '&bull;';
            filenameDiv.appendChild(document.createTextNode(' '));
            filenameDiv.appendChild(hint);
        }
        hint.title = count + ' descendant folder' + (count === 1 ? '' : 's') + ' included in the slideshow';
    } else if (hint) {
        hint.remove();
    }
}

/* ─── Sidebar drawer (mobile) ──────────────────────────────────────────────
   Used by manage_grid (folder tree), pages/trash and pages/starred (flat
   folder-filter lists). The .sidebar-drawer-btn in the navbar (always
   rendered, CSS-gated by body.has-sidebar) calls toggleSidebarDrawer.
   No-op on pages without a #folderSidebar. */
window.toggleSidebarDrawer = function() {
    document.body.classList.toggle('sidebar-open');
};
window.closeSidebarDrawer = function() {
    document.body.classList.remove('sidebar-open');
};
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('folderSidebar');
    if (!sidebar) return;
    var backdrop = document.getElementById('sidebarBackdrop');
    if (backdrop) backdrop.addEventListener('click', closeSidebarDrawer);

    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            closeSidebarDrawer();
        }
    });
    // Auto-close on viewport resize past the desktop breakpoint.
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) closeSidebarDrawer();
    });
    // Swipe-left to dismiss (touch only).
    var touchStartX = null;
    sidebar.addEventListener('touchstart', function(ev) {
        if (ev.touches.length === 1) touchStartX = ev.touches[0].clientX;
    }, { passive: true });
    sidebar.addEventListener('touchend', function(ev) {
        if (touchStartX === null) return;
        var dx = (ev.changedTouches[0] ? ev.changedTouches[0].clientX : touchStartX) - touchStartX;
        if (dx < -40) closeSidebarDrawer();
        touchStartX = null;
    }, { passive: true });
    // Closing on navigation — links inside the sidebar that actually
    // navigate (not chevron toggles, action buttons, etc.) close the drawer
    // so the user lands on the new page with a clean viewport.
    sidebar.addEventListener('click', function(ev) {
        var a = ev.target.closest('a');
        if (a && a.getAttribute('href')) closeSidebarDrawer();
    });
});

/* Refetch the Slideshow modal body and replace it in place. Called both
   when the modal opens with a stale flag AND immediately after a toggle
   while the modal is visible — so the list inside stays in sync without
   needing a close-and-reopen. */
function refreshSlideshowModalBody() {
    var modalEl = document.getElementById('slideshowModal');
    if (!modalEl) return;
    var body = modalEl.querySelector('.modal-body');
    if (!body) return;
    fetch('manage_ops.php?slideshow_body=1')
        .then(function(r) { return r.text(); })
        .then(function(html) { body.innerHTML = html; modalEl.dataset.stale = ''; })
        .catch(function() {});
}

// On modal open, refetch once if anything has marked it stale since last view.
document.addEventListener('DOMContentLoaded', function() {
    var modalEl = document.getElementById('slideshowModal');
    if (!modalEl) return;
    modalEl.addEventListener('show.bs.modal', function() {
        // Always refetch on open — guarantees the full Save/Load/Delete
        // playlist UI (rendered by manage.php's display fns) appears even
        // when the grid view's initial fallback render skipped them.
        refreshSlideshowModalBody();
    });
});

/* ─── Playlist actions (Save / Load / Delete) ───────────────────────────────
   The Slideshow modal body is AJAX-injected from manage_ops.php, so its
   buttons (.fm-save-playlist-btn / .fm-playlist-load-btn / .fm-playlist-delete-btn)
   don't exist when the page first loads. Use event delegation off document
   so handlers survive every refreshSlideshowModalBody() swap. */

// Helper — locate the inline Save-as-Playlist form inside the slideshow modal.
function _fmSavePlaylistForm() {
    return document.getElementById('fmSavePlaylistForm');
}

// "Save as Playlist" button → reveal the inline form (no second modal).
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fm-save-playlist-btn');
    if (!btn) return;
    e.preventDefault();
    var form = _fmSavePlaylistForm();
    if (!form) return;
    form.style.display = 'flex';
    btn.style.display  = 'none';
    var input = form.querySelector('input[name="playlist_name"]');
    if (input) { input.value = ''; input.focus(); }
    var status = form.querySelector('.fm-save-playlist-status');
    if (status) status.textContent = '';
});

// Cancel → hide the form, bring the button back.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fm-save-playlist-cancel');
    if (!btn) return;
    e.preventDefault();
    var form = btn.closest('.fm-save-playlist-form');
    if (form) form.style.display = 'none';
    var saveBtn = document.querySelector('.fm-save-playlist-btn');
    if (saveBtn) saveBtn.style.display = '';
});

// Submit → AJAX-save → toast + refresh body + switch to Playlists tab.
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || form.id !== 'fmSavePlaylistForm') return;
    e.preventDefault();

    var input  = form.querySelector('input[name="playlist_name"]');
    var status = form.querySelector('.fm-save-playlist-status');
    var name   = (input && input.value || '').trim();
    function setStatus(txt) { if (status) status.textContent = txt; }

    if (!name) { setStatus('Enter a name.'); if (input) input.focus(); return; }
    setStatus('');

    var fd = new FormData();
    fd.append('save_playlist',  '1');
    fd.append('playlist_name',  name);
    fd.append('token',          window.csrf || '');
    fd.append('ajax',           '1');

    var submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    fetch('manage_ops.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (submitBtn) submitBtn.disabled = false;
            if (d && d.success) {
                toast('Playlist saved.');
                refreshSlideshowModalBody();
                // After refresh, jump to the Playlists tab so user sees the
                // entry they just created (with its LOADED pill).
                setTimeout(function() {
                    var tab = document.querySelector('#slideshowModal a[href="#sm-playlists"]');
                    if (tab && window.bootstrap && bootstrap.Tab) bootstrap.Tab.getOrCreateInstance(tab).show();
                }, 150);
            } else {
                setStatus((d && d.msg) ? d.msg.replace(/<[^>]+>/g, '') : 'Could not save playlist.');
            }
        })
        .catch(function() {
            if (submitBtn) submitBtn.disabled = false;
            setStatus('Network error.');
        });
});

// Load a playlist — AJAX with confirm, refresh body in place on success.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fm-playlist-load-btn');
    if (!btn) return;
    e.preventDefault();
    var name = btn.dataset.playlistName || '';
    if (!confirm('Load playlist "' + name + '"?\nThis will replace the current selection.')) return;

    var fd = new FormData();
    fd.append('load_playlist',  '1');
    fd.append('playlist_name',  name);
    fd.append('token',          window.csrf || '');
    fd.append('ajax',           '1');
    btn.disabled = true;

    fetch('manage_ops.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d && d.success) {
                toast('Playlist loaded.');
                refreshSlideshowModalBody();
                // Outside-the-modal UI (status bar counts, row toggles) will
                // stay stale until the next navigation — intentional, so the
                // user can read the toast + the refreshed modal and close
                // the modal themselves. The TV slideshow reloads on its own
                // via change_status.txt mtime polling (~5 s) regardless of
                // what happens in the management page.
            } else {
                toast((d && d.msg) ? d.msg.replace(/<[^>]+>/g, '') : 'Load failed.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            toast('Network error.');
        });
});

// Playlists tab — client-side filter input (Option A for "many playlists").
// Listens at document scope because .fm-playlist-search is inside the
// AJAX-refreshed modal body and gets replaced on every refresh.
// Set display explicitly to 'flex' (the row's original layout) when matching,
// 'none' when not — setting it to '' would wipe the row's inline display
// and collapse it to default block layout.
document.addEventListener('input', function(e) {
    var box = e.target;
    if (!box.classList || !box.classList.contains('fm-playlist-search')) return;
    var q = box.value.trim().toLowerCase();
    var container = box.closest('#sm-playlists') || document;
    container.querySelectorAll('.fm-playlist-row').forEach(function(row) {
        var name = row.dataset.plName || '';
        row.style.display = (q === '' || name.indexOf(q) >= 0) ? 'flex' : 'none';
    });
});
// Escape inside the filter box clears it.
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    var box = e.target;
    if (!box.classList || !box.classList.contains('fm-playlist-search')) return;
    if (box.value === '') return;
    box.value = '';
    box.dispatchEvent(new Event('input', { bubbles: true }));
});

// Delete a playlist — AJAX with confirm, refresh body in place on success.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.fm-playlist-delete-btn');
    if (!btn) return;
    e.preventDefault();
    var name = btn.dataset.playlistName || '';
    if (!confirm('Delete playlist "' + name + '"?')) return;

    var fd = new FormData();
    fd.append('delete_playlist', '1');
    fd.append('playlist_name',   name);
    fd.append('token',           window.csrf || '');
    fd.append('ajax',            '1');
    btn.disabled = true;

    fetch('manage_ops.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            if (d && d.success) {
                toast('Playlist deleted.');
                refreshSlideshowModalBody();
            } else {
                toast((d && d.msg) ? d.msg.replace(/<[^>]+>/g, '') : 'Delete failed.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            toast('Network error.');
        });
});

/* ─── Status-bar pill shortcuts ─────────────────────────────────────────────
   Click a pill to flip its setting directly (confirmation prompt first), or
   open the settings modal in the case of delay. The corresponding setting in
   the settings modal stays the canonical authority — both paths hit the same
   server endpoints. */
function _mgrPillSetup() {
    function pillToggle(endpoint, prompt, applyUI) {
        if (!confirm(prompt)) return;
        fetch(endpoint, { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) { if (typeof toast === 'function') toast('Toggle failed.'); return; }
                applyUI(d.newValue);
                if (typeof toast === 'function') toast('Saved.');
            })
            .catch(function() { if (typeof toast === 'function') toast('Network error.'); });
    }

    var shufflePill = document.getElementById('sbShufflePill');
    if (shufflePill) shufflePill.addEventListener('click', function() {
        var on = document.getElementById('sbShuffleVal').textContent.trim() === 'ON';
        pillToggle('util/toggle_shuffle.php',
                   'Turn shuffle ' + (on ? 'OFF' : 'ON') + ' for the slideshow?',
                   function(newValue) {
                       var v = (newValue === '1');
                       var el = document.getElementById('sbShuffleVal');
                       el.textContent = v ? 'ON' : 'OFF';
                       el.className   = v ? 'sb-on' : 'sb-off';
                       // Keep the Settings modal switch in sync if it exists.
                       var cb = document.getElementById('setShuffle');
                       if (cb) cb.checked = v;
                   });
    });

    var fitPill = document.getElementById('sbFitPill');
    if (fitPill) fitPill.addEventListener('click', function() {
        var current = document.getElementById('sbFitVal').textContent.trim();
        var next    = current === 'cover' ? 'contain' : 'cover';
        pillToggle('util/toggle_fit.php',
                   'Switch fit mode to "' + next + '" for the slideshow?',
                   function(newValue) {
                       var pill = document.getElementById('sbFitVal');   if (pill) pill.textContent = newValue;
                       var lbl  = document.getElementById('setFitLabel'); if (lbl)  lbl.textContent  = newValue;
                       var cb   = document.getElementById('setFit');     if (cb)   cb.checked = (newValue === 'cover');
                   });
    });

    var datePill = document.getElementById('sbDatePill');
    if (datePill) datePill.addEventListener('click', function() {
        var on = document.getElementById('sbDateVal').textContent.trim() === 'ON';
        pillToggle('util/toggle_show_date.php',
                   'Turn the date overlay ' + (on ? 'OFF' : 'ON') + '?',
                   function(newValue) {
                       var v = (newValue === '1');
                       var el = document.getElementById('sbDateVal');
                       el.textContent = v ? 'ON' : 'OFF';
                       el.className   = v ? 'sb-on' : 'sb-off';
                       var cb = document.getElementById('setDate');
                       if (cb) cb.checked = v;
                   });
    });

    // Delay pill is a shortcut into the Settings modal — opening it focuses
    // the delay input. No confirmation; the modal itself is the form.
    var delayPill = document.getElementById('sbDelayPill');
    if (delayPill) delayPill.addEventListener('click', function() {
        var modalEl = document.getElementById('settingsModal');
        if (!modalEl) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
        modalEl.addEventListener('shown.bs.modal', function once() {
            modalEl.removeEventListener('shown.bs.modal', once);
            var delayInput = modalEl.querySelector('input[name="delay"]');
            if (delayInput) { delayInput.focus(); delayInput.select(); }
        });
    });
}
// Attach once DOM is ready — the shared_js block may be emitted before the
// settings/actions modals are in the page (e.g. list view appends modals after
// the script). Runs immediately if DOM is already past the loading phase.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _mgrPillSetup);
} else {
    _mgrPillSetup();
}

// Settings modal — three toggle switches, save instantly on flip.
document.getElementById("setShuffle")?.addEventListener("change", function () {
    var checked = this.checked;
    fetch("util/toggle_shuffle.php", { method: "POST" })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert("Failed to toggle shuffle."); this.checked = !checked; return; }
            // Sync the status-bar shuffle pill.
            var on  = (d.newValue === "1");
            var el  = document.getElementById('sbShuffleVal');
            if (el) { el.textContent = on ? 'ON' : 'OFF'; el.className = on ? 'sb-on' : 'sb-off'; }
        });
});
document.getElementById("setFit")?.addEventListener("change", function () {
    var checked = this.checked;
    fetch("util/toggle_fit.php", { method: "POST" })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert("Failed to toggle fit mode."); this.checked = !checked; return; }
            var pill = document.getElementById("sbFitVal");      if (pill) pill.textContent = d.newValue;
            var lbl  = document.getElementById("setFitLabel");   if (lbl)  lbl.textContent  = d.newValue;
        });
});
document.getElementById("setDate")?.addEventListener("change", function () {
    var checked = this.checked;
    fetch("util/toggle_show_date.php", { method: "POST" })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert("Failed to toggle date overlay."); this.checked = !checked; return; }
            var on = (d.newValue === "1");
            var pill = document.getElementById("sbDateVal");
            if (pill) { pill.textContent = on ? "ON" : "OFF"; pill.className = on ? "sb-on" : "sb-off"; }
        });
});

// Slideshow modal — intercept pill removes so the modal stays open.
document.addEventListener('click', function(e) {
    var link = e.target.closest('.fm-remove-pill');
    if (!link) return;
    e.preventDefault();
    var pill = link.closest('.fm-active-pill');
    var url  = link.href + (link.href.indexOf('?') >= 0 ? '&ajax=1' : '?ajax=1');
    link.style.pointerEvents = 'none';
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.success) { alert('Could not remove folder.'); link.style.pointerEvents = ''; return; }
            // Sync the matching list-view toggle, if visible.
            if (pill && pill.dataset.path) {
                var toggle = document.querySelector('input[data-folder-path="' + CSS.escape(pill.dataset.path) + '"]');
                if (toggle) toggle.checked = false;
            }
            // Live-update the outer status bar from the AJAX response.
            if (d.stats && typeof applySlideshowStats === 'function') applySlideshowStats(d.stats);
            // Refresh the "descendant in slideshow" hints on each row.
            if (d.paths && typeof refreshDescendantHints === 'function') refreshDescendantHints(d.paths);
            if (pill) {
                var imgs = parseInt(pill.dataset.imgcount || '0', 10) || 0;
                pill.remove();
                var counts = document.getElementById('fm-active-counts');
                if (counts) {
                    var nF = (parseInt(counts.dataset.folders, 10) || 0) - 1;
                    var nI = (parseInt(counts.dataset.images,  10) || 0) - imgs;
                    counts.dataset.folders = nF;
                    counts.dataset.images  = nI;
                    if (nF <= 0) counts.style.display = 'none';
                    else counts.textContent = nF + ' folder' + (nF !== 1 ? 's' : '') + ' · ' + nI + ' image' + (nI !== 1 ? 's' : '');
                }
            }
        })
        .catch(function() { alert('Network error.'); link.style.pointerEvents = ''; });
});

// Clear stale state (status message + password inputs + collapsed
// change-password section) every time the password modal opens, so the
// last "Saved." or error doesn't linger from a previous visit.
document.addEventListener('DOMContentLoaded', function() {
    var pm = document.getElementById('passwordModal');
    if (!pm) return;
    pm.addEventListener('show.bs.modal', function() {
        ['apCurrent','apNew','apConfirm'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        var status = document.getElementById('apStatus');
        if (status) status.textContent = '';
        // Re-collapse the "Change password" block so the modal opens in
        // its minimal form every time.
        var block = document.getElementById('apChangeBlock');
        if (block && window.bootstrap && bootstrap.Collapse) {
            bootstrap.Collapse.getOrCreateInstance(block, { toggle: false }).hide();
        }
    });
});

// Action-password save (from Settings modal). AJAX to manage_ops.php.
// Validates the new/confirm match client-side, then POSTs the current
// password + new password + require flag. Server validates the current
// password before writing.
function saveActionPassword(ev) {
    if (ev) ev.preventDefault();
    var cur     = document.getElementById('apCurrent').value;
    var newP    = document.getElementById('apNew').value;
    var conf    = document.getElementById('apConfirm').value;
    var require = document.getElementById('apRequire').checked;
    var status  = document.getElementById('apStatus');
    function setStatus(msg, ok) { if (status) { status.textContent = msg; status.style.color = ok ? '#28a745' : '#dc3545'; } }
    // Any change (new password OR toggle flip) requires the current password
    // when one is already set. Self-heal: when window.ACTION_PASSWORD is
    // empty (file missing/nuked), the current-password field is unused.
    if (window.ACTION_PASSWORD && cur === '') {
        setStatus('Enter the current password to make changes.', false);
        document.getElementById('apCurrent').focus();
        return false;
    }
    if (newP !== conf) { setStatus('New and confirm don\'t match.', false); return false; }
    if (require && newP === '' && !window.ACTION_PASSWORD) {
        setStatus('Set a password before enabling.', false); return false;
    }
    var fd = new FormData();
    fd.append('save_action_password', '1');
    fd.append('token', window.csrf);
    fd.append('current_password', cur);
    fd.append('new_password',     newP);
    if (require) fd.append('require_password', '1');
    fetch('manage_ops.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                setStatus(d.msg || 'Saved.', true);
                // Refresh the in-page password used by requireActionPassword
                // without forcing a full page reload.
                if (newP !== '')          window.ACTION_PASSWORD  = newP;
                window.REQUIRE_PASSWORD = require;
                document.getElementById('apCurrent').value = '';
                document.getElementById('apNew').value     = '';
                document.getElementById('apConfirm').value = '';
            } else {
                setStatus(d.msg || 'Save failed.', false);
            }
        })
        .catch(function() { setStatus('Network error.', false); });
    return false;
}

// System commands (from Settings modal).
function sendSystemAction(action) {
    if (typeof requireActionPassword === 'function' && !requireActionPassword(action)) return;
    fetch("util/system_commands.php", {
        method: "POST",
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action })
    }).then(r => r.json()).then(d => {
        alert(d.message || "Action executed.");
        var m = bootstrap.Modal.getInstance(document.getElementById('settingsModal'));
        if (m) m.hide();
    }).catch(() => alert("An error occurred."));
}
</script>
<?php
}

/* ─── Tree-view helpers (shared by manage.php list view and get_folder_rows.php
       AJAX endpoint). Keep these here so the endpoint can render rows that are
       byte-identical to the page's initial render without re-running manage.php
       (and all its side-effecting bootstrap). ───────────────────────────────── */

// Recursive image count split into active / skipped. A file counts as
// skipped if its own name or ANY ancestor directory name starts with '_'.
/**
 * Drop lines in a state file whose path no longer exists on disk. Rewrites
 * the file only if anything was actually removed — most loads do no I/O
 * beyond the read + a few file_exists() checks. Catches stale entries from
 * in-app renames/moves/deletes AND external changes (SSH, USB sync, etc.).
 *
 * @param string   $file   absolute path of the state file (paths.txt etc.)
 * @param callable $check  is_dir / is_file — applied to each line's trim()
 */
function sweep_dead_paths_($file, $check) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    $kept = [];
    foreach ($lines as $line) {
        $p = trim($line);
        if ($p === '' || $check($p)) $kept[] = $line;
    }
    if (count($kept) !== count($lines)) {
        file_put_contents($file, $kept ? implode(PHP_EOL, $kept) . PHP_EOL : '', LOCK_EX);
        // Bump change_status.txt — the slideshow viewer polls its mtime every
        // 5 s and reloads on change, so the play set updates on the TV
        // without waiting for a manual refresh. Matches what add/remove and
        // playlist-load handlers already do after mutating paths.txt.
        @file_put_contents(state_file('change_status.txt'), '1');
    }
}

// Auto-sweep both state files once per include. Cost: typically <5 ms for
// libraries with under a few hundred entries; no write unless something
// changed. Keeps paths.txt / starred_paths.txt self-cleaning across page
// loads without the rename/move handlers having to know about cleanup.
sweep_dead_paths_(state_file('paths.txt'),         'is_dir');
sweep_dead_paths_(state_file('starred_paths.txt'), 'is_file');
// path_affects_slideshow_ and bump_change_status_if_affects_ now live in
// var.php so util/* endpoints can call them without pulling in this file.

function countDirImagesSplit($dir, $inheritSkipped = false) {
    if (!is_dir($dir)) return ['active' => 0, 'skipped' => 0];
    static $img_exts = ['gif','jpg','jpeg','png','bmp','ico','svg','webp','avif'];
    $active = 0; $skipped = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        $thisSkipped = $inheritSkipped || is_skipped_name($f);
        if (is_file($p)) {
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $img_exts)) {
                if ($thisSkipped) $skipped++; else $active++;
            }
        } elseif (is_dir($p)) {
            $sub = countDirImagesSplit($p, $thisSkipped);
            $active  += $sub['active'];
            $skipped += $sub['skipped'];
        }
    }
    return ['active' => $active, 'skipped' => $skipped];
}

/**
 * Format a byte count as the largest reasonable unit (B / KB / MB / GB)
 * to one decimal place. Used by the folder action strip's size stat.
 */
function mgr_human_size_($bytes) {
    if (!is_numeric($bytes) || $bytes < 0) return '';
    if ($bytes < 1024)               return $bytes . ' B';
    if ($bytes < 1024 * 1024)        return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 1) . ' GB';
}

/**
 * Recursive subfolder count — every directory anywhere under $dir, not just
 * direct children. Hidden + skipped folder names are still counted (they
 * still take up disk space and matter as "what's here"). Returns 0 on bad
 * dir so callers don't need to guard.
 */
function mgr_count_subdirs_recursive_($dir) {
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) { $n++; $n += mgr_count_subdirs_recursive_($p); }
    }
    return $n;
}

function getDirectorySize($dir) {
    if (!is_dir($dir)) return false;
    $size = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        $size += is_file($p) ? filesize($p) : (getDirectorySize($p) ?: 0);
    }
    return $size;
}

/**
 * Render a single folder row for the list-view table. Called for both the
 * top-level folders rendered in manage.php and for AJAX-loaded nested rows
 * returned by get_folder_rows.php — so output must be byte-identical for the
 * two callers (same buttons, same URLs, same data- attributes).
 *
 * @param string $folderName       e.g. "vacation_2024"
 * @param string $parentRelPath    FM_PATH-equivalent for the parent dir
 *                                 ("time_machine/images/foo")
 * @param string $parentAbsPath    filesystem path to parent
 * @param int    $depth            0 = top-level row, 1+ = nested
 * @param array  $existingPaths    absolute paths already in the slideshow
 * @param bool   $inTrash          suppress the slideshow toggle in trash view
 * @param int    $rowKey           unique id for the checkbox <label> pairing
 * @param string $pathHint          optional path-context label rendered as a
 *                                  small grey line above the folder name —
 *                                  used by search results to show "where in
 *                                  the tree this folder lives".
 */
function render_folder_row($folderName, $parentRelPath, $parentAbsPath, $depth,
                           $existingPaths, $inTrash, $rowKey, $pathHint = '') {
    if ($folderName === 'code') return '';
    $absPath = $parentAbsPath . '/' . $folderName;
    if (!is_dir($absPath)) return '';

    $relPath = trim(($parentRelPath !== '' ? $parentRelPath . '/' : '') . $folderName, '/');

    $modif_raw = @filemtime($absPath) ?: 0;
    $modif     = $modif_raw && defined('FM_DATETIME_FORMAT')
                 ? date(FM_DATETIME_FORMAT, $modif_raw) : ($modif_raw ? date('Y-m-d H:i', $modif_raw) : '');
    $is_link   = is_link($absPath);
    $icon      = $is_link ? 'fa fa-link' : 'fa fa-folder-o';

    $inList     = in_array($absPath, $existingPaths, true);
    $rowSkipped = is_skipped_name($folderName);

    // Count descendants of this folder that are in the slideshow — gives the
    // user a subtle hint that something deeper is included, even when the
    // folder itself isn't.
    $descendantInListCount = 0;
    if (!$inList) {
        $prefix = $absPath . '/';
        foreach ($existingPaths as $ep) {
            if ($ep !== '' && strpos($ep . '/', $prefix) === 0) $descendantInListCount++;
        }
    }

    // "Via" state — folder isn't directly in paths.txt but an ancestor is.
    // In this case the slideshow IS playing this folder's images, just via
    // the parent. Show the toggle as ON-but-disabled with a "via PARENT"
    // badge so the UI reflects the real playing state.
    $ancestorIncluded = null;
    if (!$inList) {
        foreach ($existingPaths as $ep) {
            if ($ep !== '' && strpos($absPath . '/', rtrim($ep, '/') . '/') === 0) {
                $ancestorIncluded = rtrim($ep, '/');
                break;
            }
        }
    }

    // Detect any subdirectory (for caret rendering) + count for display.
    $hasSubdirs = false; $subDirCount = 0;
    foreach (@scandir($absPath) ?: [] as $i) {
        if ($i === '.' || $i === '..' || $i[0] === '.') continue;
        if (is_dir($absPath . '/' . $i)) { $hasSubdirs = true; $subDirCount++; }
    }

    $delUrl = '?p=' . urlencode($parentRelPath) . '&del=' . urlencode($folderName);
    $navUrl = '?p=' . urlencode($relPath);

    $cnt     = countDirImagesSplit($absPath);
    $sz      = getDirectorySize($absPath);
    $sizeStr = $sz !== false ? number_format($sz / (1024 * 1024), 2) . ' MB' : 'N/A';

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $indentPx  = $depth * 22;
    $extraClass = ($depth > 0 ? ' tree-child' : '') . ($rowSkipped ? ' row-skipped' : '');

    $caret = $hasSubdirs
        ? '<span class="tree-caret" data-rel="' . $h($relPath) . '" title="Expand subfolders" onclick="toggleTree(this)"><i class="fa fa-caret-right"></i></span>'
        : '<span class="tree-caret tree-caret-empty"></span>';

    $linkArrow = $is_link
        ? ' &rarr; <i>' . $h(@readlink($absPath)) . '</i>'
        : '';

    $bulkCb = ($depth === 0)
        ? '<input type="checkbox" name="file[]" id="r' . $rowKey . '" value="' . $h($folderName) . '"><label for="r' . $rowKey . '"></label>'
        : '';

    $toggleHtml = '';
    if (!$inTrash) {
        if ($ancestorIncluded !== null) {
            // "Via parent" — toggle visually ON (because the slideshow IS
            // playing this folder's images) but disabled. Tooltip + badge
            // explain why the toggle isn't directly interactive.
            $viaTip = 'Included via ' . htmlspecialchars(basename($ancestorIncluded), ENT_QUOTES, 'UTF-8')
                    . ' — remove the parent to exclude this folder.';
            $toggleHtml = '<label class="fm-toggle fm-via" title="' . $viaTip . '">'
                        . '<input type="checkbox" data-folder-path="' . $h($absPath) . '" checked disabled>'
                        . '<span class="fm-toggle-slider"></span></label>';
        } else {
            $titleAttr  = $inList ? 'Remove from slideshow' : 'Add to slideshow';
            $toggleHtml = '<label class="fm-toggle" title="' . $titleAttr . '">'
                        . '<input type="checkbox" data-folder-path="' . $h($absPath) . '"'
                        . ($inList ? ' checked' : '')
                        . ' onchange="toggleSlideshowFolder(this)">'
                        . '<span class="fm-toggle-slider"></span></label>';
        }
    }

    // "via PARENT" badge rendered inline with the folder name.
    $viaBadge = ($ancestorIncluded !== null && !$inTrash)
        ? ' <span class="via-badge" title="Included via ' . $h($ancestorIncluded) . '">'
          . '<i class="fa fa-level-up fa-rotate-90"></i> via <b>' . $h(basename($ancestorIncluded)) . '</b></span>'
        : '';

    $countHtml = '<span style="white-space:nowrap"><i class="fa fa-picture-o" style="color:#5a8fc2"></i> '
               . $cnt['active'] . ' imgs'
               . ($cnt['skipped'] > 0 ? ' <span class="skipped-count">(' . $cnt['skipped'] . ' skipped)</span>' : '')
               . '</span>'
               . ($subDirCount > 0
                  ? ' &nbsp;<span style="white-space:nowrap"><i class="fa fa-folder-o" style="color:#e8a838"></i> ' . $subDirCount . ' folders</span>'
                  : '');

    return '<tr class="folder-row' . $extraClass . '"'
         . ' data-depth="' . $depth . '"'
         . ' data-rel="' . $h($relPath) . '"'
         . ' data-parent-rel="' . $h($parentRelPath) . '">'
         . '<td>' . $bulkCb . '</td>'
         . '<td data-sort="' . $h($folderName) . '">'
         .   '<div class="filename" style="padding-left:' . $indentPx . 'px">'
         .     ($pathHint !== '' ? '<div class="path-hint">' . $h($pathHint) . '</div>' : '')
         .     $caret
         .     ' <a href="' . $h($navUrl) . '"><i class="' . $icon . '"></i> ' . $h($folderName) . '</a>'
         .     $linkArrow
         .     ($descendantInListCount > 0 && !$inTrash
                ? ' <span class="in-show-hint" title="' . $descendantInListCount
                  . ' descendant folder' . ($descendantInListCount === 1 ? '' : 's')
                  . ' included in the slideshow">&bull;</span>'
                : '')
         .     $viaBadge
         .   '</div>'
         . '</td>'
         . '<td class="inline-actions">'
         .   '<a title="Delete" href="' . $h($delUrl) . '"'
         .     ' onclick="confirmDialog(event,\'Delete Folder\',\'' . urlencode($folderName) . '\',this.href);return false;">'
         .     '<i class="fa fa-trash-o"></i></a>'
         .   '<a title="Rename" href="#"'
         .     ' onclick="rename(\'' . $h(addslashes($parentRelPath)) . '\',\'' . $h(addslashes($folderName)) . '\');return false;">'
         .     '<i class="fa fa-pencil-square-o"></i></a>'
         .   '&nbsp;' . $toggleHtml
         . '</td>'
         . '<td data-order="a-' . str_pad('', 18, '0', STR_PAD_LEFT) . '">' . $sizeStr . '</td>'
         . '<td>' . $countHtml . '</td>'
         . '<td data-order="' . $modif_raw . '">' . $h($modif) . '</td>'
         . '</tr>';
}
?>
