<?php
/*
* gets executed on load by index.html file
* returns 'contain' (default — letterbox, no crop) or 'cover' (zoom-fill, crops edges)
*/

include_once __DIR__ . "/../var.php";

$file = state_file('fit_mode.txt');
$mode = file_exists($file) ? trim(file_get_contents($file)) : '';
echo ($mode === 'cover') ? 'cover' : 'contain';
?>
