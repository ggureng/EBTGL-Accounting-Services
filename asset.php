<?php
// Correct function names and syntax:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require 'db_connection.php';
require 'functions.php';

// Enable detailed error logging
error_log("=== ACCOUNT ACTIVATION DEBUG ===");

if (isset($_GET['modal'])) {
    ob_start(); // Correct output buffering function
}

$user_id = $_SESSION['user_id'] ?? null;
$company_name = $_SESSION['company_name'] ?? null;

if (!$user_id) die("User not logged in.");
if (!$company_name) die("Company name not set in session.");

$company = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
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

// Create accounts table if not exists (EMPTY - no default accounts)
$checkAccounts = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($accountsTable));
if ($checkAccounts->rowCount() === 0) {
    $pdo->exec("
        CREATE TABLE `$accountsTable` (
            account_number INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL
        ) AUTO_INCREMENT = 1
    ");
    // REMOVED: Default accounts insertion - table will be empty initially
}

// Handle bankruptcy checklist file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_checklist_file') {
    try {
        $item_id = $_POST['item_id'] ?? null;
        if (!$item_id) {
            throw new Exception("Item ID is required.");
        }

        // Check if a file was uploaded
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed.");
        }

        $uploadDir = 'bankruptcy_uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate a unique filename to avoid conflicts
        $filename = time() . '_' . basename($_FILES['document']['name']);
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetPath)) {
            // Update the checklist item with the file path - REMOVED last_updated since we have updated_at
            $stmt = $pdo->prepare("UPDATE bankruptcy_checklist SET file_path = ? WHERE id = ? AND user_id = ? AND company_name = ?");
            $stmt->execute([$targetPath, $item_id, $user_id, $company_name]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'File uploaded successfully.']);
            } else {
                throw new Exception("Failed to update checklist item.");
            }
        } else {
            throw new Exception("Failed to move uploaded file.");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle checklist item status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_checklist_item') {
    try {
        $item_id = $_POST['item_id'] ?? null;
        $status = $_POST['status'] ?? null;
        
        if (!$item_id || !$status) {
            throw new Exception("Item ID and status are required.");
        }

        // Update the checklist item status - REMOVED last_updated since we have updated_at
        $stmt = $pdo->prepare("UPDATE bankruptcy_checklist SET status = ? WHERE id = ? AND user_id = ? AND company_name = ?");
        $stmt->execute([$status, $item_id, $user_id, $company_name]);

        if ($stmt->rowCount() > 0) {
            // Check if all items are completed
            $checkAllStmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed FROM bankruptcy_checklist WHERE user_id = ? AND company_name = ?");
            $checkAllStmt->execute([$user_id, $company_name]);
            $completion = $checkAllStmt->fetch(PDO::FETCH_ASSOC);
            
            $all_completed = ($completion['total'] > 0 && $completion['completed'] == $completion['total']);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Status updated successfully.',
                'all_completed' => $all_completed
            ]);
        } else {
            throw new Exception("Failed to update item status.");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// NEW: Handle default accounts selection FIRST, before getting accounts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate_default_accounts') {
    try {
        error_log("Starting account activation process");
        
        $selectedAccounts = $_POST['accounts'] ?? [];
        error_log("Selected accounts count: " . count($selectedAccounts));
        error_log("Selected accounts: " . print_r($selectedAccounts, true));
        
        if (empty($selectedAccounts)) {
            throw new Exception("Please select at least one account to activate.");
        }
        
        // FIX: Check if we received a single string and split it
        if (count($selectedAccounts) === 1 && strpos($selectedAccounts[0], ',') !== false) {
            error_log("Detected comma-separated string, splitting accounts");
            $selectedAccounts = explode(',', $selectedAccounts[0]);
            error_log("After splitting: " . print_r($selectedAccounts, true));
        }
        
        // Verify table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($accountsTable));
        if ($tableCheck->rowCount() === 0) {
            throw new Exception("Accounts table does not exist: $accountsTable");
        }
        
        // Insert selected accounts
        $stmt = $pdo->prepare("INSERT INTO `$accountsTable` (name, type) VALUES (?, ?)");
        $insertedCount = 0;
        $errors = [];
        
        foreach ($selectedAccounts as $accountId) {
            // Clean up the account ID in case there are spaces
            $accountId = trim($accountId);
            
            // Get account details from our comprehensive default accounts list
            $defaultAccount = getDefaultAccountById($accountId);
            if ($defaultAccount) {
                error_log("Inserting account: {$defaultAccount['name']} - {$defaultAccount['type']}");
                
                try {
                    $result = $stmt->execute([$defaultAccount['name'], $defaultAccount['type']]);
                    if ($result) {
                        $insertedCount++;
                        error_log("Successfully inserted: {$defaultAccount['name']}");
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        $errors[] = "Failed to insert {$defaultAccount['name']}: " . $errorInfo[2];
                        error_log("Insert failed for {$defaultAccount['name']}: " . $errorInfo[2]);
                    }
                } catch (PDOException $e) {
                    // Check if it's a duplicate entry error
                    if ($e->errorInfo[1] == 1062) {
                        error_log("Duplicate account skipped: {$defaultAccount['name']}");
                        $errors[] = "Account already exists: {$defaultAccount['name']}";
                    } else {
                        $errors[] = "Database error for {$defaultAccount['name']}: " . $e->getMessage();
                        error_log("PDOException for {$defaultAccount['name']}: " . $e->getMessage());
                    }
                }
            } else {
                $errors[] = "Account not found for ID: $accountId";
                error_log("Account not found: $accountId");
            }
        }
        
        if ($insertedCount === 0 && !empty($errors)) {
            throw new Exception("No accounts were inserted. Errors: " . implode("; ", $errors));
        }
        
        if (!empty($errors)) {
            error_log("Some accounts had errors: " . implode("; ", $errors));
            // Continue anyway if at least some accounts were inserted
        }
        
        error_log("Successfully inserted $insertedCount accounts");
        
        echo json_encode([
            'success' => true, 
            'message' => "$insertedCount accounts activated successfully!",
            'inserted' => $insertedCount,
            'errors' => $errors
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("Error in account activation: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'debug' => [
                'table' => $accountsTable,
                'selected_count' => count($selectedAccounts ?? []),
                'selected_accounts' => $selectedAccounts ?? []
            ]
        ]);
        exit;
    }
}

// Get accounts AFTER handling any potential activation
$accounts = getAccounts($pdo, $accountsTable);

// Handle transaction form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['date']) && isset($_POST['description'])) {
    // Check if accounts exist
    if (empty($accounts)) {
        $error_message = "Please activate accounts first before recording transactions.";
    } else {
        try {
            $pdo->beginTransaction();
            
            $date = $_POST['date'];
            $description = $_POST['description'];
            
            // Handle file upload
            $document_path = null;
            if (isset($_FILES['document']) && $_FILES['document']['error'][0] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $filename = time() . '_' . basename($_FILES['document']['name'][0]);
                $targetPath = $uploadDir . $filename;
                
                if (move_uploaded_file($_FILES['document']['tmp_name'][0], $targetPath)) {
                    $document_path = $targetPath;
                }
            }
            
            // Process each entry
            $total_debit = 0;
            $total_credit = 0;
            $entries = $_POST['entries'];
            
            foreach ($entries as $entry) {
                if (!empty($entry['account_id']) && !empty($entry['amount'])) {
                    // Get account details
                    $stmt = $pdo->prepare("SELECT name, type FROM `$accountsTable` WHERE account_number = ?");
                    $stmt->execute([$entry['account_id']]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($account) {
                        $amount = str_replace(',', '', $entry['amount']);
                        $amount = floatval($amount);
                        
                        // Insert transaction
                        $stmt = $pdo->prepare("INSERT INTO `$transactionsTable` (date, description, account_name, account_type, entry_type, amount, document_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $date,
                            $description,
                            $account['name'],
                            $account['type'],
                            $entry['type'],
                            $amount,
                            $document_path
                        ]);
                        
                        // Track totals for validation
                        if ($entry['type'] === 'debit') {
                            $total_debit += $amount;
                        } else {
                            $total_credit += $amount;
                        }
                    }
                }
            }
            
            // Validate debit and credit totals
            if (abs($total_debit - $total_credit) > 0.01) {
                throw new Exception("Debits and credits don't balance. Debit: $total_debit, Credit: $total_credit");
            }
            
            $pdo->commit();
            
            // Redirect to avoid form resubmission
            header("Location: asset.php?success=1");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error recording transaction: " . $e->getMessage();
        }
    }
}

