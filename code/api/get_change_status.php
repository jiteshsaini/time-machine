<?php
/*
 * Returns the mtime of change_status.txt as a Unix timestamp.
 *
 * The slideshow viewer polls this every 5 seconds and remembers the value it
 * last saw. A bigger number means the file was bumped (any setting toggle,
 * folder add/remove, image rotate/crop/trash, etc., writes to the file as a
 * side effect — its content is no longer read, only its mtime).
 *
 * Each viewer compares independently, so any number of displays can run
 * simultaneously and all will reload when something changes — no more
 * "first viewer wins" race the old reset-the-flag scheme had.
 */

include_once __DIR__ . "/../var.php";

$statusFile = state_file('change_status.txt');
echo @filemtime($statusFile) ?: 0;
?>
