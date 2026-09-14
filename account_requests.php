<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$conn = new mysqli("sql201.ezyro.com", "ezyro_39028485", "pogiako09", "ezyro_39028485_client_info");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "SELECT `ID`, `Company Name`, `Email`, `Phone`, `Date`, `permit_file` FROM client WHERE status = 'pending'";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Account Requests - JT Accounting</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #4682B4;
            --light-blue: #b0c4de;
            --accent-blue: #5a96cf;
            --dark-blue: #2a5a80;
            --light-gray: #f8f9fa;
            --success-green: #4CAF50;
            --danger-red: #f44336;
            --warning-orange: #ff9800;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(to bottom, #f8f9fa, #e9ecef);
            color: #333;
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Header section */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-blue);
        }
        
        .header h1 {
            color: var(--dark-blue);
            font-size: 2.2rem;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header h1 i {
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
        
        .header-stats {
            display: flex;
            gap: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px 25px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark-blue);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        /* Back button */
        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #a9a9a9, #808080);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            margin-bottom: 25px;
        }
        
        .btn-back:hover {
            background: linear-gradient(135deg, #808080, #5a5a5a);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* Table styling */
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 40px;
        }
        
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        thead {
            background: linear-gradient(135deg, var(--accent-blue), var(--primary-blue));
            color: white;
        }
        
        th {
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        tbody tr {
            background-color: white;
            transition: all 0.2s ease;
            border-bottom: 1px solid #eee;
        }
        
        tbody tr:hover {
            background-color: #f0f7ff;
        }
        
        td {
            padding: 15px;
            color: #555;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .file-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: var(--light-blue);
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .file-link:hover {
            background: var(--primary-blue);
            transform: translateY(-2px);
        }
        
        .no-file {
            color: #999;
            font-style: italic;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn-approve, .btn-delete {
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-approve {
            background: linear-gradient(135deg, var(--success-green), #45a049);
            color: white;
        }
        
        .btn-approve:hover {
            background: linear-gradient(135deg, #45a049, var(--success-green));
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, var(--danger-red), #e53935);
            color: white;
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #e53935, var(--danger-red));
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(244, 67, 54, 0.3);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .empty-state i {
            font-size: 5rem;
            color: var(--light-blue);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 1.8rem;
            color: var(--dark-blue);
            margin-bottom: 15px;
        }
        
        .empty-state p {
            color: #666;
            max-width: 600px;
            margin: 0 auto 30px;
        }
        
        /* Loading spinner */
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-blue);
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 5px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive design */
        @media (max-width: 900px) {
            .header {
                flex-direction: column;
                gap: 20px;
                align-items: flex-start;
            }
            
            .header-stats {
                width: 100%;
                justify-content: center;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 600px) {
            .header-stats {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-approve, .btn-delete {
                width: 100%;
                justify-content: center;
            }
            
            .file-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?= $_SESSION['alert'] ?? 'info' ?>" style="padding: 15px; margin: 20px auto; max-width: 1200px; border-radius: 8px; background: <?= 
        ($_SESSION['alert'] == 'success') ? '#d4edda' : (
        ($_SESSION['alert'] == 'error') ? '#f8d7da' : (
        ($_SESSION['alert'] == 'warning') ? '#fff3cd' : '#cce5ff'
    )) ?>; color: <?= 
        ($_SESSION['alert'] == 'success') ? '#155724' : (
        ($_SESSION['alert'] == 'error') ? '#721c24' : (
        ($_SESSION['alert'] == 'warning') ? '#856404' : '#004085'
    )) ?>; border: 1px solid <?= 
        ($_SESSION['alert'] == 'success') ? '#c3e6cb' : (
        ($_SESSION['alert'] == 'error') ? '#f5c6cb' : (
        ($_SESSION['alert'] == 'warning') ? '#ffeaa7' : '#b8daff'
    )) ?>;">
        <strong><?= 
            ($_SESSION['alert'] == 'success') ? '✓ Success!' : (
            ($_SESSION['alert'] == 'error') ? '✗ Error!' : (
            ($_SESSION['alert'] == 'warning') ? '⚠ Warning!' : 'ℹ Info'
        )) ?></strong> 
        <?= $_SESSION['message'] ?>
        <button onclick="this.parentElement.style.display='none'" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">×</button>
    </div>
    <?php 
    unset($_SESSION['message']);
    unset($_SESSION['alert']);
    ?>
    <?php endif; ?>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-clock"></i> Account Requests</h1>
            <div class="header-stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $result->num_rows; ?></div>
                    <div class="stat-label">Pending Requests</div>
                </div>
            </div>
        </div>

        <?php if ($result->num_rows > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Company Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Date Submitted</th>
                            <th>Permit File</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['Company Name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['Email']) ?></td>
                                <td><?= htmlspecialchars($row['Phone'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['Date']) ?></td>
                                <td>
                                    <?php if (!empty($row['permit_file'])): ?>
                                        <div class="file-actions">
                                            <a href="<?= htmlspecialchars($row['permit_file']) ?>" target="_blank" class="file-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="<?= htmlspecialchars($row['permit_file']) ?>" download class="file-link">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-file">No file</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <form style="display:inline;" method="POST" action="process_request.php" onsubmit="return confirm('Are you sure you want to APPROVE this account? This will stamp the document with verification.');">
                                            <input type="hidden" name="id" value="<?= $row['ID'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn-approve">
                                                <i class="fas fa-check-circle"></i> Approve
                                            </button>
                                        </form>
                                        <form style="display:inline;" method="POST" action="process_request.php" onsubmit="return confirm('Are you sure you want to DELETE this account?');">
                                            <input type="hidden" name="id" value="<?= $row['ID'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn-delete">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Pending Requests</h3>
                <p>All account requests have been processed. Check back later for new submissions.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Add animation to table rows
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    row.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, 100 * index);
            });
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>