<?php

/*
 * pages/set_order.php — drag-to-reorder UI for a single folder.
 *
 * Lets the user drag thumbnails into a desired sequence, then writes the
 * resulting order so the slideshow plays the folder in that sequence
 * (instead of the default date / name sort).
 *
 * Opened from the "Re-Order this folder" link in the Actions modal
 * (mgr_render_actions_modal). Returns to manage.php.
 */

include_once __DIR__ . "/../var.php";
include_once __DIR__ . "/pages_util.php";
global $appName;

$basePath = $_SERVER['DOCUMENT_ROOT']; // Get the root directory (e.g., "/var/www/html")
$appPath = $basePath.'/'.$appName;

$dir = isset($_GET['dir']) ? $_GET['dir'] : 'images';

// Keep the absolute form for the post-rename change_status bump, since
// relative paths can't be matched against paths.txt entries.
$dirAbs = $dir;

// Convert absolute path to relative
$dir = str_replace($appPath, "../..", $dir);

// Get all image files in the directory
$allFiles = scandir($dir);
$files = [];

foreach ($allFiles as $file) {
    if ($file !== '.' && $file !== '..') {
        $filePath = $dir . '/' . $file;
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif','mp4'])) {
            $files[] = $filePath;
        }
    }
}

// Determine return URL
$returnUrl = isset($_GET['return']) ? urldecode($_GET['return']) :
             (isset($_POST['return']) ? urldecode($_POST['return']) : 'manage.php');

// Handle image renaming
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    $order = json_decode($_POST['order'], true);
    $startOrder = isset($_POST['startOrder']) && is_numeric($_POST['startOrder']) ? max(100, intval($_POST['startOrder'])) : 100;

    if ($order) {
        foreach ($order as $index => $filePath) {
            $originalName = basename($filePath);
            $newIndex = $startOrder + $index;

            // Remove existing numbering if `~` is present
            if (strpos($originalName, '~') !== false) {
                $originalName = preg_replace('/^\d+~/', '', $originalName);
            }

            $newName = $dir . '/' . $newIndex . '~' . $originalName;
            rename($filePath, $newName);
        }
        // Reorder renames every file in the folder — if this folder feeds
        // the slideshow, all the cached URLs the viewer is holding are now
        // stale. Bump change_status so it reloads within 5 s.
        bump_change_status_if_affects_($dirAbs);
    }
    header("Location: " . htmlspecialchars($returnUrl));
    exit;
}
?>

<?php
function getDisplayName($filepath) {
    // Only strip the numeric order prefix ("100~"). Avoids confusing "~star"
    // (or any other mid-/end-of-name tilde) for an order marker.
    $basename = basename($filepath);
    if (preg_match('/^(\d+)~/', $basename, $m)) return $m[1];
    return '';
}

$firstName = isset($files[0]) ? getDisplayName($files[0]) : '';
$lastName = isset($files[count($files) - 1]) ? getDisplayName($files[count($files) - 1]) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Organizer</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <style>
        :root { --thumb-size: 120px; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #fafafa; }
        <?php page_chrome_styles(); ?>

        /* Page-specific controls inside the shared header. */
        .so-controls label { display: flex; align-items: center; gap: 6px; font-size: 0.9em; color: #444; }
        .so-controls input[type=number] { width: 70px; padding: 4px 6px; }
        .so-controls input[type=range] { width: 140px; vertical-align: middle; }
        .so-save {
            padding: 6px 14px; background: #0d6efd; color: #fff;
            border: none; border-radius: 4px; cursor: pointer; font-weight: 500;
        }
        .so-save:hover { background: #0b5ed7; }

        .meta { padding: 6px 12px; font-size: 0.82em; color: #666; }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(var(--thumb-size), 1fr));
            gap: 6px; padding: 8px 12px 60px;
        }
        .tile {
            position: relative; aspect-ratio: 1; overflow: hidden;
            border-radius: 4px; background: #e9e9e9; cursor: grab;
            user-select: none; -webkit-user-select: none;
        }
        .tile img {
            width: 100%; height: 100%; object-fit: cover; display: block;
            pointer-events: none; -webkit-user-drag: none;
        }
        .tile .idx {
            position: absolute; left: 4px; top: 4px;
            background: rgba(0,0,0,0.55); color: #fff;
            font-size: 0.7em; padding: 1px 5px; border-radius: 3px;
            pointer-events: none;
        }
        .tile.sortable-ghost { opacity: 0.25; }
        .tile.sortable-chosen { transform: scale(1.04); box-shadow: 0 4px 12px rgba(0,0,0,0.25); }

        @media (max-width: 600px) {
            .so-controls input[type=range] { width: 100px; }
            .so-controls input[type=number] { width: 60px; }
        }
    </style>
</head>
<body>

<form id="orderForm" method="post">
    <input type="hidden" name="order" id="orderInput">
    <?php
    $controls = '<span class="so-controls" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px">'
              .   '<label>Start:<input type="number" id="startOrder" name="startOrder" min="100" max="10000" placeholder="100"></label>'
              .   '<label>Size:<input type="range" id="sizeSlider" min="60" max="260" step="10"></label>'
              . '</span>';
    $primary  = '<button type="button" id="setOrder" class="so-save">Set Order</button>';
    page_header([
        'title'        => 'Set Order',
        'context'      => count($files) . ' image' . (count($files) === 1 ? '' : 's'),
        'backHref'     => $returnUrl,
        'controlsHtml' => $controls,
        'primaryHtml'  => $primary,
    ]);
    ?>
</form>

<div class="meta">
    <?= count($files) ?> item<?= count($files) === 1 ? '' : 's' ?>
    <?php if ($firstName || $lastName): ?>
        &middot; <?= htmlspecialchars($firstName) ?> &rarr; <?= htmlspecialchars($lastName) ?>
    <?php endif; ?>
</div>

<div id="imageContainer" class="grid">
    <?php foreach ($files as $i => $file): ?>
        <div class="tile" data-filename="<?= htmlspecialchars($file) ?>">
            <img src="<?= htmlspecialchars($file) ?>" alt="" loading="lazy">
            <span class="idx"><?= $i + 1 ?></span>
        </div>
    <?php endforeach; ?>
</div>

<script>
const grid = document.getElementById("imageContainer");
const slider = document.getElementById("sizeSlider");

// Restore thumb size preference
const savedSize = parseInt(localStorage.getItem("setOrderThumbSize") || "120", 10);
slider.value = savedSize;
document.documentElement.style.setProperty("--thumb-size", savedSize + "px");
slider.addEventListener("input", () => {
    document.documentElement.style.setProperty("--thumb-size", slider.value + "px");
    localStorage.setItem("setOrderThumbSize", slider.value);
});

function renumber() {
    grid.querySelectorAll(".tile .idx").forEach((el, i) => { el.textContent = i + 1; });
}

Sortable.create(grid, {
    animation: 150,
    delay: 180,
    delayOnTouchOnly: true,
    touchStartThreshold: 5,
    ghostClass: "sortable-ghost",
    chosenClass: "sortable-chosen",
    onEnd: renumber,
});

document.getElementById("setOrder").addEventListener("click", () => {
    const order = Array.from(grid.querySelectorAll(".tile")).map(el => el.dataset.filename);
    document.getElementById("orderInput").value = JSON.stringify(order);
    document.getElementById("orderForm").submit();
});
</script>
<?php echo app_credit_html(); ?>
</body>
</html>
