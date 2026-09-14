<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die("User not logged in.");
}

$user_id = $_SESSION['user_id'];

// Delete only this user's past schedules
$today = date('Y-m-d');
$pdo->prepare("DELETE FROM schedules WHERE user_id = ? AND schedule_date < ?")->execute([$user_id, $today]);

$highlight_date = $_GET['date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("SELECT * FROM schedules WHERE user_id = ? AND schedule_date = ?");
$stmt->execute([$user_id, $highlight_date]);
$schedules = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Schedule Calendar - EBTGL Accounting</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #4682B4; /* SteelBlue */
      --secondary: #5a96cf; /* Accent Blue */
      --accent: #2a5a80; /* Dark Blue */
      --light: #f8f9fa; /* Light Gray */
      --dark: #2a5a80; /* Dark Blue */
      --card-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
      --transition: all 0.3s ease;
      --success: #28a745;
      --error: #dc3545;
      --warning: #ffc107;
    }
    
    * {
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #2a5a80, #4682B4, #2a5a80);
      background-size: 400% 400%;
      animation: gradientBG 15s ease infinite;
      min-height: 100vh;
      padding: 20px;
      color: #333;
      margin: 0;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    
    @keyframes gradientBG {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }
    
    .calendar-container {
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
    
    .calendar-container:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
    }
    
    .calendar-header {
      background: linear-gradient(135deg, var(--accent), var(--dark));
      color: white;
      padding: 30px;
      text-align: center;
      position: relative;
    }
    
    .calendar-header h2 {
      font-size: 2.2rem;
      margin-bottom: 10px;
      font-family: 'Montserrat', sans-serif;
      font-weight: 700;
      word-wrap: break-word;
    }
    
    .highlight {
      font-weight: bold;
      color: #b0c4de; /* Light blue highlight */
      text-shadow: 0 0 5px rgba(176, 196, 222, 0.5);
      display: inline-block;
      max-width: 100%;
    }
    
    .calendar-content {
      padding: 40px;
    }
    
    .schedule-entry {
      padding: 20px;
      margin-top: 20px;
      border-radius: 12px;
      background: #f0f8ff;
      border-left: 5px solid var(--primary);
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
      transition: var(--transition);
      overflow: hidden;
    }
    
    .schedule-entry:hover {
      transform: translateX(5px);
      box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
    }
    
    .schedule-entry p {
      margin: 8px 0;
      font-size: 1.05rem;
      word-wrap: break-word;
    }
    
    .schedule-entry strong {
      color: var(--dark);
    }
    
    .rebook-btn {
      margin-top: 15px;
      padding: 10px 20px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 4px 10px rgba(70, 130, 180, 0.3);
      display: flex;
      align-items: center;
      gap: 8px;
      width: 100%;
      justify-content: center;
    }
    
    .rebook-btn:hover {
      background: linear-gradient(135deg, var(--dark), var(--primary));
      transform: translateY(-3px);
      box-shadow: 0 6px 15px rgba(70, 130, 180, 0.4);
    }
    
    .no-schedules {
      text-align: center;
      padding: 30px;
      color: #666;
      font-size: 1.1rem;
    }
    
    .back-button {
      display: block;
      width: fit-content;
      margin: 40px auto 0;
      padding: 14px 30px;
      background: linear-gradient(135deg, var(--accent), var(--dark));
      color: white;
      text-align: center;
      text-decoration: none;
      border-radius: 10px;
      font-weight: 600;
      transition: var(--transition);
      box-shadow: 0 4px 12px rgba(42, 90, 128, 0.2);
      max-width: 100%;
    }
    
    .back-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 15px rgba(42, 90, 128, 0.3);
      background: linear-gradient(135deg, #1f4a6d, var(--dark));
    }
    
    .back-button i {
      margin-right: 8px;
    }

    .overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 20px;
    }

    .popup {
      background: #fff;
      padding: 30px;
      border-radius: 16px;
      width: 100%;
      max-width: 450px;
      box-shadow: 0 15px 40px rgba(0,0,0,0.2);
      position: relative;
      max-height: 90vh;
      overflow-y: auto;
    }

    .popup h3 {
      margin-top: 0;
      color: var(--dark);
      font-size: 1.8rem;
      text-align: center;
      margin-bottom: 25px;
      font-family: 'Montserrat', sans-serif;
      word-wrap: break-word;
    }

    .popup .close {
      position: absolute;
      right: 20px;
      top: 20px;
      font-size: 24px;
      color: #888;
      cursor: pointer;
      transition: var(--transition);
      z-index: 1;
    }

    .popup .close:hover {
      color: var(--dark);
      transform: rotate(90deg);
    }

    .popup label {
      display: block;
      margin-bottom: 10px;
      font-weight: 600;
      color: var(--dark);
      font-size: 1.05rem;
      display: flex;
      align-items: center;
      gap: 10px;
      word-wrap: break-word;
    }

    .popup input, .popup select {
      width: 100%;
      padding: 14px 20px;
      border: 2px solid #e1e5eb;
      border-radius: 12px;
      font-size: 1.05rem;
      font-family: 'Poppins', sans-serif;
      transition: var(--transition);
      background-color: var(--light);
      margin-bottom: 20px;
    }

    .popup input:focus, .popup select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(70, 130, 180, 0.2);
    }

    .popup button[type="submit"] {
      width: 100%;
      padding: 16px;
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

    .popup button[type="submit"]:hover {
      background: linear-gradient(135deg, var(--dark), var(--primary));
      transform: translateY(-3px);
      box-shadow: 0 6px 20px rgba(70, 130, 180, 0.4);
    }

    /* Message Popup Styles */
    .message-popup {
      background: #fff;
      padding: 30px;
      border-radius: 16px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 15px 40px rgba(0,0,0,0.2);
      position: relative;
      text-align: center;
    }

    .message-popup .icon {
      font-size: 3.5rem;
      margin-bottom: 20px;
    }

    .message-popup.success .icon {
      color: var(--success);
    }

    .message-popup.error .icon {
      color: var(--error);
    }

    .message-popup.warning .icon {
      color: var(--warning);
    }

    .message-popup h3 {
      margin-top: 0;
      margin-bottom: 15px;
      color: var(--dark);
      font-size: 1.8rem;
      word-wrap: break-word;
    }

    .message-popup p {
      font-size: 1.1rem;
      margin-bottom: 25px;
      line-height: 1.5;
      word-wrap: break-word;
    }

    .message-popup .ok-btn {
      padding: 12px 30px;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 1.1rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
    }

    .message-popup .ok-btn:hover {
      background: linear-gradient(135deg, var(--dark), var(--primary));
      transform: translateY(-2px);
    }

    /* Time slot styles */
    .time-slot {
      padding: 12px;
      margin: 8px 0;
      border-radius: 8px;
      cursor: pointer;
      transition: var(--transition);
      border: 2px solid #e1e5eb;
      width: 100%;
      text-align: center;
      font-weight: 500;
    }

    .time-slot.available {
      background-color: #f0f8ff;
      border-color: var(--primary);
    }

    .time-slot.available:hover {
      background-color: #e1f0ff;
      transform: translateY(-2px);
    }

    .time-slot.unavailable {
      background-color: #f8f9fa;
      color: #6c757d;
      cursor: not-allowed;
      border-color: #dee2e6;
      opacity: 0.7;
    }

    .time-slot.selected {
      background-color: var(--primary);
      color: white;
      border-color: var(--dark);
      transform: scale(1.02);
    }

    .time-slots-container {
      max-height: 200px;
      overflow-y: auto;
      margin-bottom: 20px;
      border: 2px solid #e1e5eb;
      border-radius: 12px;
      padding: 15px;
    }

    .availability-info {
      font-size: 0.9rem;
      color: #666;
      margin-bottom: 15px;
      text-align: center;
      word-wrap: break-word;
    }

    .loading {
      text-align: center;
      padding: 20px;
      color: #666;
    }

    @media (max-width: 768px) {
      .calendar-container {
        margin: 10px;
      }
      
      .calendar-header {
        padding: 25px 20px;
      }
      
      .calendar-header h2 {
        font-size: 1.8rem;
      }
      
      .calendar-content {
        padding: 30px 20px;
      }
      
      .popup {
        padding: 25px 20px;
      }
    }
    
    @media (max-width: 480px) {
      body {
        padding: 10px;
      }
      
      .calendar-header h2 {
        font-size: 1.5rem;
      }
      
      .back-button {
        padding: 12px 25px;
        font-size: 1rem;
        width: 100%;
        text-align: center;
      }
      
      .popup h3 {
        font-size: 1.5rem;
      }
      
      .popup {
        padding: 20px 15px;
      }
    }
  </style>
</head>
<body>
<div class="calendar-container">
  <div class="calendar-header">
    <h2>Your Schedule for <span class="highlight"><?= htmlspecialchars($highlight_date) ?></span></h2>
  </div>
  
  <div class="calendar-content">
    <?php if ($schedules): ?>
      <?php foreach ($schedules as $entry): ?>
        <div class="schedule-entry">
          <p><strong><i class="fas fa-building"></i> Company:</strong> <?= htmlspecialchars($entry['company_name']) ?></p>
          <p><strong><i class="fas fa-clock"></i> Time:</strong> <?= htmlspecialchars(substr($entry['schedule_time'], 0, 5)) ?></p>
          <button class="rebook-btn" onclick="openRebookPopup(<?= $entry['id'] ?>)">
            <i class="fas fa-calendar-plus"></i> Rebook Appointment
          </button>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="no-schedules">
        <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 15px; color: #b0c4de;"></i>
        <p>No schedules for this date.</p>
      </div>
    <?php endif; ?>

    <a class="back-button" href="index1.html">
      <i class="fas fa-arrow-left"></i> Go Back to Home
    </a>
  </div>
</div>

<!-- Rebook Popup -->
<div class="overlay" id="rebookOverlay">
  <div class="popup">
    <span class="close" onclick="closeRebookPopup()">&times;</span>
    <h3><i class="fas fa-calendar-alt"></i> Rebook Schedule</h3>
    <form id="rebookForm" method="POST" action="rebook_schedule.php">
      <input type="hidden" name="schedule_id" id="popupScheduleId">
      
      <label for="newDate"><i class="fas fa-calendar-day"></i> New Date:</label>
      <input type="date" name="new_date" id="newDate" required min="<?= date('Y-m-d') ?>">

      <div class="availability-info" id="availabilityInfo">
        Select a date to see available time slots
      </div>

      <label for="newTime"><i class="fas fa-clock"></i> New Time:</label>
      <div class="time-slots-container" id="timeSlotsContainer">
        <div class="loading">Please select a date first</div>
      </div>
      <input type="hidden" name="new_time" id="selectedTime" required>

      <button type="submit" id="rebookSubmitBtn">Confirm Rebooking</button>
    </form>
  </div>
</div>

<!-- Message Popup -->
<div class="overlay" id="messageOverlay">
  <div class="message-popup" id="messagePopup">
    <div class="icon" id="messageIcon">
      <i class="fas fa-check-circle"></i>
    </div>
    <h3 id="messageTitle">Success</h3>
    <p id="messageText">Your appointment has been successfully rescheduled!</p>
    <button class="ok-btn" onclick="closeMessagePopup()">OK</button>
  </div>
</div>

<script>
let currentSelectedDate = '';
let currentSelectedTime = '';

function openRebookPopup(scheduleId) {
  document.getElementById('popupScheduleId').value = scheduleId;
  document.getElementById('rebookOverlay').style.display = 'flex';
  
  // Set default date to today and load time slots
  const dateInput = document.getElementById("newDate");
  const today = new Date().toISOString().split('T')[0];
  dateInput.setAttribute('min', today);
  dateInput.value = today;
  
  // Load time slots for today
  loadTimeSlots(today);
}

function closeRebookPopup() {
  document.getElementById('rebookOverlay').style.display = 'none';
  resetTimeSlots();
}

function closeMessagePopup() {
  document.getElementById('messageOverlay').style.display = 'none';
  // Reload the page to show updated schedules if it was a success
  if (document.getElementById('messagePopup').classList.contains('success')) {
    window.location.reload();
  }
}

function showMessage(type, title, message) {
  const popup = document.getElementById('messagePopup');
  const icon = document.getElementById('messageIcon');
  const titleEl = document.getElementById('messageTitle');
  const textEl = document.getElementById('messageText');
  
  // Remove all classes and add the appropriate one
  popup.className = 'message-popup ' + type;
  titleEl.textContent = title;
  textEl.textContent = message;
  
  // Set appropriate icon
  icon.innerHTML = '';
  if (type === 'success') {
    icon.innerHTML = '<i class="fas fa-check-circle"></i>';
  } else if (type === 'error') {
    icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
  } else if (type === 'warning') {
    icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
  }
  
  document.getElementById('messageOverlay').style.display = 'flex';
}

function loadTimeSlots(date) {
  if (currentSelectedDate === date) return;
  
  currentSelectedDate = date;
  const container = document.getElementById('timeSlotsContainer');
  const info = document.getElementById('availabilityInfo');
  
  // Show loading
  container.innerHTML = '<div class="loading">Loading available time slots...</div>';
  info.textContent = 'Loading availability...';
  
  fetch(`check_availability.php?date=${date}`)
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      if (data.error) {
        container.innerHTML = '<div class="loading">Error loading time slots</div>';
        info.textContent = 'Could not load availability';
        return;
      }
      
      container.innerHTML = '';
      let availableCount = 0;
      
      data.time_slots.forEach(slot => {
        const timeSlot = document.createElement('div');
        timeSlot.className = `time-slot ${slot.available ? 'available' : 'unavailable'}`;
        timeSlot.textContent = slot.display;
        
        if (slot.available) {
          availableCount++;
          timeSlot.addEventListener('click', () => selectTimeSlot(slot.value, slot.display, timeSlot));
        } else {
          timeSlot.title = 'This time slot is fully booked';
        }
        
        container.appendChild(timeSlot);
      });
      
      // Update availability info
      if (availableCount === 0) {
        info.textContent = 'No time slots available for this date';
        info.style.color = '#dc3545';
      } else {
        info.textContent = `${availableCount} time slot(s) available for ${date}`;
        info.style.color = '#28a745';
      }
      
      // Reset selected time
      document.getElementById('selectedTime').value = '';
      currentSelectedTime = '';
    })
    .catch(error => {
      console.error('Error loading time slots:', error);
      container.innerHTML = '<div class="loading">Error loading time slots. Please try again.</div>';
      info.textContent = 'Error loading availability';
    });
}

