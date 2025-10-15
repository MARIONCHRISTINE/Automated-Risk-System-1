<?php
// Handle AJAX request for checking existing risks FIRST, before any HTML output
if (isset($_GET['action']) && $_GET['action'] === 'check_existing_risks') {
    // Only include necessary files for this endpoint
    include_once 'config/database.php';
    
    header('Content-Type: application/json');
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $primary_risk = $_GET['primary_risk'] ?? '';
        
        if (empty($primary_risk)) {
            echo json_encode(['success' => false, 'message' => 'Primary risk category is required']);
            exit();
        }
        
        // Check if we're in merge mode and need to exclude original risks
        $exclude_risk_ids = [];
        if (isset($_SESSION['merge_risk_ids']) && !empty($_SESSION['merge_risk_ids'])) {
            $exclude_risk_ids = $_SESSION['merge_risk_ids'];
        }
        
        // Query to find risks where the first element of risk_categories matches the primary risk
        if (!empty($exclude_risk_ids)) {
            $placeholders = implode(',', array_fill(0, count($exclude_risk_ids), '?'));
            $query = "SELECT risk_id, risk_name, created_at, risk_categories 
                      FROM risk_incidents 
                      WHERE (JSON_UNQUOTE(JSON_EXTRACT(risk_categories, '$[0]')) = ?
                             OR JSON_UNQUOTE(JSON_EXTRACT(risk_categories, '$[0][0]')) = ?)
                      AND risk_id NOT IN ($placeholders)
                      ORDER BY created_at DESC";
            
            $stmt = $db->prepare($query);
            $params = array_merge([$primary_risk, $primary_risk], $exclude_risk_ids);
            $stmt->execute($params);
        } else {
            $query = "SELECT risk_id, risk_name, created_at, risk_categories 
                      FROM risk_incidents 
                      WHERE (JSON_UNQUOTE(JSON_EXTRACT(risk_categories, '$[0]')) = ?
                             OR JSON_UNQUOTE(JSON_EXTRACT(risk_categories, '$[0][0]')) = ?)
                      ORDER BY created_at DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$primary_risk, $primary_risk]);
        }
        
        $existing_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the risks for display
        $formatted_risks = array_map(function($risk) {
            return [
                'risk_id' => $risk['risk_id'],
                'risk_name' => $risk['risk_name'],
                'date_reported' => date('M j, Y', strtotime($risk['created_at']))
            ];
        }, $existing_risks);
        
        echo json_encode([
            'success' => true,
            'count' => count($existing_risks),
            'risks' => $formatted_risks,
            'is_new' => count($existing_risks) === 0
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Now include other files for normal page rendering
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

// Check if this is a merge operation
$is_merge_mode = false;
$merge_risks = [];
$merged_risk_id = '';

if (isset($_SESSION['merge_risk_ids']) && !empty($_SESSION['merge_risk_ids'])) {
    echo "<script>console.log('[v0] Merge mode detected. Session merge_risk_ids:', " . json_encode($_SESSION['merge_risk_ids']) . ");</script>";
    
    $is_merge_mode = true;
    $merge_risk_ids = $_SESSION['merge_risk_ids'];
    
    echo "<script>console.log('[v0] Merge mode active. Risk IDs to merge:', " . json_encode($merge_risk_ids) . ");</script>";
    
    // Fetch the selected risks from database using risk_id (not database id)
    $placeholders = implode(',', array_fill(0, count($merge_risk_ids), '?'));
    $merge_query = "SELECT id, risk_id, risk_name, risk_description, risk_categories, 
                           inherent_risk_level, risk_status, date_of_occurrence, reported_by
                    FROM risk_incidents 
                    WHERE risk_id IN ($placeholders)
                    ORDER BY risk_id DESC";
    
    $merge_stmt = $db->prepare($merge_query);
    $merge_stmt->execute($merge_risk_ids);
    $merge_risks = $merge_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<script>console.log('[v0] Fetched merge risks from database:', " . json_encode($merge_risks) . ");</script>";
    
    // Generate merged risk ID by combining risk numbers
    if (!empty($merge_risks)) {
        // Extract year/month from first risk
        $first_risk_id = $merge_risks[0]['risk_id'];
        $parts = explode('/', $first_risk_id);
        
        if (count($parts) >= 4) {
            $dept_initial = $parts[0];
            $year = $parts[1];
            $month = $parts[2];
            
            // Collect all risk sequence numbers (the last part of each risk ID)
            $risk_numbers = [];
            foreach ($merge_risks as $risk) {
                $risk_parts = explode('/', $risk['risk_id']);
                // Get the last part which is the sequence number
                $risk_numbers[] = end($risk_parts);
            }
            
            // Create merged risk ID: DEPT/YYYY/MM/##/##/##
            $merged_risk_id = $dept_initial . '/' . $year . '/' . $month . '/' . implode('/', $risk_numbers);
            
            echo "<script>console.log('[v0] Generated merged risk ID:', '" . $merged_risk_id . "');</script>";
        } else {
            echo "<script>console.error('[v0] Invalid risk ID format:', '" . $first_risk_id . "');</script>";
        }
    } else {
        echo "<script>console.error('[v0] No merge risks found in database!');</script>";
    }
} else {
    echo "<script>console.log('[v0] Merge mode NOT detected. Session merge_risk_ids is empty or not set.');</script>";
}


if (isset($_GET['download_document']) && isset($_GET['doc_id'])) {
    $doc_id = $_GET['doc_id'];
    
    $doc_query = "SELECT file_path, original_filename, mime_type 
                  FROM risk_documents 
                  WHERE id = :doc_id";
    $doc_stmt = $db->prepare($doc_query);
    $doc_stmt->bindParam(':doc_id', $doc_id);
    $doc_stmt->execute();
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($document) {
        $filePath = $document['file_path'];
        if (file_exists($filePath)) {
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $document['mime_type']);
            header('Content-Disposition: attachment; filename="' . basename($document['original_filename']) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            http_response_code(404);
            echo "File not found at path: " . htmlspecialchars($filePath);
            exit;
        }
    } else {
        http_response_code(404);
        echo "Document not found in database";
        exit;
    }
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


$risk_owners = [];

// Get risk owners for user assignment
$risk_owners_query = "SELECT id, full_name, department FROM users WHERE role IN ('risk_owner', 'admin') ORDER BY full_name";
$risk_owners_stmt = $db->prepare($risk_owners_query);
$risk_owners_stmt->execute();
$risk_owners = $risk_owners_stmt->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error = '';


if ($_POST && isset($_POST['cancel_risk'])) {
    // Clear any session data related to this form
    unset($_SESSION['form_data']);
    unset($_SESSION['merge_risk_ids']);
    
    // Redirect to reload page with clear form
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}


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
        
        // Generate the risk ID in format: DEPT/YEAR/MONTH/NUMBER
        $risk_id = $dept_initial . '/' . $year . '/' . $month . '/' . $sequential_number;
        
        return $risk_id;
        
    } catch (Exception $e) {
        error_log("Error generating risk ID: " . $e->getMessage());
        return null;
    }
}

if ($_POST && isset($_POST['submit_risk_report'])) {
    echo "<script>console.log('[v0] Form submitted. POST data received.');</script>";
    echo "<script>console.log('[v0] Is merge mode:', " . ($is_merge_mode ? 'true' : 'false') . ");</script>";
    echo "<script>console.log('[v0] Merged risk ID:', '" . $merged_risk_id . "');</script>";
    
    // Handle risk report submission (new separate submission)
    try {
        $db->beginTransaction();
        
        echo "<script>console.log('[v0] Database transaction started.');</script>";

        // Validate required fields
        if (empty($_POST['risk_description']) || (empty($_POST['cause_people_hidden']) || $_POST['cause_people_hidden'] === '[]') && (empty($_POST['cause_process_hidden']) || $_POST['cause_process_hidden'] === '[]') && (empty($_POST['cause_it_systems_hidden']) || $_POST['cause_it_systems_hidden'] === '[]') && (empty($_POST['cause_external_hidden']) || $_POST['cause_external_hidden'] === '[]')) {
            throw new Exception('Risk description and at least one cause are required');
        }
        
        echo "<script>console.log('[v0] Validation passed: risk description and causes.');</script>";
        
        $inherent_likelihood = $_POST['inherent_likelihood_level'] ?? null;
        $inherent_impact = $_POST['inherent_impact_level'] ?? null;
        
        if (empty($inherent_likelihood) || empty($inherent_impact)) {
            throw new Exception('Likelihood and impact must be selected');
        }
        
        echo "<script>console.log('[v0] Validation passed: likelihood and impact.');</script>";
        
        // Modified logic to use merged risk ID or generate new one
        if ($is_merge_mode && !empty($merged_risk_id)) {
            $generated_risk_id = $merged_risk_id;
            echo "<script>console.log('[v0] Using merged risk ID:', '" . $generated_risk_id . "');</script>";
        } else {
            $generated_risk_id = generateRiskId($db, $user['department']);
            echo "<script>console.log('[v0] Generated new risk ID:', '" . $generated_risk_id . "');</script>";
        }
        
        if (!$generated_risk_id) {
            throw new Exception("Failed to generate risk ID.");
        }
        
        $primary_risk_category_input = $_POST['risk_categories'] ?? '';
        $impact_descriptions = [
            '1' => 'Minor Impact',
            '2' => 'Moderate Impact', 
            '3' => 'Significant Impact',
            '4' => 'Extreme Impact'
        ];
        $impact_description = $impact_descriptions[$inherent_impact] ?? 'Unknown Impact';
        $risk_name = $primary_risk_category_input . ' - ' . $impact_description;
        
        $all_risk_categories = [];
        
        // Add primary risk category
        if (!empty($_POST['risk_categories'])) {
            $all_risk_categories[] = $_POST['risk_categories'];
        }
        
        // Combine cause data from hidden fields
        $all_causes = [];
        if (!empty($_POST['cause_people_hidden']) && $_POST['cause_people_hidden'] !== '[]') {
            $all_causes = array_merge($all_causes, json_decode($_POST['cause_people_hidden'], true));
        }
        if (!empty($_POST['cause_process_hidden']) && $_POST['cause_process_hidden'] !== '[]') {
            $all_causes = array_merge($all_causes, json_decode($_POST['cause_process_hidden'], true));
        }
        if (!empty($_POST['cause_it_systems_hidden']) && $_POST['cause_it_systems_hidden'] !== '[]') {
            $all_causes = array_merge($all_causes, json_decode($_POST['cause_it_systems_hidden'], true));
        }
        if (!empty($_POST['cause_external_hidden']) && $_POST['cause_external_hidden'] !== '[]') {
            $all_causes = array_merge($all_causes, json_decode($_POST['cause_external_hidden'], true));
        }
        $cause_of_risk_json = json_encode(array_unique($all_causes));

        // Add secondary risk categories
        $secondary_index = 1;
        while (isset($_POST["secondary_risk_category_$secondary_index"])) {
            $secondary_category = $_POST["secondary_risk_category_$secondary_index"];
            if (!empty($secondary_category)) {
                $all_risk_categories[] = $secondary_category;
            }
            $secondary_index++;
        }
        
        $risk_categories_json = json_encode($all_risk_categories);
        
        $all_likelihood_values = [];
        if (!empty($inherent_likelihood)) {
            $all_likelihood_values[] = $inherent_likelihood;
        }
        
        $secondary_index = 1;
        while (isset($_POST["secondary_likelihood_$secondary_index"])) {
            $secondary_likelihood = $_POST["secondary_likelihood_$secondary_index"];
            if (!empty($secondary_likelihood)) {
                $all_likelihood_values[] = $secondary_likelihood;
            }
            $secondary_index++;
        }
        
        $inherent_likelihood_values = implode(',', $all_likelihood_values);
        
        $all_consequence_values = [];
        if (!empty($inherent_impact)) {
            $all_consequence_values[] = $inherent_impact;
        }
        
        $secondary_index = 1;
        while (isset($_POST["secondary_impact_$secondary_index"])) {
            $secondary_impact = $_POST["secondary_impact_$secondary_index"];
            if (!empty($secondary_impact)) {
                $all_consequence_values[] = $secondary_impact;
            }
            $secondary_index++;
        }
        
        $inherent_consequence_values = implode(',', $all_consequence_values);
        
        $all_risk_ratings = [];
        $primary_risk_rating = intval($inherent_likelihood) * intval($inherent_impact);
        $all_risk_ratings[] = $primary_risk_rating;
        
        // Re-initialize secondary_index for calculating secondary risk ratings
        $secondary_index = 1; 
        while (isset($_POST["secondary_risk_category_$secondary_index"])) {
            $sec_likelihood = $_POST["secondary_likelihood_$secondary_index"];
            $sec_impact = $_POST["secondary_impact_$secondary_index"];
            if (!empty($sec_likelihood) && !empty($sec_impact)) {
                $secondary_risk_rating = intval($sec_likelihood) * intval($sec_impact);
                $all_risk_ratings[] = $secondary_risk_rating;
            }
            $secondary_index++;
        }
        
        $risk_rating_values = implode(',', $all_risk_ratings);
        
        $control_assessment_values = implode(',', array_fill(0, count($all_risk_ratings), '1')); // Assuming control assessment is always 1 for now
        
        $all_residual_ratings = [];
        foreach ($all_risk_ratings as $rating) {
            $all_residual_ratings[] = $rating * 1; 
        }
        $residual_rating_values = implode(',', $all_residual_ratings);
        
        $general_inherent_risk_score = $_POST['general_inherent_risk_score'] ?? null;
        $general_residual_risk_score = $_POST['general_residual_risk_score'] ?? null;
        function calculateRiskLevel($rating) {
            if ($rating >= 12) {
                return 'CRITICAL';
            } elseif ($rating >= 8) {
                return 'HIGH';
            } elseif ($rating >= 4) {
                return 'MEDIUM';
            } else {
                return 'LOW';
            }
        }
        function calculateGeneralRiskLevel($score, $maxScale) {
            if ($maxScale == 0) return 'LOW'; 
            
            // Use the same thresholds as JavaScript for consistency
            $lowThreshold = floor($maxScale * 3 / 16); // Approx 18.75%
            $mediumThreshold = floor($maxScale * 7 / 16); // Approx 43.75%
            $highThreshold = floor($maxScale * 11 / 16); // Approx 68.75%
            
            if ($score > $highThreshold) {
                return 'CRITICAL';
            } elseif ($score > $mediumThreshold) {
                return 'HIGH';
            } elseif ($score > $lowThreshold) {
                return 'MEDIUM';
            } else {
                return 'LOW';
            }
        }
        
        // Calculate inherent risk levels for all risks (primary + secondary)
        $inherent_risk_levels = [];
        foreach ($all_risk_ratings as $rating) {
            $inherent_risk_levels[] = calculateRiskLevel($rating);
        }
        $inherent_risk_level = implode(',', $inherent_risk_levels);
        
        // Calculate residual risk levels for all risks (primary + secondary)
        $residual_risk_levels = [];
        foreach ($all_residual_ratings as $rating) {
            $residual_risk_levels[] = calculateRiskLevel($rating);
        }
        $residual_risk_level = implode(',', $residual_risk_levels);
        
        $general_inherent_total = array_sum($all_risk_ratings);
        $general_residual_total = array_sum($all_residual_ratings);
        
        $max_possible_score = 0;
        if (count($all_risk_ratings) > 0) {
            $max_possible_score = 16 * count($all_risk_ratings); // Max rating per risk is 4*4=16
        }
        
        $general_inherent_risk_level = calculateGeneralRiskLevel($general_inherent_total, $max_possible_score);
        $general_residual_risk_level = calculateGeneralRiskLevel($general_residual_total, $max_possible_score);
        
        // Handle multiple file uploads (mandatory - at least one file required)
        $uploaded_files = [];
        if (!isset($_FILES['supporting_document']) || empty($_FILES['supporting_document']['name'][0])) {
            throw new Exception("At least one supporting document is mandatory. Please upload a file.");
        }
        
        $upload_dir = 'uploads/risk_documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'msg', 'eml'];
        $max_file_size = 10 * 1024 * 1024; // 10MB
        
        // Process each uploaded file
        $file_count = count($_FILES['supporting_document']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            // Skip if no file or error
            if ($_FILES['supporting_document']['error'][$i] !== UPLOAD_ERR_OK) {
                if ($_FILES['supporting_document']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue; // Skip empty file slots
                }
                throw new Exception("Error uploading file: " . $_FILES['supporting_document']['name'][$i]);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['supporting_document']['name'][$i], PATHINFO_EXTENSION));
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception("Invalid file type for " . $_FILES['supporting_document']['name'][$i] . ". Please upload PDF, DOC, DOCX, TXT, JPG, PNG, Excel, or email files.");
            }
            
            if ($_FILES['supporting_document']['size'][$i] > $max_file_size) {
                throw new Exception("File " . $_FILES['supporting_document']['name'][$i] . " is too large. Please upload files under 10MB.");
            }
            
            $file_name = uniqid() . '_' . time() . '_' . $i . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            
            if (!move_uploaded_file($_FILES['supporting_document']['tmp_name'][$i], $upload_path)) {
                throw new Exception("Failed to upload " . $_FILES['supporting_document']['name'][$i]);
            }
            
            $uploaded_files[] = [
                'path' => $upload_path,
                'original_name' => $_FILES['supporting_document']['name'][$i],
                'size' => $_FILES['supporting_document']['size'][$i]
            ];
        }
        
        if (empty($uploaded_files)) {
            throw new Exception("At least one supporting document is mandatory. Please upload a file.");
        }

        $query = "INSERT INTO risk_incidents (
            risk_id, risk_name, risk_description, cause_of_risk, department, reported_by, risk_owner_id,
            existing_or_new, risk_categories, date_of_occurrence, involves_money_loss, money_range, reported_to_glpi, glpi_ir_number,
            inherent_likelihood, inherent_consequence, 
            risk_rating, inherent_risk_level, residual_risk_level,
            control_assessment, residual_rating,
            general_inherent_risk_score, general_residual_risk_score,
            general_inherent_risk_level, general_residual_risk_level,
            risk_status,
            created_at, updated_at
        ) VALUES (
            :risk_id, :risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id,
            :existing_or_new, :risk_categories, :date_of_occurrence, :involves_money_loss, :money_range, :reported_to_glpi, :glpi_ir_number,
            :inherent_likelihood, :inherent_consequence,
            :risk_rating, :inherent_risk_level, :residual_risk_level,
            :control_assessment, :residual_rating,
            :general_inherent_risk_score, :general_residual_risk_score,
            :general_inherent_risk_level, :general_residual_risk_level,
            :risk_status,
            NOW(), NOW()
        )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_id', $generated_risk_id);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_description', $_POST['risk_description']);
        $stmt->bindParam(':cause_of_risk', $cause_of_risk_json);
        $stmt->bindParam(':department', $user['department']);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']); // Defaulting risk_owner_id to reporter for now
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':risk_categories', $risk_categories_json);
        $stmt->bindParam(':date_of_occurrence', $_POST['date_of_occurrence']);
        $stmt->bindParam(':involves_money_loss', $_POST['involves_money_loss']);
        $stmt->bindParam(':money_range', $_POST['money_range']);
        $stmt->bindParam(':reported_to_glpi', $_POST['reported_to_glpi']);
        $stmt->bindParam(':glpi_ir_number', $_POST['glpi_ir_number']);
        // $stmt->bindParam(':supporting_documents', $supporting_documents_json);
        $stmt->bindParam(':inherent_likelihood', $inherent_likelihood_values);
        $stmt->bindParam(':inherent_consequence', $inherent_consequence_values);
        $stmt->bindParam(':risk_rating', $risk_rating_values);
        $stmt->bindParam(':inherent_risk_level', $inherent_risk_level);
        $stmt->bindParam(':residual_risk_level', $residual_risk_level);
        $stmt->bindParam(':control_assessment', $control_assessment_values);
        $stmt->bindParam(':residual_rating', $residual_rating_values);
        $stmt->bindParam(':general_inherent_risk_score', $general_inherent_risk_score);
        $stmt->bindParam(':general_residual_risk_score', $general_residual_risk_score);
        $stmt->bindParam(':general_inherent_risk_level', $general_inherent_risk_level);
        $stmt->bindParam(':general_residual_risk_level', $general_residual_risk_level);
        $stmt->bindParam(':risk_status', $_POST['general_risk_status']);
        
        if ($stmt->execute()) {
            $risk_incident_id = $db->lastInsertId();
            
            echo "<script>console.log('[v0] Risk inserted successfully. New risk ID:', " . $risk_incident_id . ");</script>";
            
            if ($is_merge_mode && !empty($_SESSION['merge_risk_ids'])) {
                echo "<script>console.log('[v0] Starting consolidation of original risks:', " . json_encode($_SESSION['merge_risk_ids']) . ");</script>";
                
                $consolidate_placeholders = implode(',', array_fill(0, count($_SESSION['merge_risk_ids']), '?'));
                $consolidate_query = "UPDATE risk_incidents 
                                     SET risk_status = 'Consolidated', 
                                         updated_at = NOW() 
                                     WHERE risk_id IN ($consolidate_placeholders)";
                $consolidate_stmt = $db->prepare($consolidate_query);
                if (!$consolidate_stmt->execute($_SESSION['merge_risk_ids'])) {
                    throw new Exception("Failed to update original risks to Consolidated status.");
                }
                
                echo "<script>console.log('[v0] Successfully updated " . $consolidate_stmt->rowCount() . " risks to Consolidated status.');</script>";
            }
            
            $doc_query = "INSERT INTO risk_documents (
                            risk_id,
                            unique_risk_id,
                            section_type, 
                            original_filename, 
                            stored_filename, 
                            file_path, 
                            file_size, 
                            mime_type,
                            uploaded_by, 
                            uploaded_at
                          ) VALUES (
                            :risk_id,
                            :unique_risk_id,
                            'supporting_documents', 
                            :original_filename, 
                            :stored_filename, 
                            :file_path, 
                            :file_size, 
                            :mime_type,
                            :uploaded_by, 
                            NOW()
                          )";
            
            $doc_stmt = $db->prepare($doc_query);
            $user_id = $_SESSION['user_id'];
            
            foreach ($uploaded_files as $file) {
                // Use finfo for MIME type detection
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['path']);
                finfo_close($finfo);
                
                $stored_filename = basename($file['path']);
                
                $doc_stmt->bindParam(':risk_id', $risk_incident_id);
                $doc_stmt->bindParam(':unique_risk_id', $generated_risk_id);
                $doc_stmt->bindParam(':original_filename', $file['original_name']);
                $doc_stmt->bindParam(':stored_filename', $stored_filename);
                $doc_stmt->bindParam(':file_path', $file['path']);
                $doc_stmt->bindParam(':file_size', $file['size']);
                $doc_stmt->bindParam(':mime_type', $mime_type);
                $doc_stmt->bindParam(':uploaded_by', $user_id);
                
                if (!$doc_stmt->execute()) {
                    throw new Exception("Failed to upload supporting document: " . $file['original_name']);
                }
            }
            
            $db->commit();
            
            echo "<script>console.log('[v0] Transaction committed successfully.');</script>";
            
            unset($_SESSION['merge_risk_ids']);
            
            echo "<script>console.log('[v0] Session merge_risk_ids cleared.');</script>";
            
            $_SESSION['success_message'] = "Risk report submitted successfully!";
            
            header("Location: risk_owner_dashboard.php");
            exit();
        } else {
            $db->rollback();
            echo "<script>console.log('[v0] ERROR: Failed to execute INSERT statement.');</script>";
            $error = "Failed to submit risk report.";
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        echo "<script>console.log('[v0] ERROR caught:', '" . addslashes($e->getMessage()) . "');</script>";
        $error = $e->getMessage();
    }
}


