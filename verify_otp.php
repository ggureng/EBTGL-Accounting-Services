<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'db_connection.php';

$data = json_decode(file_get_contents("php://input"), true);
$otp = $data['otp'] ?? '';
$password = $data['password'] ?? '';

if (!$otp || !$password) {
  echo json_encode(['success' => false, 'message' => 'Missing OTP or password']);
  exit;
}

// ✅ Step 1: Get the most recent matching OTP that’s still valid (within 10 minutes)
$stmt = $pdo->prepare("
  SELECT email FROM password_reset_tokens
  WHERE otp = ? AND created_at >= (NOW() - INTERVAL 10 MINUTE)
  ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$otp]);
$row = $stmt->fetch();

if (!$row) {
  echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
  exit;
}

$email = $row['email'];
$newPassword = $password; // 👈 storing as plain text for now

// ✅ Step 2: Check if the email exists in the client table
$stmt = $pdo->prepare("SELECT id FROM client WHERE Email = ?");
$stmt->execute([$email]);
$client = $stmt->fetch();

if (!$client) {
  echo json_encode(['success' => false, 'message' => 'No account found for this email.']);
  exit;
}

// ✅ Step 3: Update the password only (do not touch username or other fields)
$update = $pdo->prepare("UPDATE client SET Password = ? WHERE Email = ?");
$success = $update->execute([$newPassword, $email]);

if ($success) {
  // ✅ Step 4: Delete all used OTPs for that email
  $pdo->prepare("DELETE FROM password_reset_tokens WHERE email = ?")->execute([$email]);

  echo json_encode(['success' => true, 'message' => 'Password successfully reset. You may now log in.']);
} else {
  echo json_encode(['success' => false, 'message' => 'Password reset failed. Please try again.']);
}
