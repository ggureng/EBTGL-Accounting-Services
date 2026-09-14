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
    'Accessed Choose Client Modal',
    'choose-client-modal.php',
    null,
    json_encode(['timestamp' => date('Y-m-d H:i:s')])
);

// Load all clients with additional details for the calling cards
$clients = $pdo->query("SELECT id, `Company Name`, `Email`, `Phone`, `Position`, `Full Name` FROM client")->fetchAll(PDO::FETCH_ASSOC);

// Log client list view
logAdminAction(
    $current_admin['id'],
    $current_admin['username'],
    'VIEW_CLIENT_LIST',
    'Viewed client list in modal',
    'client',
    null,
    json_encode(['client_count' => count($clients)])
);
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
            background: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
            margin: 0;
        }
        
        .container-main {
            max-width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .header-section {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-blue);
        }
        
        .header-section h2 {
            color: var(--dark-blue);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 1.5rem;
        }
        
        .header-section h2 i {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
            font-weight: 600;
            padding: 12px 20px;
            border: none;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .client-id {
            background-color: #e6f2ff;
            color: var(--dark-blue);
            border-radius: 20px;
            padding: 5px 15px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .btn-select {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            border: none;
            border-radius: 8px;
            padding: 6px 15px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .btn-select:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(70, 130, 180, 0.3);
        }
        
        .search-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .search-container {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .search-input {
            flex: 1;
            position: relative;
        }
        
        .search-input input {
            width: 100%;
            padding: 10px 15px 10px 40px;
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
            padding: 6px 15px;
            color: var(--dark-blue);
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(176, 196, 222, 0.1);
        }
        
        .badge {
            padding: 6px 10px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 12px;
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
        
        /* Client details dropdown */
        .client-details-dropdown {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            min-height: 300px; /* Prevent jumping */
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
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        .detail-card {
            background: white;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .detail-card h6 {
            color: var(--dark-blue);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        
        .detail-card-content {
            flex-grow: 1;
        }
        
        .list-group-item {
            font-size: 0.85rem;
            padding: 6px 10px;
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
			font-weight: 700; /* Makes text bold */
			text-align: center; /* Ensures text is centered */
			display: inline-flex; /* Better centering control */
			align-items: center; /* Vertical centering */
			justify-content: center; /* Horizontal centering */
			min-width: 80px; /* Ensures consistent button size */
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(70, 130, 180, 0.3);
        }
        
        .loading-spinner {
            text-align: center;
            padding: 20px;
            color: var(--dark-blue);
        }
        
        /* Enhanced Calling Card Styles - LANDSCAPE LAYOUT */
        .calling-card-container {
            position: relative;
            display: inline-block;
        }
        
        .calling-card-trigger {
            cursor: pointer;
            position: relative;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
            color: var(--primary-blue);
            font-weight: 600;
        }
        
        .calling-card-trigger:hover {
            background-color: rgba(70, 130, 180, 0.1);
            text-decoration: underline;
        }
        
        .calling-card {
            display: block;
            position: fixed;
            z-index: 1050;
            width: 500px; /* Wider for landscape */
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            padding: 20px;
            border: 1px solid #e0e0e0;
            max-height: 80vh;
            overflow-y: auto;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
        }
        
        .calling-card.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .calling-card-landscape {
            display: flex;
            flex-direction: row;
            gap: 20px;
        }
        
        .calling-card-personal {
            flex: 1;
            border-right: 1px solid var(--light-blue);
            padding-right: 20px;
        }
        
        .calling-card-company {
            flex: 1;
        }
        
        .calling-card-header {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-blue);
        }
        
        .calling-card-name {
            font-weight: 700;
            color: var(--dark-blue);
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .calling-card-credentials {
            color: var(--primary-blue);
            font-size: 12px;
            margin-bottom: 8px;
            font-style: italic;
        }
        
        .calling-card-position {
            color: #666;
            font-size: 14px;
        }
        
        .calling-card-contact {
            margin-bottom: 15px;
        }
        
        .calling-card-contact-item {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
            font-size: 13px;
        }
        
        .calling-card-contact-item i {
            width: 20px;
            color: var(--accent-blue);
            margin-right: 8px;
        }
        
        .calling-card-company-name {
            font-weight: 700;
            color: var(--dark-blue);
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .calling-card-tagline {
            color: #666;
            font-size: 12px;
            margin-bottom: 12px;
            font-style: italic;
        }
        
        .calling-card-accreditation {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed #ddd;
        }
        
        .calling-card-accreditation p {
            margin-bottom: 5px;
            font-size: 11px;
            color: #666;
        }
        
        .calling-card-close {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #999;
            font-size: 18px;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .calling-card-close:hover {
            color: var(--primary-blue);
        }
        
        @media (max-width: 992px) {
            .client-details-content {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .search-container {
                flex-direction: column;
            }
            
            .header-section h2 {
                font-size: 1.3rem;
            }
            
            .client-details-content {
                grid-template-columns: 1fr;
            }
            
            .calling-card {
                width: 320px;
                left: 50% !important;
                transform: translateX(-50%) translateY(10px);
            }
            
            .calling-card.active {
                transform: translateX(-50%) translateY(0);
            }
            
            .calling-card-landscape {
                flex-direction: column;
            }
            
            .calling-card-personal {
                border-right: none;
                border-bottom: 1px solid var(--light-blue);
                padding-right: 0;
                padding-bottom: 15px;
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container-main">
        <!-- Header -->
        <div class="header-section">
            <h2><i class="fas fa-users"></i> Client Selection</h2>
            <p class="text-muted">Select a client to work with</p>
        </div>
        
        <!-- Search and Filter Section -->
        <div class="search-section">
            <h4 class="mb-3">Find Clients</h4>
            
            <div class="search-container">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" id="clientSearch" placeholder="Search by company name, ID, or contact...">
                </div>
                <button class="btn-select" id="searchButton">
                    <i class="fas fa-search me-1"></i> Search
                </button>
            </div>
            
            <div class="filter-buttons">
                <button class="filter-btn active">All Clients</button>
                <button class="filter-btn">Active</button>
                <button class="filter-btn">Pending</button>
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
                            <?php foreach ($clients as $c): 
                                // Get initials for avatar
                                $initials = '';
                                if (!empty($c['Company Name'])) {
                                    $words = explode(' ', $c['Company Name']);
                                    if (count($words) > 1) {
                                        $initials = strtoupper(substr($words[0], 0, 1) . substr(end($words), 0, 1));
                                    } else {
                                        $initials = strtoupper(substr($c['Company Name'], 0, 2));
                                    }
                                }
                            ?>
                                <tr class="client-row" data-user-id="<?= $c['id'] ?>">
                                    <td>
                                        <span class="client-id"><?= htmlspecialchars($c['id']) ?></span>
                                    </td>
                                    <td>
                                        <div class="calling-card-container">
                                            <div class="calling-card-trigger fw-bold" 
                                                 onmouseenter="showCallingCard(event, 'card-<?= $c['id'] ?>')"
                                                 onmouseleave="scheduleHideCallingCard('card-<?= $c['id'] ?>')"
                                                 onclick="logCallingCardView(<?= $c['id'] ?>, '<?= htmlspecialchars($c['Company Name']) ?>')">
                                                <?= htmlspecialchars($c['Company Name']) ?>
                                            </div>
                                            <div class="calling-card" id="card-<?= $c['id'] ?>" 
                                                 onmouseenter="cancelHideCallingCard('card-<?= $c['id'] ?>')"
                                                 onmouseleave="scheduleHideCallingCard('card-<?= $c['id'] ?>')">
                                                <button class="calling-card-close" onclick="hideCallingCard('card-<?= $c['id'] ?>')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <div class="calling-card-landscape">
                                                    <div class="calling-card-personal">
                                                        <div class="calling-card-header">
                                                            <div class="calling-card-name"><?= htmlspecialchars($c['Full Name']) ?></div>
                                                            <div class="calling-card-credentials">CPA, REB, LLB</div>
                                                            <div class="calling-card-position"><?= htmlspecialchars($c['Position']) ?></div>
                                                        </div>
                                                        <div class="calling-card-contact">
                                                            <div class="calling-card-contact-item">
                                                                <i class="fas fa-phone"></i>
                                                                <span><?= htmlspecialchars($c['Phone']) ?></span>
                                                            </div>
                                                            <div class="calling-card-contact-item">
                                                                <i class="fas fa-envelope"></i>
                                                                <span><?= htmlspecialchars($c['Email']) ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="calling-card-company">
                                                        <div class="calling-card-company-name"><?= htmlspecialchars($c['Company Name']) ?></div>
                                                        <div class="calling-card-tagline">AUDITORS | TAX | MANAGEMENT CONSULTANTS</div>
                                                        <div class="calling-card-accreditation">
                                                            <p>BOA/BIR - Accredited Tax Practitioner</p>
                                                            <p>Licensed Real Estate Broker</p>
                                                            <p>License Number 29056</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
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
        // Store timeout IDs for hiding calling cards
        const callingCardTimeouts = {};
        let activeCardId = null;

        // Function to log calling card views
        function logCallingCardView(clientId, clientName) {
            fetch('log_activity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action_type=VIEW_CALLING_CARD&client_id=' + clientId + '&client_name=' + encodeURIComponent(clientName)
            })
            .then(response => response.text())
            .then(data => {
                console.log('Calling card view logged for:', clientName);
            })
            .catch(error => {
                console.error('Error logging calling card view:', error);
            });
        }

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

        // Simple search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('clientSearch');
            const searchButton = document.getElementById('searchButton');
            
            function performSearch() {
                const searchTerm = searchInput.value.toLowerCase();
                const rows = document.querySelectorAll('tbody .client-row');
                
                // Log search activity
                logSearchActivity(searchTerm);
                
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
            
            // Client details dropdown functionality
            const clientRows = document.querySelectorAll('.client-row');
            clientRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't trigger if clicking on the calling card trigger
                    if (e.target.closest('.calling-card-trigger')) {
                        return;
                    }
                    
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
                            fetchClientDetails(userId, detailsContent);
                        }
                    } else {
                        detailsRow.style.display = 'none';
                        this.classList.remove('active');
                    }
                });
            });
            
            // Function to fetch client details via AJAX
            function fetchClientDetails(userId, container) {
                fetch('get_client_details.php?user_id=' + userId)
                    .then(response => response.text())
                    .then(data => {
                        container.innerHTML = data;
                    })
                    .catch(error => {
                        console.error('Error fetching client details:', error);
                        container.innerHTML = '<div class="alert alert-danger">Error loading client details. Please try again.</div>';
                    });
            }

            // Update card positions on scroll
            window.addEventListener('scroll', function() {
                if (activeCardId) {
                    const card = document.getElementById(activeCardId);
                    if (card && card.classList.contains('active')) {
                        const trigger = document.querySelector(`[onmouseenter*="showCallingCard(event, '${activeCardId}')"]`);
                        if (trigger) {
                            positionCardNearElement(card, trigger);
                        }
                    }
                }
            });
        });
        
        // Function to position card near the trigger element
        function positionCardNearElement(card, trigger) {
            const rect = trigger.getBoundingClientRect();
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
            
            // Calculate position - position directly below the trigger
            let top = rect.bottom + scrollTop + 5;
            let left = rect.left + scrollLeft;
            
            // Check if the card would go off the right edge of the viewport
            const viewportWidth = window.innerWidth;
            const cardWidth = card.offsetWidth;
            if (left + cardWidth > viewportWidth + scrollLeft) {
                left = viewportWidth + scrollLeft - cardWidth - 10;
            }
            
            // Check if the card would go off the bottom edge of the viewport
            const viewportHeight = window.innerHeight;
            const cardHeight = card.offsetHeight;
            if (top + cardHeight > viewportHeight + scrollTop) {
                // If not enough space below, position above the trigger
                top = rect.top + scrollTop - cardHeight - 5;
                
                // Ensure it doesn't go above the viewport
                if (top < scrollTop) {
                    top = scrollTop + 10;
                }
            }
            
            card.style.top = top + 'px';
            card.style.left = left + 'px';
        }

        // Function to show calling card on hover
        function showCallingCard(event, cardId) {
            // Cancel any pending hide operation
            cancelHideCallingCard(cardId);
            
            // Hide any currently active card
            if (activeCardId && activeCardId !== cardId) {
                hideCallingCard(activeCardId);
            }
            
            const card = document.getElementById(cardId);
            const trigger = event.currentTarget;
            
            // Position the card near the trigger
            positionCardNearElement(card, trigger);
            
            card.classList.add('active');
            activeCardId = cardId;
        }

        // Function to schedule hiding of calling card
        function scheduleHideCallingCard(cardId) {
            // Only hide if mouse is not over the card
            const card = document.getElementById(cardId);
            if (card && !card.matches(':hover')) {
                callingCardTimeouts[cardId] = setTimeout(() => {
                    hideCallingCard(cardId);
                }, 300);
            }
        }

        // Function to cancel scheduled hiding
        function cancelHideCallingCard(cardId) {
            if (callingCardTimeouts[cardId]) {
                clearTimeout(callingCardTimeouts[cardId]);
            }
        }

        // Function to immediately hide calling card
        function hideCallingCard(cardId) {
            const card = document.getElementById(cardId);
            if (card) {
                card.classList.remove('active');
                if (activeCardId === cardId) {
                    activeCardId = null;
                }
            }
        }

        // Add event listener to handle clicks on the close button
        document.addEventListener('click', function(e) {
            if (e.target.closest('.calling-card-close')) {
                const card = e.target.closest('.calling-card');
                if (card) {
                    hideCallingCard(card.id);
                }
            }
        });
    </script>
</body>
</html>