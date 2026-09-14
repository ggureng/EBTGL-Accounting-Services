<?php
session_start();
require 'db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: index.html");
    exit;
}

$company_name = $_GET['company_name'] ?? '';
$user_id = $_GET['user_id'] ?? '';

if (empty($company_name) || empty($user_id)) {
    die('Invalid request parameters');
}

// Get client information
$clientStmt = $pdo->prepare("SELECT `Company Name` as company_display_name FROM client WHERE id = ?");
$clientStmt->execute([$user_id]);
$client = $clientStmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    die('Client not found');
}

// Get checklist items grouped by section
function getChecklistBySection($pdo, $company_name, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM bankruptcy_checklist WHERE company_name = ? AND user_id = ? ORDER BY section, id");
    $stmt->execute([$company_name, $user_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $grouped = [];
    foreach ($items as $item) {
        $grouped[$item['section']][] = $item;
    }
    
    return $grouped;
}

// Get filing status
function getFilingStatus($pdo, $company_name, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM bankruptcy_filing_status WHERE company_name = ? AND user_id = ?");
    $stmt->execute([$company_name, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$checklistItems = getChecklistBySection($pdo, $company_name, $user_id);
$filingStatus = getFilingStatus($pdo, $company_name, $user_id);

// Section titles
$sectionTitles = [
    'A' => 'BASIC INFORMATION',
    'B' => 'STATEMENT OF INSOLVENCY', 
    'C' => 'FINANCIAL DOCUMENTS',
    'D' => 'LEGAL & CORPORATE DOCUMENTS',
    'E' => 'PETITION DOCUMENTS',
    'F' => 'SUPPORTING ATTACHMENTS',
    'G' => 'ADDITIONAL DOCUMENTS',
    'H' => 'POST-FILING TASKS'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Review Checklist - <?= htmlspecialchars($client['company_display_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0">
                    <i class="fas fa-clipboard-list me-2"></i>
                    Bankruptcy Filing Checklist - <?= htmlspecialchars($client['company_display_name']) ?>
                </h3>
                <p class="mb-0">Status: <?= $filingStatus['overall_status'] ?? 'In Progress' ?></p>
            </div>
            <div class="card-body">
                <?php foreach ($checklistItems as $sectionCode => $items): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">SECTION <?= $sectionCode ?>: <?= $sectionTitles[$sectionCode] ?></h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($items as $item): 
                                $isCompleted = $item['status'] === 'Completed';
                            ?>
                                <div class="border p-3 mb-3 rounded <?= $isCompleted ? 'bg-light' : '' ?>">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <h6><?= htmlspecialchars($item['item_name']) ?></h6>
                                            <p class="mb-1"><small>Assigned to: <?= $item['assigned_person'] ?></small></p>
                                            <?php if ($item['due_date']): ?>
                                                <p class="mb-1"><small>Due: <?= date('M d, Y', strtotime($item['due_date'])) ?></small></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-2 text-center">
                                            <span class="badge bg-<?= $isCompleted ? 'success' : ($item['status'] === 'In Progress' ? 'warning' : 'secondary') ?>">
                                                <?= $item['status'] ?>
                                            </span>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <?php if ($item['file_path']): ?>
                                                <a href="<?= $item['file_path'] ?>" target="_blank" class="btn btn-sm btn-success">
                                                    <i class="fas fa-download me-1"></i> Download
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">No file</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($item['last_updated']): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                Last updated: <?= date('M d, Y g:i A', strtotime($item['last_updated'])) ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="text-center mt-4">
                    <a href="dashboard.php?section=bankruptcy-filings" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>