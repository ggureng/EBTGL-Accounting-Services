<?php
session_start();
require 'db_connection.php';

// Test: confirm session is set
$client_id = $_SESSION['user_id'] ?? null;
if (!$client_id) {
    die(json_encode(["unread_count" => 0]));
}

try {
    // Count unread admin messages for this client
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count
        FROM messages 
        WHERE recipient_id = ? AND is_admin_reply = 1 AND is_read_client = FALSE
    ");
    $stmt->execute([$client_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(["unread_count" => (int)$result['unread_count']]);

} catch (PDOException $e) {
    error_log("Database error in get_unread_count: " . $e->getMessage());
    echo json_encode(["unread_count" => 0]);
}
?>