<?php
session_start();
require 'db_connection.php';

$response = ['success' => false];

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT 
        `Company Name` AS company_name,
        Email AS email,
        Phone AS phone,
        Position AS position,
        `Full Name` AS full_name
        FROM client WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        $response = [
            'success' => true,
            'company_name' => $client['company_name'],
            'email' => $client['email'],
            'phone' => $client['phone'],
            'position' => $client['position'],
            'full_name' => $client['full_name']
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>