<?php
include_once 'includes/auth.php';
requireRole('staff');
include_once 'config/database.php';
include_once 'includes/auto_assignment.php';

$database = new Database();
$db = $database->getConnection();


function generateRiskId($db, $department) {
    try {
        // Get department initial from departments table
        $dept_query = "SELECT initial FROM departments WHERE department_name = :department LIMIT 1";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->bindParam(':department', $department);
        $dept_stmt->execute();
        
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dept_result) {
            throw new Exception("Department not found: " . $department);
        }
        
        $dept_initial = $dept_result['initial'];
        
        // Get current year and month
        $year = date('Y');
        $month = date('m');
        
        // Count existing risks for this department in current month
        $count_query = "SELECT COUNT(*) as count FROM risk_incidents 
                       WHERE department = :department 
                       AND YEAR(created_at) = :year 
                       AND MONTH(created_at) = :month";
        
        $count_stmt = $db->prepare($count_query);
        $count_stmt->bindParam(':department', $department);
        $count_stmt->bindParam(':year', $year);
        $count_stmt->bindParam(':month', $month);
        $count_stmt->execute();
        
        $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
        $sequential_number = $count_result['count'] + 1;
        
        // Generate the risk ID
        $risk_id = $dept_initial . '/' . $year . '/' . $month . '/' . $sequential_number;
        
        return $risk_id;
        
    } catch (Exception $e) {
        error_log("Error generating Risk ID: " . $e->getMessage());
        return null;
    }
}

