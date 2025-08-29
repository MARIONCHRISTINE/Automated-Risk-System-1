<?php
include_once 'includes/auth.php';
requireRole('compliance_team');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$dept_query = "SELECT DISTINCT department FROM risk_incidents WHERE department IS NOT NULL ORDER BY department";
$dept_stmt = $db->prepare($dept_query);
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get all risks for compliance overview with enhanced data
$query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name,
          DATEDIFF(NOW(), r.created_at) as days_open,
          CASE 
            WHEN DATEDIFF(NOW(), r.created_at) > 90 THEN 'aged'
            WHEN DATEDIFF(NOW(), r.created_at) > 60 THEN 'maturing'
            ELSE 'new'
          END as aging_status
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          LEFT JOIN users ro ON r.risk_owner_id = ro.id 
          ORDER BY r.residual_rating DESC, r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$all_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$recent_risks_query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name
                       FROM risk_incidents r 
                       LEFT JOIN users u ON r.reported_by = u.id 
                       LEFT JOIN users ro ON r.risk_owner_id = ro.id 
                       ORDER BY r.created_at DESC LIMIT 10";
$recent_stmt = $db->prepare($recent_risks_query);
$recent_stmt->execute();
$recent_risks = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get enhanced risk statistics
$stats_query = "SELECT 
    COUNT(*) as total_risks,
    SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) as critical_risks,
    SUM(CASE WHEN risk_level = 'High' THEN 1 ELSE 0 END) as high_risks,
    SUM(CASE WHEN risk_level = 'Medium' THEN 1 ELSE 0 END) as medium_risks,
    SUM(CASE WHEN risk_level = 'Low' THEN 1 ELSE 0 END) as low_risks,
    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_risks,
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_risks,
    SUM(CASE WHEN to_be_reported_to_board = 'YES' THEN 1 ELSE 0 END) as board_risks,
    SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 90 THEN 1 ELSE 0 END) as aged_risks,
    SUM(CASE WHEN planned_completion_date < NOW() AND status != 'closed' THEN 1 ELSE 0 END) as overdue_risks
    FROM risk_incidents";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Debug output to check if stats are being retrieved
if (empty($stats) || $stats['total_risks'] == 0) {
    echo "<!-- Debug: Stats array is empty. Check database connection. -->";
    error_log("Debug: Stats query returned: " . print_r($stats, true));
}

// Get department compliance health scores
$health_query = "SELECT 
    department,
    COUNT(*) as total_risks,
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_risks,
    AVG(residual_rating) as avg_risk_score,
    SUM(CASE WHEN planned_completion_date < NOW() AND status != 'closed' THEN 1 ELSE 0 END) as overdue_count,
    ROUND(
        (SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) * 40 + 
         (100 - AVG(COALESCE(residual_rating, 0))) * 30 + 
         (100 - (SUM(CASE WHEN planned_completion_date < NOW() AND status != 'closed' THEN 1 ELSE 0 END) / COUNT(*) * 100)) * 30) / 100, 2
    ) as health_score
    FROM risk_incidents 
    WHERE department IS NOT NULL
    GROUP BY department";
$health_stmt = $db->prepare($health_query);
$health_stmt->execute();
$dept_health = $health_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity from audit_logs instead
$alerts_query = "SELECT * FROM audit_logs ORDER BY timestamp DESC LIMIT 10";
$alerts_stmt = $db->prepare($alerts_query);
$alerts_stmt->execute();
$recent_alerts = $alerts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming deadlines from risk_incidents
$reviews_query = "SELECT risk_name, department, planned_completion_date as review_date, 'Risk Review' as review_type 
                  FROM risk_incidents 
                  WHERE planned_completion_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
                  AND status != 'closed'
                  ORDER BY planned_completion_date ASC";
$reviews_stmt = $db->prepare($reviews_query);
$reviews_stmt->execute();
$upcoming_reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

function detectSimilarRisks($risks) {
    $similarGroups = [];
    
    foreach ($risks as $risk) {
        $riskCategories = json_decode($risk['risk_categories'] ?? '[]', true);
        if (empty($riskCategories)) continue;
        
        // Sort categories for exact matching
        sort($riskCategories);
        $categoryKey = implode('|', $riskCategories);
        
        $found = false;
        foreach ($similarGroups as &$group) {
            if ($group['category_key'] === $categoryKey) {
                $group['risks'][] = $risk;
                $group['report_count']++;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $similarGroups[] = [
                'risks' => [$risk],
                'report_count' => 1,
                'categories' => $riskCategories,
                'category_key' => $categoryKey,
                'risk_id_number' => rand(1000, 9999) // Will be updated based on count
            ];
        }
    }
    
    // Assign proper ID numbers based on report count
    foreach ($similarGroups as &$group) {
        if ($group['report_count'] > 1) {
            $group['risk_id_number'] = rand(10000, 99999); // 5-digit for multiple reports
        } else {
            $group['risk_id_number'] = rand(1000, 9999); // 4-digit for single report
        }
    }
    
    return $similarGroups;
}

$riskGroups = detectSimilarRisks($all_risks);

// Get real heat map data by department and risk level
$heatmap_query = "SELECT 
    department,
    risk_level,
    COUNT(*) as risk_count
    FROM risk_incidents 
    WHERE department IS NOT NULL AND risk_level IS NOT NULL
    GROUP BY department, risk_level
    ORDER BY department, 
    CASE risk_level 
        WHEN 'Low' THEN 1 
        WHEN 'Medium' THEN 2 
        WHEN 'High' THEN 3 
        WHEN 'Critical' THEN 4 
    END";
$heatmap_stmt = $db->prepare($heatmap_query);
$heatmap_stmt->execute();
$heatmap_data = $heatmap_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly trends data for the last 6 months
$trends_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    risk_level,
    COUNT(*) as count
    FROM risk_incidents 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), risk_level
    ORDER BY month";
$trends_stmt = $db->prepare($trends_query);
$trends_stmt->execute();
$trends_data = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department analysis data
$dept_analysis_query = "SELECT 
    department,
    COUNT(*) as total_risks,
    SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) as critical_risks,
    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_risks
    FROM risk_incidents 
    WHERE department IS NOT NULL
    GROUP BY department
    ORDER BY total_risks DESC";
