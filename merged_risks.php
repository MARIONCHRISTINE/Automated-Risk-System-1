<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'User';
$user_email = $_SESSION['email'] ?? 'No Email';
$department = $_SESSION['department'] ?? '';

$database = new Database();
$db = $database->getConnection();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Sorting
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
$allowed_sorts = ['created_at', 'merge_count', 'risk_level', 'risk_name'];
if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'created_at';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Fetch consolidated risks
$consolidated_risks = [];
$original_risks = [];
$total_consolidated = 0;
$stats = [
    'total_consolidated' => 0,
    'this_month' => 0,
    'high_critical' => 0,
    'avg_merge_count' => 0,
    'total_original_merged' => 0,
    'risk_reduction_rate' => 0
];

// Enhanced analytics data
$category_distribution = [];
$risk_level_distribution = [];
$merge_trends = [];
$merge_effectiveness = [];

// For recommendations
$conn = $database->getConnection();

try {
    // Count total consolidated risks
    $count_sql = "SELECT COUNT(*) as total FROM risk_incidents 
                  WHERE risk_id IS NOT NULL 
                  AND LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) > 3
                  AND risk_owner_id = :user_id";
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute(['user_id' => $user_id]);
    $total_consolidated = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Build dynamic ORDER BY clause
    $order_clause = "ORDER BY ";
    if ($sort_by === 'merge_count') {
        $order_clause .= "(LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) - 2) $sort_order";
    } elseif ($sort_by === 'risk_level') {
        $order_clause .= "FIELD(general_residual_risk_level, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW') $sort_order";
    } else {
        $order_clause .= "$sort_by $sort_order";
    }
    
    // Fetch consolidated risks with sorting
    $sql = "SELECT * FROM risk_incidents 
            WHERE risk_id IS NOT NULL 
            AND LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) > 3
            AND risk_owner_id = :user_id
            $order_clause
            LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $consolidated_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all consolidated risks for analytics (no pagination)
    $sql_all = "SELECT * FROM risk_incidents 
                WHERE risk_id IS NOT NULL 
                AND LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) > 3
                AND risk_owner_id = :user_id";
    $stmt_all = $db->prepare($sql_all);
    $stmt_all->execute(['user_id' => $user_id]);
    $all_consolidated = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch original risks
    $sql_original = "SELECT * FROM risk_incidents 
                     WHERE (risk_status IN ('Consolidated', 'Merged'))
                     AND risk_owner_id = :user_id
                     ORDER BY created_at DESC";
    $stmt_original = $db->prepare($sql_original);
    $stmt_original->execute(['user_id' => $user_id]);
    $original_risks = $stmt_original->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate enhanced statistics
    $stats['total_consolidated'] = $total_consolidated;
    $stats['total_original_merged'] = count($original_risks);
    
    $total_merge_count = 0;
    $current_month = date('Y-m');
    
    // Category and risk level distribution
    $category_counts = [];
    $risk_level_counts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'Not Assessed' => 0];
    
    foreach ($all_consolidated as $risk) {
        // Count this month's consolidations
        if (strpos($risk['created_at'], $current_month) === 0) {
            $stats['this_month']++;
        }
        
        // Count high/critical
        $risk_level = $risk['general_residual_risk_level'] ?? $risk['general_inherent_risk_level'] ?? 'Not Assessed';
        if (in_array($risk_level, ['HIGH', 'CRITICAL'])) {
            $stats['high_critical']++;
        }
        
        // Risk level distribution
        $risk_level_counts[$risk_level]++;
        
        // Category distribution
        $categories = json_decode($risk['risk_categories'], true);
        if (is_array($categories)) {
            foreach ($categories as $cat) {
                if (!isset($category_counts[$cat])) {
                    $category_counts[$cat] = 0;
                }
                $category_counts[$cat]++;
            }
        }
        
        // Calculate merge count
        $parts = explode('/', $risk['risk_id']);
        $merge_count = count($parts) - 3;
        $total_merge_count += $merge_count;
    }
    
    // Average merge count
    if ($total_consolidated > 0) {
        $stats['avg_merge_count'] = round($total_merge_count / $total_consolidated, 1);
    }
    
    // Risk reduction rate (original risks vs consolidated)
    if ($stats['total_original_merged'] > 0) {
        $stats['risk_reduction_rate'] = round((($stats['total_original_merged'] - $total_consolidated) / $stats['total_original_merged']) * 100, 1);
    }
    
    // Prepare category distribution for chart
    arsort($category_counts);
    $category_distribution = array_slice($category_counts, 0, 6, true);
    
    // Prepare risk level distribution
    $risk_level_distribution = array_filter($risk_level_counts, function($count) {
        return $count > 0;
    });
    
    // Merge trends (last 6 months)
    $trends_sql = "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as count,
                    SUM(LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) - 2) as total_merged
                   FROM risk_incidents 
                   WHERE risk_id IS NOT NULL 
                   AND LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) > 3
                   AND risk_owner_id = :user_id
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                   GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                   ORDER BY month ASC";
    $trends_stmt = $db->prepare($trends_sql);
    $trends_stmt->execute(['user_id' => $user_id]);
    $merge_trends = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Merge effectiveness (compare inherent vs residual risk levels)
    $effectiveness_sql = "SELECT 
                           general_inherent_risk_level,
                           general_residual_risk_level,
                           COUNT(*) as count
                          FROM risk_incidents 
                          WHERE risk_id IS NOT NULL 
                          AND LENGTH(risk_id) - LENGTH(REPLACE(risk_id, '/', '')) > 3
                          AND risk_owner_id = :user_id
                          AND general_inherent_risk_level IS NOT NULL
                          AND general_residual_risk_level IS NOT NULL
                          GROUP BY general_inherent_risk_level, general_residual_risk_level";
    $effectiveness_stmt = $db->prepare($effectiveness_sql);
    $effectiveness_stmt->execute(['user_id' => $user_id]);
    $merge_effectiveness = $effectiveness_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching merged risks: " . $e->getMessage());
}