if (isset($_POST['submit_risk'])) {
    try {
        // Process risk categories - single selection from radio button
        $risk_categories = isset($_POST['risk_categories']) ? [$_POST['risk_categories']] : [];
        
        // Process date of occurrence
        $date_of_occurrence = isset($_POST['date_of_occurrence']) ? $_POST['date_of_occurrence'] : null;
        
        $cause_of_risk_data = [];
        if (isset($_POST['cause_people_hidden']) && !empty($_POST['cause_people_hidden'])) {
            $people_data = json_decode($_POST['cause_people_hidden'], true);
            if (is_array($people_data) && count($people_data) > 0) {
                $cause_of_risk_data['People'] = $people_data;
            }
        }
        if (isset($_POST['cause_process_hidden']) && !empty($_POST['cause_process_hidden'])) {
            $process_data = json_decode($_POST['cause_process_hidden'], true);
            if (is_array($process_data) && count($process_data) > 0) {
                $cause_of_risk_data['Process'] = $process_data;
            }
        }
        if (isset($_POST['cause_it_systems_hidden']) && !empty($_POST['cause_it_systems_hidden'])) {
            $it_data = json_decode($_POST['cause_it_systems_hidden'], true);
            if (is_array($it_data) && count($it_data) > 0) {
                $cause_of_risk_data['IT Systems'] = $it_data;
            }
        }
        if (isset($_POST['cause_external_hidden']) && !empty($_POST['cause_external_hidden'])) {
            $external_data = json_decode($_POST['cause_external_hidden'], true);
            if (is_array($external_data) && count($external_data) > 0) {
                $cause_of_risk_data['External Environment'] = $external_data;
            }
        }
        $cause_of_risk_json = json_encode($cause_of_risk_data);
        
        // Process money loss data with ranges
        $involves_money_loss = isset($_POST['involves_money_loss']) ? ($_POST['involves_money_loss'] == 'yes' ? 1 : 0) : 0;
        $money_range = ($involves_money_loss && isset($_POST['money_range']) && !empty($_POST['money_range'])) ? 
                       $_POST['money_range'] : null;
        
        // Process GLPI reporting
        $reported_to_glpi = isset($_POST['reported_to_glpi']) ? ($_POST['reported_to_glpi'] == 'yes' ? 1 : 0) : 0;
        $glpi_ir_number = ($reported_to_glpi && isset($_POST['glpi_ir_number']) && !empty($_POST['glpi_ir_number'])) ? 
                          trim($_POST['glpi_ir_number']) : null;
        
        // Get risk description
        $risk_description = trim($_POST['risk_description']);
        
        // Generate risk name
        $primary_category = $risk_categories[0] ?? 'General';
        $description_preview = strlen($risk_description) > 50 ? 
                              substr($risk_description, 0, 50) . '...' : $risk_description;
        $risk_name = $primary_category . ' - ' . $description_preview;
        
        // Get user information
        $user_id = $_SESSION['user_id'];
        $user = getCurrentUser();
        
        // Ensure department is available
        if (empty($user['department']) || $user['department'] === null) {
            $dept_query = "SELECT department FROM users WHERE id = :user_id";
            $dept_stmt = $db->prepare($dept_query);
            $dept_stmt->bindParam(':user_id', $user_id);
            $dept_stmt->execute();
            $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
            if ($dept_result && !empty($dept_result['department'])) {
                $user['department'] = $dept_result['department'];
                $_SESSION['department'] = $dept_result['department'];
            }
        }
        $department = $user['department'] ?? 'General';

        // Handle file upload (mandatory)
        $uploaded_file_path = null;
        if (!isset($_FILES['supporting_document']) || $_FILES['supporting_document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Supporting document is mandatory. Please upload a file.");
        }
        
        $upload_dir = 'uploads/risk_documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['supporting_document']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'msg', 'eml'];
        $max_file_size = 10 * 1024 * 1024; // 10MB
        
        if (!in_array($file_extension, $allowed_extensions)) {
            throw new Exception("Invalid file type. Please upload PDF, DOC, DOCX, TXT, JPG, PNG, Excel, or email files.");
        }
        
        if ($_FILES['supporting_document']['size'] > $max_file_size) {
            throw new Exception("File too large. Please upload files under 10MB.");
        }
        
        $file_name = uniqid() . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $file_name;
        if (!move_uploaded_file($_FILES['supporting_document']['tmp_name'], $upload_path)) {
            throw new Exception("Failed to upload supporting document.");
        }
        $uploaded_file_path = $upload_path;

        // Verify user session and database user
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            throw new Exception("Session expired. Please log in again.");
        }

        $user_check_query = "SELECT id FROM users WHERE id = :user_id";
        $user_check_stmt = $db->prepare($user_check_query);
        $user_check_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $user_check_stmt->execute();
        
        if ($user_check_stmt->rowCount() == 0) {
            throw new Exception("User account not found. Please contact administrator.");
        }

        // Begin transaction for data integrity
        $db->beginTransaction();
        
        // Insert risk incident
        $query = "INSERT INTO risk_incidents (
                    risk_name,
                    risk_categories, 
                    date_of_occurrence,
                    risk_description, 
                    cause_of_risk, 
                    department, 
                    reported_by,
                    risk_owner_id,
                    involves_money_loss,
                    money_range,
                    reported_to_glpi,
                    glpi_ir_number,
                    existing_or_new,
                    risk_status,
                    created_at, 
                    updated_at
                  ) VALUES (
                    :risk_name,
                    :risk_categories, 
                    :date_of_occurrence,
                    :risk_description, 
                    :cause_of_risk, 
                    :department, 
                    :reported_by,
                    :risk_owner_id,
                    :involves_money_loss,
                    :money_range,
                    :reported_to_glpi,
                    :glpi_ir_number,
                    'New',
                    'pending',
                    NOW(), 
                    NOW()
                  )";

        $stmt = $db->prepare($query);
        
        // Prepare data for binding
        $risk_categories_json = json_encode($risk_categories);
        
        // Bind parameters
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_categories', $risk_categories_json);
        $stmt->bindParam(':date_of_occurrence', $date_of_occurrence);
        $stmt->bindParam(':risk_description', $risk_description);
        $stmt->bindParam(':cause_of_risk', $cause_of_risk_json);
        $stmt->bindParam(':department', $department);
        $stmt->bindParam(':reported_by', $user_id);
        $stmt->bindParam(':risk_owner_id', $user_id);
        $stmt->bindParam(':involves_money_loss', $involves_money_loss);
        $stmt->bindParam(':money_range', $money_range);
        $stmt->bindParam(':reported_to_glpi', $reported_to_glpi);
        $stmt->bindParam(':glpi_ir_number', $glpi_ir_number);

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert risk incident.");
        }
        
        $risk_incident_id = $db->lastInsertId();
        
        // Generate and update the risk_id
        $generated_risk_id = generateRiskId($db, $department);
        
        if ($generated_risk_id) {
            $update_risk_id_query = "UPDATE risk_incidents SET risk_id = :risk_id WHERE id = :id";
            $update_risk_id_stmt = $db->prepare($update_risk_id_query);
            $update_risk_id_stmt->bindParam(':risk_id', $generated_risk_id);
            $update_risk_id_stmt->bindParam(':id', $risk_incident_id);
            
            if (!$update_risk_id_stmt->execute()) {
                throw new Exception("Failed to update risk ID.");
            }
        } else {
            throw new Exception("Failed to generate risk ID.");
        }
        
        // Handle document upload
        if ($uploaded_file_path) {
            $file_content = file_get_contents($uploaded_file_path);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $uploaded_file_path);
            finfo_close($finfo);
            
            $doc_query = "INSERT INTO risk_documents (
                            risk_id, 
                            section_type, 
                            original_filename, 
                            stored_filename, 
                            file_path, 
                            file_size, 
                            document_content,
                            mime_type,
                            uploaded_by, 
                            uploaded_at
                          ) VALUES (
                            :risk_id, 
                            'risk_identification', 
                            :original_filename, 
                            :stored_filename, 
                            :file_path, 
                            :file_size, 
                            :document_content,
                            :mime_type,
                            :uploaded_by, 
                            NOW()
                          )";
            
            $doc_stmt = $db->prepare($doc_query);
            $original_filename = $_FILES['supporting_document']['name'];
            $stored_filename = basename($uploaded_file_path);
            $file_size = $_FILES['supporting_document']['size'];
            
            $doc_stmt->bindParam(':risk_id', $risk_incident_id);
            $doc_stmt->bindParam(':original_filename', $original_filename);
            $doc_stmt->bindParam(':stored_filename', $stored_filename);
            $doc_stmt->bindParam(':file_path', $uploaded_file_path);
            $doc_stmt->bindParam(':file_size', $file_size);
            $doc_stmt->bindParam(':document_content', $file_content, PDO::PARAM_LOB);
            $doc_stmt->bindParam(':mime_type', $mime_type);
            $doc_stmt->bindParam(':uploaded_by', $user_id);
            
            if (!$doc_stmt->execute()) {
                throw new Exception("Failed to upload supporting document.");
            }
        }
        
        // Commit transaction
        $db->commit();
        
        // Call auto-assignment function
        $assignment_result = assignRiskAutomatically($risk_incident_id, $_SESSION['user_id'], $db);

        if ($assignment_result['success']) {
            header("Location: staff_dashboard.php?success=assigned");
            exit();
        } else {
            header("Location: staff_dashboard.php?success=no_owner_designated");
            exit();
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error_message = "Error: " . $e->getMessage();
        error_log("Risk submission error: " . $e->getMessage());
    } catch (PDOException $e) {
        // Rollback transaction on database error
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error_message = "Database error occurred. Please try again.";
        error_log("Risk submission database error: " . $e->getMessage());
    }
}

