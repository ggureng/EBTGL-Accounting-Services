<?php
session_start();
require 'db_connection.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $company_name = $data['company_name'] ?? '';
    $email = $data['email'] ?? '';
    $phone = $data['phone'] ?? '';
    $position = $data['position'] ?? '';
    $full_name = $data['full_name'] ?? '';
    $password = $data['password'] ?? '';
    
    // Build update query
    $updates = [];
    $params = [];
    
    if (!empty($company_name)) {
        $updates[] = "`Company Name` = ?";
        $params[] = $company_name;
    }
    
    if (!empty($email)) {
        $updates[] = "Email = ?";
        $params[] = $email;
    }
    
    if (!empty($phone)) {
        $updates[] = "Phone = ?";
        $params[] = $phone;
    }
    
    if (!empty($position)) {
        $updates[] = "Position = ?";
        $params[] = $position;
    }
    
    if (!empty($full_name)) {
        $updates[] = "`Full Name` = ?";
        $params[] = $full_name;
    }
    
    if (!empty($password)) {
        $updates[] = "password = ?";
        $params[] = $password;;
    }
    
    if (!empty($updates)) {
        $query = "UPDATE client SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $_SESSION['user_id'];
        
        $stmt = $pdo->prepare($query);
        if ($stmt->execute($params)) {
            $response['success'] = true;
            $response['message'] = 'Account updated successfully';
        } else {
            $response['message'] = 'Database error';
        }
    } else {
        $response['message'] = 'No changes to update';
    }
} else {
    $response['message'] = 'Invalid request';
}

header('Content-Type: application/json');
echo json_encode($response);
?>