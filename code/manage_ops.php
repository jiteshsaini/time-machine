<?php
/*
 * manage_ops.php — backend operations endpoint.
 *
 * Receives every AJAX + POST request the front-end UI (manage.php, the
 * grid view) and the standalone pages (pages/upload.php, pages/trash.php,
 * pages/starred.php) make. It owns no UI of its own — every response
 * is JSON, a small HTML fragment, or a redirect.
 *
 *   GET  ?slideshow_body=1                 modal body fragment refresh
 *   GET  ?ajax_folders=1                   folder browser (Copy/Move modal)
 *   GET  ?folder_path=...&folder_path_action=add|remove   slideshow toggle
 *   GET  ?clear_file=1                     wipe paths.txt
 *   GET  ?del=...    (+ POST token)        folder/file delete
 *   GET  ?copy=...&finish (+ &move)        single Copy/Move
 *   POST file[] + copy_to + finish         bulk Copy/Move
 *   POST group + delete                    bulk delete
 *   POST newfile + newfilename             new folder
 *   POST rename_from + rename_to           rename
 *   POST save_delay                        set slideshow delay
 *   POST save_playlist / load_playlist / delete_playlist
 *   POST file upload chunks                Dropzone target (used by pages/upload.php)
 *
 * Any request that doesn't match a handler falls through to a redirect
 * into manage.php (the front-end view) so old bookmarks still land in a
 * useful place.
 *
 * Companion files:
 *   manage.php       — the front-end view (grid + in-page list toggle)
 *   manage_util.php  — shared rendering + helpers (included by both)
 */
ini_set('display_errors', '1');
include_once "var.php";
include_once "manage_util.php";

// ─── Session & CSRF ──────────────────────────────────────────────────────────
define('FM_SESSION_ID', 'filemanager');
session_cache_limiter('nocache');
session_name(FM_SESSION_ID);
function session_error_handling_function($code, $msg, $file, $line) {
    if ($code == 2) { session_abort(); session_id(session_create_id()); @session_start(); }
}
set_error_handler('session_error_handling_function');
session_start();
restore_error_handler();

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(32))
        : bin2hex(openssl_random_pseudo_bytes(32));
}

// ─── Config ───────────────────────────────────────────────────────────────────
global $appName;

$root_path      = $_SERVER['DOCUMENT_ROOT'];
$root_url       = '';
$http_host      = $_SERVER['HTTP_HOST'];
$datetime_format = 'm/d/Y g:i A';
$sticky_navbar  = true;
$max_upload_size_bytes  = 5000000000;
$upload_chunk_size_bytes = 2000000;

define('APP_TITLE',       'Time Machine');
define('MAX_UPLOAD_SIZE', $max_upload_size_bytes);
define('UPLOAD_CHUNK_SIZE', $upload_chunk_size_bytes);
define('FM_READONLY',     false);
define('FM_IS_WIN',       DIRECTORY_SEPARATOR == '\\');
define('FM_ICONV_INPUT_ENC', 'UTF-8');
define('FM_DATETIME_FORMAT', $datetime_format);
define('FM_UPLOAD_EXTENSION', '');
define('FM_FILE_EXTENSION', '');
define('FM_EXCLUDE_ITEMS', []);
define('FM_SHOW_HIDDEN', false);
define('FM_USE_AUTH', false);

@set_time_limit(600);
date_default_timezone_set('Etc/UTC');
ini_set('default_charset', 'UTF-8');

$is_https = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1))
         || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
$root_path = rtrim(str_replace('\\', '/', $root_path), '/');
define('FM_ROOT_URL',  ($is_https ? 'https' : 'http') . '://' . $http_host . (!empty($root_url) ? '/' . $root_url : ''));
define('FM_SELF_URL',  ($is_https ? 'https' : 'http') . '://' . $http_host . $_SERVER['PHP_SELF']);
define('FM_ROOT_PATH', $root_path);

// ─── Slideshow modal body fragment (AJAX refresh after a toggle) ─────────────
// Returns only the inner HTML of the modal-body so the client can swap it
// in-place without reloading. Function declarations are hoisted, so calling
// the renderers here works even though they're defined later in the file.
if (!empty($_GET['slideshow_body'])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<ul class="nav nav-tabs mb-3" role="tablist">'
       . '<li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#sm-active">Active folders</a></li>'
       . '<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sm-playlists">Playlists</a></li>'
       . '</ul><div class="tab-content">'
       . '<div id="sm-active" class="tab-pane fade show active">';
    displayFileContentsWithRemoveOption(state_file('paths.txt'));
    echo '</div><div id="sm-playlists" class="tab-pane fade">';
    displayPlaylists();
    echo '</div></div>';
    exit;
}

// ─── Path param ──────────────────────────────────────────────────────────────
$p = isset($_GET['p']) ? $_GET['p'] : (isset($_POST['p']) ? $_POST['p'] : '');
$p = fm_clean_path($p);
define('FM_PATH', $p);
unset($p);

// ─── paths1.txt add / remove ─────────────────────────────────────────────────
if (file_exists(state_file('paths.txt'))) {
    $existingPaths = file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
} else {
    $existingPaths = [];
}

