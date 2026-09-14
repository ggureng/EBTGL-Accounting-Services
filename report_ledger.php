<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require 'db_connection.php';

if (isset($_GET['admin']) && $_GET['admin'] == 1 && isset($_GET['user_id'])) {
    $stmt = $pdo->prepare("SELECT `Company Name` FROM client WHERE id = ?");
    $stmt->execute([$_GET['user_id']]);
    $client = $stmt->fetch();
    if ($client) {
        $_SESSION['user_id'] = $_GET['user_id'];
        $_SESSION['company_name'] = $client['Company Name'];
    } else {
        die("Invalid user ID.");
    }
}

$company_name = $_SESSION['company_name'] ?? null;
if (!$company_name) {
    die("Company name not set in session.");
}
$company = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
$accountsTable = $company . "_Accounts";
$transactionsTable = $company . "_Transactions";

$filter = $_GET['filter'] ?? 'annual';
$forecast_period = $_GET['forecast'] ?? null;
$apply_recommendations = isset($_GET['apply_recs']);

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

function fmtPeso($amount) {
    if ($amount === null || $amount === '') return '';
    return '₱ ' . ($amount < 0 ? '(' . number_format(abs($amount), 2) . ')' : number_format($amount, 2));
}

// FIX #2: Changed to SELECT DISTINCT to prevent duplicate accounts
try {
    $stmt = $pdo->prepare("SELECT DISTINCT name FROM `$accountsTable`");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error fetching accounts: " . $e->getMessage());
}

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

$accountTotals = [];
$monthlyData = [];
$forecastedData = [];
$rec_forecastedData = [];

// Initialize accountTotals with zeros for all special accounts
foreach ($specialAccounts as $accountName) {
    $accountTotals[$accountName] = [
        'debit' => 0,
        'credit' => 0,
        'net' => 0
    ];
}

// Get monthly data for charts
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
        $monthlyData[$row['account_name']][$monthYear] = [
            'debit' => $row['total_debit'],
            'credit' => $row['total_credit'],
            'net' => $row['net_balance']
        ];
    }
} catch (Exception $e) {
    // Continue without monthly data if there's an error
}

foreach ($specialAccounts as $accountName) {
    try {
        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM `$transactionsTable` WHERE account_name = ? AND entry_type = 'debit' AND date BETWEEN ? AND ?");
        $stmt->execute([$accountName, $startDate, $endDate]);
        $debitTotal = $stmt->fetchColumn() ?: 0;

        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM `$transactionsTable` WHERE account_name = ? AND entry_type = 'credit' AND date BETWEEN ? AND ?");
        $stmt->execute([$accountName, $startDate, $endDate]);
        $creditTotal = $stmt->fetchColumn() ?: 0;

        if (in_array($accountName, ['Cash', 'Accounts Receivable', 'Inventory', 'Drawings', 'Salaries Expense'])) {
            $netTotal = $debitTotal - $creditTotal;
        } else {
            $netTotal = $creditTotal - $debitTotal;
        }

        $accountTotals[$accountName] = [
            'debit' => $debitTotal,
            'credit' => $creditTotal,
            'net' => $netTotal
        ];
    } catch (Exception $e) {
        // Keep the default zeros if there's an error
    }
}

// Initialize forecast data arrays
$forecastedData = [];
$rec_forecastedData = [];

// Generate forecast if requested
if ($forecast_period) {
    foreach ($specialAccounts as $accountName) {
        $forecastedData[$accountName] = calculateForecast($accountTotals[$accountName]['net'], $forecast_period);
        
        if ($apply_recommendations) {
            $rec_forecastedData[$accountName] = applyLedgerRecommendations($accountName, $forecastedData[$accountName], $accountTotals);
        }
    }
}

// Function to calculate forecast
function calculateForecast($current, $period) {
    $growth = 0.05; // 5% base growth
    
    // Adjust growth based on period
    switch($period) {
        case 'monthly':
            $growth = 0.01; // 1% monthly growth
            break;
        case 'quarterly':
            $growth = 0.03; // 3% quarterly growth
            break;
        case 'biannually':
            $growth = 0.06; // 6% bi-annual growth
            break;
        case 'annually':
            $growth = 0.12; // 12% annual growth
            break;
    }
    
    // Apply growth
    return $current * (1 + $growth);
}

// Function to apply recommendations
function applyLedgerRecommendations($accountName, $currentValue, $accountTotals) {
    $adjusted = $currentValue;
    
    // Different recommendations based on account type
    switch($accountName) {
        case 'Cash':
            // If cash is low compared to expenses
            $expenseRatio = (isset($accountTotals['Salaries Expense']['net']) && $accountTotals['Salaries Expense']['net'] > 0) ? 
                $currentValue / $accountTotals['Salaries Expense']['net'] : 0;
            if ($expenseRatio < 3) {
                $adjusted = $currentValue * 1.15; // Increase cash by 15%
            }
            break;
            
        case 'Accounts Receivable':
            // If receivables are high compared to revenue
            $revenueRatio = (isset($accountTotals['Sales Revenue']['net']) && $accountTotals['Sales Revenue']['net'] > 0) ? 
                $currentValue / $accountTotals['Sales Revenue']['net'] : 0;
            if ($revenueRatio > 0.3) {
                $adjusted = $currentValue * 0.85; // Reduce receivables by 15%
            }
            break;
            
        case 'Inventory':
            // If inventory is high compared to revenue
            $inventoryRatio = (isset($accountTotals['Sales Revenue']['net']) && $accountTotals['Sales Revenue']['net'] > 0) ? 
                $currentValue / $accountTotals['Sales Revenue']['net'] : 0;
            if ($inventoryRatio > 0.4) {
                $adjusted = $currentValue * 0.9; // Reduce inventory by 10%
            }
            break;
            
        case 'Accounts Payable':
            // If payables are high compared to expenses
            $payableRatio = (isset($accountTotals['Salaries Expense']['net']) && $accountTotals['Salaries Expense']['net'] > 0) ? 
                $currentValue / $accountTotals['Salaries Expense']['net'] : 0;
            if ($payableRatio > 0.5) {
                $adjusted = $currentValue * 0.8; // Reduce payables by 20%
            }
            break;
    }
    
    return $adjusted;
}

