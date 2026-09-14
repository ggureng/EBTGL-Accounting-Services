<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// Get the date from query parameter
$date = $_GET['date'] ?? '';

if (empty($date)) {
    echo json_encode(['error' => 'Date is required']);
    exit;
}

try {
    // Generate all time slots
    $time_slots = [];
    $available_count = 0;
    
    for ($hour = 8; $hour <= 17; $hour++) {
        $value = sprintf("%02d:00:00", $hour);
        $display = date("g:00 A", strtotime("$hour:00"));
        
        // Check if this time slot is already booked
        $booked_stmt = $pdo->prepare("
            SELECT COUNT(*) as booked_count 
            FROM schedules 
            WHERE schedule_date = ? AND schedule_time = ?
        ");
        $booked_stmt->execute([$date, $value]);
        $booked_count = $booked_stmt->fetch(PDO::FETCH_ASSOC)['booked_count'];
        
        // Time slot is available if no booking exists
        $available = $booked_count == 0;
        
        if ($available) {
            $available_count++;
        }
        
        $time_slots[] = [
            'value' => $value,
            'display' => $display,
            'available' => $available,
            'booked_count' => $booked_count
        ];
    }
    
    echo json_encode([
        'time_slots' => $time_slots,
        'date' => $date,
        'available_count' => $available_count
    ]);
    
} catch (Exception $e) {
    error_log("Availability check error: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to check availability']);
}
?>