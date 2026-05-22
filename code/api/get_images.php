<?php
/*
 * api/get_images.php — slideshow's image playlist endpoint.
 *
 * The slideshow viewer (index.html) polls this for the JSON list of
 * images to play. The union of:
 *   - every image recursively inside any folder in paths.txt
 *   - every file individually listed in starred_paths.txt
 * Deduped by canonical path. Hidden (.*) and skipped (_*) names are
 * excluded at every level.
 *
 * Response: JSON array of {path, date, v} objects.
 * No params. No auth (read-only).
 */

include_once __DIR__ . "/../var.php";


// Read the folder list (paths.txt) and the explicit starred-file list
// (starred_paths.txt). The slideshow serves the UNION of these two sources,
// deduped by canonical path so a starred file inside an active folder
// doesn't play twice.
$paths        = file(state_file('paths.txt'),         FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$starredPaths = file(state_file('starred_paths.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

$images = [];

// Loop through each folder path and collect {path, date, v} entries.
foreach ($paths as $path) {
    $images = array_merge($images, getImages($path));
}

// Append individually-starred file entries.
foreach ($starredPaths as $abs) {
    $abs = trim($abs);
    if ($abs === '' || !is_file($abs)) continue;
    if (is_skipped_path($abs))         continue;
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) continue;
    $rel = $appName . '/' . str_replace($_SERVER['DOCUMENT_ROOT'] . "/$appName/", '', $abs);
    $images[] = [
        'path' => $rel,
        'date' => image_date($abs),
        'v'    => @filectime($abs) ?: 0,
    ];
}

// Dedup by path so an explicit star inside an active folder is shown once.
$seen = [];
$images = array_values(array_filter($images, function ($e) use (&$seen) {
    if (isset($seen[$e['path']])) return false;
    $seen[$e['path']] = true;
    return true;
}));

echo json_encode($images, JSON_PRETTY_PRINT);

//=======================Functions===============================================

// Recursively gather image entries, each with its best-available date.
function getImages($dir) {
    global $appName;
    // Enforce the skip rule on the root path too — without this, adding
    // "_archived" directly via the manage toggle would leak its children
    // since the in-loop check only fires on descendants.
    if (is_skipped_path($dir)) return [];
    $entries = [];
    $files   = scandir($dir);
    foreach ($files as $file) {

        // Skip current/parent, hidden (.) files/folders, and "skipped"
        // (_) files/folders — leading underscore is the manual mark users
        // apply via the list view to keep something out of the slideshow.
        if ($file === '.' || $file === '..') continue;
        $firstChar = substr($file, 0, 1);
        if ($firstChar === '.' || $firstChar === '_') continue;

        $fullPath = $dir . DIRECTORY_SEPARATOR . $file;

        if (is_dir($fullPath)) {
            $entries = array_merge($entries, getImages($fullPath));
            continue;
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) continue;

        $rel = $appName . '/' . str_replace($_SERVER['DOCUMENT_ROOT'] . "/$appName/", '', $fullPath);

        $entries[] = [
            'path' => $rel,
            'date' => image_date($fullPath),
            'v'    => @filectime($fullPath) ?: 0,
        ];
    }
    return $entries;
}

?>
