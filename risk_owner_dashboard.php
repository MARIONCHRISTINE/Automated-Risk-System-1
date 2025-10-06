<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';
include_once 'includes/shared_notifications.php';
// Verify session data exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}
$database = new Database();
$db = $database->getConnection();
// First, let's check what columns actually exist in the risk_incidents table
try {
    $check_columns = "SHOW COLUMNS FROM risk_incidents LIKE 'department'";
    $stmt = $db->prepare($check_columns);
    $stmt->execute();
    $has_department_column = $stmt->rowCount() > 0;
    
    $check_columns = "SHOW COLUMNS FROM risk_incidents LIKE 'normalized_department'";
    $stmt = $db->prepare($check_columns);
    $stmt->execute();
    $has_normalized_department_column = $stmt->rowCount() > 0;
    
    // Determine which department column to use
    $department_column = $has_normalized_department_column ? 'normalized_department' : 
                         ($has_department_column ? 'department' : 'department_id');
} catch (Exception $e) {
    // Default to 'department' if we can't check
    $department_column = 'department';
}
// Handle success message from risk edit
if (isset($_GET['updated']) && isset($_GET['risk_id'])) {
    $updated_risk_id = (int)$_GET['risk_id'];
    $treatments_count = isset($_GET['treatments']) ? (int)$_GET['treatments'] : 0;
    $success_message = "✅ Risk updated successfully!";
}
// Handle risk assignment/unassignment
if ($_POST && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'assign_to_me') {
            $risk_id = (int)$_POST['risk_id'];
            $query = "UPDATE risk_incidents SET risk_owner_id = :owner_id, updated_at = NOW() WHERE id = :risk_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':owner_id', $_SESSION['user_id']);
            $stmt->bindParam(':risk_id', $risk_id);
            $stmt->execute();
            $success_message = "Risk successfully assigned to you!";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}