if (isset($_GET['folder_path'])) {
    $folderPath        = rtrim($_GET['folder_path'], '/');
    $folderPath_action = $_GET['folder_path_action'];
    $paths = file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $pathsTrim = array_map('rtrim', $paths, array_fill(0, count($paths), '/'));
    $stripped = [];   // descendants that were removed when this folder was added

    if ($folderPath_action == 'add') {
        // 0. Skipped folder ("_*" anywhere in the path) — refuse. The slideshow
        //    scan ignores these, so adding would silently do nothing.
        if (is_skipped_path($folderPath)) {
            fm_set_msg('Skipped folders (names starting with "_") can\'t be added to the slideshow. Rename the folder first.', 'alert');
        }
        // 1. Already present (exact match)? No-op.
        elseif (in_array($folderPath, $pathsTrim, true)) {
            fm_set_msg('Folder is already in the slideshow.', 'alert');
        } else {
            // 2. Any ancestor present? Refuse — adding would create duplicate
            //    images in the slideshow (parent's recursive scan already
            //    covers this folder).
            $ancestor = null;
            foreach ($pathsTrim as $existing) {
                if ($existing !== '' && strpos($folderPath . '/', $existing . '/') === 0) {
                    $ancestor = $existing; break;
                }
            }
            if ($ancestor !== null) {
                fm_set_msg('This folder is already included via <b>' . fm_enc(basename($ancestor)) . '</b>.', 'alert');
            } else {
                // 3. Strip any descendants — they become redundant once this
                //    folder is added (recursive scan covers them).
                $pathsTrim = array_filter($pathsTrim, function($p) use ($folderPath, &$stripped) {
                    if ($p !== '' && strpos($p . '/', $folderPath . '/') === 0) {
                        $stripped[] = $p; return false;
                    }
                    return true;
                });
                $pathsTrim[] = $folderPath;
                file_put_contents(state_file('paths.txt'), implode(PHP_EOL, $pathsTrim) . PHP_EOL, LOCK_EX);
                file_put_contents(state_file('change_status.txt'), '1');
                audit('folder.add', $folderPath . (empty($stripped) ? '' : ' (replaced ' . count($stripped) . ' redundant subentries)'));
                if (!empty($stripped)) {
                    fm_set_msg('Added — removed ' . count($stripped) . ' redundant subfolder entr' . (count($stripped) != 1 ? 'ies' : 'y') . ' covered by this folder.', 'ok');
                }
            }
        }
    }
    if ($folderPath_action == 'remove') {
        $paths = array_filter($pathsTrim, fn($x) => $x !== $folderPath);
        file_put_contents(state_file('paths.txt'), implode(PHP_EOL, $paths) . PHP_EOL, LOCK_EX);
        file_put_contents(state_file('change_status.txt'), '1');
        audit('folder.remove', $folderPath);
    }
    // AJAX caller (list-view toggle, modal-pill remove). Return the new state
    // + any flash message so the client can update UI without reloading.
    if (!empty($_GET['ajax'])) {
        header('Content-Type: application/json');
        $paths     = file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $paths     = array_map(fn($x) => rtrim($x, '/'), $paths);
        $nowInList = in_array($folderPath, $paths, true);
        $msg       = $_SESSION[FM_SESSION_ID]['message'] ?? '';
        $status    = $_SESSION[FM_SESSION_ID]['status']  ?? 'ok';
        unset($_SESSION[FM_SESSION_ID]['message'], $_SESSION[FM_SESSION_ID]['status']);
        // Aggregate counts mirror mgr_render_status_bar() so the client can
        // update the status bar inline without reloading.
        $totalImgs = 0;
        foreach ($paths as $ep) $totalImgs += mgr_count_dir_images(trim($ep));
        echo json_encode([
            'success'  => true,
            'inList'   => $nowInList,
            'msg'      => strip_tags((string)$msg),
            'status'   => $status,
            'stripped' => array_values($stripped),  // descendant paths removed in this add
            'paths'    => array_values($paths),     // current paths.txt — lets the client refresh descendant hints
            'stats'    => [
                'folderCount' => count($paths),
                'imageCount'  => $totalImgs,
            ],
        ]);
        exit;
    }
    $rp = $_GET;
    unset($rp['folder_path'], $rp['folder_path_action']);
    $rurl = $_SERVER['PHP_SELF'] . (!empty($rp) ? '?' . http_build_query($rp) : '');
    header("Location: $rurl"); exit;
}

if (isset($_GET['clear_file']) && $_GET['clear_file'] == 1) {
    file_put_contents(state_file('paths.txt'), '');
    file_put_contents(state_file('change_status.txt'), '1');
    // Wiping the selection breaks any playlist association — clear it so
    // the modal banner doesn't keep pointing at a playlist with no folders.
    @file_put_contents(state_file('current_playlist.txt'), '');
    audit('folder.clear_all');
}

