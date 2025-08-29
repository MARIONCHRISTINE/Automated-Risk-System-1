<?php
include_once 'includes/auth.php';
requireRole('compliance_team');
include_once 'config/database.php';

if (!isset($_GET['id'])) {
    die('Risk ID is required');
}

$riskId = $_GET['id'];
$database = new Database();
$db = $database->getConnection();

// Get complete risk details
$query = "SELECT r.*, 
                 u.full_name as reporter_name, u.email as reporter_email,
                 ro.full_name as owner_name, ro.email as owner_email
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          LEFT JOIN users ro ON r.risk_owner_id = ro.id 
          WHERE r.id = ?";

$stmt = $db->prepare($query);
$stmt->execute([$riskId]);
$risk = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$risk) {
    die('Risk not found');
}

$riskCategories = json_decode($risk['risk_categories'] ?? '[]', true);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Risk Details - ID <?php echo htmlspecialchars($risk['id']); ?></title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            line-height: 1.6;
        }
        .print-header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 3px solid #E60012; 
            padding-bottom: 15px; 
        }
        .detail-section { 
            margin-bottom: 25px; 
            page-break-inside: avoid;
        }
        .detail-label { 
            font-weight: bold; 
            color: #495057; 
            display: inline-block;
            min-width: 150px;
        }
        .detail-value { 
            margin-left: 10px; 
        }
        .section-title {
            color: #E60012;
            border-bottom: 2px solid #E60012;
            padding-bottom: 5px;
            margin-bottom: 15px;
        }
        .description-box {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #E60012;
            margin: 10px 0;
        }
        .category-tag {
            background: #E60012;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.9rem;
            margin-right: 5px;
            display: inline-block;
            margin-bottom: 5px;
        }
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>Airtel Risk Management System</h1>
        <h2>Risk Details Report</h2>
        <p><strong>Risk ID:</strong> <?php echo htmlspecialchars($risk['id']); ?></p>
        <p>Generated on: <?php echo date('F j, Y g:i A'); ?></p>
    </div>

    <div class="detail-section">
        <h3 class="section-title">Basic Information</h3>
        <p><span class="detail-label">Risk Name:</span> <?php echo htmlspecialchars($risk['risk_name']); ?></p>
        <p><span class="detail-label">Department:</span> <?php echo htmlspecialchars($risk['department']); ?></p>
        <p><span class="detail-label">Status:</span> <?php echo htmlspecialchars($risk['status']); ?></p>
        <p><span class="detail-label">Risk Level:</span> <?php echo htmlspecialchars($risk['risk_level']); ?></p>
        <p><span class="detail-label">Created Date:</span> <?php echo date('F j, Y g:i A', strtotime($risk['created_at'])); ?></p>
        <p><span class="detail-label">Board Reporting:</span> <?php echo ($risk['to_be_reported_to_board'] == 'YES' ? 'Yes' : 'No'); ?></p>
    </div>

    <div class="detail-section">
        <h3 class="section-title">People Involved</h3>
        <p><span class="detail-label">Reported By:</span> <?php echo htmlspecialchars($risk['reporter_name'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Reporter Email:</span> <?php echo htmlspecialchars($risk['reporter_email'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Risk Owner:</span> <?php echo htmlspecialchars($risk['owner_name'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Owner Email:</span> <?php echo htmlspecialchars($risk['owner_email'] ?? 'N/A'); ?></p>
    </div>

    <div class="detail-section">
        <h3 class="section-title">Risk Description</h3>
        <div class="description-box">
            <?php echo nl2br(htmlspecialchars($risk['risk_description'] ?? 'No description provided')); ?>
        </div>
    </div>

    <?php if (!empty($riskCategories)): ?>
    <div class="detail-section">
        <h3 class="section-title">Risk Categories</h3>
        <?php foreach ($riskCategories as $category): ?>
            <span class="category-tag"><?php echo htmlspecialchars($category); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="detail-section">
        <h3 class="section-title">Risk Assessment</h3>
        <p><span class="detail-label">Inherent Likelihood:</span> <?php echo htmlspecialchars($risk['inherent_likelihood'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Inherent Consequence:</span> <?php echo htmlspecialchars($risk['inherent_consequence'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Inherent Rating:</span> <?php echo htmlspecialchars($risk['inherent_rating'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Residual Likelihood:</span> <?php echo htmlspecialchars($risk['residual_likelihood'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Residual Consequence:</span> <?php echo htmlspecialchars($risk['residual_consequence'] ?? 'N/A'); ?></p>
        <p><span class="detail-label">Residual Rating:</span> <?php echo htmlspecialchars($risk['residual_rating'] ?? 'N/A'); ?></p>
    </div>

    <div class="detail-section">
        <h3 class="section-title">Timeline</h3>
        <p><span class="detail-label">Planned Start:</span> <?php echo ($risk['planned_start_date'] ? date('F j, Y', strtotime($risk['planned_start_date'])) : 'N/A'); ?></p>
        <p><span class="detail-label">Planned Completion:</span> <?php echo ($risk['planned_completion_date'] ? date('F j, Y', strtotime($risk['planned_completion_date'])) : 'N/A'); ?></p>
        <p><span class="detail-label">Actual Start:</span> <?php echo ($risk['actual_start_date'] ? date('F j, Y', strtotime($risk['actual_start_date'])) : 'N/A'); ?></p>
        <p><span class="detail-label">Actual Completion:</span> <?php echo ($risk['actual_completion_date'] ? date('F j, Y', strtotime($risk['actual_completion_date'])) : 'N/A'); ?></p>
        <p><span class="detail-label">Progress:</span> <?php echo htmlspecialchars($risk['progress_percentage'] ?? '0'); ?>%</p>
    </div>

    <div class="detail-section">
        <h3 class="section-title">Treatment Actions</h3>
        <div class="description-box">
            <?php echo nl2br(htmlspecialchars($risk['treatment_actions'] ?? 'No treatment actions specified')); ?>
        </div>
    </div>

    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
