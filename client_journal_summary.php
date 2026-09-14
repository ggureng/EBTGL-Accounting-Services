<?php
// Start session for audit logging
session_start();

// Remove session requirements and focus on database access
require 'db_connection.php';
require 'audit_logger.php';

// Log access to client journal summary
if (isset($_SESSION['admin_id'])) {
    $current_admin = getCurrentAdminInfo();
    logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'VIEW_JOURNAL_SUMMARY',
        "Accessed Client Journal Summary page",
        'journal_summary',
        null,
        null
    );
}

// Function to get financial report results for a client using trial balance formulas
function getFinancialReportResults($pdo, $companyName, $transactionsTable, $accountsTable) {
    $results = [
        'total_revenue' => 0,
        'total_expenses' => 0,
        'net_income' => 0,
        'total_assets' => 0,
        'total_liabilities' => 0,
        'equity' => 0,
        'total_debit' => 0,
        'total_credit' => 0,
        'balance_difference' => 0
    ];
    
    if (!$transactionsTable || !tableExists($pdo, $transactionsTable)) {
        return $results;
    }
    
    try {
        // Get current date for period calculations (using annual as default like the reports)
        $startDate = date('Y-01-01');
        $endDate = date('Y-m-d');
        
        // Calculate account balances using trial balance formula
        $stmt = $pdo->prepare("
            SELECT a.name, a.type,
                SUM(CASE WHEN t.entry_type = 'debit' AND t.date BETWEEN ? AND ? THEN t.amount ELSE 0 END) as total_debit,
                SUM(CASE WHEN t.entry_type = 'credit' AND t.date BETWEEN ? AND ? THEN t.amount ELSE 0 END) as total_credit
            FROM `$accountsTable` a
            LEFT JOIN `$transactionsTable` t ON a.name = t.account_name
            GROUP BY a.name, a.type
        ");
        $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals by account type
        $typeTotals = [
            'Asset' => ['debit' => 0, 'credit' => 0],
            'Liability' => ['debit' => 0, 'credit' => 0],
            'Equity' => ['debit' => 0, 'credit' => 0],
            'Revenue' => ['debit' => 0, 'credit' => 0],
            'Expense' => ['debit' => 0, 'credit' => 0],
        ];
        
        $total_debit = 0;
        $total_credit = 0;
        
        foreach ($accounts as $account) {
            $total_debit += $account['total_debit'];
            $total_credit += $account['total_credit'];
            
            if (isset($typeTotals[$account['type']])) {
                $typeTotals[$account['type']]['debit'] += $account['total_debit'];
                $typeTotals[$account['type']]['credit'] += $account['total_credit'];
            }
        }
        
        // Calculate financial metrics using trial balance formulas
        // Revenue = total credit of revenue accounts
        $results['total_revenue'] = $typeTotals['Revenue']['credit'] ?? 0;
        
        // Expenses = total debit of expense accounts
        $results['total_expenses'] = $typeTotals['Expense']['debit'] ?? 0;
        
        // Net Income = Revenue - Expenses
        $results['net_income'] = $results['total_revenue'] - $results['total_expenses'];
        
        // Assets = total debit - total credit for asset accounts
        $results['total_assets'] = ($typeTotals['Asset']['debit'] ?? 0) - ($typeTotals['Asset']['credit'] ?? 0);
        
        // Liabilities = total credit - total debit for liability accounts
        $results['total_liabilities'] = ($typeTotals['Liability']['credit'] ?? 0) - ($typeTotals['Liability']['debit'] ?? 0);
        
        // Equity = total credit - total debit for equity accounts + net income
        $equityFromAccounts = ($typeTotals['Equity']['credit'] ?? 0) - ($typeTotals['Equity']['debit'] ?? 0);
        $results['equity'] = $equityFromAccounts + $results['net_income'];
        
        // Trial balance totals
        $results['total_debit'] = $total_debit;
        $results['total_credit'] = $total_credit;
        $results['balance_difference'] = $total_debit - $total_credit;
        
    } catch (Exception $e) {
        error_log("Error calculating financial results for $companyName: " . $e->getMessage());
    }
    
    return $results;
}

// Function to get balance sheet summary
function getBalanceSheetSummary($pdo, $companyName, $transactionsTable, $accountsTable) {
    $results = [
        'current_assets' => 0,
        'non_current_assets' => 0,
        'current_liabilities' => 0,
        'non_current_liabilities' => 0
    ];
    
    if (!$transactionsTable || !tableExists($pdo, $transactionsTable)) {
        return $results;
    }
    
    try {
        $startDate = date('Y-01-01');
        $endDate = date('Y-m-d');
        
        // Current Assets
        $currentAssetsStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%cash%' OR account_name LIKE '%bank%' OR account_name LIKE '%receivable%' OR account_name LIKE '%inventory%' OR account_name LIKE '%prepaid%')
            AND date BETWEEN ? AND ?
        ");
        $currentAssetsStmt->execute([$startDate, $endDate]);
        $currentAssetsResult = $currentAssetsStmt->fetch(PDO::FETCH_ASSOC);
        $results['current_assets'] = $currentAssetsResult['balance'] ?? 0;
        
        // Non-Current Assets
        $nonCurrentAssetsStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%equipment%' OR account_name LIKE '%property%' OR account_name LIKE '%building%' OR account_name LIKE '%vehicle%' OR account_name LIKE '%asset%')
            AND date BETWEEN ? AND ?
            AND account_name NOT LIKE '%current asset%'
        ");
        $nonCurrentAssetsStmt->execute([$startDate, $endDate]);
        $nonCurrentAssetsResult = $nonCurrentAssetsStmt->fetch(PDO::FETCH_ASSOC);
        $results['non_current_assets'] = $nonCurrentAssetsResult['balance'] ?? 0;
        
        // Current Liabilities
        $currentLiabilitiesStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%payable%' OR account_name LIKE '%short term loan%' OR account_name LIKE '%current liability%')
            AND date BETWEEN ? AND ?
        ");
        $currentLiabilitiesStmt->execute([$startDate, $endDate]);
        $currentLiabilitiesResult = $currentLiabilitiesStmt->fetch(PDO::FETCH_ASSOC);
        $results['current_liabilities'] = $currentLiabilitiesResult['balance'] ?? 0;
        
        // Non-Current Liabilities
        $nonCurrentLiabilitiesStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%loan%' OR account_name LIKE '%long term debt%' OR account_name LIKE '%non current liability%')
            AND date BETWEEN ? AND ?
            AND account_name NOT LIKE '%current liability%'
        ");
        $nonCurrentLiabilitiesStmt->execute([$startDate, $endDate]);
        $nonCurrentLiabilitiesResult = $nonCurrentLiabilitiesStmt->fetch(PDO::FETCH_ASSOC);
        $results['non_current_liabilities'] = $nonCurrentLiabilitiesResult['balance'] ?? 0;
        
    } catch (Exception $e) {
        error_log("Error calculating balance sheet summary for $companyName: " . $e->getMessage());
    }
    
    return $results;
}

// Function to get cash flow summary
function getCashFlowSummary($pdo, $companyName, $transactionsTable, $accountsTable) {
    $results = [
        'operating_cash_flow' => 0,
        'investing_cash_flow' => 0,
        'financing_cash_flow' => 0,
        'net_cash_flow' => 0
    ];
    
    if (!$transactionsTable || !tableExists($pdo, $transactionsTable)) {
        return $results;
    }
    
    try {
        $startDate = date('Y-01-01');
        $endDate = date('Y-m-d');
        
        // Operating Activities
        $operatingStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%revenue%' OR account_name LIKE '%income%' OR account_name LIKE '%expense%' 
                 OR account_name LIKE '%receivable%' OR account_name LIKE '%payable%' OR account_name LIKE '%inventory%')
            AND date BETWEEN ? AND ?
        ");
        $operatingStmt->execute([$startDate, $endDate]);
        $operatingResult = $operatingStmt->fetch(PDO::FETCH_ASSOC);
        $results['operating_cash_flow'] = $operatingResult['balance'] ?? 0;
        
        // Investing Activities
        $investingStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%equipment%' OR account_name LIKE '%property%' OR account_name LIKE '%investment%'
                 OR account_name LIKE '%asset%' OR account_name LIKE '%plant%')
            AND date BETWEEN ? AND ?
        ");
        $investingStmt->execute([$startDate, $endDate]);
        $investingResult = $investingStmt->fetch(PDO::FETCH_ASSOC);
        $results['investing_cash_flow'] = $investingResult['balance'] ?? 0;
        
        // Financing Activities
        $financingStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END) as balance
            FROM `$transactionsTable` 
            WHERE (account_name LIKE '%loan%' OR account_name LIKE '%equity%' OR account_name LIKE '%dividend%'
                 OR account_name LIKE '%capital%' OR account_name LIKE '%stock%')
            AND date BETWEEN ? AND ?
        ");
        $financingStmt->execute([$startDate, $endDate]);
        $financingResult = $financingStmt->fetch(PDO::FETCH_ASSOC);
        $results['financing_cash_flow'] = $financingResult['balance'] ?? 0;
        
        // Net Cash Flow
        $results['net_cash_flow'] = $results['operating_cash_flow'] + $results['investing_cash_flow'] + $results['financing_cash_flow'];
        
    } catch (Exception $e) {
        error_log("Error calculating cash flow summary for $companyName: " . $e->getMessage());
    }
    
    return $results;
}

