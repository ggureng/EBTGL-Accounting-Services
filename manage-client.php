<?php
// Database connection setup
$host = "sql201.ezyro.com";
$username = "ezyro_39028485";
$password = "pogiako09";
$dbname = "ezyro_39028485_client_info";

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Include audit logging functions
require_once 'audit_logger.php';

// Get current admin info for audit logging
$current_admin = getCurrentAdminInfo();

// Log page access
logAdminAction(
    $current_admin['id'],
    $current_admin['username'],
    'PAGE_ACCESS',
    'Accessed Manage Client Page',
    'manage-client.php',
    null,
    json_encode(['timestamp' => date('Y-m-d H:i:s')])
);

// Check if table has the required columns, if not alter it
$checkColumns = $conn->query("SHOW COLUMNS FROM client LIKE 'inactive_reason'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE client ADD COLUMN inactive_reason TEXT");
}

$checkColumns = $conn->query("SHOW COLUMNS FROM client LIKE 'inactive_date'");
if ($checkColumns->num_rows == 0) {
    $conn->query("ALTER TABLE client ADD COLUMN inactive_date DATE");
}

// Handle client reactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_active'])) {
    $clientId = $_POST['client_id'];
    
    // Get client info before update for audit log
    $clientBefore = $conn->query("SELECT * FROM client WHERE id = $clientId")->fetch_assoc();
    
    // Update client status back to active and clear inactive fields
    $updateStmt = $conn->prepare("UPDATE client SET status='Active', inactive_reason=NULL, inactive_date=NULL WHERE id=?");
    $updateStmt->bind_param("i", $clientId);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Log client reactivation
    logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'CLIENT_REACTIVATED',
        'Reactivated client account',
        'client',
        json_encode([
            'client_id' => $clientId,
            'previous_status' => 'Inactive',
            'inactive_reason' => $clientBefore['inactive_reason'],
            'inactive_date' => $clientBefore['inactive_date']
        ]),
        json_encode([
            'client_id' => $clientId,
            'new_status' => 'Active',
            'company_name' => $clientBefore['Company Name']
        ])
    );
    
    // Refresh page to show updated status
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$sql = "SELECT * FROM client";
$result = $conn->query($sql);

// Handle client inactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_inactive'])) {
    $clientId = $_POST['client_id'];
    $inactiveReason = $_POST['inactive_reason'];
    $otherReason = isset($_POST['other_reason']) ? $_POST['other_reason'] : '';
    
    // Get client info before update for audit log
    $clientBefore = $conn->query("SELECT * FROM client WHERE id = $clientId")->fetch_assoc();
    
    // If "Other" was selected, use the custom reason
    if ($inactiveReason === 'Other' && !empty($otherReason)) {
        $inactiveReason = $otherReason;
    }
    
    // Handle file upload for bankruptcy case
    $bankruptcyFilePath = null;
    if ($inactiveReason === 'Bankruptcy' && isset($_FILES['bankruptcy_file']) && $_FILES['bankruptcy_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "uploads/bankruptcy_docs/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExtension = pathinfo($_FILES['bankruptcy_file']['name'], PATHINFO_EXTENSION);
        $allowedExtensions = ['pdf', 'png', 'jpg'];
        
        if (in_array(strtolower($fileExtension), $allowedExtensions)) {
            $fileName = "bankruptcy_" . $clientId . "_" . time() . "." . $fileExtension;
            $bankruptcyFilePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['bankruptcy_file']['tmp_name'], $bankruptcyFilePath)) {
                // File uploaded successfully
            }
        }
    }
    
    // Update client status and reason
    $updateStmt = $conn->prepare("UPDATE client SET status='Inactive', inactive_reason=?, inactive_date=NOW() WHERE id=?");
    $updateStmt->bind_param("si", $inactiveReason, $clientId);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Log client inactivation
    logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'CLIENT_MARKED_INACTIVE',
        'Marked client as inactive and generated report',
        'client',
        json_encode([
            'client_id' => $clientId,
            'previous_status' => 'Active',
            'company_name' => $clientBefore['Company Name']
        ]),
        json_encode([
            'client_id' => $clientId,
            'new_status' => 'Inactive',
            'inactive_reason' => $inactiveReason,
            'bankruptcy_file' => $bankruptcyFilePath ? basename($bankruptcyFilePath) : null
        ])
    );
    
    // Generate formal report content
    $reportContent = "================================================================================\n";
    $reportContent .= "                      CLIENT INACTIVATION REPORT\n";
    $reportContent .= "                     EBTGL ACCOUNTING SERVICES\n";
    $reportContent .= "================================================================================\n\n";
    
    $reportContent .= "REPORT INFORMATION:\n";
    $reportContent .= "────────────────────────────────────────────────────────────────────────────────\n";
    $reportContent .= "Report Date: " . date('F j, Y') . "\n";
    $reportContent .= "Report Time: " . date('g:i A') . "\n";
    $reportContent .= "Report ID: INACT-" . date('Ymd-His') . "-" . $clientId . "\n\n";
    
    // Get client details for report
    $clientQuery = $conn->prepare("SELECT * FROM client WHERE id=?");
    $clientQuery->bind_param("i", $clientId);
    $clientQuery->execute();
    $clientResult = $clientQuery->get_result();
    
    if ($clientResult->num_rows > 0) {
        $client = $clientResult->fetch_assoc();
        
        $reportContent .= "CLIENT INFORMATION:\n";
        $reportContent .= "────────────────────────────────────────────────────────────────────────────────\n";
        $reportContent .= "Client ID: " . $client['id'] . "\n";
        $reportContent .= "Company Name: " . $client['Company Name'] . "\n";
        $reportContent .= "Contact Person: " . $client['Full Name'] . "\n";
        $reportContent .= "Email Address: " . $client['Email'] . "\n";
        $reportContent .= "Phone Number: " . $client['Phone'] . "\n";
        $reportContent .= "Date Registered: " . $client['Date'] . "\n\n";
        
        $reportContent .= "INACTIVATION DETAILS:\n";
        $reportContent .= "────────────────────────────────────────────────────────────────────────────────\n";
        $reportContent .= "Status: INACTIVE\n";
        $reportContent .= "Inactivation Date: " . date('F j, Y') . "\n";
        $reportContent .= "Reason for Inactivation: " . $inactiveReason . "\n\n";
        
        if ($bankruptcyFilePath) {
            $reportContent .= "SUPPORTING DOCUMENTATION:\n";
            $reportContent .= "────────────────────────────────────────────────────────────────────────────────\n";
            $reportContent .= "Bankruptcy Documentation: " . basename($bankruptcyFilePath) . "\n";
            $reportContent .= "File Location: " . $bankruptcyFilePath . "\n\n";
        }
        
        $reportContent .= "ADDITIONAL NOTES:\n";
        $reportContent .= "────────────────────────────────────────────────────────────────────────────────\n";
        $reportContent .= "This report has been generated in accordance with company policies and procedures.\n";
        $reportContent .= "The client has been officially marked as inactive in our system.\n\n";
        
        $reportContent .= "AUTHORIZATION:\n";
        $reportContent .= "────────────────────────────────────────────────────────────────────────────────\n";
        $reportContent .= "Generated By: EBTGL Accounting Services System\n";
        $reportContent .= "Authorized Signature: __________________________\n";
        $reportContent .= "Date: " . date('F j, Y') . "\n\n";
        
        $reportContent .= "================================================================================\n";
        $reportContent .= "           OFFICIAL DOCUMENT - EBTGL ACCOUNTING SERVICES\n";
        $reportContent .= "           For inquiries, contact: admin@ebtglaccounting.com\n";
        $reportContent .= "================================================================================\n";
    }
    
    $clientQuery->close();
    
    // Save report to file
    $reportsDir = "reports/";
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0777, true);
    }
    
    $filename = "inactive_client_" . $clientId . "_" . date('Y-m-d_His') . ".txt";
    file_put_contents($reportsDir . $filename, $reportContent);
    
    // Log report generation
    logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'INACTIVATION_REPORT_GENERATED',
        'Generated client inactivation report',
        'reports',
        null,
        json_encode([
            'client_id' => $clientId,
            'report_filename' => $filename,
            'report_id' => "INACT-" . date('Ymd-His') . "-" . $clientId
        ])
    );
    
    // Set session variable to show report after redirect
    session_start();
    $_SESSION['generated_report'] = $reportContent;
    $_SESSION['client_id'] = $clientId;
    $_SESSION['client_name'] = $client['Company Name'];
    $_SESSION['client_contact'] = $client['Full Name'];
    $_SESSION['client_email'] = $client['Email'];
    $_SESSION['client_phone'] = $client['Phone'];
    $_SESSION['client_registered'] = $client['Date'];
    $_SESSION['inactive_reason'] = $inactiveReason;
    $_SESSION['bankruptcy_file'] = $bankruptcyFilePath ? basename($bankruptcyFilePath) : null;
    $_SESSION['report_id'] = "INACT-" . date('Ymd-His') . "-" . $clientId;
    
    // Refresh page to show updated status
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Check if we need to show a generated report
session_start();
if (isset($_SESSION['generated_report'])) {
    $generatedReport = $_SESSION['generated_report'];
    $reportClientId = $_SESSION['client_id'];
    $reportClientName = $_SESSION['client_name'];
    $reportClientContact = $_SESSION['client_contact'];
    $reportClientEmail = $_SESSION['client_email'];
    $reportClientPhone = $_SESSION['client_phone'];
    $reportClientRegistered = $_SESSION['client_registered'];
    $reportReason = $_SESSION['inactive_reason'];
    $reportBankruptcyFile = $_SESSION['bankruptcy_file'] ?? null;
    $reportId = $_SESSION['report_id'];
    
    // Clear the session variables
    unset($_SESSION['generated_report']);
    unset($_SESSION['client_id']);
    unset($_SESSION['client_name']);
    unset($_SESSION['client_contact']);
    unset($_SESSION['client_email']);
    unset($_SESSION['client_phone']);
    unset($_SESSION['client_registered']);
    unset($_SESSION['inactive_reason']);
    unset($_SESSION['bankruptcy_file']);
    unset($_SESSION['report_id']);
}

