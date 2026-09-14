<?php
session_start();
require 'db_connection.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Not logged in';
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$message = trim($_POST['message'] ?? '');
$filePath = '';

// Handle file upload
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = 'uploads/attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Get company name
    $companyName = 'Unknown_Company';
    $stmt = $pdo->prepare("SELECT `Company Name` FROM client WHERE id = ?");
    $stmt->execute([$userId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client && !empty($client['Company Name'])) {
        $companyName = preg_replace('/[^A-Za-z0-9]/', '_', $client['Company Name']);
    }
    
    $originalName = basename($_FILES['attachment']['name']);
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $newName = $companyName . '_sent_by_client_' . $originalName;
    
    // Add timestamp if file exists
    $counter = 1;
    $baseName = pathinfo($newName, PATHINFO_FILENAME);
    $targetPath = $uploadDir . $newName;
    while (file_exists($targetPath)) {
        $newName = $baseName . '_' . time() . '.' . $extension;
        $targetPath = $uploadDir . $newName;
        $counter++;
    }
    
    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
        $filePath = $targetPath;
    } else {
        $response['message'] = 'File upload failed';
        echo json_encode($response);
        exit;
    }
}

// Validate we have content
if (empty($message) && empty($filePath)) {
    $response['message'] = 'No content to send';
    echo json_encode($response);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, message, sent_at, attachment_path) 
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->execute([$userId, $message, $filePath]);
    $response['success'] = true;
    $response['filePath'] = $filePath;
} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);