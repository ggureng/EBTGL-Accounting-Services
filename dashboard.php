<?php
session_start();

$badgeCount = 0;
if (isset($_SESSION['realNotifications'])) {
    foreach ($_SESSION['realNotifications'] as $notification) {
        if (!isNotificationSeen($notification)) {
            $badgeCount++;
        }
    }
}

require 'db_connection.php';

// Create admin accounts table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('active', 'inactive') DEFAULT 'active'
    )
");

// Create admin audit logs table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT,
        admin_username VARCHAR(100),
        action_type VARCHAR(100) NOT NULL,
        action_description TEXT NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        resource_affected VARCHAR(255),
        old_values TEXT,
        new_values TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES admin_accounts(id) ON DELETE SET NULL
    )
");

// Create bankruptcy filing status table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bankruptcy_filing_status (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        filing_type VARCHAR(100) NOT NULL,
        checklist_completed BOOLEAN DEFAULT FALSE,
        access_granted BOOLEAN DEFAULT FALSE,
        overall_status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES client(id) ON DELETE CASCADE
    )
");

// Create bankruptcy checklist table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bankruptcy_checklist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        section CHAR(1) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        status VARCHAR(50) DEFAULT 'Not Started',
        assigned_person VARCHAR(255),
        due_date DATE,
        file_path VARCHAR(500),
        notes TEXT,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES client(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS notification_cleared (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        notification_key VARCHAR(64) NOT NULL,
        cleared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_notification_clear (admin_id, notification_key)
    )
");

// Function to log admin actions
function logAdminAction($admin_id, $admin_username, $action_type, $action_description, $resource_affected = null, $old_values = null, $new_values = null) {
    global $pdo;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $stmt = $pdo->prepare("
        INSERT INTO admin_audit_logs 
        (admin_id, admin_username, action_type, action_description, ip_address, user_agent, resource_affected, old_values, new_values) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $admin_id,
        $admin_username,
        $action_type,
        $action_description,
        $ip_address,
        $user_agent,
        $resource_affected,
        $old_values,
        $new_values
    ]);
    
    return $pdo->lastInsertId();
}

// Function to approve bankruptcy filing and grant access
function approveBankruptcyFiling($pdo, $company_name, $user_id) {
    $stmt = $pdo->prepare("UPDATE bankruptcy_filing_status SET access_granted = 1, overall_status = 'Approved' WHERE company_name = ? AND user_id = ?");
    return $stmt->execute([$company_name, $user_id]);
}

