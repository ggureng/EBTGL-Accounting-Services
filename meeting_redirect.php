<?php
session_start();
require 'db_connection.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT schedule_date FROM schedules WHERE user_id = ? ORDER BY schedule_date DESC LIMIT 1");
$stmt->execute([$user_id]);
$row = $stmt->fetch();

if ($row) {
    $latest_date = $row['schedule_date'];
    header("Location: calendar.php?date=" . urlencode($latest_date));
} else {
    header("Location: schedule.php");
}
exit;
?>
