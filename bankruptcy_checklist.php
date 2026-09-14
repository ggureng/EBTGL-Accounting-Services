<?php
session_start();
require 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_name'])) {
    header("Location: index.html");
    exit;
}

$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];

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
$filingStatus = getFilingStatus($pdo, $company_name, $user_id);
function getFilingStatus($pdo, $company_name, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM bankruptcy_filing_status WHERE company_name = ? AND user_id = ?");
    $stmt->execute([$company_name, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$checklistItems = getChecklistBySection($pdo, $company_name, $user_id);

// Calculate completion status
$totalItems = 0;
$completedItems = 0;
foreach ($checklistItems as $section) {
    $totalItems += count($section);
    foreach ($section as $item) {
        if ($item['status'] === 'Completed') $completedItems++;
    }
}
$completionStatus = ($totalItems > 0 && $completedItems == $totalItems);

// Handle approval request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_approval') {
    // Update the filing status to mark checklist as completed and set status to Pending Approval
    $stmt = $pdo->prepare("UPDATE bankruptcy_filing_status SET checklist_completed = 1, overall_status = 'Pending Approval' WHERE user_id = ? AND company_name = ?");
    $result = $stmt->execute([$user_id, $company_name]);
    
    if ($result) {
        // Return success response for AJAX
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Approval request submitted! Admin will review your submission.']);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error submitting approval request.']);
        exit;
    }
}

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
    <title>Bankruptcy Filing Checklist - <?= htmlspecialchars($company_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4682B4;
            --secondary-color: #2c3e50;
            --accent-color: #5a96cf;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-bg: #f5f9fc;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Poppins', sans-serif;
            padding: 20px;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, var(--secondary-color), var(--accent-color));
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 150px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .header h1 {
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .section-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border-left: 6px solid var(--primary-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .section-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .section-title {
            color: var(--secondary-color);
            border-bottom: 3px solid var(--primary-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
            font-weight: 700;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--primary-color);
        }
        
        .checklist-item {
            padding: 20px;
            border: 1px solid #e1e8ed;
            border-radius: 12px;
            margin-bottom: 20px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .checklist-item:hover {
            background: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(70, 130, 180, 0.1);
        }
        
        .checklist-item.completed {
            background: #f0f9ff;
            border-color: #d1edff;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-not-started { 
            background: #e9ecef; 
            color: #6c757d; 
        }
        
        .status-in-progress { 
            background: #fff3cd; 
            color: #856404; 
        }
        
        .status-completed { 
            background: #d1edff; 
            color: var(--primary-color); 
        }
        
        .file-upload-btn {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .file-upload-btn:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .progress-container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            border-left: 6px solid var(--accent-color);
        }
        
        .completion-alert {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: none;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(70, 130, 180, 0.3);
        }
        
        .progress {
            height: 25px;
            border-radius: 12px;
            background-color: #e9ecef;
            overflow: hidden;
        }
        
        .progress-bar {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
        }
        
        .metric-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 10px 0;
        }
        
        .metric-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .file-preview {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            border: 1px dashed #dee2e6;
        }
        
        .document-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .due-date {
            font-size: 0.85rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .due-date.urgent {
            color: var(--danger-color);
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 10000;
            display: none;
            animation: fadeInOut 3s ease-in-out;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            max-width: 400px;
        }

        .notification.success {
            background-color: #2ecc71;
        }

        .notification.error {
            background-color: #e74c3c;
        }

        @keyframes fadeInOut {
            0%, 100% { opacity: 0; transform: translateY(-20px); }
            10%, 90% { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .section-card {
                padding: 20px;
            }
            
            .checklist-item {
                padding: 15px;
            }
            
            .header {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Notification Element -->
    <div class="notification" id="notification"></div>

    <div class="container-fluid">
        <!-- Header -->
        <div class="header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1><i class="fas fa-clipboard-list me-3"></i>Bankruptcy Filing Checklist</h1>
                    <p class="mb-2">FRIA - Financial Rehabilitation and Insolvency Act (Philippines)</p>
                    <p class="mb-0"><strong>Company:</strong> <?= htmlspecialchars($company_name) ?></p>
                    <p class="mb-0"><strong>Filing Type:</strong> <?= $filingStatus['filing_type'] ?? 'Rehabilitation' ?></p>
                </div>
                <div class="col-md-4 text-end">
                        <button class="btn btn-warning" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print Checklist
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progress Overview -->
        <div class="progress-container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4><i class="fas fa-tasks me-2"></i>Filing Progress</h4>
                    <?php
                    $inProgressItems = 0;
                    foreach ($checklistItems as $section) {
                        foreach ($section as $item) {
                            if ($item['status'] === 'In Progress') $inProgressItems++;
                        }
                    }
                    $completionPercentage = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;
                    ?>
                    <div class="progress mb-3">
                        <div class="progress-bar" role="progressbar" style="width: <?= $completionPercentage ?>%;" 
                             aria-valuenow="<?= $completionPercentage ?>" aria-valuemin="0" aria-valuemax="100">
                            <?= $completionPercentage ?>%
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="metric-card">
                                <div class="metric-label">Total Items</div>
                                <div class="metric-value"><?= $totalItems ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric-card">
                                <div class="metric-label">Completed</div>
                                <div class="metric-value text-success"><?= $completedItems ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric-card">
                                <div class="metric-label">In Progress</div>
                                <div class="metric-value text-warning"><?= $inProgressItems ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <h4><i class="fas fa-info-circle me-2"></i>Filing Status</h4>
                    <p><strong>Overall Status:</strong> 
                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $filingStatus['overall_status'] ?? 'In Progress')) ?>">
                            <i class="fas fa-<?= ($filingStatus['overall_status'] ?? 'In Progress') === 'Completed' ? 'check' : 'sync' ?> me-1"></i>
                            <?= $filingStatus['overall_status'] ?? 'In Progress' ?>
                        </span>
                    </p>
                    
                    <?php if ($completionStatus): ?>
                        <div class="completion-alert">
                            <h5><i class="fas fa-check-circle me-2"></i>Checklist Completed!</h5>
                            <p class="mb-2">All required documents have been submitted. You can now request admin approval.</p>
                            <?php if (($filingStatus['overall_status'] ?? '') !== 'Pending Approval'): ?>
                                <button type="button" class="btn btn-success mt-2" onclick="requestApproval()">
                                    <i class="fas fa-paper-plane me-1"></i> Request Admin Approval
                                </button>
                            <?php else: ?>
                                <p class="mb-0 text-success">
                                    <i class="fas fa-check me-1"></i>
                                    Approval request submitted and pending admin review.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Checklist Sections -->
        <?php foreach ($checklistItems as $sectionCode => $items): ?>
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-folder-open"></i>
                    SECTION <?= $sectionCode ?>: <?= $sectionTitles[$sectionCode] ?>
                </h3>
                
                <?php foreach ($items as $item): 
                    $isCompleted = $item['status'] === 'Completed';
                    $isUrgent = $item['due_date'] && strtotime($item['due_date']) < strtotime('+3 days');
                ?>
                    <div class="checklist-item <?= $isCompleted ? 'completed' : '' ?>" id="item-<?= $item['id'] ?>">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1"><?= htmlspecialchars($item['item_name']) ?></h6>
                                <div class="d-flex flex-wrap gap-3 mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i><?= $item['assigned_person'] ?>
                                    </small>
                                    <?php if ($item['due_date']): ?>
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
                                <select name="status" class="form-select form-select-sm status-select" 
                                        data-item-id="<?= $item['id'] ?>">
                                    <option value="Not Started" <?= $item['status'] === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                                    <option value="In Progress" <?= $item['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="Completed" <?= $item['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <?php if ($item['file_path']): ?>
                                    <div class="file-preview text-center">
                                        <i class="fas fa-file-pdf document-icon"></i>
                                        <p class="mb-1">Document Uploaded</p>
                                        <a href="<?= $item['file_path'] ?>" target="_blank" class="btn btn-sm btn-success me-1">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                        <button type="button" class="btn btn-sm btn-warning" onclick="replaceFile(<?= $item['id'] ?>)">
                                            <i class="fas fa-sync me-1"></i> Replace
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="file-upload-section" id="upload-section-<?= $item['id'] ?>">
                                        <div class="input-group">
                                            <input type="file" name="document" class="form-control form-control-sm file-input" 
                                                   data-item-id="<?= $item['id'] ?>">
                                            <button type="button" class="btn btn-sm btn-primary upload-btn" 
                                                    data-item-id="<?= $item['id'] ?>">
                                                <i class="fas fa-upload me-1"></i> Upload
                                            </button>
                                        </div>
                                        <small class="text-muted">Max file size: 10MB</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2 text-end">
                                <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $item['status'])) ?>" 
                                      id="status-badge-<?= $item['id'] ?>">
                                    <i class="fas fa-<?= $item['status'] === 'Completed' ? 'check' : ($item['status'] === 'In Progress' ? 'sync' : 'clock') ?> me-1"></i>
                                    <?= $item['status'] ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Last Updated -->
                        <?php if ($item['last_updated']): ?>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    Last updated: <?= date('M d, Y g:i A', strtotime($item['last_updated'])) ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Instructions -->
        <div class="section-card">
            <h3 class="section-title">
                <i class="fas fa-info-circle"></i>
                FILING INSTRUCTIONS
            </h3>
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Important Notes:</h6>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            Complete all required sections before submitting for approval
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            Upload supporting documents for each completed item
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            Ensure all uploaded documents are clear and legible
                        </li>
                        <li class="list-group-item d-flex align-items-center">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            Contact your legal counsel for assistance with legal documents
                        </li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6><i class="fas fa-clock me-2"></i>Timeline:</h6>
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-badge bg-primary">1-2</div>
                            <div class="timeline-content">
                                <strong>Initial filing:</strong> 7-14 days from checklist start
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-badge bg-warning">3-4</div>
                            <div class="timeline-content">
                                <strong>Document preparation:</strong> 21-28 days
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-badge bg-info">5-6</div>
                            <div class="timeline-content">
                                <strong>Court submission:</strong> 35-42 days
                            </div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-badge bg-success">7-8</div>
                            <div class="timeline-content">
                                <strong>Overall process:</strong> 60-90 days typically
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show notification function
        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = `notification ${type}`;
            notification.style.display = 'block';
            
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }

        // Update checklist item status
        function updateChecklistItemStatus(itemId, status) {
            const formData = new FormData();
            formData.append('action', 'update_checklist_item');
            formData.append('item_id', itemId);
            formData.append('status', status);

            fetch('../asset.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the status badge
                    const badge = document.getElementById(`status-badge-${itemId}`);
                    const itemElement = document.getElementById(`item-${itemId}`);
                    
                    // Update badge text and class
                    badge.innerHTML = `<i class="fas fa-${status === 'Completed' ? 'check' : (status === 'In Progress' ? 'sync' : 'clock')} me-1"></i>${status}`;
                    badge.className = `status-badge status-${status.toLowerCase().replace(' ', '-')}`;
                    
                    // Update item completion styling
                    if (status === 'Completed') {
                        itemElement.classList.add('completed');
                    } else {
                        itemElement.classList.remove('completed');
                    }
                    
                    // Show success message
                    showNotification('Checklist item updated successfully', 'success');
                    
                    // Check if we need to refresh the parent page for progress updates
                    if (data.all_completed && typeof parent.updateChecklistItem === 'function') {
                        parent.updateChecklistItem(itemId, status);
                    }
                    
                    // Refresh progress display
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification('Error updating item: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Network error updating item', 'error');
            });
        }

        // Upload file for checklist item
        function uploadChecklistFile(itemId, file) {
            const formData = new FormData();
            formData.append('action', 'upload_checklist_file');
            formData.append('item_id', itemId);
            formData.append('document', file);

            fetch('../asset.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('File uploaded successfully', 'success');
                    // Refresh the page to show the uploaded file
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification('Error uploading file: ' + data.message, 'error');
                    // Re-enable the upload button
                    const uploadBtn = document.querySelector(`.upload-btn[data-item-id="${itemId}"]`);
                    if (uploadBtn) {
                        uploadBtn.disabled = false;
                        uploadBtn.innerHTML = '<i class="fas fa-upload me-1"></i> Upload';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Network error uploading file', 'error');
                // Re-enable the upload button
                const uploadBtn = document.querySelector(`.upload-btn[data-item-id="${itemId}"]`);
                if (uploadBtn) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="fas fa-upload me-1"></i> Upload';
                }
            });
        }

        // Replace file (show upload section again)
        function replaceFile(itemId) {
            const filePreview = document.querySelector(`#item-${itemId} .file-preview`);
            const uploadSection = document.getElementById(`upload-section-${itemId}`);
            
            if (filePreview && uploadSection) {
                filePreview.style.display = 'none';
                uploadSection.style.display = 'block';
            }
        }

        // Request admin approval
        function requestApproval() {
            if (confirm('Are you sure you want to submit all completed documents for admin approval? This will make your bankruptcy filing appear in the admin dashboard for review.')) {
                const formData = new FormData();
                formData.append('action', 'request_approval');

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        
                        // Update the UI to reflect the new status
                        const completionAlert = document.querySelector('.completion-alert');
                        if (completionAlert) {
                            completionAlert.innerHTML = `
                                <h5><i class="fas fa-check-circle me-2"></i>Approval Request Submitted!</h5>
                                <p class="mb-0 text-success">
                                    <i class="fas fa-check me-1"></i>
                                    Your bankruptcy filing is now pending admin review and will appear in the admin dashboard.
                                </p>
                            `;
                        }
                        
                        // Update the overall status display
                        const overallStatusBadge = document.querySelector('.status-badge');
                        if (overallStatusBadge) {
                            overallStatusBadge.innerHTML = `<i class="fas fa-clock me-1"></i>Pending Approval`;
                            overallStatusBadge.className = 'status-badge status-pending-approval';
                        }
                    } else {
                        showNotification('Error submitting approval request: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Network error submitting approval request', 'error');
                });
            }
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Status change event
            document.querySelectorAll('.status-select').forEach(select => {
                select.addEventListener('change', function() {
                    const itemId = this.getAttribute('data-item-id');
                    const status = this.value;
                    
                    updateChecklistItemStatus(itemId, status);
                });
            });
            
            // File upload event
            document.querySelectorAll('.upload-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const itemId = this.getAttribute('data-item-id');
                    const fileInput = document.querySelector(`.file-input[data-item-id="${itemId}"]`);
                    
                    if (fileInput.files.length === 0) {
                        showNotification('Please select a file to upload', 'error');
                        return;
                    }
                    
                    // Disable button and show loading
                    this.disabled = true;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Uploading...';
                    
                    uploadChecklistFile(itemId, fileInput.files[0]);
                });
            });
            
            // Add animation to checklist items
            const checklistItems = document.querySelectorAll('.checklist-item');
            checklistItems.forEach((item, index) => {
                item.style.animationDelay = `${index * 0.1}s`;
                item.classList.add('fade-in');
            });
        });
        
        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            .fade-in {
                animation: fadeInUp 0.6s ease-out forwards;
                opacity: 0;
            }
            
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .timeline {
                position: relative;
                padding-left: 30px;
            }
            
            .timeline-item {
                position: relative;
                margin-bottom: 20px;
            }
            
            .timeline-badge {
                position: absolute;
                left: -30px;
                top: 0;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 10px;
                font-weight: bold;
            }
            
            .timeline-content {
                padding: 10px;
                background: #f8f9fa;
                border-radius: 8px;
            }
            
            .status-pending-approval {
                background: #fff3cd;
                color: #856404;
            }
            
            @media print {
                .btn, .action-buttons {
                    display: none !important;
                }
                
                .section-card {
                    break-inside: avoid;
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>