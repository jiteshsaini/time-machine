<?php
/*
 * var.php — config + low-level shared helpers.
 *
 * Loaded first by every entry point (manage.php, manage_ops.php, every
 * pages/*.php, every api/*.php, every util/*.php). Owns the global
 * $appName, all the resize/upload tuning constants, and the small
 * cross-cutting helpers everyone needs:
 *
 *   state_file($name)              — absolute path under code/txt/
 *   is_skipped_name / _path        — leading-underscore convention
 *   is_starred_name                — ~star filename suffix
 *   trash_root / images_root       — canonical directories
 *   safe_under($abs, $root)        — path-traversal guard
 *   unique_target_path($p)         — collision-free rename target
 *   image_date / image_date_ts     — EXIF-aware sort/display dates
 *   audit($action, $detail)        — rolling audit.log writer
 *   path_affects_slideshow_($abs)  — does this path matter to the slideshow?
 *   bump_change_status_if_affects_ — gated notify-the-viewer wrapper
 *
 * No URL of its own.
 */

// Application name — the app's folder under the web root, taken from where
// this code sits rather than fixed. Each copy then works on its own images/,
// trash/ and txt/: a development checkout at /var/www/html/tm plays with its
// sample albums and can never touch the library installed at
// /var/www/html/time_machine.
global $appName;
$appName = basename(dirname(__DIR__));

// ─── Resize batch tuning ─────────────────────────────────────────────────────
// RESIZE_MAX_RECURSIVE — hard cap on the recursive image count a single
//   "Resize" session is allowed to walk. Above this, the manage UI hides the
//   Resize button with a "split into subfolders" hint. Keeps wall-clock and
//   memory bounded.
// RESIZE_BATCH_SIZE    — number of images the python worker processes per
//   batch click. Lower = faster per click, more clicks total. Higher = fewer
//   clicks, longer per batch.
define('RESIZE_MAX_RECURSIVE', 5000);
define('RESIZE_BATCH_SIZE',    200);

// ─── App credit footer ───────────────────────────────────────────────────────
// credit footer shown at the bottom of every
// management / workflow page 
define('APP_CREDIT_LABEL', 'helloworld.co.in');
define('APP_CREDIT_URL',   'https://helloworld.co.in');

function app_credit_html() {
    $label = htmlspecialchars(APP_CREDIT_LABEL, ENT_QUOTES, 'UTF-8');
    $url   = htmlspecialchars(APP_CREDIT_URL,   ENT_QUOTES, 'UTF-8');
    return '<footer class="app-credit">&copy; '
         . '<a href="' . $url . '" target="_blank" rel="noopener">' . $label . '</a>'
         . '</footer>';
}

// ─── Destructive-action password ─────────────────────────────────────────────
// Soft confirmation against accidents — NOT a security boundary. The password
// is sent to the browser as plaintext JS; anyone with DevTools can bypass it.
//
// Persisted in two small state files (shipped in the repo with sensible
// defaults; changed via the Settings → 🔑 modal):
//   txt/action_password.txt   — single-line password
//   txt/require_password.txt  — '1' or '0'
//
// Self-heal: if either file is missing or empty, treat as "no password
// required" so a user who accidentally nukes the file can recover via the
// UI without SSH. They'll see no prompts; they can set a new password and
// flip the toggle back on from the Settings modal.

function action_password() {
    return trim((string) @file_get_contents(state_file('action_password.txt')));
}
function require_password_active() {
    $f = state_file('require_password.txt');
    if (!file_exists($f))                 return false;          // self-heal
    if (action_password() === '')         return false;          // self-heal
    return trim((string) @file_get_contents($f)) === '1';
}

/**
 * Emit the action-password JS globals + a shared `requireActionPassword()`
 * helper. Call this once in the <head> (or just after <body>) of any page
 * that has destructive actions, then use `requireActionPassword(label)` in JS
 * wherever you used to inline-prompt for the password.
 *
 * Returns true from the helper when no password is required (or correct);
 * false when the user cancelled or entered the wrong password.
 */
function emit_action_password_js() {
    $pw = json_encode(action_password());
    $rq = require_password_active() ? 'true' : 'false';
    echo "<script>
        window.ACTION_PASSWORD  = $pw;
        window.REQUIRE_PASSWORD = $rq;
        function requireActionPassword(actionLabel) {
            if (!window.REQUIRE_PASSWORD) return true;
            var pass = prompt('Enter password to confirm ' + actionLabel + ':');
            if (pass === null) return false;
            if (pass !== window.ACTION_PASSWORD) { alert('Incorrect password.'); return false; }
            return true;
        }
    </script>";
}

