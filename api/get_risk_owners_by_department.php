<?php
header('Content-Type: application/json');
include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$department = isset($_GET['department']) ? trim($_GET['department']) : '';

$risk_owners = [];

if (!empty($department)) {
    try {
        // Query joins with departments table to handle both department name and ID scenarios
        $query = "SELECT DISTINCT u.id, u.full_name, u.department 
                  FROM users u
                  LEFT JOIN departments d ON (u.department = d.department_name OR u.department = d.id)
                  WHERE u.role = 'risk_owner' 
                  AND u.status = 'approved' 
                  AND (LOWER(u.department) = LOWER(:department) OR LOWER(d.department_name) = LOWER(:department))
                  ORDER BY u.full_name";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':department', $department);
        $stmt->execute();
        $risk_owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching risk owners by department: " . $e->getMessage());
        echo json_encode(['error' => 'Database error fetching risk owners.']);
        exit();
    }
}

echo json_encode($risk_owners);
?>
