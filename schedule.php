<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_name'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];

// Get time slot availability for today by default
$default_date = date('Y-m-d');
$time_slots = getTimeSlotAvailability($pdo, $default_date);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $company = $company_name;

        // Validate inputs
        if (empty($date) || empty($time)) {
            throw new Exception("Date and time are required.");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception("Invalid date format.");
        }

        // Check if the selected time is still available
        if (!isTimeSlotAvailable($pdo, $date, $time)) {
            throw new Exception("The selected time slot is no longer available. Please choose another time.");
        }

        // Insert schedule without admin assignment
        $stmt = $pdo->prepare("
            INSERT INTO schedules (user_id, schedule_date, schedule_time, company_name)
            VALUES (?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$user_id, $date, $time, $company])) {
            header("Location: calendar.php?date=" . urlencode($date) . "&status=success");
            exit;
        } else {
            throw new Exception("Failed to save schedule.");
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        // Refresh time slots for the submitted date
        if (!empty($date)) {
            $time_slots = getTimeSlotAvailability($pdo, $date);
        }
    }
}

/**
 * Check if a time slot is available (not already booked)
 */
function isTimeSlotAvailable($pdo, $date, $time) {
    try {
        // Check if this time slot is already booked
        $booked_stmt = $pdo->prepare("
            SELECT COUNT(*) as booked_count 
            FROM schedules 
            WHERE schedule_date = ? AND schedule_time = ?
        ");
        $booked_stmt->execute([$date, $time]);
        $booked_count = $booked_stmt->fetch(PDO::FETCH_ASSOC)['booked_count'];
        
        // Time slot is available if no booking exists
        return $booked_count == 0;
        
    } catch (Exception $e) {
        error_log("Time slot availability check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get time slot availability for a specific date
 */
function getTimeSlotAvailability($pdo, $date) {
    $time_slots = [];
    
    try {
        // Generate all time slots
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
            
            $time_slots[] = [
                'value' => $value,
                'display' => $display,
                'available' => $available,
                'booked_count' => $booked_count
            ];
        }
        
    } catch (Exception $e) {
        error_log("Time slot availability error: " . $e->getMessage());
        // Return all times as available if there's an error
        for ($hour = 8; $hour <= 17; $hour++) {
            $value = sprintf("%02d:00:00", $hour);
            $display = date("g:00 A", strtotime("$hour:00"));
            $time_slots[] = [
                'value' => $value,
                'display' => $display,
                'available' => true,
                'booked_count' => 0
            ];
        }
    }
    
    return $time_slots;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Submission - EBTGL Accounting</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4682B4;
            --secondary: #5a96cf;
            --accent: #2a5a80;
            --light: #f8f9fa;
            --dark: #2a5a80;
            --success: #2ecc71;
            --error: #e74c3c;
            --warning: #f39c12;
            --card-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #2a5a80, #4682B4, #2a5a80);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #333;
        }
        
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .container {
            max-width: 650px;
            width: 100%;
            background: rgba(255, 255, 255, 0.97);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }
        
        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }
        
        .header {
            background: linear-gradient(135deg, var(--accent), var(--dark));
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        
        .header h2 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .form-content {
            padding: 40px;
        }
        
        .form-group {
            margin-bottom: 30px;
            position: relative;
        }
        
        label {
            display: block;
            margin-bottom: 12px;
            font-weight: 600;
            color: var(--dark);
            font-size: 1.05rem;
            display: flex;
            align-items: center;
        }
        
        label i {
            margin-right: 12px;
            color: var(--secondary);
            width: 24px;
            text-align: center;
            font-size: 1.2rem;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        input, select {
            width: 100%;
            padding: 16px 20px 16px 55px;
            border: 2px solid #e1e5eb;
            border-radius: 12px;
            font-size: 1.05rem;
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
            background-color: var(--light);
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(70, 130, 180, 0.2);
        }
        
        .input-wrapper i {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dark);
            font-size: 1.2rem;
        }
        
        button {
            margin-top: 20px;
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.15rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            letter-spacing: 0.5px;
            font-family: 'Montserrat', sans-serif;
            box-shadow: 0 4px 15px rgba(70, 130, 180, 0.3);
        }
        
        button:hover {
            background: linear-gradient(135deg, var(--dark), var(--primary));
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(70, 130, 180, 0.4);
        }
        
        button i {
            margin-right: 12px;
        }
        
        .calendar-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
            font-size: 1.2rem;
            pointer-events: none;
        }
        
        .info-note {
            background-color: #e9f7fe;
            border-left: 4px solid var(--primary);
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.95rem;
            color: var(--dark);
        }
        
        .error-note {
            background-color: #fee9e9;
            border-left: 4px solid var(--error);
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.95rem;
            color: var(--dark);
        }
        
        .warning-note {
            background-color: #fef5e9;
            border-left: 4px solid var(--warning);
            padding: 15px 20px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.95rem;
            color: var(--dark);
        }
        
        .info-note i, .error-note i, .warning-note i {
            margin-right: 10px;
        }
        
        .info-note i {
            color: var(--primary);
        }
        
        .error-note i {
            color: var(--error);
        }
        
        .warning-note i {
            color: var(--warning);
        }
        
        .time-slot-status {
            margin-top: 10px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .available-slots {
            color: var(--success);
            font-weight: 600;
        }
        
        .full-slots {
            color: var(--error);
            font-weight: 600;
        }
        
        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        
        .time-slot {
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background-color: var(--light);
            position: relative;
        }
        
        .time-slot.available {
            border-color: var(--success);
            background-color: #e8f6ef;
        }
        
        .time-slot.available:hover {
            background-color: #d1f2eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(46, 204, 113, 0.2);
        }
        
        .time-slot.unavailable {
            border-color: #e1e5eb;
            background-color: #f8f9fa;
            color: #6c757d;
            cursor: not-allowed;
        }
        
        .time-slot.selected {
            border-color: var(--primary);
            background-color: #e3f2fd;
            box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.2);
        }
        
        .time-label {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .slot-status {
            font-size: 0.75rem;
            margin-top: 5px;
            font-weight: 500;
        }
        
        .available .slot-status {
            color: var(--success);
        }
        
        .unavailable .slot-status {
            color: var(--error);
        }
        
        .form-footer {
            text-align: center;
            padding: 20px 0;
            color: #6c757d;
            font-size: 0.9rem;
            border-top: 1px solid #eaeaea;
            margin-top: 20px;
        }
        
        .form-footer a {
            color: var(--secondary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .form-footer a:hover {
            color: var(--dark);
            text-decoration: underline;
        }
        
        .back-button {
            margin-top: 25px;
            text-align: center;
        }
        
        .back-button a {
            display: inline-block;
            color: white;
            background: linear-gradient(135deg, var(--accent), var(--dark));
            padding: 14px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            box-shadow: 0 4px 12px rgba(42, 90, 128, 0.2);
        }
        
        .back-button a:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(42, 90, 128, 0.3);
        }
        
        .back-button a i {
            margin-right: 8px;
        }
        
        /* Popup Styles */
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .popup-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 90%;
            text-align: center;
            position: relative;
        }
        
        .popup-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        
        .popup-error .popup-icon {
            color: var(--error);
        }
        
        .popup-success .popup-icon {
            color: var(--success);
        }
        
        .popup-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .popup-message {
            margin-bottom: 20px;
            color: #555;
        }
        
        .popup-close {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .popup-close:hover {
            background: var(--dark);
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 10px;
            }
            
            .header {
                padding: 25px;
            }
            
            .form-content {
                padding: 30px 20px;
            }
            
            .header h2 {
                font-size: 1.9rem;
            }
            
            input, select {
                padding: 14px 14px 14px 50px;
            }
            
            .time-slots-container {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
        
        @media (max-width: 480px) {
            .header h2 {
                font-size: 1.7rem;
            }
            
            .header p {
                font-size: 1rem;
            }
            
            input, select {
                padding: 14px 14px 14px 45px;
                font-size: 1rem;
            }
            
            label {
                font-size: 1rem;
            }
            
            button {
                padding: 16px;
                font-size: 1.1rem;
            }
            
            .time-slots-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Error Popup -->
    <div class="popup-overlay" id="errorPopup">
        <div class="popup-content popup-error">
            <div class="popup-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="popup-title">Booking Error</div>
            <div class="popup-message" id="errorPopupMessage"></div>
            <button class="popup-close" onclick="closePopup()">OK</button>
        </div>
    </div>

    <div class="container">
        <div class="header">
            <h2><i class="fas fa-calendar-alt"></i> Schedule Appointment</h2>
            <p>Book a meeting with our accounting experts</p>
        </div>
        
        <div class="form-content">
            <?php if (isset($error)): ?>
                <div class="error-note">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="scheduleForm">
                <div class="form-group">
                    <label for="company"><i class="fas fa-building"></i> Company Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-building"></i>
                        <input type="text" name="company" value="<?php echo htmlspecialchars($company_name); ?>" readonly>
                    </div>
                    <div class="info-note">
                        <i class="fas fa-info-circle"></i> Your company name is automatically filled from your account
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="date"><i class="fas fa-calendar-day"></i> Appointment Date</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-day"></i>
                        <input type="date" name="date" id="datePicker" required min="<?php echo date('Y-m-d'); ?>">
                        <span class="calendar-icon"><i class="fas fa-calendar-check"></i></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="time"><i class="fas fa-clock"></i> Preferred Time</label>
                    <input type="hidden" name="time" id="selectedTime" required>
                    
                    <div class="time-slots-container" id="timeSlotsContainer">
                        <?php foreach ($time_slots as $slot): ?>
                            <div class="time-slot <?php echo $slot['available'] ? 'available' : 'unavailable'; ?>" 
                                 data-time="<?php echo $slot['value']; ?>"
                                 data-available="<?php echo $slot['available'] ? 'true' : 'false'; ?>">
                                <div class="time-label"><?php echo $slot['display']; ?></div>
                                <div class="slot-status">
                                    <?php if ($slot['available']): ?>
                                        <i class="fas fa-check-circle"></i> Available
                                    <?php else: ?>
                                        <i class="fas fa-times-circle"></i> Booked
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="time-slot-status" id="timeSlotStatus">
                        <i class="fas fa-info-circle"></i>
                        <span id="availabilityText">Click on an available time slot to select it</span>
                    </div>
                    
                    <div class="info-note">
                        <i class="fas fa-info-circle"></i> Office hours: 8:00 AM to 5:00 PM, Monday to Friday
                    </div>
                </div>
                
                <button type="submit" id="submitButton"><i class="fas fa-calendar-plus"></i> Book Appointment</button>
                
                <div class="back-button">
                    <a href="calendar.php"><i class="fas fa-arrow-left"></i> Back to Calendar</a>
                </div>
            </form>
        </div>
        
        <div class="form-footer">
            <p>© 2023 EBTGL Accounting Services | <a href="#"><i class="fas fa-shield-alt"></i> Privacy Policy</a></p>
        </div>
    </div>

    <script>
        // Set default date to today
        const dateInput = document.getElementById("datePicker");
        const timeSlotsContainer = document.getElementById("timeSlotsContainer");
        const selectedTimeInput = document.getElementById("selectedTime");
        const availabilityText = document.getElementById("availabilityText");
        const timeSlotStatus = document.getElementById("timeSlotStatus");
        const submitButton = document.getElementById("submitButton");
        const errorPopup = document.getElementById("errorPopup");
        const errorPopupMessage = document.getElementById("errorPopupMessage");
        const today = new Date().toISOString().split('T')[0];
        dateInput.setAttribute('min', today);
        dateInput.value = today;

        // Function to update time slots based on selected date
        async function updateTimeSlots(date) {
            if (!date) return;
            
            try {
                availabilityText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading time slots...';
                
                const response = await fetch(`check_availability.php?date=${date}`);
                const data = await response.json();
                
                // Update time slots container
                timeSlotsContainer.innerHTML = '';
                
                if (data.time_slots && data.time_slots.length > 0) {
                    data.time_slots.forEach(slot => {
                        const timeSlot = document.createElement('div');
                        timeSlot.className = `time-slot ${slot.available ? 'available' : 'unavailable'}`;
                        timeSlot.setAttribute('data-time', slot.value);
                        timeSlot.setAttribute('data-available', slot.available);
                        
                        timeSlot.innerHTML = `
                            <div class="time-label">${slot.display}</div>
                            <div class="slot-status">
                                ${slot.available ? 
                                    '<i class="fas fa-check-circle"></i> Available' : 
                                    '<i class="fas fa-times-circle"></i> Booked'}
                            </div>
                        `;
                        
                        if (slot.available) {
                            timeSlot.addEventListener('click', () => selectTimeSlot(slot.value, timeSlot));
                        }
                        
                        timeSlotsContainer.appendChild(timeSlot);
                    });
                    
                    availabilityText.innerHTML = `Found ${data.available_count} available time slots`;
                    timeSlotStatus.className = 'time-slot-status';
                    
                    // Reset selection
                    selectedTimeInput.value = '';
                    submitButton.disabled = true;
                } else {
                    availabilityText.innerHTML = 'No time slots available for this date';
                    timeSlotStatus.className = 'warning-note';
                }
                
            } catch (error) {
                console.error('Error updating time slots:', error);
                availabilityText.innerHTML = 'Error loading time slots';
                timeSlotStatus.className = 'error-note';
            }
        }

        // Function to select a time slot
        function selectTimeSlot(time, element) {
            // Remove selected class from all time slots
            document.querySelectorAll('.time-slot').forEach(slot => {
                slot.classList.remove('selected');
            });
            
            // Add selected class to clicked time slot
            element.classList.add('selected');
            
            // Set the selected time
            selectedTimeInput.value = time;
            
            // Update availability text
            availabilityText.innerHTML = `Selected: ${element.querySelector('.time-label').textContent}`;
            
            // Enable submit button
            submitButton.disabled = false;
        }

        // Function to show error popup
        function showError(message) {
            errorPopupMessage.textContent = message;
            errorPopup.style.display = 'flex';
        }

        // Function to close popup
        function closePopup() {
            errorPopup.style.display = 'none';
        }

        // Update time slots when date changes
        dateInput.addEventListener('change', function() {
            updateTimeSlots(this.value);
        });

        // Add animation to form on load
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                container.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
            
            // Add click events to initial time slots
            document.querySelectorAll('.time-slot.available').forEach(slot => {
                slot.addEventListener('click', function() {
                    const time = this.getAttribute('data-time');
                    selectTimeSlot(time, this);
                });
            });
            
            // Close popup when clicking outside
            errorPopup.addEventListener('click', function(e) {
                if (e.target === errorPopup) {
                    closePopup();
                }
            });
        });
    </script>
</body>
</html>