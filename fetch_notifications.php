<?php
// fetch_notifications.php
session_start();
require 'db_connection.php';

// Helper function for time elapsed
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    $string = array('y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second');
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Get cleared notifications
$clearedNotifications = [];
$admin_id = $_SESSION['admin_id'] ?? 1;
try {
    $stmt = $pdo->prepare("SELECT notification_key FROM notification_cleared WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $clearedNotifications = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { }

// Get Clients
$clients = $pdo->query("SELECT `Company Name`, contract_end_date FROM client")->fetchAll(PDO::FETCH_ASSOC);

$realNotifications = [];

// 1. Get Transactions
foreach ($clients as $client) {
    $company = preg_replace('/[^A-Za-z0-9]/', '_', $client['Company Name']);
    $transactionsTable = $company . "_Transactions";
    
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE '$transactionsTable'");
        if ($checkTable->rowCount() > 0) {
			// FIX: Group by date and description to merge double entries (Debit/Credit)
			$stmt = $pdo->prepare("
				SELECT * FROM `$transactionsTable` 
				WHERE date >= NOW() - INTERVAL 1 DAY 
				GROUP BY date, description 
				ORDER BY date DESC
			");
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($transactions as $transaction) {
                $notificationKey = md5('transaction_' . $client['Company Name'] . '_' . $transaction['description'] . '_' . $transaction['amount'] . '_' . $transaction['date']);
                
                // Only add if not cleared
                if (!in_array($notificationKey, $clearedNotifications)) {
                    $realNotifications[] = [
                        'type' => 'transaction',
                        'message' => 'New transaction for ' . $client['Company Name'] . ': ' . $transaction['description'] . ' (' . number_format($transaction['amount'], 2) . ')',
                        'time_raw' => $transaction['date'], // For sorting
                        'time' => time_elapsed_string($transaction['date']),
                        'key' => $notificationKey
                    ];
                }
            }
        }
    } catch (Exception $e) { continue; }
}

// 2. Get Expiring Contracts
$fiveDaysLater = date('Y-m-d', strtotime('+5 days'));
foreach ($clients as $client) {
    if ($client['contract_end_date'] == $fiveDaysLater) {
        $notificationKey = md5('contract_' . $client['Company Name'] . '_' . $fiveDaysLater);
        
        if (!in_array($notificationKey, $clearedNotifications)) {
            $realNotifications[] = [
                'type' => 'contract',
                'message' => $client['Company Name'] . '\'s contract ends in 5 days',
                'time_raw' => date('Y-m-d H:i:s'),
                'time' => 'Upcoming',
                'key' => $notificationKey
            ];
        }
    }
}

// Sort by date descending
usort($realNotifications, function($a, $b) {
    return strtotime($b['time_raw']) - strtotime($a['time_raw']);
});

// Limit to recent 10
$realNotifications = array_slice($realNotifications, 0, 10);

// Calculate Badge Count
$seen_notifications = $_SESSION['notifications_seen'] ?? [];
$badgeCount = 0;
foreach($realNotifications as $notif) {
    if(!in_array($notif['key'], $seen_notifications)) {
        $badgeCount++;
    }
}

echo json_encode([
    'notifications' => $realNotifications,
    'badgeCount' => $badgeCount
]);
?>