// Generate recommendations
$recommendations = [];
foreach ($specialAccounts as $account) {
    if (!isset($accountTotals[$account])) continue;
    
    $net = $accountTotals[$account]['net'];
    $debit = $accountTotals[$account]['debit'];
    $credit = $accountTotals[$account]['credit'];
    
    switch($account) {
        case 'Cash':
            $status = $net > 0 ? 'Healthy cash position' : 'Cash deficit detected';
            $action = $net <= 0 ? 
                '1. Review expenses and prioritize essential spending<br>
                 2. Explore short-term financing options<br>
                 3. Accelerate accounts receivable collection' : 
                'Maintain current cash management practices';
            $recommendations[] = [
                'account' => $account,
                'title' => 'Cash Management',
                'value' => fmtPeso($net),
                'status' => $status,
                'action' => $action
            ];
            break;
            
        case 'Accounts Receivable':
            $ratio = (isset($accountTotals['Sales Revenue']['net']) && $accountTotals['Sales Revenue']['net'] > 0) ? 
                $net / $accountTotals['Sales Revenue']['net'] : 0;
            $status = $ratio < 0.25 ? 'Healthy receivables level' : 'High receivables concern';
            $action = $ratio >= 0.25 ? 
                '1. Implement stricter credit policies<br>
                 2. Offer early payment discounts<br>
                 3. Follow up on overdue accounts regularly' : 
                'Continue current receivables management';
            $recommendations[] = [
                'account' => $account,
                'title' => 'Receivables Management',
                'value' => fmtPeso($net) . ' (' . number_format($ratio * 100, 1) . '% of revenue)',
                'status' => $status,
                'action' => $action
            ];
            break;
            
        case 'Inventory':
            $ratio = (isset($accountTotals['Sales Revenue']['net']) && $accountTotals['Sales Revenue']['net'] > 0) ? 
                $net / $accountTotals['Sales Revenue']['net'] : 0;
            $status = $ratio < 0.3 ? 'Optimal inventory level' : 'Excess inventory detected';
            $action = $ratio >= 0.3 ? 
                '1. Implement just-in-time inventory system<br>
                 2. Run promotions to clear slow-moving items<br>
                 3. Review purchasing policies' : 
                'Maintain current inventory management';
            $recommendations[] = [
                'account' => $account,
                'title' => 'Inventory Management',
                'value' => fmtPeso($net) . ' (' . number_format($ratio * 100, 1) . '% of revenue)',
                'status' => $status,
                'action' => $action
            ];
            break;
            
        case 'Accounts Payable':
            $ratio = (isset($accountTotals['Salaries Expense']['net']) && $accountTotals['Salaries Expense']['net'] > 0) ? 
                $net / $accountTotals['Salaries Expense']['net'] : 0;
            $status = $ratio < 0.4 ? 'Manageable payables' : 'High payables concern';
            $action = $ratio >= 0.4 ? 
                '1. Negotiate extended payment terms with suppliers<br>
                 2. Prioritize payments to avoid penalties<br>
                 3. Consider consolidating payables' : 
                'Continue current payables management';
            $recommendations[] = [
                'account' => $account,
                'title' => 'Payables Management',
                'value' => fmtPeso($net) . ' (' . number_format($ratio * 100, 1) . '% of expenses)',
                'status' => $status,
                'action' => $action
            ];
            break;
            
        case 'Sales Revenue':
            $trend = isset($monthlyData[$account]) ? 
                getTrend(array_column($monthlyData[$account], 'net')) : 'stable';
            $status = $trend == 'increasing' ? 'Revenue growth positive' : 'Revenue needs attention';
            $action = $trend != 'increasing' ? 
                '1. Develop new marketing initiatives<br>
                 2. Expand to new customer segments<br>
                 3. Introduce new products/services' : 
                'Continue current revenue generation strategies';
            $recommendations[] = [
                'account' => $account,
                'title' => 'Revenue Growth',
                'value' => fmtPeso($net) . ' (' . ucfirst($trend) . ' trend)',
                'status' => $status,
                'action' => $action
            ];
            break;
            
        case 'Salaries Expense':
            $ratio = (isset($accountTotals['Sales Revenue']['net']) && $accountTotals['Sales Revenue']['net'] > 0) ? 
                $net / $accountTotals['Sales Revenue']['net'] : 0;
            $status = $ratio < 0.4 ? 'Controlled labor costs' : 'High labor cost concern';
            $action = $ratio >= 0.4 ? 
                '1. Review staffing levels and productivity<br>
                 2. Consider outsourcing non-core functions<br>
                 3. Implement efficiency improvements' : 
                'Maintain current labor cost management';
            $recommendations[] = [
                'account' => $account,
                'title' => 'Expense Management',
                'value' => fmtPeso($net) . ' (' . number_format($ratio * 100, 1) . '% of revenue)',
                'status' => $status,
                'action' => $action
            ];
            break;
    }
}

// Helper function to determine trend
function getTrend($values) {
    if (count($values) < 2) return 'stable';
    
    $first = $values[0];
    $last = $values[count($values) - 1];
    
    if ($last > $first * 1.1) return 'increasing';
    if ($last < $first * 0.9) return 'decreasing';
    return 'stable';
}

$backUrl = (isset($_GET['admin']) && isset($_GET['user_id']))
    ? 'client_details.php?user_id=' . $_GET['user_id']
    : 'asset.php';

