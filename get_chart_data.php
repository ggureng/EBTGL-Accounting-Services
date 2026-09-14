<?php
session_start();
require 'db_connection.php';
require 'functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$user_id = $_SESSION['user_id'] ?? null;
$company_name = $_SESSION['company_name'] ?? null;

if (!$user_id || !$company_name) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'User not logged in or company not set']);
    exit;
}

$company = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
$transactionsTable = "{$company}_Transactions";
$accountsTable = "{$company}_Accounts";

// Get filter parameters
$filter = $_GET['time_period'] ?? 'annual';

// Calculate date range based on filter
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

// Initialize response data structure
$responseData = [
    'balanceSheetData' => [
        'current_assets' => 0,
        'non_current_assets' => 0,
        'current_liabilities' => 0,
        'non_current_liabilities' => 0,
        'equity' => 0,
        'total_assets' => 0,
        'total_liabilities' => 0
    ],
    'incomeStatementData' => [
        'total_revenue' => 0,
        'total_expense' => 0,
        'net_income' => 0,
        'profit_margin' => 0,
        'periods' => [],
        'revenue_data' => [],
        'expense_data' => [],
        'net_income_data' => []
    ],
    'trialBalanceData' => [
        'typeTotals' => [
            'Asset' => ['debit' => 0, 'credit' => 0],
            'Liability' => ['debit' => 0, 'credit' => 0],
            'Equity' => ['debit' => 0, 'credit' => 0],
            'Revenue' => ['debit' => 0, 'credit' => 0],
            'Expense' => ['debit' => 0, 'credit' => 0]
        ],
        'paretoData' => [],
        'total_debit' => 0,
        'total_credit' => 0
    ],
    'accountTrendsData' => [],
    'debitCreditData' => []
];

try {
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

    // Update balance sheet data
    $responseData['balanceSheetData'] = [
        'current_assets' => abs($current_assets_total),
        'non_current_assets' => abs($non_current_assets_total),
        'current_liabilities' => abs($current_liabilities_total),
        'non_current_liabilities' => abs($non_current_liabilities_total),
        'equity' => abs($total_equity),
        'total_assets' => $total_assets,
        'total_liabilities' => $total_liabilities
    ];

    // Get Income Statement data
    $income_stmt = $pdo->prepare("SELECT a.name, a.type,
           SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
    FROM `$accountsTable` a
    JOIN `$transactionsTable` t ON a.name = t.account_name
    WHERE a.type IN ('Revenue', 'Expense') AND t.date BETWEEN ? AND ?
    GROUP BY a.name, a.type");
    $income_stmt->execute([$startDate, $endDate]);
    $incomeAccounts = $income_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_revenue = 0;
    $total_expense = 0;

    foreach ($incomeAccounts as $account) {
        if ($account['type'] === 'Revenue') {
            $total_revenue += abs($account['balance']);
        } else {
            $total_expense += abs($account['balance']);
        }
    }

    $net_income = $total_revenue - $total_expense;
    $profit_margin = $total_revenue > 0 ? ($net_income / $total_revenue) * 100 : 0;

    // Get data for multiple periods for trend analysis
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

    // Update income statement data
    $responseData['incomeStatementData'] = [
        'total_revenue' => $total_revenue,
        'total_expense' => $total_expense,
        'net_income' => $net_income,
        'profit_margin' => $profit_margin,
        'periods' => $periods,
        'revenue_data' => $revenue_data,
        'expense_data' => $expense_data,
        'net_income_data' => $net_income_data
    ];

    // Get Trial Balance data
    $trial_balance_stmt = $pdo->prepare("SELECT a.name, a.type,
            SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE 0 END) as total_credit
        FROM `$accountsTable` a
        LEFT JOIN `$transactionsTable` t ON a.name = t.account_name
        WHERE t.date BETWEEN ? AND ?
        GROUP BY a.name, a.type");
    $trial_balance_stmt->execute([$startDate, $endDate]);
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

    // Prepare data for Pareto chart
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
        $percentage = $totalBalance > 0 ? ($account['balance'] / $totalBalance) * 100 : 0;
        $cumulative += $percentage;
        $paretoData[] = [
            'name' => $account['name'],
            'balance' => $account['balance'],
            'percentage' => $percentage,
            'cumulative' => $cumulative,
            'type' => $account['type']
        ];
    }

    // Update trial balance data
    $responseData['trialBalanceData'] = [
        'typeTotals' => $typeTotals,
        'paretoData' => array_slice($paretoData, 0, 10),
        'total_debit' => $trial_total_debit,
        'total_credit' => $trial_total_credit
    ];

    // Get General Ledger data
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

    // Get debit/credit totals for each account
    foreach ($specialAccounts as $accountName) {
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
    }

    // Update general ledger data
    $responseData['accountTrendsData'] = $accountTrendsData;
    $responseData['debitCreditData'] = $debitCreditData;

} catch (Exception $e) {
    // Log error but still return the response with default data
    error_log("Error in get_chart_data.php: " . $e->getMessage());
    $responseData['error'] = 'Error fetching data: ' . $e->getMessage();
}

// Return data as JSON
header('Content-Type: application/json');
echo json_encode($responseData);
?>