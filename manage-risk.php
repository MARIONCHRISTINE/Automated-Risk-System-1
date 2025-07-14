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

// Get comprehensive risk details, including new lock flags
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

// Check if current user can manage this risk (must be assigned to them)
if ($risk['risk_owner_id'] != $_SESSION['user_id']) {
    $_SESSION['error_message'] = "Access denied. You can only manage risks assigned to you.";
    header('Location: risk_owner_dashboard.php');
    exit();
}

// Determine if sections are completed/locked based on new flags
$section_b_completed = (bool)$risk['section_b_locked'];
$section_c_completed = (bool)$risk['section_c_locked'];

// Handle adding new treatment method
if ($_POST && isset($_POST['add_treatment'])) {
    try {
        $db->beginTransaction();

        $treatment_title = $_POST['treatment_title'] ?? '';
        $treatment_description = $_POST['treatment_description'] ?? '';
        $assigned_to = $_POST['assigned_to'] ?? $_SESSION['user_id']; // Default to current user
        $target_date = $_POST['treatment_target_date'] ?? null;

        if (empty($treatment_title) || empty($treatment_description)) {
            throw new Exception("Treatment title and description are required");
        }

        // Insert new treatment method
        $treatment_query = "INSERT INTO risk_treatments
                           (risk_id, treatment_title, treatment_description, assigned_to, target_completion_date, status, created_by, created_at)
                           VALUES (:risk_id, :title, :description, :assigned_to, :target_date, 'pending', :created_by, NOW())";

        $treatment_stmt = $db->prepare($treatment_query);
        $treatment_stmt->bindParam(':risk_id', $risk_id);
        $treatment_stmt->bindParam(':title', $treatment_title);
        $treatment_stmt->bindParam(':description', $treatment_description);
        $treatment_stmt->bindParam(':assigned_to', $assigned_to);
        // Bind target_date only if it's not empty, otherwise let DB handle NULL
        if (!empty($target_date)) {
            $treatment_stmt->bindParam(':target_date', $target_date);
        } else {
            $treatment_stmt->bindValue(':target_date', null, PDO::PARAM_NULL);
        }
        $treatment_stmt->bindParam(':created_by', $_SESSION['user_id']);

        if ($treatment_stmt->execute()) {
            $treatment_id = $db->lastInsertId();

            // Handle file uploads for the new treatment
            if (isset($_FILES['treatment_files']) && !empty($_FILES['treatment_files']['name'][0])) {
                $files = $_FILES['treatment_files'];

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $original_filename = $files['name'][$i];
                        $file_size = $files['size'][$i];
                        $file_type = $files['type'][$i];
                        $tmp_name = $files['tmp_name'][$i];

                        // Generate unique filename
                        $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
                        $upload_path = 'uploads/treatments/' . $unique_filename;

                        // Create directory if it doesn't exist
                        if (!file_exists('uploads/treatments/')) {
                            mkdir('uploads/treatments/', 0755, true);
                        }

                        if (move_uploaded_file($tmp_name, $upload_path)) {
                            // Insert file record
                            $file_query = "INSERT INTO treatment_documents
                                         (treatment_id, original_filename, file_path, file_size, file_type, uploaded_at)
                                         VALUES (:treatment_id, :original_filename, :file_path, :file_size, :file_type, NOW())";
                            $file_stmt = $db->prepare($file_query);
                            $file_stmt->bindParam(':treatment_id', $treatment_id);
                            $file_stmt->bindParam(':original_filename', $original_filename);
                            $file_stmt->bindParam(':file_path', $upload_path);
                            $file_stmt->bindParam(':file_size', $file_size);
                            $file_stmt->bindParam(':file_type', $file_type);
                            $file_stmt->execute();
                        }
                    }
                }
            }

            $db->commit();
            $_SESSION['success_message'] = "Treatment method added successfully!";
            header("Location: manage-risk.php?id=" . $risk_id);
            exit();
        } else {
            throw new Exception("Failed to add treatment method");
        }

    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error adding treatment: " . $e->getMessage();
    }
}

// Handle updating treatment method
if ($_POST && isset($_POST['update_treatment'])) {
    try {
        $db->beginTransaction();

        $treatment_id = $_POST['treatment_id'] ?? 0;
        $treatment_status = $_POST['treatment_status'] ?? '';
        $progress_notes = $_POST['progress_notes'] ?? '';

        // Check if user can update this treatment (must be assigned to them or be the main risk owner)
        $check_query = "SELECT assigned_to FROM risk_treatments WHERE id = :treatment_id AND risk_id = :risk_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':treatment_id', $treatment_id);
        $check_stmt->bindParam(':risk_id', $risk_id);
        $check_stmt->execute();
        $treatment = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$treatment || ($treatment['assigned_to'] != $_SESSION['user_id'] && $risk['risk_owner_id'] != $_SESSION['user_id'])) {
            throw new Exception("You don't have permission to update this treatment");
        }

        // Update treatment
        $update_query = "UPDATE risk_treatments SET
                        status = :status,
                        progress_notes = :progress_notes,
                        updated_at = NOW()
                        WHERE id = :treatment_id";

        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':status', $treatment_status);
        $update_stmt->bindParam(':progress_notes', $progress_notes);
        $update_stmt->bindParam(':treatment_id', $treatment_id);

        if ($update_stmt->execute()) {
            // Handle file uploads for treatment update
            if (isset($_FILES['treatment_update_files']) && !empty($_FILES['treatment_update_files']['name'][0])) {
                $files = $_FILES['treatment_update_files'];

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $original_filename = $files['name'][$i];
                        $file_size = $files['size'][$i];
                        $file_type = $files['type'][$i];
                        $tmp_name = $files['tmp_name'][$i];

                        // Generate unique filename
                        $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
                        $upload_path = 'uploads/treatments/' . $unique_filename;

                        // Create directory if it doesn't exist
                        if (!file_exists('uploads/treatments/')) {
                            mkdir('uploads/treatments/', 0755, true);
                        }

                        if (move_uploaded_file($tmp_name, $upload_path)) {
                            // Insert file record
                            $file_query = "INSERT INTO treatment_documents
                                         (treatment_id, original_filename, file_path, file_size, file_type, uploaded_at)
                                         VALUES (:treatment_id, :original_filename, :file_path, :file_size, :file_type, NOW())";
                            $file_stmt = $db->prepare($file_query);
                            $file_stmt->bindParam(':treatment_id', $treatment_id);
                            $file_stmt->bindParam(':original_filename', $original_filename);
                            $file_stmt->bindParam(':file_path', $upload_path);
                            $file_stmt->bindParam(':file_size', $file_size);
                            $file_stmt->bindParam(':file_type', $file_type);
                            $file_stmt->execute();
                        }
                    }
                }
            }

            $db->commit();
            $_SESSION['success_message'] = "Treatment updated successfully!";
            header("Location: manage-risk.php?id=" . $risk_id);
            exit();
        } else {
            throw new Exception("Failed to update treatment");
        }

    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error updating treatment: " . $e->getMessage();
    }
}

