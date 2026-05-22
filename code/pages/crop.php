<?php
/*
 * pages/crop.php — full-screen image cropping UI (Cropper.js).
 *
 * Takes ?path=<rel> pointing to an image inside images/, lets the user
 * drag a crop box, posts the resulting rectangle to util/img_crop.php
 * which crops in place via PIL.
 *
 * Opened from per-image crop buttons in manage.php's grid/list rows.
 * Returns to manage.php (or wherever ?return=… points).
 */

ini_set('display_errors', '1');
include_once __DIR__ . "/../var.php";
include_once __DIR__ . "/pages_util.php";

define('FM_SESSION_ID', 'filemanager');
session_name(FM_SESSION_ID);
session_start();
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(32))
        : bin2hex(openssl_random_pseudo_bytes(32));
}

global $appName;
$root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');

$path = $_GET['path'] ?? '';
// Accept both leading-slash absolute URL paths and bare relative paths.
$path = ltrim($path, '/');
$abs  = $root . '/' . $path;
$real = safe_under($abs, images_root());
if ($real === false || !is_file($real)) {
    http_response_code(400);
    echo "Invalid path."; exit;
}

$relUrl = '/' . ltrim(str_replace($root, '', $real), '/');
$name   = basename($real);
$dims   = @getimagesize($real);
$dimStr = $dims ? ($dims[0] . ' × ' . $dims[1]) : '';
$return = $_GET['return'] ?? '../manage.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Crop — <?php echo htmlspecialchars($name) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.css">
  <style>
    body { background:#1a1a1a; color:#eee; margin:0; font-family:-apple-system,Segoe UI,sans-serif; }
    <?php page_chrome_styles(); ?>

    /* Page-specific: ratio buttons live inside the shared header. */
    .ratio-group { display:flex; gap:4px; }
    .ratio-group button {
        background:#f1f5fb; border:1px solid #d8e3f1; color:#333;
        padding:4px 10px; font-size:13px; border-radius:4px; cursor:pointer;
    }
    .ratio-group button.active { background:#1a73e8; border-color:#1a73e8; color:#fff; }
    .crop-save { background:#28a745; color:#fff; border:1px solid #28a745; padding:5px 12px; border-radius:4px; cursor:pointer; }
    .crop-save:disabled { opacity:.5; cursor:not-allowed; }
    .crop-reset { background:#fff; border:1px solid #ccc; color:#333; padding:5px 12px; border-radius:4px; cursor:pointer; }

    .canvas-wrap {
        max-width:100vw; max-height: calc(100vh - 56px);
        display:flex; justify-content:center; align-items:center;
        padding:14px;
    }
    #cropImg { max-width:100%; display:block; }

    .status {
        position:fixed; bottom:14px; right:14px;
        background:#222; color:#ddd; padding:8px 14px; border-radius:6px;
        font-size:13px; box-shadow:0 4px 12px rgba(0,0,0,.4);
        display:none;
    }
    .status.show { display:block; }
    .status.err  { background:#b02a37; }
  </style>
</head>
<body>

<?php
$controls = '<div class="ratio-group" id="ratioGroup">'
          .   '<button type="button" data-ratio="1.7777777" class="active">16:9</button>'
          .   '<button type="button" data-ratio="1.3333333">4:3</button>'
          .   '<button type="button" data-ratio="1">1:1</button>'
          .   '<button type="button" data-ratio="0.5625">9:16</button>'
          .   '<button type="button" data-ratio="NaN">Free</button>'
          . '</div>';

$primary = '<button type="button" id="resetBtn" class="crop-reset" title="Reset crop box">'
         .   '<i class="fa fa-undo"></i> Reset'
         . '</button> '
         . '<button type="button" id="saveBtn" class="crop-save">'
         .   '<i class="fa fa-check"></i> Crop &amp; Save'
         . '</button>';

page_header([
    'title'        => $name,
    'context'      => $dimStr,
    'backHref'     => $return,
    'backLabel'    => 'Cancel',
    'controlsHtml' => $controls,
    'primaryHtml'  => $primary,
]);
?>

<div class="canvas-wrap">
  <img id="cropImg" src="<?php echo htmlspecialchars($relUrl) ?>?_=<?php echo time() ?>" alt="">
</div>

<div class="status" id="status"></div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.js"></script>
<script>
const csrf      = <?php echo json_encode($_SESSION['token']) ?>;
const realPath  = <?php echo json_encode($real) ?>;
const returnUrl = <?php echo json_encode($return) ?>;

const img    = document.getElementById('cropImg');
const status = document.getElementById('status');
let cropper  = null;
let saving   = false;

function showStatus(msg, isError) {
    status.textContent = msg;
    status.className = 'status show' + (isError ? ' err' : '');
}
function hideStatus() { status.className = 'status'; }

img.addEventListener('load', function () {
    cropper = new Cropper(img, {
        aspectRatio: 16 / 9,
        viewMode: 1,
        autoCropArea: 0.9,
        movable: true,
        zoomable: true,
        rotatable: false,
        scalable: false,
        background: false,
    });
});

document.getElementById('ratioGroup').addEventListener('click', function (e) {
    const btn = e.target.closest('button[data-ratio]');
    if (!btn || !cropper) return;
    document.querySelectorAll('#ratioGroup button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const r = parseFloat(btn.dataset.ratio);
    cropper.setAspectRatio(isNaN(r) ? NaN : r);
});

document.getElementById('resetBtn').addEventListener('click', function () {
    if (cropper) cropper.reset();
});

document.getElementById('saveBtn').addEventListener('click', function () {
    if (!cropper || saving) return;
    if (!requireActionPassword('cropping this image')) return;
    const data = cropper.getData(true); // {x, y, width, height} rounded to integers
    if (!data.width || !data.height) { showStatus('Pick a crop area first.', true); return; }

    saving = true;
    this.disabled = true;
    showStatus('Cropping…');

    const fd = new FormData();
    fd.append('token',  csrf);
    fd.append('path',   realPath);
    fd.append('x',      data.x);
    fd.append('y',      data.y);
    fd.append('width',  data.width);
    fd.append('height', data.height);

    fetch('../util/img_crop.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(t => {
            if (t === 'ok') {
                showStatus('Saved. Redirecting…');
                setTimeout(() => { location.href = returnUrl; }, 700);
            } else {
                showStatus(t || 'Crop failed.', true);
                saving = false;
                document.getElementById('saveBtn').disabled = false;
            }
        })
        .catch(() => {
            showStatus('Network error.', true);
            saving = false;
            document.getElementById('saveBtn').disabled = false;
        });
});
</script>

<?php emit_action_password_js(); ?>
<?php echo app_credit_html(); ?>
</body>
</html>
