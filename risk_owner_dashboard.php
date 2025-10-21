<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';
include_once 'includes/shared_notifications.php';

if (isset($_POST['store_merge_risks'])) {
    // Store the selected risk IDs for merging in the session
    $_SESSION['merge_risk_ids'] = json_decode($_POST['risk_ids'], true);
    echo json_encode(['success' => true]);
    exit();
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $export_type = $_GET['type'] ?? 'department';
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="risks_export_' . date('Y-m-d_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Build query based on export type
    $database = new Database();
    $db = $database->getConnection();
    
    $user_dept_query = "SELECT department FROM users WHERE id = :user_id";
    $user_dept_stmt = $db->prepare($user_dept_query);
    $user_dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $user_dept_stmt->execute();
    $user_dept_result = $user_dept_stmt->fetch(PDO::FETCH_ASSOC);
    $user_department = $user_dept_result['department'] ?? 'General';
    
    // Determine department column
    $check_columns = "SHOW COLUMNS FROM risk_incidents LIKE 'normalized_department'";
    $stmt = $db->prepare($check_columns);
    $stmt->execute();
    $has_normalized_department_column = $stmt->rowCount() > 0;
    $department_column = $has_normalized_department_column ? 'normalized_department' : 'department';
    
    $query_parts = [];
    $params = [];

    // Base query depends on the export type
    if ($export_type === 'assigned') {
        $query_parts[] = "SELECT ri.*, u.full_name as reporter_name, ro.full_name as risk_owner_name
                         FROM risk_incidents ri 
                         LEFT JOIN users u ON ri.reported_by = u.id
                         LEFT JOIN users ro ON ri.risk_owner_id = ro.id
                         WHERE ri.risk_owner_id = :user_id";
        $params[':user_id'] = $_SESSION['user_id'];
    } elseif ($export_type === 'managed') {
        $query_parts[] = "SELECT ri.*, u.full_name as reporter_name, ro.full_name as risk_owner_name
                         FROM risk_incidents ri
                         LEFT JOIN users u ON ri.reported_by = u.id
                         LEFT JOIN users ro ON ri.risk_owner_id = ro.id
                         WHERE ri.risk_owner_id = :user_id
                         AND LOWER(ri.risk_status) = 'closed'
                         AND ri.general_inherent_risk_level IS NOT NULL
                         AND ri.general_inherent_risk_level != 'Not Assessed'";
        $params[':user_id'] = $_SESSION['user_id'];
    } elseif ($export_type === 'department') {
        $query_parts[] = "SELECT ri.*, u.full_name as reporter_name, ro.full_name as risk_owner_name
                         FROM risk_incidents ri 
                         LEFT JOIN users u ON ri.reported_by = u.id
                         LEFT JOIN users ro ON ri.risk_owner_id = ro.id
                         WHERE ri.$department_column = :department";
        $params[':department'] = $user_department;
    } else {
        die("Invalid export type specified.");
    }

    if (!empty($_GET['filters'])) {
        $filters = json_decode($_GET['filters'], true);
        if ($filters) {
            // Search filter - search across multiple fields
            if (!empty($filters['search'])) {
                $search_term = '%' . $filters['search'] . '%';
                $query_parts[] = "AND (ri.risk_id LIKE :search_term 
                                  OR ri.risk_name LIKE :search_term 
                                  OR ri.risk_description LIKE :search_term
                                  OR ri.risk_categories LIKE :search_term
                                  OR ri.risk_status LIKE :search_term)";
                $params[':search_term'] = $search_term;
            }
            
            // Category filter
            if (!empty($filters['category'])) {
                $query_parts[] = "AND ri.risk_categories LIKE :category_filter";
                $params[':category_filter'] = '%' . $filters['category'] . '%';
            }
            
            if (!empty($filters['riskLevel']) && !empty($filters['riskLevelType'])) {
                if ($filters['riskLevelType'] === 'inherent') {
                    $query_parts[] = "AND ri.general_inherent_risk_level = :risk_level";
                } else {
                    $query_parts[] = "AND ri.general_residual_risk_level = :risk_level";
                }
                $params[':risk_level'] = $filters['riskLevel'];
            }
            
            // Status filter
            if (!empty($filters['status'])) {
                $query_parts[] = "AND ri.risk_status = :status_filter";
                $params[':status_filter'] = $filters['status'];
            }
            
            // Owner filter
            if (!empty($filters['owner'])) {
                if ($filters['owner'] === 'Unassigned') {
                    $query_parts[] = "AND ri.risk_owner_id IS NULL";
                } else {
                    $query_parts[] = "AND ro.full_name = :owner_filter";
                    $params[':owner_filter'] = $filters['owner'];
                }
            }
            
            if (!empty($filters['dateFrom'])) {
                $query_parts[] = "AND DATE(ri.created_at) >= :date_from";
                $params[':date_from'] = $filters['dateFrom'];
            }
            if (!empty($filters['dateTo'])) {
                $query_parts[] = "AND DATE(ri.created_at) <= :date_to";
                $params[':date_to'] = $filters['dateTo'];
            }
        }
    }
    
    $query = implode(' ', $query_parts) . " ORDER BY ri.created_at DESC";
    
    try {
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Excel Export Error: " . $e->getMessage());
        die("Error generating export. Please try again later.");
    }
    
    // Output Excel content
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Risk ID</th>';
    echo '<th>Risk Name</th>';
    echo '<th>Description</th>';
    echo '<th>Category</th>';
    echo '<th>Inherent Risk Level</th>';
    echo '<th>Residual Risk Level</th>';
    echo '<th>Status</th>';
    echo '<th>Risk Owner</th>';
    echo '<th>Created Date</th>';
    echo '<th>Last Updated</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($risks as $risk) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($risk['risk_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($risk['risk_name']) . '</td>';
        echo '<td>' . htmlspecialchars($risk['risk_description'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars(str_replace(['[', ']', '"'], '', $risk['risk_categories'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($risk['general_inherent_risk_level'] ?? 'Not Assessed') . '</td>';
        echo '<td>' . htmlspecialchars($risk['general_residual_risk_level'] ?? 'Not Assessed') . '</td>';
        echo '<td>' . htmlspecialchars($risk['risk_status'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($risk['risk_owner_name'] ?? 'Unassigned') . '</td>';
        echo '<td>' . htmlspecialchars($risk['created_at']) . '</td>';
        echo '<td>' . htmlspecialchars($risk['updated_at']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    exit();
}

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
            $query = "UPDATE risk_incidents SET risk_owner_id = :owner_id, updated_at = NOW(), updated_by = :user_id WHERE id = :risk_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':owner_id', $_SESSION['user_id']);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
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
// Ensure department is available
if (empty($user['department']) || $user['department'] === null) {
    $dept_query = "SELECT department FROM users WHERE id = :user_id";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    if ($dept_result && !empty($dept_result['department'])) {
        $user['department'] = $dept_result['department'];
        $_SESSION['department'] = $dept_result['department'];
    }
}
$department = $user['department'] ?? 'General';

// Get comprehensive statistics
$stats = [];
// Risks in my department only - use the department column we determined exists
try {
    $query = "SELECT COUNT(*) as total FROM risk_incidents WHERE $department_column = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department); // Use the determined department
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
// High/Critical risks assessed by me in my department - use risk_level field
try {
    $query = "SELECT COUNT(*) as total FROM risk_incidents 
              WHERE $department_column = :department
              AND risk_level IN ('High', 'Critical')
              AND (risk_owner_id = :user_id OR updated_by = :user_id)
              AND risk_level IS NOT NULL
              AND risk_level != 'Not Assessed'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department); // Use the determined department
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
    $query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as risk_owner_name
             FROM risk_incidents r 
             LEFT JOIN users u ON r.reported_by = u.id 
             LEFT JOIN users ro ON r.risk_owner_id = ro.id
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
             AND ri.risk_status != 'Consolidated'
             ORDER BY ri.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department); // Use the determined department
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
// Initialize risk_levels to zeros
$risk_levels = [
    'low_risks' => 0,
    'medium_risks' => 0,
    'high_risks' => 0,
    'critical_risks' => 0,
    'not_assessed_risks' => 0
];

// CHANGE: Calculate both inherent and residual risk levels separately
$inherent_risk_levels = [
    'low_risks' => 0,
    'medium_risks' => 0,
    'high_risks' => 0,
    'critical_risks' => 0,
    'not_assessed_risks' => 0
];

$residual_risk_levels = [
    'low_risks' => 0,
    'medium_risks' => 0,
    'high_risks' => 0,
    'critical_risks' => 0,
    'not_assessed_risks' => 0
];

// Populate both inherent and residual risk levels from department_risks array
foreach ($department_risks as $risk) {
    // Count inherent risk levels
    $inherentLevel = strtolower($risk['general_inherent_risk_level'] ?? '');
    if ($inherentLevel === 'low') {
        $inherent_risk_levels['low_risks']++;
    } elseif ($inherentLevel === 'medium') {
        $inherent_risk_levels['medium_risks']++;
    } elseif ($inherentLevel === 'high') {
        $inherent_risk_levels['high_risks']++;
    } elseif ($inherentLevel === 'critical') {
        $inherent_risk_levels['critical_risks']++;
    } else {
        $inherent_risk_levels['not_assessed_risks']++;
    }
    
    // Count residual risk levels
    $residualLevel = strtolower($risk['general_residual_risk_level'] ?? '');
    if ($residualLevel === 'low') {
        $residual_risk_levels['low_risks']++;
    } elseif ($residualLevel === 'medium') {
        $residual_risk_levels['medium_risks']++;
    } elseif ($residualLevel === 'high') {
        $residual_risk_levels['high_risks']++;
    } elseif ($residualLevel === 'critical') {
        $residual_risk_levels['critical_risks']++;
    } else {
        $residual_risk_levels['not_assessed_risks']++;
    }
}

// Populate risk_levels from department_risks array
foreach ($department_risks as $risk) {
    $level = strtolower($risk['risk_level'] ?? '');
    if ($level === 'low') {
        $risk_levels['low_risks']++;
    } elseif ($level === 'medium') {
        $risk_levels['medium_risks']++;
    } elseif ($level === 'high') {
        $risk_levels['high_risks']++;
    } elseif ($level === 'critical') {
        $risk_levels['critical_risks']++;
    } else {
        // This catches null, empty strings, or 'Not Assessed' if it's not standardized
        $risk_levels['not_assessed_risks']++;
    }
}

// Risk category distribution for department with unique colors - use the department column we determined exists
$risk_by_category = [];
$category_counts = [];

foreach ($department_risks as $risk) {
    $categories_raw = $risk['risk_categories'] ?? '';
    
    if (!empty($categories_raw)) {
        // Parse JSON array format like ["Category 1", "Category 2", "Category 3"]
        $categories_array = json_decode($categories_raw, true);
        
        if (is_array($categories_array) && count($categories_array) > 0) {
            // Get the first element
            $first_element = $categories_array[0];
            
            // Check if it's an array (nested) or a string
            if (is_array($first_element)) {
                // Handle nested array case (double-encoded JSON)
                $first_category = trim($first_element[0] ?? '');
            } else {
                // Handle normal string case
                $first_category = trim($first_element);
            }
            
            if (!empty($first_category)) {
                if (!isset($category_counts[$first_category])) {
                    $category_counts[$first_category] = 0;
                }
                $category_counts[$first_category]++;
            }
        }
    }
}

// Convert to array format for chart
foreach ($category_counts as $category => $count) {
    $risk_by_category[] = [
        'risk_categories' => $category,
        'count' => $count
    ];
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
        case 'closed': return 'badge-success';
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
                'text' => 'Open',
                'color' => '#0c5460',
                'bg' => '#d1ecf1'
            ];
        case 'in_progress':
            return [
                'text' => 'In Progress',
                'color' => '#004085',
                'bg' => '#cce5ff'
            ];
        case 'closed':
            return [
                'text' => 'Closed',
                'color' => '#155724',
                'bg' => '#d4edda'
            ];
        case 'cancelled':
            return [
                'text' => 'Cancelled',
                'color' => '#721c24',
                'bg' => '#f8d7da'
            ];
        case 'overdue':
            return [
                'text' => 'Overdue',
                'color' => '#721c24',
                'bg' => '#f8d7da'
            ];
        default:
            return [
                'text' => 'Open',
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
    $dept_risks_stmt->bindParam(':department', $department); // Use the determined department
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
                             AND LOWER(risk_status) = 'closed'
                             AND general_inherent_risk_level IS NOT NULL
                             AND general_inherent_risk_level != 'Not Assessed'";
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

$all_categories = [];
try {
    $cat_query = "SELECT DISTINCT risk_categories FROM risk_incidents WHERE risk_categories IS NOT NULL AND risk_categories != ''";
    $cat_stmt = $db->prepare($cat_query);
    $cat_stmt->execute();
    $cat_results = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cat_results as $row) {
        $categories = json_decode($row['risk_categories'], true);
        if (is_array($categories)) {
            foreach ($categories as $cat) {
                if (is_array($cat)) {
                    // If it's a nested array (e.g., ["Category Name"])
                    $cat = $cat[0] ?? '';
                }
                $cat = trim($cat);
                if (!empty($cat) && !in_array($cat, $all_categories)) {
                    $all_categories[] = $cat;
                }
            }
        }
    }
    sort($all_categories);
} catch (Exception $e) {
    $all_categories = []; // Ensure $all_categories is always an array
}

$all_risk_owners = [];
try {
    // Fetch all users who are assigned risks, not just those who have reported them.
    // Also ensuring we get owners even if they haven't reported anything but own risks.
    $owner_query = "SELECT DISTINCT u.id, u.full_name 
                   FROM users u 
                   LEFT JOIN risk_incidents ri ON u.id = ri.risk_owner_id 
                   WHERE u.role = 'risk_owner' AND u.full_name IS NOT NULL 
                   ORDER BY u.full_name";
    $owner_stmt = $db->prepare($owner_query);
    $owner_stmt->execute();
    $all_risk_owners = $owner_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_risk_owners = [];
}

$all_statuses = ['Open', 'In Progress', 'Closed', 'Cancelled'];

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
            /* Add max-height and scrollbar for long risk lists */
            max-height: 600px;
            overflow-y: auto;
        }
        .table-responsive::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .table-responsive::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        .table-responsive::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
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
            color: #155724;
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
        .status-closed { background: #d4edda; color: #155724; }
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
        .risk-level-toggle {
            transition: all 0.3s ease;
            border-color: #E60012;
        }
        .risk-level-toggle.active {
            background: #E60012;
            color: white;
            border-color: #E60012;
        }
        .risk-level-toggle:not(.active) {
            background: white;
            color: #E60012;
        }
        .risk-level-toggle:not(.active):hover {
            background: #E60012;
            color: white;
        }

        /* Custom styles for merge action bar */
        #merge-action-bar {
            display: none;
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 2px solid #E60012;
            align-items: center; /* Ensure content is centered vertically */
        }
        #merge-action-bar .selected-count {
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
            margin-right: 1rem;
        }
        #merge-risks-btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            margin-right: 0.5rem;
        }
        #merge-risks-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        #merge-risks-btn:hover:not(:disabled) {
            background: #c50010 !important;
            transform: scale(1.05);
        }
        .cancel-merge-btn {
            background: transparent;
            color: #666;
            border: 1px solid #ddd;
            padding: 0.6rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .cancel-merge-btn:hover {
            background: #f8f9fa;
        }
        .risk-row-selectable.selected {
            background-color: #fff3f3 !important;
            border-left: 4px solid #E60012 !important;
        }
        
        .consolidated-risk {
            opacity: 0.7;
            background-color: #f8f9fa !important;
        }

        .consolidated-risk .risk-id-badge {
            background: #6c757d !important;
            color: white !important;
        }
        
        .risk-checkbox {
            accent-color: #E60012;
        }
        
        #select-all-risks {
            accent-color: #E60012;
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
        
        /* Custom styles for merge action bar */
        #merge-action-bar {
            display: none;
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 2px solid #E60012;
            align-items: center;
        }
        #merge-action-bar .selected-count {
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
            margin-right: 1rem;
        }
        #merge-risks-btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            margin-right: 0.5rem;
        }
        #merge-risks-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        #merge-risks-btn:hover:not(:disabled) {
            background: #c50010 !important;
            transform: scale(1.05);
        }
        .cancel-merge-btn {
            background: transparent;
            color: #666;
            border: 1px solid #ddd;
            padding: 0.6rem 1.5rem;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .cancel-merge-btn:hover {
            background: #f8f9fa;
        }
        .risk-row-selectable.selected {
            background-color: #fff3f3 !important;
            border-left: 4px solid #E60012 !important;
        }
        
        .consolidated-risk {
            opacity: 0.7;
            background-color: #f8f9fa !important;
        }

        .consolidated-risk .risk-id-badge {
            background: #6c757d !important;
            color: white !important;
        }
        
        .risk-checkbox {
            accent-color: #E60012;
        }
        
        #select-all-risks {
            accent-color: #E60012;
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
                        <div class="user-role">Risk_owner • <?php echo htmlspecialchars($department); ?></div>
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
                        <a href="risk_procedures.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk_procedures.php' ? 'active' : ''; ?>">
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
                        <div class="stat-description">All risks in <?php echo htmlspecialchars($department); ?></div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['my_assigned_risks']; ?></span>
                        <div class="stat-label">My Assigned Risks</div>
                        <div class="stat-description">Risks assigned to you</div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <!-- Ensure High/Critical Risks displays 0 instead of blank when no value -->
                        <span class="stat-number"><?php echo isset($stats['my_high_risks']) ? (int)$stats['my_high_risks'] : 0; ?></span>
                        <div class="stat-label">High/Critical Risks</div>
                        <div class="stat-description">High/Critical risks in your dept. assessed by you</div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <!-- Ensure Successfully Managed Risks displays 0 instead of blank when no value -->
                        <span class="stat-number"><?php echo isset($successfully_managed_count) ? (int)$successfully_managed_count : 0; ?></span>
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
                    
                    <!-- Risk Level Chart with Toggle -->
                     <div class="card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 class="card-title" style="margin: 0;">Risk Level Distribution</h3>
                            <div style="display: flex; gap: 0.5rem; background: #f0f0f0; padding: 0.25rem; border-radius: 8px;">
                                <button id="inherentToggle" onclick="toggleRiskLevel('inherent')" style="padding: 0.5rem 1rem; border: none; background: #e01212ff; color: white; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s;">
                                    Inherent
                                </button>
                                <button id="residualToggle" onclick="toggleRiskLevel('residual')" style="padding: 0.5rem 1rem; border: none; background: transparent; color: #666; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s;">
                                    Residual
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="levelChart"></canvas>
                        </div>
                    </div>

                </div>
                
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🚀 Quick Actions</h3>
                        <div style="display: flex; gap: 0.5rem;">
                        </div>
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
                                Successfully Managed Risks
                            </button>
                        </div>
                        
                        <!-- Department Risks Overview Content -->
                        <div id="department-overview-content" class="risk-tab-content active">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h4 style="margin: 0; color: #333;">Department Risks Overview</h4>
                                <span id="dept-risks-count" style="color: #E60012; font-weight: 600;"><?php echo $dept_risks_count; ?> risks in <?php echo htmlspecialchars($department); ?></span>
                            </div>
                            
                            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Search</label>
                                        <input type="text" id="dept-search" placeholder="Search risks..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Category</label>
                                        <select id="dept-category" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Categories</option>
                                            <?php foreach ($all_categories as $category): ?>
                                                <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Level Type</label>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button id="dept-inherent-btn" class="risk-level-toggle active" onclick="setDeptRiskLevelType('inherent')" style="flex: 1; padding: 0.5rem; border: 2px solid #E60012; background: #E60012; color: white; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Inherent</button>
                                            <button id="dept-residual-btn" class="risk-level-toggle" onclick="setDeptRiskLevelType('residual')" style="flex: 1; padding: 0.5rem; border: 2px solid #E60012; background: white; color: #E60012; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Residual</button>
                                        </div>
                                    </div>

                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Level</label>
                                        <select id="dept-risk-level" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Levels</option>
                                            <option value="Critical">Critical</option>
                                            <option value="High">High</option>
                                            <option value="Medium">Medium</option>
                                            <option value="Low">Low</option>
                                            <option value="Not Assessed">Not Assessed</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Status</label>
                                        <select id="dept-status" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Statuses</option>
                                            <?php foreach ($all_statuses as $status): ?>
                                                <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Owner</label>
                                        <select id="dept-owner" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Owners</option>
                                            <?php foreach ($all_risk_owners as $owner): ?>
                                                <option value="<?php echo htmlspecialchars($owner['full_name']); ?>"><?php echo htmlspecialchars($owner['full_name']); ?></option>
                                            <?php endforeach; ?>
                                            <option value="Unassigned">Unassigned</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Date From</label>
                                        <input type="date" id="dept-date-from" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                    </div>
                                    
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Date To</label>
                                        <input type="date" id="dept-date-to" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                    </div>
                                </div>
                                 
                                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                    <button onclick="clearDeptFilters()" style="padding: 0.5rem 1rem; border: 1px solid #ddd; background: white; color: #333; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">Clear Filters</button>
                                    <button onclick="exportDeptToExcel()" style="padding: 0.5rem 1rem; border: none; background: #28a745; color: white; border-radius: 4px; cursor: pointer; font-size: 0.9rem; font-weight: 600;">
                                        <i class="fas fa-file-excel"></i> Export to Excel
                                    </button>
                                </div>
                            </div>
                            
                            <?php if (empty($department_risks)): ?>
                                <div style="text-align: center; padding: 2rem; color: #666;">
                                    <div style="font-size: 2rem; margin-bottom: 1rem;">📋</div>
                                    <h4>No risks in your department yet</h4>
                                    <p>Your department hasn't reported any risks yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table" id="dept-risks-table">
                                        <thead>
                                            <tr>
                                                <th>Risk ID</th>
                                                <th>Risk Name</th>
                                                <th>Category</th>
                                                <th>Risk Levels</th>
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
                                            ?>;"
                                                data-risk-id="<?php echo htmlspecialchars($risk['risk_id'] ?? ''); ?>"
                                                data-risk-name="<?php echo htmlspecialchars($risk['risk_name']); ?>"
                                                data-risk-description="<?php echo htmlspecialchars($risk['risk_description'] ?? ''); ?>"
                                                data-risk-categories="<?php echo htmlspecialchars($risk['risk_categories']); ?>"
                                                data-inherent-level="<?php echo htmlspecialchars($risk['general_inherent_risk_level'] ?? 'Not Assessed'); ?>"
                                                data-residual-level="<?php echo htmlspecialchars($risk['general_residual_risk_level'] ?? 'Not Assessed'); ?>"
                                                data-status="<?php echo $risk['risk_status']; ?>"
                                                data-risk-owner="<?php echo htmlspecialchars($risk['risk_owner_name'] ?? 'Unassigned'); ?>"
                                                data-reported-date="<?php echo $risk['created_at']; ?>"
                                            >
                                                <td>
                                                    <?php if (!empty($risk['risk_id'])): ?>
                                                        <span class="risk-id-badge"><?php echo htmlspecialchars($risk['risk_id']); ?></span>
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
                                                    <?php
                                                    $categories = explode(',', $risk['risk_categories'] ?? 'Uncategorized');
                                                    foreach ($categories as $category): 
                                                        $cleanCategory = cleanCategoryName(trim($category));
                                                    ?>
                                                        <div style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.25rem; display: inline-block;">
                                                            <?php echo htmlspecialchars($cleanCategory); ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    // Inherent Risk Level
                                                    $inherentLevel = !empty($risk['general_inherent_risk_level']) ? strtolower($risk['general_inherent_risk_level']) : 'not-assessed';
                                                    $inherentLevelText = !empty($risk['general_inherent_risk_level']) ? $risk['general_inherent_risk_level'] : 'Not Assessed';
                                                    $inherentLevelColors = [
                                                        'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                        'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                        'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                                    ];
                                                    $inherentColors = $inherentLevelColors[$inherentLevel] ?? $inherentLevelColors['not-assessed'];
                                                    
                                                    // Residual Risk Level
                                                    $residualLevel = !empty($risk['general_residual_risk_level']) ? strtolower($risk['general_residual_risk_level']) : 'not-assessed';
                                                    $residualLevelText = !empty($risk['general_residual_risk_level']) ? $risk['general_residual_risk_level'] : 'Not Assessed';
                                                    $residualColors = $inherentLevelColors[$residualLevel] ?? $inherentLevelColors['not-assessed'];
                                                    ?>
                                                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                        <div>
                                                            <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Inherent:</div>
                                                            <span style="background: <?php echo $inherentColors['bg']; ?>; color: <?php echo $inherentColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                                <?php echo strtoupper($inherentLevelText); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Residual:</div>
                                                            <span style="background: <?php echo $residualColors['bg']; ?>; color: <?php echo $residualColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                                <?php echo strtoupper($residualLevelText); ?>
                                                            </span>
                                                        </div>
                                                    </div>
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
                                <span id="assigned-risks-count" style="color: #E60012; font-weight: 600;"><?php echo $assigned_risks_count; ?> risks assigned to you</span>
                            </div>
                            
                            <!-- Floating action bar for risk merging -->
                            <div id="merge-action-bar">
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <span id="selected-count">0 risks selected</span>
                                    <button id="merge-risks-btn" onclick="handleMergeRisks()" style="background: #E60012; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: all 0.3s;">
                                        Merge Risks
                                    </button>
                                    <button onclick="cancelMergeSelection()" class="cancel-merge-btn">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                            
                             <!-- Comprehensive filter controls for Assigned Risks -->
                            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                                     <!-- Search Bar -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Search</label>
                                        <input type="text" id="assigned-search" placeholder="Search risks..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                    </div>
                                    
                                     <!-- Category Filter -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Category</label>
                                        <select id="assigned-category" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Categories</option>
                                            <?php foreach ($all_categories as $category): ?>
                                                <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                     <!-- Risk Level Type Toggle -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Level Type</label>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button id="assigned-inherent-btn" class="risk-level-toggle active" onclick="setAssignedRiskLevelType('inherent')" style="flex: 1; padding: 0.5rem; border: 2px solid #E60012; background: #E60012; color: white; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Inherent</button>
                                            <button id="assigned-residual-btn" class="risk-level-toggle" onclick="setAssignedRiskLevelType('residual')" style="flex: 1; padding: 0.5rem; border: 2px solid #E60012; background: white; color: #E60012; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Residual</button>
                                        </div>
                                    </div>
                                    
                                     <!-- Risk Level Filter -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Level</label>
                                        <select id="assigned-risk-level" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Levels</option>
                                            <option value="Critical">Critical</option>
                                            <option value="High">High</option>
                                            <option value="Medium">Medium</option>
                                            <option value="Low">Low</option>
                                            <option value="Not Assessed">Not Assessed</option>
                                        </select>
                                    </div>
                                    
                                     <!-- Status Filter -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Status</label>
                                        <select id="assigned-status" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Statuses</option>
                                            <?php foreach ($all_statuses as $status): ?>
                                                <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                 <!-- Action Buttons -->
                                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                    <button onclick="clearAssignedFilters()" style="padding: 0.5rem 1rem; border: 1px solid #ddd; background: white; color: #333; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">Clear Filters</button>
                                    <button onclick="exportAssignedToExcel()" style="padding: 0.5rem 1rem; border: none; background: #28a745; color: white; border-radius: 4px; cursor: pointer; font-size: 0.9rem; font-weight: 600;">
                                        <i class="fas fa-file-excel"></i> Export to Excel
                                    </button>
                                </div>
                            </div>
                            
                            <?php if (empty($assigned_risks)): ?>
                                <div style="text-align: center; padding: 2rem; color: #666;">
                                    <div style="font-size: 2rem; margin-bottom: 1rem;">📋</div>
                                    <h4>No risks assigned to you yet</h4>
                                    <p>New risks will appear here when assigned to you by the system.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table" id="assigned-risks-table">
                                        <thead>
                                            <tr>
                                                <!-- Added checkbox column for risk selection -->
                                                <th style="width: 50px;">
                                                    <input type="checkbox" id="select-all-risks" style="cursor: pointer; width: 18px; height: 18px;">
                                                </th>
                                                <th>Risk ID</th>
                                                <th>Risk Name</th>
                                                <th>Risk Levels</th>
                                                <th>Status</th>
                                                <th>Reported By</th>
                                                <th>Reported Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assigned_risks as $risk): 
                                                $riskIdParts = explode('/', $risk['risk_id'] ?? '');
                                                $riskYear = $riskIdParts[1] ?? '';
                                                $riskMonth = $riskIdParts[2] ?? '';
                                                $riskNumber = $riskIdParts[3] ?? '';
                                                
                                                $isConsolidated = strtolower($risk['risk_status']) === 'consolidated';
                                                $isClosed = strtolower($risk['risk_status']) === 'closed';
                                                // Merged risks have more than 3 slashes (e.g., AM/2025/10/23/22/21)
                                                $isMerged = count($riskIdParts) > 4;
                                                // Can select for merging if not Consolidated, not Merged, and not Closed
                                                $canSelect = !$isConsolidated && !$isMerged && !$isClosed;
                                            ?>
                                            <tr style="border-left: 4px solid <?php
                                                 $level = getRiskLevel($risk);
                                                echo $level == 'critical' ? '#dc3545' : ($level == 'high' ? '#fd7e14' : ($level == 'medium' ? '#ffc107' : ($level == 'low' ? '#28a745' : '#6c757d')));
                                            ?>;"
                                                class="risk-row-selectable <?php echo $isConsolidated ? 'consolidated-risk' : ''; ?>"
                                                data-risk-id="<?php echo htmlspecialchars($risk['risk_id'] ?? ''); ?>"
                                                data-risk-name="<?php echo htmlspecialchars($risk['risk_name']); ?>"
                                                data-risk-description="<?php echo htmlspecialchars($risk['risk_description'] ?? ''); ?>"
                                                data-risk-categories="<?php echo htmlspecialchars($risk['risk_categories']); ?>"
                                                data-inherent-level="<?php echo htmlspecialchars($risk['general_inherent_risk_level'] ?? 'Not Assessed'); ?>"
                                                data-residual-level="<?php echo htmlspecialchars($risk['general_residual_risk_level'] ?? 'Not Assessed'); ?>"
                                                data-status="<?php echo $risk['risk_status']; ?>"
                                                data-risk-year="<?php echo htmlspecialchars($riskYear); ?>"
                                                data-risk-month="<?php echo htmlspecialchars($riskMonth); ?>"
                                                data-risk-number="<?php echo htmlspecialchars($riskNumber); ?>"
                                                data-is-consolidated="<?php echo $isConsolidated ? '1' : '0'; ?>"
                                                data-is-merged="<?php echo $isMerged ? '1' : '0'; ?>"
                                                data-is-closed="<?php echo $isClosed ? '1' : '0'; ?>"
                                            >
                                                 <!-- Updated checkbox logic for Consolidated, Merged, and Closed risks -->
                                                <td>
                                                    <?php if ($canSelect): ?>
                                                        <input type="checkbox" class="risk-checkbox" style="cursor: pointer; width: 18px; height: 18px;">
                                                    <?php elseif ($isConsolidated): ?>
                                                        <span style="color: #999; font-size: 0.8rem; font-weight: 600;">Consolidated</span>
                                                    <?php elseif ($isMerged): ?>
                                                        <span style="color: #0066cc; font-size: 0.8rem; font-weight: 600;">Merged</span>
                                                    <?php elseif ($isClosed): ?>
                                                        <span style="color: #28a745; font-size: 0.8rem; font-weight: 600;">Closed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($risk['risk_id'])): ?>
                                                        <span class="risk-id-badge"><?php echo htmlspecialchars($risk['risk_id']); ?></span>
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
                                                    <?php
                                                    // Inherent Risk Level
                                                    $inherentLevel = !empty($risk['general_inherent_risk_level']) ? strtolower($risk['general_inherent_risk_level']) : 'not-assessed';
                                                    $inherentLevelText = !empty($risk['general_inherent_risk_level']) ? $risk['general_inherent_risk_level'] : 'Not Assessed';
                                                    $inherentLevelColors = [
                                                        'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                        'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                        'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                                    ];
                                                    $inherentColors = $inherentLevelColors[$inherentLevel] ?? $inherentLevelColors['not-assessed'];
                                                    
                                                    // Residual Risk Level
                                                    $residualLevel = !empty($risk['general_residual_risk_level']) ? strtolower($risk['general_residual_risk_level']) : 'not-assessed';
                                                    $residualLevelText = !empty($risk['general_residual_risk_level']) ? $risk['general_residual_risk_level'] : 'Not Assessed';
                                                    $residualColors = $inherentLevelColors[$residualLevel] ?? $inherentLevelColors['not-assessed'];
                                                    ?>
                                                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                        <div>
                                                            <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Inherent:</div>
                                                            <span style="background: <?php echo $inherentColors['bg']; ?>; color: <?php echo $inherentColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                                <?php echo strtoupper($inherentLevelText); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Residual:</div>
                                                            <span style="background: <?php echo $residualColors['bg']; ?>; color: <?php echo $residualColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                                <?php echo strtoupper($residualLevelText); ?>
                                                            </span>
                                                        </div>
                                                    </div>
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
                                                    <?php echo htmlspecialchars($risk['reporter_name'] ?? 'Unknown'); ?>
                                                </td>
                                                <td style="color: #666; font-size: 0.9rem;">
                                                    <?php echo date('M j, Y', strtotime($risk['created_at'])); ?>
                                                </td>
                                                <td>
                                                     <!-- Show VIEW button for Consolidated risks, MANAGE for others -->
                                                    <div style="display: flex; gap: 0.5rem;">
                                                        <?php if ($isConsolidated): ?>
                                                            <a href="view_risk.php?id=<?php echo $risk['id']; ?>" style="background: #6c757d; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; text-decoration: none;">
                                                                View
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="view_risk.php?id=<?php echo $risk['id']; ?>" style="background: #E60012; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.8rem; cursor: pointer; text-decoration: none;">
                                                                Manage
                                                            </a>
                                                        <?php endif; ?>
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
                                <span id="managed-risks-count" style="color: #E60012; font-weight: 600;"><?php echo $successfully_managed_count; ?> risks completed with proper assessment</span>
                            </div>
                            
                             <!-- Comprehensive filter controls for Successfully Managed Risks -->
                            <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                                     <!-- Search Bar -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Search</label>
                                        <input type="text" id="managed-search" placeholder="Search risks..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                    </div>
                                    
                                     <!-- Category Filter -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Category</label>
                                        <select id="managed-category" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Categories</option>
                                            <?php foreach ($all_categories as $category): ?>
                                                <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                     <!-- Risk Level Type Toggle -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Level Type</label>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button id="managed-inherent-btn" class="risk-level-toggle active" onclick="setManagedRiskLevelType('inherent')" style="flex: 1; padding: 0.5rem; border: 2px solid #E60012; background: #E60012; color: white; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Inherent</button>
                                            <button id="managed-residual-btn" class="risk-level-toggle" onclick="setManagedRiskLevelType('residual')" style="flex: 1; padding: 0.5rem; border: 2px solid #E60012; background: white; color: #E60012; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600;">Residual</button>
                                        </div>
                                    </div>
                                    
                                     <!-- Risk Level Filter -->
                                    <div>
                                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333; font-size: 0.9rem;">Risk Level</label>
                                        <select id="managed-risk-level" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">All Levels</option>
                                            <option value="Critical">Critical</option>
                                            <option value="High">High</option>
                                            <option value="Medium">Medium</option>
                                            <option value="Low">Low</option>
                                            <option value="Not Assessed">Not Assessed</option>
                                        </select>
                                    </div>
                                </div>
                                
                                 <!-- Action Buttons -->
                                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                                    <button onclick="clearManagedFilters()" style="padding: 0.5rem 1rem; border: 1px solid #ddd; background: white; color: #333; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">Clear Filters</button>
                                    <button onclick="exportManagedToExcel()" style="padding: 0.5rem 1rem; border: none; background: #28a745; color: white; border-radius: 4px; cursor: pointer; font-size: 0.9rem; font-weight: 600;">
                                        <i class="fas fa-file-excel"></i> Export to Excel
                                    </button>
                                </div>
                            </div>
                            
                            <?php
                            // Get successfully managed risks with proper assessment check
                            try {
                                $managed_risks_query = "SELECT ri.*, u.full_name as reporter_name
                                                       FROM risk_incidents ri
                                                       LEFT JOIN users u ON ri.reported_by = u.id
                                                       WHERE ri.risk_owner_id = :user_id
                                                        AND LOWER(ri.risk_status) = 'closed'
                                                        AND ri.general_inherent_risk_level IS NOT NULL
                                                        AND ri.general_inherent_risk_level != 'Not Assessed'
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
                                <div style="text-align: center; padding: 3rem; color: #999;">
                                    <i class="fas fa-trophy" style="font-size: 3rem; margin-bottom: 1rem; color: #ddd;"></i>
                                    <p style="font-size: 1.1rem; margin: 0;">No successfully managed risks yet</p>
                                    <p style="font-size: 0.9rem; margin-top: 0.5rem;">Closed risks with proper assessment will appear here</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table" id="managed-risks-table">
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
                                            <tr style="border-left: 4px solid #28a745;"
                                                data-risk-id="<?php echo htmlspecialchars($risk['risk_id'] ?? ''); ?>"
                                                data-risk-name="<?php echo htmlspecialchars($risk['risk_name']); ?>"
                                                data-risk-description="<?php echo htmlspecialchars($risk['risk_description'] ?? ''); ?>"
                                                data-risk-categories="<?php echo htmlspecialchars($risk['risk_categories']); ?>"
                                                data-inherent-level="<?php echo htmlspecialchars($risk['general_inherent_risk_level'] ?? 'Not Assessed'); ?>"
                                                data-residual-level="<?php echo htmlspecialchars($risk['general_residual_risk_level'] ?? 'Not Assessed'); ?>"
                                            >
                                                <td>
                                                    <?php if (!empty($risk['risk_id'])): ?>
                                                        <span class="risk-id-badge"><?php echo htmlspecialchars($risk['risk_id']); ?></span>
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
                                                    <?php 
                                                    $categories = explode(',', $risk['risk_categories'] ?? 'Uncategorized');
                                                    foreach ($categories as $category): 
                                                        $cleanCategory = cleanCategoryName(trim($category));
                                                    ?>
                                                        <div style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.25rem; display: inline-block;">
                                                            <?php echo htmlspecialchars($cleanCategory); ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    // Inherent Risk Level
                                                    $inherentLevel = !empty($risk['general_inherent_risk_level']) ? strtolower($risk['general_inherent_risk_level']) : 'not-assessed';
                                                    $inherentLevelText = !empty($risk['general_inherent_risk_level']) ? $risk['general_inherent_risk_level'] : 'Not Assessed';
                                                    $inherentLevelColors = [
                                                        'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                        'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                        'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                        'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                                    ];
                                                    $inherentColors = $inherentLevelColors[$inherentLevel] ?? $inherentLevelColors['not-assessed'];
                                                    
                                                    // Residual Risk Level
                                                    $residualLevel = !empty($risk['general_residual_risk_level']) ? strtolower($risk['general_residual_risk_level']) : 'not-assessed';
                                                    $residualLevelText = !empty($risk['general_residual_risk_level']) ? $risk['general_residual_risk_level'] : 'Not Assessed';
                                                    $residualColors = $inherentLevelColors[$residualLevel] ?? $inherentLevelColors['not-assessed'];
                                                    ?>
                                                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                        <div>
                                                            <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Inherent:</div>
                                                            <span style="background: <?php echo $inherentColors['bg']; ?>; color: <?php echo $inherentColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                                <?php echo strtoupper($inherentLevelText); ?>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Residual:</div>
                                                            <span style="background: <?php echo $residualColors['bg']; ?>; color: <?php echo $residualColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                                <?php echo strtoupper($residualLevelText); ?>
                                                            </span>
                                                        </div>
                                                    </div>
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
                        <h2 class="card-title">My Assigned Risks (<?php echo htmlspecialchars($department); ?>)</h2>
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
                                        <?php if (!empty($risk['risk_id'])): ?>
                                            <span class="risk-id-badge"><?php echo htmlspecialchars($risk['risk_id']); ?></span>
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
                        <h2 class="card-title">Department Risks (<?php echo htmlspecialchars($department); ?>)</h2>
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
                                        <?php if (!empty($risk['risk_id'])): ?>
                                            <span class="risk-id-badge"><?php echo htmlspecialchars($risk['risk_id']); ?></span>
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
                    $closed_reports = 0;
                    
                    foreach ($my_reported_risks as $risk) {
                        switch ($risk['risk_status']) {
                            case 'pending':
                                $open_reports++;
                                break;
                            case 'in_progress':
                                $in_progress_reports++;
                                break;
                            case 'closed':
                                $closed_reports++;
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
                        <div class="stat-number" style="color: #E60012;"><?php echo $closed_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Completed</div>
                        <div class="stat-description" style="color: #666;">Successfully managed</div>
                    </div>
                </div>
                
                <!-- Action Buttons and Search -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <input type="text" id="searchRisks" placeholder="Search risks..." style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px; width: 250px;">
                    </div>
                    <div>
                        <select id="categoryFilter" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px; margin-right: 0.5rem;">
                            <option value="">All Categories</option>
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="ownerFilter" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px; margin-right: 0.5rem;">
                            <option value="">All Owners</option>
                            <?php foreach ($all_risk_owners as $owner): ?>
                                <option value="<?php echo htmlspecialchars($owner['id']); ?>"><?php echo htmlspecialchars($owner['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="statusFilter" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px;">
                            <option value="">All Statuses</option>
                            <?php foreach ($all_statuses as $status): ?>
                                <option value="<?php echo strtolower($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Reports Table -->
                <div class="card" style="padding: 0;">
                    <div style="padding: 1.5rem 2rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; color: #333; font-size: 1.2rem; font-weight: 600;">All My Risk Reports</h3>
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
                                    <tr class="risk-report-row" 
                                        data-status="<?php echo $risk['risk_status'] ?? 'pending'; ?>" 
                                        data-search="<?php echo strtolower($risk['risk_name'] . ' ' . cleanCategoryName($risk['risk_categories'] ?? '')); ?>"
                                        data-category="<?php echo htmlspecialchars(str_replace('"', '', str_replace('[', '', str_replace(']', '', $risk['risk_categories'] ?? '')))); ?>"
                                        data-owner="<?php echo $risk['risk_owner_id'] ?? ''; ?>"
                                        >
                                        <td style="padding: 1rem; border-left: 4px solid #E60012;">
                                            <?php if (!empty($risk['risk_id'])): ?>
                                                <span class="risk-id-badge"><?php echo htmlspecialchars($risk['risk_id']); ?></span>
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
                                            <?php 
                                            $categories = explode(',', $risk['risk_categories'] ?? 'Uncategorized');
                                            foreach ($categories as $category): 
                                                $cleanCategory = cleanCategoryName(trim($category));
                                            ?>
                                                <div style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.25rem; display: inline-block;">
                                                    <?php echo htmlspecialchars($cleanCategory); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php
                                            // Inherent Risk Level
                                            $inherentLevel = !empty($risk['general_inherent_risk_level']) ? strtolower($risk['general_inherent_risk_level']) : 'not-assessed';
                                            $inherentLevelText = !empty($risk['general_inherent_risk_level']) ? $risk['general_inherent_risk_level'] : 'Not Assessed';
                                            $inherentLevelColors = [
                                                'critical' => ['bg' => '#f8d7da', 'color' => '#721c24'],
                                                'high' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                'medium' => ['bg' => '#fff3cd', 'color' => '#856404'],
                                                'low' => ['bg' => '#d4edda', 'color' => '#155724'],
                                                'not-assessed' => ['bg' => '#e2e3e5', 'color' => '#383d41']
                                            ];
                                            $inherentColors = $inherentLevelColors[$inherentLevel] ?? $inherentLevelColors['not-assessed'];
                                            
                                            // Residual Risk Level
                                            $residualLevel = !empty($risk['general_residual_risk_level']) ? strtolower($risk['general_residual_risk_level']) : 'not-assessed';
                                            $residualLevelText = !empty($risk['general_residual_risk_level']) ? $risk['general_residual_risk_level'] : 'Not Assessed';
                                            $residualColors = $inherentLevelColors[$residualLevel] ?? $inherentLevelColors['not-assessed'];
                                            ?>
                                            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                <div>
                                                    <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Inherent:</div>
                                                    <span style="background: <?php echo $inherentColors['bg']; ?>; color: <?php echo $inherentColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                        <?php echo strtoupper($inherentLevelText); ?>
                                                    </span>
                                                </div>
                                                <div>
                                                    <div style="font-size: 0.7rem; color: #666; margin-bottom: 0.25rem;">Residual:</div>
                                                    <span style="background: <?php echo $residualColors['bg']; ?>; color: <?php echo $residualColors['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                                        <?php echo strtoupper($residualLevelText); ?>
                                                    </span>
                                                </div>
                                            </div>
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
            
            
            <!-- Removed Procedures tab content -->
            
        </main>
    </div>
    <script>
        let currentDeptRiskLevelType = 'inherent'; // Default to inherent
        let currentAssignedRiskLevelType = 'inherent'; // Default to inherent
        let currentManagedRiskLevelType = 'inherent'; // Default to inherent

        function setDeptRiskLevelType(type) {
            currentDeptRiskLevelType = type;
            document.getElementById('dept-inherent-btn').classList.toggle('active', type === 'inherent');
            document.getElementById('dept-residual-btn').classList.toggle('active', type === 'residual');
            document.getElementById('dept-inherent-btn').style.background = type === 'inherent' ? '#E60012' : 'white';
            document.getElementById('dept-inherent-btn').style.color = type === 'inherent' ? 'white' : '#E60012';
            document.getElementById('dept-residual-btn').style.background = type === 'residual' ? '#E60012' : 'white';
            document.getElementById('dept-residual-btn').style.color = type === 'residual' ? 'white' : '#E60012';
            filterDepartmentRisks(); // Re-apply filters with the new type
        }

        function clearDeptFilters() {
            document.getElementById('dept-search').value = '';
            document.getElementById('dept-category').value = '';
            setDeptRiskLevelType('inherent'); // Reset to inherent and update button styles
            document.getElementById('dept-risk-level').value = '';
            document.getElementById('dept-status').value = '';
            document.getElementById('dept-owner').value = '';
            document.getElementById('dept-date-from').value = ''; // Clear date filters
            document.getElementById('dept-date-to').value = '';   // Clear date filters
            filterDepartmentRisks(); // Apply the cleared filters
        }

        function exportDeptToExcel() {
            const filters = getDeptFilterValues();
            // Construct the URL with export parameters
            const baseUrl = window.location.href.split('?')[0]; // Get base URL without query string
            const exportUrl = `${baseUrl}?export=excel&type=department&filters=${encodeURIComponent(JSON.stringify(filters))}`;
            window.location.href = exportUrl;
        }

        function getDeptFilterValues() {
            const searchTerm = document.getElementById('dept-search').value.toLowerCase();
            const selectedCategory = document.getElementById('dept-category').value;
            const selectedRiskLevel = document.getElementById('dept-risk-level').value;
            const selectedStatus = document.getElementById('dept-status').value;
            const selectedOwner = document.getElementById('dept-owner').value;
            const dateFrom = document.getElementById('dept-date-from').value;
            const dateTo = document.getElementById('dept-date-to').value;

            const filters = {};
            if (searchTerm) filters.search = searchTerm;
            if (selectedCategory) filters.category = selectedCategory;
            if (selectedRiskLevel) filters.riskLevel = selectedRiskLevel;
            if (selectedStatus) filters.status = selectedStatus;
            if (selectedOwner) filters.owner = selectedOwner;
            if (dateFrom) filters.dateFrom = dateFrom;
            if (dateTo) filters.dateTo = dateTo;

            // Add the risk level type filter
            filters.riskLevelType = currentDeptRiskLevelType;
            
            return filters;
        }

        function filterDepartmentRisks() {
            const searchTerm = document.getElementById('dept-search').value.toLowerCase();
            const selectedCategory = document.getElementById('dept-category').value;
            const selectedRiskLevel = document.getElementById('dept-risk-level').value;
            const selectedStatus = document.getElementById('dept-status').value;
            const selectedOwner = document.getElementById('dept-owner').value;
            const dateFrom = document.getElementById('dept-date-from').value;
            const dateTo = document.getElementById('dept-date-to').value;
            const tableRows = document.querySelectorAll('#dept-risks-table tbody tr');
            let visibleRowCount = 0;

            tableRows.forEach(row => {
                const riskId = row.getAttribute('data-risk-id').toLowerCase();
                const riskName = row.getAttribute('data-risk-name').toLowerCase();
                const riskDescription = row.getAttribute('data-risk-description').toLowerCase();
                const riskCategoriesRaw = row.getAttribute('data-risk-categories') || '';
                const inherentLevel = row.getAttribute('data-inherent-level');
                const residualLevel = row.getAttribute('data-residual-level');
                const status = row.getAttribute('data-status');
                const owner = row.getAttribute('data-risk-owner');
                const reportedDate = row.getAttribute('data-reported-date');

                let currentRiskLevel = 'Not Assessed';
                if (currentDeptRiskLevelType === 'inherent') {
                    currentRiskLevel = inherentLevel; // First level in Risk Levels column
                } else if (currentDeptRiskLevelType === 'residual') {
                    currentRiskLevel = residualLevel; // Second level in Risk Levels column
                }

                const matchesSearch = searchTerm ? 
                    (riskId.includes(searchTerm) || 
                     riskName.includes(searchTerm) || 
                     riskDescription.includes(searchTerm) ||
                     riskCategoriesRaw.toLowerCase().includes(searchTerm) ||
                     status.toLowerCase().includes(searchTerm) ||
                     inherentLevel.toLowerCase().includes(searchTerm) ||
                     residualLevel.toLowerCase().includes(searchTerm)) : true;
                
                const matchesCategory = !selectedCategory || riskCategoriesRaw.replace(/["'\[\]]/g, '').includes(selectedCategory);
                const matchesRiskLevel = !selectedRiskLevel || currentRiskLevel.toUpperCase() === selectedRiskLevel.toUpperCase();
                const matchesStatus = !selectedStatus || status === selectedStatus;
                const matchesOwner = !selectedOwner || (selectedOwner === 'Unassigned' && owner === 'Unassigned') || owner === selectedOwner;
                
                let matchesDateRange = true;
                if (dateFrom || dateTo) {
                    const riskDate = new Date(reportedDate).toISOString().split('T')[0];
                    if (dateFrom && riskDate < dateFrom) matchesDateRange = false;
                    if (dateTo && riskDate > dateTo) matchesDateRange = false;
                }

                if (matchesSearch && matchesCategory && matchesRiskLevel && matchesStatus && matchesOwner && matchesDateRange) {
                    row.style.display = '';
                    visibleRowCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            document.getElementById('dept-risks-count').textContent = `${visibleRowCount} risks found in <?php echo htmlspecialchars($department); ?>`;
        }

        // Function to set the type of risk level to filter by for assigned risks (inherent or residual)
        function setAssignedRiskLevelType(type) {
            currentAssignedRiskLevelType = type;
            document.getElementById('assigned-inherent-btn').classList.toggle('active', type === 'inherent');
            document.getElementById('assigned-residual-btn').classList.toggle('active', type === 'residual');
            document.getElementById('assigned-inherent-btn').style.background = type === 'inherent' ? '#E60012' : 'white';
            document.getElementById('assigned-inherent-btn').style.color = type === 'inherent' ? 'white' : '#E60012';
            document.getElementById('assigned-residual-btn').style.background = type === 'residual' ? '#E60012' : 'white';
            document.getElementById('assigned-residual-btn').style.color = type === 'residual' ? 'white' : '#E60012';
            filterAssignedRisks(); // Re-apply filters with the new type
        }

        // Function to clear assigned risks filters
        function clearAssignedFilters() {
            document.getElementById('assigned-search').value = '';
            document.getElementById('assigned-category').value = '';
            setAssignedRiskLevelType('inherent'); // Reset to inherent and update button styles
            document.getElementById('assigned-risk-level').value = '';
            document.getElementById('assigned-status').value = '';
            filterAssignedRisks(); // Apply the cleared filters
        }

        // Function to export assigned risks to Excel
        function exportAssignedToExcel() {
            const filters = getAssignedFilterValues();
            // Construct the URL with export parameters
            const baseUrl = window.location.href.split('?')[0]; // Get base URL without query string
            const exportUrl = `${baseUrl}?export=excel&type=assigned&filters=${encodeURIComponent(JSON.stringify(filters))}`;
            window.location.href = exportUrl;
        }

        // Function to get current filter values for assigned risks
        function getAssignedFilterValues() {
            const searchTerm = document.getElementById('assigned-search').value.toLowerCase();
            const selectedCategory = document.getElementById('assigned-category').value;
            const selectedRiskLevel = document.getElementById('assigned-risk-level').value;
            const selectedStatus = document.getElementById('assigned-status').value;

            const filters = {};
            if (searchTerm) filters.search = searchTerm;
            if (selectedCategory) filters.category = selectedCategory;
            if (selectedRiskLevel) filters.riskLevel = selectedRiskLevel;
            if (selectedStatus) filters.status = selectedStatus;

            // Add the risk level type filter
            filters.riskLevelType = currentAssignedRiskLevelType;
            
            return filters;
        }

        // Function to filter assigned risks dynamically
        function filterAssignedRisks() {
            const searchTerm = document.getElementById('assigned-search').value.toLowerCase();
            const selectedCategory = document.getElementById('assigned-category').value;
            const selectedRiskLevel = document.getElementById('assigned-risk-level').value;
            const selectedStatus = document.getElementById('assigned-status').value;
            const tableRows = document.querySelectorAll('#assigned-risks-table tbody tr');
            let visibleRowCount = 0;

            tableRows.forEach(row => {
                const riskId = row.getAttribute('data-risk-id').toLowerCase();
                const riskName = row.getAttribute('data-risk-name').toLowerCase();
                const riskDescription = row.getAttribute('data-risk-description').toLowerCase();
                const riskCategoriesRaw = row.getAttribute('data-risk-categories') || '';
                const inherentLevel = row.getAttribute('data-inherent-level');
                const residualLevel = row.getAttribute('data-residual-level');
                const status = row.getAttribute('data-status');

                let currentRiskLevel = 'Not Assessed';
                if (currentAssignedRiskLevelType === 'inherent') {
                    currentRiskLevel = inherentLevel; // First level in Risk Levels column
                } else if (currentAssignedRiskLevelType === 'residual') {
                    currentRiskLevel = residualLevel; // Second level in Risk Levels column
                }

                const matchesSearch = searchTerm ? 
                    (riskId.includes(searchTerm) || 
                     riskName.includes(searchTerm) || 
                     riskDescription.includes(searchTerm) ||
                     riskCategoriesRaw.toLowerCase().includes(searchTerm) ||
                     status.toLowerCase().includes(searchTerm) ||
                     inherentLevel.toLowerCase().includes(searchTerm) ||
                     residualLevel.toLowerCase().includes(searchTerm)) : true;
                
                const matchesCategory = !selectedCategory || riskCategoriesRaw.replace(/["'\[\]]/g, '').includes(selectedCategory);
                const matchesRiskLevel = !selectedRiskLevel || currentRiskLevel.toUpperCase() === selectedRiskLevel.toUpperCase();
                const matchesStatus = !selectedStatus || status === selectedStatus;

                if (matchesSearch && matchesCategory && matchesRiskLevel && matchesStatus) {
                    row.style.display = '';
                    visibleRowCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update the count displayed
            document.getElementById('assigned-risks-count').textContent = `${visibleRowCount} risks assigned to you`;
        }

        // Function to set the type of risk level to filter by for managed risks (inherent or residual)
        function setManagedRiskLevelType(type) {
            currentManagedRiskLevelType = type;
            document.getElementById('managed-inherent-btn').classList.toggle('active', type === 'inherent');
            document.getElementById('managed-residual-btn').classList.toggle('active', type === 'residual');
            document.getElementById('managed-inherent-btn').style.background = type === 'inherent' ? '#E60012' : 'white';
            document.getElementById('managed-inherent-btn').style.color = type === 'inherent' ? 'white' : '#E60012';
            document.getElementById('managed-residual-btn').style.background = type === 'residual' ? '#E60012' : 'white';
            document.getElementById('managed-residual-btn').style.color = type === 'residual' ? 'white' : '#E60012';
            filterManagedRisks(); // Re-apply filters with the new type
        }

        // Function to clear managed risks filters
        function clearManagedFilters() {
            document.getElementById('managed-search').value = '';
            document.getElementById('managed-category').value = '';
            setManagedRiskLevelType('inherent'); // Reset to inherent and update button styles
            document.getElementById('managed-risk-level').value = '';
            filterManagedRisks(); // Apply the cleared filters
        }

        // Function to export managed risks to Excel
        function exportManagedToExcel() {
            const filters = getManagedFilterValues();
            // Construct the URL with export parameters
            const baseUrl = window.location.href.split('?')[0]; // Get base URL without query string
            const exportUrl = `${baseUrl}?export=excel&type=managed&filters=${encodeURIComponent(JSON.stringify(filters))}`;
            window.location.href = exportUrl;
        }

        // Function to get current filter values for managed risks
        function getManagedFilterValues() {
            const searchTerm = document.getElementById('managed-search').value.toLowerCase();
            const selectedCategory = document.getElementById('managed-category').value;
            const selectedRiskLevel = document.getElementById('managed-risk-level').value;

            const filters = {};
            if (searchTerm) filters.search = searchTerm;
            if (selectedCategory) filters.category = selectedCategory;
            if (selectedRiskLevel) filters.riskLevel = selectedRiskLevel;

            // Add the risk level type filter
            filters.riskLevelType = currentManagedRiskLevelType;
            
            return filters;
        }

        // Function to filter managed risks dynamically
        function filterManagedRisks() {
            const searchTerm = document.getElementById('managed-search').value.toLowerCase();
            const selectedCategory = document.getElementById('managed-category').value;
            const selectedRiskLevel = document.getElementById('managed-risk-level').value;
            const tableRows = document.querySelectorAll('#managed-risks-table tbody tr');
            let visibleRowCount = 0;

            tableRows.forEach(row => {
                const riskId = row.getAttribute('data-risk-id').toLowerCase();
                const riskName = row.getAttribute('data-risk-name').toLowerCase();
                const riskDescription = row.getAttribute('data-risk-description').toLowerCase();
                const riskCategoriesRaw = row.getAttribute('data-risk-categories') || '';
                const inherentLevel = row.getAttribute('data-inherent-level');
                const residualLevel = row.getAttribute('data-residual-level');

                let currentRiskLevel = 'Not Assessed';
                if (currentManagedRiskLevelType === 'inherent') {
                    currentRiskLevel = inherentLevel; // First level in Risk Levels column
                } else if (currentManagedRiskLevelType === 'residual') {
                    currentRiskLevel = residualLevel; // Second level in Risk Levels column
                }

                const matchesSearch = searchTerm ? 
                    (riskId.includes(searchTerm) || 
                     riskName.includes(searchTerm) || 
                     riskDescription.includes(searchTerm) ||
                     riskCategoriesRaw.toLowerCase().includes(searchTerm) ||
                     inherentLevel.toLowerCase().includes(searchTerm) ||
                     residualLevel.toLowerCase().includes(searchTerm)) : true;
                
                const matchesCategory = !selectedCategory || riskCategoriesRaw.replace(/["'\[\]]/g, '').includes(selectedCategory);
                const matchesRiskLevel = !selectedRiskLevel || currentRiskLevel.toUpperCase() === selectedRiskLevel.toUpperCase();

                if (matchesSearch && matchesCategory && matchesRiskLevel) {
                    row.style.display = '';
                    visibleRowCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update the count displayed
            document.getElementById('managed-risks-count').textContent = `${visibleRowCount} risks completed with proper assessment`;
        }
        
        // Event listeners for real-time filtering
        document.addEventListener('DOMContentLoaded', function() {
            
            // Department Risks filters
            document.getElementById('dept-search').addEventListener('input', filterDepartmentRisks);
            document.getElementById('dept-category').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-risk-level').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-status').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-owner').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-date-from').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-date-to').addEventListener('change', filterDepartmentRisks);
            
            // Assigned Risks filters
            document.getElementById('assigned-search').addEventListener('input', filterAssignedRisks);
            document.getElementById('assigned-category').addEventListener('change', filterAssignedRisks);
            document.getElementById('assigned-risk-level').addEventListener('change', filterAssignedRisks);
            document.getElementById('assigned-status').addEventListener('change', filterAssignedRisks);
            
            // Managed Risks filters
            document.getElementById('managed-search').addEventListener('input', filterManagedRisks);
            document.getElementById('managed-category').addEventListener('change', filterManagedRisks);
            document.getElementById('managed-risk-level').addEventListener('change', filterManagedRisks);
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
            // Risk Level Distribution Chart with toggle functionality
            const levelCtx = document.getElementById('levelChart');
            let levelChart;
            
            // Store both inherent and residual data
            const riskLevelData = {
                inherent: {
                    labels: ['Low Risk', 'Medium Risk', 'High Risk', 'Critical Risk', 'Not Assessed'],
                    data: [
                        <?php echo $inherent_risk_levels['low_risks']; ?>,
                        <?php echo $inherent_risk_levels['medium_risks']; ?>,
                        <?php echo $inherent_risk_levels['high_risks']; ?>,
                        <?php echo $inherent_risk_levels['critical_risks']; ?>,
                        <?php echo $inherent_risk_levels['not_assessed_risks']; ?>
                    ]
                },
                residual: {
                    labels: ['Low Risk', 'Medium Risk', 'High Risk', 'Critical Risk', 'Not Assessed'],
                    data: [
                        <?php echo $residual_risk_levels['low_risks']; ?>,
                        <?php echo $residual_risk_levels['medium_risks']; ?>,
                        <?php echo $residual_risk_levels['high_risks']; ?>,
                        <?php echo $residual_risk_levels['critical_risks']; ?>,
                        <?php echo $residual_risk_levels['not_assessed_risks']; ?>
                    ]
                }
            };
            
            if (levelCtx) {
                levelChart = new Chart(levelCtx, {
                    type: 'doughnut',
                    data: {
                        labels: riskLevelData.inherent.labels,
                        datasets: [{
                            data: riskLevelData.inherent.data,
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
            
            // Toggle function to switch between inherent and residual risk levels
            window.toggleRiskLevel = function(type) {
                const inherentBtn = document.getElementById('inherentToggle');
                const residualBtn = document.getElementById('residualToggle');
                
                if (type === 'inherent') {
                    inherentBtn.style.background = '#007bff';
                    inherentBtn.style.color = 'white';
                    residualBtn.style.background = 'transparent';
                    residualBtn.style.color = '#666';
                    
                    levelChart.data.datasets[0].data = riskLevelData.inherent.data;
                } else {
                    residualBtn.style.background = '#007bff';
                    residualBtn.style.color = 'white';
                    inherentBtn.style.background = 'transparent';
                    inherentBtn.style.color = '#666';
                    
                    levelChart.data.datasets[0].data = riskLevelData.residual.data;
                }
                
                levelChart.update();
            };
            
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
            const categoryFilter = document.getElementById('categoryFilter');
            const ownerFilter = document.getElementById('ownerFilter');
            const statusFilter = document.getElementById('statusFilter');
            const tableRows = document.querySelectorAll('.risk-report-row');
            
            function filterReports() {
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const selectedCategory = categoryFilter ? categoryFilter.value : '';
                const selectedOwner = ownerFilter ? ownerFilter.value : '';
                const selectedStatus = statusFilter ? statusFilter.value : '';
                
                if (tableRows) {
                    tableRows.forEach(row => {
                        const searchData = row.getAttribute('data-search') || '';
                        const rowStatus = row.getAttribute('data-status') || '';
                        const rowCategory = row.getAttribute('data-category') || '';
                        const rowOwner = row.getAttribute('data-owner') || '';
                        
                        const matchesSearch = searchTerm ? searchData.includes(searchTerm) : true;
                        const matchesCategory = !selectedCategory || rowCategory.includes(selectedCategory);
                        const matchesOwner = !selectedOwner || rowOwner === selectedOwner;
                        const matchesStatus = !selectedStatus || rowStatus === selectedStatus;
                        
                        if (matchesSearch && matchesCategory && matchesOwner && matchesStatus) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                }
            }
            
            if (searchInput) {
                searchInput.addEventListener('input', filterReports);
            }
            if (categoryFilter) {
                categoryFilter.addEventListener('change', filterReports);
            }
            if (ownerFilter) {
                ownerFilter.addEventListener('change', filterReports);
            }
            if (statusFilter) {
                statusFilter.addEventListener('change', filterReports);
            }

            // Add event listeners for department risk filters
            document.getElementById('dept-search').addEventListener('input', filterDepartmentRisks);
            document.getElementById('dept-category').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-risk-level').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-status').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-owner').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-date-from').addEventListener('change', filterDepartmentRisks);
            document.getElementById('dept-date-to').addEventListener('change', filterDepartmentRisks);
        });
        
        // Risk merging functionality
        let selectedRisks = []; // Array to store selected risk data objects
        
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.risk-checkbox');
            const selectAllCheckbox = document.getElementById('select-all-risks');
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const row = this.closest('tr');
                    if (this.checked) {
                        row.classList.add('selected');
                        addRiskToSelection(row);
                    } else {
                        row.classList.remove('selected');
                        removeRiskFromSelection(row);
                    }
                    updateMergeActionBar();
                    updateSelectAllCheckbox();
                });
            });
            
            // Handle select all checkbox
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const nonConsolidatedRows = Array.from(checkboxes).filter(cb => {
                        const row = cb.closest('tr');
                        return row.getAttribute('data-is-consolidated') === '0' && row.getAttribute('data-is-merged') === '0' && row.getAttribute('data-is-closed') === '0';
                    }).map(cb => cb.closest('tr'));
                    
                    nonConsolidatedRows.forEach(row => {
                        const checkbox = row.querySelector('.risk-checkbox');
                        checkbox.checked = this.checked;
                        if (this.checked) {
                            row.classList.add('selected');
                            addRiskToSelection(row);
                        } else {
                            row.classList.remove('selected');
                            removeRiskFromSelection(row);
                        }
                    });
                    updateMergeActionBar();
                    updateSelectAllCheckbox();
                });
            }
        });
        
        function addRiskToSelection(row) {
            const isConsolidated = row.getAttribute('data-is-consolidated') === '1';
            const isMerged = row.getAttribute('data-is-merged') === '1';
            const isClosed = row.getAttribute('data-is-closed') === '1';

            if (isConsolidated || isMerged || isClosed) return; // Don't add consolidated, merged, or closed risks
            
            const riskData = {
                risk_id: row.getAttribute('data-risk-id'),
                risk_name: row.getAttribute('data-risk-name'),
                year: row.getAttribute('data-risk-year'),
                month: row.getAttribute('data-risk-month'),
                number: row.getAttribute('data-risk-number'),
                status: row.getAttribute('data-status')
            };
            
            const exists = selectedRisks.some(r => r.risk_id === riskData.risk_id);
            if (!exists) {
                selectedRisks.push(riskData);
            }
        }
        
        function removeRiskFromSelection(row) {
            const riskId = row.getAttribute('data-risk-id');
            selectedRisks = selectedRisks.filter(r => r.risk_id !== riskId);
        }
        
        function updateSelectAllCheckbox() {
            const selectAllCheckbox = document.getElementById('select-all-risks');
            const checkboxes = document.querySelectorAll('.risk-checkbox');
            const selectableRows = Array.from(checkboxes).filter(cb => {
                const row = cb.closest('tr');
                return row.getAttribute('data-is-consolidated') === '0' && row.getAttribute('data-is-merged') === '0' && row.getAttribute('data-is-closed') === '0';
            });
            
            if (selectableRows.length === 0) {
                if (selectAllCheckbox) selectAllCheckbox.style.display = 'none'; // Hide if no selectable rows
                return;
            } else {
                if (selectAllCheckbox) selectAllCheckbox.style.display = 'block';
            }
            
            const allChecked = selectableRows.every(cb => cb.checked);
            const someChecked = selectableRows.some(cb => cb.checked);
            
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
                selectAllCheckbox.indeterminate = someChecked && !allChecked;
            }
        }
        
        function updateMergeActionBar() {
            const actionBar = document.getElementById('merge-action-bar');
            const selectedCount = document.getElementById('selected-count');
            const mergeBtn = document.getElementById('merge-risks-btn');
            
            if (selectedRisks.length >= 2) {
                actionBar.style.display = 'block';
                selectedCount.textContent = `${selectedRisks.length} risks selected`;
                mergeBtn.disabled = false;
                mergeBtn.style.opacity = '1';
            } else if (selectedRisks.length === 1) {
                actionBar.style.display = 'block';
                selectedCount.textContent = '1 risk selected (select at least 2 to merge)';
                mergeBtn.disabled = true;
                mergeBtn.style.opacity = '0.5';
                mergeBtn.style.cursor = 'not-allowed';
            } else {
                actionBar.style.display = 'none';
            }
        }
        
        function cancelMergeSelection() {
            selectedRisks = [];
            document.querySelectorAll('.risk-checkbox').forEach(cb => {
                cb.checked = false;
                cb.closest('tr').classList.remove('selected');
            });
            document.getElementById('select-all-risks').checked = false;
            document.getElementById('select-all-risks').indeterminate = false; // Reset indeterminate state
            updateMergeActionBar();
        }
        
        function handleMergeRisks() {
            if (selectedRisks.length < 2) {
                alert('Please select at least 2 risks to merge.');
                return;
            }
            
            // Get current month and year
            const now = new Date();
            const currentYear = now.getFullYear().toString();
            const currentMonth = (now.getMonth() + 1).toString().padStart(2, '0');
            
            // Validate: All risks must be from the current month and year
            const allFromCurrentMonth = selectedRisks.every(r => 
                r.year === currentYear && r.month === currentMonth
            );
            
            if (!allFromCurrentMonth) {
                alert('You can only merge risks from the current month (' + currentMonth + '/' + currentYear + ').');
                return;
            }
            
            // Validate: All risks must be from the same month and year (redundant but kept for clarity)
            const firstRisk = selectedRisks[0];
            const allSameMonthYear = selectedRisks.every(r => 
                r.year === firstRisk.year && r.month === firstRisk.month
            );
            
            if (!allSameMonthYear) {
                alert('All selected risks must be from the same month and year to merge.');
                return;
            }
            
            // Store selected risk IDs in session via AJAX
            const riskIds = selectedRisks.map(r => r.risk_id);
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `store_merge_risks=1&risk_ids=${encodeURIComponent(JSON.stringify(riskIds))}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Redirect to Report_risk.php
                    window.location.href = 'Report_risk.php?merge=1';
                } else {
                    alert('Failed to initiate merge. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }

        // Function to set the type of risk level to filter by for assigned risks (inherent or residual)
        // Note: This is a duplicate of setAssignedRiskLevelType, ensure proper function names are used if needed for distinct logic.
        // Keeping it consistent with the above pattern for now.
        function setDeptRiskLevelType(type) {
            currentDeptRiskLevelType = type;
            document.getElementById('dept-inherent-btn').classList.toggle('active', type === 'inherent');
            document.getElementById('dept-residual-btn').classList.toggle('active', type === 'residual');
            document.getElementById('dept-inherent-btn').style.background = type === 'inherent' ? '#E60012' : 'white';
            document.getElementById('dept-inherent-btn').style.color = type === 'inherent' ? 'white' : '#E60012';
            document.getElementById('dept-residual-btn').style.background = type === 'residual' ? '#E60012' : 'white';
            document.getElementById('dept-residual-btn').style.color = type === 'residual' ? 'white' : '#E60012';
            filterDepartmentRisks(); // Re-apply filters with the new type
        }

    </script>
</body>
</html>
