<?php
session_start();
require 'db_connection.php';

$selected_user_id = $_GET['user_id'] ?? null;

if (!$selected_user_id) {
    die("Client ID missing.");
}

// Fetch client info
$stmt = $pdo->prepare("SELECT * FROM client WHERE id = ?");
$stmt->execute([$selected_user_id]);
$client = $stmt->fetch();

if (!$client) {
    die("Client not found.");
}

$selected_company = $client['Company Name'];
$company = preg_replace('/[^A-Za-z0-9]/', '_', $selected_company);
$transactionsTable = "{$company}_Transactions";
$accountsTable = "{$company}_Accounts";

// Check if transactions table exists and get count
$transactionsCount = 0;
$checkTransactions = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($transactionsTable));
if ($checkTransactions->rowCount() > 0) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$transactionsTable`");
    $transactionsCount = $stmt->fetch()['count'];
}

// Check if accounts table exists and get count
$accountsCount = 0;
$checkAccounts = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($accountsTable));
if ($checkAccounts->rowCount() > 0) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$accountsTable`");
    $accountsCount = $stmt->fetch()['count'];
}

// Get recent transactions (last 5)
$recentTransactions = [];
if ($transactionsCount > 0) {
    $stmt = $pdo->prepare("SELECT * FROM `$transactionsTable` ORDER BY date DESC LIMIT 5");
    $stmt->execute();
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get account types summary
$accountTypes = [];
if ($accountsCount > 0) {
    $stmt = $pdo->prepare("SELECT type, COUNT(*) as count FROM `$accountsTable` GROUP BY type");
    $stmt->execute();
    $accountTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check client inactivity status
$inactivityStatus = '';
$lastTransactionDate = null;
$daysSinceLastTransaction = null;
$isInactive = false;

if ($transactionsCount > 0) {
    $stmt = $pdo->prepare("SELECT MAX(date) as last_transaction_date FROM `$transactionsTable`");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['last_transaction_date']) {
        $lastTransactionDate = new DateTime($result['last_transaction_date']);
        $today = new DateTime();
        $daysSinceLastTransaction = $today->diff($lastTransactionDate)->days;
        
        if ($daysSinceLastTransaction >= 15) {
            $isInactive = true;
            $inactivityStatus = "<div class='alert alert-warning mt-3'>
                <i class='fas fa-exclamation-triangle'></i>
                <strong>Inactivity Notice:</strong> No transactions for $daysSinceLastTransaction days.
                Last activity: " . $lastTransactionDate->format('M j, Y') . "
            </div>";
        } else {
            $inactivityStatus = "<div class='alert alert-success mt-3'>
                <i class='fas fa-check-circle'></i>
                <strong>Account Active:</strong> Last transaction was $daysSinceLastTransaction days ago.
            </div>";
        }
    }
} else {
    $inactivityStatus = "<div class='alert alert-info mt-3'>
        <i class='fas fa-info-circle'></i>
        <strong>No Transactions:</strong> This client has not recorded any transactions yet.
    </div>";
}
?>

<div class="client-details-header">
    <h4><?= htmlspecialchars($selected_company) ?> - Overview</h4>
    <?php if ($inactivityStatus): ?>
        <?= $inactivityStatus ?>
    <?php endif; ?>
</div>

<div class="client-details-content">
    <div class="detail-card">
        <h6><i class="fas fa-wallet"></i> Accounts Summary</h6>
        <div class="detail-card-content">
            <p><strong>Total Accounts:</strong> <?= $accountsCount ?></p>
            <?php if (!empty($accountTypes)): ?>
                <?php foreach ($accountTypes as $type): ?>
                    <p><strong><?= $type['type'] ?>:</strong> <?= $type['count'] ?> accounts</p>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No accounts created yet.</p>
            <?php endif; ?>
            <a href="client_details.php?user_id=<?= $selected_user_id ?>#accountsSection" class="btn btn-view mt-2">
                Manage Accounts
            </a>
        </div>
    </div>

    <div class="detail-card">
        <h6><i class="fas fa-exchange-alt"></i> Transactions Summary</h6>
        <div class="detail-card-content">
            <p><strong>Total Transactions:</strong> <?= $transactionsCount ?></p>
            
            <?php if ($lastTransactionDate): ?>
                <p><strong>Last Transaction:</strong> <?= $lastTransactionDate->format('M j, Y') ?></p>
                <p><strong>Days Since Last Activity:</strong> 
                    <span class="<?= $isInactive ? 'text-warning' : 'text-success' ?>">
                        <?= $daysSinceLastTransaction ?> days
                    </span>
                </p>
            <?php endif; ?>
            
            <p><strong>Recent Activity:</strong></p>
            <?php if (!empty($recentTransactions)): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentTransactions as $txn): ?>
                        <li class="list-group-item">
                            <small><?= htmlspecialchars($txn['date']) ?>: <?= htmlspecialchars($txn['description']) ?> (₱<?= number_format($txn['amount'], 2) ?>)</small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No recent transactions.</p>
            <?php endif; ?>
            <a href="client_details.php?user_id=<?= $selected_user_id ?>#transactionsSection" class="btn btn-view mt-2">
                View All Transactions
            </a>
        </div>
    </div>

    <div class="detail-card">
        <h6><i class="fas fa-chart-bar"></i> Quick Reports</h6>
        <div class="detail-card-content d-grid gap-2">
            <?php $baseParams = "acct={$company}_Accounts&txn={$company}_Transactions&admin=1&user_id={$selected_user_id}"; ?>
            <a href="report_balance_sheet.php?<?= $baseParams ?>" class="btn btn-view">Balance Sheet</a>
            <a href="report_income_statement.php?<?= $baseParams ?>" class="btn btn-view">Income Statement</a>
            <a href="report_trial_balance.php?<?= $baseParams ?>" class="btn btn-view">Trial Balance</a>
            
            <?php if ($isInactive): ?>
                <button type="button" class="btn btn-warning mt-2" onclick="sendInactivityReminder(<?= $selected_user_id ?>)">
                    <i class="fas fa-bell"></i> Send Inactivity Reminder
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function sendInactivityReminder(clientId) {
    if (confirm('Send inactivity reminder email to this client?')) {
        fetch('check_inactive_clients.php?send_reminder=' + clientId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Inactivity reminder sent successfully!');
                } else {
                    alert('Failed to send reminder: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error sending reminder: ' + error);
            });
    }
}
</script>

<style>
.alert {
    padding: 12px 15px;
    border-radius: 5px;
    margin-bottom: 15px;
    border: 1px solid transparent;
}

.alert-warning {
    color: #856404;
    background-color: #fff3cd;
    border-color: #ffeaa7;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

.alert-info {
    color: #0c5460;
    background-color: #d1ecf1;
    border-color: #bee5eb;
}

.text-warning {
    color: #ffc107 !important;
    font-weight: bold;
}

.text-success {
    color: #28a745 !important;
    font-weight: bold;
}

.btn-warning {
    background-color: #ffc107;
    border-color: #ffc107;
    color: #212529;
}

.btn-warning:hover {
    background-color: #e0a800;
    border-color: #d39e00;
}
</style>