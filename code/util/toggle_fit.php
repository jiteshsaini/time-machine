<?php
ini_set('display_errors', '1');

include_once "../var.php";

header('Content-Type: application/json');

$fit_file    = state_file('fit_mode.txt');
$status_file = state_file('change_status.txt');

try {
    $current = file_exists($fit_file) ? trim(file_get_contents($fit_file)) : 'contain';
    $newValue = ($current === 'cover') ? 'contain' : 'cover';

    file_put_contents($fit_file, $newValue);
    file_put_contents($status_file, '1');
    audit('settings.fit_mode', $newValue);

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
