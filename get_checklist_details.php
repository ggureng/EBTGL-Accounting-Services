<?php
session_start();
require 'db_connection.php';

// Only allow access from admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("
        <div class='alert alert-danger'>
            <i class='fas fa-exclamation-triangle me-2'></i>
            Access denied. Admin privileges required.
        </div>
    ");
}

$company_name = $_GET['company_name'] ?? '';
$user_id = $_GET['user_id'] ?? '';

if (!$company_name || !$user_id) {
    die("
        <div class='alert alert-danger'>
            <i class='fas fa-exclamation-triangle me-2'></i>
            Invalid parameters provided.
        </div>
    ");
}

// Get checklist items grouped by section
$stmt = $pdo->prepare("
    SELECT * FROM bankruptcy_checklist 
    WHERE company_name = ? AND user_id = ? 
    ORDER BY section, id
");
$stmt->execute([$company_name, $user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filing status
$stmt = $pdo->prepare("SELECT * FROM bankruptcy_filing_status WHERE company_name = ? AND user_id = ?");
$stmt->execute([$company_name, $user_id]);
$filingStatus = $stmt->fetch(PDO::FETCH_ASSOC);

// If no filing status exists, create one
if (!$filingStatus) {
    $stmt = $pdo->prepare("INSERT INTO bankruptcy_filing_status (company_name, user_id, filing_type, overall_status, checklist_completed, access_granted, created_at, updated_at) VALUES (?, ?, 'Rehabilitation', 'In Progress', 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute([$company_name, $user_id]);
    
    // Fetch the newly created status
    $stmt = $pdo->prepare("SELECT * FROM bankruptcy_filing_status WHERE company_name = ? AND user_id = ?");
    $stmt->execute([$company_name, $user_id]);
    $filingStatus = $stmt->fetch(PDO::FETCH_ASSOC);
}

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

$groupedItems = [];
foreach ($items as $item) {
    $groupedItems[$item['section']][] = $item;
}

// Calculate progress
$totalItems = count($items);
$completedItems = 0;
foreach ($items as $item) {
    if ($item['status'] === 'Completed') $completedItems++;
}
$completionRate = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;
?>

<style>
.checklist-review {
    max-height: 70vh;
    overflow-y: auto;
    padding-right: 10px;
}

.section-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    border-left: 4px solid #4682B4;
}

.section-title {
    color: #2c3e50;
    font-weight: 600;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: #4682B4;
}

.checklist-item {
    background: white;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
    border: 1px solid #dee2e6;
    transition: all 0.3s ease;
}

.checklist-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.checklist-item.completed {
    background: #f0f9ff;
    border-color: #d1edff;
}

.progress-summary {
    background: linear-gradient(135deg, #4682B4, #5a96cf);
    color: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.progress-summary .progress {
    height: 20px;
    background-color: rgba(255,255,255,0.3);
}

.progress-summary .progress-bar {
    background-color: white;
    color: #4682B4;
    font-weight: 600;
}

.file-badge {
    background: #28a745;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.bg-completed {
    background-color: #d1edff;
    color: #4682B4;
}

.bg-in-progress {
    background-color: #fff3cd;
    color: #856404;
}

.bg-not-started {
    background-color: #e9ecef;
    color: #6c757d;
}

.due-date {
    font-size: 0.85rem;
    color: #6c757d;
}

.due-date.urgent {
    color: #dc3545;
    font-weight: 600;
}

.notes-section {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 10px;
    margin-top: 10px;
    border-left: 3px solid #4682B4;
}

.document-preview {
    background: white;
    border: 1px dashed #dee2e6;
    border-radius: 6px;
    padding: 10px;
    text-align: center;
}

.document-icon {
    font-size: 1.5rem;
    color: #4682B4;
    margin-bottom: 5px;
}
</style>

<div class="checklist-review">
    <!-- Progress Summary -->
    <div class="progress-summary">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="mb-3">
                    <i class="fas fa-chart-line me-2"></i>
                    Checklist Progress - <?= htmlspecialchars($company_name) ?>
                </h5>
                <div class="progress mb-2">
                    <div class="progress-bar" role="progressbar" style="width: <?= $completionRate ?>%;">
                        <?= $completionRate ?>%
                    </div>
                </div>
                <p class="mb-0">
                    <strong><?= $completedItems ?></strong> of <strong><?= $totalItems ?></strong> items completed
                    <?php if ($filingStatus['checklist_completed']): ?>
                        <span class="badge bg-success ms-2">
                            <i class="fas fa-check me-1"></i>Ready for Approval
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-4 text-end">
                <div class="fw-bold">Overall Status:</div>
                <span class="badge bg-<?= $filingStatus['overall_status'] === 'Completed' ? 'success' : 'warning' ?>">
                    <?= $filingStatus['overall_status'] ?? 'In Progress' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Checklist Sections -->
    <?php foreach ($groupedItems as $sectionCode => $sectionItems): ?>
    <div class="section-card">
        <h6 class="section-title">
            <i class="fas fa-folder"></i>
            SECTION <?= $sectionCode ?>: <?= $sectionTitles[$sectionCode] ?>
        </h6>
        
        <?php foreach ($sectionItems as $item): 
            $isCompleted = $item['status'] === 'Completed';
            $isUrgent = $item['due_date'] && strtotime($item['due_date']) < strtotime('+3 days');
        ?>
        <div class="checklist-item <?= $isCompleted ? 'completed' : '' ?>">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-user me-1"></i><?= $item['assigned_person'] ?>
                        </small>
                        <?php if ($item['due_date']): ?>
                            <br>
                            <small class="due-date <?= $isUrgent ? 'urgent' : '' ?>">
                                <i class="fas fa-calendar me-1"></i>
                                Due: <?= date('M d, Y', strtotime($item['due_date'])) ?>
                                <?php if ($isUrgent): ?>
                                    <i class="fas fa-exclamation-triangle ms-1"></i>
                                <?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-2">
                    <span class="status-badge bg-<?= strtolower(str_replace(' ', '-', $item['status'])) ?>">
                        <i class="fas fa-<?= $item['status'] === 'Completed' ? 'check' : ($item['status'] === 'In Progress' ? 'sync' : 'clock') ?> me-1"></i>
                        <?= $item['status'] ?>
                    </span>
                </div>
                <div class="col-md-3">
                    <?php if ($item['file_path']): ?>
                        <div class="document-preview">
                            <i class="fas fa-file-pdf document-icon"></i>
                            <div>
                                <a href="<?= $item['file_path'] ?>" target="_blank" class="btn btn-sm btn-success">
                                    <i class="fas fa-download me-1"></i> View File
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">
                            <i class="fas fa-times-circle me-1"></i>No file uploaded
                        </span>
                    <?php endif; ?>
                </div>
                <div class="col-md-2 text-end">
                    <?php if ($item['last_updated']): ?>
                        <small class="text-muted">
                            Updated: <?= date('M d, Y', strtotime($item['last_updated'])) ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($item['notes']): ?>
            <div class="notes-section">
                <strong><i class="fas fa-sticky-note me-1"></i>Notes:</strong> 
                <?= htmlspecialchars($item['notes']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Approval Section -->
<div class="text-center mt-4 p-4 border-top">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <h5 class="mb-3">
                <i class="fas fa-clipboard-check me-2"></i>
                Filing Approval
            </h5>
            
            <?php if ($filingStatus['checklist_completed'] && !$filingStatus['access_granted']): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    This filing is ready for approval. All checklist items have been completed.
                </div>
                <form method="POST" action="dashboard.php">
                    <input type="hidden" name="company_name" value="<?= htmlspecialchars($company_name) ?>">
                    <input type="hidden" name="user_id" value="<?= $user_id ?>">
                    <button type="submit" name="approve_bankruptcy_filing" class="btn btn-success btn-lg">
                        <i class="fas fa-check me-2"></i>Approve Bankruptcy Filing
                    </button>
                    <p class="text-muted mt-2">
                        This will grant financial reports access to the client.
                    </p>
                </form>
            <?php elseif ($filingStatus['access_granted']): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    This filing was approved on <?= date('M d, Y', strtotime($filingStatus['updated_at'])) ?>.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    This filing is not yet ready for approval. <?= $totalItems - $completedItems ?> items remaining.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>