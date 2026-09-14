<?php
// signup.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Custom exception class for better error handling
class SignupException extends Exception {
    public function __construct($message, $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
    
    public function toAlert() {
        return "<script>
            alert('" . addslashes($this->getMessage()) . "');
            window.history.back();
        </script>";
    }
}

$servername = "sql201.ezyro.com";
$username = "ezyro_39028485";
$password = "pogiako09";
$dbname = "ezyro_39028485_client_info";

try {
    // Connect to database
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new SignupException("Database connection failed. Please try again later.");
    }

    // Validation functions
    function isValidName($input) {
        // Allow letters, numbers, spaces, commas, periods, apostrophes, hyphens, ampersands
        return preg_match("/^[a-zA-Z0-9\s.,'\-&]+$/", $input);
    }

    function isValidLettersOnly($input) {
        return preg_match("/^[a-zA-Z\s]+$/", $input);
    }

    function isValidPHPhone($input) {
        return preg_match("/^09\d{9}$/", $input) || $input === '';
    }

    // Validate and sanitize input
    $requiredFields = ['email', 'username', 'password', 'company_name', 'full_name', 'position'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            throw new SignupException("$field is required.");
        }
    }

    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $user = htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8');
    $pass = htmlspecialchars($_POST['password'], ENT_QUOTES, 'UTF-8');
    $company = htmlspecialchars($_POST['company_name'], ENT_QUOTES, 'UTF-8');
    $phone = isset($_POST['phone']) ? htmlspecialchars($_POST['phone'], ENT_QUOTES, 'UTF-8') : '';
    $full_name = htmlspecialchars($_POST['full_name'], ENT_QUOTES, 'UTF-8');
    $position = htmlspecialchars($_POST['position'], ENT_QUOTES, 'UTF-8');

    // Escape inputs for database
    $email = $conn->real_escape_string($email);
    $user = $conn->real_escape_string($user);
    $pass = $conn->real_escape_string($pass);
    $company = $conn->real_escape_string($company);
    $phone = $conn->real_escape_string($phone);
    $full_name = $conn->real_escape_string($full_name);
    $position = $conn->real_escape_string($position);

    // Validate inputs
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new SignupException("Invalid email format.");
    }

    if (!isValidName($company)) {
        throw new SignupException("Company name contains invalid characters. Only letters, numbers, spaces, and basic punctuation (.,'-&) are allowed.");
    }

    if (!isValidName($full_name)) {
        throw new SignupException("Full name contains invalid characters. Only letters, numbers, spaces, and basic punctuation (.,'-&) are allowed.");
    }

    if (!isValidLettersOnly($position)) {
        throw new SignupException("Position should contain only letters and spaces.");
    }

    if (!isValidPHPhone($phone)) {
        throw new SignupException("Phone must be 11 digits starting with 09 or left blank.");
    }

    // File upload handling
    $permit_file = '';
    if (isset($_FILES['permit']) && $_FILES['permit']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'documents/permits/';  // Added trailing slash
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new SignupException("Could not create upload directory.");
            }
        }
        
        // Validate file type
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        $file_type = $_FILES['permit']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            throw new SignupException("Only PDF, JPG, and PNG files are allowed.");
        }
        
        // Generate filename using company name
        $sanitized_company = preg_replace("/[^A-Za-z0-9]/", '_', $company);
        $extension = pathinfo($_FILES['permit']['name'], PATHINFO_EXTENSION);
        $file_name = $sanitized_company . '_permit.' . $extension;
        $target_path = $upload_dir . $file_name;
        
        // Add timestamp if file exists to prevent overwriting
        if (file_exists($target_path)) {
            $file_name = $sanitized_company . '_permit_' . time() . '.' . $extension;
            $target_path = $upload_dir . $file_name;
        }
        
        if (!move_uploaded_file($_FILES['permit']['tmp_name'], $target_path)) {
            throw new SignupException("Error uploading permit file. Please try again.");
        }
        $permit_file = $conn->real_escape_string($target_path);
    } else {
        throw new SignupException("Permit file is required.");
    }

    // Check if email exists
    $sql = "SELECT * FROM client WHERE Email = '$email'";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new SignupException("Database query error.");
    }

    if ($result->num_rows > 0) {
        // Update existing client
        $update = "UPDATE client SET 
                    `Company Name` = '$company',
                    Phone = '$phone',
                    Position = '$position',
                    `Full Name` = '$full_name',
                    `Date` = CURDATE(),
                    Username = '$user',
                    Password = '$pass',
                    permit_file = '$permit_file',
                    status = 'pending'
                   WHERE Email = '$email'";
        
        if (!$conn->query($update)) {
            throw new SignupException("Error updating account. Please try again.");
        }
        
        echo "<script>
            alert('Account updated and consultation submitted successfully! Please wait for admin approval.');
            window.location.href = 'index.html';
        </script>";
    } else {
        // Insert new client
        $insert = "INSERT INTO client (
                    `Company Name`, 
                    Email, 
                    Phone, 
                    Position, 
                    `Full Name`, 
                    `Date`, 
                    Username, 
                    Password, 
                    permit_file,
                    status
                   ) VALUES (
                    '$company', 
                    '$email', 
                    '$phone', 
                    '$position', 
                    '$full_name', 
                    CURDATE(), 
                    '$user', 
                    '$pass', 
                    '$permit_file',
                    'pending'
                   )";
        
        if (!$conn->query($insert)) {
            throw new SignupException("Error creating account. Please try again.");
        }
        
        echo "<script>
            alert('Account created and consultation submitted successfully! Please wait for admin approval.');
            window.location.href = 'index.html';
        </script>";
    }

} catch (SignupException $e) {
    // Clean up uploaded file if error occurred
    if (isset($target_path)) {
        @unlink($target_path);
    }
    echo $e->toAlert();
} catch (Exception $e) {
    // Generic exception handler
    if (isset($target_path)) {
        @unlink($target_path);
    }
    echo "<script>
        alert('An unexpected error occurred. Please try again later.');
        window.history.back();
    </script>";
    error_log("Unexpected error: " . $e->getMessage());
} finally {
    // Close connection if exists
    if (isset($conn)) {
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .password-container {
            position: relative;
            width: 100%;
        }
        
        .password-container input {
            width: 100%;
            padding-right: 40px;
            box-sizing: border-box;
        }
        
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            background: none;
            border: none;
            padding: 5px;
        }
        
        .toggle-password:hover {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
        }
        
        input, button {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        button[type="submit"] {
            background: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <form id="signupForm" method="POST" action="signup.php" enctype="multipart/form-data">
        <div class="form-group">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" required>
        </div>
        
        <div class="form-group">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required>
        </div>
        
        <div class="form-group">
            <label for="password">Password:</label>
            <div class="password-container">
                <input type="password" id="password" name="password" required>
                <button type="button" class="toggle-password" id="toggleSignupPassword">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
        
        <div class="form-group">
            <label for="company_name">Company Name:</label>
            <input type="text" id="company_name" name="company_name" required>
        </div>
        
        <div class="form-group">
            <label for="full_name">Full Name:</label>
            <input type="text" id="full_name" name="full_name" required>
        </div>
        
        <div class="form-group">
            <label for="position">Position:</label>
            <input type="text" id="position" name="position" required>
        </div>
        
        <div class="form-group">
            <label for="phone">Phone:</label>
            <input type="text" id="phone" name="phone" placeholder="09XXXXXXXXX">
        </div>
        
        <div class="form-group">
            <label for="permit">Permit File (PDF, JPG, PNG):</label>
            <input type="file" id="permit" name="permit" accept=".pdf,.jpg,.jpeg,.png" required>
        </div>
        
        <button type="submit">Sign Up</button>
    </form>

    <script>
        // Toggle password visibility for signup form
        document.getElementById('toggleSignupPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Phone number validation
        document.getElementById('phone').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Form validation
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            const phone = document.getElementById('phone').value;
            const permit = document.getElementById('permit').files[0];
            
            // Validate phone number format if provided
            if (phone && !/^09\d{9}$/.test(phone)) {
                e.preventDefault();
                alert('Phone must be 11 digits starting with 09 or left blank.');
                return;
            }
            
            // Validate file type
            if (permit) {
                const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
                if (!allowedTypes.includes(permit.type)) {
                    e.preventDefault();
                    alert('Only PDF, JPG, and PNG files are allowed.');
                    return;
                }
            }
        });
    </script>
</body>
</html>