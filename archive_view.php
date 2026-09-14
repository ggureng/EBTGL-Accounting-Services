<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require 'db_connection.php';

$selected_user_id = $_GET['user_id'] ?? null;

if (!$selected_user_id) {
    die("Client ID missing.");
}

$stmt = $pdo->prepare("SELECT * FROM client WHERE id = ?");
$stmt->execute([$selected_user_id]);
$client = $stmt->fetch();

if (!$client) {
    die("Client not found.");
}

$company = preg_replace('/[^A-Za-z0-9]/', '_', $client['Company Name']);
$transactionsTable = "{$company}_Transactions";

// Handle Transaction Edit
$editingTransaction = false;
$editTransactionData = null;
if (isset($_POST['edit_transaction'])) {
    $transactionId = $_POST['transaction_id'];
    $stmt = $pdo->prepare("SELECT * FROM `$transactionsTable` WHERE transaction_id = ?");
    $stmt->execute([$transactionId]);
    $editTransactionData = $stmt->fetch(PDO::FETCH_ASSOC);
    $editingTransaction = true;
}

// Save Edited Transaction
if (isset($_POST['save_transaction_edit'])) {
    $transactionId = $_POST['transaction_id'];
    $date = $_POST['date'];
    $description = trim($_POST['description']);
    $account_name = trim($_POST['account_name']);
    $account_type = $_POST['account_type'];
    $entry_type = $_POST['entry_type'];
    $amount = $_POST['amount'];
    
    // Handle document upload
    $document_path = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/documents/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = time() . '_' . basename($_FILES['document']['name']);
        $uploadFile = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadFile)) {
            $document_path = $uploadFile;
        }
    } else {
        // Keep existing document if no new file uploaded
        $stmt = $pdo->prepare("SELECT document_path FROM `$transactionsTable` WHERE transaction_id = ?");
        $stmt->execute([$transactionId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $document_path = $existing['document_path'];
    }
    
    // Update transaction
    $stmt = $pdo->prepare("UPDATE `$transactionsTable` SET date = ?, description = ?, account_name = ?, account_type = ?, entry_type = ?, amount = ?, document_path = ? WHERE transaction_id = ?");
    $stmt->execute([$date, $description, $account_name, $account_type, $entry_type, $amount, $document_path, $transactionId]);
    
    header("Location: archive_view.php?user_id=" . $selected_user_id);
    exit;
}

// Fetch all transactions
$stmt = $pdo->query("SELECT * FROM `$transactionsTable` ORDER BY date DESC");
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentMonth = date('Y-m');

// Group non-current month transactions by YYYY_MM
$grouped = [];

foreach ($transactions as $txn) {
    $txnMonth = date('Y-m', strtotime($txn['date']));
    if ($txnMonth !== $currentMonth) {
        $folder = str_replace('-', '_', $txnMonth); // e.g., 2024_05
        $grouped[$folder][] = $txn;
    }
}

// Account types array for the modal
$types = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];

?>

