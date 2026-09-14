<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require 'db_connection.php';

// Test: confirm DB connection exists
if (!isset($pdo)) {
    die(json_encode(["error" => "Database connection failed."]));
}

// Test: confirm session is set
$client_id = $_SESSION['user_id'] ?? null;
if (!$client_id) {
    die(json_encode(["error" => "Client not logged in. Session ID missing."]));
}

// Mark admin messages as read when client fetches them
if (isset($_GET['mark_read']) && $_GET['mark_read'] == 1) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read_client = TRUE WHERE recipient_id = ? AND is_admin_reply = 1");
    $stmt->execute([$client_id]);
}

try {
    // UPDATED QUERY - Fixed to properly get admin names and include read status
    $stmt = $pdo->prepare("
        SELECT m.*, 
            CASE 
                WHEN m.sender_id IS NULL THEN 'Admin' 
                ELSE 'You' 
            END AS sender,
            a.full_name AS admin_name
        FROM messages m
        LEFT JOIN admin_accounts a ON m.admin_id = a.id
        WHERE m.sender_id = :id OR m.recipient_id = :id2
        ORDER BY m.sent_at ASC
    ");
    $stmt->execute([
        'id' => $client_id,
        'id2' => $client_id
    ]);

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($messages);

} catch (PDOException $e) {
    // Return error as JSON
    error_log("Database error in fetch_messages: " . $e->getMessage());
    echo json_encode(["error" => "SQL error: " . $e->getMessage()]);
}
?>