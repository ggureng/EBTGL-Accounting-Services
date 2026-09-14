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
$success = false;

if (!$id || !is_numeric($id)) {
  die("Invalid client ID.");
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $company = $_POST['company_name'];
  $email = $_POST['email'];
  $phone = $_POST['phone'];
  $position = $_POST['position'];
  $full_name = $_POST['full_name'];

  $stmt = $conn->prepare("UPDATE client SET `Company Name`=?, Email=?, Phone=?, Position=?, `Full Name`=? WHERE id=?");
  $stmt->bind_param("sssssi", $company, $email, $phone, $position, $full_name, $id);

  if ($stmt->execute()) {
    $success = true;
  }

  $stmt->close();
}

// Get existing data
$stmt = $conn->prepare("SELECT `Company Name`, Email, Phone, Position, `Full Name` FROM client WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();

if (!$client) {
  die("Client not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Client</title>
  <style>
    body {
      background: #f2f2f2;
      font-family: 'Segoe UI', sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .form-container {
      background: #fff;
      padding: 30px 40px;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
      max-width: 500px;
      width: 100%;
    }

    h2 {
      text-align: center;
      margin-bottom: 20px;
      color: #333;
    }

    label {
      display: block;
      margin-top: 12px;
      font-weight: bold;
      color: #555;
    }

    input {
      width: 100%;
      padding: 10px;
      margin-top: 6px;
      border: 1px solid #ccc;
      border-radius: 8px;
      font-size: 15px;
    }

    input[type="submit"] {
      background-color: #4682B4;
      color: white;
      font-weight: bold;
      cursor: pointer;
      margin-top: 20px;
      transition: background 0.3s;
    }

    input[type="submit"]:hover {
      background-color: #5a9bd3;
    }

    .success {
      background: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="form-container">
    <h2>Edit Client</h2>

    <?php if ($success): ?>
      <div class="success">✅ Client info updated successfully!</div>
    <?php endif; ?>

    <form method="post">
      <label>Company Name</label>
      <input type="text" name="company_name" value="<?= htmlspecialchars($client['Company Name']) ?>" required>

      <label>Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($client['Email']) ?>" required>

      <label>Phone</label>
      <input type="tel" name="phone" pattern="\d+" title="Only numbers allowed" value="<?= htmlspecialchars($client['Phone']) ?>">

      <label>Position</label>
      <input type="text" name="position" value="<?= htmlspecialchars($client['Position']) ?>" required>

      <label>Full Name</label>
      <input type="text" name="full_name" value="<?= htmlspecialchars($client['Full Name']) ?>" required>

      <input type="submit" value="Save Changes">
    </form>
</body>
</html>

<?php $conn->close(); ?>
