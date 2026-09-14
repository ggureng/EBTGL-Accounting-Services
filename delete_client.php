<?php
// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// DB connection
$host = "sql201.ezyro.com";
$username = "ezyro_39028485";
$password = "pogiako09";
$dbname = "ezyro_39028485_client_info";
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$id = $_GET['id'] ?? null;

if (!$id || !is_numeric($id)) {
  die("Invalid ID.");
}

// ✅ Step 1: Get company name and email for this client
$get = $conn->prepare("SELECT `Company Name`, `Email` FROM client WHERE id = ?");
$get->bind_param("i", $id);
$get->execute();
$result = $get->get_result();

if ($result->num_rows === 0) {
  die("Client not found.");
}

$row = $result->fetch_assoc();
$companyName = preg_replace('/[^A-Za-z0-9]/', '_', $row['Company Name']);
$email = $row['Email'];

$transactionsTable = "{$companyName}_Transactions";
$accountsTable = "{$companyName}_Accounts";

// ✅ Step 2: Drop transaction and account tables
$conn->query("DROP TABLE IF EXISTS `$transactionsTable`");
$conn->query("DROP TABLE IF EXISTS `$accountsTable`");

// ✅ Step 3: Delete message history
$conn->query("DELETE FROM messages WHERE sender_id = $id OR recipient_id = $id");

// ✅ Step 4: Delete client record
$stmt = $conn->prepare("DELETE FROM client WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
  header("Location: manage-client.php?message=deleted");
  exit();
} else {
  echo "Error deleting client: " . $conn->error;
}

$stmt->close();
$conn->close();
?>