// Get documents from directories
$contractsDir = "uploads/attachments/";
$permitsDir = "documents/permits/";

// Get contract files (only files containing 'contract' and 'sent_by_client' in the name)
$contractsFiles = [];
if (is_dir($contractsDir)) {
  $files = scandir($contractsDir);
  foreach ($files as $file) {
    if ($file != '.' && $file != '..' && !is_dir($contractsDir . $file)) {
      // Convert to lowercase for case-insensitive matching
      $lowercaseFile = strtolower($file);
      // Check if filename contains both 'contract' and 'sent_by_client'
      if (strpos($lowercaseFile, 'contract') !== false && 
          strpos($lowercaseFile, 'sent_by_client') !== false) {
        $contractsFiles[] = $file;
      }
    }
  }
}

// Get permit files
$permitsFiles = [];
if (is_dir($permitsDir)) {
  $files = scandir($permitsDir);
  foreach ($files as $file) {
    if ($file != '.' && $file != '..' && !is_dir($permitsDir . $file)) {
      $permitsFiles[] = $file;
    }
  }
}

// Handle file deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contract'])) {
  $filename = $_POST['filename'];
  $filepath = $contractsDir . $filename;
  
  // Verify file exists and has required keywords
  $lowercaseFile = strtolower($filename);
  if (file_exists($filepath)) {
    if (strpos($lowercaseFile, 'contract') !== false && 
        strpos($lowercaseFile, 'sent_by_client') !== false) {
      if (unlink($filepath)) {
        // Log contract deletion
        logAdminAction(
            $current_admin['id'],
            $current_admin['username'],
            'CONTRACT_DELETED',
            'Deleted client contract file',
            'contracts',
            json_encode(['filename' => $filename]),
            null
        );
        
        // Remove file from the list
        $key = array_search($filename, $contractsFiles);
        if ($key !== false) {
          unset($contractsFiles[$key]);
          $contractsFiles = array_values($contractsFiles); // Reindex array
        }
      }
    }
  }
}