// Check if this is an AJAX request
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax && isset($_GET['ajax_forecast'])) {
    // Return only the forecast section for AJAX requests
    ob_start();
    
    if ($_GET['forecast']) {
        ?>
        <!-- Forecast Results - Dynamic layout based on recommendations -->
        <div class="forecast-section">
            <h4 class="forecast-header">
                <i class="fas fa-crystal-ball"></i> Financial Forecast
                <span class="badge bg-primary"><?= ucfirst($_GET['forecast']) ?> Projection</span>
                <?php if ($apply_recommendations): ?>
                    <span class="badge bg-success">With AI Recommendations</span>
                <?php endif; ?>
            </h4>
            
            <div class="timeline-container">
                <div class="timeline-point">
                    <div class="timeline-marker">1</div>
                    <div class="timeline-label">Current</div>
                </div>
                <div class="timeline-point">
                    <div class="timeline-marker active-marker">2</div>
                    <div class="timeline-label">Projected</div>
                </div>
                <?php if ($apply_recommendations && !empty($rec_forecastedData)): ?>
                <div class="timeline-point">
                    <div class="timeline-marker active-marker">3</div>
                    <div class="timeline-label">Recommended</div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="row">
                <?php if ($apply_recommendations && !empty($rec_forecastedData)): ?>
                    <!-- Three equal columns when recommendations are applied -->
                    <div class="col-md-4">
                        <div class="scenario-card">
                            <div class="scenario-header" style="color: #1a5f9c;">
                                <i class="fas fa-file-invoice-dollar"></i> Current Position
                            </div>
                            <ul class="list-group">
                                <?php foreach ($specialAccounts as $account): ?>
                                    <?php if (isset($accountTotals[$account])): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <?= $account ?>
                                            <span><?= fmtPeso($accountTotals[$account]['net']) ?></span>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="scenario-card">
                            <div class="scenario-header" style="color: #1a5f9c;">
                                <i class="fas fa-chart-line"></i> Projected Position
                                <span class="recommendation-badge">Forecast</span>
                            </div>
                            <ul class="list-group">
                                <?php foreach ($specialAccounts as $account): ?>
                                    <?php if (isset($forecastedData[$account])): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <?= $account ?>
                                            <span><?= fmtPeso($forecastedData[$account]) ?></span>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <div class="mt-3">
                                <p class="mb-1"><strong>Projection Basis:</strong></p>
                                <p class="small">Based on current growth trends (5% base + period adjustment)</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="scenario-card" style="border: 3px solid #28a745; background-color: #f8fff9;">
                            <div class="scenario-header" style="color: #1a5f9c;">
                                <i class="fas fa-lightbulb"></i> Recommended Position
                                <span class="recommendation-badge">Optimized</span>
                            </div>
                            <ul class="list-group">
                                <?php foreach ($specialAccounts as $account): ?>
                                    <?php if (isset($rec_forecastedData[$account]) && $rec_forecastedData[$account] !== null): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <?= $account ?>
                                            <span><?= fmtPeso($rec_forecastedData[$account]) ?></span>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <div class="mt-3">
                                <p class="mb-1"><strong>Improvements:</strong></p>
                                <ul class="small">
                                    <li>Working capital optimization</li>
                                    <li>Receivables management</li>
                                    <li>Expense control</li>
                                    <li>Revenue enhancement</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Two 50/50 columns when no recommendations -->
                    <div class="col-md-6">
                        <div class="scenario-card">
                            <div class="scenario-header" style="color: #1a5f9c;">
                                <i class="fas fa-file-invoice-dollar"></i> Current Position
                            </div>
                            <ul class="list-group">
                                <?php foreach ($specialAccounts as $account): ?>
                                    <?php if (isset($accountTotals[$account])): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <?= $account ?>
                                            <span><?= fmtPeso($accountTotals[$account]['net']) ?></span>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="scenario-card">
                            <div class="scenario-header" style="color: #1a5f9c;">
                                <i class="fas fa-chart-line"></i> Projected Position
                                <span class="recommendation-badge">Forecast</span>
                            </div>
                            <ul class="list-group">
                                <?php foreach ($specialAccounts as $account): ?>
                                    <?php if (isset($forecastedData[$account])): ?>
                                        <li class="list-group-item d-flex justify-content-between">
                                            <?= $account ?>
                                            <span><?= fmtPeso($forecastedData[$account]) ?></span>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                            <div class="mt-3">
                                <p class="mb-1"><strong>Projection Basis:</strong></p>
                                <p class="small">Based on current growth trends (5% base + period adjustment)</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    $forecast_content = ob_get_clean();
    echo $forecast_content;
    exit;
}

// Generate chart interpretations
$chartInterpretations = [
    'accountTrends' => generateAccountTrendsInterpretation($monthlyData, $specialAccounts),
    'debitCredit' => generateDebitCreditInterpretation($accountTotals, $specialAccounts),
    'stackedArea' => generateStackedAreaInterpretation($monthlyData, $specialAccounts)
];

function generateAccountTrendsInterpretation($monthlyData, $specialAccounts) {
    if (empty($monthlyData)) {
        return "Insufficient data to analyze account trends. Transactions are needed across multiple periods to identify patterns.";
    }
    
    $trends = [];
    foreach ($specialAccounts as $account) {
        if (isset($monthlyData[$account]) && count($monthlyData[$account]) > 1) {
            $values = array_column($monthlyData[$account], 'net');
            $first = reset($values);
            $last = end($values);
            $change = $last - $first;
            $percentage = $first != 0 ? ($change / abs($first)) * 100 : 0;
            
            if (abs($percentage) > 10) {
                $direction = $percentage > 0 ? 'increased' : 'decreased';
                $trends[] = "$account {$direction} by " . number_format(abs($percentage), 1) . "%";
            }
        }
    }
    
    if (empty($trends)) {
        return "Account balances show relative stability across the analyzed period. No significant trends detected in major account categories.";
    }
    
    $mostSignificant = $trends[0];
    return "Analysis reveals notable trends in account activity. $mostSignificant. " . 
           "Monitoring these trends helps identify seasonal patterns and business cycle impacts on financial performance.";
}

function generateDebitCreditInterpretation($accountTotals, $specialAccounts) {
    $imbalances = [];
    
    foreach ($specialAccounts as $account) {
        if (isset($accountTotals[$account])) {
            $debit = $accountTotals[$account]['debit'];
            $credit = $accountTotals[$account]['credit'];
            
            if ($debit + $credit > 0) {
                $ratio = $debit > 0 ? $credit / $debit : 0;
                if ($ratio < 0.1 || $ratio > 10) {
                    $type = $debit > $credit ? 'debit-heavy' : 'credit-heavy';
                    $imbalances[] = "$account ($type)";
                }
            }
        }
    }
    
    if (empty($imbalances)) {
        return "Debit and credit activities appear balanced across major accounts, indicating consistent transaction patterns and proper accounting practices.";
    }
    
    return "Notable debit/credit imbalances detected in: " . implode(', ', $imbalances) . 
           ". This may indicate concentrated transaction activity or specific business processes affecting these accounts.";
}

function generateStackedAreaInterpretation($monthlyData, $specialAccounts) {
    if (empty($monthlyData)) {
        return "Stacked area chart requires monthly transaction data across accounts to visualize composition changes over time.";
    }
    
    $compositionChanges = [];
    $firstPeriod = null;
    $lastPeriod = null;
    
    foreach ($specialAccounts as $account) {
        if (isset($monthlyData[$account]) && count($monthlyData[$account]) > 1) {
            if (!$firstPeriod) {
                $firstPeriod = array_keys($monthlyData[$account])[0];
                $lastPeriod = array_keys($monthlyData[$account])[count($monthlyData[$account]) - 1];
            }
            
            $firstValue = reset($monthlyData[$account])['net'];
            $lastValue = end($monthlyData[$account])['net'];
            
            if (abs($lastValue - $firstValue) > ($firstValue * 0.15)) {
                $changeType = $lastValue > $firstValue ? 'growing' : 'declining';
                $compositionChanges[] = "$account ($changeType)";
            }
        }
    }
    
    if (empty($compositionChanges)) {
        return "The composition of account balances remains relatively stable throughout the period, suggesting consistent business operations and financial management.";
    }
    
    $significantChanges = array_slice($compositionChanges, 0, 2);
    return "The stacked area visualization shows evolving account composition from $firstPeriod to $lastPeriod. " . 
           "Notable changes include: " . implode(' and ', $significantChanges) . 
           ", indicating shifts in financial structure and operational focus.";
}

// Initialize $rec_forecastedData to prevent warnings
$rec_forecastedData = $rec_forecastedData ?? [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>General Ledger</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .timeline-container {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
            position: relative;
        }
        
        .timeline-container:before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--primary-blue);
            z-index: 1;
        }
        
        .timeline-point {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        
        .timeline-marker {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
        }
        
        .timeline-label {
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .active-marker {
            background: var(--primary-blue);
            color: white;
        }
        /* Print styles with watermark */
        @media print {
            body {
                background: white !important;
                padding: 0;
                margin: 0;
                position: relative;
            }
            
            /* WATERMARK - SINGLE LINE COVERING ENTIRE PAGE */
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
            
            .report-container {
                display: none !important;
            }
            
            .document-style {
                display: block !important;
                box-shadow: none;
                border-radius: 0;
                padding: 15px;
                margin: 0;
                width: 100%;
                max-width: 100%;
                position: relative;
                z-index: 1;
            }
            
            .no-print {
                display: none !important;
            }
            
            /* Hide interactive elements in print */
            .forecast-filter, .ai-section, .chart-section, .chart-interpretation-section {
                display: none !important;
            }
        }
        /* Blue color scheme */
        :root {
            --primary-blue: #1a5f9c;
            --secondary-blue: #4682B4;
            --light-blue: #e6f0f8;
            --dark-blue: #0d3c6e;
            --accent-blue: #5a96cf;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .report-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            margin: 20px auto;
            max-width: 97%;
            width: 97%;
            position: relative;
        }
        
        .report-header {
            padding-bottom: 20px;
            border-bottom: 2px solid var(--primary-blue);
            margin-bottom: 30px;
        }
        
        .report-title {
            color: var(--dark-blue);
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .report-subtitle {
            color: #666;
            font-weight: 500;
        }
        
        .account-header {
            color: var(--accent-blue);
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 10px;
            margin-top: 40px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .special-account-container {
            margin-top: 30px;
            margin-bottom: 20px;
        }
        
        .special-account-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            padding: 12px 15px;
            background-color: rgba(70, 130, 180, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(70, 130, 180, 0.1);
            margin-bottom: 10px;
        }

        .special-account-header:hover {
            background-color: rgba(70, 130, 180, 0.1);
        }

        .account-title {
            font-weight: 600;
            color: var(--dark-blue);
            flex-grow: 1;
        }

        .account-total {
            font-weight: 700;
            color: var(--dark-blue);
            margin-right: 15px;
        }

        .account-arrow {
            color: var(--primary-blue);
            font-size: 1.1rem;
            transition: transform 0.2s ease;
        }
        
        .special-account-header[aria-expanded="true"] .account-arrow {
            transform: rotate(180deg);
        }
        
        .table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }
        
        .table thead th {
            background-color: var(--primary-blue);
            color: white;
            font-weight: 600;
            padding: 12px 15px;
            border: none;
        }
        
        .table tbody tr {
            transition: all 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(70, 130, 180, 0.05);
        }
        
        .table tbody td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        
        .text-end {
            text-align: right;
        }
        
        .filter-container {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }
        
        .forecast-section {
            background-color: #f8f9fa;
            border-left: 4px solid var(--primary-blue);
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
            position: relative;
        }
        
        .forecast-header {
            color: var(--dark-blue);
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .scenario-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            background: white;
            height: 100%;
        }
        
        .scenario-header {
            color: var(--accent-blue);
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .scenario-header i {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .recommendation-badge {
            background-color: var(--primary-blue);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: 10px;
        }
        
        .timeline-container {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
            position: relative;
        }
        
        .timeline-container:before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: var(--primary-blue);
            z-index: 1;
        }
        
        .timeline-point {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        
        .timeline-marker {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
        }
        
        .timeline-label {
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .active-marker {
            background: var(--primary-blue);
            color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            border: none;
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--dark-blue), var(--primary-blue));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(70, 130, 180, 0.4);
        }
        
        .btn-outline-secondary {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-outline-secondary:hover {
            background-color: var(--dark-blue);
            color: white;
            transform: translateY(-2px);
        }

        /* FIX #3: Added style for net total row */
        .net-total-row td {
            border-top: 2px solid var(--primary-blue) !important;
        }
        .net-total-label {
            text-align: right;
            padding-right: 10px !important;
        }
        
        .forecast-filter {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }
        
        /* AI Recommendations Styling */
        .ai-badge {
            background: linear-gradient(135deg, #6e8efb, #a777e3);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 8px;
        }
        
        .recommendation-card {
            border-left: 4px solid #4ECDC4;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .recommendation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        
        .rec-title {
            color: #2a5a80;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .rec-value {
            background-color: #4682B4;
            color: white;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.9rem;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .rec-status {
            font-weight: 600;
            margin-bottom: 10px;
            padding: 5px 10px;
            border-radius: 4px;
            background-color: #e9f7fe;
        }
        
        .status-strong {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-concerning {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .rec-action {
            background-color: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 3px solid #4ECDC4;
        }
        
        .ai-section {
            background: linear-gradient(to right, #f8f9fa, #e9f7fe);
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            border: 1px solid #c4d8eb;
        }
        
        .api-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        /* Chart section styling */
        .chart-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e1e8ed;
        }
        
        .section-title {
            color: var(--dark-blue);
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .chart-interpretation {
            background: linear-gradient(135deg, #e6f0f8, #f0f7ff);
            border-left: 4px solid var(--primary-blue);
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        
        .interpretation-title {
            font-weight: 600;
            color: var(--dark-blue);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .interpretation-title i {
            margin-right: 8px;
            color: var(--primary-blue);
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 2s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* View Filter at Top Right - From Balance Sheet */
        .view-filter-top {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border: 1px solid #c4d8eb;
            backdrop-filter: blur(5px);
        }

        .view-filter-top .view-filter-label {
            font-weight: 600;
            color: var(--dark-blue);
            white-space: nowrap;
            margin-right: 10px;
        }

        .view-filter-top .form-select-sm {
            font-size: 0.875rem;
            padding: 0.35rem 0.75rem;
            width: 120px;
            border: 1px solid #ced4da;
            border-radius: 6px;
        }

        .view-filter-top .form-select-sm:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(26, 95, 156, 0.25);
        }

        /* NEW: Document/Receipt Style for Printing - From Balance Sheet */
        .document-style {
            display: none;
            background: white;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
            font-family: 'Times New Roman', Times, serif;
            color: #000;
            line-height: 1.4;
        }
        
        .document-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        
        .document-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .document-subtitle {
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .document-company {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .document-date {
            font-size: 14px;
        }
        
        .document-section {
            margin-bottom: 25px;
        }
        
        .document-section-title {
            font-size: 18px;
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        
        .document-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }
        
        .document-row.total {
            font-weight: bold;
            border-bottom: 2px solid #000;
            padding-top: 10px;
        }
        
        .document-row.grand-total {
            font-weight: bold;
            font-size: 18px;
            border-top: 2px solid #000;
            padding-top: 15px;
            margin-top: 10px;
        }
        
        .document-notes {
            margin-top: 30px;
            font-size: 14px;
            border-top: 1px solid #000;
            padding-top: 10px;
        }
        
        .document-footer {
            text-align: center;
            margin-top: 40px;
            font-size: 12px;
            border-top: 1px solid #000;
            padding-top: 10px;
        }

        /* Print styles for charts and interpretations */
        .print-chart-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e1e8ed;
        }
        
        .print-section-title {
            color: #1a5f9c;
            border-bottom: 2px solid #1a5f9c;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .print-chart-container {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .print-chart-placeholder {
            background: #e9f7fe;
            border: 2px dashed #4682B4;
            border-radius: 8px;
            padding: 40px 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .print-chart-placeholder i {
            font-size: 48px;
            color: #4682B4;
            margin-bottom: 15px;
        }
        
        .print-chart-placeholder h4 {
            color: #1a5f9c;
            margin-bottom: 10px;
        }
        
        .print-chart-placeholder p {
            color: #666;
            margin-bottom: 0;
        }
        
        .print-interpretation-section {
            background: linear-gradient(135deg, #f8f9fa, #e9f7fe);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            border: 1px solid #c4d8eb;
        }
        
        .print-interpretation-header {
            color: #1a5f9c;
            border-bottom: 2px solid #1a5f9c;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .print-interpretation-content {
            background: white;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #1a5f9c;
            font-size: 1rem;
            line-height: 1.6;
        }
        
        .print-interpretation-points {
            margin-top: 20px;
        }
        
        .print-interpretation-point {
            background: #f0f7ff;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 3px solid #5a96cf;
        }
        
        .print-point-title {
            font-weight: 600;
            color: #1a5f9c;
            margin-bottom: 8px;
        }
        
        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary-blue);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .back-to-top.show {
            opacity: 1;
            visibility: visible;
        }

        .back-to-top:hover {
            background: var(--dark-blue);
            transform: translateX(-50%) translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }
        
        /* Print styles */
        @media print {
            body {
                background: white !important;
                padding: 0;
                margin: 0;
            }
            
            .report-container {
                display: none !important;
            }
            
            .document-style {
                display: block !important;
                box-shadow: none;
                border-radius: 0;
                padding: 15px;
                margin: 0;
                width: 100%;
                max-width: 100%;
            }
            
            .no-print {
                display: none !important;
            }
            
            /* Hide interactive elements in print */
            .forecast-filter, .ai-section, .chart-section, .chart-interpretation-section {
                display: none !important;
            }
        }
        
        @media print {
            .no-print { display: none !important; }
            body { 
                font-size: 10pt; 
                background: white !important; 
                animation: none !important;
            }
            .report-container { 
                max-width: 100%; 
                margin: 0; 
                padding: 10px; 
                box-shadow: none;
                border-radius: 0;
            }
            .table {
                box-shadow: none;
            }
            .special-account-header {
                background-color: transparent !important;
                border: none !important;
            }
            .collapse:not(.show) {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<!-- Document/Receipt Style (Hidden by default, shown when printing) -->
<div class="document-style">
    <div class="document-header">
        <div class="document-title">GENERAL LEDGER</div>
        <div class="document-company"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-subtitle">As of <?= date('F d, Y') ?></div>
        <div class="document-date">(<?= ucfirst($filter) ?> View)</div>
    </div>
    
    <?php foreach ($accounts as $account): ?>
        <?php 
        $accountName = isset($account['name']) ? htmlspecialchars($account['name']) : 'Unnamed Account';
        $isSpecialAccount = in_array($accountName, $specialAccounts);
        
        try {
            $stmt = $pdo->prepare("SELECT date, description, entry_type, amount FROM `$transactionsTable` WHERE account_name = ? AND date BETWEEN ? AND ? ORDER BY date");
            $stmt->execute([$account['name'], $startDate, $endDate]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasTransactions = count($transactions) > 0;
        } catch (Exception $e) {
            continue;
        }
        ?>
        
        <div class="document-section">
            <div class="document-section-title"><?= $accountName ?></div>
            
            <?php if ($hasTransactions): ?>
                <?php 
                $debitTotal = 0;
                $creditTotal = 0;
                ?>
                <?php foreach ($transactions as $txn): ?>
                    <div class="document-row">
                        <div><?= htmlspecialchars($txn['date']) ?> - <?= htmlspecialchars(str_replace('?', 'P', $txn['description'])) ?></div>
                        <div>
                            <?php 
                            if ($txn['entry_type'] === 'debit') {
                                echo fmtPeso($txn['amount']);
                                $debitTotal += $txn['amount'];
                            } else {
                                echo fmtPeso($txn['amount']);
                                $creditTotal += $txn['amount'];
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="document-row total">
                    <div>Total Debit</div>
                    <div><?= fmtPeso($debitTotal) ?></div>
                </div>
                <div class="document-row total">
                    <div>Total Credit</div>
                    <div><?= fmtPeso($creditTotal) ?></div>
                </div>
                
                <?php 
                if (in_array($accountName, ['Cash', 'Accounts Receivable', 'Inventory', 'Drawings', 'Salaries Expense'])) {
                    $netTotal = $debitTotal - $creditTotal;
                } else {
                    $netTotal = $creditTotal - $debitTotal;
                }
                ?>
                <div class="document-row grand-total">
                    <div>Net Balance</div>
                    <div><?= fmtPeso($netTotal) ?></div>
                </div>
            <?php else: ?>
                <div class="document-row">
                    <div>No transactions available for this period.</div>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <!-- Special Accounts Summary for Print -->
    <div class="document-section">
        <div class="document-section-title">Special Accounts Summary</div>
        
        <?php foreach ($specialAccounts as $account): ?>
            <?php if (isset($accountTotals[$account])): ?>
                <div class="document-row">
                    <div><?= $account ?></div>
                    <div><?= fmtPeso($accountTotals[$account]['net']) ?></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Financial Visualization Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Account Trends Visualization</h4>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-line"></i>
                <h4>Account Trends Chart</h4>
                <p>Line chart showing trends in account balances over time</p>
                <p><strong>Accounts tracked:</strong> <?= implode(', ', $specialAccounts) ?></p>
            </div>
        </div>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-balance-scale"></i>
                <h4>Debit vs Credit Chart</h4>
                <p>Bar chart comparing debit and credit activities across accounts</p>
            </div>
        </div>
    </div>

    <!-- Chart Interpretation Section for Print -->
    <div class="print-interpretation-section">
        <h4 class="print-interpretation-header">
            <i class="fas fa-analytics me-2"></i>Ledger Chart Interpretation
        </h4>
        
        <div class="print-interpretation-content">
            <p><strong>Account Trends:</strong> <?= $chartInterpretations['accountTrends'] ?></p>
            <p><strong>Debit/Credit Balance:</strong> <?= $chartInterpretations['debitCredit'] ?></p>
            <p><strong>Account Composition:</strong> <?= $chartInterpretations['stackedArea'] ?></p>
        </div>
    </div>

    <!-- AI Recommendations Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Financial Health Recommendations</h4>
        
        <?php foreach ($recommendations as $key => $rec): ?>
            <div class="print-chart-container">
                <div class="print-interpretation-point">
                    <div class="print-point-title"><?= $rec['title'] ?> - <?= $rec['account'] ?></div>
                    <?php if (!empty($rec['value'])): ?>
                        <p><strong>Value:</strong> <?= $rec['value'] ?></p>
                    <?php endif; ?>
                    <p><strong>Status:</strong> <?= $rec['status'] ?></p>
                    <p><strong>Recommendations:</strong><br><?= str_replace('<br>', '<br>', $rec['action']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="document-notes">
        <p><strong>Notes:</strong></p>
        <p>This general ledger represents the financial transactions of <?= htmlspecialchars($company_name) ?> as of <?= date('F d, Y') ?>.</p>
        <p>Generated on <?= date('F j, Y \a\t g:i A') ?></p>
    </div>
    
    <div class="document-footer">
        <p>END OF REPORT</p>
    </div>
</div>

<!-- Regular Report Container (Hidden when printing) -->
<div class="report-container h-90">
    <div class="report-header text-center">
        <div class="d-flex justify-content-between align-items-center">
        </div>
        <h3 class="report-title"><?= htmlspecialchars($company_name) ?></h3>
        <h3 class="report-title">General Ledger</h3>
        <p class="report-subtitle">As of <?= date('F d, Y') ?> (<?= ucfirst($filter) ?> View)</p>
    </div>

    <!-- View Filter at Top Right -->
    <div class="view-filter-top no-print">
        <span class="view-filter-label">View:</span>
        <select class="form-select-sm" onchange="updateView(this.value)">
            <option value="monthly" <?= $filter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            <option value="quarterly" <?= $filter === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
            <option value="annual" <?= $filter === 'annual' ? 'selected' : '' ?>>Annual</option>
        </select>
    </div>

    <div class="accordion" id="accountsAccordion">
        <?php foreach ($accounts as $account): ?>
            <?php 
            $accountName = isset($account['name']) ? htmlspecialchars($account['name']) : 'Unnamed Account';
            $isSpecialAccount = in_array($accountName, $specialAccounts);
            $collapseId = 'collapse_' . preg_replace('/[^A-Za-z0-9]/', '_', $accountName);
            
            try {
                $stmt = $pdo->prepare("SELECT date, description, entry_type, amount FROM `$transactionsTable` WHERE account_name = ? AND date BETWEEN ? AND ? ORDER BY date");
                $stmt->execute([$account['name'], $startDate, $endDate]);
                $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $hasTransactions = count($transactions) > 0;
            } catch (Exception $e) {
                echo "<p>Error fetching transactions: " . $e->getMessage() . "</p>";
                continue;
            }
            ?>
            
            <?php if ($isSpecialAccount): ?>
                <div class="special-account-container">
                    <div class="special-account-header collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                        <span class="account-title"><?= $accountName ?></span>
                        <div class="d-flex align-items-center">
                            <span class="account-total me-3">
                                <?= $hasTransactions ? fmtPeso($accountTotals[$accountName]['net']) : 'No transaction' ?>
                            </span>
                            <i class="fas fa-chevron-down account-arrow"></i>
                        </div>
                    </div>
                    <!-- FIX #1: Removed data-bs-parent to fix accordion behavior -->
                    <div id="<?= $collapseId ?>" class="collapse" aria-labelledby="<?= $collapseId ?>">
                        <div class="accordion-body p-0">
                            <?php if ($hasTransactions): ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th class="text-end">Debit</th>
                                            <th class="text-end">Credit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $txn): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($txn['date']) ?></td>
                                                <td><?= htmlspecialchars(str_replace('?', 'P', $txn['description'])) ?></td>
                                                <td class="text-end">
                                                    <?= $txn['entry_type'] === 'debit' ? fmtPeso($txn['amount']) : '' ?>
                                                </td>
                                                <td class="text-end">
                                                    <?= $txn['entry_type'] === 'credit' ? fmtPeso($txn['amount']) : '' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-active">
                                            <td colspan="2" class="text-end"><strong>Total:</strong></td>
                                            <td class="text-end"><strong><?= fmtPeso($accountTotals[$accountName]['debit']) ?></strong></td>
                                            <td class="text-end"><strong><?= fmtPeso($accountTotals[$accountName]['credit']) ?></strong></td>
                                        </tr>
                                        <!-- FIX #3: Restructured Net Total row -->
                                        <tr class="table-active net-total-row">
                                            <td colspan="2" class="text-end net-total-label"><strong>Net Total:</strong></td>
                                            <td class="text-end"><strong><?= fmtPeso($accountTotals[$accountName]['net']) ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="alert alert-info">No transactions available for this period.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <h4 class="account-header"><?= $accountName ?></h4>
                <?php if ($hasTransactions): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $txn): ?>
                                <tr>
                                    <td><?= htmlspecialchars($txn['date']) ?></td>
                                    <td><?= htmlspecialchars(str_replace('â‚±', 'P', $txn['description'])) ?></td>
                                    <td class="text-end">
                                        <?= $txn['entry_type'] === 'debit' ? fmtPeso($txn['amount']) : '' ?>
                                    </td>
                                    <td class="text-end">
                                        <?= $txn['entry_type'] === 'credit' ? fmtPeso($txn['amount']) : '' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert alert-info">No transactions available for this period.</div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Financial Visualization Section - Moved below the accounts accordion -->
    <div class="chart-section no-print">
        <h4 class="section-title">Account Trends Visualization</h4>
        
        <div class="row">
            <div class="col-md-6">
                <div class="chart-container">
                    <div id="accountTrendsChart"></div>
                    <div class="chart-interpretation">
                        <div class="interpretation-title">
                            <i class="fas fa-chart-line"></i> Account Trends Analysis
                        </div>
                        <?= $chartInterpretations['accountTrends'] ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <div id="debitCreditChart"></div>
                    <div class="chart-interpretation">
                        <div class="interpretation-title">
                            <i class="fas fa-balance-scale"></i> Debit/Credit Balance Analysis
                        </div>
                        <?= $chartInterpretations['debitCredit'] ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="chart-container">
                    <div id="stackedAreaChart"></div>
                    <div class="chart-interpretation">
                        <div class="interpretation-title">
                            <i class="fas fa-layer-group"></i> Account Composition Analysis
                        </div>
                        <?= $chartInterpretations['stackedArea'] ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Forecast Filter Section - Updated with balance sheet style -->
    <div class="forecast-filter no-print">
        <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>Financial Forecast</h5>
        <form id="forecastForm" method="GET" class="row g-3 align-items-center">
            <?php if (isset($_GET['admin']) && isset($_GET['user_id'])): ?>
                <input type="hidden" name="admin" value="1">
                <input type="hidden" name="user_id" value="<?= $_GET['user_id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="filter" value="<?= $filter ?>">
            <input type="hidden" name="ajax_forecast" value="1">
            
            <div class="col-md-5">
                <label class="form-label">Forecast Period:</label>
                <select name="forecast" class="form-select">
                    <option value="">-- Select Forecast Period --</option>
                    <option value="monthly" <?= $forecast_period === 'monthly' ? 'selected' : '' ?>>Monthly Projection</option>
                    <option value="quarterly" <?= $forecast_period === 'quarterly' ? 'selected' : '' ?>>Quarterly Projection</option>
                    <option value="biannually" <?= $forecast_period === 'biannually' ? 'selected' : '' ?>>Bi-Annual Projection</option>
                    <option value="annually" <?= $forecast_period === 'annually' ? 'selected' : '' ?>>Annual Projection</option>
                </select>
            </div>
            
            <div class="col-md-5">
                <div class="form-check mt-4 pt-2">
                    <input class="form-check-input" type="checkbox" name="apply_recs" id="applyRecs" <?= $apply_recommendations ? 'checked' : '' ?>>
                    <label class="form-check-label" for="applyRecs">
                        Apply recommendations to forecast
                    </label>
                </div>
            </div>
            
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary mt-3">
                    <i class="fas fa-calculator me-2"></i> Forecast
                </button>
            </div>
        </form>
    </div>

    <!-- Initially empty forecast results section -->
    <div id="forecastResults">
        <!-- Forecast content will be loaded here via AJAX -->
    </div>

    <!-- AI Recommendations Section - ALWAYS DISPLAYED AFTER FINANCIAL FORECAST -->
    <div class="ai-section">
        <h4><i class="fas fa-robot me-2 text-primary"></i> AI-Powered Financial Health Recommendations</h4>
        <p class="text-muted">Our analysis of your current ledger provides these tailored recommendations:</p>
        
        <?php if (!empty($recommendations)): ?>
        <div class="row">
            <?php foreach ($recommendations as $rec): ?>
                <div class="col-md-6 mb-4">
                    <div class="recommendation-card">
                        <div class="rec-title">
                            <?= $rec['title'] ?> - <?= $rec['account'] ?>
                            <span class="ai-badge">Analysis</span>
                        </div>
                        <?php if (!empty($rec['value'])): ?>
                            <div class="rec-value"><?= $rec['value'] ?></div>
                        <?php endif; ?>
                        <div class="rec-status <?= strpos(strtolower($rec['status']), 'healthy') !== false || strpos(strtolower($rec['status']), 'positive') !== false || strpos(strtolower($rec['status']), 'optimal') !== false ? 'status-strong' : (strpos(strtolower($rec['status']), 'concern') !== false || strpos(strtolower($rec['status']), 'deficit') !== false ? 'status-concerning' : '') ?>">
                            <?= $rec['status'] ?>
                        </div>
                        <div class="rec-action"><?= $rec['action'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            No specific recommendations available at this time. Continue monitoring your financial performance.
        </div>
        <?php endif; ?>
        
        <div class="mt-3 text-end">
            <small class="text-muted">Recommendations generated on <?= date('F j, Y \a\t g:i A') ?> using financial analysis algorithms</small>
        </div>
    </div>
    
    <div class="mt-5 text-center text-muted">
        <p>Generated on <?= date('F j, Y \a\t g:i A') ?></p>
    </div>
</div>

<!-- Back to Top Button -->
<div class="back-to-top no-print" id="backToTop">
    <i class="fas fa-arrow-up"></i>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- ApexCharts Library -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.3/dist/apexcharts.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
// Function to update view filter (from trial balance)
function updateView(filter) {
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    window.location.href = url.toString();
}

// Back to Top functionality
document.addEventListener('DOMContentLoaded', function() {
    const backToTopButton = document.getElementById('backToTop');
    
    // Show/hide back to top button based on scroll position
    window.addEventListener('scroll', function() {
        if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 100) {
            backToTopButton.classList.add('show');
        } else {
            backToTopButton.classList.remove('show');
        }
    });
    
    // Scroll to top when button is clicked
    backToTopButton.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    // Account Trends Line Chart
    <?php if (!empty($monthlyData)): ?>
    const accountTrendsOptions = {
        series: [
            <?php foreach ($specialAccounts as $account): ?>
            <?php if (isset($monthlyData[$account])): ?>
            {
                name: '<?= $account ?>',
                data: [
                    <?php 
                    $months = array_keys($monthlyData[$account]);
                    foreach ($months as $month) {
                        echo round($monthlyData[$account][$month]['net'], 2) . ',';
                    }
                    ?>
                ]
            },
            <?php endif; ?>
            <?php endforeach; ?>
        ],
        chart: {
            type: 'line',
            height: 350,
            toolbar: { show: true }
        },
        stroke: {
            curve: 'smooth',
            width: 3
        },
        xaxis: {
            categories: [
                <?php 
                if (!empty($monthlyData)) {
                    $firstAccount = reset($monthlyData);
                    $months = array_keys($firstAccount);
                    foreach ($months as $month) {
                        echo "'" . $month . "',";
                    }
                }
                ?>
            ]
        },
        yaxis: {
            labels: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        },
        colors: ['#4682B4', '#5a96cf', '#FF6B6B', '#FFA07A', '#4ECDC4', '#45B7D1', '#F7A35C', '#8D6E63'],
        tooltip: {
            y: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        }
    };
    new ApexCharts(document.querySelector("#accountTrendsChart"), accountTrendsOptions).render();
    <?php endif; ?>

    // Debit vs Credit Bar Chart
    const debitCreditOptions = {
        series: [
            {
                name: 'Debit',
                data: [
                    <?php foreach ($specialAccounts as $account): ?>
                    <?= isset($accountTotals[$account]) ? round($accountTotals[$account]['debit'], 2) . ',' : '0,' ?>
                    <?php endforeach; ?>
                ]
            },
            {
                name: 'Credit',
                data: [
                    <?php foreach ($specialAccounts as $account): ?>
                    <?= isset($accountTotals[$account]) ? round($accountTotals[$account]['credit'], 2) . ',' : '0,' ?>
                    <?php endforeach; ?>
                ]
            }
        ],
        chart: {
            type: 'bar',
            height: 350,
            stacked: true,
            toolbar: { show: true }
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%',
            },
        },
        xaxis: {
            categories: [
                <?php foreach ($specialAccounts as $account): ?>
                '<?= $account ?>',
                <?php endforeach; ?>
            ]
        },
        yaxis: {
            labels: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        },
        colors: ['#4682B4', '#FF6B6B'],
        tooltip: {
            y: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        }
    };
    new ApexCharts(document.querySelector("#debitCreditChart"), debitCreditOptions).render();

    // Stacked Area Chart
    <?php if (!empty($monthlyData)): ?>
    const stackedAreaOptions = {
        series: [
            <?php foreach ($specialAccounts as $account): ?>
            <?php if (isset($monthlyData[$account])): ?>
            {
                name: '<?= $account ?>',
                data: [
                    <?php 
                    $months = array_keys($monthlyData[$account]);
                    foreach ($months as $month) {
                        echo round($monthlyData[$account][$month]['net'], 2) . ',';
                    }
                    ?>
                ]
            },
            <?php endif; ?>
            <?php endforeach; ?>
        ],
        chart: {
            type: 'area',
            height: 350,
            stacked: true,
            toolbar: { show: true }
        },
        colors: ['#4682B4', '#5a96cf', '#FF6B6B', '#FFA07A', '#4ECDC4', '#45B7D1', '#F7A35C', '#8D6E63'],
        xaxis: {
            categories: [
                <?php 
                if (!empty($monthlyData)) {
                    $firstAccount = reset($monthlyData);
                    $months = array_keys($firstAccount);
                    foreach ($months as $month) {
                        echo "'" . $month . "',";
                    }
                }
                ?>
            ]
        },
        yaxis: {
            labels: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        },
        tooltip: {
            y: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        }
    };
    new ApexCharts(document.querySelector("#stackedAreaChart"), stackedAreaOptions).render();
    <?php endif; ?>
});

// AJAX form submission for forecast
$(document).ready(function() {
    $('#forecastForm').on('submit', function(e) {
        e.preventDefault();
        
        // Show loading indicator
        $('#forecastResults').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Generating forecast...</p></div>');
        
        // Serialize form data
        var formData = $(this).serialize();
        
        // Send AJAX request
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: formData,
            success: function(response) {
                // Update forecast results section
                $('#forecastResults').html(response);
            },
            error: function() {
                $('#forecastResults').html('<div class="alert alert-danger">Error generating forecast. Please try again.</div>');
            }
        });
    });
});
</script>
</body>
</html>
