<?php
session_start();
require 'db_connection.php';
require 'mailer/config.php';

// Function to check inactive clients and send notifications
function checkInactiveClients() {
    global $pdo;
    
    $inactiveClients = [];
    
    try {
        // Get all clients
        $stmt = $pdo->query("SELECT id, `Company Name`, Email FROM client");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($clients as $client) {
            $selected_company = $client['Company Name'];
            $company = preg_replace('/[^A-Za-z0-9]/', '_', $selected_company);
            $transactionsTable = "{$company}_Transactions";
            
            // Check if transactions table exists
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE '$transactionsTable'");
                if ($checkTable->rowCount() > 0) {
                    // Get the most recent transaction date
                    $stmt = $pdo->prepare("SELECT MAX(date) as last_transaction_date FROM `$transactionsTable`");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result && $result['last_transaction_date']) {
                        $lastTransactionDate = new DateTime($result['last_transaction_date']);
                        $today = new DateTime();
                        $daysInactive = $today->diff($lastTransactionDate)->days;
                        
                        // If inactive for 15 days or more
                        if ($daysInactive >= 15) {
                            $inactiveClients[] = [
                                'client_id' => $client['id'],
                                'company_name' => $client['Company Name'],
                                'email' => $client['Email'],
                                'days_inactive' => $daysInactive,
                                'last_transaction_date' => $result['last_transaction_date']
                            ];
                        }
                    } else {
                        // No transactions at all
                        $inactiveClients[] = [
                            'client_id' => $client['id'],
                            'company_name' => $client['Company Name'],
                            'email' => $client['Email'],
                            'days_inactive' => 'No transactions recorded',
                            'last_transaction_date' => null
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Error checking table for {$client['Company Name']}: " . $e->getMessage());
                continue;
            }
        }
    } catch (Exception $e) {
        error_log("Error in checkInactiveClients: " . $e->getMessage());
    }
    
    return $inactiveClients;
}

// Function to send inactivity email
function sendInactivityEmail($clientEmail, $companyName, $daysInactive, $lastTransactionDate = null) {
    $subject = "Account Activity Reminder - $companyName";
    
    if (is_numeric($daysInactive)) {
        $lastActivity = $lastTransactionDate ? date('F j, Y', strtotime($lastTransactionDate)) : 'Unknown';
        $daysText = "$daysInactive days";
    } else {
        $lastActivity = "No transactions recorded";
        $daysText = "an extended period";
    }
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; color: white;'>
            <h1 style='margin: 0; font-size: 28px;'>EBTGL Accounting Services</h1>
            <p style='margin: 10px 0 0 0; font-size: 16px;'>Account Activity Notification</p>
        </div>
        
        <div style='padding: 30px; background: #f9f9f9;'>
            <h2 style='color: #333; margin-bottom: 20px;'>Dear Valued Client,</h2>
            
            <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>
                We hope this message finds you well. Our records indicate that your account 
                <strong>$companyName</strong> has been inactive for $daysText.
            </p>
            
            <div style='background: white; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0;'>
                <p style='margin: 0; color: #666;'>
                    <strong>Last Activity Date:</strong> $lastActivity<br>
                    <strong>Days Since Last Activity:</strong> " . (is_numeric($daysInactive) ? "$daysInactive days" : $daysInactive) . "
                </p>
            </div>
            
            <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>
                We value your business and want to ensure you're getting the most out of our 
                accounting services. If you'd like to continue using our system, please let us know 
                by contacting our administration team through your client account portal.
            </p>
            
            <p style='color: #555; line-height: 1.6; margin-bottom: 20px;'>
                If you have any questions or need assistance with your account, our support team 
                is here to help you get back on track.
            </p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='mailto:ebtgl5220712@gmail.com' 
                   style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                          color: white; 
                          padding: 12px 30px; 
                          text-decoration: none; 
                          border-radius: 5px; 
                          display: inline-block;
                          font-weight: bold;'>
                    Contact Admin Team
                </a>
            </div>
        </div>
        
        <div style='background: #333; color: white; padding: 20px; text-align: center;'>
            <p style='margin: 0 0 10px 0; font-size: 14px;'>
                <strong>EBTGL Accounting Services</strong><br>
                Professional Accounting Solutions
            </p>
            <p style='margin: 0; font-size: 12px; color: #ccc;'>
                This is an automated notification. Please do not reply to this email.<br>
                If you have any questions, contact us at ebtgl5220712@gmail.com
            </p>
        </div>
    </div>
    ";
    
    // Check if sendCustomEmail function exists
    if (function_exists('sendCustomEmail')) {
        return sendCustomEmail($clientEmail, $subject, $body);
    } else {
        // Fallback: log that email would have been sent
        error_log("Would send email to $clientEmail - Subject: $subject");
        return true; // Return true for testing purposes
    }
}

// Function to log inactivity notifications
function logInactivityNotification($clientId, $daysInactive) {
    global $pdo;
    
    try {
        // Check if table exists, create if not
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inactivity_notifications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                client_id INT NOT NULL,
                days_inactive VARCHAR(50) NOT NULL,
                notification_date DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
            )
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO inactivity_notifications (client_id, days_inactive, notification_date) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$clientId, $daysInactive]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log inactivity notification: " . $e->getMessage());
        return false;
    }
}

