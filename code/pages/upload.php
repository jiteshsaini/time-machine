<?php
/*
 * pages/upload.php — Dropzone-based file upload page.
 *
 * Lifted out of the original manage.php so the file's UI role could be
 * retired. The chunk-receive POST handler lives in manage_ops.php
 * (the `if (!empty($_FILES))` block); the form below posts there.
 *
 * URL: pages/upload.php?p=<appName>/images/...
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

// Path validation — must stay inside the appName/images tree.
$p = isset($_GET['p']) ? trim((string)$_GET['p'], '/') : '';
$p = str_replace(['../', '..\\'], '', $p);
if ($p === '' || !preg_match("#^$appName/images(/.*)?$#", $p)) {
    $p = "$appName/images";
}
$absPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $p;
if (!is_dir($absPath)) {
    header('Location: ../manage.php?p=' . urlencode("$appName/images")); exit;
}

$backHref = '../manage.php?p=' . urlencode($p);
$displayPath = preg_replace("#^$appName/#", '', $p);

if (!defined('UPLOAD_CHUNK_SIZE')) define('UPLOAD_CHUNK_SIZE', 2000000);
if (!defined('MAX_UPLOAD_SIZE'))    define('MAX_UPLOAD_SIZE', 5000);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Upload — Time Machine</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { background:#fff; color:#222; margin:0; font-family:system-ui,-apple-system,Segoe UI,sans-serif; font-size:14px; }
    <?php page_chrome_styles(); ?>
    .upload-wrap { padding:20px; max-width:900px; margin:0 auto; }
    .upload-dest { background:#f7f9fc; border:1px solid #e3e6ea; border-radius:4px;
                   padding:10px 14px; margin-bottom:14px; font-size:13px; color:#555; }
    .upload-dest b { color:#0157b3; word-break:break-all; }
    form.dropzone {
        min-height:240px; border:2px dashed #007bff; border-radius:6px;
        background:#fafbff; padding:30px;
    }
    .dz-message { color:#555; font-size:15px; }
    .upload-hint { font-size:12px; color:#888; margin-top:10px; }
  </style>
</head>
<body>
<?php
page_header([
    'title'    => 'Upload',
    'context'  => $displayPath,
    'backHref' => $backHref,
]);
?>
<div class="upload-wrap">
  <div class="upload-dest">
    <i class="fa fa-folder-open-o" style="color:#e8a838"></i>
    Files will land in <b><?php echo htmlspecialchars($displayPath) ?></b>
  </div>
  <form action="../manage_ops.php?p=<?php echo urlencode($p) ?>"
        class="dropzone" id="fileUploader" enctype="multipart/form-data">
    <input type="hidden" name="p" value="<?php echo htmlspecialchars($p) ?>">
    <input type="hidden" name="fullpath" id="fullpath" value="<?php echo htmlspecialchars($p) ?>">
    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['token']) ?>">
    <div class="fallback"><input name="file" type="file" multiple></div>
  </form>
  <p class="upload-hint">
    Drop files (or whole folders) above. Each upload is chunked
    (<?php echo number_format(UPLOAD_CHUNK_SIZE) ?> bytes per chunk) so
    large files survive flaky connections.
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>
<script>
// Local toast — pages/upload doesn't include mgr_render_shared_js, so we
// inline a minimal alert() fallback for the few cases Dropzone emits.
function toast(msg) { try { console.log(msg); } catch(e){} alert(msg); }

Dropzone.options.fileUploader = {
    chunking: true, chunkSize: <?php echo UPLOAD_CHUNK_SIZE ?>, forceChunking: true,
    retryChunks: true, retryChunksLimit: 3, parallelUploads: 1, parallelChunkUploads: false,
    timeout: 120000, maxFilesize: "<?php echo MAX_UPLOAD_SIZE ?>",
    init: function () {
        this.on("sending", function (file, xhr, formData) {
            document.getElementById("fullpath").value = file.fullPath ? file.fullPath : file.name;
            if (file.lastModified) formData.append("lastmodified", file.lastModified);
        }).on("success", function (file, res) {
            try {
                var r = (typeof res === 'string') ? JSON.parse(res) : res;
                if (r.status === "error" || r.status === "skipped") toast(r.info);
            } catch(e) {}
        }).on("error", function(file, response) { toast(typeof response === 'string' ? response : 'Upload failed'); });
    }
};
</script>
<?php echo app_credit_html(); ?>
</body>
</html>