// Handle main risk form submission (legacy fields)
if ($_POST && isset($_POST['update_risk'])) {
    try {
        $db->beginTransaction();

        // Get form data (only risk owner sections)
        // These values will come from the visible select/input if editable,
        // or from the hidden input if disabled/locked.
        $existing_or_new = $_POST['existing_or_new'] ?? null;
        $to_be_reported_to_board = $_POST['to_be_reported_to_board'] ?? null;
        $risk_category = $_POST['risk_category'] ?? null;

        // Risk Assessment
        $inherent_likelihood = $_POST['inherent_likelihood'] ?? null;
        $inherent_consequence = $_POST['inherent_consequence'] ?? null;
        $residual_likelihood = $_POST['residual_likelihood'] ?? null;
        $residual_consequence = $_POST['residual_consequence'] ?? null;

        // Overall risk status
        $risk_status = $_POST['risk_status'] ?? null;

        // Calculate risk level based on the highest rating available
        $risk_level = 'Low';
        $max_rating = 0;

        if ($inherent_likelihood && $inherent_consequence) {
            $max_rating = max($max_rating, $inherent_likelihood * $inherent_consequence);
        }
        if ($residual_likelihood && $residual_consequence) {
            $max_rating = max($max_rating, $residual_likelihood * $residual_consequence);
        }

        if ($max_rating >= 15) $risk_level = 'Critical';
        elseif ($max_rating >= 9) $risk_level = 'High';
        elseif ($max_rating >= 4) $risk_level = 'Medium';

        // Determine new lock states for sections B and C
        $new_section_b_locked = $risk['section_b_locked']; // Keep current locked state by default
        // Lock if not already locked AND all fields are now filled
        if (!$new_section_b_locked &&
            !empty($existing_or_new) &&
            !empty($to_be_reported_to_board) &&
            !empty($risk_category)) {
            $new_section_b_locked = 1;
        }

        $new_section_c_locked = $risk['section_c_locked']; // Keep current locked state by default
        // Lock if not already locked AND at least one assessment (inherent or residual) is fully filled
        if (!$new_section_c_locked &&
            ((!empty($inherent_likelihood) && !empty($inherent_consequence)) ||
             (!empty($residual_likelihood) && !empty($residual_consequence)))) {
            $new_section_c_locked = 1;
        }


        // Update risk incident
        $update_query = "UPDATE risk_incidents SET
                        existing_or_new = :existing_or_new,
                        to_be_reported_to_board = :to_be_reported_to_board,
                        risk_category = :risk_category,
                        inherent_likelihood = :inherent_likelihood,
                        inherent_consequence = :inherent_consequence,
                        residual_likelihood = :residual_likelihood,
                        residual_consequence = :residual_consequence,
                        risk_level = :risk_level,
                        risk_status = :risk_status,
                        section_b_locked = :section_b_locked,
                        section_c_locked = :section_c_locked,
                        updated_at = NOW()
                        WHERE id = :risk_id";

        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':existing_or_new', $existing_or_new);
        $update_stmt->bindParam(':to_be_reported_to_board', $to_be_reported_to_board);
        $update_stmt->bindParam(':risk_category', $risk_category);
        $update_stmt->bindParam(':inherent_likelihood', $inherent_likelihood);
        $update_stmt->bindParam(':inherent_consequence', $inherent_consequence);
        $update_stmt->bindParam(':residual_likelihood', $residual_likelihood);
        $update_stmt->bindParam(':residual_consequence', $residual_consequence);
        $update_stmt->bindParam(':risk_level', $risk_level);
        $update_stmt->bindParam(':risk_status', $risk_status);
        $update_stmt->bindParam(':section_b_locked', $new_section_b_locked, PDO::PARAM_INT); // Ensure INT type
        $update_stmt->bindParam(':section_c_locked', $new_section_c_locked, PDO::PARAM_INT); // Ensure INT type
        $update_stmt->bindParam(':risk_id', $risk_id);

        if ($update_stmt->execute()) {
            $db->commit();
            $_SESSION['success_message'] = "Risk updated successfully!";
            header("Location: view_risk.php?id=" . $risk_id);
            exit();
        } else {
            throw new Exception("Failed to update risk");
        }

    } catch (Exception $e) {
        $db->rollBack();
        $error_message = "Error updating risk: " . $e->getMessage();
    }
}

// Get existing treatment methods
$treatments_query = "SELECT rt.*,
                            assigned_user.full_name as assigned_user_name,
                            assigned_user.email as assigned_user_email,
                            assigned_user.department as assigned_user_department,
                            creator.full_name as creator_name
                     FROM risk_treatments rt
                     LEFT JOIN users assigned_user ON rt.assigned_to = assigned_user.id
                     LEFT JOIN users creator ON rt.created_by = creator.id
                     WHERE rt.risk_id = :risk_id
                     ORDER BY rt.created_at DESC";
$treatments_stmt = $db->prepare($treatments_query);
$treatments_stmt->bindParam(':risk_id', $risk_id);
$treatments_stmt->execute();
$treatments = $treatments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get treatment documents
$treatment_docs = [];
if (!empty($treatments)) {
    $treatment_ids = array_column($treatments, 'id');
    $placeholders = str_repeat('?,', count($treatment_ids) - 1) . '?';
    $docs_query = "SELECT * FROM treatment_documents WHERE treatment_id IN ($placeholders) ORDER BY uploaded_at DESC";
    $docs_stmt = $db->prepare($docs_query);
    $docs_stmt->execute($treatment_ids);
    $docs_result = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by treatment_id
    foreach ($docs_result as $doc) {
        $treatment_docs[$doc['treatment_id']][] = $doc;
    }
}

// Get all risk owners for assignment dropdown
$risk_owners_query = "SELECT id, full_name, email, department FROM users WHERE role = 'risk_owner' ORDER BY department, full_name";
$risk_owners_stmt = $db->prepare($risk_owners_query);
$risk_owners_stmt->execute();
$risk_owners = $risk_owners_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group risk owners by department
$risk_owners_by_dept = [];
foreach ($risk_owners as $owner) {
    $risk_owners_by_dept[$owner['department']][] = $owner;
}

