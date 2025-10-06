<?php
session_start();
include_once 'config/database.php';

// Include compatibility functions if file exists
if (file_exists('includes/php_compatibility.php')) {
    include_once 'includes/php_compatibility.php';
} else {
    // Define essential functions inline if include fails
    function endsWith($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }
}

$message = '';
$error = '';
$database = new Database();
$db = $database->getConnection();

// Fetch departments from database
$departments = [];
try {
    $dept_query = "SELECT id, department_name, initial FROM departments ORDER BY department_name ASC";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->execute();
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load departments. Please try again later.";
}

if ($_POST) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = $_POST['full_name'];
    $role = $_POST['role'];
    $department = $_POST['department'];
    $area_of_responsibility = null; // Default to null
    $assigned_risk_owner_id = null; // Default to null

    // Generate username from email (part before @)
    $username = explode('@', $email)[0];

    // Validate email domain - using compatible function
    if (!endsWith($email, '@ke.airtel.com')) {
        $error = "Please use your Airtel email address ending with @ke.airtel.com";
        $database->logActivity(null, 'Registration Failed - Invalid Email Domain', 'Attempt to register with non-Airtel email: ' . $email, $_SERVER['REMOTE_ADDR']);
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
        $database->logActivity(null, 'Registration Failed - Password Mismatch', 'Attempt to register for ' . $email . ' with mismatched passwords.', $_SERVER['REMOTE_ADDR']);
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
        $database->logActivity(null, 'Registration Failed - Weak Password', 'Attempt to register for ' . $email . ' with a password less than 6 characters.', $_SERVER['REMOTE_ADDR']);
    } elseif (empty($role)) {
        $error = "Please select your role.";
        $database->logActivity(null, 'Registration Failed - Missing Role', 'Attempt to register for ' . $email . ' with no role selected.', $_SERVER['REMOTE_ADDR']);
    } elseif (empty($department)) {
        $error = "Please select your department.";
        $database->logActivity(null, 'Registration Failed - Missing Department', 'Attempt to register for ' . $email . ' with no department selected.', $_SERVER['REMOTE_ADDR']);
    } else {
        // Handle role-specific fields
        if ($role === 'risk_owner') {
            $area_of_responsibility = $_POST['area_of_responsibility'] ?? null;
            if (empty($area_of_responsibility)) {
                $error = "Please specify the Area of Responsibility for a Risk Owner.";
                $database->logActivity(null, 'Registration Failed - Missing AOR', 'Attempt to register Risk Owner ' . $email . ' with no Area of Responsibility.', $_SERVER['REMOTE_ADDR']);
            }
        } elseif ($role === 'staff') {
            $assigned_risk_owner_id = $_POST['assigned_risk_owner_id'] ?? null;
            if (empty($assigned_risk_owner_id)) {
                $error = "Please select your assigned Risk Owner.";
                $database->logActivity(null, 'Registration Failed - Missing Assigned RO', 'Attempt to register Staff ' . $email . ' with no assigned Risk Owner.', $_SERVER['REMOTE_ADDR']);
            }
        }

        if (empty($error)) { // Proceed only if no validation errors so far
            // Check if email or username already exists (regardless of status)
            $check_query = "SELECT id, status FROM users WHERE email = :email OR username = :username";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->execute();

            if ($check_stmt->rowCount() > 0) {
                $existing_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing_user['status'] === 'approved') {
                    $error = "Email address or username already registered and active. Please use a different one or contact support.";
                    $database->logActivity(null, 'Registration Failed - Already Active', 'Attempt to register with already active email/username: ' . $email, $_SERVER['REMOTE_ADDR']);
                } else {
                    // User exists but is not active (e.g., 'pending', 'inactive', 'deleted')
                    // Reactivate the account instead of creating a new one
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $status = ($role === 'admin') ? 'approved' : 'pending'; // New status for reactivation
                    $update_query = "UPDATE users SET
                                        password = :password,
                                        full_name = :full_name,
                                        role = :role,
                                        department = :department,
                                        area_of_responsibility = :area_of_responsibility,
                                        assigned_risk_owner_id = :assigned_risk_owner_id,
                                        status = :status,
                                        updated_at = NOW()
                                    WHERE id = :id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed_password);
                    $update_stmt->bindParam(':full_name', $full_name);
                    $update_stmt->bindParam(':role', $role);
                    $update_stmt->bindParam(':department', $department);
                    $update_stmt->bindParam(':area_of_responsibility', $area_of_responsibility);
                    $update_stmt->bindParam(':assigned_risk_owner_id', $assigned_risk_owner_id);
                    $update_stmt->bindParam(':status', $status);
                    $update_stmt->bindParam(':id', $existing_user['id']);

                    if ($update_stmt->execute()) {
                        if ($role === 'admin') {
                            $message = "Admin account reactivated and updated successfully! You can now login.";
                            $database->logActivity($existing_user['id'], 'Admin Account Reactivated', 'Admin account ' . $email . ' reactivated and updated.', $_SERVER['REMOTE_ADDR']);
                        } else {
                            $message = "Account reactivated and updated! Your account is pending approval by the administrator.";
                            $database->logActivity($existing_user['id'], 'User Account Reactivated', 'User account ' . $email . ' reactivated and updated (pending approval).', $_SERVER['REMOTE_ADDR']);
                        }
                        $_POST = array(); // Clear form fields
                    } else {
                        $error = "Failed to reactivate account. Please try again.";
                        $database->logActivity(null, 'Account Reactivation Failed', 'Failed to reactivate account for ' . $email . '.', $_SERVER['REMOTE_ADDR']);
                    }
                }
            } else {
                // No existing user found, proceed with new registration (INSERT)
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                // Admin accounts are auto-approved, others need approval
                $status = ($role === 'admin') ? 'approved' : 'pending';
                $query = "INSERT INTO users (username, email, password, full_name, role, department, area_of_responsibility, assigned_risk_owner_id, status, created_at, updated_at)
                            VALUES (:username, :email, :password, :full_name, :role, :department, :area_of_responsibility, :assigned_risk_owner_id, :status, NOW(), NOW())";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':department', $department);
                $stmt->bindParam(':area_of_responsibility', $area_of_responsibility);
                $stmt->bindParam(':assigned_risk_owner_id', $assigned_risk_owner_id);
                $stmt->bindParam(':status', $status);

                if ($stmt->execute()) {
                    if ($role === 'admin') {
                        $message = "Admin account created successfully! You can now login.";
                        $database->logActivity($db->lastInsertId(), 'New Admin Registration', 'New admin account created and auto-approved for ' . $email, $_SERVER['REMOTE_ADDR']);
                    } else {
                        $message = "Registration successful! Your account is pending approval by the administrator.";
                        $database->logActivity($db->lastInsertId(), 'New User Registration', 'New ' . $role . ' account created for ' . $email . ' (pending approval).', $_SERVER['REMOTE_ADDR']);
                    }
                    // Clear form fields on successful submission
                    $_POST = array();
                } else {
                    $error = "Registration failed. Please try again.";
                    $database->logActivity(null, 'Registration Failed - Database Error', 'Failed to register new user ' . $email . '. Database error: ' . implode(" ", $stmt->errorInfo()), $_SERVER['REMOTE_ADDR']);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Airtel Risk Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        .register-container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(230, 0, 18, 0.2);
            width: 100%;
            max-width: 500px;
            border-top: 5px solid #E60012;
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .airtel-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            display: block;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(230, 0, 18, 0.2);
            object-fit: cover;
        }
        .logo h1 {
            color: #E60012;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        input, select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        .role-selection {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        .role-card {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .role-card:hover {
            border-color: #E60012;
            background: #fff5f5;
        }
        .role-card.selected {
            border-color: #E60012;
            background: #fff5f5;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        .role-card input[type="radio"] {
            display: none;
        }
        .role-title {
            font-weight: 600;
            color: #333;
            text-align: center;
            padding: 1rem;
        }
        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(230, 0, 18, 0.3);
        }
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #ffcdd2;
        }
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #c8e6c9;
        }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .login-link a {
            color: #E60012;
            text-decoration: none;
            font-weight: 500;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <img src="image.png" alt="Airtel Logo" class="airtel-logo">
            <h1>Airtel Risk Management</h1>
            <p>Create your account to get started</p>
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
            </div>
            <div class="form-group">
                <label for="email">Airtel Email Address</label>
                <input type="email" id="email" name="email" required placeholder="your.name@ke.airtel.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            <div class="form-group">
                <label for="department">Department</label>
                <select id="department" name="department" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['department_name']); ?>" 
                                <?php echo (isset($_POST['department']) && $_POST['department'] === $dept['department_name']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Select Your Role</label>
                <div class="role-selection">
                    <label class="role-card" for="role_staff">
                        <input type="radio" id="role_staff" name="role" value="staff" <?php echo (isset($_POST['role']) && $_POST['role'] === 'staff') ? 'checked' : ''; ?> onchange="toggleRoleFields()">
                        <div class="role-title">Staff</div>
                    </label>
                    <label class="role-card" for="role_risk_owner">
                        <input type="radio" id="role_risk_owner" name="role" value="risk_owner" <?php echo (isset($_POST['role']) && $_POST['role'] === 'risk_owner') ? 'checked' : ''; ?> onchange="toggleRoleFields()">
                        <div class="role-title">Risk Owner</div>
                    </label>
                    <label class="role-card" for="role_compliance">
                        <input type="radio" id="role_compliance" name="role" value="compliance_team" <?php echo (isset($_POST['role']) && $_POST['role'] === 'compliance') ? 'checked' : ''; ?> onchange="toggleRoleFields()">
                        <div class="role-title">Compliance</div>
                    </label>
                    <label class="role-card" for="role_admin">
                        <input type="radio" id="role_admin" name="role" value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'checked' : ''; ?> onchange="toggleRoleFields()">
                        <div class="role-title">Administrator</div>
                    </label>
                </div>
            </div>

            <div id="riskOwnerFields" style="display: none;">
                <div class="form-group">
                    <label for="area_of_responsibility">Area of Responsibility</label>
                    <select id="area_of_responsibility" name="area_of_responsibility">
                        <option value="">Select Area of Responsibility</option>
                        <option value="Marketing" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
                        <option value="Sales" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Sales') ? 'selected' : ''; ?>>Sales</option>
                        <option value="IT" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'IT') ? 'selected' : ''; ?>>IT</option>
                        <option value="Partnerships" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Partnerships') ? 'selected' : ''; ?>>Partnerships</option>
                        <option value="Operations" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Operations') ? 'selected' : ''; ?>>Operations</option>
                        <option value="Distributions" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Distributions') ? 'selected' : ''; ?>>Distributions</option>
                        <option value="Finance" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                        <option value="Human Resources" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Human Resources') ? 'selected' : ''; ?>>Human Resources</option>
                        <option value="Legal" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Legal') ? 'selected' : ''; ?>>Legal</option>
                        <option value="Customer Service" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Customer Service') ? 'selected' : ''; ?>>Customer Service</option>
                        <option value="Network" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Network') ? 'selected' : ''; ?>>Network</option>
                        <option value="Other" <?php echo (isset($_POST['area_of_responsibility']) && $_POST['area_of_responsibility'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
            </div>
            <div id="staffFields" style="display: none;">
                <div class="form-group">
                    <label for="assigned_risk_owner_id">Assigned Risk Owner</label>
                    <select id="assigned_risk_owner_id" name="assigned_risk_owner_id">
                        <option value="">Select a Department first</option>
                         Options will be loaded dynamically by JavaScript 
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="6" placeholder="Minimum 6 characters">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter your password">
            </div>
            <button type="submit" class="btn">Create Account</button>
        </form>
        <div class="login-link">
            <p>Already have an account? <a href="login.php">Sign in here</a></p>
        </div>
    </div>
    <script>
        // Function to fetch risk owners based on department
        function fetchRiskOwnersByDepartment(department) {
            const assignedRiskOwnerSelect = document.getElementById('assigned_risk_owner_id');
            const prevSelectedOwnerId = assignedRiskOwnerSelect.dataset.prevSelected; // Get previously selected value
            assignedRiskOwnerSelect.innerHTML = '<option value="">Loading Risk Owners...</option>'; // Clear and show loading state
            if (!department) {
                assignedRiskOwnerSelect.innerHTML = '<option value="">Select a Department first</option>';
                return;
            }
            // Construct the URL for the API call
            const apiUrl = `api/get_risk_owners_by_department.php?department=${encodeURIComponent(department)}`;
            fetch(apiUrl)
                .then(response => {
                    if (!response.ok) {
                        // Log the full response text for debugging if there's an HTTP error
                        return response.text().then(text => { throw new Error(`HTTP error! status: ${response.status}, response: ${text}`); });
                    }
                    return response.json();
                })
                .then(data => {
                    assignedRiskOwnerSelect.innerHTML = '<option value="">Select your Risk Owner</option>'; // Default option
                    if (data.error) {
                        assignedRiskOwnerSelect.innerHTML = '<option value="">Error loading owners</option>';
                        console.error('API Error:', data.error);
                        return;
                    }
                    if (data.length === 0) {
                        assignedRiskOwnerSelect.innerHTML = '<option value="">No Risk Owners in this Department</option>';
                    } else {
                        data.forEach(owner => {
                            const option = document.createElement('option');
                            option.value = owner.id;
                            option.textContent = `${owner.full_name} (${owner.department})`;
                            assignedRiskOwnerSelect.appendChild(option);
                        });
                    }
                    // Attempt to re-select the previously chosen value if it's still valid
                    if (prevSelectedOwnerId) {
                        const optionExists = Array.from(assignedRiskOwnerSelect.options).some(option => option.value === prevSelectedOwnerId);
                        if (optionExists) {
                            assignedRiskOwnerSelect.value = prevSelectedOwnerId;
                        } else {
                            assignedRiskOwnerSelect.value = ''; // Clear if previous selection is not in the new list
                        }
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    assignedRiskOwnerSelect.innerHTML = '<option value="">Error loading Risk Owners</option>';
                });
        }
        // Function to toggle visibility of role-specific fields
        function toggleRoleFields() {
            const selectedRole = document.querySelector('input[name="role"]:checked')?.value;
            const riskOwnerFields = document.getElementById('riskOwnerFields');
            const staffFields = document.getElementById('staffFields');
            const departmentSelect = document.getElementById('department');
            const assignedRiskOwnerSelect = document.getElementById('assigned_risk_owner_id');
            if (riskOwnerFields && staffFields) {
                riskOwnerFields.style.display = 'none';
                staffFields.style.display = 'none';
                // Reset required attributes
                riskOwnerFields.querySelector('select').removeAttribute('required');
                staffFields.querySelector('select').removeAttribute('required');
                if (selectedRole === 'risk_owner') {
                    riskOwnerFields.style.display = 'block';
                    riskOwnerFields.querySelector('select').setAttribute('required', 'required');
                } else if (selectedRole === 'staff') {
                    staffFields.style.display = 'block';
                    staffFields.querySelector('select').setAttribute('required', 'required');
                    // Store current selection before fetching new list (if form was submitted with errors)
                    if (assignedRiskOwnerSelect.value) {
                        assignedRiskOwnerSelect.dataset.prevSelected = assignedRiskOwnerSelect.value;
                    } else if ('<?php echo isset($_POST["assigned_risk_owner_id"]) ? htmlspecialchars($_POST["assigned_risk_owner_id"]) : ""; ?>') {
                        // If there was a POST value but no current selection, use the POST value
                        assignedRiskOwnerSelect.dataset.prevSelected = '<?php echo isset($_POST["assigned_risk_owner_id"]) ? htmlspecialchars($_POST["assigned_risk_owner_id"]) : ""; ?>';
                    }
                    // Fetch risk owners for the currently selected department
                    fetchRiskOwnersByDepartment(departmentSelect.value);
                }
            }
        }
        // Event listener for role card selection
        document.querySelectorAll('.role-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input[type="radio"]').checked = true;
                toggleRoleFields(); // Call toggle function on click
            });
        });
        // Event listener for department change (only if staff role is selected)
        document.getElementById('department').addEventListener('change', function() {
            const selectedRole = document.querySelector('input[name="role"]:checked')?.value;
            if (selectedRole === 'staff') {
                fetchRiskOwnersByDepartment(this.value);
            }
        });
        // Initial call on page load to set up fields correctly
        document.addEventListener('DOMContentLoaded', function() {
            const initialCheckedRadio = document.querySelector('input[type="radio"]:checked');
            if (initialCheckedRadio) {
                initialCheckedRadio.closest('.role-card').classList.add('selected');
            }
            toggleRoleFields(); // Call on load to set initial visibility and fetch initial risk owners if staff is selected
        });
    </script>
</body>
</html>