// Calculate pagination
$total_pages = ceil($total_consolidated / $per_page);

// Function to extract original risk IDs from merged risk ID
function extractOriginalRiskIds($merged_risk_id) {
    $parts = explode('/', $merged_risk_id);
    if (count($parts) < 4) return [];
    
    $prefix = $parts[0] . '/' . $parts[1] . '/' . $parts[2];
    $sequences = array_slice($parts, 3);
    
    $original_ids = [];
    foreach ($sequences as $seq) {
        $original_ids[] = $prefix . '/' . $seq;
    }
    return $original_ids;
}

// Function to get risk level badge color
function getRiskLevelColor($level) {
    switch (strtoupper($level)) {
        case 'CRITICAL': return '#dc3545';
        case 'HIGH': return '#fd7e14';
        case 'MEDIUM': return '#ffc107';
        case 'LOW': return '#28a745';
        default: return '#6c757d';
    }
}

// Function to get risk level numeric value for comparison
function getRiskLevelValue($level) {
    switch (strtoupper($level)) {
        case 'CRITICAL': return 4;
        case 'HIGH': return 3;
        case 'MEDIUM': return 2;
        case 'LOW': return 1;
        default: return 0;
    }
}

// Function to beautify status
function getBeautifulStatus($status) {
    $status_map = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'Open' => 'Open',
        'Closed' => 'Closed',
        'Consolidated' => 'Consolidated',
        'Merged' => 'Merged'
    ];
    return $status_map[$status] ?? $status;
}

