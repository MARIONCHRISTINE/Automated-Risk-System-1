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

// Get risk ID from URL
$risk_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$risk_id) {
    header('Location: risk_owner_dashboard.php');
    exit();
}

// Get current user info
$user = getCurrentUser();

// Debug: Ensure department is available
if (empty($user['department'])) {
    if (isset($_SESSION['department'])) {
        $user['department'] = $_SESSION['department'];
    } else {
        $dept_query = "SELECT department FROM users WHERE id = :user_id";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept_result) {
            $user['department'] = $dept_result['department'];
        }
    }
}

// Get comprehensive risk details
$query = "SELECT ri.*, 
                 reporter.full_name as reporter_name, 
                 reporter.email as reporter_email,
                 owner.full_name as owner_name, 
                 owner.email as owner_email,
                 owner.department as owner_department
          FROM risk_incidents ri 
          LEFT JOIN users reporter ON ri.reported_by = reporter.id
          LEFT JOIN users owner ON ri.risk_owner_id = owner.id
          WHERE ri.id = :risk_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':risk_id', $risk_id);
$stmt->execute();
$risk = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$risk) {
    $_SESSION['error_message'] = "Risk not found.";
    header('Location: risk_owner_dashboard.php');
    exit();
}

$selected_categories = [];
if (!empty($risk['risk_categories'])) {
    $categories_data = json_decode($risk['risk_categories'], true);
    if (is_array($categories_data)) {
        // Flatten nested arrays and remove null values
        $flattened_categories = [];
        foreach ($categories_data as $category) {
            if (is_array($category)) {
                // Handle nested arrays like [["Compliance"]] or [["Financial Exposure","Other"]]
                foreach ($category as $sub_category) {
                    if (!is_null($sub_category) && !empty($sub_category)) {
                        $flattened_categories[] = $sub_category;
                    }
                }
            } else {
                // Handle simple arrays like ["fraud"] or ["Financial Exposure"]
                if (!is_null($category) && !empty($category)) {
                    $flattened_categories[] = $category;
                }
            }
        }
        $selected_categories = array_unique($flattened_categories);
    } else {
        // Fallback for single category stored as string
        $selected_categories = [$risk['risk_categories']];
    }
}

// Check access permissions
$has_access = false;
$access_reason = '';

// Risk owners can access risks assigned to them or in their department
if ($risk['risk_owner_id'] == $_SESSION['user_id']) {
    $has_access = true;
    $access_reason = 'risk_owner';
} elseif ($risk['department'] == $user['department']) {
    $has_access = true;
    $access_reason = 'department_access';
} elseif ($risk['reported_by'] == $_SESSION['user_id']) {
    $has_access = true;
    $access_reason = 'reporter';
}

if (!$has_access) {
    $_SESSION['error_message'] = "Access denied. You can only view risks assigned to you or in your department.";
    header('Location: risk_owner_dashboard.php');
    exit();
}

// Check if current user can manage this risk (ONLY if assigned to them)
$can_manage_risk = ($risk['risk_owner_id'] == $_SESSION['user_id']);

// Get risk attachments if they exist
$attachments_query = "SELECT * FROM risk_attachments WHERE risk_id = :risk_id ORDER BY uploaded_at DESC";
$attachments_stmt = $db->prepare($attachments_query);
$attachments_stmt->bindParam(':risk_id', $risk_id);
$attachments_stmt->execute();
$attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get risk documents if they exist (keeping for other sections)
$documents_query = "SELECT * FROM risk_documents WHERE risk_id = :risk_id ORDER BY section_type, uploaded_at DESC";
$documents_stmt = $db->prepare($documents_query);
$documents_stmt->bindParam(':risk_id', $risk_id);
$documents_stmt->execute();
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group documents by section
$grouped_documents = [];
foreach ($documents as $doc) {
    $grouped_documents[$doc['section_type']][] = $doc;
}

// Get risk history/audit trail - FIXED QUERY with proper table aliases
$history_query = "SELECT 
    'risk_created' as action_type,
    ri.created_at as action_date,
    reporter.full_name as action_by,
    'Risk initially reported' as action_description
FROM risk_incidents ri
LEFT JOIN users reporter ON ri.reported_by = reporter.id
WHERE ri.id = :risk_id

