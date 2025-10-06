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

// Check if current user can manage this risk (must be assigned to them)
if ($risk['risk_owner_id'] != $_SESSION['user_id']) {
    $_SESSION['error_message'] = "Access denied. You can only manage risks assigned to you.";
    header('Location: risk_owner_dashboard.php');
    exit();
}

$section_c_completed = !empty($risk['impact_category']) && !empty($risk['likelihood_level']) && !empty($risk['impact_level']);

// Handle adding new treatment method
if ($_POST && isset($_POST['add_treatment'])) {
    try {
        $db->beginTransaction();

        $treatment_title = $_POST['treatment_title'] ?? '';
        $treatment_description = $_POST['treatment_description'] ?? '';
        $assigned_to = $_POST['assigned_to'] ?? $_SESSION['user_id'];
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

                        $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
                        $upload_path = 'uploads/treatments/' . $unique_filename;

                        if (!file_exists('uploads/treatments/')) {
                            mkdir('uploads/treatments/', 0755, true);
                        }

                        if (move_uploaded_file($tmp_name, $upload_path)) {
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

        $check_query = "SELECT assigned_to FROM risk_treatments WHERE id = :treatment_id AND risk_id = :risk_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':treatment_id', $treatment_id);
        $check_stmt->bindParam(':risk_id', $risk_id);
        $check_stmt->execute();
        $treatment = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$treatment || ($treatment['assigned_to'] != $_SESSION['user_id'] && $risk['risk_owner_id'] != $_SESSION['user_id'])) {
            throw new Exception("You don't have permission to update this treatment");
        }

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

                        $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
                        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
                        $upload_path = 'uploads/treatments/' . $unique_filename;

                        if (!file_exists('uploads/treatments/')) {
                            mkdir('uploads/treatments/', 0755, true);
                        }

                        if (move_uploaded_file($tmp_name, $upload_path)) {
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

if ($_POST && isset($_POST['update_risk'])) {
    try {
        $db->beginTransaction();

        // Get form data using correct field names that match database schema
        $risk_type = $_POST['risk_type'] ?? null;
        $impact_category = $_POST['impact_category'] ?? null;
        $likelihood_level = $_POST['likelihood_level'] ?? null;
        $impact_level = $_POST['impact_level'] ?? null;
        $impact_description = $_POST['impact_description_text'] ?? null;
        $risk_status = $_POST['risk_status'] ?? null;

        $additional_categories = $_POST['additional_categories'] ?? [];
        $additional_likelihood = $_POST['additional_likelihood'] ?? [];
        $additional_impact = $_POST['additional_impact'] ?? [];
        $additional_ratings = $_POST['additional_ratings'] ?? [];
        $has_additional_risks = $_POST['has_additional_risks'] ?? 'no';

        // Process primary risk
        $primary_likelihood = $_POST['likelihood'] ?? $likelihood_level;
        $primary_impact = $_POST['impact'] ?? $impact_level;
        
        // Calculate risk rating
        $risk_rating = 0;
        if ($primary_likelihood && $primary_impact) {
            $risk_rating = $primary_likelihood * $primary_impact;
        }

        $all_categories = [];
        $all_likelihood = [];
        $all_impact = [];
        $all_ratings = [];

        // Add primary risk data
        if (!empty($risk['risk_categories'])) {
            $existing_categories = json_decode($risk['risk_categories'], true);
            if (!empty($existing_categories[0])) {
                $all_categories[] = $existing_categories[0];
                $all_likelihood[] = $primary_likelihood;
                $all_impact[] = $primary_impact;
                $all_ratings[] = $risk_rating;
            }
        }

        // Add additional risks data
        if ($has_additional_risks === 'yes' && !empty($additional_categories)) {
            for ($i = 0; $i < count($additional_categories); $i++) {
                if (!empty($additional_categories[$i]) && !empty($additional_likelihood[$i]) && !empty($additional_impact[$i])) {
                    $all_categories[] = $additional_categories[$i];
                    $all_likelihood[] = $additional_likelihood[$i];
                    $all_impact[] = $additional_impact[$i];
                    $all_ratings[] = $additional_ratings[$i] ?? ($additional_likelihood[$i] * $additional_impact[$i]);
                }
            }
        }

        // Convert arrays to comma-separated strings for database storage
        $categories_json = json_encode($all_categories);
        $likelihood_string = implode(',', $all_likelihood);
        $impact_string = implode(',', $all_impact);
        $ratings_string = implode(',', $all_ratings);

        // Get general risk scores
        $general_inherent_score = $_POST['general_inherent_risk_score'] ?? 0;
        $general_residual_score = $_POST['general_residual_risk_score'] ?? 0;

        $update_query = "UPDATE risk_incidents SET
                        risk_type = :risk_type,
                        impact_category = :impact_category,
                        likelihood_level = :likelihood_level,
                        impact_level = :impact_level,
                        impact_description = :impact_description,
                        risk_rating = :risk_rating,
                        risk_status = :risk_status,
                        risk_categories = :risk_categories,
                        inherent_likelihood = :inherent_likelihood,
                        inherent_consequence = :inherent_consequence,
                        general_inherent_risk_score = :general_inherent_risk_score,
                        general_residual_risk_score = :general_residual_risk_score,
                        has_additional_risks = :has_additional_risks,
                        updated_at = NOW()
                        WHERE id = :risk_id";

        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':risk_type', $risk_type);
        $update_stmt->bindParam(':impact_category', $impact_category);
        $update_stmt->bindParam(':likelihood_level', $primary_likelihood);
        $update_stmt->bindParam(':impact_level', $primary_impact);
        $update_stmt->bindParam(':impact_description', $impact_description);
        $update_stmt->bindParam(':risk_rating', $risk_rating);
        $update_stmt->bindParam(':risk_status', $risk_status);
        $update_stmt->bindParam(':risk_categories', $categories_json);
        $update_stmt->bindParam(':inherent_likelihood', $likelihood_string);
        $update_stmt->bindParam(':inherent_consequence', $impact_string);
        $update_stmt->bindParam(':general_inherent_risk_score', $general_inherent_score);
        $update_stmt->bindParam(':general_residual_risk_score', $general_residual_score);
        $update_stmt->bindParam(':has_additional_risks', $has_additional_risks);
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
        $_SESSION['error_message'] = "Error updating risk: " . $e->getMessage();
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

    foreach ($docs_result as $doc) {
        $treatment_docs[$doc['treatment_id']][] = $doc;
    }
}

// Get all risk owners for assignment dropdown
$risk_owners_query = "SELECT id, full_name, email, department FROM users WHERE role = 'risk_owner' ORDER BY department, full_name";
$risk_owners_stmt = $db->prepare($risk_owners_query);
$risk_owners_stmt->execute();
$risk_owners = $risk_owners_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications using shared component
$all_notifications = getNotifications($db, $_SESSION['user_id']);

$completion_fields = [
    'risk_type', 'impact_category', 'likelihood_level', 'impact_level'
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
    $treatment_completion = count($treatments) > 0 ? ($completed_treatments / count($treatments)) * 30 : 0;
}

$base_completion = ($completed_fields / count($completion_fields)) * 70;
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
                        if (isset($_SESSION['user_id'])) {
                            renderNotificationBar($all_notifications);
                        }
                        ?>
                    </li>
                </ul>
            </div>
        </nav>

        <main class="main-content">
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

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
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

                    <!-- Risk Categories -->
                    <div class="form-group">
                        <label>Risk Categories</label>
                        <div class="readonly-display">
                            <?php 
                            $categories = [];
                            if (!empty($risk['risk_categories'])) {
                                $decoded_categories = json_decode($risk['risk_categories'], true);
                                if (is_array($decoded_categories)) {
                                    $categories = $decoded_categories;
                                }
                            }
                            
                            if (!empty($categories)) {
                                foreach ($categories as $category) {
                                    echo '<span style="background: #E60012; color: white; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 500; margin-right: 0.5rem; display: inline-block; margin-bottom: 0.25rem;">' . htmlspecialchars($category) . '</span>';
                                }
                            } else {
                                echo '<span style="color: #666; font-style: italic;">No categories selected</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- Money Loss Information -->
                    <div class="form-group">
                        <label>Does your risk involve loss of money?</label>
                        <div class="readonly-display">
                            <?php 
                            if (isset($risk['involves_money_loss'])): 
                                $involves_money = (bool)$risk['involves_money_loss'];
                            ?>
                                <span style="background: <?php echo $involves_money ? '#E60012' : '#28a745'; ?>; color: white; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                                    <?php echo $involves_money ? 'Yes' : 'No'; ?>
                                    <?php if ($involves_money && !empty($risk['money_amount'])): ?>
                                        - $<?php echo number_format($risk['money_amount'], 2); ?>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #666; font-style: italic;">Not specified</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Risk Description -->
                    <div class="form-group">
                        <label>Risk Description</label>
                        <div class="readonly-display"><?php echo nl2br(htmlspecialchars($risk['risk_description'])); ?></div>
                    </div>

                    <!-- Cause of Risk -->
                    <div class="form-group">
                        <label>Cause of Risk</label>
                        <div class="readonly-display"><?php echo nl2br(htmlspecialchars($risk['cause_of_risk'])); ?></div>
                    </div>

                    <!-- Supporting Documents -->
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
                </div>

                <!-- SECTION B: RISK ASSESSMENT -->
                <div class="form-section <?php echo $section_c_completed ? 'readonly' : ''; ?>" id="section-c">
                    <div class="section-header <?php echo $section_c_completed ? 'readonly' : ''; ?>">
                        <div class="section-icon <?php echo $section_c_completed ? 'readonly' : ''; ?>">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="section-title <?php echo $section_c_completed ? 'readonly' : ''; ?>">
                            <h3>SECTION B: RISK ASSESSMENT</h3>
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

                    <?php 
                    $has_assessment_data = !empty($risk['inherent_likelihood']) || !empty($risk['inherent_consequence']) || !empty($risk['risk_rating']);
                    
                    if ($section_c_completed || $has_assessment_data): ?>
                        <!-- Display Mode: Show existing assessment data -->
                        <div class="assessment-grid">
                            
                            <!-- Risk Type Display -->
                            <div class="form-group">
                                <label class="form-label">a. Existing or New Risk</label>
                                <div class="readonly-display" style="padding: 10px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #007bff;">
                                    <?php echo isset($risk['existing_or_new']) ? ucfirst(str_replace('_', ' ', $risk['existing_or_new'])) : 'Not specified'; ?>
                                </div>
                            </div>

                            <!-- Risk Rating Display -->
                            <div class="form-group">
                                <label class="form-label">b. Risk Rating</label>
                                
                                <?php 
                                $risk_categories = json_decode($risk['risk_categories'] ?? '[]', true);
                                $inherent_likelihood_values = explode(',', $risk['inherent_likelihood'] ?? '');
                                $inherent_consequence_values = explode(',', $risk['inherent_consequence'] ?? '');
                                $risk_rating_values = explode(',', $risk['risk_rating'] ?? '');
                                $residual_rating_values = explode(',', $risk['residual_rating'] ?? '');
                                
                                // Display primary risk (i.)
                                if (!empty($risk_categories)): ?>
                                    <div style="margin: 15px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #E60012; border-radius: 4px;">
                                        <h4 style="margin: 0 0 15px 0; color: #E60012; font-size: 16px;">i. <?php echo htmlspecialchars($risk_categories[0]); ?></h4>
                                        
                                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                            <!-- Inherent Risk Display -->
                                            <div style="flex: 1; min-width: 200px; padding: 15px; background: #e8f5e8; border: 2px solid #28a745; border-radius: 8px;">
                                                <h6 style="color: #28a745; margin-bottom: 10px;">Inherent Risk</h6>
                                                <div style="margin-bottom: 8px;">
                                                    <strong>Likelihood:</strong> <?php echo $inherent_likelihood_values[0] ?? 'N/A'; ?>
                                                </div>
                                                <div style="margin-bottom: 8px;">
                                                    <strong>Impact:</strong> <?php echo $inherent_consequence_values[0] ?? 'N/A'; ?>
                                                </div>
                                                <div>
                                                    <strong>Rating:</strong> 
                                                    <?php 
                                                    $rating = $risk_rating_values[0] ?? 0;
                                                    $level = 'Low';
                                                    $color = '#28a745';
                                                    
                                                    if ($rating >= 12) {
                                                        $level = 'Critical';
                                                        $color = '#dc3545';
                                                    } elseif ($rating >= 8) {
                                                        $level = 'High';
                                                        $color = '#fd7e14';
                                                    } elseif ($rating >= 4) {
                                                        $level = 'Medium';
                                                        $color = '#ffc107';
                                                    }
                                                    ?>
                                                    <span style="background: <?php echo $color; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                                        <?php echo $rating; ?> - <?php echo $level; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <!-- Residual Risk Display -->
                                            <div style="flex: 1; min-width: 200px; padding: 15px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">
                                                <h6 style="color: #856404; margin-bottom: 10px;">Residual Risk</h6>
                                                <div>
                                                    <strong>Rating:</strong> 
                                                    <?php 
                                                    $residual_rating = $residual_rating_values[0] ?? 0;
                                                    if ($residual_rating > 0):
                                                        $res_level = 'Low';
                                                        $res_color = '#28a745';
                                                        
                                                        if ($residual_rating >= 12) {
                                                            $res_level = 'Critical';
                                                            $res_color = '#dc3545';
                                                        } elseif ($residual_rating >= 8) {
                                                            $res_level = 'High';
                                                            $res_color = '#fd7e14';
                                                        } elseif ($residual_rating >= 4) {
                                                            $res_level = 'Medium';
                                                            $res_color = '#ffc107';
                                                        }
                                                    ?>
                                                        <span style="background: <?php echo $res_color; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                                            <?php echo $residual_rating; ?> - <?php echo $res_level; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #666;">Not assessed</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif;
                                
                                for ($i = 1; $i < count($risk_categories); $i++): 
                                    $roman_numerals = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];
                                    $numeral = $roman_numerals[$i] ?? ($i + 1);
                                ?>
                                    <div style="margin: 15px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #6f42c1; border-radius: 4px;">
                                        <h4 style="margin: 0 0 15px 0; color: #6f42c1; font-size: 16px;"><?php echo $numeral; ?>. <?php echo htmlspecialchars($risk_categories[$i]); ?></h4>
                                        
                                        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                            <!-- Secondary Inherent Risk Display -->
                                            <div style="flex: 1; min-width: 200px; padding: 15px; background: #e8f5e8; border: 2px solid #28a745; border-radius: 8px;">
                                                <h6 style="color: #28a745; margin-bottom: 10px;">Inherent Risk</h6>
                                                <div style="margin-bottom: 8px;">
                                                    <strong>Likelihood:</strong> <?php echo $inherent_likelihood_values[$i] ?? 'N/A'; ?>
                                                </div>
                                                <div style="margin-bottom: 8px;">
                                                    <strong>Impact:</strong> <?php echo $inherent_consequence_values[$i] ?? 'N/A'; ?>
                                                </div>
                                                <div>
                                                    <strong>Rating:</strong> 
                                                    <?php 
                                                    $sec_rating = $risk_rating_values[$i] ?? 0;
                                                    $sec_level = 'Low';
                                                    $sec_color = '#28a745';
                                                    
                                                    if ($sec_rating >= 12) {
                                                        $sec_level = 'Critical';
                                                        $sec_color = '#dc3545';
                                                    } elseif ($sec_rating >= 8) {
                                                        $sec_level = 'High';
                                                        $sec_color = '#fd7e14';
                                                    } elseif ($sec_rating >= 4) {
                                                        $sec_level = 'Medium';
                                                        $sec_color = '#ffc107';
                                                    }
                                                    ?>
                                                    <span style="background: <?php echo $sec_color; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                                        <?php echo $sec_rating; ?> - <?php echo $sec_level; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <!-- Secondary Residual Risk Display -->
                                            <div style="flex: 1; min-width: 200px; padding: 15px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">
                                                <h6 style="color: #856404; margin-bottom: 10px;">Residual Risk</h6>
                                                <div>
                                                    <strong>Rating:</strong> 
                                                    <?php 
                                                    $sec_residual = $residual_rating_values[$i] ?? 0;
                                                    if ($sec_residual > 0):
                                                        $sec_res_level = 'Low';
                                                        $sec_res_color = '#28a745';
                                                        
                                                        if ($sec_residual >= 12) {
                                                            $sec_res_level = 'Critical';
                                                            $sec_res_color = '#dc3545';
                                                        } elseif ($sec_residual >= 8) {
                                                            $sec_res_level = 'High';
                                                            $sec_res_color = '#fd7e14';
                                                        } elseif ($sec_residual >= 4) {
                                                            $sec_res_level = 'Medium';
                                                            $sec_res_color = '#ffc107';
                                                        }
                                                    ?>
                                                        <span style="background: <?php echo $sec_res_color; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                                            <?php echo $sec_residual; ?> - <?php echo $sec_res_level; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="color: #666;">Not assessed</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <!-- General Risk Score Display -->
                            <div class="form-group">
                                <label class="form-label">c. General Risk Score</label>
                                
                                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                                    <div style="flex: 1; min-width: 250px; padding: 20px; background: #e3f2fd; border: 2px solid #2196f3; border-radius: 8px; text-align: center;">
                                        <h6 style="color: #1976d2; margin-bottom: 10px; font-weight: 600;">General Inherent Risk Score</h6>
                                        <div style="font-size: 24px; font-weight: bold; color: #1976d2;">
                                            <?php echo number_format($risk['general_inherent_risk_score'] ?? 0, 2); ?>
                                        </div>
                                    </div>
                                    
                                    <div style="flex: 1; min-width: 250px; padding: 20px; background: #ffebee; border: 2px solid #f44336; border-radius: 8px; text-align: center;">
                                        <h6 style="color: #d32f2f; margin-bottom: 10px; font-weight: 600;">General Residual Risk Score</h6>
                                        <div style="font-size: 24px; font-weight: bold; color: #d32f2f;">
                                            <?php echo number_format($risk['general_residual_risk_score'] ?? 0, 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Replaced entire input mode with exact Section B code from report_risk.php -->
                        
                        <!-- Section 2a: Risk Classification -->
                        <div class="form-group">
                            <label class="form-label">a. Existing or New Risk</label>
                            <select name="existing_or_new" id="existing_or_new" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="existing" <?php echo (isset($risk['existing_or_new']) && $risk['existing_or_new'] == 'existing') ? 'selected' : ''; ?>>Existing Risk</option>
                                <option value="new" <?php echo (isset($risk['existing_or_new']) && $risk['existing_or_new'] == 'new') ? 'selected' : ''; ?>>New Risk</option>
                            </select>
                        </div>

                        <!-- Section 2b: Risk Rating -->
                        <div class="form-group">
                            <label class="form-label">b. Risk Rating</label>
                            
                            <!-- Dynamic risk category header -->
                            <div style="margin: 15px 0; padding: 10px; background: #f8f9fa; border-left: 4px solid #E60012; border-radius: 4px;">
                                <h4 style="margin: 0; color: #E60012; font-size: 16px;">
                                    Risk i. <span id="primary_risk_category_display"><?php echo !empty($risk['risk_categories']) ? json_decode($risk['risk_categories'], true)[0] : 'Select Risk Category from Section A'; ?></span>
                                </h4>
                            </div>
                            
                            <!-- Inherent Risk Assessment -->
                            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #28a745; border-radius: 8px;">
                                <h5 style="color: #28a745; margin-bottom: 15px; font-weight: 600;">Inherent Risk</h5>
                                
                                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                    <!-- Likelihood Selection -->
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
                                            <input type="hidden" name="likelihood" id="likelihood_value" required>
                                        </div>
                                    </div>
                                    
                                    <!-- Impact Selection -->
                                    <div style="flex: 1; min-width: 300px;">
                                        <div class="form-group">
                                            <label class="form-label">Impact *</label>
                                            <div class="impact-boxes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                                
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
                                            <input type="hidden" name="impact" id="impact_value" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="inherent_rating_result" style="margin-top: 15px; padding: 15px; background: #fd7e14; color: white; border-radius: 8px; text-align: center; font-weight: bold; font-size: 16px;">
                                    Inherent Risk Rating will appear here
                                </div>
                                <input type="hidden" name="risk_rating" id="risk_rating_value">
                                <input type="hidden" name="inherent_risk_level" id="inherent_risk_level_value">
                            </div>
                            
                            <!-- Residual Risk Section -->
                            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #ffc107; border-radius: 8px;">
                                <h5 style="color: #ffc107; margin-bottom: 15px; font-weight: 600;">Residual Risk</h5>
                                <!-- Updated residual risk display to match inherent risk styling -->
                                <div id="residual_rating_result" style="padding: 15px; background: #fd7e14; color: white; border-radius: 8px; text-align: center; font-weight: bold; font-size: 16px;">
                                    Residual Risk Rating will appear here
                                </div>
                                <input type="hidden" name="residual_rating" id="residual_rating_value">
                                <input type="hidden" name="residual_risk_level" id="residual_risk_level_value">
                                <input type="hidden" name="control_assessment" id="control_assessment_value">
                            </div>
                            
                            <!-- Additional Risk Assessment -->
                            <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #6f42c1; border-radius: 8px;">
                                <h5 style="color: #6f42c1; margin-bottom: 15px; font-weight: 600;">Additional Risk Assessment</h5>
                                
                                <div style="margin-bottom: 15px;">
                                    <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">
                                        Is there any other triggered risk? <span style="color: red;">*</span>
                                    </p>
                                    <div>
                                        <label style="margin-right: 15px; font-weight: normal;">
                                            <input type="radio" name="has_additional_risks" value="yes" style="margin-right: 5px;" onchange="toggleAdditionalRisks(true)"> Yes
                                        </label>
                                        <label style="font-weight: normal;">
                                            <input type="radio" name="has_additional_risks" value="no" style="margin-right: 5px;" onchange="toggleAdditionalRisks(false)"> No
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="additional_risks_container" style="display: none;">
                                    <!-- Additional risks will be dynamically added here -->
                                </div>
                                
                                <button type="button" id="add_risk_btn" onclick="addAdditionalRisk()" 
                                        style="display: none; background: #6f42c1; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Add Risk
                                </button>
                            </div>
                        </div>

                        <!-- Section 2c: General Risk Score -->
                        <div class="form-group">
                            <label class="form-label">c. GENERAL RISK SCORE</label>
                            
                            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                                <div style="flex: 1; min-width: 250px; padding: 20px; background: #e3f2fd; border: 2px solid #2196f3; border-radius: 8px; text-align: center;">
                                    <h6 style="color: #1976d2; margin-bottom: 10px; font-weight: 600;">General Inherent Risk Score</h6>
                                    <div id="general_inherent_score" style="font-size: 24px; font-weight: bold; color: #1976d2;">0.00</div>
                                    <div style="font-size: 12px; color: #666; margin-top: 5px;">General Inherent Risk Score will appear here</div>
                                    <input type="hidden" name="general_inherent_risk_score" id="general_inherent_risk_score_value">
                                </div>
                                
                                <div style="flex: 1; min-width: 250px; padding: 20px; background: #ffebee; border: 2px solid #f44336; border-radius: 8px; text-align: center;">
                                    <h6 style="color: #d32f2f; margin-bottom: 10px; font-weight: 600;">General Residual Risk Score</h6>
                                    <div id="general_residual_score" style="font-size: 24px; font-weight: bold; color: #d32f2f;">0.00</div>
                                    <div style="font-size: 12px; color: #666; margin-top: 5px;">General Residual Risk Score will appear here</div>
                                    <input type="hidden" name="general_residual_risk_score" id="general_residual_risk_score_value">
                                </div>
                            </div>
                        </div>
                        
                    <?php endif; ?>

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
                            <option value="pending" <?php echo (isset($risk['risk_status']) && $risk['risk_status'] == 'pending') ? 'selected' : ''; ?>>Open</option>
                            <option value="in_progress" <?php echo (isset($risk['risk_status']) && $risk['risk_status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo (isset($risk['risk_status']) && $risk['risk_status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo (isset($risk['risk_status']) && $risk['risk_status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
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

                    const event = new Event('change', { bubbles: true });
                    fileInput.dispatchEvent(event);
                });
            });

            // Initialize impact boxes and risk rating on page load
            updateImpactBoxes();
            calculateRiskRating();
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
            alert('Reassignment feature coming soon!');
        }

        document.getElementById('riskForm').addEventListener('submit', function(e) {
            const requiredFields = [];

            <?php if (!$section_c_completed): ?>
            requiredFields.push('risk_type', 'impact_category', 'likelihood_level', 'impact_level');
            <?php endif; ?>

            let hasError = false;
            let firstErrorField = null;

            document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

            requiredFields.forEach(fieldName => {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (field && !field.value) {
                    field.style.borderColor = '#dc3545';
                    field.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
                    field.classList.add('error');
                    hasError = true;
                    if (!firstErrorField) firstErrorField = field;
                } else if (field) {
                    field.style.borderColor = '';
                    field.style.boxShadow = '';
                }
            });

            if (hasError) {
                e.preventDefault();

                const errorAlert = document.createElement('div');
                errorAlert.className = 'alert alert-danger';
                errorAlert.innerHTML = `
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Please fill in all required fields in the editable sections.</span>
                `;

                const form = document.getElementById('riskForm');
                form.insertBefore(errorAlert, form.firstChild);

                firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstErrorField.focus();

                setTimeout(() => {
                    errorAlert.remove();
                }, 5000);

                return false;
            }

            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner spinner"></i> Updating Risk...';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.7';

            document.getElementById('riskForm').classList.add('loading');
        });

        function selectLikelihood(element, value) {
            document.querySelectorAll('.likelihood-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            
            element.style.border = '3px solid #333';
            document.getElementById('likelihood_value').value = value;
            calculateRiskRating();
        }
        
        function selectImpact(element, value) {
            document.querySelectorAll('.impact-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            
            element.style.border = '3px solid #333';
            document.getElementById('impact_value').value = value;
            
            const category = document.getElementById('impact_category').value;
            if (category) {
                const impactData = getImpactData();
                const levels = ['minor', 'moderate', 'significant', 'extreme'];
                const levelKey = levels[value - 1];
                if (impactData[category] && impactData[category][levelKey]) {
                    document.getElementById('impact_description_value').value = impactData[category][levelKey];
                }
            }
            
            calculateRiskRating();
        }
        
function calculateRiskRating() {
    const likelihood = parseInt(document.getElementById('likelihood_value').value) || 0;
    const impact = parseInt(document.getElementById('impact_value').value) || 0;
    
    if (likelihood > 0 && impact > 0) {
        const rating = likelihood * impact;
        let level = 'Low';
        let color = '#28a745';
        
        if (rating >= 12) {
            level = 'Critical';
            color = '#dc3545';
        } else if (rating >= 8) {
            level = 'High';
            color = '#fd7e14';
        } else if (rating >= 4) {
            level = 'Medium';
            color = '#ffc107';
        }
        
        document.getElementById('inherent_rating_result').innerHTML = `
            <span style="background: ${color}; color: white; padding: 10px 20px; border-radius: 10px; font-weight: bold;">Inherent Risk Rating: ${level.toUpperCase()} (${rating})</span>`;
        
        document.getElementById('risk_rating_value').value = rating;
        document.getElementById('inherent_risk_level_value').value = level.toLowerCase();
        
        const controlAssessment = 1; // Default value as specified
        const residualRating = Math.round(rating * controlAssessment);
        let residualLevel = 'Low';
        let residualColor = '#28a745';
        
        if (residualRating >= 12) {
            residualLevel = 'Critical';
            residualColor = '#dc3545';
        } else if (residualRating >= 8) {
            residualLevel = 'High';
            residualColor = '#fd7e14';
        } else if (residualRating >= 4) {
            residualLevel = 'Medium';
            residualColor = '#ffc107';
        }
        
        document.getElementById('residual_rating_result').innerHTML = `
            <span style="background: ${residualColor}; color: white; padding: 10px 20px; border-radius: 10px; font-weight: bold;">Residual Risk Rating: ${residualLevel.toUpperCase()} (${residualRating})</span>`;
        
        document.getElementById('residual_rating_value').value = residualRating;
        document.getElementById('residual_risk_level_value').value = residualLevel.toLowerCase();
        document.getElementById('control_assessment_value').value = controlAssessment;
        
        // Update primary risk in allRisks array
        allRisks[0] = {
            category: document.getElementById('primary_risk_category_display').textContent.trim(),
            likelihood: likelihood,
            impact: impact,
            rating: rating
        };
        
        calculateGeneralRiskScore();
    } else {
        document.getElementById('inherent_rating_result').textContent = 'Inherent Risk Rating will appear here';
        document.getElementById('residual_rating_result').textContent = 'Residual Risk Rating will appear here';
        document.getElementById('risk_rating_value').value = '';
        document.getElementById('residual_rating_value').value = '';
        document.getElementById('inherent_risk_level_value').value = '';
        document.getElementById('residual_risk_level_value').value = '';
        document.getElementById('control_assessment_value').value = '';
    }
}

        
        function updateImpactBoxes() {
            const category = document.getElementById('impact_category').value;
            const container = document.getElementById('impact_boxes_container');
            
            if (!category) {
                container.style.display = 'none';
                return;
            }
            
            const impactData = getImpactData();
            
            if (impactData[category]) {
                document.getElementById('extreme_text').textContent = impactData[category].extreme;
                document.getElementById('significant_text').textContent = impactData[category].significant;
                document.getElementById('moderate_text').textContent = impactData[category].moderate;
                document.getElementById('minor_text').textContent = impactData[category].minor;
                
                container.style.display = 'grid';
            }
        }
        
        function getImpactData() {
            return {
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
                    moderate: "Breach of cyber security and data privacy attempted and prevented, Any cyberattack and data",
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
        }

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

    <script>

// Impact definitions for each risk category
const impactDefinitions = {
    'Financial Exposure': {
        extreme: "> UGX 500M or > 5% of annual revenue",
        significant: "UGX 100M - 500M or 1-5% of annual revenue", 
        moderate: "UGX 50M - 100M or 0.5-1% of annual revenue",
        minor: "< UGX 50M or < 0.5% of annual revenue"
    },
    'Market Share': {
        extreme: "> 10% decrease in market share",
        significant: "5-10% decrease in market share",
        moderate: "2-5% decrease in market share", 
        minor: "< 2% decrease in market share"
    },
    'Customer Experience': {
        extreme: "Mass customer exodus, permanent reputation damage",
        significant: "Significant customer complaints, media coverage",
        moderate: "Moderate customer dissatisfaction, some complaints",
        minor: "Minimal customer inconvenience"
    },
    'Compliance': {
        extreme: "> UGX 100M in penalties, license revocation",
        significant: "UGX 50M - 100M in penalties, regulatory action",
        moderate: "UGX 10M - 50M in penalties, warnings",
        minor: "< UGX 10M in penalties, minor violations"
    },
    'Reputation': {
        extreme: "National media coverage, permanent brand damage",
        significant: "Regional media coverage, significant brand impact",
        moderate: "Local media coverage, moderate brand impact",
        minor: "Limited negative publicity"
    },
    'Fraud': {
        extreme: "> UGX 200M loss, systemic fraud",
        significant: "UGX 50M - 200M loss, organized fraud",
        moderate: "UGX 10M - 50M loss, isolated incidents",
        minor: "< UGX 10M loss, minor fraud attempts"
    },
    'Operations': {
        extreme: "> 7 days business disruption, complete shutdown",
        significant: "3-7 days disruption, major service impact",
        moderate: "1-3 days disruption, moderate service impact",
        minor: "< 1 day disruption, minimal impact"
    },
    'Networks': {
        extreme: "> 24 hours network outage, complete connectivity loss",
        significant: "8-24 hours outage, major connectivity issues",
        moderate: "2-8 hours outage, moderate connectivity issues",
        minor: "< 2 hours outage, minor connectivity issues"
    },
    'People': {
        extreme: "Multiple fatalities, permanent disabilities",
        significant: "Single fatality, serious injuries",
        moderate: "Serious injury, temporary disability",
        minor: "Minor injury, first aid treatment"
    },
    'IT': {
        extreme: "Complete system compromise, massive data breach",
        significant: "Major system breach, significant data loss",
        moderate: "Limited system breach, moderate data exposure",
        minor: "Minor security incident, minimal data risk"
    },
    'Other': {
        extreme: "Catastrophic impact on business operations",
        significant: "Major impact on business operations",
        moderate: "Moderate impact on business operations",
        minor: "Minor impact on business operations"
    }
};

// Global variables for risk tracking
let additionalRiskCounter = 1;
let allRisks = [];

function toggleAdditionalRisks(show) {
    const container = document.getElementById('additional_risks_container');
    const addButton = document.getElementById('add_risk_btn');
    
    if (show) {
        container.style.display = 'block';
        addButton.style.display = 'inline-block';
        // Add first additional risk automatically
        if (container.children.length === 0) {
            addAdditionalRisk();
        }
    } else {
        container.style.display = 'none';
        addButton.style.display = 'none';
        // Clear all additional risks
        container.innerHTML = '';
        additionalRiskCounter = 1;
        allRisks = allRisks.filter((risk, index) => index === 0); // Keep only primary risk
        calculateGeneralRiskScore();
    }
}

// Update impact boxes based on primary risk category
function updateImpactBoxesFromCategory() {
    const primaryCategory = document.getElementById('primary_risk_category_display').textContent.trim();
    
    if (primaryCategory && primaryCategory !== 'Select Risk Category from Section A' && impactDefinitions[primaryCategory]) {
        const definitions = impactDefinitions[primaryCategory];
        
        document.getElementById('extreme_text').textContent = definitions.extreme;
        document.getElementById('significant_text').textContent = definitions.significant;
        document.getElementById('moderate_text').textContent = definitions.moderate;
        document.getElementById('minor_text').textContent = definitions.minor;
    }
}

// Select likelihood
function selectLikelihood(element, value) {
    // Remove selection from all likelihood boxes
    document.querySelectorAll('.likelihood-box').forEach(box => {
        box.style.border = '3px solid transparent';
    });
    
    // Select current box
    element.style.border = '3px solid #333';
    document.getElementById('likelihood_value').value = value;
    
    calculateRiskRating();
}

// Select impact
function selectImpact(element, value) {
    // Remove selection from all impact boxes
    document.querySelectorAll('.impact-box').forEach(box => {
        box.style.border = '3px solid transparent';
    });
    
    // Select current box
    element.style.border = '3px solid #333';
    document.getElementById('impact_value').value = value;
    
    calculateRiskRating();
}

function calculateRiskRating() {
    const likelihood = parseInt(document.getElementById('likelihood_value').value) || 0;
    const impact = parseInt(document.getElementById('impact_value').value) || 0;
    const primaryCategory = document.getElementById('primary_risk_category_display').textContent.trim();
    
    if (likelihood > 0 && impact > 0) {
        const rating = likelihood * impact;
        let level = 'Low';
        let color = '#28a745';
        
        if (rating >= 12) {
            level = 'Critical';
            color = '#dc3545';
        } else if (rating >= 8) {
            level = 'High';
            color = '#fd7e14';
        } else if (rating >= 4) {
            level = 'Medium';
            color = '#ffc107';
        }
        
        document.getElementById('inherent_rating_result').innerHTML = 
            `<span style="background: ${color}; color: white; padding: 15px 30px; border-radius: 8px; font-weight: bold; font-size: 16px;">${rating} - ${level}</span>`;
        
        document.getElementById('risk_rating_value').value = rating;
        document.getElementById('inherent_risk_level_value').value = level;
        
        // Update residual risk (same as inherent for now)
        document.getElementById('residual_rating_result').innerHTML = 
            `<span style="background: ${color}; color: white; padding: 15px 30px; border-radius: 8px; font-weight: bold; font-size: 16px;">${rating} - ${level}</span>`;
        
        document.getElementById('residual_rating_value').value = rating;
        document.getElementById('residual_risk_level_value').value = level;
        
        // Update allRisks array for primary risk
        allRisks[0] = {
            category: primaryCategory,
            likelihood: likelihood,
            impact: impact,
            rating: rating
        };
        
        calculateGeneralRiskScore();
    } else {
        document.getElementById('inherent_rating_result').innerHTML = 'Inherent Risk Rating will appear here';
        document.getElementById('residual_rating_result').innerHTML = 'Residual Risk Rating will appear here';
        document.getElementById('risk_rating_value').value = '';
        
        // Remove primary risk from allRisks
        delete allRisks[0];
    }
}

// Add additional risk assessment
function addAdditionalRisk() {
    additionalRiskCounter++;
    const romanNumerals = ['', 'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];
    const romanNumeral = romanNumerals[additionalRiskCounter] || additionalRiskCounter.toString();
    
    const container = document.getElementById('additional_risks_container');
    const riskDiv = document.createElement('div');
    riskDiv.className = 'additional-risk';
    riskDiv.id = `additional_risk_${additionalRiskCounter}`;
    
    // Get used categories to prevent duplicates
    const usedCategories = allRisks.map(risk => risk && risk.category).filter(cat => cat);
    const primaryCategory = document.getElementById('primary_risk_category_display').textContent.trim();
    if (primaryCategory && primaryCategory !== 'Select Risk Category from Section A') {
        usedCategories.push(primaryCategory);
    }
    
    riskDiv.innerHTML = `
        <div style="border: 2px solid #e9ecef; border-radius: 10px; padding: 25px; margin-bottom: 20px; background: #f8f9fa;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h5 style="color: #495057; margin: 0;">Additional Risk Assessment (${romanNumeral})</h5>
                <button type="button" onclick="removeAdditionalRisk(${additionalRiskCounter})" 
                        style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label">Risk Category *</label>
                <select id="additional_category_${additionalRiskCounter}" name="additional_categories[]" 
                        onchange="updateAdditionalImpactBoxes(${additionalRiskCounter})" 
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;" required>
                    <option value="">-- Select Category --</option>
                    ${Object.keys(impactDefinitions).map(category => 
                        usedCategories.includes(category) ? '' : `<option value="${category}">${category}</option>`
                    ).join('')}
                </select>
            </div>
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <label class="form-label">Likelihood *</label>
                    <div class="likelihood-boxes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                        <div class="likelihood-box" style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalLikelihood(this, 4, ${additionalRiskCounter})" data-value="4">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">ALMOST CERTAIN</div>
                            <div style="font-size: 12px; line-height: 1.3;">
                                1. Guaranteed to happen<br>
                                2. Has been happening<br>
                                3. Continues to happen
                            </div>
                        </div>
                        <div class="likelihood-box" style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalLikelihood(this, 3, ${additionalRiskCounter})" data-value="3">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">LIKELY</div>
                            <div style="font-size: 12px; line-height: 1.3;">
                                A history of happening at certain intervals/seasons/events
                            </div>
                        </div>
                        <div class="likelihood-box" style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalLikelihood(this, 2, ${additionalRiskCounter})" data-value="2">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">POSSIBLE</div>
                            <div style="font-size: 12px; line-height: 1.3;">
                                1. More than 1 year from last occurrence<br>
                                2. Circumstances indicating or allowing possibility of happening
                            </div>
                        </div>
                        <div class="likelihood-box" style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalLikelihood(this, 1, ${additionalRiskCounter})" data-value="1">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">UNLIKELY</div>
                            <div style="font-size: 12px; line-height: 1.3;">
                                1. Not occurred before<br>
                                2. This is the first time its happening<br>
                                3. Not expected to happen for sometime
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="additional_likelihood[]" id="additional_likelihood_${additionalRiskCounter}" required>
                </div>
                
                <div style="flex: 1; min-width: 300px;">
                    <label class="form-label">Impact *</label>
                    <div class="impact-boxes" id="additional_impact_boxes_${additionalRiskCounter}" style="display: none; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                        <div class="impact-box" style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalImpact(this, 4, ${additionalRiskCounter})" data-value="4">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div>
                            <div id="additional_extreme_text_${additionalRiskCounter}" style="font-size: 12px; line-height: 1.3;"></div>
                        </div>
                        <div class="impact-box" style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalImpact(this, 3, ${additionalRiskCounter})" data-value="3">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div>
                            <div id="additional_significant_text_${additionalRiskCounter}" style="font-size: 12px; line-height: 1.3;"></div>
                        </div>
                        <div class="impact-box" style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalImpact(this, 2, ${additionalRiskCounter})" data-value="2">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div>
                            <div id="additional_moderate_text_${additionalRiskCounter}" style="font-size: 12px; line-height: 1.3;"></div>
                        </div>
                        <div class="impact-box" style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                             onclick="selectAdditionalImpact(this, 1, ${additionalRiskCounter})" data-value="1">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div>
                            <div id="additional_minor_text_${additionalRiskCounter}" style="font-size: 12px; line-height: 1.3;"></div>
                        </div>
                    </div>
                    <input type="hidden" name="additional_impact[]" id="additional_impact_${additionalRiskCounter}" required>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <div id="additional_rating_display_${additionalRiskCounter}" style="display: inline-block; padding: 15px 30px; border: 2px solid #ddd; border-radius: 10px; background: white;">
                    <strong>Rating will appear here</strong>
                </div>
                <input type="hidden" name="additional_ratings[]" id="additional_rating_${additionalRiskCounter}">
            </div>
        </div>
    `;
    
    container.appendChild(riskDiv);
}

// Update impact boxes for additional risk
function updateAdditionalImpactBoxes(riskId) {
    const categorySelect = document.getElementById(`additional_category_${riskId}`);
    const category = categorySelect.value;
    
    if (category && impactDefinitions[category]) {
        const definitions = impactDefinitions[category];
        
        document.getElementById(`additional_extreme_text_${riskId}`).textContent = definitions.extreme;
        document.getElementById(`additional_significant_text_${riskId}`).textContent = definitions.significant;
        document.getElementById(`additional_moderate_text_${riskId}`).textContent = definitions.moderate;
        document.getElementById(`additional_minor_text_${riskId}`).textContent = definitions.minor;
        
        document.getElementById(`additional_impact_boxes_${riskId}`).style.display = 'grid';
    } else {
        document.getElementById(`additional_impact_boxes_${riskId}`).style.display = 'none';
    }
}

// Select additional likelihood
function selectAdditionalLikelihood(element, value, riskId) {
    const container = document.getElementById(`additional_risk_${riskId}`);
    container.querySelectorAll('.likelihood-box').forEach(box => {
        box.style.border = '3px solid transparent';
    });
    
    element.style.border = '3px solid #333';
    document.getElementById(`additional_likelihood_${riskId}`).value = value;
    
    calculateAdditionalRiskRating(riskId);
}

// Select additional impact
function selectAdditionalImpact(element, value, riskId) {
    const container = document.getElementById(`additional_risk_${riskId}`);
    container.querySelectorAll('.impact-box').forEach(box => {
        box.style.border = '3px solid transparent';
    });
    
    element.style.border = '3px solid #333';
    document.getElementById(`additional_impact_${riskId}`).value = value;
    
    calculateAdditionalRiskRating(riskId);
}

// Calculate additional risk rating
function calculateAdditionalRiskRating(riskId) {
    const likelihood = parseInt(document.getElementById(`additional_likelihood_${riskId}`).value) || 0;
    const impact = parseInt(document.getElementById(`additional_impact_${riskId}`).value) || 0;
    const category = document.getElementById(`additional_category_${riskId}`).value;
    
    if (likelihood > 0 && impact > 0) {
        const rating = likelihood * impact;
        let level = 'Low';
        let color = '#28a745';
        
        if (rating >= 12) {
            level = 'Critical';
            color = '#dc3545';
        } else if (rating >= 8) {
            level = 'High';
            color = '#fd7e14';
        } else if (rating >= 4) {
            level = 'Medium';
            color = '#ffc107';
        }
        
        document.getElementById(`additional_rating_display_${riskId}`).innerHTML = 
            `<span style="background: ${color}; color: white; padding: 10px 20px; border-radius: 10px; font-weight: bold;">${rating} - ${level}</span>`;
        
        document.getElementById(`additional_rating_${riskId}`).value = rating;
        
        // Update allRisks array
        allRisks[riskId] = {
            category: category,
            likelihood: likelihood,
            impact: impact,
            rating: rating
        };
        
        calculateGeneralRiskScore();
    } else {
        document.getElementById(`additional_rating_display_${riskId}`).innerHTML = '<strong>Rating will appear here</strong>';
        document.getElementById(`additional_rating_${riskId}`).value = '';
        
        // Remove from allRisks array
        delete allRisks[riskId];
    }
}

// Remove additional risk
function removeAdditionalRisk(riskId) {
    document.getElementById(`additional_risk_${riskId}`).remove();
    delete allRisks[riskId];
    calculateGeneralRiskScore();
}

// Calculate general risk score
function calculateGeneralRiskScore() {
    const validRisks = allRisks.filter(risk => risk && risk.rating > 0);
    
    if (validRisks.length > 0) {
        const totalRating = validRisks.reduce((sum, risk) => sum + risk.rating, 0);
        const averageRating = totalRating / validRisks.length;
        const inherentScore = averageRating * validRisks.length;
        
        document.getElementById('general_inherent_score').textContent = inherentScore.toFixed(2);
        document.getElementById('general_inherent_risk_score_value').value = inherentScore.toFixed(2);
        
        const controlAssessment = 1; // Default value
        const residualScore = inherentScore * controlAssessment;
        document.getElementById('general_residual_score').textContent = residualScore.toFixed(2);
        document.getElementById('general_residual_risk_score_value').value = residualScore.toFixed(2);
    } else {
        document.getElementById('general_inherent_score').textContent = '0.00';
        document.getElementById('general_residual_score').textContent = '0.00';
        document.getElementById('general_inherent_risk_score_value').value = '0';
        document.getElementById('general_residual_risk_score_value').value = '0';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize impact boxes based on existing primary category
    updateImpactBoxesFromCategory();
    
    // Initialize allRisks array with primary risk if it exists
    const primaryLikelihood = parseInt(document.getElementById('likelihood_value')?.value) || 0;
    const primaryImpact = parseInt(document.getElementById('impact_value')?.value) || 0;
    const primaryCategory = document.getElementById('primary_risk_category_display')?.textContent.trim();
    
    if (primaryLikelihood > 0 && primaryImpact > 0 && primaryCategory !== 'Select Risk Category from Section A') {
        allRisks[0] = {
            category: primaryCategory,
            likelihood: primaryLikelihood,
            impact: primaryImpact,
            rating: primaryLikelihood * primaryImpact
        };
        calculateGeneralRiskScore();
    }
});

// Listen for changes in primary risk category from Section A
function updatePrimaryRiskCategory(category) {
    document.getElementById('primary_risk_category_display').textContent = category;
    updateImpactBoxesFromCategory();
    
    // Update primary risk in allRisks array
    if (allRisks[0]) {
        allRisks[0].category = category;
        calculateGeneralRiskScore();
    }
}

</script>

</body>
</html>
