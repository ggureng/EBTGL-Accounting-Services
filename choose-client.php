<?php
session_start();
require 'db_connection.php';
require 'audit_logger.php'; // Include the audit logger

// Log page access
$current_admin = getCurrentAdminInfo();
logAdminAction(
    $current_admin['id'],
    $current_admin['username'],
    'PAGE_ACCESS',
    'Accessed Choose Client Page',
    'choose-client.php',
    null,
    json_encode(['timestamp' => date('Y-m-d H:i:s')])
);

// Load all clients
$clients = $pdo->query("SELECT id, `Company Name` FROM client")->fetchAll(PDO::FETCH_ASSOC);

// Log client list view
logAdminAction(
    $current_admin['id'],
    $current_admin['username'],
    'VIEW_CLIENT_LIST',
    'Viewed client list in main page',
    'client',
    null,
    json_encode(['client_count' => count($clients)])
);

// Handle document actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'log_document_action') {
        $client_id = $_POST['client_id'] ?? null;
        $client_name = $_POST['client_name'] ?? null;
        $document_name = $_POST['document_name'] ?? null;
        $action_type = $_POST['document_action'] ?? null;
        
        if ($client_id && $client_name && $document_name && $action_type) {
            $action_description = ucfirst($action_type) . ' document: ' . $document_name . ' for client: ' . $client_name;
            
            logAdminAction(
                $current_admin['id'],
                $current_admin['username'],
                'DOCUMENT_' . strtoupper($action_type),
                $action_description,
                'documents',
                null,
                json_encode([
                    'client_id' => $client_id,
                    'client_name' => $client_name,
                    'document_name' => $document_name,
                    'action' => $action_type
                ])
            );
            
            echo json_encode(['success' => true, 'message' => 'Document action logged']);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Client - EBTGL Accounting Services</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #4682B4;
            --light-blue: #b0c4de;
            --accent-blue: #5a96cf;
            --dark-blue: #3a6a94;
            --background: #f8f9fa;
        }
        
        body {
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px;
        }
        
        /* Navbar styling to match dashboard */
        .navbar {
            background: linear-gradient(135deg, #6a8fbb, #b0c4de);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            padding: 10px 20px;
        }
        
        .navbar-brand {
            display: flex;
            align-items: center;
            font-weight: bold;
            color: white !important;
        }
        
        .navbar-brand img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            border: 2px solid white;
        }
        
        .navbar .nav-link {
            color: white !important;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }
        
        .navbar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        /* Main content styling */
        .container-main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .header-section {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-blue);
            position: relative;
        }
        
        .header-section h2 {
            color: var(--dark-blue);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-section h2 i {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        /* Card styling */
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(70, 130, 180, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
            font-weight: 600;
            padding: 15px 20px;
            border: none;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .client-id {
            background-color: #e6f2ff;
            color: var(--dark-blue);
            border-radius: 20px;
            padding: 5px 15px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(70, 130, 180, 0.3);
        }
        
        .btn-back {
            background: linear-gradient(135deg, #a9a9a9, #808080);
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back:hover {
            background: linear-gradient(135deg, #808080, #5a5a5a);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Search and filter section */
        .search-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .search-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .search-input {
            flex: 1;
            position: relative;
        }
        
        .search-input input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input input:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 3px rgba(90, 150, 207, 0.2);
            outline: none;
        }
        
        .search-input i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            background-color: #e6f2ff;
            border: none;
            border-radius: 20px;
            padding: 8px 20px;
            color: var(--dark-blue);
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
        }
        
        /* Stats section */
        .stats-container {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
    
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--light-blue), var(--accent-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .stat-content h3 {
            margin: 0;
            font-size: 24px;
            color: var(--dark-blue);
        }
        
        .stat-content p {
            margin: 5px 0 0;
            color: #666;
            font-size: 14px;
        }
        
        /* Client details dropdown */
        .client-details-dropdown {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .client-details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .client-details-content {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .detail-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .detail-card h6 {
            color: var(--dark-blue);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 20px;
            color: var(--dark-blue);
        }
        
        /* Client row styling for clickable rows */
        .client-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .client-row:hover {
            background-color: rgba(70, 130, 180, 0.05);
        }
        
        .client-row.active {
            background-color: rgba(70, 130, 180, 0.1);
        }
        
        .chevron-icon {
            transition: transform 0.3s ease;
        }
        
        .client-row.active .chevron-icon {
            transform: rotate(180deg);
        }

        /* Document styles */
        .document-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
        
        .document-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }
        
        .document-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .document-item:hover {
            border-color: var(--accent-blue);
            box-shadow: 0 2px 8px rgba(70, 130, 180, 0.1);
        }
        
        .document-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .document-icon {
            color: var(--primary-blue);
            font-size: 18px;
        }
        
        .document-name {
            font-weight: 500;
            color: #2c3e50;
        }
        
        .document-size {
            font-size: 12px;
            color: #6c757d;
            margin-left: 10px;
        }
        
        .document-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-document {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-view-doc {
            background: #e3f2fd;
            color: var(--primary-blue);
        }
        
        .btn-view-doc:hover {
            background: #bbdefb;
        }
        
        .btn-download-doc {
            background: #e8f5e8;
            color: #28a745;
        }
        
        .btn-download-doc:hover {
            background: #c8e6c9;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .client-grid {
                grid-template-columns: 1fr;
            }
            
            .search-container {
                flex-direction: column;
            }
            
            .client-details-content {
                grid-template-columns: 1fr;
            }
            
            .document-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .document-actions {
                align-self: flex-end;
            }
        }
    </style>
</head>
<body>

    <div class="container-main">
        <!-- Back Button -->
        <a href="dashboard.php" class="btn btn-back mb-4">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Header -->
        <div class="header-section">
            <h2><i class="fas fa-users"></i> Client Transactions</h2>
            <p class="text-muted">Select a client to view details and documents</p>
        </div>
        
        <!-- Search and Filter Section -->
        <div class="search-section">
            <h4 class="mb-4">Find Clients</h4>
            
            <div class="search-container">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" id="clientSearch" placeholder="Search by company name, ID, or contact...">
                </div>
                <button class="btn-view" id="searchButton">
                    <i class="fas fa-search me-2"></i> Search
                </button>
            </div>
            
            <div class="filter-buttons">
                <button class="filter-btn active">All Clients</button>
                <!--<button class="filter-btn">Active</button>
                <button class="filter-btn">Pending</button>
                <button class="filter-btn">Needs Review</button>
                <button class="filter-btn">Archived</button>-->
            </div>
        </div>
        
        <!-- Clients Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-list me-2"></i> Client Directory
                </div>
                <div>
                    <span class="badge bg-light text-dark"><?php echo count($clients); ?> records</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Company Name</th>
                                <th>Status</th>
                                <th>Last Activity</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clients as $c): ?>
                                <tr class="client-row" data-user-id="<?= $c['id'] ?>">
                                    <td>
                                        <span class="client-id"><?= htmlspecialchars($c['id']) ?></span>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($c['Company Name']) ?></td>
                                    <td>
                                        <span class="badge bg-success">Active</span>
                                    </td>
                                    <td>Jun 15, 2023</td>
                                    <td>
                                        <i class="fas fa-chevron-down chevron-icon"></i>
                                    </td>
                                </tr>
                                <tr class="client-details-row" id="details-<?= $c['id'] ?>" style="display: none;">
                                    <td colspan="5">
                                        <div class="client-details-dropdown">
                                            <div class="loading-spinner">
                                                <i class="fas fa-spinner fa-spin"></i> Loading client details...
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to log client details view
        function logClientDetailsView(clientId, clientName) {
            fetch('log_activity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action_type=VIEW_CLIENT_DETAILS&client_id=' + clientId + '&client_name=' + encodeURIComponent(clientName)
            })
            .then(response => response.text())
            .then(data => {
                console.log('Client details view logged for:', clientName);
            })
            .catch(error => {
                console.error('Error logging client details view:', error);
            });
        }

        // Function to log search activity
        function logSearchActivity(searchTerm) {
            if (searchTerm.length > 2) { // Only log meaningful searches
                fetch('log_activity.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action_type=CLIENT_SEARCH&search_term=' + encodeURIComponent(searchTerm)
                })
                .then(response => response.text())
                .then(data => {
                    console.log('Search activity logged:', searchTerm);
                })
                .catch(error => {
                    console.error('Error logging search activity:', error);
                });
            }
        }

        // Function to log document actions
        function logDocumentAction(clientId, clientName, documentName, action) {
            const formData = new FormData();
            formData.append('action', 'log_document_action');
            formData.append('client_id', clientId);
            formData.append('client_name', clientName);
            formData.append('document_name', documentName);
            formData.append('document_action', action);

            fetch('choose-client.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Document action logged:', action, 'for', documentName);
                }
            })
            .catch(error => {
                console.error('Error logging document action:', error);
            });
        }

        // Function to handle document viewing
        function viewDocument(clientId, clientName, documentName) {
            // Log the document view action
            logDocumentAction(clientId, clientName, documentName, 'view');
            
            // Simulate document viewing (replace with actual document viewer)
            alert('Viewing document: ' + documentName + '\nFor client: ' + clientName);
            
            // In a real implementation, you would open the document in a viewer
            // window.open('documents/' + documentName, '_blank');
        }

        // Function to handle document downloading
        function downloadDocument(clientId, clientName, documentName) {
            // Log the document download action
            logDocumentAction(clientId, clientName, documentName, 'download');
            
            // Simulate document download (replace with actual download logic)
            alert('Downloading document: ' + documentName + '\nFor client: ' + clientName);
            
            // In a real implementation, you would trigger the download
            // const link = document.createElement('a');
            // link.href = 'documents/' + documentName;
            // link.download = documentName;
            // link.click();
        }

        // Add interactive features
        document.addEventListener('DOMContentLoaded', function() {
            // Filter button functionality
            const filterButtons = document.querySelectorAll('.filter-btn');
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const filterType = this.textContent;
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Log filter activity
                    fetch('log_activity.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action_type=CLIENT_FILTER&filter_type=' + encodeURIComponent(filterType)
                    });
                });
            });
            
            // Simple search functionality
            const searchInput = document.getElementById('clientSearch');
            const searchButton = document.getElementById('searchButton');
            
            function performSearch() {
                const searchTerm = searchInput.value.toLowerCase();
                
                // Log search activity
                logSearchActivity(searchTerm);
                
                const rows = document.querySelectorAll('tbody .client-row');
                
                rows.forEach(row => {
                    const companyName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                    const clientId = row.querySelector('td:first-child').textContent.toLowerCase();
                    
                    if (companyName.includes(searchTerm) || clientId.includes(searchTerm)) {
                        row.style.display = '';
                        // Also show the details row if it's visible
                        const detailsRow = document.getElementById('details-' + row.dataset.userId);
                        if (detailsRow && detailsRow.style.display !== 'none') {
                            detailsRow.style.display = '';
                        }
                    } else {
                        row.style.display = 'none';
                        // Also hide the details row
                        const detailsRow = document.getElementById('details-' + row.dataset.userId);
                        if (detailsRow) {
                            detailsRow.style.display = 'none';
                        }
                    }
                });
            }
            
            searchInput.addEventListener('keyup', performSearch);
            searchButton.addEventListener('click', performSearch);
            
            // Client details dropdown functionality
            const clientRows = document.querySelectorAll('.client-row');
            clientRows.forEach(row => {
                row.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const clientName = this.querySelector('td:nth-child(2)').textContent.trim();
                    const detailsRow = document.getElementById('details-' + userId);
                    
                    // Toggle the details row
                    if (detailsRow.style.display === 'none') {
                        // Close any other open details
                        document.querySelectorAll('.client-details-row').forEach(r => {
                            if (r.id !== 'details-' + userId) {
                                r.style.display = 'none';
                            }
                        });
                        
                        document.querySelectorAll('.client-row').forEach(r => {
                            r.classList.remove('active');
                        });
                        
                        // Show this one
                        detailsRow.style.display = '';
                        this.classList.add('active');
                        
                        // Log client details view
                        logClientDetailsView(userId, clientName);
                        
                        // Load details if not already loaded
                        const detailsContent = detailsRow.querySelector('.client-details-dropdown');
                        if (detailsContent.innerHTML.includes('Loading client details')) {
                            fetchClientDetails(userId, detailsContent, clientName);
                        }
                    } else {
                        detailsRow.style.display = 'none';
                        this.classList.remove('active');
                    }
                });
            });
            
            // Function to fetch client details via AJAX
            function fetchClientDetails(userId, container, clientName) {
                fetch('get_client_details.php?user_id=' + userId)
                    .then(response => response.text())
                    .then(data => {
                        container.innerHTML = data;
                        
                        // Add documents section after loading client details
                        addDocumentsSection(container, userId, clientName);
                    })
                    .catch(error => {
                        console.error('Error fetching client details:', error);
                        container.innerHTML = '<div class="alert alert-danger">Error loading client details. Please try again.</div>';
                    });
            }

            // Function to add documents section
            function addDocumentsSection(container, clientId, clientName) {
                const documentsHTML = `
                    <div class="document-section">
                        <h6><i class="fas fa-file-alt"></i> Client Documents</h6>
                        <div class="document-list">
                            <div class="document-item">
                                <div class="document-info">
                                    <i class="fas fa-file-pdf document-icon"></i>
                                    <span class="document-name">Financial Statements Q1 2024.pdf</span>
                                    <span class="document-size">(2.4 MB)</span>
                                </div>
                                <div class="document-actions">
                                    <button class="btn-document btn-view-doc" 
                                            onclick="viewDocument(${clientId}, '${clientName}', 'Financial Statements Q1 2024.pdf')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-document btn-download-doc" 
                                            onclick="downloadDocument(${clientId}, '${clientName}', 'Financial Statements Q1 2024.pdf')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <i class="fas fa-file-excel document-icon"></i>
                                    <span class="document-name">Transaction Report March 2024.xlsx</span>
                                    <span class="document-size">(1.8 MB)</span>
                                </div>
                                <div class="document-actions">
                                    <button class="btn-document btn-view-doc" 
                                            onclick="viewDocument(${clientId}, '${clientName}', 'Transaction Report March 2024.xlsx')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-document btn-download-doc" 
                                            onclick="downloadDocument(${clientId}, '${clientName}', 'Transaction Report March 2024.xlsx')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <i class="fas fa-file-word document-icon"></i>
                                    <span class="document-name">Audit Report 2023.docx</span>
                                    <span class="document-size">(3.1 MB)</span>
                                </div>
                                <div class="document-actions">
                                    <button class="btn-document btn-view-doc" 
                                            onclick="viewDocument(${clientId}, '${clientName}', 'Audit Report 2023.docx')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-document btn-download-doc" 
                                            onclick="downloadDocument(${clientId}, '${clientName}', 'Audit Report 2023.docx')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                            <div class="document-item">
                                <div class="document-info">
                                    <i class="fas fa-file-invoice-dollar document-icon"></i>
                                    <span class="document-name">Tax Return 2023.pdf</span>
                                    <span class="document-size">(1.5 MB)</span>
                                </div>
                                <div class="document-actions">
                                    <button class="btn-document btn-view-doc" 
                                            onclick="viewDocument(${clientId}, '${clientName}', 'Tax Return 2023.pdf')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-document btn-download-doc" 
                                            onclick="downloadDocument(${clientId}, '${clientName}', 'Tax Return 2023.pdf')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                container.innerHTML += documentsHTML;
            }
        });
    </script>
</body>
</html>