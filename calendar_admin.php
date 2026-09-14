<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Connect to DB
$host = "sql201.ezyro.com";
$db = "ezyro_39028485_client_info";
$user = "ezyro_39028485";
$pass = "pogiako09";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// DELETE PAST APPOINTMENTS (MINIMUM 1 HOUR OLD)
$deleteQuery = "DELETE FROM schedules 
                WHERE CONCAT(schedule_date, ' ', schedule_time) < 
                      DATE_SUB(NOW(), INTERVAL 1 HOUR)";
$conn->query($deleteQuery);

// Fetch all schedules (no admin filtering)
$query = "SELECT schedule_date, schedule_time, company_name FROM schedules";
$result = $conn->query($query);

$events = [];
$today = date('Y-m-d');
$todayCount = 0;
$weekCount = 0;
$upcomingEvents = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $date = $row['schedule_date'];
        $time = $row['schedule_time'];
        $start = $time ? "{$date}T{$time}" : $date;
        
        $event = [
            'title' => $row['company_name'],
            'start' => $start
        ];
        
        $events[] = $event;
        
        // Count today's appointments
        if ($date === $today) {
            $todayCount++;
        }
        
        // Count upcoming this week (next 7 days)
        $eventDate = strtotime($date);
        $todayTime = strtotime($today);
        $nextWeek = strtotime('+7 days', $todayTime);
        
        if ($eventDate >= $todayTime && $eventDate <= $nextWeek) {
            $weekCount++;
        }
        
        // Collect upcoming events (next 7 days)
        if ($eventDate >= $todayTime) {
            $upcomingEvents[] = [
                'date' => $date,
                'time' => $time,
                'company' => $row['company_name']
            ];
        }
    }
}

// Sort upcoming events by date
usort($upcomingEvents, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

// Limit to 5 upcoming events
$upcomingEvents = array_slice($upcomingEvents, 0, 5);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Calendar - JT Accounting Services</title>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #4682B4;
            --light-blue: #b0c4de;
            --accent-blue: #5a96cf;
            --dark-blue: #2a5a80;
            --light-gray: #f8f9fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            height: 100vh;
            display: flex;
            color: #333;
            overflow: hidden;
        }
        
        /* Sidebar styling - WIDER */
        .sidebar {
            width: 320px;
            background: linear-gradient(to bottom, var(--primary-blue), var(--dark-blue));
            padding: 25px 20px;
            color: white;
            display: flex;
            flex-direction: column;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            z-index: 10;
            overflow-y: auto;
        }
        
        .calendar-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }
        
        .calendar-title i {
            background: rgba(255,255,255,0.2);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .calendar-stats {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .stat-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .stat-value {
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        .upcoming-title {
            font-size: 1.2rem;
            margin: 30px 0 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .upcoming-list {
            flex: 1;
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .upcoming-event {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .upcoming-event:hover {
            background: rgba(255,255,255,0.2);
            transform: translateX(5px);
        }
        
        .event-time {
            font-weight: 700;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .event-company {
            font-size: 0.95rem;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--light-blue), var(--accent-blue));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            width: 100%;
            margin-top: 20px;
        }
        
        .btn-back:hover {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Calendar container */
        .calendar-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 25px;
            overflow: hidden;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-blue);
        }
        
        .calendar-header h2 {
            color: var(--dark-blue);
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        /* FullCalendar customizations */
        #calendar {
            flex: 1;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            padding: 25px;
            overflow: hidden;
        }
        
        /* Custom FullCalendar styles */
        .fc .fc-toolbar {
            margin-bottom: 15px;
        }
        
        .fc .fc-toolbar-title {
            font-size: 1.5rem;
            color: var(--dark-blue);
        }
        
        .fc .fc-button {
            background: var(--light-blue);
            border: none;
            color: white;
            text-transform: capitalize;
            border-radius: 6px;
            padding: 8px 15px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .fc .fc-button:hover {
            background: var(--primary-blue);
            transform: translateY(-2px);
        }
        
        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background: var(--primary-blue);
        }
        
        .fc .fc-daygrid-day-number {
            color: var(--dark-blue);
            font-weight: 600;
        }
        
        .fc .fc-daygrid-event {
            background: var(--primary-blue);
            border: none;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 0.9rem;
        }
        
        .fc .fc-daygrid-event-dot {
            display: none;
        }
        
        .fc .fc-day-today {
            background: rgba(176, 196, 222, 0.2) !important;
        }
        
        .fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: var(--primary-blue);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Responsive design */
        @media (max-width: 900px) {
            body {
                flex-direction: column;
                height: auto;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                padding: 20px;
            }
            
            .calendar-container {
                padding: 20px;
            }
            
            .calendar-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 600px) {
            .calendar-title {
                font-size: 1.5rem;
            }
            
            .calendar-header h2 {
                fontSize: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="calendar-title">
            <i class="fas fa-calendar-alt"></i>
            <span>Client Schedule</span>
        </div>
        
        <div class="calendar-stats">
            <div class="stat-item">
                <span>Total Appointments:</span>
                <span class="stat-value"><?php echo count($events); ?></span>
            </div>
            <div class="stat-item">
                <span>Today's Appointments:</span>
                <span class="stat-value"><?php echo $todayCount; ?></span>
            </div>
            <div class="stat-item">
                <span>Upcoming This Week:</span>
                <span class="stat-value"><?php echo $weekCount; ?></span>
            </div>
        </div>
        
        <div class="upcoming-title">
            <i class="fas fa-clock"></i>
            <span>Upcoming Appointments</span>
        </div>
        
        <div class="upcoming-list">
            <?php if (count($upcomingEvents) > 0): ?>
                <?php foreach ($upcomingEvents as $event): 
                    $dateTime = new DateTime($event['date']);
                    $dateStr = $dateTime->format('M j'); // Format: Jun 28
                    
                    $timeStr = '';
                    if (!empty($event['time'])) {
                        $time = DateTime::createFromFormat('H:i:s', $event['time']);
                        if ($time) {
                            $timeStr = $time->format('g:i A'); // Format: 3:45 PM
                        }
                    }
                ?>
                    <div class="upcoming-event">
                        <div class="event-time">
                            <i class="fas fa-calendar-day"></i>
                            <span><?php echo $dateStr; ?><?php echo $timeStr ? ", $timeStr" : ''; ?></span>
                        </div>
                        <div class="event-company">
                            <i class="fas fa-building"></i>
                            <span><?php echo htmlspecialchars($event['company']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="upcoming-event">
                    <div class="event-time">
                        <i class="fas fa-info-circle"></i>
                        <span>No upcoming appointments</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="calendar-container">
        <div class="calendar-header">
            <h2>
                <i class="fas fa-calendar-check"></i>
                Appointment Calendar
            </h2>
        </div>
        
        <div id="calendar"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?php echo json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                eventColor: '#4682B4',
                eventDisplay: 'block',
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                },
                dayMaxEvents: 3,
                navLinks: true,
                nowIndicator: true,
                businessHours: {
                    daysOfWeek: [1, 2, 3, 4, 5],
                    startTime: '08:00',
                    endTime: '18:00'
                }
            });
            calendar.render();
        });
    </script>
</body>
</html>