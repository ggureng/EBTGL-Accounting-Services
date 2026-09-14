<?php
// auto_check_trigger.php
// Include this file in your main pages to trigger automatic checks

// Only run if check_inactive_clients.php exists
if (file_exists('check_inactive_clients.php')) {
    // Use output buffering to prevent any output
    ob_start();
    
    // Include the check file silently
    @include 'check_inactive_clients.php';
    
    // Clear the buffer
    ob_end_clean();
    
    // Log that auto-trigger was attempted
    error_log("Auto-trigger attempted at " . date('Y-m-d H:i:s'));
}
?>