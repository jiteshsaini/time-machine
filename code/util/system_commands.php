<?php
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $response = ['status' => 'ok'];

    // Handle system commands
    if ($action === 'reboot') {
        rebootSystem();
        $response['message'] = "Reboot initiated after closing the browser.";
    } elseif ($action === 'shutdown') {
        shutdownSystem();
        $response['message'] = "Shutdown initiated after closing the browser.";
    } elseif ($action === 'closeBrowser') {
        $resetStatus = resetBrowser();
        $response['reset'] = $resetStatus;
        $closed = [];
        if (!empty($resetStatus['chromium_running'])) $closed[] = 'Chromium';
        if (!empty($resetStatus['firefox_running']))  $closed[] = 'Firefox';
        $response['message'] = $closed
            ? (implode(' & ', $closed) . ' closed.')
            : 'No browser was running.';
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
//
// These are the only commands the app runs as root, and the installer's
// sudo rule (/etc/sudoers.d/time-machine) allows exactly these, word for
// word - change both together.
// ============================

function resetBrowser() {
    // ── Detect which kiosk browser is running (for the status message). ──
    // pgrep exits 0 when a match is found, non-zero otherwise.
    exec("pgrep -x chromium",         $c1, $cs1);
    exec("pgrep -x chromium-browser", $c2, $cs2);
    exec("pgrep -x firefox",          $f1, $fs1);
    exec("pgrep -x firefox-esr",      $f2, $fs2);
    $chromiumRunning = ($cs1 === 0 || $cs2 === 0);
    $firefoxRunning  = ($fs1 === 0 || $fs2 === 0);

    // ── Kill whichever browser(s) are open. pkill harmlessly no-ops when a
    //    process name isn't running, so attempting both is safe. ──
    exec("sudo pkill -x chromium;         sudo pkill -x chromium-browser", $o1, $kc);
    exec("sudo pkill -x firefox;          sudo pkill -x firefox-esr",      $o2, $kf);

    // Nothing to clear here: the kiosk launcher starts the browser with an
    // empty profile every time.

    return [
        'chromium_running' => $chromiumRunning,
        'firefox_running'  => $firefoxRunning,
    ];
}


function rebootSystem() {
    resetBrowser(); // Close the browser first
    exec("sudo reboot > /dev/null 2>&1 &");
}

function shutdownSystem() {
    resetBrowser(); // Close the browser first
    exec("sudo shutdown -h now > /dev/null 2>&1 &");
}
?>