<!DOCTYPE html>
<html>
<head>
    <title>Archived Transactions - <?= htmlspecialchars($client['Company Name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #4682B4;
            --light-blue: #b0c4de;
            --accent-blue: #5a96cf;
            --dark-blue: #3a6a94;
            --background: #f8f9fa;
        }
        
        body {
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px;
        }
        
        .navbar {
            background: linear-gradient(135deg, #6a8fbb, #b0c4de);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            padding: 10px 20px;
        }
        
        .navbar-brand {
            display: flex;
            align-items: center;
            font-weight: bold;
            color: white !important;
        }
        
        .container-main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .header-section {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-blue);
            position: relative;
        }
        
        .header-section h2 {
            color: var(--dark-blue);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-section h2 i {
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
        
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(70, 130, 180, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
            font-weight: 600;
            padding: 15px 20px;
            border: none;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(70, 130, 180, 0.3);
        }
        
        .btn-back {
            background: linear-gradient(135deg, #a9a9a9, #808080);
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back:hover {
            background: linear-gradient(135deg, #808080, #5a5a5a);
            color: white;
            transform: translateY(-2px);
        }
        
        .client-id {
            background-color: #e6f2ff;
            color: var(--dark-blue);
            border-radius: 20px;
            padding: 5px 15px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
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
        }
        
        .stat-content p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .filter-btn {
            background-color: #e6f2ff;
            border: none;
            border-radius: 20px;
            padding: 8px 20px;
            color: var(--dark-blue);
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(176, 196, 222, 0.1);
        }
        
        .badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .stats-container {
                flex-direction: column;
            }
        }
        
        .collapse-content {
            transition: all 0.3s ease;
        }
        
        .collapsed .chevron-icon {
            transform: rotate(180deg);
        }
        
        .collapse-header {
            cursor: pointer;
        }
        
        .chevron-icon {
            transition: transform 0.3s ease;
        }
        
        .doc-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .doc-btn {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .archive-card-header {
            background: linear-gradient(135deg, #8a9aaf, #a3b8d0) !important;
            color: #fff;
        }
        
        /* Edit Transaction Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-container {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #5a96cf, #4682B4);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }
        
        .modal-close:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            padding: 20px 25px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            border-radius: 0 0 12px 12px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }
        
        .form-label {
            font-weight: 600;
            color: #3a6a94;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control-custom {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ced4da;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.15s;
        }
        
        .form-control-custom:focus {
            border-color: #5a96cf;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(90, 150, 207, 0.25);
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .form-col {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0 10px;
            margin-bottom: 20px;
        }
        
        .document-box {
            background: #f8f9fa;
            border: 1px dashed #ced4da;
            border-radius: 8px;
            padding: 15px;
            margin-top: 5px;
        }
        
        .document-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #495057;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .btn-save {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn-save:hover {
            background: #218838;
        }
        
        .btn-view-sm {
            background: #5a96cf;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-view-sm:hover {
            background: #4682B4;
            color: white;
        }
        
        .required::after {
            content: " *";
            color: #dc3545;
        }
        
        @media (max-width: 768px) {
            .form-col {
                flex: 0 0 100%;
                max-width: 100%;
            }
            
            .modal-container {
                max-height: 95vh;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer button {
                width: 100%;
            }
            
            .doc-actions {
                flex-direction: column;
            }
            
            .doc-actions .btn {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>

    <div class="container-main">
        <!-- Back Button -->
        <a href="client_details.php?user_id=<?= $selected_user_id ?>" class="btn btn-back mb-4">
            <i class="fas fa-arrow-left"></i> Back to Client Details
        </a>

        <!-- Header -->
        <div class="header-section">
            <h2><i class="fas fa-archive"></i> Archived Transactions for <?= htmlspecialchars($client['Company Name']) ?></h2>
            <p class="text-muted">Browse historical financial records</p>
        </div>

        <!-- Edit Transaction Modal -->
        <?php if ($editingTransaction && $editTransactionData): ?>
            <div class="modal-overlay" id="editTransactionModal">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3 class="modal-title">Edit Transaction</h3>
                        <button type="button" class="modal-close" onclick="closeModal()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="transaction_id" value="<?= $editTransactionData['transaction_id'] ?>">
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label required">Date</label>
                                    <input type="date" name="date" class="form-control-custom" 
                                           value="<?= htmlspecialchars($editTransactionData['date']) ?>" 
                                           required>
                                </div>
                                <div class="form-col">
                                    <label class="form-label required">Amount (₱)</label>
                                    <input type="number" name="amount" class="form-control-custom" 
                                           step="0.01" min="0" 
                                           value="<?= htmlspecialchars($editTransactionData['amount']) ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div style="margin-bottom: 20px;">
                                <label class="form-label required">Description</label>
                                <input type="text" name="description" class="form-control-custom" 
                                       value="<?= htmlspecialchars($editTransactionData['description']) ?>" 
                                       required>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label required">Account Name</label>
                                    <input type="text" name="account_name" class="form-control-custom" 
                                           value="<?= htmlspecialchars($editTransactionData['account_name']) ?>" 
                                           required>
                                </div>
                                <div class="form-col">
                                    <label class="form-label required">Account Type</label>
                                    <select name="account_type" class="form-control-custom" required>
                                        <?php
                                        foreach ($types as $type) {
                                            $selected = ($editTransactionData['account_type'] === $type) ? 'selected' : '';
                                            echo "<option value=\"$type\" $selected>$type</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-col">
                                    <label class="form-label required">Entry Type</label>
                                    <select name="entry_type" class="form-control-custom" required>
                                        <option value="debit" <?= $editTransactionData['entry_type'] === 'debit' ? 'selected' : '' ?>>Debit</option>
                                        <option value="credit" <?= $editTransactionData['entry_type'] === 'credit' ? 'selected' : '' ?>>Credit</option>
                                    </select>
                                </div>
                                <div class="form-col">
                                    <label class="form-label">Supporting Document</label>
                                    <input type="file" name="document" class="form-control-custom" 
                                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                    <small class="text-muted" style="display: block; margin-top: 5px;">Leave empty to keep existing document</small>
                                </div>
                            </div>
                            
                            <?php if (!empty($editTransactionData['document_path'])): ?>
                                <div style="margin-top: 15px;">
                                    <label class="form-label">Current Document:</label>
                                    <div class="document-box">
                                        <div class="document-info">
                                            <i class="fas fa-file"></i>
                                            <span><?= basename($editTransactionData['document_path']) ?></span>
                                            <div style="margin-left: auto; display: flex; gap: 5px;">
                                                <a href="<?= htmlspecialchars($editTransactionData['document_path']) ?>" 
                                                   target="_blank" 
                                                   class="btn-view-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="<?= htmlspecialchars($editTransactionData['document_path']) ?>" 
                                                   download 
                                                   class="btn-view-sm">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-cancel" onclick="closeModal()">
                                Cancel
                            </button>
                            <button type="submit" name="save_transaction_edit" class="btn-save">
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($grouped)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox fa-3x mb-3 text-muted"></i>
                    <h4 class="text-muted">No Archived Transactions Found</h4>
                    <p class="text-muted">All transactions are in the current month</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= count($grouped) ?></h3>
                            <p>Archived Months</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= count($transactions) ?></h3>
                            <p>Total Transactions</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?= htmlspecialchars($client['Company Name']) ?></h3>
                            <p>Client</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php foreach ($grouped as $month_year => $txns): 
                // Convert YYYY_MM to Month Year format
                $parts = explode('_', $month_year);
                $dateObj = DateTime::createFromFormat('!m', $parts[1]);
                $monthName = $dateObj->format('F');
                $displayDate = $monthName . ' ' . $parts[0];
            ?>
                <div class="card">
                    <div class="card-header archive-card-header d-flex justify-content-between align-items-center collapse-header"
                         data-bs-toggle="collapse" data-bs-target="#collapse<?= $month_year ?>">
                        <div>
                            <i class="fas fa-folder me-2"></i> <?= $displayDate ?>
                        </div>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-light text-dark me-2"><?= count($txns) ?> transactions</span>
                            <i class="fas fa-chevron-down chevron-icon"></i>
                        </div>
                    </div>
                    
                    <div id="collapse<?= $month_year ?>" class="collapse show">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Account</th>
                                            <th>Type</th>
                                            <th>Entry</th>
                                            <th>Amount</th>
                                            <th>Document</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($txns as $txn): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($txn['date']) ?></td>
                                            <td><?= htmlspecialchars($txn['description']) ?></td>
                                            <td><?= htmlspecialchars($txn['account_name']) ?></td>
                                            <td><?= htmlspecialchars($txn['account_type']) ?></td>
                                            <td><?= htmlspecialchars($txn['entry_type']) ?></td>
                                            <td>₱<?= number_format($txn['amount'], 2) ?></td>
                                            <td>
                                                <?php if (!empty($txn['document_path'])): ?>
                                                    <div class="doc-actions">
                                                        <a href="<?= htmlspecialchars($txn['document_path']) ?>" 
                                                           target="_blank" 
                                                           class="btn btn-sm btn-view doc-btn"
                                                           title="View document">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="<?= htmlspecialchars($txn['document_path']) ?>" 
                                                           download 
                                                           class="btn btn-sm btn-view doc-btn"
                                                           title="Download document">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </div>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="doc-actions">
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="transaction_id" value="<?= $txn['transaction_id'] ?>">
                                                        <button type="submit" name="edit_transaction" class="btn btn-sm btn-view doc-btn" title="Edit transaction">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Add collapsible functionality
            document.querySelectorAll('.collapse-header').forEach(header => {
                header.addEventListener('click', function() {
                    const icon = this.querySelector('.chevron-icon');
                    icon.classList.toggle('fa-chevron-down');
                    icon.classList.toggle('fa-chevron-up');
                });
            });
            
            // Close modal function
            window.closeModal = function() {
                window.location.href = 'archive_view.php?user_id=<?= $selected_user_id ?>';
            };
            
            // Close modal when clicking outside (on the overlay)
            document.addEventListener('click', function(e) {
                const modal = document.getElementById('editTransactionModal');
                if (modal && e.target === modal) {
                    closeModal();
                }
            });
            
            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                }
            });
            
            // Prevent clicks inside the modal from closing it
            const modalContainer = document.querySelector('.modal-container');
            if (modalContainer) {
                modalContainer.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
            // Prevent body scrolling when modal is open
            const modal = document.getElementById('editTransactionModal');
            if (modal) {
                document.body.style.overflow = 'hidden';
            }
        });
    </script>
</body>
</html>