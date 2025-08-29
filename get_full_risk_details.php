<?php
include_once 'includes/auth.php';
requireRole('compliance_team');
include_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Risk ID is required']);
    exit;
}

$riskId = $_GET['id'];
$database = new Database();
$db = $database->getConnection();

try {
    // Get complete risk details with all related information
    $query = "SELECT r.*, 
                     u.full_name as reporter_name, u.email as reporter_email,
                     ro.full_name as owner_name, ro.email as owner_email,
                     DATEDIFF(NOW(), r.created_at) as days_open
              FROM risk_incidents r 
              LEFT JOIN users u ON r.reported_by = u.id 
              LEFT JOIN users ro ON r.risk_owner_id = ro.id 
              WHERE r.id = ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$riskId]);
    $risk = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$risk) {
        echo json_encode(['success' => false, 'message' => 'Risk not found']);
        exit;
    }
    
    // Parse risk categories
    $riskCategories = json_decode($risk['risk_categories'] ?? '[]', true);
    
    // Generate comprehensive HTML for risk details
    $html = '
    <div class="risk-details-container">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
            <div class="detail-section">
                <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">Basic Information</h4>
                <div style="space-y: 0.75rem;">
                    <div><span class="detail-label">Risk ID:</span> <span class="detail-value">' . htmlspecialchars($risk['id']) . '</span></div>
                    <div><span class="detail-label">Risk Name:</span> <span class="detail-value">' . htmlspecialchars($risk['risk_name']) . '</span></div>
                    <div><span class="detail-label">Department:</span> <span class="detail-value">' . htmlspecialchars($risk['department']) . '</span></div>
                    <div><span class="detail-label">Status:</span> <span class="detail-value">' . htmlspecialchars($risk['status']) . '</span></div>
                    <div><span class="detail-label">Risk Level:</span> <span class="detail-value">' . htmlspecialchars($risk['risk_level']) . '</span></div>
                    <div><span class="detail-label">Days Open:</span> <span class="detail-value">' . $risk['days_open'] . ' days</span></div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">People Involved</h4>
                <div style="space-y: 0.75rem;">
                    <div><span class="detail-label">Reported By:</span> <span class="detail-value">' . htmlspecialchars($risk['reporter_name'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Reporter Email:</span> <span class="detail-value">' . htmlspecialchars($risk['reporter_email'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Risk Owner:</span> <span class="detail-value">' . htmlspecialchars($risk['owner_name'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Owner Email:</span> <span class="detail-value">' . htmlspecialchars($risk['owner_email'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Created Date:</span> <span class="detail-value">' . date('M j, Y g:i A', strtotime($risk['created_at'])) . '</span></div>
                    <div><span class="detail-label">Board Reporting:</span> <span class="detail-value">' . ($risk['to_be_reported_to_board'] == 'YES' ? 'Yes' : 'No') . '</span></div>
                </div>
            </div>
        </div>
        
        <div class="detail-section" style="margin-bottom: 2rem;">
            <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">Risk Description</h4>
            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #E60012;">
                <p style="margin: 0; line-height: 1.6; color: #495057;">' . nl2br(htmlspecialchars($risk['risk_description'] ?? 'No description provided')) . '</p>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
            <div class="detail-section">
                <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">Risk Assessment</h4>
                <div style="space-y: 0.75rem;">
                    <div><span class="detail-label">Inherent Likelihood:</span> <span class="detail-value">' . htmlspecialchars($risk['inherent_likelihood'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Inherent Consequence:</span> <span class="detail-value">' . htmlspecialchars($risk['inherent_consequence'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Inherent Rating:</span> <span class="detail-value">' . htmlspecialchars($risk['inherent_rating'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Residual Likelihood:</span> <span class="detail-value">' . htmlspecialchars($risk['residual_likelihood'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Residual Consequence:</span> <span class="detail-value">' . htmlspecialchars($risk['residual_consequence'] ?? 'N/A') . '</span></div>
                    <div><span class="detail-label">Residual Rating:</span> <span class="detail-value">' . htmlspecialchars($risk['residual_rating'] ?? 'N/A') . '</span></div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">Timeline</h4>
                <div style="space-y: 0.75rem;">
                    <div><span class="detail-label">Planned Start:</span> <span class="detail-value">' . ($risk['planned_start_date'] ? date('M j, Y', strtotime($risk['planned_start_date'])) : 'N/A') . '</span></div>
                    <div><span class="detail-label">Planned Completion:</span> <span class="detail-value">' . ($risk['planned_completion_date'] ? date('M j, Y', strtotime($risk['planned_completion_date'])) : 'N/A') . '</span></div>
                    <div><span class="detail-label">Actual Start:</span> <span class="detail-value">' . ($risk['actual_start_date'] ? date('M j, Y', strtotime($risk['actual_start_date'])) : 'N/A') . '</span></div>
                    <div><span class="detail-label">Actual Completion:</span> <span class="detail-value">' . ($risk['actual_completion_date'] ? date('M j, Y', strtotime($risk['actual_completion_date'])) : 'N/A') . '</span></div>
                    <div><span class="detail-label">Progress:</span> <span class="detail-value">' . htmlspecialchars($risk['progress_percentage'] ?? '0') . '%</span></div>
                </div>
            </div>
        </div>';
    
    if (!empty($riskCategories)) {
        $html .= '
        <div class="detail-section" style="margin-bottom: 2rem;">
            <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">Risk Categories</h4>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">';
        
        foreach ($riskCategories as $category) {
            $html .= '<span style="background: #E60012; color: white; padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.9rem;">' . htmlspecialchars($category) . '</span>';
        }
        
        $html .= '</div></div>';
    }
    
    $html .= '
        <div class="detail-section">
            <h4 style="color: #E60012; margin-bottom: 1rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">Treatment Actions</h4>
            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #E60012;">
                <p style="margin: 0; line-height: 1.6; color: #495057;">' . nl2br(htmlspecialchars($risk['treatment_actions'] ?? 'No treatment actions specified')) . '</p>
            </div>
        </div>
    </div>';
    
    echo json_encode(['success' => true, 'html' => $html]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