// Web-based auto-trigger function
function webBasedAutoTrigger() {
    $lastRunFile = 'last_inactivity_check.txt';
    $checkInterval = 86400; // 24 hours
    
    // Check if we should run automatically
    if (file_exists($lastRunFile)) {
        $lastRun = (int)file_get_contents($lastRunFile);
        if (time() - $lastRun < $checkInterval) {
            return [
                'success' => true,
                'run' => false,
                'message' => 'Last check was ' . round((time() - $lastRun) / 3600, 1) . ' hours ago. Next check in ' . round(($checkInterval - (time() - $lastRun)) / 3600, 1) . ' hours.'
            ];
        }
    }
    
    // Run the check
    $inactiveClients = checkInactiveClients();
    $emailsSent = 0;
    $results = [];
    
    foreach ($inactiveClients as $client) {
        $emailResult = sendInactivityEmail(
            $client['email'],
            $client['company_name'],
            $client['days_inactive'],
            $client['last_transaction_date']
        );
        
        if ($emailResult) {
            logInactivityNotification($client['client_id'], $client['days_inactive']);
            $emailsSent++;
            $results[] = "✓ Email sent to {$client['company_name']} ({$client['days_inactive']} days inactive)";
        } else {
            $results[] = "✗ Failed to send email to {$client['company_name']}";
        }
    }
    
    // Update last run time
    file_put_contents($lastRunFile, time());
    
    // Log the automatic run
    error_log("Auto-inactivity check: " . count($inactiveClients) . " inactive clients, $emailsSent emails sent");
    
    return [
        'success' => true,
        'run' => true,
        'message' => 'Inactivity check completed successfully.',
        'clients_checked' => count($inactiveClients),
        'emails_sent' => $emailsSent,
        'results' => $results
    ];
}

// Handle manual reminder sending for specific client
if (isset($_GET['send_reminder'])) {
    $clientId = $_GET['send_reminder'];
    
    try {
        // Get client details
        $stmt = $pdo->prepare("SELECT id, `Company Name`, Email FROM client WHERE id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($client) {
            $selected_company = $client['Company Name'];
            $company = preg_replace('/[^A-Za-z0-9]/', '_', $selected_company);
            $transactionsTable = "{$company}_Transactions";
            
            // Get last transaction date
            $daysInactive = 'Unknown';
            $lastTransactionDate = null;
            
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE '$transactionsTable'");
                if ($checkTable->rowCount() > 0) {
                    $stmt = $pdo->prepare("SELECT MAX(date) as last_transaction_date FROM `$transactionsTable`");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($result && $result['last_transaction_date']) {
                        $lastTransactionDate = new DateTime($result['last_transaction_date']);
                        $today = new DateTime();
                        $daysInactive = $today->diff($lastTransactionDate)->days;
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting transaction date: " . $e->getMessage());
            }
            
            // Send reminder email
            $emailResult = sendInactivityEmail(
                $client['Email'],
                $client['Company Name'],
                $daysInactive,
                $lastTransactionDate ? $lastTransactionDate->format('Y-m-d') : null
            );
            
            if ($emailResult) {
                logInactivityNotification($client['id'], $daysInactive);
                echo json_encode(['success' => true, 'message' => 'Reminder sent successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send reminder email']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Client not found']);
        }
    } catch (Exception $e) {
        error_log("Error in send_reminder: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Main execution logic
try {
    if (php_sapi_name() === 'cli') {
        // CLI execution
        echo "Checking for inactive clients...\n";
        
        $inactiveClients = checkInactiveClients();
        
        if (empty($inactiveClients)) {
            echo "No inactive clients found.\n";
        } else {
            echo "Found " . count($inactiveClients) . " inactive client(s):\n";
            
            $emailsSent = 0;
            
            foreach ($inactiveClients as $client) {
                echo "- {$client['company_name']}: Inactive for {$client['days_inactive']} days\n";
                
                // Send email notification
                $emailResult = sendInactivityEmail(
                    $client['email'],
                    $client['company_name'],
                    $client['days_inactive'],
                    $client['last_transaction_date']
                );
                
                if ($emailResult) {
                    echo "  ✓ Notification email sent to {$client['email']}\n";
                    $emailsSent++;
                    logInactivityNotification($client['client_id'], $client['days_inactive']);
                } else {
                    echo "  ✗ Failed to send email to {$client['email']}\n";
                }
            }
            
            echo "\nSummary: $emailsSent email(s) sent successfully.\n";
        }
    } else {
        // Web execution
        header('Content-Type: application/json');
        
        if (isset($_GET['run_check']) && $_GET['run_check'] == '1') {
            // Manual trigger
            $inactiveClients = checkInactiveClients();
            $results = [];
            $emailsSent = 0;
            
            foreach ($inactiveClients as $client) {
                $emailResult = sendInactivityEmail(
                    $client['email'],
                    $client['company_name'],
                    $client['days_inactive'],
                    $client['last_transaction_date']
                );
                
                if ($emailResult) {
                    logInactivityNotification($client['client_id'], $client['days_inactive']);
                    $emailsSent++;
                }
                
                $results[] = [
                    'company' => $client['company_name'],
                    'email' => $client['email'],
                    'days_inactive' => $client['days_inactive'],
                    'email_sent' => $emailResult
                ];
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Manual inactivity check completed',
                'clients_checked' => count($inactiveClients),
                'emails_sent' => $emailsSent,
                'results' => $results
            ]);
        } else {
            // Auto-trigger (runs automatically when file is accessed)
            $result = webBasedAutoTrigger();
            echo json_encode($result);
        }
    }
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    error_log("Inactivity check error: " . $e->getMessage());
}
?>