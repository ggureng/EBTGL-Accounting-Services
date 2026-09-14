<?php
session_start();
require 'db_connection.php';

// Set header for JSON response
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to reschedule.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$schedule_id = $_POST['schedule_id'] ?? null;
$new_date = $_POST['new_date'] ?? null;
$new_time = $_POST['new_time'] ?? null;

if (!$schedule_id || !$new_date || !$new_time) {
    echo json_encode(['success' => false, 'message' => 'Missing required information. Please fill all fields.']);
    exit;
}

// Validate date
if ($new_date < date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'Cannot schedule appointments in the past. Please select a future date.']);
    exit;
}

// Validate time format
if (!preg_match('/^([0-1][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/', $new_time)) {
    echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
    exit;
}

// Check if the user owns this schedule
try {
    $stmt = $pdo->prepare("SELECT user_id, company_name, assigned_admin_id, schedule_date FROM schedules WHERE id = ?");
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found.']);
        exit;
    }
    
    if ($schedule['user_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to reschedule this appointment.']);
        exit;
    }
} catch (Exception $e) {
    error_log("Schedule ownership verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error verifying schedule ownership.']);
    exit;
}

/**
 * Check if a time slot is available (not all admins are booked)
 */
function isTimeSlotAvailable($pdo, $date, $time, $exclude_schedule_id = null) {
    try {
        // Get total number of active admins
        $admin_stmt = $pdo->prepare("SELECT COUNT(*) as total_admins FROM admin_accounts WHERE status = 'active'");
        $admin_stmt->execute();
        $result = $admin_stmt->fetch(PDO::FETCH_ASSOC);
        $total_admins = $result ? $result['total_admins'] : 0;
        
        if ($total_admins == 0) {
            return false; // No admins available
        }
        
        // Get number of admins already booked at this time
        $sql = "
            SELECT COUNT(DISTINCT assigned_admin_id) as booked_admins 
            FROM schedules 
            WHERE schedule_date = ? AND schedule_time = ? AND assigned_admin_id IS NOT NULL
        ";
        
        $params = [$date, $time];
        
        if ($exclude_schedule_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_schedule_id;
        }
        
        $booked_stmt = $pdo->prepare($sql);
        $booked_stmt->execute($params);
        $result = $booked_stmt->fetch(PDO::FETCH_ASSOC);
        $booked_admins = $result ? $result['booked_admins'] : 0;
        
        // Time slot is available if booked admins < total admins
        return $booked_admins < $total_admins;
        
    } catch (Exception $e) {
        error_log("Time slot availability check error: " . $e->getMessage());
        return false;
    }
}

// Check availability for the new time slot
if (!isTimeSlotAvailable($pdo, $new_date, $new_time, $schedule_id)) {
    echo json_encode(['success' => false, 'message' => 'The selected time slot is no longer available. Please choose another time.']);
    exit;
}

// Get the current schedule data
$assigned_admin_id = $schedule['assigned_admin_id'];
$current_date = $schedule['schedule_date'];
$company_name = $schedule['company_name'];

// Auto-assign admin logic - always reassign for rescheduling to balance workload
try {
    $admin_stmt = $pdo->prepare("
        SELECT id, full_name 
        FROM admin_accounts 
        WHERE status = 'active'
    ");
    $admin_stmt->execute();
    $admins = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($admins)) {
        echo json_encode(['success' => false, 'message' => 'No administrators available for scheduling. Please try again later.']);
        exit;
    }
    
    // Get admin workload for the new date
    $workload_stmt = $pdo->prepare("
        SELECT assigned_admin_id, COUNT(*) as appointment_count 
        FROM schedules 
        WHERE schedule_date = ? AND assigned_admin_id IS NOT NULL AND id != ?
        GROUP BY assigned_admin_id
    ");
    $workload_stmt->execute([$new_date, $schedule_id]);
    $workloads = $workload_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create workload map
    $workload_map = [];
    foreach ($workloads as $workload) {
        $workload_map[$workload['assigned_admin_id']] = $workload['appointment_count'];
    }
    
    // Find admin with least appointments
    $selected_admin = null;
    $min_appointments = PHP_INT_MAX;
    
    foreach ($admins as $admin) {
        $appointment_count = $workload_map[$admin['id']] ?? 0;
        if ($appointment_count < $min_appointments) {
            $min_appointments = $appointment_count;
            $selected_admin = $admin;
        }
    }
    
    $assigned_admin_id = $selected_admin ? $selected_admin['id'] : $admins[0]['id'];
    
} catch (Exception $e) {
    error_log("Admin reassignment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error assigning administrator. Please try again.']);
    exit;
}

// Update the schedule with new date, time, and new admin
try {
    $stmt = $pdo->prepare("
        UPDATE schedules 
        SET schedule_date = ?, schedule_time = ?, assigned_admin_id = ?
        WHERE id = ?
    ");
    
    if ($stmt->execute([$new_date, $new_time, $assigned_admin_id, $schedule_id])) {
        // Format the time for display (remove seconds)
        $display_time = substr($new_time, 0, 5);
        
        echo json_encode([
            'success' => true, 
            'message' => "Appointment for {$company_name} successfully rescheduled to {$new_date} at {$display_time}!"
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update schedule in database. Please try again.']);
    }
} catch (PDOException $e) {
    error_log("Schedule update PDO error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Schedule update general error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred while updating the schedule.']);
}
?>