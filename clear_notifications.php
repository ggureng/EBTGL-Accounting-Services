<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

$admin_id = $_SESSION['admin_id'] ?? 1;

// Mark ALL current notifications as cleared in database AND session
if (isset($_SESSION['realNotifications'])) {
    foreach ($_SESSION['realNotifications'] as $notification) {
        $key = $notification['key']; // Use the consistent key
        
        // Mark in database
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO notification_cleared (admin_id, notification_key) VALUES (?, ?)");
            $stmt->execute([$admin_id, $key]);
        } catch (Exception $e) {
            error_log("Error clearing notification: " . $e->getMessage());
        }
        
        // Mark in session
        if (!in_array($key, $_SESSION['notifications_seen'])) {
            $_SESSION['notifications_seen'][] = $key;
        }
    }
}

// Don't clear the realNotifications array - we need it for display
// Just ensure they're all marked as seen/cleared

echo json_encode(['success' => true]);
exit;
?>