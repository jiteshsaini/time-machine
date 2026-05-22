<?php
/*
* gets executed on load by index.html file
*/

include_once __DIR__ . "/../var.php";

// Read file content
$content = file_get_contents(state_file('shuffle.txt'));

echo $content;

?>
