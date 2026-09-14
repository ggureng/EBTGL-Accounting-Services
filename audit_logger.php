<?php
// Remove session_start() from here since it's already called in admin_messages.php
// session_start(); // COMMENTED OUT - Already started in calling file
require 'db_connection.php';

// Check if admin is logged in - UPDATED to check session status
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to get current admin info
function getCurrentAdminInfo() {
    if (!isset($_SESSION['admin_id'])) {
        // Return a default system admin if no session
        return [
            'id' => 1,
            'username' => 'system'
        ];
    }
    return [
        'id' => $_SESSION['admin_id'] ?? 1,
        'username' => $_SESSION['admin_username'] ?? 'system'
    ];
}

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

// Function to log message actions
function logMessageAction($admin_id, $admin_username, $action_type, $client_id, $client_name, $message_preview = null, $attachment_info = null) {
    $action_description = '';
    $new_values = [];
    
    switch ($action_type) {
        case 'SEND_MESSAGE':
            $action_description = "Sent message to client: {$client_name}";
            $new_values = [
                'client_id' => $client_id,
                'client_name' => $client_name,
                'message_preview' => $message_preview,
                'has_attachment' => !empty($attachment_info),
                'attachment_info' => $attachment_info
            ];
            break;
            
        case 'VIEW_CONVERSATION':
            $action_description = "Viewed conversation with client: {$client_name}";
            $new_values = [
                'client_id' => $client_id,
                'client_name' => $client_name
            ];
            break;
            
        case 'UPLOAD_ATTACHMENT':
            $action_description = "Uploaded attachment in conversation with: {$client_name}";
            $new_values = [
                'client_id' => $client_id,
                'client_name' => $client_name,
                'attachment_info' => $attachment_info
            ];
            break;
    }
    
    return logAdminAction(
        $admin_id,
        $admin_username,
        $action_type,
        $action_description,
        'messages',
        null,
        json_encode($new_values)
    );
}

// Function to log calling card views
function logCallingCardView($client_id, $client_name) {
    $current_admin = getCurrentAdminInfo();
    return logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'VIEW_CALLING_CARD',
        "Viewed calling card for client: {$client_name}",
        'client',
        null,
        json_encode(['client_id' => $client_id, 'client_name' => $client_name])
    );
}

// Function to log client details views
function logClientDetailsView($client_id, $client_name) {
    $current_admin = getCurrentAdminInfo();
    return logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'VIEW_CLIENT_DETAILS',
        "Viewed detailed information for client: {$client_name}",
        'client',
        null,
        json_encode(['client_id' => $client_id, 'client_name' => $client_name])
    );
}

// Function to log search activity
function logSearchActivity($search_term) {
    $current_admin = getCurrentAdminInfo();
    return logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'CLIENT_SEARCH',
        "Searched for clients with term: {$search_term}",
        'client',
        null,
        json_encode(['search_term' => $search_term])
    );
}

// Function to log filter activity
function logFilterActivity($filter_type) {
    $current_admin = getCurrentAdminInfo();
    return logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'CLIENT_FILTER',
        "Applied client filter: {$filter_type}",
        'client',
        null,
        json_encode(['filter_type' => $filter_type])
    );
}

// Function to log document actions
function logDocumentAction($client_id, $client_name, $document_name, $action) {
    $current_admin = getCurrentAdminInfo();
    $action_description = ucfirst($action) . "ed document: {$document_name} for client: {$client_name}";
    
    return logAdminAction(
        $current_admin['id'],
        $current_admin['username'],
        'DOCUMENT_' . strtoupper($action),
        $action_description,
        'documents',
        null,
        json_encode([
            'client_id' => $client_id,
            'client_name' => $client_name,
            'document_name' => $document_name,
            'action' => $action
        ])
    );
}

// Only run the HTML/display code if this file is accessed directly, not when included
if (basename($_SERVER['PHP_SELF']) == 'audit_logger.php') {
    // Get filter parameters
    $filter_admin = $_GET['admin'] ?? '';
    $filter_action = $_GET['action'] ?? '';
    $filter_date_from = $_GET['date_from'] ?? '';
    $filter_date_to = $_GET['date_to'] ?? '';

    // Build query with filters
    $query = "
        SELECT al.*, aa.username as admin_username 
        FROM admin_audit_logs al 
        LEFT JOIN admin_accounts aa ON al.admin_id = aa.id 
        WHERE 1=1
    ";

    $params = [];

    if ($filter_admin) {
        $query .= " AND (aa.username LIKE ? OR al.admin_username LIKE ?)";
        $params[] = "%$filter_admin%";
        $params[] = "%$filter_admin%";
    }

    if ($filter_action) {
        $query .= " AND al.action_type LIKE ?";
        $params[] = "%$filter_action%";
    }

    if ($filter_date_from) {
        $query .= " AND DATE(al.created_at) >= ?";
        $params[] = $filter_date_from;
    }

    if ($filter_date_to) {
        $query .= " AND DATE(al.created_at) <= ?";
        $params[] = $filter_date_to;
    }

    $query .= " ORDER BY al.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique admin usernames for filter dropdown
    $admin_usernames = $pdo->query("
        SELECT DISTINCT username FROM admin_accounts 
        UNION 
        SELECT DISTINCT admin_username FROM admin_audit_logs 
        WHERE admin_username IS NOT NULL
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Get unique action types for filter dropdown
    $action_types = $pdo->query("
        SELECT DISTINCT action_type FROM admin_audit_logs 
        ORDER BY action_type
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    // Continue with HTML output...
    // [Rest of the HTML code remains the same]
}
?>