// Function to check if table exists
function tableExists($pdo, $tableName) {
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE '$tableName'");
        return $checkTable->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Function to get all clients with their status and journal entry count
function getAllClientsWithJournalInfo($pdo) {
    $clients = [];
    
    try {
        // First, let's check what tables we have in the database
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        error_log("Available tables: " . implode(', ', $tables));
        
        // Get all clients from the client table
        $stmt = $pdo->query("SELECT id, `Company Name`, `Date` FROM client ORDER BY `Company Name`");
        $allClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($allClients) . " clients in client table");
        
        foreach ($allClients as $client) {
            $companyName = $client['Company Name'];
            $clientId = $client['id'];
            
            // Debug: Log each client being processed
            error_log("Processing client: " . $companyName . " (ID: " . $clientId . ")");
            
            // Count journal entries - use the same table naming pattern as choose-client-modal.php
            $journalCount = 0;
            $transactionsTable = '';
            $accountsTable = '';
            
            try {
                // Create the transactions table name (same method as choose-client-modal.php)
                $sanitizedCompanyName = preg_replace('/[^A-Za-z0-9]/', '_', $companyName);
                $transactionsTable = $sanitizedCompanyName . "_Transactions";
                $accountsTable = $sanitizedCompanyName . "_Accounts";
                
                error_log("Looking for transactions table: " . $transactionsTable);
                
                // Check if the transactions table exists
                $tableExists = tableExists($pdo, $transactionsTable);
                
                if ($tableExists) {
                    error_log("Table $transactionsTable exists");
                    
                    // Count entries in the transactions table
                    $countStmt = $pdo->prepare("SELECT COUNT(*) as entry_count FROM `$transactionsTable`");
                    $countStmt->execute();
                    $result = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $journalCount = $result['entry_count'] ?? 0;
                    
                    error_log("Found $journalCount journal entries for $companyName");
                    
                    // Let's also check what's actually in the table
                    $sampleStmt = $pdo->prepare("SELECT * FROM `$transactionsTable` LIMIT 5");
                    $sampleStmt->execute();
                    $sampleEntries = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
                    error_log("Sample entries in $transactionsTable: " . count($sampleEntries));
                    
                } else {
                    error_log("Table $transactionsTable does NOT exist");
                }
            } catch (Exception $e) {
                error_log("Error counting journal entries for $companyName: " . $e->getMessage());
                $journalCount = 0;
            }
            
            // Get financial report results using trial balance formulas
            $financialResults = getFinancialReportResults($pdo, $companyName, $transactionsTable, $accountsTable);
            
            // Get balance sheet summary
            $balanceSheetSummary = getBalanceSheetSummary($pdo, $companyName, $transactionsTable, $accountsTable);
            
            // Get cash flow summary
            $cashFlowSummary = getCashFlowSummary($pdo, $companyName, $transactionsTable, $accountsTable);
            
            // Combine all results
            $allFinancialResults = array_merge($financialResults, $balanceSheetSummary, $cashFlowSummary);
            
            // Determine client status - simplified for now
            $status = 'Active';
            $inactivationReason = null;
            
            // Try to get status from database if the column exists
            try {
                $statusStmt = $pdo->prepare("SHOW COLUMNS FROM client LIKE 'status'");
                $statusStmt->execute();
                if ($statusStmt->rowCount() > 0) {
                    $statusData = $pdo->query("SELECT status, inactivation_reason FROM client WHERE id = $clientId")->fetch(PDO::FETCH_ASSOC);
                    if ($statusData && !empty($statusData['status'])) {
                        $status = $statusData['status'];
                        $inactivationReason = $statusData['inactivation_reason'] ?? null;
                    }
                }
            } catch (Exception $e) {
                // If status column doesn't exist, use fallback
                error_log("Status column not available, using fallback: " . $e->getMessage());
            }
            
            // Fallback: determine status based on contract date
            if ($status === 'Active') {
                try {
                    if (!empty($client['Date'])) {
                        $dateAdded = new DateTime($client['Date']);
                        $endOfContract = clone $dateAdded;
                        $endOfContract->add(new DateInterval('P100D'));
                        $today = new DateTime();

                        if ($endOfContract <= $today) {
                            $status = 'Inactive';
                            $inactivationReason = 'Contract Expired';
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error parsing date for client $companyName: " . $e->getMessage());
                }
            }
            
            $clients[] = [
                'id' => $clientId,
                'company_name' => $companyName,
                'status' => $status,
                'inactivation_reason' => $inactivationReason,
                'journal_entries' => $journalCount,
                'date_added' => $client['Date'],
                'transactions_table' => $transactionsTable,
                'table_exists' => $tableExists ?? false,
                'financial_results' => $allFinancialResults
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching clients: " . $e->getMessage());
        // Return empty array but log the error
    }
    
    return $clients;
}

// Get all clients with journal info
$allClients = getAllClientsWithJournalInfo($pdo);

// Debug: Log final results
error_log("Final client count: " . count($allClients));
foreach ($allClients as $client) {
    error_log("Client: " . $client['company_name'] . " | Entries: " . $client['journal_entries'] . " | Table: " . $client['transactions_table'] . " | Exists: " . ($client['table_exists'] ? 'Yes' : 'No'));
}

// Separate active and inactive clients
$activeClients = array_filter($allClients, function($client) {
    return $client['status'] === 'Active';
});

$inactiveClients = array_filter($allClients, function($client) {
    return $client['status'] !== 'Active';
});

// Get unique inactivation reasons for filter dropdown
$inactivationReasons = array_unique(array_filter(array_column($inactiveClients, 'inactivation_reason')));

// Handle search and filtering
$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$reasonFilter = $_GET['reason'] ?? 'all';

if ($searchTerm) {
    $searchTerm = strtolower($searchTerm);
    $activeClients = array_filter($activeClients, function($client) use ($searchTerm) {
        return strpos(strtolower($client['company_name']), $searchTerm) !== false;
    });
    
    $inactiveClients = array_filter($inactiveClients, function($client) use ($searchTerm) {
        return strpos(strtolower($client['company_name']), $searchTerm) !== false;
    });
}

// Apply reason filter to inactive clients
if ($reasonFilter !== 'all') {
    $inactiveClients = array_filter($inactiveClients, function($client) use ($reasonFilter) {
        return $client['inactivation_reason'] === $reasonFilter;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Journal Summary - EBTGL Accounting</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f9fc;
            color: #333;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .search-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .search-box {
            position: relative;
            margin-bottom: 15px;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #4682B4;
            outline: none;
            box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.1);
        }
        
        .filter-container {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #555;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 14px;
        }
        
        /* Status Filter Buttons - Integrated from manage-client.php */
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
            background: linear-gradient(135deg, #5a96cf, #4682B4);
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
        
        /* UPDATED: Smaller summary cards that stay side by side */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .summary-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            text-align: center;
            min-height: 90px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .summary-card.active {
            border-left: 4px solid #27ae60;
        }
        
        .summary-card.inactive {
            border-left: 4px solid #e74c3c;
        }
        
        .summary-card.total {
            border-left: 4px solid #4682B4;
        }
        
        .summary-card.entries {
            border-left: 4px solid #f39c12;
        }
        
        .summary-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 3px;
            line-height: 1.2;
        }
        
        .summary-label {
            color: #718096;
            font-size: 0.85rem;
        }
        
        /* UPDATED: Compact Color Legend with side-by-side layout */
        .color-legend {
            background: white;
            padding: 12px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        
        .legend-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .legend-items {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        
        .legend-row {
            display: contents;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f8f9fa;
            font-size: 0.8rem;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            flex-shrink: 0;
        }
        
        .legend-color.green {
            background-color: #27ae60;
        }
        
        .legend-color.red {
            background-color: #e74c3c;
        }
        
        .legend-color.blue {
            background-color: #4682B4;
        }
        
        .legend-color.gray {
            background-color: #7f8c8d;
        }
        
        .legend-label {
            font-weight: 500;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        
        .legend-description {
            font-size: 0.7rem;
            color: #718096;
            margin-top: 1px;
        }
        
        .client-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .section-header {
            background: #f8f9fa;
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .client-count {
            background: #4682B4;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .client-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .client-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 1px solid #e9ecef;
        }
        
        .client-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .client-table tr:hover {
            background-color: #f8fafc;
        }
        
        .client-table tr:last-child td {
            border-bottom: none;
        }
        
        /* NEW: Column grouping styles */
        .green-columns {
            background-color: rgba(39, 174, 96, 0.05);
        }
        
        .green-columns th {
            border-left: 3px solid #27ae60;
        }
        
        .red-columns {
            background-color: rgba(231, 76, 60, 0.05);
        }
        
        .red-columns th {
            border-left: 3px solid #e74c3c;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .status-active {
            background: #d5f5e3;
            color: #27ae60;
        }
        
        .status-inactive {
            background: #fadbd8;
            color: #e74c3c;
        }
        
        .reason-badge {
            background: #f8f9fa;
            color: #6c757d;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .entries-count {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .financial-value {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .financial-positive {
            color: #27ae60;
        }
        
        .financial-negative {
            color: #e74c3c;
        }
        
        .financial-neutral {
            color: #7f8c8d;
        }
        
        .view-entries-btn {
            background: #4682B4;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .view-entries-btn:hover {
            background: #3a6a94;
            transform: translateY(-1px);
        }
        
        .no-clients {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .no-clients i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .inactive-filter {
            background: #fff3cd;
            padding: 15px 25px;
            border-bottom: 1px solid #ffeaa7;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .debug-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 12px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .debug-toggle {
            background: #6c757d;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 10px;
        }
        
        @media (max-width: 1024px) {
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .summary-cards {
                grid-template-columns: 1fr 1fr;
            }
            
            .client-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-buttons {
                justify-content: center;
            }
            
            .section-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
            
            .filter-container {
                flex-direction: column;
            }
            
            .legend-items {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .header p {
                font-size: 1rem;
            }
            
            .legend-items {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Debug Panel (can be removed in production) -->
    <button class="debug-toggle" onclick="toggleDebug()">Toggle Debug Info</button>
    <div class="debug-panel" id="debugPanel" style="display: none;">
        <h4>Debug Information</h4>
        <p><strong>Total Clients Found:</strong> <?php echo count($allClients); ?></p>
        <p><strong>Active Clients:</strong> <?php echo count($activeClients); ?></p>
        <p><strong>Inactive Clients:</strong> <?php echo count($inactiveClients); ?></p>
        <h5>Client Details:</h5>
        <?php foreach ($allClients as $client): ?>
            <div style="margin-bottom: 10px; padding: 5px; border-bottom: 1px solid #ddd;">
                <strong><?php echo htmlspecialchars($client['company_name']); ?></strong><br>
                ID: <?php echo $client['id']; ?> | 
                Status: <?php echo $client['status']; ?> | 
                Journal Entries: <?php echo $client['journal_entries']; ?> | 
                Table: <?php echo $client['transactions_table']; ?> | 
                Exists: <?php echo $client['table_exists'] ? 'Yes' : 'No'; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="search-container">
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="searchInput" class="search-input" placeholder="Search clients by name..." 
                   value="<?php echo htmlspecialchars($searchTerm); ?>">
        </div>
        
        <!-- Status Filter Buttons - Integrated from manage-client.php -->
        <div class="status-filters">
            <button class="status-btn active" id="btnActive">
                <i class="fas fa-check-circle"></i> Active Clients
            </button>
            <button class="status-btn inactive" id="btnInactive">
                <i class="fas fa-times-circle"></i> Inactive Clients
            </button>
        </div>
    </div>
    
    <!-- UPDATED: Smaller summary cards that stay in one row -->
    <div class="summary-cards">
        <div class="summary-card total">
            <div class="summary-number"><?php echo count($allClients); ?></div>
            <div class="summary-label">Total Clients</div>
        </div>
        <div class="summary-card active">
            <div class="summary-number"><?php echo count($activeClients); ?></div>
            <div class="summary-label">Active Clients</div>
        </div>
        <div class="summary-card inactive">
            <div class="summary-number"><?php echo count($inactiveClients); ?></div>
            <div class="summary-label">Inactive Clients</div>
        </div>
        <div class="summary-card entries">
            <div class="summary-number">
                <?php 
                    $totalEntries = array_sum(array_column($allClients, 'journal_entries'));
                    echo number_format($totalEntries);
                ?>
            </div>
            <div class="summary-label">Total Journal Entries</div>
        </div>
    </div>
    
    <!-- UPDATED: Compact Color Legend with side-by-side layout -->
    <div class="color-legend">
        <h3 class="legend-title">
            <i class="fas fa-palette" style="font-size: 0.9rem;"></i>
            Financial Color Legend
        </h3>
        <div class="legend-items">
            <div class="legend-item">
                <div class="legend-color green"></div>
                <div>
                    <div class="legend-label">Green: Positive</div>
                    <div class="legend-description">Revenue, Assets, Positive Values</div>
                </div>
            </div>
            <div class="legend-item">
                <div class="legend-color red"></div>
                <div>
                    <div class="legend-label">Red: Negative</div>
                    <div class="legend-description">Expenses, Liabilities, Negative Values</div>
                </div>
            </div>
            <div class="legend-item">
                <div class="legend-color blue"></div>
                <div>
                    <div class="legend-label">Blue: Neutral</div>
                    <div class="legend-description">Counts, IDs, Informational</div>
                </div>
            </div>
            <div class="legend-item">
                <div class="legend-color gray"></div>
                <div>
                    <div class="legend-label">Gray: Balanced</div>
                    <div class="legend-description">Zero or Balanced Values</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Active Clients Section -->
    <div class="client-section" id="activeClientsSection">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-check-circle text-success"></i>
                Active Clients
                <span class="client-count"><?php echo count($activeClients); ?></span>
            </h2>
        </div>
        
        <?php if (count($activeClients) > 0): ?>
            <div class="table-responsive">
                <table class="client-table">
                    <thead>
                        <tr>
                            <th>Company Name</th>
                            <!-- Green Columns (Positive Financial Indicators) -->
                            <th class="green-columns">Total Revenue</th>
                            <th class="green-columns">Total Assets</th>
                            <th class="green-columns">Current Assets</th>
                            <th class="green-columns">Non-Current Assets</th>
                            <th class="green-columns">Total Debit</th>
                            <!-- Red Columns (Negative Financial Indicators) -->
                            <th class="red-columns">Total Expenses</th>
                            <th class="red-columns">Total Liabilities</th>
                            <th class="red-columns">Current Liabilities</th>
                            <th class="red-columns">Non-Current Liabilities</th>
                            <th class="red-columns">Total Credit</th>
                            <!-- Variable Columns (Can be positive or negative) -->
                            <th>Net Income</th>
                            <th>Equity</th>
                            <th>Operating Cash Flow</th>
                            <th>Investing Cash Flow</th>
                            <th>Financing Cash Flow</th>
                            <th>Net Cash Flow</th>
                            <th>Balance Difference</th>
                            <th>Status</th>
                            <th>Journal Entries</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeClients as $client): 
                            $financial = $client['financial_results'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($client['company_name']); ?></strong>
                                <?php if (!$client['table_exists']): ?>
                                    <br><small class="text-muted">(No transaction table)</small>
                                <?php endif; ?>
                            </td>
                            <!-- Green Columns (Positive Financial Indicators) -->
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['total_revenue'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['total_assets'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['current_assets'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['non_current_assets'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['total_debit'], 2); ?>
                                </span>
                            </td>
                            <!-- Red Columns (Negative Financial Indicators) -->
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['total_expenses'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['total_liabilities'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['current_liabilities'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['non_current_liabilities'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['total_credit'], 2); ?>
                                </span>
                            </td>
                            <!-- Variable Columns (Can be positive or negative) -->
                            <td>
                                <span class="financial-value <?php echo $financial['net_income'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['net_income'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['equity'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['equity'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['operating_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['operating_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['investing_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['investing_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['financing_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['financing_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['net_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['net_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo abs($financial['balance_difference']) < 0.01 ? 'financial-neutral' : ($financial['balance_difference'] >= 0 ? 'financial-positive' : 'financial-negative'); ?>">
                                    $<?php echo number_format($financial['balance_difference'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-active">Active</span>
                            </td>
                            <td>
                                <span class="entries-count"><?php echo number_format($client['journal_entries']); ?></span>
                            </td>
                            <td>
                                <button class="view-entries-btn" onclick="viewClientJournals('<?php echo htmlspecialchars($client['company_name']); ?>', <?php echo $client['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Entries
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-clients">
                <i class="fas fa-users"></i>
                <h3>No Active Clients</h3>
                <p>There are currently no active clients in the system.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Inactive Clients Section -->
    <div class="client-section" id="inactiveClientsSection" style="display: none;">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-times-circle text-danger"></i>
                Inactive Clients
                <span class="client-count"><?php echo count($inactiveClients); ?></span>
            </h2>
        </div>
        
        <?php if (count($inactiveClients) > 0): ?>
            <div class="inactive-filter">
                <div class="filter-group">
                    <div class="filter-label">Filter by Inactivation Reason:</div>
                    <select id="reasonFilter" class="filter-select" onchange="setReasonFilter(this.value)">
                        <option value="all" <?php echo $reasonFilter === 'all' ? 'selected' : ''; ?>>All Reasons</option>
                        <?php foreach ($inactivationReasons as $reason): ?>
                            <option value="<?php echo htmlspecialchars($reason); ?>" 
                                    <?php echo $reasonFilter === $reason ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($reason); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="client-table">
                    <thead>
                        <tr>
                            <th>Company Name</th>
                            <!-- Green Columns (Positive Financial Indicators) -->
                            <th class="green-columns">Total Revenue</th>
                            <th class="green-columns">Total Assets</th>
                            <th class="green-columns">Current Assets</th>
                            <th class="green-columns">Non-Current Assets</th>
                            <th class="green-columns">Total Debit</th>
                            <!-- Red Columns (Negative Financial Indicators) -->
                            <th class="red-columns">Total Expenses</th>
                            <th class="red-columns">Total Liabilities</th>
                            <th class="red-columns">Current Liabilities</th>
                            <th class="red-columns">Non-Current Liabilities</th>
                            <th class="red-columns">Total Credit</th>
                            <!-- Variable Columns (Can be positive or negative) -->
                            <th>Net Income</th>
                            <th>Equity</th>
                            <th>Operating Cash Flow</th>
                            <th>Investing Cash Flow</th>
                            <th>Financing Cash Flow</th>
                            <th>Net Cash Flow</th>
                            <th>Balance Difference</th>
                            <th>Status</th>
                            <th>Inactivation Reason</th>
                            <th>Journal Entries</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inactiveClients as $client): 
                            $financial = $client['financial_results'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($client['company_name']); ?></strong>
                                <?php if (!$client['table_exists']): ?>
                                    <br><small class="text-muted">(No transaction table)</small>
                                <?php endif; ?>
                            </td>
                            <!-- Green Columns (Positive Financial Indicators) -->
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['total_revenue'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['total_assets'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['current_assets'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['non_current_assets'], 2); ?>
                                </span>
                            </td>
                            <td class="green-columns">
                                <span class="financial-value financial-positive">
                                    $<?php echo number_format($financial['total_debit'], 2); ?>
                                </span>
                            </td>
                            <!-- Red Columns (Negative Financial Indicators) -->
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['total_expenses'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['total_liabilities'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['current_liabilities'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['non_current_liabilities'], 2); ?>
                                </span>
                            </td>
                            <td class="red-columns">
                                <span class="financial-value financial-negative">
                                    $<?php echo number_format($financial['total_credit'], 2); ?>
                                </span>
                            </td>
                            <!-- Variable Columns (Can be positive or negative) -->
                            <td>
                                <span class="financial-value <?php echo $financial['net_income'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['net_income'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['equity'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['equity'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['operating_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['operating_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['investing_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['investing_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['financing_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['financing_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo $financial['net_cash_flow'] >= 0 ? 'financial-positive' : 'financial-negative'; ?>">
                                    $<?php echo number_format($financial['net_cash_flow'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="financial-value <?php echo abs($financial['balance_difference']) < 0.01 ? 'financial-neutral' : ($financial['balance_difference'] >= 0 ? 'financial-positive' : 'financial-negative'); ?>">
                                    $<?php echo number_format($financial['balance_difference'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-inactive">Inactive</span>
                            </td>
                            <td>
                                <span class="reason-badge"><?php echo htmlspecialchars($client['inactivation_reason'] ?? 'Unknown'); ?></span>
                            </td>
                            <td>
                                <span class="entries-count"><?php echo number_format($client['journal_entries']); ?></span>
                            </td>
                            <td>
                                <button class="view-entries-btn" onclick="viewClientJournals('<?php echo htmlspecialchars($client['company_name']); ?>', <?php echo $client['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Entries
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-clients">
                <i class="fas fa-users-slash"></i>
                <h3>No Inactive Clients</h3>
                <p>All clients are currently active.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Real-time search functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            filterTable(searchTerm);
        });
        
        function filterTable(searchTerm) {
            // Determine which table is currently visible
            const activeSection = document.getElementById('activeClientsSection');
            const inactiveSection = document.getElementById('inactiveClientsSection');
            
            let rows;
            if (activeSection.style.display !== 'none') {
                rows = activeSection.querySelectorAll('.client-table tbody tr');
            } else {
                rows = inactiveSection.querySelectorAll('.client-table tbody tr');
            }
            
            rows.forEach(row => {
                const companyName = row.cells[0].textContent.toLowerCase();
                if (companyName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // Status filter functionality - Integrated from manage-client.php
        document.getElementById('btnActive').addEventListener('click', function() {
            this.classList.add('active');
            document.getElementById('btnInactive').classList.remove('active');
            document.getElementById('activeClientsSection').style.display = 'block';
            document.getElementById('inactiveClientsSection').style.display = 'none';
            
            // Log the filter activity
            logAuditAction('FILTER_JOURNAL_SUMMARY', 'Filtered to view Active Clients');
        });
        
        document.getElementById('btnInactive').addEventListener('click', function() {
            this.classList.add('active');
            document.getElementById('btnActive').classList.remove('active');
            document.getElementById('activeClientsSection').style.display = 'none';
            document.getElementById('inactiveClientsSection').style.display = 'block';
            
            // Log the filter activity
            logAuditAction('FILTER_JOURNAL_SUMMARY', 'Filtered to view Inactive Clients');
        });
        
        function setReasonFilter(reason) {
            const url = new URL(window.location);
            const currentStatus = 'inactive';
            
            if (reason !== 'all') {
                url.searchParams.set('reason', reason);
            } else {
                url.searchParams.delete('reason');
            }
            
            url.searchParams.set('status', currentStatus);
            window.location.href = url.toString();
            
            // Log the reason filter activity
            logAuditAction('FILTER_JOURNAL_SUMMARY', `Filtered inactive clients by reason: ${reason}`);
        }
        
        function viewClientJournals(companyName, clientId) {
            // Log the view entries action
            logAuditAction('VIEW_CLIENT_JOURNAL', `Viewed journal entries for client: ${companyName}`, clientId);
            
            // Open the client's journal entries in a new tab or modal
            window.location.href = `choose-client-modal.php?company=${encodeURIComponent(companyName)}`;
        }
        
        function toggleDebug() {
            const debugPanel = document.getElementById('debugPanel');
            debugPanel.style.display = debugPanel.style.display === 'none' ? 'block' : 'none';
        }
        
        // Function to log audit actions via AJAX
        function logAuditAction(actionType, description, clientId = null) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'log_journal_audit.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            const data = `action_type=${encodeURIComponent(actionType)}&description=${encodeURIComponent(description)}${clientId ? '&client_id=' + clientId : ''}`;
            
            xhr.send(data);
        }
        
        // Log page view
        document.addEventListener('DOMContentLoaded', function() {
            logAuditAction('VIEW_JOURNAL_SUMMARY', 'Accessed Client Journal Summary page');
        });
    </script>
</body>
</html>