<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Get values
    $company_name = trim($_POST['company_name']);
    $email = trim($_POST['email']);
    $phone = isset($_POST['phone']) && $_POST['phone'] !== '' ? trim($_POST['phone']) : null;
    $position = trim($_POST['position']);
    $full_name = trim($_POST['full_name']);
    $date_today = trim($_POST['date_today']);

    // Validation functions
    function isValidName($input) {
        // Allow letters, numbers, commas, periods, hyphens, ampersands, and spaces
        return preg_match("/^[a-zA-Z0-9\s.,\-&]+$/", $input);
    }

    function isValidLettersOnly($input) {
        return preg_match("/^[a-zA-Z\s]+$/", $input);
    }

    function isValidPHPhone($input) {
        return preg_match("/^09\d{9}$/", $input);
    }

    // Validate inputs
    if (
        !isValidName($company_name) ||
        !isValidName($full_name) ||
        !isValidLettersOnly($position) ||
        ($phone !== null && $phone !== "" && !isValidPHPhone($phone))
    ) {
        die("Invalid input. Please check your fields. Phone must be 11 digits starting with 09 or left blank.");
    }

    // Connect to DB
    $conn = new mysqli("sql201.ezyro.com", "ezyro_39028485", "pogiako09", "ezyro_39028485_client_info");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Handle file upload
    $permitPath = "";
    if (isset($_FILES['permit']) && $_FILES['permit']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "documents/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $originalName = basename($_FILES["permit"]["name"]);
        $safeName = preg_replace("/[^A-Za-z0-9_.-]/", "_", $company_name) . "_" . time() . "_" . $originalName;
        $targetFile = $uploadDir . $safeName;

        if (move_uploaded_file($_FILES["permit"]["tmp_name"], $targetFile)) {
            $permitPath = $targetFile;
        } else {
            die("Failed to upload permit file.");
        }
    }

    $_SESSION['company_name'] = $company_name;

    // Check if email already exists
    $check_sql = "SELECT 1 FROM client WHERE Email = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Email exists: UPDATE
        if ($permitPath === "") {
            $result = $conn->query("SELECT permit_file FROM client WHERE Email = '$email'");
            $existing = $result->fetch_assoc();
            $permitPath = $existing['permit_file'];
        }

        $update_sql = "UPDATE client 
                       SET `Company Name` = ?, Phone = ?, Position = ?, `Full Name` = ?, Date = ?, permit_file = ?, status = 'pending' 
                       WHERE Email = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sssssss", $company_name, $phone, $position, $full_name, $date_today, $permitPath, $email);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Email doesn't exist: INSERT
        $insert_sql = "INSERT INTO client (`Company Name`, Email, Phone, Position, `Full Name`, Date, Username, Password, permit_file, status)
                       VALUES (?, ?, ?, ?, ?, ?, '', '', ?, 'pending')";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("sssssss", $company_name, $email, $phone, $position, $full_name, $date_today, $permitPath);
        $insert_stmt->execute();
        $insert_stmt->close();
    }

    $stmt->close();
    $conn->close();

    echo "<script>
        alert('Your consultation request has been submitted. Please wait for admin approval.');
        window.location.href = 'index.html';
    </script>";
}
?>