/**
 * Resolve a path for one of the app's flat-file state files
 * (paths.txt, delay.txt, shuffle.txt, etc. — all under txt/).
 */
function state_file($name) {
    return __DIR__ . '/txt/' . $name;
}

/**
 * "Skipped" files/folders are marked with a leading underscore — kept on
 * disk but excluded from the slideshow scan and hidden by default in
 * manage.php / find_duplicates.php / img_resize.py.
 */
function is_skipped_name($name) {
    return is_string($name) && strlen($name) > 0 && $name[0] === '_';
}

/**
 * "Star" marker — appended just before the extension, e.g. "photo~star.jpg".
 * Survives file moves/restore (it's part of the filename, no external index)
 * and is independent of the ordering prefix ("100~photo.jpg") and the skip
 * prefix ("_photo.jpg"), which can coexist with it.
 */
function is_starred_name($name) {
    return is_string($name) && (bool) preg_match('/~star(\.[a-z0-9]+)$/i', $name);
}

/** Add ~star before the extension. No-op if already starred. */
function add_star_to_name($name) {
    if (is_starred_name($name)) return $name;
    if (preg_match('/^(.*)(\.[a-z0-9]+)$/i', $name, $m)) {
        return $m[1] . '~star' . $m[2];
    }
    return $name . '~star';   // no extension — just append
}

/** Strip ~star from before the extension. No-op if not starred. */
function remove_star_from_name($name) {
    return preg_replace('/~star(\.[a-z0-9]+)$/i', '$1', $name);
}

/** Strip ~star for display purposes — leaves the on-disk name untouched. */
function display_name_strip_star($name) {
    return remove_star_from_name($name);
}

/** True if the request is filtering to show only starred items (?starred=1). */
function starred_only_active() {
    return !empty($_GET['starred']);
}

/**
 * True if ANY segment of the given path starts with '_' (skipped) or '.'
 * (hidden). Use this to enforce the skip rule when a folder is referenced
 * directly (e.g. via paths.txt) instead of being reached by recursive scan.
 * Without this, adding "_archived" via the toggle would still leak its
 * images into the slideshow.
 */
function is_skipped_path($path) {
    foreach (explode('/', str_replace('\\', '/', (string)$path)) as $seg) {
        if ($seg !== '' && ($seg[0] === '_' || $seg[0] === '.')) return true;
    }
    return false;
}

/**
 * Was the "Show skipped" toggle activated for the current request?
 * Both manage views read this so the toggle vocabulary stays consistent.
 */
/**
 * Tri-state filter mode for skipped (_*) files in the list / grid views.
 *
 *   'off'  — hide skipped files (the default)
 *   'all'  — show skipped alongside regular
 *   'only' — show only the skipped files
 *
 * Driven by ?show_skipped= in the URL. Legacy ?show_skipped=1 maps to 'all'.
 */
// Two states: 'off' (hide skipped) or 'only' (show only skipped).
// Any legacy 'all' value coming in from old cookies / URLs is now treated
// as 'only' to keep "include skipped" behavior somewhat similar.
function show_skipped_mode() {
    $v = $_GET['show_skipped'] ?? '';
    if ($v === 'only' || $v === 'all') return 'only';
    return 'off';
}
function show_skipped_active() {
    return show_skipped_mode() !== 'off';
}

/**
 * Absolute path to the trash root (sibling of images/ inside the app).
 * Created on first access. Mirrors the images/ tree internally so a
 * file at images/A/B/c.jpg trashes to trash/A/B/c.jpg, which makes
 * restore a simple move-back.
 */
function trash_root() {
    global $appName;
    $root = $_SERVER['DOCUMENT_ROOT'] . '/' . $appName . '/trash';
    if (!is_dir($root)) {
        $old = umask(0);
        @mkdir($root, 0775, true);
        umask($old);
    }
    return $root;
}

function images_root() {
    global $appName;
    return $_SERVER['DOCUMENT_ROOT'] . '/' . $appName . '/images';
}

/**
 * Verify an absolute path lies inside a given allowed root (after
 * resolving symlinks). Returns the canonical realpath on success,
 * false on rejection. Used by trash endpoints to refuse paths
 * outside images/ or trash/.
 */
function safe_under($abs, $root) {
    $real = @realpath($abs);
    if ($real === false) return false;
    $root = rtrim($root, '/');
    return strpos($real, $root . '/') === 0 ? $real : false;
}