// ─── Delay: Save ──────────────────────────────────────────────────────────────
if (isset($_POST['save_delay'], $_POST['delay'], $_POST['token'])) {
    if (!verifyToken($_POST['token'])) {
        fm_set_msg('Invalid Token.', 'error');
    } else {
        $newDelay = (int) $_POST['delay'];
        if ($newDelay > 0) {
            file_put_contents(state_file('delay.txt'), $newDelay);
            file_put_contents(state_file('change_status.txt'), '1');
            audit('settings.delay', (string) $newDelay);
            fm_set_msg('Delay set to <b>' . $newDelay . 's</b>.', 'ok');
        } else {
            fm_set_msg('Delay must be greater than 0.', 'error');
        }
    }
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// ─── Action password: change ────────────────────────────────────────────────
// AJAX-only. Validates the current password against the stored value (or
// the ACTION_PASSWORD_DEFAULT on first run), then writes the new password
// + the require-password flag to the two state files. The settings UI
// lives in the Settings modal — see mgr_render_settings_modal().
if (isset($_POST['save_action_password'])) {
    header('Content-Type: application/json');
    if (!isset($_POST['token']) || !verifyToken($_POST['token'])) {
        echo json_encode(['success' => false, 'msg' => 'Invalid token.']); exit;
    }
    $cur     = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password']     ?? '');
    $require = !empty($_POST['require_password']);
    $stored  = action_password();
    // Self-heal: when no password is stored (file missing/empty) anyone
    // can set one. This is the recovery path after an accidental delete.
    if ($stored !== '' && !hash_equals($stored, $cur)) {
        echo json_encode(['success' => false, 'msg' => 'Current password is incorrect.']); exit;
    }
    // Only block enabling require when there's no usable password at all
    // (no stored password AND no new password supplied). If a password is
    // already stored, the user just wants to flip the toggle back on with
    // the existing password — that's fine.
    if ($require && trim($new) === '' && $stored === '') {
        echo json_encode(['success' => false, 'msg' => 'Set a password first (expand "Change password") before enabling.']); exit;
    }
    // Empty $new with require=off is fine — disables the prompt entirely.
    if (trim($new) !== '') {
        file_put_contents(state_file('action_password.txt'), $new, LOCK_EX);
    }
    file_put_contents(state_file('require_password.txt'), $require ? '1' : '0', LOCK_EX);
    audit('settings.action_password', $require ? 'changed (required)' : 'changed (off)');
    echo json_encode(['success' => true, 'msg' => 'Password settings saved.']); exit;
}

// ─── Playlist: Save ───────────────────────────────────────────────────────────
if (isset($_POST['save_playlist'], $_POST['playlist_name'], $_POST['token'])) {
    $isAjax = !empty($_POST['ajax']);
    $ok = false; $msg = ''; $status = 'error';
    if (!verifyToken($_POST['token'])) {
        $msg = 'Invalid Token.'; $status = 'error';
    } else {
        $pl_name = trim($_POST['playlist_name']);
        if ($pl_name === '') {
            $msg = 'Playlist name cannot be empty.'; $status = 'error';
        } else {
            $playlists = (file_exists(state_file('playlists.json')) && ($j = file_get_contents(state_file('playlists.json')))) ? json_decode($j, true) : [];
            if (!is_array($playlists)) $playlists = [];

            if (isset($playlists[$pl_name])) {
                $msg = 'A playlist named <b>' . htmlspecialchars($pl_name) . '</b> already exists. Choose a different name.';
                $status = 'error';
            } else {
                $currentPaths = file_exists(state_file('paths.txt')) ? file(state_file('paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
                if (empty($currentPaths)) {
                    $msg = 'No folders selected — nothing to save.'; $status = 'alert';
                } else {
                    $currentSorted = $currentPaths; sort($currentSorted);
                    $duplicate = null;
                    foreach ($playlists as $pname => $ppaths) {
                        $ps = $ppaths; sort($ps);
                        if ($ps === $currentSorted) { $duplicate = $pname; break; }
                    }
                    if ($duplicate !== null) {
                        $msg = 'This folder combination is already saved as <b>' . htmlspecialchars($duplicate) . '</b>.';
                        $status = 'alert';
                    } else {
                        $playlists[$pl_name] = $currentPaths;
                        file_put_contents(state_file('playlists.json'), json_encode($playlists, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        // The just-saved playlist becomes the loaded one, so
                        // the modal banner switches to its name immediately.
                        file_put_contents(state_file('current_playlist.txt'), $pl_name, LOCK_EX);
                        audit('playlist.save', $pl_name);
                        $ok  = true;
                        $msg = 'Playlist <b>' . htmlspecialchars($pl_name) . '</b> saved.';
                        $status = 'ok';
                    }
                }
            }
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'status' => $status, 'msg' => $msg]); exit;
    }
    fm_set_msg($msg, $status);
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// ─── Playlist: Load ───────────────────────────────────────────────────────────
if (isset($_POST['load_playlist'], $_POST['playlist_name'], $_POST['token'])) {
    $isAjax = !empty($_POST['ajax']);
    $ok = false; $msg = ''; $status = 'error';
    if (!verifyToken($_POST['token'])) {
        $msg = 'Invalid Token.';
    } else {
        $pl_name   = trim($_POST['playlist_name']);
        $playlists = (file_exists(state_file('playlists.json')) && ($j = file_get_contents(state_file('playlists.json')))) ? json_decode($j, true) : [];
        if (is_array($playlists) && isset($playlists[$pl_name])) {
            file_put_contents(state_file('paths.txt'), implode(PHP_EOL, $playlists[$pl_name]) . PHP_EOL, LOCK_EX);
            file_put_contents(state_file('change_status.txt'), '1');
            // Remember which playlist is now loaded so the modal can show its name.
            file_put_contents(state_file('current_playlist.txt'), $pl_name, LOCK_EX);
            audit('playlist.load', $pl_name);
            $ok  = true;
            $msg = 'Playlist <b>' . htmlspecialchars($pl_name) . '</b> loaded.';
            $status = 'ok';
        } else {
            $msg = 'Playlist not found.';
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'status' => $status, 'msg' => $msg]); exit;
    }
    fm_set_msg($msg, $status);
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// ─── Playlist: Delete ─────────────────────────────────────────────────────────
if (isset($_POST['delete_playlist'], $_POST['playlist_name'], $_POST['token'])) {
    $isAjax = !empty($_POST['ajax']);
    $ok = false; $msg = ''; $status = 'error';
    if (!verifyToken($_POST['token'])) {
        $msg = 'Invalid Token.';
    } else {
        $pl_name   = trim($_POST['playlist_name']);
        $playlists = (file_exists(state_file('playlists.json')) && ($j = file_get_contents(state_file('playlists.json')))) ? json_decode($j, true) : [];
        if (is_array($playlists) && isset($playlists[$pl_name])) {
            unset($playlists[$pl_name]);
            file_put_contents(state_file('playlists.json'), json_encode($playlists, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            // If the deleted playlist was the loaded one, clear the association
            // so the modal doesn't keep showing a dangling name.
            $curFile = state_file('current_playlist.txt');
            if (file_exists($curFile) && trim((string)@file_get_contents($curFile)) === $pl_name) {
                @file_put_contents($curFile, '', LOCK_EX);
            }
            audit('playlist.delete', $pl_name);
            $ok  = true;
            $msg = 'Playlist <b>' . htmlspecialchars($pl_name) . '</b> deleted.';
            $status = 'ok';
        } else {
            $msg = 'Playlist not found.';
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $ok, 'status' => $status, 'msg' => $msg]); exit;
    }
    fm_set_msg($msg, $status);
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// ─── AJAX: folder tree for copy/move browser ─────────────────────────────────
if (isset($_GET['ajax_folders'])) {
    header('Content-Type: application/json');
    $reqPath  = fm_clean_path($_GET['path'] ?? '');
    $imgRoot  = $appName . '/images';
    // Security: path must stay within time_machine/images
    if ($reqPath !== '' && !preg_match('/^' . preg_quote($appName, '/') . '\/images(\/.*)?$/', $reqPath)) {
        echo json_encode(['error' => 'Access denied']); exit;
    }
    $fullPath = FM_ROOT_PATH . ($reqPath !== '' ? '/' . $reqPath : '');
    $result   = [];
    if (is_dir($fullPath)) {
        foreach (scandir($fullPath) as $item) {
            if ($item === '.' || $item === '..') continue;
            if ($item[0] === '.') continue;
            if (is_dir($fullPath . '/' . $item)) $result[] = $item;
        }
        natcasesort($result);
    }
    // Parent path (don't go above images root)
    $parent = null;
    if ($reqPath !== '' && $reqPath !== $imgRoot) {
        $up = dirname($reqPath);
        $parent = ($up === $appName || $up === '.') ? $imgRoot : $up;
    }
    echo json_encode(['path' => $reqPath, 'folders' => array_values($result), 'parent' => $parent]);
    exit;
}

// ─── Path enforcement: must stay inside time_machine/(images|trash)/ ─────────
$fp = FM_PATH;
$selfName = basename($_SERVER['PHP_SELF']);
if ($fp === '' || $fp === $appName) {
    header("Location: $selfName?p=$appName/images"); exit;
}
if (!preg_match("/^$appName\/(images|trash)(\/.*)?$/", $fp)) {
    header("Location: $selfName?p=$appName/images"); exit;
}

// Are we browsing inside the trash? Affects which actions/buttons render.
// Trash is its own dedicated view at pages/trash.php now. manage.php no
// longer renders a special "trash mode" — $inTrash stays false here so the
// regular file-manager UI is the only one this file emits.
$inTrash = false;
trash_root(); // ensure the directory exists so browsing into it doesn't 404

// ─── Redirect bare ?p= ───────────────────────────────────────────────────────
if (!isset($_GET['p']) && empty($_FILES)) {
    fm_redirect(FM_SELF_URL . '?p=');
}

// ─── ACTIONS ─────────────────────────────────────────────────────────────────

// Single-item delete (per-row trash icon)
//   - Folders: permanent delete (no folder-trash support, by design)
//   - Files in images/: moved to trash, recoverable
//   - Files in trash/: permanently deleted
if (isset($_GET['del'], $_POST['token'])) {
    $msg = ''; $status = 'ok';
    $del = str_replace('/', '', fm_clean_path($_GET['del']));
    if ($del == '' || $del == '..' || $del == '.' || !verifyToken($_POST['token'])) {
        $msg = 'Invalid file or folder name'; $status = 'error';
    } else {
        $dpath  = FM_ROOT_PATH . (FM_PATH != '' ? '/' . FM_PATH : '') . '/' . $del;
        $is_dir = is_dir($dpath);

        if ($is_dir) {
            if (fm_rdelete($dpath)) {
                audit('fs.rmdir', $dpath);
                bump_change_status_if_affects_($dpath);
                $msg = 'Folder "' . $del . '" deleted';
            } else {
                $msg = 'Folder "' . $del . '" not deleted'; $status = 'error';
            }
        } elseif ($inTrash) {
            $real = safe_under($dpath, trash_root());
            if ($real && @unlink($real)) {
                audit('fs.purge', $real);
                $msg = 'File "' . $del . '" permanently deleted';
            } else {
                $msg = 'File "' . $del . '" not deleted'; $status = 'error';
            }
        } else {
            $real = safe_under($dpath, images_root());
            if ($real && is_file($real)) {
                $rel = substr($real, strlen(images_root()) + 1);
                $dest = trash_root() . '/' . $rel;
                $destDir = dirname($dest);
                if (!is_dir($destDir)) { $old = umask(0); @mkdir($destDir, 0775, true); umask($old); }
                $dest = unique_target_path($dest);
                if (@rename($real, $dest)) {
                    audit('fs.trash', $real . ' -> ' . $dest);
                    bump_change_status_if_affects_($real);
                    $msg = 'File "' . $del . '" moved to trash';
                } else {
                    $msg = 'File "' . $del . '" could not be trashed'; $status = 'error';
                }
            } else {
                $msg = 'Invalid file path'; $status = 'error';
            }
        }
    }
    if (!empty($_GET['ajax']) || !empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => ($status === 'ok'), 'msg' => $msg, 'status' => $status]);
        exit;
    }
    fm_set_msg($msg, $status);
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Mass delete
if (isset($_POST['group'], $_POST['delete'], $_POST['token'])) {
    if (!verifyToken($_POST['token'])) fm_set_msg('Invalid Token.', 'error');
    else {
        $path   = FM_ROOT_PATH . (FM_PATH != '' ? '/' . FM_PATH : '');
        $errors = 0;
        $files  = $_POST['file'] ?? [];
        if (is_array($files) && count($files)) {
            $anyAffected = false;
            foreach ($files as $f) {
                if ($f != '') {
                    $target = $path . '/' . $f;
                    $wasAffected = path_affects_slideshow_($target);
                    if (!fm_rdelete($target)) $errors++;
                    elseif ($wasAffected) $anyAffected = true;
                }
            }
            if ($anyAffected) @file_put_contents(state_file('change_status.txt'), '1');
            audit('fs.delete_many', count($files) . ' items in ' . $path);
            fm_set_msg($errors == 0 ? 'Selected files and folder deleted' : 'Error while deleting items', $errors ? 'error' : 'ok');
        } else {
            fm_set_msg('Nothing selected', 'alert');
        }
    }
    fm_redirect(fm_return_url(FM_SELF_URL . '?p=' . urlencode(FM_PATH)));
}

// New folder/file
// New folder
if (isset($_POST['newfilename'], $_POST['newfile'], $_POST['token'])) {
    $new = str_replace('/', '', fm_clean_path(strip_tags($_POST['newfilename'])));
    if (fm_isvalid_filename($new) && $new != '' && verifyToken($_POST['token'])) {
        $path = FM_ROOT_PATH . (FM_PATH != '' ? '/' . FM_PATH : '');
        $r = fm_mkdir($path . '/' . $new, false);
        if ($r === true) { audit('fs.mkdir', $path . '/' . $new); fm_set_msg('Folder <b>' . fm_enc($new) . '</b> Created'); }
        elseif ($r === $path . '/' . $new) fm_set_msg('Folder <b>' . fm_enc($new) . '</b> already exists', 'alert');
        else fm_set_msg('Folder <b>' . fm_enc($new) . '</b> not created', 'error');
    } else {
        fm_set_msg('Invalid characters in folder name', 'error');
    }
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Rename
if (isset($_POST['rename_from'], $_POST['rename_to'], $_POST['token'])) {
    $msg = ''; $status = 'ok'; $new = '';
    if (!verifyToken($_POST['token'])) {
        $msg = 'Invalid Token.'; $status = 'error';
    } else {
        $old       = str_replace('/', '', fm_clean_path(urldecode($_POST['rename_from'])));
        $new       = str_replace('/', '', fm_clean_path(strip_tags(urldecode($_POST['rename_to']))));
        // rename_path lets tree-view nested rows rename inside their own parent
        // folder. Fall back to FM_PATH for safety. An empty-string POST value
        // counts as "missing" too so we don't accidentally rename at DOC_ROOT.
        $rp        = $_POST['rename_path'] ?? '';
        $parentRel = $rp !== '' ? trim(fm_clean_path($rp), '/') : FM_PATH;
        $path      = FM_ROOT_PATH . ($parentRel != '' ? '/' . $parentRel : '');
        $src       = $path . '/' . $old;
        $dst       = $path . '/' . $new;
        if (!fm_isvalid_filename($new) || $new === '') {
            $msg = 'Invalid characters in file name'; $status = 'error';
        } elseif ($old === '') {
            $msg = 'Missing source name.'; $status = 'error';
        } elseif (!file_exists($src)) {
            $msg = 'Source not found: ' . $src; $status = 'error';
        } elseif (file_exists($dst) && $src !== $dst) {
            $msg = 'A file or folder named "' . $new . '" already exists here.'; $status = 'error';
        } elseif ($src === $dst) {
            $msg = 'Name unchanged.';   // not strictly an error — same name
        } elseif (fm_rename($src, $dst)) {
            audit('fs.rename', $old . ' -> ' . $new . ' in ' . $path);
            bump_change_status_if_affects_($src, $dst);
            $msg = 'Renamed from "' . $old . '" to "' . $new . '"';
        } else {
            $msg = 'Rename failed (permissions?).'; $status = 'error';
        }
    }
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => ($status === 'ok'), 'msg' => $msg, 'status' => $status, 'new' => $new]);
        exit;
    }
    fm_set_msg($msg, $status);
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Mass copy/move (POST finish)
if (isset($_POST['file'], $_POST['copy_to'], $_POST['finish'], $_POST['token'])) {
    if (!verifyToken($_POST['token'])) { fm_set_msg('Invalid Token.', 'error'); }
    else {
        $path        = FM_ROOT_PATH . (FM_PATH != '' ? '/' . FM_PATH : '');
        $copy_to     = fm_clean_path($_POST['copy_to']);
        $copy_to_path = FM_ROOT_PATH . ($copy_to != '' ? '/' . $copy_to : '');
        if ($path == $copy_to_path) {
            fm_set_msg('Paths must be not equal', 'alert');
            fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
        }
        if (!is_dir($copy_to_path)) fm_mkdir($copy_to_path, true);
        $move    = isset($_POST['move']);
        $errors  = 0;
        $skipped = 0;
        $ok      = 0;
        foreach ($_POST['file'] as $f) {
            if ($f != '') {
                $f    = fm_clean_path($f);
                $from = $path . '/' . $f;
                $dest = $copy_to_path . '/' . $f;
                // Distinguish "destination already exists" (skipped, not a failure)
                // from genuine I/O failures, so the user sees an accurate count.
                if (file_exists($dest)) { $skipped++; continue; }
                if ($move) {
                    $r = fm_rename($from, $dest);
                    if ($r === true) $ok++; else $errors++;
                } else {
                    if (fm_rcopy($from, $dest)) $ok++; else $errors++;
                }
            }
        }
        $verb = $move ? 'moved' : 'copied';
        // Bump once per bulk op (not per file) — gated by whether either the
        // source folder or destination folder is part of the slideshow.
        if ($ok > 0) bump_change_status_if_affects_($path, $copy_to_path);
        audit($move ? 'fs.move_many' : 'fs.copy_many',
              count($_POST['file']) . ' items: ' . $path . ' -> ' . $copy_to_path
              . ' (ok=' . $ok . ' skipped=' . $skipped . ' errors=' . $errors . ')');
        $parts = [];
        if ($ok)      $parts[] = $ok . ' ' . $verb;
        if ($skipped) $parts[] = $skipped . ' skipped (name already exists in destination)';
        if ($errors)  $parts[] = $errors . ' failed';
        $level = $errors ? 'error' : (($skipped && !$ok) ? 'alert' : 'ok');
        fm_set_msg($parts ? implode(', ', $parts) : 'Nothing to do', $level);
    }
    fm_redirect(fm_return_url(FM_SELF_URL . '?p=' . urlencode(FM_PATH)));
}

// Single copy/move finish (GET)
if (isset($_GET['copy'], $_GET['finish'])) {
    $copy = fm_clean_path(urldecode($_GET['copy']));
    if ($copy == '') { fm_set_msg('Source path not defined', 'error'); fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH)); }
    $from = FM_ROOT_PATH . '/' . $copy;
    $dest = FM_ROOT_PATH . (FM_PATH != '' ? '/' . FM_PATH : '') . '/' . basename($from);
    $move = isset($_GET['move']);
    if ($from != $dest) {
        if ($move) {
            $r = fm_rename($from, $dest);
            if ($r) {
                audit('fs.move', $from . ' -> ' . $dest);
                bump_change_status_if_affects_($from, $dest);
                fm_set_msg('Moved from <b>' . fm_enc($copy) . '</b> to <b>' . fm_enc(trim(FM_PATH . '/' . basename($from), '/')) . '</b>');
            }
            elseif ($r === null) fm_set_msg('File or folder with this path already exists', 'alert');
            else fm_set_msg('Error while moving', 'error');
        } else {
            if (fm_rcopy($from, $dest)) {
                audit('fs.copy', $from . ' -> ' . $dest);
                bump_change_status_if_affects_($from, $dest);
                fm_set_msg('Copied from <b>' . fm_enc($copy) . '</b> to <b>' . fm_enc(trim(FM_PATH . '/' . basename($from), '/')) . '</b>');
            }
            else fm_set_msg('Error while copying', 'error');
        }
    } else {
        fm_set_msg('Paths must be not equal', 'alert');
    }
    fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
}

// Upload (chunked Dropzone)
if (!empty($_FILES)) {
    if (!isset($_POST['token']) || !verifyToken($_POST['token'])) {
        echo json_encode(['status' => 'error', 'info' => 'Invalid Token.']); exit;
    }
    $chunkIndex   = $_POST['dzchunkindex'];
    $chunkTotal   = $_POST['dztotalchunkcount'];
    $fullPathInput = fm_clean_path($_REQUEST['fullpath']);
    $f     = $_FILES;
    $path  = FM_ROOT_PATH . (FM_PATH != '' ? '/' . FM_PATH : '');
    $ds    = DIRECTORY_SEPARATOR;
    $response  = ['status' => 'error', 'info' => 'Oops! Try again'];
    $filename  = $f['file']['name'];
    $tmp_name  = $f['file']['tmp_name'];
    $ext       = pathinfo($filename, PATHINFO_FILENAME) != '' ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '';

    if (!fm_isvalid_filename($filename)) { echo json_encode(['status' => 'error', 'info' => 'Invalid File name!']); exit; }

    if (is_writable($path . $ds)) {
        $fullPath = $path . '/' . basename($fullPathInput);
        $folder   = substr($fullPath, 0, strrpos($fullPath, '/'));
        if (!is_dir($folder)) { $old = umask(0); mkdir($folder, 0777, true); umask($old); }

        // Duplicate detection (including ~filename pattern)
        $incomingBaseName = basename($fullPathInput);
        $isDuplicate = false;
        if (is_dir($folder)) {
            foreach (scandir($folder) as $ef) {
                if ($ef === $incomingBaseName) { $isDuplicate = true; break; }
                if (strpos($ef, '~') !== false) {
                    $parts = explode('~', $ef, 2);
                    if (count($parts) === 2 && $parts[1] === $incomingBaseName) { $isDuplicate = true; break; }
                }
            }
        }
        if ($isDuplicate && (!$chunkTotal || $chunkIndex == $chunkTotal - 1)) {
            $partFile = "{$fullPath}.part";
            if (file_exists($partFile)) @unlink($partFile);
            echo json_encode(['status' => 'skipped', 'info' => "Duplicate: '{$incomingBaseName}' skipped.", 'filename' => $incomingBaseName]);
            exit;
        }

        if (empty($f['file']['error']) && !empty($tmp_name) && $tmp_name != 'none') {
            if ($chunkTotal) {
                $partFile = "{$fullPath}.part";
                $out = @fopen($partFile, $chunkIndex == 0 ? 'wb' : 'ab');
                if ($out) {
                    $in = @fopen($tmp_name, 'rb');
                    if ($in) {
                        stream_copy_to_stream($in, $out);
                        $response = ['status' => 'success', 'info' => 'chunk ok'];
                    }
                    @fclose($in); @fclose($out); @unlink($tmp_name);
                }
                if ($chunkIndex == $chunkTotal - 1) {
                    $ext1 = $ext ? '.' . $ext : '';
                    $target = file_exists($fullPath)
                        ? $path . '/' . basename($fullPathInput, $ext1) . '_' . date('ymdHis') . $ext1
                        : $fullPath;
                    if (!@rename($partFile, $target)) {
                        $response = ['status' => 'error', 'info' => 'Failed to finalize file.'];
                        if (file_exists($partFile)) @unlink($partFile);
                    } else {
                        fm_touch_uploaded($target, $_POST['lastmodified'] ?? null);
                        // New file landed — if it joined a slideshow folder,
                        // poke the viewer so it shows up within 5 s.
                        bump_change_status_if_affects_($target);
                    }
                }
            } else {
                if (move_uploaded_file($tmp_name, $fullPath)) {
                    fm_touch_uploaded($fullPath, $_POST['lastmodified'] ?? null);
                    bump_change_status_if_affects_($fullPath);
                    $response = ['status' => 'success', 'info' => 'file upload successful'];
                } else {
                    $response = ['status' => 'error', 'info' => 'Upload failed'];
                }
            }
        }
    } else {
        $response = ['status' => 'error', 'info' => 'Upload folder not writable.'];
    }
    echo json_encode($response); exit;
}


// ─── Fallback ────────────────────────────────────────────────────────────────
// Any request that reaches this point hasn't matched a handler above —
// redirect to the front-end view (manage.php), preserving the current
// ?p=… so old bookmarks land in the same folder.
fm_redirect('manage.php?p=' . urlencode(FM_PATH));

// ─── Selected Folders panel ───────────────────────────────────────────────────
function displayFileContentsWithRemoveOption($file_path) {
    global $appName;

    $lines     = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $base      = $_SERVER['DOCUMENT_ROOT'] . "/" . $appName . "/images/";
    $img_exts  = ['jpg','jpeg','png','gif','bmp','webp','avif','svg','ico'];

    // ── Starred-images banner — shown at the very top of the Active folders
    // tab so it's the first thing the user sees. Hidden when there are no
    // starred files in the slideshow.
    echo mgr_render_starred_banner();

    // ── Count stats across all selected folders (recursive, skipped-aware) ───
    $folder_count  = count($lines);
    $total_images  = 0;
    foreach ($lines as $line) {
        $total_images += mgr_count_dir_images(trim($line));
    }

    // ── Loaded-playlist banner (Tier 1 of the playlist-association feature)
    // Read current_playlist.txt; if it names a still-existing playlist, show
    // a small banner so the user knows which playlist this selection belongs
    // to. Stale names (deleted playlists) are treated as detached.
    $loadedPlaylist = '';
    $curFile = state_file('current_playlist.txt');
    if (file_exists($curFile)) {
        $candidate = trim((string)@file_get_contents($curFile));
        if ($candidate !== '') {
            $pl = (file_exists(state_file('playlists.json')) && ($pj = @file_get_contents(state_file('playlists.json'))))
                ? json_decode($pj, true) : [];
            if (is_array($pl) && isset($pl[$candidate])) {
                $loadedPlaylist = $candidate;
            }
        }
    }
    if ($loadedPlaylist !== '') {
        echo '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;margin-bottom:8px;'
           . 'background:#fff8e1;border:1px solid #ffe082;border-radius:6px;font-size:12px;color:#7a5b00">'
           . '<i class="fa fa-bookmark" style="color:#b8860b"></i>'
           . '<span>Playlist: <b>' . htmlspecialchars($loadedPlaylist) . '</b></span>'
           . '</div>';
    }

    // ── Header row ───────────────────────────────────────────────────────────
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
        echo '<div style="display:flex;gap:6px;align-items:center">';
        echo '<button type="button" class="fm-save-playlist-btn" '
           . 'style="font-size:12px;color:#2e7d32;border:1px solid #a5d6a7;padding:2px 8px;border-radius:3px;'
           . 'text-decoration:none;background:#f1f8f1;cursor:pointer" '
           . 'title="Save this folder combination as a named playlist">'
           . '<i class="fa fa-bookmark-o"></i> Save as Playlist</button>';
        echo '<a href="?clear_file=1" onclick="return confirm(\'Remove all selected folders from the slideshow?\');"'
           . ' style="font-size:12px;color:#aaa;border:1px solid #ddd;padding:2px 8px;border-radius:3px;'
           . 'text-decoration:none" title="Clear all selected folders">Clear all</a>';
        echo '</div>';
    }
    echo '</div>';

    // ── Inline "Save as Playlist" form (hidden until the button is clicked).
    // Kept inside the slideshow modal so users don't have to deal with a
    // stacked second modal. Driven by JS in manage_util.php.
    echo '<form id="fmSavePlaylistForm" class="fm-save-playlist-form" autocomplete="off" '
       . 'style="display:none;align-items:center;gap:6px;margin:0 0 10px;padding:8px 10px;'
       . 'background:#f1f8f1;border:1px solid #a5d6a7;border-radius:6px">'
       . '<i class="fa fa-bookmark-o" style="color:#2e7d32"></i>'
       . '<input type="text" name="playlist_name" required maxlength="80" '
       .   'placeholder="Name this playlist…" '
       .   'style="flex:1;min-width:0;padding:5px 8px;border:1px solid #c8d6c8;border-radius:4px;font-size:13px">'
       . '<button type="submit" '
       .   'style="font-size:12px;padding:4px 12px;border:1px solid #2e7d32;border-radius:4px;'
       .   'background:#2e7d32;color:#fff;cursor:pointer">Save</button>'
       . '<button type="button" class="fm-save-playlist-cancel" '
       .   'style="font-size:12px;padding:4px 10px;border:1px solid #ccc;border-radius:4px;'
       .   'background:#fff;color:#666;cursor:pointer">Cancel</button>'
       . '<span class="fm-save-playlist-status" style="font-size:11px;color:#c0392b;min-width:0"></span>'
       . '</form>';

    // ── Sub-label ────────────────────────────────────────────────────────────
    echo '<div style="font-size:12px;color:#888;margin-bottom:10px">'
       . 'Files inside these folders appear in the slideshow</div>';

    // ── Empty state ───────────────────────────────────────────────────────────
    if ($folder_count === 0) {
        echo '<p style="color:#bbb;font-style:italic;margin:6px 0 2px">'
           . '<i class="fa fa-folder-open-o"></i>&nbsp;'
           . 'No folders selected &mdash; use the <b>Show</b> buttons in the table above to add folders to the slideshow.'
           . '</p>';
        return;
    }

    // ── Pills ─────────────────────────────────────────────────────────────────
    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
    foreach ($lines as $line) {
        $line      = trim($line);
        $name      = basename($line);
        $relative  = str_replace($base, '', $line);

        // Per-folder image count
        $img_count = 0;
        if (is_dir($line)) {
            foreach (scandir($line) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $img_exts) && is_file($line . '/' . $f))
                    $img_count++;
            }
        }

        // Preserve current page params in remove URL — but drop any params that
        // would trigger an unrelated handler (slideshow_body returns HTML; the
        // toggle endpoint must return JSON to the pill-remove fetch).
        $qp = $_GET;
        unset($qp['slideshow_body'], $qp['ajax']);
        $qp['folder_path']        = $line;
        $qp['folder_path_action'] = 'remove';
        $remove_url = $_SERVER['PHP_SELF'] . '?' . http_build_query($qp);

        // Pill is tagged with data-path so the modal can hide it after AJAX
        // remove. Image count carries data-imgcount for live total update.
        echo '<span class="fm-active-pill" data-path="' . htmlspecialchars($line) . '" '
           . 'data-imgcount="' . $img_count . '" style="'
           . 'display:inline-flex;align-items:center;gap:6px;'
           . 'background:#e8f5e9;border:1px solid #a5d6a7;'
           . 'padding:5px 10px 5px 9px;border-radius:12px;font-size:13px;color:#2e7d32">';
        echo   '<i class="fa fa-folder" style="color:#43a047;font-size:12px;flex-shrink:0"></i>';
        // Name + path stacked
        echo   '<span style="display:flex;flex-direction:column;line-height:1.35">';
        echo     '<span style="font-weight:600">' . htmlspecialchars($name) . '</span>';
        echo     '<span style="font-size:10px;color:#71a878;max-width:200px;'
               . 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap" '
               . 'title="' . htmlspecialchars($relative) . '">' . htmlspecialchars($relative) . '</span>';
        echo   '</span>';
        if ($img_count > 0)
            echo '<span style="color:#888;font-size:11px;flex-shrink:0">(' . $img_count . ')</span>';
        echo   '<a class="fm-remove-pill" href="' . htmlspecialchars($remove_url) . '" '
           .   'title="Remove from slideshow" '
           .   'style="color:#e53935;font-weight:bold;font-size:15px;line-height:1;'
           .   'margin-left:2px;text-decoration:none;flex-shrink:0">&times;</a>';
        echo '</span>';
    }
    echo '</div>';
}

