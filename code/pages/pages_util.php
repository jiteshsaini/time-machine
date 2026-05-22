<?php
/*
 * pages_util.php — shared helpers for the workflow pages in this folder:
 *   trash.php, starred.php, upload.php, crop.php, find_duplicates.php,
 *   set_order.php
 *
 * Each page is a dedicated tool (one job each) rather than a multi-tab
 * manager. They share visual chrome — a sticky white header with title,
 * context line, page-specific controls, primary action, and a Back link
 * to manage.php — plus the optional left-sidebar drawer (trash, starred).
 *
 * Helpers provided:
 *   page_chrome_styles()           — CSS for header, substrip, sidebar drawer
 *   page_header($opts)             — sticky header + Back/Home links
 *   pages_emit_sidebar_drawer_js() — drawer open/close/swipe/Esc (safe no-op
 *                                    on pages without a #folderSidebar)
 *   app_credit_html()              — footer attribution (in var.php)
 *
 * Include AFTER var.php.
 */

/**
 * Emit the shared CSS for the page-level header. Call inside <style>…</style>
 * in <head> of each page that uses page_header().
 */
function page_chrome_styles() {
?>
    /* App credit footer — present on all workflow pages. */
    .app-credit { text-align:center; padding:16px 12px; margin-top:24px;
                  font-size:12px; color:#999; border-top:1px solid #eee; }
    .app-credit a { color:#888; text-decoration:none; }
    .app-credit a:hover { color:#555; text-decoration:underline; }

    .page-header {
        position: sticky; top: 0; z-index: 30;
        display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
        padding: 8px 14px;
        background: #fff;
        border-bottom: 1px solid #d8e3f1;
        box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        font-size: 14px;
    }
    .page-header .ph-title {
        font-weight: 700; color: #222;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        min-width: 0;
    }
    .page-header .ph-context {
        color: #888; font-size: 12px; font-weight: 400;
        margin-left: 4px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        min-width: 0; flex: 1 1 auto;
    }
    .page-header .ph-controls {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .page-header .ph-primary {
        margin-left: auto;
    }
    .page-header .ph-back, .page-header .ph-home {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 10px;
        border: 1px solid #ccc; border-radius: 4px;
        background: #fff; color: #333; text-decoration: none;
        white-space: nowrap;
    }
    .page-header .ph-back:hover, .page-header .ph-home:hover { background: #f1f5fb; }
    .page-header .ph-home { padding: 4px 9px; }   /* icon-only */

    /* Optional sub-row below the header for stats / breadcrumbs. */
    .page-substrip {
        display: flex; flex-wrap: wrap; align-items: center; gap: 14px;
        padding: 6px 14px;
        background: #f8fafc;
        border-bottom: 1px solid #e6eaf0;
        font-size: 12px; color: #555;
    }
    .page-substrip b { color: #222; font-weight: 600; }
    .page-substrip .savings { color: #228a2c; font-weight: 600; }

    @media (max-width: 600px) {
        .page-header { padding: 6px 10px; gap: 6px; }
        .page-header .ph-context { display: none; }
    }

    /* ── Sidebar drawer (shared with grid view) — folder filter list ─── */
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
        padding:4px 9px; border-radius:4px; cursor:pointer;
        font-size:14px; line-height:1;
    }
    .sidebar-drawer-btn:hover { background:#eef2f8; }
    body.has-sidebar .sidebar-drawer-btn { display:inline-flex; align-items:center; }
    .sb-head {
        display:flex; align-items:center; gap:6px; padding:8px 10px;
        border-bottom:1px solid #e6e6ea; background:#fff;
        font-size:12px; color:#666; font-weight:600; letter-spacing:.3px;
        text-transform:uppercase; flex:0 0 auto;
    }
    .sb-head-title { flex:1; display:flex; align-items:center; gap:6px; }
    .sb-head-title i { color:#0157b3; }
    .sb-scroll { flex:1; overflow-y:auto; padding:6px 4px 80px; }
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

    /* ── Hierarchical folder tree (shared by starred / trash sidebars) ── */
    .sb-tree { list-style:none; padding:0; margin:0; }
    .sb-tree ul { list-style:none; padding:0; margin:0; }
    .sb-tnode > ul { padding-left:14px; border-left:1px dotted #d7dde6; margin-left:11px; }
    .sb-tnode.sb-tcollapsed > ul { display:none; }
    .sb-trow {
        display:flex; align-items:center; gap:2px;
        padding:5px 10px 5px 4px;
        border-left:3px solid transparent;
        font-size:13px; color:#333;
    }
    .sb-trow:hover { background:#eef2f8; }
    .sb-trow.sb-tactive {
        background:#dbeafe; border-left-color:#0157b3;
        font-weight:600; color:#0157b3;
    }
    .sb-trow.sb-tactive .sb-tcount { color:#0157b3; }
    .sb-tchev {
        flex-shrink:0; width:18px; height:18px;
        display:inline-flex; align-items:center; justify-content:center;
        background:transparent; border:0; padding:0; cursor:pointer;
        color:#888; font-size:11px;
    }
    .sb-tchev-blank { cursor:default; }
    .sb-tchev i { transition:transform .12s ease-out; }
    .sb-tnode:not(.sb-tcollapsed) > .sb-trow .sb-tchev i { transform:rotate(90deg); }
    .sb-trow > a {
        flex:1; min-width:0;
        display:flex; align-items:center; gap:7px;
        color:inherit; text-decoration:none;
    }
    .sb-trow > a > i.fa-folder-o,
    .sb-trow > a > i.fa-folder { color:#bba56b; font-size:13px; flex-shrink:0; }
    .sb-tname { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sb-tcount { color:#888; font-size:11px; font-variant-numeric:tabular-nums; flex-shrink:0; }

    @media (min-width: 1024px) {
        #folderSidebar {
            width:250px; max-width:none; transform:none; transition:none;
            z-index:60;
        }
        #sidebarBackdrop { display:none; }
        .sidebar-drawer-btn { display:none !important; }
        body.has-sidebar { padding-left:250px; }
        body.has-sidebar .bulk-bar { left:250px; }
    }
<?php
}

/**
 * Emit the shared drawer JS — toggle / close / Esc / swipe / resize / nav-
 * click. Safe no-op on pages without a #folderSidebar.
 */
function pages_emit_sidebar_drawer_js() {
?>
<script>
window.toggleSidebarDrawer = function() { document.body.classList.toggle('sidebar-open'); };
window.closeSidebarDrawer  = function() { document.body.classList.remove('sidebar-open'); };
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('folderSidebar');
    if (!sidebar) return;
    var backdrop = document.getElementById('sidebarBackdrop');
    if (backdrop) backdrop.addEventListener('click', closeSidebarDrawer);
    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape' && document.body.classList.contains('sidebar-open')) closeSidebarDrawer();
    });
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) closeSidebarDrawer();
    });
    var tx = null;
    sidebar.addEventListener('touchstart', function(ev) {
        if (ev.touches.length === 1) tx = ev.touches[0].clientX;
    }, { passive: true });
    sidebar.addEventListener('touchend', function(ev) {
        if (tx === null) return;
        var dx = (ev.changedTouches[0] ? ev.changedTouches[0].clientX : tx) - tx;
        if (dx < -40) closeSidebarDrawer();
        tx = null;
    }, { passive: true });
    sidebar.addEventListener('click', function(ev) {
        // Chevron clicks expand/collapse — never close the drawer.
        if (ev.target.closest('.sb-tchev')) return;
        var a = ev.target.closest('a');
        if (a && a.getAttribute('href')) closeSidebarDrawer();
    });

    // Folder-tree expand/collapse with localStorage persistence.
    // We store the set of paths the user has explicitly opened beyond the
    // server-rendered defaults (active path + ancestors are already open).
    // Toggling a default-open node adds it to the "user closed" set.
    var OPEN_KEY   = 'pagesSbTreeOpen';
    var CLOSED_KEY = 'pagesSbTreeClosed';
    function loadSet(key) {
        try { return new Set(JSON.parse(localStorage.getItem(key) || '[]')); }
        catch (_) { return new Set(); }
    }
    function saveSet(key, set) {
        try { localStorage.setItem(key, JSON.stringify(Array.from(set))); } catch (_) {}
    }
    var opened = loadSet(OPEN_KEY);
    var closed = loadSet(CLOSED_KEY);

    // Apply persisted overrides on top of server-rendered defaults.
    sidebar.querySelectorAll('li.sb-tnode').forEach(function(li) {
        var path = li.dataset.path;
        if (!path) return;
        if (opened.has(path))      li.classList.remove('sb-tcollapsed');
        else if (closed.has(path)) li.classList.add('sb-tcollapsed');
    });

    sidebar.addEventListener('click', function(ev) {
        var chev = ev.target.closest('.sb-tchev');
        if (!chev || chev.classList.contains('sb-tchev-blank')) return;
        ev.preventDefault();
        ev.stopPropagation();
        var li = chev.closest('li.sb-tnode');
        if (!li) return;
        var path = li.dataset.path;
        var nowCollapsed = li.classList.toggle('sb-tcollapsed');
        if (nowCollapsed) {
            closed.add(path);  opened.delete(path);
        } else {
            opened.add(path);  closed.delete(path);
        }
        saveSet(OPEN_KEY, opened);
        saveSet(CLOSED_KEY, closed);
    });
});
</script>
<?php
}

/**
 * Render a hierarchical folder-tree sidebar (replaces the flat sb-flist used
 * on starred.php / trash.php). Takes the same flat folder→info map already
 * built by those pages (folder relative path → ['count' => N, ...]) and
 * emits a nested <ul> where parent paths are listed once and children are
 * indented underneath.
 *
 * Special path "(root)" (used for entries directly under images_root) is
 * rendered as a sibling leaf, not nested.
 *
 * The active node + its ancestors are expanded by default; everything else
 * is collapsed. User toggles persist in localStorage via the JS in
 * pages_emit_sidebar_drawer_js().
 *
 * @param array  $sbFolderIndex  rel-path => ['count' => N, ...]
 * @param string $folderFilter   current ?folder= value ('' for "All")
 * @param array  $baseQuery      current $_GET, used to preserve other params
 * @param string $allLabel       label for the top "All …" link
 * @param string $allIcon        Font-Awesome class for the "All" icon (e.g. 'fa-star-o')
 */
function pages_render_folder_tree($sbFolderIndex, $folderFilter, $baseQuery, $allLabel, $allIcon) {
    // ── Build the tree from the flat map ───────────────────────────────────
    $root = ['children' => [], 'own' => 0, 'name' => '', 'path' => ''];
    foreach ($sbFolderIndex as $path => $info) {
        if ($path === '(root)') {
            // Stash as a synthetic child of the root so it renders alongside
            // the top-level real folders.
            if (!isset($root['children']['(root)'])) {
                $root['children']['(root)'] = ['children' => [], 'own' => 0, 'name' => '(root)', 'path' => '(root)'];
            }
            $root['children']['(root)']['own'] = (int)$info['count'];
            continue;
        }
        $parts = explode('/', $path);
        $node = &$root;
        $cum  = '';
        foreach ($parts as $part) {
            $cum = $cum === '' ? $part : ($cum . '/' . $part);
            if (!isset($node['children'][$part])) {
                $node['children'][$part] = ['children' => [], 'own' => 0, 'name' => $part, 'path' => $cum];
            }
            $node = &$node['children'][$part];
        }
        $node['own'] = (int)$info['count'];
        unset($node);
    }

    // Recursive total = own + all descendants.
    $totalOf = function($node) use (&$totalOf) {
        $sum = (int)$node['own'];
        foreach ($node['children'] as $c) $sum += $totalOf($c);
        return $sum;
    };

    // ── Header: "All …" link (clears the filter) ──────────────────────────
    $grandTotal = 0;
    foreach ($root['children'] as $c) $grandTotal += $totalOf($c);

    $allHref   = '?' . http_build_query(array_diff_key($baseQuery, ['folder' => 1]));
    $allActive = $folderFilter === '' ? ' sb-tactive' : '';
    $out  = '<ul class="sb-tree">';
    $out .= '<li class="sb-tnode sb-tnode-all"><div class="sb-trow' . $allActive . '">'
          . '<span class="sb-tchev sb-tchev-blank"></span>'
          . '<a href="' . htmlspecialchars($allHref) . '">'
          . '<i class="fa ' . htmlspecialchars($allIcon) . '"></i>'
          . '<span class="sb-tname">' . htmlspecialchars($allLabel) . '</span>'
          . '<span class="sb-tcount">' . $grandTotal . '</span>'
          . '</a></div></li>';

    // ── Recursive renderer ────────────────────────────────────────────────
    $render = function($children, $depth) use (&$render, $folderFilter, $baseQuery, $totalOf) {
        if (empty($children)) return '';
        // Sort: by descendant-aware total desc, then by name asc.
        uasort($children, function($a, $b) use ($totalOf) {
            $cmp = $totalOf($b) <=> $totalOf($a);
            if ($cmp !== 0) return $cmp;
            return strcasecmp($a['name'], $b['name']);
        });
        $out = '<ul' . ($depth === 0 ? '' : '') . '>';
        foreach ($children as $c) {
            $tot      = $totalOf($c);
            $hasKids  = !empty($c['children']);
            $isActive = ($c['path'] === $folderFilter);
            // Ancestor of the active folder (so we auto-expand the chain).
            $isAncestor = $folderFilter !== ''
                       && $c['path'] !== ''
                       && strpos($folderFilter . '/', $c['path'] . '/') === 0
                       && !$isActive;
            $collapsed = $hasKids && !$isActive && !$isAncestor;

            $qp = array_merge($baseQuery, ['folder' => $c['path']]);
            $href = '?' . http_build_query($qp);

            $liCls = 'sb-tnode' . ($collapsed ? ' sb-tcollapsed' : '');
            $out .= '<li class="' . $liCls . '" data-path="' . htmlspecialchars($c['path']) . '">';
            $out .= '<div class="sb-trow' . ($isActive ? ' sb-tactive' : '') . '">';
            if ($hasKids) {
                $out .= '<button type="button" class="sb-tchev" tabindex="-1" aria-label="Toggle children">'
                      . '<i class="fa fa-caret-right"></i></button>';
            } else {
                $out .= '<span class="sb-tchev sb-tchev-blank"></span>';
            }
            $out .= '<a href="' . htmlspecialchars($href) . '" title="' . htmlspecialchars($c['path']) . '">'
                  . '<i class="fa fa-folder-o"></i>'
                  . '<span class="sb-tname">' . htmlspecialchars($c['name']) . '</span>'
                  . '<span class="sb-tcount">' . $tot . '</span>'
                  . '</a>';
            $out .= '</div>';
            if ($hasKids) $out .= $render($c['children'], $depth + 1);
            $out .= '</li>';
        }
        $out .= '</ul>';
        return $out;
    };

    // Render top-level children inline (not wrapped in another <ul> — we're
    // already inside .sb-tree above).
    if (!empty($root['children'])) {
        // Pull top-level inner <ul> open/close so the children sit inside
        // the same .sb-tree list as the "All" entry.
        $inner = $render($root['children'], 0);
        // strip the outer <ul></ul> the renderer emits, since we're already
        // inside .sb-tree.
        $inner = preg_replace('/^<ul>|<\/ul>$/', '', $inner);
        $out .= $inner;
    }

    $out .= '</ul>';
    return $out;
}

/**
 * Render the shared header for a workflow page.
 *
 * @param array $opts {
 *   @var string $title         Page title (e.g. "Find Duplicates").
 *   @var string $context       Optional small grey sub-line (path, filename, etc.).
 *   @var string $backHref      URL for the Back link (required).
 *   @var string $backLabel     Back link label. Defaults to "Back".
 *   @var string $controlsHtml  Optional HTML for page-specific controls (middle slot).
 *   @var string $primaryHtml   Optional HTML for the page's primary action button (before Back).
 *   @var string $statsHtml     Optional HTML for the sub-strip below the header.
 * }
 */
function page_header($opts) {
    global $appName;
    $title        = $opts['title']        ?? '';
    $context      = $opts['context']      ?? '';
    $backHref     = $opts['backHref']     ?? '';                           // empty → no Back link
    $backLabel    = $opts['backLabel']    ?? 'Back';
    $controlsHtml = $opts['controlsHtml'] ?? '';
    $primaryHtml  = $opts['primaryHtml']  ?? '';
    $statsHtml    = $opts['statsHtml']    ?? '';
    // Always-available Home link — anchored at the images root so the user
    // can never get lost in the workflow pages.
    $homeHref     = $opts['homeHref']     ?? ('../manage.php?p=' . urlencode(($appName ?? '') . '/images'));

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<header class="page-header">
  <button type="button" class="sidebar-drawer-btn" onclick="toggleSidebarDrawer()"
          title="Show folder filter" aria-label="Show folder filter">
    <i class="fa fa-sitemap"></i>
  </button>
  <a class="ph-home" href="<?php echo $h($homeHref) ?>" title="Home — back to image manager">
    <i class="fa fa-home"></i>
  </a>
  <span class="ph-title"><?php echo $h($title) ?></span>
  <?php if ($context !== ''): ?>
  <span class="ph-context"><?php echo $h($context) ?></span>
  <?php endif; ?>
  <?php if ($controlsHtml !== ''): ?>
  <span class="ph-controls"><?php echo $controlsHtml ?></span>
  <?php endif; ?>
  <?php if ($primaryHtml !== ''): ?>
  <span class="ph-primary"><?php echo $primaryHtml ?></span>
  <?php endif; ?>
  <?php if ($backHref !== ''): ?>
  <a class="ph-back" href="<?php echo $h($backHref) ?>" title="Back to where you came from">
    <i class="fa fa-arrow-left"></i> <?php echo $h($backLabel) ?>
  </a>
  <?php endif; ?>
</header>
<?php if ($statsHtml !== ''): ?>
<div class="page-substrip"><?php echo $statsHtml ?></div>
<?php endif;
}
