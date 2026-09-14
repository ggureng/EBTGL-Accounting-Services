<?php
// log_activity.php
session_start();
require 'db_connection.php';
require 'audit_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_admin = getCurrentAdminInfo();
    $action_type = $_POST['action_type'] ?? 'UNKNOWN_ACTION';
    $client_id = $_POST['client_id'] ?? null;
    $client_name = $_POST['client_name'] ?? null;
    $search_term = $_POST['search_term'] ?? null;
    $filter_type = $_POST['filter_type'] ?? null;
    $document_name = $_POST['document_name'] ?? null;
    $document_action = $_POST['document_action'] ?? null;
    $message_preview = $_POST['message_preview'] ?? null;
    $attachment_info = $_POST['attachment_info'] ?? null;
    
    $result = '';
    
    switch ($action_type) {
        case 'VIEW_CALLING_CARD':
            $result = logCallingCardView($client_id, $client_name);
            break;
            
        case 'VIEW_CLIENT_DETAILS':
            $result = logClientDetailsView($client_id, $client_name);
            break;
            
        case 'CLIENT_SEARCH':
            $result = logSearchActivity($search_term);
            break;
            
        case 'CLIENT_FILTER':
            $result = logFilterActivity($filter_type);
            break;
            
        case 'DOCUMENT_VIEW':
            $result = logDocumentAction($client_id, $client_name, $document_name, 'view');
            break;
            
        case 'DOCUMENT_DOWNLOAD':
            $result = logDocumentAction($client_id, $client_name, $document_name, 'download');
            break;
            
        case 'SEND_MESSAGE':
            $result = logMessageAction(
                $current_admin['id'],
                $current_admin['username'],
                'SEND_MESSAGE',
                $client_id,
                $client_name,
                $message_preview,
                $attachment_info
            );
            break;
            
        case 'VIEW_CONVERSATION':
            $result = logMessageAction(
                $current_admin['id'],
                $current_admin['username'],
                'VIEW_CONVERSATION',
                $client_id,
                $client_name
            );
            break;
            
        case 'UPLOAD_ATTACHMENT':
            $result = logMessageAction(
                $current_admin['id'],
                $current_admin['username'],
                'UPLOAD_ATTACHMENT',
                $client_id,
                $client_name,
                null,
                $attachment_info
            );
            break;
            
        default:
            // For unknown actions, use the generic logAdminAction
            $action_description = 'Performed action: ' . $action_type;
            $new_values = json_encode([
                'client_id' => $client_id,
                'client_name' => $client_name,
                'search_term' => $search_term,
                'filter_type' => $filter_type,
                'document_name' => $document_name,
                'document_action' => $document_action,
                'message_preview' => $message_preview,
                'attachment_info' => $attachment_info
            ]);
            
            $result = logAdminAction(
                $current_admin['id'],
                $current_admin['username'],
                $action_type,
                $action_description,
                'system',
                null,
                $new_values
            );
            break;
    }
    
    if ($result) {
        echo 'Activity logged successfully with ID: ' . $result;
    } else {
        echo 'Error logging activity';
    }
} else {
    echo 'Invalid request method';
}
?>