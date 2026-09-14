<?php
// This file contains only the financial overview graph and its functions
// It's designed to be included in asset.php

// Get filter parameters
$filter = $_GET['filter'] ?? 'annual';

// Calculate date range based on filter (same logic as balance sheet)
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
?>

<!-- Financial Overview Graph Content -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-chart-bar me-2"></i> Financial Overview Graph</h3>
        
        <!-- View Filter at Top Right -->
        <div class="view-filter-top">
            <span class="view-filter-label">View:</span>
            <select class="form-select-sm" id="viewFilter">
                <option value="monthly" <?= $filter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="quarterly" <?= $filter === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                <option value="annual" <?= $filter === 'annual' ? 'selected' : '' ?>>Annual</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        
        <!-- Balance Sheet Charts Section -->
        <div class="chart-section">
            <h4 class="section-title">Balance Sheet Visualization</h4>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="balancePieChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="assetLiabilityChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- NEW: General Ledger Charts Section -->
        <div class="chart-section">
            <h4 class="section-title">General Ledger Visualization</h4>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="accountTrendsChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="debitCreditChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- NEW: Income Statement Charts Section -->
        <div class="chart-section">
            <h4 class="section-title">Income Statement Visualization</h4>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="incomeRevenueExpenseChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="incomeProfitMarginTrendChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- NEW: Trial Balance Charts Section -->
        <div class="chart-section">
            <h4 class="section-title">Trial Balance Visualization</h4>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="trialBalanceDebitCreditChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <div id="trialBalanceParetoChart">
                            <div class="chart-loading">
                                <div class="chart-loading-spinner"></div>
                                <p>Loading chart...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="section-title">Key Metrics</h5>
                        <div class="ratio-item">
                            <span>Total Assets</span>
                            <span class="ratio-value ratio-good" id="totalAssets">₱<?= number_format($total_assets, 2) ?></span>
                        </div>
                        <div class="ratio-item">
                            <span>Total Liabilities</span>
                            <span class="ratio-value ratio-warning" id="totalLiabilities">₱<?= number_format($total_liabilities, 2) ?></span>
                        </div>
                        <div class="ratio-item">
                            <span>Total Equity</span>
                            <span class="ratio-value ratio-good" id="totalEquity">₱<?= number_format($total_equity, 2) ?></span>
                        </div>
                        <div class="ratio-item">
                            <span>Current Ratio</span>
                            <span class="ratio-value <?= ($current_liabilities_total > 0 && ($current_assets_total / $current_liabilities_total) > 1.5) ? 'ratio-good' : 'ratio-warning' ?>" id="currentRatio">
                                <?= $current_liabilities_total > 0 ? number_format($current_assets_total / $current_liabilities_total, 2) : 'N/A' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="section-title">Financial Health Analysis</h5>
                        <p>Based on your current financial data, here's an overview of your financial health:</p>
                        <ul>
                            <li><strong>Assets Composition:</strong> 
                                <span id="assetsComposition">
                                    <?= $total_assets > 0 ? number_format(($current_assets_total / $total_assets) * 100, 1) : 0 ?>% Current, 
                                    <?= $total_assets > 0 ? number_format(($non_current_assets_total / $total_assets) * 100, 1) : 0 ?>% Non-Current
                                </span>
                            </li>
                            <li><strong>Liability Structure:</strong> 
                                <span id="liabilityStructure">
                                    <?= $total_liabilities > 0 ? number_format(($current_liabilities_total / $total_liabilities) * 100, 1) : 0 ?>% Current, 
                                    <?= $total_liabilities > 0 ? number_format(($non_current_liabilities_total / $total_liabilities) * 100, 1) : 0 ?>% Non-Current
                                </span>
                            </li>
                            <li><strong>Debt to Equity Ratio:</strong> 
                                <span id="debtEquityRatio">
                                    <?= $total_equity > 0 ? number_format($total_liabilities / $total_equity, 2) : 'N/A' ?>
                                </span>
                            </li>
                            <li><strong>Financial Leverage:</strong> 
                                <span id="financialLeverage">
                                    <?= $total_assets > 0 ? number_format(($total_liabilities / $total_assets) * 100, 1) : 0 ?>%
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* View filter aligned with action buttons at top right */
.view-filter-top {
    background: rgba(255, 255, 255, 0.95);
    padding: 10px 15px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    border: 1px solid #c4d8eb;
    backdrop-filter: blur(5px);
}

