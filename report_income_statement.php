<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require 'db_connection.php';

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
        $period_end = date('Y-m-t', strtotime($period_start));
    } elseif ($filter === 'quarterly') {
        $month = date('n');
        $year = date('Y');
        $currentQuarter = ceil($month / 3);
        $quarter = $currentQuarter - $i;
        
        // Adjust year if quarter goes negative
        $yearAdjustment = 0;
        while ($quarter < 1) {
            $quarter += 4;
            $yearAdjustment--;
        }
        
        $targetYear = $year + $yearAdjustment;
        $quarterStartMonth = ($quarter - 1) * 3 + 1;
        $period_start = $targetYear . '-' . str_pad($quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01';
        $period_end = date('Y-m-t', strtotime($targetYear . '-' . str_pad($quarterStartMonth + 2, 2, '0', STR_PAD_LEFT) . '-01'));
        $period_label = 'Q' . $quarter . ' ' . $targetYear;
    } else {
        $year = date('Y') - $i;
        $period_start = $year . '-01-01';
        $period_label = $year;
        $period_end = $year . '-12-31';
    }
    
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

// Current period data
$stmt = $pdo->prepare("SELECT a.name, a.type,
        SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END) as balance
    FROM `$accountsTable` a
    JOIN `$transactionsTable` t ON a.name = t.account_name
    WHERE a.type IN ('Revenue', 'Expense') AND t.date BETWEEN ? AND ?
    GROUP BY a.name, a.type");
$stmt->execute([$startDate, $endDate]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenues = [];
$expenses = [];
$total_revenue = 0;
$total_expense = 0;

foreach ($accounts as $account) {
    if ($account['type'] === 'Revenue') {
        $revenues[] = $account;
        $total_revenue += abs($account['balance']);
    } else {
        $expenses[] = $account;
        $total_expense += abs($account['balance']);
    }
}

$net_income = $total_revenue - $total_expense;

// Calculate profit margin
$profit_margin = $total_revenue > 0 ? ($net_income / $total_revenue) * 100 : 0;

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

// Function to apply recommendations - UPDATED with AI-powered logic
function applyIncomeRecommendations($revenues, $expenses, $period) {
    $adjusted_revenues = $revenues;
    $adjusted_expenses = $expenses;
    
    // Enhanced revenue enhancement strategies based on AI analysis
    $revenue_increase = 0.15; // 15% potential increase from AI recommendations
    
    foreach ($adjusted_revenues as &$revenue) {
        $revenue['balance'] = calculateForecast($revenue['balance'], $period) * (1 + $revenue_increase);
    }
    
    // Enhanced expense optimization with AI insights
    $expense_reduction = 0.12; // 12% potential reduction
    
    foreach ($adjusted_expenses as &$expense) {
        // Strategic expense reduction based on category analysis
        $reduction = (stripos($expense['name'], 'cost') !== false || 
                     stripos($expense['name'], 'cogs') !== false) ? 0.05 : $expense_reduction;
        $expense['balance'] = calculateForecast($expense['balance'], $period) * (1 - $reduction);
    }
    
    return ['revenues' => $adjusted_revenues, 'expenses' => $adjusted_expenses];
}

// Generate forecasted data if requested
$forecasted_data = null;
$rec_forecasted_data = null;
if ($forecast_period) {
    // Apply growth to all accounts
    $forecasted_revenues = [];
    $forecasted_expenses = [];
    
    foreach ($revenues as $revenue) {
        $revenue['balance'] = calculateForecast($revenue['balance'], $forecast_period);
        $forecasted_revenues[] = $revenue;
    }
    
    foreach ($expenses as $expense) {
        $expense['balance'] = calculateForecast($expense['balance'], $forecast_period);
        $forecasted_expenses[] = $expense;
    }
    
    $forecasted_data = [
        'revenues' => $forecasted_revenues,
        'expenses' => $forecasted_expenses,
        'total_revenue' => array_sum(array_column($forecasted_revenues, 'balance')),
        'total_expense' => array_sum(array_column($forecasted_expenses, 'balance'))
    ];
    
    $forecasted_data['net_income'] = $forecasted_data['total_revenue'] - $forecasted_data['total_expense'];
    $forecasted_data['profit_margin'] = $forecasted_data['total_revenue'] > 0 ? 
        ($forecasted_data['net_income'] / $forecasted_data['total_revenue']) * 100 : 0;
    
    // Create recommended forecast if requested
    if ($apply_recommendations) {
        $rec_data = applyIncomeRecommendations($revenues, $expenses, $forecast_period);
        $rec_forecasted_data = [
            'revenues' => $rec_data['revenues'],
            'expenses' => $rec_data['expenses'],
            'total_revenue' => array_sum(array_column($rec_data['revenues'], 'balance')),
            'total_expense' => array_sum(array_column($rec_data['expenses'], 'balance'))
        ];
        
        $rec_forecasted_data['net_income'] = $rec_forecasted_data['total_revenue'] - $rec_forecasted_data['total_expense'];
        $rec_forecasted_data['profit_margin'] = $rec_forecasted_data['total_revenue'] > 0 ? 
            ($rec_forecasted_data['net_income'] / $rec_forecasted_data['total_revenue']) * 100 : 0;
    }
}

// NEW: Function to call OpenAI API for AI-powered recommendations (from cash flow)
function callOpenAIAPI($prompt) {
    // Your OpenAI API key - store this securely in your environment variables
    $api_key = getenv('OPENAI_API_KEY') ?: 'your-openai-api-key-here';
    
    $url = 'https://api.openai.com/v1/chat/completions';
    
    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a financial advisor specializing in income statement analysis and recommendations. Provide clear, actionable advice in a structured format.'
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

// NEW: Function to generate AI-powered recommendations (from cash flow)
function generateAIRecommendations($total_revenue, $total_expense, $net_income, $profit_margin, $period) {
    
    // Prepare prompt for AI
    $prompt = "Analyze this income statement and provide recommendations in the following JSON format: 
    {
        'profit_margin': {'title': 'Profit Margin Improvement', 'value': 'value', 'status': 'status text', 'action': 'recommendations'},
        'revenue_growth': {'title': 'Revenue Growth Opportunities', 'value': 'value', 'status': 'status text', 'action': 'recommendations'},
        'expense_management': {'title': 'Expense Optimization', 'value': 'value', 'status': 'status text', 'action': 'recommendations'},
        'operational_efficiency': {'title': 'Operational Efficiency', 'value': 'value', 'status': 'status text', 'action': 'recommendations'},
        'financial_health': {'title': 'Overall Financial Health', 'value': 'net_income', 'status': 'status text', 'action': 'recommendations'}
    }
    
    Income statement data for $period period:
    - Total Revenue: $total_revenue
    - Total Expenses: $total_expense
    - Net Income: $net_income
    - Profit Margin: $profit_margin%
    
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
    return generateRuleBasedRecommendations($total_revenue, $total_expense, $net_income, $profit_margin, $period);
}

// Fallback function for rule-based recommendations
function generateRuleBasedRecommendations($total_revenue, $total_expense, $net_income, $profit_margin, $period) {
    
    // Generate recommendations based on income statement analysis
    $ai_recommendations = [
        'profit_margin' => [
            'title' => 'Profit Margin Improvement',
            'value' => number_format($profit_margin, 2) . '%',
            'status' => $profit_margin > 15 ? 'Strong profitability' : ($profit_margin > 5 ? 'Moderate profitability' : 'Concerning profitability'),
            'action' => generateProfitMarginRecommendations($profit_margin, $period)
        ],
        'revenue_growth' => [
            'title' => 'Revenue Growth Opportunities',
            'value' => '₱ ' . number_format($total_revenue, 2),
            'status' => $total_revenue > 0 ? 'Revenue generation active' : 'Need revenue growth strategies',
            'action' => generateRevenueRecommendations($total_revenue, $period)
        ],
        'expense_management' => [
            'title' => 'Expense Optimization',
            'value' => '₱ ' . number_format($total_expense, 2),
            'status' => $total_revenue > 0 && ($total_expense / $total_revenue) < 0.7 ? 'Expenses well managed' : 'High expense ratio detected',
            'action' => generateExpenseRecommendations($total_revenue > 0 ? ($total_expense / $total_revenue) * 100 : 0, $period)
        ],
        'operational_efficiency' => [
            'title' => 'Operational Efficiency',
            'value' => number_format($total_revenue > 0 ? ($total_expense / $total_revenue) * 100 : 0, 2) . '% expense ratio',
            'status' => 'Process optimization opportunities',
            'action' => generateEfficiencyRecommendations($total_revenue > 0 ? ($total_expense / $total_revenue) * 100 : 0, $period)
        ],
        'financial_health' => [
            'title' => 'Overall Financial Health',
            'value' => '₱ ' . number_format($net_income, 2),
            'status' => $net_income > 0 ? 'Positive financial performance' : 'Negative financial performance',
            'action' => generateFinancialHealthRecommendations($net_income, $profit_margin, $period)
        ]
    ];
    
    return $ai_recommendations;
}

// AI-simulated recommendation functions (fallback)
function generateProfitMarginRecommendations($profit_margin, $period) {
    $recommendations = [];
    
    if ($profit_margin < 5) {
        $recommendations[] = "Critical: Profit margin is very low. Immediate actions needed:";
        $recommendations[] = "1. Review pricing strategy for all products/services";
        $recommendations[] = "2. Identify and eliminate unprofitable products/services";
        $recommendations[] = "3. Implement aggressive cost reduction measures";
        $recommendations[] = "4. Focus on high-margin product lines";
    } elseif ($profit_margin < 15) {
        $recommendations[] = "Moderate: Profit margin has room for improvement:";
        $recommendations[] = "1. Analyze product/service line profitability";
        $recommendations[] = "2. Implement targeted price increases on high-value items";
        $recommendations[] = "3. Optimize operational efficiency to reduce costs";
        $recommendations[] = "4. Explore upselling and cross-selling opportunities";
    } else {
        $recommendations[] = "Strong: Maintain and enhance profitability:";
        $recommendations[] = "1. Continue monitoring product/service profitability";
        $recommendations[] = "2. Invest in growth initiatives with similar margin profiles";
        $recommendations[] = "3. Consider strategic acquisitions or market expansion";
        $recommendations[] = "4. Reinvest profits into innovation and R&D";
    }
    
    // Add period-specific advice
    if ($period == 'monthly') {
        $recommendations[] = "5. Implement monthly margin analysis by product/service";
    } elseif ($period == 'quarterly') {
        $recommendations[] = "5. Conduct quarterly pricing strategy reviews";
    }
    
    return implode('<br>', $recommendations);
}

function generateRevenueRecommendations($total_revenue, $period) {
    $recommendations = [];
    
    if ($total_revenue <= 0) {
        $recommendations[] = "Critical: No revenue generated. Immediate actions needed:";
        $recommendations[] = "1. Develop and implement a sales and marketing plan";
        $recommendations[] = "2. Identify target market and customer needs";
        $recommendations[] = "3. Create minimum viable product/service offering";
        $recommendations[] = "4. Establish pricing and distribution channels";
    } else {
        $recommendations[] = "Revenue enhancement strategies:";
        $recommendations[] = "1. Develop new customer acquisition strategies";
        $recommendations[] = "2. Expand to new markets or product lines";
        $recommendations[] = "3. Implement upselling and cross-selling techniques";
        $recommendations[] = "4. Improve customer retention and loyalty programs";
        $recommendations[] = "5. Leverage digital marketing and social media";
    }
    
    return implode('<br>', $recommendations);
}

function generateExpenseRecommendations($expense_ratio, $period) {
    $recommendations = [];
    
    if ($expense_ratio > 80) {
        $recommendations[] = "Critical: Expenses are too high relative to revenue:";
        $recommendations[] = "1. Conduct thorough expense audit and categorization";
        $recommendations[] = "2. Implement immediate cost reduction measures";
        $recommendations[] = "3. Renegotiate contracts with suppliers and vendors";
        $recommendations[] = "4. Eliminate non-essential expenses immediately";
    } elseif ($expense_ratio > 70) {
        $recommendations[] = "High: Expense ratio needs improvement:";
        $recommendations[] = "1. Identify and eliminate unnecessary expenses";
        $recommendations[] = "2. Negotiate with suppliers for better rates";
        $recommendations[] = "3. Implement energy-saving and waste-reduction initiatives";
        $recommendations[] = "4. Automate processes to reduce labor costs";
    } else {
        $recommendations[] = "Controlled: Expenses are well managed:";
        $recommendations[] = "1. Continue monitoring expense categories regularly";
        $recommendations[] = "2. Look for incremental efficiency improvements";
        $recommendations[] = "3. Invest in technology that reduces long-term costs";
        $recommendations[] = "4. Benchmark against industry standards";
    }
    
    return implode('<br>', $recommendations);
}

function generateEfficiencyRecommendations($expense_ratio, $period) {
    $recommendations = [];
    
    $recommendations[] = "Operational efficiency improvements:";
    $recommendations[] = "1. Automate repetitive tasks to reduce labor costs";
    $recommendations[] = "2. Implement lean management principles";
    $recommendations[] = "3. Cross-train employees for better resource allocation";
    $recommendations[] = "4. Streamline business processes and workflows";
    
    if ($expense_ratio > 70) {
        $recommendations[] = "5. Conduct process mapping to identify inefficiencies";
        $recommendations[] = "6. Implement performance metrics for all key processes";
    }
    
    if ($period == 'monthly') {
        $recommendations[] = "7. Track efficiency metrics monthly";
    }
    
    return implode('<br>', $recommendations);
}

function generateFinancialHealthRecommendations($net_income, $profit_margin, $period) {
    $recommendations = [];
    
    if ($net_income > 0) {
        $recommendations[] = "Strong financial health maintained. Opportunities:";
        $recommendations[] = "1. Reinvest profits into business growth initiatives";
        $recommendations[] = "2. Build cash reserves for future opportunities";
        $recommendations[] = "3. Consider strategic investments or acquisitions";
        $recommendations[] = "4. Evaluate shareholder returns if sustainable";
    } else {
        $recommendations[] = "Financial challenges require attention:";
        $recommendations[] = "1. Implement immediate cost conservation measures";
        $recommendations[] = "2. Secure additional financing if needed";
        $recommendations[] = "3. Review and prioritize all expenses";
        $recommendations[] = "4. Focus on high-margin revenue streams";
    }
    
    // Add margin-specific advice
    if ($profit_margin < 5) {
        $recommendations[] = "5. Urgently address low profit margin through pricing or cost control";
    }
    
    return implode('<br>', $recommendations);
}

// Generate recommendations using AI integration
$recommendations = generateAIRecommendations(
    $total_revenue, 
    $total_expense, 
    $net_income, 
    $profit_margin,
    $filter
);

// Generate chart interpretations - FIXED: Added $expenses parameter and division safety checks
function generateChartInterpretations($total_revenue, $total_expense, $net_income, $profit_margin, $revenue_data, $expense_data, $net_income_data, $periods, $expenses) {
    $interpretations = [];
    
    // Revenue vs Expense interpretation
    $revenue_expense_ratio = $total_revenue > 0 ? ($total_expense / $total_revenue) * 100 : 0;
    if ($revenue_expense_ratio < 70) {
        $revenue_expense_status = "Healthy revenue-to-expense ratio";
        $revenue_expense_insight = "Your expenses represent " . number_format($revenue_expense_ratio, 1) . "% of your revenue, indicating efficient cost management.";
    } elseif ($revenue_expense_ratio < 90) {
        $revenue_expense_status = "Moderate revenue-to-expense ratio";
        $revenue_expense_insight = "Your expenses represent " . number_format($revenue_expense_ratio, 1) . "% of your revenue. Consider optimizing costs to improve profitability.";
    } else {
        $revenue_expense_status = "High revenue-to-expense ratio";
        $revenue_expense_insight = "Your expenses represent " . number_format($revenue_expense_ratio, 1) . "% of your revenue, which may impact long-term sustainability.";
    }
    
    $interpretations[] = [
        'title' => 'Revenue vs Expense Analysis',
        'chart' => 'Revenue vs Expense Chart',
        'status' => $revenue_expense_status,
        'insight' => $revenue_expense_insight
    ];
    
    // Profit Margin interpretation
    if ($profit_margin > 20) {
        $margin_status = "Excellent profitability";
        $margin_insight = "Your profit margin of " . number_format($profit_margin, 1) . "% indicates strong financial health and efficient operations.";
    } elseif ($profit_margin > 10) {
        $margin_status = "Good profitability";
        $margin_insight = "Your profit margin of " . number_format($profit_margin, 1) . "% is healthy. Consider strategies to further improve efficiency.";
    } elseif ($profit_margin > 0) {
        $margin_status = "Moderate profitability";
        $margin_insight = "Your profit margin of " . number_format($profit_margin, 1) . "% leaves room for improvement. Focus on cost optimization and revenue growth.";
    } else {
        $margin_status = "Negative profitability";
        $margin_insight = "Your negative profit margin indicates financial challenges. Immediate action is needed to reduce costs or increase revenue.";
    }
    
    $interpretations[] = [
        'title' => 'Profit Margin Assessment',
        'chart' => 'Profit Margin Gauge',
        'status' => $margin_status,
        'insight' => $margin_insight
    ];
    
    // Waterfall Chart interpretation - FIXED: Check if $expenses is set and not empty
    $gross_profit = $total_revenue;
    $operating_expenses = $total_expense;
    
    if (is_array($expenses) && !empty($expenses)) {
        $gross_profit = $total_revenue - array_reduce($expenses, function($carry, $item) { 
            return (stripos($item['name'], 'cost') !== false || stripos($item['name'], 'cogs') !== false) ? 
                $carry + abs($item['balance']) : $carry; 
        }, 0);
        
        $operating_expenses = array_reduce($expenses, function($carry, $item) { 
            return (stripos($item['name'], 'cost') === false && stripos($item['name'], 'cogs') === false) ? 
                $carry + abs($item['balance']) : $carry; 
        }, 0);
    }
    
    $gross_margin = $total_revenue > 0 ? ($gross_profit / $total_revenue) * 100 : 0;
    $operating_ratio = $total_revenue > 0 ? ($operating_expenses / $total_revenue) * 100 : 0;
    
    $waterfall_insight = "The waterfall chart shows your revenue transformation: Starting with ₱" . number_format($total_revenue, 0) . " revenue, " . 
                        number_format($gross_margin, 1) . "% remains as gross profit after direct costs, then " . 
                        number_format($operating_ratio, 1) . "% goes to operating expenses, leaving " . 
                        number_format($profit_margin, 1) . "% as net income.";
    
    $interpretations[] = [
        'title' => 'Income Waterfall Analysis',
        'chart' => 'Waterfall Chart',
        'status' => 'Revenue Transformation',
        'insight' => $waterfall_insight
    ];
    
    // Trend Analysis interpretation - FIXED: Added division safety checks
    $revenue_growth = 0;
    $expense_growth = 0;
    $income_growth = 0;
    
    if (count($revenue_data) > 1 && $revenue_data[0] != 0) {
        $revenue_growth = (($revenue_data[count($revenue_data)-1] - $revenue_data[0]) / $revenue_data[0]) * 100;
    }
    
    if (count($expense_data) > 1 && $expense_data[0] != 0) {
        $expense_growth = (($expense_data[count($expense_data)-1] - $expense_data[0]) / $expense_data[0]) * 100;
    }
    
    if (count($net_income_data) > 1 && $net_income_data[0] != 0) {
        $income_growth = (($net_income_data[count($net_income_data)-1] - $net_income_data[0]) / abs($net_income_data[0])) * 100;
    }
    
    if ($revenue_growth > 10) {
        $trend_status = "Strong growth trajectory";
        $trend_insight = "Your revenue has grown " . number_format($revenue_growth, 1) . "% over the period, indicating excellent business expansion.";
    } elseif ($revenue_growth > 0) {
        $trend_status = "Moderate growth";
        $trend_insight = "Your revenue has grown " . number_format($revenue_growth, 1) . "% over the period. Consider strategies to accelerate growth.";
    } else {
        $trend_status = "Revenue challenges";
        $trend_insight = "Your revenue has declined " . number_format(abs($revenue_growth), 1) . "% over the period. Focus on revenue generation strategies.";
    }
    
    // Add expense trend analysis
    if ($expense_growth > $revenue_growth) {
        $trend_insight .= " Expenses are growing faster than revenue (" . number_format($expense_growth, 1) . "% vs " . number_format($revenue_growth, 1) . "%), which may pressure profitability.";
    } else {
        $trend_insight .= " Expense growth (" . number_format($expense_growth, 1) . "%) is controlled relative to revenue growth.";
    }
    
    $interpretations[] = [
        'title' => 'Financial Trend Analysis',
        'chart' => 'Trend Chart',
        'status' => $trend_status,
        'insight' => $trend_insight
    ];
    
    return $interpretations;
}

// Generate chart interpretations - FIXED: Added $expenses parameter
$chart_interpretations = generateChartInterpretations(
    $total_revenue, 
    $total_expense, 
    $net_income, 
    $profit_margin,
    $revenue_data,
    $expense_data,
    $net_income_data,
    $periods,
    $expenses  // Now passing the expenses array
);

function fmt($amt) {
    if ($amt == 0 || $amt == null) return '';
    return '&#8369; ' . ($amt < 0 ? '(' . number_format(abs($amt), 2) . ')' : number_format($amt, 2));
}

// Back URL
$backUrl = (isset($_GET['admin']) && isset($_GET['user_id']))
    ? 'client_details.php?user_id=' . $_GET['user_id']
    : 'asset.php';

// If this is an AJAX request, return JSON data
if ($is_ajax && $forecast_period) {
    header('Content-Type: application/json');
    
    // Check if forecast data was generated successfully
    if (!$forecasted_data) {
        echo json_encode(['error' => 'Failed to generate forecast data']);
        exit;
    }
    
    $response = [
        'current' => [
            'total_revenue' => $total_revenue,
            'total_expense' => $total_expense,
            'net_income' => $net_income,
            'profit_margin' => $profit_margin
        ],
        'forecasted' => [
            'total_revenue' => $forecasted_data['total_revenue'],
            'total_expense' => $forecasted_data['total_expense'],
            'net_income' => $forecasted_data['net_income'],
            'profit_margin' => $forecasted_data['profit_margin']
        ]
    ];
    
    if ($apply_recommendations && $rec_forecasted_data) {
        $response['recommended'] = [
            'total_revenue' => $rec_forecasted_data['total_revenue'],
            'total_expense' => $rec_forecasted_data['total_expense'],
            'net_income' => $rec_forecasted_data['net_income'],
            'profit_margin' => $rec_forecasted_data['profit_margin']
        ];
    }
    
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Income Statement</title>
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

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff;
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .report-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin: 20px auto;
            max-width: 97%;
            width: 97%;
            position: relative;
            border: 1px solid #e1e8ed;
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

        /* Three-column layout */
        .income-statement-columns {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .income-column {
            flex: 1;
            border: 1px solid #c4d8eb;
            border-radius: 8px;
            padding: 15px;
            height: 70vh;
            overflow-y: auto;
            background: white;
            box-shadow: 0 4px 8px rgba(26, 95, 156, 0.1);
        }

        .income-column-header {
            background-color: var(--primary-blue);
            color: white;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Item styling */
        .account-category {
            background-color: var(--light-blue);
            padding: 8px 12px;
            border-radius: 6px;
            font-weight: 600;
            margin: 15px 0 10px;
            border-left: 4px solid var(--secondary-blue);
            color: var(--dark-blue);
        }

        .list-group-item {
            border-radius: 6px !important;
            margin-bottom: 5px;
            border-left: 3px solid transparent;
            border-color: #e1e8ed;
        }

        .list-group-item:hover {
            background-color: #f5f9fd;
            border-left-color: var(--primary-blue);
        }

        .total-row {
            font-weight: 600;
            background-color: #e1ecf5;
        }

        .net-income {
            font-weight: 700;
            background-color: var(--primary-blue);
            color: white;
            margin-top: 15px;
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
        
        .text-end {
            text-align: right;
        }
        
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
        
        .scenario-header {
            color: var(--dark-blue);
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
        
        /* Added styles for forecast filter */
        .forecast-filter {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            border: 1px solid #e1e8ed;
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

        /* NEW: Chart Interpretation Section Styles */
        .interpretation-section {
            background: linear-gradient(to right, #f0f8ff, #e6f3ff);
            border-radius: 10px;
            padding: 20px;
            margin-top: 30px;
            border: 1px solid #c4d8eb;
        }
        
        .interpretation-card {
            border-left: 4px solid #5a96cf;
            background-color: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }
        
        .interpretation-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }
        
        .interp-title {
            color: var(--dark-blue);
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }
        
        .interp-chart {
            background-color: var(--light-blue);
            color: var(--dark-blue);
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
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
        
        .interp-insight {
            background-color: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            border-left: 3px solid #5a96cf;
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
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            min-height: 370px; /* Ensure containers have height */
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
        
        /* NEW: Document/Receipt Style for Printing - FROM BALANCE SHEET */
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

        /* Print styles for charts and interpretations - FROM BALANCE SHEET */
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
            .forecast-filter, .ai-section, .chart-section, .interpretation-section {
                display: none !important;
            }
        }

        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .income-column {
            position: relative;
            overflow-y: auto;
            max-height: 70vh;
        }

        .account-category {
            position: sticky;
            top: 60px; /* Adjust based on header height */
            z-index: 90;
            background-color: var(--light-blue);
        }

        .income-column {
            position: relative;
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
        } 
        
        .ai-badge {
            background: linear-gradient(135deg, #6e8efb, #a777e3);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 8px;
        }
        
        .chart-error {
            color: #e74c3c;
            padding: 10px;
            text-align: center;
            background-color: #fadbd8;
            border-radius: 5px;
            margin: 10px 0;
        }

        /* NEW: Button container styles for side-by-side buttons */
        .button-container {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* NEW: Forecast results hidden by default */
        #forecastResults {
            display: none;
        }
        
        /* NEW: Loading indicator */
        #loadingIndicator {
            display: none;
            text-align: center;
            padding: 20px;
        }

        /* NEW: Back to Top Button - Arrow Only */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(26, 95, 156, 0.3);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .back-to-top:hover {
            background: var(--dark-blue);
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 6px 20px rgba(26, 95, 156, 0.4);
        }

        .back-to-top.show {
            display: flex;
        }

        /* NEW: Green outline for AI-Optimized Financials card FRAME ONLY - THICKER */
        .ai-optimized-card {
            border: 4px solid #28a745 !important;
        }

        /* NEW: Green tag for AI recommendations */
        .ai-recommendation-tag {
            background-color: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: 10px;
            font-weight: 500;
        }
    </style>
</head>
<body>

<!-- NEW: Document/Receipt Style (Hidden by default, shown when printing) - FROM BALANCE SHEET -->
<div class="document-style">
    <div class="document-header">
        <div class="document-title">INCOME STATEMENT</div>
        <div class="document-company"><?= htmlspecialchars($company_name) ?></div>
        <div class="document-subtitle">For the period ended <?= date('F d, Y') ?></div>
        <div class="document-date">(<?= ucfirst($filter) ?> View)</div>
    </div>
    
    <!-- Revenues Section -->
    <div class="document-section">
        <div class="document-section-title">REVENUES</div>
        
        <?php foreach ($revenues as $account): ?>
            <div class="document-row">
                <div><?= htmlspecialchars($account['name']) ?></div>
                <div><?= fmt($account['balance']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="document-row total">
            <div>Total Revenues</div>
            <div><?= fmt($total_revenue) ?></div>
        </div>
    </div>
    
    <!-- Expenses Section -->
    <div class="document-section">
        <div class="document-section-title">EXPENSES</div>
        
        <?php foreach ($expenses as $account): ?>
            <div class="document-row">
                <div><?= htmlspecialchars($account['name']) ?></div>
                <div><?= fmt($account['balance']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="document-row total">
            <div>Total Expenses</div>
            <div><?= fmt($total_expense) ?></div>
        </div>
    </div>
    
    <!-- Profitability Section -->
    <div class="document-section">
        <div class="document-section-title">PROFITABILITY</div>
        
        <div class="document-row">
            <div>Total Revenues</div>
            <div><?= fmt($total_revenue) ?></div>
        </div>
        <div class="document-row">
            <div>Total Expenses</div>
            <div><?= fmt($total_expense) ?></div>
        </div>
        <div class="document-row grand-total">
            <div>NET INCOME</div>
            <div><?= fmt($net_income) ?></div>
        </div>
        <div class="document-row total">
            <div>PROFIT MARGIN</div>
            <div><?= number_format($profit_margin, 2) ?>%</div>
        </div>
    </div>

    <!-- Financial Visualization Section for Print -->
    <div class="print-chart-section">
        <h4 class="print-section-title">Financial Performance Visualization</h4>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-bar"></i>
                <h4>Revenue vs Expense Analysis</h4>
                <p>Bar chart comparing revenue, expenses, and net income</p>
                <p><strong>Total Revenue:</strong> <?= fmt($total_revenue) ?></p>
                <p><strong>Total Expenses:</strong> <?= fmt($total_expense) ?></p>
                <p><strong>Net Income:</strong> <?= fmt($net_income) ?></p>
            </div>
        </div>
        
        <div class="print-chart-container">
            <div class="print-chart-placeholder">
                <i class="fas fa-chart-pie"></i>
                <h4>Profit Margin Analysis</h4>
                <p>Gauge chart showing profit margin percentage</p>
                <p><strong>Profit Margin:</strong> <?= number_format($profit_margin, 2) ?>%</p>
                <p><strong>Status:</strong> <?= $profit_margin > 15 ? 'Strong' : ($profit_margin > 5 ? 'Moderate' : 'Needs Improvement') ?></p>
            </div>
        </div>
    </div>

    <!-- Chart Interpretation Section for Print -->
    <div class="print-interpretation-section">
        <h4 class="print-interpretation-header">
            <i class="fas fa-analytics me-2"></i>Income Statement Chart Interpretation
        </h4>
        
        <div class="print-interpretation-content">
            <?php 
            $revenue_expense_ratio = $total_revenue > 0 ? ($total_expense / $total_revenue) * 100 : 0;
            $interpretation = "Your income statement shows " . ($net_income >= 0 ? "a profit" : "a loss") . " of " . fmt($net_income) . 
                            " for the period. Your revenue of " . fmt($total_revenue) . " and expenses of " . fmt($total_expense) . 
                            " result in a profit margin of " . number_format($profit_margin, 2) . "%. " .
                            ($profit_margin > 15 ? "This indicates strong profitability." : 
                            ($profit_margin > 5 ? "This shows moderate profitability with room for improvement." : 
                            "This suggests challenges in maintaining profitability that need attention."));
            echo $interpretation;
            ?>
        </div>
        
        <div class="print-interpretation-points">
            <div class="print-interpretation-point">
                <div class="print-point-title">Revenue vs Expense Analysis</div>
                <p>The bar chart illustrates the relationship between your revenue and expenses. A healthy business typically maintains expenses at 70% or less of revenue to ensure sustainable profitability.</p>
            </div>
            
            <div class="print-interpretation-point">
                <div class="print-point-title">Profit Margin Assessment</div>
                <p>The profit margin gauge shows what percentage of each revenue peso translates into profit. Industry standards vary, but generally, margins above 15% are considered strong, while those below 5% may indicate challenges.</p>
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
        <p>This income statement represents the financial performance of <?= htmlspecialchars($company_name) ?> for the period ended <?= date('F d, Y') ?>.</p>
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
    <h3 class="report-title">Income Statement</h3>
    <p class="report-subtitle">For the period ended <?= date('F d, Y') ?> (<?= ucfirst($filter) ?> View)</p>
    </div>

<!-- View Filter at Top Right -->
<div class="view-filter-top no-print">
    <span class="view-filter-label">View:</span>
    <select class="form-select-sm" id="viewFilter">
        <option value="monthly" <?= $filter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        <option value="quarterly" <?= $filter === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
        <option value="annual" <?= $filter === 'annual' ? 'selected' : '' ?>>Annual</option>
    </select>
</div>

<!-- Income Statement Content in Three Columns -->
<div class="income-statement-columns">
    <!-- Revenues Column -->
    <div class="income-column">
        <div class="income-column-header">
            <h4>Revenues</h4>
        </div>
        
        <ul class="list-group mb-4">
            <?php foreach ($revenues as $account): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= htmlspecialchars($account['name']) ?>
                    <span class="text-end"><?= fmt($account['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between align-items-center total-row">
                <strong>Total Revenues</strong>
                <strong class="text-end"><?= fmt($total_revenue) ?></strong>
            </li>
        </ul>
    </div>
    
    <!-- Expenses Column -->
    <div class="income-column">
        <div class="income-column-header">
            <h4>Expenses</h4>
        </div>
        
        <ul class="list-group mb-4">
            <?php foreach ($expenses as $account): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= htmlspecialchars($account['name']) ?>
                    <span class="text-end"><?= fmt($account['balance']) ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between align-items-center total-row">
                <strong>Total Expenses</strong>
                <strong class="text-end"><?= fmt($total_expense) ?></strong>
            </li>
        </ul>
    </div>
    
    <!-- Summary Column -->
    <div class="income-column">
        <div class="income-column-header">
            <h4>Profitability</h4>
        </div>
        
        <ul class="list-group mb-4">
            <li class="list-group-item d-flex justify-content-between align-items-center">
                Total Revenues
                <span class="text-end"><?= fmt($total_revenue) ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                Total Expenses
                <span class="text-end"><?= fmt($total_expense) ?></span>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center net-income">
                <strong>Net Income</strong>
                <strong class="text-end"><?= fmt($net_income) ?></strong>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center total-row">
                <strong>Profit Margin</strong>
                <strong class="text-end"><?= number_format($profit_margin, 2) ?>%</strong>
            </li>
        </ul>
    </div>
</div>

<!-- Financial Visualization Section -->
<div class="chart-section mt-4 no-print">
    <h4 class="section-title">Financial Performance Visualization</h4>
    
    <div class="row">
        <div class="col-md-6 chart-container" id="revenueExpenseChart">
            <div class="chart-error" id="revenueExpenseChartError" style="display: none;"></div>
        </div>
        <div class="col-md-6 chart-container" id="profitMarginChart">
            <div class="chart-error" id="profitMarginChartError" style="display: none;"></div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-12 chart-container" id="waterfallChart">
            <div class="chart-error" id="waterfallChartError" style="display: none;"></div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-12 chart-container" id="trendChart">
            <div class="chart-error" id="trendChartError" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- NEW: Chart Interpretations Section -->
<div class="interpretation-section no-print">
    <h4><i class="fas fa-chart-bar me-2 text-primary"></i> Chart Interpretations</h4>
    <p class="text-muted">Our analysis of your financial charts provides these insights:</p>
    
    <div class="row">
        <?php foreach ($chart_interpretations as $interpretation): ?>
            <div class="col-md-6 mb-4">
                <div class="interpretation-card">
                    <div class="interp-title">
                        <?= $interpretation['title'] ?>
                    </div>
                    <div class="interp-chart"><?= $interpretation['chart'] ?></div>
                    <div class="interp-status"><?= $interpretation['status'] ?></div>
                    <div class="interp-insight"><?= $interpretation['insight'] ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="mt-3 text-end">
        <small class="text-muted">Chart interpretations generated on <?= date('F j, Y \a\t g:i A') ?> based on current financial data</small>
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
                    Apply AI recommendations to forecast
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
<div id="forecastResults" class="no-print">
    <!-- Forecast content will be loaded here via JavaScript -->
</div>

<!-- AI Recommendations Section -->
<div class="ai-section no-print">
    <h4><i class="fas fa-robot me-2 text-primary"></i> AI-Powered Income Statement Recommendations</h4>
    <p class="text-muted">Our AI analysis of your income statement provides these tailored recommendations:</p>
    
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

<!-- NEW: Back to Top Button - Arrow Only -->
<button class="back-to-top no-print" id="backToTop">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- ApexCharts Library -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.3/dist/apexcharts.min.js"></script>

<script>
// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Back to Top Button Functionality
    const backToTopButton = document.getElementById('backToTop');
    
    // Show/hide back to top button only when user reaches bottom of page
    window.addEventListener('scroll', function() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;
        
        // Show button only when scrolled to bottom (within 100px from bottom)
        if (scrollTop + windowHeight >= documentHeight - 100) {
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

    // View Filter Functionality - FIXED
    const viewFilter = document.getElementById('viewFilter');
    viewFilter.addEventListener('change', function() {
        const selectedFilter = this.value;
        
        // Create a new URL with all existing parameters
        const url = new URL(window.location.href);
        url.searchParams.set('filter', selectedFilter);
        
        // Preserve other GET parameters
        const forecastPeriod = document.getElementById('forecastPeriod').value;
        if (forecastPeriod) {
            url.searchParams.set('forecast', forecastPeriod);
        }
        
        if (document.getElementById('applyRecs').checked) {
            url.searchParams.set('apply_recs', '1');
        }
        
        // Navigate to the new URL
        window.location.href = url.toString();
    });

    // Function to initialize charts with retry mechanism
    function initializeCharts() {
        // Revenue vs Expense Bar Chart
        initializeRevenueExpenseChart();
        
        // Profit Margin Gauge Chart
        initializeProfitMarginChart();
        
        // Waterfall Chart
        initializeWaterfallChart();
        
        // Trend Chart
        initializeTrendChart();
    }
    
    // Function to initialize Revenue vs Expense Bar Chart
    function initializeRevenueExpenseChart() {
        const container = document.querySelector("#revenueExpenseChart");
        if (!container) {
            document.getElementById("revenueExpenseChartError").textContent = "Chart container not found";
            document.getElementById("revenueExpenseChartError").style.display = "block";
            return;
        }
        
        try {
            const barOptions = {
                series: [{
                    name: 'Revenue',
                    data: [<?= $total_revenue ?>]
                }, {
                    name: 'Expenses',
                    data: [<?= $total_expense ?>]
                }, {
                    name: 'Net Income',
                    data: [<?= $net_income ?>]
                }],
                chart: {
                    type: 'bar',
                    height: 350,
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
                    },
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return '₱' + val.toLocaleString();
                    }
                },
                xaxis: {
                    categories: ['Current Period'],
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
                colors: ['#4682B4', '#FF6B6B', '#4ECDC4'],
                legend: {
                    position: 'bottom'
                }
            };
            
            // Check if container is visible
            if (container.offsetParent === null) {
                setTimeout(initializeRevenueExpenseChart, 100);
                return;
            }
            
            new ApexCharts(container, barOptions).render();
        } catch (e) {
            document.getElementById("revenueExpenseChartError").textContent = "Error rendering chart: " + e.message;
            document.getElementById("revenueExpenseChartError").style.display = "block";
            console.error("Error rendering revenueExpenseChart:", e);
        }
    }
    
    // Function to initialize Profit Margin Gauge Chart
    function initializeProfitMarginChart() {
        const container = document.querySelector("#profitMarginChart");
        if (!container) {
            document.getElementById("profitMarginChartError").textContent = "Chart container not found";
            document.getElementById("profitMarginChartError").style.display = "block";
            return;
        }
        
        try {
            const gaugeOptions = {
                series: [<?= number_format($profit_margin, 2) ?>],
                chart: {
                    height: 350,
                    type: 'radialBar',
                },
                plotOptions: {
                    radialBar: {
                        startAngle: -135,
                        endAngle: 135,
                        dataLabels: {
                            name: {
                                fontSize: '16px',
                                color: undefined,
                                offsetY: 120
                            },
                            value: {
                                offsetY: 76,
                                fontSize: '22px',
                                color: undefined,
                                formatter: function (val) {
                                    return val + "%";
                                }
                            }
                        }
                    }
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shade: 'dark',
                        shadeIntensity: 0.15,
                        inverseColors: false,
                        opacityFrom: 1,
                        opacityTo: 1,
                        stops: [0, 50, 65, 91]
                    },
                },
                stroke: {
                    dashArray: 4
                },
                labels: ['Profit Margin'],
                colors: ['<?= $profit_margin >= 0 ? "#4ECDC4" : "#FF6B6B" ?>'],
            };
            
            // Check if container is visible
            if (container.offsetParent === null) {
                setTimeout(initializeProfitMarginChart, 100);
                return;
            }
            
            new ApexCharts(container, gaugeOptions).render();
        } catch (e) {
            document.getElementById("profitMarginChartError").textContent = "Error rendering chart: " + e.message;
            document.getElementById("profitMarginChartError").style.display = "block";
            console.error("Error rendering profitMarginChart:", e);
        }
    }
    
    // Function to initialize Waterfall Chart
    function initializeWaterfallChart() {
        const container = document.querySelector("#waterfallChart");
        if (!container) {
            document.getElementById("waterfallChartError").textContent = "Chart container not found";
            document.getElementById("waterfallChartError").style.display = "block";
            return;
        }
        
        try {
            const waterfallOptions = {
                series: [{
                    name: 'Income',
                    data: [
                        {
                            x: 'Revenue',
                            y: <?= $total_revenue ?>
                        },
                        {
                            x: 'COGS',
                            y: -<?= array_reduce($expenses, function($carry, $item) { 
                                return (stripos($item['name'], 'cost') !== false || stripos($item['name'], 'cogs') !== false) ? 
                                    $carry + abs($item['balance']) : $carry; 
                            }, 0) ?>
                        },
                        {
                            x: 'Gross Profit',
                            y: <?= $total_revenue - array_reduce($expenses, function($carry, $item) { 
                                return (stripos($item['name'], 'cost') !== false || stripos($item['name'], 'cogs') !== false) ? 
                                    $carry + abs($item['balance']) : $carry; 
                            }, 0) ?>
                        },
                        {
                            x: 'Operating Expenses',
                            y: -<?= array_reduce($expenses, function($carry, $item) { 
                                return (stripos($item['name'], 'cost') === false && stripos($item['name'], 'cogs') === false) ? 
                                    $carry + abs($item['balance']) : $carry; 
                            }, 0) ?>
                        },
                        {
                            x: 'Net Income',
                            y: <?= $net_income ?>
                        }
                    ]
                }],
                chart: {
                    type: 'bar',
                    height: 350,
                    toolbar: {
                        show: true,
                        tools: {
                            download: true
                        }
                    }
                },
                plotOptions: {
                    bar: {
                        colors: {
                            ranges: [{
                                from: -1000000,
                                to: -1,
                                color: '#FF6B6B'
                            }, {
                                from: 0,
                                to: 1000000,
                                color: '#4682B4'
                            }]
                        },
                        columnWidth: '80%',
                    }
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return '₱' + Math.abs(val).toLocaleString();
                    }
                },
                yaxis: {
                    title: {
                        text: 'Amount (₱)'
                    },
                    labels: {
                        formatter: function(val) {
                            return '₱' + Math.abs(val).toLocaleString();
                        }
                    }
                },
                xaxis: {
                    type: 'category'
                },
                legend: {
                    show: false
                }
            };
            
            // Check if container is visible
            if (container.offsetParent === null) {
                setTimeout(initializeWaterfallChart, 100);
                return;
            }
            
            new ApexCharts(container, waterfallOptions).render();
        } catch (e) {
            document.getElementById("waterfallChartError").textContent = "Error rendering chart: " + e.message;
            document.getElementById("waterfallChartError").style.display = "block";
            console.error("Error rendering waterfallChart:", e);
        }
    }
    
    // Function to initialize Trend Chart
    function initializeTrendChart() {
        const container = document.querySelector("#trendChart");
        if (!container) {
            document.getElementById("trendChartError").textContent = "Chart container not found";
            document.getElementById("trendChartError").style.display = "block";
            return;
        }
        
        try {
            const trendOptions = {
                series: [{
                    name: 'Revenue',
                    data: [<?= implode(',', $revenue_data) ?>]
                }, {
                    name: 'Expenses',
                    data: [<?= implode(',', $expense_data) ?>]
                }, {
                    name: 'Net Income',
                    data: [<?= implode(',', $net_income_data) ?>]
                }],
                chart: {
                    height: 350,
                    type: 'line',
                    zoom: {
                        enabled: false
                    },
                    toolbar: {
                        show: true,
                        tools: {
                            download: true
                        }
                    }
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    curve: 'straight',
                    width: 3
                },
                colors: ['#4682B4', '#FF6B6B', '#4ECDC4'],
                xaxis: {
                    categories: [<?= '"' . implode('","', $periods) . '"' ?>],
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
                legend: {
                    position: 'top'
                }
            };
            
            // Check if container is visible
            if (container.offsetParent === null) {
                setTimeout(initializeTrendChart, 100);
                return;
            }
            
            new ApexCharts(container, trendOptions).render();
        } catch (e) {
            document.getElementById("trendChartError").textContent = "Error rendering chart: " + e.message;
            document.getElementById("trendChartError").style.display = "block";
            console.error("Error rendering trendChart:", e);
        }
    }
    
    // Initialize charts with a small delay to ensure DOM is ready
    setTimeout(initializeCharts, 100);
    
    // Forecast Button Click Handler
    document.getElementById('forecastButton').addEventListener('click', function() {
        const forecastPeriod = document.getElementById('forecastPeriod').value;
        const applyRecs = document.getElementById('applyRecs').checked;
        
        if (!forecastPeriod) {
            alert('Please select a forecast period');
            return;
        }
        
        // Show loading state
        const forecastButton = document.getElementById('forecastButton');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const originalText = forecastButton.innerHTML;
        forecastButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Calculating...';
        forecastButton.disabled = true;
        loadingIndicator.style.display = 'block';
        
        // Prepare parameters for GET request
        const params = new URLSearchParams();
        params.append('forecast', forecastPeriod);
        params.append('apply_recs', applyRecs ? '1' : '0');
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
            forecastButton.innerHTML = originalText;
            forecastButton.disabled = false;
            
            // Check for error response
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            
            // Update forecast results
            updateForecastResults(data, forecastPeriod, applyRecs);
        })
        .catch(error => {
            console.error('Error:', error);
            loadingIndicator.style.display = 'none';
            forecastButton.innerHTML = originalText;
            forecastButton.disabled = false;
            alert('Error generating forecast. Please try again. ' + error.message);
        });
    });
    
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
        const colClass = applyRecs && data.recommended ? 'col-md-4' : 'col-md-6';
        
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
        
        // Add third timeline point only if recommendations are applied
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
                <div class="${colClass}">
                    <div class="scenario-card">
                        <div class="scenario-header">
                            <i class="fas fa-file-invoice-dollar"></i> Current Financials
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Total Revenue
                                <span data-current="total_revenue">${formatCurrency(data.current.total_revenue)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Total Expenses
                                <span data-current="total_expense">${formatCurrency(data.current.total_expense)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Net Income
                                <span data-current="net_income">${formatCurrency(data.current.net_income)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Profit Margin
                                <span data-current="profit_margin">${data.current.profit_margin.toFixed(2)}%</span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                <!-- Projected Financial Position -->
                <div class="${colClass}">
                    <div class="scenario-card">
                        <div class="scenario-header">
                            <i class="fas fa-chart-line"></i> Projected Financials
                            <span class="recommendation-badge">Forecast</span>
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Total Revenue
                                <span data-forecasted="total_revenue">${formatCurrency(data.forecasted.total_revenue)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Total Expenses
                                <span data-forecasted="total_expense">${formatCurrency(data.forecasted.total_expense)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Net Income
                                <span data-forecasted="net_income">${formatCurrency(data.forecasted.net_income)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Profit Margin
                                <span data-forecasted="profit_margin">${data.forecasted.profit_margin.toFixed(2)}%</span>
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
                <!-- Recommended Financial Position -->
                <div class="${colClass}">
                    <div class="scenario-card ai-optimized-card">
                        <div class="scenario-header">
                            <i class="fas fa-lightbulb"></i> AI-Optimized Financials
                            <span class="recommendation-badge">AI Optimized</span>
                        </div>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between">
                                Total Revenue
                                <span data-recommended="total_revenue">${formatCurrency(data.recommended.total_revenue)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Total Expenses
                                <span data-recommended="total_expense">${formatCurrency(data.recommended.total_expense)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                Net Income
                                <span data-recommended="net_income">${formatCurrency(data.recommended.net_income)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between total-row">
                                Profit Margin
                                <span data-recommended="profit_margin">${data.recommended.profit_margin.toFixed(2)}%</span>
                            </li>
                        </ul>
                        <div class="mt-3">
                            <p class="mb-1"><strong>AI Optimization Applied:</strong></p>
                            <p class="small">AI-powered recommendations for revenue enhancement and expense optimization</p>
                        </div>
                    </div>
                </div>`;
        }
        
        html += `</div></div>`;
        
        // Update the forecast results section
        document.getElementById('forecastResults').innerHTML = html;
        document.getElementById('forecastResults').style.display = 'block';
        
        // Scroll to forecast results
        document.getElementById('forecastResults').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
</script>
</body>
</html>