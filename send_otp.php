<?php
header('Content-Type: application/json');
session_start();
require 'mailer/config.php';
require 'db_connection.php';

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';

if (!$email) {
  echo json_encode(['success' => false, 'message' => 'Email is required.']);
  exit;
}

// Check if email exists
$stmt = $pdo->prepare("SELECT id FROM client WHERE Email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
  echo json_encode(['success' => false, 'message' => 'Email not found.']);
  exit;
}

$otp = rand(100000, 999999);

// Save OTP to database
$pdo->prepare("INSERT INTO password_reset_tokens (email, otp) VALUES (?, ?)")->execute([$email, $otp]);

// Send email
$subject = "🔐 Your OTP - EBGTL Accounting";
$body = "Your one-time password is <b>$otp</b>. It expires in 10 minutes.";

if (sendCustomEmail($email, $subject, $body)) {
  echo json_encode(['success' => true, 'message' => 'OTP sent. Please check your email.']);
} else {
  echo json_encode(['success' => false, 'message' => 'Failed to send email.']);
}