// Get notifications using shared component
$all_notifications = getNotifications($db, $_SESSION['user_id']);

// Calculate completion percentage
$completion_fields = [
    'existing_or_new', 'to_be_reported_to_board', 'risk_category',
    'inherent_likelihood', 'inherent_consequence', 'residual_likelihood', 'residual_consequence'
];

$completed_fields = 0;
foreach ($completion_fields as $field) {
    if (!empty($risk[$field])) {
        $completed_fields++;
    }
}

// Add treatment completion to percentage
$treatment_completion = 0;
if (!empty($treatments)) {
    $completed_treatments = 0;
    foreach ($treatments as $treatment) {
        if ($treatment['status'] === 'completed') {
            $completed_treatments++;
        }
    }
    $treatment_completion = count($treatments) > 0 ? ($completed_treatments / count($treatments)) * 30 : 0; // 30% weight for treatments
}

$base_completion = ($completed_fields / count($completion_fields)) * 70; // 70% weight for basic fields
$completion_percentage = round($base_completion + $treatment_completion);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Risk - <?php echo htmlspecialchars($risk['risk_name']); ?> - Airtel Risk Management</title>
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

        /* Header - Keep original styling */
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

        /* Navigation Bar - Keep original styling */
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

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* Enhanced Page Header */
        .page-header {
            background: linear-gradient(135deg, #E60012, #B8000E);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(230, 0, 18, 0.3);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }

        .page-header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }

        .page-title-section h1 {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }

        .page-meta .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .completion-info {
            text-align: right;
            min-width: 200px;
        }

        .completion-percentage {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .completion-bar {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .completion-fill {
            background: white;
            height: 100%;
            border-radius: 10px;
            transition: width 0.8s ease;
        }

        /* Enhanced Form Sections */
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-top: 4px solid #E60012;
            transition: all 0.3s ease;
        }

        .form-section:hover {
            box-shadow: 0 6px 25px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        .form-section.readonly {
            background: #f8f9fa;
            border-top-color: #6c757d;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #E60012;
        }

        .section-header.readonly {
            border-bottom-color: #6c757d;
        }

        .section-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #E60012, #B8000E);
            color: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: 0 4px 15px rgba(230, 0, 18, 0.3);
        }

        .section-icon.readonly {
            background: linear-gradient(135deg, #6c757d, #545b62);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .section-title h3 {
            font-size: 1.4rem;
            color: #E60012;
            margin: 0;
            font-weight: 600;
        }

        .section-title.readonly h3 {
            color: #6c757d;
        }

        .section-title p {
            font-size: 0.9rem;
            color: #666;
            margin: 0.25rem 0 0 0;
        }

        .section-note {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        /* Enhanced Form Elements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 600;
            color: #333;
            font-size: 0.95rem;
        }

        .required {
            color: #E60012;
            margin-left: 0.25rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            transition: all 0.3s;
            font-size: 1rem;
            background: white;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
            transform: translateY(-1px);
        }
        
        /* Style for disabled inputs to look like readonly-display */
        input[disabled], select[disabled], textarea[disabled] {
            background: #f8f9fa;
            color: #495057;
            cursor: not-allowed;
            border-color: #dee2e6;
            box-shadow: none;
            transform: none;
        }

        textarea {
            height: 120px;
            resize: vertical;
            font-family: inherit;
        }

        .readonly-display {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #6c757d;
            color: #495057;
            font-weight: 500;
            min-height: 50px;
            display: flex;
            align-items: center;
        }

        /* Enhanced Risk Assessment Matrix */
        .risk-matrix-container {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            align-items: center;
        }

        /* Enhanced Risk Assessment Matrix */
        .risk-matrix-container {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 2rem;
            border-radius: 12px;
            margin: 2rem 0;
            border: 2px solid #dee2e6;
        }

        .matrix-title {
            text-align: center;
            color: #E60012;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        /* Enhanced Assessment Cards */
        .assessment-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin: 2rem 0;
        }

        .assessment-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 2px solid #e1e5e9;
            transition: all 0.3s ease;
        }

        .assessment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            border-color: #E60012;
        }

        .assessment-card h4 {
            color: #E60012;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .risk-rating-display {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        /* Treatment Methods Section */
        .treatments-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-top: 4px solid #17a2b8;
        }

        .treatment-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .treatment-card:hover {
            border-color: #17a2b8;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.2);
        }

        .treatment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .treatment-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #17a2b8;
            margin: 0;
        }

        .treatment-status {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-in-progress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .treatment-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .meta-value {
            font-weight: 600;
            color: #333;
        }

        .treatment-description {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #17a2b8;
            margin: 1rem 0;
            line-height: 1.6;
        }

        .treatment-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .add-treatment-btn {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }

        .add-treatment-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(23, 162, 184, 0.4);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 0;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .modal-header {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 1.5rem;
            border-radius: 12px 12px 0 0;
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
            transition: opacity 0.3s;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 2rem;
        }

        /* User Selection Styles */
        .user-search-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .user-search-input {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
        }

        .user-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e1e5e9;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .user-dropdown.show {
            display: block;
        }

        .user-option {
            padding: 0.75rem;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
            transition: background 0.2s;
        }

        .user-option:hover {
            background: #f8f9fa;
        }

        .user-option:last-child {
            border-bottom: none;
        }

        .user-name {
            font-weight: 600;
            color: #333;
        }

        .user-dept {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }

        /* Enhanced File Upload */
        .file-upload-area {
            border: 3px dashed #dee2e6;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            margin-top: 1rem;
            cursor: pointer;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
        }

        .file-upload-area:hover {
            border-color: #E60012;
            background: linear-gradient(135deg, rgba(230, 0, 18, 0.05), rgba(230, 0, 18, 0.02));
            transform: translateY(-2px);
        }

        .file-upload-area.dragover {
            border-color: #E60012;
            background: linear-gradient(135deg, rgba(230, 0, 18, 0.1), rgba(230, 0, 18, 0.05));
            transform: scale(1.02);
        }

        .upload-icon {
            font-size: 2.5rem;
            color: #E60012;
            margin-bottom: 1rem;
        }

        .existing-files {
            margin-top: 1.5rem;
        }

        .file-item {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            border-color: #E60012;
            transform: translateX(5px);
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .file-icon {
            width: 35px;
            height: 35px;
            background: #E60012;
            color: white;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Enhanced Buttons */
        .btn {
            background: linear-gradient(135deg, #E60012, #B8000E);
            color: white;
            border: none;
            padding: 0.9rem 1.8rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(230, 0, 18, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(230, 0, 18, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #545b62);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #E60012;
            color: #E60012;
            box-shadow: none;
        }

        .btn-outline:hover {
            background: #E60012;
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }

        .btn-info:hover {
            box-shadow: 0 6px 20px rgba(23, 162, 184, 0.4);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
            justify-content: center;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        /* Enhanced Alerts */
        .alert {
            padding: 1.2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(212, 237, 218, 0.9), rgba(195, 230, 203, 0.9));
            color: #155724;
            border: 2px solid #28a745;
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(248, 215, 218, 0.9), rgba(245, 198, 203, 0.9));
            color: #721c24;
            border: 2px solid #dc3545;
        }

        .alert i {
            font-size: 1.3rem;
        }

        /* Enhanced Info Groups for Section A */
        .info-group {
            background: rgba(248, 249, 250, 0.8);
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-group-title {
            color: #6c757d;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #dee2e6;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .risk-name-display {
            font-size: 1.1rem;
            font-weight: 600;
            color: #E60012;
            background: rgba(230, 0, 18, 0.05);
            border-left-color: #E60012;
        }

        .risk-description-display,
        .risk-cause-display {
            min-height: 80px;
            line-height: 1.6;
        }

        /* Responsive Design */
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

            .form-row {
                grid-template-columns: 1fr;
            }

            .main-content {
                padding: 1rem;
            }

            .form-section {
                padding: 1.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .page-header-content {
                flex-direction: column;
            }

            .completion-info {
                text-align: left;
                min-width: auto;
            }

            .assessment-grid {
                grid-template-columns: 1fr;
            }

            .treatment-meta {
                grid-template-columns: 1fr;
            }

            .treatment-actions {
                flex-direction: column;
            }
        }

        /* Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Smooth Animations */
        .form-section {
            animation: slideInUp 0.4s ease;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
                        <div class="user-email"><?php echo htmlspecialchars($_SESSION['email'] ?? 'No Email'); ?></div>
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
                        <a href="risk_owner_dashboard.php">
                            🏠 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="report_risk.php">
                            📝 Report Risk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php?tab=my-reports">
                            👀 My Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="risk_owner_dashboard.php?tab=procedures">
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
            <!-- Back Button -->
            <div style="margin-bottom: 2rem;">
                <a href="risk_owner_dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <!-- Enhanced Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <div class="page-title-section">
                        <h1>🛠️ Manage Risk: <?php echo htmlspecialchars($risk['risk_name']); ?></h1>
                        <div class="page-meta">
                            <span class="badge">ID: <?php echo htmlspecialchars($risk['id']); ?></span>
                            <span class="badge">Reported by: <?php echo htmlspecialchars($risk['reporter_name']); ?></span>
                            <span class="badge">Department: <?php echo htmlspecialchars($risk['department']); ?></span>
                            <span class="badge">Assigned to: You</span>
                            <span class="badge"><?php echo count($treatments); ?> Treatment Methods</span>
                        </div>
                    </div>
                    <div class="completion-info">
                        <div class="completion-percentage"><?php echo htmlspecialchars($completion_percentage); ?>%</div>
                        <div>Complete</div>
                        <div class="completion-bar">
                            <div class="completion-fill" style="width: <?php echo htmlspecialchars($completion_percentage); ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="riskForm">
                <!-- SECTION A: RISK IDENTIFICATION (Read-only) -->
                <div class="form-section readonly">
                    <div class="section-header readonly">
                        <div class="section-icon readonly">
                            <i class="fas fa-search"></i>
                        </div>
                        <div class="section-title readonly">
                            <h3>SECTION A: RISK IDENTIFICATION</h3>
                            <p>Information provided by the risk reporter</p>
                        </div>
                    </div>

                    <div class="section-note">
                        <i class="fas fa-info-circle"></i>
                        <span>This section was completed by the staff member who reported the risk. It is read-only for reference.</span>
                    </div>

                    <!-- Risk Basic Information -->
                    <div class="info-group">
                        <h4 class="info-group-title"><i class="fas fa-info-circle"></i> Basic Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Risk ID</label>
                                <div class="readonly-display"><?php echo htmlspecialchars($risk['id']); ?></div>
                            </div>
                            <div class="form-group">
                                <label>Date of Risk Entry</label>
                                <div class="readonly-display"><?php echo htmlspecialchars(date('M j, Y', strtotime($risk['created_at']))); ?></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Department</label>
                                <div class="readonly-display"><?php echo htmlspecialchars($risk['department']); ?></div>
                            </div>
                            <div class="form-group">
                                <label>Reported By</label>
                                <div class="readonly-display"><?php echo htmlspecialchars($risk['reporter_name']); ?> (<?php echo htmlspecialchars($risk['reporter_email']); ?>)</div>
                            </div>
                        </div>
                    </div>

                    <!-- Risk Details -->
                    <div class="info-group">
                        <h4 class="info-group-title"><i class="fas fa-exclamation-triangle"></i> Risk Details</h4>
                        <div class="form-group">
                            <label>Risk Name</label>
                            <div class="readonly-display risk-name-display"><?php echo htmlspecialchars($risk['risk_name']); ?></div>
                        </div>

                        <div class="form-group">
                            <label>Risk Description</label>
                            <div class="readonly-display risk-description-display"><?php echo nl2br(htmlspecialchars($risk['risk_description'])); ?></div>
                        </div>

                        <div class="form-group">
                            <label>Cause of Risk</label>
                            <div class="readonly-display risk-cause-display"><?php echo nl2br(htmlspecialchars($risk['cause_of_risk'])); ?></div>
                        </div>
                    </div>
                </div>

                <!-- SECTION B: RISK CLASSIFICATION -->
                <div class="form-section <?php echo $section_b_completed ? 'readonly' : ''; ?>">
                    <div class="section-header <?php echo $section_b_completed ? 'readonly' : ''; ?>">
                        <div class="section-icon <?php echo $section_b_completed ? 'readonly' : ''; ?>">
                            <i class="fas fa-tags"></i>
                        </div>
                        <div class="section-title <?php echo $section_b_completed ? 'readonly' : ''; ?>">
                            <h3>SECTION B: RISK CLASSIFICATION</h3>
                            <p><?php echo $section_b_completed ? 'Classification completed - cannot be modified' : 'Categorize and classify the risk'; ?></p>
                        </div>
                        <?php if ($section_b_completed): ?>
                            <div style="margin-left: auto;">
                                <span style="background: #28a745; color: white; padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                                    <i class="fas fa-lock"></i> LOCKED
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($section_b_completed): ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Existing or New Risk</label>
                                <div class="readonly-display"><?php echo htmlspecialchars(ucfirst($risk['existing_or_new'])); ?></div>
                            </div>
                            <div class="form-group">
                                <label>To be Reported to Board</label>
                                <div class="readonly-display"><?php echo htmlspecialchars(ucfirst($risk['to_be_reported_to_board'])); ?></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Risk Category</label>
                            <div class="readonly-display"><?php echo htmlspecialchars($risk['risk_category']); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="existing_or_new">Existing or New Risk <span class="required">*</span></label>
                                <select id="existing_or_new" name="existing_or_new" class="form-control" required>
                                    <option value="">Select...</option>
                                    <option value="existing" <?php echo ($risk['existing_or_new'] == 'existing') ? 'selected' : ''; ?>>Existing</option>
                                    <option value="new" <?php echo ($risk['existing_or_new'] == 'new') ? 'selected' : ''; ?>>New</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="to_be_reported_to_board">To be Reported to Board <span class="required">*</span></label>
                                <select id="to_be_reported_to_board" name="to_be_reported_to_board" class="form-control" required>
                                    <option value="">Select...</option>
                                    <option value="yes" <?php echo ($risk['to_be_reported_to_board'] == 'yes') ? 'selected' : ''; ?>>Yes</option>
                                    <option value="no" <?php echo ($risk['to_be_reported_to_board'] == 'no') ? 'selected' : ''; ?>>No</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="risk_category">Risk Category <span class="required">*</span></label>
                            <select id="risk_category" name="risk_category" class="form-control" required>
                                <option value="">Select Risk Category...</option>
                                <option value="Operational" <?php echo ($risk['risk_category'] == 'Operational') ? 'selected' : ''; ?>>Operational</option>
                                <option value="Financial" <?php echo ($risk['risk_category'] == 'Financial') ? 'selected' : ''; ?>>Financial</option>
                                <option value="Strategic" <?php echo ($risk['risk_category'] == 'Strategic') ? 'selected' : ''; ?>>Strategic</option>
                                <option value="Compliance" <?php echo ($risk['risk_category'] == 'Compliance') ? 'selected' : ''; ?>>Compliance</option>
                                <option value="Technology" <?php echo ($risk['risk_category'] == 'Technology') ? 'selected' : ''; ?>>Technology</option>
                                <option value="Reputational" <?php echo ($risk['risk_category'] == 'Reputational') ? 'selected' : ''; ?>>Reputational</option>
                                <option value="Human Resources" <?php echo ($risk['risk_category'] == 'Human Resources') ? 'selected' : ''; ?>>Human Resources</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SECTION C: RISK ASSESSMENT -->
                <div class="form-section <?php echo $section_c_completed ? 'readonly' : ''; ?>" id="section-c">
                    <div class="section-header <?php echo $section_c_completed ? 'readonly' : ''; ?>">
                        <div class="section-icon <?php echo $section_c_completed ? 'readonly' : ''; ?>">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="section-title <?php echo $section_c_completed ? 'readonly' : ''; ?>">
                            <h3>SECTION C: RISK ASSESSMENT</h3>
                            <p><?php echo $section_c_completed ? 'Assessment completed - cannot be modified' : 'Evaluate risk likelihood and impact'; ?></p>
                        </div>
                        <?php if ($section_c_completed): ?>
                            <div style="margin-left: auto;">
                                <span style="background: #28a745; color: white; padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">
                                    <i class="fas fa-lock"></i> LOCKED
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($section_c_completed): ?>
                        <div class="assessment-grid">
                            <div class="assessment-card">
                                <h4><i class="fas fa-exclamation-triangle"></i> Inherent Risk (Before Controls)</h4>
                                <div class="form-group">
                                    <label>Inherent Likelihood (1-5)</label>
                                    <div class="readonly-display"><?php echo htmlspecialchars($risk['inherent_likelihood']); ?></div>
                                </div>
                                <div class="form-group">
                                    <label>Inherent Consequence (1-5)</label>
                                    <div class="readonly-display"><?php echo htmlspecialchars($risk['inherent_consequence']); ?></div>
                                </div>
                            </div>
                            <div class="assessment-card">
                                <h4><i class="fas fa-shield-alt"></i> Residual Risk (After Controls)</h4>
                                <div class="form-group">
                                    <label>Residual Likelihood (1-5)</label>
                                    <div class="readonly-display"><?php echo htmlspecialchars($risk['residual_likelihood']); ?></div>
                                </div>
                                <div class="form-group">
                                    <label>Residual Consequence (1-5)</label>
                                    <div class="readonly-display"><?php echo htmlspecialchars($risk['residual_consequence']); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="assessment-grid">
                            <div class="assessment-card">
                                <h4><i class="fas fa-exclamation-triangle"></i> Inherent Risk (Before Controls)</h4>
                                <div class="form-group">
                                    <label for="inherent_likelihood">Inherent Likelihood (1-5)</label>
                                    <select id="inherent_likelihood" name="inherent_likelihood" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="1" <?php echo ($risk['inherent_likelihood'] == '1') ? 'selected' : ''; ?>>1 - Very Unlikely</option>
                                        <option value="2" <?php echo ($risk['inherent_likelihood'] == '2') ? 'selected' : ''; ?>>2 - Unlikely</option>
                                        <option value="3" <?php echo ($risk['inherent_likelihood'] == '3') ? 'selected' : ''; ?>>3 - Possible</option>
                                        <option value="4" <?php echo ($risk['inherent_likelihood'] == '4') ? 'selected' : ''; ?>>4 - Likely</option>
                                        <option value="5" <?php echo ($risk['inherent_likelihood'] == '5') ? 'selected' : ''; ?>>5 - Very Likely</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="inherent_consequence">Inherent Consequence (1-5)</label>
                                    <select id="inherent_consequence" name="inherent_consequence" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="1" <?php echo ($risk['inherent_consequence'] == '1') ? 'selected' : ''; ?>>1 - Very Low</option>
                                        <option value="2" <?php echo ($risk['inherent_consequence'] == '2') ? 'selected' : ''; ?>>2 - Low</option>
                                        <option value="3" <?php echo ($risk['inherent_consequence'] == '3') ? 'selected' : ''; ?>>3 - Medium</option>
                                        <option value="4" <?php echo ($risk['inherent_consequence'] == '4') ? 'selected' : ''; ?>>4 - High</option>
                                        <option value="5" <?php echo ($risk['inherent_consequence'] == '5') ? 'selected' : ''; ?>>5 - Very High</option>
                                    </select>
                                </div>
                            </div>
                            <div class="assessment-card">
                                <h4><i class="fas fa-shield-alt"></i> Residual Risk (After Controls)</h4>
                                <div class="form-group">
                                    <label for="residual_likelihood">Residual Likelihood (1-5)</label>
                                    <select id="residual_likelihood" name="residual_likelihood" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="1" <?php echo ($risk['residual_likelihood'] == '1') ? 'selected' : ''; ?>>1 - Very Unlikely</option>
                                        <option value="2" <?php echo ($risk['residual_likelihood'] == '2') ? 'selected' : ''; ?>>2 - Unlikely</option>
                                        <option value="3" <?php echo ($risk['residual_likelihood'] == '3') ? 'selected' : ''; ?>>3 - Possible</option>
                                        <option value="4" <?php echo ($risk['residual_likelihood'] == '4') ? 'selected' : ''; ?>>4 - Likely</option>
                                        <option value="5" <?php echo ($risk['residual_likelihood'] == '5') ? 'selected' : ''; ?>>5 - Very Likely</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="residual_consequence">Residual Consequence (1-5)</label>
                                    <select id="residual_consequence" name="residual_consequence" class="form-control">
                                        <option value="">Select...</option>
                                        <option value="1" <?php echo ($risk['residual_consequence'] == '1') ? 'selected' : ''; ?>>1 - Very Low</option>
                                        <option value="2" <?php echo ($risk['residual_consequence'] == '2') ? 'selected' : ''; ?>>2 - Low</option>
                                        <option value="3" <?php echo ($risk['residual_consequence'] == '3') ? 'selected' : ''; ?>>3 - Medium</option>
                                        <option value="4" <?php echo ($risk['residual_consequence'] == '4') ? 'selected' : ''; ?>>4 - High</option>
                                        <option value="5" <?php echo ($risk['residual_consequence'] == '5') ? 'selected' : ''; ?>>5 - Very High</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Overall Risk Status -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="section-title">
                            <h3>OVERALL RISK STATUS</h3>
                            <p>Set the overall status of this risk</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="risk_status">Overall Risk Status</label>
                        <select id="risk_status" name="risk_status" class="form-control">
                            <option value="">Select Status...</option>
                            <option value="pending" <?php echo ($risk['risk_status'] == 'pending') ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo ($risk['risk_status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo ($risk['risk_status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo ($risk['risk_status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>

                <!-- Action Buttons for Main Risk -->
                <div class="action-buttons">
                    <button type="submit" name="update_risk" class="btn" id="submitBtn">
                        <i class="fas fa-save"></i> Update Risk
                    </button>
                    <a href="view_risk.php?id=<?php echo htmlspecialchars($risk_id); ?>" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </form>

            <!-- SECTION D: TREATMENT METHODS -->
            <div class="treatments-section">
                <div class="section-header">
                    <div class="section-icon" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="section-title">
                        <h3 style="color: #17a2b8;">TREATMENT METHODS</h3>
                        <p>Manage multiple treatment approaches for this risk</p>
                    </div>
                    <div style="margin-left: auto;">
                        <button type="button" class="add-treatment-btn" onclick="openAddTreatmentModal()">
                            <i class="fas fa-plus"></i> Add Treatment Method
                        </button>
                    </div>
                </div>

                <?php if (empty($treatments)): ?>
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <div style="font-size: 3rem; margin-bottom: 1rem; color: #17a2b8;">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h3 style="margin-bottom: 0.5rem;">No Treatment Methods Yet</h3>
                        <p style="margin-bottom: 1.5rem;">Start by adding your first treatment method to manage this risk.</p>
                        <button type="button" class="btn btn-info" onclick="openAddTreatmentModal()">
                            <i class="fas fa-plus"></i> Add First Treatment Method
                        </button>
                    </div>
                <?php else: ?>
                    <?php foreach ($treatments as $treatment): ?>
                        <div class="treatment-card">
                            <div class="treatment-header">
                                <h4 class="treatment-title"><?php echo htmlspecialchars($treatment['treatment_title']); ?></h4>
                                <span class="treatment-status status-<?php echo htmlspecialchars($treatment['status']); ?>">
                                    <?php
                                    $status_labels = [
                                        'pending' => '🔓 Pending',
                                        'in_progress' => '⚡ In Progress',
                                        'completed' => '✅ Completed',
                                        'cancelled' => '❌ Cancelled'
                                    ];
                                    echo htmlspecialchars($status_labels[$treatment['status']] ?? ucfirst($treatment['status']));
                                    ?>
                                </span>
                            </div>

                            <div class="treatment-meta">
                                <div class="meta-item">
                                    <div class="meta-label">Assigned To</div>
                                    <div class="meta-value">
                                        <?php echo htmlspecialchars($treatment['assigned_user_name']); ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($treatment['assigned_user_department']); ?></small>
                                    </div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Created By</div>
                                    <div class="meta-value"><?php echo htmlspecialchars($treatment['creator_name']); ?></div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Target Date</div>
                                    <div class="meta-value">
                                        <?php echo $treatment['target_completion_date'] ? htmlspecialchars(date('M j, Y', strtotime($treatment['target_completion_date']))) : 'Not set'; ?>
                                    </div>
                                </div>
                                <div class="meta-item">
                                    <div class="meta-label">Created</div>
                                    <div class="meta-value"><?php echo htmlspecialchars(date('M j, Y', strtotime($treatment['created_at']))); ?></div>
                                </div>
                            </div>

                            <div class="treatment-description">
                                <?php echo nl2br(htmlspecialchars($treatment['treatment_description'])); ?>
                            </div>

                            <?php if (!empty($treatment['progress_notes'])): ?>
                                <div style="background: #e3f2fd; padding: 1rem; border-radius: 8px; border-left: 4px solid #2196f3; margin: 1rem 0;">
                                    <strong style="color: #1976d2;">Progress Notes:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($treatment['progress_notes'])); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Treatment Documents -->
                            <?php if (isset($treatment_docs[$treatment['id']])): ?>
                                <div class="existing-files">
                                    <strong><i class="fas fa-folder"></i> Documents:</strong>
                                    <?php foreach ($treatment_docs[$treatment['id']] as $doc): ?>
                                        <div class="file-item">
                                            <div class="file-info">
                                                <div class="file-icon">
                                                    <i class="fas fa-file"></i>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($doc['original_filename']); ?></strong>
                                                    <br><small><?php echo htmlspecialchars(number_format($doc['file_size'] / 1024, 1)); ?> KB • <?php echo htmlspecialchars(date('M j, Y', strtotime($doc['uploaded_at']))); ?></small>
                                                </div>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-outline btn-sm">
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="treatment-actions">
                                <?php if ($treatment['assigned_to'] == $_SESSION['user_id'] || $risk['risk_owner_id'] == $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-info btn-sm" onclick="openUpdateTreatmentModal(<?php echo htmlspecialchars($treatment['id']); ?>, '<?php echo addslashes(htmlspecialchars($treatment['treatment_title'])); ?>', '<?php echo htmlspecialchars($treatment['status']); ?>', '<?php echo addslashes(htmlspecialchars($treatment['progress_notes'] ?? '')); ?>')">
                                        <i class="fas fa-edit"></i> Update Progress
                                    </button>
                                <?php endif; ?>

                                <?php if ($risk['risk_owner_id'] == $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-outline btn-sm" onclick="reassignTreatment(<?php echo htmlspecialchars($treatment['id']); ?>)">
                                        <i class="fas fa-user-edit"></i> Reassign
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add Treatment Modal -->
    <div id="addTreatmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add New Treatment Method</h3>
                <button type="button" class="close" onclick="closeAddTreatmentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="addTreatmentForm">
                    <div class="form-group">
                        <label for="treatment_title">Treatment Title <span class="required">*</span></label>
                        <input type="text" id="treatment_title" name="treatment_title" required placeholder="e.g., Implement Security Controls">
                    </div>

                    <div class="form-group">
                        <label for="treatment_description">Treatment Description <span class="required">*</span></label>
                        <textarea id="treatment_description" name="treatment_description" required placeholder="Describe the specific treatment approach and actions to be taken..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="assigned_to">Assign To</label>
                        <div class="user-search-container">
                            <input type="text" id="user_search" class="user-search-input" placeholder="Search by name or department..." autocomplete="off">
                            <input type="hidden" id="assigned_to" name="assigned_to" value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
                            <div id="user_dropdown" class="user-dropdown"></div>
                        </div>
                        <small style="color: #666; margin-top: 0.5rem; display: block;">
                            Currently assigned to: <strong id="selected_user_display"><?php echo htmlspecialchars($user['full_name']); ?> (You)</strong>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="treatment_target_date">Target Completion Date</label>
                        <input type="date" id="treatment_target_date" name="treatment_target_date">
                    </div>

                    <div class="form-group">
                        <label for="treatment_files">Supporting Documents</label>
                        <div class="file-upload-area" onclick="document.getElementById('treatment_files').click()">
                            <div class="upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <h4>Upload Supporting Documents</h4>
                            <p>Click to upload or drag and drop files here</p>
                            <small>Supported formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG</small>
                            <input type="file" id="treatment_files" name="treatment_files[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeAddTreatmentModal()">Cancel</button>
                        <button type="submit" name="add_treatment" class="btn btn-info">
                            <i class="fas fa-plus"></i> Add Treatment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Treatment Modal -->
    <div id="updateTreatmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Treatment Progress</h3>
                <button type="button" class="close" onclick="closeUpdateTreatmentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="updateTreatmentForm">
                    <input type="hidden" id="update_treatment_id" name="treatment_id">

                    <div class="form-group">
                        <label>Treatment Title</label>
                        <div id="update_treatment_title_display" class="readonly-display"></div>
                    </div>

                    <div class="form-group">
                        <label for="treatment_status">Status</label>
                        <select id="treatment_status" name="treatment_status" class="form-control">
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="progress_notes">Progress Notes</label>
                        <textarea id="progress_notes" name="progress_notes" class="form-control" placeholder="Add progress updates, challenges, or completion notes..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="treatment_update_files">Additional Documents</label>
                        <div class="file-upload-area" onclick="document.getElementById('treatment_update_files').click()">
                            <div class="upload-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <h4>Upload Progress Evidence</h4>
                            <p>Reports, screenshots, certificates, or other progress documentation</p>
                            <small>Evidence of progress, completion certificates, reports, etc.</small>
                            <input type="file" id="treatment_update_files" name="treatment_update_files[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeUpdateTreatmentModal()">Cancel</button>
                        <button type="submit" name="update_treatment" class="btn btn-info">
                            <i class="fas fa-save"></i> Update Treatment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Risk owners data for user search
        const riskOwners = <?php echo json_encode($risk_owners); ?>;

        // Enhanced file upload handling
        document.addEventListener('DOMContentLoaded', function() {
            const fileInputs = document.querySelectorAll('input[type="file"]');

            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const files = this.files;
                    const uploadArea = this.parentElement;

                    if (files.length > 0) {
                        let fileNames = [];
                        let totalSize = 0;

                        for (let i = 0; i < files.length; i++) {
                            fileNames.push(files[i].name);
                            totalSize += files[i].size;
                        }

                        const totalSizeMB = (totalSize / (1024 * 1024)).toFixed(2);

                        uploadArea.innerHTML = `
                            <div class="upload-icon">
                                <i class="fas fa-check-circle" style="color: #28a745;"></i>
                            </div>
                            <h4 style="color: #28a745;">${files.length} File(s) Selected</h4>
                            <p style="font-size: 0.9rem; color: #666; margin: 0.5rem 0;">${fileNames.join(', ')}</p>
                            <small style="color: #999;">Total size: ${totalSizeMB} MB • Click to change files</small>
                        `;

                        // Add success animation
                        uploadArea.style.transform = 'scale(1.02)';
                        setTimeout(() => {
                            uploadArea.style.transform = 'scale(1)';
                        }, 200);
                    }
                });
            });

            // Enhanced drag and drop functionality
            const uploadAreas = document.querySelectorAll('.file-upload-area');

            uploadAreas.forEach(area => {
                area.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    this.classList.add('dragover');
                });

                area.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');
                });

                area.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('dragover');

                    const fileInput = this.querySelector('input[type="file"]');
                    fileInput.files = e.dataTransfer.files;

                    // Trigger change event
                    const event = new Event('change', { bubbles: true });
                    fileInput.dispatchEvent(event);
                });
            });
        });

        // User search functionality
        document.getElementById('user_search').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const dropdown = document.getElementById('user_dropdown');

            if (searchTerm.length < 2) {
                dropdown.classList.remove('show');
                return;
            }

            const filteredUsers = riskOwners.filter(user =>
                user.full_name.toLowerCase().includes(searchTerm) ||
                user.department.toLowerCase().includes(searchTerm) ||
                user.email.toLowerCase().includes(searchTerm)
            );

            if (filteredUsers.length > 0) {
                dropdown.innerHTML = filteredUsers.map(user => `
                    <div class="user-option" onclick="selectUser(${user.id}, '${user.full_name}', '${user.department}')">
                        <div class="user-name">${user.full_name}</div>
                        <div class="user-dept">${user.department} • ${user.email}</div>
                    </div>
                `).join('');
                dropdown.classList.add('show');
            } else {
                dropdown.innerHTML = '<div class="user-option">No users found</div>';
                dropdown.classList.add('show');
            }
        });

        function selectUser(userId, userName, userDept) {
            document.getElementById('assigned_to').value = userId;
            document.getElementById('user_search').value = userName;
            document.getElementById('selected_user_display').textContent = `${userName} (${userDept})`;
            document.getElementById('user_dropdown').classList.remove('show');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-search-container')) {
                document.getElementById('user_dropdown').classList.remove('show');
            }
        });

        // Modal functions
        function openAddTreatmentModal() {
            document.getElementById('addTreatmentModal').classList.add('show');
        }

        function closeAddTreatmentModal() {
            document.getElementById('addTreatmentModal').classList.remove('show');
        }

        function openUpdateTreatmentModal(treatmentId, title, status, progressNotes) {
            document.getElementById('update_treatment_id').value = treatmentId;
            document.getElementById('update_treatment_title_display').textContent = title;
            document.getElementById('treatment_status').value = status;
            document.getElementById('progress_notes').value = progressNotes;
            document.getElementById('updateTreatmentModal').classList.add('show');
        }

        function closeUpdateTreatmentModal() {
            document.getElementById('updateTreatmentModal').classList.remove('show');
        }

        function reassignTreatment(treatmentId) {
            // This would open a reassignment modal - simplified for now
            alert('Reassignment feature coming soon!');
        }

        // Update form validation to handle read-only sections
        document.getElementById('riskForm').addEventListener('submit', function(e) {
            const requiredFields = [];

            // Only validate editable sections
            <?php if (!$section_b_completed): ?>
            requiredFields.push('existing_or_new', 'to_be_reported_to_board', 'risk_category');
            <?php endif; ?>

            let hasError = false;
            let firstErrorField = null;

            // Remove previous error states
            document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

            requiredFields.forEach(fieldName => {
                const field = document.querySelector(`[name="${fieldName}"]:not([disabled])`); // Only validate non-disabled fields
                if (field && !field.value) {
                    field.style.borderColor = '#dc3545';
                    field.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
                    field.classList.add('error');
                    hasError = true;
                    if (!firstErrorField) firstErrorField = field;
                } else if (field) {
                    field.style.borderColor = ''; // Reset to default
                    field.style.boxShadow = ''; // Reset to default
                }
            });

            if (hasError) {
                e.preventDefault();

                // Show error message
                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger';
                errorAlert.innerHTML = `
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Please fill in all required fields in the editable sections.</span>
                `;

                // Insert error message at top of form
                const form = document.getElementById('riskForm');
                form.insertBefore(errorAlert, form.firstChild);

                // Scroll to first error field
                firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstErrorField.focus();

                // Remove error message after 5 seconds
                setTimeout(() => {
                    errorAlert.remove();
                }, 5000);

                return false;
            }

            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner spinner"></i> Updating Risk...';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.7';

            // Add loading class to form
            document.getElementById('riskForm').classList.add('loading');
        });

        // Enhanced auto-calculate risk ratings with animations
        function calculateRisk(likelihoodId, consequenceId, displayId) {
            const likelihood = document.getElementById(likelihoodId);
            const consequence = document.getElementById(consequenceId);

            if (likelihood && consequence) {
                // Only add event listeners if the fields are NOT disabled
                if (!likelihood.disabled) {
                    likelihood.addEventListener('change', updateRating);
                }
                if (!consequence.disabled) {
                    consequence.addEventListener('change', updateRating);
                }

                // Initial calculation
                updateRating();

                function updateRating() {
                    const l = parseInt(likelihood.value) || 0;
                    const c = parseInt(consequence.value) || 0;
                    const rating = l * c;

                    let ratingDisplay = document.getElementById(displayId);
                    if (!ratingDisplay) {
                        ratingDisplay = document.createElement('div');
                        ratingDisplay.id = displayId;
                        ratingDisplay.className = 'risk-rating-display';
                        // Append to the parent of the consequence select
                        consequence.closest('.assessment-card').appendChild(ratingDisplay);
                    }

                    if (rating > 0) {
                        let level = 'Low';
                        let color = '#28a745';
                        let bgColor = 'linear-gradient(135deg, #d4edda, #c3e6cb)';
                        let textColor = '#155724';

                        if (rating >= 15) {
                            level = 'Critical';
                            color = '#dc3545';
                            bgColor = 'linear-gradient(135deg, #dc3545, #c82333)';
                            textColor = 'white';
                        } else if (rating >= 9) {
                            level = 'High';
                            color = '#fd7e14';
                            bgColor = 'linear-gradient(135deg, #f8d7da, #f5c6cb)';
                            textColor = '#721c24';
                        } else if (rating >= 4) {
                            level = 'Medium';
                            color = '#ffc107';
                            bgColor = 'linear-gradient(135deg, #fff3cd, #ffeaa7)';
                            textColor = '#856404';
                        }

                        ratingDisplay.innerHTML = `
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <strong>Risk Rating:</strong>
                                <div style="background: ${bgColor}; color: ${textColor}; padding: 0.5rem 1rem; border-radius: 15px; font-weight: bold; display: flex; align-items: center; gap: 0.5rem;">
                                    <i class="fas fa-calculator"></i>
                                    ${rating} - ${level}
                                </div>
                            </div>
                        `;

                        // Add animation
                        ratingDisplay.style.transform = 'scale(1.05)';
                        setTimeout(() => {
                            ratingDisplay.style.transform = 'scale(1)';
                        }, 200);

                    } else {
                        ratingDisplay.innerHTML = `
                            <div style="text-align: center; color: #666; font-style: italic;">
                                <i class="fas fa-info-circle"></i>
                                Select both likelihood and consequence to calculate risk rating
                            </div>
                        `;
                    }
                }
            }
        }

        // Initialize risk calculators only for editable sections
        <?php if (!$section_c_completed): ?>
        calculateRisk('inherent_likelihood', 'inherent_consequence', 'inherent_rating');
        calculateRisk('residual_likelihood', 'residual_consequence', 'residual_rating');
        <?php endif; ?>

        // Progress bar animation
        const progressFill = document.querySelector('.completion-fill');
        if (progressFill) {
            const targetWidth = progressFill.style.width;
            progressFill.style.width = '0%';
            setTimeout(() => {
                progressFill.style.width = targetWidth;
            }, 500);
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
            }
        });
    </script>
</body>
</html>