/**
 * Pick a non-colliding target path: if $path already exists, append
 * "_<timestamp>" before the extension. Returns the chosen path.
 */
function unique_target_path($path) {
    if (!file_exists($path)) return $path;
    $dir  = dirname($path);
    $base = basename($path);
    $ext  = pathinfo($base, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($base, 0, -(strlen($ext) + 1)) : $base;
    $sfx  = '_' . date('ymdHis');
    $cand = $dir . '/' . $stem . $sfx . ($ext ? '.' . $ext : '');
    $i = 0;
    while (file_exists($cand)) {
        $i++;
        $cand = $dir . '/' . $stem . $sfx . '_' . $i . ($ext ? '.' . $ext : '');
    }
    return $cand;
}

/**
 * Resolve a "best available" timestamp for a photo.
 * Priority: EXIF DateTimeOriginal → EXIF DateTime → filemtime.
 * Returns unix seconds, or 0 if nothing found.
 */
function image_date_ts($absPath) {
    if (!is_file($absPath)) return 0;

    if (function_exists('exif_read_data')) {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff'])) {
            $exif = @exif_read_data($absPath, 'IFD0,EXIF', false, false);
            if (is_array($exif)) {
                $raw = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
                // EXIF format: "YYYY:MM:DD HH:MM:SS" — guard against blank or
                // zero placeholders (some cameras / re-saved files emit
                // "0000:00:00 00:00:00" or all-spaces, which overflows strtotime).
                if (is_string($raw) && preg_match('/^(19|20)\d{2}:[01]\d:[0-3]\d /', $raw)) {
                    $t = @strtotime(str_replace(':', '-', substr($raw, 0, 10)) . substr($raw, 10));
                    if ($t && $t > 0) return $t;
                }
            }
        }
    }

    $mt = @filemtime($absPath);
    return ($mt && $mt > 0) ? $mt : 0;
}

/**
 * Resolve a "best available" date for a photo, formatted for display.
 * Priority chain identical to image_date_ts(); returns '' if nothing found.
 */
function image_date($absPath) {
    $ts = image_date_ts($absPath);
    return $ts ? date('j M Y  H:i', $ts) : '';
}

/**
 * Append an entry to the per-display audit log.
 * Format: ISO-time | IP | action | detail
 * Trimmed to last 500 lines on each write.
 */
function audit($action, $detail = '') {
    $logFile = state_file('audit.log');
    $line = sprintf(
        "%s | %s | %s | %s\n",
        date('c'),
        $_SERVER['REMOTE_ADDR'] ?? '-',
        $action,
        str_replace(["\n", "\r"], ' ', (string) $detail)
    );
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    // Cheap rolling trim
    if (filesize($logFile) > 200000) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $lines = array_slice($lines, -500);
        file_put_contents($logFile, implode("\n", $lines) . "\n", LOCK_EX);
    }
}

/**
 * Does $absPath belong to (or contain) anything the slideshow plays?
 * True when:
 *   - $absPath is a paths.txt entry, OR
 *   - $absPath is inside one of those entries (descendant), OR
 *   - some paths.txt entry is inside $absPath (changing an ancestor of a
 *     slideshow folder), OR
 *   - same two relationships for starred_paths.txt.
 *
 * Reads both state files (typically <4 KB each, hot in the OS file cache).
 * Sub-ms in practice; safe to call many times per request.
 */
function path_affects_slideshow_($absPath) {
    if (!$absPath) return false;
    $absPath = rtrim($absPath, '/');
    $check = function ($lines) use ($absPath) {
        foreach ($lines as $line) {
            $p = rtrim(trim($line), '/');
            if ($p === '') continue;
            if ($p === $absPath) return true;
            if (strpos($absPath . '/', $p . '/') === 0) return true;     // ancestor in list
            if (strpos($p . '/', $absPath . '/') === 0) return true;     // descendant in list
        }
        return false;
    };
    $paths = @file(state_file('paths.txt'),         FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if ($check($paths)) return true;
    $stars = @file(state_file('starred_paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return $check($stars);
}

/**
 * Bump change_status.txt only if either path affects what the slideshow
 * plays. Convenience wrapper so each handler is a single line. Pass "old"
 * always; pass "new" too for renames/moves so a file moving INTO a
 * slideshow folder also triggers a reload.
 */
function bump_change_status_if_affects_($old, $new = null) {
    if (path_affects_slideshow_($old) || ($new !== null && path_affects_slideshow_($new))) {
        @file_put_contents(state_file('change_status.txt'), '1');
    }
}
?>