// Check if this is being loaded in a modal
$isModal = isset($_GET['modal']) && $_GET['modal'] == 'true';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Clients - EBTGL Accounting Services</title>
  
  <!-- DataTables CSS & JS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  
  <style>
    :root {
      --primary-blue: #4682B4;
      --light-blue: #b0c4de;
      --accent-blue: #5a96cf;
      --dark-blue: #3a6a94;
      --background: #f8f9fa;
    }
    
    body {
      background: <?php echo $isModal ? 'transparent' : 'linear-gradient(to bottom, #f8f9fa, #e9ecef)'; ?>;
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      <?php if (!$isModal): ?>
      padding-top: 20px;
      <?php endif; ?>
      margin: 0;
    }
    
    /* Navbar styling to match dashboard */
    <?php if (!$isModal): ?>
    .navbar {
      background: linear-gradient(135deg, #6a8fbb, #b0c4de);
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      position: fixed;
      top: 0;
      width: 100%;
      z-index: 1000;
      padding: 10px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      height: 60px;
    }
    
    .navbar-brand {
      display: flex;
      align-items: center;
      font-weight: bold;
      color: white;
      text-decoration: none;
      font-size: 1.2rem;
    }
    
    .navbar-brand img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 10px;
      border: 2px solid white;
    }
    
    .nav-links {
      display: flex;
      gap: 20px;
    }
    
    .nav-links a {
      color: white;
      text-decoration: none;
      font-weight: 500;
      padding: 8px 15px;
      border-radius: 20px;
      transition: all 0.3s ease;
    }
    
    .nav-links a:hover {
      background-color: rgba(255, 255, 255, 0.2);
    }
    
    .nav-links a.active {
      background-color: rgba(255, 255, 255, 0.3);
    }
    <?php endif; ?>
    
    /* Main container */
    .container-main {
      max-width: 1400px;
      margin: 20px auto;
      padding: 0 20px;
    }
    
    /* Header section */
    .header-section {
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid var(--light-blue);
      position: relative;
    }
    
    .header-section h1 {
      color: var(--dark-blue);
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 10px;
      font-size: 1.8rem;
    }
    
    .header-section h1 i {
      background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
      width: 50px;
      height: 50px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
    }
    
    .header-section p {
      color: #666;
      font-size: 1.1rem;
      max-width: 800px;
      margin-top: 5px;
    }
    
    /* Stats section */
    .stats-container {
      display: flex;
      gap: 20px;
      margin-bottom: 25px;
      flex-wrap: wrap;
    }
    
    .stat-card {
      flex: 1;
      min-width: 200px;
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.05);
      display: flex;
      align-items: center;
      gap: 15px;
      transition: all 0.3s ease;
      cursor: pointer;
    }
    
    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 20px rgba(0,0,0,0.08);
      background: #f0f7ff;
    }
    
    .stat-icon {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--light-blue), var(--accent-blue));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
    }
    
    .stat-content h3 {
      margin: 0;
      font-size: 24px;
      color: var(--dark-blue);
      font-weight: 700;
    }
    
    .stat-content p {
      margin: 5px 0 0;
      color: #666;
      font-size: 14px;
    }
    
    /* Back button */
    .btn-back {
      background: linear-gradient(135deg, #a9a9a9, #808080);
      border: none;
      border-radius: 8px;
      padding: 10px 25px;
      color: white;
      font-weight: 600;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      margin-bottom: 20px;
    }
    
    .btn-back:hover {
      background: linear-gradient(135deg, #808080, #5a5a5a);
      color: white;
      transform: translateY(-2px);
    }
    
    /* Status filter buttons */
    .status-filters {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
    }
    
    .status-btn {
      padding: 10px 25px;
      border-radius: 8px;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .status-btn.active {
      background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
      color: white;
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    .status-btn.inactive {
      background: linear-gradient(135deg, #a9a9a9, #808080);
      color: white;
    }
    
    .status-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    /* Table styling */
    #clientTable_wrapper {
      background: white;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.08);
      padding: 20px;
      margin-bottom: 30px;
    }
    
    .dataTables_filter {
      margin-bottom: 15px;
    }
    
    .dataTables_filter label {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .dataTables_filter input {
      padding: 8px 15px;
      border-radius: 8px;
      border: 1px solid #ddd;
      font-size: 16px;
    }
    
    .dataTables_length select {
      padding: 5px 10px;
      border-radius: 8px;
      border: 1px solid #ddd;
    }
    
    #clientTable {
      width: 100% !important;
      border-collapse: separate;
      border-spacing: 0;
    }
    
    #clientTable thead th {
      background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
      color: white;
      padding: 15px;
      text-align: left;
      border: none;
    }
    
    #clientTable tbody tr {
      background-color: white;
      transition: all 0.2s ease;
    }
    
    #clientTable tbody tr:hover {
      background-color: #f0f7ff;
    }
    
    #clientTable tbody td {
      padding: 12px 15px;
      border-bottom: 1px solid #eee;
      color: #555;
    }
    
    #clientTable tbody tr:last-child td {
      border-bottom: none;
    }
    
    /* Status indicator */
    .status-indicator {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      margin-right: 8px;
    }
    
    .status-active {
      background-color: #28a745;
    }
    
    .status-inactive {
      background-color: #dc3545;
    }
    
    /* Action buttons */
    .action-btns {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    
    .btn-edit, .btn-inactive, .btn-reactive {
      padding: 6px 12px;
      border-radius: 6px;
      font-weight: 500;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 5px;
      text-decoration: none;
      transition: all 0.2s ease;
      border: none;
      cursor: pointer;
    }
    
    .btn-edit {
      background-color: #e6f2ff;
      color: var(--dark-blue);
      border: 1px solid #b0c4de;
    }
    
    .btn-edit:hover {
      background-color: #d4e6ff;
      transform: translateY(-2px);
    }
    
    .btn-inactive {
      background-color: #fff3cd;
      color: #856404;
      border: 1px solid #ffeaa7;
    }
    
    .btn-inactive:hover {
      background-color: #ffeaa7;
      transform: translateY(-2px);
    }
    
    .btn-reactive {
      background-color: #e6ffe6;
      color: #008000;
      border: 1px solid #b0deb0;
    }
    
    .btn-reactive:hover {
      background-color: #d4ffd4;
      transform: translateY(-2px);
    }
    
    /* Document Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1001;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.7);
      overflow: hidden;
    }
    
    .modal-content {
      background-color: white;
      margin: 5% auto;
      padding: 25px;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
      width: 80%;
      max-width: 600px;
      max-height: 80vh;
      display: flex;
      flex-direction: column;
    }
    
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 15px;
      border-bottom: 1px solid #eee;
      margin-bottom: 15px;
    }
    
    .modal-title {
      font-size: 1.5rem;
      color: var(--dark-blue);
      font-weight: 700;
      margin: 0;
    }
    
    .close-modal {
      background: none;
      border: none;
      font-size: 1.8rem;
      cursor: pointer;
      color: #999;
      transition: all 0.2s ease;
    }
    
    .close-modal:hover {
      color: #333;
      transform: scale(1.1);
    }
    
    .modal-body {
      overflow-y: auto;
      padding: 10px 5px;
      flex-grow: 1;
    }
    
    .documents-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }
    
    .document-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 15px;
      border-bottom: 1px solid #f0f0f0;
      transition: all 0.2s ease;
    }
    
    .document-item:hover {
      background-color: #f8faff;
    }
    
    .document-name {
      flex-grow: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-right: 15px;
      color: #444;
    }
    
    .document-actions {
      display: flex;
      gap: 8px;
    }
    
    .btn-view-doc, .btn-download-doc {
      padding: 6px 12px;
      border-radius: 6px;
      font-weight: 500;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 5px;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    
    .btn-view-doc {
      background-color: #e6f2ff;
      color: var(--dark-blue);
      border: 1px solid #b0c4de;
    }
    
    .btn-view-doc:hover {
      background-color: #d4e6ff;
      transform: translateY(-2px);
    }
    
    .btn-download-doc {
      background-color: #e6ffe6;
      color: #008000;
      border: 1px solid #b0deb0;
    }
    
    .btn-download-doc:hover {
      background-color: #d4ffd4;
      transform: translateY(-2px);
    }
    
    .no-documents {
      text-align: center;
      padding: 30px;
      color: #888;
      font-style: italic;
    }
    
    .btn-delete-doc {
      background-color: #ffe6e6;
      color: #cc0000;
      border: 1px solid #ffb0b0;
      padding: 6px 12px;
      border-radius: 6px;
      font-weight: 500;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 5px;
      text-decoration: none;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    
    .btn-delete-doc:hover {
      background-color: #ffd4d4;
      transform: translateY(-2px);
    }
    
    /* Search bar styling */
    .search-container {
      margin-bottom: 15px;
    }
    
    .search-container .input-group {
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .search-container .input-group-text {
      background-color: #f8f9fa;
      border: none;
      padding: 0.75rem 1rem;
    }
    
    .search-container .form-control {
      border: none;
      padding: 0.75rem;
      font-size: 16px;
    }
    
    /* Inactive Modal Form Styling - Fixed spacing */
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: var(--dark-blue);
    }
    
    .form-select {
      width: 100%;
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #ddd;
      font-size: 16px;
      background-color: #f8f9fa;
    }
    
    .form-select:focus {
      outline: none;
      border-color: var(--accent-blue);
      box-shadow: 0 0 0 3px rgba(90, 150, 207, 0.2);
    }
    
    .other-reason-container, .bankruptcy-file-container {
      margin-top: 15px;
      margin-bottom: 20px;
      display: none;
    }
    
    .form-textarea {
      width: 100%;
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #ddd;
      font-size: 16px;
      min-height: 100px;
      resize: vertical;
    }
    
    .form-textarea:focus {
      outline: none;
      border-color: var(--accent-blue);
      box-shadow: 0 0 0 3px rgba(90, 150, 207, 0.2);
    }
    
    .form-file {
      width: 100%;
      padding: 10px 15px;
      border-radius: 8px;
      border: 1px solid #ddd;
      font-size: 16px;
      background-color: #f8f9fa;
    }
    
    .form-file:focus {
      outline: none;
      border-color: var(--accent-blue);
      box-shadow: 0 0 0 3px rgba(90, 150, 207, 0.2);
    }
    
    .btn-submit-container {
      display: flex;
      justify-content: center;
      margin-top: 20px;
    }
    
    .btn-submit {
      background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
      color: white;
      border: none;
      border-radius: 8px;
      padding: 12px 25px;
      font-weight: 600;
      font-size: 16px;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    
    .btn-submit:hover {
      background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
      transform: translateY(-2px);
    }
    
    /* Report Modal Styling - Professional Document Design */
    .report-modal-content {
      background-color: white;
      margin: 2% auto;
      padding: 0;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
      width: 90%;
      max-width: 900px;
      max-height: 90vh;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    
    .report-header {
      background: linear-gradient(135deg, #2c3e50, #34495e);
      color: white;
      padding: 25px;
      text-align: center;
      position: relative;
      border-bottom: 5px solid var(--accent-blue);
    }
    
    .report-header h2 {
      margin: 0;
      font-size: 28px;
      font-weight: 700;
      letter-spacing: 1px;
    }
    
    .report-subtitle {
      margin: 10px 0 0;
      font-size: 18px;
      opacity: 0.9;
    }
    
    .report-body {
      padding: 30px;
      background: white;
      font-family: 'Georgia', 'Times New Roman', serif;
      font-size: 16px;
      line-height: 1.5;
      overflow-y: auto;
      flex-grow: 1;
    }
    
    .report-section {
      margin-bottom: 25px;
      page-break-inside: avoid;
    }
    
    .report-section-title {
      font-size: 18px;
      font-weight: 700;
      color: #2c3e50;
      margin-bottom: 15px;
      padding-bottom: 8px;
      border-bottom: 2px solid #b0c4de;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    
    .report-info-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 15px;
      margin-bottom: 25px;
    }
    
    .report-info-item {
      padding: 15px;
      background-color: #f8f9fa;
      border-radius: 6px;
      border-left: 4px solid var(--accent-blue);
    }
    
    .report-info-label {
      font-size: 12px;
      font-weight: 600;
      color: #666;
      text-transform: uppercase;
      margin-bottom: 5px;
      letter-spacing: 0.5px;
    }
    
    .report-info-value {
      font-size: 16px;
      font-weight: 600;
      color: #2c3e50;
    }
    
    .report-detail-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 10px;
      margin-bottom: 20px;
    }
    
    .report-detail-item {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
      border-bottom: 1px dashed #e0e0e0;
    }
    
    .report-detail-label {
      font-weight: 600;
      color: #555;
    }
    
    .report-detail-value {
      color: #333;
    }
    
    .report-reason {
      background-color: #f8f9fa;
      padding: 20px;
      border-radius: 6px;
      border-left: 4px solid var(--accent-blue);
      font-size: 16px;
      line-height: 1.6;
      margin-top: 10px;
    }
    
    .report-attachment {
      margin-top: 15px;
      padding: 15px;
      background-color: #e6f2ff;
      border-radius: 6px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-left: 4px solid #5a96cf;
    }
    
    .report-attachment i {
      color: var(--accent-blue);
      font-size: 20px;
    }
    
    .report-signature-area {
      margin-top: 40px;
      padding-top: 20px;
      border-top: 2px solid #e0e0e0;
      text-align: center;
    }
    
    .report-signature-line {
      width: 300px;
      border-bottom: 1px solid #333;
      margin: 40px auto 10px;
    }
    
    .report-signature-label {
      font-size: 14px;
      color: #666;
      margin-top: 5px;
    }
    
    .report-footer {
      background-color: #f8f9fa;
      padding: 20px 30px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid #eee;
      font-size: 14px;
    }
    
    .report-date {
      color: #666;
    }
    
    .report-actions {
      display: flex;
      gap: 15px;
    }
    
    .btn-print, .btn-download-report {
      background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
      color: white;
      border: none;
      border-radius: 6px;
      padding: 10px 20px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    
    .btn-print:hover, .btn-download-report:hover {
      background: linear-gradient(135deg, var(--primary-blue), var(--dark-blue));
      transform: translateY(-2px);
    }
    
    .btn-close-report {
      background: linear-gradient(135deg, #a9a9a9, #808080);
      color: white;
      border: none;
      border-radius: 6px;
      padding: 10px 20px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    
    .btn-close-report:hover {
      background: linear-gradient(135deg, #808080, #5a5a5a);
      transform: translateY(-2px);
    }
    
    /* NEW: Document-style report layout - PARAGRAPH FORM */
    .document-report {
      font-family: 'Times New Roman', serif;
      line-height: 1.6;
      font-size: 16px;
      padding: 30px;
      background: white;
    }
    
    .document-header {
      text-align: center;
      margin-bottom: 30px;
      border-bottom: 2px solid #000;
      padding-bottom: 20px;
    }
    
    .document-title {
      font-size: 22px;
      font-weight: bold;
      margin-bottom: 10px;
      text-transform: uppercase;
    }
    
    .document-subtitle {
      font-size: 16px;
      font-weight: bold;
    }
    
    .document-section {
      margin-bottom: 25px;
    }
    
    .document-section-title {
      font-weight: bold;
      font-size: 18px;
      margin-bottom: 15px;
      text-decoration: underline;
    }
    
    .document-paragraph {
      margin-bottom: 15px;
      text-align: justify;
      line-height: 1.7;
    }
    
    .document-client-info {
      margin: 20px 0;
      padding: 15px;
      background-color: #f8f9fa;
      border-left: 4px solid var(--accent-blue);
    }
    
    .document-client-info p {
      margin: 8px 0;
    }
    
    .document-reason {
      margin-top: 15px;
      padding: 15px;
      border: 1px solid #ccc;
      background-color: #f9f9f9;
      font-style: italic;
    }
    
    .document-attachment {
      margin-top: 15px;
      padding: 15px;
      border: 1px solid #ccc;
      background-color: #f0f8ff;
    }
    
    .document-signature {
      margin-top: 50px;
      text-align: right;
    }
    
    .document-signature-line {
      width: 300px;
      border-bottom: 1px solid #000;
      margin: 40px 0 5px auto;
    }
    
    .document-signature-label {
      font-size: 14px;
      font-style: italic;
    }
    
    .document-footer {
      margin-top: 30px;
      text-align: center;
      font-size: 12px;
      color: #666;
      border-top: 1px solid #ccc;
      padding-top: 10px;
    }
    
    /* Make report body scrollable */
    .scrollable-report {
      max-height: 60vh;
      overflow-y: auto;
      padding: 20px;
      border: 1px solid #eee;
      background-color: #fafafa;
    }
    
    /* FIXED: Print-specific styles for the report */
    @media print {
      body * {
        visibility: hidden;
      }
      #reportModal, #reportModal * {
        visibility: visible;
      }
      #reportModal {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: auto;
        background: white;
        z-index: 9999;
        overflow: visible;
        margin: 0;
        padding: 0;
      }
      .report-modal-content {
        width: 100%;
        max-width: 100%;
        margin: 0;
        padding: 0;
        box-shadow: none;
        border-radius: 0;
        max-height: none;
        overflow: visible;
      }
      .report-header {
        border-radius: 0;
        padding: 20px;
        margin-bottom: 0;
      }
      .report-body {
        padding: 20px;
        overflow: visible;
        max-height: none;
      }
      .report-footer {
        display: none;
      }
      .btn-print, .btn-close-report, .btn-download-report {
        display: none;
      }
      
      /* Ensure good print layout */
      .document-report {
        padding: 20px;
        font-size: 14px;
      }
      .document-header {
        margin-bottom: 20px;
        padding-bottom: 15px;
      }
      .document-title {
        font-size: 20px;
      }
      .document-section {
        margin-bottom: 20px;
        page-break-inside: avoid;
      }
      .document-paragraph {
        font-size: 14px;
        line-height: 1.5;
      }
      .document-client-info, .document-reason, .document-attachment {
        page-break-inside: avoid;
      }
      .document-signature {
        margin-top: 30px;
      }
      .document-footer {
        margin-top: 20px;
        font-size: 10px;
      }
      
      /* Hide modal backdrop and other elements */
      .modal {
        position: absolute;
        background: white;
      }
      .close-modal {
        display: none;
      }
    }
    
    /* Modal-specific styles */
    <?php if ($isModal): ?>
    body {
      padding: 0;
      background: white;
    }
    
    .container-main {
      margin: 0;
      padding: 20px;
      max-width: 100%;
    }
    
    .header-section {
      margin-top: 0;
      padding-top: 0;
    }
    
    .header-section h1 {
      font-size: 1.5rem;
    }
    
    .header-section h1 i {
      width: 40px;
      height: 40px;
      font-size: 18px;
    }
    
    .stats-container {
      gap: 10px;
      margin-bottom: 20px;
    }
    
    .stat-card {
      padding: 15px;
      min-width: 150px;
    }
    
    .stat-icon {
      width: 50px;
      height: 50px;
      font-size: 20px;
    }
    
    .stat-content h3 {
      font-size: 20px;
    }
    
    #clientTable_wrapper {
      padding: 15px;
      margin-bottom: 20px;
    }
    
    .status-filters {
      margin-bottom: 15px;
    }
    <?php endif; ?>
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      <?php if (!$isModal): ?>
      .navbar {
        flex-direction: column;
        padding: 10px;
      }
      
      .nav-links {
        margin-top: 10px;
        flex-wrap: wrap;
        justify-content: center;
      }
      
      .container-main {
        padding-top: 120px;
      }
      <?php endif; ?>
      
      .modal-content {
        width: 95%;
        margin: 10% auto;
      }
      
      .document-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
      }
      
      .document-actions {
        align-self: flex-end;
      }
      
      .status-filters {
        flex-direction: column;
      }
      
      .action-btns {
        flex-direction: column;
        gap: 5px;
      }
      
      .btn-edit, .btn-inactive, .btn-reactive {
        width: 100%;
        justify-content: center;
      }
      
      .report-modal-content {
        width: 95%;
        margin: 5% auto;
      }
      
      .report-info-grid, .report-detail-grid {
        grid-template-columns: 1fr;
      }
      
      .report-footer {
        flex-direction: column;
        gap: 15px;
        text-align: center;
      }
      
      .report-actions {
        width: 100%;
        justify-content: center;
      }
      
      .stat-card {
        min-width: 100%;
      }
      
      .document-info-grid {
        grid-template-columns: 1fr;
      }
      
      .document-info-label {
        width: 140px;
      }
      
      .document-signature-line {
        width: 200px;
      }
    }
  </style>
</head>
<body>
  <div class="container-main">
    <!-- Header -->
    <div class="header-section">
      <h1><i class="fas fa-user-cog"></i> Client Management</h1>
      <p>View, edit, and manage all client accounts in one place. Keep track of important client information and perform administrative tasks efficiently.</p>
    </div>

    <!-- Stats Section -->
    <div class="stats-container">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo $result->num_rows; ?></h3>
          <p>Total Clients</p>
        </div>
      </div>
      
      <div class="stat-card" id="contractsCard">
        <div class="stat-icon">
          <i class="fas fa-file-contract"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo count($contractsFiles); ?></h3>
          <p>Contracts</p>
        </div>
      </div>
      
      <div class="stat-card" id="permitsCard">
        <div class="stat-icon">
          <i class="fas fa-file-signature"></i>
        </div>
        <div class="stat-content">
          <h3><?php echo count($permitsFiles); ?></h3>
          <p>Business Permits</p>
        </div>
      </div>
    </div>

    <!-- Status Filter Buttons -->
    <div class="status-filters">
      <button class="status-btn active" id="btnActive">
        <i class="fas fa-check-circle"></i> Active Clients
      </button>
      <button class="status-btn inactive" id="btnInactive">
        <i class="fas fa-times-circle"></i> Inactive Clients
      </button>
    </div>

    <!-- Client Table -->
    <div class="table-responsive">
      <table id="clientTable" class="display">
        <thead>
          <tr>
            <th>ID</th>
            <th>Company Name</th>
            <th>Date Added</th>
            <th>End of Contract</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          if ($result->num_rows > 0) {
            // Reset pointer to beginning for second loop
            $result->data_seek(0);
            while ($row = $result->fetch_assoc()) {
              // Calculate end of contract date (1000 days from date added)
              $dateAdded = new DateTime($row["Date"]);
              $endOfContract = clone $dateAdded;
              $endOfContract->add(new DateInterval('P100D'));
              $endOfContractFormatted = $endOfContract->format('Y-m-d');
              
              // Determine if contract is active or inactive
              $today = new DateTime();
              $status = $endOfContract > $today ? 'Active' : 'Inactive';
              
              // Check if client has been manually marked as inactive
              if (!empty($row['inactive_reason'])) {
                  $status = 'Inactive';
              }
              
              $statusClass = $status === 'Active' ? 'status-active' : 'status-inactive';
              
              echo "<tr data-status='$status'>
                      <td>".htmlspecialchars($row["id"])."</td>
                      <td><strong>".htmlspecialchars($row["Company Name"])."</strong></td>
                      <td>".htmlspecialchars($row["Date"])."</td>
                      <td>".htmlspecialchars($endOfContractFormatted)."</td>
                      <td><span class='status-indicator $statusClass'></span>$status</td>
                      <td class='action-btns'>
                        <a href='edit_client.php?id=".$row["id"]."' class='btn-edit'><i class='fas fa-edit'></i> Edit</a>";
              
              if ($status === 'Active') {
                echo "<button type='button' class='btn-inactive' data-client-id='".$row["id"]."' data-client-name='".htmlspecialchars($row["Company Name"])."'>
                        <i class='fas fa-user-slash'></i> Tag as Inactive
                      </button>";
              } else {
                echo "<form method='POST' style='display:inline;' onsubmit='return confirm(\"Are you sure you want to reactivate this client?\")'>
                        <input type='hidden' name='mark_active' value='1'>
                        <input type='hidden' name='client_id' value='".$row["id"]."'>
                        <button type='submit' class='btn-reactive'><i class='fas fa-user-check'></i> Reactivate</button>
                      </form>";
              }
              
              echo "</td>
                    </tr>";
            }
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Contracts Modal -->
  <div class="modal" id="contractsModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title"><i class="fas fa-file-contract me-2"></i> Client Contracts</h3>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <!-- Search bar added here -->
        <div class="search-container">
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" id="contractsSearch" class="form-control" placeholder="Search contracts...">
          </div>
        </div>
        
        <?php if (count($contractsFiles) > 0): ?>
          <form id="deleteContractForm" method="POST" style="display: none;">
            <input type="hidden" name="delete_contract" value="1">
            <input type="hidden" id="filenameToDelete" name="filename" value="">
          </form>
          
          <ul class="documents-list" id="contractsList">
            <?php foreach ($contractsFiles as $file): ?>
              <li class="document-item">
                <span class="document-name"><?= htmlspecialchars($file) ?></span>
                <div class="document-actions">
                  <a href="<?= $contractsDir . rawurlencode($file) ?>" target="_blank" class="btn-view-doc" onclick="logDocumentAction('<?= htmlspecialchars($file) ?>', 'view', 'contract')">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <a href="<?= $contractsDir . rawurlencode($file) ?>" download class="btn-download-doc" onclick="logDocumentAction('<?= htmlspecialchars($file) ?>', 'download', 'contract')">
                    <i class="fas fa-download"></i> Download
                  </a>
                  <button class="btn-delete-doc" data-filename="<?= htmlspecialchars($file) ?>">
                    <i class="fas fa-trash-alt"></i> Delete
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="no-documents">
            <i class="fas fa-folder-open fa-2x mb-3"></i>
            <p>No contracts found</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Permits Modal -->
  <div class="modal" id="permitsModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title"><i class="fas fa-file-certificate me-2"></i> Business Permits</h3>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <!-- Search bar added here -->
        <div class="search-container">
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" id="permitsSearch" class="form-control" placeholder="Search permits...">
          </div>
        </div>
        
        <?php if (count($permitsFiles) > 0): ?>
          <ul class="documents-list" id="permitsList">
            <?php foreach ($permitsFiles as $file): ?>
              <li class="document-item">
                <span class="document-name"><?= htmlspecialchars($file) ?></span>
                <div class="document-actions">
                  <a href="<?= $permitsDir . rawurlencode($file) ?>" target="_blank" class="btn-view-doc" onclick="logDocumentAction('<?= htmlspecialchars($file) ?>', 'view', 'permit')">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <a href="<?= $permitsDir . rawurlencode($file) ?>" download class="btn-download-doc" onclick="logDocumentAction('<?= htmlspecialchars($file) ?>', 'download', 'permit')">
                    <i class='fas fa-download'></i> Download
                  </a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="no-documents">
            <i class="fas fa-folder-open fa-2x mb-3"></i>
            <p>No business permits found</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Inactive Client Modal -->
  <div class="modal" id="inactiveModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title"><i class="fas fa-user-slash me-2"></i> Mark Client as Inactive</h3>
        <button class="close-modal">&times;</button>
      </div>
      <div class="modal-body">
        <form id="inactiveForm" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="mark_inactive" value="1">
          <input type="hidden" id="clientId" name="client_id" value="">
          
          <div class="form-group">
            <label for="clientName" class="form-label">Client:</label>
            <input type="text" id="clientName" class="form-control" readonly>
          </div>
          
          <div class="form-group">
            <label for="inactiveReason" class="form-label">Reason for Inactivation:</label>
            <select id="inactiveReason" name='inactive_reason' class="form-select" required>
              <option value="">Select a reason</option>
              <option value="Bankruptcy">Bankruptcy</option>
              <option value="Termination of Contract">Termination of Contract</option>
              <option value="Non-payment">Non-payment</option>
              <option value="Business Closure">Business Closure</option>
              <option value="Other">Other (please specify)</option>
            </select>
          </div>
          
          <div id="otherReasonContainer" class="other-reason-container">
            <div class="form-group">
              <label for="otherReason" class="form-label">Please specify reason:</label>
              <textarea id="otherReason" name="other_reason" class="form-textarea" placeholder="Enter the reason for marking this client as inactive..."></textarea>
            </div>
          </div>
          
          <div id="bankruptcyFileContainer" class="bankruptcy-file-container">
            <div class="form-group">
              <label for="bankruptcyFile" class="form-label">Bankruptcy Documentation (PDF, PNG, JPG):</label>
              <input type="file" id="bankruptcyFile" name="bankruptcy_file" class="form-file" accept=".pdf,.png,.jpg">
            </div>
          </div>
          
          <div class="btn-submit-container">
            <button type="submit" class="btn-submit">
              <i class="fas fa-file-export"></i> Generate Report & Mark Inactive
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Report Modal - Updated with Paragraph Form and Scrollable -->
  <div class="modal" id="reportModal">
    <div class="report-modal-content">
      <div class="report-header">
        <h2>CLIENT INACTIVATION REPORT</h2>
        <p class="report-subtitle">EBTGL Accounting Services</p>
      </div>
      
      <!-- Scrollable report content -->
      <div class="report-body">
        <div class="document-report">
          <div class="document-header">
            <div class="document-title">Client Inactivation Report</div>
            <div class="document-subtitle">EBTGL Accounting Services</div>
          </div>
          
          <div class="document-section">
            <div class="document-section-title">Report Information</div>
            <p class="document-paragraph">
              This report was generated on <strong><?php echo date('F j, Y'); ?></strong> at <strong><?php echo date('g:i A'); ?></strong> 
              with Report ID: <strong><?php echo $reportId; ?></strong>. The purpose of this document is to formally record the inactivation 
              of a client account in accordance with company policies and procedures.
            </p>
          </div>
          
          <div class="document-section">
            <div class="document-section-title">Client Information</div>
            <div class="document-client-info">
              <p><strong>Client ID:</strong> <?php echo $reportClientId; ?></p>
              <p><strong>Company Name:</strong> <?php echo $reportClientName; ?></p>
              <p><strong>Contact Person:</strong> <?php echo $reportClientContact; ?></p>
              <p><strong>Email Address:</strong> <?php echo $reportClientEmail; ?></p>
              <p><strong>Phone Number:</strong> <?php echo $reportClientPhone; ?></p>
              <p><strong>Date Registered:</strong> <?php echo $reportClientRegistered; ?></p>
            </div>
            <p class="document-paragraph">
              The above client was registered with EBTGL Accounting Services on <?php echo $reportClientRegistered; ?> and has been 
              receiving accounting services since that time. The client relationship has now been terminated and the account 
              has been officially marked as inactive in our system.
            </p>
          </div>
          
          <div class="document-section">
            <div class="document-section-title">Inactivation Details</div>
            <p class="document-paragraph">
              The client account was officially marked as inactive on <strong><?php echo date('F j, Y'); ?></strong> at 
              <strong><?php echo date('g:i A'); ?></strong>. This action was taken following a thorough review of the client's 
              status and circumstances.
            </p>
            
            <div class="document-reason">
              <p><strong>Reason for Inactivation:</strong></p>
              <p><?php echo $reportReason; ?></p>
            </div>
            
            <p class="document-paragraph">
              This decision was made in accordance with company policies regarding client account management and has been 
              properly documented in our records. All relevant departments have been notified of this status change.
            </p>
          </div>
          
          <?php if ($reportBankruptcyFile): ?>
          <div class="document-section">
            <div class="document-section-title">Supporting Documentation</div>
            <div class="document-attachment">
              <p><strong>Bankruptcy Documentation:</strong> <?php echo $reportBankruptcyFile; ?></p>
              <p class="document-paragraph">
                Supporting documentation has been uploaded to our secure document management system and is available 
                for review by authorized personnel. This documentation provides evidence supporting the inactivation reason.
              </p>
            </div>
          </div>
          <?php endif; ?>
          
          <div class="document-section">
            <div class="document-section-title">Conclusion</div>
            <p class="document-paragraph">
              The client account has been properly transitioned to inactive status in our accounting system. All ongoing 
              services have been terminated, and final billing has been processed according to our standard procedures. 
              The client has been removed from active client lists and will no longer appear in regular service rotations.
            </p>
            <p class="document-paragraph">
              This report serves as the official record of this inactivation and has been filed in accordance with 
              document retention policies. For any inquiries regarding this inactivation, please contact the 
              administration department.
            </p>
          </div>
          
          <div class="document-signature">
            <div class="document-signature-line"></div>
            <div class="document-signature-label">Authorized Signature</div>
          </div>
          
          <div class="document-footer">
            Generated on <?php echo date('F j, Y \a\t g:i A'); ?> by EBTGL Accounting Services<br>
            For inquiries, contact: admin@ebtglaccounting.com | Report ID: <?php echo $reportId; ?>
          </div>
        </div>
      </div>
      
      <div class="report-footer">
        <div class="report-date">Generated on <?php echo date('F j, Y \a\t g:i A'); ?> by EBTGL Accounting Services</div>
        <div class="report-actions">
          <button class="btn-download-report" onclick="downloadReport()">
            <i class="fas fa-download"></i> Download Report
          </button>
          <button class="btn-print" onclick="printReport()">
            <i class="fas fa-print"></i> Print Report
          </button>
          <button class="btn-close-report" id="closeReport">
            <i class="fas fa-times"></i> Close
          </button>
        </div>
      </div>
    </div>
  </div>
 </div>

  <script>
    $(document).ready(function() {
      // Initialize DataTable with enhanced styling
      var table = $('#clientTable').DataTable({
        responsive: true,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        language: {
          search: '<i class="fas fa-search"></i>',
          searchPlaceholder: 'Search clients...',
          paginate: {
            previous: '<i class="fas fa-chevron-left"></i>',
            next: '<i class="fas fa-chevron-right"></i>'
          }
        },
        initComplete: function() {
          // Add custom styling to DataTable elements
          $('.dataTables_length select').addClass('form-select');
          $('.dataTables_filter input').addClass('form-control');
        }
      });
      
      // Status filter functionality - updated column index from 8 to 4
      $('#btnActive').on('click', function() {
        $(this).addClass('active');
        $('#btnInactive').removeClass('active');
        table.column(4).search('^Active$', true, false).draw();
      });
      
      $('#btnInactive').on('click', function() {
        $(this).addClass('active');
        $('#btnActive').removeClass('active');
        table.column(4).search('^Inactive$', true, false).draw();
      });
      
      // Show active clients by default
      $('#btnActive').click();
      
      // Modal functionality
      const contractsCard = document.getElementById('contractsCard');
      const permitsCard = document.getElementById('permitsCard');
      const contractsModal = document.getElementById('contractsModal');
      const permitsModal = document.getElementById('permitsModal');
      const inactiveModal = document.getElementById('inactiveModal');
      const reportModal = document.getElementById('reportModal');
      const closeButtons = document.querySelectorAll('.close-modal');
      
      // Open contracts modal
      contractsCard.addEventListener('click', () => {
        contractsModal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
        
        // Log contracts modal view
        logModalView('contracts');
      });
      
      // Open permits modal
      permitsCard.addEventListener('click', () => {
        permitsModal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
        
        // Log permits modal view
        logModalView('permits');
      });
      
      // Close modals
      closeButtons.forEach(button => {
        button.addEventListener('click', () => {
          contractsModal.style.display = 'none';
          permitsModal.style.display = 'none';
          inactiveModal.style.display = 'none';
          reportModal.style.display = 'none';
          document.body.style.overflow = 'auto'; // Re-enable scrolling
        });
      });
      
      // Close report modal with the close button
      $('#closeReport').on('click', function() {
        reportModal.style.display = 'none';
        document.body.style.overflow = 'auto';
      });
      
      // Close modal when clicking outside
      window.addEventListener('click', (e) => {
        if (e.target === contractsModal) {
          contractsModal.style.display = 'none';
          document.body.style.overflow = 'auto';
        }
        if (e.target === permitsModal) {
          permitsModal.style.display = 'none';
          document.body.style.overflow = 'auto';
        }
        if (e.target === inactiveModal) {
          inactiveModal.style.display = 'none';
          document.body.style.overflow = 'auto';
        }
        if (e.target === reportModal) {
          reportModal.style.display = 'none';
          document.body.style.overflow = 'auto';
        }
      });
      
      // Contract file deletion
      $(document).on('click', '.btn-delete-doc', function() {
        const filename = $(this).data('filename');
        if (confirm('Are you sure you want to delete this contract?\nThis action cannot be undone.')) {
          // Set filename in hidden form field
          $('#filenameToDelete').val(filename);
          
          // Submit the form
          $('#deleteContractForm').submit();
        }
      });
      
      // Search functionality for contracts modal
      $('#contractsSearch').on('input', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('#contractsList .document-item').each(function() {
          const fileName = $(this).find('.document-name').text().toLowerCase();
          $(this).toggle(fileName.includes(searchTerm));
        });
      });

      // Search functionality for permits modal
      $('#permitsSearch').on('input', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('#permitsList .document-item').each(function() {
          const fileName = $(this).find('.document-name').text().toLowerCase();
          $(this).toggle(fileName.includes(searchTerm));
        });
      });
      
      // Inactive client modal functionality - FIXED
      // Use event delegation for dynamically created elements
      $(document).on('click', '.btn-inactive', function() {
        const clientId = $(this).data('client-id');
        const clientName = $(this).data('client-name');
        
        console.log('Inactive button clicked for client:', clientName, 'ID:', clientId);
        
        // Set client info in the form
        $('#clientId').val(clientId);
        $('#clientName').val(clientName);
        
        // Reset form
        $('#inactiveReason').val('');
        $('#otherReason').val('');
        $('#otherReasonContainer').hide();
        $('#bankruptcyFileContainer').hide();
        $('#bankruptcyFile').val('');
        
        // Show modal
        inactiveModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Log inactive modal view
        logModalView('inactive_client', clientId, clientName);
      });
      
      // Show/hide other reason textarea based on selection
      $('#inactiveReason').on('change', function() {
        if ($(this).val() === 'Other') {
          $('#otherReasonContainer').show();
          $('#bankruptcyFileContainer').hide();
        } else if ($(this).val() === 'Bankruptcy') {
          $('#otherReasonContainer').hide();
          $('#bankruptcyFileContainer').show();
        } else {
          $('#otherReasonContainer').hide();
          $('#bankruptcyFileContainer').hide();
        }
      });
      
      // Form validation for inactive form
      $('#inactiveForm').on('submit', function(e) {
        const reason = $('#inactiveReason').val();
        
        if (!reason) {
          e.preventDefault();
          alert('Please select a reason for marking this client as inactive.');
          return false;
        }
        
        if (reason === 'Other' && !$('#otherReason').val().trim()) {
          e.preventDefault();
          alert('Please specify the reason for marking this client as inactive.');
          return false;
        }
        
        if (reason === 'Bankruptcy') {
          const fileInput = $('#bankruptcyFile')[0];
          if (fileInput.files.length === 0) {
            e.preventDefault();
            alert('Please attach bankruptcy documentation.');
            return false;
          }
          
          // Check file type
          const fileName = fileInput.files[0].name;
          const fileExtension = fileName.split('.').pop().toLowerCase();
          const allowedExtensions = ['pdf', 'png', 'jpg'];
          
          if (!allowedExtensions.includes(fileExtension)) {
            e.preventDefault();
            alert('Please attach a valid file type (PDF, PNG, JPG only).');
            return false;
          }
        }
        
        // Show confirmation dialog
        if (!confirm('Are you sure you want to mark this client as inactive? This will generate a report.')) {
          e.preventDefault();
          return false;
        }
        
        return true;
      });
      
      // Show report modal if there's a generated report
      <?php if (isset($generatedReport)): ?>
        reportModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Log report modal view
        logModalView('inactivation_report', <?php echo $reportClientId; ?>, '<?php echo $reportClientName; ?>');
      <?php endif; ?>
    });
    
    // Function to print the report
    function printReport() {
      window.print();
      
      // Log report printing
      logReportAction('print');
    }
    
    // Function to download the report as text file
    function downloadReport() {
      const reportContent = `<?php echo isset($generatedReport) ? addslashes($generatedReport) : ''; ?>`;
      
      if (!reportContent) {
        alert('No report content available for download.');
        return;
      }
      
      const element = document.createElement('a');
      const file = new Blob([reportContent], {type: 'text/plain'});
      element.href = URL.createObjectURL(file);
      element.download = 'Client_Inactivation_Report_<?php echo $reportId; ?>.txt';
      document.body.appendChild(element);
      element.click();
      document.body.removeChild(element);
      
      // Log report download
      logReportAction('download');
    }
    
    // Function to log modal views
    function logModalView(modalType, clientId = null, clientName = null) {
      fetch('log_activity.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action_type=VIEW_MODAL&modal_type=' + modalType + 
              (clientId ? '&client_id=' + clientId : '') + 
              (clientName ? '&client_name=' + encodeURIComponent(clientName) : '')
      })
      .then(response => response.text())
      .then(data => {
        console.log('Modal view logged:', modalType);
      })
      .catch(error => {
        console.error('Error logging modal view:', error);
      });
    }
    
    // Function to log document actions
    function logDocumentAction(documentName, action, documentType) {
      fetch('log_activity.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action_type=DOCUMENT_' + action.toUpperCase() + '&document_name=' + encodeURIComponent(documentName) + '&document_type=' + documentType
      })
      .then(response => response.text())
      .then(data => {
        console.log('Document action logged:', action, documentName);
      })
      .catch(error => {
        console.error('Error logging document action:', error);
      });
    }
    
    // Function to log report actions
    function logReportAction(action) {
      fetch('log_activity.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action_type=REPORT_' + action.toUpperCase() + '&report_id=<?php echo $reportId; ?>&client_id=<?php echo $reportClientId; ?>&client_name=<?php echo urlencode($reportClientName); ?>'
      })
      .then(response => response.text())
      .then(data => {
        console.log('Report action logged:', action);
      })
      .catch(error => {
        console.error('Error logging report action:', error);
      });
    }
  </script>
</body>
</html>
<?php $conn->close(); ?>