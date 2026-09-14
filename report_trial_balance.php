<?php
session_start();
require 'db_connection.php';

// Check if this is being loaded in a modal
$isModal = isset($_GET['modal']) && $_GET['modal'] == 1;

if (isset($_GET['admin']) && $_GET['admin'] == 1 && isset($_GET['user_id'])) {
    require 'db_connection.php';
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

// Check if this is an AJAX request
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Handle AJAX request parameters
if ($is_ajax) {
    $forecast_period = $_GET['forecast'] ?? null;
    $apply_recommendations = isset($_GET['apply_recs']);
}

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

try {
    $stmt = $pdo->prepare("SELECT a.name, a.type,
            SUM(CASE WHEN t.entry_type = 'debit' AND t.date BETWEEN ? AND ? THEN t.amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN t.entry_type = 'credit' AND t.date BETWEEN ? AND ? THEN t.amount ELSE 0 END) as total_credit
        FROM `$accountsTable` a
        LEFT JOIN `$transactionsTable` t ON a.name = t.account_name
        GROUP BY a.name, a.type");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching trial balance data: " . $e->getMessage());
}

// Calculate totals by account type for charts
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

// Prepare data for Pareto chart (top accounts by absolute balance)
$accountBalances = [];
foreach ($accounts as $account) {
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

// Function to calculate forecast
function calculateForecast($current, $period) {
    $forecast = $current;
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

// Generate forecasted data if requested
$forecasted_accounts = null;
if ($forecast_period) {
    $forecasted_accounts = [];
    
    // Apply growth to all accounts
    foreach ($accounts as $account) {
        $forecasted_account = $account;
        $forecasted_account['total_debit'] = calculateForecast($account['total_debit'], $forecast_period);
        $forecasted_account['total_credit'] = calculateForecast($account['total_credit'], $forecast_period);
        $forecasted_accounts[] = $forecasted_account;
    }
    
    // Calculate forecasted totals
    $fc_total_debit = 0;
    $fc_total_credit = 0;
    foreach ($forecasted_accounts as $account) {
        $fc_total_debit += $account['total_debit'];
        $fc_total_credit += $account['total_credit'];
    }
}

// Generate recommendations using AI integration (simplified for trial balance)
$debit_credit_ratio = $total_debit > 0 ? ($total_credit / $total_debit) : 0;
$unbalanced_accounts = array_filter($accounts, function($acc) {
    return abs($acc['total_debit'] - $acc['total_credit']) > ($acc['total_debit'] + $acc['total_credit']) * 0.1;
});

$recommendations = [
    'balance' => [
        'title' => 'Trial Balance Accuracy',
        'value' => '₱ ' . number_format(abs($total_debit - $total_credit), 2),
        'status' => (abs($total_debit - $total_credit) < 1) ? 'Perfectly balanced' : 'Out of balance',
        'action' => (abs($total_debit - $total_credit) >= 1) ? 
            '1. Review journal entries for accuracy<br>
             2. Check for missing transactions<br>
             3. Verify account classifications' : 
            'Trial balance is accurately balanced'
    ],
    'accounts' => [
        'title' => 'Account Analysis',
        'value' => count($unbalanced_accounts) . ' accounts',
        'status' => (count($unbalanced_accounts) > 0) ? 'Potential issues detected' : 'All accounts appear normal',
        'action' => (count($unbalanced_accounts) > 0) ? 
            '1. Review unbalanced accounts for errors<br>
             2. Verify transaction amounts<br>
             3. Check for duplicate entries' : 
            'All accounts are properly balanced'
    ],
    'activity' => [
        'title' => 'Account Activity',
        'value' => count(array_filter($accounts, function($acc) { 
            return $acc['total_debit'] == 0 && $acc['total_credit'] == 0; 
        })) . ' inactive',
        'status' => 'Activity level normal',
        'action' => '1. Monitor inactive accounts for needed activity<br>
                     2. Consider consolidating rarely used accounts<br>
                     3. Review account structure for efficiency'
    ]
];

// Generate recommended position data (optimized forecast)
function calculateRecommendedForecast($current, $period, $type) {
    $base_growth = 0.05; // 5% base growth
    
    // Adjust growth based on period and account type
    $period_multiplier = 1;
    switch($period) {
        case 'monthly':
            $period_multiplier = 1;
            break;
        case 'quarterly':
            $period_multiplier = 3;
            break;
        case 'biannually':
            $period_multiplier = 6;
            break;
        case 'annually':
            $period_multiplier = 12;
            break;
    }
    
    // Adjust growth based on account type for optimization
    $type_multiplier = 1.0;
    switch($type) {
        case 'Asset':
            $type_multiplier = 1.08; // 8% higher growth for assets
            break;
        case 'Liability':
            $type_multiplier = 0.95; // 5% lower growth for liabilities
            break;
        case 'Revenue':
            $type_multiplier = 1.10; // 10% higher growth for revenue
            break;
        case 'Expense':
            $type_multiplier = 0.92; // 8% lower growth for expenses
            break;
        case 'Equity':
            $type_multiplier = 1.05; // 5% higher growth for equity
            break;
    }
    
    $optimized_growth = $base_growth * $period_multiplier * $type_multiplier;
    
    return $current * (1 + $optimized_growth);
}

// Generate recommended position if forecast period is set
$recommended_accounts = null;
if ($forecast_period) {
    $recommended_accounts = [];
    
    // Apply optimized growth to all accounts
    foreach ($accounts as $account) {
        $recommended_account = $account;
        $recommended_account['total_debit'] = calculateRecommendedForecast($account['total_debit'], $forecast_period, $account['type']);
        $recommended_account['total_credit'] = calculateRecommendedForecast($account['total_credit'], $forecast_period, $account['type']);
        $recommended_accounts[] = $recommended_account;
    }
    
    // Calculate recommended totals
    $rec_total_debit = 0;
    $rec_total_credit = 0;
    foreach ($recommended_accounts as $account) {
        $rec_total_debit += $account['total_debit'];
        $rec_total_credit += $account['total_credit'];
    }
}

function fmt($amt) {
    if ($amt == 0 || $amt == null) return '';
    return '&#8369; ' . ($amt < 0 ? '(' . number_format(abs($amt), 2) . ')' : number_format($amt, 2));
}

// If this is a modal request, only output the content without full HTML
if ($isModal) {
    ob_start();
    ?>
    <!-- Header with Logo and Accounting Services -->
    <div class="report-header d-flex justify-content-between align-items-center p-3 border-bottom">
        <div class="logo">
            <img src="images/NXT.png" alt="EBTGL Accounting Services Logo" style="height: 50px;">
        </div>
        <div class="accounting-service text-end">
            <h5 class="mb-0">EBTGL Accounting Services</h5>
        </div>
    </div>

    <div class="p-3">
        <p class="report-subtitle text-center">As of <?= date('F d, Y') ?> (<?= ucfirst($filter) ?> View)</p>

        <table class="table">
            <thead>
                <tr>
                    <th>Account Name</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?= htmlspecialchars($account['name']) ?></td>
                        <td class="text-end"><?= fmt($account['total_debit']) ?></td>
                        <td class="text-end"><?= fmt($account['total_credit']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="text-end"><strong>Total</strong></td>
                    <td class="text-end grand-total"><?= fmt($total_debit) ?></td>
                    <td class="text-end grand-total"><?= fmt($total_credit) ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-5 text-center text-muted">
            <p>Generated on <?= date('F j, Y \a\t g:i A') ?></p>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    echo $content;
    exit;
    }

// If this is an AJAX request, return JSON data
if ($is_ajax && $forecast_period) {
    header('Content-Type: application/json');
    
    // Generate recommendations for forecasted data if apply_recs is set
    $fc_recommendations = null;
    $rec_recommendations = null;
    
    if ($apply_recommendations && $forecasted_accounts) {
        $fc_total_debit = 0;
        $fc_total_credit = 0;
        foreach ($forecasted_accounts as $account) {
            $fc_total_debit += $account['total_debit'];
            $fc_total_credit += $account['total_credit'];
        }
        
        $fc_unbalanced_accounts = array_filter($forecasted_accounts, function($acc) {
            return abs($acc['total_debit'] - $acc['total_credit']) > ($acc['total_debit'] + $acc['total_credit']) * 0.1;
        });
        
        $fc_recommendations = [
            'balance' => [
                'title' => 'Trial Balance Accuracy',
                'value' => '₱ ' . number_format(abs($fc_total_debit - $fc_total_credit), 2),
                'status' => (abs($fc_total_debit - $fc_total_credit) < 1) ? 'Perfectly balanced' : 'Out of balance',
                'action' => (abs($fc_total_debit - $fc_total_credit) >= 1) ? 
                    '1. Review journal entries for accuracy<br>
                     2. Check for missing transactions<br>
                     3. Verify account classifications' : 
                    'Trial balance is accurately balanced'
            ],
            'accounts' => [
                'title' => 'Account Analysis',
                'value' => count($fc_unbalanced_accounts) . ' accounts',
                'status' => (count($fc_unbalanced_accounts) > 0) ? 'Potential issues detected' : 'All accounts appear normal',
                'action' => (count($fc_unbalanced_accounts) > 0) ? 
                    '1. Review unbalanced accounts for errors<br>
                     2. Verify transaction amounts<br>
                     3. Check for duplicate entries' : 
                    'All accounts are properly balanced'
            ],
            'activity' => [
                'title' => 'Account Activity',
                'value' => count(array_filter($forecasted_accounts, function($acc) { 
                    return $acc['total_debit'] == 0 && $acc['total_credit'] == 0; 
                })) . ' inactive',
                'status' => 'Activity level normal',
                'action' => '1. Monitor inactive accounts for needed activity<br>
                             2. Consider consolidating rarely used accounts<br>
                             3. Review account structure for efficiency'
            ]
        ];
        
        // Generate recommendations for recommended position
        if ($recommended_accounts) {
            $rec_total_debit = 0;
            $rec_total_credit = 0;
            foreach ($recommended_accounts as $account) {
                $rec_total_debit += $account['total_debit'];
                $rec_total_credit += $account['total_credit'];
            }
            
            $rec_unbalanced_accounts = array_filter($recommended_accounts, function($acc) {
                return abs($acc['total_debit'] - $acc['total_credit']) > ($acc['total_debit'] + $acc['total_credit']) * 0.1;
            });
            
            $rec_recommendations = [
                'balance' => [
                    'title' => 'Optimized Trial Balance',
                    'value' => '₱ ' . number_format(abs($rec_total_debit - $rec_total_credit), 2),
                    'status' => (abs($rec_total_debit - $rec_total_credit) < 1) ? 'Perfectly balanced' : 'Out of balance',
                    'action' => '1. Optimized growth applied to account types<br>
                                 2. Higher growth for revenue and assets<br>
                                 3. Controlled growth for expenses and liabilities'
                ],
                'performance' => [
                    'title' => 'Performance Optimization',
                    'value' => 'Enhanced growth strategy',
                    'status' => 'Optimized for financial health',
                    'action' => '1. Assets growth: +8%<br>
                                 2. Revenue growth: +10%<br>
                                 3. Expenses control: -8%<br>
                                 4. Liabilities control: -5%'
                ],
                'efficiency' => [
                    'title' => 'Financial Efficiency',
                    'value' => 'Improved balance structure',
                    'status' => 'Enhanced financial position',
                    'action' => '1. Better asset utilization<br>
                                 2. Improved revenue streams<br>
                                 3. Controlled expense growth<br>
                                 4. Optimized liability management'
                ]
            ];
        }
    }
    
    $response = [
        'current' => [
            'total_debit' => $total_debit,
            'total_credit' => $total_credit,
            'difference' => $total_debit - $total_credit
        ],
        'forecasted' => [
            'total_debit' => $fc_total_debit,
            'total_credit' => $fc_total_credit,
            'difference' => $fc_total_debit - $fc_total_credit
        ],
        'recommended' => [
            'total_debit' => $rec_total_debit,
            'total_credit' => $rec_total_credit,
            'difference' => $rec_total_debit - $rec_total_credit
        ],
        'recommendations' => $fc_recommendations,
        'rec_recommendations' => $rec_recommendations
    ];
    
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Trial Balance</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
            --success-green: #28a745;
            --light-green: #d4edda;
            --dark-green: #155724;
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
        
        .table tfoot td {
            padding: 12px 15px;
            font-weight: 700;
            background-color: rgba(70, 130, 180, 0.1);
        }
        
        .table tfoot .grand-total {
            font-weight: 800;
            background-color: rgba(42, 90, 128, 0.15);
            font-size: 16px;
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
        
        /* Forecast Section Styles */
        .forecast-section {
            background-color: #f8f9fa;
            border-left: 4px solid var(--primary-blue);
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
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
        }
        
        .recommended-scenario-card {
            border: 3px solid #1a5f9c; /* Thicker blue border */
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 15px rgba(26, 95, 156, 0.2);
            background: linear-gradient(135deg, #f8f9fa, #e6f0f8);
        }
        
        .scenario-header {
            color: var(--dark-blue);
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .recommended-scenario-header {
            color: #1a5f9c; /* Changed to blue */
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
        
        .optimized-badge {
            background: linear-gradient(135deg, #1a5f9c, #4682B4); /* Changed to blue */
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: 10px;
        }

        .ai-recommendations-badge {
            background: linear-gradient(135deg, #28a745, #20c997);
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
        
        .optimized-marker {
            background: var(--primary-blue); /* Changed to blue */
            color: white;
            border-color: var(--primary-blue);
        }
        
        /* Forecast Filter */
        .forecast-filter {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }
        
        /* Recommendation Cards */
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
            color: var(--dark-blue);
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .rec-value {
            background-color: var(--primary-blue);
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
        
        .status-optimized {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .rec-action {
            background-color: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 3px solid #4ECDC4;
        }
        
        /* AI Section - Normal styling */
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
        
        /* Chart containers */
        .chart-container {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        
        .section-title {
            color: var(--dark-blue);
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 10px;
            margin-bottom: 20px;
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
        
        .ai-badge {
            background: linear-gradient(135deg, #6e8efb, #a777e3);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 8px;
        }
        
        /* NEW: Chart Interpretation Section Styles */
        .chart-interpretation {
            background: linear-gradient(to right, #f8f9fa, #e9f7fe);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid #c4d8eb;
        }
        
        .interpretation-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        
        .interpretation-box h6 {
            color: var(--dark-blue);
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .interpretation-box p {
            color: #555;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        /* NEW: Document/Receipt Style for Printing */
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
            .forecast-filter, .ai-section, .chart-section, .chart-interpretation {
                display: none !important;
            }
        }

        /* Modal Banner Styles */
        .modal-banner {
            background: linear-gradient(135deg, #2c3e50, #4a6583);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
        }

        /* NEW: Button container styles for side-by-side buttons */
        .button-container {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* NEW: Back to top button styles */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-blue);
            color: white;
            border: none;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .back-to-top:hover {
            background: var(--dark-blue);
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4);
        }

        .back-to-top.show {
            display: flex;
        }
    </style>
</head>
<body>
<!-- Document/Receipt Style (Hidden by default, shown when printing) -->
<div class="document-style">
    <div class="document-header">
        <div class="document-title">TRIAL BALANCE</div>
        <div class="document-company"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-subtitle">As of <?= date('F d, Y') ?></div>
        <div class="document-date">(<?= ucfirst($filter) ?> View)</div>
    </div>
    
    <!-- Trial Balance Table -->
    <div class="document-section">
        <div class="document-section-title">ACCOUNTS</div>
        
        <!-- Table Header -->
        <div class="document-row" style="font-weight: bold;">
            <div>Account Name</div>
            <div>Debit</div>
            <div>Credit</div>
        </div>
        
        <!-- Account Rows -->
        <?php foreach ($accounts as $account): ?>
            <div class="document-row">
                <div><?= htmlspecialchars($account['name']) ?></div>
                <div><?= fmt($account['total_debit']) ?></div>
                <div><?= fmt($account['total_credit']) ?></div>
            </div>
        <?php endforeach; ?>
        
        <!-- Totals -->
        <div class="document-row total">
            <div>Total</div>
            <div><?= fmt($total_debit) ?></div>
            <div><?= fmt($total_credit) ?></div>
        </div>
        
        <div class="document-row grand-total">
            <div>Difference</div>
            <div><?= fmt($total_debit - $total_credit) ?></div>
        </div>
    </div>

    <!-- Financial Visualization Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Trial Balance Visualization</h4>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-bar"></i>
                <h4>Debit vs Credit by Account Type</h4>
                <p>Bar chart showing debit and credit totals for each account type</p>
                <p><strong>Assets Debit:</strong> <?= fmt($typeTotals['Asset']['debit']) ?></p>
                <p><strong>Assets Credit:</strong> <?= fmt($typeTotals['Asset']['credit']) ?></p>
                <p><strong>Liabilities Debit:</strong> <?= fmt($typeTotals['Liability']['debit']) ?></p>
                <p><strong>Liabilities Credit:</strong> <?= fmt($typeTotals['Liability']['credit']) ?></p>
                <p><strong>Equity Debit:</strong> <?= fmt($typeTotals['Equity']['debit']) ?></p>
                <p><strong>Equity Credit:</strong> <?= fmt($typeTotals['Equity']['credit']) ?></p>
                <p><strong>Revenue Debit:</strong> <?= fmt($typeTotals['Revenue']['debit']) ?></p>
                <p><strong>Revenue Credit:</strong> <?= fmt($typeTotals['Revenue']['credit']) ?></p>
                <p><strong>Expenses Debit:</strong> <?= fmt($typeTotals['Expense']['debit']) ?></p>
                <p><strong>Expenses Credit:</strong> <?= fmt($typeTotals['Expense']['credit']) ?></p>
            </div>
        </div>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-line"></i>
                <h4>Pareto Chart of Account Balances</h4>
                <p>Chart showing the most significant accounts by balance</p>
                <p><strong>Total Debit:</strong> <?= fmt($total_debit) ?></p>
                <p><strong>Total Credit:</strong> <?= fmt($total_credit) ?></p>
                <p><strong>Balance Difference:</strong> <?= fmt($total_debit - $total_credit) ?></p>
            </div>
        </div>
    </div>

    <!-- Chart Interpretation Section for Print -->
    <div class="print-interpretation-section">
        <h4 class="print-interpretation-header">
            <i class="fas fa-analytics me-2"></i>Trial Balance Chart Interpretation
        </h4>
        
        <div class="print-interpretation-content">
            The trial balance charts provide valuable insights into your company's accounting records. The Debit vs Credit chart shows the distribution of amounts across different account types, while the Pareto chart highlights the most significant accounts that make up the majority of your trial balance totals.
        </div>
        
        <div class="print-interpretation-points">
            <div class="print-interpretation-point">
                <div class="print-point-title">Debit vs Credit Analysis</div>
                <p>This visualization helps verify that total debits equal total credits for each account type, which is a key principle of double-entry accounting. Any significant discrepancies may indicate data entry errors or classification issues.</p>
            </div>
            
            <div class="print-interpretation-point">
                <div class="print-point-title">Account Significance Analysis</div>
                <p>The Pareto chart identifies the accounts with the highest balances, allowing you to focus your review and audit efforts on the most material accounts that have the greatest impact on your financial statements.</p>
            </div>
        </div>
    </div>

    <!-- AI Recommendations Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Financial Health Recommendations</h4>
        
        <?php foreach ($recommendations as $key => $rec): ?>
            <div class="print-chart-container">
                <div class="print-interpretation-point">
                    <div class="print-point-title"><?= $rec['title'] ?></div>
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
        <p>This trial balance represents the account balances of <?= htmlspecialchars($company_name) ?> as of <?= date('F d, Y') ?>.</p>
        <p>Generated on <?= date('F j, Y \a\t g:i A') ?></p>
    </div>
    
    <div class="document-footer">
        <p>END OF REPORT</p>
    </div>
</div>

<!-- Regular Report Container (Hidden when printing) -->
<div class="report-container h-90">
    <div class="report-header text-center">
        <h3 class="report-title"><?= htmlspecialchars($company_name) ?></h3>
        <h3 class="report-title">Trial Balance</h3>
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

    <table class="table">
        <thead>
            <tr>
                <th>Account Name</th>
                <th class="text-end">Debit</th>
                <th class="text-end">Credit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td><?= htmlspecialchars($account['name']) ?></td>
                    <td class="text-end"><?= fmt($account['total_debit']) ?></td>
                    <td class="text-end"><?= fmt($account['total_credit']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="text-end"><strong>Total</strong></td>
                <td class="text-end grand-total"><?= fmt($total_debit) ?></td>
                <td class="text-end grand-total"><?= fmt($total_credit) ?></td>
            </tr>
        </tfoot>
    </table>
    
    <!-- Financial Visualization Section -->
    <div class="chart-section">
        <h4 class="section-title">Trial Balance Visualization</h4>
        
        <div class="row">
            <div class="col-md-6 chart-container" id="debitCreditChart"></div>
            <div class="col-md-6 chart-container" id="paretoChart"></div>
        </div>
    </div>
    
    <!-- Chart Interpretation Section -->
    <div class="chart-interpretation">
        <h5><i class="fas fa-lightbulb me-2"></i>Chart Interpretations</h5>
        <div class="row">
            <div class="col-md-6">
                <div class="interpretation-box">
                    <h6>Debit vs Credit by Account Type</h6>
                    <p>This chart shows the total debit and credit amounts for each account type (Assets, Liabilities, Equity, Revenue, and Expenses). It helps in verifying that total debits equal total credits for each account type, which is a key principle of double-entry accounting.</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="interpretation-box">
                    <h6>Pareto Chart of Account Balances</h6>
                    <p>This chart displays the accounts with the highest balances (in absolute value) and their cumulative percentage. It helps in identifying the most significant accounts that make up the majority of the trial balance, which is useful for focusing audit and analysis efforts.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Forecast Filter Section -->
    <div class="forecast-filter no-print">
        <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>Financial Forecast</h5>
        <form id="forecastForm" method="GET" class="row g-3 align-items-center">
            <?php if (isset($_GET['admin']) && isset($_GET['user_id'])): ?>
                <input type="hidden" name="admin" value="1">
                <input type="hidden" name="user_id" value="<?= $_GET['user_id'] ?>">
            <?php endif; ?>
            <input type="hidden" name="filter" value="<?= $filter ?>">
            
            <div class="col-md-5">
                <label class="form-label">Forecast Period:</label>
                <select name="forecast" class="form-select" id="forecastPeriod">
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
                <button type="button" class="btn btn-primary mt-3" id="forecastButton">
                    <i class="fas fa-calculator me-2"></i> Forecast
                </button>
            </div>
        </form>
    </div>

    <!-- Loading indicator -->
    <div class="loading" id="loadingIndicator">
        <div class="loading-spinner"></div>
        <p class="mt-2">Generating forecast...</p>
    </div>

    <!-- Forecast Results - Initially Blank -->
    <div id="forecastResults"></div>

    <!-- AI-Powered Recommendations Section - Normal styling, 2 columns -->
    <div class="ai-section" id="aiSection">
        <h4><i class="fas fa-robot me-2 text-primary"></i> AI-Powered Financial Health Recommendations</h4>
        <p class="text-muted">Our analysis of your trial balance provides these tailored recommendations:</p>
        
        <div class="row" id="aiRecommendations">
            <?php foreach ($recommendations as $key => $rec): ?>
                <?php
                $statusClass = '';
                if (strpos($rec['status'], 'Perfectly') !== false || strpos($rec['status'], 'normal') !== false) {
                    $statusClass = 'status-strong';
                } elseif (strpos($rec['status'], 'Out of balance') !== false || strpos($rec['status'], 'Potential issues') !== false) {
                    $statusClass = 'status-concerning';
                }
                ?>
                <div class="col-md-6 mb-4">
                    <div class="recommendation-card">
                        <div class="rec-title">
                            <?= $rec['title'] ?>
                            <span class="ai-badge">AI Analysis</span>
                        </div>
                        <?php if (!empty($rec['value'])): ?>
                            <div class="rec-value"><?= $rec['value'] ?></div>
                        <?php endif; ?>
                        <div class="rec-status <?= $statusClass ?>">
                            <?= $rec['status'] ?></div>
                        <div class="rec-action"><?= $rec['action'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mt-3 text-end">
            <small class="text-muted">Recommendations generated on <?= date('F j, Y \a\t g:i A') ?> using advanced financial analysis algorithms</small>
        </div>
    </div>
    
    <div class="mt-5 text-center text-muted">
        <p>Generated on <?= date('F j, Y \a\t g:i A') ?></p>
    </div>
</div>

<!-- Back to Top Button -->
<button class="back-to-top no-print" id="backToTop">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ApexCharts Library -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.3/dist/apexcharts.min.js"></script>

<script>
// Function to update view filter
function updateView(filter) {
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    window.location.href = url.toString();
}

// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Back to top button functionality
    const backToTopButton = document.getElementById('backToTop');
    
    // Show/hide back to top button when user reaches bottom
    window.addEventListener('scroll', function() {
        // Check if user has scrolled to the bottom of the page
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight;
        const clientHeight = document.documentElement.clientHeight;
        
        // Show button when user is near the bottom (within 100px)
        if (scrollTop + clientHeight >= scrollHeight - 100) {
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

    // Debit vs Credit by Account Type Chart
    const debitCreditOptions = {
        series: [
            {
                name: 'Debit',
                data: [
                    <?= $typeTotals['Asset']['debit'] ?>,
                    <?= $typeTotals['Liability']['debit'] ?>,
                    <?= $typeTotals['Equity']['debit'] ?>,
                    <?= $typeTotals['Revenue']['debit'] ?>,
                    <?= $typeTotals['Expense']['debit'] ?>
                ]
            },
            {
                name: 'Credit',
                data: [
                    <?= $typeTotals['Asset']['credit'] ?>,
                    <?= $typeTotals['Liability']['credit'] ?>,
                    <?= $typeTotals['Equity']['credit'] ?>,
                    <?= $typeTotals['Revenue']['credit'] ?>,
                    <?= $typeTotals['Expense']['credit'] ?>
                ]
            }
        ],
        chart: {
            type: 'bar',
            height: 300,
            stacked: false,
            toolbar: {
                show: true,
                tools: {
                    download: true
                }
            }
        },
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '55%',
                endingShape: 'rounded'
            }
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            show: true,
            width: 2,
            colors: ['transparent']
        },
        xaxis: {
            categories: ['Assets', 'Liabilities', 'Equity', 'Revenue', 'Expenses'],
            labels: {
                style: {
                    fontSize: '14px'
                }
            }
        },
        yaxis: {
            title: {
                text: 'Amount (₱)'
            },
            labels: {
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                }
            }
        },
        fill: {
            opacity: 1
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

    const debitCreditChart = new ApexCharts(document.querySelector("#debitCreditChart"), debitCreditOptions);
    debitCreditChart.render();

    // Pareto Chart (Top Accounts by Balance)
    const topAccounts = <?= json_encode(array_slice($paretoData, 0, 10)) ?>;
    
    const paretoOptions = {
        series: [
            {
                name: 'Account Balance',
                type: 'column',
                data: topAccounts.map(acc => acc.balance)
            },
            {
                name: 'Cumulative Percentage',
                type: 'line',
                data: topAccounts.map(acc => acc.cumulative)
            }
        ],
        chart: {
            height: 300,
            type: 'line',
            toolbar: {
                show: true,
                tools: {
                    download: true
                }
            }
        },
        stroke: {
            width: [0, 4]
        },
        dataLabels: {
            enabled: true,
            enabledOnSeries: [1],
            formatter: function(val) {
                return val.toFixed(1) + '%';
            }
        },
        labels: topAccounts.map(acc => acc.name),
        xaxis: {
            type: 'category',
            labels: {
                show: true,
                rotate: -45,
                rotateAlways: true,
                hideOverlappingLabels: false,
                trim: true,
                style: {
                    fontSize: '10px'
                }
            }
        },
        yaxis: [
            {
                title: {
                    text: 'Account Balance',
                },
                labels: {
                    formatter: function(val) {
                        return '₱' + val.toLocaleString();
                    }
                }
            },
            {
                opposite: true,
                title: {
                    text: 'Percentage'
                },
                max: 100,
                labels: {
                    formatter: function(val) {
                        return val.toFixed(0) + '%';
                    }
                }
            }
        ],
        colors: ['#4682B4', '#FF6B6B'],
        tooltip: {
            shared: true,
            intersect: false,
            y: {
                formatter: function(val, { seriesIndex }) {
                    return seriesIndex === 0 ? 
                        '₱' + val.toLocaleString() : 
                        val.toFixed(1) + '%';
                }
            }
        }
    };

    const paretoChart = new ApexCharts(document.querySelector("#paretoChart"), paretoOptions);
    paretoChart.render();
    
    // Handle forecast button click with AJAX
    const forecastButton = document.getElementById('forecastButton');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const forecastResults = document.getElementById('forecastResults');
    const aiSection = document.getElementById('aiSection');
    const aiRecommendations = document.getElementById('aiRecommendations');
    
    if (forecastButton) {
        forecastButton.addEventListener('click', function() {
            const forecastPeriod = document.getElementById('forecastPeriod');
            const applyRecs = document.getElementById('applyRecs');
            
            if (!forecastPeriod.value) {
                alert('Please select a forecast period');
                return;
            }
            
            // Show loading indicator
            loadingIndicator.style.display = 'block';
            forecastButton.disabled = true;
            
            // Prepare parameters for GET request
            const params = new URLSearchParams();
            params.append('forecast', forecastPeriod.value);
            params.append('apply_recs', applyRecs.checked ? '1' : '0');
            params.append('filter', '<?= $filter ?>');
            
            <?php if (isset($_GET['admin']) && isset($_GET['user_id'])): ?>
            params.append('admin', '1');
            params.append('user_id', '<?= $_GET['user_id'] ?>');
            <?php endif; ?>
            
            // Add X-Requested-With header to identify as AJAX
            const headers = new Headers();
            headers.append('X-Requested-With', 'XMLHttpRequest');
            
            // Send AJAX request with GET method
            fetch('?' + params.toString(), {
                method: 'GET',
                headers: headers
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // Hide loading indicator
                loadingIndicator.style.display = 'none';
                forecastButton.disabled = false;
                
                // Check for error response
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                
                // Update forecast results
                updateForecastResults(data, forecastPeriod.value, applyRecs.checked);
                
                // Update AI recommendations if apply_recs is checked
                if (applyRecs.checked && data.rec_recommendations) {
                    updateAIRecommendations(data.rec_recommendations);
                } else if (applyRecs.checked && data.recommendations) {
                    updateAIRecommendations(data.recommendations);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                loadingIndicator.style.display = 'none';
                forecastButton.disabled = false;
                alert('Error generating forecast. Please try again. ' + error.message);
            });
        });
    }
    
    // Function to update forecast results with AJAX data
    function updateForecastResults(data, period, applyRecs) {
        // Format currency function
        const formatCurrency = (value) => {
            if (value == 0 || value == null) return '';
            const absValue = Math.abs(value);
            const formatted = absValue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return '₱ ' + (value < 0 ? '(' + formatted + ')' : formatted);
        };
        
        // Create HTML for forecast results
        let html = `
        <div class="forecast-section">
            <h4 class="forecast-header">
                <i class="fas fa-crystal-ball"></i> Financial Forecast
                <span class="badge bg-primary">${period.charAt(0).toUpperCase() + period.slice(1)} Projection</span>
                ${applyRecs ? '<span class="badge bg-success ms-2">With AI Recommendations</span>' : ''}
            </h4>
            
            <div class="timeline-container">
                <div class="timeline-point">
                    <div class="timeline-marker">1</div>
                    <div class="timeline-label">Current</div>
                </div>
                <div class="timeline-point">
                    <div class="timeline-marker active-marker">2</div>
                    <div class="timeline-label">Projected</div>
                </div>`;
                
        // Add recommended position timeline point if applyRecs is true
        if (applyRecs && data.recommended) {
            html += `
                <div class="timeline-point">
                    <div class="timeline-marker active-marker">3</div>
                    <div class="timeline-label">Recommended</div>
                </div>`;
        }
                
        html += `
            </div>
            
            <div class="row">
                <!-- Current Financial Position -->
                <div class="col-md-${applyRecs && data.recommended ? '4' : '6'}">
                    <div class="scenario-card">
                        <div class="scenario-header">
                            <i class="fas fa-file-invoice-dollar"></i> Current Position
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Total Debit
                                <span data-current="total_debit">${formatCurrency(data.current.total_debit)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Total Credit
                                <span data-current="total_credit">${formatCurrency(data.current.total_credit)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Difference
                                <span data-current="difference">${formatCurrency(data.current.difference)}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Projected Financial Position -->
                <div class="col-md-${applyRecs && data.recommended ? '4' : '6'}">
                    <div class="scenario-card">
                        <div class="scenario-header">
                            <i class="fas fa-chart-line"></i> Projected Position
                            <span class="recommendation-badge">Forecast</span>
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Total Debit
                                <span data-forecasted="total_debit">${formatCurrency(data.forecasted.total_debit)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Total Credit
                                <span data-forecasted="total_credit">${formatCurrency(data.forecasted.total_credit)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Difference
                                <span data-forecasted="difference">${formatCurrency(data.forecasted.difference)}</span>
                            </li>
                        </ul>
                        <div class="mt-3">
                            <p class="mb-1"><strong>Projection Basis:</strong></p>
                            <p class="small">Based on current growth trends (5% base + period adjustment)</p>
                        </div>
                    </div>
                </div>`;
                
        // Add Recommended Position if applyRecs is true and data exists - HIGHLIGHTED IN BLUE
        if (applyRecs && data.recommended) {
            html += `
                <!-- Recommended Financial Position - HIGHLIGHTED IN BLUE -->
                <div class="col-md-4">
                    <div class="recommended-scenario-card">
                        <div class="recommended-scenario-header">
                            <i class="fas fa-star"></i> Recommended Position
                            <span class="optimized-badge">Optimized</span>
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Total Debit
                                <span data-recommended="total_debit">${formatCurrency(data.recommended.total_debit)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Total Credit
                                <span data-recommended="total_credit">${formatCurrency(data.recommended.total_credit)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Difference
                                <span data-recommended="difference">${formatCurrency(data.recommended.difference)}</span>
                            </li>
                        </ul>
                        <div class="mt-3">
                            <p class="mb-1"><strong>Optimization Strategy:</strong></p>
                            <p class="small">Enhanced growth for revenue/assets, controlled growth for expenses/liabilities</p>
                        </div>
                    </div>
                </div>`;
        }
                
        html += `
            </div>
        </div>`;
        
        // Update the forecast results section
        forecastResults.innerHTML = html;
    }
    
    // Function to update AI recommendations
    function updateAIRecommendations(recommendations) {
        let html = '';
        
        for (const key in recommendations) {
            const rec = recommendations[key];
            const statusClass = rec.status.includes('strong') || rec.status.includes('Perfect') || rec.status.includes('Optimized') ? 
                              (rec.status.includes('Optimized') ? 'status-optimized' : 'status-strong') : 
                              (rec.status.includes('concerning') || rec.status.includes('Out of balance') ? 'status-concerning' : '');
            
            html += `
            <div class="col-md-6 mb-4">
                <div class="recommendation-card">
                    <div class="rec-title">
                        ${rec.title}
                        <span class="ai-badge">AI Analysis</span>
                    </div>
                    ${rec.value ? `<div class="rec-value">${rec.value}</div>` : ''}
                    <div class="rec-status ${statusClass}">
                        ${rec.status}
                    </div>
                    <div class="rec-action">${rec.action}</div>
                </div>
            </div>`;
        }
        
        aiRecommendations.innerHTML = html;
    }
    
    // Hide loading indicator after page load (in case it was left visible)
    loadingIndicator.style.display = 'none';
    if (forecastButton) forecastButton.disabled = false;
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>