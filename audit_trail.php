<?php
session_start();
require 'db_connection.php';
require 'audit_logger.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if (!isset($_GET['modal'])) {
        header('Location: index.html');
        exit;
    }
}

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Audit Trail - EBTGL Accounting</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f9fc;
            color: #333;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 2rem;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #718096, #4a5568);
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #4a5568;
        }
        
        .filter-input, .filter-select {
            padding: 10px 12px;
            border: 1px solid #dce1e5;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: #4682B4;
            box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.1);
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .audit-table th {
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .audit-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .audit-table tr:hover {
            background-color: #f8fafc;
        }
        
        .action-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .badge-success {
            background-color: #c6f6d5;
            color: #22543d;
        }
        
        .badge-warning {
            background-color: #feebc8;
            color: #744210;
        }
        
        .badge-danger {
            background-color: #fed7d7;
            color: #742a2a;
        }
        
        .badge-info {
            background-color: #bee3f8;
            color: #2a4365;
        }
        
        .badge-message {
            background-color: #e9d8fd;
            color: #553c9a;
        }
        
        .admin-badge {
            background-color: #e6fffa;
            color: #234e52;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
        }
        
        .json-preview {
            background: #f7fafc;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.85rem;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }
        
        .json-preview:hover {
            white-space: normal;
            overflow: visible;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid #4682B4;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .stat-label {
            color: #718096;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .audit-table {
                font-size: 0.9rem;
            }
            
            .audit-table th,
            .audit-table td {
                padding: 10px 8px;
            }
        }
        
        .export-btn {
            background: linear-gradient(135deg, #38a169, #48bb78);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-history"></i> Admin Audit Trail</h1>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn btn-primary export-btn" onclick="exportToCSV()">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($audit_logs); ?></div>
                <div class="stat-label">Total Logs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_unique(array_column($audit_logs, 'admin_username'))); ?></div>
                <div class="stat-label">Unique Admins</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($action_types); ?></div>
                <div class="stat-label">Action Types</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <form method="GET" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label">Admin Username</label>
                        <select name="admin" class="filter-select">
                            <option value="">All Admins</option>
                            <?php foreach ($admin_usernames as $username): ?>
                                <option value="<?php echo htmlspecialchars($username); ?>" 
                                    <?php echo $filter_admin === $username ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($username); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Action Type</label>
                        <select name="action" class="filter-select">
                            <option value="">All Actions</option>
                            <?php foreach ($action_types as $action): ?>
                                <option value="<?php echo htmlspecialchars($action); ?>" 
                                    <?php echo $filter_action === $action ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date From</label>
                        <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date To</label>
                        <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Audit Logs Table -->
        <?php if (count($audit_logs) > 0): ?>
            <div style="overflow-x: auto;">
                <table class="audit-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Admin</th>
                            <th>Action Type</th>
                            <th>Description</th>
                            <th>Resource</th>
                            <th>Changes</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_logs as $log): ?>
                            <tr>
                                <td><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <span class="admin-badge">
                                        <?php echo htmlspecialchars($log['admin_username'] ?: 'System'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $badge_class = 'badge-info';
                                    if (strpos($log['action_type'], 'SEND_MESSAGE') !== false || strpos($log['action_type'], 'VIEW_CONVERSATION') !== false || strpos($log['action_type'], 'UPLOAD_ATTACHMENT') !== false) {
                                        $badge_class = 'badge-message';
                                    } elseif (strpos($log['action_type'], 'CREATE') !== false) {
                                        $badge_class = 'badge-success';
                                    } elseif (strpos($log['action_type'], 'UPDATE') !== false) {
                                        $badge_class = 'badge-warning';
                                    } elseif (strpos($log['action_type'], 'DELETE') !== false || strpos($log['action_type'], 'FAILED') !== false) {
                                        $badge_class = 'badge-danger';
                                    }
                                    ?>
                                    <span class="action-badge <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars($log['action_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['action_description']); ?></td>
                                <td><?php echo htmlspecialchars($log['resource_affected'] ?: '-'); ?></td>
                                <td>
                                    <?php if ($log['new_values']): ?>
                                        <div class="json-preview" title="Click to expand">
                                            <?php echo htmlspecialchars($log['new_values']); ?>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data">
                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; color: #cbd5e0;"></i>
                <h3>No audit logs found</h3>
                <p>No admin activities match your current filters.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function clearFilters() {
            document.getElementById('filterForm').reset();
            document.getElementById('filterForm').submit();
        }
        
        function exportToCSV() {
            // Get table data
            const table = document.querySelector('.audit-table');
            let csv = [];
            
            // Add headers
            const headers = [];
            for (let i = 0; i < table.rows[0].cells.length; i++) {
                headers.push(table.rows[0].cells[i].textContent);
            }
            csv.push(headers.join(','));
            
            // Add rows
            for (let i = 1; i < table.rows.length; i++) {
                const row = [];
                const cells = table.rows[i].cells;
                
                for (let j = 0; j < cells.length; j++) {
                    let cellText = cells[j].textContent.trim();
                    // Escape commas and quotes for CSV
                    cellText = cellText.replace(/"/g, '""');
                    if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n')) {
                        cellText = '"' + cellText + '"';
                    }
                    row.push(cellText);
                }
                
                csv.push(row.join(','));
            }
            
            // Download CSV file
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'admin_audit_trail_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Auto-submit form when date inputs change
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });
    </script>
</body>
</html>