// Handle success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'assigned':
            $success_message = "Risk reported and immediately assigned to your designated risk owner!";
            break;
        case 'no_owner_designated':
            $success_message = "Risk reported successfully! No designated risk owner found for your account. Please contact your administrator.";
            break;
        case 'reported':
            $success_message = "Risk reported successfully! Assignment in progress.";
            break;
        case 'no_owner':
            $success_message = "Risk reported successfully! No risk owners available in your department at the moment.";
            break;
    }
}

$query = "SELECT r.*, 
                 u.username as reporter_username,
                 u.full_name as reporter_full_name,
                 (SELECT COUNT(*) FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification') as has_document,
                 (SELECT rd.file_path FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification' LIMIT 1) as document_path,
                 (SELECT rd.original_filename FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification' LIMIT 1) as document_filename
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id
          WHERE r.reported_by = :user_id 
          ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info with department from database
$user = getCurrentUser();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Airtel Risk Management</title>
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
            padding-top: 100px;
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
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        .main-cards-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .hero {
            text-align: center;
            padding: 5rem 3rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }
        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 1.5rem 2.5rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        .cta-button:hover {
            background: #B8000E;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 300px;
            position: relative;
            border-left: 6px solid #E60012;
        }
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        .stat-number {
            font-size: 4.5rem;
            font-weight: 800;
            color: #E60012;
            margin-bottom: 1rem;
        }
        .stat-label {
            font-size: 1.4rem;
            font-weight: 600;
            color: #666;
            margin-bottom: 1.5rem;
        }
        .stat-hint {
            color: #E60012;
            font-size: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .reports-section {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            display: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .reports-section.show {
            display: block;
        }
        .reports-header {
            background: #E60012;
            padding: 1.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .reports-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .hide-reports-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .hide-reports-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .reports-content {
            max-height: 400px;
            overflow-y: auto;
        }
        .risk-item {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
        }
        .risk-item:hover {
            background: #f8f9fa;
        }
        .risk-item:last-child {
            border-bottom: none;
        }
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .risk-info {
            flex: 1;
        }
        .risk-id {
            font-size: 0.9rem;
            color: white;
            background: #E60012;
            font-weight: 700;
            margin-bottom: 0.5rem;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            display: inline-block;
            box-shadow: 0 2px 6px rgba(230, 0, 18, 0.3);
        }
        .risk-name {
            font-weight: 600;
            color: #333;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .view-btn {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #E60012;
            color: white;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
            white-space: nowrap;
        }
        .view-btn:hover {
            background: #B8000E;
        }
        .risk-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #666;
        }
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
        }
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .empty-state h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .empty-state p {
            color: #666;
            margin-bottom: 1.5rem;
        }
        .success {
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            padding: 1.2rem;
            border-radius: 8px;
            border-left: 4px solid #A50010;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
            animation: slideInRight 0.5s ease-out;
            position: fixed;
            top: 120px;
            right: 20px;
            max-width: 400px;
            z-index: 2000;
            overflow: hidden;
        }
        .success.fade-out {
            animation: fadeOutRight 0.5s ease-out forwards;
        }
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        @keyframes fadeOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1.2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        .chatbot {
            position: fixed;
            bottom: 25px;
            right: 25px;
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(230, 0, 18, 0.3);
            color: white;
            font-size: 1.6rem;
            transition: transform 0.3s;
            z-index: 1000;
        }
        .chatbot:hover {
            transform: scale(1.1);
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
            background: white;
            border-radius: 8px;
            width: 95%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            margin-bottom: 1.8rem;
        }
        label {
            display: block;
            margin-bottom: 0.6rem;
            color: #333;
            font-weight: 500;
            font-size: 1rem;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        textarea {
            height: 120px;
            resize: vertical;
        }
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.9rem 2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #B8000E;
        }
        .styled-file-container {
            margin-top: 10px;
        }
        .styled-file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        .styled-file-input {
            position: absolute;
            left: -9999px;
        }
        .styled-file-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 20px;
            border: 2px dashed #ddd;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
            font-size: 1rem;
        }
        .styled-file-label:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
            color: #E60012;
        }
        .styled-file-label i {
            font-size: 1.5rem;
        }
        .risk-categories-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            max-height: 400px;
            overflow-y: auto;
        }
        .category-item {
            margin-bottom: 15px;
        }
        .category-item:last-child {
            margin-bottom: 0;
        }
        .radio-category-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 500;
            font-size: 16px;
            margin: 0;
            padding: 15px 20px;
            border-radius: 8px;
            background: linear-gradient(135deg, #E60012, #B8000E);
            color: white;
            transition: all 0.3s ease;
            width: 100%;
            box-shadow: 0 2px 4px rgba(230, 0, 18, 0.2);
        }
        .radio-category-label:hover {
            background: linear-gradient(135deg, #B8000E, #A50010);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        .radio-category-label input[type="radio"] {
            margin-right: 15px;
            width: 18px;
            height: 18px;
            accent-color: white;
            cursor: pointer;
        }
        .checkmark {
            color: white;
            font-weight: 500;
        }
        .document-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.3s;
            margin-top: 0.5rem;
        }
        .document-link:hover {
            background: #B8000E;
            color: white;
            text-decoration: none;
        }
        .radio-group {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .radio-option {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 12px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            background: white;
            transition: all 0.3s ease;
            min-width: 120px;
            font-weight: 500;
        }
        .radio-option:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
        }
        .radio-option input[type="radio"] {
            display: none;
        }
        .radio-custom {
            width: 20px;
            height: 20px;
            border: 2px solid #ddd;
            border-radius: 50%;
            margin-right: 12px;
            position: relative;
            transition: all 0.3s ease;
        }
        .radio-option input[type="radio"]:checked + .radio-custom {
            border-color: #E60012;
            background: #E60012;
        }
        .radio-option input[type="radio"]:checked + .radio-custom::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
        }
        .radio-option input[type="radio"]:checked ~ .radio-text {
            color: #E60012;
            font-weight: 600;
        }
        .radio-text {
            font-size: 1rem;
            color: #333;
            transition: color 0.3s ease;
        }
        .modal-info-display {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            border-left: 3px solid #E60012;
            margin-bottom: 1rem;
        }
        .section-header {
            color: #E60012;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #E60012;
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .conditional-section {
            display: none;
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-left: 3px solid #E60012;
            border-radius: 4px;
        }
        .conditional-section.show {
            display: block;
        }
        .dropdown-container {
            margin-top: 10px;
        }
        .cause-dropdown {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            background: white;
        }
        .cause-dropdown:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        .info-text {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
            font-style: italic;
        }

        .form-label {
            display: block;
            margin-bottom: 0.8rem;
            color: #E60012;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            border-left: 4px solid #E60012;
            padding-left: 12px;
            background: linear-gradient(90deg, rgba(230, 0, 18, 0.05) 0%, transparent 100%);
            padding-top: 8px;
            padding-bottom: 8px;
            border-radius: 4px;
        }
        
        .form-label small {
            text-transform: none;
            font-weight: 500;
            font-size: 0.85rem;
            color: #666;
            display: block;
            margin-top: 4px;
        }
        
        .cause-cards-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .cause-card {
            background: white;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
        }
        
        .cause-card:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.15);
        }
        
        .cause-card-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .cause-card-title {
            font-weight: 600;
            color: #333;
            font-size: 1rem;
            margin-bottom: 5px;
        }
        
        .cause-card-count {
            font-size: 0.85rem;
            color: #E60012;
            font-weight: 600;
            margin-top: 8px;
            min-height: 20px;
        }
        
        .cause-card.has-selections {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.08);
        }
        
        .cause-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
        }
        
        .cause-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .cause-modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        
        .cause-modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .cause-modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        
        .cause-modal-close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s;
        }
        
        .cause-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .cause-modal-body {
            padding: 2rem;
        }
        
        .checkbox-item {
            margin-bottom: 12px;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .checkbox-item:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
        }
        
        .checkbox-item.checked {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.08);
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            margin: 0;
            font-weight: 500;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
            accent-color: #E60012;
        }
        
        .cause-modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #e1e5e9;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
        }
        
        .cause-modal-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-size: 1rem;
        }
        
        .cause-modal-btn-cancel {
            background: #f8f9fa;
            color: #333;
            border: 2px solid #e1e5e9;
        }
        
        .cause-modal-btn-cancel:hover {
            background: #e9ecef;
        }
        
        .cause-modal-btn-save {
            background: #E60012;
            color: white;
        }
        
        .cause-modal-btn-save:hover {
            background: #B8000E;
        }
        
        @media (max-width: 768px) {
            body {
                padding-top: 120px;
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
            .main-cards-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .hero {
                padding: 3rem 2rem;
                min-height: 250px;
            }
            .stat-card {
                padding: 2.5rem 1.5rem;
                min-height: 250px;
            }
            .stat-number {
                font-size: 3.5rem;
            }
            .stat-label {
                font-size: 1.2rem;
            }
            .stat-hint {
                font-size: 0.9rem;
            }
            .reports-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            .main-content {
                padding: 1rem;
            }
            .risk-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .logout-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            .modal-body {
                padding: 1.5rem;
            }
            .cause-cards-container {
                grid-template-columns: 1fr;
            }
            
            .cause-modal-content {
                width: 95%;
                max-height: 85vh;
            }
            
            .cause-modal-body {
                padding: 1.5rem;
            }
            .success {
                right: 10px;
                left: 10px;
                max-width: calc(100% - 20px);
            }
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
                        <h1 class="main-title">Airtel Risk Management</h1>
                        <p class="sub-title">Risk Management System</p>
                    </div>
                </div>
                <div class="header-right">
                    <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'S'; ?></div>
                    <div class="user-details">
                        <div class="user-email"><?php echo $_SESSION['email']; ?></div>
                        <div class="user-role">Staff • <?php echo $user['department'] ?? 'General'; ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        <main class="main-content">
            <?php if (isset($success_message)): ?>
                <div class="success" id="successNotification"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <div class="main-cards-layout">
                <section class="hero">
                    <div style="text-align: center;">
                        <button class="cta-button" onclick="openReportModal()">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            Report New Risk
                        </button>
                    </div>
                </section>
                <div class="stat-card" id="statsCard" onclick="scrollToReports()">
                    <div class="stat-number"><?php echo count($user_risks); ?></div>
                    <div class="stat-label">Risks Reported</div>
                    <div class="stat-hint">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                        Click to view details
                    </div>
                </div>
            </div>
            <section class="reports-section" id="reportsSection">
                <div class="reports-header">
                    <h2 class="reports-title">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Your Recent Reports
                    </h2>
                    <button class="hide-reports-btn" onclick="closeReports()">Click to Hide</button>
                </div>
                <div class="reports-content">
                    <?php if (count($user_risks) > 0): ?>
                        <?php foreach (array_slice($user_risks, 0, 10) as $risk): ?>
                            <div class="risk-item">
                                <div class="risk-header">
                                    <div class="risk-info"> 
                                        <div class="risk-id"><?php echo htmlspecialchars($risk['risk_id'] ?? 'Pending'); ?></div>
                                        <div class="risk-name"><?php echo htmlspecialchars($risk['risk_name']); ?></div>
                                    </div>
                                    <button class="view-btn" onclick='viewRisk(<?php echo json_encode($risk); ?>)'>
                                        View
                                    </button>
                                </div>
                                <div class="risk-meta">
                                    <span><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></span>
                                    <?php if ($risk['risk_owner_id']): ?>
                                        <span style="color:#E60012;">• Risk Owner Assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">📋</div>
                            <h3>No risks reported yet</h3>
                            <p>Start by reporting your first risk using the button above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    
     <!-- Report New Risk Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report New Risk</h3>
                <button class="close" onclick="closeReportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="riskForm" method="POST" enctype="multipart/form-data" style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div class="section-header">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Section 1: Risk Identification
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">A. Risk Categories * <small>(Select one primary risk)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Financial Exposure" required>
                                    <span class="checkmark">Financial Exposure [Revenue, Operating Expenditure, Book value]</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Decrease in market share" required>
                                    <span class="checkmark">Decrease in market share</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Customer Experience" required>
                                    <span class="checkmark">Customer Experience</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Compliance" required>
                                    <span class="checkmark">Compliance</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Reputation" required>
                                    <span class="checkmark">Reputation</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Fraud" required>
                                    <span class="checkmark">Fraud</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Operations" required>
                                    <span class="checkmark">Operations (Business continuity)</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Networks" required>
                                    <span class="checkmark">Networks</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="People" required>
                                    <span class="checkmark">People</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="IT" required>
                                    <span class="checkmark">IT (Cybersecurity & Data Privacy)</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Other" required>
                                    <span class="checkmark">Other</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">B. Date of Occurrence *</label>
                        <input type="date" name="date_of_occurrence" class="form-control" required max="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 0.9rem; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 1rem;">
                        <small class="info-text">Select the date when the risk occurred</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">C. Does your risk involves loss of money? *</label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="involves_money_loss" value="yes" onchange="toggleMoneyRange(this)" required>
                                    <span class="checkmark">Yes</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="involves_money_loss" value="no" onchange="toggleMoneyRange(this)" required>
                                    <span class="checkmark">No</span>
                                </label>
                            </div>
                        </div>
                        <div id="money-range-section" class="conditional-section">
                            <label class="form-label">Select Money Range *</label>
                            <select name="money_range" class="cause-dropdown">
                                <option value="">-- Select Range --</option>
                                <option value="0 - 100,000">0 - 100,000</option>
                                <option value="100,001 - 500,000">100,001 - 500,000</option>
                                <option value="500,001 - 1,000,000">500,001 - 1,000,000</option>
                                <option value="1,000,001 - 2,500,000">1,000,001 - 2,500,000</option>
                                <option value="2,500,001 - 5,000,000">2,500,001 - 5,000,000</option>
                                <option value="5,000,000+">5,000,000+</option>
                            </select>
                            <small class="info-text">Select the estimated financial impact range</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">D. Risk Description *</label>
                        <div class="styled-textarea-container">
                            <textarea name="risk_description" class="styled-textarea" required placeholder="Example: On 2025/01/15 afternoon, at the Nairobi branch office, a system outage occurred due to server failure, causing customer service disruptions for 3 hours. Approximately 500 customers were unable to access Airtel mobile money." style="width: 100%; padding: 0.9rem; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 1rem; height: 150px; resize: vertical;"></textarea>
                        </div>
                        <small class="info-text">Describe WHAT happened, WHERE it occurred, HOW it happened, and WHEN it took place</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">E. Cause of Risk *</label>
                        <small class="info-text" style="display: block; margin-bottom: 15px;">Select ONE category below, then choose multiple causes within that category.</small>
                        
                        <div class="cause-cards-container">
                            <div class="cause-card" id="peopleCard" onclick="openCauseModal('people')">
                                <div class="cause-card-icon">👥</div>
                                <div class="cause-card-title">People</div>
                                <div class="cause-card-count" id="peopleCount">0 selected</div>
                            </div>
                            
                            <div class="cause-card" id="processCard" onclick="openCauseModal('process')">
                                <div class="cause-card-icon">⚙️</div>
                                <div class="cause-card-title">Process</div>
                                <div class="cause-card-count" id="processCount">0 selected</div>
                            </div>
                            
                            <div class="cause-card" id="itSystemsCard" onclick="openCauseModal('itSystems')">
                                <div class="cause-card-icon">💻</div>
                                <div class="cause-card-title">IT Systems</div>
                                <div class="cause-card-count" id="itSystemsCount">0 selected</div>
                            </div>
                            
                            <div class="cause-card" id="externalCard" onclick="openCauseModal('external')">
                                <div class="cause-card-icon">🌍</div>
                                <div class="cause-card-title">External Environment</div>
                                <div class="cause-card-count" id="externalCount">0 selected</div>
                            </div>
                        </div>
                        
                        <div id="causeSelectionSummary" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #E60012; display: none;">
                            <strong style="color: #E60012; display: block; margin-bottom: 10px;">Your Selections:</strong>
                            <div id="summaryContent" style="font-size: 0.9rem; color: #333;"></div>
                        </div>
                        
                        <input type="hidden" name="cause_people_hidden" id="cause_people_hidden">
                        <input type="hidden" name="cause_process_hidden" id="cause_process_hidden">
                        <input type="hidden" name="cause_it_systems_hidden" id="cause_it_systems_hidden">
                        <input type="hidden" name="cause_external_hidden" id="cause_external_hidden">
                    </div>

                    <div class="form-group">
                        <label class="form-label">F. Have you reported to GLPI? *</label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="reported_to_glpi" value="yes" onchange="toggleGLPISection(this)" required>
                                    <span class="checkmark">Yes</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="reported_to_glpi" value="no" onchange="toggleGLPISection(this)" required>
                                    <span class="checkmark">No</span>
                                </label>
                            </div>
                        </div>
                        <div id="glpi-ir-section" class="conditional-section">
                            <label class="form-label">Provide IR Number *</label>
                            <input type="text" name="glpi_ir_number" class="form-control" placeholder="Example: 1234567" style="width: 100%; padding: 0.9rem; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 1rem;">
                            <small class="info-text">Enter the Ticket number from the raised incidentin GLPI system.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">G. Supporting Documents * (Mandatory)</label>
                        <div class="styled-file-container">
                            <div class="styled-file-upload">
                                <input type="file" name="supporting_document" class="styled-file-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.xlsx,.xls,.msg,.eml" required id="fileInput" onchange="displayFileName()">
                                <label class="styled-file-label" for="fileInput">
                                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                    <span id="fileLabel">Click to upload supporting document</span>
                                </label>
                            </div>
                            <small class="form-text text-muted" style="display: block; margin-top: 10px; color: #666; text-align: center;">
                                <strong>Required:</strong> Please provide screenshots, images, PDFs, Excel sheets, email trails, or other relevant documentation<br>
                                Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG, TXT, XLSX, XLS, MSG, EML (Max size: 10MB)
                            </small>
                        </div>
                    </div>

                    <button type="submit" name="submit_risk" style="background: #E60012; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; width: 100%; font-weight: 600;">
                        Submit Risk Identification
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="causeModal" class="cause-modal">
        <div class="cause-modal-content">
            <div class="cause-modal-header">
                <h3 class="cause-modal-title" id="causeModalTitle">Select Causes</h3>
                <button class="cause-modal-close" onclick="closeCauseModal()">&times;</button>
            </div>
            <div class="cause-modal-body" id="causeModalBody">
            </div>
            <div class="cause-modal-footer">
                <button class="cause-modal-btn cause-modal-btn-cancel" onclick="closeCauseModal()">Cancel</button>
                <button class="cause-modal-btn cause-modal-btn-save" onclick="saveCauseSelections()">Save Selections</button>
            </div>
        </div>
    </div>

    <div id="riskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Risk Details</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Risk ID:</label>
                    <div class="modal-info-display" id="modalRiskId"></div>
                </div>
                <div class="form-group">
                    <label>Risk Name:</label>
                    <div class="modal-info-display" id="modalRiskName"></div>
                </div>
                <div class="form-group">
                    <label>Risk Categories:</label>
                    <div class="modal-info-display" id="modalRiskCategory"></div>
                </div>
                <div class="form-group">
                    <label>Date of Occurrence:</label>
                    <div class="modal-info-display" id="modalDateOccurrence"></div>
                </div>
                <div class="form-group">
                    <label>Department:</label>
                    <div class="modal-info-display" id="modalDepartment"></div>
                </div>
                <div class="form-group">
                    <label>Risk Description:</label>
                    <div class="modal-info-display" id="modalRiskDescription"></div>
                </div>
                <div class="form-group">
                    <label>Cause of Risk:</label>
                    <div class="modal-info-display" id="modalCauseOfRisk"></div>
                </div>
                <div class="form-group">
                    <label>Involves Money Loss:</label>
                    <div class="modal-info-display" id="modalMoneyLoss"></div>
                </div>
                <div class="form-group" id="moneyRangeSection" style="display: none;">
                    <label>Money Range:</label>
                    <div class="modal-info-display" id="modalMoneyRange"></div>
                </div>
                <div class="form-group">
                    <label>Reported to GLPI:</label>
                    <div class="modal-info-display" id="modalGLPI"></div>
                </div>
                <div class="form-group" id="glpiNumberSection" style="display: none;">
                    <label>GLPI IR Number:</label>
                    <div class="modal-info-display" id="modalGLPINumber"></div>
                </div>
                <div class="form-group">
                    <label>Date Submitted:</label>
                    <div class="modal-info-display" id="modalDateSubmitted"></div>
                </div>
                <div class="form-group">
                    <label>Assignment Status:</label>
                    <div class="modal-info-display" id="modalAssignmentStatus"></div>
                </div>
                <div class="form-group" id="documentSection" style="display: none;">
                    <label>Supporting Document:</label>
                    <div class="modal-info-display">
                        <a href="#" id="documentLink" class="document-link" target="_blank">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            View Document
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="chatbot" onclick="openChatbot()" title="Need help? Click to chat">💬</div>

    <script>
        const causeData = {
            people: {
                title: 'People',
                icon: '👥',
                options: [
                    'Training/awareness deficiencies',
                    'Human error (mistakes, miscalculations)',
                    'Negligence or carelessness',
                    'Fraud or internal misconduct',
                    'Non-compliance with procedures/process',
                    'Supervision or oversight deficiencies',
                    'Fatigue or stress related errors',
                    'Unauthorized actions/ overstepping authority'
                ]
            },
            process: {
                title: 'Process',
                icon: '⚙️',
                options: [
                    'Inadequate or unclear procedure/process',
                    'Unavailable/Undefined internal controls',
                    'Deficiencies in segregation of duties',
                    'Delays/deficiencies in the approval process',
                    'Monitoring deficiencies',
                    'Reconciliation deficiencies',
                    'Change Management deficiencies'
                ]
            },
            itSystems: {
                title: 'IT Systems',
                icon: '💻',
                options: [
                    'System downtime/outages',
                    'Software bugs or glitches',
                    'Integration errors',
                    'Cybersecurity breach/hacking',
                    'Poor system design/usability',
                    'Outdated hardware or software',
                    'Connectivity or network failure',
                    'Incomplete system updates/patches',
                    'Delayed system changes'
                ]
            },
            external: {
                title: 'External Environment',
                icon: '🌍',
                options: [
                    'Regulatory or legal changes',
                    'Economic downturn or market volatility',
                    'Natural disasters (flood, drought, earthquake)',
                    'Political instability / Policy shifts',
                    'Third party vendor failures',
                    'Power outages / Infrastructure failure',
                    'Fraud by external parties',
                    'Pandemics or public health emergencies',
                    'Competition pressure/Disruptive innovation'
                ]
            }
        };
        
        let currentCategory = '';
        let activeCategory = '';
        let selections = [];
        
        function openCauseModal(category) {
            if (activeCategory !== category) {
                activeCategory = category;
                selections = [];
                document.querySelectorAll('.cause-card').forEach(card => {
                    card.classList.remove('has-selections');
                });
                document.getElementById('peopleCount').textContent = '0 selected';
                document.getElementById('processCount').textContent = '0 selected';
                document.getElementById('itSystemsCount').textContent = '0 selected';
                document.getElementById('externalCount').textContent = '0 selected';
            }
            
            currentCategory = category;
            const data = causeData[category];
            
            document.getElementById('causeModalTitle').innerHTML = data.icon + ' ' + data.title;
            
            const modalBody = document.getElementById('causeModalBody');
            modalBody.innerHTML = '';
            
            data.options.forEach((option, index) => {
                const isChecked = selections.includes(option);
                
                const checkboxItem = document.createElement('div');
                checkboxItem.className = 'checkbox-item' + (isChecked ? ' checked' : '');
                checkboxItem.onclick = function(e) {
                    if (e.target.tagName !== 'INPUT') {
                        const checkbox = this.querySelector('input[type="checkbox"]');
                        checkbox.checked = !checkbox.checked;
                        this.classList.toggle('checked', checkbox.checked);
                    } else {
                        this.classList.toggle('checked', e.target.checked);
                    }
                };
                
                checkboxItem.innerHTML = `
                    <label class="checkbox-label">
                        <input type="checkbox" value="${option}" ${isChecked ? 'checked' : ''}>
                        ${option}
                    </label>
                `;
                
                modalBody.appendChild(checkboxItem);
            });
            
            document.getElementById('causeModal').classList.add('show');
        }
        
        function closeCauseModal() {
            document.getElementById('causeModal').classList.remove('show');
        }
        
        function saveCauseSelections() {
            const checkboxes = document.querySelectorAll('#causeModalBody input[type="checkbox"]:checked');
            selections = Array.from(checkboxes).map(cb => cb.value);
            
            updateCauseCard(currentCategory);
            updateHiddenInputs();
            updateSelectionSummary();
            closeCauseModal();
        }
        
        function updateCauseCard(category) {
            const count = selections.length;
            const countElement = document.getElementById(category + 'Count');
            const cardElement = document.getElementById(category + 'Card');
            
            if (count > 0) {
                countElement.textContent = count + ' selected';
                cardElement.classList.add('has-selections');
            } else {
                countElement.textContent = '0 selected';
                cardElement.classList.remove('has-selections');
            }
        }
        
        function updateHiddenInputs() {
            document.getElementById('cause_people_hidden').value = activeCategory === 'people' ? JSON.stringify(selections) : '[]';
            document.getElementById('cause_process_hidden').value = activeCategory === 'process' ? JSON.stringify(selections) : '[]';
            document.getElementById('cause_it_systems_hidden').value = activeCategory === 'itSystems' ? JSON.stringify(selections) : '[]';
            document.getElementById('cause_external_hidden').value = activeCategory === 'external' ? JSON.stringify(selections) : '[]';
        }
        
        function updateSelectionSummary() {
            const summaryDiv = document.getElementById('causeSelectionSummary');
            const summaryContent = document.getElementById('summaryContent');
            
            if (selections.length > 0 && activeCategory) {
                const categoryMap = {
                    people: { title: 'People', icon: '👥' },
                    process: { title: 'Process', icon: '⚙️' },
                    itSystems: { title: 'IT Systems', icon: '💻' },
                    external: { title: 'External Environment', icon: '🌍' }
                };
                
                const category = categoryMap[activeCategory];
                const summaryHTML = `<div style="margin-bottom: 10px;">
                    <strong>${category.icon} ${category.title}:</strong><br>
                    <span style="margin-left: 20px; color: #666;">${selections.join(', ')}</span>
                </div>`;
                
                summaryContent.innerHTML = summaryHTML;
                summaryDiv.style.display = 'block';
            } else {
                summaryDiv.style.display = 'none';
            }
        }
        
        window.addEventListener('click', function(event) {
            const causeModal = document.getElementById('causeModal');
            if (event.target === causeModal) {
                closeCauseModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const successNotification = document.getElementById('successNotification');
            if (successNotification) {
                setTimeout(function() {
                    successNotification.classList.add('fade-out');
                    setTimeout(function() {
                        successNotification.style.display = 'none';
                    }, 500);
                }, 4000);
            }

            const statsCard = document.getElementById('statsCard');
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection) {
                reportsSection.style.display = 'block';
                reportsSection.classList.add('show');
            }
            if (statsCard && <?php echo count($user_risks); ?> > 0) {
                statsCard.addEventListener('click', function() {
                    toggleReports();
                });
            }

            for (const category in causeData) {
                updateCauseCard(category);
                updateHiddenInputs();
            }
            updateSelectionSummary();
        });

        function toggleMoneyRange(radio) {
            const moneyRangeSection = document.getElementById('money-range-section');
            const moneyRangeSelect = document.querySelector('select[name="money_range"]');
            
            if (radio.value === 'yes') {
                moneyRangeSection.classList.add('show');
                moneyRangeSelect.required = true;
            } else {
                moneyRangeSection.classList.remove('show');
                moneyRangeSelect.required = false;
                moneyRangeSelect.value = '';
            }
        }

        function toggleGLPISection(radio) {
            const glpiSection = document.getElementById('glpi-ir-section');
            const glpiInput = document.querySelector('input[name="glpi_ir_number"]');
            
            if (radio.value === 'yes') {
                glpiSection.classList.add('show');
                glpiInput.required = true;
            } else {
                glpiSection.classList.remove('show');
                glpiInput.required = false;
                glpiInput.value = '';
            }
        }

        function displayFileName() {
            const fileInput = document.getElementById('fileInput');
            const fileLabel = document.getElementById('fileLabel');
            
            if (fileInput.files.length > 0) {
                fileLabel.textContent = fileInput.files[0].name;
            } else {
                fileLabel.textContent = 'Click to upload supporting document';
            }
        }

        function openReportModal() {
            document.getElementById('reportModal').classList.add('show');
            document.getElementById('reportModal').style.display = 'flex';
        }

        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('show');
            document.getElementById('reportModal').style.display = 'none';
        }

        function closeModal() {
            document.getElementById('riskModal').classList.remove('show');
            document.getElementById('riskModal').style.display = 'none';
        }

        function scrollToReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection) {
                reportsSection.style.display = 'block';
                reportsSection.classList.add('show');
                setTimeout(function() {
                    reportsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        }

        function toggleReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection) {
                if (reportsSection.style.display === 'none' || reportsSection.style.display === '') {
                    reportsSection.style.display = 'block';
                    reportsSection.classList.add('show');
                } else {
                    reportsSection.style.display = 'none';
                    reportsSection.classList.remove('show');
                }
            }
        }

        function closeReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection) {
                reportsSection.style.display = 'none';
                reportsSection.classList.remove('show');
            }
        }

        function viewRisk(risk) {
            document.getElementById('modalRiskId').textContent = risk.risk_id || 'N/A';
            
            document.getElementById('modalRiskName').textContent = risk.risk_name || 'N/A';
            
            let parsedCategories = JSON.parse(risk.risk_categories);
            let categoryDisplay = '';
            if (Array.isArray(parsedCategories)) {
                categoryDisplay = parsedCategories.join(', ');
            } else {
                categoryDisplay = parsedCategories;
            }
            document.getElementById('modalRiskCategory').textContent = categoryDisplay;
            
            document.getElementById('modalDateOccurrence').textContent = risk.date_of_occurrence || 'N/A';
            
            document.getElementById('modalDepartment').textContent = risk.department || 'N/A';
            
            document.getElementById('modalRiskDescription').textContent = risk.risk_description || 'N/A';
            
            let parsedCause = JSON.parse(risk.cause_of_risk);
            let causeDisplay = '';
            for (const category in parsedCause) {
                if (parsedCause[category].length > 0) {
                    causeDisplay += `<strong>${category}:</strong> ${parsedCause[category].join(', ')}<br>`;
                }
            }
            document.getElementById('modalCauseOfRisk').innerHTML = causeDisplay || 'N/A';
            
            document.getElementById('modalMoneyLoss').textContent = risk.involves_money_loss == 1 ? 'Yes' : 'No';
            
            if (risk.involves_money_loss == 1 && risk.money_range) {
                document.getElementById('moneyRangeSection').style.display = 'block';
                document.getElementById('modalMoneyRange').textContent = risk.money_range;
            } else {
                document.getElementById('moneyRangeSection').style.display = 'none';
            }
            
            document.getElementById('modalGLPI').textContent = risk.reported_to_glpi == 1 ? 'Yes' : 'No';
            
            if (risk.reported_to_glpi == 1 && risk.glpi_ir_number) {
                document.getElementById('glpiNumberSection').style.display = 'block';
                document.getElementById('modalGLPINumber').textContent = risk.glpi_ir_number;
            } else {
                document.getElementById('glpiNumberSection').style.display = 'none';
            }
            
            document.getElementById('modalDateSubmitted').textContent = risk.created_at || 'N/A';
            
            document.getElementById('modalAssignmentStatus').textContent = risk.risk_owner_id ? 'Assigned to Risk Owner' : 'Pending Assignment';
            
            if (risk.document_path && risk.document_path !== '') {
                document.getElementById('documentSection').style.display = 'block';
                document.getElementById('documentLink').href = risk.document_path;
                document.getElementById('documentLink').textContent = risk.document_filename || 'View Document';
            } else {
                document.getElementById('documentSection').style.display = 'none';
            }
            
            document.getElementById('riskModal').classList.add('show');
            document.getElementById('riskModal').style.display = 'flex';
        }

        function openChatbot() {
            alert('Chatbot feature coming soon!');
        }

        window.onclick = function(event) {
            const reportModal = document.getElementById('reportModal');
            const riskModal = document.getElementById('riskModal');
            const causeModal = document.getElementById('causeModal');
            
            if (event.target == reportModal) {
                closeReportModal();
            }
            if (event.target == riskModal) {
                closeModal();
            }
            if (event.target == causeModal) {
                closeCauseModal();
            }
        }
    </script>
</body>
</html>
