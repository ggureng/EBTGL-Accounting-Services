<?php
session_start();
require 'db_connection.php';

// Check if admin is logged in
$admin_id = $_SESSION['admin_id'] ?? null;

if (isset($_POST['client_id'])) {
    // Admin sending message to client
    $client_id = $_POST['client_id'];
    $message = $_POST['reply'] ?? '';
    
    // Handle file upload
    $attachmentPath = '';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = time() . '_' . basename($_FILES['attachment']['name']);
        $uploadFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadFile)) {
            $attachmentPath = $uploadFile;
        }
    }

    try {
        // Insert message with admin_id - UPDATED QUERY with read status
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, recipient_id, message, attachment_path, is_admin_reply, admin_id, is_read_client, sent_at) 
            VALUES (NULL, ?, ?, ?, 1, ?, FALSE, NOW())
        ");
        
        $stmt->execute([
            $client_id,
            $message,
            $attachmentPath,
            $admin_id
        ]);

        echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
    } catch (PDOException $e) {
        error_log("Database error in save_message: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error sending message: ' . $e->getMessage()]);
    }
} else {
    // Client sending message (existing code)
    $client_id = $_SESSION['user_id'] ?? null;
    if (!$client_id) {
        echo json_encode(['success' => false, 'message' => 'Client not logged in']);
        exit;
    }

    $message = $_POST['message'] ?? '';
    
    // Handle file upload
    $attachmentPath = '';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = time() . '_' . basename($_FILES['attachment']['name']);
        $uploadFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadFile)) {
            $attachmentPath = $uploadFile;
        }
    }

    // Prevent empty messages without attachments
    if (empty($message) && empty($attachmentPath)) {
        echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
        exit;
    }

    try {
        // Insert client message (no admin_id needed for client messages) - UPDATED with read status
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, recipient_id, message, attachment_path, is_admin_reply, is_read_admin, sent_at) 
            VALUES (?, NULL, ?, ?, 0, FALSE, NOW())
        ");
        
        $stmt->execute([
            $client_id,
            $message,
            $attachmentPath
        ]);

        echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
    } catch (PDOException $e) {
        error_log("Database error in save_message: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error sending message: ' . $e->getMessage()]);
    }
}
?>