$submitted_risk = null;
if (isset($_SESSION['submitted_risk_id'])) {
    $risk_query = "SELECT * FROM risk_incidents WHERE id = :risk_id AND reported_by = :user_id"; // Changed to reported_by for consistency
    $risk_stmt = $db->prepare($risk_query);
    $risk_stmt->bindParam(':risk_id', $_SESSION['submitted_risk_id']);
    $risk_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $risk_stmt->execute();
    $submitted_risk = $risk_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get form data from session if available for repopulation
$form_data = $_SESSION['form_data'] ?? [];

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
            justify-content: space_between;
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
            padding: 0.75rem 1rem;
            margin: 2rem 0 1rem 0;
            border-radius: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #E60012;
            border-bottom: 1px solid #eee;
        }
        
        .section-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        .section-header p {
            margin: 0;
            font-size: 0.9rem;
            color: #6c757d;
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
        
        /* Updated form-label styling to match the style from staff_dashboard.php */
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
        
        /* Updated styling for single selection radio buttons */
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

        /* Enhanced category name styling with color distinction for radio buttons */
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

        /* Updated radio-category-label to handle both radio and checkbox inputs */
        .radio-category-label input[type="radio"],
        .radio-category-label input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.4);
            accent-color: white;
        }

        /* Cause of Risk checkbox styling */
        .cause-checkboxes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 10px;
        }

        .cause-checkbox-item {
            padding: 15px;
            border: 2px solid #4caf50;
            border-radius: 8px;
            background: linear-gradient(135deg, #e8f5e8, #f1f8e9);
            transition: all 0.3s ease;
        }

        .cause-checkbox-item:hover {
            background: linear-gradient(135deg, #c8e6c9, #dcedc8);
            border-color: #388e3c;
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }

        .cause-checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #2e7d32;
        }

        .cause-checkbox-label input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.3);
            accent-color: #4caf50;
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

        /* CHANGE START: Added CSS for conditional sections, cause cards, and modals */
        /* Conditional Section Styles */
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
        
        /* Cause Dropdown Styles */
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
        
        /* Cause Cards Container */
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
        
        /* Cause Modal Styles */
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
        
        /* Info Text Styles */
        .info-text {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Styled Textarea Container */
        .styled-textarea-container {
            width: 100%;
        }
        
        .styled-textarea {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            height: 150px;
            resize: vertical;
            font-family: inherit;
        }
        
        .styled-textarea:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        /* File Upload Styles */
        .styled-file-container {
            width: 100%;
        }
        
        .styled-file-upload {
            position: relative;
        }
        
        .styled-file-input {
            display: none;
        }
        
        .styled-file-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            border: 2px dashed #e1e5e9;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
        }
        
        .styled-file-label:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
            color: #E60012;
            transform: translateY(-2px);
        }
        
        .styled-file-label.drag-over {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.1);
            border-style: solid;
        }
        
        .file-upload-icon {
            font-size: 3rem;
            color: #E60012;
            margin-bottom: 10px;
        }
        
        .file-upload-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .file-upload-subtext {
            font-size: 0.9rem;
            color: #666;
        }
        
        .selected-files-list {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            display: none;
        }
        
        .selected-files-list.show {
            display: block;
        }
        
        .selected-files-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: white;
            border: 1px solid #e1e5e9;
            border-radius: 4px;
            margin-bottom: 8px;
        }
        
        .file-item-name {
            flex: 1;
            color: #333;
            font-size: 0.9rem;
        }
        
        .file-item-remove {
            color: #E60012;
            cursor: pointer;
            font-weight: bold;
            padding: 0 8px;
        }
        
        .file-item-remove:hover {
            color: #B8000E;
        }
        
        @media (max-width: 768px) {
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
        }
        /* CHANGE END */
        
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

        /* Removed all treatment-related CSS */
        
        /* New styling for Risk Description textarea to match the category styling */
        .styled-textarea-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }

        .styled-textarea {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border: 2px solid #e3f2fd;
            border-radius: 8px;
            background: linear-gradient(135deg, #fafafa, #f5f5f5);
            font-size: 16px;
            font-weight: 500;
            color: #333;
            resize: vertical;
            transition: all 0.3s ease;
        }

        .styled-textarea:focus {
            outline: none;
            border-color: #E60012;
            background: linear-gradient(135deg, #fff, #fafafa);
            box-shadow: 0 4px 8px rgba(230, 0, 18, 0.1);
            transform: translateY(-1px);
        }

        /* New styling for file upload to match the category styling */
        .styled-file-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
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
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 30px 20px;
            border: 3px dashed #ddd;
            border-radius: 12px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
            font-size: 1rem;
            min-height: 150px;
        }
        .styled-file-label:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
            color: #E60012;
            transform: translateY(-2px);
        }
        .styled-file-label.drag-over {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.1);
            border-style: solid;
        }
        .styled-file-label i {
            font-size: 1.5rem;
        }
        .file-upload-icon {
            font-size: 3rem;
            color: #E60012;
            margin-bottom: 10px;
        }
        .file-upload-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .file-upload-subtext {
            font-size: 0.9rem;
            color: #666;
        }
        .selected-files-list {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #E60012;
            display: none;
        }
        .selected-files-list.show {
            display: block;
        }
        .selected-files-title {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        .selected-file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #e1e5e9;
        }
        .selected-file-item:last-child {
            margin-bottom: 0;
        }
        .selected-file-name {
            font-size: 0.9rem;
            color: #333;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .selected-file-size {
            font-size: 0.85rem;
            color: #666;
            margin-left: 10px;
            margin-right: 10px;
        }
        .remove-file-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            transition: background 0.3s;
        }
        .remove-file-btn:hover {
            background: #c82333;
        }

        /* Risk badges */
        .risk-badge {
            display: inline-block;
            padding: 0.4em 0.6em;
            font-size: 0.8em;
            font-weight: 700;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
        }

        .risk-badge.low {
            color: #000;
            background-color: #88dd88;
        }

        .risk-badge.medium {
            color: #000;
            background-color: #ffdd00;
        }

        .risk-badge.high {
            background-color: #ff8800;
        }

        .risk-badge.critical {
            background-color: #ff4444;
        }

        /* Added unified secondary risk assessment styling */
        .secondary-risk-assessment {
            margin: 20px 0;
            padding: 20px;
            background: #fff;
            border: 2px solid #6f42c1; /* Using a distinct color for secondary risks */
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(111, 66, 193, 0.1); /* Shadow matching the border color */
        }

        .secondary-risk-header {
            color: #6f42c1;
            margin-bottom: 15px;
            font-weight: 600;
            font-size: 18px;
        }

        .secondary-risk-content {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .secondary-likelihood-section,
        .secondary-impact-section {
            flex: 1;
            min-width: 300px;
        }

        .secondary-likelihood-boxes,
        .secondary-impact-boxes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }

        .secondary-likelihood-box,
        .secondary-impact-box {
            padding: 15px;
            border-radius: 8px;
            cursor: pointer;
            border: 3px solid transparent;
            text-align: center;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .secondary-likelihood-box:hover,
        .secondary-impact-box:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .secondary-risk-rating-display {
            text-align: center;
            padding: 10px;
            margin-top: 15px;
            border-radius: 4px;
            font-weight: bold;
        }

        .next-risk-question {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
        }

        .next-risk-options {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .next-risk-option {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 6px;
            transition: all 0.3s;
        }

        .next-risk-option:hover {
            border-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }

        .next-risk-option input[type="radio"] {
            margin-right: 8px;
        }

        /* Removed all treatment-related styling */
        
    </style>
    <style>
        /* Added missing modal styles for Risk Chain Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
            color: white;
        }
        
        .modal-header .close {
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
        
        .modal-header .close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 2rem;
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
                <h2 class="card-title"><?php echo $is_merge_mode ? 'Consolidate Risks' : 'Risk Registration Form'; ?></h2>
            </div>
            <div class="card-body">
                
                <div class="progress-indicator">
                    <div class="progress-step active">1. Identify</div>
                    <div class="progress-step">2. Assess</div>
                    <div class="progress-step">3. Treat</div>
                    <div class="progress-step">4. Monitor</div>
                </div>
                
                <?php if (!empty($error)):  ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                <?php endif; ?>
            
            <?php if (!$submitted_risk): ?>
                <!-- Risk Assessment Form - Only show if risk not yet submitted -->
                <form method="POST" enctype="multipart/form-data">
                    <?php if ($is_merge_mode): ?>
                        <div style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-left: 4px solid #ffc107; border-radius: 8px;">
                            <h4 style="color: #ffc107; margin-bottom: 15px;"><i class="fas fa-link"></i> Risks to be Consolidated</h4>
                            <ul style="list-style: none; padding-left: 0; max-height: 200px; overflow-y: auto;">
                                <?php foreach($merge_risks as $risk): ?>
                                    <li style="margin-bottom: 8px; font-size: 0.95rem; color: #333;">
                                        <strong><?php echo htmlspecialchars($risk['risk_id']); ?></strong> - <?php echo htmlspecialchars($risk['risk_name']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                                A new consolidated risk ID will be generated: <strong style="color: #E60012;"><?php echo htmlspecialchars($merged_risk_id); ?></strong>
                            </p>
                            <input type="hidden" name="merged_risk_id" value="<?php echo htmlspecialchars($merged_risk_id); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="section-header">
                        <i class="fas fa-search"></i> Section 1: Risk Identification
                    </div>
                    
                    <!-- Risk Categories label -->
                    <div class="form-group">
                        <label class="form-label">A. Risk Categories * <small>(Select one primary risk)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Financial Exposure" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Financial Exposure') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Financial Exposure [Revenue, Operating Expenditure, Book value]</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Decrease in market share" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Decrease in market share') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Decrease in market share</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Customer Experience" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Customer Experience') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Customer Experience</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Compliance" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Compliance') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Compliance</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Reputation" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Reputation') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Reputation</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Fraud" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Fraud') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Fraud</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Operations" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Operations') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Operations (Business continuity)</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Networks" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Networks') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Networks</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="People" <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'People') ? 'checked' : ''; ?>>
                                    <span class="checkmark">People</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="IT" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'IT') ? 'checked' : ''; ?>>
                                    <span class="checkmark">IT (Cybersecurity & Data Privacy)</span>
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="risk_categories" value="Other" required <?php echo (isset($form_data['risk_categories']) && $form_data['risk_categories'] == 'Other') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Other</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- B. Date of Occurrence section -->
                    <div class="form-group">
                        <label class="form-label">B. Date of Occurrence *</label>
                        <input type="date" name="date_of_occurrence" class="form-control" required max="<?php echo date('Y-m-d'); ?>" style="width: 100%; padding: 0.9rem; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 1rem;" value="<?php echo htmlspecialchars($form_data['date_of_occurrence'] ?? ''); ?>">
                        <small class="info-text">Select the date when the risk occurred</small>
                    </div>

                    <!-- C. Does your risk involves loss of money -->
                    <div class="form-group">
                        <label class="form-label">C. Does your risk involves loss of money? *</label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="involves_money_loss" value="yes" onchange="toggleMoneyRange(this)" required <?php echo (isset($form_data['involves_money_loss']) && $form_data['involves_money_loss'] == 'yes') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Yes</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="involves_money_loss" value="no" onchange="toggleMoneyRange(this)" required <?php echo (isset($form_data['involves_money_loss']) && $form_data['involves_money_loss'] == 'no') ? 'checked' : ''; ?>>
                                    <span class="checkmark">No</span>
                                </label>
                            </div>
                        </div>
                        <div id="money-range-section" class="conditional-section" <?php echo (isset($form_data['involves_money_loss']) && $form_data['involves_money_loss'] == 'yes') ? 'style="display: block;"' : ''; ?>>
                            <label class="form-label">Select Money Range *</label>
                            <select name="money_range" class="cause-dropdown">
                                <option value="">-- Select Range --</option>
                                <option value="0 - 100,000" <?php echo (isset($form_data['money_range']) && $form_data['money_range'] == '0 - 100,000') ? 'selected' : ''; ?>>0 - 100,000</option>
                                <option value="100,001 - 500,000" <?php echo (isset($form_data['money_range']) && $form_data['money_range'] == '100,001 - 500,000') ? 'selected' : ''; ?>>100,001 - 500,000</option>
                                <option value="500,001 - 1,000,000" <?php echo (isset($form_data['money_range']) && $form_data['money_range'] == '500,001 - 1,000,000') ? 'selected' : ''; ?>>500,001 - 1,000,000</option>
                                <option value="1,000,001 - 2,500,000" <?php echo (isset($form_data['money_range']) && $form_data['money_range'] == '1,000,001 - 2,500,000') ? 'selected' : ''; ?>>1,000,001 - 2,500,000</option>
                                <option value="2,500,001 - 5,000,000" <?php echo (isset($form_data['money_range']) && $form_data['money_range'] == '2,500,001 - 5,000,000') ? 'selected' : ''; ?>>2,500,001 - 5,000,000</option>
                                <option value="5,000,000+" <?php echo (isset($form_data['money_range']) && $form_data['money_range'] == '5,000,000+') ? 'selected' : ''; ?>>5,000,000+</option>
                            </select>
                            <small class="info-text">Select the estimated financial impact range</small>
                        </div>
                    </div>
                    
                    <!-- D. Risk Description -->
                    <div class="form-group">
                        <label class="form-label">D. Risk Description *</label>
                        <div class="styled-textarea-container">
                            <textarea name="risk_description" class="styled-textarea" required placeholder="Example: On 2025/01/15 afternoon, at the Nairobi branch office, a system outage occurred due to server failure, causing customer service disruptions for 3 hours. Approximately 500 customers were unable to access Airtel mobile money."><?php echo htmlspecialchars($form_data['risk_description'] ?? ''); ?></textarea>
                        </div>
                        <small class="info-text">Describe WHAT happened, WHERE it occurred, HOW it happened, and WHEN it took place</small>
                    </div>
                    
                    <!-- E. Cause of Risk -->
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
                        
                        <input type="hidden" name="cause_people_hidden" id="cause_people_hidden" value="<?php echo htmlspecialchars($form_data['cause_people_hidden'] ?? ''); ?>">
                        <input type="hidden" name="cause_process_hidden" id="cause_process_hidden" value="<?php echo htmlspecialchars($form_data['cause_process_hidden'] ?? ''); ?>">
                        <input type="hidden" name="cause_it_systems_hidden" id="cause_it_systems_hidden" value="<?php echo htmlspecialchars($form_data['cause_it_systems_hidden'] ?? ''); ?>">
                        <input type="hidden" name="cause_external_hidden" id="cause_external_hidden" value="<?php echo htmlspecialchars($form_data['cause_external_hidden'] ?? ''); ?>">
                    </div>

                    <!-- F. Have you reported to GLPI with conditional IR Number -->
                    <div class="form-group">
                        <label class="form-label">F. Have you reported to GLPI? *</label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="reported_to_glpi" value="yes" onchange="toggleGLPISection(this)" required <?php echo (isset($form_data['reported_to_glpi']) && $form_data['reported_to_glpi'] == 'yes') ? 'checked' : ''; ?>>
                                    <span class="checkmark">Yes</span>
                                </label>
                            </div>
                            <div class="category-item">
                                <label class="radio-category-label">
                                    <input type="radio" name="reported_to_glpi" value="no" onchange="toggleGLPISection(this)" required <?php echo (isset($form_data['reported_to_glpi']) && $form_data['reported_to_glpi'] == 'no') ? 'checked' : ''; ?>>
                                    <span class="checkmark">No</span>
                                </label>
                            </div>
                        </div>
                        <div id="glpi-ir-section" class="conditional-section" <?php echo (isset($form_data['reported_to_glpi']) && $form_data['reported_to_glpi'] == 'yes') ? 'style="display: block;"' : ''; ?>>
                            <label class="form-label">Provide IR Number *</label>
                            <input type="text" name="glpi_ir_number" class="form-control" placeholder="Example: 1234567" style="width: 100%; padding: 0.9rem; border: 2px solid #e1e5e9; border-radius: 8px; font-size: 1rem;" value="<?php echo htmlspecialchars($form_data['glpi_ir_number'] ?? ''); ?>">
                            <small class="info-text">Enter the Ticket number from the raised incident in GLPI system.</small>
                        </div>
                    </div>

                    <!-- G. Supporting Documents with drag-and-drop functionality -->
                    <div class="form-group">
                        <label class="form-label">G. Supporting Documents * (Mandatory - Multiple files allowed)</label>
                        <div class="styled-file-container">
                            <div class="styled-file-upload">
                                <input type="file" name="supporting_document[]" class="styled-file-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.xlsx,.xls,.msg,.eml" required id="fileInput" multiple>
                                <label class="styled-file-label" for="fileInput" id="dropZone">
                                    <div class="file-upload-icon">📁</div>
                                    <div class="file-upload-text">Drag & Drop files here</div>
                                    <div class="file-upload-subtext">or click to browse</div>
                                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-top: 10px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                </label>
                            </div>
                            <div class="selected-files-list" id="selectedFilesList">
                                <div class="selected-files-title">Selected Files:</div>
                                <div id="filesContainer"></div>
                            </div>
                            <small class="form-text text-muted" style="display: block; margin-top: 10px; color: #666; text-align: center;">
                                <strong>Required:</strong> Please provide screenshots, images, PDFs, Excel sheets, email trails, or other relevant documentation<br>
                                Accepted formats: PDF, DOC, DOCX, JPG, PNG, XLSX, XLS, MSG, EML (Max size: 10MB per file)
                            </small>
                        </div>
                    </div>  
                    
                    <div class="section-header">
                        <i class="fas fa-calculator"></i> Section 2: Risk Assessment
                    </div>
                    
                    <!-- Existing/New Risk Detection -->
                    <div class="form-group">
                        <label class="form-label">a. Existing or New Risk *</label>
                        
                        <?php if ($is_merge_mode): ?>
                             <!-- In merge mode, still show the automated check interface but with context -->
                            <div style="margin-bottom: 20px; padding: 20px; background: #e3f2fd; border-left: 4px solid #2196F3; border-radius: 8px;">
                                <h4 style="color: #2196F3; margin-bottom: 15px;">
                                    <i class="fas fa-info-circle"></i> Consolidating Existing Risks
                                </h4>
                                <p style="margin: 0; color: #1976D2; font-weight: 600;">
                                    The system will check if other risks with the same category exist (excluding the risks being merged).
                                </p>
                            </div>
                            
                             <!-- Placeholder state -->
                            <div id="risk-detection-placeholder" style="padding: 20px; background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; text-align: center; color: #6c757d;">
                                <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p style="margin: 0; font-weight: 500;">Please select a primary risk category in Section 1 to check for existing risks</p>
                            </div>
                            
                             <!-- Loading state -->
                            <div id="risk-detection-loading" style="display: none; padding: 20px; background: #e3f2fd; border: 2px solid #2196F3; border-radius: 8px; text-align: center;">
                                <div style="display: inline-block; width: 20px; height: 20px; border: 3px solid #2196F3; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                                <p style="margin: 10px 0 0 0; color: #1976D2; font-weight: 600;">Checking for existing risk in database...</p>
                            </div>
                            
                             <!-- Result state - NEW RISK -->
                            <div id="risk-detection-new" style="display: none; padding: 20px; background: #d4edda; border: 2px solid #28a745; border-radius: 8px; text-align: center;">
                                <i class="fas fa-check-circle" style="font-size: 2rem; color: #28a745; margin-bottom: 10px;"></i>
                                <h4 style="margin: 0; color: #155724; font-weight: 700;">NEW RISK</h4>
                                <p style="margin: 5px 0 0 0; color: #155724;">No other related risks found with this category (excluding consolidated risks)</p>
                            </div>
                            
                             <!-- Result state - EXISTING RISK -->
                            <div id="risk-detection-existing" style="display: none; padding: 20px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; text-align: center;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #856404; margin-bottom: 10px;"></i>
                                <h4 style="margin: 0; color: #856404; font-weight: 700;">EXISTING RISK</h4>
                                <p style="margin: 5px 0 15px 0; color: #856404;"><span id="existing-risk-count">0</span> other related risk(s) found in the database</p>
                                <button type="button" onclick="openRiskChainModal()" style="background: #ffc107; color: #000; border: none; padding: 10px 20px; border-radius: 5px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                                    <i class="fas fa-link"></i> VIEW CHAIN
                                </button>
                            </div>
                            
                             <!-- Error state -->
                            <div id="risk-detection-error" style="display: none; padding: 20px; background: #f8d7da; border: 2px solid #dc3545; border-radius: 8px; text-align: center;">
                                <i class="fas fa-times-circle" style="font-size: 2rem; color: #721c24; margin-bottom: 10px;"></i>
                                <h4 style="margin: 0; color: #721c24; font-weight: 700;">Unable to check</h4>
                                <p style="margin: 5px 0 0 0; color: #721c24;">Please contact IT admin for assistance</p>
                            </div>
                            
                             <!-- Hidden input to store the result for form submission -->
                            <input type="hidden" name="existing_or_new" id="existing_or_new_value" required>
                        <?php else: ?>
                              <!-- Normal mode - show the automated check interface -->
                             <!-- Placeholder state -->
                            <div id="risk-detection-placeholder" style="padding: 20px; background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; text-align: center; color: #6c757d;">
                                <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                <p style="margin: 0; font-weight: 500;">Please select a primary risk category in Section 1 to check for existing risks</p>
                            </div>
                            
                             <!-- Loading state -->
                            <div id="risk-detection-loading" style="display: none; padding: 20px; background: #e3f2fd; border: 2px solid #2196F3; border-radius: 8px; text-align: center;">
                                <div style="display: inline-block; width: 20px; height: 20px; border: 3px solid #2196F3; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                                <p style="margin: 10px 0 0 0; color: #1976D2; font-weight: 600;">Checking for existing risk in database...</p>
                            </div>
                            
                             <!-- Result state - NEW RISK -->
                            <div id="risk-detection-new" style="display: none; padding: 20px; background: #d4edda; border: 2px solid #28a745; border-radius: 8px; text-align: center;">
                                <i class="fas fa-check-circle" style="font-size: 2rem; color: #28a745; margin-bottom: 10px;"></i>
                                <h4 style="margin: 0; color: #155724; font-weight: 700;">NEW RISK</h4>
                                <p style="margin: 5px 0 0 0; color: #155724;">No existing risks found with this category</p>
                            </div>
                            
                             <!-- Result state - EXISTING RISK -->
                            <div id="risk-detection-existing" style="display: none; padding: 20px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; text-align: center;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #856404; margin-bottom: 10px;"></i>
                                <h4 style="margin: 0; color: #856404; font-weight: 700;">EXISTING RISK</h4>
                                <p style="margin: 5px 0 15px 0; color: #856404;"><span id="existing-risk-count">0</span> related risk(s) found in the database</p>
                                <button type="button" onclick="openRiskChainModal()" style="background: #ffc107; color: #000; border: none; padding: 10px 20px; border-radius: 5px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                                    <i class="fas fa-link"></i> VIEW CHAIN
                                </button>
                            </div>
                            
                             <!-- Error state -->
                            <div id="risk-detection-error" style="display: none; padding: 20px; background: #f8d7da; border: 2px solid #dc3545; border-radius: 8px; text-align: center;">
                                <i class="fas fa-times-circle" style="font-size: 2rem; color: #721c24; margin-bottom: 10px;"></i>
                                <h4 style="margin: 0; color: #721c24; font-weight: 700;">Unable to check</h4>
                                <p style="margin: 5px 0 0 0; color: #721c24;">Please contact IT admin for assistance</p>
                            </div>
                            
                             <!-- Hidden input to store the result for form submission -->
                            <input type="hidden" name="existing_or_new" id="existing_or_new_value" required>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">b. Risk Calculation *</label>
                        <small class="info-text" style="display: block; margin-bottom: 15px;">Based on the selected primary risk category, specify the impact details below.</small>
                             <!-- Primary Risk Category Display -->
                            <div id="primary-risk-category-display" style="display: none; margin-bottom: 20px; padding: 15px; background: #e8f5e9; border-left: 4px solid #28a745; border-radius: 4px;">
                                <div style="color: #28a745; font-weight: 600; font-size: 16px;">
                                    <i class="fas fa-tag"></i> Primary Risk: <span id="primary-risk-name"></span>
                                </div>
                            </div>
                        <!-- Inherent Risk Section -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #28a745; border-radius: 8px;">
                            <h5 style="color: #28a745; margin-bottom: 15px; font-weight: 600;">Inherent Risk</h5>
                            
                            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 300px;">
                                    <div class="form-group">
                                        <label class="form-label">Likelihood *</label>
                                        <div class="likelihood-boxes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 4)"
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
                                                 onclick="selectInherentLikelihood(this, 3)"
                                                 data-value="3">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">LIKELY</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    A history of happening at certain intervals/seasons/events
                                                </div>
                                            </div>
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 2)"
                                                 data-value="2">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">POSSIBLE</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    1. More than 1 year from last occurrence<br>
                                                    2. Circumstances indicating or allowing possibility of happening
                                                </div>
                                            </div>
                                            
                                            <div class="likelihood-box" 
                                                 style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentLikelihood(this, 1)"
                                                 data-value="1">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">UNLIKELY</div>
                                                <div style="font-size: 12px; line-height: 1.3;">
                                                    1. Not occurred before<br>
                                                    2. This is the first time its happening<br>
                                                    3. Not expected to happen for sometime
                                                </div>
                                            </div>
                                            
                                        </div>
                                        <input type="hidden" name="inherent_likelihood_level" id="inherent_likelihood_value" required value="<?php echo htmlspecialchars($form_data['inherent_likelihood_level'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div style="flex: 1; min-width: 300px;">
                                    <div class="form-group">
                                        <label class="form-label">Impact *</label>
                                        
                                         <!--  impact boxes based on selected risk category -->
                                        <div class="impact-boxes" id="inherent_impact_boxes_container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                                            
                                            <div class="impact-box" id="inherent_extreme_box"
                                                 style="background-color: #ff4444; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 4)"
                                                 data-value="4">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div>
                                                <div id="inherent_extreme_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                            <div class="impact-box" id="inherent_significant_box"
                                                 style="background-color: #ff8800; color: white; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 3)"
                                                 data-value="3">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div>
                                                <div id="inherent_significant_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                            <div class="impact-box" id="inherent_moderate_box"
                                                 style="background-color: #ffdd00; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 2)"
                                                 data-value="2">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div>
                                                <div id="inherent_moderate_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                            <div class="impact-box" id="inherent_minor_box"
                                                 style="background-color: #88dd88; color: black; padding: 15px; border-radius: 8px; cursor: pointer; border: 3px solid transparent; text-align: center; font-weight: bold;"
                                                 onclick="selectInherentImpact(this, 1)"
                                                 data-value="1">
                                                <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div>
                                                <div id="inherent_minor_text" style="font-size: 12px; line-height: 1.3;"></div>
                                            </div>
                                            
                                        </div>
                                        <input type="hidden" name="inherent_impact_level" id="inherent_impact_value" required value="<?php echo htmlspecialchars($form_data['inherent_impact_level'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <input type="hidden" name="inherent_risk_rating" id="inherent_risk_rating" value="<?php echo htmlspecialchars($form_data['inherent_risk_rating'] ?? ''); ?>">
                            <div id="inherent_risk_display" class="rating-display" style="margin-top: 15px;">Inherent Risk Rating will appear here</div>
                        </div>
                        
                        <!-- Residual Risk Section -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #ffc107; border-radius: 8px;">
                            <h5 style="color: #ffc107; margin-bottom: 15px; font-weight: 600;">Residual Risk</h5>
                            <input type="hidden" name="residual_risk_rating" id="residual_risk_rating" value="<?php echo htmlspecialchars($form_data['residual_risk_rating'] ?? ''); ?>">
                            <div id="residual_risk_display" class="rating-display" style="margin-top: 15px;">Residual Risk Rating will appear here</div>
                        </div>
                        
                        <!-- Secondary risk section with unified system -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #6f42c1; border-radius: 8px;">
                            <h5 style="color: #6f42c1; margin-bottom: 15px; font-weight: 600;">Additional Risk Assessment</h5>
                            
                            <div id="secondary-risks-container"></div>
                            
                            <!-- Initial question for first secondary risk -->
                            <div id="initial-secondary-question" class="next-risk-question">
                                <label style="font-weight: 600; color: #333; margin-bottom: 10px; display: block;">Is there any other triggered risk?</label>
                                <div class="next-risk-options">
                                    <label class="next-risk-option">
                                        <input type="radio" name="has_secondary_risk" value="yes" onclick="showSecondaryRiskSelection()" <?php echo (isset($form_data['has_secondary_risk']) && $form_data['has_secondary_risk'] == 'yes') ? 'checked' : ''; ?>>
                                        <span style="font-weight: 500;">Yes</span>
                                    </label>
                                    <label class="next-risk-option">
                                        <input type="radio" name="has_secondary_risk" value="no" onclick="hideSecondaryRiskSelection()" <?php echo (isset($form_data['has_secondary_risk']) && $form_data['has_secondary_risk'] == 'no') ? 'checked' : ''; ?>>
                                        <span style="font-weight: 500;">No</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Secondary risk selection (initially hidden) -->
                            <div id="secondary-risk-selection" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                                <div class="form-group">
                                    <label class="form-label">Risk Categories * <small>(Select one secondary risk)</small></label>
                                    <div class="risk-categories-container" id="secondary-risk-categories">
                                        <!-- Categories will be populated dynamically -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 2c: AGGREGATE RISK SCORE -->
                    <div class="form-group">
                        <label class="form-label">c. AGGREGATE RISK SCORE</label>
                        
                        <!-- Aggregate Inherent Risk Score Display -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #17a2b8; border-radius: 8px;">
                            <h5 style="color: #17a2b8; margin-bottom: 15px; font-weight: 600;">Aggregate Inherent Risk Score</h5>
                            <div id="general_inherent_risk_display" class="rating-display" style="margin-top: 15px; font-size: 16px; font-weight: 600; padding: 15px; background: #f8f9fa; border-radius: 4px; text-align: center;">
                                Aggregate Inherent Risk Score will appear here
                            </div>
                            <input type="hidden" name="general_inherent_risk_score" id="general_inherent_risk_score" value="<?php echo htmlspecialchars($form_data['general_inherent_risk_score'] ?? ''); ?>">
                        </div>
                        
                        <!-- Aggregate Residual Risk Score Display -->
                        <div style="margin: 20px 0; padding: 20px; background: #fff; border: 2px solid #dc3545; border-radius: 8px;">
                            <h5 style="color: #dc3545; margin-bottom: 15px; font-weight: 600;">Aggregate Residual Risk Score</h5>
                            <div id="general_residual_risk_display" class="rating-display" style="margin-top: 15px; font-size: 16px; font-weight: 600; padding: 15px; background: #f8f9fa; border-radius: 4px; text-align: center;">
                                Aggregate Residual Risk Score will appear here
                            </div>
                            <input type="hidden" name="general_residual_risk_score" id="general_residual_risk_score" value="<?php echo htmlspecialchars($form_data['general_residual_risk_score'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Section 3, General Risk Status -->
                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-chart-line"></i> SECTION 3: GENERAL RISK STATUS
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Overall Risk Status *</label>
                            <select name="general_risk_status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="Open" <?php echo (isset($form_data['general_risk_status']) && $form_data['general_risk_status'] == 'Open') ? 'selected' : ''; ?>>Open</option>
                                <option value="In Progress" <?php echo (isset($form_data['general_risk_status']) && $form_data['general_risk_status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Closed" <?php echo (isset($form_data['general_risk_status']) && $form_data['general_risk_status'] == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                <option value="On Hold" <?php echo (isset($form_data['general_risk_status']) && $form_data['general_risk_status'] == 'On Hold') ? 'selected' : ''; ?>>On Hold</option>
                            </select>
                        </div>
                    </div>

                    <!-- Form action buttons -->
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="clearEntireForm()">Cancel</button>
                        <button type="submit" name="submit_risk_report" class="btn">
                            <i class="fas fa-save"></i> Submit Risk Report
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <!-- Display submitted risk data -->
                <div class="section-header">
                    <i class="fas fa-check-circle"></i> Submitted Risk Report
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
                    <h4 style="color: #28a745; margin-bottom: 15px;">Risk Details</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div><strong>Risk Name:</strong> <?php echo htmlspecialchars($submitted_risk['risk_name']); ?></div>
                        <div><strong>Department:</strong> <?php echo htmlspecialchars($submitted_risk['department']); ?></div>
                        <div><strong>Risk Category:</strong> <?php echo htmlspecialchars(json_decode($submitted_risk['risk_categories'])[0] ?? ''); ?></div>
                        <div><strong>Risk Level:</strong> <?php echo htmlspecialchars($submitted_risk['general_inherent_risk_level']); ?></div>
                    </div>
                    <div style="margin-top: 15px;">
                        <strong>Description:</strong><br>
                        <?php echo nl2br(htmlspecialchars($submitted_risk['risk_description'])); ?>
                    </div>
                </div>
            <?php endif; ?>
    </div>
</div>

    <!-- Cause Modal HTML structure -->
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

     <!-- Removed Add Treatment Modal completely -->

     <!-- Removed Update Treatment Modal completely -->

     <!-- Risk Chain Modal -->
    <div id="riskChainModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #ffc107, #ff9800);">
                <h3 class="modal-title"><i class="fas fa-link"></i> Risk Chain</h3>
                <button type="button" class="close" onclick="closeRiskChainModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: #666; font-size: 0.95rem;">
                    The following risks share the same primary risk category and are related to this incident:
                </p>
                <div id="riskChainList" style="max-height: 400px; overflow-y: auto;">
                     <!-- Risk chain items will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Added CSS for spinning animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Added CSS for risk chain modal items */
        .risk-chain-item {
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .risk-chain-item:hover {
            background: #fff3cd;
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
        }
        
        .risk-chain-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .risk-chain-id {
            font-weight: 700;
            color: #856404;
            font-size: 1.1rem;
        }
        
        .risk-chain-date {
            font-size: 0.85rem;
            color: #666;
            font-weight: 500;
        }
        
        .risk-chain-name {
            color: #333;
            font-size: 0.95rem;
            line-height: 1.4;
        }
    </style>

    <script>
        // User search data (assuming riskOwners is populated from PHP)
        const riskOwners = <?php echo json_encode($risk_owners); ?>;
        
        // Initialize selectedFiles array for file uploads
        let selectedFiles = [];
        // Initialize window.selectedRisks for secondary risk selection
        window.selectedRisks = [];
        // Initialize window.currentSecondaryRiskIndex for tracking secondary risks
        window.currentSecondaryRiskIndex = 0;
        // Initialize allRiskCategories globally
        const allRiskCategories = <?php echo json_encode([
            ['value' => 'Financial Exposure', 'display' => 'Financial Exposure [Revenue, Operating Expenditure, Book value]'],
            ['value' => 'Decrease in market share', 'display' => 'Decrease in market share'],
            ['value' => 'Customer Experience', 'display' => 'Customer Experience'],
            ['value' => 'Compliance', 'display' => 'Compliance'],
            ['value' => 'Reputation', 'display' => 'Reputation'],
            ['value' => 'Fraud', 'display' => 'Fraud'],
            ['value' => 'Operations', 'display' => 'Operations (Business continuity)'],
            ['value' => 'Networks', 'display' => 'Networks'],
            ['value' => 'People', 'display' => 'People'],
            ['value' => 'IT', 'display' => 'IT (Cybersecurity & Data Privacy)'],
            ['value' => 'Other', 'display' => 'Other']
        ]); ?>;

        // causeData object and cause modal functions
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

        let activeCategory = '';
        let currentCategory = '';
        let selections = [];

        function openCauseModal(category) {
            if (activeCategory !== category) {
                const categories = ['people', 'process', 'itSystems', 'external'];
                categories.forEach(cat => {
                    if (cat !== category) {
                        const hiddenId = 'cause_' + (cat === 'itSystems' ? 'it_systems' : cat) + '_hidden';
                        const hiddenInput = document.getElementById(hiddenId);
                        if (hiddenInput) {
                            hiddenInput.value = '[]';
                        }
                        
                        const cardElement = document.getElementById(cat + 'Card');
                        const countElement = document.getElementById(cat + 'Count');
                        if (cardElement) {
                            cardElement.classList.remove('has-selections');
                        }
                        if (countElement) {
                            countElement.textContent = '0 selected';
                        }
                    }
                });
            }
            
            activeCategory = category;
            currentCategory = category;
            
            const hiddenInputId = 'cause_' + (category === 'itSystems' ? 'it_systems' : category) + '_hidden';
            const hiddenInput = document.getElementById(hiddenInputId);
            
            if (hiddenInput && hiddenInput.value) {
                try {
                    selections = JSON.parse(hiddenInput.value);
                } catch (e) {
                    selections = [];
                }
            } else {
                selections = [];
            }
            
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
            
            const modal = document.getElementById('causeModal');
            modal.style.display = 'flex';
            modal.classList.add('show');
        }

        function closeCauseModal() {
            document.getElementById('causeModal').classList.remove('show');
            document.getElementById('causeModal').style.display = 'none';
        }

        function saveCauseSelections() {
            const checkboxes = document.querySelectorAll('#causeModalBody input[type="checkbox"]:checked');
            selections = Array.from(checkboxes).map(cb => cb.value);
            
            updateHiddenInputs();
            updateCauseCard(currentCategory);
            updateSelectionSummary();
            closeCauseModal();
        }

        function updateCauseCard(category) {
            const hiddenInputId = 'cause_' + (category === 'itSystems' ? 'it_systems' : category) + '_hidden';
            const hiddenInput = document.getElementById(hiddenInputId);
            
            if (!hiddenInput) return;
            
            const storedSelections = JSON.parse(hiddenInput.value || '[]');
            const count = storedSelections.length;
            
            const cardElement = document.getElementById(category + 'Card');
            const countElement = document.getElementById(category + 'Count');
            
            if (countElement) {
                countElement.textContent = count + ' selected';
            }
            
            if (cardElement) {
                if (count > 0) {
                    cardElement.classList.add('has-selections');
                } else {
                    cardElement.classList.remove('has-selections');
                }
            }
        }

        function updateHiddenInputs() {
            document.getElementById('cause_people_hidden').value = activeCategory === 'people' ? JSON.stringify(selections) : (document.getElementById('cause_people_hidden').value || '[]');
            document.getElementById('cause_process_hidden').value = activeCategory === 'process' ? JSON.stringify(selections) : (document.getElementById('cause_process_hidden').value || '[]');
            document.getElementById('cause_it_systems_hidden').value = activeCategory === 'itSystems' ? JSON.stringify(selections) : (document.getElementById('cause_it_systems_hidden').value || '[]');
            document.getElementById('cause_external_hidden').value = activeCategory === 'external' ? JSON.stringify(selections) : (document.getElementById('cause_external_hidden').value || '[]');
        }

        function updateSelectionSummary() {
            const summaryDiv = document.getElementById('causeSelectionSummary');
            const summaryContent = document.getElementById('summaryContent');
            
            let hasAnySelection = false;
            let summaryHTML = '';

            for (const category in causeData) {
                const hiddenInputId = 'cause_' + (category === 'itSystems' ? 'it_systems' : category) + '_hidden';
                const hiddenInput = document.getElementById(hiddenInputId);
                
                if (hiddenInput && hiddenInput.value) {
                    const storedSelections = JSON.parse(hiddenInput.value || '[]');
                    if (storedSelections.length > 0) {
                        hasAnySelection = true;
                        const categoryInfo = causeData[category];
                        summaryHTML += `<div style="margin-bottom: 10px;">
                            <strong>${categoryInfo.icon} ${categoryInfo.title}:</strong><br>
                            <span style="margin-left: 20px; color: #666;">${storedSelections.join(', ')}</span>
                        </div>`;
                    }
                }
            }
            
            if (hasAnySelection) {
                summaryContent.innerHTML = summaryHTML;
                summaryDiv.style.display = 'block';
            } else {
                summaryDiv.style.display = 'none';
            }
        }

        function toggleMoneyRange(radio) {
            const moneyRangeSection = document.getElementById('money-range-section');
            const moneyRangeSelect = document.querySelector('select[name="money_range"]');
            
            if (radio && radio.value === 'yes') {
                moneyRangeSection.classList.add('show');
                moneyRangeSelect.required = true;
            } else {
                moneyRangeSection.classList.remove('show');
                moneyRangeSelect.required = false;
                if(moneyRangeSelect) moneyRangeSelect.value = '';
            }
        }

        function toggleGLPISection(radio) {
            const glpiSection = document.getElementById('glpi-ir-section');
            const glpiInput = document.querySelector('input[name="glpi_ir_number"]');
            
            if (radio && radio.value === 'yes') {
                glpiSection.classList.add('show');
                glpiInput.required = true;
            } else {
                glpiSection.classList.remove('show');
                glpiInput.required = false;
                if(glpiInput) glpiInput.value = '';
            }
        }

        // File upload with drag and drop functionality
        // let selectedFiles = []; // moved to global scope

        function updateFileInput() {
            const fileInput = document.getElementById('fileInput');
            const dt = new DataTransfer();
            selectedFiles.forEach(file => {
                dt.items.add(file);
            });
            fileInput.files = dt.files;
        }

        function displayFileName() {
            const filesList = document.getElementById('selectedFilesList');
            const filesContainer = document.getElementById('filesContainer');
            
            if (selectedFiles.length > 0) {
                filesContainer.innerHTML = '';
                
                selectedFiles.forEach((file, index) => {
                    const fileSize = formatFileSize(file.size);
                    const fileItem = document.createElement('div');
                    fileItem.className = 'selected-file-item';
                    fileItem.innerHTML = `
                        <span class="selected-file-name">${file.name}</span>
                        <span class="selected-file-size">${fileSize}</span>
                        <button type="button" class="remove-file-btn" onclick="removeFile(${index})">Remove</button>
                    `;
                    filesContainer.appendChild(fileItem);
                });
                
                filesList.classList.add('show');
            } else {
                filesList.classList.remove('show');
            }
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            updateFileInput();
            displayFileName();
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        // Helper function to check if file already exists
        function fileExists(file) {
            return selectedFiles.some(existingFile => 
                existingFile.name === file.name && 
                existingFile.size === file.size &&
                existingFile.lastModified === file.lastModified
            );
        }

        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, unhighlight, false);
        });

        function highlight(e) {
            dropZone.classList.add('drag-over');
        }

        function unhighlight(e) {
            dropZone.classList.remove('drag-over');
        }

        dropZone.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const newFiles = dt.files;
            
            for (let i = 0; i < newFiles.length; i++) {
                // Check for duplicates before adding
                if (!fileExists(newFiles[i])) {
                    selectedFiles.push(newFiles[i]);
                }
            }
            
            updateFileInput();
            displayFileName();
        }

        fileInput.addEventListener('change', function() {
            const newFiles = this.files;
            
            for (let i = 0; i < newFiles.length; i++) {
                // Check for duplicates before adding
                if (!fileExists(newFiles[i])) {
                    selectedFiles.push(newFiles[i]);
                }
            }
            
            // Update the file input with all selected files
            updateFileInput();
            displayFileName();
        });

        // Close cause modal when clicking outside
        window.addEventListener('click', function(event) {
            const causeModal = document.getElementById('causeModal');
            if (event.target === causeModal) {
                closeCauseModal();
            }
            
            const riskChainModal = document.getElementById('riskChainModal');
            if (event.target === riskChainModal) {
                closeRiskChainModal();
            }
        });

        // Initialize cause cards on page load if there are saved selections
        document.addEventListener('DOMContentLoaded', function() {
            // Restore cause selections if they exist
            for (const category in causeData) {
                updateCauseCard(category);
            }
            updateSelectionSummary();
        });

        document.addEventListener('DOMContentLoaded', function() {
            console.log('Risk form JavaScript loaded');
            
            // Restore form data from session if available
            <?php if (!empty($form_data)): ?>
                console.log('Restoring form data from session...');
                const formData = <?php echo json_encode($form_data); ?>;

                for (const key in formData) {
                    const element = document.querySelector(`[name="${key}"]`);
                    if (element) {
                        if (element.type === 'radio') {
                            if (element.value == formData[key]) {
                                element.checked = true;
                            }
                        } else if (element.type === 'checkbox') {
                            // Handle checkboxes if needed
                        } else {
                            element.value = formData[key];
                        }
                    }
                }
                
                // Restore conditional sections visibility
                toggleMoneyRange(document.querySelector('input[name="involves_money_loss"]:checked'));
                toggleGLPISection(document.querySelector('input[name="reported_to_glpi"]:checked'));

                // Restore cause selections
                for (const category in causeData) {
                    updateCauseCard(category);
                }
                updateSelectionSummary();
                
                // Restore risk category selection and update impact boxes
                const selectedCategoryValue = formData.risk_categories;
                if (selectedCategoryValue) {
                    const categoryRadio = document.querySelector(`input[name="risk_categories"][value="${selectedCategoryValue}"]`);
                    if (categoryRadio) {
                        categoryRadio.checked = true;
                        updateRiskCategoryDisplay(selectedCategoryValue);
                        updateImpactBoxes(selectedCategoryValue);
                        updateSecondaryRiskCategories(); // Update available categories for secondary risks
                        checkForExistingRisks(selectedCategoryValue); // Check for existing risks in database
                    }
                }
                
                // Restore inherent likelihood and impact selections
                const inherentLikelihoodValue = formData.inherent_likelihood_level;
                if (inherentLikelihoodValue) {
                    const likelihoodBox = document.querySelector(`.likelihood-box[data-value="${inherentLikelihoodValue}"]`);
                    if (likelihoodBox) {
                        selectInherentLikelihood(likelihoodBox, inherentLikelihoodValue);
                    }
                }
                const inherentImpactValue = formData.inherent_impact_level;
                if (inherentImpactValue) {
                    const impactBox = document.querySelector(`.impact-box[data-value="${inherentImpactValue}"]`);
                    if (impactBox) {
                        selectInherentImpact(impactBox, inherentImpactValue);
                    }
                }

                // Restore secondary risk selections
                const hasSecondaryRisk = formData.has_secondary_risk;
                if (hasSecondaryRisk === 'yes') {
                    showSecondaryRiskSelection();
                    const secondaryRiskContainer = document.getElementById('secondary-risks-container');
                    const secondaryRiskCategoriesDiv = document.getElementById('secondary-risk-categories');
                    
                    // Re-populate available categories first
                    const initialCategoryRadio = document.querySelector('input[name="risk_categories"]:checked');
                    if (initialCategoryRadio) {
                        window.selectedRisks = [initialCategoryRadio.value];
                    } else {
                        window.selectedRisks = [];
                    }
                    updateSecondaryRiskCategories();

                    let secondaryRiskIndex = 1;
                    while (formData[`secondary_risk_category_${secondaryRiskIndex}`]) {
                        const secCategory = formData[`secondary_risk_category_${secondaryRiskIndex}`];
                        const secLikelihood = formData[`secondary_likelihood_${secondaryRiskIndex}`];
                        const secImpact = formData[`secondary_impact_${secondaryRiskIndex}`];
                        
                        window.selectedRisks.push(secCategory); // Mark as selected

                        // Create the UI for the secondary risk
                        createSecondaryRiskAssessment(secCategory, secCategory, secondaryRiskIndex);
                        
                        // Select the radio button for the secondary risk category
                        const secCategoryRadio = document.querySelector(`#secondary-risk-categories input[name="current_secondary_risk"][value="${secCategory}"]`);
                        if (secCategoryRadio) {
                            secCategoryRadio.checked = true;
                        }

                        // Select likelihood and impact values
                        const secLikelihoodBox = document.querySelector(`#secondary-risk-${secondaryRiskIndex} .secondary-likelihood-box[data-value="${secLikelihood}"]`);
                        if (secLikelihoodBox) {
                            selectSecondaryLikelihood(secondaryRiskIndex, secLikelihoodBox, secLikelihood);
                        }
                        const secImpactBox = document.querySelector(`#secondary-risk-${secondaryRiskIndex} .secondary-impact-box[data-value="${secImpact}"]`);
                        if (secImpactBox) {
                            selectSecondaryImpact(secondaryRiskIndex, secImpactBox, secImpact);
                        }
                        
                        calculateSecondaryRiskRating(secondaryRiskIndex);
                        secondaryRiskIndex++;
                    }
                    window.currentSecondaryRiskIndex = secondaryRiskIndex - 1;
                }
                
                // Re-calculate general risk scores after all data is restored
                calculateGeneralRiskScores();
            <?php endif; ?>
        });


        // User search functionality
        const userSearchInput = document.getElementById('user_search');
        const userDropdown = document.getElementById('user_dropdown');
        const assignedToInput = document.getElementById('assigned_to');
        const selectedUserDisplay = document.getElementById('selected_user_display');

        if (userSearchInput) {
            userSearchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                if (searchTerm.length < 2) {
                    userDropdown.classList.remove('show');
                    return;
                }
                const filteredUsers = riskOwners.filter(user =>
                    user.full_name.toLowerCase().includes(searchTerm) ||
                    user.department.toLowerCase().includes(searchTerm)
                );
                if (filteredUsers.length > 0) {
                    userDropdown.innerHTML = filteredUsers.map(user => `
                        <div class="user-option" onclick="selectUser(${user.id}, '${user.full_name}', '${user.department}')">
                            <div class="user-name">${user.full_name}</div>
                            <div class="user-dept">${user.department}</div>
                        </div>
                    `).join('');
                    userDropdown.classList.add('show');
                } else {
                    userDropdown.innerHTML = '<div class="user-option">No users found</div>';
                    userDropdown.classList.add('show');
                }
            });
            
            userSearchInput.addEventListener('blur', function() {
                setTimeout(() => {
                    userDropdown.classList.remove('show');
                }, 200);
            });
        }

        function selectUser(id, name, department) {
            assignedToInput.value = id;
            selectedUserDisplay.textContent = `${name} (${department})`;
            userSearchInput.value = ''; // Clear search input
            userDropdown.classList.remove('show');
        }

        // Event listeners to primary risk category radio buttons
        const riskCategoryRadios = document.querySelectorAll('input[name="risk_categories"]');
        riskCategoryRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.checked) {
                    window.selectedRisks = [this.value];
                    updateRiskCategoryDisplay(this.value);
                    updateImpactBoxes(this.value);
                    updateSecondaryRiskCategories(); // Update available categories for secondary risks
                    checkForExistingRisks(this.value); // Check for existing risks in database
                }
            });
        });

        // Global variable to store fetched existing risks
        let existingRisksData = []; 

        function checkForExistingRisks(primaryRiskCategory) {
            // Show loading state
            document.getElementById('risk-detection-placeholder').style.display = 'none';
            document.getElementById('risk-detection-loading').style.display = 'block';
            document.getElementById('risk-detection-new').style.display = 'none';
            document.getElementById('risk-detection-existing').style.display = 'none';
            document.getElementById('risk-detection-error').style.display = 'none';
            
            // Make AJAX call
            fetch(`?action=check_existing_risks&primary_risk=${encodeURIComponent(primaryRiskCategory)}`)
                .then(response => response.json())
                .then(data => {
                    // Hide loading
                    document.getElementById('risk-detection-loading').style.display = 'none';
                    
                    if (data.success) {
                        existingRisksData = data.risks;
                        
                        if (data.is_new) {
                            // Show NEW RISK
                            document.getElementById('risk-detection-new').style.display = 'block';
                            document.getElementById('existing_or_new_value').value = 'NEW';
                        } else {
                            // Show EXISTING RISK
                            document.getElementById('risk-detection-existing').style.display = 'block';
                            document.getElementById('existing-risk-count').textContent = data.count;
                            document.getElementById('existing_or_new_value').value = 'EXISTING';
                        }
                    } else {
                        // Show error
                        document.getElementById('risk-detection-error').style.display = 'block';
                        document.getElementById('existing_or_new_value').value = '';
                    }
                })
                .catch(error => {
                    console.error('Error checking existing risks:', error);
                    // Hide loading and show error
                    document.getElementById('risk-detection-loading').style.display = 'none';
                    document.getElementById('risk-detection-error').style.display = 'block';
                    document.getElementById('existing_or_new_value').value = '';
                });
        }

        function openRiskChainModal() {
            const modal = document.getElementById('riskChainModal');
            const riskChainList = document.getElementById('riskChainList');
            
            // Populate the list
            if (existingRisksData.length > 0) {
                riskChainList.innerHTML = existingRisksData.map(risk => `
                    <div class="risk-chain-item">
                        <div class="risk-chain-item-header">
                            <span class="risk-chain-id">${risk.risk_id}</span>
                            <span class="risk-chain-date">${risk.date_reported}</span>
                        </div>
                        <div class="risk-chain-name">${risk.risk_name}</div>
                    </div>
                `).join('');
            } else {
                riskChainList.innerHTML = '<p style="text-align: center; color: #666;">No related risks found.</p>';
            }
            
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeRiskChainModal() {
            const modal = document.getElementById('riskChainModal');
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Close risk chain modal when clicking outside
        window.addEventListener('click', function(event) {
            const riskChainModal = document.getElementById('riskChainModal');
            if (event.target === riskChainModal) {
                closeRiskChainModal();
            }
        });


        function selectInherentLikelihood(element, value) {
            document.querySelectorAll('.likelihood-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById('inherent_likelihood_value').value = value;
            calculateInherentRiskRating();
        }

        function selectInherentImpact(element, value) {
            document.querySelectorAll('.impact-box').forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById('inherent_impact_value').value = value;
            calculateInherentRiskRating();
        }

        function calculateInherentRiskRating() {
            var likelihood = document.getElementById('inherent_likelihood_value').value;
            var impact = document.getElementById('inherent_impact_value').value;
            if (likelihood && impact) {
                var inherentRating = likelihood * impact;
                document.getElementById('inherent_risk_rating').value = inherentRating;
                displayInherentRiskRating(inherentRating);
                
                // Calculate residual risk
                var controlAssessment = getControlAssessmentValue();
                var residualRating = inherentRating * controlAssessment;
                document.getElementById('residual_risk_rating').value = residualRating;
                displayResidualRiskRating(residualRating);
                updateRiskSummary(inherentRating, residualRating);
            }
        }

        function displayInherentRiskRating(rating) {
            var display = document.getElementById('inherent_risk_display');
            var ratingText = '';
            if (rating >= 12) {
                ratingText = 'CRITICAL (' + rating + ')';
            } else if (rating >= 8) {
                ratingText = 'HIGH (' + rating + ')';
            } else if (rating >= 4) {
                ratingText = 'MEDIUM (' + rating + ')';
            } else {
                ratingText = 'LOW (' + rating + ')';
            }
            display.textContent = 'Inherent Risk Rating: ' + ratingText;
            applyRiskStyling(display, rating); 
        }

        function displayResidualRiskRating(rating) {
            var display = document.getElementById('residual_risk_display');
            var ratingText = '';
            if (rating >= 12) {
                ratingText = 'CRITICAL (' + rating + ')';
            } else if (rating >= 8) {
                ratingText = 'HIGH (' + rating + ')';
            } else if (rating >= 4) {
                ratingText = 'MEDIUM (' + rating + ')';
            } else {
                ratingText = 'LOW (' + rating + ')';
            }
            display.textContent = 'Residual Risk Rating: ' + ratingText;
            applyRiskStyling(display, rating); 
            
            calculateGeneralRiskScores(); // Recalculate general scores after residual rating is updated
        }

        function applyRiskStyling(element, rating) {
            if (rating >= 12) {
                element.style.backgroundColor = '#ff4444';
                element.style.color = 'white';
            } else if (rating >= 8) {
                element.style.backgroundColor = '#ff8800';
                element.style.color = 'white';
            } else if (rating >= 4) {
                element.style.backgroundColor = '#ffdd00';
                element.style.color = 'black';
            } else {
                element.style.backgroundColor = '#88dd88';
                element.style.color = 'black';
            }
        }

        function updateRiskSummary(inherentRating, residualRating) {
            var summaryDiv = document.getElementById('overall_risk_summary');
            if (summaryDiv) {
                var summaryText = 'Final Risk Assessment: ';
                if (residualRating >= 12) {
                    summaryText += 'CRITICAL (' + residualRating + ')';
                } else if (residualRating >= 8) {
                    summaryText += 'HIGH (' + residualRating + ')';
                } else if (residualRating >= 4) {
                    summaryText += 'MEDIUM (' + residualRating + ')';
                } else {
                    summaryText += 'LOW (' + residualRating + ')';
                }
                summaryText += '<br><small>Inherent Risk: ' + inherentRating + ' × Control Assessment: 1 = Residual Risk: ' + residualRating + '</small>';
                summaryDiv.innerHTML = summaryText;
                summaryDiv.style.fontStyle = 'normal';
                summaryDiv.style.color = '#333';
            }
            calculateGeneralRiskScores();
        }

        function getControlAssessmentValue() {
            return 1; 
        }

        function calculateGeneralRiskScores() {
            let totalInherentScore = 0;
            let totalResidualScore = 0;
            let riskCount = 0;
            
            // Get primary risk scores
            const primaryInherent = parseInt(document.getElementById('inherent_risk_rating').value) || 0;
            const primaryResidual = parseInt(document.getElementById('residual_risk_rating').value) || 0;
            if (primaryInherent > 0) {
                totalInherentScore += primaryInherent;
                totalResidualScore += primaryResidual;
                riskCount++;
            }
            
            // Get all secondary risk scores
            const secondaryRiskElements = document.querySelectorAll('[id^="secondary_risk_rating_"]');
            secondaryRiskElements.forEach(function(element) {
                const rating = parseInt(element.value) || 0;
                if (rating > 0) {
                    totalInherentScore += rating;
                    totalResidualScore += rating;
                    riskCount++;
                }
            });
            
            // Calculate maximum possible score based on number of risks
            const maxScale = 16 * riskCount; // Max rating per risk is 4*4=16
            
            // Update displays
            if (riskCount > 0) {
                displayGeneralInherentRisk(totalInherentScore, maxScale);
                displayGeneralResidualRisk(totalResidualScore, maxScale);
                document.getElementById('general_inherent_risk_score').value = totalInherentScore;
                document.getElementById('general_residual_risk_score').value = totalResidualScore;
            } else {
                // Reset if no risks are present
                document.getElementById('general_inherent_risk_display').textContent = 'Aggregate Inherent Risk Score will appear here';
                document.getElementById('general_residual_risk_display').textContent = 'Aggregate Residual Risk Score will appear here';
                document.getElementById('general_inherent_risk_score').value = '';
                document.getElementById('general_residual_risk_score').value = '';
                // Reset styling if necessary
                applyGeneralRiskStyling(document.getElementById('general_inherent_risk_display'), 0, 1); // Minimal maxScale to show LOW
                applyGeneralRiskStyling(document.getElementById('general_residual_risk_display'), 0, 1);
            }
        }

        function displayGeneralInherentRisk(score, maxScale) {
            const display = document.getElementById('general_inherent_risk_display');
            const ratingText = getGeneralRiskRating(score, maxScale);
            display.textContent = 'Aggregate Inherent Risk Score: ' + ratingText;
            applyGeneralRiskStyling(display, score, maxScale);
        }

        function displayGeneralResidualRisk(score, maxScale) {
            const display = document.getElementById('general_residual_risk_display');
            const ratingText = getGeneralRiskRating(score, maxScale);
            display.textContent = 'Aggregate Residual Risk Score: ' + ratingText;
            applyGeneralRiskStyling(display, score, maxScale);
        }

        function getGeneralRiskRating(score, maxScale) {
            if (maxScale <= 0) return 'LOW (0)'; // Handle case with no risks
            const lowThreshold = Math.floor(maxScale * 3 / 16); // Approx 18.75%
            const mediumThreshold = Math.floor(maxScale * 7 / 16); // Approx 43.75%
            const highThreshold = Math.floor(maxScale * 11 / 16); // Approx 68.75%
            
            if (score > highThreshold) {
                return 'CRITICAL (' + score + ')';
            } else if (score > mediumThreshold) {
                return 'HIGH (' + score + ')';
            } else if (score > lowThreshold) {
                return 'MEDIUM (' + score + ')';
            } else {
                return 'LOW (' + score + ')';
            }
        }

        function applyGeneralRiskStyling(display, score, maxScale) {
            if (maxScale <= 0) { 
                display.style.backgroundColor = '#88dd88';
                display.style.color = 'black';
                return;
            }
            const lowThreshold = Math.floor(maxScale * 3 / 16);
            const mediumThreshold = Math.floor(maxScale * 7 / 16);
            const highThreshold = Math.floor(maxScale * 11 / 16);
            
            if (score > highThreshold) {
                display.style.backgroundColor = '#ff4444'; // Critical
                display.style.color = 'white';
            } else if (score > mediumThreshold) {
                display.style.backgroundColor = '#ff8800'; // High
                display.style.color = 'white';
            } else if (score > lowThreshold) {
                display.style.backgroundColor = '#ffdd00'; // Medium
                display.style.color = 'black';
            } else {
                display.style.backgroundColor = '#88dd88'; // Low
                display.style.color = 'black';
            }
            display.style.padding = '10px';
            display.style.borderRadius = '4px';
            display.style.fontWeight = 'bold';
            display.style.textAlign = 'center';
        }

        function showSecondaryRiskSelection() {
            if (window.selectedRisks.length === 0) {
                alert("Please select a primary risk category first before adding secondary risks.");
                const noRadio = document.querySelector('input[name="has_secondary_risk"][value="no"]');
                if (noRadio) {
                    noRadio.checked = true;
                }
                return;
            }
            const secondaryRiskSelection = document.getElementById('secondary-risk-selection');
            if (secondaryRiskSelection) {
                secondaryRiskSelection.style.display = 'block';
                updateSecondaryRiskCategories();
            }
        }

        function hideSecondaryRiskSelection() {
            const secondaryRiskSelection = document.getElementById('secondary-risk-selection');
            if (secondaryRiskSelection) {
                secondaryRiskSelection.style.display = 'none';
            }
        }

        function updateSecondaryRiskCategories() {
            const container = document.getElementById('secondary-risk-categories');
            if (!container) return;
            
            const availableCategories = allRiskCategories.filter(category => 
                !window.selectedRisks.includes(category.value)
            );
            
            if (availableCategories.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">All risk categories have been selected.</p>';
                return;
            }
            
            container.innerHTML = availableCategories.map(category => `
                <div class="category-item" data-value="${category.value}">
                    <label class="radio-category-label">
                        <input type="radio" name="current_secondary_risk" value="${category.value}" onclick="selectSecondaryRisk('${category.value}', '${category.display}')">
                        <span class="checkmark">${category.display}</span>
                    </label>
                </div>
            `).join('');
        }

        function selectSecondaryRisk(value, displayText) {
            window.selectedRisks.push(value); // Add to the list to prevent selecting it again
            window.currentSecondaryRiskIndex++;
            createSecondaryRiskAssessment(value, displayText, window.currentSecondaryRiskIndex);
            document.getElementById('secondary-risk-selection').style.display = 'none';
            const radioButtons = document.querySelectorAll('input[name="has_secondary_risk"]');
            radioButtons.forEach(radio => radio.checked = false);
            document.getElementById('initial-secondary-question').style.display = 'block';
        }

        function createSecondaryRiskAssessment(riskValue, riskDisplay, riskIndex) {
            const container = document.getElementById('secondary-risks-container');
            const romanNumerals = ['', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x', 'xi'];
            const romanNumeral = romanNumerals[riskIndex] || `risk_${riskIndex}`;
            
            const assessmentHtml = `
                <div class="secondary-risk-assessment" id="secondary-risk-${riskIndex}">
                    <div class="secondary-risk-header">
                        ${romanNumeral}. ${riskDisplay}
                    </div>
                    <div class="secondary-risk-content">
                        <div class="secondary-likelihood-section">
                            <label class="form-label">Likelihood *</label>
                            <div class="secondary-likelihood-boxes">
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #ff4444; color: white;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 4)"
                                     data-value="4">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">ALMOST CERTAIN</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        1. Guaranteed to happen<br>
                                        2. Has been happening<br>
                                        3. Continues to happen
                                    </div>
                                </div>
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #ff8800; color: white;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 3)"
                                     data-value="3">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">LIKELY</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        A history of happening at certain intervals/seasons/events
                                    </div>
                                </div>
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #ffdd00; color: black;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 2)"
                                     data-value="2">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">POSSIBLE</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        1. More than 1 year from last occurrence<br>
                                        2. Circumstances indicating or allowing possibility of happening
                                    </div>
                                </div>
                                <div class="secondary-likelihood-box" 
                                     style="background-color: #88dd88; color: black;"
                                     onclick="selectSecondaryLikelihood(${riskIndex}, this, 1)"
                                     data-value="1">
                                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">UNLIKELY</div>
                                    <div style="font-size: 12px; line-height: 1.3;">
                                        1. Not occurred before<br>
                                        2. This is the first time its happening<br>
                                        3. Not expected to happen for sometime
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="secondary_likelihood_${riskIndex}" id="secondary_likelihood_${riskIndex}" required>
                        </div>
                        <div class="secondary-impact-section">
                            <label class="form-label">Impact *</label>
                            <div class="secondary-impact-boxes" id="secondary_impact_boxes_${riskIndex}">
                                <!-- Will be populated dynamically -->
                            </div>
                            <input type="hidden" name="secondary_impact_${riskIndex}" id="secondary_impact_${riskIndex}" required>
                        </div>
                    </div>
                    <input type="hidden" name="secondary_risk_rating_${riskIndex}" id="secondary_risk_rating_${riskIndex}">
                    <input type="hidden" name="secondary_risk_category_${riskIndex}" value="${riskValue}">
                    <div id="secondary_risk_display_${riskIndex}" class="secondary-risk-rating-display">Secondary Risk Rating will appear here</div>
                </div>
            `;
            
            container.innerHTML += assessmentHtml;
            updateSecondaryImpactBoxes(riskIndex, riskValue);
        }

        function selectSecondaryLikelihood(riskIndex, element, value) {
            const likelihoodBoxes = document.querySelectorAll(`#secondary-risk-${riskIndex} .secondary-likelihood-box`);
            likelihoodBoxes.forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById(`secondary_likelihood_${riskIndex}`).value = value;
            calculateSecondaryRiskRating(riskIndex);
        }

        function selectSecondaryImpact(riskIndex, element, value) {
            const impactBoxes = document.querySelectorAll(`#secondary-risk-${riskIndex} .secondary-impact-box`);
            impactBoxes.forEach(box => {
                box.style.border = '3px solid transparent';
            });
            element.style.border = '3px solid #E60012';
            document.getElementById(`secondary_impact_${riskIndex}`).value = value;
            calculateSecondaryRiskRating(riskIndex);
        }

        function calculateSecondaryRiskRating(riskIndex) {
            const likelihood = document.getElementById(`secondary_likelihood_${riskIndex}`).value;
            const impact = document.getElementById(`secondary_impact_${riskIndex}`).value;
            if (likelihood && impact) {
                const rating = likelihood * impact;
                document.getElementById(`secondary_risk_rating_${riskIndex}`).value = rating;
                displaySecondaryRiskRating(riskIndex, rating);
            }
        }

        function displaySecondaryRiskRating(riskIndex, rating) {
            const display = document.getElementById(`secondary_risk_display_${riskIndex}`);
            const controlAssessment = 1.0; // Assuming control assessment is 1 for now
            const residualRating = Math.round(rating * controlAssessment);
            let inherentRatingText = '';
            let residualRatingText = '';
            
            if (rating >= 12) {
                inherentRatingText = 'CRITICAL (' + rating + ')';
            } else if (rating >= 8) {
                inherentRatingText = 'HIGH (' + rating + ')';
            } else if (rating >= 4) {
                inherentRatingText = 'MEDIUM (' + rating + ')';
            } else {
                inherentRatingText = 'LOW (' + rating + ')';
            }
            
            if (residualRating >= 12) {
                residualRatingText = 'CRITICAL (' + residualRating + ')';
                display.style.backgroundColor = '#ff4444';
                display.style.color = 'white';
            } else if (residualRating >= 8) {
                residualRatingText = 'HIGH (' + residualRating + ')';
                display.style.backgroundColor = '#ff8800';
                display.style.color = 'white';
            } else if (residualRating >= 4) {
                residualRatingText = 'MEDIUM (' + residualRating + ')';
                display.style.backgroundColor = '#ffdd00';
                display.style.color = 'black';
            } else {
                residualRatingText = 'LOW (' + residualRating + ')';
                display.style.backgroundColor = '#88dd88';
                display.style.color = 'black';
            }
            
            display.innerHTML = 'Secondary Inherent Risk Rating: ' + inherentRatingText + '<br>Secondary Residual Risk Rating: ' + residualRatingText;
            display.style.padding = '10px';
            display.style.borderRadius = '4px';
            display.style.fontWeight = 'bold';
            display.style.textAlign = 'center';
            
            calculateGeneralRiskScores();
        }

        function updateSecondaryImpactBoxes(riskIndex, categoryName) {
            const impacts = impactDefinitions[categoryName];
            if (!impacts) return;
            
            const container = document.getElementById(`secondary_impact_boxes_${riskIndex}`);
            if (!container) return;
            
            container.innerHTML = `
                <div class="secondary-impact-box" 
                     style="background-color: #ff4444; color: white;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 4)"
                     data-value="4">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.extreme.text.split('\n')[1]}</div>
                </div>
                <div class="secondary-impact-box" 
                     style="background-color: #ff8800; color: white;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 3)"
                     data-value="3">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.significant.text.split('\n')[1]}</div>
                </div>
                <div class="secondary-impact-box" 
                     style="background-color: #ffdd00; color: black;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 2)"
                     data-value="2">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.moderate.text.split('\n')[1]}</div>
                </div>
                <div class="secondary-impact-box" 
                     style="background-color: #88dd88; color: black;"
                     onclick="selectSecondaryImpact(${riskIndex}, this, 1)"
                     data-value="1">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div>
                    <div style="font-size: 12px; line-height: 1.3;">${impacts.minor.text.split('\n')[1]}</div>
                </div>
            `;
        }

        // Impact definitions
        const impactDefinitions = {
            'Financial Exposure': {
                extreme: { text: 'EXTREME\n> $10M or > 10% of annual revenue', value: 4 },
                significant: { text: 'SIGNIFICANT\n$1M - $10M or 1 - 10% of annual revenue', value: 3 },
                moderate: { text: 'MODERATE\n$100K - $1M or 0.1-1% of annual revenue', value: 2 },
                minor: { text: 'MINOR\n< $100K or < 0.1% of annual revenue', value: 1 }
            },
            'Decrease in market share': {
                extreme: { text: 'EXTREME\n> 10% market share loss', value: 4 },
                significant: { text: 'SIGNIFICANT\n5-10% market share loss', value: 3 },
                moderate: { text: 'MODERATE\n1-5% market share loss', value: 2 },
                minor: { text: 'MINOR\n< 1% market share loss', value: 1 }
            },
            'Customer Experience': {
                extreme: { text: 'EXTREME\nMass customer exodus', value: 4 },
                significant: { text: 'SIGNIFICANT\nSignificant customer complaints', value: 3 },
                moderate: { text: 'MODERATE\nModerate customer dissatisfaction', value: 2 },
                minor: { text: 'MINOR\nMinimal customer inconvenience', value: 1 }
            },
            'Compliance': {
                extreme: { text: 'EXTREME\nPenalties > $1M', value: 4 },
                significant: { text: 'SIGNIFICANT\nPenalties $0.5M - $1M', value: 3 },
                moderate: { text: 'MODERATE\nPenalties $0.1M - $0.5M', value: 2 },
                minor: { text: 'MINOR\n< $0.1M', value: 1 }
            },
            'Reputation': {
                extreme: { text: 'EXTREME\nNational/International media coverage', value: 4 },
                significant: { text: 'SIGNIFICANT\nRegional media coverage', value: 3 },
                moderate: { text: 'MODERATE\nLocal media coverage', value: 2 },
                minor: { text: 'MINOR\nMinimal public awareness', value: 1 }
            },
            'Fraud': {
                extreme: { text: 'EXTREME\n> $1M fraudulent activity', value: 4 },
                significant: { text: 'SIGNIFICANT\n$100K - $1M fraudulent activity', value: 3 },
                moderate: { text: 'MODERATE\n$10K - $100K fraudulent activity', value: 2 },
                minor: { text: 'MINOR\n< $10K fraudulent activity', value: 1 }
            },
            'Operations': {
                extreme: { text: 'EXTREME\n> 7 days business disruption', value: 4 },
                significant: { text: 'SIGNIFICANT\n3-7 days business disruption', value: 3 },
                moderate: { text: 'MODERATE\n1-3 days business disruption', value: 2 },
                minor: { text: 'MINOR\n< 1 day business disruption', value: 1 }
            },
            'Networks': {
                extreme: { text: 'EXTREME\nComplete network failure', value: 4 },
                significant: { text: 'SIGNIFICANT\nMajor network disruption', value: 3 },
                moderate: { text: 'MODERATE\nModerate network issues', value: 2 },
                minor: { text: 'MINOR\nMinimal network glitches', value: 1 }
            },
            'People': {
                extreme: { text: 'EXTREME\nMass employee exodus', value: 4 },
                significant: { text: 'SIGNIFICANT\nKey personnel loss', value: 3 },
                moderate: { text: 'MODERATE\nModerate staff turnover', value: 2 },
                minor: { text: 'MINOR\nMinimal staff impact', value: 1 }
            },
            'IT': {
                extreme: { text: 'EXTREME\nMajor data breach/system failure', value: 4 },
                significant: { text: 'SIGNIFICANT\nSignificant security incident', value: 3 },
                moderate: { text: 'MODERATE\nModerate IT disruption', value: 2 },
                minor: { text: 'MINOR\nMinimal technical issues', value: 1 }
            },
            'Other': {
                extreme: { text: 'EXTREME\nSevere impact on operations', value: 4 },
                significant: { text: 'SIGNIFICANT\nMajor operational impact', value: 3 },
                moderate: { text: 'MODERATE\nModerate operational impact', value: 2 },
                minor: { text: 'MINOR\nMinimal operational impact', value: 1 }
            }
        };

        function updateRiskCategoryDisplay(categoryName) {
            // Show primary risk category name in Section 2b
            const primaryDisplay = document.getElementById('primary-risk-category-display');
            const primaryNameSpan = document.getElementById('primary-risk-name');
            
            if (primaryDisplay && primaryNameSpan) {
                // Get the full display text from the radio button
                const selectedRadio = document.querySelector(`input[name="risk_categories"][value="${categoryName}"]`);
                const displayText = selectedRadio ? selectedRadio.nextElementSibling.textContent : categoryName;
                
                primaryNameSpan.textContent = displayText;
                primaryDisplay.style.display = 'block';
            }
            
            // Also update the old header (if it exists)
            const header = document.getElementById('selected-risk-category-header');
            const categoryNameSpan = document.getElementById('selected-category-name');
            if (header && categoryNameSpan) {
                categoryNameSpan.textContent = categoryName;
                header.style.display = 'block';
            }
        }

        function updateImpactBoxes(categoryName) {
            const impacts = impactDefinitions[categoryName];
            if (!impacts) return;
            
            const extremeBox = document.getElementById('inherent_extreme_box');
            const significantBox = document.getElementById('inherent_significant_box');
            const moderateBox = document.getElementById('inherent_moderate_box');
            const minorBox = document.getElementById('inherent_minor_box');
            
            if (extremeBox) {
                extremeBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">EXTREME</div><div style="font-size: 12px; line-height: 1.3;">${impacts.extreme.text.split('\n')[1]}</div>`;
                extremeBox.setAttribute('data-value', impacts.extreme.value);
            }
            if (significantBox) {
                significantBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">SIGNIFICANT</div><div style="font-size: 12px; line-height: 1.3;">${impacts.significant.text.split('\n')[1]}</div>`;
                significantBox.setAttribute('data-value', impacts.significant.value);
            }
            if (moderateBox) {
                moderateBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MODERATE</div><div style="font-size: 12px; line-height: 1.3;">${impacts.moderate.text.split('\n')[1]}</div>`;
                moderateBox.setAttribute('data-value', impacts.moderate.value);
            }
            if (minorBox) {
                minorBox.innerHTML = `<div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">MINOR</div><div style="font-size: 12px; line-height: 1.3;">${impacts.minor.text.split('\n')[1]}</div>`;
                minorBox.setAttribute('data-value', impacts.minor.value);
            }
        }

        function clearEntireForm() {
            if (confirm('Are you sure you want to cancel? All data will be lost.')) {
                // Create and submit a form to trigger the PHP cancel handler
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cancel_risk';
                input.value = '1';
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
