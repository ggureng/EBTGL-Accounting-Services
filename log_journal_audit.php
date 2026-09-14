<?php
session_start();
require 'db_connection.php';
require 'audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $client_id = $_POST['client_id'] ?? null;
    
    if (isset($_SESSION['admin_id'])) {
        $current_admin = getCurrentAdminInfo();
        
        // Log the action
        logAdminAction(
            $current_admin['id'],
            $current_admin['username'],
            $action_type,
            $description,
            'journal_summary',
            null,
            $client_id ? json_encode(['client_id' => $client_id]) : null
        );
    }
    
    echo json_encode(['status' => 'success']);
}
?>