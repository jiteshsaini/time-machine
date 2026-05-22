<?php
/*
* gets executed on load and on change by index.html
* returns '1' if the camera-style date overlay should be shown, '0' otherwise
*/

include_once __DIR__ . "/../var.php";

$file = state_file('show_date.txt');
$val  = file_exists($file) ? trim(file_get_contents($file)) : '';
echo ($val === '1') ? '1' : '0';
?>
