<?php
ini_set('display_errors', '1');

include_once "../var.php";

header('Content-Type: application/json');

$flag_file   = state_file('show_date.txt');
$status_file = state_file('change_status.txt');

try {
    $current  = file_exists($flag_file) ? trim(file_get_contents($flag_file)) : '0';
    $newValue = ($current === '1') ? '0' : '1';

    file_put_contents($flag_file, $newValue);
    file_put_contents($status_file, '1');
    audit('settings.show_date', $newValue);

    echo json_encode([
        'success'  => true,
        'newValue' => $newValue
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
