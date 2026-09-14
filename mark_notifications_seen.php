<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['notifications_seen'])) {
    $_SESSION['notifications_seen'] = [];
}

// Get current notification keys from session
$newlySeenCount = 0;

if (isset($_SESSION['realNotifications'])) {
    foreach ($_SESSION['realNotifications'] as $notification) {
        $key = $notification['key'];
        
        // Only mark as seen if not already seen and not cleared
        if (!in_array($key, $_SESSION['notifications_seen']) && !$notification['cleared']) {
            $_SESSION['notifications_seen'][] = $key;
            $newlySeenCount++;
        }
    }
}

// Update last seen timestamp
$_SESSION['notifications_last_seen'] = time();

echo json_encode([
    'success' => true,
    'newly_seen' => $newlySeenCount,
    'total_seen' => count($_SESSION['notifications_seen'])
]);
exit;