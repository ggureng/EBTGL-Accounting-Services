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

// Cash flow specific queries
// Operating Activities
$stmt = $pdo->prepare("SELECT a.name, 
       SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
FROM `$accountsTable` a
JOIN `$transactionsTable` t ON a.name = t.account_name
WHERE t.date BETWEEN ? AND ?
AND (a.name LIKE '%revenue%' OR a.name LIKE '%income%' OR a.name LIKE '%expense%' 
     OR a.name LIKE '%receivable%' OR a.name LIKE '%payable%' OR a.name LIKE '%inventory%')
GROUP BY a.name");
$stmt->execute([$startDate, $endDate]);
$operating_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Investing Activities
$stmt = $pdo->prepare("SELECT a.name, 
       SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
FROM `$accountsTable` a
JOIN `$transactionsTable` t ON a.name = t.account_name
WHERE t.date BETWEEN ? AND ?
AND (a.name LIKE '%equipment%' OR a.name LIKE '%property%' OR a.name LIKE '%investment%'
     OR a.name LIKE '%asset%' OR a.name LIKE '%plant%')
GROUP BY a.name");
$stmt->execute([$startDate, $endDate]);
$investing_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Financing Activities
$stmt = $pdo->prepare("SELECT a.name, 
       SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
FROM `$accountsTable` a
JOIN `$transactionsTable` t ON a.name = t.account_name
WHERE t.date BETWEEN ? AND ?
AND (a.name LIKE '%loan%' OR a.name LIKE '%equity%' OR a.name LIKE '%dividend%'
     OR a.name LIKE '%capital%' OR a.name LIKE '%stock%')
GROUP BY a.name");
$stmt->execute([$startDate, $endDate]);
$financing_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$operating_total = 0;
foreach ($operating_activities as $activity) {
    $operating_total += $activity['balance'];
}

$investing_total = 0;
foreach ($investing_activities as $activity) {
    $investing_total += $activity['balance'];
}

$financing_total = 0;
foreach ($financing_activities as $activity) {
    $financing_total += $activity['balance'];
}

$net_cash_flow = $operating_total + $investing_total + $financing_total;

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
function applyRecommendations($operating, $investing, $financing) {
    $adjusted = [
        'operating' => $operating,
        'investing' => $investing,
        'financing' => $financing
    ];
    
    // Cash Flow Optimization
    if ($operating < 0) {
        // Improve operating cash flow by 20%
        $adjusted['operating'] = $operating * 1.2;
    }
    
    if ($investing < -$operating * 0.5) {
        // Reduce investing outflows if they're too high relative to operating cash flow
        $adjusted['investing'] = $investing * 0.8;
    }
    
    if ($financing < 0 && $operating > 0) {
        // If we have positive operating cash flow but negative financing, adjust
        $adjusted['financing'] = $financing * 0.5;
    }
    
    return $adjusted;
}

// Generate forecasted data if requested
$forecasted_cash_flows = null;
$rec_forecasted_cash_flows = null;
if ($forecast_period) {
    $forecasted_cash_flows = [
        'operating' => calculateForecast($operating_total, $forecast_period),
        'investing' => calculateForecast($investing_total, $forecast_period),
        'financing' => calculateForecast($financing_total, $forecast_period)
    ];
    
    $forecasted_net_cash_flow = $forecasted_cash_flows['operating'] + $forecasted_cash_flows['investing'] + $forecasted_cash_flows['financing'];
    
    // Create recommended forecast if requested
    if ($apply_recommendations) {
        $rec_forecasted_cash_flows = applyRecommendations(
            $forecasted_cash_flows['operating'],
            $forecasted_cash_flows['investing'],
            $forecasted_cash_flows['financing']
        );
        $rec_forecasted_net_cash_flow = $rec_forecasted_cash_flows['operating'] + $rec_forecasted_cash_flows['investing'] + $rec_forecasted_cash_flows['financing'];
    }
}

// Generate recommendations using AI integration
$recommendations = generateAIRecommendations(
    $operating_total, 
    $investing_total, 
    $financing_total, 
    $net_cash_flow,
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
                'content' => 'You are a financial advisor specializing in cash flow analysis and recommendations. Provide clear, actionable advice in a structured format.'
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
function generateAIRecommendations($operating, $investing, $financing, $net_cash_flow, $period) {
    
    // Prepare prompt for AI
    $prompt = "Analyze this cash flow statement and provide recommendations in the following JSON format: 
    {
        'operating': {'title': 'Operating Cash Flow', 'value': 'amount', 'status': 'status text', 'action': 'recommendations'},
        'investing': {'title': 'Investing Activities', 'value': 'amount', 'status': 'status text', 'action': 'recommendations'},
        'financing': {'title': 'Financing Activities', 'value': 'amount', 'status': 'status text', 'action': 'recommendations'},
        'liquidity': {'title': 'Cash Flow Health', 'value': 'net_cash_flow', 'status': 'status text', 'action': 'recommendations'},
        'efficiency': {'title': 'Cash Flow Efficiency', 'value': '', 'status': 'status text', 'action': 'recommendations'}
    }
    
    Cash flow data for $period period:
    - Operating Cash Flow: $operating
    - Investing Activities: $investing
    - Financing Activities: $financing
    - Net Cash Flow: $net_cash_flow
    
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
    return generateRuleBasedRecommendations($operating, $investing, $financing, $net_cash_flow, $period);
}

// Fallback function for rule-based recommendations
function generateRuleBasedRecommendations($operating, $investing, $financing, $net_cash_flow, $period) {
    
    // Generate recommendations based on cash flow analysis
    $ai_recommendations = [
        'operating' => [
            'title' => 'Operating Cash Flow',
            'value' => fmt($operating),
            'status' => $operating > 0 ? 'Healthy operational performance' : 'Concerning operational cash outflow',
            'action' => generateOperatingRecommendations($operating, $period)
        ],
        'investing' => [
            'title' => 'Investing Activities',
            'value' => fmt($investing),
            'status' => $investing < 0 ? 'Normal investment in growth' : 'Asset divestment occurring',
            'action' => generateInvestingRecommendations($investing, $period)
        ],
        'financing' => [
            'title' => 'Financing Activities',
            'value' => fmt($financing),
            'status' => $financing > 0 ? 'Net financing inflow' : 'Net financing outflow',
            'action' => generateFinancingRecommendations($financing, $period)
        ],
        'liquidity' => [
            'title' => 'Cash Flow Health',
            'value' => fmt($net_cash_flow),
            'status' => $net_cash_flow > 0 ? 'Positive net cash position' : 'Negative net cash position',
            'action' => generateLiquidityRecommendations($net_cash_flow, $period)
        ],
        'efficiency' => [
            'title' => 'Cash Flow Efficiency',
            'value' => '',
            'status' => 'Working capital optimization',
            'action' => generateEfficiencyRecommendations($operating, $investing, $financing, $period)
        ]
    ];
    
    return $ai_recommendations;
}

// AI-simulated recommendation functions (fallback)
function generateOperatingRecommendations($operating, $period) {
    $recommendations = [];
    
    if ($operating < 0) {
        $recommendations[] = "Critical: Negative operating cash flow detected. Immediate actions needed:";
        $recommendations[] = "1. Accelerate accounts receivable collection";
        $recommendations[] = "2. Negotiate extended payment terms with suppliers";
        $recommendations[] = "3. Review and reduce non-essential operating expenses";
        $recommendations[] = "4. Optimize inventory management to reduce carrying costs";
    } else {
        $recommendations[] = "Positive operating cash flow maintained. Optimization opportunities:";
        $recommendations[] = "1. Implement early payment discounts for customers";
        $recommendations[] = "2. Evaluate supplier payment terms for better cash timing";
        $recommendations[] = "3. Consider temporary investment of excess operating cash";
    }
    
    return implode('<br>', $recommendations);
}

function generateInvestingRecommendations($investing, $period) {
    $recommendations = [];
    
    if ($investing < 0) {
        $recommendations[] = "Investment in long-term assets detected. Recommendations:";
        $recommendations[] = "1. Ensure ROI analysis on all capital expenditures";
        $recommendations[] = "2. Consider leasing vs. buying analysis for major equipment";
        $recommendations[] = "3. Stagger large investments to smooth cash outflows";
    } else {
        $recommendations[] = "Asset divestment occurring. Considerations:";
        $recommendations[] = "1. Evaluate if asset sales align with long-term strategy";
        $recommendations[] = "2. Reinvest proceeds into productive assets";
        $recommendations[] = "3. Consider tax implications of asset disposals";
    }
    
    return implode('<br>', $recommendations);
}

function generateFinancingRecommendations($financing, $period) {
    $recommendations = [];
    
    if ($financing > 0) {
        $recommendations[] = "Net financing inflow. Strategic considerations:";
        $recommendations[] = "1. Evaluate cost of capital for new financing";
        $recommendations[] = "2. Ensure debt levels remain sustainable";
        $recommendations[] = "3. Consider optimal capital structure mix";
    } else {
        $recommendations[] = "Net financing outflow (debt repayment/dividends). Analysis:";
        $recommendations[] = "1. Balance debt repayment with operational needs";
        $recommendations[] = "2. Evaluate dividend sustainability";
        $recommendations[] = "3. Maintain adequate financial flexibility";
    }
    
    return implode('<br>', $recommendations);
}

function generateLiquidityRecommendations($net_cash_flow, $period) {
    $recommendations = [];
    
    if ($net_cash_flow > 0) {
        $recommendations[] = "Strong overall cash position. Opportunities:";
        $recommendations[] = "1. Build cash reserves for future opportunities";
        $recommendations[] = "2. Consider strategic investments or acquisitions";
        $recommendations[] = "3. Evaluate shareholder returns if sustainable";
    } else {
        $recommendations[] = "Negative net cash flow requires attention:";
        $recommendations[] = "1. Implement immediate cash conservation measures";
        $recommendations[] = "2. Secure additional financing if needed";
        $recommendations[] = "3. Review and prioritize all cash outflows";
    }
    
    return implode('<br>', $recommendations);
}

function generateEfficiencyRecommendations($operating, $investing, $financing, $period) {
    $recommendations = [];
    
    $recommendations[] = "Cash flow efficiency enhancements:";
    $recommendations[] = "1. Implement cash flow forecasting and monitoring";
    $recommendations[] = "2. Optimize working capital management";
    $recommendations[] = "3. Align cash flow timing with business cycles";
    
    if ($operating < $investing + $financing) {
        $recommendations[] = "4. Focus on improving operating cash flow sustainability";
    }
    
    return implode('<br>', $recommendations);
}

// Back URL
$backUrl = (isset($_GET['admin']) && isset($_GET['user_id']))
    ? 'client_details.php?user_id=' . $_GET['user_id']
    : 'asset.php';

// If this is an AJAX request, return JSON data
if ($is_ajax && $forecast_period) {
    header('Content-Type: application/json');
    
    // Check if forecast data was generated successfully
    if (!$forecasted_cash_flows) {
        echo json_encode(['error' => 'Failed to generate forecast data']);
        exit;
    }
    
    $response = [
        'current' => [
            'operating' => $operating_total,
            'investing' => $investing_total,
            'financing' => $financing_total,
            'net_cash_flow' => $net_cash_flow
        ],
        'forecasted' => [
            'operating' => $forecasted_cash_flows['operating'],
            'investing' => $forecasted_cash_flows['investing'],
            'financing' => $forecasted_cash_flows['financing'],
            'net_cash_flow' => $forecasted_net_cash_flow
        ]
    ];
    
    if ($apply_recommendations && $rec_forecasted_cash_flows) {
        $response['recommended'] = [
            'operating' => $rec_forecasted_cash_flows['operating'],
            'investing' => $rec_forecasted_cash_flows['investing'],
            'financing' => $rec_forecasted_cash_flows['financing'],
            'net_cash_flow' => $rec_forecasted_net_cash_flow
        ];
    }
    
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cash Flow Statement</title>
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
        .cash-flow-columns {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
		
        .cash-flow-column {
            flex: 1;
            border: 1px solid #c4d8eb;
            border-radius: 8px;
            padding: 15px;
            height: 70vh;
            overflow-y: auto;
            background: white;
            box-shadow: 0 4px 8px rgba(26, 95, 156, 0.1);
            position: relative;
            display: flex;
            flex-direction: column;
        }
		
		.cash-flow-column-header {
			background-color: var(--primary-blue);
			color: white;
			text-align: center;
			padding: 10px;
			border-radius: 6px 6px 0 0;
			margin: -15px -15px 15px -15px;
			width: calc(100% + 30px);
		}

		.cash-flow-column-header h4 {
			margin: 0;
			font-weight: 600;
			font-size: 1.2rem;
		}
		
        /* Make the account lists scrollable but keep totals fixed at bottom */
        .cash-flow-column > .account-category,
        .cash-flow-column > ul {
            flex-shrink: 0; /* Prevent shrinking */
        }

        .cash-flow-column > ul:last-of-type {
            flex-grow: 1; /* Allow the last list to grow and push totals down */
            overflow-y: auto; /* Make accounts scrollable */
            margin-bottom: 0 !important; /* Remove bottom margin */
        }
		
	     .cash-flow-column > *:not(.grand-total) {
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
          overflow-y: auto;
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
        .cash-flow-column ul {
            margin-bottom: 10px !important;
        }

        .cash-flow-column ul:last-of-type {
            margin-bottom: 5px !important;
        }

        /* Ensure account lists are scrollable but totals remain fixed */
        .account-list-container {
            flex-grow: 1;
            overflow-y: auto;
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
            color: var(--primary-blue);
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

        .cash-flow-column {
            position: relative;
            overflow-y: auto;
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
            color: #000;
        }
        
        .document-subtitle {
            font-size: 16px;
            margin-bottom: 5px;
            color: #000;
        }
        
        .document-company {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #000;
        }
        
        .document-date {
            font-size: 14px;
            color: #000;
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
            color: #000;
        }
        
        .document-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
            color: #000;
        }
        
        .document-row.total {
            font-weight: bold;
            border-bottom: 2px solid #000;
            padding-top: 10px;
            color: #000;
        }
        
        .document-row.grand-total {
            font-weight: bold;
            font-size: 18px;
            border-top: 2px solid #000;
            padding-top: 15px;
            margin-top: 10px;
            background-color: #f0f0f0 !important;
            color: #000 !important;
        }
        
        .document-notes {
            margin-top: 30px;
            font-size: 14px;
            border-top: 1px solid #000;
            padding-top: 10px;
            color: #000;
        }
        
        .document-footer {
            text-align: center;
            margin-top: 40px;
            font-size: 12px;
            border-top: 1px solid #000;
            padding-top: 10px;
            color: #000;
        }

        /* Print styles for charts and interpretations */
        .print-chart-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8f8f8;
            border-radius: 10px;
            border: 1px solid #000;
        }
        
        .print-section-title {
            color: #000;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        
        .print-chart-container {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid #000;
        }
        
        .print-chart-placeholder {
            background: #f0f0f0;
            border: 2px dashed #000;
            border-radius: 8px;
            padding: 40px 20px;
            margin: 20px 0;
            text-align: center;
            color: #000;
        }
        
        .print-chart-placeholder i {
            font-size: 48px;
            color: #000;
            margin-bottom: 15px;
        }
        
        .print-chart-placeholder h4 {
            color: #000;
            margin-bottom: 10px;
            font-weight: bold;
        }
        
        .print-chart-placeholder p {
            color: #000;
            margin-bottom: 0;
        }
        
        .print-interpretation-section {
            background: #f8f8f8;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            border: 1px solid #000;
        }
        
        .print-interpretation-header {
            color: #000;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            font-weight: bold;
        }
        
        .print-interpretation-content {
            background: white;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #000;
            font-size: 1rem;
            line-height: 1.6;
            color: #000;
        }
        
        .print-interpretation-points {
            margin-top: 20px;
        }
        
        .print-interpretation-point {
            background: #f0f0f0;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 3px solid #000;
            color: #000;
        }
        
        .print-point-title {
            font-weight: 600;
            color: #000;
            margin-bottom: 8px;
        }
        
        /* Print styles */
        @media print {
            body {
                background: white !important;
                padding: 0;
                margin: 0;
                color: #000 !important;
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
                color: #000 !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            /* Hide interactive elements in print */
            .forecast-filter, .ai-section, .chart-section, .interpretation-section {
                display: none !important;
            }
            
            /* Ensure all text is black in print */
            .document-style * {
                color: #000 !important;
                background: white !important;
            }
            
            .document-style .print-chart-placeholder,
            .document-style .print-interpretation-point,
            .document-style .print-chart-container,
            .document-style .print-interpretation-section,
            .document-style .print-chart-section {
                background: #f8f8f8 !important;
                color: #000 !important;
                border-color: #000 !important;
            }
            
            .document-style .document-row.total,
            .document-style .document-row.grand-total {
                background: #e0e0e0 !important;
                color: #000 !important;
            }
            
            /* Specifically fix the grand total row */
            .document-style .document-row.grand-total {
                background: #d0d0d0 !important;
                color: #000 !important;
                font-weight: bold !important;
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

        /* Cash Flow Graph Interpretation Section */
        .interpretation-section {
            background: linear-gradient(to right, #f8f9fa, #e9f7fe);
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            border: 1px solid #c4d8eb;
        }
        
        .interpretation-card {
            border-left: 4px solid #4ECDC4;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .interpretation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        
        .interp-title {
            color: #2a5a80;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .interp-value {
            background-color: #4682B4;
            color: white;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.9rem;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .interp-status {
            font-weight: 600;
            margin-bottom: 10px;
            padding: 5px 10px;
            border-radius: 4px;
            background-color: #e9f7fe;
        }
        
        .interp-action {
            background-color: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 3px solid #4ECDC4;
        }

        /* NEW: Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--primary-blue);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .back-to-top:hover {
            background-color: var(--dark-blue);
            transform: translateX(-50%) translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.4);
        }

        /* UPDATED: Green badge for AI recommendations - same format as blue badge */
        .ai-recommendation-badge {
            background-color: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: 10px;
        }

        /* NEW: Thicker border for Recommended Position card */
        .recommended-card {
            border: 3px solid #28a745 !important;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05) !important;
        }

        /* REMOVED: .recommended-header styles to remove blue highlight */
    </style>
</head>
<body>

<!-- Document/Receipt Style (Hidden by default, shown when printing) -->
<div class="document-style">
    <div class="document-header">
        <div class="document-title">CASH FLOW STATEMENT</div>
        <div class="document-company"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-subtitle">For Period <?= date('F d, Y', strtotime($startDate)) ?> to <?= date('F d, Y', strtotime($endDate)) ?></div>
        <div class="document-date">(<?= ucfirst($filter) ?> View)</div>
    </div>
    
    <!-- Operating Activities Section -->
    <div class="document-section">
        <div class="document-section-title">OPERATING ACTIVITIES</div>
        
        <?php foreach ($operating_activities as $activity): ?>
            <div class="document-row">
                <div><?= htmlspecialchars($activity['name']) ?></div>
                <div><?= fmt($activity['balance']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="document-row total">
            <div>Net Cash from Operating Activities</div>
            <div><?= fmt($operating_total) ?></div>
        </div>
    </div>
    
    <!-- Investing Activities Section -->
    <div class="document-section">
        <div class="document-section-title">INVESTING ACTIVITIES</div>
        
        <?php foreach ($investing_activities as $activity): ?>
            <div class="document-row">
                <div><?= htmlspecialchars($activity['name']) ?></div>
                <div><?= fmt($activity['balance']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="document-row total">
            <div>Net Cash from Investing Activities</div>
            <div><?= fmt($investing_total) ?></div>
        </div>
    </div>
    
    <!-- Financing Activities Section -->
    <div class="document-section">
        <div class="document-section-title">FINANCING ACTIVITIES</div>
        
        <?php foreach ($financing_activities as $activity): ?>
            <div class="document-row">
                <div><?= htmlspecialchars($activity['name']) ?></div>
                <div><?= fmt($activity['balance']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="document-row total">
            <div>Net Cash from Financing Activities</div>
            <div><?= fmt($financing_total) ?></div>
        </div>
    </div>

    <!-- Net Cash Flow -->
    <div class="document-section">
        <div class="document-row grand-total">
            <div>NET INCREASE/DECREASE IN CASH</div>
            <div><?= fmt($net_cash_flow) ?></div>
        </div>
    </div>

    <!-- Financial Visualization Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Cash Flow Visualization</h4>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-pie"></i>
                <h4>Cash Flow Composition</h4>
                <p>Pie chart showing distribution of Operating, Investing, and Financing Activities</p>
                <p><strong>Operating Activities:</strong> <?= fmt($operating_total) ?></p>
                <p><strong>Investing Activities:</strong> <?= fmt($investing_total) ?></p>
                <p><strong>Financing Activities:</strong> <?= fmt($financing_total) ?></p>
            </div>
        </div>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-bar"></i>
                <h4>Cash Flow Performance</h4>
                <p>Bar chart comparing Operating, Investing, Financing Activities and Net Cash Flow</p>
                <p><strong>Operating Activities:</strong> <?= fmt($operating_total) ?></p>
                <p><strong>Investing Activities:</strong> <?= fmt($investing_total) ?></p>
                <p><strong>Financing Activities:</strong> <?= fmt($financing_total) ?></p>
                <p><strong>Net Cash Flow:</strong> <?= fmt($net_cash_flow) ?></p>
            </div>
        </div>
    </div>

    <!-- Graph Interpretation Section for Print -->
    <div class="print-interpretation-section">
        <h4 class="print-interpretation-header">
            <i class="fas fa-analytics me-2"></i>Cash Flow Graph Interpretation
        </h4>
        
        <div class="print-interpretation-content">
            <p>The cash flow statement provides valuable insights into your company's cash generation and usage patterns. Based on your current data:</p>
            
            <p><strong>Operating Activities:</strong> <?= $operating_total > 0 ? 'Positive operating cash flow indicates healthy core business operations.' : 'Negative operating cash flow may indicate challenges in core business profitability.' ?></p>
            
            <p><strong>Investing Activities:</strong> <?= $investing_total < 0 ? 'Negative investing cash flow typically represents capital expenditures for future growth.' : 'Positive investing cash flow may indicate asset sales or investment returns.' ?></p>
            
            <p><strong>Financing Activities:</strong> <?= $financing_total > 0 ? 'Positive financing cash flow shows net financing inflow from debt or equity.' : 'Negative financing cash flow indicates debt repayment or dividend distribution.' ?></p>
            
            <p><strong>Overall Cash Position:</strong> <?= $net_cash_flow > 0 ? 'Positive net cash flow indicates your business is generating more cash than it is using, which is a positive sign of financial health.' : 'Negative net cash flow suggests your business is using more cash than it is generating, which may require attention to cash management.' ?></p>
        </div>
        
        <div class="print-interpretation-points">
            <div class="print-interpretation-point">
                <div class="print-point-title">Cash Flow Composition Analysis</div>
                <p>The pie chart illustrates how your company's cash flows are distributed across different activities. A healthy cash flow typically shows strong positive operating cash flow, strategic investing outflows for growth, and balanced financing activities.</p>
            </div>
            
            <div class="print-interpretation-point">
                <div class="print-point-title">Cash Flow Performance</div>
                <p>The bar chart provides a clear visual comparison between your different cash flow activities. This helps assess your company's cash generation capability and spending patterns across operations, investments, and financing.</p>
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
        <p>This cash flow statement represents the cash movements of <?= htmlspecialchars($company_name) ?> for the period from <?= date('F d, Y', strtotime($startDate)) ?> to <?= date('F d, Y', strtotime($endDate)) ?>.</p>
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
        <h3 class="report-title">Cash Flow Statement</h3>
        <p class="report-subtitle">For Period <?= date('F d, Y', strtotime($startDate)) ?> to <?= date('F d, Y', strtotime($endDate)) ?> (<?= ucfirst($filter) ?> View)</p>
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

<!-- Cash Flow Statement Content in Three Columns -->
<div class="cash-flow-columns">
	<!-- Operating Activities Column -->
		<div class="cash-flow-column">
        <div class="cash-flow-column-header">
            <h4>Operating Activities</h4>
        </div>
        
        <ul class="list-group mb-4">
            <?php foreach ($operating_activities as $activity): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($activity['name']) ?>
                    <span class="text-end"><?= fmt($activity['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Net Cash from Operations
                <span class="text-end"><?= fmt($operating_total) ?></span>
            </li>
        </ul>
    </div>
    
    <!-- Investing Activities Column -->
    <div class="cash-flow-column">
        <div class="cash-flow-column-header">
            <h4>Investing Activities</h4>
        </div>
        
        <ul class="list-group mb-4">
            <?php foreach ($investing_activities as $activity): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($activity['name']) ?>
                    <span class="text-end"><?= fmt($activity['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Net Cash from Investing
                <span class="text-end"><?= fmt($investing_total) ?></span>
            </li>
        </ul>
    </div>
    
    <!-- Financing Activities Column -->
    <div class="cash-flow-column">
        <div class="cash-flow-column-header">
            <h4>Financing Activities</h4>
        </div>
        
        <ul class="list-group mb-4">
            <?php foreach ($financing_activities as $activity): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <?= htmlspecialchars($activity['name']) ?>
                    <span class="text-end"><?= fmt($activity['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between total-row">
                Net Cash from Financing
                <span class="text-end"><?= fmt($financing_total) ?></span>
            </li>
        </ul>

        <li class="list-group-item d-flex justify-content-between grand-total">
            Net Increase/Decrease in Cash
            <span class="text-end"><?= fmt($net_cash_flow) ?></span>
        </li>
    </div>
</div>

<!-- Financial Visualization Section -->
<div class="chart-section mt-4 no-print">
    <h4 class="section-title">Cash Flow Visualization</h4>
    
    <div class="row">
        <div class="col-md-6 chart-container" id="cashFlowPieChart"></div>
        <div class="col-md-6 chart-container" id="cashFlowBarChart"></div>
    </div>
</div>

<!-- Cash Flow Graph Interpretation Section -->
<div class="interpretation-section no-print">
    <h4><i class="fas fa-chart-pie me-2 text-primary"></i> Cash Flow Graph Interpretations</h4>
    <p class="text-muted">Analysis of your cash flow visualization:</p>
    
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="interpretation-card">
                <div class="interp-title">
                    Cash Flow Composition
                    <span class="ai-badge">Pie Chart</span>
                </div>
                <div class="interp-value">Activity Breakdown</div>
                <div class="interp-status <?= $operating_total > 0 ? 'status-strong' : 'status-concerning' ?>">
                    Operating: <?= $operating_total > 0 ? 'Positive' : 'Negative' ?>, 
                    Investing: <?= $investing_total < 0 ? 'Outflow' : 'Inflow' ?>, 
                    Financing: <?= $financing_total > 0 ? 'Inflow' : 'Outflow' ?>
                </div>
                <div class="interp-action">
                    The pie chart shows how your cash flow is distributed across different activities. 
                    <?php if ($operating_total > 0): ?>
                        <strong>Positive operating cash flow</strong> indicates healthy core business operations.
                    <?php else: ?>
                        <strong>Negative operating cash flow</strong> may indicate challenges in core business profitability.
                    <?php endif; ?>
                    <?php if ($investing_total < 0): ?>
                        <strong>Investing outflows</strong> typically represent capital expenditures for future growth.
                    <?php else: ?>
                        <strong>Investing inflows</strong> may indicate asset sales or investment returns.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-4">
            <div class="interpretation-card">
                <div class="interp-title">
                    Cash Flow Performance
                    <span class="ai-badge">Bar Chart</span>
                </div>
                <div class="interp-value">Net Cash Flow: <?= fmt($net_cash_flow) ?></div>
                <div class="interp-status <?= $net_cash_flow > 0 ? 'status-strong' : 'status-concerning' ?>">
                    <?= $net_cash_flow > 0 ? 'Positive Net Cash Position' : 'Negative Net Cash Position' ?>
                </div>
                <div class="interp-action">
                    The bar chart compares cash flows from different activities. 
                    <?php if ($net_cash_flow > 0): ?>
                        Your business is <strong>generating more cash than it's using</strong>, which is a positive sign of financial health.
                        This excess cash can be used for debt repayment, investments, or shareholder returns.
                    <?php else: ?>
                        Your business is <strong>using more cash than it's generating</strong>, which may require attention.
                        Consider reviewing operating efficiency, investment timing, or financing strategies.
                    <?php endif; ?>
                    The relationship between operating, investing, and financing activities shows your cash management strategy.
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-3 text-end">
        <small class="text-muted">Graph interpretations generated on <?= date('F j, Y \a\t g:i A') ?> based on current cash flow data</small>
    </div>
</div>

<!-- Forecast Filter Section -->
<div class="forecast-filter no-print">
    <h5 class="mb-3"><i class="fas fa-chart-line me-2"></i>Cash Flow Forecast</h5>
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
<div class="loading no-print" id="loadingIndicator">
    <div class="loading-spinner"></div>
    <p class="mt-2">Generating forecast...</p>
</div>

<!-- Forecast Results - Initially Blank -->
<div id="forecastResults" class="no-print"></div>

<!-- AI Recommendations Section -->
<div class="ai-section no-print">
    <h4><i class="fas fa-robot me-2 text-primary"></i> AI-Powered Cash Flow Recommendations</h4>
    <p class="text-muted">Our AI analysis of your cash flow provides these tailored recommendations:</p>
    
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
                    <div class="rec-status <?= strpos(strtolower($rec['status']), 'strong') !== false || strpos(strtolower($rec['status']), 'healthy') !== false || strpos(strtolower($rec['status']), 'positive') !== false ? 'status-strong' : (strpos(strtolower($rec['status']), 'concerning') !== false || strpos(strtolower($rec['status']), 'negative') !== false ? 'status-concerning' : '') ?>">
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

<!-- Back to Top Button -->
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

// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Back to Top Button Functionality
    const backToTopBtn = document.getElementById('backToTopBtn');
    
    // Show/hide back to top button based on scroll position
    window.addEventListener('scroll', function() {
        // Show button when user scrolls to bottom 100px of the page
        if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 100) {
            backToTopBtn.style.display = 'flex';
        } else {
            backToTopBtn.style.display = 'none';
        }
    });
    
    // Scroll to top when button is clicked
    backToTopBtn.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    // Only create charts if containers exist
    if (document.querySelector("#cashFlowPieChart")) {
        // Cash Flow Composition Pie Chart
        const pieOptions = {
            series: [
                <?= abs($operating_total) ?>, 
                <?= abs($investing_total) ?>, 
                <?= abs($financing_total) ?>
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
            labels: ['Operating Activities', 'Investing Activities', 'Financing Activities'],
            colors: ['#4682B4', '#5a96cf', '#4ECDC4'],
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
        new ApexCharts(document.querySelector("#cashFlowPieChart"), pieOptions).render();
    }

    if (document.querySelector("#cashFlowBarChart")) {
        // Cash Flow Activities Bar Chart
        const barOptions = {
            series: [{
                name: 'Cash Flow',
                data: [<?= $operating_total ?>, <?= $investing_total ?>, <?= $financing_total ?>, <?= $net_cash_flow ?>]
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
                categories: ['Operating', 'Investing', 'Financing', 'Net Cash Flow'],
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
            colors: ['#4682B4'],
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
        new ApexCharts(document.querySelector("#cashFlowBarChart"), barOptions).render();
    }
    
    // Handle forecast button click with AJAX
    const forecastButton = document.getElementById('forecastButton');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const forecastResults = document.getElementById('forecastResults');
    
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
        
        // Determine column classes based on whether recommendations are applied
        const columnClass = applyRecs ? 'col-md-4' : 'col-md-6';
        
        // Create HTML for forecast results
        let html = `
        <div class="forecast-section">
            <h4 class="forecast-header">
                <i class="fas fa-crystal-ball"></i> Cash Flow Forecast
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
        
        // Add third timeline point only if recommendations are applied
        if (applyRecs) {
            html += `
                <div class="timeline-point">
                    <div class="timeline-marker active-marker">3</div>
                    <div class="timeline-label">Recommended</div>
                </div>`;
        }
        
        html += `
            </div>
            
            <div class="row">
                <!-- Current Cash Flow Position -->
                <div class="${columnClass}">
                    <div class="scenario-card">
                        <div class="scenario-header">
                            <i class="fas fa-file-invoice-dollar"></i> Current Position
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Operating Activities
                                <span data-current="operating">${formatCurrency(data.current.operating)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Investing Activities
                                <span data-current="investing">${formatCurrency(data.current.investing)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Financing Activities
                                <span data-current="financing">${formatCurrency(data.current.financing)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Net Cash Flow
                                <span data-current="net_cash_flow">${formatCurrency(data.current.net_cash_flow)}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Projected Cash Flow Position -->
                <div class="${columnClass}">
                    <div class="scenario-card">
                        <div class="scenario-header">
                            <i class="fas fa-chart-line"></i> Projected Position
                            <span class="recommendation-badge">Forecast</span>
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Operating Activities
                                <span data-forecasted="operating">${formatCurrency(data.forecasted.operating)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Investing Activities
                                <span data-forecasted="investing">${formatCurrency(data.forecasted.investing)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Financing Activities
                                <span data-forecasted="financing">${formatCurrency(data.forecasted.financing)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Net Cash Flow
                                <span data-forecasted="net_cash_flow">${formatCurrency(data.forecasted.net_cash_flow)}</span>
                            </li>
                        </ul>
                        <div class="mt-3">
                            <p class="mb-1"><strong>Projection Basis:</strong></p>
                            <p class="small">Based on current growth trends (5% base + period adjustment)</p>
                        </div>
                    </div>
                </div>`;
        
        // Add recommended section only if available and checkbox is checked
        if (applyRecs && data.recommended) {
            html += `
                <!-- Recommended Cash Flow Position -->
                <div class="${columnClass}">
                    <div class="scenario-card recommended-card">
                        <div class="scenario-header">
                            <i class="fas fa-lightbulb"></i> Recommended Position
                            <span class="recommendation-badge">AI Optimized</span>
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Operating Activities
                                <span data-recommended="operating">${formatCurrency(data.recommended.operating)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Investing Activities
                                <span data-recommended="investing">${formatCurrency(data.recommended.investing)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Financing Activities
                                <span data-recommended="financing">${formatCurrency(data.recommended.financing)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Net Cash Flow
                                <span data-recommended="net_cash_flow">${formatCurrency(data.recommended.net_cash_flow)}</span>
                            </li>
                        </ul>
                        <div class="mt-3">
                            <p class="mb-1"><strong>Optimization Applied:</strong></p>
                            <p class="small">AI-powered recommendations for cash flow management and efficiency</p>
                        </div>
                    </div>
                </div>`;
        }
        
        html += `</div></div>`;
        
        // Update the forecast results section
        forecastResults.innerHTML = html;
        
        // Scroll to forecast results
        forecastResults.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    
    // Hide loading indicator after page load (in case it was left visible)
    loadingIndicator.style.display = 'none';
    if (forecastButton) forecastButton.disabled = false;
});
</script>
</body>
</html>