// Handle accepting assignment
if ($_POST && isset($_POST['accept_assignment'])) {
    $risk_id = $_POST['risk_id'];
    // Update assignment status
    $query = "UPDATE risk_assignments SET status = 'Accepted' WHERE risk_id = :risk_id AND assigned_to = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':risk_id', $risk_id);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    if ($stmt->execute()) {
        $success_message = "Risk assignment accepted successfully!";
    }
}
// Handle updating existing_or_new status (Risk Owner decision)
if ($_POST && isset($_POST['update_risk_type'])) {
    $risk_id = $_POST['risk_id'];
    $existing_or_new = $_POST['existing_or_new'];
    $to_be_reported_to_board = $_POST['to_be_reported_to_board'];
    
    $query = "UPDATE risk_incidents SET existing_or_new = :existing_or_new, to_be_reported_to_board = :to_be_reported_to_board, updated_at = NOW(), updated_by = :user_id WHERE id = :risk_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':existing_or_new', $existing_or_new);
    $stmt->bindParam(':to_be_reported_to_board', $to_be_reported_to_board);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->bindParam(':risk_id', $risk_id);
    
    if ($stmt->execute()) {
        $success_message = "Risk classification updated successfully!";
    }
}
// Handle risk assessment update
if ($_POST && isset($_POST['update_assessment'])) {
    $risk_id = $_POST['risk_id'];
    $probability = $_POST['probability'];
    $impact = $_POST['impact'];
    
    // Calculate risk score
    $risk_rating = $probability * $impact;
    
    // Determine risk level based on rating
    $risk_level = 'Low';
    if ($risk_rating > 20) $risk_level = 'Critical';
    elseif ($risk_rating > 12) $risk_level = 'High';
    elseif ($risk_rating > 6) $risk_level = 'Medium';
    
    $query = "UPDATE risk_incidents SET 
              probability = :probability, 
              impact = :impact, 
              risk_level = :risk_level,
              updated_at = NOW(),
              updated_by = :user_id
              WHERE id = :risk_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':probability', $probability);
    $stmt->bindParam(':impact', $impact);
    $stmt->bindParam(':risk_level', $risk_level);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->bindParam(':risk_id', $risk_id);
    
    if ($stmt->execute()) {
        $success_message = "Risk assessment updated successfully!";
    }
}
// Get current user info
$user = getCurrentUser();
// Debug: Ensure department is available
if (empty($user['department'])) {
    // Fallback: get department from session or database
    if (isset($_SESSION['department'])) {
        $user['department'] = $_SESSION['department'];
    } else {
        // Get department from database - try different possible column names
        try {
            $dept_query = "SELECT department, normalized_department, department_name FROM users WHERE id = :user_id";
            $dept_stmt = $db->prepare($dept_query);
            $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
            $dept_stmt->execute();
            $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
            if ($dept_result) {
                // Try different possible column names
                $user['department'] = $dept_result['normalized_department'] ?? 
                                     $dept_result['department'] ?? 
                                     $dept_result['department_name'] ?? 
                                     'Unknown Department';
            }
        } catch (Exception $e) {
            $user['department'] = 'Unknown Department';
        }
    }
}
// Get comprehensive statistics
$stats = [];
// Risks in my department only - use the department column we determined exists
try {
    $query = "SELECT COUNT(*) as total FROM risk_incidents WHERE $department_column = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $user['department']);
    $stmt->execute();
    $stats['department_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $stats['department_risks'] = 0;
}
// Risks assigned to this risk owner
try {
    $query = "SELECT COUNT(*) as total FROM risk_incidents WHERE risk_owner_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $stats['my_assigned_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $stats['my_assigned_risks'] = 0;
}
// Risks reported by this risk owner
try {
    $query = "SELECT COUNT(*) as total FROM risk_incidents WHERE reported_by = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $stats['my_reported_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $stats['my_reported_risks'] = 0;
}
// High/Critical risks assigned to me - use risk_level field
try {
    $query = "SELECT COUNT(*) as total FROM risk_incidents 
              WHERE risk_owner_id = :user_id 
              AND risk_level IN ('High', 'Critical')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $stats['my_high_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $stats['my_high_risks'] = 0;
}
// Get recently assigned risks (last 24 hours)
try {
    $recent_query = "SELECT COUNT(*) as count FROM risk_incidents 
                     WHERE risk_owner_id = :user_id 
                     AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $recent_stmt = $db->prepare($recent_query);
    $recent_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $recent_stmt->execute();
    $stats['recent_assignments'] = $recent_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (Exception $e) {
    $stats['recent_assignments'] = 0;
}
// Get assigned risks (risks assigned to this user)
try {
    $query = "SELECT r.*, u.full_name as reporter_name 
             FROM risk_incidents r 
             LEFT JOIN users u ON r.reported_by = u.id 
             WHERE r.risk_owner_id = :user_id
             ORDER BY r.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $assigned_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $assigned_risks = [];
}
// Get department risks - use the department column we determined exists
try {
    $query = "SELECT ri.*, u.full_name as reporter_name, ro.full_name as risk_owner_name 
             FROM risk_incidents ri 
             LEFT JOIN users u ON ri.reported_by = u.id
             LEFT JOIN users ro ON ri.risk_owner_id = ro.id 
             WHERE ri.$department_column = :department 
             ORDER BY ri.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $user['department']);
    $stmt->execute();
    $department_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $department_risks = [];
}
// Get risks reported by this user
try {
    $query = "SELECT ri.*, 
                     ro.full_name as risk_owner_name,
                     reporter.full_name as reporter_name 
             FROM risk_incidents ri 
             LEFT JOIN users ro ON ri.risk_owner_id = ro.id
             LEFT JOIN users reporter ON ri.reported_by = reporter.id 
             WHERE ri.reported_by = :user_id 
             ORDER BY ri.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $my_reported_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $my_reported_risks = [];
}
// Get pending assignments (if you have a separate assignments table)
$pending_assignments = []; // Initialize as empty array since we're not sure if this table exists
// Try to get pending assignments, but handle if table doesn't exist
try {
    $pending_query = "SELECT r.*, u.full_name as reporter_name, ra.assignment_date 
                     FROM risk_assignments ra
                     JOIN risk_incidents r ON ra.risk_id = r.id
                     JOIN users u ON r.reported_by = u.id
                     WHERE ra.assigned_to = :user_id AND ra.status = 'Pending'
                     ORDER BY ra.assignment_date DESC";
    $pending_stmt = $db->prepare($pending_query);
    $pending_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $pending_stmt->execute();
    $pending_assignments = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist, keep empty array
    $pending_assignments = [];
}
// Risk level distribution for department only - use the department column we determined exists
try {
    $query = "SELECT 
        SUM(CASE WHEN risk_level = 'Low' THEN 1 ELSE 0 END) as low_risks, 
        SUM(CASE WHEN risk_level = 'Medium' THEN 1 ELSE 0 END) as medium_risks, 
        SUM(CASE WHEN risk_level = 'High' THEN 1 ELSE 0 END) as high_risks, 
        SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) as critical_risks,
        SUM(CASE WHEN risk_level IS NULL OR risk_level = 'Not Assessed' THEN 1 ELSE 0 END) as not_assessed_risks
        FROM risk_incidents 
        WHERE $department_column = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $user['department']);
    $stmt->execute();
    $risk_levels = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Default values if query fails
    $risk_levels = [
        'low_risks' => 0,
        'medium_risks' => 0,
        'high_risks' => 0,
        'critical_risks' => 0,
        'not_assessed_risks' => 0
    ];
}
// Risk category distribution for department with unique colors - use the department column we determined exists
try {
    $category_query = "SELECT 
        risk_categories, 
        COUNT(*) as count 
        FROM risk_incidents 
        WHERE $department_column = :department 
        GROUP BY risk_categories 
        ORDER BY count DESC";
    $category_stmt = $db->prepare($category_query);
    $category_stmt->bindParam(':department', $user['department']);
    $category_stmt->execute();
    $risk_by_category = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $risk_by_category = [];
}
// Generate unique colors for each category
$category_colors = [
    '#E60012', // Airtel Red
    '#FF6B35', // Orange Red
    '#F7931E', // Orange
    '#FFD700', // Gold
    '#32CD32', // Lime Green
    '#20B2AA', // Light Sea Green
    '#4169E1', // Royal Blue
    '#9370DB', // Medium Purple
    '#FF1493', // Deep Pink
    '#8B4513', // Saddle Brown
    '#2F4F4F', // Dark Slate Gray
    '#B22222'  // Fire Brick
];
// Helper function to clean category names by removing quotes and brackets
function cleanCategoryName($name) {
    return str_replace(['"', "'", '[', ']'], '', $name);
}
// Risk level calculation function that uses risk_level field first
function getRiskLevel($risk) {
    // First check if risk_level is set in the database
    if (!empty($risk['risk_level']) && $risk['risk_level'] !== 'Not Assessed') {
        return strtolower($risk['risk_level']);
    }
    
    // If risk_level is not set or is 'Not Assessed', calculate from available fields
    // Check probability and impact first
    if (!empty($risk['probability']) && !empty($risk['impact'])) {
        $rating = (int)$risk['probability'] * (int)$risk['impact'];
    }
    // Check inherent risk
    elseif (!empty($risk['inherent_likelihood']) && !empty($risk['inherent_consequence'])) {
        $rating = (int)$risk['inherent_likelihood'] * (int)$risk['inherent_consequence'];
    }
    // Check residual risk
    elseif (!empty($risk['residual_likelihood']) && !empty($risk['residual_consequence'])) {
        $rating = (int)$risk['residual_likelihood'] * (int)$risk['residual_consequence'];
    }
    else {
        return 'not-assessed';
    }
    
    if ($rating >= 15) return 'critical';
    if ($rating >= 9) return 'high';
    if ($rating >= 4) return 'medium';
    return 'low';
}
function getRiskLevelText($risk) {
    // First check if risk_level is set in the database
    if (!empty($risk['risk_level']) && $risk['risk_level'] !== 'Not Assessed') {
        return $risk['risk_level'];
    }
    
    // If risk_level is not set or is 'Not Assessed', calculate from available fields
    // Check probability and impact first
    if (!empty($risk['probability']) && !empty($risk['impact'])) {
        $rating = (int)$risk['probability'] * (int)$risk['impact'];
    }
    // Check inherent risk
    elseif (!empty($risk['inherent_likelihood']) && !empty($risk['inherent_consequence'])) {
        $rating = (int)$risk['inherent_likelihood'] * (int)$risk['inherent_consequence'];
    }
    // Check residual risk
    elseif (!empty($risk['residual_likelihood']) && !empty($risk['residual_consequence'])) {
        $rating = (int)$risk['residual_likelihood'] * (int)$risk['residual_consequence'];
    }
    else {
        return 'Not Assessed';
    }
    
    if ($rating >= 15) return 'Critical';
    if ($rating >= 9) return 'High';
    if ($rating >= 4) return 'Medium';
    return 'Low';
}
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'completed': return 'badge-success';
        case 'in_progress': return 'badge-warning';
        case 'cancelled': return 'badge-danger';
        default: return 'badge-secondary';
    }
}
// Function to get beautiful status display
function getBeautifulStatus($status) {
    if (!$status) $status = 'pending';
    
    switch(strtolower($status)) {
        case 'pending':
            return [
                'text' => '🔓 Open',
                'color' => '#0c5460',
                'bg' => '#d1ecf1'
            ];
        case 'in_progress':
            return [
                'text' => '⚡ In Progress',
                'color' => '#004085',
                'bg' => '#cce5ff'
            ];
        case 'completed':
            return [
                'text' => '✅ Completed',
                'color' => '#155724',
                'bg' => '#d4edda'
            ];
        case 'cancelled':
            return [
                'text' => '❌ Cancelled',
                'color' => '#721c24',
                'bg' => '#f8d7da'
            ];
        case 'overdue':
            return [
                'text' => '⏰ Overdue',
                'color' => '#721c24',
                'bg' => '#f8d7da'
            ];
        default:
            return [
                'text' => '🔓 Open',
                'color' => '#0c5460',
                'bg' => '#d1ecf1'
            ];
    }
}
// Calculate real values for the new tabs
// Get department risks count - use the department column we determined exists
try {
    $dept_risks_query = "SELECT COUNT(*) as total FROM risk_incidents WHERE $department_column = :department";
    $dept_risks_stmt = $db->prepare($dept_risks_query);
    $dept_risks_stmt->bindParam(':department', $user['department']);
    $dept_risks_stmt->execute();
    $dept_risks_count = $dept_risks_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $dept_risks_count = 0;
}
// Get assigned risks count (already have this as $stats['my_assigned_risks'])
$assigned_risks_count = $stats['my_assigned_risks'];
// Get successfully managed risks count - only risks that have been properly assessed
try {
    $managed_risks_query = "SELECT COUNT(*) as total FROM risk_incidents 
                             WHERE risk_owner_id = :user_id 
                             AND risk_status = 'completed'
                             AND risk_level IS NOT NULL
                             AND risk_level != 'Not Assessed'";
    $managed_risks_stmt = $db->prepare($managed_risks_query);
    $managed_risks_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $managed_risks_stmt->execute();
    $successfully_managed_count = $managed_risks_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $successfully_managed_count = 0;
}
// Get notifications using shared component
try {
    $all_notifications = getNotifications($db, $_SESSION['user_id']);
} catch (Exception $e) {
    $all_notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Owner Dashboard - Airtel Risk Management</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* All CSS styles remain the same as in the original code */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            padding-top: 150px;
        }
        
        .dashboard {
            min-height: 100vh;
        }
        
        .header {
            background: #E60012;
            padding: 1.5rem 2rem;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(230, 0, 18, 0.2);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .logo-circle {
            width: 55px;
            height: 55px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 5px;
        }
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        .header-titles {
            display: flex;
            flex-direction: column;
        }
        .main-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin: 0;
            line-height: 1.2;
        }
        .sub-title {
            font-size: 1rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.2;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .user-avatar {
            width: 45px;
            height: 45px;
            background: white;
            color: #E60012;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .user-email {
            font-size: 1rem;
            font-weight: 500;
            color: white;
            margin: 0;
            line-height: 1.2;
        }
        .user-role {
            font-size: 0.9rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.2;
        }
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.7rem 1.3rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            margin-left: 1rem;
        }
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        .nav {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            position: fixed;
            top: 100px;
            left: 0;
            right: 0;
            z-index: 999;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        .nav-menu {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-item {
            margin: 0;
        }
        .nav-item a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            cursor: pointer;
        }
        .nav-item a:hover {
            color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        .nav-item a.active {
            color: #E60012;
            border-bottom-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        .flex {
            display: flex;
        }
        .justify-between {
            justify-content: space-between;
        }
        .items-center {
            align-items: center;
        }
        .w-full {
            width: 100%;
        }
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-top: 4px solid #E60012;
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        .card-title {
            color: #333;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .stats-grid, .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid #E60012;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #E60012;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .stat-description {
            color: #999;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(230, 0, 18, 0.2);
        }
        .action-card .stat-number {
            font-size: 3rem;
        }
        
        .risk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
        }
        
        .risk-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            background: white;
            border-top: 3px solid #E60012;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .risk-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .risk-header h3 {
            color: #333;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .risk-level, .risk-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .level-low, .risk-low { background: #d4edda; color: #155724; }
        .level-medium, .risk-medium { background: #fff3cd; color: #856404; }
        .level-high, .risk-high { background: #ffebee; color: #E60012; }
        .level-critical, .risk-critical { background: #ffcdd2; color: #E60012; }
        .level-not-assessed, .risk-not-assessed { background: #e2e3e5; color: #383d41; }
        
        .risk-card p {
            margin-bottom: 0.8rem;
            color: #666;
            line-height: 1.5;
        }
        
        .risk-card strong {
            color: #333;
        }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-outline {
            background: transparent;
            color: #E60012;
            border: 1px solid #E60012;
        }
        .btn-outline:hover {
            background: #E60012;
            color: white;
        }
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        .btn-primary {
            background: #007bff;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 1rem 2rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .cta-button:hover {
            background: #B8000E;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            border-top: 4px solid #E60012;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 10px 10px 0 0;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .close:hover {
            opacity: 0.7;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            transition: border-color 0.3s;
            font-size: 1rem;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        textarea {
            height: 100px;
            resize: vertical;
        }
        
        .success, .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        
        .error, .alert-danger {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        
        .pending-badge {
            background: #fff3cd;
            color: #856404;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .classification-section {
            background: #fff5f5;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #E60012;
            margin-bottom: 1rem;
        }
        
        .classification-section h4 {
            color: #E60012;
            margin-bottom: 1rem;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        .table th,
        .table td {
            padding: 0.75rem;
            vertical-align: top;
            border-top: 1px solid #dee2e6;
            text-align: left;
        }
        .table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid #dee2e6;
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        .table tbody + tbody {
            border-top: 2px solid #dee2e6;
        }
        .risk-row {
            position: relative;
            border-left: 4px solid transparent;
        }
        
        .risk-row.critical { border-left-color: #dc3545; }
        .risk-row.high { border-left-color: #fd7e14; }
        .risk-row.medium { border-left-color: #ffc107; }
        .risk-row.low { border-left-color: #28a745; }
        .risk-row.not-assessed { border-left-color: #6c757d; }
        .assignment-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .owner-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .unassigned-badge {
            background: #fff3e0;
            color: #ef6c00;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .quick-assign-form {
            display: inline-block;
            margin: 0;
        }
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-in-progress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .text-muted {
            color: #6c757d;
        }
        
        /* Custom Risk ID Badge */
        .risk-id-badge {
            display: inline-block;
            background: #E60012;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }
        
        /* Procedures Specific Styles */
        .procedure-section {
            margin: 2rem 0;
            padding: 1.5rem;
            border-left: 4px solid #E60012;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .procedure-title {
            color: #E60012;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .field-definition {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            margin: 0.5rem 0;
        }
        
        .field-name {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .field-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .risk-matrix-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .risk-matrix-table th,
        .risk-matrix-table td {
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            text-align: center;
        }
        
        .risk-matrix-table th {
            background: #E60012;
            color: white;
        }
        
        .matrix-1 { background: #d4edda; }
        .matrix-2 { background: #fff3cd; }
        .matrix-3 { background: #f8d7da; }
        .matrix-4 { background: #f5c6cb; }
        .matrix-5 { background: #dc3545; color: white; }
        
        .toc {
            background: #e9ecef;
            padding: 1.5rem;
            border-radius: 0.25rem;
            margin-bottom: 2rem;
        }
        
        .toc ul {
            list-style: none;
            padding-left: 0;
        }
        
        .toc li {
            margin: 0.5rem 0;
        }
        
        .toc a {
            text-decoration: none;
            color: #E60012;
        }
        
        .toc a:hover {
            text-decoration: underline;
        }
        
        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .department-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            border-left: 4px solid #E60012;
        }
        
        .department-title {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .auto-assignment-flow {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .flow-step {
            display: flex;
            align-items: center;
            margin: 0.5rem 0;
        }
        
        .flow-step i {
            color: #2196f3;
            margin-right: 0.5rem;
            width: 20px;
        }
        
        /* Notification Styles */
        .notification-nav-item {
            position: relative;
        }
        .nav-notification-container {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 1rem 1.5rem;
            border-radius: 0.25rem;
            transition: all 0.3s ease;
            color: #6c757d;
            text-decoration: none;
        }
        .nav-notification-container:hover {
            background-color: rgba(230, 0, 18, 0.05);
            color: #E60012;
            text-decoration: none;
        }
        .nav-notification-container.nav-notification-empty {
            opacity: 0.6;
            cursor: default;
        }
        .nav-notification-container.nav-notification-empty:hover {
            background-color: transparent;
            color: #6c757d;
        }
        .nav-notification-bell {
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        .nav-notification-container:hover .nav-notification-bell {
            transform: scale(1.1);
        }
        .nav-notification-bell.has-notifications {
            color: #ffc107;
            animation: navBellRing 2s infinite;
        }
        @keyframes navBellRing {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-10deg); }
            20%, 40% { transform: rotate(10deg); }
        }
        .nav-notification-text {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .nav-notification-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: navPulse 2s infinite;
            margin-left: auto;
        }
        @keyframes navPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        .nav-notification-dropdown {
            position: fixed !important;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            width: 400px;
            max-height: 500px;
            z-index: 1000;
            display: none;
            transition: all 0.3s ease;
            transform: translateY(-10px);
        }
        .nav-notification-dropdown.show {
            display: block;
            transform: translateY(0);
            opacity: 1;
        }
        .nav-notification-header {
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
            color: #495057;
            background: #f8f9fa;
            border-radius: 0.5rem 0.5rem 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .nav-notification-content {
            max-height: 350px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: max-height 0.3s ease;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
        }
        .nav-notification-content::-webkit-scrollbar {
            width: 8px;
        }
        .nav-notification-content::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        .nav-notification-content::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
            transition: background 0.3s ease;
        }
        .nav-notification-content::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        .nav-notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f8f9fa;
            transition: all 0.3s ease;
            position: relative;
        }
        .nav-notification-item:hover {
            background-color: #f8f9fa;
        }
        .nav-notification-item:last-child {
            border-bottom: none;
        }
        .nav-notification-item.read {
            display: none !important;
        }
        .nav-notification-item.unread {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .nav-notification-title {
            font-weight: bold;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        .nav-notification-risk {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .nav-notification-message {
            color: #495057;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 0.25rem;
            border-left: 3px solid #007bff;
        }
        .nav-notification-date {
            color: #6c757d;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }
        .nav-notification-actions {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .nav-notification-actions .btn {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        /* Quick Actions Containers */
        .quick-actions-containers {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .quick-action-container {
            color: white;
            padding: 1rem; /* Reduced padding for a more compact height */
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100px; /* Reduced min-height */
        }
        .quick-action-container:hover {
            transform: translateY(-5px);
            text-decoration: none;
            color: white;
        }
        .quick-action-icon {
            font-size: 2rem; /* Reduced icon size */
            margin-bottom: 0.5rem; /* Reduced margin */
        }
        .quick-action-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        .quick-action-description {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        /* Chatbot Icon Styling */
        .chatbot-icon {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        /* Risk Management Tabs */
        .risk-management-tabs {
            border-top: 1px solid #dee2e6;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }
        .risk-tabs-nav {
            display: flex;
            gap: 0;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 1.5rem;
        }
        .risk-tab-btn {
            padding: 1rem 1.5rem;
            border: none;
            background: none;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
        }
        .risk-tab-btn:hover {
            color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        .risk-tab-btn.active {
            color: #E60012;
            border-bottom-color: #E60012;
            font-weight: 600;
        }
        .risk-tab-content {
            display: none;
        }
        .risk-tab-content.active {
            display: block;
        }
        
        @media (max-width: 768px) {
            body {
                padding-top: 200px;
            }
            
            .header {
                padding: 1.2rem 1.5rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .header-right {
                align-self: flex-end;
            }
            
            .main-title {
                font-size: 1.3rem;
            }
            
            .sub-title {
                font-size: 0.9rem;
            }
            
            .logout-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
            
            .nav {
                top: 120px;
                padding: 0.25rem 0;
            }
            
            .nav-content {
                padding: 0 0.5rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .nav-menu {
                flex-wrap: nowrap;
                justify-content: flex-start;
                gap: 0;
                min-width: max-content;
                padding: 0 0.5rem;
            }
            
            .nav-item {
                flex: 0 0 auto;
                min-width: 80px;
            }
            
            .nav-item a {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                text-align: center;
                border-bottom: 3px solid transparent;
                border-left: none;
                width: 100%;
                white-space: nowrap;
                min-height: 44px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
            }
            
            .nav-item a.active {
                border-bottom-color: #E60012;
                border-left-color: transparent;
                background-color: rgba(230, 0, 18, 0.1);
            }
            
            .nav-item a:hover {
                background-color: rgba(230, 0, 18, 0.05);
            }
            
            .nav-notification-container {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                min-width: 80px;
            }
            
            .nav-notification-text {
                font-size: 0.65rem;
            }
            
            .nav-notification-bell {
                font-size: 1rem;
            }
            
            .nav-notification-badge {
                width: 16px;
                height: 16px;
                font-size: 0.6rem;
            }
            
            .nav-notification-dropdown {
                width: 95vw;
                left: 2.5vw !important;
                right: 2.5vw !important;
            }
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .modal-body {
                padding: 1.5rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .assignment-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1.5rem;
            }
            
            .stat-number {
                font-size: 2rem;
            }
            
            .chart-container {
                height: 250px;
            }
            .main-content {
                padding: 1rem;
            }
            .card {
                padding: 1rem;
            }
            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            .department-grid {
                grid-template-columns: 1fr;
            }
            .procedure-section {
                padding: 1rem;
            }
            .quick-actions-containers {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .risk-tabs-nav {
                flex-direction: column;
                gap: 0;
            }
            .risk-tab-btn {
                text-align: left;
                border-bottom: 1px solid #dee2e6;
                border-right: 3px solid transparent;
            }
            .risk-tab-btn.active {
                border-right-color: #E60012;
                border-bottom-color: #dee2e6;
            }
        }
        @media print {
            .main-content { margin: 0; }
            .card { box-shadow: none; }
            .btn { display: none; }
            nav { display: none; }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="logo-circle">
                        <img src="image.png" alt="Airtel Logo" />
                    </div>
                    <div class="header-titles">
                        <h1 class="main-title">Airtel Risk Register System</h1>
                        <p class="sub-title">Risk Management System</p>
                    </div>
                </div>
                <div class="header-right">
                    <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'R'; ?></div>
                    <div class="user-details">
                        <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : 'No Email'; ?></div>
                        <div class="user-role">Risk_owner • <?php echo htmlspecialchars($user['department'] ?? 'No Department'); ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        
        <nav class="nav">
            <div class="nav-content">
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="javascript:void(0)" onclick="showTab('dashboard')" class="<?php echo !isset($_GET['tab']) ? 'active' : ''; ?>">
                            🏠 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="report_risk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'report_risk.php' ? 'active' : ''; ?>">
                            📝 Report Risk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="javascript:void(0)" onclick="showTab('my-reports')" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'my-reports' ? 'active' : ''; ?>">
                            👀 My Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="javascript:void(0)" onclick="showTab('procedures')" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'procedures' ? 'active' : ''; ?>">
                            📋 Procedures
                        </a>
                    </li>
                    <li class="nav-item notification-nav-item">
                        <?php
                        // Use shared notifications component
                        if (isset($_SESSION['user_id'])) {
                            renderNotificationBar($all_notifications);
                        }
                        ?>
                    </li>
                </ul>
            </div>
        </nav>
        
        <main class="main-content">
            <?php if (isset($success_message)): ?>
                <div class="success">✅ <?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error">❌ <?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <!-- Dashboard Tab -->
            <div id="dashboard-tab" class="tab-content active">
                <!-- Risk Owner Statistics Cards -->
                <div class="dashboard-grid">
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['department_risks']; ?></span>
                        <div class="stat-label">Department Risks</div>
                        <div class="stat-description">All risks in <?php echo $user['department']; ?></div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['my_assigned_risks']; ?></span>
                        <div class="stat-label">My Assigned Risks</div>
                        <div class="stat-description">Risks assigned to you</div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['my_high_risks']; ?></span>
                        <div class="stat-label">High/Critical Risks</div>
                        <div class="stat-description">High/Critical risks assessed by you</div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $successfully_managed_count; ?></span>
                        <div class="stat-label">Successfully Managed Risks</div>
                        <div class="stat-description">Risks you have completed with proper assessment</div>
                    </div>
                </div>
                
                <!-- Charts Section -->
                <div class="dashboard-grid">
                    <!-- Risk Category Chart (UPDATED with unique colors) -->
                    <div class="card">
                        <h3 class="card-title">Risk Categories Distribution</h3>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Risk Level Chart (EXISTING) -->
                    <div class="card">
                        <h3 class="card-title">Risk Level Distribution</h3>
                        <div class="chart-container">
                            <canvas id="levelChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🚀 Quick Actions</h3>
                    </div>
                    
                    <!-- Team Chat and AI Assistant Containers -->
                    <div class="quick-actions-containers">
                        <!-- Team Chat -->
                        <div class="quick-action-container" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <a href="teamchat.php" style="text-decoration: none; color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                                <div class="quick-action-icon">
                                    <i class="fas fa-comments" style="font-size: 2rem;"></i>
                                </div>
                                <div class="quick-action-title">Team Chat</div>
                                <div class="quick-action-description">Collaborate with your team</div>
                            </a>
                        </div>
                        
                        <!-- AI Assistant -->
                        <div class="quick-action-container" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <a href="javascript:void(0)" onclick="openChatBot()" style="text-decoration: none; color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                                <div class="quick-action-icon">
                                    <img src="chatbot.png" alt="AI Assistant" class="chatbot-icon">
                                </div>
                                <div class="quick-action-title">AI Assistant</div>
                                <div class="quick-action-description">Get help with risk management</div>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Risk Management Tabs -->
                    <div class="risk-management-tabs">
                        <div class="risk-tabs-nav">
                            <button onclick="showRiskTab('department-overview')" id="department-overview-tab" class="risk-tab-btn active">
                                Department Risks Overview
                            </button>
                            <button onclick="showRiskTab('assigned-risks')" id="assigned-risks-tab" class="risk-tab-btn">
                                Assigned Risks
                            </button>
                            <button onclick="showRiskTab('successfully-managed')" id="successfully-managed-tab" class="risk-tab-btn">
                                Risks Successfully Managed by You
                            </button>
                        </div>
                        
                        <!-- Department Risks Overview Content -->
                        <div id="department-overview-content" class="risk-tab-content active">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h4 style="margin: 0; color: #333;">Department Risks Overview</h4>
                                <span style="color: #E60012; font-weight: 600;"><?php echo $dept_risks_count; ?> risks in <?php echo $user['department']; ?></span>
                            </div>
                            
                            <?php if (empty($department_risks)): ?>
                                <div style="text-align: center; padding: 2rem; color: #666;">
                                    <div style="font-size: 2rem; margin-bottom: 1rem;">📋</div>
                                    <h4>No risks in your department yet</h4>
                                    <p>Your department hasn't reported any risks yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Risk ID</th>
                                                <th>Risk Name</th>
                                                <th>Category</th>
                                                <th>Risk Level</th>
                                                <th>Status</th>
                                                <th>Risk Owner</th>
                                                <th>Reported Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($department_risks as $risk): ?>
                                            <tr style="border-left: 4px solid <?php
                                                 $level = getRiskLevel($risk);
                                                echo $level == 'critical' ? '#dc3545' : ($level == 'high' ? '#fd7e14' : ($level == 'medium' ? '#ffc107' : ($level == 'low' ? '#28a745' : '#6c757d')));
                                            ?>;">
                                                <td>
                                                    <?php if (!empty($risk['custom_risk_id'])): ?>
                                                        <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($risk['risk_name']); ?></div>
                                                    <?php if ($risk['risk_description']): ?>
                                                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($risk['risk_description'], 0, 50)) . (strlen($risk['risk_description']) > 50 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                                        <?php echo htmlspecialchars(cleanCategoryName($risk['risk_categories'] ?? 'Uncategorized')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $level = getRiskLevel($risk);
                                                    $levelText = getRiskLevelText($risk);
                                                    $levelColors = [
                                                        'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                        'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                        'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                                    ];
                                                    $colors = $levelColors[$level] ?? $levelColors['not-assessed'];
                                                    ?>
                                                    <span style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                        <?php echo strtoupper($levelText); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusInfo = getBeautifulStatus($risk['risk_status']);
                                                    ?>
                                                    <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                        <?php echo $statusInfo['text']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($risk['risk_owner_name']): ?>
                                                        <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500;">
                                                            <?php echo htmlspecialchars($risk['risk_owner_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="background: #fff3e0; color: #ef6c00; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500;">
                                                            Unassigned
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="color: #666; font-size: 0.9rem;">
                                                    <?php echo date('M j, Y', strtotime($risk['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <a href="view_risk.php?id=<?php echo $risk['id']; ?>" style="background: #E60012; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; text-decoration: none;">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Assigned Risks Content -->
                        <div id="assigned-risks-content" class="risk-tab-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h4 style="margin: 0; color: #333;">My Assigned Risks</h4>
                                <span style="color: #E60012; font-weight: 600;"><?php echo $assigned_risks_count; ?> risks assigned to you</span>
                            </div>
                            
                            <?php if (empty($assigned_risks)): ?>
                                <div style="text-align: center; padding: 2rem; color: #666;">
                                    <div style="font-size: 2rem; margin-bottom: 1rem;">📋</div>
                                    <h4>No risks assigned to you yet</h4>
                                    <p>New risks will appear here when assigned to you by the system.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Risk ID</th>
                                                <th>Risk Name</th>
                                                <th>Category</th>
                                                <th>Risk Level</th>
                                                <th>Status</th>
                                                <th>Assigned Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assigned_risks as $risk): ?>
                                            <tr style="border-left: 4px solid <?php
                                                 $level = getRiskLevel($risk);
                                                echo $level == 'critical' ? '#dc3545' : ($level == 'high' ? '#fd7e14' : ($level == 'medium' ? '#ffc107' : ($level == 'low' ? '#28a745' : '#6c757d')));
                                            ?>;">
                                                <td>
                                                    <?php if (!empty($risk['custom_risk_id'])): ?>
                                                        <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($risk['risk_name']); ?></div>
                                                    <?php if ($risk['risk_description']): ?>
                                                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($risk['risk_description'], 0, 50)) . (strlen($risk['risk_description']) > 50 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                                        <?php echo htmlspecialchars(cleanCategoryName($risk['risk_categories'] ?? 'Uncategorized')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $level = getRiskLevel($risk);
                                                    $levelText = getRiskLevelText($risk);
                                                    $levelColors = [
                                                        'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                        'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                        'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                                    ];
                                                    $colors = $levelColors[$level] ?? $levelColors['not-assessed'];
                                                    ?>
                                                    <span style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                        <?php echo strtoupper($levelText); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusInfo = getBeautifulStatus($risk['risk_status']);
                                                    ?>
                                                    <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                        <?php echo $statusInfo['text']; ?>
                                                    </span>
                                                </td>
                                                <td style="color: #666; font-size: 0.9rem;">
                                                    <?php echo date('M j, Y', strtotime($risk['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 0.5rem;">
                                                        <a href="view_risk.php?id=<?php echo $risk['id']; ?>" style="background: #E60012; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; text-decoration: none;">
                                                            Manage
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Successfully Managed Risks Content (UPDATED with proper assessment check) -->
                        <div id="successfully-managed-content" class="risk-tab-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h4 style="margin: 0; color: #333;">Risks Successfully Managed by You</h4>
                                <span style="color: #E60012; font-weight: 600;"><?php echo $successfully_managed_count; ?> risks completed with proper assessment</span>
                            </div>
                            
                            <?php
                            // Get successfully managed risks with proper assessment check
                            try {
                                $managed_risks_query = "SELECT ri.*, u.full_name as reporter_name
                                                       FROM risk_incidents ri
                                                       LEFT JOIN users u ON ri.reported_by = u.id
                                                       WHERE ri.risk_owner_id = :user_id
                                                        AND ri.risk_status = 'completed'
                                                        AND ri.risk_level IS NOT NULL
                                                        AND ri.risk_level != 'Not Assessed'
                                                       ORDER BY ri.updated_at DESC";
                                $managed_risks_stmt = $db->prepare($managed_risks_query);
                                $managed_risks_stmt->bindParam(':user_id', $_SESSION['user_id']);
                                $managed_risks_stmt->execute();
                                $successfully_managed_risks = $managed_risks_stmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Exception $e) {
                                $successfully_managed_risks = [];
                            }
                            ?>
                            
                            <?php if (empty($successfully_managed_risks)): ?>
                                <div style="text-align: center; padding: 2rem; color: #666;">
                                    <div style="font-size: 2rem; margin-bottom: 1rem;">🎯</div>
                                    <h4>No successfully managed risks yet</h4>
                                    <p>Risks you complete with proper risk assessment will appear here.</p>
                                    <div style="background: #fff3cd; color: #856404; padding: 1rem; border-radius: 8px; margin-top: 1rem; text-align: left;">
                                        <strong>Note:</strong> Only risks that have been properly assessed (with likelihood and impact ratings) and marked as completed will be shown here.
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Risk ID</th>
                                                <th>Risk Name</th>
                                                <th>Category</th>
                                                <th>Risk Level</th>
                                                <th>Reported By</th>
                                                <th>Completed Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($successfully_managed_risks as $risk): ?>
                                            <tr style="border-left: 4px solid #28a745;">
                                                <td>
                                                    <?php if (!empty($risk['custom_risk_id'])): ?>
                                                        <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600; color: #333;"><?php echo htmlspecialchars($risk['risk_name']); ?></div>
                                                    <?php if ($risk['risk_description']): ?>
                                                        <div style="font-size: 0.85rem; color: #666; margin-top: 0.25rem;">
                                                            <?php echo htmlspecialchars(substr($risk['risk_description'], 0, 50)) . (strlen($risk['risk_description']) > 50 ? '...' : ''); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                                        <?php echo htmlspecialchars(cleanCategoryName($risk['risk_categories'] ?? 'Uncategorized')); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $level = getRiskLevel($risk);
                                                    $levelText = getRiskLevelText($risk);
                                                    $levelColors = [
                                                        'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                        'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                        'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                                    ];
                                                    $colors = $levelColors[$level] ?? $levelColors['not-assessed'];
                                                    ?>
                                                    <span style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                        <?php echo strtoupper($levelText); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($risk['reporter_name']); ?></td>
                                                <td style="color: #666; font-size: 0.9rem;">
                                                    <?php echo date('M j, Y', strtotime($risk['updated_at'])); ?>
                                                </td>
                                                <td>
                                                    <a href="view_risk.php?id=<?php echo $risk['id']; ?>" style="background: #28a745; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; text-decoration: none;">
                                                        View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- My Assigned Risks Tab -->
            <div id="risks-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">My Assigned Risks (<?php echo $user['department']; ?>)</h2>
                        <span class="badge badge-info"><?php echo count($assigned_risks); ?> risks assigned to you</span>
                    </div>
                    <?php if (empty($assigned_risks)): ?>
                        <div class="alert-info">
                            <p><strong>No risks assigned to you yet.</strong></p>
                            <p>All risks are automatically assigned by the system. New risks will appear here when assigned to you.</p>
                            <button class="btn btn-primary" onclick="showTab('department')">View Department Risks</button>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Risk ID</th>
                                    <th>Risk Name</th>
                                    <th>Risk Level</th>
                                    <th>Status</th>
                                    <th>Reported By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assigned_risks as $risk): ?>
                                <tr class="risk-row <?php echo getRiskLevel($risk); ?>" data-risk-id="<?php echo $risk['id']; ?>">
                                    <td>
                                        <?php if (!empty($risk['custom_risk_id'])): ?>
                                            <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($risk['risk_name']); ?></strong>
                                        <?php if ($risk['risk_description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($risk['risk_description'], 0, 100)) . (strlen($risk['risk_description']) > 100 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="risk-badge risk-<?php echo getRiskLevel($risk); ?>">
                                            <?php echo getRiskLevelText($risk); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusInfo = getBeautifulStatus($risk['risk_status']);
                                        ?>
                                        <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                            <?php echo $statusInfo['text']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($risk['reporter_name']); ?></td>
                                    <td>
                                        <div class="assignment-actions">
                                            <a href="view_risk.php?id=<?php echo $risk['id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Department Risks Tab -->
            <div id="department-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Department Risks (<?php echo $user['department']; ?>)</h2>
                        <span class="badge badge-info"><?php echo count($department_risks); ?> risks in your department</span>
                    </div>
                    <?php if (empty($department_risks)): ?>
                        <div class="alert-info">
                            <p><strong>No risks in your department yet.</strong></p>
                            <p>Your department hasn't reported any risks yet.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Risk ID</th>
                                    <th>Risk Name</th>
                                    <th>Risk Level</th>
                                    <th>Status</th>
                                    <th>Risk Owner</th>
                                    <th>Reported By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($department_risks as $risk): ?>
                                <tr class="risk-row <?php echo getRiskLevel($risk); ?>" data-risk-id="<?php echo $risk['id']; ?>">
                                    <td>
                                        <?php if (!empty($risk['custom_risk_id'])): ?>
                                            <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($risk['risk_name']); ?></strong>
                                        <?php if ($risk['risk_description']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($risk['risk_description'], 0, 100)) . (strlen($risk['risk_description']) > 100 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="risk-badge risk-<?php echo getRiskLevel($risk); ?>">
                                            <?php echo getRiskLevelText($risk); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusInfo = getBeautifulStatus($risk['risk_status']);
                                        ?>
                                        <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                            <?php echo $statusInfo['text']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($risk['risk_owner_name']): ?>
                                            <span class="owner-badge"><?php echo htmlspecialchars($risk['risk_owner_name']); ?></span>
                                        <?php else: ?>
                                            <span class="unassigned-badge">Auto-Assigning...</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($risk['reporter_name']); ?></td>
                                    <td>
                                        <div class="assignment-actions">
                                            <a href="view_risk.php?id=<?php echo $risk['id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- My Reports Tab -->
            <div id="my-reports-tab" class="tab-content">
                <!-- Statistics Cards -->
                <div class="dashboard-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 2rem;">
                    <?php
                    // Calculate report statistics
                    $total_reports = count($my_reported_risks);
                    $open_reports = 0;
                    $in_progress_reports = 0;
                    $completed_reports = 0;
                    
                    foreach ($my_reported_risks as $risk) {
                        switch ($risk['risk_status']) {
                            case 'pending':
                                $open_reports++;
                                break;
                            case 'in_progress':
                                $in_progress_reports++;
                                break;
                            case 'completed':
                                $completed_reports++;
                                break;
                        }
                    }
                    ?>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $total_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Total Reports</div>
                        <div class="stat-description" style="color: #666;">All risks you have reported</div>
                    </div>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $open_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Open</div>
                        <div class="stat-description" style="color: #666;">Awaiting review</div>
                    </div>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $in_progress_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">In Progress</div>
                        <div class="stat-description" style="color: #666;">Being addressed</div>
                    </div>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $completed_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Completed</div>
                        <div class="stat-description" style="color: #666;">Successfully managed</div>
                    </div>
                </div>
                
                <!-- Action Buttons and Search -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <input type="text" id="searchRisks" placeholder="Search risks..." style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px; width: 250px;">
                    </div>
                </div>
                
                <!-- Reports Table -->
                <div class="card" style="padding: 0;">
                    <div style="padding: 1.5rem 2rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; color: #333; font-size: 1.2rem; font-weight: 600;">All My Risk Reports</h3>
                        <select id="statusFilter" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background: white;">
                            <option value="">All Statuses</option>
                            <option value="pending">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <?php if (empty($my_reported_risks)): ?>
                        <div style="padding: 3rem; text-align: center; color: #666;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                            <h3 style="margin-bottom: 0.5rem;">No Risk Reports Yet</h3>
                            <p style="margin-bottom: 1.5rem;">You haven't reported any risks yet. Start by reporting your first risk.</p>
                            <a href="report_risk.php" class="btn" style="background: #E60012; color: white; padding: 0.75rem 1.5rem; border-radius: 5px; text-decoration: none;">
                                Report Your First Risk
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table" style="margin: 0;">
                                <thead style="background: #f8f9fa;">
                                    <tr>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk ID</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk Name</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Category</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk Level</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Status</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk Owner</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Reported Date</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Last Updated</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="reportsTableBody">
                                    <?php foreach ($my_reported_risks as $risk): ?>
                                    <tr class="risk-report-row" data-status="<?php echo $risk['risk_status'] ?? 'pending'; ?>" data-search="<?php echo strtolower($risk['risk_name'] . ' ' . cleanCategoryName($risk['risk_categories'] ?? '')); ?>">
                                        <td style="padding: 1rem; border-left: 4px solid #E60012;">
                                            <?php if (!empty($risk['custom_risk_id'])): ?>
                                                <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($risk['risk_name']); ?>
                                            </div>
                                            <?php if ($risk['risk_description']): ?>
                                                <div style="font-size: 0.85rem; color: #666; line-height: 1.4;">
                                                    <?php echo htmlspecialchars(substr($risk['risk_description'], 0, 80)) . (strlen($risk['risk_description']) > 80 ? '...' : ''); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <span style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                                <?php echo htmlspecialchars(cleanCategoryName($risk['risk_categories'] ?? 'Uncategorized')); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php
                                            $level = getRiskLevel($risk);
                                            $levelText = getRiskLevelText($risk);
                                            $levelColors = [
                                                'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                            ];
                                            $colors = $levelColors[$level] ?? $levelColors['not-assessed'];
                                            ?>
                                            <span style="background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                <?php echo strtoupper($levelText); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php
                                            $statusInfo = getBeautifulStatus($risk['risk_status']);
                                            ?>
                                            <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                <?php echo $statusInfo['text']; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php if ($risk['risk_owner_name']): ?>
                                                <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500;">
                                                    <?php echo htmlspecialchars($risk['risk_owner_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #fff3e0; color: #ef6c00; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 500;">
                                                    Auto-Assigning...
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1rem; color: #666; font-size: 0.9rem;">
                                            <?php echo date('M j, Y g:i A', strtotime($risk['created_at'])); ?>
                                        </td>
                                        <td style="padding: 1rem; color: #666; font-size: 0.9rem;">
                                            <?php echo date('M j, Y g:i A', strtotime($risk['updated_at'])); ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <a href="view_risk.php?id=<?php echo $risk['id']; ?>" style="background: #E60012; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; text-decoration: none;">
                                                    View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Procedures Tab -->
            <div id="procedures-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">📋 Risk Management Procedures</h2>
                        <p style="margin: 0; color: #666;">Complete guide for risk owners in the Airtel Risk Management System</p>
                    </div>
                    
                    <!-- Table of Contents -->
                    <div class="toc">
                        <h3>📑 Table of Contents</h3>
                        <ul>
                            <li><a href="#role-overview">1. Risk Owner Role Overview</a></li>
                            <li><a href="#risk-assessment">2. Risk Assessment Process</a></li>
                            <li><a href="#risk-matrix">3. Risk Assessment Matrix</a></li>
                            <li><a href="#department-management">4. Department Risk Management</a></li>
                            <li><a href="#auto-assignment">5. Automatic Risk Assignment</a></li>
                            <li><a href="#best-practices">6. Best Practices</a></li>
                        </ul>
                    </div>
                    
                    <!-- Role Overview -->
                    <div class="procedure-section" id="role-overview">
                        <h3 class="procedure-title">1. Risk Owner Role Overview</h3>
                        <p>As a Risk Owner in the Airtel Risk Management System, you are responsible for managing risks within your department and ensuring proper risk assessment and treatment.</p>
                        
                        <h4>Key Responsibilities:</h4>
                        <ul>
                            <li><strong>Risk Assessment:</strong> Evaluate and rate risks assigned to you</li>
                            <li><strong>Risk Treatment:</strong> Develop and implement risk mitigation strategies</li>
                            <li><strong>Department Oversight:</strong> Monitor all risks within your department</li>
                            <li><strong>Reporting:</strong> Report new risks discovered in your area</li>
                            <li><strong>Status Updates:</strong> Keep risk statuses current and accurate</li>
                        </ul>
                    </div>
                    
                    <!-- Risk Assessment Process -->
                    <div class="procedure-section" id="risk-assessment">
                        <h3 class="procedure-title">2. Risk Assessment Process</h3>
                        <p>The risk assessment process involves evaluating both the likelihood and impact of identified risks.</p>
                        
                        <h4>Assessment Fields:</h4>
                        <div class="field-definition">
                            <div class="field-name">Inherent Risk (Before Controls)</div>
                            <div class="field-description">
                                <strong>Inherent Likelihood:</strong> Probability of the risk occurring without any controls (1-5 scale)<br>
                                <strong>Inherent Consequence:</strong> Impact if the risk occurs without controls (1-5 scale)
                            </div>
                        </div>
                        
                        <div class="field-definition">
                            <div class="field-name">Residual Risk (After Controls)</div>
                            <div class="field-description">
                                <strong>Residual Likelihood:</strong> Probability after implementing controls (1-5 scale)<br>
                                <strong>Residual Consequence:</strong> Impact after implementing controls (1-5 scale)
                            </div>
                        </div>
                        
                        <div class="field-definition">
                            <div class="field-name">Current Risk Assessment</div>
                            <div class="field-description">
                                <strong>Probability:</strong> Current likelihood of occurrence (1-5 scale)<br>
                                <strong>Impact:</strong> Current potential impact (1-5 scale)
                            </div>
                        </div>
                    </div>
                    
                    <!-- Risk Matrix -->
                    <div class="procedure-section" id="risk-matrix">
                        <h3 class="procedure-title">3. Risk Assessment Matrix</h3>
                        <p>Use this matrix to determine risk levels based on likelihood and impact ratings:</p>
                        
                        <table class="risk-matrix-table">
                            <thead>
                                <tr>
                                    <th>Impact →<br>Likelihood ↓</th>
                                    <th>1 (Very Low)</th>
                                    <th>2 (Low)</th>
                                    <th>3 (Medium)</th>
                                    <th>4 (High)</th>
                                    <th>5 (Very High)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th>5 (Very Likely)</th>
                                    <td class="matrix-2">5 - Medium</td>
                                    <td class="matrix-3">10 - High</td>
                                    <td class="matrix-4">15 - Critical</td>
                                    <td class="matrix-5">20 - Critical</td>
                                    <td class="matrix-5">25 - Critical</td>
                                </tr>
                                <tr>
                                    <th>4 (Likely)</th>
                                    <td class="matrix-2">4 - Medium</td>
                                    <td class="matrix-2">8 - Medium</td>
                                    <td class="matrix-3">12 - High</td>
                                    <td class="matrix-4">16 - Critical</td>
                                    <td class="matrix-5">20 - Critical</td>
                                </tr>
                                <tr>
                                    <th>3 (Possible)</th>
                                    <td class="matrix-1">3 - Low</td>
                                    <td class="matrix-2">6 - Medium</td>
                                    <td class="matrix-3">9 - High</td>
                                    <td class="matrix-3">12 - High</td>
                                    <td class="matrix-4">15 - Critical</td>
                                </tr>
                                <tr>
                                    <th>2 (Unlikely)</th>
                                    <td class="matrix-1">2 - Low</td>
                                    <td class="matrix-2">4 - Medium</td>
                                    <td class="matrix-2">6 - Medium</td>
                                    <td class="matrix-2">8 - Medium</td>
                                    <td class="matrix-3">10 - High</td>
                                </tr>
                                <tr>
                                    <th>1 (Very Unlikely)</th>
                                    <td class="matrix-1">1 - Low</td>
                                    <td class="matrix-1">2 - Low</td>
                                    <td class="matrix-1">3 - Low</td>
                                    <td class="matrix-2">4 - Medium</td>
                                    <td class="matrix-2">5 - Medium</td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <h4>Risk Level Definitions:</h4>
                        <ul>
                            <li><strong>Low (1-3):</strong> Acceptable risk, monitor regularly</li>
                            <li><strong>Medium (4-8):</strong> Manageable risk, implement controls</li>
                            <li><strong>High (9-14):</strong> Significant risk, immediate action required</li>
                            <li><strong>Critical (15-25):</strong> Unacceptable risk, urgent action required</li>
                        </ul>
                    </div>
                    
                    <!-- Department Management -->
                    <div class="procedure-section" id="department-management">
                        <h3 class="procedure-title">4. Department Risk Management</h3>
                        <p>As a risk owner, you have access to manage risks within your specific department:</p>
                        
                        <div class="department-grid">
                            <?php
                            $departments = [
                                'IT' => 'Information Technology risks including cybersecurity, system failures, and data breaches',
                                'Finance' => 'Financial risks including fraud, compliance, and budget overruns',
                                'Operations' => 'Operational risks including process failures and service disruptions',
                                'HR' => 'Human resources risks including compliance and employee-related issues',
                                'Legal' => 'Legal and regulatory compliance risks',
                                'Marketing' => 'Marketing and brand reputation risks'
                            ];
                            
                            foreach ($departments as $dept => $description) {
                                if ($dept == $user['department']) {
                                    echo "<div class='department-card' style='border-left-color: #E60012; background: #fff5f5;'>";
                                    echo "<div class='department-title' style='color: #E60012;'>🏢 {$dept} (Your Department)</div>";
                                    echo "<p style='margin: 0; color: #666;'>{$description}</p>";
                                    echo "</div>";
                                    break;
                                }
                            }
                            ?>
                        </div>
                        
                        <h4>Department Access Levels:</h4>
                        <ul>
                            <li><strong>View All Department Risks:</strong> See all risks reported within your department</li>
                            <li><strong>Take Ownership:</strong> Assign unowned risks to yourself</li>
                            <li><strong>Transfer Ownership:</strong> Reassign risks to other department members</li>
                            <li><strong>Update Status:</strong> Change risk status and add progress notes</li>
                        </ul>
                    </div>
                    
                    <!-- Auto Assignment -->
                    <div class="procedure-section" id="auto-assignment">
                        <h3 class="procedure-title">5. Automatic Risk Assignment</h3>
                        <p>The system automatically assigns risks to appropriate risk owners based on department and expertise:</p>
                        
                        <div class="auto-assignment-flow">
                            <h4>🔄 Auto-Assignment Process:</h4>
                            <div class="flow-step">
                                <i class="fas fa-upload"></i>
                                <span>Risk is reported by any user</span>
                            </div>
                            <div class="flow-step">
                                <i class="fas fa-search"></i>
                                <span>System identifies the risk category and department</span>
                            </div>
                            <div class="flow-step">
                                <i class="fas fa-users"></i>
                                <span>System finds available risk owners in that department</span>
                            </div>
                            <div class="flow-step">
                                <i class="fas fa-user-check"></i>
                                <span>Risk is automatically assigned to the most suitable risk owner</span>
                            </div>
                            <div class="flow-step">
                                <i class="fas fa-bell"></i>
                                <span>Risk owner receives notification of new assignment</span>
                            </div>
                        </div>
                        
                        <h4>Manual Override Options:</h4>
                        <ul>
                            <li><strong>Self-Assignment:</strong> Take ownership of unassigned risks in your department</li>
                            <li><strong>Transfer:</strong> Reassign risks to other qualified team members</li>
                            <li><strong>Escalation:</strong> Escalate complex risks to senior management</li>
                            <li><strong>Regulatory Issues:</strong> Involve legal and compliance teams</li>
                        </ul>
                    </div>
                    
                    <!-- Best Practices -->
                    <div class="procedure-section" id="best-practices">
                        <h3 class="procedure-title">6. Best Practices</h3>
                        
                        <h4>🎯 Risk Assessment Best Practices:</h4>
                        <ul>
                            <li><strong>Be Objective:</strong> Base assessments on facts and data, not assumptions</li>
                            <li><strong>Consider Context:</strong> Evaluate risks within the current business environment</li>
                            <li><strong>Document Rationale:</strong> Explain your reasoning for risk ratings</li>
                            <li><strong>Regular Reviews:</strong> Reassess risks periodically as conditions change</li>
                            <li><strong>Stakeholder Input:</strong> Consult with relevant team members and experts</li>
                        </ul>
                        
                        <h4>📊 Risk Management Best Practices:</h4>
                        <ul>
                            <li><strong>Prioritize by Impact:</strong> Focus on high and critical risks first</li>
                            <li><strong>Develop Action Plans:</strong> Create specific, measurable mitigation strategies</li>
                            <li><strong>Monitor Progress:</strong> Track implementation of risk treatments</li>
                            <li><strong>Communicate Effectively:</strong> Keep stakeholders informed of risk status</li>
                            <li><strong>Learn from Experience:</strong> Use past incidents to improve risk management</li>
                        </ul>
                        
                        <h4>🚨 Escalation Guidelines:</h4>
                        <ul>
                            <li><strong>Critical Risks:</strong> Immediately escalate to senior management</li>
                            <li><strong>Cross-Department Risks:</strong> Coordinate with other department risk owners</li>
                            <li><strong>Resource Constraints:</strong> Escalation when additional resources are needed</li>
                            <li><strong>Regulatory Issues:</strong> Involve legal and compliance teams</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script>
        // Tab switching functionality
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all nav links
            const navLinks = document.querySelectorAll('.nav-item a');
            navLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // If tabName is invalid, fallback to dashboard
            let selectedTab = document.getElementById(tabName + '-tab');
            if (!selectedTab) {
                tabName = 'dashboard';
                selectedTab = document.getElementById('dashboard-tab');
            }
            selectedTab.classList.add('active');
            
            // Find the nav link that corresponds to this tabName and set it active
            navLinks.forEach(link => {
                // Check if the link has an onclick that matches the tabName
                if (link.getAttribute('onclick') && link.getAttribute('onclick').includes(`showTab('${tabName}')`)) {
                    link.classList.add('active');
                }
                // For the Report Risk link, which is a direct href (only if tabName is 'report_risk')
                else if (tabName === 'report_risk' && link.getAttribute('href') === 'report_risk.php') {
                    link.classList.add('active');
                }
            });
            
            // Update URL without page reload
            if (tabName !== 'dashboard') {
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('tab', tabName);
                window.history.pushState({}, '', newUrl);
            } else {
                const newUrl = new URL(window.location);
                newUrl.searchParams.delete('tab');
                window.history.pushState({}, '', newUrl);
            }
        }
        
        // Risk management tabs functionality
        function showRiskTab(tabName) {
            // Hide all risk tab contents
            const riskTabContents = document.querySelectorAll('.risk-tab-content');
            riskTabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all risk tab buttons
            const riskTabBtns = document.querySelectorAll('.risk-tab-btn');
            riskTabBtns.forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected risk tab content
            const selectedRiskTab = document.getElementById(tabName + '-content');
            if (selectedRiskTab) {
                selectedRiskTab.classList.add('active');
            }
            
            // Add active class to clicked risk tab button
            const clickedBtn = document.getElementById(tabName + '-tab');
            if (clickedBtn) {
                clickedBtn.classList.add('active');
            }
        }
        
        // Search and filter functionality for My Reports
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            
            if (tab) {
                showTab(tab);
            } else {
                // Ensure dashboard is shown by default
                showTab('dashboard');
            }
            
            const searchInput = document.getElementById('searchRisks');
            const statusFilter = document.getElementById('statusFilter');
            const tableRows = document.querySelectorAll('.risk-report-row');
            
            function filterReports() {
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const selectedStatus = statusFilter ? statusFilter.value : '';
                
                if (tableRows) {
                    let visibleCount = 0;
                    tableRows.forEach(row => {
                        const searchData = row.getAttribute('data-search') || '';
                        const rowStatus = row.getAttribute('data-status') || '';
                        
                        const matchesSearch = searchData.includes(searchTerm);
                        const matchesStatus = !selectedStatus || rowStatus === selectedStatus;
                        
                        if (matchesSearch && matchesStatus) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });
                }
            }
            
            if (searchInput) {
                searchInput.addEventListener('input', filterReports);
            }
            
            if (statusFilter) {
                statusFilter.addEventListener('change', filterReports);
            }
        });
        
        // Notification dropdown toggle
        document.addEventListener('DOMContentLoaded', function() {
            const notificationContainer = document.querySelector('.nav-notification-container');
            const notificationDropdown = document.querySelector('.nav-notification-dropdown');
            if (notificationContainer && notificationDropdown) {
                notificationContainer.addEventListener('click', function(e) {
                    e.preventDefault();
                    notificationDropdown.classList.toggle('show');
                });
                // Close the dropdown if clicked outside
                document.addEventListener('click', function(e) {
                    if (!notificationContainer.contains(e.target) && !notificationDropdown.contains(e.target)) {
                        notificationDropdown.classList.remove('show');
                    }
                });
            }
        });
        
        // Chart initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Risk Level Distribution Chart
            const levelCtx = document.getElementById('levelChart');
            if (levelCtx) {
                new Chart(levelCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Low Risk', 'Medium Risk', 'High Risk', 'Critical Risk', 'Not Assessed'],
                        datasets: [{
                            data: [
                                <?php echo $risk_levels['low_risks']; ?>,
                                <?php echo $risk_levels['medium_risks']; ?>,
                                <?php echo $risk_levels['high_risks']; ?>,
                                <?php echo $risk_levels['critical_risks']; ?>,
                                <?php echo $risk_levels['not_assessed_risks']; ?>
                            ],
                            backgroundColor: ['#28a745', '#ffc107', '#fd7e14', '#dc3545', '#6c757d'],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            }
                        }
                    }
                });
            }
            
            // Risk Category Distribution Chart (UPDATED with unique colors)
            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                new Chart(categoryCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($cat) { 
                            $cleaned = cleanCategoryName($cat['risk_categories']);
                            return '"' . htmlspecialchars($cleaned) . '"'; 
                        }, $risk_by_category)); ?>],
                        datasets: [{
                            label: 'Number of Risks',
                            data: [<?php echo implode(',', array_column($risk_by_category, 'count')); ?>],
                            backgroundColor: [
                                <?php
                                for ($i = 0; $i < count($risk_by_category); $i++) {
                                    echo '"' . $category_colors[$i % count($category_colors)] . '"';
                                    if ($i < count($risk_by_category) - 1) echo ',';
                                }
                                ?>
                            ],
                            borderWidth: 1,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }
        });
        
        // Functions for quick actions
        function openTeamChat() {
            window.location.href = "teamchat.php";
        }
        
        function openChatBot() {
            alert('AI Assistant feature coming soon! This will provide intelligent help with risk assessment and management procedures.');
        }
    </script>
</body>
</html>