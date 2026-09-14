<?php
// edit_transaction_modal.php

// This file should be included from client_details.php
// It expects these variables to be set:
// - $editTransactionData
// - $selected_user_id
// - $_SERVER['REQUEST_URI']
// - $types (account types array)

if (!isset($editTransactionData) || !$editTransactionData) {
    die("No transaction data provided.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Transaction</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            min-height: 100vh;
        }
        
        .modal-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-box {
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
        
        .close-btn {
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
        
        .close-btn:hover {
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
            
            .modal-box {
                max-height: 95vh;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="modal-container">
        <div class="modal-box">
            <div class="modal-header">
                <h3 class="modal-title">Edit Transaction</h3>
                <button type="button" class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" action="client_details.php?user_id=<?= $selected_user_id ?>" enctype="multipart/form-data">
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
                                $types = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
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

    <script>
        function closeModal() {
            // Redirect back to client details page
            window.location.href = 'client_details.php?user_id=<?= $selected_user_id ?>';
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // Close modal when clicking outside (on the overlay)
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-container')) {
                closeModal();
            }
        });
        
        // Prevent clicks inside the modal from closing it
        document.querySelector('.modal-box').addEventListener('click', function(e) {
            e.stopPropagation();
        });
    </script>
</body>
</html>