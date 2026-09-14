<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

header('Content-Type: application/json');

$user = $_POST['username'] ?? '';
$pass = $_POST['password'] ?? '';

// Database connection
$servername = "sql201.ezyro.com";
$dbuser = "ezyro_39028485";
$dbpass = "pogiako09";
$dbname = "ezyro_39028485_client_info";

$conn = new mysqli($servername, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit();
}

$user = $conn->real_escape_string($user);

// 🔐 Step 1: Check admin_account
$stmt = $conn->prepare("SELECT * FROM admin_accounts WHERE username = ?");
$stmt->bind_param("s", $user);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $admin = $result->fetch_assoc();

    // Verify hashed password
    if (password_verify($pass, $admin['password'])) {
        $_SESSION['role'] = 'admin';
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];

        echo json_encode([
            "status" => "success",
            "message" => "Welcome Admin",
            "redirect" => "dashboard.php",
            "role" => "admin"
        ]);
        exit();
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid admin credentials."]);
        exit();
    }
}

// ✅ Step 2: Check client table
$stmt = $conn->prepare("SELECT * FROM client WHERE Username = ? AND Password = ?");
$stmt->bind_param("ss", $user, $pass);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();

    if (strtolower($row['status']) === 'pending') {
        echo json_encode([
            "status" => "pending",
            "message" => "Kindly wait for admin's approval. Thank you for your patience! Have a good day."
        ]);
    } else {
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['company_name'] = $row['Company Name'];

        echo json_encode([
            "status" => "success",
            "message" => "Welcome " . $row['Company Name'],
            "redirect" => "index1.html",
            "role" => "client"
        ]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid username or password."]);
}

$conn->close();
?>