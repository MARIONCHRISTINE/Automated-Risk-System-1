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

$success = '';
$error = '';

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Risk reported successfully and assigned to you!';
}

// Function to handle file uploads
function handleFileUploads($files, $risk_id, $section_type, $db) {
    $upload_dir = 'uploads/risk_documents/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $uploaded_files = [];
    $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    if (isset($files['name']) && is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] == UPLOAD_ERR_OK) {
                $file_name = $files['name'][$i];
                $file_tmp = $files['tmp_name'][$i];
                $file_size = $files['size'][$i];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                if (!in_array($file_ext, $allowed_types)) {
                    throw new Exception("File type not allowed: $file_name");
                }
                
                if ($file_size > $max_size) {
                    throw new Exception("File too large: $file_name (max 10MB)");
                }
                
                $file_content = file_get_contents($file_tmp);
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file_tmp);
                finfo_close($finfo);
                
                $unique_name = $risk_id . '_' . $section_type . '_' . time() . '_' . $i . '.' . $file_ext;
                $file_path = $upload_dir . $unique_name;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    $query = "INSERT INTO risk_documents (risk_id, section_type, original_filename, stored_filename, file_path, file_size, document_content, mime_type, uploaded_by, uploaded_at)
                               VALUES (:risk_id, :section_type, :original_filename, :stored_filename, :file_path, :file_size, :document_content, :mime_type, :uploaded_by, NOW())";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':risk_id', $risk_id);
                    $stmt->bindParam(':section_type', $section_type);
                    $stmt->bindParam(':original_filename', $file_name);
                    $stmt->bindParam(':stored_filename', $unique_name);
                    $stmt->bindParam(':file_path', $file_path);
                    $stmt->bindParam(':file_size', $file_size);
                    $stmt->bindParam(':document_content', $file_content, PDO::PARAM_LOB);
                    $stmt->bindParam(':mime_type', $mime_type);
                    $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                    $stmt->execute();
                    
                    $uploaded_files[] = $file_name;
                } else {
                    throw new Exception("Failed to upload file: $file_name");
                }
            }
        }
    }
    
    return $uploaded_files;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Validate required fields
        if (empty($_POST['risk_description']) || empty($_POST['cause_of_risk'])) {
            throw new Exception('Risk description and cause are required');
        }
        
        // Handle new impact category selection
        $impact_category = $_POST['impact_category'] ?? null;
        $impact_level = $_POST['impact_level'] ?? null;
        $impact_description = $_POST['impact_description'] ?? null;
        
        if (empty($impact_category) || empty($impact_level)) {
            throw new Exception('Impact category and level must be selected');
        }
        
        // Handle likelihood selection
        $likelihood_level = $_POST['likelihood_level'] ?? null;
        $likelihood_description = $_POST['likelihood_description'] ?? null;
        
        if (empty($likelihood_level)) {
            throw new Exception('Likelihood level must be selected');
        }
        
        // Create risk name from impact category
        $risk_name = $impact_category . ' - ' . substr($_POST['risk_description'], 0, 50) . '...';
        
        // Store single category as JSON array for compatibility
        $risk_categories_json = json_encode([$_POST['risk_categories']]);
        
        // Store category details as JSON object
        $category_details = [
            $impact_category => $impact_description
        ];
        $category_details_json = json_encode($category_details);
        
        // Calculate risk rating (likelihood × impact)
        $risk_rating = intval($likelihood_level) * intval($impact_level);
        
        $query = "INSERT INTO risk_incidents (
            risk_name, risk_description, cause_of_risk, department, reported_by, risk_owner_id,
            existing_or_new, risk_categories, category_details,
            inherent_likelihood, inherent_consequence, 
            risk_rating,
            treatment_action, controls_action_plans, target_completion_date,
            progress_update, treatment_status, risk_status,
            involves_money_loss, money_amount,
            created_at, updated_at
        ) VALUES (
            :risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id,
            :existing_or_new, :risk_categories, :category_details,
            :inherent_likelihood, :inherent_consequence,
            :risk_rating,
            :treatment_action, :controls_action_plans, :target_completion_date,
            :progress_update, :treatment_status, :risk_status,
            :involves_money_loss, :money_amount,
            NOW(), NOW()
        )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_description', $_POST['risk_description']);
        $stmt->bindParam(':cause_of_risk', $_POST['cause_of_risk']);
        $stmt->bindParam(':department', $user['department']);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']);
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':risk_categories', $risk_categories_json);
        $stmt->bindParam(':category_details', $category_details_json);
        $stmt->bindParam(':inherent_likelihood', $likelihood_level);
        $stmt->bindParam(':inherent_consequence', $impact_level);
        $stmt->bindParam(':risk_rating', $risk_rating);
        $stmt->bindParam(':treatment_action', $_POST['treatment_action']);
        $stmt->bindParam(':controls_action_plans', $_POST['controls_action_plans']);
        $stmt->bindParam(':target_completion_date', $_POST['target_completion_date']);
        $stmt->bindParam(':progress_update', $_POST['progress_update']);
        $stmt->bindParam(':treatment_status', $_POST['treatment_status']);
        $stmt->bindParam(':risk_status', $_POST['risk_status']);
        $stmt->bindParam(':involves_money_loss', $_POST['involves_money_loss']);
        $stmt->bindParam(':money_amount', $_POST['money_amount']);
        
        if ($stmt->execute()) {
            $risk_id = $db->lastInsertId();
            
            // Insert category details into separate table if it exists
            if ($impact_category && $impact_description) {
                $category_query = "INSERT INTO risk_category_details (
                    risk_id, risk_category, category_description, category_type
                ) VALUES (?, ?, ?, ?)";
                
                $category_stmt = $db->prepare($category_query);
                $category_stmt->execute([$risk_id, $impact_category, $impact_description, 'impact_level']);
            }
            
            if (isset($_FILES['controls_documents']) && !empty($_FILES['controls_documents']['name'][0])) {
                $controls_files = handleFileUploads($_FILES['controls_documents'], $risk_id, 'controls_action_plans', $db);
            }
            
            if (isset($_FILES['progress_documents']) && !empty($_FILES['progress_documents']['name'][0])) {
                $progress_files = handleFileUploads($_FILES['progress_documents'], $risk_id, 'progress_update', $db);
            }
            
            if (isset($_FILES['supporting_document']) && $_FILES['supporting_document']['error'] == UPLOAD_ERR_OK) {
                $supporting_files = handleFileUploads($_FILES['supporting_document'], $risk_id, 'supporting_documents', $db);
            }
            
            $db->commit();
            header("Location: report_risk.php?success=1");
            exit();
        } else {
            $db->rollback();
            $error = "Failed to report comprehensive risk.";
        }
    } catch (Exception $e) {
        $db->rollback();
        $error = $e->getMessage();
    }
}