UNION ALL

SELECT 
    'risk_updated' as action_type,
    ri.updated_at as action_date,
    owner.full_name as action_by,
    'Risk details updated' as action_description
FROM risk_incidents ri
LEFT JOIN users owner ON ri.risk_owner_id = owner.id
WHERE ri.id = :risk_id AND ri.updated_at != ri.created_at

ORDER BY action_date DESC";

$history_stmt = $db->prepare($history_query);
$history_stmt->bindParam(':risk_id', $risk_id);
$history_stmt->execute();
$risk_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get risk comments (if table exists)
$comments_query = "SELECT rc.*, u.full_name, u.email 
                   FROM risk_comments rc 
                   LEFT JOIN users u ON rc.user_id = u.id 
                   WHERE rc.risk_id = :risk_id 
                   ORDER BY rc.created_at DESC";
try {
    $comments_stmt = $db->prepare($comments_query);
    $comments_stmt->bindParam(':risk_id', $risk_id);
    $comments_stmt->execute();
    $risk_comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $risk_comments = []; // Table might not exist yet
}

// Get notifications using shared component
$all_notifications = getNotifications($db, $_SESSION['user_id']);

// Helper functions for display
function getRiskRatingDisplay($likelihood, $consequence) {
    if (!$likelihood || !$consequence) return '-';
    
    $rating = (int)$likelihood * (int)$consequence;
    
    if ($rating >= 15) return "Critical ($rating)";
    if ($rating >= 9) return "High ($rating)";
    if ($rating >= 4) return "Medium ($rating)";
    return "Low ($rating)";
}

function getRiskRatingClass($likelihood, $consequence) {
    if (!$likelihood || !$consequence) return 'rating-low';
    
    $rating = (int)$likelihood * (int)$consequence;
    
    if ($rating >= 15) return 'rating-critical';
    if ($rating >= 9) return 'rating-high';
    if ($rating >= 4) return 'rating-medium';
    return 'rating-low';
}

function displayValue($value, $default = '-') {
    return !empty($value) ? htmlspecialchars($value) : $default;
}

function displayDate($date, $format = 'M j, Y') {
    return !empty($date) ? date($format, strtotime($date)) : '-';
}

function getImpactDescription($category, $level) {
    $impacts = [
        'financial' => [
            4 => 'Extreme: >5% of annual revenue',
            3 => 'Significant: 1-5% of annual revenue', 
            2 => 'Moderate: 0.25-1% of annual revenue',
            1 => 'Minor: <0.25% of annual revenue'
        ],
        'market_share' => [
            4 => 'Extreme: >10% market share loss',
            3 => 'Significant: 5-10% market share loss',
            2 => 'Moderate: 2-5% market share loss', 
            1 => 'Minor: <2% market share loss'
        ],
        'customer' => [
            4 => 'Extreme: >50% customer loss',
            3 => 'Significant: 20-50% customer loss',
            2 => 'Moderate: 5-20% customer loss',
            1 => 'Minor: <5% customer loss'
        ],
        'compliance' => [
            4 => 'Extreme: >$1M penalties',
            3 => 'Significant: $0.5M-$1M penalties',
            2 => 'Moderate: $0.1M-$0.5M penalties',
            1 => 'Minor: <$0.1M penalties'
        ],
        'reputation' => [
            4 => 'Extreme: National/international negative coverage',
            3 => 'Significant: Regional negative coverage',
            2 => 'Moderate: Local negative coverage',
            1 => 'Minor: Limited negative coverage'
        ],
        'operations' => [
            4 => 'Extreme: Complete shutdown >3 days',
            3 => 'Significant: Major disruption 1-3 days',
            2 => 'Moderate: Minor disruption <1 day',
            1 => 'Minor: Limited disruption'
        ],
        'fraud' => [
            4 => 'Extreme: >$1M fraud loss',
            3 => 'Significant: $0.5M-$1M fraud loss',
            2 => 'Moderate: $0.1M-$0.5M fraud loss',
            1 => 'Minor: <$0.1M fraud loss'
        ],
        'technology' => [
            4 => 'Extreme: Complete system failure >24hrs',
            3 => 'Significant: Major system issues 8-24hrs',
            2 => 'Moderate: Minor system issues 2-8hrs',
            1 => 'Minor: Brief system issues <2hrs'
        ],
        'people' => [
            4 => 'Extreme: >50% key staff turnover',
            3 => 'Significant: 25-50% key staff turnover',
            2 => 'Moderate: 10-25% key staff turnover',
            1 => 'Minor: <10% key staff turnover'
        ],
        'other' => [
            4 => 'Extreme: Severe impact',
            3 => 'Significant: Major impact',
            2 => 'Moderate: Moderate impact',
            1 => 'Minor: Minor impact'
        ]
    ];
    
    return $impacts[$category][$level] ?? '-';
}

