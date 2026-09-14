<?php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require 'db_connection.php';

$selected_user_id = $_GET['user_id'] ?? null;

if (!$selected_user_id) {
    die("Client ID missing.");
}

// Fetch client info
$stmt = $pdo->prepare("SELECT * FROM client WHERE id = ?");
$stmt->execute([$selected_user_id]);
$client = $stmt->fetch();

if (!$client) {
    die("Client not found.");
}

$_SESSION['user_id'] = $selected_user_id;
$_SESSION['company_name'] = $client['Company Name'];

$selected_company = $client['Company Name'];
$company = preg_replace('/[^A-Za-z0-9]/', '_', $selected_company);
$transactionsTable = "{$company}_Transactions";
$accountsTable = "{$company}_Accounts";

// Create transactions table if not exists
$checkTransactions = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($transactionsTable));
if ($checkTransactions->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE `$transactionsTable` (
            transaction_id INT AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            description VARCHAR(255) NOT NULL,
            account_name VARCHAR(255) NOT NULL,
            account_type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
            entry_type ENUM('debit', 'credit') NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            document_path VARCHAR(255) DEFAULT NULL
        )
    ");
}

// Create accounts table if not exists
$checkAccounts = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($accountsTable));
if ($checkAccounts->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE `$accountsTable` (
            account_number INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL
        ) AUTO_INCREMENT = 1
    ");
}