$all_notifications = getNotifications($db, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report New Risk - Airtel Risk Management</title>
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
        }
        
        .form-control:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        textarea.form-control {
            height: 100px;
            resize: vertical;
        }
        
        /* Updated styling for 2x2 grid layout and better visual distinction */
        .risk-categories-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            max-height: 400px;
            overflow-y: auto;
        }

        .category-item {
            margin-bottom: 20px;
            padding: 15px;
            border: 2px solid #e3f2fd;
            border-radius: 8px;
            background: #fafafa;
        }

        .category-item:last-child {
            border-bottom: 2px solid #e3f2fd;
        }

        /* Enhanced category name styling with color distinction */
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 0;
            padding: 12px;
            border-radius: 6px;
            background: linear-gradient(135deg,rgb(231, 9, 28),rgb(235, 152, 159));
            color: white;
            transition: all 0.3s ease;
        }

        .checkbox-label:hover {
            background: linear-gradient(135deg,rgb(248, 4, 4),rgb(241, 2, 2));
            transform: translateY(-1px);
        }

        .checkbox-label input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.4);
            accent-color: white;
        }

        /* 2x2 grid layout for impact levels */
        .impact-levels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 12px;
            margin-top: 15px;
            padding: 10px;
        }

        /* Square-styled radio buttons with distinct colors */
        .radio-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            border: 2px solid #4caf50;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #e8f5e8, #f1f8e9);
            min-height: 80px;
            text-align: center;
            font-size: 13px;
            line-height: 1.3;
        }

        .radio-label:hover {
            background: linear-gradient(135deg, #c8e6c9, #dcedc8);
            border-color: #388e3c;
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }

        .radio-label input[type="radio"] {
            margin-right: 8px;
            transform: scale(1.3);
            accent-color: #4caf50;
        }

        .radio-label input[type="radio"]:checked + span {
            font-weight: 600;
            color: #2e7d32;
        }

        /* Responsive design for smaller screens */
        @media (max-width: 768px) {
            .impact-levels {
                grid-template-columns: 1fr;
                grid-template-rows: repeat(4, 1fr);
            }
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
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .risk-matrix {
                grid-template-columns: 1fr;
            }
        }

        .impact-levels {
            display: flex;
            flex-direction: column;
        }

        .radio-label {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 5px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .radio-label:hover {
            background-color: rgba(230, 0, 18, 0.05);
        }

        .radio-label input[type="radio"] {
            margin-right: 8px;
            transform: scale(1.2);
            accent-color: #E60012;
        }
    </style>
</head>
<body>
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
                    // if (isset($_SESSION['user_id'])) {
                    //     renderNotificationBar($all_notifications);
                    // }
                    ?>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Risk Registration Form</h2>
                <div>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <div class="card-body">
                
                <div class="progress-indicator">
                    <div class="progress-step active">1. Identify</div>
                    <div class="progress-step">2. Assess</div>
                    <div class="progress-step">3. Treat</div>
                    <div class="progress-step">4. Monitor</div>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="section-header">
                        <i class="fas fa-search"></i> Section 1: Risk Identification
                    </div>
                    
                    <!-- Removed A. tag from Risk Categories label -->
                    <div class="form-group">
                        <label class="form-label">Risk Categories * <small>(Select all that apply)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Financial Exposure">
                                    <span class="checkmark">Financial Exposure [Revenue, Operating Expenditure, Book value]</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Decrease in market share">
                                    <span class="checkmark">Decrease in market share</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Customer Experience">
                                    <span class="checkmark">Customer Experience</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Compliance">
                                    <span class="checkmark">Compliance</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Reputation">
                                    <span class="checkmark">Reputation</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Fraud">
                                    <span class="checkmark">Fraud</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Operations">
                                    <span class="checkmark">Operations (Business continuity)</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Networks">
                                    <span class="checkmark">Networks</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="People">
                                    <span class="checkmark">People</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="IT">
                                    <span class="checkmark">IT (Cybersecurity & Data Privacy)</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Other">
                                    <span class="checkmark">Other</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Removed B. tag and changed text to sentence case -->
                    <div class="form-group">
                        <label class="form-label">Does your risk involves loss of money? *</label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="radio" name="involves_money_loss" value="yes" onchange="toggleMoneyAmount(this)">
                                    <span class="checkmark">Yes</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="radio" name="involves_money_loss" value="no" onchange="toggleMoneyAmount(this)">
                                    <span class="checkmark">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Amount input field that shows when Yes is selected -->
                        <div id="money-amount-section" style="display: none; margin-top: 15px;">
                            <label class="form-label">Amount (in your local currency) *</label>
                            <input type="number" name="money_amount" class="form-control" placeholder="Enter the estimated amount" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Risk Description *</label>
                        <textarea name="risk_description" class="form-control" required placeholder="Provide a detailed description of the risk"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cause of Risk *</label>
                        <textarea name="cause_of_risk" class="form-control" required placeholder="What causes this risk to occur?"></textarea>
                    </div>
                    
                    <!-- Added optional upload supporting document field after Cause of Risk -->
                    <div class="form-group">
                        <label class="form-label">Upload supporting document <small>(Optional)</small></label>
                        <input type="file" name="supporting_document" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt">
                        <small class="form-text text-muted">Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT (Max size: 10MB)</small>
                    </div>
                    
                    <div class="section-header">
                        <i class="fas fa-calculator"></i> Section 2: Risk Assessment
                    </div>
                    
                    <!-- Moved Existing or New Risk field to Section 2 -->
                    <div class="form-group">
                        <label class="form-label">Existing or New Risk *</label>
                        <select name="existing_or_new" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="New">New Risk</option>
                            <option value="Existing">Existing Risk</option>
                        </select>
                    </div>
                    
                    <!-- Simplified risk assessment with likelihood and impact side by side -->
                    <div class="risk-matrix">
                        <div class="matrix-title">Risk Rating</div>
                        
                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <!-- Replaced dropdown with clickable colored boxes for likelihood -->
                            <div style="flex: 1; min-width: 300px;">
                                <div class="form-group">
                                    <label class="form-label">Likelihood *</label>
                                    <div class="likelihood-boxes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                        
                                        <div class="likelihood-box" 
                                             style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectLikelihood(this, 4)"
                                             data-value="4">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">ALMOST CERTAIN</div>
                                            <div style="font-size: 12px; line-height: 1.3;">
                                                1. Guaranteed to happen<br>
                                                2. Has been happening<br>
                                                3. Continues to happen
                                            </div>
                                        </div>
                                        
                                        <div class="likelihood-box" 
                                             style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectLikelihood(this, 3)"
                                             data-value="3">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">LIKELY</div>
                                            <div style="font-size: 12px; line-height: 1.3;">
                                                A history of happening at certain intervals/seasons/events
                                            </div>
                                        </div>
                                        
                                        <div class="likelihood-box" 
                                             style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectLikelihood(this, 2)"
                                             data-value="2">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">POSSIBLE</div>
                                            <div style="font-size: 12px; line-height: 1.3;">
                                                1. More than 1 year from last occurrence<br>
                                                2. Circumstances indicating or allowing possibility of happening
                                            </div>
                                        </div>
                                        
                                        <div class="likelihood-box" 
                                             style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectLikelihood(this, 1)"
                                             data-value="1">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">UNLIKELY</div>
                                            <div style="font-size: 12px; line-height: 1.3;">
                                                1. Not occurred before<br>
                                                2. This is the first time its happening<br>
                                                3. Not expected to happen for sometime
                                            </div>
                                        </div>
                                        
                                    </div>
                                    <input type="hidden" name="likelihood_level" id="likelihood_value" required>
                                </div>
                            </div>
                            
                            <div style="flex: 1; min-width: 300px;">
                                <div class="form-group">
                                    <label class="form-label">Impact *</label>
                                    
                                    <!-- Added category dropdown for impact assessment -->
                                    <!-- Updated dropdown to match exact 10 categories from matrix -->
                                    <div class="form-group" style="margin-bottom: 15px;">
                                        <label class="form-label" style="font-size: 14px; margin-bottom: 8px;">Select Impact Category:</label>
                                        <select id="impact_category" name="impact_category" onchange="updateImpactBoxes()" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;" required>
                                            <option value="">-- Select Category --</option>
                                            <option value="financial">Financial Exposure (Revenue, Operating Expenditure, Book value)</option>
                                            <option value="market_share">Decrease in market share</option>
                                            <option value="customer">Customer Experience</option>
                                            <option value="compliance">Compliance</option>
                                            <option value="reputation">Reputation</option>
                                            <option value="fraud">Fraud</option>
                                            <option value="operations">Operations (Business continuity)</option>
                                            <option value="networks">Networks</option>
                                            <option value="people">People</option>
                                            <option value="it_cyber">IT (Cybersecurity & Data Privacy)</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Made impact boxes dynamic based on category selection -->
                                    <div class="impact-boxes" id="impact_boxes_container" style="display: none; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                        
                                        <div class="impact-box" id="extreme_box"
                                             style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectImpact(this, 4)"
                                             data-value="4">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div>
                                            <div id="extreme_text" style="font-size: 12px; line-height: 1.3;"></div>
                                        </div>
                                        
                                        <div class="impact-box" id="significant_box"
                                             style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectImpact(this, 3)"
                                             data-value="3">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div>
                                            <div id="significant_text" style="font-size: 12px; line-height: 1.3;"></div>
                                        </div>
                                        
                                        <div class="impact-box" id="moderate_box"
                                             style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectImpact(this, 2)"
                                             data-value="2">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div>
                                            <div id="moderate_text" style="font-size: 12px; line-height: 1.3;"></div>
                                        </div>
                                        
                                        <div class="impact-box" id="minor_box"
                                             style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                             onclick="selectImpact(this, 1)"
                                             data-value="1">
                                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div>
                                            <div id="minor_text" style="font-size: 12px; line-height: 1.3;"></div>
                                        </div>
                                        
                                    </div>
                                    <input type="hidden" name="impact_level" id="impact_value" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Impact Description</label>
                            <textarea name="impact_description" class="form-control" placeholder="Describe the impact of the risk"></textarea>
                        </div>

                        <input type="hidden" name="risk_rating" id="risk_rating">
                        <div id="risk_display" class="rating-display">Rating will appear here</div>
                    </div>

                    <div class="section-header">
                        <i class="fas fa-tools"></i> Section 3: Risk Treatment
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Treatment Action *</label>
                        <select name="treatment_action" class="form-control" required>
                            <option value="">Select Treatment</option>
                            <option value="Avoid">Avoid - Eliminate the risk</option>
                            <option value="Mitigate">Mitigate - Reduce likelihood/impact</option>
                            <option value="Transfer">Transfer - Share or transfer risk</option>
                            <option value="Accept">Accept - Accept the risk</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Controls/Action Plans *</label>
                        <textarea name="controls_action_plans" class="form-control" required placeholder="Describe the controls and action plans to manage this risk"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Target Completion Date</label>
                            <input type="date" name="target_completion_date" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Treatment Status *</label>
                            <select name="treatment_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="Not Started">Not Started</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="On Hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="section-header">
                        <i class="fas fa-chart-line"></i> Section 4: Progress Update
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Progress Update</label>
                        <textarea name="progress_update" class="form-control" placeholder="Provide updates on the progress of risk treatment activities"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Risk Status *</label>
                        <select name="risk_status" class="form-control" required>
                            <option value="">Select Status</option>
                            <option value="pending">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #dee2e6;">
                        <button type="submit" name="submit_comprehensive_risk" class="btn" style="padding: 1rem 3rem; font-size: 1.1rem;">
                            <i class="fas fa-save"></i> Submit Risk Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function selectLikelihood(element, value) {
            // Remove selection from all likelihood boxes
            document.querySelectorAll('.likelihood-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            
            // Highlight selected box
            element.style.border = '3px solid #333';
            
            // Set the hidden input value
            document.getElementById('likelihood_value').value = value;
            
            // Recalculate risk rating
            calculateRiskRating();
        }
        
        function selectImpact(element, value) {
            // Remove selection from all impact boxes
            document.querySelectorAll('.impact-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            
            // Highlight selected box
            element.style.border = '3px solid #333';
            
            // Set the hidden input value
            document.getElementById('impact_value').value = value;
            
            // Recalculate risk rating
            calculateRiskRating();
        }
        
        function calculateRiskRating() {
            const likelihood = document.getElementById('likelihood_value').value;
            const impact = document.getElementById('impact_value').value;
            
            if (likelihood && impact) {
                const rating = parseInt(likelihood) * parseInt(impact);
                let level = 'Low';
                let className = 'rating-low';
                
                if (rating >= 12) {
                    level = 'Critical';
                    className = 'rating-critical';
                } else if (rating >= 8) {
                    level = 'High';
                    className = 'rating-high';
                } else if (rating >= 4) {
                    level = 'Medium';
                    className = 'rating-medium';
                }
                
                document.getElementById('risk_rating').value = rating + ' - ' + level;
                document.getElementById('risk_display').innerHTML = 'Risk Rating: ' + rating + ' (' + level + ')';
                document.getElementById('risk_display').className = 'rating-display ' + className;
            }
        }
        
        function toggleMoneyAmount(radio) {
            const moneySection = document.getElementById('money-amount-section');
            const moneyInput = document.querySelector('input[name="money_amount"]');
            
            if (radio.value === 'yes') {
                moneySection.style.display = 'block';
                moneyInput.required = true;
            } else {
                moneySection.style.display = 'none';
                moneyInput.required = false;
                moneyInput.value = '';
            }
        }

        function updateImpactBoxes() {
            const category = document.getElementById('impact_category').value;
            const container = document.getElementById('impact_boxes_container');
            
            if (!category) {
                container.style.display = 'none';
                return;
            }
            
            const impactData = {
                financial: {
                    extreme: "Claims >5% of Company Revenue, Penalty >$50M-$1M",
                    significant: "Claims 1-5% of Company Revenue, Penalty $5M-$50M", 
                    moderate: "Claims 0.5%-1% of Company Revenue, Penalty $0.5M-$5M",
                    minor: "Claims <0.5% of Company Revenue"
                },
                market_share: {
                    extreme: "Decrease >5%",
                    significant: "Decrease 1%-5%",
                    moderate: "Decrease 0.5%-1%", 
                    minor: "Decrease <0.5%"
                },
                customer: {
                    extreme: "Breach of Customer Experience, Sanctions, Potential for legal action",
                    significant: "Sanctions, Potential for legal action",
                    moderate: "Sanctions, Potential for legal action",
                    minor: "Claims or Compliance"
                },
                compliance: {
                    extreme: "Breach of Regulatory Requirements, Sanctions, Potential for legal action, Penalty >$50M-$1M",
                    significant: "National Impact, Limited (social) media coverage",
                    moderate: "Isolated Impact",
                    minor: "No impact on brand"
                },
                reputation: {
                    extreme: "Reputation Impact, $1M Code of conduct for >2-3 days",
                    significant: "$1M Code of conduct for <2-3 days", 
                    moderate: "Isolated Impact",
                    minor: "No impact on brand"
                },
                fraud: {
                    extreme: "Capability Outage, System downtime >24hrs, System downtime from 1-5 days",
                    significant: "Network availability >36% but <50%, System downtime from 1-5 days, Frustration exceeds 30% threshold by 30%",
                    moderate: "Network availability >36% but <50%, Brief operational downtime <1 day, data loss averted due to timely intervention", 
                    minor: "Limited operational downtime, immediately resolved, Brief outage of business discipline"
                },
                operations: {
                    extreme: "Capability Outage, System downtime >24hrs, System downtime from 1-5 days",
                    significant: "Network availability >36% but <50%, System downtime from 1-5 days, Frustration exceeds 30% threshold by 30%",
                    moderate: "Network availability >36% but <50%, Brief operational downtime <1 day, data loss averted due to timely intervention",
                    minor: "Limited operational downtime, immediately resolved, Brief outage of business discipline"
                },
                networks: {
                    extreme: "Breach of cyber security and data privacy attempted and prevented, Breach that affects all users, 16% of customers",
                    significant: "Breach of cyber security and data privacy attempted and prevented, Any cyberattack and data",
                    moderate: "Network availability >36% but <50%, Frustration exceeds 30% threshold by 30%",
                    minor: "Network availability >36% but <50%, Frustration exceeds 30% threshold by 30%"
                },
                people: {
                    extreme: "1 Fatality, 4 Accidents",
                    significant: "1 Total employee turnover 5-15%, Succession planning for EC & critical positions 2 Accidents",
                    moderate: "1 Total employee turnover 5-15%, Succession planning for EC & critical positions 2 Accidents", 
                    minor: "1 Total employee turnover <5%, 1 Heavily injured 1 Accident"
                },
                it_cyber: {
                    extreme: "Breach of cyber security and data privacy attempted and prevented, Breach that affects all users, 16% of customers",
                    significant: "Breach of cyber security and data privacy attempted and prevented, Any cyberattack and data",
                    moderate: "Breach of cyber security and data privacy attempted and prevented, Any cyberattack and data",
                    minor: "Network availability >36% but <50%, Frustration exceeds 30% threshold by 30%"
                }
            };
            
            if (impactData[category]) {
                document.getElementById('extreme_text').textContent = impactData[category].extreme;
                document.getElementById('significant_text').textContent = impactData[category].significant;
                document.getElementById('moderate_text').textContent = impactData[category].moderate;
                document.getElementById('minor_text').textContent = impactData[category].minor;
                
                container.style.display = 'grid';
            }
        }
    </script>
</body>
</html>
