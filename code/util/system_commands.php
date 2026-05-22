<?php
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['status' => 'ok'];

    // Handle system commands
    if ($action === 'reboot') {
        rebootSystem();
        $response['message'] = "Reboot initiated after resetting Chromium.";
    } elseif ($action === 'shutdown') {
        shutdownSystem();
        $response['message'] = "Shutdown initiated after resetting Chromium.";
    } elseif ($action === 'closeBrowser') {
        $resetStatus = resetChromium();
        $response['reset'] = $resetStatus;
        $response['message'] = "Chromium browser closed and cache cleared.";
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        exit;
    }

    echo json_encode($response);
    exit;
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

// ============================
// Utility Functions
// ============================

function resetChromium() {
    // Kill Chromium
    exec("sudo pkill chromium", $output1, $status1);

    // Clear Chromium cache
    exec("sudo rm -rf /home/pi/.cache/chromium/*", $output2, $status2);

    // Clear session crash and restore prompts
    exec("sudo rm -f /home/pi/.config/chromium/Default/Preferences", $output3, $status3);
    exec("sudo rm -f /home/pi/.config/chromium/Singleton*", $output4, $status4);

    return [
        'chromium_kill_status' => $status1,
        'cache_clear_status' => $status2,
        'preferences_clear_status' => $status3,
        'singleton_clear_status' => $status4
    ];
}


function rebootSystem() {
    resetChromium(); // Clean up before reboot
    exec("sudo reboot > /dev/null 2>&1 &");
}

function shutdownSystem() {
    resetChromium(); // Clean up before shutdown
    exec("sudo shutdown -h now > /dev/null 2>&1 &");
}
?>