// File: ml_comparison.php
<?php
session_start();
require 'db_connection.php';
require 'vendor/autoload.php'; // Composer autoload for TensorFlow PHP

use TensorFlow\TensorFlow;

class FinancialComparison {
    private $pdo;
    private $company;
    private $transactionsTable;
    
    public function __construct($pdo, $company) {
        $this->pdo = $pdo;
        $this->company = preg_replace('/[^A-Za-z0-9]/', '_', $company);
        $this->transactionsTable = $this->company . "_Transactions";
    }

    public function generateComparisonReport($currentPeriod, $comparisonPeriod) {
        // Fetch data for both periods
        $currentData = $this->getPeriodData($currentPeriod);
        $previousData = $this->getPeriodData($comparisonPeriod);
        
        // Prepare data for TensorFlow
        $dataset = $this->prepareComparisonDataset($currentData, $previousData);
        
        // Analyze with TensorFlow
        $analysis = $this->analyzeWithTensorFlow($dataset);
        
        // Generate textual summary
        return $this->generateSummary($analysis);
    }
    
    private function getPeriodData($period) {
        $stmt = $this->pdo->prepare("SELECT account_type, 
            SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE 0 END) as debits,
            SUM(CASE WHEN entry_type = 'credit' THEN amount ELSE 0 END) as credits
            FROM {$this->transactionsTable} 
            WHERE date BETWEEN :start AND :end
            GROUP BY account_type");
        
        $stmt->execute([
            ':start' => $period['start'],
            ':end' => $period['end']
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function prepareComparisonDataset($current, $previous) {
        // Normalize data and prepare features/labels
        $dataset = [];
        $categories = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
        
        foreach ($categories as $category) {
            $currentVal = $this->findCategoryTotal($current, $category);
            $prevVal = $this->findCategoryTotal($previous, $category);
            
            $dataset[] = [
                'category' => $category,
                'current' => $currentVal,
                'previous' => $prevVal,
                'change' => $currentVal - $prevVal,
                'pct_change' => ($prevVal != 0) ? (($currentVal - $prevVal) / abs($prevVal)) * 100 : 0
            ];
        }
        
        return $dataset;
    }
    
    private function analyzeWithTensorFlow($dataset) {
        // Initialize TensorFlow
        $tf = new TensorFlow();
        
        // Prepare data arrays
        $currentPeriod = [];
        $previousPeriod = [];
        
        foreach ($dataset as $data) {
            $currentPeriod[] = $data['current'];
            $previousPeriod[] = $data['previous'];
        }
        
        // Create tensors
        $currentTensor = $tf->tensor($currentPeriod);
        $previousTensor = $tf->tensor($previousPeriod);
        
        // Calculate differences and trends
        $absoluteChange = $tf->sub($currentTensor, $previousTensor);
        $percentChange = $tf->div($absoluteChange, $tf->abs($previousTensor));
        $percentChange = $tf->mul($percentChange, $tf->constant(100.0));
        
        // Detect significant changes
        $significantChanges = $tf->greater($tf->abs($percentChange), $tf->constant(10.0));
        
        return [
            'absolute' => $absoluteChange->value(),
            'percent' => $percentChange->value(),
            'significant' => $significantChanges->value()
        ];
    }
    
    private function generateSummary($analysis) {
        $categories = ['Assets', 'Liabilities', 'Equity', 'Revenue', 'Expenses'];
        $summary = "## Financial Performance Comparison\n\n";
        
        for ($i = 0; $i < count($categories); $i++) {
            $change = $analysis['absolute'][$i];
            $pct = round($analysis['percent'][$i], 2);
            $trend = ($change >= 0) ? 'increase' : 'decrease';
            
            $summary .= "### {$categories[$i]}:\n";
            $summary .= "- {$trend} of " . abs($pct) . "% (" . fmt(abs($change)) . ")\n";
            
            if ($analysis['significant'][$i]) {
                $summary .= "- **Significant change** detected - ";
                if (abs($pct) > 25) {
                    $summary .= "major shift in financial structure\n";
                } else {
                    $summary .= "notable trend worth investigating\n";
                }
            }
            $summary .= "\n";
        }
        
        $summary .= $this->generateOverallAssessment($analysis);
        return $summary;
    }
    
    private function generateOverallAssessment($analysis) {
        // Calculate overall financial health score
        $healthScore = 0;
        $positiveFactors = 0;
        
        // Revenue growth
        if ($analysis['percent'][3] > 0) {
            $healthScore += min(30, $analysis['percent'][3]);
            $positiveFactors++;
        }
        
        // Expense control
        if ($analysis['percent'][4] < 0) {
            $healthScore += abs(min(20, $analysis['percent'][4]));
            $positiveFactors++;
        }
        
        // Asset growth
        if ($analysis['percent'][0] > 0) {
            $healthScore += min(20, $analysis['percent'][0]);
        }
        
        // Liability reduction
        if ($analysis['percent'][1] < 0) {
            $healthScore += abs(min(15, $analysis['percent'][1]));
            $positiveFactors++;
        }
        
        // Equity growth
        if ($analysis['percent'][2] > 0) {
            $healthScore += min(15, $analysis['percent'][2]);
            $positiveFactors++;
        }
        
        $assessment = "\n## Overall Financial Health Assessment\n";
        $assessment .= "**Health Score:** " . round($healthScore) . "/100\n\n";
        
        if ($healthScore >= 70) {
            $assessment .= "✅ **Excellent financial performance** - The company shows strong growth fundamentals";
        } elseif ($healthScore >= 50) {
            $assessment .= "⚠️ **Moderate financial health** - Some areas need attention but overall stable";
        } else {
            $assessment .= "❌ **Financial health concerns** - Multiple areas require immediate attention";
        }
        
        $assessment .= "\n\n**Key Recommendations:**\n";
        if ($analysis['percent'][4] > 15) {
            $assessment .= "- Investigate expense growth (" . round($analysis['percent'][4]) . "% increase)\n";
        }
        if ($analysis['percent'][1] > 10) {
            $assessment .= "- Review liability increases (" . round($analysis['percent'][1]) . "% growth)\n";
        }
        if ($analysis['percent'][3] < 5) {
            $assessment .= "- Develop revenue growth strategies\n";
        }
        
        return $assessment;
    }
    
    private function findCategoryTotal($data, $category) {
        foreach ($data as $row) {
            if ($row['account_type'] === $category) {
                return $row['debits'] - $row['credits'];
            }
        }
        return 0;
    }
}

// Usage in report
$company_name = $_SESSION['company_name'] ?? null;
if (!$company_name) die("Company name not set in session.");

$currentPeriod = [
    'start' => date('Y-m-01'),
    'end' => date('Y-m-t')
];

$previousPeriod = [
    'start' => date('Y-m-01', strtotime('-1 month')),
    'end' => date('Y-m-t', strtotime('-1 month'))
];

$comparator = new FinancialComparison($pdo, $company_name);
$report = $comparator->generateComparisonReport($currentPeriod, $previousPeriod);

// Display report
echo "<div class='comparison-report'>";
echo nl2br(htmlspecialchars($report));
echo "</div>";
?>