// NEW: Function to get comprehensive default accounts
function getDefaultAccounts() {
    return [
        // Assets
        ['id' => 'cash', 'name' => 'Cash', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'cash_on_hand', 'name' => 'Cash on Hand', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'cash_in_bank', 'name' => 'Cash in Bank', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'petty_cash', 'name' => 'Petty Cash', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'accounts_receivable', 'name' => 'Accounts Receivable', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'allowance_doubtful', 'name' => 'Allowance for Doubtful Accounts', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'notes_receivable', 'name' => 'Notes Receivable', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'inventory', 'name' => 'Inventory', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'raw_materials', 'name' => 'Raw Materials Inventory', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'work_in_process', 'name' => 'Work in Process Inventory', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'finished_goods', 'name' => 'Finished Goods Inventory', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'prepaid_insurance', 'name' => 'Prepaid Insurance', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'prepaid_rent', 'name' => 'Prepaid Rent', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'prepaid_supplies', 'name' => 'Prepaid Supplies', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'prepaid_taxes', 'name' => 'Prepaid Taxes', 'type' => 'Asset', 'category' => 'Current Assets'],
        ['id' => 'short_term_investments', 'name' => 'Short-term Investments', 'type' => 'Asset', 'category' => 'Current Assets'],
        
        // Fixed Assets
        ['id' => 'land', 'name' => 'Land', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'buildings', 'name' => 'Buildings', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'accum_depreciation_buildings', 'name' => 'Accumulated Depreciation - Buildings', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'equipment', 'name' => 'Equipment', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'accum_depreciation_equipment', 'name' => 'Accumulated Depreciation - Equipment', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'vehicles', 'name' => 'Vehicles', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'accum_depreciation_vehicles', 'name' => 'Accumulated Depreciation - Vehicles', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'furniture_fixtures', 'name' => 'Furniture and Fixtures', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'accum_depreciation_furniture', 'name' => 'Accumulated Depreciation - Furniture', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'computer_equipment', 'name' => 'Computer Equipment', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'accum_depreciation_computer', 'name' => 'Accumulated Depreciation - Computer Equipment', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'leasehold_improvements', 'name' => 'Leasehold Improvements', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        ['id' => 'accum_depreciation_leasehold', 'name' => 'Accumulated Depreciation - Leasehold Improvements', 'type' => 'Asset', 'category' => 'Fixed Assets'],
        
        // Intangible Assets
        ['id' => 'patents', 'name' => 'Patents', 'type' => 'Asset', 'category' => 'Intangible Assets'],
        ['id' => 'copyrights', 'name' => 'Copyrights', 'type' => 'Asset', 'category' => 'Intangible Assets'],
        ['id' => 'trademarks', 'name' => 'Trademarks', 'type' => 'Asset', 'category' => 'Intangible Assets'],
        ['id' => 'goodwill', 'name' => 'Goodwill', 'type' => 'Asset', 'category' => 'Intangible Assets'],
        ['id' => 'licenses', 'name' => 'Licenses', 'type' => 'Asset', 'category' => 'Intangible Assets'],
        
        // Other Assets
        ['id' => 'long_term_investments', 'name' => 'Long-term Investments', 'type' => 'Asset', 'category' => 'Other Assets'],
        ['id' => 'security_deposits', 'name' => 'Security Deposits', 'type' => 'Asset', 'category' => 'Other Assets'],
        ['id' => 'deferred_tax_assets', 'name' => 'Deferred Tax Assets', 'type' => 'Asset', 'category' => 'Other Assets'],
        
        // Liabilities - Current
        ['id' => 'accounts_payable', 'name' => 'Accounts Payable', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'notes_payable', 'name' => 'Notes Payable', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'accrued_expenses', 'name' => 'Accrued Expenses', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'accrued_wages', 'name' => 'Accrued Wages', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'accrued_payroll_taxes', 'name' => 'Accrued Payroll Taxes', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'accrued_interest', 'name' => 'Accrued Interest Payable', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'unearned_revenue', 'name' => 'Unearned Revenue', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'sales_tax_payable', 'name' => 'Sales Tax Payable', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'income_tax_payable', 'name' => 'Income Tax Payable', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'current_portion_long_term_debt', 'name' => 'Current Portion of Long-term Debt', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        ['id' => 'dividends_payable', 'name' => 'Dividends Payable', 'type' => 'Liability', 'category' => 'Current Liabilities'],
        
        // Liabilities - Long-term
        ['id' => 'loans_payable', 'name' => 'Loans Payable', 'type' => 'Liability', 'category' => 'Long-term Liabilities'],
        ['id' => 'bonds_payable', 'name' => 'Bonds Payable', 'type' => 'Liability', 'category' => 'Long-term Liabilities'],
        ['id' => 'mortgage_payable', 'name' => 'Mortgage Payable', 'type' => 'Liability', 'category' => 'Long-term Liabilities'],
        ['id' => 'deferred_tax_liability', 'name' => 'Deferred Tax Liability', 'type' => 'Liability', 'category' => 'Long-term Liabilities'],
        ['id' => 'lease_liability', 'name' => 'Lease Liability', 'type' => 'Liability', 'category' => 'Long-term Liabilities'],
        
        // Equity
        ['id' => 'common_stock', 'name' => 'Common Stock', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'preferred_stock', 'name' => 'Preferred Stock', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'additional_paid_in_capital', 'name' => 'Additional Paid-in Capital', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'treasury_stock', 'name' => 'Treasury Stock', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'retained_earnings', 'name' => 'Retained Earnings', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'dividends', 'name' => 'Dividends', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'drawings', 'name' => 'Drawings', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'owner_capital', 'name' => 'Owner\'s Capital', 'type' => 'Equity', 'category' => 'Equity'],
        ['id' => 'owner_withdrawals', 'name' => 'Owner\'s Withdrawals', 'type' => 'Equity', 'category' => 'Equity'],
        
        // Revenue
        ['id' => 'sales_revenue', 'name' => 'Sales Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'service_revenue', 'name' => 'Service Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'consulting_revenue', 'name' => 'Consulting Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'commission_revenue', 'name' => 'Commission Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'rent_revenue', 'name' => 'Rent Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'interest_revenue', 'name' => 'Interest Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'dividend_revenue', 'name' => 'Dividend Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'royalty_revenue', 'name' => 'Royalty Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        ['id' => 'other_operating_revenue', 'name' => 'Other Operating Revenue', 'type' => 'Revenue', 'category' => 'Revenue'],
        
        // Expenses - Operating
        ['id' => 'salaries_expense', 'name' => 'Salaries Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'wages_expense', 'name' => 'Wages Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'rent_expense', 'name' => 'Rent Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'utilities_expense', 'name' => 'Utilities Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'electricity_expense', 'name' => 'Electricity Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'water_expense', 'name' => 'Water Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'internet_expense', 'name' => 'Internet Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'telephone_expense', 'name' => 'Telephone Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'office_supplies_expense', 'name' => 'Office Supplies Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'printing_stationery', 'name' => 'Printing and Stationery', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'postage_shipping', 'name' => 'Postage and Shipping', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'advertising_expense', 'name' => 'Advertising Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'marketing_expense', 'name' => 'Marketing Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'travel_expense', 'name' => 'Travel Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'meals_entertainment', 'name' => 'Meals and Entertainment', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'vehicle_expense', 'name' => 'Vehicle Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'fuel_expense', 'name' => 'Fuel Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'maintenance_repairs', 'name' => 'Maintenance and Repairs', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'insurance_expense', 'name' => 'Insurance Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'professional_fees', 'name' => 'Professional Fees', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'legal_fees', 'name' => 'Legal Fees', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'accounting_fees', 'name' => 'Accounting Fees', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'bank_charges', 'name' => 'Bank Charges', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'depreciation_expense', 'name' => 'Depreciation Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'amortization_expense', 'name' => 'Amortization Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        ['id' => 'bad_debt_expense', 'name' => 'Bad Debt Expense', 'type' => 'Expense', 'category' => 'Operating Expenses'],
        
        // Expenses - Cost of Goods Sold
        ['id' => 'cost_of_goods_sold', 'name' => 'Cost of Goods Sold', 'type' => 'Expense', 'category' => 'Cost of Goods Sold'],
        ['id' => 'purchases', 'name' => 'Purchases', 'type' => 'Expense', 'category' => 'Cost of Goods Sold'],
        ['id' => 'freight_in', 'name' => 'Freight In', 'type' => 'Expense', 'category' => 'Cost of Goods Sold'],
        ['id' => 'purchase_returns', 'name' => 'Purchase Returns', 'type' => 'Expense', 'category' => 'Cost of Goods Sold'],
        ['id' => 'purchase_discounts', 'name' => 'Purchase Discounts', 'type' => 'Expense', 'category' => 'Cost of Goods Sold'],
        
        // Expenses - Other
        ['id' => 'interest_expense', 'name' => 'Interest Expense', 'type' => 'Expense', 'category' => 'Other Expenses'],
        ['id' => 'income_tax_expense', 'name' => 'Income Tax Expense', 'type' => 'Expense', 'category' => 'Other Expenses'],
        ['id' => 'loss_on_disposal', 'name' => 'Loss on Disposal of Assets', 'type' => 'Expense', 'category' => 'Other Expenses'],
        ['id' => 'foreign_currency_loss', 'name' => 'Foreign Currency Loss', 'type' => 'Expense', 'category' => 'Other Expenses'],
        ['id' => 'donations', 'name' => 'Donations', 'type' => 'Expense', 'category' => 'Other Expenses'],
        ['id' => 'miscellaneous_expense', 'name' => 'Miscellaneous Expense', 'type' => 'Expense', 'category' => 'Other Expenses']
    ];
}

// NEW: Function to get default account by ID
function getDefaultAccountById($id) {
    $defaultAccounts = getDefaultAccounts();
    foreach ($defaultAccounts as $account) {
        if ($account['id'] === $id) {
            return $account;
        }
    }
    return null;
}

// NEW: Check if accounts table is empty (first time access)
$isAccountsEmpty = empty($accounts);

// Bankruptcy Detection System
function detectBankruptcy($pdo, $accountsTable, $transactionsTable) {
    // Get financial ratios for analysis
    $ratios = calculateFinancialRatios($pdo, $accountsTable, $transactionsTable);
    
    // Simple bankruptcy prediction algorithm (Altman Z-score inspired)
    $z_score = calculateZScore($ratios);
    
    // Bankruptcy threshold (Z-score < 1.8 indicates distress)
    $bankruptcy_probability = 0;
    
    if ($z_score < 1.8) {
        // High probability of bankruptcy
        $bankruptcy_probability = 0.8 + (1.8 - $z_score) * 0.2; // Scale between 0.8-1.0
    } elseif ($z_score < 2.99) {
        // Gray area - moderate risk
        $bankruptcy_probability = 0.3 + (2.99 - $z_score) * 0.5 / 1.19; // Scale between 0.3-0.8
    } else {
        // Safe zone
        $bankruptcy_probability = max(0, 0.3 - ($z_score - 2.99) * 0.1); // Scale between 0-0.3
    }
    
    return [
        'probability' => $bankruptcy_probability,
        'z_score' => $z_score,
        'ratios' => $ratios,
        'is_bankrupt' => $bankruptcy_probability > 0.7 // Threshold for disabling transactions
    ];
}

// FIXED: Remove the $type parameter from calculateFinancialRatios function
function calculateFinancialRatios($pdo, $accountsTable, $transactionsTable) {
    // Get account balances
    $assets = getAccountBalancesByType($pdo, $accountsTable, $transactionsTable, 'Asset');
    $liabilities = getAccountBalancesByType($pdo, $accountsTable, $transactionsTable, 'Liability');
    $equity = getAccountBalancesByType($pdo, $accountsTable, $transactionsTable, 'Equity');
    $revenue = getAccountBalancesByType($pdo, $accountsTable, $transactionsTable, 'Revenue');
    $expenses = getAccountBalancesByType($pdo, $accountsTable, $transactionsTable, 'Expense');
    
    // Calculate key financial metrics
    $total_assets = array_sum(array_values($assets));
    $total_liabilities = array_sum(array_values($liabilities));
    $total_equity = array_sum(array_values($equity));
    $net_income = array_sum(array_values($revenue)) - array_sum(array_values($expenses));
    
    // Calculate financial ratios
    $ratios = [];
    
    // Current Ratio (Current Assets / Current Liabilities)
    // Simplified: Using total assets and liabilities
    $ratios['current_ratio'] = $total_liabilities > 0 ? $total_assets / $total_liabilities : 0;
    
    // Debt to Equity Ratio
    $ratios['debt_to_equity'] = $total_equity > 0 ? $total_liabilities / $total_equity : ($total_liabilities > 0 ? 10 : 0); // High if no equity
    
    // Return on Assets
    $ratios['return_on_assets'] = $total_assets > 0 ? $net_income / $total_assets : 0;
    
    // Working Capital to Total Assets
    $working_capital = $total_assets - $total_liabilities;
    $ratios['working_capital_ratio'] = $total_assets > 0 ? $working_capital / $total_assets : 0;
    
    // Retained Earnings to Total Assets
    // Simplified: Using equity as proxy for retained earnings
    $ratios['retained_earnings_ratio'] = $total_assets > 0 ? $total_equity / $total_assets : 0;
    
    return $ratios;
}

function calculateZScore($ratios) {
    // Simplified Altman Z-score calculation
    // Original formula: Z = 1.2A + 1.4B + 3.3C + 0.6D + 1.0E
    
    // Using available ratios with simplified coefficients
    $z_score = 
        (1.2 * $ratios['working_capital_ratio']) +
        (1.4 * $ratios['retained_earnings_ratio']) +
        (3.3 * $ratios['return_on_assets']) +
        (0.6 * (1 / max(0.1, $ratios['debt_to_equity']))) + // Inverse of debt-to-equity as proxy
        (1.0 * 0.5); // Simplified sales ratio (constant for demo)
    
    return $z_score;
}

function getAccountBalancesByType($pdo, $accountsTable, $transactionsTable, $type) {
    $stmt = $pdo->prepare("
        SELECT a.account_number, a.name, 
               COALESCE(SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE 0 END), 0) as total_debit,
               COALESCE(SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE 0 END), 0) as total_credit
        FROM `$accountsTable` a
        LEFT JOIN `$transactionsTable` t ON a.name = t.account_name
        WHERE a.type = ?
        GROUP BY a.account_number, a.name
    ");
    $stmt->execute([$type]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $balances = [];
    foreach ($accounts as $account) {
        // Calculate balance based on account type
        if ($type === 'Asset' || $type === 'Expense') {
            $balance = $account['total_debit'] - $account['total_credit'];
        } else {
            $balance = $account['total_credit'] - $account['total_debit'];
        }
        $balances[$account['name']] = $balance;
    }
    
    return $balances;
}

// Check bankruptcy status
$bankruptcy_status = detectBankruptcy($pdo, $accountsTable, $transactionsTable);
$is_bankrupt = $bankruptcy_status['is_bankrupt'];

// NEW: Check bankruptcy filing status
$filingStatus = getBankruptcyFilingStatus($pdo, $company_name, $user_id);
$checklistCompleted = $filingStatus['checklist_completed'] ?? false;
$accessGranted = $filingStatus['access_granted'] ?? false;

// Function to get bankruptcy filing status
function getBankruptcyFilingStatus($pdo, $company_name, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM bankruptcy_filing_status WHERE company_name = ? AND user_id = ?");
    $stmt->execute([$company_name, $user_id]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$status) {
        // Create initial filing status
        $stmt = $pdo->prepare("INSERT INTO bankruptcy_filing_status (company_name, user_id, filing_type, overall_status) VALUES (?, ?, 'Rehabilitation', 'In Progress')");
        $stmt->execute([$company_name, $user_id]);
        
        // Create checklist items
        initializeBankruptcyChecklist($pdo, $company_name, $user_id);
        
        return ['checklist_completed' => false, 'access_granted' => false];
    }
    
    return $status;
}

// Function to initialize bankruptcy checklist
function initializeBankruptcyChecklist($pdo, $company_name, $user_id) {
    $checklistItems = [
        // Section A: Basic Information
        ['A', 'Debtor Name', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Trade Name', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Business Address', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Contact Information', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Business Nature', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Type of Filing', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Filing Date', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+7 days'))],
        
        // Section B: Statement of Insolvency
        ['B', 'Verified Statement of Insolvency', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+14 days'))],
        ['B', 'Narrative of Insolvency Causes', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+14 days'))],
        ['B', 'Relief Sought', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+14 days'))],
        
        // Section C: Financial Documents
        ['C', 'Audited Financial Statements (3 Years)', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Latest Interim Financial Statement', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Cash Flow Statement', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Schedule of Debts & Liabilities', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Inventory of Assets', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Receivables & Payables List', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Bank Statements', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        ['C', 'Income Tax Returns (3 Years)', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+21 days'))],
        
        // Section D: Legal & Corporate Documents
        ['D', 'Articles of Incorporation / Partnership', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+28 days'))],
        ['D', 'Latest General Information Sheet (GIS)', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+28 days'))],
        ['D', 'Board Resolution', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+28 days'))],
        ['D', 'Secretary\'s Certificate', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+28 days'))],
        ['D', 'Business Permits & Licenses', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+28 days'))],
        
        // Section E: Petition Documents
        ['E', 'Verified Petition', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+35 days'))],
        ['E', 'Annexes and Schedules', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+35 days'))],
        ['E', 'Nominees (3)', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+35 days'))],
        ['E', 'Creditor Notification List', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+35 days'))],
        ['E', 'Rehabilitation Plan', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+35 days'))],
        
        // Section F: Supporting Attachments
        ['F', 'Property Titles / Deeds', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+42 days'))],
        ['F', 'Inventory Photos / Appraisal', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+42 days'))],
        ['F', 'List of Pending Cases', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+42 days'))],
        ['F', 'Certificate of Non-Forum Shopping', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+42 days'))],
        ['F', 'Affidavit of Service', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+42 days'))],
        
        // Section G: Additional Documents
        ['G', 'Feasibility Study', 'Not Started', 'Management', date('Y-m-d', strtotime('+49 days'))],
        ['G', 'Restructuring Plan', 'Not Started', 'Management', date('Y-m-d', strtotime('+49 days'))],
        ['G', 'Projected Cash Flow', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+49 days'))],
        ['G', 'Recovery Timeline', 'Not Started', 'Management', date('Y-m-d', strtotime('+49 days'))],
        
        // Section H: Post-Filing Tasks
        ['H', 'File Petition at RTC', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+56 days'))],
        ['H', 'Pay Filing Fees & Stamps', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+56 days'))],
        ['H', 'Serve Copies to Creditors', 'Not Started', 'Admin', date('Y-m-d', strtotime('+56 days'))],
        ['H', 'Attend Initial Hearing', 'Not Started', 'Debtor & Counsel', date('Y-m-d', strtotime('+56 days'))],
        ['H', 'Publish Notice in Newspaper', 'Not Started', 'Legal Team', date('Y-m-d', strtotime('+56 days'))]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO bankruptcy_checklist (company_name, user_id, section, item_name, status, assigned_person, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($checklistItems as $item) {
        $stmt->execute([$company_name, $user_id, $item[0], $item[1], $item[2], $item[3], $item[4]]);
    }
}

// NEW: Get balance sheet data for financial overview graph
$filter = 'annual'; // Default to annual view for financial overview
$startDate = null;
$endDate = date('Y-m-d');
if ($filter === 'monthly') {
    $startDate = date('Y-m-01');
} elseif ($filter === 'quarterly') {
    $month = date('n');
    $quarterStart = floor(($month - 1) / 3) * 3 + 1;
    $startDate = date('Y') . '-' . str_pad($quarterStart, 2, '0', STR_PAD_LEFT) . '-01';
} elseif ($filter === 'annual') {
    $startDate = date('Y-01-01');
}

// Get balance sheet data
$stmt = $pdo->prepare("SELECT a.name, a.type, 
       CASE 
           WHEN a.name LIKE '%payable%' OR a.name LIKE '%loan%' THEN 'Non-Current Liability'
           WHEN a.name LIKE '%receivable%' OR a.name LIKE '%inventory%' OR a.name LIKE '%prepaid%' THEN 'Current Asset'
           WHEN a.name LIKE '%cash%' OR a.name LIKE '%bank%' THEN 'Current Asset'
           WHEN a.type = 'Liability' THEN 'Current Liability'
           WHEN a.type = 'Asset' THEN 'Non-Current Asset'
           ELSE 'Equity'
       END as category,
       SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
FROM `$accountsTable` a
JOIN `$transactionsTable` t ON a.name = t.account_name
WHERE t.date BETWEEN ? AND ?
GROUP BY a.name, a.type");
$stmt->execute([$startDate, $endDate]);
$balanceSheetAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [
    'Current Asset' => [],
    'Non-Current Asset' => [],
    'Current Liability' => [],
    'Non-Current Liability' => [],
    'Equity' => []
];

foreach ($balanceSheetAccounts as $acc) {
    if (isset($grouped[$acc['category']])) {
        $grouped[$acc['category']][] = $acc;
    }
}

// Calculate totals for charts
function sectionTotal($section) {
    $total = 0;
    if (is_array($section)) {
        foreach ($section as $acc) $total += $acc['balance'];
    }
    return $total;
}

$current_assets_total = sectionTotal($grouped['Current Asset'] ?? []);
$non_current_assets_total = sectionTotal($grouped['Non-Current Asset'] ?? []);
$total_assets = $current_assets_total + $non_current_assets_total;

$current_liabilities_total = sectionTotal($grouped['Current Liability'] ?? []);
$non_current_liabilities_total = sectionTotal($grouped['Non-Current Liability'] ?? []);
$total_liabilities = $current_liabilities_total + $non_current_liabilities_total;

$total_equity = sectionTotal($grouped['Equity'] ?? []);
$total_liabilities_equity = $total_liabilities + $total_equity;

// NEW: Get Income Statement data for financial overview graph
$income_stmt = $pdo->prepare("SELECT a.name, a.type,
       SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
FROM `$accountsTable` a
JOIN `$transactionsTable` t ON a.name = t.account_name
WHERE a.type IN ('Revenue', 'Expense') AND t.date BETWEEN ? AND ?
GROUP BY a.name, a.type");
$income_stmt->execute([$startDate, $endDate]);
$incomeAccounts = $income_stmt->fetchAll(PDO::FETCH_ASSOC);

$revenues = [];
$expenses = [];
$total_revenue = 0;
$total_expense = 0;

foreach ($incomeAccounts as $account) {
    if ($account['type'] === 'Revenue') {
        $revenues[] = $account;
        $total_revenue += abs($account['balance']);
    } else {
        $expenses[] = $account;
        $total_expense += abs($account['balance']);
    }
}

$net_income = $total_revenue - $total_expense;
$profit_margin = $total_revenue > 0 ? ($net_income / $total_revenue) * 100 : 0;

// NEW: Get data for multiple periods for trend analysis
$periods = [];
$revenue_data = [];
$expense_data = [];
$net_income_data = [];

// Get data for the last 6 periods for trend analysis
for ($i = 5; $i >= 0; $i--) {
    $period_start = null;
    $period_label = '';
    
    if ($filter === 'monthly') {
        $period_start = date('Y-m-01', strtotime("-$i months"));
        $period_label = date('M Y', strtotime("-$i months"));
    } elseif ($filter === 'quarterly') {
        $quarter = floor((date('n') - $i * 3 - 1) / 3) + 1;
        $year = date('Y', strtotime("-$i months"));
        $period_start = date('Y-m-d', mktime(0, 0, 0, ($quarter - 1) * 3 + 1, 1, $year));
        $period_label = 'Q' . $quarter . ' ' . $year;
    } else {
        $year = date('Y') - $i;
        $period_start = $year . '-01-01';
        $period_label = $year;
    }
    
    $period_end = $filter === 'annual' ? $year . '-12-31' : date('Y-m-t', strtotime($period_start));
    
    $stmt = $pdo->prepare("SELECT a.type,
            SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
        FROM `$accountsTable` a
        JOIN `$transactionsTable` t ON a.name = t.account_name
        WHERE a.type IN ('Revenue', 'Expense') AND t.date BETWEEN ? AND ?
        GROUP BY a.type");
    $stmt->execute([$period_start, $period_end]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $revenue = 0;
    $expense = 0;
    
    foreach ($result as $row) {
        if ($row['type'] === 'Revenue') {
            $revenue = abs($row['balance']);
        } else {
            $expense = abs($row['balance']);
        }
    }
    
    $periods[] = $period_label;
    $revenue_data[] = $revenue;
    $expense_data[] = $expense;
    $net_income_data[] = $revenue - $expense;
}

// NEW: Get Trial Balance data for financial overview graph
$trial_balance_stmt = $pdo->prepare("SELECT a.name, a.type,
        SUM(CASE WHEN t.entry_type = 'debit' AND t.date BETWEEN ? AND ? THEN t.amount ELSE 0 END) as total_debit,
        SUM(CASE WHEN t.entry_type = 'credit' AND t.date BETWEEN ? AND ? THEN t.amount ELSE 0 END) as total_credit
    FROM `$accountsTable` a
    LEFT JOIN `$transactionsTable` t ON a.name = t.account_name
    GROUP BY a.name, a.type");
$trial_balance_stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$trialBalanceAccounts = $trial_balance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals by account type for Trial Balance charts
$typeTotals = [
    'Asset' => ['debit' => 0, 'credit' => 0],
    'Liability' => ['debit' => 0, 'credit' => 0],
    'Equity' => ['debit' => 0, 'credit' => 0],
    'Revenue' => ['debit' => 0, 'credit' => 0],
    'Expense' => ['debit' => 0, 'credit' => 0],
];

$trial_total_debit = 0;
$trial_total_credit = 0;
foreach ($trialBalanceAccounts as $account) {
    $trial_total_debit += $account['total_debit'];
    $trial_total_credit += $account['total_credit'];
    
    if (isset($typeTotals[$account['type']])) {
        $typeTotals[$account['type']]['debit'] += $account['total_debit'];
        $typeTotals[$account['type']]['credit'] += $account['total_credit'];
    }
}

// Prepare data for Pareto chart (top accounts by absolute balance)
$accountBalances = [];
foreach ($trialBalanceAccounts as $account) {
    $balance = abs($account['total_debit'] - $account['total_credit']);
    if ($balance > 0) {
        $accountBalances[] = [
            'name' => $account['name'],
            'balance' => $balance,
            'type' => $account['type']
        ];
    }
}

// Sort by balance descending
usort($accountBalances, function($a, $b) {
    return $b['balance'] <=> $a['balance'];
});

// Calculate cumulative percentage for Pareto
$totalBalance = array_sum(array_column($accountBalances, 'balance'));
$cumulative = 0;
$paretoData = [];
foreach ($accountBalances as $account) {
    $percentage = ($account['balance'] / $totalBalance) * 100;
    $cumulative += $percentage;
    $paretoData[] = [
        'name' => $account['name'],
        'balance' => $account['balance'],
        'percentage' => $percentage,
        'cumulative' => $cumulative,
        'type' => $account['type']
    ];
}

// NEW: Prepare data for General Ledger Charts (from report_ledger.php)
$specialAccounts = [
    'Cash', 
    'Accounts Receivable', 
    'Inventory', 
    'Accounts Payable', 
    'Capital', 
    'Drawings', 
    'Sales Revenue', 
    'Salaries Expense'
];

$accountTrendsData = [];
$debitCreditData = [];

// Get monthly data for account trends
try {
    $monthlyStmt = $pdo->prepare("
        SELECT 
            account_name,
            YEAR(date) as year,
            MONTH(date) as month,
            SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) as total_credit,
            SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as net_balance
        FROM `$transactionsTable` 
        WHERE date BETWEEN ? AND ?
        AND account_name IN ('" . implode("','", $specialAccounts) . "')
        GROUP BY account_name, YEAR(date), MONTH(date)
        ORDER BY account_name, year, month
    ");
    $monthlyStmt->execute([$startDate, $endDate]);
    $monthlyResults = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize by account
    foreach ($monthlyResults as $row) {
        $monthYear = date('M Y', mktime(0, 0, 0, $row['month'], 1, $row['year']));
        $accountTrendsData[$row['account_name']][$monthYear] = [
            'debit' => $row['total_debit'],
            'credit' => $row['total_credit'],
            'net' => $row['net_balance']
        ];
    }
} catch (Exception $e) {
    // Continue without monthly data if there's an error
}

// Get debit/credit totals for each account
foreach ($specialAccounts as $accountName) {
    try {
        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM `$transactionsTable` WHERE account_name = ? AND entry_type = 'debit' AND date BETWEEN ? AND ?");
        $stmt->execute([$accountName, $startDate, $endDate]);
        $debitTotal = $stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM `$transactionsTable` WHERE account_name = ? AND entry_type = 'credit' AND date BETWEEN ? AND ?");
        $stmt->execute([$accountName, $startDate, $endDate]);
        $creditTotal = $stmt->fetchColumn() ?: 0;

        $debitCreditData[$accountName] = [
            'debit' => $debitTotal,
            'credit' => $creditTotal
        ];
    } catch (Exception $e) {
        // Keep the default zeros if there's an error
        $debitCreditData[$accountName] = [
            'debit' => 0,
            'credit' => 0
        ];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Bookkeeping - <?= htmlspecialchars($company_name) ?></title> 
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <meta charset="UTF-8">
    <!-- Load ApexCharts in the head to ensure it's available when needed -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.3/dist/apexcharts.min.js"></script>
    <style>
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f9fc;
            color: #333;
            overflow-x: hidden;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navigation Bar */
        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 30px;
            background: linear-gradient(135deg, #2c3e50, #4a6583);
            position: sticky;
            top: 0;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .navbar-brand {
            display: flex;
            align-items: center;
            font-weight: 600;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
        }
        
        .navbar-brand img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 12px;
            border: 2px solid white;
        }
        
        .nav-links {
            display: flex;
            gap: 20px;
            align-items: center;
        }
        
        .nav-links a {
            text-decoration: none;
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .nav-links a:hover {
            background-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        
        .nav-links a.selected {
            background-color: #3498db;
            color: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: linear-gradient(135deg, #2c3e50, #4a6583);
            color: white;
            height: calc(100vh - 70px);
            position: fixed;
            top: 70px;
            left: 0;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 999;
            box-shadow: 4px 0 12px rgba(0,0,0,0.1);
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .sidebar-item {
            padding: 12px 25px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #3498db;
        }
        
        .sidebar-item.active {
            background-color: rgba(255, 255, 255, 0.15);
            border-left-color: #3498db;
        }
        
        .sidebar-item i {
            margin-right: 12px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-item span {
            font-weight: 500;
            font-size: 16px;
        }
        
        .sidebar-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.2);
            margin: 15px 25px;
        }
        
        .sidebar-section-title {
            padding: 10px 25px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 10px;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            width: calc(100% - 260px);
            min-height: calc(100vh - 70px);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        /* Dashboard Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 0 10px;
        }
        
        .dashboard-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        /* Section titles */
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #4682B4;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            font-size: 1rem;
        }
        
        .btn-outline-secondary {
            border-radius: 8px;
            padding: 10px 16px;
            font-weight: 500;
            font-size: 1rem;
            border-color: #4682B4;
            color: #4682B4;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }
        
        /* Form Elements */
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 15px;
            border: 1px solid #dce1e5;
            height: 45px;
            font-size: 1rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #4682B4;
            box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.2);
        }
        
        .form-label {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        
        /* Entry Rows */
        .entry-row {
            padding: 15px;
            background-color: rgba(236, 240, 241, 0.4);
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        /* Alerts */
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: none;
            border-radius: 8px;
            color: #155724;
            padding: 12px 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            font-size: 1rem;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border: none;
            border-radius: 8px;
            color: #721c24;
            padding: 12px 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            font-size: 1rem;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: none;
            border-radius: 8px;
            color: #856404;
            padding: 12px 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            font-size: 1rem;
        }
        
        /* Required field indicator */
        .required-asterisk {
            color: #e74c3c;
            font-size: 0.8em;
            vertical-align: super;
            margin-left: 3px;
        }
        
        /* Validation */
        .invalid-feedback-custom {
            display: none;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.9rem;
            color: #e74c3c;
        }
        
        .is-invalid {
            border-color: #e74c3c;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23e74c3c'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23e74c3c' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .navbar {
                padding: 10px 15px;
            }
            
            .nav-links a {
                padding: 6px 12px;
                font-size: 14px;
            }
            
            .menu-toggle {
                display: block !important;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .card {
                padding: 20px;
            }
            
            .card-header {
                margin: -20px -20px 15px -20px;
                padding: 10px 15px;
            }
            
            .section-title {
                font-size: 1rem;
            }
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            height: 95vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 12px 20px;
            flex-shrink: 0;
        }

        .modal-title {
            font-weight: 600;
            font-size: 1.2rem;
        }

        .modal-body {
            padding: 0;
            flex: 1;
            overflow: hidden;
        }

        .modal-footer {
            border-top: 1px solid #eee;
            padding: 12px 20px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Print styles for modal content */
        @media print {
            body * {
                visibility: hidden;
            }
            .modal-content, .modal-content * {
                visibility: visible;
            }
            .modal-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                border: none;
                box-shadow: none;
            }
            .modal-header, .modal-footer {
                display: none;
            }
        }
        
        /* Utility Classes */
        .text-center {
            text-align: center;
        }
        
        .mb-0 {
            margin-bottom: 0;
        }
        
        .mt-3 {
            margin-top: 1rem;
        }
        
        .d-flex {
            display: flex;
        }
        
        .justify-content-between {
            justify-content: space-between;
        }
        
        .align-items-center {
            align-items: center;
        }

        /* Entry control buttons */
        .entry-controls {
            display: flex;
            gap: 5px;
            height: 45px;
        }
        
        .entry-controls .btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            height: 100%;
        }
        
        /* Iframe styling for reports - FIXED SCROLLING */
        .report-iframe {
            width: 100%;
            height: 100%;
            border: none;
            overflow: auto;
        }
        
        .iframe-container {
            height: 100%;
            width: 100%;
        }

        /* Adjust modal dialog for better responsiveness */
        .modal-dialog {
            max-width: 98%;
            height: 95vh;
            margin: 2.5vh auto;
        }

        .modal-dialog-centered {
            display: flex;
            align-items: center;
            min-height: calc(100% - 1rem);
        }
        
        /* Modal header styles */
        .modal-header-content {
            display: flex;
            align-items: center;
            width: 100%;
        }
        
        .modal-logo {
            width: 40px;
            height: 40px;
            margin-right: 15px;
        }
        
        .modal-report-title {
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        /* NEW: Amount input with comma formatting */
        .amount-input {
            text-align: right;
        }
        
        /* NEW: Modal header layout adjustments */
        .modal-header-layout {
            display: flex;
            align-items: center;
            width: 100%;
            justify-content: space-between;
        }
        
        .modal-header-left {
            display: flex;
            align-items: center;
            flex: 1;
        }
        
        .modal-header-center {
            flex: 1;
            text-align: center;
        }
        
        .modal-header-right {
            flex: 1;
            display: flex;
            justify-content: flex-end;
        }

        /* NEW: Footer text style */
        .footer-text {
            font-weight: 500;
            color: #4682B4;
            font-size: 0.95rem;
            margin: 0 15px;
        }

        /* NEW: Currency dropdown styles */
        .currency-dropdown {
            min-width: 80px;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .currency-dropdown-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }
        
        .currency-options {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .currency-option {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        
        .currency-option:hover {
            background-color: #f5f5f5;
        }
        
        .currency-option:last-child {
            border-bottom: none;
        }
        
        .currency-option.active {
            background-color: #e9ecef;
            font-weight: 500;
        }

        /* ==================== */
        /* Chat Feature Styles */
        /* ==================== */
        .chat-toggle {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background-color: #4682B4;
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 1999;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .chat-toggle:hover {
            background-color: #3a6d9c;
            transform: scale(1.1);
        }

        .chatbox {
            position: fixed;
            bottom: 100px;
            right: 30px;
            width: 350px;
            max-height: 500px;
            background: white;
            border-radius: 12px;
            display: none;
            flex-direction: column;
            z-index: 2000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            transform: translateY(20px);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .chatbox.open {
            display: flex;
            animation: fadeInUp 0.3s ease-out forwards;
        }

        .chat-header {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            padding: 20px;
            font-weight: 600;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chat-header i {
            cursor: pointer;
            font-size: 1.3rem;
            opacity: 0.8;
            transition: all 0.3s ease;
        }

        .chat-header i:hover {
            opacity: 1;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 15px;
            height: 300px;
        }

        .chat-input {
            display: flex;
            border-top: 1px solid #dce1e5;
            background: white;
            padding: 15px;
        }

        .chat-input input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid #dce1e5;
            border-radius: 8px;
            font-size: 1rem;
            outline: none;
            transition: all 0.3s ease;
        }

        .chat-input input:focus {
            border-color: #4682B4;
            box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.15);
        }

        .chat-input button {
            background: #4682B4;
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 8px;
            cursor: pointer;
            margin-left: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .chat-input button:hover {
            background: #3a6d9c;
            transform: scale(1.05);
        }

        .message-bubble {
            max-width: 80%;
            padding: 12px 18px;
            border-radius: 20px;
            font-size: 0.95rem;
            line-height: 1.5;
            word-wrap: break-word;
            position: relative;
            animation: fadeIn 0.3s ease-out;
        }

        .message-bubble.you {
            background-color: #4682B4;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 5px;
        }

        .message-bubble.admin {
            background-color: #f0f0f0;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 5px;
        }

        .message-time {
            font-size: 0.7rem;
            opacity: 0.7;
            margin-top: 5px;
        }

        .file-attachment {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 10px;
            background-color: #f0f7ff;
            border-radius: 20px;
            margin-top: 5px;
            font-size: 14px;
        }
        
        .file-attachment a {
            color: #4682B4;
            text-decoration: none;
        }
        
        .file-attachment a:hover {
            text-decoration: underline;
        }
        
        .remove-file {
            color: #e74c3c;
            cursor: pointer;
        }

		/* Image attachment styles */
        .image-attachment {
            margin-top: 5px;
            text-align: center;
			display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .image-attachment img {
            max-width: 100%;
            max-height: 200px;
			min-height: 50px;
			width: auto;
			height: auto;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s;
            object-fit: contain;
			border: 1px solid #e1e8ed;
            background: #f8f9fa;
        }
        
        .image-attachment img:hover {
            transform: scale(1.02);
			border-color: #4682B4;
        }
		
		.image-attachment .filename {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
            word-break: break-all;
            max-width: 100%;
        }
		
		/* Scroll to bottom button - CENTERED - FROM INDEX1 */
		.scroll-to-bottom {
			position: absolute;
			bottom: 70px;
			left: 50%;
			transform: translateX(-50%);
			width: 35px;
			height: 35px;
			border-radius: 50%;
			background: var(--primary);
			color: white;
			border: none;
			cursor: pointer;
			display: none;
			align-items: center;
			justify-content: center;
			font-size: 14px;
			box-shadow: var(--shadow-md);
			transition: var(--transition);
			z-index: 10;
		}

		.scroll-to-bottom:hover {
			background: var(--primary-dark);
			transform: translateX(-50%) scale(1.1);
		}

		.scroll-to-bottom.visible {
			display: flex;
		}
		
        .empty-chat {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }

		:root {
			--primary: #005B96;
			--primary-dark: #004a7a;
			--shadow-md: 0 6px 12px rgba(0,0,0,0.1);
			--transition: all 0.3s ease;
		}

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 30px;
            right: 30px;
            padding: 18px 28px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 4000;
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

        /* Animations */
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

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes fadeInOut {
            0%, 100% { opacity: 0; transform: translateY(-20px); }
            10%, 90% { opacity: 1; transform: translateY(0); }
        }

        /* Responsive adjustments for chat */
        @media (max-width: 768px) {
            .chatbox {
                width: 90%;
                right: 5%;
                bottom: 80px;
                max-height: 70vh;
            }
            
            .chat-toggle {
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
        }

        /* Bankruptcy Detection Styles */
        .bankruptcy-alert {
            border-left: 5px solid #e74c3c;
            background-color: #fdf2f2;
        }
        
        .financial-health-card {
            border-left: 5px solid #2ecc71;
        }
        
        .financial-warning-card {
            border-left: 5px solid #f39c12;
        }
        
        .ratio-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .ratio-item:last-child {
            border-bottom: none;
        }
        
        .ratio-value {
            font-weight: 600;
        }
        
        .ratio-good {
            color: #2ecc71;
        }
        
        .ratio-warning {
            color: #f39c12;
        }
        
        .ratio-danger {
            color: #e74c3c;
        }
        
        .progress {
            height: 10px;
            margin-top: 5px;
        }
        
        .form-disabled-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 12px;
        }
        
        .form-disabled-message {
            background-color: #e74c3c;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Menu Toggle Button */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* Bankruptcy Risk in Sidebar */
        .bankruptcy-risk-sidebar {
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            margin: 15px;
            text-align: center;
        }
        
        .bankruptcy-risk-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .bankruptcy-risk-low {
            color: #2ecc71;
        }
        
        .bankruptcy-risk-medium {
            color: #f39c12;
        }
        
        .bankruptcy-risk-high {
            color: #e74c3c;
        }
        
        .bankruptcy-risk-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        /* Graph Placeholder */
        .graph-placeholder {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 15px 0;
            border: 1px dashed #ddd;
        }
        
        .graph-placeholder i {
            font-size: 2rem;
            color: #4682B4;
            margin-bottom: 10px;
        }
        
        /* Content Area Styles */
        .content-area {
            display: none;
        }
        
        .content-area.active {
            display: block;
        }
        
        /* Report Container - FIXED SCROLLING */
        .report-container {
            width: 100%;
            height: 600px;
            border: none;
            overflow: auto !important; /* Changed from hidden to auto */
        }

        /* Ensure iframe content is scrollable */
        .report-container iframe {
            width: 100%;
            height: 100%;
            border: none;
            overflow: auto !important; /* Ensure iframe content can scroll */
        }

        /* Make the card body scrollable */
        .card-body.p-0 {
            overflow: auto; /* Changed from hidden to auto */
        }
        
        /* Financial Graph Styles */
        .financial-graph {
            width: 100%;
            height: 400px;
            background-color: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            margin-bottom: 20px;
        }
        
        .graph-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        /* NEW: Chart container styles for Financial Overview Graph */
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            height: 400px;
        }

        .chart-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e1e8ed;
        }

        .section-title {
            color: #2c3e50;
            border-bottom: 2px solid #4682B4;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        /* NEW: Chart fallback styles */
        .chart-fallback {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #666;
            text-align: center;
        }
        
        .chart-fallback i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #4682B4;
        }
        
        .chart-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        
        .chart-loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4682B4;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin-bottom: 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* NEW: Modal styles for account selection */
        .accounts-modal .modal-dialog {
            max-width: 90%;
            max-height: 90vh;
        }
        
        .accounts-modal .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .account-category {
            margin-bottom: 25px;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .category-header {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            padding: 12px 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .category-header i {
            transition: transform 0.3s ease;
        }
        
        .category-header.collapsed i {
            transform: rotate(-90deg);
        }
        
        .category-accounts {
            padding: 15px;
            background: #f8f9fa;
        }
        
        .account-checkbox {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .account-checkbox:last-child {
            border-bottom: none;
        }
        
        .account-checkbox input {
            margin-right: 10px;
        }
        
        .account-checkbox label {
            margin-bottom: 0;
            font-weight: 500;
            cursor: pointer;
        }
        
        .account-type-badge {
            margin-left: 10px;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
        }
        
        .badge-asset {
            background-color: #28a745;
            color: white;
        }
        
        .badge-liability {
            background-color: #dc3545;
            color: white;
        }
        
        .badge-equity {
            background-color: #6f42c1;
            color: white;
        }
        
        .badge-revenue {
            background-color: #20c997;
            color: white;
        }
        
        .badge-expense {
            background-color: #fd7e14;
            color: white;
        }
        
        .select-all-section {
            margin-bottom: 15px;
            padding: 10px 15px;
            background: #e9f7fe;
            border-radius: 8px;
            border-left: 4px solid #4682B4;
        }
        
        .accounts-count {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 10px;
        }

        /* NEW: Official Bankruptcy Message Styles */
        .official-bankruptcy-message {
            border-left: 5px solid #e74c3c;
            background: linear-gradient(135deg, #fff5f5, #fed7d7);
            padding: 30px;
            text-align: center;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .official-bankruptcy-icon {
            font-size: 4rem;
            color: #e74c3c;
            margin-bottom: 20px;
        }
        
        .official-bankruptcy-title {
            color: #c53030;
            font-weight: 700;
            font-size: 1.8rem;
            margin-bottom: 15px;
        }
        
        .official-bankruptcy-subtitle {
            color: #742a2a;
            font-size: 1.2rem;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .official-bankruptcy-content {
            color: #2d3748;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 25px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .fria-reference {
            background-color: #fff;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 25px;
            text-align: left;
        }
        
        .fria-reference h5 {
            color: #2c5282;
            border-bottom: 2px solid #4299e1;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .rehabilitation-process {
            background: linear-gradient(135deg, #ebf8ff, #bee3f8);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .rehabilitation-process h5 {
            color: #2b6cb0;
            margin-bottom: 15px;
        }
        
        .process-steps {
            text-align: left;
            margin: 0 auto;
            max-width: 600px;
        }
        
        .process-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .step-number {
            background: #4299e1;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <!-- Notification element -->
    <div class="notification" id="notification"></div>

    <!-- NEW: Accounts Selection Modal -->
    <div class="modal fade accounts-modal" id="accountsModal" tabindex="-1" aria-labelledby="accountsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="accountsModalLabel">
                        <i class="fas fa-book me-2"></i>Activate Your Chart of Accounts
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Please select the accounts you want to activate for your company. You can always add custom accounts later.
                    </div>
                    
                    <div class="select-all-section">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAllAccounts">
                            <label class="form-check-label fw-bold" for="selectAllAccounts">
                                Select All Accounts
                            </label>
                        </div>
                        <div class="accounts-count">
                            <span id="selectedCount">0</span> of <span id="totalCount">0</span> accounts selected
                        </div>
                    </div>
                    
                    <div id="accountsContainer">
                        <!-- Accounts will be loaded here by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="activateAccountsBtn" disabled>
                        <i class="fas fa-check me-1"></i> Activate Selected Accounts
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-brand" onclick="window.location.href='index1.html'">
            <img src="images/NXT.png" alt="Bookkeeping - <?= htmlspecialchars($company_name) ?>">
            Bookkeeping - <?= htmlspecialchars($company_name) ?>
        </div>
        <button class="menu-toggle" id="menuToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="nav-links simplified-nav">
            <a class="back-btn" onclick="window.location.href='index1.html'">
                <i class="fas fa-arrow-left me-2"></i> Back to Main
            </a>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-menu">
            <!-- Bookkeeping Section -->
            <div class="sidebar-item active" onclick="showContent('bookkeeping')">
                <i class="fas fa-book"></i>
                <span>Bookkeeping</span>
            </div>
            
            <div class="sidebar-divider"></div>
            
            <!-- Bankruptcy Risk -->
            <div class="sidebar-item" onclick="showContent('bankruptcy')">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Bankruptcy Risk</span>
            </div>
            
            <div class="sidebar-divider"></div>
            
            <!-- Bankruptcy Filing Checklist -->
            <?php if ($is_bankrupt && (!$checklistCompleted || !$accessGranted)): ?>
            <div class="sidebar-item" onclick="showContent('bankruptcy-checklist')">
                <i class="fas fa-clipboard-list"></i>
                <span>Bankruptcy Filing</span>
            </div>
            <div class="sidebar-divider"></div>
            <?php endif; ?>
            
            <!-- Financial Overview Graph -->
            <div class="sidebar-item" onclick="showContent('financial-graph')">
                <i class="fas fa-chart-bar"></i>
                <span>Financial Overview Graph</span>
            </div>
            
            <div class="sidebar-divider"></div>
            
            <!-- Reports Section -->
            <div class="sidebar-section-title">Reports</div>
            
            <div class="sidebar-item" onclick="showContent('balance-sheet')">
                <i class="fas fa-balance-scale"></i>
                <span>Balance Sheet</span>
            </div>
            
            <div class="sidebar-item" onclick="showContent('income-statement')">
                <i class="fas fa-money-bill-wave"></i>
                <span>Income Statement</span>
            </div>
            
            <div class="sidebar-item" onclick="showContent('trial-balance')">
                <i class="fas fa-calculator"></i>
                <span>Trial Balance</span>
            </div>
            
            <div class="sidebar-item" onclick="showContent('general-ledger')">
                <i class="fas fa-book"></i>
                <span>General Ledger</span>
            </div>
            
            <div class="sidebar-item" onclick="showContent('cash-flow')">
                <i class="fas fa-money-check"></i>
                <span>Cash Flow</span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Bookkeeping Form (Default Content) - Now loaded from external file -->
        <div class="content-area active" id="bookkeeping-content">
            <?php 
            // Include bookkeeping form but add a check for empty accounts
            if (empty($accounts)): 
            ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-book me-2"></i>Bookkeeping</h3>
                    </div>
                    <div class="card-body text-center">
                        <div class="alert alert-warning">
                            <h4><i class="fas fa-exclamation-triangle me-2"></i>No Accounts Activated</h4>
                            <p>You need to activate accounts before you can record transactions.</p>
                            <button class="btn btn-primary mt-3" onclick="showAccountsModal()">
                                <i class="fas fa-book me-1"></i> Activate Accounts Now
                            </button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php if ($is_bankrupt && $accessGranted): ?>
                    <!-- Official Bankruptcy Message when filing is approved -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-book me-2"></i>Bookkeeping</h3>
                        </div>
                        <div class="card-body">
                            <div class="official-bankruptcy-message">
                                <div class="official-bankruptcy-icon">
                                    <i class="fas fa-gavel"></i>
                                </div>
                                <h2 class="official-bankruptcy-title">OFFICIALLY BANKRUPT</h2>
                                <h4 class="official-bankruptcy-subtitle">Account Access Suspended</h4>
                                
                                <div class="official-bankruptcy-content">
                                    <p>This company has been officially declared bankrupt and all bookkeeping operations have been suspended pursuant to court order.</p>
                                    <p>To reopen account access and resume business operations, you must apply for corporate rehabilitation under the Financial Rehabilitation and Insolvency Act (FRIA) of 2010.</p>
                                </div>
                                
                                <div class="fria-reference">
                                    <h5><i class="fas fa-scale-balanced me-2"></i>FRIA Reference</h5>
                                    <p><strong>Financial Rehabilitation and Insolvency Act (RA 10142)</strong></p>
                                    <p>The FRIA provides a framework for the rehabilitation of distressed corporations to restore their viability and continue business operations while protecting creditor rights and preserving enterprise value.</p>
                                </div>
                                
                                <div class="rehabilitation-process">
                                    <h5><i class="fas fa-clipboard-list me-2"></i>Rehabilitation Application Process</h5>
                                    <div class="process-steps">
                                        <div class="process-step">
                                            <div class="step-number">1</div>
                                            <div>
                                                <strong>File Petition for Rehabilitation</strong><br>
                                                Submit formal petition with the appropriate court demonstrating feasibility of rehabilitation
                                            </div>
                                        </div>
                                        <div class="process-step">
                                            <div class="step-number">2</div>
                                            <div>
                                                <strong>Court Approval & Stay Order</strong><br>
                                                Obtain court approval and stay order suspending all claims against the corporation
                                            </div>
                                        </div>
                                        <div class="process-step">
                                            <div class="step-number">3</div>
                                            <div>
                                                <strong>Implement Rehabilitation Plan</strong><br>
                                                Execute approved rehabilitation plan under court supervision
                                            </div>
                                        </div>
                                        <div class="process-step">
                                            <div class="step-number">4</div>
                                            <div>
                                                <strong>Court Confirmation</strong><br>
                                                Obtain final court confirmation of successful rehabilitation
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <p class="text-muted">For legal assistance with rehabilitation proceedings, please consult with qualified insolvency practitioners or legal counsel specializing in corporate rehabilitation.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php include 'bookkeeping_form.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Bankruptcy Risk Analysis Content -->
        <div class="content-area" id="bankruptcy-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-exclamation-triangle me-2"></i> Bankruptcy Risk Analysis</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card financial-health-card mb-4">
                                <div class="card-body">
                                    <h4 class="<?= $bankruptcy_status['probability'] < 0.3 ? 'text-success' : ($bankruptcy_status['probability'] < 0.7 ? 'text-warning' : 'text-danger') ?>">
                                        <?= number_format($bankruptcy_status['probability'] * 100, 1) ?>% Bankruptcy Risk
                                    </h4>
                                    <p class="text-muted">Z-Score: <?= number_format($bankruptcy_status['z_score'], 2) ?></p>
                                    <div class="progress">
                                        <div class="progress-bar <?= $bankruptcy_status['probability'] < 0.3 ? 'bg-success' : ($bankruptcy_status['probability'] < 0.7 ? 'bg-warning' : 'bg-danger') ?>" 
                                             role="progressbar" 
                                             style="width: <?= $bankruptcy_status['probability'] * 100 ?>%"
                                             aria-valuenow="<?= $bankruptcy_status['probability'] * 100 ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="section-title">Financial Ratios</h5>
                                    <?php
                                    $ratios = $bankruptcy_status['ratios'];
                                    $ratioNames = [
                                        'current_ratio' => 'Current Ratio',
                                        'debt_to_equity' => 'Debt to Equity',
                                        'return_on_assets' => 'Return on Assets',
                                        'working_capital_ratio' => 'Working Capital Ratio',
                                        'retained_earnings_ratio' => 'Retained Earnings Ratio'
                                    ];
                                    
                                    foreach ($ratioNames as $key => $name):
                                        $value = $ratios[$key] ?? 0;
                                        $colorClass = '';
                                        if ($key === 'current_ratio') {
                                            $colorClass = $value > 1.5 ? 'ratio-good' : ($value > 1 ? 'ratio-warning' : 'ratio-danger');
                                        } elseif ($key === 'debt_to_equity') {
                                            $colorClass = $value < 1 ? 'ratio-good' : ($value < 2 ? 'ratio-warning' : 'ratio-danger');
                                        } elseif ($key === 'return_on_assets') {
                                            $colorClass = $value > 0.05 ? 'ratio-good' : ($value > 0 ? 'ratio-warning' : 'ratio-danger');
                                        } elseif ($key === 'working_capital_ratio') {
                                            $colorClass = $value > 0.2 ? 'ratio-good' : ($value > 0 ? 'ratio-warning' : 'ratio-danger');
                                        } elseif ($key === 'retained_earnings_ratio') {
                                            $colorClass = $value > 0.4 ? 'ratio-good' : ($value > 0.2 ? 'ratio-warning' : 'ratio-danger');
                                        }
                                    ?>
                                    <div class="ratio-item">
                                        <span><?= $name ?></span>
                                        <span class="ratio-value <?= $colorClass ?>"><?= number_format($value, 3) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($is_bankrupt): ?>
                    <div class="alert alert-danger bankruptcy-alert mt-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-triangle me-3 fa-2x"></i>
                            <div>
                                <h4 class="alert-heading mb-1">Financial Distress Detected!</h4>
                                <p class="mb-0">Our AI analysis indicates a high probability of bankruptcy. Transaction capabilities have been temporarily disabled.</p>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($bankruptcy_status['probability'] > 0.3): ?>
                    <div class="alert alert-warning mt-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-3"></i>
                            <div>
                                <h4 class="alert-heading mb-1">Financial Warning</h4>
                                <p class="mb-0">Our analysis shows some financial stress indicators. Monitor your financial health closely.</p>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-success mt-4">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle me-3"></i>
                            <div>
                                <h4 class="alert-heading mb-1">Good Financial Health</h4>
                                <p class="mb-0">Our analysis indicates stable financial condition. Continue monitoring your financial metrics.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Financial Overview Graph Content - Now loaded from external file -->
        <div class="content-area" id="financial-graph-content">
            <?php include 'financial_overview_graph.php'; ?>
        </div>
        
        <!-- Reports Content Areas -->
        <div class="content-area" id="balance-sheet-content">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-balance-scale me-2"></i> Balance Sheet</h3>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="printReport('balance-sheet')">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
                <div>
                    <iframe class="report-container" src="report_balance_sheet.php?acct=<?= $accountsTable ?>&txn=<?= $transactionsTable ?><?= ($is_bankrupt && !$accessGranted) ? '&restricted=1' : '' ?>"></iframe>
                </div>
            </div>
        </div>
        
        <div class="content-area" id="income-statement-content">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-money-bill-wave me-2"></i> Income Statement</h3>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="printReport('income-statement')">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div> 
                <div class="card-body p-0">
                    <iframe class="report-container" src="report_income_statement.php?acct=<?= $accountsTable ?>&txn=<?= $transactionsTable ?><?= ($is_bankrupt && !$accessGranted) ? '&restricted=1' : '' ?>"></iframe>
                </div>
            </div>
        </div>
        
        <div class="content-area" id="trial-balance-content">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-calculator me-2"></i> Trial Balance</h3>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="printReport('trial-balance')">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <iframe class="report-container" src="report_trial_balance.php?acct=<?= $accountsTable ?>&txn=<?= $transactionsTable ?><?= ($is_bankrupt && !$accessGranted) ? '&restricted=1' : '' ?>"></iframe>
                </div>
            </div>
        </div>
        
        <div class="content-area" id="general-ledger-content">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-book me-2"></i> General Ledger</h3>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="printReport('general-ledger')">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <iframe class="report-container" src="report_ledger.php?acct=<?= $accountsTable ?>&txn=<?= $transactionsTable ?><?= ($is_bankrupt && !$accessGranted) ? '&restricted=1' : '' ?>"></iframe>
                </div>
            </div>
        </div>
        
        <div class="content-area" id="cash-flow-content">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-money-check me-2"></i> Cash Flow</h3>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="printReport('cash-flow')">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <iframe class="report-container" src="report_cash_flow.php?acct=<?= $accountsTable ?>&txn=<?= $transactionsTable ?><?= ($is_bankrupt && !$accessGranted) ? '&restricted=1' : '' ?>"></iframe>
                </div>
            </div>
        </div>
                    <!-- Bankruptcy Filing Checklist Content -->
        <?php if ($is_bankrupt && (!$checklistCompleted || !$accessGranted)): ?>
        <div class="content-area" id="bankruptcy-checklist-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0" style="font-size: 1.2rem;">
                        <i class="fas fa-clipboard-list me-2"></i> Bankruptcy Filing Checklist
                    </h3>
                </div>
                <div class="card-body p-0">
                    <iframe class="report-container" src="bankruptcy_checklist.php"></iframe>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Chat Feature -->
    <div class="chat-toggle" onclick="toggleChat()">
        <i class="fas fa-comment-dots"></i>
    </div>

    <div class="chatbox" id="chatbox">
        <div class="chat-header">
            <span>Chat with Admin</span>
            <i class="fas fa-times" onclick="toggleChat()"></i>
        </div>
        <div class="chat-messages" id="chatMessages"></div>
		<button class="scroll-to-bottom" id="scrollToBottom" onclick="scrollToBottom()">
            <i class="fas fa-arrow-down"></i>
        </button>
        <div class="chat-input">
            <input type="text" id="chatInput" placeholder="Type your message...">
            <label for="fileAttachment" style="cursor: pointer; margin-left: 10px;">
                <i class="fas fa-paperclip"></i>
            </label>
            <input type="file" id="fileAttachment" style="display: none">
            <button onclick="sendMessage()">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
        <div id="filePreview" style="padding: 5px 15px; background: white; border-top: 1px solid #eee;"></div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Bankruptcy status
    let isBankrupt = <?= $is_bankrupt ? 'true' : 'false' ?>;
    let bankruptcyProbability = <?= $bankruptcy_status['probability'] ?>;
    let isAccountsEmpty = <?= $isAccountsEmpty ? 'true' : 'false' ?>;
    let accessGranted = <?= $accessGranted ? 'true' : 'false' ?>;

    // NEW: Default accounts data
    const defaultAccounts = <?= json_encode(getDefaultAccounts()) ?>;

    // NEW: Show accounts modal manually
    function showAccountsModal() {
        initializeAccountsModal();
        const accountsModal = new bootstrap.Modal(document.getElementById('accountsModal'));
        accountsModal.show();
    }

    // NEW: Initialize accounts modal on page load if accounts are empty
    document.addEventListener('DOMContentLoaded', function() {
        if (isAccountsEmpty) {
            // Show accounts modal after a short delay
            setTimeout(() => {
                showAccountsModal();
            }, 500);
        }
    });

    // NEW: Initialize accounts modal with default accounts
    function initializeAccountsModal() {
        const container = document.getElementById('accountsContainer');
        const totalCount = document.getElementById('totalCount');
        
        // Group accounts by category
        const accountsByCategory = {};
        defaultAccounts.forEach(account => {
            if (!accountsByCategory[account.category]) {
                accountsByCategory[account.category] = [];
            }
            accountsByCategory[account.category].push(account);
        });
        
        // Create HTML for each category
        let html = '';
        Object.keys(accountsByCategory).forEach(category => {
            const accounts = accountsByCategory[category];
            const categoryId = category.toLowerCase().replace(/\s+/g, '-');
            
            html += `
                <div class="account-category">
                    <div class="category-header" data-bs-toggle="collapse" data-bs-target="#${categoryId}-accounts" aria-expanded="true">
                        <span>${category} (${accounts.length} accounts)</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="collapse show category-accounts" id="${categoryId}-accounts">
            `;
            
            accounts.forEach(account => {
                const badgeClass = `badge-${account.type.toLowerCase()}`;
                html += `
                    <div class="account-checkbox">
                        <input type="checkbox" id="acc-${account.id}" name="accounts" value="${account.id}" class="account-checkbox-input">
                        <label for="acc-${account.id}">
                            ${account.name}
                            <span class="account-type-badge ${badgeClass}">${account.type}</span>
                        </label>
                    </div>
                `;
            });
            
            html += `</div></div>`;
        });
        
        container.innerHTML = html;
        totalCount.textContent = defaultAccounts.length;
        
        // Add event listeners
        document.getElementById('selectAllAccounts').addEventListener('change', toggleSelectAll);
        document.querySelectorAll('.account-checkbox-input').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectionCount);
        });
        document.getElementById('activateAccountsBtn').addEventListener('click', activateSelectedAccounts);
        
        // Initialize count
        updateSelectionCount();
    }

    // NEW: Toggle select all accounts
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAllAccounts').checked;
        document.querySelectorAll('.account-checkbox-input').forEach(checkbox => {
            checkbox.checked = selectAll;
        });
        updateSelectionCount();
    }

    // NEW: Update selected accounts count
    function updateSelectionCount() {
        const selected = document.querySelectorAll('.account-checkbox-input:checked').length;
        const total = defaultAccounts.length;
        document.getElementById('selectedCount').textContent = selected;
        document.getElementById('activateAccountsBtn').disabled = selected === 0;
    }

    // NEW: Activate selected accounts - FIXED VERSION
    function activateSelectedAccounts() {
        const selectedAccounts = Array.from(document.querySelectorAll('.account-checkbox-input:checked'))
            .map(checkbox => checkbox.value);
        
        const activateBtn = document.getElementById('activateAccountsBtn');
        const originalText = activateBtn.innerHTML;
        activateBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Activating...';
        activateBtn.disabled = true;
        
        console.log("Sending accounts to activate:", selectedAccounts);
        
        // FIX: Create proper form data with individual account parameters
        const formData = new FormData();
        formData.append('action', 'activate_default_accounts');
        selectedAccounts.forEach(account => {
            formData.append('accounts[]', account);
        });
        
        // Send AJAX request to activate accounts
        fetch('asset.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log("Response received:", data);
            if (data.success) {
                showNotification(data.message, 'success');
                // Close modal and refresh page
                const accountsModal = bootstrap.Modal.getInstance(document.getElementById('accountsModal'));
                accountsModal.hide();
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                // Show detailed error message
                let errorMsg = data.message || 'An unknown error occurred';
                if (data.debug) {
                    errorMsg += ` (Debug: Table: ${data.debug.table}, Selected: ${data.debug.selected_count})`;
                }
                showNotification(errorMsg, 'error');
                activateBtn.innerHTML = originalText;
                activateBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Network error: ' + error.message, 'error');
            activateBtn.innerHTML = originalText;
            activateBtn.disabled = false;
        });
    }

    // Financial health analysis
    function analyzeFinancialHealth() {
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Analyzing...';
        button.disabled = true;
        
        // Simulate API call to re-analyze financial health
        setTimeout(() => {
            // Reload the page to get updated bankruptcy status
            window.location.reload();
        }, 1500);
    }
    
    function updateFinancialHealthUI(data) {
        // Update bankruptcy probability
        const probabilityElement = document.querySelector('.financial-health-card h4');
        probabilityElement.textContent = (data.probability * 100).toFixed(1) + '% Bankruptcy Risk';
        probabilityElement.className = data.is_bankrupt ? 'text-danger' : (data.probability > 0.3 ? 'text-warning' : 'text-success');
        
        // Update progress bar
        const progressBar = document.querySelector('.progress-bar');
        progressBar.style.width = (data.probability * 100) + '%';
        progressBar.className = 'progress-bar ' + (data.is_bankrupt ? 'bg-danger' : (data.probability > 0.3 ? 'bg-warning' : 'bg-success'));
        
        // Update Z-score
        document.querySelector('.text-muted').textContent = 'Z-Score: ' + data.z_score.toFixed(2);
        
        // Update ratios
        const ratios = data.ratios;
        document.querySelectorAll('.ratio-item').forEach((item, index) => {
            const ratioName = Object.keys(ratios)[index];
            const ratioValue = ratios[ratioName];
            const valueSpan = item.querySelector('.ratio-value');
            
            // Update value
            valueSpan.textContent = ratioValue.toFixed(3);
            
            // Update color based on ratio value
            valueSpan.className = 'ratio-value ' + getRatioColorClass(ratioName, ratioValue);
        });
        
        // Update overall alert and form status if bankruptcy status changed
        if (data.is_bankrupt !== isBankrupt) {
            isBankrupt = data.is_bankrupt;
            location.reload(); // Reload to update form disabled state
        }
    }
    
    function getRatioColorClass(ratioName, value) {
        switch(ratioName) {
            case 'current_ratio':
                return value > 1.5 ? 'ratio-good' : (value > 1 ? 'ratio-warning' : 'ratio-danger');
            case 'debt_to_equity':
                return value < 1 ? 'ratio-good' : (value < 2 ? 'ratio-warning' : 'ratio-danger');
            case 'return_on_assets':
                return value > 0.05 ? 'ratio-good' : (value > 0 ? 'ratio-warning' : 'ratio-danger');
            case 'working_capital_ratio':
                return value > 0.2 ? 'ratio-good' : (value > 0 ? 'ratio-warning' : 'ratio-danger');
            case 'retained_earnings_ratio':
                return value > 0.4 ? 'ratio-good' : (value > 0.2 ? 'ratio-warning' : 'ratio-danger');
            default:
                return 'ratio-warning';
        }
    }

    // Function to show different content areas
    function showContent(contentType) {
        // Hide all content areas
        document.querySelectorAll('.content-area').forEach(area => {
            area.classList.remove('active');
        });
        
        // Show selected content area
        document.getElementById(contentType + '-content').classList.add('active');
        
        // Update active state in sidebar
        document.querySelectorAll('.sidebar-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // Find and activate the clicked sidebar item
        const sidebarItems = document.querySelectorAll('.sidebar-item');
        for (let i = 0; i < sidebarItems.length; i++) {
            if (sidebarItems[i].textContent.includes(contentType.replace('-', ' ')) || 
                (contentType === 'bookkeeping' && i === 0)) {
                sidebarItems[i].classList.add('active');
                break;
            }
        }
        
        // If showing bookkeeping form, re-initialize form functionality
        if (contentType === 'bookkeeping') {
            // The bookkeeping form will handle its own initialization
            if (typeof initializeBookkeepingForm === 'function') {
                initializeBookkeepingForm();
            }
        }
        
        // If showing financial graph, render the charts
        if (contentType === 'financial-graph') {
            setTimeout(function() {
                if (typeof renderAllCharts === 'function') {
                    renderAllCharts();
                }
            }, 100);
        }
    }

    // Print report function - UPDATED WITH SINGLE LINE WATERMARK
    function printReport(reportType) {
        const iframe = document.querySelector(`#${reportType}-content iframe`);
        if (iframe && iframe.contentWindow) {
            // Inject watermark style into the iframe before printing
            const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            
            // Create watermark style - SINGLE LINE COVERING ENTIRE PAGE
            const watermarkStyle = document.createElement('style');
            watermarkStyle.id = 'print-watermark';
            watermarkStyle.innerHTML = `
                @media print {
                    body::before {
                        content: "EBTGL TAROBAL COMPANY";
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        font-size: 55px;
                        color: rgba(0, 0, 0, 0.08);
                        z-index: 9999;
                        pointer-events: none;
                        font-weight: bold;
                        opacity: 0.8;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        transform: rotate(-45deg);
                        white-space: nowrap;
                        overflow: hidden;
                        text-align: center;
                    }
                }
            `;
            
            // Remove existing watermark if present
            const existingWatermark = iframeDoc.getElementById('print-watermark');
            if (existingWatermark) {
                existingWatermark.remove();
            }
            
            // Add the watermark style to iframe head
            iframeDoc.head.appendChild(watermarkStyle);
            
            // Trigger print
            iframe.contentWindow.print();
        }
    }

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

    // Chat functionality variables
    let selectedFile = null;
    let filePreviewVisible = false;

    function toggleChat() {
        const chatbox = document.getElementById('chatbox');
        const isOpen = chatbox.classList.contains('open');
        
        if (isOpen) {
            chatbox.classList.remove('open');
            setTimeout(() => {
                chatbox.style.display = 'none';
            }, 300);
        } else {
            chatbox.style.display = 'flex';
            setTimeout(() => {
                chatbox.classList.add('open');
                document.getElementById('chatInput').focus();
                // Always scroll to bottom when opening chat
                setTimeout(() => {
                    scrollToBottom();
                }, 100);
            }, 10);
        }
    }

    document.getElementById('fileAttachment').addEventListener('change', function(e) {
        if (this.files.length > 0) {
            selectedFile = this.files[0];
            showFilePreview();
        }
    });

    function showFilePreview() {
        if (!selectedFile) return;
        
        const preview = document.getElementById('filePreview');
        preview.innerHTML = `
            <div class="file-attachment">
                <i class="fas fa-file"></i>
                <span>${selectedFile.name}</span>
                <span class="remove-file" onclick="removeFile()">
                    <i class="fas fa-times"></i>
                </span>
            </div>
        `;
        filePreviewVisible = true;
    }

    function removeFile() {
        selectedFile = null;
        document.getElementById('fileAttachment').value = '';
        document.getElementById('filePreview').innerHTML = '';
        filePreviewVisible = false;
    }

	// Scroll to bottom functionality
	function scrollToBottom() {
		const chatMessages = document.getElementById('chatMessages');
		chatMessages.scrollTop = chatMessages.scrollHeight;
		hideScrollToBottomButton();
	}

	function hideScrollToBottomButton() {
		const scrollBtn = document.getElementById('scrollToBottom');
		scrollBtn.classList.remove('visible');
	}

	function showScrollToBottomButton() {
		const scrollBtn = document.getElementById('scrollToBottom');
		scrollBtn.classList.add('visible');
	}

	function checkScrollPosition() {
		const chatMessages = document.getElementById('chatMessages');
		const scrollBtn = document.getElementById('scrollToBottom');
    
		// Show scroll button if not at bottom (with 50px threshold)
		const isAtBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 50;
    
		if (!isAtBottom) {
			scrollBtn.classList.add('visible');
		} else {
			scrollBtn.classList.remove('visible');
		}
	}

    // Helper functions
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function convertUrlsToLinks(text) {
        const urlRegex = /(https?:\/\/[^\s<]+)/g;
        return text.replace(urlRegex, url => {
            return `<a href="${url}" target="_blank" rel="noopener noreferrer" 
                    style="color: #1a73e8; text-decoration: underline;">${url}</a>`;
        });
    }

    // Function to check if a file is an image
    function isImageFile(fileName) {
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
        const extension = fileName.split('.').pop().toLowerCase();
        return imageExtensions.includes(extension);
    }

    // Function to load messages with error handling
    function loadMessages() {
        fetch('fetch_messages.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // Check if we got an error response
                if (data.error) {
                    console.error('Server error:', data.error);
                    showNotification('Error loading messages: ' + data.error, 'error');
                    return;
                }
                
                const chatMessages = document.getElementById('chatMessages');
                const wasAtBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 10;
                
                // Only update if messages have changed
                if (chatMessages.dataset.messageCount == data.length) return;
                chatMessages.dataset.messageCount = data.length;
                
                chatMessages.innerHTML = '';
                
                if (data.length === 0) {
                    chatMessages.innerHTML = '<div class="empty-chat">No messages yet. Start the conversation!</div>';
                    return;
                }
                
                data.forEach(msg => {
                    const senderType = msg.sender === 'You' ? 'you' : 'admin';
                    
                    const messageDiv = document.createElement('div');
                    messageDiv.classList.add('message-bubble', senderType);
                    
                    let contentHTML = '';
                    
                    // Add message text if exists - only convert URLs for admin messages
                    if (msg.message) {
                        if (senderType === 'admin') {
                            contentHTML += `<div>${convertUrlsToLinks(escapeHtml(msg.message))}</div>`;
                        } else {
                            contentHTML += `<div>${escapeHtml(msg.message)}</div>`;
                        }
                    }
                    
                    // Add file attachment if exists
                    if (msg.attachment_path) {
                        // Extract filename from path
                        const fileName = msg.attachment_path.split('/').pop();
                        
						// In the loadMessages function, update the image attachment section to:
						if (isImageFile(fileName)) {
							// Display image with proper constraints
							contentHTML += `
								<div class="image-attachment">
									<img src="${msg.attachment_path}" alt="Attached Image" 
										style="max-width: 100%; max-height: 200px; object-fit: contain; border-radius: 8px;"
										onclick="openImageModal('${msg.attachment_path}')">
								</div>
							`;
						}						
                    }
                    
                    // Add timestamp
                    if (msg.sent_at) {
                        const sentTime = new Date(msg.sent_at).toLocaleTimeString();
                        contentHTML += `<div class="message-time">${sentTime}</div>`;
                    }
                    
                    messageDiv.innerHTML = contentHTML;
                    chatMessages.appendChild(messageDiv);
                });
                
				// Always scroll to bottom when loading messages unless user has scrolled up
				const isAtBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 50;
				if (isAtBottom || wasAtBottom) {
					chatMessages.scrollTop = chatMessages.scrollHeight;
					hideScrollToBottomButton();
				} else {
					showScrollToBottomButton();
				}
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                showNotification('Error loading messages. Please try again.', 'error');
            });
    }

    // Function to open image in modal for full view
    function openImageModal(imageSrc) {
        // Create modal for image viewing
        const modal = document.createElement('div');
        modal.style.position = 'fixed';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.width = '100%';
        modal.style.height = '100%';
        modal.style.backgroundColor = 'rgba(0,0,0,0.8)';
        modal.style.display = 'flex';
        modal.style.justifyContent = 'center';
        modal.style.alignItems = 'center';
        modal.style.zIndex = '10000';
        modal.style.cursor = 'pointer';
        
        const img = document.createElement('img');
        img.src = imageSrc;
        img.style.maxWidth = '90%';
        img.style.maxHeight = '90%';
        img.style.objectFit = 'contain';
        img.style.borderRadius = '8px';
        
        modal.appendChild(img);
        document.body.appendChild(modal);
        
        // Close modal when clicked
        modal.addEventListener('click', function() {
            document.body.removeChild(modal);
        });
    }

    // Enhanced send message function
    function sendMessage() {
        const input = document.getElementById('chatInput');
        const text = input.value.trim();
        
        // Prevent sending links from client side
        const linkPattern = /(https?:\/\/|www\.)\S+/i;
        if (linkPattern.test(text)) {
            showNotification('Sending links is not allowed', 'error');
            return;
        }

        // Check file size
        if (selectedFile && selectedFile.size > 10 * 1024 * 1024) {
            showNotification('File size must be less than 10MB', 'error');
            return;
        }

        if (!text && !selectedFile) {
            return;
        }

        const formData = new FormData();
        formData.append('message', text);
        
        if (selectedFile) {
            formData.append('attachment', selectedFile);
        }

        // Show sending state
        const sendBtn = document.querySelector('.chat-input button');
        const originalHtml = sendBtn.innerHTML;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        sendBtn.disabled = true;

        // Clear inputs immediately for better UX
        input.value = '';
        removeFile();

        fetch('send_message.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Only reload messages if send was successful
                loadMessages();
                // Auto-scroll to bottom after sending
                setTimeout(() => {
                    scrollToBottom();
                }, 100);
            } else {
                showNotification('Error: ' + (data.message || 'Unknown error'), 'error');
                // Restore send button
                sendBtn.innerHTML = originalHtml;
                sendBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error sending message', 'error');
            // Restore send button
            sendBtn.innerHTML = originalHtml;
            sendBtn.disabled = false;
        });
    }

    // Optimized message polling
    let lastMessageCount = 0;
    function checkForNewMessages() {
        fetch('fetch_messages.php')
            .then(response => response.json())
            .then(messages => {
                if (messages.length !== lastMessageCount) {
                    lastMessageCount = messages.length;
                    loadMessages();
                }
            })
            .catch(error => console.error('Polling error:', error));
    }

    // Start polling with initial load
    loadMessages();
    setInterval(checkForNewMessages, 3000);

    document.getElementById('chatInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Add scroll event listener to chat messages
    document.addEventListener('DOMContentLoaded', function() {
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.addEventListener('scroll', checkScrollPosition);
        }
    });

    // Sidebar toggle for mobile
    document.getElementById('menuToggle').addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('active');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menuToggle');
        
        if (window.innerWidth <= 992 && 
            !sidebar.contains(event.target) && 
            !menuToggle.contains(event.target) && 
            sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }
    });

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Set up any additional initialization if needed
        console.log('Page loaded successfully');
    });
    </script>
</body>
</html>