// Handle form submission to add a new account
$duplicateError = null;
$duplicateAccountInfo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_name'], $_POST['account_type'])) {
    $newAccountName = trim($_POST['account_name']);
    $newAccountType = $_POST['account_type'];

    if ($newAccountName !== '' && in_array($newAccountType, ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'])) {
        // Enhanced duplicate checking - check for similar names (case-insensitive, whitespace-insensitive)
        $stmt = $pdo->prepare("SELECT * FROM `$accountsTable` WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
        $stmt->execute([$newAccountName]);
        $existingAccount = $stmt->fetch();
        
        if ($existingAccount) {
            $duplicateError = "An account with this name already exists!";
            $duplicateAccountInfo = $existingAccount;
        } else {
            $stmt = $pdo->prepare("INSERT INTO `$accountsTable` (name, type) VALUES (?, ?)");
            $stmt->execute([$newAccountName, $newAccountType]);
            header("Location: " . $_SERVER['REQUEST_URI']); // Prevent form resubmission
            exit;
        }
    }
}

// Delete Account
if (isset($_POST['delete'])) {
    $deleteId = $_POST['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM `$accountsTable` WHERE account_number = ?");
    $stmt->execute([$deleteId]);
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Prepare for Edit
$editing = false;
$editData = null;
if (isset($_POST['edit'])) {
    $editId = $_POST['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM `$accountsTable` WHERE account_number = ?");
    $stmt->execute([$editId]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
    $editing = true;
}

// Save Edited Account
if (isset($_POST['save_edit'])) {
    $accountNumber = $_POST['account_number'];
    $newName = trim($_POST['account_name']);
    $newType = $_POST['account_type'];
    
    // Enhanced duplicate checking for edit
    $stmt = $pdo->prepare("SELECT * FROM `$accountsTable` WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND account_number != ?");
    $stmt->execute([$newName, $accountNumber]);
    $existingAccount = $stmt->fetch();
    
    if ($existingAccount) {
        $duplicateError = "An account with this name already exists!";
        $duplicateAccountInfo = $existingAccount;
        $editing = true;
        $editData = ['account_number' => $accountNumber, 'name' => $newName, 'type' => $newType];
    } else {
        $stmt = $pdo->prepare("UPDATE `$accountsTable` SET name = ?, type = ? WHERE account_number = ?");
        $stmt->execute([$newName, $newType, $accountNumber]);
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

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
    
    header("Location: client_details.php?user_id=" . $selected_user_id);
    exit;
}

// Fetch accounts
$stmt = $pdo->query("SELECT * FROM `$accountsTable` ORDER BY account_number ASC");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch transactions
$stmt = $pdo->prepare("SELECT * FROM `$transactionsTable` ORDER BY date DESC");
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>
<head>
    <title>Client Details - <?= htmlspecialchars($selected_company) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #4682B4;
            --light-blue: #b0c4de;
            --accent-blue: #5a96cf;
            --dark-blue: #3a6a94;
            --background: #f8f9fa;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --success-color: #28a745;
        }
        
        body {
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px;
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
        
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
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
        
        .btn-outline-primary {
            border-color: var(--accent-blue);
            color: var(--accent-blue);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--accent-blue);
            color: white;
        }
        
        .client-id {
            background-color: #e6f2ff;
            color: var(--dark-blue);
            border-radius: 20px;
            padding: 5px 15px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(176, 196, 222, 0.1);
        }
        
        .badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .collapse-header {
            cursor: pointer;
        }
        
        .chevron-icon {
            transition: transform 0.3s ease;
        }
        
        .collapsed .chevron-icon {
            transform: rotate(180deg);
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
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        
        .duplicate-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-left: 4px solid var(--warning-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: pulseWarning 1.5s ease-in-out;
        }
        
        .duplicate-warning i {
            color: var(--warning-color);
            font-size: 24px;
        }
        
        .duplicate-warning .warning-content {
            flex: 1;
        }
        
        .duplicate-warning .warning-title {
            color: #856404;
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .duplicate-warning .existing-account-info {
            background-color: white;
            border-radius: 6px;
            padding: 10px;
            margin-top: 10px;
            border: 1px dashed #ffeaa7;
        }
        
        .duplicate-warning .account-detail {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }
        
        .duplicate-warning .account-detail span {
            font-size: 13px;
        }
        
        .duplicate-warning .account-detail strong {
            color: var(--dark-blue);
        }
        
        .duplicate-suggestion {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            padding: 10px 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .duplicate-suggestion i {
            color: var(--success-color);
            margin-right: 8px;
        }
        
        /* REMOVED: All input-with-icon styling since icons are removed */
        .tooltip-custom {
            position: relative;
            display: inline-block;
        }
        
        .tooltip-custom .tooltiptext {
            visibility: hidden;
            width: 300px;
            background-color: #555;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 10px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -150px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .tooltip-custom .tooltiptext::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #555 transparent transparent transparent;
        }
        
        .tooltip-custom:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
        
        @keyframes pulseWarning {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .shake {
            animation: shake 0.5s ease-in-out;
        }
        
        .edit-mode-badge {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
        }
        
        .edit-cancel-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border: none;
            color: white;
            padding: 5px 15px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
            transition: all 0.3s ease;
        }
        
        .edit-cancel-btn:hover {
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        .status-indicator.available {
            background-color: var(--success-color);
        }
        
        .status-indicator.duplicate {
            background-color: var(--danger-color);
        }
        
        @media (max-width: 768px) {
            .doc-actions {
                flex-direction: column;
            }
            
            .doc-actions .btn {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .duplicate-warning {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .duplicate-warning .account-detail {
                flex-direction: column;
                gap: 5px;
            }
            
            .tooltip-custom .tooltiptext {
                width: 250px;
                margin-left: -125px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

    <div class="container-main">
        <!-- Back Button -->
        <a href="choose-client-modal.php" class="btn btn-back mb-4">
            <i class="fas fa-arrow-left"></i> Back to Client List
        </a>

        <!-- Header -->
        <div class="header-section">
            <h2><i class="fas fa-building"></i> <?= htmlspecialchars($selected_company) ?>
                <?php if ($editing): ?>
                    <span class="edit-mode-badge">
                        <i class="fas fa-edit"></i> Editing Account
                    </span>
                <?php endif; ?>
            </h2>
            <p class="text-muted">Client details and management</p>
        </div>

        <!-- Accounts Section -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center collapse-header"
                 data-bs-toggle="collapse" data-bs-target="#accountsCollapse">
                <div>
                    <i class="fas fa-wallet me-2"></i> Account Management
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-light text-dark me-2"><?= count($accounts) ?> accounts</span>
                    <i class="fas fa-chevron-down chevron-icon"></i>
                </div>
            </div>
            
            <div id="accountsCollapse" class="collapse show">
                <div class="card-body">
                    <div id="accountsSection">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4><?= $editing ? "Edit Account" : "Add New Account" ?></h4>
                            <?php if ($editing): ?>
                                <a href="?user_id=<?= $selected_user_id ?>" class="edit-cancel-btn">
                                    <i class="fas fa-times"></i> Cancel Edit
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Enhanced Duplicate Warning -->
                        <?php if (isset($duplicateError) && $duplicateAccountInfo): ?>
                            <div class="duplicate-warning" id="duplicateWarning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div class="warning-content">
                                    <div class="warning-title">
                                        <i class="fas fa-times-circle"></i> Account Already Exists
                                    </div>
                                    <p>The account name "<strong><?= htmlspecialchars($_POST['account_name']) ?></strong>" already exists in the system.</p>
                                    
                                    <div class="existing-account-info">
                                        <strong>Existing Account Details:</strong>
                                        <div class="account-detail">
                                            <span><strong>Account #:</strong> <?= str_pad($duplicateAccountInfo['account_number'], 3, '0', STR_PAD_LEFT) ?></span>
                                            <span><strong>Name:</strong> <?= htmlspecialchars($duplicateAccountInfo['name']) ?></span>
                                            <span><strong>Type:</strong> <?= htmlspecialchars($duplicateAccountInfo['type']) ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="duplicate-suggestion">
                                        <i class="fas fa-lightbulb"></i>
                                        <strong>Suggestion:</strong> Try using a different name or modify the existing account.
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="document.getElementById('duplicateWarning').style.display='none'">
                                    <i class="fas fa-times"></i> Dismiss
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" class="mb-4 row g-2" id="accountForm">
                            <?php if ($editing): ?>
                                <input type="hidden" name="account_number" id="account_number" value="<?= $editData['account_number'] ?>">
                            <?php endif; ?>
                            <div class="col-md-5">
                                <input type="text" name="account_name" 
                                       class="form-control <?= isset($duplicateError) ? 'is-invalid shake' : '' ?>" 
                                       placeholder="Account Title" 
                                       required
                                       value="<?= $editing ? htmlspecialchars($editData['name']) : (isset($_POST['account_name']) ? htmlspecialchars($_POST['account_name']) : '') ?>"
                                       id="accountNameInput"
                                       autocomplete="off"
                                       <?= $editing ? 'data-edit-mode="true" data-edit-id="' . $editData['account_number'] . '"' : '' ?>>
                                
                                <?php if (isset($duplicateError)): ?>
                                    <div class="invalid-feedback d-flex align-items-center">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <?= $duplicateError ?>
                                        <span class="tooltip-custom ms-2">
                                            <i class="fas fa-info-circle" style="color: #dc3545;"></i>
                                            <span class="tooltiptext">
                                                This account name already exists in the system. Please choose a different name or edit the existing account.
                                            </span>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="form-text" id="accountNameFeedback">
                                        <small>Account names must be unique (case-insensitive)</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <select name="account_type" class="form-select" required id="accountTypeSelect">
                                    <option value="">Select Type</option>
                                    <?php
                                    $types = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
                                    $currentType = $editing ? $editData['type'] : (isset($_POST['account_type']) ? $_POST['account_type'] : '');
                                    foreach ($types as $type) {
                                        $selected = ($currentType === $type) ? 'selected' : '';
                                        echo "<option value=\"$type\" $selected>$type</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <?php if ($editing): ?>
                                    <button type="submit" name="save_edit" class="btn btn-view w-100" id="submitBtn">
                                        <i class="fas fa-save me-2"></i> Save Changes
                                    </button>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-view w-100" id="submitBtn">
                                        <i class="fas fa-plus-circle me-2"></i> Add Account
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>

                        <hr>
                        <h4 class="mb-4">
                            <i class="fas fa-list me-2"></i> Existing Accounts
                            <span class="badge bg-light text-dark ms-2"><?= count($accounts) ?> total</span>
                        </h4>
                        
                        <?php if (count($accounts) > 0): ?>
                            <div class="alert alert-info d-flex align-items-center mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <small>Click on account names to see which transactions are linked to them</small>
                            </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="accountsTable">
                                <thead>
                                    <tr>
                                        <th>Account #</th>
                                        <th>Account Title</th>
                                        <th>Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($accounts) === 0): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4">
                                                <div class="text-muted">
                                                    <i class="fas fa-wallet fa-2x mb-3 d-block"></i>
                                                    No accounts added yet. Add your first account above.
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else:
                                        foreach ($accounts as $acc): 
                                            // Count transactions for this account
                                            $stmt = $pdo->prepare("SELECT COUNT(*) as transaction_count FROM `$transactionsTable` WHERE account_name = ?");
                                            $stmt->execute([$acc['name']]);
                                            $transactionCount = $stmt->fetch()['transaction_count'];
                                        ?>
                                            <tr data-account-id="<?= $acc['account_number'] ?>" 
                                                data-account-name="<?= htmlspecialchars($acc['name']) ?>"
                                                data-account-type="<?= $acc['type'] ?>">
                                                <td>
                                                    <span class="client-id"><?= str_pad($acc['account_number'], 3, '0', STR_PAD_LEFT) ?></span>
                                                </td>
                                                <td>
                                                    <div class="tooltip-custom">
                                                        <?= htmlspecialchars($acc['name']) ?>
                                                        <?php if ($transactionCount > 0): ?>
                                                            <span class="badge bg-info ms-2" style="font-size: 0.7em;">
                                                                <?= $transactionCount ?> txn
                                                            </span>
                                                        <?php endif; ?>
                                                        <span class="tooltiptext">
                                                            <strong><?= htmlspecialchars($acc['name']) ?></strong><br>
                                                            Account Type: <?= $acc['type'] ?><br>
                                                            Account #: <?= str_pad($acc['account_number'], 3, '0', STR_PAD_LEFT) ?><br>
                                                            Linked Transactions: <?= $transactionCount ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge 
                                                        <?= $acc['type'] == 'Asset' ? 'bg-success' : 
                                                           ($acc['type'] == 'Liability' ? 'bg-danger' : 
                                                           ($acc['type'] == 'Equity' ? 'bg-primary' : 
                                                           ($acc['type'] == 'Revenue' ? 'bg-warning text-dark' : 'bg-secondary'))) ?>">
                                                        <?= $acc['type'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="edit_id" value="<?= $acc['account_number'] ?>">
                                                        <button type="submit" name="edit" class="btn btn-sm btn-view" title="Edit this account">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>  <!-- End of Accounts Section -->

        <!-- Transactions Section -->
        <?php
        $currentMonth = date('Y-m');
        $filteredTransactions = array_filter($transactions, function ($txn) use ($currentMonth) {
            return strpos($txn['date'], $currentMonth) === 0;
        });
        ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-exchange-alt me-2"></i> Transactions
                </div>
                <div>
                    <span class="badge bg-light text-dark"><?= count($filteredTransactions) ?> records</span>
                </div>
            </div>
            <div class="card-body">
                <!-- Edit Transaction Modal - Loaded from separate file -->
                <?php if ($editingTransaction && $editTransactionData): ?>
                    <?php 
                    // Include the modal from separate file
                    include 'edit_transaction_modal.php';
                    ?>
                <?php endif; ?>
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Show entries:</label>
                        <select id="entriesPerPage" class="form-select w-50">
                            <option>20</option>
                            <option>50</option>
                            <option>80</option>
                            <option selected>100</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search:</label>
                        <input type="text" id="transactionSearch" class="form-control" placeholder="Search transactions...">
                    </div>
                    <div class="col-md-4 text-end">
                        <label class="form-label d-block">View:</label>
                        <a href="archive_view.php?user_id=<?= $selected_user_id ?>" class="btn btn-view">📁 Archived</a>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table id="transactionsTable" class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Entry Type</th>
                                <th>Amount</th>
                                <th>Document</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filteredTransactions) === 0): ?>
                                <tr><td colspan="8" class="text-center">No transactions found for this month.</td></tr>
                            <?php else:
                                foreach ($filteredTransactions as $txn): ?>
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
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>  <!-- End of Transactions Section -->

        <!-- Reports Section -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-bar me-2"></i> Financial Reports
            </div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <?php $baseParams = "acct={$company}_Accounts&txn={$company}_Transactions&admin=1&user_id={$selected_user_id}"; ?>
                    <a href="report_balance_sheet.php?<?= $baseParams ?>" class="btn btn-outline-primary">📊 Balance Sheet</a>
                    <a href="report_income_statement.php?<?= $baseParams ?>" class="btn btn-outline-primary">💰 Income Statement</a>
                    <a href="report_trial_balance.php?<?= $baseParams ?>" class="btn btn-outline-primary">📋 Trial Balance</a>
                    <a href="report_ledger.php?<?= $baseParams ?>" class="btn btn-outline-primary">📚 General Ledger</a>
                    <a href="report_cash_flow.php?<?= $baseParams ?>" class="btn btn-outline-primary">💸 Cash Flow</a>
                </div>
            </div>
        </div>  <!-- End of Reports Section -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const rowsPerPageSelector = document.getElementById('entriesPerPage');
            const searchInput = document.getElementById('transactionSearch');
            const table = document.getElementById('transactionsTable');
            const tbody = table.querySelector('tbody');
            let rows = Array.from(tbody.querySelectorAll('tr'));

            function filterTable() {
                const search = searchInput.value.toLowerCase();
                const limit = parseInt(rowsPerPageSelector.value);
                let count = 0;

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const show = text.includes(search) && count < limit;
                    row.style.display = show ? '' : 'none';
                    if (show) count++;
                });
            }

            searchInput.addEventListener('input', filterTable);
            rowsPerPageSelector.addEventListener('change', filterTable);
            filterTable();
            
            // Collapsible functionality
            document.querySelectorAll('.collapse-header').forEach(header => {
                header.addEventListener('click', function() {
                    const icon = this.querySelector('.chevron-icon');
                    icon.classList.toggle('fa-chevron-down');
                    icon.classList.toggle('fa-chevron-up');
                });
            });
            
            // Account name validation with real-time duplicate checking (no icons)
            const accountNameInput = document.getElementById('accountNameInput');
            const accountForm = document.getElementById('accountForm');
            const submitBtn = document.getElementById('submitBtn');
            const accountNameFeedback = document.getElementById('accountNameFeedback');
            const accountsTable = document.getElementById('accountsTable');
            
            // Get all existing accounts from the table
            let existingAccounts = [];
            if (accountsTable) {
                const accountRows = accountsTable.querySelectorAll('tbody tr[data-account-id]');
                accountRows.forEach(row => {
                    existingAccounts.push({
                        id: row.dataset.accountId,
                        name: row.dataset.accountName.toLowerCase(),
                        type: row.dataset.accountType
                    });
                });
            }
            
            function updateFeedback(message, type = '') {
                if (!accountNameFeedback) return;
                
                if (message === '') {
                    accountNameFeedback.innerHTML = '<small>Account names must be unique (case-insensitive)</small>';
                    return;
                }
                
                if (type === 'error') {
                    accountNameFeedback.innerHTML = `<small class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>${message}</small>`;
                } else if (type === 'success') {
                    accountNameFeedback.innerHTML = `<small class="text-success"><i class="fas fa-check-circle me-1"></i>${message}</small>`;
                }
            }
            
            // Real-time duplicate checking
            if (accountNameInput && accountForm) {
                const isEditing = accountNameInput.dataset.editMode === 'true';
                const editId = accountNameInput.dataset.editId;
                
                // Real-time validation
                accountNameInput.addEventListener('input', function() {
                    const accountName = this.value.trim().toLowerCase();
                    
                    // Remove shake animation class if present
                    this.classList.remove('shake', 'is-invalid');
                    
                    if (accountName === '') {
                        updateFeedback('');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.classList.remove('disabled');
                        }
                        return;
                    }
                    
                    // Check for duplicates
                    let isDuplicate = false;
                    
                    if (isEditing && editId) {
                        // When editing, exclude current account from duplicate check
                        isDuplicate = existingAccounts.some(acc => 
                            acc.name === accountName && acc.id != editId
                        );
                    } else {
                        // When adding new account
                        isDuplicate = existingAccounts.some(acc => 
                            acc.name === accountName
                        );
                    }
                    
                    // Update UI based on duplicate status
                    if (isDuplicate) {
                        this.classList.add('is-invalid', 'shake');
                        updateFeedback('Account name already exists!', 'error');
                        
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.title = "Cannot save: Account name already exists";
                            submitBtn.classList.add('disabled');
                        }
                    } else {
                        updateFeedback('Account name is available', 'success');
                        
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.title = "";
                            submitBtn.classList.remove('disabled');
                        }
                    }
                });
                
                // Also check on focus out
                accountNameInput.addEventListener('blur', function() {
                    if (this.value.trim() !== '') {
                        this.dispatchEvent(new Event('input'));
                    }
                });
                
                // Form submission validation
                accountForm.addEventListener('submit', function(e) {
                    const accountName = accountNameInput.value.trim().toLowerCase();
                    
                    if (accountName === '') {
                        e.preventDefault();
                        accountNameInput.focus();
                        accountNameInput.classList.add('is-invalid');
                        return;
                    }
                    
                    let isDuplicate = false;
                    if (isEditing && editId) {
                        isDuplicate = existingAccounts.some(acc => 
                            acc.name === accountName && acc.id != editId
                        );
                    } else {
                        isDuplicate = existingAccounts.some(acc => 
                            acc.name === accountName
                        );
                    }
                    
                    if (isDuplicate) {
                        e.preventDefault();
                        accountNameInput.classList.add('is-invalid', 'shake');
                        
                        // Show enhanced error message
                        alert("❌ Account Name Already Exists!\n\nAn account with the name \"" + accountNameInput.value + "\" already exists in the system.\n\nPlease choose a different name or edit the existing account.");
                        
                        accountNameInput.focus();
                        accountNameInput.select();
                    }
                });
            }
            
            // Auto-focus on account name input if there was a duplicate error or if editing
            <?php if (isset($duplicateError) || $editing): ?>
                setTimeout(function() {
                    const accountNameInput = document.getElementById('accountNameInput');
                    if (accountNameInput) {
                        accountNameInput.focus();
                        accountNameInput.select();
                    }
                }, 300);
            <?php endif; ?>
            
            // Add hover effect to account rows
            if (accountsTable) {
                accountsTable.querySelectorAll('tbody tr').forEach(row => {
                    row.addEventListener('mouseenter', function() {
                        this.style.backgroundColor = 'rgba(176, 196, 222, 0.15)';
                    });
                    row.addEventListener('mouseleave', function() {
                        this.style.backgroundColor = '';
                    });
                });
            }
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>