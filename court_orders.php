<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_name'])) {
    header("Location: index.html");
    exit;
}

$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];

// Handle form submissions for compliance reporting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_compliance_report'])) {
        $report_type = $_POST['report_type'];
        $report_period = $_POST['report_period'];
        $content = $_POST['content'];
        $supporting_docs = $_FILES['supporting_docs'];
        
        // File upload handling
        if ($supporting_docs['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'court_reports/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $filename = time() . '_' . basename($supporting_docs['name']);
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($supporting_docs['tmp_name'], $targetPath)) {
                // Save report to database
                $stmt = $pdo->prepare("INSERT INTO court_compliance_reports (user_id, company_name, report_type, report_period, content, file_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $company_name, $report_type, $report_period, $content, $targetPath]);
                
                $_SESSION['success_message'] = "Compliance report submitted successfully!";
            }
        }
    }
}
?>

<!-- Court Orders Compliance Page HTML -->
<!DOCTYPE html>
<html>
<head>
    <title>Court Orders Compliance - <?= htmlspecialchars($company_name) ?></title>
    <!-- Include CSS and JS files -->
</head>
<body>
    <div class="container-fluid">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4><i class="fas fa-gavel me-2"></i>Court Orders Compliance</h4>
            </div>
            <div class="card-body">
                <!-- Compliance reporting form and history -->
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-md-6">
                            <label>Report Type</label>
                            <select name="report_type" class="form-select" required>
                                <option value="">Select Report Type</option>
                                <option value="Monthly Operating Report">Monthly Operating Report</option>
                                <option value="Compliance Certificate">Compliance Certificate</option>
                                <option value="Progress Report">Progress Report</option>
                                <option value="Financial Statement">Financial Statement</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Reporting Period</label>
                            <input type="month" name="report_period" class="form-control" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label>Report Content</label>
                        <textarea name="content" class="form-control" rows="6" placeholder="Describe compliance with court orders, progress on rehabilitation plan, and any challenges faced..." required></textarea>
                    </div>
                    <div class="mt-3">
                        <label>Supporting Documents</label>
                        <input type="file" name="supporting_docs" class="form-control" accept=".pdf,.doc,.docx">
                    </div>
                    <div class="mt-3">
                        <button type="submit" name="submit_compliance_report" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Submit to Court
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>