$dept_analysis_stmt = $db->prepare($dept_analysis_query);
$dept_analysis_stmt->execute();
$dept_analysis_data = $dept_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Compliance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    
    <style>
    :root {
        --primary-color: #E60012;
        --gradient: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
        --shadow: 0 4px 20px rgba(230, 0, 18, 0.15);
        --border-color: #e9ecef;
        --text-light: #6c757d;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
        --info-color: #17a2b8;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
        background: white;
        color: #333;
        /* Adding padding-top for desktop to push content below fixed header and navbar */
        padding-top: 180px; /* Header + navbar height */
    }

    /* Replicated exact header styling from reference */
    .header {
        background: #E60012;
        padding: 1.5rem 2rem;
        color: white;
        position: fixed !important;
        top: 0 !important;
        left: 0;
        right: 0;
        z-index: 9999 !important;
        box-shadow: 0 2px 10px rgba(230, 0, 18, 0.2);
        display: flex;
        align-items: center;
    }
    
    .header-content {
        max-width: 1200px;
        margin: 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
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
        color: white;
        text-decoration: none;
    }

    /* Updated navigation to only show 5 requested items */
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

    /* Responsive design for mobile */
    @media (max-width: 768px) {
        .header {
            padding: 0.75rem 1rem;
            height: 80px;
        }
        
        .header-content {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .main-title {
            font-size: 1.2rem;
        }
        
        .sub-title {
            font-size: 0.8rem;
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
        
        body {
            /* Mobile already had correct padding, keeping it the same */
            padding-top: 180px; /* Header + navbar height + extra spacing */
        }
        
        .main-content {
            padding: 1rem;
            max-height: calc(100vh - 160px);
        }
    }

    /* Updated color scheme to Airtel Red theme */
    :root {
        --primary-color: #E60012;
        --primary-dark: #CC0010;
        --primary-light: #FF1A2E;
        --secondary-color: #f8f9fa;
        --text-dark: #2c3e50;
        --text-light: #6c757d;
        --border-color: #e9ecef;
        --shadow: 0 2px 10px rgba(230, 0, 18, 0.1);
        --gradient: linear-gradient(135deg, #E60012 0%, #FF1A2E 100%);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #ffffff;
        min-height: 100vh;
        color: var(--text-dark);
    }

    /* Modern header with Airtel Red gradient */
    .header {
        background: var(--gradient);
        color: white;
        padding: 1.5rem 2rem;
        box-shadow: var(--shadow);
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        max-width: 1200px;
        margin: 0 auto;
    }

    .logo-section {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .logo {
        width: 40px;
        height: 40px;
        background: white;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: var(--primary-color);
    }

    .header h1 {
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 1rem;
        font-size: 0.9rem;
    }

    /* Modern container and card styling */
    .container {
        max-width: 1200px;
        margin: 2rem auto;
        padding: 0 1rem;
        /* Removing extra margin-top since body now has proper padding */
        /* margin-top: 3rem; */
    }

    .dashboard-card {
        background: white;
        border-radius: 12px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
        border-top: 4px solid var(--primary-color);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .card-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
    }

    .card-header h2 {
        color: var(--text-dark);
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
    }

    .card-body {
        padding: 1.5rem;
    }

    /* Modern tab styling with Airtel Red accents */
    .nav-tabs {
        display: flex;
        border-bottom: 2px solid var(--border-color);
        margin-bottom: 1.5rem;
        background: #f8f9fa;
        border-radius: 8px 8px 0 0;
        padding: 0.5rem;
    }

    .nav-tab {
        padding: 0.75rem 1.5rem;
        background: transparent;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        color: var(--text-light);
        transition: all 0.3s ease;
        margin-right: 0.5rem;
    }

    .nav-tab:hover {
        background: rgba(230, 0, 18, 0.1);
        color: var(--primary-color);
    }

    .nav-tab.active {
        background: var(--primary-color);
        color: white;
        box-shadow: 0 2px 8px rgba(230, 0, 18, 0.3);
    }

    /* Modern button styling */
    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .btn-primary {
        background: var(--gradient);
        color: white;
        box-shadow: 0 2px 8px rgba(230, 0, 18, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(230, 0, 18, 0.4);
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .btn-success {
        background: #28a745;
        color: white;
    }

    .btn-warning {
        background: #ffc107;
        color: #212529;
    }

    .btn-danger {
        background: #dc3545;
        color: white;
    }

    /* Modern table styling */
    .table-container {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        color: white;
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.9rem;
    }

    td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-dark);
    }

    tr:hover {
        background: rgba(230, 0, 18, 0.05);
    }

    /* Modern form styling */
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: var(--text-dark);
    }

    .form-control {
        width: 100%;
        padding: 0.75rem;
        border: 2px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
    }

    /* Modern status badges */
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-high { background: #fee; color: #dc3545; border: 1px solid #f5c6cb; }
    .status-medium { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
    .status-low { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .status-completed { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }

    /* Modern grid layout */
    /* Updated grid to display all 4 cards in one row and increased card height */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
        width: 100%;
    }

    /* Updated stat-card styling to match risk_owner_dashboard.php reference exactly */
    .stat-card {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border-top: 4px solid #E60012;
        transition: transform 0.3s ease;
        text-align: center;
        min-height: 180px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        width: 100%;
        box-sizing: border-box;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(230, 0, 18, 0.15);
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        color: #E60012;
        margin-bottom: 0.5rem;
        display: block;
        line-height: 1;
    }

    .stat-label {
        color: #495057;
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        display: block;
        line-height: 1.2;
    }

    .stat-description {
        color: #6c757d;
        font-size: 0.85rem;
        display: block;
        line-height: 1.3;
    }

    /* Added risk level distribution grid fix */
    .risk-level-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
        width: 100%;
    }

    .risk-level-card {
        background: white;
        padding: 2rem;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        text-align: center;
        min-height: 160px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        width: 100%;
        box-sizing: border-box;
    }

    .risk-level-card h3 {
        margin: 0;
        font-size: 3rem;
        font-weight: 700;
        line-height: 1;
    }

    .risk-level-card p {
        margin: 0.5rem 0;
        color: #495057;
        font-weight: 600;
        font-size: 1.1rem;
        line-height: 1.2;
    }

    .risk-level-card small {
        color: #6c757d;
        line-height: 1.3;
    }

    /* Loading and animation effects */
    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(230, 0, 18, 0.3);
        border-radius: 50%;
        border-top-color: var(--primary-color);
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .fade-in {
        animation: fadeIn 0.5s ease-in;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Added main content sizing constraints */
    .main-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem;
        height: calc(100vh - 160px);
        overflow-y: auto;
    }

    /* Added tab content sizing and scrolling */
    .tab-content {
        display: none;
        height: 100%;
        overflow-y: auto;
    }

    .tab-content.active {
        display: block;
    }

    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border-top: 4px solid #E60012;
        padding: 2rem;
        margin-bottom: 1.5rem;
        max-height: calc(100vh - 220px);
        overflow-y: auto;
    }

    /* Mobile responsive fixes for metric boxes */
    @media (max-width: 768px) {
        .header {
            padding: 0.75rem 1rem;
            height: 80px;
        }
        
        .header-content {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .main-title {
            font-size: 1.2rem;
        }
        
        .sub-title {
            font-size: 0.8rem;
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
        
        body {
            /* Increased padding for mobile to ensure content is below header and navbar */
            padding-top: 180px; /* Header + navbar height + extra spacing */
        }
        
        .main-content {
            padding: 1rem;
            /* Adjusted max-height calculation for new padding */
            max-height: calc(100vh - 180px);
        }

        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .risk-level-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .stat-card {
            min-height: 120px;
            padding: 1.25rem;
        }

        .risk-level-card {
            min-height: 140px;
            padding: 1.5rem;
        }

        .stat-number {
            font-size: 2rem;
        }

        .risk-level-card h3 {
            font-size: 2.5rem;
        }
    }

    /* Added tablet responsive design */
    @media (max-width: 1024px) and (min-width: 769px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .risk-level-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    /* Hide scrollbars from frontend */
    body {
        padding-top: 180px;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* Internet Explorer 10+ */
    }
    
    body::-webkit-scrollbar {
        display: none; /* WebKit */
    }
    
    .tab-content {
        display: none;
        height: 100%;
        overflow-y: auto;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* Internet Explorer 10+ */
    }
    
    .tab-content::-webkit-scrollbar {
        display: none; /* WebKit */
    }

    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border-top: 4px solid #E60012;
        padding: 2rem;
        margin-bottom: 1.5rem;
        max-height: calc(100vh - 220px);
        overflow-y: auto;
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* Internet Explorer 10+ */
    }
    
    .card::-webkit-scrollbar {
        display: none; /* WebKit */
    }

    /* Added print styles for risk details */
    @media print {
        .no-print {
            display: none !important;
        }
        
        .print-only {
            display: block !important;
        }
        
        body {
            padding-top: 0 !important;
        }
        
        .header, .nav {
            display: none !important;
        }
        
        .main-content {
            padding: 0 !important;
            max-height: none !important;
        }
    }
    
    .print-only {
        display: none;
    }
    
    /* Added styles for enhanced filters */
    .filter-section {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #e9ecef;
    }
    
    .filter-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-label {
        font-weight: 500;
        color: #495057;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }
    
    .filter-input {
        padding: 0.5rem;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        font-size: 0.9rem;
    }
    
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.85rem;
        border-radius: 4px;
    }
</style>
</head>
<body>
    <!-- Replaced header with exact structure from reference -->
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Compliance</h1>
                    <p class="sub-title">Risk Management System</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'M'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : '232000@airtel.africa'; ?></div>
                    <div class="user-role"><?php echo isset($_SESSION['department']) ? $_SESSION['department'] . ' • Airtel Money' : 'Compliance • Airtel Money'; ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>

    <!-- Updated navigation to only show 5 requested items -->
    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <!-- Added dashboard icon -->
                    <a href="#" class="active" onclick="showTab('overview')">
                        <span>📊</span> Overview
                    </a>
                </li>
                <li class="nav-item">
                    <!-- Added tracking icon -->
                    <a href="#" onclick="showTab('tracking')">
                        <span>📋</span> Risk Tracking
                    </a>
                </li>
                <li class="nav-item">
                    <!-- Added analytics icon -->
                    <a href="#" onclick="showTab('analytics')">
                        <span>📈</span> Analytics
                    </a>
                </li>
                <li class="nav-item">
                    <!-- Added health icon -->
                    <a href="#" onclick="showTab('health')">
                        <span>💚</span> Compliance Health
                    </a>
                </li>
                <li class="nav-item">
                    <!-- Added notifications icon -->
                    <a href="#" onclick="showTab('notifications')">
                        <span>🔔</span> Notifications
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Removed alert banner notification popup -->
    
    <!-- Added proper main content wrapper with sizing constraints -->
    <main class="main-content">
        <!-- Overview Tab -->
        <div id="overview" class="tab-content active">
            <?php if (empty($stats)): ?>
                <div style="background: #ffebee; color: #c62828; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                    Debug: Stats array is empty. Check database connection.
                </div>
            <?php endif; ?>
            
            <!-- Updated Overview tab with Team Chat, AI Assistant, and Live Risk Data Matrix -->
            <!-- Updated to match reference image layout with 4 metric cards in top row -->
            <!-- Updated metric cards to use exact stat-card styling from risk_owner_dashboard.php reference -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['total_risks']) ? $stats['total_risks'] : '0'; ?></div>
                    <div class="stat-label">Total Risks</div>
                    <div class="stat-description">All risks in system</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['aged_risks']) ? $stats['aged_risks'] : '0'; ?></div>
                    <div class="stat-label">Aged Risks</div>
                    <div class="stat-description">Risks over 90 days old</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['closed_risks']) ? $stats['closed_risks'] : '0'; ?></div>
                    <div class="stat-label">Successfully Managed Risks</div>
                    <div class="stat-description">Successfully closed risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['board_risks']) ? $stats['board_risks'] : '0'; ?></div>
                    <div class="stat-label">Board Risks</div>
                    <div class="stat-description">Risks reported to board</div>
                </div>
            </div>

            <!-- Quick Actions Section - Updated to match reference image -->
            <!-- Reduced card height and updated AI Assistant icon -->
            <div style="margin-bottom: 2rem;">
                <div style="background: white; border-radius: 12px; border-top: 3px solid #E60012; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 1.5rem;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #333; font-size: 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        🚀 Quick Actions
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <!-- Team Chat Card - Purple gradient matching image exactly -->
                        <a href="teamchat.php" style="text-decoration: none;">
                            <div style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(111, 66, 193, 0.2); color: white; text-align: center; cursor: pointer; transition: all 0.3s ease; min-height: 100px; display: flex; flex-direction: column; justify-content: center;" 
                                 onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 25px rgba(111, 66, 193, 0.3)'"
                                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(111, 66, 193, 0.2)'">
                                <div style="font-size: 2.5rem; margin-bottom: 0.5rem; opacity: 0.9;">💬</div>
                                <h4 style="margin: 0 0 0.3rem 0; font-size: 1.3rem; font-weight: 600; letter-spacing: -0.02em;">Team Chat</h4>
                                <p style="margin: 0; opacity: 0.85; font-size: 0.9rem; font-weight: 400;">Collaborate with your team</p>
                            </div>
                        </a>
                        
                        <!-- Made AI Assistant image round -->
                        <div style="background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%); padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 188, 212, 0.2); color: white; text-align: center; cursor: pointer; transition: all 0.3s ease; min-height: 100px; display: flex; flex-direction: column; justify-content: center; position: relative;"
                             onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 25px rgba(0, 188, 212, 0.3)'"
                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 188, 212, 0.2)'">
                            <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 0.5rem auto; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                <img src="chatbot.png" alt="AI Assistant" style="width: 30px; height: 30px; object-fit: contain; border-radius: 50%;">
                            </div>
                            <h4 style="margin: 0 0 0.3rem 0; font-size: 1.3rem; font-weight: 600; letter-spacing: -0.02em;">AI Assistant</h4>
                            <p style="margin: 0; opacity: 0.85; font-size: 0.9rem; font-weight: 400;">Get help with risk management</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Simplified Live Risk Data Matrix with clean horizontal lines -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012; margin-bottom: 2rem;">
                <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📊 Live Risk Data Matrix</h3>
                <div style="height: 400px; position: relative; background: #f8f9fa; border-radius: 8px;">
                    <canvas id="liveRiskMatrix" width="800" height="400" style="width: 100%; height: 100%;"></canvas>
                    <!-- Y-axis label -->
                    <div style="position: absolute; left: 10px; top: 10px; font-size: 0.9rem; color: #495057; font-weight: 500;">
                        Number of Times Reported
                    </div>
                    <!-- X-axis label -->
                    <div style="position: absolute; bottom: 10px; right: 10px; font-size: 0.9rem; color: #495057; font-weight: 500;">
                        Risk Timeline
                    </div>
                </div>
                <div style="display: flex; justify-content: center; gap: 2rem; margin-top: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <div style="width: 16px; height: 4px; background: #dc3545; border-radius: 2px;"></div>
                        <span style="font-size: 0.9rem; color: #495057;">Multiple Reports (5-digit ID)</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <div style="width: 16px; height: 4px; background: #28a745; border-radius: 2px;"></div>
                        <span style="font-size: 0.9rem; color: #495057;">Single Report (4-digit ID)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Risk Tracking Tab -->
        <div id="tracking" class="tab-content">
            <!-- Updated Risk Level Distribution Cards to match overview tab styling -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" style="color: #dc3545;"><?php echo $stats['critical_risks']; ?></div>
                    <div class="stat-label">Critical Risks</div>
                    <div class="stat-description">Immediate attention required</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #fd7e14;"><?php echo $stats['high_risks']; ?></div>
                    <div class="stat-label">High Risks</div>
                    <div class="stat-description">Priority monitoring needed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ffc107;"><?php echo $stats['medium_risks']; ?></div>
                    <div class="stat-label">Medium Risks</div>
                    <div class="stat-description">Regular monitoring</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #28a745;"><?php echo $stats['low_risks']; ?></div>
                    <div class="stat-label">Low Risks</div>
                    <div class="stat-description">Minimal oversight needed</div>
                </div>
            </div>

            <!-- Enhanced Filters Section -->
            <div class="filter-section no-print">
                <h4 style="margin: 0 0 1rem 0; color: #495057;">🔍 Filter Risks</h4>
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label">Search by Risk ID</label>
                        <input type="text" id="searchRiskId" class="filter-input" placeholder="Enter Risk ID..." onkeyup="filterByRiskId(this.value)">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Department</label>
                        <select id="departmentFilter" class="filter-input" onchange="filterRisks(this.value)">
                            <option value="all">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Risk Level</label>
                        <select id="riskLevelFilter" class="filter-input" onchange="filterByRiskLevel(this.value)">
                            <option value="all">All Risk Levels</option>
                            <option value="Critical">Critical</option>
                            <option value="High">High</option>
                            <option value="Medium">Medium</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Board Reporting</label>
                        <select id="boardFilter" class="filter-input" onchange="filterByBoardReporting(this.value)">
                            <option value="all">All Risks</option>
                            <option value="YES">Report to Board</option>
                            <option value="NO">Not Report to Board</option>
                        </select>
                    </div>
                </div>
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label">Date From</label>
                        <input type="date" id="dateFrom" class="filter-input" onchange="filterByDateRange()">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Date To</label>
                        <input type="date" id="dateTo" class="filter-input" onchange="filterByDateRange()">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Actions</label>
                        <div class="action-buttons">
                            <button onclick="clearAllFilters()" class="btn btn-secondary btn-sm">Clear Filters</button>
                            <button onclick="exportFilteredRisks('pdf')" class="btn btn-danger btn-sm">📄 Export PDF</button>
                            <button onclick="exportFilteredRisks('excel')" class="btn btn-success btn-sm">📊 Export Excel</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Updated Risks Table with View All button and last 10 risks display -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                    <h3 style="margin: 0; color: #495057; font-size: 1.3rem; font-weight: 600;">
                        <span id="tableTitle">Recent Risks (Last 10)</span>
                    </h3>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button id="viewAllBtn" onclick="toggleViewAll()" class="btn btn-primary" style="padding: 0.5rem 1rem;">
                            View All Risks
                        </button>
                        <button onclick="printRisksTable()" class="btn btn-secondary no-print" style="padding: 0.5rem 1rem;">
                            🖨️ Print Table
                        </button>
                    </div>
                </div>
                
                <div style="overflow-x: auto;">
                    <table id="risksTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                <!-- Changed table header text color from #495057 to white -->
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk ID</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk Name</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk Level</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Status</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Department</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Date Created</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Board Report</th>
                                <!-- Removed the Actions column with View Details and Print buttons -->
                            </tr>
                        </thead>
                        <tbody id="risksTableBody">
                            <!-- Initial display shows last 10 risks -->
                            <?php foreach ($recent_risks as $risk): ?>
                            <tr data-risk-id="<?php echo htmlspecialchars($risk['id']); ?>"
                                data-department="<?php echo htmlspecialchars($risk['department']); ?>" 
                                data-risk-level="<?php echo htmlspecialchars($risk['risk_level']); ?>"
                                data-board="<?php echo htmlspecialchars($risk['to_be_reported_to_board'] ?? 'NO'); ?>"
                                data-created="<?php echo htmlspecialchars($risk['created_at']); ?>"
                                style="border-bottom: 1px solid #e9ecef;">
                                <td style="padding: 1rem; color: #495057; font-weight: 500;"><?php echo htmlspecialchars($risk['id']); ?></td>
                                <td style="padding: 1rem; color: #495057; font-weight: 500;"><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                <td style="padding: 1rem;">
                                    <?php 
                                    $levelColors = [
                                        'Critical' => '#dc3545',
                                        'High' => '#fd7e14', 
                                        'Medium' => '#ffc107',
                                        'Low' => '#28a745'
                                    ];
                                    $color = $levelColors[$risk['risk_level']] ?? '#6c757d';
                                    ?>
                                    <span style="padding: 0.25rem 0.75rem; background: <?php echo $color; ?>; color: white; border-radius: 20px; font-size: 0.85rem; font-weight: 500;">
                                        <?php echo htmlspecialchars($risk['risk_level']); ?>
                                    </span>
                                </td>
                                <td style="padding: 1rem;">
                                    <span style="padding: 0.25rem 0.75rem; background: <?php echo $risk['status'] == 'Closed' ? '#28a745' : '#17a2b8'; ?>; color: white; border-radius: 20px; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($risk['status']); ?>
                                    </span>
                                </td>
                                <td style="padding: 1rem; color: #495057;"><?php echo htmlspecialchars($risk['department']); ?></td>
                                <td style="padding: 1rem; color: #495057;"><?php echo date('M j, Y', strtotime($risk['created_at'])); ?></td>
                                <td style="padding: 1rem;">
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" <?php echo ($risk['to_be_reported_to_board'] == 'YES') ? 'checked' : ''; ?> 
                                               onchange="toggleBoardReporting(<?php echo $risk['id']; ?>, this.checked)"
                                               style="accent-color: #E60012;">
                                        <span style="font-size: 0.9rem; color: #495057;">Report to Board</span>
                                    </label>
                                </td>
                                <!-- Removed the Actions column with View Details and Print buttons -->
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Analytics Tab -->
        <div id="analytics" class="tab-content">
            <!-- Implemented comprehensive Analytics with heat map and reports -->
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                <!-- Risk Heat Map -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🔥 Risk Heat Map</h3>
                    <div style="height: 400px; position: relative;">
                        <canvas id="riskHeatMap" width="100%" height="400"></canvas>
                    </div>
                </div>
                
                <!-- Report Generation -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📋 Generate Reports</h3>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">Report Type:</label>
                        <select id="reportType" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                            <option value="monthly">Monthly Report</option>
                            <option value="quarterly">Quarterly Report</option>
                            <option value="annual">Annual Report</option>
                            <option value="custom">Custom Date Range</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">Department:</label>
                        <select id="reportDepartment" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                            <option value="all">All Departments</option>
                            <?php foreach($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">Risk Level:</label>
                        <select id="reportRiskLevel" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                            <option value="all">All Risk Levels</option>
                            <option value="Critical">Critical Only</option>
                            <option value="High">High Only</option>
                            <option value="Medium">Medium Only</option>
                            <option value="Low">Low Only</option>
                        </select>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <button onclick="generateReport('pdf')" style="padding: 0.75rem; background: #E60012; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                            📄 Download PDF Report
                        </button>
                        <button onclick="generateReport('excel')" style="padding: 0.75rem; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                            📊 Download Excel Report
                        </button>
                        <button onclick="printReport()" style="padding: 0.75rem; background: #17a2b8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                            🖨️ Print Report
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Analytics Charts -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📈 Risk Trends</h3>
                    <canvas id="riskTrendsChart" width="100%" height="300"></canvas>
                </div>
                
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🏢 Department Analysis</h3>
                    <canvas id="departmentChart" width="100%" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Compliance Health Tab -->
        <div id="health" class="tab-content">
            <!-- Implemented Compliance Health monitoring with department scores -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #28a745; text-align: center;">
                    <h3 style="margin: 0; font-size: 3rem; font-weight: 700; color: #28a745;">
                        <?php echo round(array_sum(array_column($dept_health, 'health_score')) / count($dept_health), 1); ?>%
                    </h3>
                    <p style="margin: 0.5rem 0; color: #495057; font-weight: 600; font-size: 1.1rem;">Overall Health Score</p>
                    <small style="color: #6c757d;">Organization-wide compliance</small>
                </div>
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #ffc107; text-align: center;">
                    <h3 style="margin: 0; font-size: 3rem; font-weight: 700; color: #ffc107;"><?php echo $stats['overdue_risks']; ?></h3>
                    <p style="margin: 0.5rem 0; color: #495057; font-weight: 600; font-size: 1.1rem;">Overdue Actions</p>
                    <small style="color: #6c757d;">Require immediate attention</small>
                </div>
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #17a2b8; text-align: center;">
                    <h3 style="margin: 0; font-size: 3rem; font-weight: 700; color: #17a2b8;">
                        <?php echo round(($stats['closed_risks'] / $stats['total_risks']) * 100, 1); ?>%
                    </h3>
                    <p style="margin: 0.5rem 0; color: #495057; font-weight: 600; font-size: 1.1rem;">Resolution Rate</p>
                    <small style="color: #6c757d;">Successfully managed risks</small>
                </div>
            </div>
            
            <!-- Department Health Scores -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012; margin-bottom: 2rem;">
                <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🏢 Department Health Scores</h3>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #495057;">Department</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #495057;">Total Risks</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #495057;">Closed Risks</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #495057;">Overdue</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #495057;">Health Score</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: #495057;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dept_health as $dept): ?>
                            <tr style="border-bottom: 1px solid #e9ecef;">
                                <td style="padding: 1rem; color: #495057; font-weight: 500;"><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td style="padding: 1rem; color: #495057;"><?php echo $dept['total_risks']; ?></td>
                                <td style="padding: 1rem; color: #495057;"><?php echo $dept['closed_risks']; ?></td>
                                <td style="padding: 1rem; color: #495057;"><?php echo $dept['overdue_count']; ?></td>
                                <td style="padding: 1rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div style="flex: 1; height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                            <div style="height: 100%; background: <?php echo $dept['health_score'] >= 80 ? '#28a745' : ($dept['health_score'] >= 60 ? '#ffc107' : '#dc3545'); ?>; width: <?php echo $dept['health_score']; ?>%; transition: width 0.3s ease;"></div>
                                        </div>
                                        <span style="font-weight: 600; color: #495057;"><?php echo round($dept['health_score'], 1); ?>%</span>
                                    </div>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php 
                                    $status = $dept['health_score'] >= 80 ? 'Excellent' : ($dept['health_score'] >= 60 ? 'Good' : 'Needs Attention');
                                    $statusColor = $dept['health_score'] >= 80 ? '#28a745' : ($dept['health_score'] >= 60 ? '#ffc107' : '#dc3545');
                                    ?>
                                    <span style="padding: 0.25rem 0.75rem; background: <?php echo $statusColor; ?>; color: white; border-radius: 20px; font-size: 0.85rem;">
                                        <?php echo $status; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Notifications Tab -->
        <div id="notifications" class="tab-content">
            <!-- Enhanced Notifications with real alerts and upcoming reviews -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <!-- Recent Alerts -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🚨 Recent Alerts</h3>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($recent_alerts)): ?>
                        <div style="text-align: center; padding: 2rem; color: #6c757d;">
                            <p>No recent alerts</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_alerts as $alert): ?>
                        <div style="margin-bottom: 1rem; padding: 1rem; border-left: 4px solid #dc3545; background: #fff5f5; border-radius: 6px;">
                            <h4 style="margin: 0 0 0.5rem 0; color: #dc3545; font-size: 1rem;"><?php echo htmlspecialchars($alert['title']); ?></h4>
                            <p style="margin: 0 0 0.5rem 0; color: #495057; font-size: 0.9rem;"><?php echo htmlspecialchars($alert['message']); ?></p>
                            <small style="color: #6c757d;"><?php echo date('M j, Y g:i A', strtotime($alert['timestamp'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Upcoming Reviews -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📅 Upcoming Reviews</h3>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($upcoming_reviews)): ?>
                        <div style="text-align: center; padding: 2rem; color: #6c757d;">
                            <p>No upcoming reviews scheduled</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($upcoming_reviews as $review): ?>
                        <div style="margin-bottom: 1rem; padding: 1rem; border-left: 4px solid #17a2b8; background: #f0f9ff; border-radius: 6px;">
                            <h4 style="margin: 0 0 0.5rem 0; color: #17a2b8; font-size: 1rem;"><?php echo htmlspecialchars($review['risk_name']); ?></h4>
                            <p style="margin: 0 0 0.5rem 0; color: #495057; font-size: 0.9rem;">Department: <?php echo htmlspecialchars($review['department']); ?></p>
                            <p style="margin: 0 0 0.5rem 0; color: #495057; font-size: 0.9rem;">Review Type: <?php echo htmlspecialchars($review['review_type']); ?></p>
                            <small style="color: #6c757d;">Due: <?php echo date('M j, Y', strtotime($review['review_date'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Enhanced Risk Details Modal with full information -->
    <div id="riskDetailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 95%; max-width: 1000px; max-height: 90%; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);">
            <div style="padding: 2rem; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; border-radius: 12px 12px 0 0;">
                <h3 style="margin: 0; color: #495057;">Complete Risk Details</h3>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button onclick="printRiskDetails()" class="btn btn-secondary btn-sm no-print">🖨️ Print Details</button>
                    <button onclick="closeRiskDetailsModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6c757d;">&times;</button>
                </div>
            </div>
            <div id="riskDetailsContent" style="padding: 2rem;">
                <!-- Full risk details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Risk Details Modal -->
    <div id="riskModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90%; overflow-y: auto;">
            <div style="padding: 2rem; border-bottom: 1px solid #e9ecef; display: flex; justify-content: between; align-items: center;">
                <h3 style="margin: 0; color: #495057;">Risk Details</h3>
                <button onclick="closeRiskModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6c757d;">&times;</button>
            </div>
            <div id="riskModalContent" style="padding: 2rem;">
                <!-- Risk details will be loaded here -->
            </div>
        </div>
    </div>
    
    <!-- Risk Details Popup Modal -->
    <div id="riskPopupOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999;" onclick="closeRiskPopup()"></div>
    <div id="riskPopup" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); padding: 2rem; max-width: 500px; width: 90%; z-index: 1000;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; color: #495057;">Risk Details</h3>
            <button onclick="closeRiskPopup()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6c757d;">&times;</button>
        </div>
        <div id="riskPopupContent">
            <!-- Risk details will be loaded here -->
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all nav items
            const navItems = document.querySelectorAll('.nav-item a');
            navItems.forEach(item => {
                item.classList.remove('active');
            });
            
            // Show the selected tab content
            const selectedTab = document.getElementById(tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Add active class to the clicked nav item
            event.target.classList.add('active');
        }

    let riskGroups = <?php echo json_encode($riskGroups); ?>;
    let animationTime = 0;
    let clickableAreas = [];

    function initializeOverviewCharts() {
        const canvas = document.getElementById('liveRiskMatrix');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        const width = canvas.width = 800;
        const height = canvas.height = 400;
        
        function drawLiveChart() {
            ctx.clearRect(0, 0, width, height);
            clickableAreas = []; // Reset clickable areas
            
            ctx.fillStyle = '#6c757d';
            ctx.font = '12px Arial';
            ctx.textAlign = 'right';
            for (let i = 0; i <= 10; i++) {
                const y = height - (height / 10) * i - 20;
                
                // Create clickable area for Y-axis values
                clickableAreas.push({
                    type: 'yaxis',
                    x: 0,
                    y: y - 10,
                    width: 40,
                    height: 20,
                    value: i,
                    risks: riskGroups.filter(group => group.report_count === i)
                });
                
                ctx.fillText(i.toString(), 30, y + 4);
            }
            
            // Draw horizontal lines for each risk group
            riskGroups.forEach((group, index) => {
                const reportCount = group.report_count;
                const isMultiple = reportCount > 1;
                const color = isMultiple ? '#dc3545' : '#28a745';
                
                // Calculate Y position based on report count
                const yPos = height - (reportCount / 10) * (height - 40) - 20;
                
                // Draw horizontal line that moves
                ctx.strokeStyle = color;
                ctx.lineWidth = 3;
                ctx.beginPath();
                
                const startX = 50;
                const endX = width - 100;
                const waveOffset = Math.sin(animationTime * 0.02 + index) * 5;
                
                ctx.moveTo(startX, yPos + waveOffset);
                ctx.lineTo(endX, yPos + waveOffset);
                ctx.stroke();
                
                // Draw data point
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.arc(endX - 20, yPos + waveOffset, isMultiple ? 6 : 4, 0, 2 * Math.PI);
                ctx.fill();
                
                // Draw risk ID
                ctx.fillStyle = '#495057';
                ctx.font = '11px Arial';
                ctx.textAlign = 'left';
                ctx.fillText(`ID: ${group.risk_id_number}`, startX + 10, yPos + waveOffset - 8);
                
                clickableAreas.push({
                    type: 'riskline',
                    x: startX,
                    y: yPos + waveOffset - 10,
                    width: endX - startX,
                    height: 20,
                    group: group
                });
            });
            
            animationTime += 1;
            requestAnimationFrame(drawLiveChart);
        }
        
        canvas.onclick = function(event) {
            const rect = canvas.getBoundingClientRect();
            const clickX = event.clientX - rect.left;
            const clickY = event.clientY - rect.top;
            
            // Check all clickable areas
            for (let area of clickableAreas) {
                if (clickX >= area.x && clickX <= area.x + area.width && 
                    clickY >= area.y && clickY <= area.y + area.height) {
                    
                    if (area.type === 'riskline') {
                        showRiskDetails(area.group);
                    } else if (area.type === 'yaxis') {
                        showYAxisRisks(area.value, area.risks);
                    }
                    break;
                }
            }
        };
        
        drawLiveChart();
    }
    
    function showRiskDetails(group) {
        const popup = document.getElementById('riskPopup');
        const overlay = document.getElementById('riskPopupOverlay');
        
        let content = `
            <div style="margin-bottom: 1rem;">
                <h4 style="color: #E60012; margin: 0 0 0.5rem 0;">Risk Group ID: ${group.risk_id_number}</h4>
                <p style="margin: 0; color: #6c757d; font-weight: 500;">Reported ${group.report_count} time(s)</p>
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <h5 style="margin: 0 0 0.5rem 0; color: #495057;">Risk Categories:</h5>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    ${group.categories.map(cat => `<span style="background: #E60012; color: white; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem;">${cat}</span>`).join('')}
                </div>
            </div>
            
            <div>
                <h5 style="margin: 0 0 1rem 0; color: #495057;">Individual Risk Reports:</h5>
                <div style="max-height: 300px; overflow-y: auto; padding-right: 10px;">
        `;
        
        group.risks.forEach(risk => {
            content += `
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 0.75rem; border-left: 4px solid #E60012;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <h6 style="margin: 0; color: #495057; font-weight: 600;">Database Risk ID: ${risk.id}</h6>
                        <span style="background: ${getRiskLevelColor(risk.risk_level)}; color: white; padding: 0.2rem 0.5rem; border-radius: 10px; font-size: 0.7rem;">${risk.risk_level}</span>
                    </div>
                    <p style="margin: 0 0 0.5rem 0; font-weight: 500; color: #333;">${risk.risk_name}</p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; color: #6c757d;">
                        <p style="margin: 0;"><strong>Department:</strong> ${risk.department}</p>
                        <p style="margin: 0;"><strong>Status:</strong> ${risk.status}</p>
                        <p style="margin: 0;"><strong>Reporter:</strong> ${risk.reporter_name || 'N/A'}</p>
                        <p style="margin: 0;"><strong>Date:</strong> ${new Date(risk.created_at).toLocaleDateString()}</p>
                    </div>
                    ${risk.risk_description ? `<p style="margin: 0.5rem 0 0 0; font-size: 0.85rem; color: #495057; font-style: italic;">${risk.risk_description}</p>` : ''}
                </div>
            `;
        });
        
        content += `
                </div>
            </div>
        `;
        
        document.getElementById('riskPopupContent').innerHTML = content;
        popup.style.display = 'block';
        overlay.style.display = 'block';
    }
    
    function showYAxisRisks(reportCount, risks) {
        if (risks.length === 0) {
            alert(`No risks reported ${reportCount} times`);
            return;
        }
        
        const popup = document.getElementById('riskPopup');
        const overlay = document.getElementById('riskPopupOverlay');
        
        let content = `
            <div style="margin-bottom: 1rem;">
                <h4 style="color: #E60012; margin: 0 0 0.5rem 0;">Risks Reported ${reportCount} Time(s)</h4>
                <p style="margin: 0; color: #6c757d;">Found ${risks.length} risk group(s) at this level</p>
            </div>
            
            <div style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
        `;
        
        risks.forEach(group => {
            content += `
                <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 1rem; border: 2px solid #E60012;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h5 style="margin: 0; color: #495057;">Risk Group ID: ${group.risk_id_number}</h5>
                        <span style="background: #E60012; color: white; padding: 0.3rem 0.8rem; border-radius: 15px; font-size: 0.8rem;">${group.report_count} Reports</span>
                    </div>
                    
                    <div style="margin-bottom: 1rem;">
                        <h6 style="margin: 0 0 0.5rem 0; color: #495057;">Categories:</h6>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.3rem;">
                            ${group.categories.map(cat => `<span style="background: #6c757d; color: white; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem;">${cat}</span>`).join('')}
                        </div>
                    </div>
                    
                    <div>
                        <h6 style="margin: 0 0 0.5rem 0; color: #495057;">Individual Risks:</h6>
            `;
            
            group.risks.forEach(risk => {
                content += `
                    <div style="background: white; padding: 0.75rem; border-radius: 4px; margin-bottom: 0.5rem; border-left: 3px solid ${getRiskLevelColor(risk.risk_level)};">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.3rem;">
                            <span style="font-weight: 600; color: #495057;">DB Risk ID: ${risk.id}</span>
                            <span style="font-size: 0.7rem; color: #6c757d;">${risk.risk_level}</span>
                        </div>
                        <p style="margin: 0 0 0.3rem 0; font-weight: 500; color: #333;">${risk.risk_name}</p>
                        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #6c757d;">
                            <span><strong>Dept:</strong> ${risk.department}</span>
                            <span><strong>Status:</strong> ${risk.status}</span>
                        </div>
                    </div>
                `;
            });
            
            content += `
                    </div>
                </div>
            `;
        });
        
        content += '</div>';
        
        document.getElementById('riskPopupContent').innerHTML = content;
        popup.style.display = 'block';
        overlay.style.display = 'block';
    }

    function getRiskLevelColor(level) {
        switch(level?.toLowerCase()) {
            case 'critical': return '#dc3545';
            case 'high': return '#fd7e14';
            case 'medium': return '#ffc107';
            case 'low': return '#28a745';
            default: return '#6c757d';
        }
    }
    
    function closeRiskPopup() {
        document.getElementById('riskPopup').style.display = 'none';
        document.getElementById('riskPopupOverlay').style.display = 'none';
    }

    function initializeAnalyticsCharts() {
        const heatMapCanvas = document.getElementById('riskHeatMap');
        if (heatMapCanvas) {
            const ctx = heatMapCanvas.getContext('2d');
            const width = heatMapCanvas.width = heatMapCanvas.offsetWidth;
            const height = heatMapCanvas.height = 400;
            
            // Real departments and risk levels from database
            const departments = <?php echo json_encode($departments); ?>;
            const riskLevels = ['Low', 'Medium', 'High', 'Critical'];
            const cellWidth = width / departments.length;
            const cellHeight = height / riskLevels.length;
            
            // Real heat map data from database
            const heatMapData = <?php echo json_encode($heatmap_data); ?>;
            
            // Create heat data matrix
            const heatData = [];
            departments.forEach((dept, deptIndex) => {
                heatData[deptIndex] = [];
                riskLevels.forEach((level, levelIndex) => {
                    const dataPoint = heatMapData.find(d => d.department === dept && d.risk_level === level);
                    heatData[deptIndex][levelIndex] = dataPoint ? parseInt(dataPoint.risk_count) : 0;
                });
            });
            
            const maxValue = Math.max(...heatData.flat()) || 1;
            
            departments.forEach((dept, deptIndex) => {
                riskLevels.forEach((level, levelIndex) => {
                    const x = deptIndex * cellWidth;
                    const y = levelIndex * cellHeight;
                    const value = heatData[deptIndex][levelIndex];
                    const intensity = value / maxValue;
                    
                    // Color based on intensity and risk level
                    let red, green, blue;
                    if (levelIndex === 3) { // Critical
                        red = Math.floor(220 + intensity * 35);
                        green = Math.floor(20 + (1 - intensity) * 30);
                        blue = 20;
                    } else if (levelIndex === 2) { // High
                        red = Math.floor(255 * intensity + 100);
                        green = Math.floor(140 + (1 - intensity) * 50);
                        blue = 20;
                    } else if (levelIndex === 1) { // Medium
                        red = Math.floor(255 * intensity + 50);
                        green = Math.floor(193 + (1 - intensity) * 30);
                        blue = Math.floor(7 + intensity * 20);
                    } else { // Low
                        red = Math.floor(40 + intensity * 60);
                        green = Math.floor(167 + intensity * 50);
                        blue = Math.floor(69 + intensity * 30);
                    }
                    
                    ctx.fillStyle = `rgb(${red}, ${green}, ${blue})`;
                    ctx.fillRect(x, y, cellWidth, cellHeight);
                    
                    // Draw border
                    ctx.strokeStyle = '#fff';
                    ctx.lineWidth = 2;
                    ctx.strokeRect(x, y, cellWidth, cellHeight);
                    
                    // Draw text
                    ctx.fillStyle = intensity > 0.5 ? '#fff' : '#000';
                    ctx.font = '16px Arial';
                    ctx.textAlign = 'center';
                    ctx.fillText(value, x + cellWidth/2, y + cellHeight/2 + 5);
                });
            });
            
            // Draw labels
            ctx.fillStyle = '#495057';
            ctx.font = '12px Arial';
            ctx.textAlign = 'center';
            
            // Department labels (truncate long names)
            departments.forEach((dept, index) => {
                const truncatedDept = dept.length > 12 ? dept.substring(0, 12) + '...' : dept;
                ctx.fillText(truncatedDept, index * cellWidth + cellWidth/2, height + 20);
            });
            
            // Risk level labels
            ctx.textAlign = 'right';
            riskLevels.forEach((level, index) => {
                ctx.fillText(level, -10, index * cellHeight + cellHeight/2 + 5);
            });
        }
        
        const trendsCanvas = document.getElementById('riskTrendsChart');
        if (trendsCanvas) {
            const ctx = trendsCanvas.getContext('2d');
            const width = trendsCanvas.width = trendsCanvas.offsetWidth;
            const height = trendsCanvas.height = 300;
            
            // Real trends data from database
            const trendsData = <?php echo json_encode($trends_data); ?>;
            
            // Get last 6 months
            const months = [];
            const criticalTrend = [];
            const highTrend = [];
            const totalTrend = [];
            
            for (let i = 5; i >= 0; i--) {
                const date = new Date();
                date.setMonth(date.getMonth() - i);
                const monthKey = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
                const monthLabel = date.toLocaleDateString('en-US', { month: 'short' });
                months.push(monthLabel);
                
                const criticalCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Critical').reduce((sum, d) => sum + parseInt(d.count), 0);
                const highCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'High').reduce((sum, d) => sum + parseInt(d.count), 0);
                const totalCount = trendsData.filter(d => d.month === monthKey).reduce((sum, d) => sum + parseInt(d.count), 0);
                
                criticalTrend.push(criticalCount);
                highTrend.push(highCount);
                totalTrend.push(totalCount);
            }
            
            const maxValue = Math.max(...totalTrend) || 1;
            
            // Clear canvas
            ctx.clearRect(0, 0, width, height);
            
            // Draw grid lines
            ctx.strokeStyle = '#e9ecef';
            ctx.lineWidth = 1;
            for (let i = 0; i <= 5; i++) {
                const y = (height * 0.8 / 5) * i + 20;
                ctx.beginPath();
                ctx.moveTo(40, y);
                ctx.lineTo(width - 20, y);
                ctx.stroke();
            }
            
            // Draw trend lines
            function drawTrendLine(data, color, label) {
                ctx.strokeStyle = color;
                ctx.lineWidth = 3;
                ctx.beginPath();
                data.forEach((value, index) => {
                    const x = 40 + ((width - 60) / (months.length - 1)) * index;
                    const y = height - 40 - (value / maxValue) * (height * 0.6);
                    if (index === 0) ctx.moveTo(x, y);
                    else ctx.lineTo(x, y);
                });
                ctx.stroke();
                
                // Draw points
                ctx.fillStyle = color;
                data.forEach((value, index) => {
                    const x = 40 + ((width - 60) / (months.length - 1)) * index;
                    const y = height - 40 - (value / maxValue) * (height * 0.6);
                    ctx.beginPath();
                    ctx.arc(x, y, 4, 0, 2 * Math.PI);
                    ctx.fill();
                });
            }
            
            drawTrendLine(criticalTrend, '#dc3545', 'Critical');
            drawTrendLine(highTrend, '#fd7e14', 'High');
            drawTrendLine(totalTrend, '#007bff', 'Total');
            
            // Draw labels
            ctx.fillStyle = '#495057';
            ctx.font = '12px Arial';
            ctx.textAlign = 'center';
            months.forEach((month, index) => {
                const x = 40 + ((width - 60) / (months.length - 1)) * index;
                ctx.fillText(month, x, height - 10);
            });
            
            // Draw legend
            const legendItems = [
                { color: '#dc3545', label: 'Critical' },
                { color: '#fd7e14', label: 'High' },
                { color: '#007bff', label: 'Total' }
            ];
            legendItems.forEach((item, index) => {
                const x = width - 120 + (index * 40);
                ctx.fillStyle = item.color;
                ctx.fillRect(x, 10, 15, 3);
                ctx.fillStyle = '#495057';
                ctx.font = '10px Arial';
                ctx.textAlign = 'left';
                ctx.fillText(item.label, x, 25);
            });
        }
        
        const deptCanvas = document.getElementById('departmentChart');
        if (deptCanvas) {
            const ctx = deptCanvas.getContext('2d');
            const width = deptCanvas.width = deptCanvas.offsetWidth;
            const height = deptCanvas.height = 300;
            
            // Real department data from database
            const deptData = <?php echo json_encode($dept_analysis_data); ?>;
            
            if (deptData.length > 0) {
                const maxRisks = Math.max(...deptData.map(d => parseInt(d.total_risks))) || 1;
                const barWidth = (width - 80) / deptData.length * 0.6;
                const barSpacing = (width - 80) / deptData.length * 0.4;
                
                // Clear canvas
                ctx.clearRect(0, 0, width, height);
                
                deptData.forEach((dept, index) => {
                    const x = 40 + index * (barWidth + barSpacing) + barSpacing/2;
                    const totalHeight = (parseInt(dept.total_risks) / maxRisks) * (height * 0.6);
                    const criticalHeight = (parseInt(dept.critical_risks) / maxRisks) * (height * 0.6);
                    const closedHeight = (parseInt(dept.closed_risks) / maxRisks) * (height * 0.6);
                    
                    const y = height - 60;
                    
                    // Draw total risks bar
                    ctx.fillStyle = '#007bff';
                    ctx.fillRect(x, y - totalHeight, barWidth * 0.3, totalHeight);
                    
                    // Draw critical risks bar
                    ctx.fillStyle = '#dc3545';
                    ctx.fillRect(x + barWidth * 0.35, y - criticalHeight, barWidth * 0.3, criticalHeight);
                    
                    // Draw closed risks bar
                    ctx.fillStyle = '#28a745';
                    ctx.fillRect(x + barWidth * 0.7, y - closedHeight, barWidth * 0.3, closedHeight);
                    
                    // Draw department name (truncated)
                    ctx.fillStyle = '#495057';
                    ctx.font = '10px Arial';
                    ctx.textAlign = 'center';
                    const truncatedName = dept.department.length > 10 ? dept.department.substring(0, 10) + '...' : dept.department;
                    ctx.fillText(truncatedName, x + barWidth/2, height - 35);
                    
                    // Draw values
                    ctx.font = '9px Arial';
                    ctx.fillText(dept.total_risks, x + barWidth * 0.15, y - totalHeight - 5);
                    ctx.fillText(dept.critical_risks, x + barWidth * 0.5, y - criticalHeight - 5);
                    ctx.fillText(dept.closed_risks, x + barWidth * 0.85, y - closedHeight - 5);
                });
                
                // Draw legend
                const legendItems = [
                    { color: '#007bff', label: 'Total' },
                    { color: '#dc3545', label: 'Critical' },
                    { color: '#28a745', label: 'Closed' }
                ];
                legendItems.forEach((item, index) => {
                    const x = 40 + (index * 60);
                    ctx.fillStyle = item.color;
                    ctx.fillRect(x, 10, 15, 10);
                    ctx.fillStyle = '#495057';
                    ctx.font = '10px Arial';
                    ctx.textAlign = 'left';
                    ctx.fillText(item.label, x + 20, 18);
                });
            }
        }
    }

// Initialize overview charts on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeOverviewCharts();
            initializeAnalyticsCharts();
        });
    </script>

    <script>
        let showingAllRisks = false;
        let allRisksData = <?php echo json_encode($all_risks); ?>;
        let recentRisksData = <?php echo json_encode($recent_risks); ?>;
        
        function toggleViewAll() {
            const tableBody = document.getElementById('risksTableBody');
            const tableTitle = document.getElementById('tableTitle');
            const viewAllBtn = document.getElementById('viewAllBtn');
            
            if (!showingAllRisks) {
                // Show all risks
                displayRisks(allRisksData);
                tableTitle.textContent = `All Risks (${allRisksData.length})`;
                viewAllBtn.textContent = 'Show Recent Only';
                showingAllRisks = true;
            } else {
                // Show recent risks only
                displayRisks(recentRisksData);
                tableTitle.textContent = 'Recent Risks (Last 10)';
                viewAllBtn.textContent = 'View All Risks';
                showingAllRisks = false;
            }
        }
        
        function displayRisks(risks) {
            const tableBody = document.getElementById('risksTableBody');
            tableBody.innerHTML = '';
            
            risks.forEach(risk => {
                const levelColors = {
                    'Critical': '#dc3545',
                    'High': '#fd7e14',
                    'Medium': '#ffc107',
                    'Low': '#28a745'
                };
                const color = levelColors[risk.risk_level] || '#6c757d';
                
                const row = document.createElement('tr');
                row.setAttribute('data-risk-id', risk.id);
                row.setAttribute('data-department', risk.department);
                row.setAttribute('data-risk-level', risk.risk_level);
                row.setAttribute('data-board', risk.to_be_reported_to_board || 'NO');
                row.setAttribute('data-created', risk.created_at);
                row.style.borderBottom = '1px solid #e9ecef';
                
                row.innerHTML = `
                    <td style="padding: 1rem; color: #495057; font-weight: 500;">${risk.id}</td>
                    <td style="padding: 1rem; color: #495057; font-weight: 500;">${risk.risk_name}</td>
                    <td style="padding: 1rem;">
                        <span style="padding: 0.25rem 0.75rem; background: ${color}; color: white; border-radius: 20px; font-size: 0.85rem; font-weight: 500;">
                            ${risk.risk_level}
                        </span>
                    </td>
                    <td style="padding: 1rem;">
                        <span style="padding: 0.25rem 0.75rem; background: ${risk.status == 'Closed' ? '#28a745' : '#17a2b8'}; color: white; border-radius: 20px; font-size: 0.85rem;">
                            ${risk.status}
                        </span>
                    </td>
                    <td style="padding: 1rem; color: #495057;">${risk.department}</td>
                    <td style="padding: 1rem; color: #495057;">${new Date(risk.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}</td>
                    <td style="padding: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" ${(risk.to_be_reported_to_board == 'YES') ? 'checked' : ''} 
                                   onchange="toggleBoardReporting(${risk.id}, this.checked)"
                                   style="accent-color: #E60012;">
                            <span style="font-size: 0.9rem; color: #495057;">Report to Board</span>
                        </label>
                    </td>
                    <!-- Removed the Actions column with View Details and Print buttons from JavaScript function -->
                `;
                
                tableBody.appendChild(row);
            });
        }
        
        function filterByRiskId(searchValue) {
            const rows = document.querySelectorAll('#risksTableBody tr');
            rows.forEach(row => {
                const riskId = row.getAttribute('data-risk-id');
                if (searchValue === '' || riskId.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function filterByDateRange() {
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const rows = document.querySelectorAll('#risksTableBody tr');
            
            rows.forEach(row => {
                const createdDate = row.getAttribute('data-created');
                const riskDate = new Date(createdDate);
                
                let showRow = true;
                
                if (dateFrom && riskDate < new Date(dateFrom)) {
                    showRow = false;
                }
                
                if (dateTo && riskDate > new Date(dateTo)) {
                    showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        function clearAllFilters() {
            document.getElementById('searchRiskId').value = '';
            document.getElementById('departmentFilter').value = 'all';
            document.getElementById('riskLevelFilter').value = 'all';
            document.getElementById('boardFilter').value = 'all';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            
            const rows = document.querySelectorAll('#risksTableBody tr');
            rows.forEach(row => {
                row.style.display = '';
            });
        }
        
        function exportFilteredRisks(format) {
            const visibleRows = Array.from(document.querySelectorAll('#risksTableBody tr')).filter(row => 
                row.style.display !== 'none'
            );
            
            if (visibleRows.length === 0) {
                alert('No risks to export. Please adjust your filters.');
                return;
            }
            
            // Collect data from visible rows
            const exportData = [];
            visibleRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 7) {
                    exportData.push({
                        riskId: cells[0].textContent.trim(),
                        riskName: cells[1].textContent.trim(),
                        riskLevel: cells[2].textContent.trim(),
                        status: cells[3].textContent.trim(),
                        department: cells[4].textContent.trim(),
                        dateCreated: cells[5].textContent.trim(),
                        boardReport: cells[6].querySelector('input[type="checkbox"]')?.checked ? 'YES' : 'NO'
                    });
                }
            });
            
            if (format === 'excel') {
                exportToExcel(exportData);
            } else if (format === 'pdf') {
                exportToPDF(exportData);
            }
        }
        
        function exportToExcel(data) {
            let csvContent = "Risk ID,Risk Name,Risk Level,Status,Department,Date Created,Board Report\n";
            
            data.forEach(row => {
                csvContent += `"${row.riskId}","${row.riskName}","${row.riskLevel}","${row.status}","${row.department}","${row.dateCreated}","${row.boardReport}"\n`;
            });
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `risk_export_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function exportToPDF(data) {
            const printWindow = window.open('', '_blank');
            let tableRows = '';
            
            data.forEach(row => {
                tableRows += `
                    <tr>
                        <td>${row.riskId}</td>
                        <td>${row.riskName}</td>
                        <td>${row.riskLevel}</td>
                        <td>${row.status}</td>
                        <td>${row.department}</td>
                        <td>${row.dateCreated}</td>
                        <td>${row.boardReport}</td>
                    </tr>
                `;
            });
            
            printWindow.document.write(`
                <html>
                <head>
                    <title>Risk Export Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #E60012; padding-bottom: 10px; }
                        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                        th { background-color: #495057; color: white; font-weight: bold; }
                        tr:nth-child(even) { background-color: #f8f9fa; }
                        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Airtel Risk Management System</h1>
                        <h2>Risk Export Report</h2>
                        <p>Generated on: ${new Date().toLocaleDateString()} | Total Risks: ${data.length}</p>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Risk ID</th>
                                <th>Risk Name</th>
                                <th>Risk Level</th>
                                <th>Status</th>
                                <th>Department</th>
                                <th>Date Created</th>
                                <th>Board Report</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                    <div class="footer">
                        <p>This report contains ${data.length} risk records exported from the Airtel Risk Management System.</p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        function viewFullRiskDetails(riskId) {
            fetch(`get_full_risk_details.php?id=${riskId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('riskDetailsContent').innerHTML = data.html;
                    document.getElementById('riskDetailsModal').style.display = 'block';
                } else {
                    alert('Failed to load risk details: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading risk details');
            });
        }
        
        function closeRiskDetailsModal() {
            document.getElementById('riskDetailsModal').style.display = 'none';
        }
        
        function printRiskDetails() {
            const printContent = document.getElementById('riskDetailsContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Risk Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #E60012; padding-bottom: 10px; }
                        .detail-section { margin-bottom: 20px; }
                        .detail-label { font-weight: bold; color: #495057; }
                        .detail-value { margin-left: 10px; }
                        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f8f9fa; }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>Airtel Risk Management System</h1>
                        <h2>Risk Details Report</h2>
                        <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    </div>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        function printSingleRisk(riskId) {
            window.open(`print_risk.php?id=${riskId}`, '_blank');
        }
        
        function printRisksTable() {
            window.print();
        }
        
        // Initialize overview charts on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeOverviewCharts();
            initializeAnalyticsCharts();
        });
    </script>
</body>
</html>
