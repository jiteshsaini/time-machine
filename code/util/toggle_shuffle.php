<?php
ini_set('display_errors', '1');

include_once "../var.php";

header('Content-Type: application/json');

$shuffle_file = state_file('shuffle.txt');
$status_file  = state_file('change_status.txt');

try {
    $current = file_exists($shuffle_file) ? trim(file_get_contents($shuffle_file)) : '0';
    $newValue = ($current === '1') ? '0' : '1';

    file_put_contents($shuffle_file, $newValue);
    file_put_contents($status_file, '1');
    audit('settings.shuffle', $newValue);

    echo json_encode([
        'success' => true,
        'newValue' => $newValue
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
