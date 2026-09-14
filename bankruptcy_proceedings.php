<?php
session_start();
require 'db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_name'])) {
    header("Location: index.html");
    exit;
}

$user_id = $_SESSION['user_id'];
$company_name = $_SESSION['company_name'];

// Get bankruptcy proceeding details
$stmt = $pdo->prepare("
    SELECT bp.*, bfs.access_granted 
    FROM bankruptcy_proceedings bp 
    LEFT JOIN bankruptcy_filing_status bfs ON bp.user_id = bfs.user_id AND bp.company_name = bfs.company_name 
    WHERE bp.user_id = ? AND bp.company_name = ?
");
$stmt->execute([$user_id, $company_name]);
$proceeding = $stmt->fetch(PDO::FETCH_ASSOC);

// Get court hearings
$stmt = $pdo->prepare("SELECT * FROM bankruptcy_court_hearings WHERE proceeding_id = ? ORDER BY hearing_date");
$stmt->execute([$proceeding['id']]);
$hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get creditors
$stmt = $pdo->prepare("SELECT * FROM bankruptcy_creditors WHERE proceeding_id = ?");
$stmt->execute([$proceeding['id']]);
$creditors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// FRIA Process Stages
$friaStages = [
    'Pre-filing' => ['icon' => 'fa-clipboard-list', 'description' => 'Preparation of petition and required documents'],
    'Petition Filing' => ['icon' => 'fa-file-contract', 'description' => 'Submission of petition to appropriate court'],
    'Initial Hearing' => ['icon' => 'fa-gavel', 'description' => 'Court determines sufficiency of petition'],
    'Stay Order' => ['icon' => 'fa-shield-alt', 'description' => 'Automatic stay on claims against debtor'],
    'Creditors Meeting' => ['icon' => 'fa-users', 'description' => 'Formation of creditors committee'],
    'Rehabilitation Plan' => ['icon' => 'fa-chart-line', 'description' => 'Approval and implementation of plan'],
    'Monitoring' => ['icon' => 'fa-eye', 'description' => 'Court supervision of implementation'],
    'Termination' => ['icon' => 'fa-flag-checkered', 'description' => 'Successful completion of rehabilitation']
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bankruptcy Proceedings - <?= htmlspecialchars($company_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .proceeding-timeline {
            position: relative;
            padding: 20px 0;
        }
        .timeline-progress {
            position: absolute;
            top: 50px;
            left: 50px;
            right: 50px;
            height: 4px;
            background: #e9ecef;
            z-index: 1;
        }
        .timeline-progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #4682B4, #5a96cf);
            transition: width 0.5s ease;
        }
        .timeline-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            z-index: 2;
        }
        .timeline-step {
            text-align: center;
            flex: 1;
        }
        .step-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: white;
            border: 4px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 1.2rem;
            color: #6c757d;
            transition: all 0.3s ease;
        }
        .step-active .step-icon {
            border-color: #4682B4;
            background: #4682B4;
            color: white;
            transform: scale(1.1);
        }
        .step-completed .step-icon {
            border-color: #28a745;
            background: #28a745;
            color: white;
        }
        .step-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        .step-description {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .court-card {
            border-left: 4px solid #4682B4;
            transition: transform 0.3s ease;
        }
        .court-card:hover {
            transform: translateY(-2px);
        }
        .hearing-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-balance-scale me-2"></i>Bankruptcy Proceedings - FRIA Process</h4>
                        <p class="mb-0">Financial Rehabilitation and Insolvency Act of 2010</p>
                    </div>
                    <div class="card-body">
                        <!-- Case Information -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Case Information</h5>
                                <table class="table table-sm">
                                    <tr>
                                        <th>Company:</th>
                                        <td><?= htmlspecialchars($company_name) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Case Number:</th>
                                        <td><?= $proceeding['court_case_number'] ?: 'Pending Assignment' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Filing Type:</th>
                                        <td><?= $proceeding['filing_type'] ?></td>
                                    </tr>
                                    <tr>
                                        <th>Current Stage:</th>
                                        <td><span class="badge bg-primary"><?= $proceeding['current_stage'] ?></span></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h5>Court Information</h5>
                                <table class="table table-sm">
                                    <tr>
                                        <th>Court:</th>
                                        <td><?= $proceeding['court_name'] ?: 'Regional Trial Court - To be assigned' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Presiding Judge:</th>
                                        <td><?= $proceeding['presiding_judge'] ?: 'To be assigned' ?></td>
                                    </tr>
                                    <tr>
                                        <th>Next Hearing:</th>
                                        <td><?= $proceeding['next_hearing_date'] ? date('M d, Y', strtotime($proceeding['next_hearing_date'])) : 'To be scheduled' ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- FRIA Process Timeline -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-road me-2"></i>FRIA Rehabilitation Process</h5>
                            </div>
                            <div class="card-body">
                                <div class="proceeding-timeline">
                                    <div class="timeline-progress">
                                        <div class="timeline-progress-bar" style="width: 25%;"></div>
                                    </div>
                                    <div class="timeline-steps">
                                        <?php 
                                        $currentStageIndex = array_search($proceeding['current_stage'], array_keys($friaStages));
                                        $i = 0;
                                        foreach($friaStages as $stage => $info): 
                                            $stepClass = '';
                                            if ($i < $currentStageIndex) $stepClass = 'step-completed';
                                            elseif ($i == $currentStageIndex) $stepClass = 'step-active';
                                        ?>
                                        <div class="timeline-step <?= $stepClass ?>">
                                            <div class="step-icon">
                                                <i class="fas <?= $info['icon'] ?>"></i>
                                            </div>
                                            <div class="step-title"><?= $stage ?></div>
                                            <div class="step-description"><?= $info['description'] ?></div>
                                        </div>
                                        <?php $i++; endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Court Hearings -->
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-gavel me-2"></i>Court Hearings & Deadlines</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($hearings): ?>
                                            <?php foreach($hearings as $hearing): ?>
                                            <div class="card court-card mb-3">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div>
                                                            <h6 class="card-title"><?= $hearing['hearing_type'] ?></h6>
                                                            <p class="card-text mb-1"><i class="fas fa-calendar me-2"></i><?= date('F j, Y', strtotime($hearing['hearing_date'])) ?> at <?= $hearing['hearing_time'] ?></p>
                                                            <p class="card-text mb-1"><i class="fas fa-map-marker-alt me-2"></i><?= $hearing['venue'] ?: 'Court to be determined' ?></p>
                                                            <p class="card-text"><strong>Purpose:</strong> <?= $hearing['purpose'] ?></p>
                                                            <?php if ($hearing['documents_required']): ?>
                                                            <div class="alert alert-warning py-2">
                                                                <small><strong>Documents Required:</strong> <?= $hearing['documents_required'] ?></small>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="badge hearing-badge bg-<?= $hearing['status'] == 'Scheduled' ? 'warning' : ($hearing['status'] == 'Completed' ? 'success' : 'secondary') ?>">
                                                            <?= $hearing['status'] ?>
                                                        </span>
                                                    </div>
                                                    <?php if ($hearing['outcome']): ?>
                                                    <div class="mt-2 p-2 bg-light rounded">
                                                        <strong>Outcome:</strong> <?= $hearing['outcome'] ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-muted">No hearings scheduled yet.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <!-- Creditors Information -->
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Creditors Summary</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($creditors): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Creditor</th>
                                                            <th>Amount</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($creditors as $creditor): ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($creditor['creditor_name']) ?></td>
                                                            <td>₱<?= number_format($creditor['amount_owed'], 2) ?></td>
                                                            <td><span class="badge bg-<?= $creditor['claim_status'] == 'Approved' ? 'success' : 'warning' ?>"><?= $creditor['claim_status'] ?></span></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted">Creditors list being prepared.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Legal Notices -->
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Legal Notices</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($proceeding['stay_order_issued']): ?>
                                            <div class="alert alert-success">
                                                <h6><i class="fas fa-shield-alt me-2"></i>Stay Order in Effect</h6>
                                                <small>All claims and actions against the company are temporarily suspended.</small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($proceeding['creditors_committee_formed']): ?>
                                            <div class="alert alert-info">
                                                <h6><i class="fas fa-users me-2"></i>Creditors Committee Formed</h6>
                                                <small>Creditors committee has been established for the rehabilitation process.</small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="alert alert-warning">
                                            <h6><i class="fas fa-clock me-2"></i>Next Steps</h6>
                                            <small>Complete all required documents for the next court hearing. Consult with your legal counsel for guidance.</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>