function selectTimeSlot(time, display, element) {
  // Remove selected class from all time slots
  document.querySelectorAll('.time-slot').forEach(slot => {
    slot.classList.remove('selected');
  });
  
  // Add selected class to clicked time slot
  element.classList.add('selected');
  
  // Store selected time
  document.getElementById('selectedTime').value = time;
  currentSelectedTime = time;
}

function resetTimeSlots() {
  currentSelectedDate = '';
  currentSelectedTime = '';
  document.getElementById('selectedTime').value = '';
  document.getElementById('timeSlotsContainer').innerHTML = '<div class="loading">Please select a date first</div>';
  document.getElementById('availabilityInfo').textContent = 'Select a date to see available time slots';
  document.getElementById('availabilityInfo').style.color = '#666';
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
  // Date change listener
  document.getElementById('newDate').addEventListener('change', function() {
    loadTimeSlots(this.value);
  });
  
  // Form submission handler
  document.getElementById('rebookForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!currentSelectedTime) {
      showMessage('error', 'Time Required', 'Please select an available time slot');
      return;
    }
    
    const formData = new FormData(this);
    const submitBtn = document.getElementById('rebookSubmitBtn');
    
    // Disable button to prevent multiple submissions
    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing...';
    
    fetch('rebook_schedule.php', {
      method: 'POST',
      body: formData
    })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        showMessage('success', 'Success', data.message);
        closeRebookPopup();
      } else {
        showMessage('error', 'Error', data.message);
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showMessage('error', 'Network Error', 'An error occurred while processing your request. Please check your connection and try again.');
    })
    .finally(() => {
      // Re-enable button
      submitBtn.disabled = false;
      submitBtn.textContent = 'Confirm Rebooking';
    });
  });
  
  // Add animation to container on load
  const container = document.querySelector('.calendar-container');
  container.style.opacity = '0';
  container.style.transform = 'translateY(20px)';
  
  setTimeout(() => {
    container.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    container.style.opacity = '1';
    container.style.transform = 'translateY(0)';
  }, 100);
});
</script>
</body>
</html>