<?php

// Report all errors except deprecations
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require 'db_connection.php';

// Initialize seen messages in session
if (!isset($_SESSION['seen_messages'])) {
    $_SESSION['seen_messages'] = [];
}

// Function to detect and make URLs clickable
function makeLinksClickable($text) {
    return preg_replace(
        '!(((f|ht)tps?://)[-a-zA-Zа-яА-Я()0-9@:%_+.~#?&;//=]+)!i', 
        '<a href="$1" target="_blank" class="link">$1</a>', 
        $text
    );
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    if (isset($_GET['load_messages']) && isset($_GET['client_id'])) {
        $client_id = $_GET['client_id'];
        
        // Get messages for this client using positional parameters - UPDATED QUERY
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   IFNULL(c.`Company Name`, recipient.`Company Name`) AS company_name,
                   IFNULL(m.sender_id, 0) AS sender_id_normalized,
                   a.full_name AS admin_name
            FROM messages m
            LEFT JOIN client c ON m.sender_id = c.id
            LEFT JOIN client recipient ON m.recipient_id = recipient.id
            LEFT JOIN admin_accounts a ON m.admin_id = a.id
            WHERE (m.sender_id = ? OR m.recipient_id = ?)
            ORDER BY sent_at DESC
        ");
        // Pass client_id twice for both parameters
        $stmt->execute([$client_id, $client_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $displayMessages = [];
        foreach (array_reverse($messages) as $m) {
            $messageText = nl2br(makeLinksClickable(htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8')));
            
            $attachmentHTML = '';
            if (!empty($m['attachment_path'])) {
                $filePath = $m['attachment_path'];
                $fileName = basename($filePath);
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                // Check if it's an image file
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
                
                if (in_array($fileExt, $imageExtensions)) {
                    // Display actual image for image files
                    $attachmentHTML = <<<HTML
                        <div class="image-attachment">
                            <a href="{$filePath}" target="_blank" class="image-link">
                                <img src="{$filePath}" alt="{$fileName}" class="attachment-image">
                            </a>
                            <div class="image-filename">{$fileName}</div>
                        </div>
                    HTML;
                } else {
                    // Use file icons for non-image files
                    $fileIcons = [
                        'pdf' => 'fas fa-file-pdf',
                        'doc' => 'fas fa-file-word',
                        'docx' => 'fas fa-file-word',
                        'xls' => 'fas fa-file-excel',
                        'xlsx' => 'fas fa-file-excel',
                        'txt' => 'fas fa-file-alt',
                        'zip' => 'fas fa-file-archive',
                        'rar' => 'fas fa-file-archive'
                    ];
                    
                    $fileIcon = $fileIcons[$fileExt] ?? 'fas fa-file';
                    
                    $attachmentHTML = <<<HTML
                        <a href="{$filePath}" target="_blank" class="attachment">
                            <i class="{$fileIcon}"></i>
                            <div class="attachment-info">
                                <div class="attachment-name">{$fileName}</div>
                                <div class="attachment-size">1.2 MB</div>
                            </div>
                        </a>
                    HTML;
                }
            }
            
            $msgClass = $m['is_admin_reply'] ? 'admin-msg' : 'client-msg';
            $readReceipt = $m['is_admin_reply'] ? '<i class="fas fa-check-double" style="margin-left: 5px;"></i>' : '';
            
            // Add admin name for admin messages
            $adminNameHTML = '';
            if ($m['is_admin_reply'] && !empty($m['admin_name'])) {
                $adminNameHTML = '<div class="admin-name">By: ' . htmlspecialchars($m['admin_name']) . '</div>';
            }
            
            // Add unread styling for client messages that haven't been seen by admin
            $unreadClass = '';
            if (!$m['is_admin_reply'] && !$m['is_read_admin']) {
                $unreadClass = 'unread-msg';
            }
            
            // Add data-id attribute for message tracking
            $displayMessages[] = <<<HTML
                <div class="msg {$msgClass} {$unreadClass}" data-id="{$m['id']}">
                    {$adminNameHTML}
                    <div class="msg-text">{$messageText}</div>
                    {$attachmentHTML}
                    <div class="msg-time">{$readReceipt}</div>
                </div>
            HTML;
        }
        
        echo implode('', $displayMessages);
        exit;
    }
    
    // New AJAX handler for badge counts with unseen messages
    if (isset($_GET['load_badges'])) {
        $badgeData = [];
        
        // Get all clients
        $clients = $pdo->query("SELECT id FROM client")->fetchAll(PDO::FETCH_COLUMN);
        
        // Calculate unseen counts for each client (client messages not read by admin)
        foreach ($clients as $client_id) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS unseen_count
                FROM messages
                WHERE (sender_id = :client_id OR recipient_id = :client_id)
                AND is_admin_reply = 0
                AND is_read_admin = 0
            ");
            $stmt->execute([':client_id' => $client_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $badgeData[$client_id] = (int)$result['unseen_count'];
        }
        
        header('Content-Type: application/json');
        echo json_encode($badgeData);
        exit;
    }
    
    // Handle marking messages as read
    if (isset($_GET['mark_read']) && isset($_GET['client_id'])) {
        $client_id = $_GET['client_id'];
        
        // Mark all messages from this client as read by admin
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_read_admin = 1 
            WHERE (sender_id = ? OR recipient_id = ?)
            AND is_admin_reply = 0
        ");
        $stmt->execute([$client_id, $client_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
}

// Initialize grouped as empty array to prevent undefined variable errors
$grouped = [];

try {
    // Get all clients - ensure we always get an array
    $clients = $pdo->query("SELECT id, `Company Name` AS company_name FROM client")->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialize with all clients
    foreach ($clients as $client) {
        $grouped[$client['id']] = [
            'company' => $client['company_name'],
            'messages' => [],
            'unseen_count' => 0,
            'last_message_time' => null
        ];
    }

    // Get all messages - UPDATED QUERY
    $stmt = $pdo->query("
        SELECT m.*, 
               IFNULL(c.`Company Name`, recipient.`Company Name`) AS company_name,
               IFNULL(m.sender_id, 0) AS sender_id_normalized,
               a.full_name AS admin_name
        FROM messages m
        LEFT JOIN client c ON m.sender_id = c.id
        LEFT JOIN client recipient ON m.recipient_id = recipient.id
        LEFT JOIN admin_accounts a ON m.admin_id = a.id
        ORDER BY sent_at DESC
    ");
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Assign messages to clients and count unseen
    foreach ($messages as $msg) {
        $clientKey = $msg['sender_id_normalized'] ?: $msg['recipient_id'];
        if (isset($grouped[$clientKey])) {
            $grouped[$clientKey]['messages'][] = $msg;
            
            // Update last message time
            if (!$grouped[$clientKey]['last_message_time'] || 
                strtotime($msg['sent_at']) > strtotime($grouped[$clientKey]['last_message_time'])) {
                $grouped[$clientKey]['last_message_time'] = $msg['sent_at'];
            }
            
            // Count unseen messages (client messages not read by admin)
            if (!$msg['is_admin_reply'] && !$msg['is_read_admin']) {
                $grouped[$clientKey]['unseen_count']++;
            }
        }
    }
    
    // Sort clients by last message time (newest first) and then by unseen count
    uasort($grouped, function($a, $b) {
        // First sort by unseen count (higher first)
        if ($a['unseen_count'] != $b['unseen_count']) {
            return $b['unseen_count'] - $a['unseen_count'];
        }
        
        // Then by last message time (newer first)
        if ($a['last_message_time'] && $b['last_message_time']) {
            return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
        }
        
        // If one doesn't have messages, put the one with messages first
        if ($a['last_message_time'] && !$b['last_message_time']) return -1;
        if (!$a['last_message_time'] && $b['last_message_time']) return 1;
        
        return 0;
    });
} catch (PDOException $e) {
    // Handle database errors gracefully
    error_log("Database error: " . $e->getMessage());
    $clients = [];
}

// Process messages for display
if (isset($_GET['client_id']) && isset($grouped[$_GET['client_id']])) {
    $client_id = $_GET['client_id'];
    $chat = $grouped[$client_id]['messages'];
    
    // Mark all messages as seen for this client
    $stmt = $pdo->prepare("
        UPDATE messages 
        SET is_read_admin = 1 
        WHERE (sender_id = ? OR recipient_id = ?)
        AND is_admin_reply = 0
    ");
    $stmt->execute([$client_id, $client_id]);
    
    // Update the local count
    $grouped[$client_id]['unseen_count'] = 0;
    
    // Process messages for display
    $displayMessages = [];
    foreach (array_reverse($chat) as $m) {
        $messageText = nl2br(makeLinksClickable(htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8')));
        
        $attachmentHTML = '';
        if (!empty($m['attachment_path'])) {
            $filePath = $m['attachment_path'];
            $fileName = basename($filePath);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Check if it's an image file
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            
            if (in_array($fileExt, $imageExtensions)) {
                // Display actual image for image files
                $attachmentHTML = <<<HTML
                    <div class="image-attachment">
                        <a href="{$filePath}" target="_blank" class="image-link">
                            <img src="{$filePath}" alt="{$fileName}" class="attachment-image">
                        </a>
                        <div class="image-filename">{$fileName}</div>
                    </div>
                HTML;
            } else {
                // Use file icons for non-image files
                $fileIcons = [
                    'pdf' => 'fas fa-file-pdf',
                    'doc' => 'fas fa-file-word',
                    'docx' => 'fas fa-file-word',
                    'xls' => 'fas fa-file-excel',
                    'xlsx' => 'fas fa-file-excel',
                    'txt' => 'fas fa-file-alt',
                    'zip' => 'fas fa-file-archive',
                    'rar' => 'fas fa-file-archive'
                ];
                
                $fileIcon = $fileIcons[$fileExt] ?? 'fas fa-file';
                
                $attachmentHTML = <<<HTML
                    <a href="{$filePath}" target="_blank" class="attachment">
                        <i class="{$fileIcon}"></i>
                        <div class="attachment-info">
                            <div class="attachment-name">{$fileName}</div>
                            <div class="attachment-size">1.2 MB</div>
                        </div>
                    </a>
                HTML;
            }
        }
        
        $msgClass = $m['is_admin_reply'] ? 'admin-msg' : 'client-msg';
        $readReceipt = $m['is_admin_reply'] ? '<i class="fas fa-check-double" style="margin-left: 5px;"></i>' : '';
        
        // Add admin name for admin messages
        $adminNameHTML = '';
        if ($m['is_admin_reply'] && !empty($m['admin_name'])) {
            $adminNameHTML = '<div class="admin-name">By: ' . htmlspecialchars($m['admin_name']) . '</div>';
        }
        
        // Add unread styling for client messages that haven't been seen by admin
        $unreadClass = '';
        if (!$m['is_admin_reply'] && !$m['is_read_admin']) {
            $unreadClass = 'unread-msg';
        }
        
        // Add data-id attribute for message tracking
        $displayMessages[] = <<<HTML
            <div class="msg {$msgClass} {$unreadClass}" data-id="{$m['id']}">
                {$adminNameHTML}
                <div class="msg-text">{$messageText}</div>
                {$attachmentHTML}
                <div class="msg-time">{$readReceipt}</div>
            </div>
        HTML;
    }
}

?>

<!DOCTYPE html>
<html>
<head>
  <title>Admin Messages</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary-blue: #4682B4;
      --light-blue: #b0c4de;
      --dark-blue: #2a5a80;
      --accent-blue: #5a96cf;
      --light-gray: #f8f9fa;
      --white: #ffffff;
      --text-dark: #333;
      --text-medium: #555;
      --text-light: #777;
      --success: #4CAF50;
      --danger: #f44336;
    }
    
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f0f2f5;
      color: var(--text-dark);
      height: 100vh;
      overflow: hidden;
    }
    
    .header {
      background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
      padding: 15px 20px;
      color: white;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      position: relative;
    }
    
    .back-button {
      position: absolute;
      left: 20px;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255, 255, 255, 0.2);
      border: none;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      cursor: pointer;
      transition: all 0.3s;
      z-index: 10;
    }
    
    .back-button:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-50%) scale(1.1);
    }
    
    .logo {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: bold;
      font-size: 1.2rem;
      margin-left: 40px;
    }
    
    .user-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .user-avatar {
      width: 35px;
      height: 35px;
      border-radius: 50%;
      background-color: var(--light-blue);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: bold;
    }
    
    .container {
      display: flex;
      height: calc(100vh - 60px);
    }
    
    .sidebar {
      width: 280px;
      background: var(--white);
      border-right: 1px solid #ddd;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    
    .sidebar-header {
      padding: 20px;
      border-bottom: 1px solid #eee;
      position: relative;
    }
    
    .sidebar-header h2 {
      color: var(--dark-blue);
      font-size: 1.4rem;
      margin-bottom: 15px;
      padding-left: 30px;
    }
    
    .search-container {
      position: relative;
      margin-bottom: 15px;
    }
    
    .search-container input {
      width: 100%;
      padding: 10px 15px 10px 40px;
      border: 1px solid #ddd;
      border-radius: 20px;
      font-size: 14px;
      background-color: var(--light-gray);
    }
    
    .search-container i {
      position: absolute;
      left: 15px;
      top: 12px;
      color: var(--text-light);
    }
    
    .client-list {
      overflow-y: auto;
      flex: 1;
    }
    
    .client-item {
      display: flex;
      align-items: center;
      padding: 15px 20px;
      border-bottom: 1px solid #eee;
      cursor: pointer;
      transition: background 0.2s;
    }
    
    .client-item:hover {
      background-color: var(--light-gray);
    }
    
    .client-item.active {
      background-color: #e6f0ff;
    }
    
    /* NEW: Bold styling for clients with unread messages */
    .client-item.unread {
      background-color: #f0f7ff;
      border-left: 3px solid var(--primary-blue);
    }
    
    .client-item.unread .client-name {
      font-weight: bold;
      color: var(--dark-blue);
    }
    
    .client-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--light-blue), var(--accent-blue));
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      margin-right: 15px;
      flex-shrink: 0;
    }
    
    .client-info {
      flex: 1;
      overflow: hidden;
    }
    
    .client-name {
      font-weight: 600;
      margin-bottom: 3px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .client-last-msg {
      font-size: 13px;
      color: var(--text-light);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .chat-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      background-color: #f5f7fa;
    }
    
    .chat-header {
      padding: 15px 20px;
      background-color: var(--white);
      border-bottom: 1px solid #eee;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: relative;
    }
    
    .chat-back-btn {
      position: absolute;
      left: 15px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--primary-blue);
      font-size: 18px;
      cursor: pointer;
      z-index: 10;
    }
    
    .chat-info {
      display: flex;
      align-items: center;
      margin-left: 30px;
    }
    
    .chat-title {
      font-weight: 600;
      font-size: 16px;
      color: var(--dark-blue);
    }
    
    .chat-status {
      font-size: 13px;
      color: var(--text-light);
      margin-top: 3px;
    }
    
    .chat-actions {
      display: flex;
      gap: 15px;
    }
    
    .action-btn {
      background: none;
      border: none;
      color: var(--primary-blue);
      cursor: pointer;
      font-size: 16px;
      transition: color 0.2s;
    }
    
    .action-btn:hover {
      color: var(--dark-blue);
    }
    
    .messages-container {
      flex: 1;
      overflow-y: auto;
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 15px;
      background-color: var(--light-gray);
      background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="none" stroke="%23b0c4de" stroke-width="0.5" stroke-dasharray="5,5"/></svg>');
      position: relative;
      transition: opacity 0.3s ease;
    }
    
    .messages-container.updating {
      opacity: 1; /* Keep full opacity during updates */
    }
    
    .msg {
      max-width: 70%;
      padding: 12px 15px;
      border-radius: 18px;
      font-size: 14px;
      line-height: 1.5;
      word-wrap: break-word;
      position: relative;
      animation: fadeIn 0.3s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,0.1);
      transition: all 0.3s ease;
      transform-origin: bottom;
    }
    
    .msg-new {
      opacity: 0;
      transform: translateY(20px) scale(0.95);
    }
    
    .msg-visible {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .admin-msg {
      background-color: var(--primary-blue);
      color: white;
      align-self: flex-end;
      border-bottom-right-radius: 5px;
    }
    
    .client-msg {
      background-color: var(--white);
      color: var(--text-dark);
      align-self: flex-start;
      border-bottom-left-radius: 5px;
      border: 1px solid #e0e0e0;
    }
    
    /* NEW: Unread message styling for admin */
    .unread-msg {
        background-color: #e8f4fd !important;
        border-left: 3px solid #2196F3;
        position: relative;
    }
    
    .unread-msg::before {
        content: '';
        position: absolute;
        left: -8px;
        top: 50%;
        transform: translateY(-50%);
        width: 8px;
        height: 8px;
        background-color: #2196F3;
        border-radius: 50%;
    }
    
    .msg-time {
      font-size: 11px;
      margin-top: 5px;
      opacity: 0.7;
      text-align: right;
    }
    
    .client-msg .msg-time {
      color: var(--text-light);
    }
    
    .admin-msg .msg-time {
      color: rgba(255,255,255,0.8);
    }
    
    .attachment {
      margin-top: 10px;
      padding: 8px 12px;
      background: rgba(0,0,0,0.05);
      border-radius: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: inherit;
      border: 1px solid rgba(0,0,0,0.1);
      transition: background 0.2s;
    }
    
    .admin-msg .attachment {
      background: rgba(255,255,255,0.15);
      border-color: rgba(255,255,255,0.2);
    }
    
    .attachment:hover {
      background: rgba(0,0,0,0.08);
    }
    
    .admin-msg .attachment:hover {
      background: rgba(255,255,255,0.2);
    }
    
    .attachment i {
      font-size: 18px;
      flex-shrink: 0;
    }
    
    .attachment-info {
      flex: 1;
      overflow: hidden;
    }
    
    .attachment-name {
      font-size: 13px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .attachment-size {
      font-size: 11px;
      opacity: 0.7;
    }
    
    /* Image attachment styles */
    .image-attachment {
      margin-top: 10px;
      max-width: 300px;
    }

    .image-link {
      display: block;
      text-decoration: none;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid rgba(0,0,0,0.1);
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .image-link:hover {
      transform: scale(1.02);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .attachment-image {
      width: 100%;
      height: auto;
      display: block;
      max-height: 200px;
      object-fit: cover;
    }

    .image-filename {
      font-size: 12px;
      color: var(--text-light);
      margin-top: 5px;
      text-align: center;
      word-break: break-all;
    }

    .admin-msg .image-filename {
      color: rgba(255,255,255,0.8);
    }

    /* Adjust existing attachment styles for non-image files */
    .attachment {
      margin-top: 10px;
      padding: 8px 12px;
      background: rgba(0,0,0,0.05);
      border-radius: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: inherit;
      border: 1px solid rgba(0,0,0,0.1);
      transition: background 0.2s;
      max-width: 250px;
    }

    .admin-msg .attachment {
      background: rgba(255,255,255,0.15);
      border-color: rgba(255,255,255,0.2);
    }

    /* Admin name styling */
    .admin-name {
      font-size: 11px;
      font-weight: 600;
      margin-bottom: 5px;
      opacity: 0.9;
    }

    .client-msg .admin-name {
      color: var(--text-medium);
    }

    .admin-msg .admin-name {
      color: rgba(255,255,255,0.9);
    }

    .reply-container {
      padding: 15px;
      background-color: var(--white);
      border-top: 1px solid #ddd;
    }
    
    .reply-box {
      display: flex;
      gap: 10px;
    }
    
    .file-upload {
      position: relative;
      display: flex;
      align-items: center;
    }
    
    .file-btn {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: var(--light-gray);
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--primary-blue);
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .file-btn:hover {
      background: var(--primary-blue);
      color: white;
    }
    
    .file-input {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: pointer;
    }
    
    .msg-input {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    
    .msg-textarea {
      width: 100%;
      min-height: 45px;
      max-height: 120px;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 24px;
      font-size: 14px;
      resize: none;
      outline: none;
      transition: border-color 0.2s;
    }
    
    .msg-textarea:focus {
      border-color: var(--primary-blue);
      box-shadow: 0 0 0 2px rgba(70, 130, 180, 0.2);
    }
    
    .send-btn {
      width: 45px;
      height: 45px;
      border-radius: 50%;
      background: var(--primary-blue);
      color: white;
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s;
      flex-shrink: 0;
    }
    
    .send-btn:hover {
      background: var(--dark-blue);
      transform: scale(1.05);
    }
    
    .attachment-preview {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      background: var(--light-gray);
      border-radius: 20px;
      margin-top: 10px;
    }
    
    .attachment-preview .file-info {
      flex: 1;
      overflow: hidden;
    }
    
    .attachment-preview .file-name {
      font-size: 13px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .attachment-preview .remove-btn {
      background: none;
      border: none;
      color: var(--danger);
      cursor: pointer;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.2s;
    }
    
    .attachment-preview .remove-btn:hover {
      background: rgba(244, 67, 54, 0.1);
    }
    
    .empty-state {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 20px;
      color: var(--text-light);
    }
    
    .empty-state i {
      font-size: 48px;
      color: var(--light-blue);
      margin-bottom: 15px;
    }
    
    .empty-state h3 {
      font-size: 18px;
      margin-bottom: 10px;
      color: var(--dark-blue);
    }
    
    .empty-state p {
      max-width: 400px;
      line-height: 1.5;
    }
    
    .link {
      color: var(--primary-blue);
      text-decoration: none;
    }
    
    .link:hover {
      text-decoration: underline;
    }
    
    /* Make URLs clickable */
    .msg-text a {
      color: inherit;
      text-decoration: underline;
    }
    
    .admin-msg .msg-text a {
      color: #cce4ff;
    }
    
    .client-msg .msg-text a {
      color: var(--primary-blue);
    }
    
    .sending-spinner {
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    /* New styles for smooth reloading */
    .update-indicator {
      position: absolute;
      top: 10px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--primary-blue);
      color: white;
      padding: 5px 15px;
      border-radius: 20px;
      font-size: 12px;
      z-index: 10;
      opacity: 0;
      transition: opacity 0.3s;
      pointer-events: none;
    }
    
    .update-indicator.visible {
      opacity: 1;
    }
    
    .smooth-scroll {
      scroll-behavior: smooth;
    }
    
    /* NEW: Unseen message badge */
    .unseen-badge {
      background-color: var(--primary-blue);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      margin-left: 5px;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <div class="container">

    <div class="sidebar">

      <div class="sidebar-header">


        <h2>Client Conversations</h2>

        <div class="search-container">

          <i class="fas fa-search"></i>

          <input type="text" placeholder="Search clients...">

        </div>

      </div>

      <div class="client-list">

        <?php foreach ($grouped as $client_id_val => $info): ?>

          <div class="client-item <?= (isset($_GET['client_id']) && $_GET['client_id'] == $client_id_val) ? 'active' : '' ?> <?= $info['unseen_count'] > 0 ? 'unread' : '' ?>"

               onclick="location.href='?client_id=<?= $client_id_val ?>'"

               data-client-id="<?= $client_id_val ?>">

            <div class="client-avatar"><?= substr($info['company'], 0, 1) ?></div>

            <div class="client-info">

              <div class="client-name">

                <?= htmlspecialchars($info['company']) ?>

                <?php if ($info['unseen_count'] > 0): ?>

                  <span class="unseen-badge"><?= $info['unseen_count'] ?></span>

                <?php endif; ?>

              </div>

              <?php 

                // Get the last message if exists

                $lastMsg = !empty($info['messages']) ? $info['messages'][0] : null;

                if ($lastMsg) {

                    $text = $lastMsg['message'];

                    if (empty($text) && !empty($lastMsg['attachment_path'])) {

                        $text = '[Attachment]';

                    }

                    $previewText = ($lastMsg['is_admin_reply'] ? '<i>You:</i> ' : '') . htmlspecialchars($text);

                    $lastMsgText = (strlen($previewText) > 30) ? substr($previewText, 0, 30).'...' : $previewText;

                } else {

                    $lastMsgText = 'No messages';

                }

              ?>

              <div class="client-last-msg"><?= $lastMsgText ?></div>

            </div>

          </div>

        <?php endforeach; ?>

      </div>

    </div>
    

    <div class="chat-container">

      <?php if (isset($displayMessages)): ?>

        <div class="chat-header">

          <button class="chat-back-btn" onclick="window.location.href='admin_messages.php'">

            <i class="fas fa-arrow-left"></i>

          </button>

          <div class="chat-info">

            <div>

              <div class="chat-title"><?= htmlspecialchars($grouped[$client_id]['company']) ?></div>

              <div class="chat-status">Online - Last seen just now</div>

            </div>

          </div>

          <div class="chat-actions">

            <button class="action-btn" title="Call"><i class="fas fa-phone"></i></button>

            <button class="action-btn" title="Information"><i class="fas fa-info-circle"></i></button>

          </div>

        </div>

        

        <div class="messages-container" id="messagesContainer">

          <?= implode('', $displayMessages) ?>

        </div>

        

        <div class="reply-container">

          <form id="replyForm" enctype="multipart/form-data">

            <input type="hidden" name="client_id" value="<?= $client_id ?>">

            

            <div id="attachmentPreview"></div>

            

            <div class="reply-box">

              <div class="file-upload">

                <button type="button" class="file-btn" title="Attach file">

                  <i class="fas fa-paperclip"></i>

                </button>

                <input type="file" name="attachment" id="attachment" class="file-input">

              </div>

              

              <div class="msg-input">

                <textarea name="reply" id="replyInput" class="msg-textarea" placeholder="Type a message..." rows="1"></textarea>

              </div>

              

              <button type="submit" class="send-btn" title="Send message">

                <i class="fas fa-paper-plane"></i>

              </button>

            </div>

          </form>

        </div>

      <?php else: ?>

        <div class="empty-state">

          <i class="fas fa-comments"></i>

          <h3>No Conversation Selected</h3>

          <p>Select a client from the list to view and reply to messages. You can also start a new conversation by selecting a client.</p>

        </div>

      <?php endif; ?>

    </div>

  </div>

  

  <script>

    // Global variables for message tracking

    let lastMessageId = 0;

    let isNearBottom = true;

    let updateIndicatorTimeout;

    const messageContainer = document.getElementById('messagesContainer');

    let isReloading = false; // Flag to prevent concurrent reloads



    // Set clientId from the hidden input

    const clientIdInput = document.querySelector('input[name="client_id"]');

    let clientId = clientIdInput ? clientIdInput.value : null;

    

    // Auto-scroll to bottom of messages

    function scrollToBottom() {

      if (messageContainer) {

        messageContainer.scrollTop = messageContainer.scrollHeight;

      }

    }

    

    // Check if user is near bottom of chat

    function checkScrollPosition() {

      if (!messageContainer) return true;

      isNearBottom = messageContainer.scrollTop + messageContainer.clientHeight >= 

                    messageContainer.scrollHeight - 100;

      return isNearBottom;

    }

    

    // Show update indicator

    function showUpdateIndicator() {

      const indicator = document.querySelector('.update-indicator');

      if (indicator) {

        indicator.classList.add('visible');

        clearTimeout(updateIndicatorTimeout);

        updateIndicatorTimeout = setTimeout(() => {

          indicator.classList.remove('visible');

        }, 2000);

      }

    }



    // Improved reloadMessages function with mark as read
    async function reloadMessages() {
      if (!clientId || isReloading) return;
      isReloading = true;
      
      // Save current scroll position
      const wasNearBottom = checkScrollPosition();
      
      // Show update indicator if not near bottom
      if (!wasNearBottom) {
        showUpdateIndicator();
      }
      
      try {
        const response = await fetch(`admin_messages.php?ajax=1&load_messages=1&client_id=${clientId}&t=${new Date().getTime()}`);
        const html = await response.text();
        
        if (!html.trim()) {
          isReloading = false;
          return;
        }
        
        // Create temporary container
        const tempContainer = document.createElement('div');
        tempContainer.innerHTML = html;
        const newMessages = tempContainer.querySelectorAll('.msg');
        
        // Get last message ID
        const currentMessages = messageContainer.querySelectorAll('.msg');
        if (currentMessages.length > 0) {
          lastMessageId = parseInt(currentMessages[currentMessages.length - 1].dataset.id || '0');
        }
        
        // Add only new messages
        let addedMessages = false;
        newMessages.forEach(msg => {
          const msgId = parseInt(msg.dataset.id || '0');
          
          if (msgId > lastMessageId) {
            // Add smooth entrance effect
            msg.classList.add('msg-new');
            messageContainer.appendChild(msg);
            
            // Trigger animation
            setTimeout(() => {
              msg.classList.add('msg-visible');
            }, 10);
            
            addedMessages = true;
            lastMessageId = Math.max(lastMessageId, msgId);
          }
        });
        
        // Scroll to bottom if new messages were added and user was near bottom
        if (addedMessages && wasNearBottom) {
          messageContainer.classList.add('smooth-scroll');
          scrollToBottom();
          setTimeout(() => {
            messageContainer.classList.remove('smooth-scroll');
          }, 300);
        }
        
        // Mark messages as read when viewing the chat
        if (document.querySelector('.chat-container .chat-header')) {
          fetch(`admin_messages.php?ajax=1&mark_read=1&client_id=${clientId}`)
            .then(() => {
              // Update badges after marking as read
              updateSidebarBadges();
            });
        }
      } catch (error) {
        console.error('Error fetching messages:', error);
      } finally {
        isReloading = false;
      }
    }



    // Function to update sidebar badges

    function updateSidebarBadges() {

      fetch('admin_messages.php?ajax=1&load_badges=1&t=' + new Date().getTime())

        .then(response => response.json())

        .then(data => {

          document.querySelectorAll('.client-item').forEach(item => {

            const clientId = item.getAttribute('data-client-id');

            const badge = item.querySelector('.unseen-badge');

            const count = data[clientId] || 0;

            
            // Update unread styling
            if (count > 0) {
              item.classList.add('unread');
              if (!badge) {
                const nameDiv = item.querySelector('.client-name');
                if (nameDiv) {
                  const newBadge = document.createElement('span');
                  newBadge.className = 'unseen-badge';
                  newBadge.textContent = count;
                  nameDiv.appendChild(newBadge);
                }
              } else {
                badge.textContent = count;
              }
            } else {
              item.classList.remove('unread');
              if (badge) {
                badge.remove();
              }
            }

          });

        })

        .catch(error => {

          console.error('Error updating badges:', error);

        });

    }



    // Initialize with smooth scroll

    window.addEventListener('DOMContentLoaded', () => {

      // Add update indicator to DOM

      if (messageContainer) {

        const indicator = document.createElement('div');

        indicator.className = 'update-indicator';

        indicator.innerHTML = 'New messages available';

        messageContainer.parentNode.insertBefore(indicator, messageContainer);

      }

      

      // Initialize message IDs

      if (messageContainer) {

        const messages = messageContainer.querySelectorAll('.msg');

        if (messages.length > 0) {

          lastMessageId = parseInt(messages[messages.length - 1].dataset.id || '0');

        }

        scrollToBottom();

      }

      

      if (clientId) {

        // Start auto-refresh

        reloadMessages();

        setInterval(reloadMessages, 3000);

      }

      

      // Start badge updates

      updateSidebarBadges();

      setInterval(updateSidebarBadges, 5000);

      

      // Auto-resize textarea

      const textarea = document.getElementById('replyInput');

      if (textarea) {

        textarea.addEventListener('input', function() {

          this.style.height = 'auto';

          this.style.height = (this.scrollHeight) + 'px';

        });

        

        // Submit on Enter (without Shift)

        textarea.addEventListener('keydown', function(e) {

          if (e.key === 'Enter' && !e.shiftKey) {

            e.preventDefault();

            submitMessage();

          }

        });

      }

      

      // File input change handler

      const fileInput = document.getElementById('attachment');

      if (fileInput) {

        fileInput.addEventListener('change', function() {

          if (this.files.length > 0) {

            const file = this.files[0];

            const preview = document.getElementById('attachmentPreview');

            

            preview.innerHTML = `

              <div class="attachment-preview">

                <i class="fas fa-file-alt"></i>

                <div class="file-info">

                  <div class="file-name">${file.name}</div>

                </div>

                <button type="button" class="remove-btn" onclick="document.getElementById('attachment').value = ''; this.parentNode.remove();">

                  <i class="fas fa-times"></i>

                </button>

              </div>

            `;

          }

        });

      }

      

      // Handle form submission

      const replyForm = document.getElementById('replyForm');

      if (replyForm) {

        replyForm.addEventListener('submit', function(e) {

          e.preventDefault();

          submitMessage();

        });

      }

    });

    

    // Submit message via AJAX

    async function submitMessage() {

      if (!clientId) return;

      

      const form = document.getElementById('replyForm');

      const formData = new FormData(form);

      

      // Validate input

      const message = formData.get('reply');

      const attachment = formData.get('attachment');

      

      if (!message && !attachment) {

        return; // Don't send empty messages

      }

      

      // Show sending state

      const sendBtn = document.querySelector('.send-btn');

      const originalContent = sendBtn.innerHTML;

      if (sendBtn) {

        sendBtn.innerHTML = '<i class="fas fa-spinner sending-spinner"></i>';

        sendBtn.disabled = true;

      }

      

      try {

        const response = await fetch('save_message.php', {

          method: 'POST',

          body: formData

        });

        const data = await response.json();

        

        if (data.success) {

          // Clear input and reset form

          document.getElementById('replyInput').value = '';

          document.getElementById('attachment').value = '';

          document.getElementById('attachmentPreview').innerHTML = '';

          

          // Reset textarea height

          const textarea = document.getElementById('replyInput');

          if (textarea) {

            textarea.style.height = 'auto';

          }

          

          // Refresh messages immediately after sending

          await reloadMessages();

          scrollToBottom();

          

          // Update badges

          updateSidebarBadges();

        } else {

          alert('Error sending message: ' + data.message);

        }

      } catch (error) {

        console.error('Error:', error);

        alert('An error occurred while sending the message.');

      } finally {

        // Reset send button

        const sendBtn = document.querySelector('.send-btn');

        if (sendBtn) {

          sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';

          sendBtn.disabled = false;

        }

      }

    }

  </script>

</body>

</html>