.view-filter-top .view-filter-label {
    font-weight: 600;
    color: #1a5f9c;
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
    border-color: #1a5f9c;
    box-shadow: 0 0 0 0.2rem rgba(26, 95, 156, 0.25);
}

.chart-loading {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.chart-loading-spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 2s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.chart-fallback {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.chart-fallback i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #ccc;
}

.chart-container {
    min-height: 400px;
    position: relative;
}
</style>

<script>
// Financial Overview Graph functionality

// Chart instances
let balancePieChart = null;
let assetLiabilityChart = null;
let incomeRevenueExpenseChart = null;
let incomeProfitMarginTrendChart = null;
let trialBalanceDebitCreditChart = null;
let trialBalanceParetoChart = null;
let accountTrendsChart = null;
let debitCreditChart = null;

// Global data variables
let balanceSheetData = {};
let incomeStatementData = {};
let trialBalanceData = {};
let accountTrendsData = {};
let debitCreditData = {};

// Track initialization state
let chartsInitialized = false;

// Current filter state
let currentFilter = '<?= $filter ?>';

// Function to check if ApexCharts is available
function isApexChartsLoaded() {
    return typeof ApexCharts !== 'undefined' && typeof ApexCharts === 'function';
}

// Function to wait for ApexCharts to load
function waitForApexCharts(callback, maxWaitTime = 10000) {
    const startTime = Date.now();
    
    function check() {
        if (isApexChartsLoaded()) {
            console.log('ApexCharts loaded successfully');
            callback();
        } else if (Date.now() - startTime < maxWaitTime) {
            setTimeout(check, 100);
        } else {
            console.error('ApexCharts failed to load within ' + maxWaitTime + 'ms');
            showChartFallback('Chart library failed to load. Please refresh the page.');
        }
    }
    
    check();
}

// Function to update view filter - FIXED to match report_balance_sheet.php
function updateView(filter) {
    console.log('Updating view to:', filter);
    
    // Update current filter
    currentFilter = filter;
    
    // Show loading state for all charts
    showChartLoading();
    
    // Update URL without page reload
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    window.history.pushState({}, '', url);
    
    // Update the select dropdown value
    const selectElement = document.getElementById('viewFilter');
    if (selectElement) {
        selectElement.value = filter;
    }
    
    // Fetch new chart data
    fetchChartData(filter);
}

// Function to fetch chart data via AJAX
function fetchChartData(filter) {
    console.log('Fetching chart data for filter:', filter);
    
    // Show loading state
    showChartLoading();
    
    // Make AJAX request to get chart data
    fetch(`get_chart_data.php?time_period=${filter}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Received chart data:', data);
            
            // Check if we have an error
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Update global data variables
            balanceSheetData = data.balanceSheetData || {};
            incomeStatementData = data.incomeStatementData || {};
            trialBalanceData = data.trialBalanceData || {};
            accountTrendsData = data.accountTrendsData || {};
            debitCreditData = data.debitCreditData || {};
            
            // Update key metrics
            updateKeyMetrics(data);
            
            // If charts are already initialized, just update them
            if (chartsInitialized) {
                renderAllCharts();
            } else {
                // Wait for ApexCharts to be ready, then render charts
                waitForApexCharts(() => {
                    renderAllCharts();
                    chartsInitialized = true;
                });
            }
        })
        .catch(error => {
            console.error('Error fetching chart data:', error);
            showChartFallback('Error loading chart data: ' + error.message);
        });
}

// Function to update key metrics in the UI
function updateKeyMetrics(data) {
    const bsData = data.balanceSheetData || {};
    
    // Calculate totals from components if not provided
    const totalAssets = bsData.total_assets || ((bsData.current_assets || 0) + (bsData.non_current_assets || 0));
    const totalLiabilities = bsData.total_liabilities || ((bsData.current_liabilities || 0) + (bsData.non_current_liabilities || 0));
    const totalEquity = bsData.equity || 0;
    
    // Update balance sheet metrics
    if (document.getElementById('totalAssets')) {
        document.getElementById('totalAssets').textContent = '₱' + (totalAssets || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (document.getElementById('totalLiabilities')) {
        document.getElementById('totalLiabilities').textContent = '₱' + (totalLiabilities || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (document.getElementById('totalEquity')) {
        document.getElementById('totalEquity').textContent = '₱' + (totalEquity || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    // Update current ratio
    const currentAssets = bsData.current_assets || 0;
    const currentLiabilities = bsData.current_liabilities || 0;
    const currentRatio = currentLiabilities > 0 ? (currentAssets / currentLiabilities) : 0;
    const currentRatioElement = document.getElementById('currentRatio');
    if (currentRatioElement) {
        currentRatioElement.textContent = currentLiabilities > 0 ? currentRatio.toFixed(2) : 'N/A';
        currentRatioElement.className = 'ratio-value ' + (currentRatio > 1.5 ? 'ratio-good' : 'ratio-warning');
    }
    
    // Update financial health analysis
    const assetsComposition = totalAssets > 0 ? 
        `${((currentAssets / totalAssets) * 100).toFixed(1)}% Current, ${(((bsData.non_current_assets || 0) / totalAssets) * 100).toFixed(1)}% Non-Current` : 
        '0% Current, 0% Non-Current';
    if (document.getElementById('assetsComposition')) {
        document.getElementById('assetsComposition').textContent = assetsComposition;
    }
    
    const liabilityStructure = totalLiabilities > 0 ? 
        `${((currentLiabilities / totalLiabilities) * 100).toFixed(1)}% Current, ${(((bsData.non_current_liabilities || 0) / totalLiabilities) * 100).toFixed(1)}% Non-Current` : 
        '0% Current, 0% Non-Current';
    if (document.getElementById('liabilityStructure')) {
        document.getElementById('liabilityStructure').textContent = liabilityStructure;
    }
    
    const debtEquityRatio = totalEquity > 0 ? 
        (totalLiabilities / totalEquity).toFixed(2) : 'N/A';
    if (document.getElementById('debtEquityRatio')) {
        document.getElementById('debtEquityRatio').textContent = debtEquityRatio;
    }
    
    const financialLeverage = totalAssets > 0 ? 
        ((totalLiabilities / totalAssets) * 100).toFixed(1) + '%' : '0%';
    if (document.getElementById('financialLeverage')) {
        document.getElementById('financialLeverage').textContent = financialLeverage;
    }
}

// Function to show loading state for all charts
function showChartLoading() {
    document.querySelectorAll('.chart-container > div[id$="Chart"]').forEach(container => {
        container.innerHTML = `
            <div class="chart-loading">
                <div class="chart-loading-spinner"></div>
                <p>Loading chart data...</p>
            </div>
        `;
    });
}

// Function to render all charts
function renderAllCharts() {
    console.log('Rendering all charts...');
    
    // Check if chart containers exist and are visible
    if (!areChartContainersReady()) {
        console.log('Chart containers not ready, retrying...');
        setTimeout(renderAllCharts, 100);
        return;
    }
    
    renderBalanceSheetCharts();
    renderIncomeStatementCharts();
    renderTrialBalanceCharts();
    renderGeneralLedgerCharts();
    
    console.log('All charts rendered successfully');
}

// Function to check if chart containers are ready
function areChartContainersReady() {
    const containers = [
        'balancePieChart',
        'assetLiabilityChart', 
        'accountTrendsChart',
        'debitCreditChart',
        'incomeRevenueExpenseChart',
        'incomeProfitMarginTrendChart',
        'trialBalanceDebitCreditChart',
        'trialBalanceParetoChart'
    ];
    
    for (let id of containers) {
        const container = document.getElementById(id);
        if (!container || container.offsetParent === null) {
            console.log('Container not ready:', id);
            return false;
        }
    }
    return true;
}

// Function to render balance sheet charts
function renderBalanceSheetCharts() {
    if (!isApexChartsLoaded()) {
        console.error('ApexCharts not loaded');
        return;
    }
    
    const pieChartContainer = document.getElementById("balancePieChart");
    const barChartContainer = document.getElementById("assetLiabilityChart");
    
    if (!pieChartContainer || !barChartContainer) {
        console.error('Balance sheet chart containers not found');
        return;
    }
    
    try {
        // Clear containers
        pieChartContainer.innerHTML = '';
        barChartContainer.innerHTML = '';
        
        // Check if we have data
        if (!balanceSheetData || Object.keys(balanceSheetData).length === 0) {
            pieChartContainer.innerHTML = createFallbackHTML('No Balance Sheet Data', 'fas fa-chart-pie');
            barChartContainer.innerHTML = createFallbackHTML('No Balance Sheet Data', 'fas fa-chart-bar');
            return;
        }
        
        // Balance Sheet Composition Pie Chart
        const pieOptions = {
            series: [
                Math.abs(balanceSheetData.current_assets || 0), 
                Math.abs(balanceSheetData.non_current_assets || 0), 
                Math.abs(balanceSheetData.current_liabilities || 0), 
                Math.abs(balanceSheetData.non_current_liabilities || 0), 
                Math.abs(balanceSheetData.equity || 0)
            ],
            chart: { 
                type: 'pie', 
                height: 350,
                toolbar: {
                    show: true
                }
            },
            labels: ['Current Assets', 'Non-Current Assets', 'Current Liabilities', 'Non-Current Liabilities', 'Equity'],
            colors: ['#4682B4', '#5a96cf', '#FF6B6B', '#FFA07A', '#4ECDC4'],
            legend: { 
                position: 'bottom' 
            },
            responsive: [{
                breakpoint: 480,
                options: { 
                    chart: { 
                        width: 300 
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }]
        };
        
        if (balancePieChart) {
            balancePieChart.destroy();
        }
        
        balancePieChart = new ApexCharts(pieChartContainer, pieOptions);
        balancePieChart.render();

        // Assets vs Liabilities Bar Chart
        const barOptions = {
            series: [{
                name: 'Assets',
                data: [Math.abs(balanceSheetData.current_assets || 0), Math.abs(balanceSheetData.non_current_assets || 0)]
            }, {
                name: 'Liabilities',
                data: [Math.abs(balanceSheetData.current_liabilities || 0), Math.abs(balanceSheetData.non_current_liabilities || 0)]
            }],
            chart: { 
                type: 'bar', 
                height: 350,
                toolbar: {
                    show: true
                }
            },
            plotOptions: { 
                bar: { 
                    horizontal: false,
                    columnWidth: '60%'
                } 
            },
            xaxis: { 
                categories: ['Current', 'Non-Current']
            },
            yaxis: {
                labels: {
                    formatter: function(val) {
                        return '₱' + val.toLocaleString();
                    }
                }
            },
            colors: ['#4682B4', '#FF6B6B']
        };
        
        if (assetLiabilityChart) {
            assetLiabilityChart.destroy();
        }
        
        assetLiabilityChart = new ApexCharts(barChartContainer, barOptions);
        assetLiabilityChart.render();
        
    } catch (error) {
        console.error('Error rendering balance sheet charts:', error);
        pieChartContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
        barChartContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
    }
}

// Function to render General Ledger charts
function renderGeneralLedgerCharts() {
    if (!isApexChartsLoaded()) return;
    
    const accountTrendsContainer = document.getElementById("accountTrendsChart");
    const debitCreditContainer = document.getElementById("debitCreditChart");
    
    if (!accountTrendsContainer || !debitCreditContainer) return;
    
    try {
        accountTrendsContainer.innerHTML = '';
        debitCreditContainer.innerHTML = '';
        
        // Account Trends Line Chart
        if (accountTrendsData && Object.keys(accountTrendsData).length > 0) {
            const series = [];
            const categories = new Set();

            Object.keys(accountTrendsData).forEach(account => {
                const accountData = accountTrendsData[account];
                const dataPoints = [];

                Object.keys(accountData).forEach(month => {
                    categories.add(month);
                });

                const sortedCategories = Array.from(categories).sort((a, b) => {
                    return new Date(a) - new Date(b);
                });

                sortedCategories.forEach(month => {
                    dataPoints.push(accountData[month]?.net || 0);
                });

                series.push({
                    name: account,
                    data: dataPoints
                });
            });

            const sortedCategories = Array.from(categories).sort((a, b) => {
                return new Date(a) - new Date(b);
            });

            const accountTrendsOptions = {
                series: series,
                chart: { 
                    type: 'line', 
                    height: 350,
                    toolbar: {
                        show: true
                    }
                },
                stroke: { 
                    curve: 'smooth', 
                    width: 3 
                },
                xaxis: { 
                    categories: sortedCategories 
                },
                yaxis: {
                    labels: {
                        formatter: function(val) {
                            return '₱' + val.toLocaleString();
                        }
                    }
                },
                colors: ['#4682B4', '#5a96cf', '#FF6B6B', '#FFA07A', '#4ECDC4', '#45B7D1', '#F7A35C', '#8D6E63']
            };

            if (accountTrendsChart) {
                accountTrendsChart.destroy();
            }
            
            accountTrendsChart = new ApexCharts(accountTrendsContainer, accountTrendsOptions);
            accountTrendsChart.render();
        } else {
            accountTrendsContainer.innerHTML = createFallbackHTML('No Account Trends Data', 'fas fa-chart-line');
        }

        // Debit vs Credit Bar Chart
        if (debitCreditData && Object.keys(debitCreditData).length > 0) {
            const accountNames = Object.keys(debitCreditData);
            const debitSeries = accountNames.map(account => debitCreditData[account]?.debit || 0);
            const creditSeries = accountNames.map(account => debitCreditData[account]?.credit || 0);

            const debitCreditOptions = {
                series: [
                    { 
                        name: 'Debit', 
                        data: debitSeries 
                    },
                    { 
                        name: 'Credit', 
                        data: creditSeries 
                    }
                ],
                chart: { 
                    type: 'bar', 
                    height: 350,
                    toolbar: {
                        show: true
                    }
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '55%',
                    }
                },
                xaxis: { 
                    categories: accountNames,
                    labels: {
                        rotate: -45,
                        style: {
                            fontSize: '12px'
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
                colors: ['#4682B4', '#FF6B6B']
            };

            if (debitCreditChart) {
                debitCreditChart.destroy();
            }
            
            debitCreditChart = new ApexCharts(debitCreditContainer, debitCreditOptions);
            debitCreditChart.render();
        } else {
            debitCreditContainer.innerHTML = createFallbackHTML('No Debit/Credit Data', 'fas fa-balance-scale');
        }
        
    } catch (error) {
        console.error('Error rendering general ledger charts:', error);
        accountTrendsContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
        debitCreditContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
    }
}

// Function to render Income Statement charts
function renderIncomeStatementCharts() {
    if (!isApexChartsLoaded()) return;
    
    const revenueExpenseContainer = document.getElementById("incomeRevenueExpenseChart");
    const profitMarginTrendContainer = document.getElementById("incomeProfitMarginTrendChart");
    
    if (!revenueExpenseContainer || !profitMarginTrendContainer) return;
    
    try {
        revenueExpenseContainer.innerHTML = '';
        profitMarginTrendContainer.innerHTML = '';
        
        if (!incomeStatementData || Object.keys(incomeStatementData).length === 0) {
            revenueExpenseContainer.innerHTML = createFallbackHTML('No Income Statement Data', 'fas fa-chart-bar');
            profitMarginTrendContainer.innerHTML = createFallbackHTML('No Income Statement Data', 'fas fa-chart-line');
            return;
        }
        
        // Revenue vs Expense Bar Chart
        const revenueExpenseOptions = {
            series: [{
                name: 'Revenue',
                data: [Math.abs(incomeStatementData.total_revenue || 0)]
            }, {
                name: 'Expenses',
                data: [Math.abs(incomeStatementData.total_expense || 0)]
            }, {
                name: 'Net Income',
                data: [Math.abs(incomeStatementData.net_income || 0)]
            }],
            chart: { 
                type: 'bar', 
                height: 350,
                toolbar: {
                    show: true
                }
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '55%',
                }
            },
            xaxis: { 
                categories: ['Current Period'] 
            },
            yaxis: {
                labels: {
                    formatter: function(val) {
                        return '₱' + val.toLocaleString();
                    }
                }
            },
            colors: ['#4682B4', '#FF6B6B', '#4ECDC4']
        };
        
        if (incomeRevenueExpenseChart) {
            incomeRevenueExpenseChart.destroy();
        }
        
        incomeRevenueExpenseChart = new ApexCharts(revenueExpenseContainer, revenueExpenseOptions);
        incomeRevenueExpenseChart.render();

        // Profit Margin Trend Chart
        if (incomeStatementData.periods && incomeStatementData.periods.length > 0) {
            const profitMarginTrendOptions = {
                series: [{
                    name: 'Profit Margin %',
                    data: incomeStatementData.net_income_data.map((netIncome, index) => {
                        const revenue = incomeStatementData.revenue_data[index] || 0;
                        return revenue > 0 ? (netIncome / revenue) * 100 : 0;
                    })
                }],
                chart: { 
                    height: 350, 
                    type: 'line',
                    toolbar: {
                        show: true
                    }
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    curve: 'straight',
                    width: 3
                },
                xaxis: { 
                    categories: incomeStatementData.periods || [] 
                },
                yaxis: {
                    labels: {
                        formatter: function(val) {
                            return val.toFixed(2) + '%';
                        }
                    }
                },
                colors: ['#4ECDC4']
            };
            
            if (incomeProfitMarginTrendChart) {
                incomeProfitMarginTrendChart.destroy();
            }
            
            incomeProfitMarginTrendChart = new ApexCharts(profitMarginTrendContainer, profitMarginTrendOptions);
            incomeProfitMarginTrendChart.render();
        } else {
            profitMarginTrendContainer.innerHTML = createFallbackHTML('No Trend Data', 'fas fa-chart-line');
        }
        
    } catch (error) {
        console.error('Error rendering income statement charts:', error);
        revenueExpenseContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
        profitMarginTrendContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
    }
}

// Function to render Trial Balance charts
function renderTrialBalanceCharts() {
    if (!isApexChartsLoaded()) return;
    
    const debitCreditContainer = document.getElementById("trialBalanceDebitCreditChart");
    const paretoContainer = document.getElementById("trialBalanceParetoChart");
    
    if (!debitCreditContainer || !paretoContainer) return;
    
    try {
        debitCreditContainer.innerHTML = '';
        paretoContainer.innerHTML = '';
        
        if (!trialBalanceData || Object.keys(trialBalanceData).length === 0) {
            debitCreditContainer.innerHTML = createFallbackHTML('No Trial Balance Data', 'fas fa-chart-bar');
            paretoContainer.innerHTML = createFallbackHTML('No Trial Balance Data', 'fas fa-chart-line');
            return;
        }
        
        // Debit vs Credit by Account Type Chart
        const typeTotals = trialBalanceData.typeTotals || {};
        const debitCreditOptions = {
            series: [
                {
                    name: 'Debit',
                    data: [
                        typeTotals.Asset?.debit || 0,
                        typeTotals.Liability?.debit || 0,
                        typeTotals.Equity?.debit || 0,
                        typeTotals.Revenue?.debit || 0,
                        typeTotals.Expense?.debit || 0
                    ]
                },
                {
                    name: 'Credit',
                    data: [
                        typeTotals.Asset?.credit || 0,
                        typeTotals.Liability?.credit || 0,
                        typeTotals.Equity?.credit || 0,
                        typeTotals.Revenue?.credit || 0,
                        typeTotals.Expense?.credit || 0
                    ]
                }
            ],
            chart: { 
                type: 'bar', 
                height: 350,
                toolbar: {
                    show: true
                }
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '55%',
                }
            },
            xaxis: { 
                categories: ['Assets', 'Liabilities', 'Equity', 'Revenue', 'Expenses'] 
            },
            yaxis: {
                labels: {
                    formatter: function(val) {
                        return '₱' + val.toLocaleString();
                    }
                }
            },
            colors: ['#4682B4', '#FF6B6B']
        };
        
        if (trialBalanceDebitCreditChart) {
            trialBalanceDebitCreditChart.destroy();
        }
        
        trialBalanceDebitCreditChart = new ApexCharts(debitCreditContainer, debitCreditOptions);
        trialBalanceDebitCreditChart.render();

        // Pareto Chart
        if (trialBalanceData.paretoData && trialBalanceData.paretoData.length > 0) {
            const paretoOptions = {
                series: [
                    {
                        name: 'Account Balance',
                        type: 'column',
                        data: trialBalanceData.paretoData.map(acc => acc.balance || 0)
                    },
                    {
                        name: 'Cumulative Percentage',
                        type: 'line',
                        data: trialBalanceData.paretoData.map(acc => acc.cumulative || 0)
                    }
                ],
                chart: { 
                    height: 350, 
                    type: 'line',
                    toolbar: {
                        show: true
                    }
                },
                stroke: {
                    width: [0, 4]
                },
                labels: trialBalanceData.paretoData.map(acc => acc.name || 'Unknown'),
                xaxis: {
                    type: 'category',
                    labels: {
                        rotate: -45,
                        style: {
                            fontSize: '12px'
                        }
                    }
                },
                yaxis: [
                    {
                        seriesName: 'Account Balance',
                        labels: {
                            formatter: function(val) {
                                return '₱' + val.toLocaleString();
                            }
                        }
                    },
                    {
                        seriesName: 'Cumulative Percentage',
                        opposite: true,
                        labels: {
                            formatter: function(val) {
                                return val.toFixed(1) + '%';
                            }
                        }
                    }
                ],
                colors: ['#4682B4', '#FF6B6B']
            };
            
            if (trialBalanceParetoChart) {
                trialBalanceParetoChart.destroy();
            }
            
            trialBalanceParetoChart = new ApexCharts(paretoContainer, paretoOptions);
            trialBalanceParetoChart.render();
        } else {
            paretoContainer.innerHTML = createFallbackHTML('No Pareto Data', 'fas fa-chart-line');
        }
        
    } catch (error) {
        console.error('Error rendering trial balance charts:', error);
        debitCreditContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
        paretoContainer.innerHTML = createFallbackHTML('Chart Error', 'fas fa-exclamation-triangle');
    }
}

// Helper function to create fallback HTML
function createFallbackHTML(message, iconClass) {
    return `
        <div class="chart-fallback">
            <i class="${iconClass}"></i>
            <h4>${message}</h4>
            <p>No data available for the selected period</p>
        </div>
    `;
}

// Function to show chart fallback
function showChartFallback(message = 'There was an error loading the chart. Please try refreshing the page.') {
    document.querySelectorAll('.chart-container > div[id$="Chart"]').forEach(container => {
        container.innerHTML = `
            <div class="chart-fallback">
                <i class="fas fa-exclamation-triangle"></i>
                <h4>Chart Unavailable</h4>
                <p>${message}</p>
            </div>
        `;
    });
}

// Initialize when the page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing charts...');
    
    // Set up event listener for the filter dropdown - FIXED
    const filterSelect = document.getElementById('viewFilter');
    if (filterSelect) {
        filterSelect.addEventListener('change', function() {
            updateView(this.value);
        });
    }
    
    // Wait for ApexCharts to load, then initialize
    waitForApexCharts(() => {
        console.log('ApexCharts loaded, initializing charts...');
        fetchChartData(currentFilter);
    });
});

// Also try initializing when window loads completely
window.addEventListener('load', function() {
    console.log('Window fully loaded');
    // Ensure charts are initialized even if DOMContentLoaded already fired
    if (!chartsInitialized && isApexChartsLoaded()) {
        fetchChartData(currentFilter);
    }
});
</script>