// Calculate risk age
$risk_age = floor((time() - strtotime($risk['created_at'])) / (60 * 60 * 24));

// Calculate days until target completion
$days_until_completion = '';
if (!empty($risk['target_completion_date'])) {
    $target_timestamp = strtotime($risk['target_completion_date']);
    $days_diff = floor(($target_timestamp - time()) / (60 * 60 * 24));
    
    if ($days_diff > 0) {
        $days_until_completion = "$days_diff days remaining";
    } elseif ($days_diff == 0) {
        $days_until_completion = "Due today";
    } else {
        $days_until_completion = abs($days_diff) . " days overdue";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Risk - <?php echo htmlspecialchars($risk['risk_name']); ?> - Airtel Risk Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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
        
        /* Header */
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

        /* Navigation Bar */
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
        
        /* Notification styles */
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

        /* Notification Dropdown Styles */
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
        .flex {
            display: flex;
        }
        .justify-between {
            justify-content: space-between;
        }
        .items-center {
            align-items: center;
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
        .btn-outline {
            background: transparent;
            color: #E60012;
            border: 1px solid #E60012;
        }
        .btn-outline:hover {
            background: #E60012;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-top: 4px solid #E60012;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 2rem 2rem 1rem 2rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            color: #333;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }

        .card-body {
            padding: 2rem;
        }

        .section-header {
            background: #E60012;
            color: white;
            padding: 0.75rem 1rem;
            margin: 2rem 0 1rem 0;
            border-radius: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
        }

        .progress-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 0.25rem;
        }

        .progress-step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .progress-step.active {
            background: #E60012;
            color: white;
        }

        .progress-step.completed {
            background: #28a745;
            color: white;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            transition: border-color 0.3s;
            font-size: 1rem;
            background: #f8f9fa;
            color: #495057;
            cursor: not-allowed;
        }

        .form-control:focus {
            outline: none;
            border-color: #dee2e6;
        }

        textarea.form-control {
            height: 100px;
            resize: none;
        }

        .risk-matrix {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }

        .matrix-section {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            background: #f8f9fa;
        }

        .matrix-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: #E60012;
            text-align: center;
        }

        .rating-display {
            text-align: center;
            padding: 0.5rem;
            margin-top: 0.5rem;
            border-radius: 0.25rem;
            font-weight: bold;
        }

        .rating-low { background: #d4edda; color: #155724; }
        .rating-medium { background: #fff3cd; color: #856404; }
        .rating-high { background: #f8d7da; color: #721c24; }
        .rating-critical { background: #721c24; color: white; }

        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
            color: white;
            text-decoration: none;
        }

        .btn-manage {
            background: #28a745;
            font-size: 1.2rem;
            padding: 1rem 2rem;
            margin: 0 0.5rem;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-manage:hover {
            background: #218838;
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            transform: translateY(-2px);
        }

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .file-list {
            margin-top: 0.5rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.4rem;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .file-name {
            font-weight: 500;
        }

        .file-size {
            color: #666;
            font-size: 0.8rem;
        }

        .action-buttons {
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #dee2e6;
        }

        /* Risk Stats Cards */
        .risk-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #E60012;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #E60012;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        /* Timeline/History */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.75rem;
            top: 1rem;
            width: 12px;
            height: 12px;
            background: #E60012;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #E60012;
        }

        .timeline-date {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .timeline-content {
            font-weight: 500;
        }

        .timeline-user {
            font-size: 0.9rem;
            color: #E60012;
            margin-top: 0.5rem;
        }

        /* Comments Section */
        .comments-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .comment-item {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border-left: 3px solid #E60012;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .comment-author {
            font-weight: 600;
            color: #E60012;
        }

        .comment-date {
            font-size: 0.8rem;
            color: #666;
        }

        .comment-content {
            color: #333;
            line-height: 1.5;
        }

        /* Risk Metrics */
        .risk-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .metric-card {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-top: 3px solid #E60012;
        }

        .metric-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #E60012;
        }

        .metric-label {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }

        /* Overdue indicator */
        .overdue {
            color: #dc3545;
            font-weight: bold;
        }

        .due-today {
            color: #ffc107;
            font-weight: bold;
        }

        .on-track {
            color: #28a745;
            font-weight: bold;
        }

        /* Responsive */
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .risk-matrix {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
                gap: 1rem;
            }

            .btn-manage {
                font-size: 1.1rem;
                padding: 0.8rem 1.5rem;
            }

            .risk-stats {
                grid-template-columns: 1fr;
            }

            .risk-metrics {
                grid-template-columns: repeat(2, 1fr);
            }

            .timeline {
                padding-left: 1.5rem;
            }

            .timeline-item::before {
                left: -1.25rem;
            }
        }

        /* Added styles for selected checkboxes and radio buttons */
        .category-item {
            margin-bottom: 0.5rem;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: not-allowed;
            padding: 0.5rem;
            border-radius: 0.25rem;
            transition: background-color 0.3s;
        }
        
        .checkbox-label input[type="checkbox"],
        .checkbox-label input[type="radio"] {
            margin-right: 0.5rem;
            cursor: not-allowed;
        }
        
        .checkmark.selected {
            background-color: #E60012;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-weight: 500;
        }
        
        .risk-categories-container {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            background: #f8f9fa;
        }

        /* Styles for displaying selected categories and answers */
        .selected-categories-display {
            padding: 0.75rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }

        .selected-category-item, .selected-answer-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            color: #495057;
            font-weight: 500;
        }

        .selected-category-item i, .selected-answer-item i {
            margin-right: 0.5rem;
            color: #28a745;
        }

        .no-data {
            color: #6c757d;
            font-style: italic;
        }

        .money-amount-display {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #fff3cd;
            border-radius: 0.25rem;
            border-left: 3px solid #ffc107;
        }

        .money-amount-display .form-label {
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .money-amount-display .amount-value {
            font-weight: bold;
            color: #856404;
        }

        .readonly-content {
            padding: 0.75rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            color: #495057;
            white-space: pre-wrap;
        }

        /* Styles for Risk Assessment Section */
        .risk-assessment-items {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .assessment-item {
            padding: 1rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }
    </style>
    <style>
        .risk-rating-table {
            width: 100%;
            margin-top: 1rem;
            border-collapse: collapse;
        }

        .risk-rating-table th,
        .risk-rating-table td {
            padding: 0.75rem;
            text-align: left;
            border: 1px solid #dee2e6;
        }

        .risk-rating-table th {
            font-weight: bold;
            background-color: #f8f9fa;
        }

        .risk-rating-table tbody tr:nth-child(odd) {
            background-color: #f8f9fa;
        }
    </style>
    <style>
/* Added styles for risk rating table */
.risk-rating-table {
    margin-top: 15px;
}

.risk-rating-table table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.risk-rating-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    padding: 12px;
    text-align: left;
    border: 1px solid #dee2e6;
}

.risk-rating-table td {
    padding: 10px 12px;
    border: 1px solid #dee2e6;
    vertical-align: top;
}

.risk-rating-table tr:nth-child(even) {
    background-color: #f8f9fa;
}

.risk-rating-table tr:hover {
    background-color: #e9ecef;
}
</style>
<style>
/* Styles for Risk Treatment Section */
.treatments-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-top: 1rem;
}

.treatment-card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    padding: 1.5rem;
    border-top: 3px solid #E60012;
}

.treatment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.treatment-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.treatment-status {
    padding: 0.4rem 0.8rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
    color: white;
}

.status-open {
    background: #28a745;
}

.status-in_progress {
    background: #ffc107;
    color: #212529;
}

.status-completed {
    background: #17a2b8;
}

.status-on_hold {
    background: #6c757d;
}

.treatment-meta {
    margin-bottom: 1rem;
}

.meta-item {
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
}

.meta-label {
    font-weight: 500;
    color: #666;
    margin-right: 0.5rem;
    width: 100px;
    flex-shrink: 0;
}

.meta-value {
    color: #333;
}

.treatment-description {
    margin-bottom: 1rem;
}

.treatment-description p {
    margin: 0;
    color: #444;
    line-height: 1.5;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: #666;
}

.empty-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h4 {
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.empty-state p {
    margin: 0;
}
</style>
</head>
<body>
    <!-- Header -->
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
    
    <!-- Navigation Bar -->
    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk_owner_dashboard.php' ? 'active' : ''; ?>">
                        🏠 Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="report_risk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'report_risk.php' ? 'active' : ''; ?>">
                        📝 Report Risk
                    </a>
                </li>
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php?tab=my-reports" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'my-reports' ? 'active' : ''; ?>">
                        👀 My Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a href="risk-procedures.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk-procedures.php' ? 'active' : ''; ?>" target="_blank">
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
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Risk Stats Overview -->
        <div class="risk-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo $risk_age; ?></div>
                <div class="stat-label">Days Since Reported</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo displayValue($risk['risk_status'], 'Open'); ?></div>
                <div class="stat-label">Current Status</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo strpos($days_until_completion, 'overdue') !== false ? 'overdue' : (strpos($days_until_completion, 'today') !== false ? 'due-today' : 'on-track'); ?>">
                    <?php echo $days_until_completion ?: '-'; ?>
                </div>
                <div class="stat-label">Target Completion</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($documents); ?></div>
                <div class="stat-label">Attached Documents</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Risk Details - ID: <?php echo $risk['id']; ?></h2>
                <div>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <div class="card-body">
                
                <!-- Progress Indicator -->
                <div class="progress-indicator">
                    <div class="progress-step completed">1. Identify</div>
                    <div class="progress-step <?php echo ($risk['risk_category'] && $risk['inherent_likelihood']) ? 'completed' : 'active'; ?>">2. Assess</div>
                    <div class="progress-step <?php echo ($risk['treatment_action']) ? 'completed' : ''; ?>">3. Treat</div>
                    <div class="progress-step <?php echo ($risk['progress_update']) ? 'completed' : ''; ?>">4. Monitor</div>
                </div>
                
                <?php if ($access_reason === 'department_access'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Department Access:</strong> You are viewing this risk because it belongs to your department (<?php echo htmlspecialchars($user['department']); ?>).
                    </div>
                <?php elseif ($access_reason === 'reporter'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Reporter Access:</strong> You are viewing this risk because you reported it. You have read-only access to track its progress.
                    </div>
                <?php endif; ?>

                
                
                <!-- Section 1: Risk Identification -->
                <div class="section-header">
                    <i class="fas fa-search"></i> Section 1: Risk Identification
                </div>
                
                <!-- a. Risk Categories - Display from risk_categories column -->
                <div class="form-group">
                    <label class="form-label">a. Risk Categories * <small>(Selected categories)</small></label>
                    <div class="selected-categories-display">
                        <?php if (!empty($selected_categories)): ?>
                            <?php foreach ($selected_categories as $category): ?>
                                <div class="selected-category-item">
                                    <i class="fas fa-check-circle"></i>
                                    <?php 
                                    if (is_string($category)) {
                                        // Display full category names
                                        $category_names = [
                                            'Financial Exposure' => 'Financial Exposure [Revenue, Operating Expenditure, Book value]',
                                            'Decrease in market share' => 'Decrease in market share',
                                            'Customer Experience' => 'Customer Experience',
                                            'Compliance' => 'Compliance',
                                            'Reputation' => 'Reputation',
                                            'Fraud' => 'Fraud',
                                            'Operations' => 'Operations (Business continuity)',
                                            'Networks' => 'Networks',
                                            'People' => 'People',
                                            'IT' => 'IT (Cybersecurity & Data Privacy)',
                                            'Other' => 'Other',
                                            'fraud' => 'Fraud',
                                            'compliance' => 'Compliance',
                                            'reputation' => 'Reputation',
                                            'operations' => 'Operations (Business continuity)'
                                        ];
                                        echo htmlspecialchars($category_names[$category] ?? $category);
                                    }
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-data">No categories selected</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- b. Money loss question - Updated to check money_amount instead of involves_money_loss -->
                <div class="form-group">
                    <label class="form-label">b. Does your risk involves loss of money? *</label>
                    <div class="selected-answer-display">
                        <?php 
                        $money_amount = floatval($risk['money_amount'] ?? 0);
                        if ($money_amount > 0): ?>
                            <div class="selected-answer-item">
                                <i class="fas fa-check-circle"></i>
                                Yes
                            </div>
                            <div class="money-amount-display">
                                <label class="form-label">Amount lost (in your local currency):</label>
                                <div class="amount-value"><?php echo number_format($money_amount, 2); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="selected-answer-item">
                                <i class="fas fa-times-circle"></i>
                                No
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- c. Risk Description - Display from risk_description column -->
                <div class="form-group">
                    <label class="form-label">c. Risk Description *</label>
                    <div class="readonly-content"><?php echo nl2br(htmlspecialchars(displayValue($risk['risk_description']))); ?></div>
                </div>
                
                <!-- d. Cause of Risk - Display from cause_of_risk column -->
                <div class="form-group">
                    <label class="form-label">d. Cause of Risk *</label>
                    <div class="readonly-content"><?php echo nl2br(htmlspecialchars(displayValue($risk['cause_of_risk']))); ?></div>
                </div>
                
                <!-- e. Supporting documents - Using exact code provided by user -->
                <div class="form-group">
                    <label>Supporting Documents</label>
                    <div class="readonly-display">
                        <?php
                        $doc_query = "SELECT original_filename, file_path, section_type, uploaded_at FROM risk_documents WHERE risk_id = ? ORDER BY uploaded_at DESC";
                        $doc_stmt = $db->prepare($doc_query);
                        $doc_stmt->execute([$risk_id]);
                        $documents = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($documents)):
                        ?>
                            <div style="background: white; border-radius: 8px; padding: 1rem; border: 1px solid #dee2e6;">
                                <?php foreach ($documents as $doc): ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; margin-bottom: 0.5rem; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #E60012;">
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div style="width: 35px; height: 35px; background: #E60012; color: white; border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-file-alt"></i>
                                            </div>
                                            <div>
                                                <strong style="color: #333;"><?php echo htmlspecialchars($doc['original_filename']); ?></strong>
                                                <br><small style="color: #666;">
                                                    Uploaded: <?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" 
                                           style="background: #E60012; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s;">
                                            <i class="fas fa-external-link-alt"></i> View
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: #666; font-style: italic;">
                                <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                <br>No supporting documents uploaded
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Risk Ownership Info -->
                <div class="risk-metrics">
                    <div class="metric-card">
                        <div class="metric-value">
                            <?php echo htmlspecialchars($risk['reporter_name'] ?: 'Unknown'); ?>
                        </div>
                        <div class="metric-label">Reported By</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">
                            <?php echo htmlspecialchars($risk['owner_name'] ?: 'Unassigned'); ?>
                        </div>
                        <div class="metric-label">Risk Owner</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">
                            <?php echo displayDate($risk['created_at']); ?>
                        </div>
                        <div class="metric-label">Date Reported</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value">
                            <?php echo displayDate($risk['updated_at']); ?>
                        </div>
                        <div class="metric-label">Last Updated</div>
                    </div>
                </div>
                
                <!-- Section 2: Risk Assessment -->
                <div class="section-header">
                    <i class="fas fa-calculator"></i> Section 2: Risk Assessment
                </div>
                
                <!-- a. Existing or New Risk question -->
                <div class="form-group">
                    <label class="form-label">a. Existing or New Risk *</label>
                    <div class="readonly-content">
                        <?php 
                        // Check if this is existing or new risk based on available data
                        $risk_type = !empty($risk['id']) && !empty($risk['created_at']) ? 'Existing Risk' : 'New Risk';
                        echo $risk_type;
                        ?>
                    </div>
                </div>

                <!-- Updated Risk Assessment to Risk Rating with table format and risk names -->
                <div class="form-group">
                    <label class="form-label">b. Risk Rating</label>
                    
                    <div class="risk-rating-table">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Risk Categories</th>
                                    <th>Inherent Risk Rating</th>
                                    <th>Residual Risk Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Parse risk categories, ratings, and residual ratings
                                $risk_categories = !empty($risk['risk_category']) ? json_decode($risk['risk_category'], true) : [];
                                $risk_ratings = !empty($risk['risk_rating']) ? explode(',', $risk['risk_rating']) : [];
                                $residual_ratings = !empty($risk['residual_rating']) ? explode(',', $risk['residual_rating']) : [];
                                $inherent_levels = !empty($risk['inherent_risk_level']) ? explode(',', $risk['inherent_risk_level']) : [];
                                $residual_levels = !empty($risk['residual_risk_level']) ? explode(',', $risk['residual_risk_level']) : [];
                                
                                // Get risk names from risk_name field (assuming comma-separated for multiple risks)
                                $risk_names = !empty($risk['risk_name']) ? explode(',', $risk['risk_name']) : [];
                                
                                // Ensure we have at least one row to display
                                $max_count = max(count($risk_categories), count($risk_ratings), count($residual_ratings), count($risk_names), 1);
                                
                                for ($i = 0; $i < $max_count; $i++) {
                                    $roman_numeral = '';
                                    switch($i) {
                                        case 0: $roman_numeral = 'i.'; break;
                                        case 1: $roman_numeral = 'ii.'; break;
                                        case 2: $roman_numeral = 'iii.'; break;
                                        case 3: $roman_numeral = 'iv.'; break;
                                        case 4: $roman_numeral = 'v.'; break;
                                        default: $roman_numeral = ($i + 1) . '.'; break;
                                    }
                                    
                                    // Get risk name for this index
                                    $risk_name = isset($risk_names[$i]) ? trim($risk_names[$i]) : (isset($risk_categories[$i]) ? $risk_categories[$i] : 'Risk ' . ($i + 1));
                                    
                                    $inherent_rating = isset($risk_ratings[$i]) ? trim($risk_ratings[$i]) : '-';
                                    $residual_rating = isset($residual_ratings[$i]) ? trim($residual_ratings[$i]) : '-';
                                    $inherent_level = isset($inherent_levels[$i]) ? trim($inherent_levels[$i]) : '';
                                    $residual_level = isset($residual_levels[$i]) ? trim($residual_levels[$i]) : '';
                                    
                                    // Add risk level text to ratings if available
                                    if (!empty($inherent_level) && $inherent_rating !== '-') {
                                        $inherent_rating = $inherent_level . ' (' . $inherent_rating . ')';
                                    }
                                    if (!empty($residual_level) && $residual_rating !== '-') {
                                        $residual_rating = $residual_level . ' (' . $residual_rating . ')';
                                    }
                                    
                                    echo '<tr>';
                                    echo '<td>' . $roman_numeral . ' ' . htmlspecialchars($risk_name) . '</td>';
                                    echo '<td>' . htmlspecialchars($inherent_rating) . '</td>';
                                    echo '<td>' . htmlspecialchars($residual_rating) . '</td>';
                                    echo '</tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Changed from Impact Description to General Risk Score -->
                <div class="form-group">
                    <label class="form-label">c. GENERAL RISK SCORE</label>
                    <div class="general-risk-scores">
                        <div class="score-item">
                            <label class="score-label">General Inherent Risk Score:</label>
                            <div class="score-value">
                                <?php 
                                $general_inherent_score = $risk['general_inherent_risk_score'] ?? '-';
                                $general_inherent_level = $risk['general_inherent_risk_level'] ?? '';
                                
                                if ($general_inherent_score !== '-' && !empty($general_inherent_level)) {
                                    echo htmlspecialchars($general_inherent_level . ' (' . $general_inherent_score . ')');
                                } else {
                                    echo htmlspecialchars($general_inherent_score);
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="score-item">
                            <label class="score-label">General Residual Risk Score:</label>
                            <div class="score-value">
                                <?php 
                                $general_residual_score = $risk['general_residual_risk_score'] ?? '-';
                                $general_residual_level = $risk['general_residual_risk_level'] ?? '';
                                
                                if ($general_residual_score !== '-' && !empty($general_residual_level)) {
                                    echo htmlspecialchars($general_residual_level . ' (' . $general_residual_score . ')');
                                } else {
                                    echo htmlspecialchars($general_residual_score);
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Changed Section 3 from Risk Treatment to General Risk Status -->
                <div class="section-header">
                    <i class="fas fa-info-circle"></i> SECTION 3: GENERAL RISK STATUS
                </div>
                
                <div class="form-group">
                    <label class="form-label">Risk Status</label>
                    <input type="text" class="form-control" value="<?php echo displayValue($risk['risk_status'], 'Open'); ?>" readonly>
                </div>
                
                <!-- Section 4: Risk Treatment Methods -->
                <div class="section-header">
                    <i class="fas fa-shield-alt"></i> Section 4: Risk Treatment Methods
                </div>
                
                <?php
                $treatments_query = "SELECT rt.*, u.full_name as assigned_user_name 
                                   FROM risk_treatments rt 
                                   LEFT JOIN users u ON rt.assigned_to = u.id 
                                   WHERE rt.risk_id = :risk_id 
                                   ORDER BY rt.created_at DESC";
                $treatments_stmt = $db->prepare($treatments_query);
                $treatments_stmt->bindParam(':risk_id', $risk_id);
                $treatments_stmt->execute();
                $treatments = $treatments_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (empty($treatments)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h4>No Treatment Methods Added</h4>
                        <p>No risk treatment methods have been defined for this risk yet.</p>
                    </div>
                <?php else: ?>
                    <div class="treatments-grid">
                        <?php foreach ($treatments as $treatment): ?>
                            <div class="treatment-card">
                                <div class="treatment-header">
                                    <h4 class="treatment-title"><?php echo htmlspecialchars($treatment['treatment_title']); ?></h4>
                                    <span class="treatment-status status-<?php echo $treatment['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $treatment['status'])); ?>
                                    </span>
                                </div>
                                
                                <div class="treatment-meta">
                                    <div class="meta-item">
                                        <span class="meta-label">Assigned To:</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($treatment['assigned_user_name'] ?: 'Unassigned'); ?></span>
                                    </div>
                                    
                                    <?php if (!empty($treatment['target_completion_date'])): ?>
                                        <div class="meta-item">
                                            <span class="meta-label">Target Date:</span>
                                            <span class="meta-value"><?php echo date('M j, Y', strtotime($treatment['target_completion_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="meta-item">
                                        <span class="meta-label">Created:</span>
                                        <span class="meta-value"><?php echo date('M j, Y', strtotime($treatment['created_at'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="treatment-description">
                                    <span class="meta-label">Description:</span>
                                    <p><?php echo nl2br(htmlspecialchars($treatment['treatment_description'])); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
               

        <!-- Risk History & Timeline -->
        <?php if (!empty($risk_history)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history"></i> Risk History & Timeline</h3>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <?php foreach ($risk_history as $history): ?>
                        <div class="timeline-item">
                            <div class="timeline-date">
                                <?php echo date('M j, Y g:i A', strtotime($history['action_date'])); ?>
                            </div>
                            <div class="timeline-content">
                                <?php echo htmlspecialchars($history['action_description']); ?>
                            </div>
                            <div class="timeline-user">
                                By: <?php echo htmlspecialchars($history['action_by'] ?: 'System'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Comments Section -->
        <?php if (!empty($risk_comments)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-comments"></i> Risk Comments & Notes</h3>
            </div>
            <div class="card-body">
                <div class="comments-section">
                    <?php foreach ($risk_comments as $comment): ?>
                        <div class="comment-item">
                            <div class="comment-header">
                                <span class="comment-author"><?php echo htmlspecialchars($comment['full_name']); ?></span>
                                <span class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                            </div>
                            <div class="comment-content">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body">
                <div class="action-buttons">
                    <?php if ($can_manage_risk): ?>
                        <a href="manage-risk.php?id=<?php echo $risk_id; ?>" class="btn btn-manage">
                            <i class="fas fa-cogs"></i> Manage This Risk
                        </a>
                    <?php endif; ?>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print Risk Details
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