// Function to get status color
function getStatusColor($status) {
    $color_map = [
        'pending' => '#ffc107',
        'in_progress' => '#17a2b8',
        'Open' => '#007bff',
        'Closed' => '#28a745',
        'Consolidated' => '#6c757d',
        'Merged' => '#6f42c1'
    ];
    return $color_map[$status] ?? '#6c757d';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Merged Risks - Enhanced Analytics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
        
        .dashboard {
            min-height: 100vh;
        }
        
        /* Header Styles */
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
            max-width: 1400px;
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
        
        /* Navigation Styles */
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
            max-width: 1400px;
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
        
        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .page-header h2 {
            font-size: 1.8rem;
            color: #1a1a1a;
            font-weight: 700;
        }
        .page-header-actions {
            display: flex;
            gap: 1rem;
        }
        
        /* View Toggle Buttons */
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            background: white;
            padding: 0.25rem;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .view-toggle-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            color: #666;
            cursor: pointer;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .view-toggle-btn.active {
            background: #E60012;
            color: white;
        }
        
        .view-toggle-btn:hover:not(.active) {
            background: #f8f9fa;
        }
        
        /* Statistics Cards - Enhanced with 4 cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
            border-top: 4px solid #E60012;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        
        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0.5rem 0;
            color: #E60012;
            text-align: center;
        }
        
        .stat-card .stat-title {
            font-weight: 700;
            color: #1a1a1a;
            font-size: 1rem;
            margin-bottom: 0.25rem;
            text-align: center;
        }
        
        .stat-card .stat-subtitle {
            color: #666;
            font-size: 0.85rem;
            text-align: center;
        }
        
        .stat-card .stat-icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 2rem;
            color: rgba(230, 0, 18, 0.1);
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .chart-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .chart-section h5 {
            margin-bottom: 1rem;
            color: #1a1a1a;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .chart-section.full-width {
            grid-column: 1 / -1;
        }
        
        /* Advanced Filters */
        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .filters-header h5 {
            font-weight: 600;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        /* Sorting Controls */
        .sorting-controls {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .sorting-controls label {
            font-weight: 600;
            color: #333;
            margin-right: 0.5rem;
        }
        
        .sorting-controls select {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .results-info {
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #E60012;
            color: white;
        }
        
        .btn-primary:hover {
            background: #c00010;
        }
        
        .btn-secondary {
            background: white;
            color: #333;
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background: #f8f9fa;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }
        
        /* Consolidated Risk Cards */
        .consolidated-risk-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #E60012;
            transition: all 0.3s ease;
        }
        
        .consolidated-risk-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateX(4px);
        }
        
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .risk-header h4 {
            margin: 0;
            color: #1a1a1a;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .risk-id-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
        }
        
        .risk-id-badge {
            background: linear-gradient(135deg, #E60012 0%, #c00010 100%);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .toggle-icon {
            color: #E60012;
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }
        
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }
        
        /* Impact Comparison Badge */
        .impact-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        
        .impact-badge.positive {
            background: #d4edda;
            color: #155724;
        }
        
        .impact-badge.negative {
            background: #f8d7da;
            color: #721c24;
        }
        
        .impact-badge.neutral {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .risk-meta {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e0e0e0;
        }
        
        .risk-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        .risk-level-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            color: white;
        }
        
        /* Original Risks Section */
        .original-risks-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px dashed #e0e0e0;
            display: none;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .original-risks-section.show {
            display: block;
        }
        
        .original-risks-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            color: #1a1a1a;
            font-weight: 600;
        }
        
        .original-risk-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 3px solid #6c757d;
            transition: all 0.3s ease;
        }
        
        .original-risk-item:hover {
            background: #e9ecef;
            transform: translateX(4px);
        }
        
        .original-risk-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .original-risk-id {
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .original-risk-name {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        
        .original-risk-meta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.85rem;
        }
        
        /* Timeline View */
        .timeline-view {
            display: none;
        }
        
        .timeline-view.active {
            display: block;
        }
        
        .timeline-container {
            position: relative;
            padding: 2rem 0;
        }
        
        .timeline-line {
            position: absolute;
            left: 50%;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #E60012;
            transform: translateX(-50%);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 3rem;
            display: flex;
            align-items: center;
        }
        
        .timeline-item:nth-child(odd) {
            flex-direction: row;
        }
        
        .timeline-item:nth-child(even) {
            flex-direction: row-reverse;
        }
        
        .timeline-content {
            width: 45%;
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .timeline-dot {
            width: 20px;
            height: 20px;
            background: #E60012;
            border-radius: 50%;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid white;
            box-shadow: 0 0 0 2px #E60012;
        }
        
        .timeline-date {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        /* Comparison View */
        .comparison-view {
            display: none;
        }
        
        .comparison-view.active {
            display: block;
        }
        
        .comparison-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .comparison-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 2rem;
            align-items: center;
        }
        
        .comparison-side {
            text-align: center;
        }
        
        .comparison-side h5 {
            margin-bottom: 1rem;
            color: #1a1a1a;
        }
        
        .comparison-arrow {
            font-size: 2rem;
            color: #E60012;
        }
        
        .comparison-metrics {
            display: grid;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .comparison-metric {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
        }
        
        .comparison-metric-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 0.25rem;
        }
        
        .comparison-metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a1a1a;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }
        
        .pagination a,
        .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: #E60012;
            color: white;
            border-color: #E60012;
        }
        
        .pagination .active {
            background: #E60012;
            color: white;
            border-color: #E60012;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #666;
            margin-bottom: 1.5rem;
        }
        
        /* Loading State */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #E60012;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding-top: 180px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav-menu {
                flex-wrap: wrap;
            }
            
            .filter-row {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .timeline-item {
                flex-direction: column !important;
            }
            
            .timeline-content {
                width: 100%;
            }
            
            .timeline-line {
                left: 20px;
            }
            
            .timeline-dot {
                left: 20px;
            }
        }
        
        /* Print Styles */
        @media print {
            .header, .nav, .filters-section, .btn, .pagination, .view-toggle {
                display: none !important;
            }
            
            body {
                padding-top: 0;
            }
            
            .consolidated-risk-card {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
     Loading Overlay 
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <div class="dashboard">
         Header 
        <header class="header">
            <div class="header-content">
                <div class="header-left">
                    <div class="logo-circle">
                        <img src="image.png" alt="Airtel Logo" />
                    </div>
                    <div class="header-titles">
                        <h1 class="main-title">Airtel Risk Register System</h1>
                        <p class="sub-title">Enhanced Merged Risks Analytics</p>
                    </div>
                </div>
                <div class="header-right">
                    <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'R'; ?></div>
                    <div class="user-details">
                        <div class="user-email"><?php echo htmlspecialchars($user_email); ?></div>
                        <div class="user-role">Risk Owner • <?php echo htmlspecialchars($department); ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        
         Navigation 
        <nav class="nav">
            <div class="nav-content">
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="report_risk.php">
                            <i class="fas fa-edit"></i> Report Risk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php?tab=my-reports">
                            <i class="fas fa-ellipsis-h"></i> My Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="merged_risks.php" class="active">
                            <i class="fas fa-code-branch"></i> Merged Risks
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="risk_procedures.php">
                            <i class="fas fa-book"></i> Procedures
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#">
                            <i class="fas fa-bell"></i> Notifications
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        
        <main class="main-content">
             Page Header with View Toggle 
            <div class="page-header">
                <h2><i class="fas fa-code-branch"></i> Merged Risks Analytics</h2>
                <div class="page-header-actions">
                    <div class="view-toggle">
                        <button class="view-toggle-btn active" onclick="switchView('list')">
                            <i class="fas fa-list"></i> List View
                        </button>
                        <button class="view-toggle-btn" onclick="switchView('timeline')">
                            <i class="fas fa-stream"></i> Timeline
                        </button>
                        <button class="view-toggle-btn" onclick="switchView('comparison')">
                            <i class="fas fa-balance-scale"></i> Comparison
                        </button>
                    </div>
                </div>
            </div>

             Enhanced Statistics - 4 cards 
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-code-branch stat-icon"></i>
                    <h3><?php echo $stats['total_consolidated']; ?></h3>
                    <p class="stat-title">Merged Risks</p>
                    <p class="stat-subtitle">Total new risks created from merging</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-link stat-icon"></i>
                    <h3><?php echo $stats['total_original_merged']; ?></h3>
                    <p class="stat-title">Consolidated Risks</p>
                    <p class="stat-subtitle">Original risks that were merged</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle stat-icon"></i>
                    <h3><?php echo $stats['high_critical']; ?></h3>
                    <p class="stat-title">High/Critical</p>
                    <p class="stat-subtitle">Priority risks</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-percentage stat-icon"></i>
                    <h3><?php echo $stats['risk_reduction_rate']; ?>%</h3>
                    <p class="stat-title">Reduction Rate</p>
                    <p class="stat-subtitle">Risk consolidation efficiency</p>
                </div>
            </div>

             Enhanced Charts Grid 
            <div class="charts-grid">
                 Merge Trends Chart 
                <?php if (!empty($merge_trends)): ?>
                <div class="chart-section full-width">
                    <h5><i class="fas fa-chart-line"></i> Merge Trends (Last 6 Months)</h5>
                    <canvas id="mergeTrendsChart" height="80"></canvas>
                </div>
                <?php endif; ?>
            </div>

            <!-- NEW FEATURE: Merge Recommendations Engine -->
            <div class="filters-section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                <div class="filters-header" style="border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 1rem;">
                    <h5 style="color: white;"><i class="fas fa-lightbulb"></i> Merge Recommendations</h5>
                    <button class="btn btn-light btn-sm" onclick="refreshRecommendations()" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                <div style="margin-top: 1rem;">
                    <p style="margin-bottom: 1rem; opacity: 0.95;">
                        <i class="fas fa-info-circle"></i> Based on similarity analysis, these risks could be consolidated to reduce duplication:
                    </p>
                    <div id="recommendationsList" style="display: grid; gap: 1rem;">
                        <?php
                        // Fetch potential risks to merge (similar category, owner, or description)
                        $recommendations_query = "
                            SELECT r1.risk_id as risk1_id, r1.risk_name as risk1_name, r1.risk_categories as risk1_cat, r1.general_inherent_risk_level as risk1_level,
                                   r2.risk_id as risk2_id, r2.risk_name as risk2_name, r2.risk_categories as risk2_cat, r2.general_inherent_risk_level as risk2_level
                            FROM risk_incidents r1
                            JOIN risk_incidents r2 ON r1.id < r2.id
                            WHERE r1.risk_id NOT LIKE '%/%/%/%'
                              AND r2.risk_id NOT LIKE '%/%/%/%'
                              AND r1.risk_status IN ('Open', 'in_progress')
                              AND r2.risk_status IN ('Open', 'in_progress')
                              AND r1.risk_owner_id = :user_id AND r2.risk_owner_id = :user_id -- Only show recommendations for the current user
                              AND (
                                  r1.risk_categories = r2.risk_categories
                                  OR r1.risk_name LIKE CONCAT('%', SUBSTRING(r2.risk_name, 1, 15), '%')
                              )
                            LIMIT 5
                        ";
                        $recommendations_stmt = $conn->prepare($recommendations_query);
                        $recommendations_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                        $recommendations_stmt->execute();
                        $recommendations_result = $recommendations_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($recommendations_result)):
                            foreach ($recommendations_result as $rec):
                                $similarity_score = 0;
                                $reasons = [];
                                
                                $rec1_cats = json_decode($rec['risk1_cat'], true);
                                $rec2_cats = json_decode($rec['risk2_cat'], true);

                                // Check for common categories
                                $common_categories = [];
                                if(is_array($rec1_cats) && is_array($rec2_cats)) {
                                    $common_categories = array_intersect($rec1_cats, $rec2_cats);
                                }

                                if (!empty($common_categories)) {
                                    $similarity_score += 40;
                                    $reasons[] = "Common category";
                                }
                                if ($rec['risk1_level'] === $rec['risk2_level']) {
                                    $similarity_score += 30;
                                    $reasons[] = "Same risk level";
                                }
                                // Using Levenshtein distance for name similarity for better accuracy
                                $lev_distance = levenshtein(strtolower($rec['risk1_name']), strtolower($rec['risk2_name']));
                                $max_len = max(strlen($rec['risk1_name']), strlen($rec['risk2_name']));
                                $similarity_percentage = (1 - ($lev_distance / $max_len)) * 100;

                                if ($similarity_percentage > 50) { // Threshold for similarity
                                    $similarity_score += 30;
                                    $reasons[] = "Similar names";
                                }
                                
                                $score_color = $similarity_score >= 70 ? '#4ade80' : ($similarity_score >= 50 ? '#fbbf24' : '#fb923c');
                        ?>
                        <div style="background: rgba(255,255,255,0.15); padding: 1rem; border-radius: 8px; backdrop-filter: blur(10px);">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                                <div style="flex: 1;">
                                    <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                                        <span style="background: rgba(255,255,255,0.25); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
                                            <?php echo htmlspecialchars($rec['risk1_id']); ?>
                                        </span>
                                        <i class="fas fa-plus" style="font-size: 0.75rem; opacity: 0.7;"></i>
                                        <span style="background: rgba(255,255,255,0.25); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;">
                                            <?php echo htmlspecialchars($rec['risk2_id']); ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 0.9rem; opacity: 0.9; line-height: 1.4;">
                                        <strong><?php echo htmlspecialchars(substr($rec['risk1_name'], 0, 40)) . '...'; ?></strong>
                                        <br>
                                        <strong><?php echo htmlspecialchars(substr($rec['risk2_name'], 0, 40)) . '...'; ?></strong>
                                    </div>
                                    <div style="margin-top: 0.5rem; font-size: 0.8rem; opacity: 0.8;">
                                        <i class="fas fa-check-circle"></i> <?php echo implode(', ', $reasons); ?>
                                    </div>
                                </div>
                                <div style="text-align: center; margin-left: 1rem;">
                                    <div style="background: <?php echo $score_color; ?>; color: #1a1a1a; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 700; font-size: 1.1rem; margin-bottom: 0.5rem;">
                                        <?php echo round($similarity_score); ?>%
                                    </div>
                                    <button class="btn btn-light btn-sm" onclick="window.location.href='merge_risks.php?risk1=<?php echo urlencode($rec['risk1_id']); ?>&risk2=<?php echo urlencode($rec['risk2_id']); ?>'" style="background: white; color: #667eea; border: none; font-weight: 600;">
                                        <i class="fas fa-code-branch"></i> Merge
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <div style="text-align: center; padding: 2rem; opacity: 0.8;">
                            <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                            <p style="font-size: 1.1rem; margin: 0;">No merge recommendations at this time. Your risk register is well-organized!</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

             Advanced Filters 
            <div class="filters-section">
                <div class="filters-header">
                    <h5><i class="fas fa-filter"></i> Advanced Filters</h5>
                    <button class="btn btn-secondary btn-sm" onclick="toggleFilters()">
                        <i class="fas fa-chevron-down" id="filterToggleIcon"></i>
                    </button>
                </div>
                <div id="filterContent">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="searchInput" placeholder="Search by risk ID or name...">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tags"></i> Category</label>
                            <select id="categoryFilter">
                                <option value="">All Categories</option>
                                <?php
                                // Dynamically generate category options from existing data if available
                                $all_categories_from_data = [];
                                foreach($all_consolidated as $risk) {
                                    $categories = json_decode($risk['risk_categories'], true);
                                    if(is_array($categories)) {
                                        foreach($categories as $cat) {
                                            if (!in_array($cat, $all_categories_from_data)) {
                                                $all_categories_from_data[] = $cat;
                                            }
                                        }
                                    }
                                }
                                sort($all_categories_from_data);
                                foreach($all_categories_from_data as $cat) {
                                    echo "<option value='" . htmlspecialchars($cat) . "'>" . htmlspecialchars($cat) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-exclamation-triangle"></i> Risk Level</label>
                            <select id="riskLevelFilter">
                                <option value="">All Levels</option>
                                <option value="CRITICAL">Critical</option>
                                <option value="HIGH">High</option>
                                <option value="MEDIUM">Medium</option>
                                <option value="LOW">Low</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar-alt"></i> Month</label>
                            <select id="monthFilter">
                                <option value="">All Months</option>
                                <?php
                                // Generate months for the last year for better filtering
                                for ($i = 0; $i < 12; $i++) {
                                    $month = date('Y-m', strtotime("-$i months"));
                                    echo "<option value='$month'>" . date('F Y', strtotime("-$i months")) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-link"></i> Merge Count</label>
                            <select id="mergeCountFilter">
                                <option value="">All</option>
                                <option value="2-3">2-3 risks</option>
                                <option value="4-5">4-5 risks</option>
                                <option value="6+">6+ risks</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-info-circle"></i> Status</label>
                            <select id="statusFilter">
                                <option value="">All Status</option>
                                <option value="Open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button class="btn btn-secondary" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Clear Filters
                        </button>
                        <button class="btn btn-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                        <button class="btn btn-info" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn btn-primary" onclick="printReport()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
            </div>

             Sorting Controls 
            <div class="sorting-controls">
                <div>
                    <label>Sort by:</label>
                    <select id="sortBy" onchange="updateSort()">
                        <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Created</option>
                        <option value="merge_count" <?php echo $sort_by === 'merge_count' ? 'selected' : ''; ?>>Merge Count</option>
                        <option value="risk_level" <?php echo $sort_by === 'risk_level' ? 'selected' : ''; ?>>Risk Level</option>
                        <option value="risk_name" <?php echo $sort_by === 'risk_name' ? 'selected' : ''; ?>>Risk Name</option>
                    </select>
                    <select id="sortOrder" onchange="updateSort()">
                        <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    </select>
                </div>
                <div class="results-info">
                    Showing <strong id="visibleCount"><?php echo count($consolidated_risks); ?></strong> of <strong><?php echo $total_consolidated; ?></strong> consolidated risks
                </div>
            </div>

             List View 
            <div id="listView" class="list-view active">
                <?php if (empty($consolidated_risks)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-code-branch"></i></div>
                        <h3>No Merged Risks Found</h3>
                        <p>You haven't consolidated any risks yet. Merge multiple related risks from your dashboard to see them here.</p>
                        <a href="risk_owner_dashboard.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Go to Dashboard to Merge Risks
                        </a>
                    </div>
                <?php else: ?>
                    <div id="consolidatedRisksList">
                        <?php foreach ($consolidated_risks as $risk): 
                            $risk_level = $risk['general_residual_risk_level'] ?? $risk['general_inherent_risk_level'] ?? 'Not Assessed';
                            $inherent_level = $risk['general_inherent_risk_level'] ?? 'Not Assessed';
                            $risk_color = getRiskLevelColor($risk_level);
                            $original_ids = extractOriginalRiskIds($risk['risk_id']);
                            $status_color = getStatusColor($risk['risk_status']);
                            $categories = json_decode($risk['risk_categories'], true);
                            $category_str = is_array($categories) ? implode(', ', $categories) : $risk['risk_categories'];
                            
                            // Calculate impact (inherent vs residual)
                            $inherent_value = getRiskLevelValue($inherent_level);
                            $residual_value = getRiskLevelValue($risk_level);
                            $impact_diff = $inherent_value - $residual_value;
                            $impact_class = $impact_diff > 0 ? 'positive' : ($impact_diff < 0 ? 'negative' : 'neutral');
                            $impact_text = $impact_diff > 0 ? "Risk Reduced" : ($impact_diff < 0 ? "Risk Increased" : "No Change");
                            $impact_icon = $impact_diff > 0 ? 'fa-arrow-down' : ($impact_diff < 0 ? 'fa-arrow-up' : 'fa-minus');
                        ?>
                        <div class="consolidated-risk-card" 
                             data-risk-id="<?php echo htmlspecialchars($risk['risk_id']); ?>" 
                             data-risk-level="<?php echo htmlspecialchars($risk_level); ?>"
                             data-created="<?php echo date('Y-m', strtotime($risk['created_at'])); ?>"
                             data-category="<?php echo htmlspecialchars($category_str); ?>"
                             data-merge-count="<?php echo count($original_ids); ?>"
                             data-status="<?php echo htmlspecialchars($risk['risk_status']); ?>"
                             data-timestamp="<?php echo strtotime($risk['created_at']); ?>">
                            <div class="risk-header">
                                <div style="flex: 1;">
                                    <div class="risk-id-container" onclick="toggleOriginalRisks(this)">
                                        <div class="risk-id-badge">
                                            <i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($risk['risk_id']); ?>
                                        </div>
                                        <span class="toggle-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                    <h4><?php echo htmlspecialchars($risk['risk_name']); ?></h4>
                                    <p style="color: #666; margin: 0.5rem 0 0 0; font-size: 0.95rem;">
                                        <?php echo htmlspecialchars(substr($risk['risk_description'], 0, 150)) . '...'; ?>
                                    </p>
                                    <?php if ($inherent_level !== 'Not Assessed' && $risk_level !== 'Not Assessed'): ?>
                                    <div class="impact-badge <?php echo $impact_class; ?>">
                                        <i class="fas <?php echo $impact_icon; ?>"></i>
                                        <?php echo $impact_text; ?> (<?php echo $inherent_level; ?> → <?php echo $risk_level; ?>)
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <button class="btn btn-primary" onclick="event.stopPropagation(); window.location.href='view_risk.php?id=<?php echo $risk['id']; ?>'">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </div>
                            </div>
                            
                            <div class="risk-meta">
                                <div class="risk-meta-item">
                                    <i class="fas fa-link"></i>
                                    <span><strong><?php echo count($original_ids); ?></strong> risks merged</span>
                                </div>
                                <div class="risk-meta-item">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span class="risk-level-badge" style="background-color: <?php echo $risk_color; ?>;">
                                        <?php echo htmlspecialchars($risk_level); ?>
                                    </span>
                                </div>
                                <div class="risk-meta-item">
                                    <i class="fas fa-tags"></i>
                                    <span><?php echo htmlspecialchars(is_array($categories) ? implode(', ', array_slice($categories, 0, 2)) : $risk['risk_categories']); ?></span>
                                </div>
                                <div class="risk-meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></span>
                                </div>
                                <div class="risk-meta-item">
                                    <i class="fas fa-info-circle"></i>
                                    <span class="status-badge" style="background-color: <?php echo $status_color; ?>;">
                                        <?php echo getBeautifulStatus($risk['risk_status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                             Original Risks Section 
                            <div class="original-risks-section">
                                <div class="original-risks-header">
                                    <i class="fas fa-sitemap"></i>
                                    <span>Original Risks Merged (<?php echo count($original_ids); ?>)</span>
                                </div>
                                <?php foreach ($original_ids as $original_id): 
                                    $original_risk = null;
                                    foreach ($original_risks as $or) {
                                        if ($or['risk_id'] === $original_id) {
                                            $original_risk = $or;
                                            break;
                                        }
                                    }
                                    
                                    if ($original_risk):
                                        $orig_level = $original_risk['general_residual_risk_level'] ?? $original_risk['general_inherent_risk_level'] ?? 'Not Assessed';
                                        $orig_color = getRiskLevelColor($orig_level);
                                        $orig_status_color = getStatusColor($original_risk['risk_status']);
                                ?>
                                <div class="original-risk-item">
                                    <div class="original-risk-header">
                                        <span class="original-risk-id">
                                            <i class="fas fa-link"></i> <?php echo htmlspecialchars($original_id); ?>
                                        </span>
                                        <button class="btn btn-secondary btn-sm" onclick="window.location.href='view_risk.php?id=<?php echo $original_risk['id']; ?>'">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </div>
                                    <div class="original-risk-name">
                                        <?php echo htmlspecialchars($original_risk['risk_name']); ?>
                                    </div>
                                    <div class="original-risk-meta">
                                        <span>
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <span class="risk-level-badge" style="background-color: <?php echo $orig_color; ?>; font-size: 0.75rem; padding: 0.2rem 0.6rem;">
                                                <?php echo htmlspecialchars($orig_level); ?>
                                            </span>
                                        </span>
                                        <span>
                                            <i class="fas fa-info-circle"></i>
                                            <span class="status-badge" style="background-color: <?php echo $orig_status_color; ?>; font-size: 0.75rem; padding: 0.2rem 0.6rem;">
                                                <?php echo getBeautifulStatus($original_risk['risk_status']); ?>
                                            </span>
                                        </span>
                                        <span>
                                            <i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($original_risk['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="original-risk-item" style="border-left-color: #ccc;">
                                    <div class="original-risk-id" style="color: #999;">
                                        <i class="fas fa-unlink"></i> <?php echo htmlspecialchars($original_id); ?>
                                    </div>
                                    <div class="original-risk-name" style="color: #999;">
                                        Risk details not found (may have been deleted)
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                     Pagination 
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>"><i class="fas fa-chevron-left"></i> Previous</a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?>">Next <i class="fas fa-chevron-right"></i></a>
                        <?php else: ?>
                            <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

             Timeline View 
            <div id="timelineView" class="timeline-view">
                <div class="timeline-container">
                    <div class="timeline-line"></div>
                    <?php foreach ($consolidated_risks as $index => $risk): 
                        $risk_level = $risk['general_residual_risk_level'] ?? $risk['general_inherent_risk_level'] ?? 'Not Assessed';
                        $risk_color = getRiskLevelColor($risk_level);
                        $original_ids = extractOriginalRiskIds($risk['risk_id']);
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-content">
                            <div class="timeline-date">
                                <i class="fas fa-calendar-alt"></i> <?php echo date('F d, Y', strtotime($risk['created_at'])); ?>
                            </div>
                            <h4><?php echo htmlspecialchars($risk['risk_name']); ?></h4>
                            <p style="color: #666; font-size: 0.9rem; margin: 0.5rem 0;">
                                <strong><?php echo count($original_ids); ?> risks</strong> merged into 
                                <span class="risk-level-badge" style="background-color: <?php echo $risk_color; ?>; font-size: 0.75rem; padding: 0.2rem 0.6rem;">
                                    <?php echo $risk_level; ?>
                                </span>
                            </p>
                            <button class="btn btn-primary btn-sm" onclick="window.location.href='view_risk.php?id=<?php echo $risk['id']; ?>'">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                        </div>
                        <div class="timeline-dot"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

             Comparison View 
            <div id="comparisonView" class="comparison-view">
                <?php foreach ($consolidated_risks as $risk): 
                    $inherent_level = $risk['general_inherent_risk_level'] ?? 'Not Assessed';
                    $residual_level = $risk['general_residual_risk_level'] ?? $risk['general_inherent_risk_level'] ?? 'Not Assessed';
                    $original_ids = extractOriginalRiskIds($risk['risk_id']);
                    
                    // Calculate average scores from original risks
                    $total_inherent = 0;
                    $total_residual = 0;
                    $count = 0;
                    foreach ($original_ids as $original_id) {
                        foreach ($original_risks as $or) {
                            if ($or['risk_id'] === $original_id) {
                                $total_inherent += getRiskLevelValue($or['general_inherent_risk_level'] ?? 'Not Assessed');
                                $total_residual += getRiskLevelValue($or['general_residual_risk_level'] ?? $or['general_inherent_risk_level'] ?? 'Not Assessed');
                                $count++;
                            }
                        }
                    }
                    $avg_original_score = $count > 0 ? round($total_inherent / $count, 1) : 0;
                    $consolidated_score = getRiskLevelValue($residual_level);
                ?>
                <div class="comparison-card">
                    <h4 style="margin-bottom: 1.5rem;"><?php echo htmlspecialchars($risk['risk_name']); ?></h4>
                    <div class="comparison-grid">
                        <div class="comparison-side">
                            <h5>Before Merge</h5>
                            <div class="comparison-metrics">
                                <div class="comparison-metric">
                                    <div class="comparison-metric-label">Total Risks</div>
                                    <div class="comparison-metric-value"><?php echo count($original_ids); ?></div>
                                </div>
                                <div class="comparison-metric">
                                    <div class="comparison-metric-label">Avg Risk Score</div>
                                    <div class="comparison-metric-value"><?php echo $avg_original_score; ?></div>
                                </div>
                                <div class="comparison-metric">
                                    <div class="comparison-metric-label">Status</div>
                                    <div class="comparison-metric-value" style="font-size: 1rem;">Multiple</div>
                                </div>
                            </div>
                        </div>
                        <div class="comparison-arrow">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="comparison-side">
                            <h5>After Merge</h5>
                            <div class="comparison-metrics">
                                <div class="comparison-metric">
                                    <div class="comparison-metric-label">Consolidated</div>
                                    <div class="comparison-metric-value">1</div>
                                </div>
                                <div class="comparison-metric">
                                    <div class="comparison-metric-label">Risk Score</div>
                                    <div class="comparison-metric-value"><?php echo $consolidated_score; ?></div>
                                </div>
                                <div class="comparison-metric">
                                    <div class="comparison-metric-label">Risk Level</div>
                                    <div class="comparison-metric-value" style="font-size: 1rem;"><?php echo $residual_level; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 1rem;">
                        <button class="btn btn-primary btn-sm" onclick="window.location.href='view_risk.php?id=<?php echo $risk['id']; ?>'">
                            <i class="fas fa-eye"></i> View Full Details
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script>
        // View Switching
        function switchView(view) {
            // Update buttons
            document.querySelectorAll('.view-toggle-btn').forEach(btn => btn.classList.remove('active'));
            event.target.closest('.view-toggle-btn').classList.add('active');
            
            // Update views
            document.getElementById('listView').classList.remove('active');
            document.getElementById('timelineView').classList.remove('active');
            document.getElementById('comparisonView').classList.remove('active');
            
            if (view === 'list') {
                document.getElementById('listView').classList.add('active');
            } else if (view === 'timeline') {
                document.getElementById('timelineView').classList.add('active');
            } else if (view === 'comparison') {
                document.getElementById('comparisonView').classList.add('active');
            }
        }
        
        // Toggle original risks section
        function toggleOriginalRisks(element) {
            const card = element.closest('.consolidated-risk-card');
            const originalSection = card.querySelector('.original-risks-section');
            const icon = element.querySelector('.toggle-icon i');
            
            originalSection.classList.toggle('show');
            
            if (originalSection.classList.contains('show')) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
        
        // Toggle filters
        function toggleFilters() {
            const content = document.getElementById('filterContent');
            const icon = document.getElementById('filterToggleIcon');
            
            if (content.style.display === 'none' || content.style.display === '') { // Check for initial state too
                content.style.display = 'block';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                content.style.display = 'none';
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
        
        // Filter functionality
        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const riskLevel = document.getElementById('riskLevelFilter').value;
            const month = document.getElementById('monthFilter').value;
            const category = document.getElementById('categoryFilter').value;
            const mergeCount = document.getElementById('mergeCountFilter').value;
            const status = document.getElementById('statusFilter').value;
            
            const cards = document.querySelectorAll('.consolidated-risk-card');
            let visibleCount = 0;
            
            cards.forEach(card => {
                const riskId = card.dataset.riskId.toLowerCase();
                const cardRiskLevel = card.dataset.riskLevel;
                const cardMonth = card.dataset.created;
                const cardCategory = card.dataset.category.toLowerCase();
                const cardMergeCount = parseInt(card.dataset.mergeCount);
                const cardStatus = card.dataset.status;
                const riskName = card.querySelector('h4').textContent.toLowerCase();
                
                const matchesSearch = riskId.includes(searchTerm) || riskName.includes(searchTerm);
                const matchesLevel = !riskLevel || cardRiskLevel === riskLevel;
                const matchesMonth = !month || cardMonth === month;
                const matchesCategory = !category || cardCategory.includes(category.toLowerCase());
                const matchesStatus = !status || cardStatus === status;
                
                let matchesMergeCount = true;
                if (mergeCount === '2-3') matchesMergeCount = cardMergeCount >= 2 && cardMergeCount <= 3;
                else if (mergeCount === '4-5') matchesMergeCount = cardMergeCount >= 4 && cardMergeCount <= 5;
                else if (mergeCount === '6+') matchesMergeCount = cardMergeCount >= 6;
                
                if (matchesSearch && matchesLevel && matchesMonth && matchesCategory && matchesMergeCount && matchesStatus) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            document.getElementById('visibleCount').textContent = visibleCount;
        }
        
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('riskLevelFilter').value = '';
            document.getElementById('monthFilter').value = '';
            document.getElementById('categoryFilter').value = '';
            document.getElementById('mergeCountFilter').value = '';
            document.getElementById('statusFilter').value = '';
            applyFilters();
        }
        
        // Sorting
        function updateSort() {
            const sortBy = document.getElementById('sortBy').value;
            const sortOrder = document.getElementById('sortOrder').value;
            window.location.href = `?page=1&sort=${sortBy}&order=${sortOrder}`;
        }
        
        // Export to Excel
        function exportToExcel() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.classList.add('show');
            
            setTimeout(() => {
                const data = [];
                // Filtered cards first, then export
                const visibleCards = Array.from(document.querySelectorAll('.consolidated-risk-card')).filter(card => card.style.display !== 'none');
                
                visibleCards.forEach(card => {
                    const riskId = card.dataset.riskId;
                    const riskName = card.querySelector('h4').textContent;
                    const riskLevel = card.dataset.riskLevel;
                    const mergeCount = card.dataset.mergeCount;
                    const category = card.dataset.category;
                    const status = card.dataset.status;
                    const created = card.dataset.created;
                    
                    data.push({
                        'Risk ID': riskId,
                        'Risk Name': riskName,
                        'Risk Level': riskLevel,
                        'Risks Merged': mergeCount,
                        'Category': category,
                        'Status': status,
                        'Created': created
                    });
                });
                
                if (data.length === 0) {
                    alert("No data to export. Please apply filters to select visible risks.");
                    loadingOverlay.classList.remove('show');
                    return;
                }

                const ws = XLSX.utils.json_to_sheet(data);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Merged Risks');
                XLSX.writeFile(wb, 'Merged_Risks_Enhanced_' + new Date().toISOString().split('T')[0] + '.xlsx');
                
                loadingOverlay.classList.remove('show');
            }, 500);
        }
        
        // Export to PDF
        function exportToPDF() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.classList.add('show');
            
            setTimeout(() => {
                // Temporarily hide elements not meant for print
                const header = document.querySelector('.header');
                const nav = document.querySelector('.nav');
                const filtersSection = document.querySelector('.filters-section');
                const sortingControls = document.querySelector('.sorting-controls');
                const pagination = document.querySelector('.pagination');
                const viewToggle = document.querySelector('.view-toggle');
                const exportButtons = filtersSection.querySelector('.filter-actions');

                header.style.display = 'none';
                nav.style.display = 'none';
                filtersSection.style.display = 'none';
                sortingControls.style.display = 'none';
                pagination.style.display = 'none';
                viewToggle.style.display = 'none';
                exportButtons.style.display = 'none'; // Hide buttons within filters section

                document.body.style.paddingTop = '0'; // Remove top padding for printing

                window.print();

                // Restore styles
                header.style.display = '';
                nav.style.display = '';
                filtersSection.style.display = '';
                sortingControls.style.display = '';
                pagination.style.display = '';
                viewToggle.style.display = '';
                exportButtons.style.display = '';
                document.body.style.paddingTop = '150px'; // Restore original padding

                loadingOverlay.classList.remove('show');
            }, 500);
        }
        
        // Print Report
        function printReport() {
            window.print();
        }

        // Refresh recommendations function
        function refreshRecommendations() {
            location.reload();
        }
        
        // Initialize filters on load
        document.addEventListener('DOMContentLoaded', () => {
            // Ensure filters are initially visible if they exist and are meant to be shown
            const filterContent = document.getElementById('filterContent');
            const filterToggleIcon = document.getElementById('filterToggleIcon');
            if (filterContent && filterToggleIcon) {
                // Assume filters are visible by default if not explicitly hidden
                if (filterContent.style.display === 'none') {
                    toggleFilters(); // Show them if they were hidden by default
                }
            }
            applyFilters(); // Apply filters on load to show correct counts and visibility
        });
        
        // Add event listeners
        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('riskLevelFilter').addEventListener('change', applyFilters);
        document.getElementById('monthFilter').addEventListener('change', applyFilters);
        document.getElementById('categoryFilter').addEventListener('change', applyFilters);
        document.getElementById('mergeCountFilter').addEventListener('change', applyFilters);
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        
        // Initialize charts
        <?php if (!empty($merge_trends)): ?>
        const mergeTrendsCtx = document.getElementById('mergeTrendsChart').getContext('2d');
        new Chart(mergeTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($merge_trends, 'month')); ?>,
                datasets: [{
                    label: 'Merged Risks Created',
                    data: <?php echo json_encode(array_column($merge_trends, 'count')); ?>,
                    backgroundColor: 'rgba(230, 0, 18, 0.1)',
                    borderColor: '#E60012',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#E60012',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }, {
                    label: 'Original Risks Consolidated',
                    data: <?php echo json_encode(array_column($merge_trends, 'total_merged')); ?>,
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    borderColor: '#17a2b8',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#17a2b8',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    },
                    datalabels: {
                        display: true,
                        align: 'top',
                        backgroundColor: function(context) {
                            return context.datasetIndex === 0 ? '#E60012' : '#17a2b8';
                        },
                        borderRadius: 4,
                        color: 'white',
                        font: {
                            weight: 'bold',
                            size: 11
                        },
                        padding: 4,
                        formatter: Math.round
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
    </script>
</body>
</html>