// Function to get pending bankruptcy filings
function getPendingBankruptcyFilings($pdo) {
    $stmt = $pdo->prepare("
        SELECT bfs.*, c.`Company Name` as company_display_name, c.Email, c.Phone 
        FROM bankruptcy_filing_status bfs 
        JOIN client c ON bfs.user_id = c.id 
        WHERE bfs.checklist_completed = 1 AND bfs.access_granted = 0
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get bankruptcy checklist progress
function getBankruptcyChecklistProgress($pdo, $company_name, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_items
        FROM bankruptcy_checklist 
        WHERE company_name = ? AND user_id = ?
    ");
    $stmt->execute([$company_name, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to get current admin info
function getCurrentAdminInfo() {
    return [
        'id' => $_SESSION['admin_id'] ?? 1,
        'username' => $_SESSION['admin_username'] ?? 'system'
    ];
}

// Function to format time elapsed
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
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

// NEW: Function to check if a notification has been seen
function isNotificationSeen($notification) {
    if (!isset($_SESSION['notifications_seen'])) {
        $_SESSION['notifications_seen'] = [];
    }
    $key = md5($notification['message'] . $notification['time']);
    return in_array($key, $_SESSION['notifications_seen']);
}

// Email sending function
function sendAdminOTPEmail($email, $full_name, $otp) {
    $subject = "🔐 EBTGL Admin Account Verification OTP";
    $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #4682B4, #5a96cf); color: white; padding: 20px; text-align: center; border-radius: 8px; margin-bottom: 20px; }
                .otp-code { font-size: 32px; font-weight: bold; color: #4682B4; text-align: center; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; letter-spacing: 5px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>EBTGL Accounting Services</h2>
                    <h3>Admin Account Verification</h3>
                </div>
                
                <p>Dear $full_name,</p>
                
                <p>Your One-Time Password (OTP) for admin account creation is:</p>
                
                <div class='otp-code'>$otp</div>
                
                <p>This OTP will expire in <strong>10 minutes</strong>.</p>
                
                <p>If you did not request this admin account creation, please ignore this email or contact our support team immediately.</p>
                
                <div class='footer'>
                    <p>Best regards,<br>
                    <strong>EBTGL Accounting Services Team</strong></p>
                    <p><small>This is an automated message. Please do not reply to this email.</small></p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    // Try to use your existing mailer configuration
    if (file_exists('mailer/config.php')) {
        require 'mailer/config.php';
        return sendCustomEmail($email, $subject, $body);
    } 
    // Fallback to config.php if mailer/config.php doesn't exist
    elseif (file_exists('config.php')) {
        require 'config.php';
        if (function_exists('sendCustomEmail')) {
            return sendCustomEmail($email, $subject, $body);
        }
    }
    
    return false;
}

// Function to send welcome email
function sendAdminWelcomeEmail($email, $full_name, $username) {
    $subject = "👋 Welcome to EBTGL Accounting Services - Admin Account Created";
    $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #4682B4, #5a96cf); color: white; padding: 20px; text-align: center; border-radius: 8px; margin-bottom: 20px; }
                .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to EBTGL Accounting Services!</h2>
                </div>
                
                <p>Dear $full_name,</p>
                
                <p>Your admin account has been successfully created and you now have access to the EBTGL Accounting Services admin dashboard.</p>
                
                <div class='details'>
                    <h3>Your Account Details:</h3>
                    <p><strong>Username:</strong> $username</p>
                    <p><strong>Email:</strong> $email</p>
                </div>
                
                <p>You can now access the admin dashboard to:</p>
                <ul>
                    <li>Manage client accounts</li>
                    <li>View financial reports</li>
                    <li>Monitor system activities</li>
                    <li>And much more!</li>
                </ul>
                
                <p><strong>Next Steps:</strong> Log in to the admin dashboard using your credentials to get started.</p>
                
                <div class='footer'>
                    <p>Best regards,<br>
                    <strong>EBTGL Accounting Services Team</strong></p>
                    <p><small>This is an automated message. Please do not reply to this email.</small></p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    // Try to use your existing mailer configuration
    if (file_exists('mailer/config.php')) {
        require 'mailer/config.php';
        return sendCustomEmail($email, $subject, $body);
    } 
    // Fallback to config.php if mailer/config.php doesn't exist
    elseif (file_exists('config.php')) {
        require 'config.php';
        if (function_exists('sendCustomEmail')) {
            return sendCustomEmail($email, $subject, $body);
        }
    }
    
    return false;
}

// Function to calculate client financial metrics
function calculateClientFinancials($company) {
    global $pdo;
    
    $company = preg_replace('/[^A-Za-z0-9]/', '_', $company);
    $accountsTable = $company . "_Accounts";
    $transactionsTable = $company . "_Transactions";
    
    $totalAssets = 0;
    $totalRevenue = 0;
    $totalLiabilities = 0;
    $netIncome = 0;
    
    try {
        // Check if tables exist
        $checkAccounts = $pdo->query("SHOW TABLES LIKE '$accountsTable'");
        $checkTransactions = $pdo->query("SHOW TABLES LIKE '$transactionsTable'");
        
        if ($checkAccounts->rowCount() > 0 && $checkTransactions->rowCount() > 0) {
            // Calculate total assets
            $stmt = $pdo->prepare("
                SELECT SUM(
                    CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END
                ) as balance 
                FROM `$accountsTable` a 
                JOIN `$transactionsTable` t ON a.name = t.account_name 
                WHERE a.type = 'Asset'
            ");
            $stmt->execute();
            $totalAssets = $stmt->fetchColumn() ?: 0;
            
            // Calculate total revenue
            $stmt = $pdo->prepare("
                SELECT SUM(
                    CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END
                ) as balance 
                FROM `$accountsTable` a 
                JOIN `$transactionsTable` t ON a.name = t.account_name 
                WHERE a.type = 'Revenue'
            ");
            $stmt->execute();
            $totalRevenue = $stmt->fetchColumn() ?: 0;
            
            // Calculate total liabilities
            $stmt = $pdo->prepare("
                SELECT SUM(
                    CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END
                ) as balance 
                FROM `$accountsTable` a 
                JOIN `$transactionsTable` t ON a.name = t.account_name 
                WHERE a.type = 'Liability'
            ");
            $stmt->execute();
            $totalLiabilities = $stmt->fetchColumn() ?: 0;
            
            // Calculate net income (Revenue - Expenses)
            $stmt = $pdo->prepare("
                SELECT 
                    (SELECT SUM(CASE WHEN t.entry_type = 'credit' THEN t.amount ELSE -t.amount END)
                     FROM `$accountsTable` a 
                     JOIN `$transactionsTable` t ON a.name = t.account_name 
                     WHERE a.type = 'Revenue') -
                    (SELECT SUM(CASE WHEN t.entry_type = 'debit' THEN t.amount ELSE -t.amount END)
                     FROM `$accountsTable` a 
                     JOIN `$transactionsTable` t ON a.name = t.account_name 
                     WHERE a.type = 'Expense') as net_income
            ");
            $stmt->execute();
            $netIncome = $stmt->fetchColumn() ?: 0;
        }
        
    } catch (Exception $e) {
        // Handle error silently
        error_log("Error calculating financials for $company: " . $e->getMessage());
    }
    
    return [
        'assets' => $totalAssets,
        'revenue' => $totalRevenue,
        'liabilities' => $totalLiabilities,
        'net_income' => $netIncome,
        'score' => ($totalAssets * 0.7) + ($totalRevenue * 0.3)
    ];
}

// NEW: Function to detect bankruptcy risk
function hasBankruptcyRisk($financials) {
    // More conservative bankruptcy detection
    $assets = $financials['assets'];
    $liabilities = $financials['liabilities'];
    $netIncome = $financials['net_income'];
    
    // Bankruptcy indicators:
    // 1. Liabilities significantly exceed assets (insolvency)
    // 2. Consistent negative net income
    // 3. Very low financial score
    
    $debtToAssetRatio = $assets > 0 ? ($liabilities / $assets) : ($liabilities > 0 ? 10 : 0);
    
    // Consider bankrupt if:
    // - Debt-to-asset ratio is very high (> 2.0) OR
    // - Negative net income with high liabilities OR
    // - Very low financial score with negative indicators
    if ($debtToAssetRatio > 2.0) {
        return true; // Severely insolvent
    }
    
    if ($netIncome < 0 && $liabilities > $assets * 0.5) {
        return true; // Losing money with significant debt
    }
    
    if ($financials['score'] < 50000 && $netIncome < 0) {
        return true; // Very low score with losses
    }
    
    return false;
}

// Handle admin account creation with OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_otp') {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $full_name = $_POST['full_name'];
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        // Validate inputs
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format']);
            exit;
        }
        
        // Check if email or username already exists
        $stmt = $pdo->prepare("SELECT id FROM admin_accounts WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email or username already exists']);
            exit;
        }
        
        // Generate OTP (6 digits)
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $_SESSION['admin_otp'] = $otp;
        $_SESSION['admin_data'] = [
            'email' => $email,
            'full_name' => $full_name,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ];
        $_SESSION['otp_expiry'] = time() + 600; // 10 minutes expiry
        
        // Get current admin info for audit log
        $current_admin = getCurrentAdminInfo();
        
        // Log OTP generation
        logAdminAction(
            $current_admin['id'],
            $current_admin['username'],
            'ADMIN_CREATION_OTP_SENT',
            'Generated OTP for new admin account creation',
            'admin_accounts',
            null,
            json_encode(['email' => $email, 'username' => $username, 'full_name' => $full_name])
        );
        
        // Send OTP email using the new function
        $email_sent = sendAdminOTPEmail($email, $full_name, $otp);
        
        if ($email_sent) {
            echo json_encode([
                'success' => true, 
                'message' => 'OTP sent to your email successfully'
            ]);
        } else {
            // If email fails, use debug mode
            echo json_encode([
                'success' => true, 
                'message' => 'OTP generated (Email service temporarily unavailable)',
                'debug_otp' => $otp,
                'email_error' => 'Email service unavailable - using debug mode'
            ]);
        }
        exit;
        
    } elseif ($_POST['action'] === 'verify_otp') {
        $entered_otp = $_POST['otp'];
        
        if (!isset($_SESSION['admin_otp']) || !isset($_SESSION['otp_expiry']) || !isset($_SESSION['admin_data'])) {
            echo json_encode(['success' => false, 'message' => 'OTP session expired. Please start over.']);
            exit;
        }
        
        if (time() > $_SESSION['otp_expiry']) {
            unset($_SESSION['admin_otp'], $_SESSION['admin_data'], $_SESSION['otp_expiry']);
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
            exit;
        }
        
        if ($entered_otp === $_SESSION['admin_otp']) {
            // OTP verified, create admin account
            $admin_data = $_SESSION['admin_data'];
            
            $stmt = $pdo->prepare("INSERT INTO admin_accounts (email, full_name, username, password) VALUES (?, ?, ?, ?)");
            $result = $stmt->execute([
                $admin_data['email'],
                $admin_data['full_name'],
                $admin_data['username'],
                $admin_data['password']
            ]);
            
            if ($result) {
                $new_admin_id = $pdo->lastInsertId();
                
                // Get current admin info for audit log
                $current_admin = getCurrentAdminInfo();
                
                // Log admin account creation
                logAdminAction(
                    $current_admin['id'],
                    $current_admin['username'],
                    'ADMIN_ACCOUNT_CREATED',
                    'Successfully created new admin account',
                    'admin_accounts',
                    null,
                    json_encode([
                        'new_admin_id' => $new_admin_id,
                        'email' => $admin_data['email'],
                        'username' => $admin_data['username'],
                        'full_name' => $admin_data['full_name']
                    ])
                );
                
                // Also log from the new admin's perspective (system action)
                logAdminAction(
                    $new_admin_id,
                    $admin_data['username'],
                    'ACCOUNT_CREATED',
                    'Admin account was created by ' . $current_admin['username'],
                    'admin_accounts',
                    null,
                    json_encode(['created_by' => $current_admin['username']])
                );
                
                // Send welcome email
                $welcome_sent = sendAdminWelcomeEmail(
                    $admin_data['email'], 
                    $admin_data['full_name'], 
                    $admin_data['username']
                );
                
                // Clear session data
                unset($_SESSION['admin_otp'], $_SESSION['admin_data'], $_SESSION['otp_expiry']);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Admin account created successfully!' . 
                                ($welcome_sent ? ' Welcome email sent.' : ' Could not send welcome email.')
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create admin account. Please try again.']);
            }
        } else {
            // Get current admin info for audit log
            $current_admin = getCurrentAdminInfo();
            
            // Log failed OTP attempt
            logAdminAction(
                $current_admin['id'],
                $current_admin['username'],
                'ADMIN_CREATION_OTP_FAILED',
                'Failed OTP verification during admin account creation',
                'admin_accounts',
                null,
                json_encode(['attempted_otp' => $entered_otp])
            );
            
            echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
        }
        exit;
    }
}

// Handle bankruptcy filing approval
if (isset($_POST['approve_bankruptcy_filing'])) {
    $company_name = $_POST['company_name'];
    $user_id = $_POST['user_id'];
    
    if (approveBankruptcyFiling($pdo, $company_name, $user_id)) {
        // Log the action
        $current_admin = getCurrentAdminInfo();
        logAdminAction(
            $current_admin['id'],
            $current_admin['username'],
            'BANKRUPTCY_FILING_APPROVED',
            'Approved bankruptcy filing and granted financial reports access',
            'bankruptcy_filing_status',
            null,
            json_encode(['company_name' => $company_name, 'user_id' => $user_id])
        );
        
        $_SESSION['success_message'] = "Bankruptcy filing approved for " . $company_name . ". Financial reports access granted.";
    } else {
        $_SESSION['error_message'] = "Failed to approve bankruptcy filing.";
    }
    
    header("Location: dashboard.php?section=bankruptcy-filings");
    exit;
}

// Get all clients with their dates
try {
    $clients = $pdo->query("SELECT id, `Company Name`, `Date` FROM client")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
    error_log("Error fetching clients: " . $e->getMessage());
}

// Calculate active and inactive clients
$activeClientsCount = 0;
$inactiveClientsCount = 0;

foreach ($clients as $client) {
    try {
        $dateAdded = new DateTime($client['Date']);
        $endOfContract = clone $dateAdded;
        $endOfContract->add(new DateInterval('P100D'));
        $today = new DateTime();

        if ($endOfContract > $today) {
            $activeClientsCount++;
        } else {
            $inactiveClientsCount++;
        }
    } catch (Exception $e) {
        // In case of invalid date, consider as inactive
        $inactiveClientsCount++;
    }
}

// Calculate client retention rate
$totalClients = count($clients);
$clientRetentionRate = $totalClients > 0 ? round(($activeClientsCount / $totalClients) * 100, 1) : 0;

// Calculate financial scores for each client
$clientScores = [];
foreach ($clients as $client) {
    $financials = calculateClientFinancials($client['Company Name']);
    $clientScores[] = [
        'id' => $client['id'],
        'name' => $client['Company Name'],
        'score' => $financials['score'],
        'assets' => $financials['assets'],
        'revenue' => $financials['revenue'],
        'liabilities' => $financials['liabilities'],
        'net_income' => $financials['net_income']
    ];
}

// Sort clients by score (descending)
usort($clientScores, function($a, $b) {
    return $b['score'] <=> $a['score'];
});

// Get all clients (not just top 10)
$allClients = $clientScores;

// Function to format currency
function fmt($value) {
    if ($value == 0) return '₱0';
    return '₱' . number_format($value);
}

// Get notifications for recent transactions and expiring contracts
$realNotifications = [];
$recentTransactions = [];

// Get cleared notifications from database
$clearedNotifications = [];
try {
    $admin_id = $_SESSION['admin_id'] ?? 1;
    $stmt = $pdo->prepare("SELECT notification_key FROM notification_cleared WHERE admin_id = ?");
    $stmt->execute([$admin_id]);
    $clearedNotifications = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching cleared notifications: " . $e->getMessage());
}

// 1. Get recent transactions (last 24 hours) - BUT filter out cleared ones
foreach ($clients as $client) {
    $company = preg_replace('/[^A-Za-z0-9]/', '_', $client['Company Name']);
    $transactionsTable = $company . "_Transactions";
    
    // Check if table exists
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE '$transactionsTable'");
        if ($checkTable->rowCount() > 0) {
            // Get transactions in the last 24 hours
            $stmt = $pdo->prepare("SELECT * FROM `$transactionsTable` WHERE date >= NOW() - INTERVAL 1 DAY ORDER BY 
            date DESC");
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($transactions as $transaction) {
                $notificationKey = md5('New transaction for ' . $client['Company Name'] . ': ' . 
                                     $transaction['description'] . ' (₱' .
                                     number_format($transaction['amount'], 2) . ')' . $transaction['date']);
                
                // Only add if not cleared
                if (!in_array($notificationKey, $clearedNotifications)) {
                    $recentTransactions[] = [
                        'client' => $client['Company Name'],
                        'transaction' => $transaction,
                        'time' => $transaction['date'],
                        'key' => $notificationKey
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Skip if table doesn't exist or error
        continue;
    }
}

// Add recent transactions to real notifications (limit to 5 most recent)
usort($recentTransactions, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});
$recentTransactions = array_slice($recentTransactions, 0, 5);
foreach ($recentTransactions as $rt) {
    $timeAgo = time_elapsed_string($rt['time']);
    $realNotifications[] = [
        'type' => 'transaction',
        'message' => 'New transaction for ' . $rt['client'] . ': ' .
                     $rt['transaction']['description'] . ' (₱' .
                     number_format($rt['transaction']['amount'], 2) . ')',
        'time' => $timeAgo,
        'key' => $rt['key']
    ];
}

// 2. Get clients with contracts ending in 5 days - BUT filter out cleared ones
try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM client LIKE 'contract_end_date'");
    if ($columnCheck->rowCount() > 0) {
        $fiveDaysLater = date('Y-m-d', strtotime('+5 days'));
        $stmt = $pdo->prepare("SELECT `Company Name` FROM client WHERE contract_end_date = ?");
        $stmt->execute([$fiveDaysLater]);
        $expiringContracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($expiringContracts as $contract) {
            $notificationKey = md5($contract['Company Name'] . '\'s contract ends in 5 days' . $fiveDaysLater);
            
            // Only add if not cleared
            if (!in_array($notificationKey, $clearedNotifications)) {
                $realNotifications[] = [
                    'type' => 'contract',
                    'message' => $contract['Company Name'] . '\'s contract ends in 5 days',
                    'time' => 'Upcoming',
                    'key' => $notificationKey
                ];
            }
        }
    }
} catch (Exception $e) {
    // Skip if column doesn't exist
}

// 3. Get recent messages from clients (last 24 hours) - BUT filter out cleared ones
try {
    $stmt = $pdo->prepare("
        SELECT m.*, c.`Company Name` as company_name 
        FROM messages m 
        JOIN client c ON m.sender_id = c.id 
        WHERE m.sent_at >= NOW() - INTERVAL 1 DAY 
        ORDER BY m.sent_at DESC
    ");
    $stmt->execute();
    $recentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($recentMessages as $message) {
        $notificationKey = md5('New message from ' . $message['company_name'] . ': ' . substr($message['message'], 0, 50) . $message['sent_at']);
        
        // Only add if not cleared
        if (!in_array($notificationKey, $clearedNotifications)) {
            $messagePreview = strlen($message['message']) > 50 ? substr($message['message'], 0, 50) . '...' : $message['message'];
            $realNotifications[] = [
                'type' => 'message',
                'message' => 'New message from ' . $message['company_name'] . ': ' . $messagePreview,
                'time' => time_elapsed_string($message['sent_at']),
                'key' => $notificationKey
            ];
        }
    }
} catch (Exception $e) {
    // Skip if error
    error_log("Error fetching recent messages: " . $e->getMessage());
}

// 4. Get recent audit logs (last 24 hours) - BUT filter out cleared ones
try {
    $stmt = $pdo->prepare("
        SELECT * FROM admin_audit_logs 
        WHERE created_at >= NOW() - INTERVAL 1 DAY 
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $recentAuditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($recentAuditLogs as $log) {
        $notificationKey = md5('Audit: ' . $log['action_type'] . ' - ' . $log['action_description'] . $log['created_at']);
        
        // Only add if not cleared
        if (!in_array($notificationKey, $clearedNotifications)) {
            $realNotifications[] = [
                'type' => 'audit',
                'message' => 'Admin activity: ' . $log['action_type'] . ' - ' . $log['action_description'],
                'time' => time_elapsed_string($log['created_at']),
                'key' => $notificationKey
            ];
        }
    }
} catch (Exception $e) {
    // Skip if error
    error_log("Error fetching recent audit logs: " . $e->getMessage());
}

// Only store new notifications in session (not already seen ones)
if (!isset($_SESSION['notifications_last_seen'])) {
    $_SESSION['notifications_last_seen'] = time();
}

// Filter out notifications that were already seen
// Store ALL notifications in session (both seen and unseen)
// We'll use 'notifications_seen' only for visual indicators, not for filtering
$_SESSION['realNotifications'] = $realNotifications;

// Calculate new (unseen) notifications for badge count
$newNotifications = [];
foreach ($realNotifications as $notification) {
    $key = $notification['key'] ?? md5($notification['message'] . $notification['time']);
    if (!isset($_SESSION['notifications_seen']) || !in_array($key, $_SESSION['notifications_seen'])) {
        $newNotifications[] = $notification;
    }
}

// Create display notifications - show ALL notifications, not just new ones
$notifications = $realNotifications;
if (empty($realNotifications)) {
    $notifications[] = [
        'type' => 'system', 
        'message' => 'No recent activity', 
        'time' => 'Just now',
        'key' => md5('No recent activity' . time())
    ];
}

// Use for badge count - only count new notifications
$badgeCount = count($newNotifications);

// Get additional metrics for admin dashboard
$systemStatus = "Healthy";

// Calculate percentages for active/inactive clients
$activePercentage = $totalClients > 0 ? round(($activeClientsCount / $totalClients) * 100, 2) : 0;
$inactivePercentage = $totalClients > 0 ? round(($inactiveClientsCount / $totalClients) * 100, 2) : 0;

// Get recent audit logs for display
$recentAuditLogs = [];
try {
    $stmt = $pdo->prepare("
        SELECT al.*, aa.username as admin_username 
        FROM admin_audit_logs al 
        LEFT JOIN admin_accounts aa ON al.admin_id = aa.id 
        ORDER BY al.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recentAuditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Handle error silently
    $recentAuditLogs = [];
}

// Get pending bankruptcy filings
$pendingBankruptcyFilings = getPendingBankruptcyFilings($pdo);

// Get approved bankruptcy filings
$approvedBankruptcyFilings = [];
try {
    $stmt = $pdo->prepare("
        SELECT bfs.*, c.`Company Name` as company_display_name 
        FROM bankruptcy_filing_status bfs 
        JOIN client c ON bfs.user_id = c.id 
        WHERE bfs.access_granted = 1
    ");
    $stmt->execute();
    $approvedBankruptcyFilings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $approvedBankruptcyFilings = [];
}

// NEW: Check if there are any bankruptcy filings that need to be initialized
function initializeBankruptcyFiling($pdo, $user_id, $company_name, $filing_type = 'Bankruptcy') {
    // Check if filing status already exists
    $stmt = $pdo->prepare("SELECT id FROM bankruptcy_filing_status WHERE user_id = ? AND company_name = ?");
    $stmt->execute([$user_id, $company_name]);
    
    if ($stmt->fetch()) {
        return; // Already exists
    }
    
    // Create filing status with correct type
    $stmt = $pdo->prepare("INSERT INTO bankruptcy_filing_status (user_id, company_name, filing_type) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $company_name, $filing_type]);
    
    // Create checklist items
    $checklistItems = [
        // Section A: BASIC INFORMATION
        ['A', 'Petition for Bankruptcy', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+7 days'))],
        ['A', 'Verified Bankruptcy Plan', 'Not Started', 'Financial Advisor', date('Y-m-d', strtotime('+14 days'))],
        ['A', 'Schedule of Debts and Liabilities', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+10 days'))],
        ['A', 'Inventory of Assets', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+10 days'))],
        
        // Section B: STATEMENT of INSOLVENCY
        ['B', 'Statement of Financial Condition', 'Not Started', 'CEO/CFO', date('Y-m-d', strtotime('+5 days'))],
        ['B', 'Cash Flow Projections', 'Not Started', 'Financial Analyst', date('Y-m-d', strtotime('+12 days'))],
        ['B', 'Business Plan for Recovery', 'Not Started', 'Management Team', date('Y-m-d', strtotime('+21 days'))],
        
        // Section C: FINANCIAL DOCUMENTS
        ['C', 'Audited Financial Statements (Last 3 years)', 'Not Started', 'External Auditor', date('Y-m-d', strtotime('+7 days'))],
        ['C', 'Interim Financial Statements', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+5 days'))],
        ['C', 'Tax Returns (Last 3 years)', 'Not Started', 'Tax Consultant', date('Y-m-d', strtotime('+7 days'))],
        ['C', 'Bank Statements (Last 12 months)', 'Not Started', 'Treasurer', date('Y-m-d', strtotime('+5 days'))],
        
        // Section D: LEGAL & CORPORATE DOCUMENTS
        ['D', 'Articles of Incorporation/Partnership', 'Not Started', 'Corporate Secretary', date('Y-m-d', strtotime('+3 days'))],
        ['D', 'By-Laws and Amendments', 'Not Started', 'Corporate Secretary', date('Y-m-d', strtotime('+3 days'))],
        ['D', 'SEC Registration Certificate', 'Not Started', 'Corporate Secretary', date('Y-m-d', strtotime('+3 days'))],
        ['D', 'List of Directors/Officers/Partners', 'Not Started', 'Corporate Secretary', date('Y-m-d', strtotime('+3 days'))],
        
        // Section E: PETITION DOCUMENTS
        ['E', 'Board Resolution Authorizing Filing', 'Not Started', 'Corporate Secretary', date('Y-m-d', strtotime('+2 days'))],
        ['E', 'Affidavit of General Facts', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+5 days'))],
        ['E', 'Certificate of Non-Forum Shopping', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+5 days'))],
        
        // Section F: SUPPORTING ATTACHMENTS
        ['F', 'List of Top 20 Creditors', 'Not Started', 'Accountant', date('Y-m-d', strtotime('+8 days'))],
        ['F', 'Projected Recovery Cash Flows', 'Not Started', 'Financial Analyst', date('Y-m-d', strtotime('+15 days'))],
        ['F', 'Management Information Sheet', 'Not Started', 'HR Department', date('Y-m-d', strtotime('+5 days'))],
        
        // Section G: ADDITIONAL DOCUMENTS
        ['G', 'Proof of Publication/Notice to Creditors', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+10 days'))],
        ['G', 'Creditors\' Meeting Minutes', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+20 days'))],
        
        // Section H: POST-FILING TASKS
        ['H', 'Court Approval Documentation', 'Not Started', 'Legal Counsel', date('Y-m-d', strtotime('+30 days'))],
        ['H', 'Implementation of Bankruptcy Plan', 'Not Started', 'Management Team', date('Y-m-d', strtotime('+45 days'))]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO bankruptcy_checklist (user_id, company_name, section, item_name, status, assigned_person, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($checklistItems as $item) {
        $stmt->execute([$user_id, $company_name, $item[0], $item[1], $item[2], $item[3], $item[4]]);
    }
}

// NEW: Initialize bankruptcy filing ONLY for clients with actual bankruptcy risk
foreach ($clients as $client) {
    // Check if client has actual bankruptcy risk using improved detection
    $financials = calculateClientFinancials($client['Company Name']);
    if (hasBankruptcyRisk($financials)) {
        initializeBankruptcyFiling($pdo, $client['id'], $client['Company Name'], 'Bankruptcy');
    }
}

// Function to get last inactivity check time
function getLastInactivityCheckTime() {
    global $pdo;
    
    try {
        // Check if the table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'inactivity_checks'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $pdo->query("SELECT MAX(check_time) as last_check FROM inactivity_checks");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['last_check']) {
                $lastCheck = new DateTime($result['last_check']);
                $now = new DateTime();
                $hoursAgo = $now->diff($lastCheck)->h;
                
                return [
                    'raw' => $result['last_check'],
                    'formatted' => $lastCheck->format('M j, Y g:i A'),
                    'hours_ago' => $hoursAgo
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error getting last inactivity check: " . $e->getMessage());
    }
    
    return null;
}

// Get last inactivity check time
$lastInactivityCheck = getLastInactivityCheckTime();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EBTGL Accounting Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Include ApexCharts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@latest/dist/apexcharts.min.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@latest"></script>
    <!-- Include Bootstrap for modal -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            transition: all 0.3s ease;
            opacity: 0;
        }

        .back-to-top.show {
            display: flex;
            opacity: 1;
            animation: fadeInUp 0.3s ease;
        }

        .back-to-top.hiding {
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        .back-to-top:hover {
            transform: translateX(-50%) translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.2);
            background: linear-gradient(135deg, #3a6a94, #4c7db8);
        }
        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f9fc;
            color: #333;
            overflow-x: hidden;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #2c3e50, #4a6583);
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .sidebar-header img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid white;
        }
        
        .sidebar-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .menu-section {
            margin-bottom: 25px;
        }
        
        .menu-title {
            padding: 0 20px 10px;
            font-size: 0.85rem;
            color: rgba(255,255,255,0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .menu-items {
            list-style: none;
        }
        
        .menu-item {
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            border-left: 3px solid transparent;
        }
        
        .menu-item:hover {
            background-color: rgba(255,255,255,0.1);
            border-left-color: #3498db;
        }
        
        .menu-item.active {
            background-color: rgba(255,255,255,0.15);
            border-left-color: #3498db;
        }
        
        .menu-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }
        
        .admin-actions {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
        }
        
        .admin-action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 12px 15px;
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .admin-action-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .admin-action-btn i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .top-bar-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .top-bar-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        /* Notification Styles */
        .notification-container {
            position: relative;
			display: inline-block;
        }
        
        .notification-bell {
            position: relative;
			display: inline-block
            cursor: pointer;
            color: #4a5568;
            font-size: 20px;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .notification-bell:hover {
            background-color: #f1f5f9;
            color: #2c3e50;
        }
        
		.notification-badge {
			position: absolute;
			top: -5px;  
			right: -5px; 
			background-color: #e74c3c;
			color: white;
			border-radius: 50%;
			width: 20px;
			height: 20px;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 11px;
			font-weight: bold;
			border: 2px solid white; 
			z-index: 1001; 
		}
		
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            width: 350px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }
        
        .notification-dropdown.open {
            display: block;
        }
		
		@keyframes fadeIn {
			from { opacity: 0; transform: translateY(-10px);  }
			to { opacity: 1; transform: translateY(0); }
		}
        
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notification-header h3 {
            font-size: 16px;
            color: #2c3e50;
            margin: 0;
        }
        
        .notification-clear {
            color: #3498db;
            cursor: pointer;
            font-size: 14px;
        }
        
        .notification-list {
            max-height: 300px;
            overflow-y: auto;
			display: flex;
			flex-direction: column;
        }
		
		.notification-list {
			scroll-behavior: smooth;
		}
        
        .notification-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: #f8fafc;
        }
        
        .notification-item.unread {
            background-color: #f0f7ff;
        }
        
        .notification-content {
            display: flex;
            justify-content: space-between;
        }
        
        .notification-message {
            font-size: 14px;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .notification-time {
            font-size: 12px;
            color: #718096;
        }
        
        .notification-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-right: 5px;
        }
        
		.unseen-indicator {
			color: #e74c3c;
			font-size: 16px;
			margin-right: 5px;
			animation: pulse 2s infinite;
		}
		
        .type-deadline {
            background-color: #ffeaa7;
            color: #d35400;
        }
        
        .type-task {
            background-color: #d6eaf8;
            color: #2874a6;
        }
        
        .type-system {
            background-color: #d5f5e3;
            color: #1e8449;
        }
        
        .type-client {
            background-color: #e8daef;
            color: #6c3483;
        }
        
        .type-transaction {
            background-color: #d6eaf8;
            color: #2874a6;
        }
        
        .type-contract {
            background-color: #fadbd8;
            color: #c0392b;
        }
        
        .type-message {
            background-color: #e8f4fd;
            color: #1a73e8;
        }
        
        .type-audit {
            background-color: #fff3cd;
            color: #856404;
        }
        
        /* Dashboard Content */
        .dashboard-content {
            padding: 0;
        }
        
        /* Metrics Grid - Updated for larger client card */
        .metrics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            grid-template-rows: auto auto;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        /* Make the first card (clients) span two rows */
        .metric-card:first-child {
            grid-row: span 2;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        
        .metric-title {
            font-size: 1rem;
            font-weight: 500;
            color: #718096;
            margin-bottom: 10px;
        }
        
        .metric-value {
            font-size: 2.2rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .metric-trend {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
            color: #38a169;
        }
        
        .metric-trend.down {
            color: #e53e3e;
        }
        
        .metric-subtext {
            font-size: 0.85rem;
            color: #718096;
            margin-top: 5px;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        .chart-legend {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            justify-content: center;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        /* Full-width chart */
        .full-chart-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        /* NEW: Dynamic Content Area */
        .dynamic-content-area {
            background: white;
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            min-height: 600px;
            display: none;
        }
        
        .dynamic-content-header {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .dynamic-content-title {
            font-weight: 600;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .dynamic-content-body {
            padding: 0;
            height: 500px;
        }
        
        .dynamic-iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 0 0 12px 12px;
        }
        
        .dynamic-content-actions {
            display: flex;
            gap: 10px;
        }
        
        .dynamic-content-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .dynamic-content-btn.back {
            background: #6c757d;
            color: white;
        }
        
        .dynamic-content-btn.print {
            background: #28a745;
            color: white;
        }
        
        .dynamic-content-btn.close {
            background: #dc3545;
            color: white;
        }
        
        .dynamic-content-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        /* Popup Styles */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        
        .popup-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
        }
        
        .popup-close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .popup-title {
            color: #4682B4;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .popup-field {
            width: 100%;
            padding: 12px 15px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        .popup-field:focus {
            border-color: #4682B4;
            outline: none;
        }
        
        .popup-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .popup-btn:hover {
            background: linear-gradient(135deg, #3a6a94, #4c7db8);
        }
        
        .error-message {
            color: #e74c3c;
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
        }
        
        .success-message {
            color: #27ae60;
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
        }
        
        .hidden {
            display: none !important;
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .metrics-grid, .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .metric-card:first-child {
                grid-row: span 1;
            }
        }
        
        @media (max-width: 992px) {
            .sidebar {
                width: 80px;
                overflow: visible;
            }
            
            .sidebar-header h2, .menu-title, .menu-item span, .admin-action-btn span {
                display: none;
            }
            
            .sidebar-header {
                justify-content: center;
                padding: 20px 10px;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px 10px;
            }
            
            .admin-action-btn {
                justify-content: center;
                padding: 15px 10px;
            }
            
            .main-content {
                margin-left: 80px;
            }
        }
        
        @media (max-width: 768px) {
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .top-bar-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .notification-dropdown {
                width: 300px;
                right: -50px;
            }
        }
        
        @media (max-width: 480px) {
            .sidebar {
                width: 60px;
            }
            
            .main-content {
                margin-left: 60px;
                padding: 15px;
            }
            
            .notification-dropdown {
                width: 280px;
                right: -80px;
            }
        }

        /* Top Clients Styles - Updated for larger size and search */
        .client-search-container {
            margin-bottom: 15px;
            position: relative;
        }
        
        .client-search {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            padding-left: 40px;
            box-sizing: border-box;
        }
        
        .client-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }
        
        .top-clients {
            max-height: 400px; /* Increased height for larger card */
            overflow-y: auto;
            padding-right: 10px;
        }
        
        .client-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .client-rank {
            font-weight: bold;
            margin-right: 15px;
            min-width: 30px;
            font-size: 1.1em;
            color: #4a6583;
        }

        .client-name {
            flex-grow: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 1.05em;
            padding-right: 20px; /* Added padding to create space */
        }

        .client-value {
            font-weight: 600;
            min-width: 120px; /* Increased minimum width */
            text-align: right;
            font-size: 1.05em;
            color: #2c3e50;
        }

        /* Top client highlighting */
        .top-client {
            font-size: 1.15em;
            font-weight: 700;
            color: #2c3e50;
            background-color: rgba(52, 152, 219, 0.05);
            border-radius: 8px;
            padding: 12px 15px;
            margin: 5px 0;
        }

        .top-client .client-rank {
            font-size: 1.2em;
            color: #3498db;
        }

        .top-client .client-value {
            color: #27ae60;
            font-weight: 700;
        }

        /* Hover effect for all client rows */
        .client-row:hover {
            background-color: rgba(52, 152, 219, 0.08);
        }
        
        .text-center {
            text-align: center;
        }
        
        .py-3 {
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
        
        /* Highlight selected client */
        .selected-client {
            background-color: rgba(70, 130, 180, 0.1);
            border-left: 4px solid #4682B4;
        }
        
        /* No results message */
        .no-results {
            text-align: center;
            padding: 20px;
            color: #718096;
            font-style: italic;
        }

        /* Services Section Styles */
        .section-container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
        }
        
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .service-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #e2e8f0;
        }
        
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .service-card h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
        }
        
        .service-card p {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge {
            background-color: #c6f6d5;
            color: #22543d;
        }
        
        /* About Section Styles */
        .about-content {
            margin-top: 20px;
            position: relative;
        }
        
        .about-text p {
            margin-bottom: 20px;
            line-height: 1.7;
            color: #4a5568;
        }
        
        .about-highlights {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .highlight-card {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #4682B4;
        }
        
        .highlight-card h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .highlight-card p {
            color: #4a5568;
            line-height: 1.6;
        }

        /* System status indicator */
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-healthy {
            background-color: #2ecc71;
        }
        
        .status-warning {
            background-color: #f39c12;
        }
        
        .status-error {
            background-color: #e74c3c;
        }
        
        /* User Profile Dropdown */
        .user-profile {
            position: relative;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #4682B4;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            cursor: pointer;
        }
        
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            min-width: 200px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .user-dropdown.open {
            display: block;
        }
        
        .user-dropdown-item {
            padding: 12px 20px;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-dropdown-item:hover {
            background-color: #f8f9fa;
        }
        
        .user-dropdown-divider {
            height: 1px;
            background-color: #e9ecef;
            margin: 5px 0;
        }

        /* Audit Trail Styles */
        .audit-trail-section {
            margin-top: 30px;
        }

        .audit-log-item {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid #4682B4;
        }

        .audit-log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .audit-log-action {
            font-weight: 600;
            color: #2c3e50;
        }

        .audit-log-time {
            font-size: 0.85rem;
            color: #718096;
        }

        .audit-log-admin {
            color: #4682B4;
            font-weight: 500;
        }

        .audit-log-description {
            color: #4a5568;
            margin-bottom: 8px;
        }

        .audit-log-details {
            font-size: 0.85rem;
            color: #718096;
        }

        .audit-log-resource {
            background: #f7fafc;
            padding: 8px 12px;
            border-radius: 4px;
            margin-top: 8px;
            border-left: 3px solid #e2e8f0;
        }

        .view-audit-logs-btn {
            background: linear-gradient(135deg, #718096, #4a5568);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .view-audit-logs-btn:hover {
            background: linear-gradient(135deg, #4a5568, #2d3748);
            transform: translateY(-2px);
        }

        /* Bankruptcy Filings Styles */
        .bankruptcy-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .bankruptcy-table th,
        .bankruptcy-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .bankruptcy-table th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .bankruptcy-table tr:hover {
            background-color: #f8fafc;
        }
        
        .progress {
            height: 20px;
            background-color: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background-color: #38a169;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }
        
        .btn-group {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
        }
        
        .btn-success {
            background-color: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #219653;
        }
        
        .btn-warning {
            background-color: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #e67e22;
        }
        
        .bg-success {
            background-color: #27ae60 !important;
        }
        
        .bg-warning {
            background-color: #f39c12 !important;
        }
        
        .bg-secondary {
            background-color: #95a5a6 !important;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background-color: #d6eaf8;
            color: #2874a6;
            border-left: 4px solid #3498db;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f8f9fa;
        }
        
        .table-hover tbody tr:hover {
            background-color: #e9ecef;
        }

        /* Inactivity Monitor Styles */
        .inactivity-monitor-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            border-left: 4px solid #3498db;
        }
        
        .inactivity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .inactivity-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }
        
        .inactivity-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: #d5f5e3;
            color: #1e8449;
        }
        
        .status-inactive {
            background-color: #fadbd8;
            color: #c0392b;
        }
        
        .inactivity-content {
            margin-bottom: 20px;
        }
        
        .last-check-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .check-time {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .check-details {
            color: #718096;
            font-size: 0.9rem;
        }
        
        .inactivity-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .action-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }
        
        .action-card:hover {
            background: #e3f2fd;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .action-icon {
            font-size: 24px;
            margin-bottom: 10px;
            color: #3498db;
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .action-description {
            font-size: 0.85rem;
            color: #718096;
            margin-bottom: 15px;
        }
        
        .action-btn {
            width: 100%;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-run-check {
            background: #3498db;
            color: white;
        }
        
        .btn-run-check:hover {
            background: #2980b9;
        }
        
        .btn-view-results {
            background: #95a5a6;
            color: white;
        }
        
        .btn-view-results:hover {
            background: #7f8c8d;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="images/NXT.png" alt="EBTGL Accounting">
            <h2>EBTGL Accounting</h2>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-section">
                <div class="menu-title">Main Navigation</div>
                <ul class="menu-items">
                    <li class="menu-item active" onclick="showSection('home')">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </li>
                    <li class="menu-item" onclick="showSection('services')">
                        <i class="fas fa-cogs"></i>
                        <span>Services</span>
                    </li>
                    <li class="menu-item" onclick="showSection('about')">
                        <i class="fas fa-info-circle"></i>
                        <span>About</span>
                    </li>
                    <li class="menu-item" onclick="showSection('bankruptcy-filings')">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Bankruptcy Filings</span>
                    </li>
            </div>
            
            <div class="admin-actions">
                <button class="admin-action-btn" onclick="showDynamicContent('choose-client-modal.php', 'Choose Client')">
                    <i class="fas fa-users"></i>
                    <span>Choose Client</span>
                </button>
                <button class="admin-action-btn" onclick="showDynamicContent('client_journal_summary.php', 'Client Journal Summary')">
            		<i class="fas fa-users"></i>
           		 	<span>Journal Summary</span>
        		</button>
                <button class="admin-action-btn" onclick="showDynamicContent('manage-client.php', 'Manage Client')">
                    <i class="fas fa-user-cog"></i>
                    <span>Manage Client</span>
                </button>
                <button class="admin-action-btn" onclick="showDynamicContent('calendar_admin.php', 'Appointment Calendar')">
                    <i class="fas fa-calendar-alt"></i>
                    <span>View Calendar</span>
                </button>
                <button class="admin-action-btn" onclick="showDynamicContent('account_requests.php', 'Account Requests')">
                    <i class="fas fa-user-plus"></i>
                    <span>Account Requests</span>
                </button>
                <button class="admin-action-btn" onclick="showDynamicContent('admin_messages.php', 'Messages')">
                    <i class="fas fa-comments"></i>
                    <span>Messages</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar - Will be hidden when in dynamic content -->
        <div class="top-bar" id="topBar">
            <h1 class="top-bar-title" id="page-title">Executive Dashboard</h1>
            <div class="top-bar-actions">
                <!-- Notification Bell -->
               <div class="notification-container">
                  <div class="notification-bell" onclick="toggleNotifications(event)">
                     <i class="fas fa-bell"></i>
                     <?php if ($badgeCount > 0): ?>
                        <span class="notification-badge"><?php echo $badgeCount; ?></span>
                     <?php endif; ?>
                  </div>
                  <div class="notification-dropdown" id="notificationDropdown">
                     <div class="notification-header">
                        <h3>Notifications</h3>
                        <?php if (count($realNotifications) > 0): ?>
                           <span class="notification-clear" onclick="clearNotifications()">Clear All</span>
						<?php else: ?>
							<span class="notification-clear" style="display: none;" onclick="clearNotifications()">Clear All</span>
                        <?php endif; ?>
                     </div>
                     <div class="notification-list">
                        <?php foreach ($notifications as $index => $notification): 
                            $key = $notification['key'] ?? md5($notification['message'] . $notification['time']);
                            $isSeen = isset($_SESSION['notifications_seen']) && in_array($key, $_SESSION['notifications_seen']);
                            $isReal = in_array($notification, $realNotifications);
                        ?>
                        <div class="notification-item <?= (!$isSeen && $isReal) ? 'unread' : '' ?>" 
                            onclick="handleNotification('<?php echo $notification['type']; ?>', this)">
                            <div class="notification-content">
                                <div>
                                    <span class="notification-type type-<?php echo $notification['type']; ?>">
                                        <?php echo ucfirst($notification['type']); ?>
                                    </span>
                                    <?php if (!$isSeen && $isReal): ?>
                                        <span class="unseen-indicator">●</span>
                                    <?php endif; ?>
                                    <div class="notification-message"><?php echo $notification['message']; ?></div>
                                </div>
                                <div class="notification-time"><?php echo $notification['time']; ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                     </div>
                  </div>
               </div>
                
                <!-- User Profile -->
                <div class="user-profile">
                    <div class="user-avatar" onclick="toggleUserDropdown()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="user-dropdown" id="userDropdown">
                        <div class="user-dropdown-item" onclick="showEditAdminPopup()">
                            <i class="fas fa-user-edit"></i>
                            <span>Edit Admin Account</span>
                        </div>
                        <div class="user-dropdown-item" onclick="showCreateAdminPopup()">
                            <i class="fas fa-user-plus"></i>
                            <span>Create Admin Account</span>
                        </div>
                        <div class="user-dropdown-item" onclick="showAuditTrail()">
                            <i class="fas fa-history"></i>
                            <span>View Audit Trail</span>
                        </div>
                        <div class="user-dropdown-divider"></div>
                        <div class="user-dropdown-item" onclick="logout()">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Log Out</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Dashboard Section -->
            <div id="home">
                <div class="metrics-grid">
                    <!-- Top Clients Card - Now larger and with search -->
                    <div class="metric-card">
                        <div class="metric-title">All Clients (Ranked by Performance)</div>
                        <div class="client-search-container">
                            <i class="fas fa-search client-search-icon"></i>
                            <input type="text" id="clientSearch" class="client-search" placeholder="Search clients..." onkeyup="filterClients()">
                        </div>
                        <div class="top-clients" id="clientsContainer">
                            <?php if (count($allClients) > 0): ?>
                                <?php foreach ($allClients as $index => $client): ?>
                                    <div class="client-row <?= $index === 0 ? 'top-client' : '' ?>" 
                                         data-client-id="<?= $client['id'] ?>" 
                                         data-client-name="<?= htmlspecialchars($client['name']) ?>" 
                                         data-client-assets="<?= $client['assets'] ?>" 
                                         data-client-revenue="<?= $client['revenue'] ?>"
                                         onclick="selectClient(this, '<?= htmlspecialchars($client['name']) ?>', <?= $client['assets'] ?>, <?= $client['revenue'] ?>)">
                                        <div class="client-rank"><?= $index + 1 ?>.</div>
                                        <div class="client-name" title="<?= htmlspecialchars($client['name']) ?>">
                                            <?= htmlspecialchars($client['name']) ?>
                                        </div>
                                        <div class="client-value"><?= fmt($client['score']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">No client data available</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Updated metrics for accounting system - Client Retention Rate -->
                    <div class="metric-card">
                        <div class="metric-title">Client Retention Rate</div>
                        <div class="metric-value"><?php echo $clientRetentionRate; ?>%</div>
                        <div class="metric-trend">
                            <span>&nbsp;</span>
                        </div>
                        <div class="metric-subtext">Based on active clients</div>
                    </div>
                    
                    <!-- Updated: Active Clients -->
                    <div class="metric-card">
                        <div class="metric-title">Active Clients</div>
                        <div class="metric-value"><?php echo $activeClientsCount; ?></div>
                        <div class="metric-trend">
                            <span>&nbsp;</span>
                        </div>
                        <div class="metric-subtext"><?php echo $activePercentage; ?>% of total clients</div>
                    </div>
                    
                    <!-- Updated: Inactive Clients (previously Pending Tasks) -->
                    <div class="metric-card">
                        <div class="metric-title">Inactive Clients</div>
                        <div class="metric-value"><?php echo $inactiveClientsCount; ?></div>
                        <div class="metric-trend">
                            <span>&nbsp;</span>
                        </div>
                        <div class="metric-subtext"><?php echo $inactivePercentage; ?>% of total clients</div>
                    </div>
                    
                    <!-- Updated: Revenue Growth -> System Health -->
                    <div class="metric-card">
                        <div class="metric-title">Firm Health</div>
                        <div class="metric-value">
                            <span class="status-indicator status-<?php echo strtolower($systemStatus); ?>"></span>
                            <?php echo $systemStatus; ?>
                        </div>
                        <div class="metric-trend">
                            <span>Stable</span>
                        </div>
                        <div class="metric-subtext">all systems operational</div>
                    </div>
                </div>
                
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title" id="pageViewsTitle">Financial Summary</h3>
                        </div>
                        <div class="chart-container" id="pageViewsChart"></div>
                        <div class="chart-legend" id="pageViewsLegend">
                            <!-- Legend will be dynamically updated -->
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title" id="mrrCountryTitle">Revenue Distribution</h3>
                        </div>
                        <div class="chart-container" id="mrrCountryChart"></div>
                    </div>
                </div>
                
                <!-- Inactivity Monitor Card - Moved here -->
                <div class="inactivity-monitor-card">
                    <div class="inactivity-header">
                        <h3 class="inactivity-title">
                            <i class="fas fa-bell"></i> Inactivity Monitor
                        </h3>
                        <div class="inactivity-status">
                            <span class="status-badge <?= $lastInactivityCheck ? 'status-active' : 'status-inactive' ?>">
                                <?= $lastInactivityCheck ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="inactivity-content">
                        <?php if ($lastInactivityCheck): ?>
                            <div class="last-check-info">
                                <div class="check-time">
                                    <i class="fas fa-clock"></i> Last Check: <?= $lastInactivityCheck['formatted'] ?>
                                </div>
                                <div class="check-details">
                                    Checked <?= $lastInactivityCheck['hours_ago'] ?> hours ago | System monitoring client activity
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="last-check-info">
                                <div class="check-time text-warning">
                                    <i class="fas fa-exclamation-triangle"></i> No automatic checks have been run yet
                                </div>
                                <div class="check-details">
                                    Automatic monitoring will start when the system performs its first check
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="inactivity-actions">
                            <div class="action-card">
                                <div class="action-icon">
                                    <i class="fas fa-sync-alt"></i>
                                </div>
                                <div class="action-title">Run Manual Check</div>
                                <div class="action-description">
                                    Check all clients and send notifications immediately
                                </div>
                                <button class="action-btn btn-run-check" onclick="runManualCheck()">
                                    <i class="fas fa-play"></i> Run Check Now
                                </button>
                            </div>
                            
                            <div class="action-card">
                                <div class="action-icon">
                                    <i class="fas fa-chart-bar"></i>
                                </div>
                                <div class="action-title">View Check Results</div>
                                <div class="action-description">
                                    See detailed results of the latest inactivity check
                                </div>
                                <button class="action-btn btn-view-results" onclick="viewCheckResults()">
                                    <i class="fas fa-eye"></i> View Results
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Audit Trail Section -->
                <div class="full-chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Recent Admin Activities</h3>
                        <button class="view-audit-logs-btn" onclick="showAuditTrail()">
                            <i class="fas fa-history"></i> View Full Audit Trail
                        </button>
                    </div>
                    <div class="audit-trail-section">
                        <?php if (count($recentAuditLogs) > 0): ?>
                            <?php foreach ($recentAuditLogs as $log): ?>
                                <div class="audit-log-item">
                                    <div class="audit-log-header">
                                        <span class="audit-log-action"><?php echo htmlspecialchars($log['action_type']); ?></span>
                                        <span class="audit-log-time"><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></span>
                                    </div>
                                    <div class="audit-log-description">
                                        <?php echo htmlspecialchars($log['action_description']); ?>
                                    </div>
                                    <div class="audit-log-details">
                                        By: <span class="audit-log-admin"><?php echo htmlspecialchars($log['admin_username'] ?: 'System'); ?></span>
                                        <?php if ($log['resource_affected']): ?>
                                            | Resource: <?php echo htmlspecialchars($log['resource_affected']); ?>
                                        <?php endif; ?>
                                        <?php if ($log['ip_address']): ?>
                                            | IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($log['new_values']): ?>
                                        <div class="audit-log-resource">
                                            <strong>Changes:</strong> <?php echo htmlspecialchars($log['new_values']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <p>No audit logs available yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Services Section -->
            <div id="services" style="display:none;">
                <div class="section-container">
                    <h2 class="section-title">Our Accounting Services</h2>
                    <p>We provide comprehensive financial solutions tailored to your business needs. Our expert team ensures accuracy, compliance and strategic insights for your financial success.</p>
                    
                    <div class="services-grid">
                        <div class="service-card">
                            <h3>Bookkeeping</h3>
                            <p>Accurate recording of financial transactions to keep your business finances organized and compliant.</p>
                            <span class="badge">Available Now</span>
                        </div>
                        <div class="service-card">
                            <h3>Financial Reporting</h3>
                            <p>Detailed financial statements and performance analysis to help you make informed business decisions.</p>
                            <span class="badge">Available Now</span>
                        </div>
                        <div class="service-card">
                            <h3>Charts of Accounts</h3>
                            <p>Customized accounting framework to categorize all your business transactions effectively.</p>
                            <span class="badge">Available Now</span>
                        </div>
                        <div class="service-card">
                            <h3>Budget Management</h3>
                            <p>Strategic planning and monitoring of financial resources to achieve your business objectives.</p>
                            <span class="badge">Coming Soon</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- About Section -->
            <div id="about" style="display:none;">
                <div class="section-container">
                    <h2 class="section-title">About EBTGL Accounting Services</h2>
                    <div class="about-content">
                        <div class="about-text">
                            <p>EBTGL Accounting Services is built on a strong foundation of trust, professionalism, and client satisfaction. Founded in 2010, we've grown from a small team of dedicated accountants to a leading firm serving businesses across the region.</p>

                            <p>Established in 1984, E.B TAROBAL COMPANY GENERAL PROFESSIONAL PARTNERSHIP has grown into a trusted provider of professional accounting and consulting services. Initially operating as a family-targeted business, the firm was formally registered with the Securities and Exchange Commission (SEC) in 1998 as a general professional partnership.</p>

                            <p> Over the years, it has built a strong reputation by offering a comprehensive range of services, including bookkeeping, internal & external auditing, management consulting, tax consulting & etc.. Committed to community development, the firm actively hires and organizes staffing by engaging local talent, particularly individuals seeking employment within barangay communities.</p>

                            <p> To meet the demands of the audit season, project-based auditors are contracted to support external audit engagements, while tax season operations run from January to April 30. Throughout the rest of the year, the firm focuses on providing consulting services through structured retainership arrangements, ensuring continuous support for its clientele.</p>
                        
                            <div class="about-highlights">
                                <div class="highlight-card">
                                    <h4>Our Vision</h4>
                                    <p>To be the leading provider of innovative accounting solutions for growing businesses.</p>
                                </div>
                                <div class="highlight-card">
                                    <h4>Core Values</h4>
                                    <p>Integrity, Excellence, Innovation, and Client Focus.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bankruptcy Filings Section -->
            <div id="bankruptcy-filings" style="display:none;">
                <div class="section-container">
                    <h2 class="section-title">Bankruptcy Filings Management</h2>

                    <!-- Pending Filings -->
                    <div class="card">
                        <div class="card-header">
                            <h4 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Pending Bankruptcy Filings</h4>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pendingBankruptcyFilings)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>No pending bankruptcy filings for approval.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Company</th>
                                                <th>Filing Type</th>
                                                <th>Checklist Progress</th>
                                                <th>Contact Info</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pendingBankruptcyFilings as $filing): 
                                                $progress = getBankruptcyChecklistProgress($pdo, $filing['company_name'], $filing['user_id']);
                                                $completionRate = $progress['total_items'] > 0 ? 
                                                    round(($progress['completed_items'] / $progress['total_items']) * 100) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($filing['company_display_name']) ?></strong>
                                                </td>
                                                <td><?= htmlspecialchars($filing['filing_type']) ?></td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-success" role="progressbar" 
                                                             style="width: <?= $completionRate ?>%;" 
                                                             aria-valuenow="<?= $completionRate ?>" 
                                                             aria-valuemin="0" aria-valuemax="100">
                                                            <?= $completionRate ?>%
                                                        </div>
                                                    </div>
                                                    <small><?= $progress['completed_items'] ?>/<?= $progress['total_items'] ?> items</small>
                                                </td>
                                                <td>
                                                    <small>
                                                        <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($filing['Email']) ?><br>
                                                        <i class="fas fa-phone me-1"></i><?= htmlspecialchars($filing['Phone']) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning">Pending Approval</span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-primary" 
                                                                onclick="viewChecklistDetails('<?= $filing['company_name'] ?>', <?= $filing['user_id'] ?>)">
                                                            <i class="fas fa-eye me-1"></i> Review
                                                        </button>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="company_name" value="<?= $filing['company_name'] ?>">
                                                            <input type="hidden" name="user_id" value="<?= $filing['user_id'] ?>">
                                                            <button type="submit" name="approve_bankruptcy_filing" 
                                                                    class="btn btn-sm btn-success"
                                                                    onclick="return confirm('Are you sure you want to approve the bankruptcy filing for <?= htmlspecialchars($filing['company_display_name']) ?>? This will grant them access to financial reports.')">
                                                                <i class="fas fa-check me-1"></i> Approve
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Approved Filings -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Approved Bankruptcy Filings</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($approvedBankruptcyFilings)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>No approved bankruptcy filings.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Company</th>
                                                <th>Filing Type</th>
                                                <th>Approval Date</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($approvedBankruptcyFilings as $filing): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($filing['company_display_name']) ?></td>
                                                <td><?= htmlspecialchars($filing['filing_type']) ?></td>
                                                <td><?= date('M d, Y', strtotime($filing['updated_at'])) ?></td>
                                                <td><span class="badge bg-success">Approved</span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- NEW: Audit Trail Content Area -->
            <div id="audit-trail-content" class="dynamic-content-area">
                <div class="dynamic-content-header">
                    <h3 class="dynamic-content-title">
                        <i class="fas fa-history"></i> Audit Trail - Recent Admin Activities
                    </h3>
                    <div class="dynamic-content-actions">
                        <button class="dynamic-content-btn print" onclick="printAuditTrail()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="dynamic-content-btn close" onclick="hideAuditTrail()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
                <div class="dynamic-content-body">
                    <div id="audit-trail-container" style="padding: 20px; height: 100%; overflow-y: auto;">
                        <!-- Audit trail content will be loaded here -->
                    </div>
                </div>
            </div>

            
            <!-- NEW: Dynamic Content Area -->
            <div id="dynamic-content-area" class="dynamic-content-area">
                <div class="dynamic-content-header">
                    <h3 class="dynamic-content-title" id="dynamic-content-title">
                        <i class="fas fa-file"></i> Dynamic Content
                    </h3>
                    <div class="dynamic-content-actions">
                        <button class="dynamic-content-btn print" onclick="printIframeContent()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="dynamic-content-btn close" onclick="hideDynamicContent()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
                <div class="dynamic-content-body">
                    <iframe id="dynamic-iframe" class="dynamic-iframe" src="about:blank"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Admin Popups -->
    <div id="email-verification-popup" class="popup-overlay hidden">
        <div class="popup-container">
            <span class="popup-close" onclick="closeEmailPopup()">&times;</span>
            <h2 class="popup-title">Verify Admin Email</h2>
            <input type="email" id="admin-email" class="popup-field" placeholder="Enter your admin email" required>
            <button class="popup-btn" onclick="verifyAdminEmail()">Verify Email</button>
            <div id="email-error" class="error-message hidden">Invalid admin email. Please try again.</div>
        </div>
    </div>

    <div id="update-credentials-popup" class="popup-overlay hidden">
        <div class="popup-container">
            <span class="popup-close" onclick="closeUpdatePopup()">&times;</span>
            <h2 class="popup-title">Update Admin Account</h2>
            <input type="text" id="new-username" class="popup-field" placeholder="New username" required>
            <input type="password" id="new-password" class="popup-field" placeholder="New password" required>
            <button class="popup-btn" onclick="updateAdminAccount()">Update Account</button>
            <div id="update-success" class="success-message" style="display: none;">
                Account updated successfully! You will be logged out.
            </div>
        </div>
    </div>

    <!-- Create Admin Account Popups -->
    <div id="create-admin-popup" class="popup-overlay hidden">
        <div class="popup-container">
            <span class="popup-close" onclick="closeCreateAdminPopup()">&times;</span>
            <h2 class="popup-title">Create Admin Account</h2>
            <input type="email" id="new-admin-email" class="popup-field" placeholder="Email address" required>
            <input type="text" id="new-admin-fullname" class="popup-field" placeholder="Full Name" required>
            <input type="text" id="new-admin-username" class="popup-field" placeholder="Username" required>
            <input type="password" id="new-admin-password" class="popup-field" placeholder="Password" required>
            <button class="popup-btn" onclick="sendAdminOTP()">Send OTP</button>
            <div id="create-admin-error" class="error-message hidden"></div>
            <div id="create-admin-success" class="success-message hidden"></div>
        </div>
    </div>

    <div id="verify-otp-popup" class="popup-overlay hidden">
        <div class="popup-container">
            <span class="popup-close" onclick="closeVerifyOTPPopup()">&times;</span>
            <h2 class="popup-title">Verify OTP</h2>
            <p style="text-align: center; margin-bottom: 20px; color: #666;">Enter the 6-digit OTP sent to your email</p>
            <input type="text" id="otp-code" class="popup-field" placeholder="Enter OTP" maxlength="6" required>
            <button class="popup-btn" onclick="verifyAdminOTP()">Verify OTP</button>
            <div id="otp-error" class="error-message hidden"></div>
            <div id="otp-success" class="success-message hidden"></div>
            <p style="text-align: center; margin-top: 15px; font-size: 12px; color: #999;">
                OTP will expire in 10 minutes
            </p>
        </div>
    </div>

    <!-- Modal for Checklist Details -->
    <div class="modal fade" id="checklistDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Checklist Details - <span id="modalCompanyName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="checklistDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Results Modal -->
    <div class="modal fade" id="resultsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resultsModalTitle">Check Results</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="resultsModalBody">
                    <!-- Results will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chart variables
        let pageViewsChart, mrrCountryChart;
        let selectedClient = null;

        // Initialize the dashboard when the DOM is fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            initDashboard();
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.notification-container')) {
                    closeNotifications();
                }
                
                if (!event.target.closest('.user-profile')) {
                    closeUserDropdown();
                }
            });
            
            // Start real-time notification updates
            startRealTimeUpdates();
        });

        function initDashboard() {
            setupCharts();
            
            // Select the first client by default
            setTimeout(function() {
                const firstClient = document.querySelector('.client-row');
                if (firstClient) {
                    const clientName = firstClient.getAttribute('data-client-name');
                    const assets = parseFloat(firstClient.getAttribute('data-client-assets'));
                    const revenue = parseFloat(firstClient.getAttribute('data-client-revenue'));
                    selectClient(firstClient, clientName, assets, revenue);
                }
            }, 500); // Small delay to ensure charts are fully initialized
        }
        
        // Real-time notification updates
        function startRealTimeUpdates() {
            // Update notifications every 30 seconds
            setInterval(updateNotifications, 30000);
        }
        
        function updateNotifications() {
            fetch('get_realtime_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.notifications) {
                        updateNotificationUI(data.notifications, data.badgeCount);
                    }
                })
                .catch(error => {
                    console.error('Error updating notifications:', error);
                });
        }
        
        function updateNotificationUI(notifications, badgeCount) {
            const notificationList = document.querySelector('.notification-list');
            const notificationBadge = document.querySelector('.notification-badge');
            const clearButton = document.querySelector('.notification-clear');
            
            // Update notification list
            if (notifications.length > 0) {
                let html = '';
                notifications.forEach(notification => {
                    const isSeen = notification.isSeen || false;
                    html += `
                        <div class="notification-item ${!isSeen ? 'unread' : ''}" 
                            onclick="handleNotification('${notification.type}', this)">
                            <div class="notification-content">
                                <div>
                                    <span class="notification-type type-${notification.type}">
                                        ${notification.type.charAt(0).toUpperCase() + notification.type.slice(1)}
                                    </span>
                                    ${!isSeen ? '<span class="unseen-indicator">●</span>' : ''}
                                    <div class="notification-message">${notification.message}</div>
                                </div>
                                <div class="notification-time">${notification.time}</div>
                            </div>
                        </div>
                    `;
                });
                notificationList.innerHTML = html;
                
                // Show clear button
                if (clearButton) {
                    clearButton.style.display = 'block';
                }
            } else {
                notificationList.innerHTML = '<div class="notification-item"><div class="notification-content"><div class="notification-message">No recent activity</div><div class="notification-time">Just now</div></div></div>';
                
                // Hide clear button
                if (clearButton) {
                    clearButton.style.display = 'none';
                }
            }
            
            // Update badge
            if (badgeCount > 0) {
                if (notificationBadge) {
                    notificationBadge.textContent = badgeCount;
                } else {
                    const bell = document.querySelector('.notification-bell');
                    const badge = document.createElement('span');
                    badge.className = 'notification-badge';
                    badge.textContent = badgeCount;
                    bell.appendChild(badge);
                }
            } else if (notificationBadge) {
                notificationBadge.remove();
            }
        }
        
        function setupCharts() {
            // Financial Summary Chart (Bar)
            pageViewsChart = new ApexCharts(document.querySelector("#pageViewsChart"), {
                series: [{
                    name: "Amount",
                    data: [0, 0]
                }],
                chart: {
                    type: 'bar',
                    height: 300,
                    toolbar: {
                        show: false
                    }
                },
                colors: ['#3498db', '#2ecc71'],
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '55%',
                        endingShape: 'rounded'
                    },
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
                    categories: ['Assets', 'Revenue'],
                    labels: {
                        style: {
                            colors: '#718096',
                            fontSize: '12px'
                        }
                    }
                },
                yaxis: {
                    title: {
                        text: 'Amount (₱)',
                        style: {
                            color: '#718096'
                        }
                    },
                    labels: {
                        formatter: function(val) {
                            return '₱' + val.toLocaleString();
                        },
                        style: {
                            colors: '#718096',
                            fontSize: '12px'
                        }
                    }
                },
                fill: {
                    opacity: 1
                },
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return '₱' + val.toLocaleString();
                        }
                    }
                },
                grid: {
                    borderColor: '#f1f5f9',
                    strokeDashArray: 4
                }
            });
            pageViewsChart.render();

            // Revenue Distribution Chart (Donut)
            mrrCountryChart = new ApexCharts(document.querySelector("#mrrCountryChart"), {
                series: [0, 0, 0],
                chart: {
                    type: 'donut',
                    height: 300
                },
                labels: ['Operating', 'Investing', 'Financing'],
                colors: ['#3498db', '#2ecc71', '#e74c3c'],
                legend: {
                    position: 'bottom',
                    labels: {
                        colors: '#718096'
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return '₱' + val.toLocaleString();
                        }
                    }
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '65%',
                            labels: {
                                show: true,
                                total: {
                                    show: true,
                                    label: 'Total',
                                    formatter: function (w) {
                                        return '₱' + w.globals.seriesTotals.reduce((a, b) => a + b, 0).toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                },
                dataLabels: {
                    enabled: false
                }
            });
            mrrCountryChart.render();
        }
        
        function selectClient(element, clientName, assets, revenue) {
            console.log('Selecting client:', clientName, assets, revenue);
            
            // Remove selection from all clients
            document.querySelectorAll('.client-row').forEach(row => {
                row.classList.remove('selected-client');
            });
            
            // Add selection to clicked client
            element.classList.add('selected-client');
            
            // Update chart titles with client name
            document.getElementById('pageViewsTitle').innerText = 'Financial Summary - ' + clientName;
            document.getElementById('mrrCountryTitle').innerText = 'Revenue Distribution - ' + clientName;
            
            // Ensure we have valid numbers
            assets = assets || 0;
            revenue = revenue || 0;
            
            // Update Financial Summary Chart
            pageViewsChart.updateSeries([{
                name: "Amount",
                data: [assets, revenue]
            }]);
            
            // Update Revenue Distribution Chart
            const operating = revenue * 0.7;
            const investing = revenue * 0.2;
            const financing = revenue * 0.1;
            mrrCountryChart.updateSeries([operating, investing, financing]);
            
            // Update legend for Financial Summary
            const legendHtml = `
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #3498db;"></div>
                    <span>Assets: ₱${assets.toLocaleString()}</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #2ecc71;"></div>
                    <span>Revenue: ₱${revenue.toLocaleString()}</span>
                </div>
            `;
            document.getElementById('pageViewsLegend').innerHTML = legendHtml;
        }
        
        // Real-time client search function
        function filterClients() {
            const searchTerm = document.getElementById('clientSearch').value.toLowerCase();
            const clientRows = document.querySelectorAll('.client-row');
            let hasResults = false;
            
            clientRows.forEach(row => {
                const clientName = row.getAttribute('data-client-name').toLowerCase();
                if (clientName.includes(searchTerm)) {
                    row.style.display = 'flex';
                    hasResults = true;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show no results message if needed
            let noResultsMsg = document.getElementById('noResultsMsg');
            if (!hasResults) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.id = 'noResultsMsg';
                    noResultsMsg.className = 'no-results';
                    noResultsMsg.textContent = 'No clients found matching your search';
                    document.getElementById('clientsContainer').appendChild(noResultsMsg);
                }
            } else if (noResultsMsg) {
                noResultsMsg.remove();
            }
        }
        
        function showSection(section) {
            // Hide all sections
            document.getElementById('home').style.display = 'none';
            document.getElementById('services').style.display = 'none';
            document.getElementById('about').style.display = 'none';
            document.getElementById('bankruptcy-filings').style.display = 'none';
            document.getElementById('dynamic-content-area').style.display = 'none';
            document.getElementById('audit-trail-content').style.display = 'none';

            // Show selected section
            document.getElementById(section).style.display = 'block';

            // Show top bar for main sections
            document.getElementById('topBar').style.display = 'flex';

            // Highlight selected menu item
            document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
            document.querySelectorAll('.menu-item').forEach(item => {
                if (item.textContent.trim().toLowerCase().replace(' ', '-') === section) {
                    item.classList.add('active');
                }
            });

            // Update page title
            let pageTitle = 'Executive Dashboard';
            if (section === 'services') pageTitle = 'Our Services';
            if (section === 'about') pageTitle = 'About Us';
            if (section === 'bankruptcy-filings') pageTitle = 'Bankruptcy Filings';
            document.getElementById('page-title').innerText = pageTitle;

            // Close any open dropdowns
            closeNotifications();
            closeUserDropdown();
        }

        // NEW: Audit Trail Functions
        function showAuditTrail() {
            // Hide all sections
            document.getElementById('home').style.display = 'none';
            document.getElementById('services').style.display = 'none';
            document.getElementById('about').style.display = 'none';
            document.getElementById('bankruptcy-filings').style.display = 'none';
            document.getElementById('dynamic-content-area').style.display = 'none';
            
            // Show audit trail content area
            document.getElementById('audit-trail-content').style.display = 'block';
            
            // Hide top bar
            document.getElementById('topBar').style.display = 'none';
            
            // Load audit trail content
            loadAuditTrail();
            
            // Update page title
            document.getElementById('page-title').innerText = 'Audit Trail - Recent Admin Activities';
            
            // Close any open dropdowns
            closeNotifications();
            closeUserDropdown();
        }
        
        function hideAuditTrail() {
            document.getElementById('audit-trail-content').style.display = 'none';
            showSection('home');
        }
        
        function loadAuditTrail() {
            const container = document.getElementById('audit-trail-container');
            container.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading audit trail...</p></div>';
            
            // In a real implementation, you would fetch this from the server
            // For now, we'll use the PHP data that's already available
            setTimeout(() => {
                let html = `
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Options</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label">Date Range</label>
                                    <select class="form-select" id="dateRange">
                                        <option value="7">Last 7 days</option>
                                        <option value="30" selected>Last 30 days</option>
                                        <option value="90">Last 90 days</option>
                                        <option value="365">Last year</option>
                                        <option value="all">All time</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Action Type</label>
                                    <select class="form-select" id="actionType">
                                        <option value="all">All Actions</option>
                                        <option value="ADMIN_ACCOUNT_CREATED">Admin Account Created</option>
                                        <option value="ADMIN_CREATION_OTP_SENT">OTP Sent</option>
                                        <option value="BANKRUPTCY_FILING_APPROVED">Bankruptcy Approved</option>
                                        <option value="LOGIN">Login</option>
                                        <option value="LOGOUT">Logout</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Admin User</label>
                                    <select class="form-select" id="adminUser">
                                        <option value="all">All Admins</option>
                                        <option value="system">System</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-primary" onclick="applyAuditFilters()">
                                    <i class="fas fa-filter me-1"></i> Apply Filters
                                </button>
                                <button class="btn btn-outline-secondary" onclick="resetAuditFilters()">
                                    <i class="fas fa-redo me-1"></i> Reset
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Admin Activities</h5>
                            <span class="badge bg-primary"><?php echo count($recentAuditLogs); ?> activities</span>
                        </div>
                        <div class="card-body">
                `;
                
                <?php if (count($recentAuditLogs) > 0): ?>
                    <?php foreach ($recentAuditLogs as $log): ?>
                        html += `
                            <div class="audit-log-item mb-3">
                                <div class="audit-log-header">
                                    <span class="audit-log-action"><?php echo htmlspecialchars($log['action_type']); ?></span>
                                    <span class="audit-log-time"><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></span>
                                </div>
                                <div class="audit-log-description">
                                    <?php echo htmlspecialchars($log['action_description']); ?>
                                </div>
                                <div class="audit-log-details">
                                    By: <span class="audit-log-admin"><?php echo htmlspecialchars($log['admin_username'] ?: 'System'); ?></span>
                                    <?php if ($log['resource_affected']): ?>
                                        | Resource: <?php echo htmlspecialchars($log['resource_affected']); ?>
                                    <?php endif; ?>
                                    <?php if ($log['ip_address'] && $log['ip_address'] !== 'Unknown'): ?>
                                        | IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($log['new_values']): ?>
                                    <div class="audit-log-resource">
                                        <strong>Changes:</strong> <?php echo htmlspecialchars($log['new_values']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        `;
                    <?php endforeach; ?>
                <?php else: ?>
                    html += `
                        <div class="text-center py-4">
                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                            <h5>No audit logs available</h5>
                            <p class="text-muted">Admin activities will appear here once they occur.</p>
                        </div>
                    `;
                <?php endif; ?>
                
                html += `
                        </div>
                    </div>
                `;
                
                container.innerHTML = html;
            }, 500);
        }
        
        function applyAuditFilters() {
            // In a real implementation, this would make an AJAX call to filter the audit logs
            alert('Filters applied! In a real implementation, this would refresh the audit log list with filtered results.');
        }
        
        function resetAuditFilters() {
            document.getElementById('dateRange').value = '30';
            document.getElementById('actionType').value = 'all';
            document.getElementById('adminUser').value = 'all';
            alert('Filters reset!');
        }
        
        function printAuditTrail() {
            window.print();
        }


        // NEW: Dynamic Content Functions
        function showDynamicContent(url, title) {
            // Hide all sections
            document.getElementById('home').style.display = 'none';
            document.getElementById('services').style.display = 'none';
            document.getElementById('about').style.display = 'none';
            document.getElementById('bankruptcy-filings').style.display = 'none';
            document.getElementById('audit-trail-content').style.display = 'none';
            
            // Show dynamic content area
            document.getElementById('dynamic-content-area').style.display = 'block';
            
            // Hide top bar
            document.getElementById('topBar').style.display = 'none';
            
            // Set title and iframe source
            document.getElementById('dynamic-content-title').innerHTML = '<i class="fas fa-file"></i> ' + title;
            document.getElementById('dynamic-iframe').src = url;
            
            // Update page title
            document.getElementById('page-title').innerText = title;
            
            // Close any open dropdowns
            closeNotifications();
            closeUserDropdown();
        }
        
        function hideDynamicContent() {
            document.getElementById('dynamic-content-area').style.display = 'none';
            document.getElementById('dynamic-iframe').src = 'about:blank';
            showSection('home');
        }
        
        function printIframeContent() {
            const iframe = document.getElementById('dynamic-iframe');
            iframe.contentWindow.print();
        }
        
        function goBackInIframe() {
            const iframe = document.getElementById('dynamic-iframe');
            try {
                iframe.contentWindow.history.back();
            } catch (e) {
                console.error("Error going back in iframe:", e);
            }
        }
        
        // Inactivity Monitor Functions
        function runManualCheck() {
            // Show loading state
            const btn = document.querySelector('.btn-run-check');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';
            btn.disabled = true;
            
            // Simulate API call
            setTimeout(() => {
                // Show success message
                showAlert('Manual check completed successfully! Inactive clients have been notified.', 'success');
                
                // Reset button
                btn.innerHTML = originalText;
                btn.disabled = false;
                
                // In a real implementation, you would refresh the page or update the status
                console.log('Manual check completed');
            }, 2000);
        }
        
        function viewCheckResults() {
            // Show loading in modal
            document.getElementById('resultsModalTitle').textContent = 'Inactivity Check Results';
            document.getElementById('resultsModalBody').innerHTML = `
                <div class="text-center p-4">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading check results...</p>
                </div>
            `;
            
            // Show modal
            const resultsModal = new bootstrap.Modal(document.getElementById('resultsModal'));
            resultsModal.show();
            
            // Simulate loading results
            setTimeout(() => {
                document.getElementById('resultsModalBody').innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Last Check Completed Successfully</strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Checked Clients</h5>
                                    <h2 class="text-primary">${<?php echo $totalClients; ?>}</h2>
                                    <p class="text-muted">Total clients in system</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title">Inactive Clients</h5>
                                    <h2 class="text-warning">${<?php echo $inactiveClientsCount; ?>}</h2>
                                    <p class="text-muted">Clients with expired contracts</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h6>Recent Activity:</h6>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Automatic check completed
                                <span class="badge bg-success">Success</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Email notifications sent
                                <span class="badge bg-info">${<?php echo $inactiveClientsCount; ?>} clients</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Database updated
                                <span class="badge bg-success">Completed</span>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Next automatic check will run in approximately 24 hours.
                        </small>
                    </div>
                `;
            }, 1500);
        }
        
        function showAlert(message, type = 'info') {
            // Create alert element
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Add to page
            document.querySelector('.main-content').insertBefore(alert, document.querySelector('.main-content').firstChild);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }
        
        function logout() {
            fetch('logout.php')
            .then(() => {
                sessionStorage.removeItem("admin_logged_in");
                window.location.href = "index.html";
            });
        }
        
        // New functions for admin account creation with OTP
        function showCreateAdminPopup() {
            document.getElementById('create-admin-popup').classList.remove('hidden');
            document.getElementById('create-admin-error').classList.add('hidden');
            document.getElementById('create-admin-success').classList.add('hidden');
            
            // Clear form fields
            document.getElementById('new-admin-email').value = '';
            document.getElementById('new-admin-fullname').value = '';
            document.getElementById('new-admin-username').value = '';
            document.getElementById('new-admin-password').value = '';
            
            closeUserDropdown();
            closeNotifications();
        }
        
        function closeCreateAdminPopup() {
            document.getElementById('create-admin-popup').classList.add('hidden');
        }
        
        function closeVerifyOTPPopup() {
            document.getElementById('verify-otp-popup').classList.add('hidden');
        }
        
        function sendAdminOTP() {
            const email = document.getElementById('new-admin-email').value.trim();
            const fullName = document.getElementById('new-admin-fullname').value.trim();
            const username = document.getElementById('new-admin-username').value.trim();
            const password = document.getElementById('new-admin-password').value.trim();
            
            // Basic validation
            if (!email || !fullName || !username || !password) {
                showCreateAdminError("All fields are required");
                return;
            }
            
            if (!validateEmail(email)) {
                showCreateAdminError("Please enter a valid email address");
                return;
            }
            
            if (password.length < 6) {
                showCreateAdminError("Password must be at least 6 characters long");
                return;
            }
            
            // Show loading state
            const btn = document.querySelector('#create-admin-popup .popup-btn');
            const originalText = btn.textContent;
            btn.textContent = 'Sending OTP...';
            btn.disabled = true;
            
            // Send AJAX request
            const formData = new FormData();
            formData.append('action', 'send_otp');
            formData.append('email', email);
            formData.append('full_name', fullName);
            formData.append('username', username);
            formData.append('password', password);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('create-admin-popup').classList.add('hidden');
                    document.getElementById('verify-otp-popup').classList.remove('hidden');
                    document.getElementById('otp-error').classList.add('hidden');
                    document.getElementById('otp-success').classList.add('hidden');
                    document.getElementById('otp-code').value = '';
                    
                    // Show success message
                    showCreateAdminSuccess("OTP sent successfully! Check your email.");
                } else {
                    showCreateAdminError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCreateAdminError("An error occurred. Please try again.");
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
        
        function verifyAdminOTP() {
            const otp = document.getElementById('otp-code').value.trim();
            
            if (!otp || otp.length !== 6) {
                showOTPError("Please enter a valid 6-digit OTP");
                return;
            }
            
            // Show loading state
            const btn = document.querySelector('#verify-otp-popup .popup-btn');
            const originalText = btn.textContent;
            btn.textContent = 'Verifying...';
            btn.disabled = true;
            
            // Send AJAX request
            const formData = new FormData();
            formData.append('action', 'verify_otp');
            formData.append('otp', otp);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showOTPSuccess(data.message);
                    setTimeout(() => {
                        closeVerifyOTPPopup();
                        alert('Admin account created successfully! A welcome email has been sent.');
                    }, 2000);
                } else {
                    showOTPError(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showOTPError("An error occurred. Please try again.");
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
        
        function showCreateAdminError(message) {
            const errorElement = document.getElementById('create-admin-error');
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }
        
        function showCreateAdminSuccess(message) {
            const successElement = document.getElementById('create-admin-success');
            successElement.textContent = message;
            successElement.classList.remove('hidden');
        }
        
        function showOTPError(message) {
            const errorElement = document.getElementById('otp-error');
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }
        
        function showOTPSuccess(message) {
            const successElement = document.getElementById('otp-success');
            successElement.textContent = message;
            successElement.classList.remove('hidden');
        }
        
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        // Existing functions for admin account edit
        function showEditAdminPopup() {
            document.getElementById('email-verification-popup').classList.remove('hidden');
            document.getElementById('email-error').classList.add('hidden');
            document.getElementById('admin-email').value = '';
            closeUserDropdown();
            closeNotifications();
        }
        
        function closeEmailPopup() {
            document.getElementById('email-verification-popup').classList.add('hidden');
        }
        
        function closeUpdatePopup() {
            document.getElementById('update-credentials-popup').classList.add('hidden');
        }
        
        function verifyAdminEmail() {
            const email = document.getElementById('admin-email').value.trim();
            if (!email) {
                showEmailError("Please enter your admin email");
                return;
            }
            
            // Simulate email verification
            setTimeout(() => {
                document.getElementById('email-verification-popup').classList.add('hidden');
                document.getElementById('update-credentials-popup').classList.remove('hidden');
                document.getElementById('new-username').value = '';
                document.getElementById('new-password').value = '';
                document.getElementById('update-success').style.display = 'none';
            }, 500);
        }

        function updateAdminAccount() {
            const newUsername = document.getElementById('new-username').value.trim();
            const newPassword = document.getElementById('new-password').value.trim();
            
            if (!newUsername || !newPassword) {
                alert("Please enter both username and password");
                return;
            }
            
            // Show success message
            document.getElementById('update-success').style.display = 'block';
            
            // Log out after a short delay
            setTimeout(() => {
                logout();
            }, 2000);
        }
                
        function showEmailError(message) {
            const errorElement = document.getElementById('email-error');
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }        

        
        function closeNotifications() {
            document.getElementById('notificationDropdown').classList.remove('open');
        }
		
		function markNotificationsAsSeen() {
			return new Promise((resolve, reject) => {
				fetch('mark_notifications_seen.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: 'mark_seen=true'
				})
				.then(response => {
					if (!response.ok) {
						throw new Error('Network response was not ok');
					}
					return response.json();
				})
				.then(data => {
					if (data.success) {
						// Remove badge only
						const badge = document.querySelector('.notification-badge');
						if (badge) {
							badge.remove();
						}
						// Remove unread styles only
						document.querySelectorAll('.notification-item.unread').forEach(item => {
							item.classList.remove('unread');
						});
						document.querySelectorAll('.unseen-indicator').forEach(indicator => {
							indicator.remove();
						});
						
						resolve(data);
					} else {
						reject(new Error(data.message));
					}
				})
				.catch(error => {
					console.error('Error marking notifications as seen:', error);
					// Even if the server call fails, update the UI
					const badge = document.querySelector('.notification-badge');
					if (badge) {
						badge.remove();
					}
					document.querySelectorAll('.notification-item.unread').forEach(item => {
						item.classList.remove('unread');
					});
					document.querySelectorAll('.unseen-indicator').forEach(indicator => {
						indicator.remove();
					});
					resolve({success: false, error: error.message});
				});
			});
		}

        
        function resetNotificationScroll() {
            const notificationList = document.querySelector('.notification-list');
            if (notificationList) {
                // Always show newest notifications at the top
                notificationList.scrollTop = 0;
            }
        }

        // Update the toggleNotifications function
        function toggleNotifications(event) {
            if (event) {
                event.stopPropagation();
            }
            
            const dropdown = document.getElementById('notificationDropdown');
            const isOpen = dropdown.classList.contains('open');
            
            closeUserDropdown();
            
            if (isOpen) {
                closeNotifications();
            } else {
                dropdown.classList.add('open');
				markNotificationsAsSeen();
                // Reset to top immediately when opening
                setTimeout(resetNotificationScroll, 10);
            }
        }

		function clearNotifications() {
			if (confirm('Are you sure you want to clear all notifications?')) {
				fetch('clear_notifications.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					}
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						// Update the notification list to show empty state
						const notificationList = document.querySelector('.notification-list');
						notificationList.innerHTML = '<div class="notification-item"><div class="notification-content"><div class="notification-message">No recent activity</div><div class="notification-time">Just now</div></div></div>';
						
						// Hide the badge
						const badge = document.querySelector('.notification-badge');
						if (badge) {
							badge.remove();
						}
						
						// Hide the clear all button
						const clearButton = document.querySelector('.notification-clear');
						if (clearButton) {
							clearButton.style.display = 'none';
						}
						
						// Reset scroll after clearing
						setTimeout(resetNotificationScroll, 10);
					} else {
						alert('Failed to clear notifications. Please try again.');
					}
				})
				.catch(error => {
					console.error('Error clearing notifications:', error);
					alert('Error clearing notifications. Please try again.');
				});
			}
		}


        
        function handleNotification(type) {
            // Handle notification click based on type
            switch(type) {
                case 'deadline':
                    window.location.href = 'calendar_admin.php';
                    break;
                case 'task':
                    window.location.href = 'tasks.php';
                    break;
                case 'client':
                    showDynamicContent('choose-client-modal.php', 'Choose Client');
                    break;
                case 'transaction':
                    showDynamicContent('choose-client-modal.php', 'Choose Client');
                    break;
                case 'contract':
                    showDynamicContent('manage-client.php', 'Manage Client');
                    break;
                case 'message':
                    showDynamicContent('admin_messages.php', 'Messages');
                    break;
                case 'audit':
                    showAuditTrail();
                    break;
                default:
                    // Do nothing for other types
            }
            closeNotifications();
        }

        // User dropdown functionality
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            const isOpen = dropdown.classList.contains('open');
            
            // Close other dropdowns
            closeNotifications();
            
            // Toggle user dropdown
            if (isOpen) {
                closeUserDropdown();
            } else {
                dropdown.classList.add('open');
            }
            
            event.stopPropagation();
        }
        
        function closeUserDropdown() {
            document.getElementById('userDropdown').classList.remove('open');
        }

        // Bankruptcy Filing Functions
        function viewChecklistDetails(companyName, userId) {
            // Show loading
            document.getElementById('checklistDetailsContent').innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading checklist details...</p></div>';
            
            // Fetch checklist details via AJAX
            fetch('get_checklist_details.php?company_name=' + encodeURIComponent(companyName) + '&user_id=' + userId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('modalCompanyName').textContent = companyName;
                    document.getElementById('checklistDetailsContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('checklistDetailsContent').innerHTML = '<div class="alert alert-danger">Error loading checklist details.</div>';
                });
            
            // Show modal
            new bootstrap.Modal(document.getElementById('checklistDetailsModal')).show();
        }

        // Back to Top Functionality
        let inactivityTimer;
        const INACTIVITY_TIMEOUT = 2000; // 2 seconds

        function initBackToTop() {
            const backToTopBtn = document.getElementById('backToTop');
            let isScrolled = false;
            
            function checkScrollPosition() {
                const scrollPosition = window.scrollY;
                const windowHeight = window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                
                // Calculate 30% scroll threshold
                const scrollThreshold = documentHeight * 0.3;
                
                if (scrollPosition > scrollThreshold && !isScrolled) {
                    // User has scrolled down 30%
                    isScrolled = true;
                    showBackToTop();
                } else if (scrollPosition <= 100 && isScrolled) {
                    // User is near the top
                    isScrolled = false;
                    hideBackToTop();
                }
            }
            
            function showBackToTop() {
                const backToTopBtn = document.getElementById('backToTop');
                backToTopBtn.classList.remove('hiding');
                backToTopBtn.classList.add('show');
                resetInactivityTimer();
            }
            
            function hideBackToTop() {
                const backToTopBtn = document.getElementById('backToTop');
                backToTopBtn.classList.add('hiding');
                setTimeout(() => {
                    backToTopBtn.classList.remove('show');
                }, 500); // Match the transition duration
            }
            
            function resetInactivityTimer() {
                clearTimeout(inactivityTimer);
                inactivityTimer = setTimeout(() => {
                    // Hide button after inactivity
                    if (window.scrollY > 100) { // Only hide if not at top
                        hideBackToTop();
                    }
                }, INACTIVITY_TIMEOUT);
            }
            
            // Event listeners
            window.addEventListener('scroll', checkScrollPosition);
            
            // User activity events
            ['mousemove', 'keydown', 'touchstart', 'click', 'scroll'].forEach(event => {
                document.addEventListener(event, resetInactivityTimer);
            });
            
            // Initial check
            checkScrollPosition();
        }
        
        function scrollToTop() {
            // Hide button immediately when clicked
            const backToTopBtn = document.getElementById('backToTop');
            backToTopBtn.classList.add('hiding');
            setTimeout(() => {
                backToTopBtn.classList.remove('show');
            }, 300);
            
            // Smooth scroll to top
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
            
            // Reset inactivity timer
            resetInactivityTimer();
        }
        
        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(() => {
                const backToTopBtn = document.getElementById('backToTop');
                if (backToTopBtn.classList.contains('show') && window.scrollY > 100) {
                    backToTopBtn.classList.add('hiding');
                    setTimeout(() => {
                        backToTopBtn.classList.remove('show');
                    }, 500);
                }
            }, INACTIVITY_TIMEOUT);
        }
        
        // Initialize back to top when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initBackToTop();
        });
    </script>
    <script>
        // ✅ Redirect if not logged in
        if (sessionStorage.getItem("admin_logged_in") !== "true") {
            window.location.href = "index.html";
        }

        // ✅ Prevent back button after logout
        window.onload = function () {
            history.pushState(null, null, location.href);
            window.onpopstate = function () {
                window.location.href = "index.html";
            };
        };
    </script>
    <!-- Add this HTML right before the closing </body> tag -->
    <button class="back-to-top" id="backToTop" onclick="scrollToTop()">
        <i class="fas fa-arrow-up"></i>
    </button>
</body>
</html>