// ─── Playlists panel ─────────────────────────────────────────────────────────
function displayPlaylists() {
    global $appName;

    $playlists = (file_exists(state_file('playlists.json')) && ($j = file_get_contents(state_file('playlists.json'))))
        ? json_decode($j, true) : [];
    if (!is_array($playlists)) $playlists = [];

    // Which playlist (if any) is currently loaded — for the "Loaded" pill.
    $loadedPlaylist = '';
    $curFile = state_file('current_playlist.txt');
    if (file_exists($curFile)) {
        $candidate = trim((string)@file_get_contents($curFile));
        if ($candidate !== '' && isset($playlists[$candidate])) $loadedPlaylist = $candidate;
    }

    $img_exts = ['jpg','jpeg','png','gif','bmp','webp','avif','svg','ico'];
    $base     = $_SERVER['DOCUMENT_ROOT'] . "/" . $appName . "/images/";

    // ── Header — label + count badge on the left, filter input on the right.
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">';
    echo   '<div style="display:flex;align-items:center;gap:8px">';
    echo     '<b style="font-size:14px"><i class="fa fa-bookmark-o" style="color:#888"></i> Saved Playlists</b>';
    if (count($playlists) > 0)
        echo '<span style="background:#6c757d;color:#fff;padding:2px 9px;border-radius:10px;font-size:12px">'
           . count($playlists) . '</span>';
    echo   '</div>';
    if (count($playlists) > 1) {
        echo '<input type="search" class="fm-playlist-search" placeholder="Filter…" '
           . 'style="width:160px;padding:4px 8px;border:1px solid #ccc;border-radius:6px;font-size:12px;box-sizing:border-box">';
    }
    echo '</div>';
    echo '<div style="font-size:12px;color:#888;margin-bottom:10px">'
       . 'Load a saved playlist to instantly switch the slideshow to that folder combination</div>';

    // ── Empty state ───────────────────────────────────────────────────────────
    if (empty($playlists)) {
        echo '<p style="color:#bbb;font-style:italic;margin:6px 0 2px">'
           . '<i class="fa fa-bookmark-o"></i>&nbsp;'
           . 'No playlists saved yet &mdash; select folders above and click <b>Save as Playlist</b>.'
           . '</p>';
        return;
    }

    // ── Playlist rows ─────────────────────────────────────────────────────────
    echo '<div class="fm-playlist-rows" style="display:flex;flex-direction:column;gap:6px">';
    foreach ($playlists as $pl_name => $paths) {
        // Count folders and images
        $folder_count = count($paths);
        $img_count    = 0;
        foreach ($paths as $dir) {
            $dir = trim($dir);
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..') continue;
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $img_exts) && is_file($dir . '/' . $f))
                    $img_count++;
            }
        }

        // Build folder name list for subtitle
        $names = array_map(fn($p) => basename(trim($p)), $paths);
        $subtitle = implode(', ', array_slice($names, 0, 4));
        if (count($names) > 4) $subtitle .= ', …';

        echo '<div class="fm-playlist-row" data-pl-name="' . htmlspecialchars(strtolower($pl_name)) . '" '
           . 'style="display:flex;align-items:center;justify-content:space-between;'
           . 'padding:8px 12px;background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;gap:10px">';

        $isLoaded = ($loadedPlaylist === $pl_name);

        // Left: name + subtitle (plus a "Loaded" pill when active).
        echo   '<div style="flex:1;min-width:0">';
        echo     '<div style="font-weight:600;font-size:13px;color:#333;display:flex;align-items:center;gap:6px">'
               . htmlspecialchars($pl_name);
        if ($isLoaded) {
            echo '<span style="font-size:10px;background:#fff3cd;color:#7a5b00;border:1px solid #ffe082;'
               . 'padding:1px 6px;border-radius:9px;font-weight:600;letter-spacing:.3px">LOADED</span>';
        }
        echo     '</div>';
        echo     '<div style="font-size:11px;color:#888;margin-top:1px;white-space:nowrap;'
               . 'overflow:hidden;text-overflow:ellipsis" title="' . htmlspecialchars(implode(', ', $names)) . '">'
               . htmlspecialchars($subtitle) . '</div>';
        echo   '</div>';

        // Centre: stats badge
        echo   '<span style="font-size:11px;color:#6c757d;white-space:nowrap;flex-shrink:0">'
               . $folder_count . ' folder' . ($folder_count != 1 ? 's' : '')
               . ' &middot; ' . $img_count . ' img' . ($img_count != 1 ? 's' : '')
               . '</span>';

        // Right: Load + Delete
        echo   '<div style="display:flex;gap:6px;flex-shrink:0">';

        // Load button — AJAX-handled by JS in manage_util.php
        echo     '<button type="button" class="fm-playlist-load-btn" '
               . 'data-playlist-name="' . htmlspecialchars($pl_name) . '" '
               . 'style="font-size:12px;padding:3px 10px;border:1px solid #a5d6a7;border-radius:4px;'
               . 'background:#e8f5e9;color:#2e7d32;cursor:pointer" title="Load this playlist">'
               . '<i class="fa fa-play"></i> Load</button>';

        // Delete button — AJAX-handled by JS in manage_util.php
        echo     '<button type="button" class="fm-playlist-delete-btn" '
               . 'data-playlist-name="' . htmlspecialchars($pl_name) . '" '
               . 'style="font-size:12px;padding:3px 8px;border:1px solid #f5c6cb;border-radius:4px;'
               . 'background:#fff5f5;color:#c0392b;cursor:pointer" title="Delete this playlist">'
               . '&times;</button>';

        echo   '</div>';
        echo '</div>';
    }
    echo '</div>';
}

