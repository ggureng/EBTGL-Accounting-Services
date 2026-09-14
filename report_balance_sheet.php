<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require 'db_connection.php';

// Support admin access
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

// Main balance sheet query
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
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [
    'Current Asset' => [],
    'Non-Current Asset' => [],
    'Current Liability' => [],
    'Non-Current Liability' => [],
    'Equity' => []
];

foreach ($accounts as $acc) {
    if (isset($grouped[$acc['category']])) {
        $grouped[$acc['category']][] = $acc;
    }
}

// Helper functions
function fmt($val) {
    if ($val == 0 || $val == null) return '';
    return '&#8369; ' . ($val < 0 ? '(' . number_format(abs($val), 2) . ')' : number_format($val, 2));
}

function sectionTotal($section) {
    $total = 0;
    if (is_array($section)) {
        foreach ($section as $acc) $total += $acc['balance'];
    }
    return $total;
}

// Function to calculate forecast
function calculateForecast($current, $period) {
    if ($current == 0) return 0;
    
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

// Function to apply recommendations
function applyRecommendations($grouped) {
    $adjusted = $grouped;
    
    // Working Capital Optimization
    $current_assets_total = sectionTotal($adjusted['Current Asset'] ?? []);
    $current_liabilities_total = sectionTotal($adjusted['Current Liability'] ?? []);
    
    $current_ratio = $current_liabilities_total != 0 ? $current_assets_total / $current_liabilities_total : 0;
    $target_ratio = 1.8;
    
    if ($current_ratio < $target_ratio) {
        $needed = $current_liabilities_total * $target_ratio - $current_assets_total;
        if ($needed > 0) {
            $cashAccountFound = false;
            $receivableAccountFound = false;
            
            if (isset($adjusted['Current Asset'])) {
                foreach ($adjusted['Current Asset'] as &$asset) {
                    if (!$cashAccountFound && (stripos($asset['name'], 'cash') !== false || stripos($asset['name'], 'bank') !== false)) {
                        $asset['balance'] += $needed * 0.7;
                        $cashAccountFound = true;
                    }
                    if (!$receivableAccountFound && stripos($asset['name'], 'receivable') !== false) {
                        $asset['balance'] -= $needed * 0.3;
                        $receivableAccountFound = true;
                    }
                }
                
                // If no cash account found, add to first current asset
                if (!$cashAccountFound && count($adjusted['Current Asset']) > 0) {
                    $adjusted['Current Asset'][0]['balance'] += $needed * 0.7;
                }
            }
            
            // Add to retained earnings
            $found = false;
            if (isset($adjusted['Equity'])) {
                foreach ($adjusted['Equity'] as &$equity) {
                    if (stripos($equity['name'], 'retained') !== false) {
                        $equity['balance'] += $needed;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $adjusted['Equity'][] = ['name' => 'Retained Earnings (Optimized)', 'balance' => $needed];
            }
        }
    }
    
    // Debt Reduction Strategy
    $current_liabilities_total = sectionTotal($adjusted['Current Liability'] ?? []);
    $non_current_liabilities_total = sectionTotal($adjusted['Non-Current Liability'] ?? []);
    $equity_total = sectionTotal($adjusted['Equity'] ?? []);
    
    $debt_ratio = $equity_total != 0 ? ($current_liabilities_total + $non_current_liabilities_total) / $equity_total : 0;
    if ($debt_ratio > 0.6) {
        $reduction = $non_current_liabilities_total * 0.15;
        $loanAccountFound = false;
        
        if (isset($adjusted['Non-Current Liability'])) {
            foreach ($adjusted['Non-Current Liability'] as &$liability) {
                if (!$loanAccountFound && stripos($liability['name'], 'loan') !== false) {
                    $liability['balance'] -= $reduction;
                    $loanAccountFound = true;
                    break;
                }
            }
            
            // If no loan account found, reduce first non-current liability
            if (!$loanAccountFound && count($adjusted['Non-Current Liability']) > 0) {
                $adjusted['Non-Current Liability'][0]['balance'] -= $reduction;
            }
        }
        
        // Pay from cash reserves
        $cashAccountFound = false;
        if (isset($adjusted['Current Asset'])) {
            foreach ($adjusted['Current Asset'] as &$asset) {
                if (!$cashAccountFound && (stripos($asset['name'], 'cash') !== false || stripos($asset['name'], 'bank') !== false)) {
                    $asset['balance'] -= $reduction * 0.7;
                    $cashAccountFound = true;
                    break;
                }
            }
        }
        
        // Add savings to equity
        $interest_savings = $reduction * 0.05; // Estimated interest savings
        $found = false;
        if (isset($adjusted['Equity'])) {
            foreach ($adjusted['Equity'] as &$equity) {
                if (stripos($equity['name'], 'retained') !== false) {
                    $equity['balance'] += $interest_savings;
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            $adjusted['Equity'][] = ['name' => 'Retained Earnings (Optimized)', 'balance' => $interest_savings];
        }
    }
    
    // Asset Optimization
    $non_current_assets_total = sectionTotal($adjusted['Non-Current Asset'] ?? []);
    $current_assets_total = sectionTotal($adjusted['Current Asset'] ?? []);
    
    $fixed_asset_ratio = $current_assets_total != 0 ? $non_current_assets_total / $current_assets_total : 0;
    if ($fixed_asset_ratio > 2.5) {
        $sale_value = $non_current_assets_total * 0.12;
        $fixedAssetFound = false;
        
        if (isset($adjusted['Non-Current Asset'])) {
            foreach ($adjusted['Non-Current Asset'] as &$asset) {
                if (!$fixedAssetFound && (stripos($asset['name'], 'equipment') !== false || stripos($asset['name'], 'property') !== false)) {
                    $asset['balance'] -= $sale_value;
                    $fixedAssetFound = true;
                    break;
                }
            }
        }
        
        // Add proceeds to cash
        $cashAccountFound = false;
        if (isset($adjusted['Current Asset'])) {
            foreach ($adjusted['Current Asset'] as &$asset) {
                if (!$cashAccountFound && (stripos($asset['name'], 'cash') !== false || stripos($asset['name'], 'bank') !== false)) {
                    $asset['balance'] += $sale_value;
                    $cashAccountFound = true;
                    break;
                }
            }
        }
    }
    
    return $adjusted;
}

// Function to calculate enhanced forecast with consistent recommendation following
function calculateEnhancedForecast($current, $period) {
    if ($current == 0) return 0;
    
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
    
    // Apply enhanced growth for consistent recommendation following (25-50% higher than regular forecast)
    $enhanced_growth = $growth * 1.35; // 35% higher growth
    
    // Apply enhanced growth
    return $current * (1 + $enhanced_growth);
}

// Generate forecasted data if requested
$forecasted_grouped = null;
$rec_forecasted_grouped = null;
if ($forecast_period) {
    $forecasted_grouped = $grouped;
    
    // Apply growth to all accounts
    foreach ($forecasted_grouped as $category => $accounts) {
        foreach ($accounts as $key => $account) {
            $forecasted_grouped[$category][$key]['balance'] = calculateForecast($account['balance'], $forecast_period);
        }
    }
    
    // Create recommended forecast if requested
    if ($apply_recommendations) {
        $rec_forecasted_grouped = $grouped;
        
        // Apply enhanced growth to all accounts for recommended forecast
        foreach ($rec_forecasted_grouped as $category => $accounts) {
            foreach ($accounts as $key => $account) {
                $rec_forecasted_grouped[$category][$key]['balance'] = calculateEnhancedForecast($account['balance'], $forecast_period);
            }
        }
        
        // Apply additional optimizations
        $rec_forecasted_grouped = applyRecommendations($rec_forecasted_grouped);
    }
}

// Calculate totals
$current_assets_total = sectionTotal($grouped['Current Asset'] ?? []);
$non_current_assets_total = sectionTotal($grouped['Non-Current Asset'] ?? []);
$total_assets = $current_assets_total + $non_current_assets_total;

$current_liabilities_total = sectionTotal($grouped['Current Liability'] ?? []);
$non_current_liabilities_total = sectionTotal($grouped['Non-Current Liability'] ?? []);
$total_liabilities = $current_liabilities_total + $non_current_liabilities_total;

$total_equity = sectionTotal($grouped['Equity'] ?? []);
$total_liabilities_equity = $total_liabilities + $total_equity;

// Calculate forecasted totals if available
if ($forecasted_grouped) {
    $fc_current_assets_total = sectionTotal($forecasted_grouped['Current Asset'] ?? []);
    $fc_non_current_assets_total = sectionTotal($forecasted_grouped['Non-Current Asset'] ?? []);
    $fc_total_assets = $fc_current_assets_total + $fc_non_current_assets_total;

    $fc_current_liabilities_total = sectionTotal($forecasted_grouped['Current Liability'] ?? []);
    $fc_non_current_liabilities_total = sectionTotal($forecasted_grouped['Non-Current Liability'] ?? []);
    $fc_total_liabilities = $fc_current_liabilities_total + $fc_non_current_liabilities_total;

    $fc_total_equity = sectionTotal($forecasted_grouped['Equity'] ?? []);
    $fc_total_liabilities_equity = $fc_total_liabilities + $fc_total_equity;
}

// Calculate recommended forecast totals if available
if ($rec_forecasted_grouped) {
    $rec_fc_current_assets_total = sectionTotal($rec_forecasted_grouped['Current Asset'] ?? []);
    $rec_fc_non_current_assets_total = sectionTotal($rec_forecasted_grouped['Non-Current Asset'] ?? []);
    $rec_fc_total_assets = $rec_fc_current_assets_total + $rec_fc_non_current_assets_total;

    $rec_fc_current_liabilities_total = sectionTotal($rec_forecasted_grouped['Current Liability'] ?? []);
    $rec_fc_non_current_liabilities_total = sectionTotal($rec_forecasted_grouped['Non-Current Liability'] ?? []);
    $rec_fc_total_liabilities = $rec_fc_current_liabilities_total + $rec_fc_non_current_liabilities_total;

    $rec_fc_total_equity = sectionTotal($rec_forecasted_grouped['Equity'] ?? []);
    $rec_fc_total_liabilities_equity = $rec_fc_total_liabilities + $rec_fc_total_equity;
}

// Generate recommendations using AI integration
$recommendations = generateAIRecommendations(
    $current_assets_total, 
    $non_current_assets_total, 
    $current_liabilities_total, 
    $non_current_liabilities_total, 
    $total_equity,
    $total_assets,
    $filter
);

// Function to call OpenAI API for AI-powered recommendations
function callOpenAIAPI($prompt) {
    // Your OpenAI API key - store this securely in your environment variables
    $api_key = getenv('OPENAI_API_KEY') ?: 'your-openai-api-key-here';
    
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a financial advisor specializing in balance sheet analysis and recommendations. Provide clear, actionable advice in a structured format.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 1000
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $response_data = json_decode($response, true);
        return $response_data['choices'][0]['message']['content'] ?? 'No response from AI';
    } else {
        error_log("OpenAI API Error: HTTP $http_code - $response");
        return false;
    }
}

// Function to generate AI-powered recommendations
function generateAIRecommendations($current_assets, $non_current_assets, $current_liabilities, 
                                  $non_current_liabilities, $equity, $total_assets, $period) {
    
    // Calculate key financial ratios
    $current_ratio = $current_liabilities != 0 ? $current_assets / $current_liabilities : 0;
    $debt_to_equity = $equity != 0 ? ($current_liabilities + $non_current_liabilities) / $equity : 0;
    $debt_ratio = $total_assets != 0 ? ($current_liabilities + $non_current_liabilities) / $total_assets : 0;
    $equity_ratio = $total_assets != 0 ? $equity / $total_assets : 0;
    $fixed_asset_ratio = $current_assets != 0 ? $non_current_assets / $current_assets : 0;
    
    // Prepare prompt for AI
    $prompt = "Analyze this balance sheet and provide recommendations in the following JSON format: 
    {
        'liquidity': {'title': 'Working Capital Optimization', 'value': 'current_ratio:1', 'status': 'status text', 'action': 'recommendations'},
        'leverage': {'title': 'Debt Management', 'value': 'debt_to_equity', 'status': 'status text', 'action': 'recommendations'},
        'asset_util': {'title': 'Asset Efficiency', 'value': 'fixed_asset_ratio:1', 'status': 'status text', 'action': 'recommendations'},
        'profitability': {'title': 'Profitability Boosters', 'value': '', 'status': 'status text', 'action': 'recommendations'},
        'equity': {'title': 'Equity Strengthening', 'value': 'equity_ratio%', 'status': 'status text', 'action': 'recommendations'}
    }
    
    Financial data for $period period:
    - Current Assets: $current_assets
    - Non-Current Assets: $non_current_assets
    - Current Liabilities: $current_liabilities
    - Non-Current Liabilities: $non_current_liabilities
    - Equity: $equity
    - Total Assets: $total_assets
    
    Key ratios:
    - Current Ratio: $current_ratio
    - Debt to Equity: $debt_to_equity
    - Debt Ratio: $debt_ratio
    - Equity Ratio: $equity_ratio
    - Fixed Asset Ratio: $fixed_asset_ratio
    
    Provide specific, actionable recommendations for each category.";
    
    // Call the OpenAI API
    $ai_response = callOpenAIAPI($prompt);
    
    if ($ai_response && strpos($ai_response, '{') !== false) {
        // Extract JSON from response
        $json_start = strpos($ai_response, '{');
        $json_end = strrpos($ai_response, '}');
        $json_str = substr($ai_response, $json_start, $json_end - $json_start + 1);
        
        // Parse the JSON response
        $ai_recommendations = json_decode($json_str, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($ai_recommendations)) {
            return $ai_recommendations;
        }
    }
    
    // Fallback to rule-based recommendations if API fails
    return generateRuleBasedRecommendations(
        $current_assets, $non_current_assets, $current_liabilities, 
        $non_current_liabilities, $equity, $total_assets, $period
    );
}

// Fallback function for rule-based recommendations
function generateRuleBasedRecommendations($current_assets, $non_current_assets, $current_liabilities, 
                                        $non_current_liabilities, $equity, $total_assets, $period) {
    
    // Calculate key financial ratios
    $current_ratio = $current_liabilities != 0 ? $current_assets / $current_liabilities : 0;
    $debt_to_equity = $equity != 0 ? ($current_liabilities + $non_current_liabilities) / $equity : 0;
    $debt_ratio = $total_assets != 0 ? ($current_liabilities + $non_current_liabilities) / $total_assets : 0;
    $equity_ratio = $total_assets != 0 ? $equity / $total_assets : 0;
    $fixed_asset_ratio = $current_assets != 0 ? $non_current_assets / $current_assets : 0;
    
    // In a real implementation, you would call an AI API here
    // For demonstration, we'll use rule-based logic that simulates AI analysis
    
    $liquidity_status = $current_ratio > 1.5 ? "Strong" : ($current_ratio > 1 ? "Adequate" : "Concerning");
    $leverage_status = $debt_to_equity < 1 ? "Conservative" : ($debt_to_equity < 2 ? "Moderate" : "Aggressive");
    
    // Generate recommendations based on financial ratios
    $ai_recommendations = [
        'liquidity' => [
            'title' => 'Working Capital Optimization',
            'value' => number_format($current_ratio, 2) . ':1',
            'status' => $current_ratio > 1.8 ? 'Strong liquidity position' : 'Working capital needs improvement',
            'action' => generateLiquidityRecommendations($current_ratio, $period)
        ],
        'leverage' => [
            'title' => 'Debt Management',
            'value' => number_format($debt_to_equity, 2),
            'status' => $debt_to_equity < 0.6 ? 'Healthy debt levels' : 'High financial leverage',
            'action' => generateLeverageRecommendations($debt_to_equity, $period)
        ],
        'asset_util' => [
            'title' => 'Asset Efficiency',
            'value' => number_format($fixed_asset_ratio, 2) . ':1',
            'status' => $fixed_asset_ratio < 2.5 ? 'Efficient asset utilization' : 'Asset-heavy business model',
            'action' => generateAssetUtilizationRecommendations($non_current_assets, $current_assets, $period)
        ],
        'profitability' => [
            'title' => 'Profitability Boosters',
            'value' => '',
            'status' => 'Revenue enhancement opportunities',
            'action' => generateProfitabilityRecommendations($equity_ratio, $period)
        ],
        'equity' => [
            'title' => 'Equity Strengthening',
            'value' => number_format($equity_ratio * 100, 1) . '%',
            'status' => $equity_ratio > 0.4 ? 'Strong equity position' : 'Opportunity to strengthen equity',
            'action' => generateEquityRecommendations($equity_ratio, $period)
        ]
    ];
    
    return $ai_recommendations;
}

// AI-simulated recommendation functions (fallback)
function generateLiquidityRecommendations($current_ratio, $period) {
    $recommendations = [];
    
    if ($current_ratio < 1) {
        $recommendations[] = "Immediate action needed: Your current ratio of " . number_format($current_ratio, 2) . 
                            " indicates potential liquidity challenges. Consider:";
        $recommendations[] = "1. Negotiate extended payment terms with suppliers";
        $recommendations[] = "2. Accelerate accounts receivable collection process";
        $recommendations[] = "3. Explore short-term financing options for working capital";
    } elseif ($current_ratio < 1.5) {
        $recommendations[] = "Moderate liquidity position. Recommendations:";
        $recommendations[] = "1. Implement stricter credit controls for customers";
        $recommendations[] = "2. Optimize inventory management to reduce carrying costs";
        $recommendations[] = "3. Establish a line of credit for emergency needs";
    } else {
        $recommendations[] = "Strong liquidity position. Suggestions for optimization:";
        $recommendations[] = "1. Consider short-term investment of excess cash";
        $recommendations[] = "2. Evaluate early payment discounts from suppliers";
        $recommendations[] = "3. Review credit policies to potentially expand sales";
    }
    
    // Add period-specific advice
    if ($period == 'monthly') {
        $recommendations[] = "4. Implement weekly cash flow forecasting for better visibility";
    } elseif ($period == 'quarterly') {
        $recommendations[] = "4. Conduct quarterly review of working capital efficiency";
    }
    
    return implode('<br>', $recommendations);
}

function generateLeverageRecommendations($debt_to_equity, $period) {
    $recommendations = [];
    
    if ($debt_to_equity > 2) {
        $recommendations[] = "High leverage detected. Recommendations:";
        $recommendations[] = "1. Develop a debt reduction plan targeting highest interest loans first";
        $recommendations[] = "2. Consider equity financing to strengthen balance sheet";
        $recommendations[] = "3. Refinance existing debt at lower interest rates if possible";
    } elseif ($debt_to_equity > 1) {
        $recommendations[] = "Moderate leverage. Suggestions:";
        $recommendations[] = "1. Maintain current debt levels while focusing on profitability";
        $recommendations[] = "2. Consider pre-paying expensive debt when cash flows allow";
        $recommendations[] = "3. Monitor interest coverage ratio regularly";
    } else {
        $recommendations[] = "Conservative debt levels. Opportunities:";
        $recommendations[] = "1. Consider strategic leverage to fund growth opportunities";
        $recommendations[] = "2. Evaluate return on potential investments vs. cost of debt";
        $recommendations[] = "3. Maintain strong credit rating for future flexibility";
    }
    
    return implode('<br>', $recommendations);
}

function generateAssetUtilizationRecommendations($non_current_assets, $current_assets, $period) {
    $ratio = $current_assets != 0 ? $non_current_assets / $current_assets : 0;
    $recommendations = [];
    
    if ($ratio > 3) {
    $recommendations[] = "Asset-heavy structure detected. Recommendations:";
    $recommendations[] = "1. Evaluate leasing options instead of owning equipment";
    $recommendations[] = "2. Consider sale-leaseback arrangements to free up capital";
    $recommendations[] = "3. Implement preventive maintenance to extend asset life";
    } elseif ($ratio > 1.5) {
        $recommendations[] = "Moderate asset allocation. Suggestions:";
        $recommendations[] = "1. Review asset utilization rates for improvement opportunities";
        $recommendations[] = "2. Consider disposing of underutilized assets";
        $recommendations[] = "3. Evaluate technology upgrades to improve efficiency";
    } else {
        $recommendations[] = "Efficient asset structure. Opportunities:";
        $recommendations[] = "1. Consider strategic investments in productivity-enhancing assets";
        $recommendations[] = "2. Review replacement cycles for optimal efficiency";
        $recommendations[] = "3. Explore automation opportunities to reduce labor costs";
    }
    
    return implode('<br>', $recommendations);
}

function generateProfitabilityRecommendations($equity_ratio, $period) {
    $recommendations = [];
    
    $recommendations[] = "Profitability enhancement strategies:";
    $recommendations[] = "1. Analyze product/service line profitability to focus on highest margins";
    $recommendations[] = "2. Implement cost control measures without compromising quality";
    $recommendations[] = "3. Develop pricing strategy based on value delivered, not just costs";
    
    if ($equity_ratio < 0.3) {
        $recommendations[] = "4. Focus on retaining earnings to strengthen equity base";
    }
    
    if ($period == 'monthly') {
        $recommendations[] = "5. Implement monthly review of key performance indicators";
    }
    
    return implode('<br>', $recommendations);
}

function generateEquityRecommendations($equity_ratio, $period) {
    $recommendations = [];
    
    if ($equity_ratio < 0.3) {
        $recommendations[] = "Equity strengthening needed. Recommendations:";
        $recommendations[] = "1. Retain more earnings instead of distributing as dividends";
        $recommendations[] = "2. Consider equity financing from investors if growth opportunities exist";
        $recommendations[] = "3. Evaluate converting debt to equity where possible";
    } elseif ($equity_ratio < 0.5) {
        $recommendations[] = "Moderate equity position. Suggestions:";
        $recommendations[] = "1. Maintain balanced approach between debt and equity financing";
        $recommendations[] = "2. Consider stock buyback program if shares are undervalued";
        $recommendations[] = "3. Develop long-term capital structure strategy";
    } else {
        $recommendations[] = "Strong equity position. Opportunities:";
        $recommendations[] = "1. Consider strategic acquisitions using equity if appropriate";
        $recommendations[] = "2. Evaluate special dividends or shareholder returns";
        $recommendations[] = "3. Maintain strong equity base for future expansion";
    }
    
    return implode('<br>', $recommendations);
}

// Generate chart interpretation using AI
$chart_interpretation = generateChartInterpretation(
    $current_assets_total, 
    $non_current_assets_total, 
    $current_liabilities_total, 
    $non_current_liabilities_total, 
    $total_equity,
    $total_assets,
    $filter
);

// Function to generate AI-powered chart interpretation
function generateChartInterpretation($current_assets, $non_current_assets, $current_liabilities, 
                                   $non_current_liabilities, $equity, $total_assets, $period) {
    
    // Calculate key financial ratios
    $current_ratio = $current_liabilities != 0 ? $current_assets / $current_liabilities : 0;
    $debt_to_equity = $equity != 0 ? ($current_liabilities + $non_current_liabilities) / $equity : 0;
    $debt_ratio = $total_assets != 0 ? ($current_liabilities + $non_current_liabilities) / $total_assets : 0;
    $equity_ratio = $total_assets != 0 ? $equity / $total_assets : 0;
    $fixed_asset_ratio = $current_assets != 0 ? $non_current_assets / $current_assets : 0;
    
    // Prepare prompt for AI
    $prompt = "Analyze these balance sheet charts and provide a concise interpretation focusing on:
    1. Balance Sheet Composition (Pie Chart) - showing Current Assets, Non-Current Assets, Current Liabilities, Non-Current Liabilities, and Equity
    2. Assets vs Liabilities Comparison (Bar Chart) - comparing Current/Non-Current Assets vs Liabilities
    
    Financial data for $period period:
    - Current Assets: $current_assets
    - Non-Current Assets: $non_current_assets
    - Current Liabilities: $current_liabilities
    - Non-Current Liabilities: $non_current_liabilities
    - Equity: $equity
    - Total Assets: $total_assets
    
    Key ratios:
    - Current Ratio: $current_ratio
    - Debt to Equity: $debt_to_equity
    - Equity Ratio: $equity_ratio
    
    Provide a clear, professional interpretation of what the charts reveal about the company's financial structure and health.";
    
    // Call the OpenAI API
    $ai_response = callOpenAIAPI($prompt);
    
    if ($ai_response) {
        return $ai_response;
    }
    
    // Fallback interpretation
    return "The balance sheet charts provide valuable insights into your company's financial structure. The composition pie chart shows how your assets, liabilities, and equity are distributed, while the bar chart compares your assets against liabilities. Based on your current ratio of " . number_format($current_ratio, 2) . ":1 and debt-to-equity ratio of " . number_format($debt_to_equity, 2) . ", your financial position appears " . ($current_ratio > 1.5 ? "strong" : "moderate") . ". The equity ratio of " . number_format($equity_ratio * 100, 1) . "% indicates " . ($equity_ratio > 0.4 ? "a healthy level of owner financing" : "potential reliance on debt financing") . ".";
}

// Back URL
$backUrl = (isset($_GET['admin']) && isset($_GET['user_id']))
    ? 'client_details.php?user_id=' . $_GET['user_id']
    : 'asset.php';

// If this is an AJAX request, return JSON data
if ($is_ajax && $forecast_period) {
    header('Content-Type: application/json');
    
    // Check if forecast data was generated successfully
    if (!$forecasted_grouped) {
        echo json_encode(['error' => 'Failed to generate forecast data']);
        exit;
    }
    
    $response = [
        'current' => [
            'total_assets' => $total_assets,
            'total_liabilities' => $total_liabilities,
            'total_equity' => $total_equity,
            'net_position' => $total_assets - $total_liabilities
        ],
        'forecasted' => [
            'total_assets' => $fc_total_assets,
            'total_liabilities' => $fc_total_liabilities,
            'total_equity' => $fc_total_equity,
            'net_position' => $fc_total_assets - $fc_total_liabilities
        ]
    ];
    
    if ($apply_recommendations && $rec_forecasted_grouped) {
        $response['recommended'] = [
            'total_assets' => $rec_fc_total_assets,
            'total_liabilities' => $rec_fc_total_liabilities,
            'total_equity' => $rec_fc_total_equity,
            'net_position' => $rec_fc_total_assets - $rec_fc_total_liabilities
        ];
    }
    
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Balance Sheet</title>
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
        }

        /* Three-column layout */
        .balance-sheet-columns {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
		
        .balance-column {
            flex: 1;
            border: 1px solid #c4d8eb;
            border-radius: 8px;
            padding: 15px;
            height: 70vh;
            overflow-y: hidden;
            background: white;
            box-shadow: 0 4px 8px rgba(26, 95, 156, 0.1);
            position: relative;
            display: flex;
            flex-direction: column;
        }
		
		.balance-column-header {
			background-color: var(--primary-blue);
			color: white;
			text-align: center;
			padding: 10px;
			border-radius: 6px 6px 0 0;
			margin: -15px -15px 15px -15px;
			width: calc(100% + 30px);
		}

		.balance-column-header h4 {
			margin: 0;
			font-weight: 600;
			font-size: 1.2rem;
		}
		
        /* Make the account lists scrollable but keep totals fixed at bottom */
        .balance-column > .account-category,
        .balance-column > ul {
            flex-shrink: 0; /* Prevent shrinking */
        }

        .balance-column > ul:last-of-type {
            flex-grow: 1; /* Allow the last list to grow and push totals down */
            overflow-y: hidden; /* Make accounts scrollable */
            margin-bottom: 0 !important; /* Remove bottom margin */
        }
		
	     .balance-column > *:not(.grand-total) {
          flex-shrink: 1; /* Allow content to shrink */
		}	

        /* Ensure totals stay at bottom and don't get overlapped */
        .total-row {
            font-weight: 600;
            background-color: #e1ecf5;
            margin-top: 15px !important;
            margin-bottom: 5px !important;
            padding-top: 10px !important;
            padding-bottom: 10px !important;
            flex-shrink: 0; /* Prevent shrinking */
        }
	     .account-list-container {
          flex-grow: 1; /* Take up available space */
          overflow-y: hidden;
		}
		
        /* Fix grand total positioning */
		.grand-total {
			font-weight: 800 !important;
			font-size: 1.1rem !important;
			background-color: var(--primary-blue) !important;
			color: white !important;
			margin-top: auto !important; /* Push to bottom using flex */
			position: sticky;
			bottom: 0;
			z-index: 90;
			margin: 15px -15px -15px -15px !important; /* Adjust margins to span full width */
			width: calc(100% + 30px); /* Compensate for column padding */
			border-radius: 0 0 6px 6px !important;
			padding: 15px !important;
			box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.2);
			border-top: 2px solid rgba(255, 255, 255, 0.2);
			flex-shrink: 0; /* Prevent shrinking */
			/* Ensure it behaves like a footer */
			order: 999; /* Force to bottom in flex container */
		}
	

        /* Adjust list group margins */
        .balance-column ul {
            margin-bottom: 10px !important;
        }

        .balance-column ul:last-of-type {
            margin-bottom: 5px !important;
        }

        /* Ensure account lists are scrollable but totals remain fixed */
        .account-list-container {
            flex-grow: 1;
            overflow-y: hidden;
            margin-bottom: 10px;
        }

        :root {
            --primary: #4682B4;
            --secondary: #5a96cf;
            --accent: #2a5a80;
            --light: #f8f9fa;
            --dark: #2a5a80;
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
            overflow: hidden !important;
        }
        
        .forecast-section {
            background-color: #f8f9fa;
            border-left: 4px solid var(--primary);
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        
        .forecast-header {
            color: var(--dark);
            border-bottom: 2px solid var(--primary);
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
        
        .scenario-header {
            color: var(--accent);
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
            background-color: var(--primary);
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
            background: var(--primary);
            color: white;
        }
        
        /* Added styles for forecast filter */
        .forecast-filter {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }
        
        .recommendation-card {
            border-left: 4px solid #4ECDC4;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }
        
        .recommendation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
        
        .rec-title {
            color: var(--dark);
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .rec-value {
            background-color: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.9rem;
            display: inline-block;
            margin-bottom: 8px;
        }
        
        .rec-action {
            background-color: #e9f7fe;
            padding: 10px;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .balance-column {
            position: relative;
            overflow-y: hidden;
            max-height: 70vh;
        }

        /* View filter aligned with action buttons at top right */
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

        /* Make sure the report container has relative positioning */
        .report-container {
            position: relative;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            margin: 20px auto;
            max-width: 97%;
            width: 97%;
            overflow: hidden !important;
            scrolling: no !important;
        }

        /* Completely remove the old bottom filter */
        .view-filter-bottom {
            display: none;
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
        } .ai-badge {
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

        /* Chart Interpretation Section */
        .chart-interpretation-section {
            background: linear-gradient(135deg, #f8f9fa, #e9f7fe);
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            border: 1px solid #c4d8eb;
            box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        }
        
        .interpretation-header {
            color: var(--dark-blue);
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .interpretation-content {
            background: white;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid var(--primary-blue);
            font-size: 1rem;
            line-height: 1.6;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        
        .interpretation-points {
            margin-top: 20px;
        }
        
        .interpretation-point {
            background: #f0f7ff;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 3px solid var(--accent-blue);
        }
        
        .point-title {
            font-weight: 600;
            color: var(--dark-blue);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .point-title i {
            margin-right: 10px;
            color: var(--primary-blue);
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
            .forecast-filter, .ai-section, .chart-section, .chart-interpretation-section {
                display: none !important;
            }
        }

        /* NEW: Report header styles from trial balance */
        .report-header {
            padding-bottom: 10px;
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

        /* NEW: Button container styles for side-by-side buttons */
        .button-container {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* NEW: Initially hidden forecast section */
        .forecast-results-container {
            display: none;
        }
        
        /* NEW: Back to Top Button - UPDATED to show only at bottom */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            display: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-blue);
            color: white;
            border: none;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }
        
        .back-to-top:hover {
            background: var(--dark-blue);
            transform: translateX(-50%) translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4);
        }
        
        .back-to-top.show {
            display: flex;
        }

        /* NEW: Green frame for recommended position */
        .recommended-card {
            border: 3px solid #28a745 !important;
            box-shadow: 0 0 15px rgba(40, 167, 69, 0.3) !important;
            position: relative;
            transition: all 0.3s ease;
        }

        .recommended-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4) !important;
        }
    </style>
</head>
<body>

<!-- Document/Receipt Style (Hidden by default, shown when printing) -->
<div class="document-style">
    <div class="document-header">
        <div class="document-title">BALANCE SHEET</div>
        <div class="document-company"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-subtitle">As of <?= date('F d, Y') ?></div>
        <div class="document-date">(<?= ucfirst($filter) ?> View)</div>
    </div>
    
    <!-- Assets Section -->
    <div class="document-section">
        <div class="document-section-title">ASSETS</div>
        
        <div class="document-section">
            <div style="font-weight: bold; margin-bottom: 5px;">Current Assets</div>
            <?php foreach (($grouped['Current Asset'] ?? []) as $acc): ?>
                <div class="document-row">
                    <div><?= htmlspecialchars($acc['name']) ?></div>
                    <div><?= fmt($acc['balance']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="document-row total">
                <div>Total Current Assets</div>
                <div><?= fmt($current_assets_total) ?></div>
            </div>
        </div>
        
        <div class="document-section">
            <div style="font-weight: bold; margin-bottom: 5px;">Non-Current Assets</div>
            <?php foreach (($grouped['Non-Current Asset'] ?? []) as $acc): ?>
                <div class="document-row">
                    <div><?= htmlspecialchars($acc['name']) ?></div>
                    <div><?= fmt($acc['balance']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="document-row total">
                <div>Total Non-Current Assets</div>
                <div><?= fmt($non_current_assets_total) ?></div>
            </div>
        </div>
        
        <div class="document-row grand-total">
            <div>TOTAL ASSETS</div>
            <div><?= fmt($total_assets) ?></div>
        </div>
    </div>
    
    <!-- Liabilities and Equity Section -->
    <div class="document-section">
        <div class="document-section-title">LIABILITIES AND EQUITY</div>
        
        <div class="document-section">
            <div style="font-weight: bold; margin-bottom: 5px;">Current Liabilities</div>
            <?php foreach (($grouped['Current Liability'] ?? []) as $acc): ?>
                <div class="document-row">
                    <div><?= htmlspecialchars($acc['name']) ?></div>
                    <div><?= fmt($acc['balance']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="document-row total">
                <div>Total Current Liabilities</div>
                <div><?= fmt($current_liabilities_total) ?></div>
            </div>
        </div>
        
        <div class="document-section">
            <div style="font-weight: bold; margin-bottom: 5px;">Non-Current Liabilities</div>
            <?php foreach (($grouped['Non-Current Liability'] ?? []) as $acc): ?>
                <div class="document-row">
                    <div><?= htmlspecialchars($acc['name']) ?></div>
                    <div><?= fmt($acc['balance']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="document-row total">
                <div>Total Non-Current Liabilities</div>
                <div><?= fmt($non_current_liabilities_total) ?></div>
            </div>
        </div>
        
        <div class="document-section">
            <div style="font-weight: bold; margin-bottom: 5px;">Equity</div>
            <?php foreach (($grouped['Equity'] ?? []) as $acc): ?>
                <div class="document-row">
                    <div><?= htmlspecialchars($acc['name']) ?></div>
                    <div><?= fmt($acc['balance']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="document-row total">
                <div>Total Equity</div>
                <div><?= fmt($total_equity) ?></div>
            </div>
        </div>
        
        <div class="document-row grand-total">
            <div>TOTAL LIABILITIES AND EQUITY</div>
            <div><?= fmt($total_liabilities_equity) ?></div>
        </div>
    </div>

    <!-- Financial Visualization Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Financial Position Visualization</h4>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-pie"></i>
                <h4>Balance Sheet Composition</h4>
                <p>Pie chart showing distribution of Current Assets, Non-Current Assets, Current Liabilities, Non-Current Liabilities, and Equity</p>
                <p><strong>Current Assets:</strong> <?= fmt($current_assets_total) ?></p>
                <p><strong>Non-Current Assets:</strong> <?= fmt($non_current_assets_total) ?></p>
                <p><strong>Current Liabilities:</strong> <?= fmt($current_liabilities_total) ?></p>
                <p><strong>Non-Current Liabilities:</strong> <?= fmt($non_current_liabilities_total) ?></p>
                <p><strong>Equity:</strong> <?= fmt($total_equity) ?></p>
            </div>
        </div>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-bar"></i>
                <h4>Assets vs Liabilities Comparison</h4>
                <p>Bar chart comparing Current/Non-Current Assets vs Liabilities</p>
                <p><strong>Total Assets:</strong> <?= fmt($total_assets) ?></p>
                <p><strong>Total Liabilities:</strong> <?= fmt($total_liabilities) ?></p>
                <p><strong>Net Position:</strong> <?= fmt($total_assets - $total_liabilities) ?></p>
            </div>
        </div>
    </div>

    <!-- Chart Interpretation Section for Print -->
    <div class="print-interpretation-section">
        <h4 class="print-interpretation-header">
            <i class="fas fa-analytics me-2"></i>Balance Sheet Chart Interpretation
        </h4>
        
        <div class="print-interpretation-content">
            <?= $chart_interpretation ?>
        </div>
        
        <div class="print-interpretation-points">
            <div class="print-interpretation-point">
                <div class="print-point-title">Balance Sheet Composition Analysis</div>
                <p>The pie chart illustrates how your company's resources are allocated across different categories. A healthy balance sheet typically shows a balanced distribution between current and non-current assets, with liabilities structured to support sustainable growth.</p>
            </div>
            
            <div class="print-interpretation-point">
                <div class="print-point-title">Assets vs Liabilities Comparison</div>
                <p>The bar chart provides a clear visual comparison between your assets and liabilities. This helps assess your company's solvency and financial stability by showing whether assets adequately cover liabilities.</p>
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
        <p>This balance sheet represents the financial position of <?= htmlspecialchars($company_name) ?> as of <?= date('F d, Y') ?>.</p>
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
        <h3 class="report-title">Balance Sheet</h3>
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

<!-- Balance Sheet Content in Three Columns -->
<div class="balance-sheet-columns">
	<!-- Assets Column -->
		<div class="balance-column">
        <div class="balance-column-header">
            <h4>Assets</h4>
        </div>
        
        <div class="account-category">Current Assets</div>
        <ul class="list-group mb-4">
            <?php foreach (($grouped['Current Asset'] ?? []) as $acc): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($acc['name']) ?>
                    <span class="text-end"><?= fmt($acc['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Total Current Assets
                <span class="text-end"><?= fmt($current_assets_total) ?></span>
            </li>
        </ul>

        <div class="account-category">Non-Current Assets</div>
        <ul class="list-group mb-4">
            <?php foreach (($grouped['Non-Current Asset'] ?? []) as $acc): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($acc['name']) ?>
                    <span class="text-end"><?= fmt($acc['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Total Non-Current Assets
                <span class="text-end"><?= fmt($non_current_assets_total) ?></span>
            </li>
        </ul>

        <li class="list-group-item d-flex justify-content-between grand-total">
            Total Assets
            <span class="text-end"><?= fmt($total_assets) ?></span>
        </li>
    </div>
    
    <!-- Liabilities Column -->
    <div class="balance-column">
        <div class="balance-column-header">
            <h4>Liabilities</h4>
        </div>
        
        <div class="account-category">Current Liabilities</div>
        <ul class="list-group mb-4">
            <?php foreach (($grouped['Current Liability'] ?? []) as $acc): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($acc['name']) ?>
                    <span class="text-end"><?= fmt($acc['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Total Current Liabilities
                <span class="text-end"><?= fmt($current_liabilities_total) ?></span>
            </li>
        </ul>

        <div class="account-category">Non-Current Liabilities</div>
        <ul class="list-group mb-4">
            <?php foreach (($grouped['Non-Current Liability'] ?? []) as $acc): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($acc['name']) ?>
                    <span class="text-end"><?= fmt($acc['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Total Non-Current Liabilities
                <span class="text-end"><?= fmt($non_current_liabilities_total) ?></span>
            </li>
        </ul>

        <li class="list-group-item d-flex justify-content-between grand-total">
            Total Liabilities
            <span class="text-end"><?= fmt($total_liabilities) ?></span>
        </li>
    </div>
    
    <!-- Equity Column -->
    <div class="balance-column">
        <div class="balance-column-header">
            <h4>Equity</h4>
        </div>
        
        <ul class="list-group mb-4">
            <?php foreach (($grouped['Equity'] ?? []) as $acc): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($acc['name']) ?>
                    <span class="text-end"><?= fmt($acc['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Total Equity
                <span class="text-end"><?= fmt($total_equity) ?></span>
            </li>
        </ul>

        <li class="list-group-item d-flex justify-content-between grand-total">
            Total Liabilities and Equity
            <span class="text-end"><?= fmt($total_liabilities_equity) ?></span>
        </li>
    </div>
</div>

<!-- Financial Visualization Section -->
<div class="chart-section mt-4 no-print">
    <h4 class="section-title">Financial Position Visualization</h4>
    
    <div class="row">
        <div class="col-md-6 chart-container" id="balancePieChart"></div>
        <div class="col-md-6 chart-container" id="assetLiabilityChart"></div>
    </div>
</div>

<!-- Chart Interpretation Section -->
<div class="chart-interpretation-section no-print">
    <h4 class="interpretation-header">
        <i class="fas fa-analytics me-2"></i>Balance Sheet Chart Interpretation
    </h4>
    
    <div class="interpretation-content">
        <?= $chart_interpretation ?>
    </div>
    
    <div class="interpretation-points">
        <div class="interpretation-point">
            <div class="point-title">
                <i class="fas fa-chart-pie"></i>Balance Sheet Composition Analysis
            </div>
            <p>The pie chart illustrates how your company's resources are allocated across different categories. A healthy balance sheet typically shows a balanced distribution between current and non-current assets, with liabilities structured to support sustainable growth.</p>
        </div>
        
        <div class="interpretation-point">
            <div class="point-title">
                <i class="fas fa-balance-scale"></i>Assets vs Liabilities Comparison
            </div>
            <p>The bar chart provides a clear visual comparison between your assets and liabilities. This helps assess your company's solvency and financial stability by showing whether assets adequately cover liabilities.</p>
        </div>
    </div>
    
    <div class="mt-3 text-end">
        <small class="text-muted">Chart interpretation generated on <?= date('F j, Y \a\t g:i A') ?> using AI analysis</small>
    </div>
</div>

<!-- Forecast Filter Section - MOVED ABOVE RECOMMENDATIONS -->
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
                <option value="monthly">Monthly Projection</option>
                <option value="quarterly">Quarterly Projection</option>
                <option value="biannually">Bi-Annual Projection</option>
                <option value="annually">Annual Projection</option>
            </select>
        </div>
        
        <div class="col-md-5">
            <div class="form-check mt-4 pt-2">
                <input class="form-check-input" type="checkbox" name="apply_recs" id="applyRecs">
                <label class="form-check-label" for="applyRecs">
                    Apply recommendations to forecast
                </label>
                </div>
        </div>
        
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary mt-3" id="forecastButton">
                <i class="fas fa-calculator me-2"></i> Forecast
            </button>
        </div>
    </form>
</div>

<!-- Loading indicator -->
<div class="loading no-print" id="loadingIndicator">
    <div class="loading-spinner"></div>
    <p class="mt-2">Generating forecast...</p>
</div>

<!-- Forecast Results - Initially Blank -->
<div class="forecast-results-container no-print" id="forecastResults">
    <!-- Forecast results will be loaded here via AJAX -->
</div>

<!-- AI Recommendations Section - MOVED BELOW FORECAST -->
<div class="ai-section no-print">
    <h4><i class="fas fa-robot me-2 text-primary"></i> AI-Powered Financial Health Recommendations</h4>
    <p class="text-muted">Our AI analysis of your balance sheet provides these tailored recommendations:</p>
    
    <div class="row">
        <?php foreach ($recommendations as $key => $rec): ?>
            <div class="col-md-6 mb-4">
                <div class="recommendation-card">
                    <div class="rec-title">
                        <?= $rec['title'] ?>
                        <span class="ai-badge">AI Analysis</span>
                    </div>
                    <?php if (!empty($rec['value'])): ?>
                        <div class="rec-value"><?= $rec['value'] ?></div>
                    <?php endif; ?>
                    <div class="rec-status <?= strpos(strtolower($rec['status']), 'strong') !== false || strpos(strtolower($rec['status']), 'healthy') !== false ? 'status-strong' : (strpos(strtolower($rec['status']), 'concerning') !== false ? 'status-concerning' : '') ?>">
                        <?= $rec['status'] ?>
                    </div>
                    <div class="rec-action"><?= $rec['action'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="mt-3 text-end">
        <small class="text-muted">Recommendations generated on <?= date('F j, Y \a\t g:i A') ?> using advanced financial analysis algorithms</small>
    </div>
</div>

<div class="mt-5 text-center text-muted no-print">
    <p>Generated on <?= date('F j, Y \a\t g:i A') ?></p>
</div>

</div>

<!-- Back to Top Button - UPDATED to show only at bottom -->
<button class="back-to-top no-print" id="backToTopBtn">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ApexCharts Library -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.3/dist/apexcharts.min.js"></script>

<script>
// Function to update view filter (from trial balance)
function updateView(filter) {
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    window.location.href = url.toString();
}

// Function to format currency
function formatCurrency(value) {
    if (value == 0 || value == null) return '';
    const absValue = Math.abs(value);
    const formatted = absValue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return '₱ ' + (value < 0 ? '(' + formatted + ')' : formatted);
}

// Helper function to get period display name
function getPeriodDisplayName(period) {
    const periodMap = {
        monthly: 'Monthly',
        quarterly: 'Quarterly',
        biannually: 'Bi-Annual',
        annually: 'Annual'
    };
    return periodMap[period] || period;
}

// Function to build forecast section HTML
function buildForecastSection(data, forecastPeriod, applyRecs) {
    let html = `
    <div class="forecast-section">
        <h4 class="forecast-header">
            <i class="fas fa-crystal-ball"></i> Financial Forecast
            <span class="badge bg-primary">${getPeriodDisplayName(forecastPeriod)} Projection</span>
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
            </div>
            ${applyRecs && data.recommended ? `
            <div class="timeline-point">
                <div class="timeline-marker active-marker">3</div>
                <div class="timeline-label">Recommended</div>
            </div>
            ` : ''}
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
                            Total Assets
                            <span>${formatCurrency(data.current.total_assets)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            Total Liabilities
                            <span>${formatCurrency(data.current.total_liabilities)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            Total Equity
                            <span>${formatCurrency(data.current.total_equity)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between total-row">
                            Net Position
                            <span>${formatCurrency(data.current.net_position)}</span>
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
                            Total Assets
                            <span>${formatCurrency(data.forecasted.total_assets)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            Total Liabilities
                            <span>${formatCurrency(data.forecasted.total_liabilities)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            Total Equity
                            <span>${formatCurrency(data.forecasted.total_equity)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between total-row">
                            Net Position
                            <span>${formatCurrency(data.forecasted.net_position)}</span>
                        </li>
                    </ul>
                    <div class="mt-3">
                        <p class="mb-1"><strong>Projection Basis:</strong></p>
                        <p class="small">Based on current growth trends (5% base + period adjustment)</p>
                    </div>
                </div>
            </div>
            `;

    if (applyRecs && data.recommended) {
        html += `
            <!-- Recommended Financial Position -->
            <div class="col-md-4">
                <div class="scenario-card recommended-card">
                    <div class="scenario-header">
                        <i class="fas fa-lightbulb"></i> Recommended Position
                        <span class="recommendation-badge">AI Optimized</span>
                    </div>
                    <ul class="list-group">
                        <li class="list-group-item d-flex justify-content-between">
                            Total Assets
                            <span>${formatCurrency(data.recommended.total_assets)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            Total Liabilities
                            <span>${formatCurrency(data.recommended.total_liabilities)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            Total Equity
                            <span>${formatCurrency(data.recommended.total_equity)}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between total-row">
                            Net Position
                            <span>${formatCurrency(data.recommended.net_position)}</span>
                        </li>
                    </ul>
                    <div class="mt-3">
                        <p class="mb-1"><strong>Optimization Applied:</strong></p>
                        <p class="small">Enhanced growth projection (35% higher) with AI-powered recommendations for working capital, debt management, and asset efficiency</p>
                    </div>
                </div>
            </div>
        `;
    }

    html += `
        </div>
        
        ${applyRecs && data.recommended ? `
        <div class="mt-4 p-3 bg-light border rounded">
            <h6><i class="fas fa-info-circle me-2 text-primary"></i>About the Recommended Position</h6>
            <p class="mb-0">The "Recommended Position" shows the potential outcome if you consistently follow the AI recommendations. This scenario applies enhanced growth projections (35% higher than standard forecast) along with strategic optimizations to working capital, debt structure, and asset utilization.</p>
        </div>
        ` : ''}
    </div>
    `;

    return html;
}

// Back to Top functionality - UPDATED to show only when at bottom
function setupBackToTop() {
    const backToTopBtn = document.getElementById('backToTopBtn');
    
    window.addEventListener('scroll', function() {
        // Show button only when user reaches the bottom of the page
        const scrollPosition = window.pageYOffset;
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;
        
        // Show button when within 100px of the bottom
        if (scrollPosition + windowHeight >= documentHeight - 100) {
            backToTopBtn.classList.add('show');
        } else {
            backToTopBtn.classList.remove('show');
        }
    });
    
    backToTopBtn.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}

// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Setup back to top button
    setupBackToTop();
    
    // Only create charts if containers exist
    if (document.querySelector("#balancePieChart")) {
        // Balance Sheet Composition Pie Chart
        const pieOptions = {
            series: [
                <?= abs($current_assets_total) ?>, 
                <?= abs($non_current_assets_total) ?>, 
                <?= abs($current_liabilities_total) ?>, 
                <?= abs($non_current_liabilities_total) ?>, 
                <?= abs($total_equity) ?>
            ],
            chart: { 
                type: 'pie', 
                height: 300,
                toolbar: {
                    show: true,
                    tools: {
                        download: true
                    }
                }
            },
            labels: ['Current Assets', 'Non-Current Assets', 'Current Liabilities', 'Non-Current Liabilities', 'Equity'],
            colors: ['#4682B4', '#5a96cf', '#FF6B6B', '#FFA07A', '#4ECDC4'],
            legend: { position: 'bottom' },
            responsive: [{
                breakpoint: 480,
                options: { chart: { width: 300 } }
            }],
            dataLabels: {
                enabled: true,
                formatter: function(val, opts) {
                    return opts.w.config.series[opts.seriesIndex].toLocaleString('en-PH', {
                        style: 'currency',
                        currency: 'PHP',
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0
                    });
                }
            }
        };
        new ApexCharts(document.querySelector("#balancePieChart"), pieOptions).render();
    }

    if (document.querySelector("#assetLiabilityChart")) {
        // Assets vs Liabilities Bar Chart
        const barOptions = {
            series: [{
                name: 'Assets',
                data: [<?= abs($current_assets_total) ?>, <?= abs($non_current_assets_total) ?>]
            }, {
                name: 'Liabilities',
                data: [<?= abs($current_liabilities_total) ?>, <?= abs($non_current_liabilities_total) ?>]
            }],
            chart: { 
                type: 'bar', 
                height: 300,
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
                    columnWidth: '60%',
                    endingShape: 'rounded'
                } 
            },
            xaxis: { 
                categories: ['Current', 'Non-Current'],
                labels: {
                    style: {
                        fontSize: '14px'
                    }
                }
            },
            yaxis: {
                labels: {
                    formatter: function(val) {
                        return '₱' + val.toLocaleString();
                    }
                }
            },
            colors: ['#4682B4', '#FF6B6B'],
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    return '₱' + val.toLocaleString();
                },
                offsetY: -20,
                style: {
                    fontSize: '12px',
                    colors: ["#000"]
                }
            }
        };
        new ApexCharts(document.querySelector("#assetLiabilityChart"), barOptions).render();
    }
    
    // Handle forecast form submission with AJAX
    const forecastForm = document.getElementById('forecastForm');
    const forecastResults = document.getElementById('forecastResults');
    const loadingIndicator = document.getElementById('loadingIndicator');
    
    if (forecastForm) {
        forecastForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const forecastPeriod = document.getElementById('forecastPeriod').value;
            const applyRecs = document.getElementById('applyRecs').checked;
            
            if (!forecastPeriod) {
                alert('Please select a forecast period');
                return;
            }
            
            // Show loading indicator
            loadingIndicator.style.display = 'block';
            forecastResults.style.display = 'none';
            
            // Prepare form data
            const formData = new FormData(this);
            
            // Send AJAX request
            fetch('report_balance_sheet.php?' + new URLSearchParams(formData), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                loadingIndicator.style.display = 'none';
                
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                
                // Build and display forecast section
                const forecastHTML = buildForecastSection(data, forecastPeriod, applyRecs);
                
                forecastResults.innerHTML = forecastHTML;
                forecastResults.style.display = 'block';
                
                // Scroll to forecast results
                forecastResults.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(error => {
                console.error('Error:', error);
                loadingIndicator.style.display = 'none';
                alert('An error occurred while generating the forecast.');
            });
        });
    }
});
</script>
</body>
</html>
