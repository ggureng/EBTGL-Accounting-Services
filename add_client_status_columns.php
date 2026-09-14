<?php
session_start();
require 'db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.html");
    exit;
}

try {
    // Check if status column exists
    $checkStatus = $pdo->query("SHOW COLUMNS FROM client LIKE 'status'");
    if ($checkStatus->rowCount() === 0) {
        $pdo->exec("ALTER TABLE client ADD COLUMN status VARCHAR(50) DEFAULT 'Active'");
        echo "Added status column. ";
    }
    
    // Check if inactivation_reason column exists
    $checkReason = $pdo->query("SHOW COLUMNS FROM client LIKE 'inactivation_reason'");
    if ($checkReason->rowCount() === 0) {
        $pdo->exec("ALTER TABLE client ADD COLUMN inactivation_reason VARCHAR(255) DEFAULT NULL");
        echo "Added inactivation_reason column. ";
    }
    
    // Update existing clients based on contract dates
    $pdo->exec("
        UPDATE client 
        SET status = 'Active' 
        WHERE status IS NULL 
        AND DATE_ADD(`Date`, INTERVAL 100 DAY) > NOW()
    ");
    
    $pdo->exec("
        UPDATE client 
        SET status = 'Inactive', 
            inactivation_reason = 'Contract Expired' 
        WHERE status IS NULL 
        AND DATE_ADD(`Date`, INTERVAL 100 DAY) <= NOW()
    ");
    
    echo "Client status columns updated successfully.";
    
} catch (Exception $e) {
    echo "Error updating columns: " . $e->getMessage();
}
?>