// ─── Utility functions ────────────────────────────────────────────────────────
function verifyToken($token) {
    return hash_equals($_SESSION['token'], $token);
}
function fm_set_msg($msg, $status = 'ok') {
    $_SESSION[FM_SESSION_ID]['message'] = $msg;
    $_SESSION[FM_SESSION_ID]['status']  = $status;
}
function fm_redirect($url, $code = 302) {
    header('Location: ' . $url, true, $code); exit;
}
/**
 * Resolve a safe redirect target from user-supplied POST['return'].
 * Accepts only same-host paths (no schemes, no host hopping). Falls back
 * to $defaultUrl when the input is missing or rejected.
 */
function fm_return_url($defaultUrl) {
    $r = $_POST['return'] ?? '';
    if (!is_string($r) || $r === '') return $defaultUrl;
    if ($r[0] !== '/' || strpos($r, '//') === 0) return $defaultUrl; // must be path-only
    if (preg_match('#[\r\n]#', $r)) return $defaultUrl; // header injection guard
    return $r;
}
function fm_enc($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function fm_isvalid_filename($text) {
    return strpbrk($text, '/?%*:|"<>') === false;
}
function fm_is_valid_ext($filename) {
    $allowed = FM_FILE_EXTENSION ? explode(',', FM_FILE_EXTENSION) : false;
    $ext     = pathinfo($filename, PATHINFO_EXTENSION);
    return $allowed ? in_array($ext, $allowed) : true;
}
function fm_convert_win($filename) {
    if (FM_IS_WIN && function_exists('iconv'))
        return iconv(FM_ICONV_INPUT_ENC, 'UTF-8//IGNORE', $filename);
    return $filename;
}
function get_absolute_path($path) {
    $path  = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
    $abs   = [];
    foreach ($parts as $part) {
        if ($part == '.') continue;
        if ($part == '..') array_pop($abs);
        else $abs[] = $part;
    }
    return implode(DIRECTORY_SEPARATOR, $abs);
}
function fm_clean_path($path, $trim = true) {
    $path = $trim ? trim($path) : $path;
    $path = trim($path, '\\/');
    $path = str_replace(['../', '..\\'], '', $path);
    $path = get_absolute_path($path);
    if ($path == '..') $path = '';
    return str_replace('\\', '/', $path);
}
function fm_get_parent_path($path) {
    $path = fm_clean_path($path);
    if ($path != '') {
        $arr = explode('/', $path);
        if (count($arr) > 1) return implode('/', array_slice($arr, 0, -1));
        return '';
    }
    return false;
}
function fm_get_size($file) {
    return filesize($file);
}
function fm_get_filesize($size) {
    $size  = (float) $size;
    $units = ['B','KB','MB','GB','TB'];
    $power = $size > 0 ? min(floor(log($size, 1024)), count($units) - 1) : 0;
    return sprintf('%s %s', round($size / pow(1024, $power), 2), $units[$power]);
}
function fm_get_file_icon_class($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $icons = [
        'fa fa-picture-o' => ['ico','gif','jpg','jpeg','png','bmp','tif','tiff','webp','avif','svg'],
        'fa fa-file-archive-o' => ['zip','tar','gz','bz2','7z','rar'],
        'fa fa-file-code-o' => ['js','ts','json','php','py','sh','css','html','xml','sql'],
        'fa fa-file-text-o' => ['txt','md','log','ini','conf','yaml','yml'],
    ];
    foreach ($icons as $cls => $exts) {
        if (in_array($ext, $exts)) return $cls;
    }
    return 'fa fa-file-o';
}
/**
 * Restore mtime of an uploaded file to the client's original lastModified.
 * $msString is the milliseconds-since-epoch value sent by Dropzone's File API,
 * or null if the browser didn't provide one — in which case we leave the file
 * with its current (= upload completion) timestamp.
 */
function fm_touch_uploaded($filePath, $msString) {
    if (!$msString || !is_numeric($msString)) return;
    $seconds = (int) ($msString / 1000);
    if ($seconds <= 0) return;
    @touch($filePath, $seconds, $seconds);
}

function fm_rdelete($path) {
    if (is_link($path)) return unlink($path);
    if (is_dir($path)) {
        $ok = true;
        foreach (scandir($path) as $f) {
            if ($f != '.' && $f != '..' && !fm_rdelete($path . '/' . $f)) $ok = false;
        }
        return $ok ? rmdir($path) : false;
    }
    if (is_file($path)) return unlink($path);
    return false;
}
function fm_rename($old, $new) {
    if (!is_dir($old) && !fm_is_valid_ext($new)) return false;
    return (!file_exists($new) && file_exists($old)) ? rename($old, $new) : null;
}
function fm_copy($f1, $f2, $upd = true) {
    if (file_exists($f2) && $upd && filemtime($f2) >= filemtime($f1)) return false;
    $ok = copy($f1, $f2);
    if ($ok) touch($f2, filemtime($f1));
    return $ok;
}
function fm_rcopy($path, $dest, $upd = true, $force = true) {
    if (is_dir($path)) {
        if (!fm_mkdir($dest, $force)) return false;
        $ok = true;
        foreach (scandir($path) as $f) {
            if ($f != '.' && $f != '..' && !fm_rcopy($path . '/' . $f, $dest . '/' . $f)) $ok = false;
        }
        return $ok;
    }
    if (is_file($path)) return fm_copy($path, $dest, $upd);
    return false;
}
function fm_mkdir($dir, $force) {
    if (file_exists($dir)) {
        if (is_dir($dir)) return $dir;
        if (!$force) return false;
        unlink($dir);
    }
    // Wrap with umask(0) so the requested 0777 isn't masked down to 755/775
    // by the process umask — otherwise other users (SMB share, file-copy
    // accounts) can't write into folders www-data created.
    $old = umask(0);
    $ok  = mkdir($dir, 0777, true);
    umask($old);
    return $ok;
}
function countDirImages($dir) {
    if (!is_dir($dir)) return 0;
    static $img_exts = ['gif','jpg','jpeg','png','bmp','ico','svg','webp','avif'];
    $count = 0;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_file($p) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $img_exts)) {
            $count++;
        } elseif (is_dir($p)) {
            $count += countDirImages($p);
        }
    }
    return $count;
}

// countDirImagesSplit() and getDirectorySize() moved to manage_util.php so
// the AJAX tree-row endpoint (get_folder_rows.php) can reuse them.
function hasVisibleFilesOnly($path) {
    if (!is_dir($path)) return 0;
    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        if (is_file(rtrim($path, '/') . '/' . $item)) return 1;
    }
    return 0;
}
function lng_del_folder() { return 'Delete Folder'; }
?>
