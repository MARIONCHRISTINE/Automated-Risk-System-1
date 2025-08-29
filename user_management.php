<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
include_once 'includes/auth.php';
requireRole('admin');
include_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();
// Email Configuration Class - FIXED GMAIL SMTP SETTINGS
class EmailConfig {
    // Basic Email Settings
    const FROM_EMAIL = 'mauriceokoth952@gmail.com';
    const FROM_NAME = 'Airtel Risk Management System';
    const SUPPORT_EMAIL = 'support@yourcompany.com';
    const COMPANY_NAME = 'Airtel Risk Management';
    // System URLs
    const LOGIN_URL = 'http://localhost/Automated-risk-system-1/login.php';
    const SYSTEM_URL = 'http://localhost/Automated-risk-system-1';
    // SMTP Settings - CORRECTED FOR GMAIL
    const SMTP_HOST = 'smtp.gmail.com';
    const SMTP_PORT = 587;
    const SMTP_USERNAME = 'mauriceokoth952@gmail.com';
    const SMTP_PASSWORD = 'dein nmuj btpc cxoe'; // Your Gmail App Password
    const SMTP_ENCRYPTION = 'tls';
    // Email Templates Settings
    const COMPANY_LOGO = 'src link rel href="image.png"';
    const PRIMARY_COLOR = '#E60012';
    const SECONDARY_COLOR = '#f8f9fa';
}
// Create necessary tables for session management and account locking
try {
    // Active Sessions Table
    $create_sessions_table = "
    CREATE TABLE IF NOT EXISTS active_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_id VARCHAR(255) NOT NULL UNIQUE,
        user_email VARCHAR(255) NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        user_role VARCHAR(50) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        is_active TINYINT(1) DEFAULT 1,
        logout_time TIMESTAMP NULL,
        session_data TEXT,
        INDEX idx_user_id (user_id),
        INDEX idx_session_id (session_id),
        INDEX idx_last_activity (last_activity),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($create_sessions_table);
    // Account Lockouts Table
    $create_lockouts_table = "
    CREATE TABLE IF NOT EXISTS account_lockouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_email VARCHAR(255) NOT NULL,
        failed_attempts INT DEFAULT 0,
        locked_at TIMESTAMP NULL,
        unlock_at TIMESTAMP NULL,
        is_locked TINYINT(1) DEFAULT 0,
        lock_reason VARCHAR(255) DEFAULT 'Too many failed login attempts',
        locked_by_admin TINYINT(1) DEFAULT 0,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_user_email (user_email),
        INDEX idx_locked_at (locked_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $db->exec($create_lockouts_table);
    // Security Settings Table
    $create_security_settings_table = "
    CREATE TABLE IF NOT EXISTS security_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_name VARCHAR(100) NOT NULL UNIQUE,
        setting_value VARCHAR(255) NOT NULL,
        description TEXT,
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
    )";
    $db->exec($create_security_settings_table);
    // Insert default security settings
    $default_settings = [
        ['max_failed_attempts', '5', 'Maximum failed login attempts before account lockout'],
        ['lockout_duration', '15', 'Account lockout duration in minutes'],
        ['auto_unlock', '1', 'Automatically unlock accounts after lockout duration (1=enabled, 0=disabled)'],
        ['session_timeout', '30', 'Session timeout in minutes'],
        ['max_concurrent_sessions', '3', 'Maximum concurrent sessions per user']
    ];
    foreach ($default_settings as $setting) {
        $check_setting = $db->prepare("SELECT COUNT(*) FROM security_settings WHERE setting_name = ?");
        $check_setting->execute([$setting[0]]);
        if ($check_setting->fetchColumn() == 0) {
            $insert_setting = $db->prepare("INSERT INTO security_settings (setting_name, setting_value, description, updated_by) VALUES (?, ?, ?, ?)");
            $insert_setting->execute([$setting[0], $setting[1], $setting[2], $_SESSION['user_id']]);
        }
    }
} catch (Exception $e) {
    error_log("Database setup error: " . $e->getMessage());
}
// Get real session and lockout data
function getActiveSessions($db) {
    $timeout_minutes = getSecuritySetting($db, 'session_timeout', 30);
   
    $query = "SELECT s.*,
                     TIMESTAMPDIFF(MINUTE, s.last_activity, NOW()) as idle_minutes,
                     CASE
                         WHEN TIMESTAMPDIFF(MINUTE, s.last_activity, NOW()) > ? THEN 'Expired'
                         WHEN s.is_active = 1 THEN 'Active'
                         ELSE 'Inactive'
                     END as status
              FROM active_sessions s
              WHERE s.logout_time IS NULL
              ORDER BY s.last_activity DESC";
   
    $stmt = $db->prepare($query);
    $stmt->execute([$timeout_minutes]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getLockedAccounts($db) {
    $query = "SELECT al.*, u.full_name,
                     TIMESTAMPDIFF(MINUTE, al.locked_at, NOW()) as locked_minutes,
                     TIMESTAMPDIFF(MINUTE, NOW(), al.unlock_at) as remaining_minutes
              FROM account_lockouts al
              LEFT JOIN users u ON al.user_id = u.id
              WHERE al.is_locked = 1
              ORDER BY al.locked_at DESC";
   
    $stmt = $db->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getSecuritySetting($db, $setting_name, $default_value) {
    $query = "SELECT setting_value FROM security_settings WHERE setting_name = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$setting_name]);
    $result = $stmt->fetchColumn();
    return $result !== false ? (int)$result : $default_value;
}
// Handle AJAX requests for session management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
   
    switch ($_POST['ajax_action']) {
        case 'get_active_sessions':
            $sessions = getActiveSessions($db);
            echo json_encode(['success' => true, 'sessions' => $sessions]);
            exit;
           
        case 'force_logout':
            $session_id = $_POST['session_id'] ?? '';
            $query = "UPDATE active_sessions SET is_active = 0, logout_time = NOW() WHERE session_id = ?";
            $stmt = $db->prepare($query);
            $result = $stmt->execute([$session_id]);
            echo json_encode(['success' => $result]);
            exit;
           
        case 'force_logout_all':
            $query = "UPDATE active_sessions SET is_active = 0, logout_time = NOW() WHERE is_active = 1 AND logout_time IS NULL";
            $stmt = $db->prepare($query);
            $result = $stmt->execute();
            echo json_encode(['success' => $result]);
            exit;
           
        case 'get_locked_accounts':
            $locked = getLockedAccounts($db);
            echo json_encode(['success' => true, 'accounts' => $locked]);
            exit;
           
        case 'unlock_account':
            $user_email = $_POST['user_email'] ?? '';
            $query = "UPDATE account_lockouts SET is_locked = 0, failed_attempts = 0, unlock_at = NOW() WHERE user_email = ?";
            $stmt = $db->prepare($query);
            $result = $stmt->execute([$user_email]);
            echo json_encode(['success' => $result]);
            exit;
           
        case 'unlock_all_accounts':
            $query = "UPDATE account_lockouts SET is_locked = 0, failed_attempts = 0, unlock_at = NOW() WHERE is_locked = 1";
            $stmt = $db->prepare($query);
            $result = $stmt->execute();
            echo json_encode(['success' => $result]);
            exit;
           
        case 'update_security_settings':
            $settings = json_decode($_POST['settings'], true);
            $success = true;
            foreach ($settings as $name => $value) {
                $query = "UPDATE security_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_name = ?";
                $stmt = $db->prepare($query);
                if (!$stmt->execute([$value, $_SESSION['user_id'], $name])) {
                    $success = false;
                }
            }
            echo json_encode(['success' => $success]);
            exit;
    }
}
// Get current stats for display
$active_sessions = getActiveSessions($db);
$locked_accounts = getLockedAccounts($db);
$active_sessions_count = count(array_filter($active_sessions, function($s) { return $s['status'] === 'Active'; }));
$admin_sessions_count = count(array_filter($active_sessions, function($s) { return $s['user_role'] === 'admin' && $s['status'] === 'Active'; }));
$locked_accounts_count = count($locked_accounts);
$max_failed_attempts = getSecuritySetting($db, 'max_failed_attempts', 5);
$lockout_duration = getSecuritySetting($db, 'lockout_duration', 15);
$auto_unlock = getSecuritySetting($db, 'auto_unlock', 1);
// Download and install PHPMailer automatically if not present
function ensurePHPMailerExists() {
    $phpmailerPath = __DIR__ . '/PHPMailer';
    if (!file_exists($phpmailerPath)) {
        // Create PHPMailer directory
        mkdir($phpmailerPath, 0755, true);
       
        // Download PHPMailer files
        $files = [
            'PHPMailer.php' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/PHPMailer.php',
            'SMTP.php' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/SMTP.php',
            'Exception.php' => 'https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/Exception.php'
        ];
       
        foreach ($files as $filename => $url) {
            $content = @file_get_contents($url);
            if ($content !== false) {
                file_put_contents($phpmailerPath . '/' . $filename, $content);
            }
        }
    }
    // Include PHPMailer files
    if (file_exists($phpmailerPath . '/PHPMailer.php')) {
        require_once $phpmailerPath . '/PHPMailer.php';
        require_once $phpmailerPath . '/SMTP.php';
        require_once $phpmailerPath . '/Exception.php';
        return true;
    }
    return false;
}
// COMPLETELY REWRITTEN Email sending function - CRITICAL FIX FOR RECIPIENT EMAIL
function sendInvitationEmail($recipientEmail, $recipientName, $role, $department, $defaultPassword) {
    // CRITICAL DEBUG: Log all parameters at the start
    error_log("=== EMAIL SENDING DEBUG START ===");
    error_log("RECIPIENT EMAIL PARAMETER: " . $recipientEmail);
    error_log("RECIPIENT NAME PARAMETER: " . $recipientName);
    error_log("ROLE PARAMETER: " . $role);
    error_log("DEPARTMENT PARAMETER: " . $department);
    error_log("FROM EMAIL (SENDER): " . EmailConfig::FROM_EMAIL);
   
    // VALIDATION: Ensure we have a valid recipient email
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("ERROR - Invalid recipient email: " . $recipientEmail);
        return false;
    }
   
    // VALIDATION: Ensure recipient email is not the same as sender email
    if (trim(strtolower($recipientEmail)) === trim(strtolower(EmailConfig::FROM_EMAIL))) {
        error_log("ERROR - Recipient email is same as sender email: " . $recipientEmail);
        return false;
    }
   
    // Try to ensure PHPMailer is available
    $phpmailerAvailable = ensurePHPMailerExists();
    if (!$phpmailerAvailable) {
        error_log("PHPMailer not available, using native PHP mail");
        return sendWithNativePHP($recipientEmail, $recipientName, $role, $department, $defaultPassword);
    }
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    try {
        // CRITICAL: Clear all recipients first to ensure clean state
        $mail->clearAllRecipients();
        $mail->clearAddresses();
        $mail->clearCCs();
        $mail->clearBCCs();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();
       
        // Server settings
        $mail->isSMTP();
        $mail->Host = EmailConfig::SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = EmailConfig::SMTP_USERNAME;
        $mail->Password = EmailConfig::SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = EmailConfig::SMTP_PORT;
       
        // Enable debug output for troubleshooting
        $mail->SMTPDebug = 2; // Enable verbose debug output
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer DEBUG: " . $str);
        };
       
        // CRITICAL: Set sender information
        $mail->setFrom(EmailConfig::FROM_EMAIL, EmailConfig::FROM_NAME);
        error_log("SENDER SET TO: " . EmailConfig::FROM_EMAIL . " (" . EmailConfig::FROM_NAME . ")");
       
        // CRITICAL: Add recipient - THIS IS THE KEY FIX
        error_log("DEBUG: About to add recipient: $recipientEmail ($recipientName)");
        $mail->addAddress($recipientEmail, $recipientName);
        error_log("RECIPIENT ADDED TO PHPMAILER: " . $recipientEmail . " (" . $recipientName . ")");
       
        // Set reply-to
        $mail->addReplyTo(EmailConfig::SUPPORT_EMAIL, EmailConfig::COMPANY_NAME . ' Support');
       
        // CRITICAL: Verify the recipient was added correctly
        $recipients = $mail->getToAddresses();
        error_log("PHPMAILER TO ADDRESSES: " . print_r($recipients, true));
       
        // Content settings
        $mail->isHTML(true);
        $mail->Subject = "Invitation to " . EmailConfig::COMPANY_NAME . " System";
        $mail->CharSet = 'UTF-8';
       
        // Create email body
        $mail->Body = createEmailTemplate($recipientName, $recipientEmail, $role, $department, $defaultPassword);
       
        // CRITICAL: Add custom headers to ensure proper delivery
        $mail->addCustomHeader('X-Recipient-Email', $recipientEmail);
        $mail->addCustomHeader('X-Recipient-Name', $recipientName);
        $mail->addCustomHeader('X-Mailer', 'Airtel Risk Management System');
       
        // Log final email details before sending
        error_log("FINAL EMAIL DETAILS BEFORE SENDING:");
        error_log("- Subject: " . $mail->Subject);
        error_log("- From: " . $mail->From . " (" . $mail->FromName . ")");
        error_log("- To: " . $recipientEmail . " (" . $recipientName . ")");
        error_log("- Reply-To: " . EmailConfig::SUPPORT_EMAIL);
       
        // Send the email
        $result = $mail->send();
       
        if ($result) {
            error_log("SUCCESS - Email sent successfully to: " . $recipientEmail);
            error_log("=== EMAIL SENDING DEBUG END (SUCCESS) ===");
            return true;
        } else {
            error_log("FAILED - PHPMailer send() returned false for: " . $recipientEmail);
            error_log("=== EMAIL SENDING DEBUG END (FAILED) ===");
            return false;
        }
       
    } catch (Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        error_log("PHPMailer ErrorInfo: " . $mail->ErrorInfo);
        error_log("Failed recipient: " . $recipientEmail);
        error_log("=== EMAIL SENDING DEBUG END (EXCEPTION) ===");
       
        // Fallback to native PHP mail
        return sendWithNativePHP($recipientEmail, $recipientName, $role, $department, $defaultPassword);
    }
}
// Create email template
function createEmailTemplate($recipientName, $recipientEmail, $role, $department, $defaultPassword) {
    return "
    <html>
    <head>
        <title>Welcome to " . EmailConfig::COMPANY_NAME . "</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
            .header { background: " . EmailConfig::PRIMARY_COLOR . "; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: " . EmailConfig::SECONDARY_COLOR . "; }
            .credentials { background: #fff; padding: 15px; border-left: 4px solid " . EmailConfig::PRIMARY_COLOR . "; margin: 20px 0; border-radius: 5px; }
            .footer { background: #333; color: white; padding: 15px; text-align: center; font-size: 12px; }
            .btn { background: " . EmailConfig::PRIMARY_COLOR . "; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 0; }
            .btn:hover { background: #B8000E; }
            .password-box { background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 16px; font-weight: bold; color: #E60012; }
            .debug-info { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 10px; margin: 10px 0; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='debug-info'>
            <strong>DEBUG INFO:</strong><br>
            This email should be delivered to: " . htmlspecialchars($recipientEmail) . "<br>
            Recipient Name: " . htmlspecialchars($recipientName) . "<br>
            Generated at: " . date('Y-m-d H:i:s') . "
        </div>
       
        <div class='header'>
            <h1>🎉 Welcome to " . EmailConfig::COMPANY_NAME . "</h1>
        </div>
       
        <div class='content'>
            <h2>Hello " . htmlspecialchars($recipientName) . ",</h2>
           
            <p>Congratulations! You have been successfully invited to join the " . EmailConfig::COMPANY_NAME . " system.</p>
           
            <div class='credentials'>
                <h3>📋 Your Account Details:</h3>
                <p><strong>👤 Name:</strong> " . htmlspecialchars($recipientName) . "</p>
                <p><strong>📧 Email:</strong> " . htmlspecialchars($recipientEmail) . "</p>
                <p><strong>🏷️ Role:</strong> " . ucfirst(str_replace('_', ' ', $role)) . "</p>
                <p><strong>🏢 Department:</strong> " . htmlspecialchars($department) . "</p>
                <p><strong>🔑 Temporary Password:</strong></p>
                <div class='password-box'>" . htmlspecialchars($defaultPassword) . "</div>
            </div>
           
            <h3>🚀 Getting Started:</h3>
            <ol>
                <li>Click the login button below to access the system</li>
                <li>Use your email address and the temporary password provided above</li>
                <li>You will be required to change your password on first login for security</li>
            </ol>
           
            <p style='text-align: center;'>
                <a href='" . EmailConfig::LOGIN_URL . "' class='btn'>🔐 Login to System</a>
            </p>
           
            <div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <h4>🔒 Important Security Notes:</h4>
                <ul>
                    <li>Please change your password immediately after first login</li>
                    <li>Do not share your login credentials with anyone</li>
                    <li>If you did not expect this invitation, please contact the system administrator</li>
                    <li>This email contains sensitive information - please delete it after logging in</li>
                </ul>
            </div>
           
            <p>If you have any questions or need assistance, please contact us at <a href='mailto:" . EmailConfig::SUPPORT_EMAIL . "'>" . EmailConfig::SUPPORT_EMAIL . "</a></p>
           
            <p>Best regards,<br>
            <strong>" . EmailConfig::COMPANY_NAME . " Team</strong></p>
        </div>
       
        <div class='footer'>
            <p>&copy; " . date('Y') . " " . EmailConfig::COMPANY_NAME . ". All rights reserved.</p>
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>System URL: " . EmailConfig::SYSTEM_URL . "</p>
            <p><strong>This email was sent to: " . htmlspecialchars($recipientEmail) . "</strong></p>
        </div>
    </body>
    </html>
    ";
}
// CORRECTED Fallback function using native PHP mail
function sendWithNativePHP($recipientEmail, $recipientName, $role, $department, $defaultPassword) {
    error_log("=== NATIVE PHP MAIL DEBUG START ===");
    error_log("NATIVE PHP - Recipient: " . $recipientEmail);
   
    // VALIDATION: Ensure we have a valid recipient email
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("ERROR - Invalid recipient email in native PHP: " . $recipientEmail);
        return false;
    }
   
    $subject = "Invitation to " . EmailConfig::COMPANY_NAME . " System";
    $message = createEmailTemplate($recipientName, $recipientEmail, $role, $department, $defaultPassword);
    // CRITICAL: Proper email headers with explicit To header
    $headers = array(
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . EmailConfig::FROM_NAME . ' <' . EmailConfig::FROM_EMAIL . '>',
        'To: ' . $recipientName . ' <' . $recipientEmail . '>',
        'Reply-To: ' . EmailConfig::SUPPORT_EMAIL,
        'X-Mailer: PHP/' . phpversion(),
        'X-Priority: 1',
        'X-Recipient-Email: ' . $recipientEmail,
        'X-Recipient-Name: ' . $recipientName,
        'Return-Path: ' . EmailConfig::FROM_EMAIL
    );
    $headerString = implode("\r\n", $headers);
   
    error_log("NATIVE PHP MAIL HEADERS: " . $headerString);
    error_log("NATIVE PHP MAIL TO: " . $recipientEmail);
    error_log("NATIVE PHP MAIL SUBJECT: " . $subject);
    // Try to send email
    $result = mail($recipientEmail, $subject, $message, $headerString);
    if ($result) {
        error_log("SUCCESS - Email sent successfully using native PHP mail to: " . $recipientEmail);
        error_log("=== NATIVE PHP MAIL DEBUG END (SUCCESS) ===");
        return true;
    } else {
        error_log("FAILED - Failed to send email using native PHP mail to: " . $recipientEmail);
        error_log("=== NATIVE PHP MAIL DEBUG END (FAILED) ===");
       
        // As last resort, save to file with clear instructions
        $emailsDir = __DIR__ . '/emails';
        if (!file_exists($emailsDir)) {
            mkdir($emailsDir, 0755, true);
        }
       
        $filename = $emailsDir . '/URGENT_SEND_MANUALLY_' . date('Y-m-d_H-i-s') . '_' . str_replace(['@', '.'], ['_at_', '_'], $recipientEmail) . '.html';
       
        $emailContent = " URGENT: MANUAL SENDING REQUIRED \n";
        $emailContent .= " TO: " . $recipientEmail . " \n";
        $emailContent .= " SUBJECT: " . $subject . " \n";
        $emailContent .= " CREDENTIALS: " . $recipientEmail . " / " . $defaultPassword . " \n";
        $emailContent .= " COPY THE CONTENT BELOW AND SEND VIA YOUR EMAIL CLIENT \n\n";
        $emailContent .= $message;
       
        if (file_put_contents($filename, $emailContent)) {
            error_log("Email saved for manual sending: " . $filename);
            return 'manual_required';
        }
       
        return false;
    }
}
// First, let's check if is_active column exists and add it if it doesn't
try {
    $check_column = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if ($check_column->rowCount() == 0) {
        // Add is_active column if it doesn't exist
        $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        echo "<script>console.log('Added is_active column to users table');</script>";
    }
} catch (Exception $e) {
    error_log("Column check error: " . $e->getMessage());
}
// --- Data Fetching for User Management (Define queries first) ---
// Get all users (excluding current admin)
$users_query = "SELECT id, username, email, full_name, role, department, phone, is_active, created_at, updated_at, status, area_of_responsibility, assigned_risk_owner_id FROM users WHERE id != :current_user_id ORDER BY created_at DESC";
$users_stmt = $db->prepare($users_query);
$users_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
// Get Deleted Users from the deleted_users table
$deleted_users_query = "SELECT * FROM deleted_users ORDER BY deleted_at DESC";
$deleted_users_stmt = $db->prepare($deleted_users_query);
// Execute initial queries
$users_stmt->execute();
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
$deleted_users_stmt->execute();
$deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
// Get current user info for header
$user_info = getCurrentUser();
// --- PHP Logic for User Management Features ---
// CRITICAL FIX: Handle individual "Send Invitation" button clicks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invitation'])) {
    $userId = $_POST['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
   
    error_log("=== INDIVIDUAL SEND INVITATION REQUEST START ===");
    error_log("Requested User ID: " . $userId);
   
    // Fetch the user from the database using the ID
    $query = "SELECT id, full_name, email, role, department FROM users WHERE id = :id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
   
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
   
    error_log("DATABASE QUERY RESULT for User ID $userId: " . print_r($user, true));
   
    if ($user) {
        // Generate a secure random password
        $defaultPassword = 'Welcome@' . date('Y') . rand(100, 999);
        $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);
       
        // Update the user's password in the database
        $updateQuery = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':password', $hashedPassword);
        $updateStmt->bindParam(':id', $userId, PDO::PARAM_INT);
       
        if ($updateStmt->execute()) {
            error_log("Password updated successfully for User ID: " . $userId);
           
            // Extract user details
            $recipientEmail = trim($user['email']);
            $recipientName = trim($user['full_name']);
            $role = trim($user['role']);
            $department = trim($user['department'] ?? 'General');
           
            error_log("INDIVIDUAL INVITATION - CLEANED EMAIL DATA:");
            error_log("- Email: '" . $recipientEmail . "'");
            error_log("- Name: '" . $recipientName . "'");
            error_log("- Role: '" . $role . "'");
            error_log("- Department: '" . $department . "'");
            error_log("- Password: '" . $defaultPassword . "'");
           
            // Now send the invitation
            $emailSent = sendInvitationEmail($recipientEmail, $recipientName, $role, $department, $defaultPassword);
           
            if ($emailSent === true) {
                $success_message = "✅ Invitation sent to {$recipientEmail} successfully! Password: {$defaultPassword}";
                error_log("SUCCESS: Individual invitation sent to " . $recipientEmail);
               
                // Log successful invitation
                $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $db->prepare($log_query);
                $log_stmt->execute([
                    $_SESSION['user_email'] ?? 'Admin',
                    'Individual Invitation Sent',
                    'Invitation sent to ' . $recipientEmail . ' (User ID: ' . $userId . ') with new credentials',
                    $ip_address,
                    $_SESSION['user_id']
                ]);
            } elseif ($emailSent === 'manual_required') {
                $success_message = "⚠️ Email server unavailable. Invitation saved to 'emails' folder for manual sending to {$recipientEmail}. Password: {$defaultPassword}";
            } else {
                $error_message = "❌ Failed to send invitation to {$recipientEmail}. Please check your email configuration and server logs.";
                error_log("ERROR: Failed to send individual invitation to " . $recipientEmail);
            }
        } else {
            $error_message = "❌ Failed to update password for user. Please try again.";
            error_log("ERROR: Failed to update password for User ID: " . $userId);
        }
    } else {
        $error_message = "❌ User not found with ID: {$userId}";
        error_log("ERROR: User not found for ID: " . $userId);
    }
   
    error_log("=== INDIVIDUAL SEND INVITATION REQUEST END ===");
}
// Handle Send Invitation to Existing User - CORRECTED WITH PROPER DATABASE FETCH
if ($_POST && isset($_POST['send_invitation']) && !isset($_POST['user_id'])) {
    // This is for the old bulk invitation system - keeping for backward compatibility
    $user_id = $_POST['user_id'] ?? null;
    if ($user_id) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        error_log("=== LEGACY SINGLE INVITATION REQUEST START ===");
        error_log("Requested User ID: " . $user_id);
        // CORRECTED: Get user details FROM DATABASE using proper SELECT query
        $user_query = "SELECT id, full_name, email, role, department FROM users WHERE id = :user_id LIMIT 1";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $user_stmt->execute();
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
        // DEBUG: Log the fetched user data
        error_log("DATABASE QUERY RESULT for User ID $user_id: " . print_r($user_data, true));
        if ($user_data && !empty($user_data['email'])) {
            // Generate a new default password for the invitation
            $default_password = 'Welcome@' . date('Y');
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
           
            // Update user's password with the new default password
            $update_password_query = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id";
            $update_stmt = $db->prepare($update_password_query);
            $update_stmt->bindParam(':password', $hashed_password);
            $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
           
            if ($update_stmt->execute()) {
                error_log("Password updated successfully for User ID: " . $user_id);
               
                // CRITICAL: Clean and validate email before sending
                $clean_recipient_email = trim($user_data['email']);
                $clean_recipient_name = trim($user_data['full_name']);
                $clean_role = trim($user_data['role']);
                $clean_department = trim($user_data['department'] ?? 'General');
               
                error_log("CLEANED EMAIL DATA:");
                error_log("- Email: '" . $clean_recipient_email . "'");
                error_log("- Name: '" . $clean_recipient_name . "'");
                error_log("- Role: '" . $clean_role . "'");
                error_log("- Department: '" . $clean_department . "'");
               
                // Send invitation email - USING CLEANED DATABASE EMAIL
                $emailSent = sendInvitationEmail(
                    $clean_recipient_email,
                    $clean_recipient_name,
                    $clean_role,
                    $clean_department,
                    $default_password
                );
               
                if ($emailSent === true) {
                    $success_message = "✅ Invitation email sent successfully to " . $clean_recipient_email . " (User ID: $user_id) via Gmail SMTP!";
                    error_log("SUCCESS MESSAGE: " . $success_message);
                } elseif ($emailSent === 'manual_required') {
                    $success_message = "⚠️ Email server unavailable. Invitation saved to 'emails' folder for manual sending to " . $clean_recipient_email . ". Please check the folder and send manually.";
                } else {
                    $error_message = "❌ Failed to send invitation email to " . $clean_recipient_email . ". Please check your email configuration and server logs.";
                    error_log("ERROR MESSAGE: " . $error_message);
                }
               
                // Log successful invitation
                $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $db->prepare($log_query);
                $log_stmt->execute([
                    $_SESSION['user_email'] ?? 'Admin',
                    'Invitation Created',
                    'Invitation created for ' . $clean_recipient_email . ' (User ID: ' . $user_id . ') with new credentials',
                    $ip_address,
                    $_SESSION['user_id']
                ]);
            } else {
                $error_message = "Failed to update user password for invitation.";
                error_log("ERROR: Failed to update password for User ID: " . $user_id);
            }
        } else {
            $error_message = "User not found or email is empty for User ID: $user_id";
            error_log("ERROR - User data issue for ID $user_id: " . print_r($user_data, true));
        }
       
        error_log("=== LEGACY SINGLE INVITATION REQUEST END ===");
    }
}
// Handle Bulk User Actions - CORRECTED WITH PROPER DATABASE FETCH
if ($_POST && isset($_POST['bulk_action']) && !empty($_POST['bulk_action']) && isset($_POST['selected_users']) && !empty($_POST['selected_users'])) {
    $action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    error_log("=== BULK ACTION REQUEST START ===");
    error_log("Bulk Action: " . $action);
    error_log("Selected Users: " . implode(',', $selected_users));
    try {
        $db->beginTransaction();
        $success_count = 0;
        $failed_count = 0;
       
        foreach ($selected_users as $user_id) {
            $query = "";
            $log_action_type = 'Bulk User Action';
            $log_details = "";
           
            // CORRECTED: Fetch user's data from database using proper SELECT query
            $user_email_query = "SELECT id, email, full_name, role, department FROM users WHERE id = :user_id LIMIT 1";
            $user_email_stmt = $db->prepare($user_email_query);
            $user_email_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $user_email_stmt->execute();
            $user_data = $user_email_stmt->fetch(PDO::FETCH_ASSOC);
           
            error_log("BULK ACTION - User data for ID $user_id: " . print_r($user_data, true));
           
            $user_email = $user_data['email'] ?? 'Unknown';
            $user_name = $user_data['full_name'] ?? 'Unknown';
            $user_role = $user_data['role'] ?? 'staff';
            $user_department = $user_data['department'] ?? 'General';
            $user_email_detail = " (Email: " . $user_email . ", Name: " . $user_name . ")";
           
            switch ($action) {
                case 'approve':
                    $query = "UPDATE users SET status = 'approved', is_active = 1, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'User Approved';
                    $log_details = "Approved user ID: " . $user_id . $user_email_detail;
                    break;
                case 'suspend':
                    $query = "UPDATE users SET status = 'suspended', is_active = 0, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'User Suspended';
                    $log_details = "Suspended user ID: " . $user_id . $user_email_detail;
                    break;
                case 'delete_soft':
                    // Move user to deleted_users table and delete from users table
                    $move_query = "INSERT INTO deleted_users (original_user_id, full_name, email, username, password, role, department, status, is_active, original_created_at, original_updated_at, deleted_by)
                                    SELECT id, full_name, email, username, password, role, department, status, is_active, created_at, updated_at, ?
                                    FROM users WHERE id = ?";
                    $move_stmt = $db->prepare($move_query);
                    $move_stmt->bindParam(1, $_SESSION['user_id']);
                    $move_stmt->bindParam(2, $user_id);
                   
                    if ($move_stmt->execute()) {
                        // Now delete from users table
                        $delete_query = "DELETE FROM users WHERE id = ?";
                        $delete_stmt = $db->prepare($delete_query);
                        $delete_stmt->bindParam(1, $user_id);
                       
                        if ($delete_stmt->execute() && $delete_stmt->rowCount() > 0) {
                            $success_count++;
                            error_log("Successfully moved user ID: $user_id to recycle bin");
                           
                            // Log to system_audit_logs
                            $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                            $log_stmt = $db->prepare($log_query);
                            $log_stmt->execute([
                                $_SESSION['user_email'] ?? 'Admin',
                                'User Moved to Recycle Bin',
                                'Moved to recycle bin user ID: ' . $user_id . $user_email_detail,
                                $ip_address,
                                $_SESSION['user_id']
                            ]);
                        } else {
                            $failed_count++;
                            error_log("Failed to delete user ID: $user_id from users table");
                        }
                    } else {
                        $failed_count++;
                        error_log("Failed to move user ID: $user_id to recycle bin");
                    }
                    continue 2;
                case 'delete_permanent':
                    $query = "DELETE FROM users WHERE id = ?";
                    $log_action_type = 'User Permanently Deleted';
                    $log_details = "Permanently deleted user ID: " . $user_id . $user_email_detail;
                    break;
                case 'reset_password':
                    $new_password = password_hash('newdefaultpassword', PASSWORD_DEFAULT);
                    $query = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $new_password);
                    $stmt->bindParam(2, $user_id);
                    if ($stmt->execute() && $stmt->rowCount() > 0) {
                        $success_count++;
                        // Log to system_audit_logs
                        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                        $log_stmt = $db->prepare($log_query);
                        $log_stmt->execute([
                            $_SESSION['user_email'] ?? 'Admin',
                            'Password Reset',
                            'Password reset for user ID: ' . $user_id . $user_email_detail,
                            $ip_address,
                            $_SESSION['user_id']
                        ]);
                    } else {
                        $failed_count++;
                    }
                    continue 2;
                case 'change_role_admin':
                    $query = "UPDATE users SET role = 'admin', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Admin for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'change_role_risk_owner':
                    $query = "UPDATE users SET role = 'risk_owner', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Risk Owner for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'change_role_staff':
                    $query = "UPDATE users SET role = 'staff', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Staff for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'change_role_compliance':
                    $query = "UPDATE users SET role = 'compliance_team', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Compliance for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'enable_account':
                    $query = "UPDATE users SET status = 'approved', is_active = 1, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Account Enabled';
                    $log_details = "Enabled account for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'disable_account':
                    $query = "UPDATE users SET status = 'suspended', is_active = 0, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Account Disabled';
                    $log_details = "Disabled account for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'approve_and_invite':
                    error_log("APPROVE_AND_INVITE for User ID: " . $user_id);
                   
                    // First approve the user
                    $approve_query = "UPDATE users SET status = 'approved', is_active = 1, updated_at = NOW() WHERE id = ?";
                    $approve_stmt = $db->prepare($approve_query);
                    $approve_stmt->bindParam(1, $user_id);
                   
                    if ($approve_stmt->execute() && $approve_stmt->rowCount() > 0) {
                        error_log("User approved successfully: " . $user_id);
                       
                        // Generate a new default password for the invitation
                        $default_password = 'Welcome@' . date('Y') . rand(100, 999);
                        $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
                       
                        // Update user's password with the new default password
                        $update_password_query = "UPDATE users SET password = ? WHERE id = ?";
                        $update_stmt = $db->prepare($update_password_query);
                        $update_stmt->bindParam(1, $hashed_password);
                        $update_stmt->bindParam(2, $user_id);
                       
                        if ($update_stmt->execute()) {
                            error_log("Password updated successfully for User ID: " . $user_id);
                           
                            // CORRECTED: Get fresh user data from database for email using proper SELECT
                            $fresh_user_query = "SELECT id, email, full_name, role, department FROM users WHERE id = ? LIMIT 1";
                            $fresh_user_stmt = $db->prepare($fresh_user_query);
                            $fresh_user_stmt->bindParam(1, $user_id, PDO::PARAM_INT);
                            $fresh_user_stmt->execute();
                            $fresh_user_data = $fresh_user_stmt->fetch(PDO::FETCH_ASSOC);
                           
                            error_log("FRESH USER DATA for invitation (User ID $user_id): " . print_r($fresh_user_data, true));
                           
                            if ($fresh_user_data && !empty($fresh_user_data['email'])) {
                                // CRITICAL: Clean and validate email before sending
                                $clean_recipient_email = trim($fresh_user_data['email']);
                                $clean_recipient_name = trim($fresh_user_data['full_name']);
                                $clean_role = trim($fresh_user_data['role']);
                                $clean_department = trim($fresh_user_data['department'] ?? 'General');
                               
                                error_log("BULK INVITE - CLEANED EMAIL DATA for User ID $user_id:");
                                error_log("- Email: '" . $clean_recipient_email . "'");
                                error_log("- Name: '" . $clean_recipient_name . "'");
                               
                                // Send invitation email using fresh database data
                                $emailSent = sendInvitationEmail(
                                    $clean_recipient_email,
                                    $clean_recipient_name,
                                    $clean_role,
                                    $clean_department,
                                    $default_password
                                );
                               
                                if ($emailSent) {
                                    $success_count++;
                                    $log_action_type = 'User Approved & Invited';
                                    $log_details = "Approved and created invitation for user ID: " . $user_id . " (Email: " . $clean_recipient_email . ", Name: " . $clean_recipient_name . ")";
                                    error_log("SUCCESS: Email sent for User ID: " . $user_id . " to " . $clean_recipient_email);
                                } else {
                                    $failed_count++;
                                    $log_action_type = 'User Approved (Invitation Failed)';
                                    $log_details = "Approved user ID: " . $user_id . " but failed to create invitation (Email: " . $clean_recipient_email . ", Name: " . $clean_recipient_name . ")";
                                    error_log("FAILED: Email not sent for User ID: " . $user_id . " to " . $clean_recipient_email);
                                }
                            } else {
                                $failed_count++;
                                $log_action_type = 'User Approved (No Email Found)';
                                $log_details = "Approved user ID: " . $user_id . " but no valid email found in database";
                                error_log("ERROR - No valid email found for user ID: " . $user_id);
                            }
                        } else {
                            $failed_count++;
                            $log_action_type = 'User Approved (Password Update Failed)';
                            $log_details = "Approved user ID: " . $user_id . " but failed to update password" . $user_email_detail;
                            error_log("ERROR: Failed to update password for User ID: " . $user_id);
                        }
                    } else {
                        $failed_count++;
                        $log_action_type = 'User Approval Failed';
                        $log_details = "Failed to approve user ID: " . $user_id . $user_email_detail;
                        error_log("ERROR: Failed to approve User ID: " . $user_id);
                    }
                   
                    // Log the action
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        $log_action_type,
                        $log_details,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                    continue 2;
            }
           
            if (!empty($query)) {
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $user_id);
               
                if ($stmt->execute()) {
                    $affected_rows = $stmt->rowCount();
                    if ($affected_rows > 0) {
                        $success_count++;
                        error_log("Successfully executed action '$action' for user ID: $user_id");
                       
                        // Log to system_audit_logs
                        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                        $log_stmt = $db->prepare($log_query);
                        $log_stmt->execute([
                            $_SESSION['user_email'] ?? 'Admin',
                            $log_action_type,
                            $log_details,
                            $ip_address,
                            $_SESSION['user_id']
                        ]);
                    } else {
                        $failed_count++;
                        error_log("Action '$action' for user ID: $user_id executed but no rows affected");
                    }
                } else {
                    $failed_count++;
                    $error_info = $stmt->errorInfo();
                    error_log("Failed to execute action '$action' for user ID: $user_id. Error: " . implode(' - ', $error_info));
                }
            }
        }
       
        $db->commit();
        
        // if ($success_count > 0) {
        //     $success_message = "Bulk action '{$action}' completed successfully for {$success_count} users.";
        //     if ($failed_count > 0) {
        //         $success_message .= " {$failed_count} users failed to update.";
        //     }
        // } else {
        //     $error_message = "Bulk action '{$action}' failed for all selected users.";
        // }
        
        // FORCE REFRESH DATA after bulk actions
        $users_stmt->execute();
        $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $deleted_users_stmt->execute();
        $deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("After bulk action - Active users: " . count($all_users) . ", Deleted users: " . count($deleted_users));
       
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Database error during bulk action: " . $e->getMessage();
        error_log("Bulk action error: " . $e->getMessage());
    }
   
    error_log("=== BULK ACTION REQUEST END ===");
}
// Handle individual user actions (reset password, change role, toggle status)
if ($_POST && isset($_POST['single_action_user_id'])) {
    $user_id = $_POST['single_action_user_id'];
    $action_type = $_POST['single_action_type'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    // CORRECTED: Fetch user's email from database using proper SELECT query
    $user_email_query = "SELECT email FROM users WHERE id = :user_id LIMIT 1";
    $user_email_stmt = $db->prepare($user_email_query);
    $user_email_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $user_email_stmt->execute();
    $user_email = $user_email_stmt->fetchColumn();
    $user_email_detail = $user_email ? " (Email: " . $user_email . ")" : "";
    try {
        switch ($action_type) {
            case 'reset_password':
                $new_password = password_hash('newdefaultpassword', PASSWORD_DEFAULT);
                $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':password', $new_password);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Password reset for user ID: {$user_id}. New password: 'newdefaultpassword'.";
                    // Log to system_audit_logs
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        'Password Reset',
                        'Password reset for user ID: ' . $user_id . $user_email_detail,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = "Failed to reset password for user ID: {$user_id}.";
                }
                break;
            case 'change_role':
                $new_role = $_POST['new_role'];
                $query = "UPDATE users SET role = :role, updated_at = NOW() WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':role', $new_role);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Role changed to '{$new_role}' for user ID: {$user_id}.";
                    // Log to system_audit_logs
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        'Role Changed',
                        'Role changed to ' . $new_role . ' for user ID: ' . $user_id . $user_email_detail,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = "Failed to change role for user ID: {$user_id}.";
                }
                break;
            case 'toggle_status':
                $current_status_query = "SELECT status FROM users WHERE id = :user_id";
                $current_status_stmt = $db->prepare($current_status_query);
                $current_status_stmt->bindParam(':user_id', $user_id);
                $current_status_stmt->execute();
                $current_status = $current_status_stmt->fetchColumn();
               
                $new_status = ($current_status === 'approved') ? 'suspended' : 'approved';
                $new_is_active = ($new_status === 'approved') ? 1 : 0;
               
                $query = "UPDATE users SET status = :status, is_active = :is_active, updated_at = NOW() WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $new_status);
                $stmt->bindParam(':is_active', $new_is_active);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Account status changed to '{$new_status}' for user ID: {$user_id}.";
                    // Log to system_audit_logs
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        'Account Status Changed',
                        'Account status changed to ' . $new_status . ' for user ID: ' . $user_id . $user_email_detail,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = "Failed to change account status for user ID: {$user_id}.";
                }
                break;
        }
       
        // Refresh data after individual actions
        $users_stmt->execute();
        $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
       
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        error_log("Individual user action error: " . $e->getMessage());
    }
}
// Handle permanent delete from recycle bin
if ($_POST && isset($_POST['delete_permanent_user'])) {
    $deleted_user_id = $_POST['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    try {
        $db->beginTransaction();
       
        // Get user email and details for logging before deletion
        $user_email_query = "SELECT email, full_name, original_user_id FROM deleted_users WHERE id = :deleted_user_id";
        $user_email_stmt = $db->prepare($user_email_query);
        $user_email_stmt->bindParam(':deleted_user_id', $deleted_user_id);
        $user_email_stmt->execute();
        $user_data = $user_email_stmt->fetch(PDO::FETCH_ASSOC);
        $user_email_detail = $user_data ? " (Email: " . $user_data['email'] . ", Name: " . $user_data['full_name'] . ")" : "";
       
        // Delete from deleted_users table (recycle bin)
        $delete_from_recycle_query = "DELETE FROM deleted_users WHERE id = :deleted_user_id";
        $delete_recycle_stmt = $db->prepare($delete_from_recycle_query);
        $delete_recycle_stmt->bindParam(':deleted_user_id', $deleted_user_id);
       
        if ($delete_recycle_stmt->execute()) {
            $db->commit();
            $success_message = "User permanently deleted from the system! The email address can now be re-registered.";
           
            // Log the permanent deletion
            $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->execute([
                $_SESSION['user_email'] ?? 'Admin',
                'User Permanently Deleted',
                'User ID: ' . $deleted_user_id . $user_email_detail . ' permanently deleted from system.',
                $ip_address,
                $_SESSION['user_id']
            ]);
           
            // Refresh deleted users data
            $deleted_users_stmt->execute();
            $deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
           
        } else {
            $db->rollBack();
            $error_message = "Failed to permanently delete user from recycle bin.";
        }
       
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Database error during permanent deletion: " . $e->getMessage();
        error_log("Permanent deletion error: " . $e->getMessage());
    }
}
// Handle restore from recycle bin
if ($_POST && isset($_POST['restore_user'])) {
    $deleted_user_id = $_POST['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    // Get user data from deleted_users table
    $get_user_query = "SELECT * FROM deleted_users WHERE id = :deleted_user_id";
    $get_user_stmt = $db->prepare($get_user_query);
    $get_user_stmt->bindParam(':deleted_user_id', $deleted_user_id);
    $get_user_stmt->execute();
    $deleted_user = $get_user_stmt->fetch(PDO::FETCH_ASSOC);
    if ($deleted_user) {
        $user_email_detail = " (Email: " . $deleted_user['email'] . ", Name: " . $deleted_user['full_name'] . ")";
       
        // Check if email already exists in users table (prevent duplicates)
        $check_email_query = "SELECT COUNT(*) FROM users WHERE email = :email";
        $check_email_stmt = $db->prepare($check_email_query);
        $check_email_stmt->bindParam(':email', $deleted_user['email']);
        $check_email_stmt->execute();
        $email_exists = $check_email_stmt->fetchColumn();
       
        if ($email_exists > 0) {
            $error_message = "Cannot restore user: Email address already exists in the system.";
        } else {
            // Insert back into users table
            $restore_query = "INSERT INTO users (full_name, email, username, password, role, department, status, is_active, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, ?, NOW())";
            $restore_stmt = $db->prepare($restore_query);
           
            if ($restore_stmt->execute([
                $deleted_user['full_name'],
                $deleted_user['email'],
                $deleted_user['username'],
                $deleted_user['password'],
                $deleted_user['role'],
                $deleted_user['department'],
                $deleted_user['original_created_at']
            ])) {
                // Remove from deleted_users table
                $remove_query = "DELETE FROM deleted_users WHERE id = :deleted_user_id";
                $remove_stmt = $db->prepare($remove_query);
                $remove_stmt->bindParam(':deleted_user_id', $deleted_user_id);
                $remove_stmt->execute();
               
                $success_message = "User restored successfully (status set to pending)!";
                // Log to system_audit_logs
                $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                $log_stmt = $db->prepare($log_query);
                $log_stmt->execute([
                    $_SESSION['user_email'] ?? 'Admin',
                    'User Restored',
                    'User ID: ' . $deleted_user_id . $user_email_detail . ' restored from recycle bin.',
                    $ip_address,
                    $_SESSION['user_id']
                ]);
               
                // Refresh both user lists after restore
                $users_stmt->execute();
                $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
               
                $deleted_users_stmt->execute();
                $deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $error_message = "Failed to restore user to users table.";
            }
        }
    } else {
        $error_message = "Failed to restore user - user not found in recycle bin.";
    }
}
// DEBUG: Final logging
error_log("DEBUG - Total active users: " . count($all_users));
error_log("DEBUG - Total deleted users: " . count($deleted_users));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Airtel Risk Management</title>
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
       
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
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
       
        /* Added navigation buttons styling */
        .section-nav {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .section-nav-btn {
            background: white;
            color: #E60012;
            border: 2px solid #E60012;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            min-width: 200px;
            justify-content: center;
        }
        
        .section-nav-btn:hover {
            background: #E60012;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .section-nav-btn.active {
            background: #E60012;
            color: white;
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .section-content {
            display: none;
        }
        
        .section-content.active {
            display: block;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
       
        .user-table th, .user-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
       
        .user-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
       
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
       
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        .status-deleted { background: #f8d7da; color: #721c24; }
       
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
       
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
       
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
       
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
       
        .btn-success {
            background: #28a745;
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
       
        .btn-primary {
            background: #007bff;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
       
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
       
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
       
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
       
        .search-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 1rem;
        }
       
        .bulk-actions-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: center;
        }
       
        .bulk-actions-container select, .bulk-actions-container button {
            padding: 0.75rem 1rem;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
       
        .bulk-actions-container select {
            flex-grow: 1;
        }
       
        .sections-container {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            margin-bottom: 2rem;
            width: 100%;
            max-width: none;
        }
       
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #e1e5e9;
            width: 100%;
        }
       
        .section-header {
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            padding: 1.5rem 2rem;
            position: relative;
        }
       
        .section-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
        }
       
        .section-content {
            padding: 2rem;
        }
       
        .table-container {
            overflow-x: auto;
            margin: 1rem 0;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
            width: 100%;
        }
       
        .subsection {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid #f1f3f4;
        }
       
        .subsection h3 {
            color: #333;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
       
        .subsection h3 i {
            color: #E60012;
        }
       
        .subsection p {
            color: #666;
            margin-bottom: 1rem;
        }
       
        .invite-form {
            margin-bottom: 1.5rem;
        }
       
        .form-group {
            margin-bottom: 1.5rem;
        }
       
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
            font-size: 0.95rem;
        }
       
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
       
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
       
        .btn-full {
            width: 100%;
            justify-content: center;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
       
        .stats-mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
       
        .mini-stat {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #E60012;
        }
       
        .mini-stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #E60012;
            margin-bottom: 0.25rem;
        }
       
        .mini-stat-label {
            font-size: 0.85rem;
            color: #666;
            font-weight: 500;
        }
       
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
       
        .settings-list {
            margin-bottom: 1.5rem;
        }
       
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f3f4;
        }
       
        .setting-item:last-child {
            border-bottom: none;
        }
       
        .setting-label {
            font-weight: 500;
            color: #333;
        }
       
        .setting-value {
            color: #666;
            font-weight: 500;
        }
       
        .info-box {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
       
        .info-box i {
            color: #17a2b8;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }
       
        .info-box p {
            margin: 0;
            font-size: 0.9rem;
            color: #495057;
            line-height: 1.4;
        }
       
        .view-toggle-btn {
            text-align: center;
            margin-bottom: 1rem;
        }
       
        .debug-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-family: monospace;
            font-size: 0.9rem;
        }
        .register-link-container {
            background: #f8f9fa;
            border: 2px dashed #E60012;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
        }
        .register-link-container h3 {
            color: #E60012;
            margin-bottom: 1rem;
        }
        .register-link-container p {
            color: #666;
            margin-bottom: 1.5rem;
        }
        .btn-register {
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(230, 0, 18, 0.3);
            color: white;
            text-decoration: none;
        }
        .invitation-section {
            background: #fff5f5;
            border: 1px solid #ffcdd2;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
        }
        .invitation-section h4 {
            color: #E60012;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pending-users-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e1e5e9;
            border-radius: 6px;
        }
        .pending-user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #f1f3f4;
            background: white;
        }
        .pending-user-item:last-child {
            border-bottom: none;
        }
        .pending-user-info {
            flex-grow: 1;
        }
        .pending-user-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        .pending-user-details {
            font-size: 0.9rem;
            color: #666;
        }
        .pending-user-actions {
            display: flex;
            gap: 0.5rem;
        }
        .permanent-delete-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .permanent-delete-warning i {
            color: #856404;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }
        .permanent-delete-warning p {
            margin: 0;
            font-size: 0.9rem;
            color: #856404;
            line-height: 1.4;
        }
       
        /* Responsive adjustments */
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
           
            .logout-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
           
            .nav {
                top: 80px;
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
           
            .main-content {
                padding: 1rem 0.25rem;
            }
            .card {
                padding: 1.5rem;
            }
            .bulk-actions-container {
                flex-direction: column;
                align-items: stretch;
            }
            .bulk-actions-container select, .bulk-actions-container button {
                width: 100%;
            }
           
            .section-header {
                padding: 1.25rem 1.5rem;
            }
           
            .section-content {
                padding: 1.5rem;
            }
           
            .stats-mini-grid {
                grid-template-columns: 1fr;
            }
           
            body {
                padding-top: 140px;
            }
           
            .header-content {
                padding: 0 1rem;
            }
           
            .nav-content {
                padding: 0 1rem;
            }
        }
        @media (max-width: 480px) {
            .section-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
           
            .section-title h2 {
                font-size: 1.2rem;
            }
           
            .main-content {
                padding: 0.5rem 0.1rem;
            }
           
            .section-content {
                padding: 1rem;
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
                <h1 class="main-title">Airtel Risk Register System</h1>
                <p class="sub-title">Admin Management Dashboard</p>
            </div>
        </div>
        <div class="header-right">
            <div class="user-avatar"><?php echo isset($user_info['full_name']) ? strtoupper(substr($user_info['full_name'], 0, 1)) : 'A'; ?></div>
            <div class="user-details">
                <div class="user-email"><?php echo htmlspecialchars($user_info['email']); ?></div>
                <div class="user-role"><?php echo ucfirst($user_info['role']); ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
</header>
<nav class="nav">
    <div class="nav-content">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="admin_dashboard.php">
                    📊 Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="user_management.php" class="active">
                    👥 User Management
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_dashboard.php#database-tab" onclick="showDatabaseTab()">
                    🗄️ Database Management
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_dashboard.php#notifications-tab" onclick="showNotificationsTab()">
                    🔔 Notifications
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_dashboard.php#settings-tab" onclick="showSettingsTab()">
                    ⚙️ Settings
                </a>
            </li>
        </ul>
    </div>
</nav>
<div class="main-content">
    
    
    <?php /* 
    if (isset($success_message)): ?>
        <div class="success">✅ <?php echo $success_message; ?></div>
    <?php endif; ?>
    <?php if (isset($error_message)): ?>
        <div class="error">❌ <?php echo $error_message; ?></div>
    <?php endif;
    */ ?>

    
    <div class="card">
        
        <div class="section-nav">
            <button class="section-nav-btn active" onclick="showSection('all-users')">
                <i class="fas fa-users"></i>
                All Users (<?php echo count($all_users); ?>)
            </button>
            <button class="section-nav-btn" onclick="showSection('register-invite')">
                <i class="fas fa-user-plus"></i>
                Register & Invite User
            </button>
            <button class="section-nav-btn" onclick="showSection('session-management')">
                <i class="fas fa-clock"></i>
                Session Management (<?php echo $active_sessions_count; ?>)
            </button>
            <button class="section-nav-btn" onclick="showSection('account-locking')">
                <i class="fas fa-lock"></i>
                Account Locking (<?php echo $locked_accounts_count; ?>)
            </button>
        </div>

        
        <div id="all-users" class="section-content active">
            <div class="section-header" style="background: linear-gradient(135deg, #E60012 0%, #B8000E 100%); color: white; padding: 1.5rem 2rem; border-radius: 8px; margin-bottom: 2rem;">
                <div class="section-title">
                    <i class="fas fa-users"></i>
                    <h2 style="color: white; margin: 0;">All Users (<?php echo count($all_users); ?>)</h2>
                </div>
                <div class="section-description" style="color: rgba(255,255,255,0.9); margin-top: 0.5rem;">
                
                </div>
            </div>
            
            <?php if (count($all_users) > 5): ?>
                <div class="view-toggle-btn">
                    <button class="btn btn-primary" onclick="toggleAllUsers()" id="viewAllBtn">
                        View All Users (<?php echo count($all_users); ?>)
                    </button>
                </div>
            <?php endif; ?>
           
            <input type="text" id="userSearchInput" class="search-input" onkeyup="filterUserTable()" placeholder="Search for users by name, email, or department...">
           
            
            <form method="POST" id="bulkActionForm" onsubmit="return validateBulkAction();">
                <div class="bulk-actions-container">
                    <select name="bulk_action" id="bulkActionSelect" required>
                        <option value="">Select Action</option>
                        <option value="approve">Approve Selected</option>
                        <option value="suspend">Suspend Selected</option>
                        <option value="enable_account">Enable Selected</option>
                        <option value="disable_account">Disable Selected</option>
                        <option value="reset_password">Reset Password Selected</option>
                        <option value="change_role_admin">Change Role to Admin</option>
                        <option value="change_role_risk_owner">Change Role to Risk Owner</option>
                        <option value="change_role_staff">Change Role to Staff</option>
                        <option value="change_role_compliance">Change Role to Compliance</option>
                        <option value="delete_soft">Move to Recycle Bin</option>
                        <option value="approve_and_invite">Approve & Send Invitation</option>
                    </select>
                    <button type="submit" class="btn">Apply</button>
                </div>
               
                <div class="table-container">
                    <table class="user-table" id="userTable">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllUsers"></th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Account Status</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody">
                            <?php
                            $display_users = array_slice($all_users, 0, 5); // Show only first 5 users
                            if (count($display_users) > 0):
                                foreach ($display_users as $user):
                                    // Determine account status based on is_active and status
                                    $account_status = 'Active';
                                    if ($user['status'] === 'suspended' || (isset($user['is_active']) && !$user['is_active'])) {
                                        $account_status = 'Locked';
                                    }
                                ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="user-checkbox"></td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $account_status === 'Active' ? 'status-approved' : 'status-suspended'; ?>">
                                                <?php echo $account_status; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr><td colspan="6" style="text-align: center;">No active users found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            
            <script>
            const allUsersData = <?php echo json_encode($all_users); ?>;
            let showingAll = false;
            </script>
           
            
            <div class="subsection">
                <h3><i class="fas fa-trash-restore"></i> Recycle Bin (<?php echo count($deleted_users); ?>)</h3>
                
               
                <?php if (count($deleted_users) > 5): ?>
                <div class="view-toggle-btn">
                    <button class="btn btn-primary" onclick="toggleDeletedUsers()" id="viewAllDeletedBtn">
                        View All Deleted Users (<?php echo count($deleted_users); ?>)
                    </button>
                </div>
                <?php endif; ?>
               
                <div class="table-container">
                    <table class="user-table" id="deletedUserTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Deleted At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="deletedUserTableBody">
                            <?php
                            $display_deleted_users = array_slice($deleted_users, 0, 5);
                            if (count($display_deleted_users) > 0): ?>
                                <?php foreach ($display_deleted_users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></td>
                                        <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($user['deleted_at'])); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to restore <?php echo htmlspecialchars($user['full_name']); ?>?');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="restore_user" class="btn btn-info btn-sm">Restore</button>
                                            </form>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete <?php echo htmlspecialchars($user['full_name']); ?>? This will completely remove them from the database and allow their email to be re-registered. This action cannot be undone.');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="delete_permanent_user" class="btn btn-danger btn-sm">Permanent Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center;">Recycle bin is empty.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
               
                
                <script>
                const allDeletedUsersData = <?php echo json_encode($deleted_users); ?>;
                let showingAllDeleted = false;
                </script>
            </div>
        </div>
       
        
        <div id="register-invite" class="section-content">
            <div class="section-header" style="background: linear-gradient(135deg, #E60012 0%, #B8000E 100%); color: white; padding: 1.5rem 2rem; border-radius: 8px; margin-bottom: 2rem;">
                <div class="section-title">
                    <i class="fas fa-user-plus"></i>
                    <h2 style="color: white; margin: 0;">Register & Invite User</h2>
                </div>
                <div class="section-description" style="color: rgba(255,255,255,0.9); margin-top: 0.5rem;">
                    
                </div>
            </div>
            
            
            <div class="register-link-container">
                <h3><i class="fas fa-user-plus"></i> Step 1: Register New User</h3>
                <a href="register.php?return_to=user_management" class="btn-register">
                    <i class="fas fa-plus-circle"></i> Register New User
                </a>
            </div>
           
            
            <div class="invitation-section" style="background: #fff9e6; border-color: #ffd700;">
                <h4><i class="fas fa-user-check"></i> Step 1.5: Approve Pending Registrations</h4>
                <p>Review and approve newly registered users before sending invitations.</p>
               
                <?php
                $pending_users = array_filter($all_users, function($user) {
                    return $user['status'] === 'pending';
                });
                ?>
               
                <?php if (count($pending_users) > 0): ?>
                    <div class="pending-users-list">
                        <?php foreach ($pending_users as $user): ?>
                            <div class="pending-user-item">
                                <div class="pending-user-info">
                                    <div class="pending-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="pending-user-details">
                                        <?php echo htmlspecialchars($user['email']); ?> •
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?> •
                                        <?php echo htmlspecialchars($user['department']); ?> •
                                        <span class="status-badge status-pending">Pending Approval</span>
                                    </div>
                                </div>
                                <div class="pending-user-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Approve registration for <?php echo htmlspecialchars($user['full_name']); ?>?');">
                                        <input type="hidden" name="selected_users[]" value="<?php echo $user['id']; ?>">
                                        <input type="hidden" name="bulk_action" value="approve">
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Approve and send invitation to <?php echo htmlspecialchars($user['full_name']); ?>?');">
                                        <input type="hidden" name="selected_users[]" value="<?php echo $user['id']; ?>">
                                        <input type="hidden" name="bulk_action" value="approve_and_invite">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-user-check"></i> Approve & Invite
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <p>No pending registrations found. All registered users have been processed.</p>
                    </div>
                <?php endif; ?>
            </div>
           
            
            <div class="invitation-section">
                <h4><i class="fas fa-paper-plane"></i> Step 2: Send Invitations to Registered Users</h4>
                <p>Select registered users below to send them invitation emails with login credentials.</p>
               
                <?php
                // Get users who are approved but may need invitations sent
                $approved_users = array_filter($all_users, function($user) {
                    return $user['status'] === 'approved';
                });
                ?>
                <?php if (count($approved_users) > 0): ?>
                    <div class="pending-users-list">
                        <?php foreach ($approved_users as $user): ?>
                            <div class="pending-user-item">
                                <div class="pending-user-info">
                                    <div class="pending-user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="pending-user-details">
                                        <?php echo htmlspecialchars($user['email']); ?> •
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?> •
                                        <?php echo htmlspecialchars($user['department']); ?> •
                                        <span class="status-badge status-approved">Approved</span>
                                    </div>
                                </div>
                                <div class="pending-user-actions">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Send invitation email to <?php echo htmlspecialchars($user['full_name']); ?> at <?php echo htmlspecialchars($user['email']); ?>?');">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="send_invitation" class="btn btn-success btn-sm">
                                            <i class="fas fa-paper-plane"></i> Send Invitation
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <p>No approved users found. Register and approve users first, then return here to send invitations.</p>
                    </div>
                <?php endif; ?>
            </div>
           
            <div class="info-box">
                
            
            </div>
        </div>
       
        
        <div id="session-management" class="section-content">
            <div class="section-header" style="background: linear-gradient(135deg, #E60012 0%, #B8000E 100%); color: white; padding: 1.5rem 2rem; border-radius: 8px; margin-bottom: 2rem;">
                <div class="section-title">
                    <i class="fas fa-clock"></i>
                    <h2 style="color: white; margin: 0;">Session Management</h2>
                </div>
                <div class="section-description" style="color: rgba(255,255,255,0.9); margin-top: 0.5rem;">
                    Monitor and control active user sessions across the system
                </div>
            </div>
           
            <div class="stats-mini-grid">
                <div class="mini-stat">
                    <div class="mini-stat-number"><?php echo $active_sessions_count; ?></div>
                    <div class="mini-stat-label">Active Sessions</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-number"><?php echo $admin_sessions_count; ?></div>
                    <div class="mini-stat-label">Admin Sessions</div>
                </div>
            </div>
           
            <div class="action-buttons">
                <button class="btn btn-info btn-full" onclick="viewActiveSessions()">
                    <i class="fas fa-eye"></i> View Active Sessions
                </button>
                <button class="btn btn-warning btn-full" onclick="forceLogoutAll()">
                    <i class="fas fa-sign-out-alt"></i> Force Logout All Users
                </button>
                <button class="btn btn-primary btn-full" onclick="sessionSettings()">
                    <i class="fas fa-cog"></i> Session Settings
                </button>
            </div>
           
            <div class="info-box">
                <i class="fas fa-shield-alt"></i>
                <p>Session timeout is currently set to <?php echo getSecuritySetting($db, 'session_timeout', 30); ?> minutes of inactivity.</p>
            </div>
        </div>
       
        
        <div id="account-locking" class="section-content">
            <div class="section-header" style="background: linear-gradient(135deg, #E60012 0%, #B8000E 100%); color: white; padding: 1.5rem 2rem; border-radius: 8px; margin-bottom: 2rem;">
                <div class="section-title">
                    <i class="fas fa-lock"></i>
                    <h2 style="color: white; margin: 0;">Account Locking</h2>
                </div>
                <div class="section-description" style="color: rgba(255,255,255,0.9); margin-top: 0.5rem;">
                    Configure security policies for account lockouts and access control
                </div>
            </div>
           
            <div class="stats-mini-grid">
                <div class="mini-stat">
                    <div class="mini-stat-number"><?php echo $max_failed_attempts; ?></div>
                    <div class="mini-stat-label">Failed Attempts Limit</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-number"><?php echo $locked_accounts_count; ?></div>
                    <div class="mini-stat-label">Currently Locked</div>
                </div>
            </div>
           
            <div class="settings-list">
                <div class="setting-item">
                    <span class="setting-label">Max Failed Attempts:</span>
                    <span class="setting-value"><?php echo $max_failed_attempts; ?> attempts</span>
                </div>
                <div class="setting-item">
                    <span class="setting-label">Lockout Duration:</span>
                    <span class="setting-value"><?php echo $lockout_duration; ?> minutes</span>
                </div>
                <div class="setting-item">
                    <span class="setting-label">Auto-unlock:</span>
                    <span class="setting-value"><?php echo $auto_unlock ? 'Enabled' : 'Disabled'; ?></span>
                </div>
            </div>
           
            <div class="action-buttons">
                <button class="btn btn-primary btn-full" onclick="configureLocking()">
                    <i class="fas fa-cog"></i> Configure Policies
                </button>
                <button class="btn btn-warning btn-full" onclick="viewLockedAccounts()">
                    <i class="fas fa-user-lock"></i> View Locked Accounts
                </button>
                <button class="btn btn-success btn-full" onclick="unlockAllAccounts()">
                    <i class="fas fa-unlock"></i> Unlock All Accounts
                </button>
            </div>
           
            <div class="info-box">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Account lockout helps prevent brute force attacks and unauthorized access attempts.</p>
            </div>
        </div>
    </div>
</div>

<script>
    
    function showSection(sectionId) {
        
        const sections = document.querySelectorAll('.section-content');
        sections.forEach(section => {
            section.classList.remove('active');
        });
        
        
        const buttons = document.querySelectorAll('.section-nav-btn');
        buttons.forEach(button => {
            button.classList.remove('active');
        });
        
        
        document.getElementById(sectionId).classList.add('active');
        
        
        event.target.classList.add('active');
    }

    
    function validateBulkAction() {
        const selectedAction = document.getElementById('bulkActionSelect').value;
        const selectedUsers = document.querySelectorAll('.user-checkbox:checked');
       
        if (!selectedAction) {
            alert('Please select an action to perform.');
            return false;
        }
       
        if (selectedUsers.length === 0) {
            alert('Please select at least one user.');
            return false;
        }
       
        
        let confirmMessage = '';
        switch(selectedAction) {
            case 'delete_soft':
                confirmMessage = `Are you sure you want to move ${selectedUsers.length} user(s) to the recycle bin?`;
                break;
            case 'delete_permanent':
                confirmMessage = `Are you sure you want to PERMANENTLY delete ${selectedUsers.length} user(s)? This action cannot be undone.`;
                break;
            case 'reset_password':
                confirmMessage = `Are you sure you want to reset passwords for ${selectedUsers.length} user(s)?`;
                break;
            case 'approve_and_invite':
                confirmMessage = `Are you sure you want to approve and send invitation emails to ${selectedUsers.length} user(s)? Emails will be sent to their registered email addresses.`;
                break;
            default:
                confirmMessage = `Are you sure you want to perform this action on ${selectedUsers.length} user(s)?`;
        }
       
        if (confirm(confirmMessage)) {
            
            const submitBtn = document.querySelector('button[type="submit"]');
            submitBtn.textContent = 'Processing...';
            submitBtn.disabled = true;
            return true;
        }
        return false;
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const selectAllUsersCheckbox = document.getElementById('selectAllUsers');
        if (selectAllUsersCheckbox) {
            selectAllUsersCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.user-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = selectAllUsersCheckbox.checked;
                });
            });
        }
    });
    function filterUserTable() {
        const input = document.getElementById('userSearchInput');
        const filter = input.value.toLowerCase();
        const table = document.getElementById('userTable');
        const tr = table.getElementsByTagName('tr');
       
        for (let i = 1; i < tr.length; i++) {
            let found = false;
            const td = tr[i].getElementsByTagName('td');
            if (td[1] && td[1].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
            } else if (td[2] && td[2].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
            } else if (td[4] && td[4].textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
            }
            tr[i].style.display = found ? "" : "none";
        }
    }
    function toggleAllUsers() {
        const tbody = document.getElementById('userTableBody');
        const btn = document.getElementById('viewAllBtn');
       
        if (!showingAll) {
            
            tbody.innerHTML = '';
            allUsersData.forEach(user => {
                const row = createUserRow(user);
                tbody.appendChild(row);
            });
            btn.textContent = 'Show Less';
            showingAll = true;
        } else {
            
            tbody.innerHTML = '';
            allUsersData.slice(0, 5).forEach(user => {
                const row = createUserRow(user);
                tbody.appendChild(row);
            });
            btn.textContent = `View All Users (${allUsersData.length})`;
            showingAll = false;
        }
    }
    function createUserRow(user) {
        const row = document.createElement('tr');
        const accountStatus = (user.status === 'suspended' || (user.is_active !== undefined && !user.is_active)) ? 'Locked' : 'Active';
        const accountStatusClass = accountStatus === 'Active' ? 'status-approved' : 'status-suspended';
       
        row.innerHTML = `
            <td><input type="checkbox" name="selected_users[]" value="${user.id}" class="user-checkbox"></td>
            <td>${user.full_name}</td>
            <td>${user.email}</td>
            <td>${user.role.charAt(0).toUpperCase() + user.role.slice(1).replace('_', ' ')}</td>
            <td><span class="status-badge status-${user.status}">${user.status.charAt(0).toUpperCase() + user.status.slice(1)}</span></td>
            <td><span class="status-badge ${accountStatusClass}">${accountStatus}</span></td>
        `;
        return row;
    }
    function toggleDeletedUsers() {
        const tbody = document.getElementById('deletedUserTableBody');
        const btn = document.getElementById('viewAllDeletedBtn');
       
        if (!showingAllDeleted) {
            
            tbody.innerHTML = '';
            if (allDeletedUsersData.length > 0) {
                allDeletedUsersData.forEach(user => {
                    const row = createDeletedUserRow(user);
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Recycle bin is empty.</td></tr>';
            }
            btn.textContent = 'Show Less';
            showingAllDeleted = true;
        } else {
            
            tbody.innerHTML = '';
            const displayUsers = allDeletedUsersData.slice(0, 5);
            if (displayUsers.length > 0) {
                displayUsers.forEach(user => {
                    const row = createDeletedUserRow(user);
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Recycle bin is empty.</td></tr>';
            }
            btn.textContent = `View All Deleted Users (${allDeletedUsersData.length})`;
            showingAllDeleted = false;
        }
    }
    function createDeletedUserRow(user) {
        const row = document.createElement('tr');
        const deletedDate = new Date(user.deleted_at);
        const formattedDate = deletedDate.toLocaleDateString('en-US', {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
       
        row.innerHTML = `
            <td>${user.full_name}</td>
            <td>${user.email}</td>
            <td>${user.role.charAt(0).toUpperCase() + user.role.slice(1).replace('_', ' ')}</td>
            <td>${user.department || 'N/A'}</td>
            <td>${formattedDate}</td>
            <td>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to restore ${user.full_name}?');">
                    <input type="hidden" name="user_id" value="${user.id}">
                    <button type="submit" name="restore_user" class="btn btn-info btn-sm">Restore</button>
                </form>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete ${user.full_name}? This will completely remove them from the database and allow their email to be re-registered. This action cannot be undone.');">
                    <input type="hidden" name="user_id" value="${user.id}">
                    <button type="submit" name="delete_permanent_user" class="btn btn-danger btn-sm">Permanent Delete</button>
                </form>
            </td>
        `;
        return row;
    }
    
    function viewActiveSessions() {
        fetch('user_management.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=get_active_sessions'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayActiveSessionsModal(data.sessions);
            } else {
                alert('Failed to load active sessions');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading active sessions');
        });
    }
    function displayActiveSessionsModal(sessions) {
        let modalHtml = `
            <div id="sessionsModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 90%; max-height: 90%; overflow-y: auto; width: 800px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3>Active Sessions (${sessions.length})</h3>
                        <button onclick="closeModal('sessionsModal')" style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">×</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">User</th>
                                    <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Role</th>
                                    <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">IP Address</th>
                                    <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Login Time</th>
                                    <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Status</th>
                                    <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>`;
       
        sessions.forEach(session => {
            const loginTime = new Date(session.login_time).toLocaleString();
            const statusColor = session.status === 'Active' ? '#28a745' :
                               session.status === 'Expired' ? '#dc3545' : '#ffc107';
           
            modalHtml += `
                <tr>
                    <td style="padding: 0.75rem; border: 1px solid #ddd;">
                        <div><strong>${session.user_name}</strong></div>
                        <div style="font-size: 0.8rem; color: #666;">${session.user_email}</div>
                    </td>
                    <td style="padding: 0.75rem; border: 1px solid #ddd;">${session.user_role}</td>
                    <td style="padding: 0.75rem; border: 1px solid #ddd;">${session.ip_address}</td>
                    <td style="padding: 0.75rem; border: 1px solid #ddd;">${loginTime}</td>
                    <td style="padding: 0.75rem; border: 1px solid #ddd;">
                        <span style="background: ${statusColor}; color: white; padding: 0.25rem 0.5rem; border-radius: 3px; font-size: 0.8rem;">
                            ${session.status}
                        </span>
                        ${session.idle_minutes > 0 ? `<div style="font-size: 0.7rem; color: #666;">Idle: ${session.idle_minutes}m</div>` : ''}
                    </td>
                    <td style="padding: 0.75rem; border: 1px solid #ddd;">
                        ${session.status === 'Active' ?
                            `<button onclick="forceLogoutSession('${session.session_id}')" style="background: #dc3545; color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 3px; cursor: pointer; font-size: 0.8rem;">Force Logout</button>`
                            : '-'}
                    </td>
                </tr>`;
        });
       
        modalHtml += `
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 1rem; text-align: right;">
                        <button onclick="forceLogoutAll()" style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; margin-right: 0.5rem;">Force Logout All</button>
                        <button onclick="closeModal('sessionsModal')" style="background: #6c757d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">Close</button>
                    </div>
                </div>
            </div>`;
       
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    function forceLogoutSession(sessionId) {
        if (confirm('Are you sure you want to force logout this session?')) {
            fetch('user_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=force_logout&session_id=${sessionId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Session logged out successfully');
                    closeModal('sessionsModal');
                    viewActiveSessions(); 
                } else {
                    alert('Failed to logout session');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error logging out session');
            });
        }
    }
    function forceLogoutAll() {
        if (confirm('Are you sure you want to force logout ALL users? This will end all active sessions immediately.')) {
            fetch('user_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=force_logout_all'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('All users have been logged out successfully');
                    closeModal('sessionsModal');
                } else {
                    alert('Failed to logout all users');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error logging out all users');
            });
        }
    }
    function sessionSettings() {
        displaySessionSettingsModal();
    }
    function displaySessionSettingsModal() {
        let modalHtml = `
            <div id="sessionSettingsModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 500px; width: 90%;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3>Session Settings</h3>
                        <button onclick="closeModal('sessionSettingsModal')" style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">×</button>
                    </div>
                    <form id="sessionSettingsForm">
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Session Timeout (minutes):</label>
                            <input type="number" name="session_timeout" value="<?php echo getSecuritySetting($db, 'session_timeout', 30); ?>" min="5" max="480" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Max Concurrent Sessions:</label>
                            <input type="number" name="max_concurrent_sessions" value="<?php echo getSecuritySetting($db, 'max_concurrent_sessions', 3); ?>" min="1" max="10" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="text-align: right;">
                            <button type="button" onclick="saveSessionSettings()" style="background: #28a745; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; margin-right: 0.5rem;">Save Settings</button>
                            <button type="button" onclick="closeModal('sessionSettingsModal')" style="background: #6c757d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>`;
       
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    
    function viewLockedAccounts() {
        fetch('user_management.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=get_locked_accounts'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLockedAccountsModal(data.accounts);
            } else {
                alert('Failed to load locked accounts');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading locked accounts');
        });
    }
    function displayLockedAccountsModal(accounts) {
        let modalHtml = `
            <div id="lockedAccountsModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 90%; max-height: 90%; overflow-y: auto; width: 800px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3>Locked Accounts (${accounts.length})</h3>
                        <button onclick="closeModal('lockedAccountsModal')" style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">×</button>
                    </div>`;
       
        if (accounts.length === 0) {
            modalHtml += `
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-unlock" style="font-size: 3rem; margin-bottom: 1rem; color: #28a745;"></i>
                    <p>No accounts are currently locked.</p>
                </div>`;
        } else {
            modalHtml += `
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">User</th>
                                <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Failed Attempts</th>
                                <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Locked Time</th>
                                <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Remaining</th>
                                <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Reason</th>
                                <th style="padding: 0.75rem; border: 1px solid #ddd; text-align: left;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>`;
           
            accounts.forEach(account => {
                const lockedTime = new Date(account.locked_at).toLocaleString();
                const remainingMinutes = account.remaining_minutes > 0 ? account.remaining_minutes : 0;
               
                modalHtml += `
                    <tr>
                        <td style="padding: 0.75rem; border: 1px solid #ddd;">
                            <div><strong>${account.full_name || 'Unknown'}</strong></div>
                            <div style="font-size: 0.8rem; color: #666;">${account.user_email}</div>
                        </td>
                        <td style="padding: 0.75rem; border: 1px solid #ddd;">${account.failed_attempts}</td>
                        <td style="padding: 0.75rem; border: 1px solid #ddd;">${lockedTime}</td>
                        <td style="padding: 0.75rem; border: 1px solid #ddd;">
                            ${remainingMinutes > 0 ? `${remainingMinutes} minutes` :
                              account.locked_by_admin ? 'Manual unlock required' : 'Expired'}
                        </td>
                        <td style="padding: 0.75rem; border: 1px solid #ddd;">${account.lock_reason}</td>
                        <td style="padding: 0.75rem; border: 1px solid #ddd;">
                            <button onclick="unlockAccount('${account.user_email}')" style="background: #28a745; color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 3px; cursor: pointer; font-size: 0.8rem;">Unlock</button>
                        </td>
                    </tr>`;
            });
           
            modalHtml += `
                        </tbody>
                    </table>
                </div>`;
        }
       
        modalHtml += `
                    <div style="margin-top: 1rem; text-align: right;">
                        ${accounts.length > 0 ?
                            `<button onclick="unlockAllAccounts()" style="background: #28a745; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; margin-right: 0.5rem;">Unlock All</button>`
                            : ''}
                        <button onclick="closeModal('lockedAccountsModal')" style="background: #6c757d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">Close</button>
                    </div>
                </div>
            </div>`;
       
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    function unlockAccount(userEmail) {
        if (confirm(`Are you sure you want to unlock the account for ${userEmail}?`)) {
            fetch('user_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ajax_action=unlock_account&user_email=${encodeURIComponent(userEmail)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Account unlocked successfully');
                    closeModal('lockedAccountsModal');
                    viewLockedAccounts(); 
                } else {
                    alert('Failed to unlock account');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error unlocking account');
            });
        }
    }
    function unlockAllAccounts() {
        if (confirm('Are you sure you want to unlock ALL currently locked accounts?')) {
            fetch('user_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ajax_action=unlock_all_accounts'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('All locked accounts have been unlocked successfully');
                    closeModal('lockedAccountsModal');
                } else {
                    alert('Failed to unlock all accounts');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error unlocking all accounts');
            });
        }
    }
    function configureLocking() {
        displayLockingConfigModal();
    }
    function displayLockingConfigModal() {
        let modalHtml = `
            <div id="lockingConfigModal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                <div style="background: white; padding: 2rem; border-radius: 10px; max-width: 500px; width: 90%;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3>Account Locking Configuration</h3>
                        <button onclick="closeModal('lockingConfigModal')" style="background: #dc3545; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">×</button>
                    </div>
                    <form id="lockingConfigForm">
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Max Failed Attempts:</label>
                            <input type="number" name="max_failed_attempts" value="<?php echo $max_failed_attempts; ?>" min="3" max="20" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Lockout Duration (minutes):</label>
                            <input type="number" name="lockout_duration" value="<?php echo $lockout_duration; ?>" min="5" max="1440" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label style="display: flex; align-items: center; font-weight: 500;">
                                <input type="checkbox" name="auto_unlock" value="1" <?php echo $auto_unlock ? 'checked' : ''; ?> style="margin-right: 0.5rem;">
                                Auto-unlock after lockout duration
                            </label>
                        </div>
                        <div style="text-align: right;">
                            <button type="button" onclick="saveLockingConfig()" style="background: #28a745; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; margin-right: 0.5rem;">Save Configuration</button>
                            <button type="button" onclick="closeModal('lockingConfigModal')" style="background: #6c757d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer;">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>`;
       
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.remove();
        }
    }
    function saveSessionSettings() {
        const form = document.getElementById('sessionSettingsForm');
        const formData = new FormData(form);
       
        let settings = {};
        for (let [key, value] of formData.entries()) {
            settings[key] = value;
        }
       
        fetch('user_management.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax_action=update_security_settings&settings=${encodeURIComponent(JSON.stringify(settings))}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Session settings updated successfully');
                closeModal('sessionSettingsModal');
                location.reload(); 
            } else {
                alert('Failed to update session settings');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating session settings');
        });
    }
    function saveLockingConfig() {
        const form = document.getElementById('lockingConfigForm');
        const formData = new FormData(form);
       
        let settings = {};
        for (let [key, value] of formData.entries()) {
            settings[key] = value;
        }
       
        
        if (!settings.auto_unlock) {
            settings.auto_unlock = '0';
        }
       
        fetch('user_management.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `ajax_action=update_security_settings&settings=${encodeURIComponent(JSON.stringify(settings))}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Locking configuration updated successfully');
                closeModal('lockingConfigModal');
                location.reload(); 
            } else {
                alert('Failed to update locking configuration');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating locking configuration');
        });
    }
    
    function showDatabaseTab() {
        window.location.href = 'admin_dashboard.php';
        setTimeout(function() {
            if (window.showTab) {
                showTab({preventDefault: function(){}}, 'database');
            }
        }, 100);
    }
    function showNotificationsTab() {
        window.location.href = 'admin_dashboard.php';
        setTimeout(function() {
            if (window.showTab) {
                showTab({preventDefault: function(){}}, 'notifications');
            }
        }, 100);
    }
    function showSettingsTab() {
        window.location.href = 'admin_dashboard.php';
        setTimeout(function() {
            if (window.showTab) {
                showTab({preventDefault: function(){}}, 'settings');
            }
        }, 100);
    }
    
    setInterval(() => {
        if (document.getElementById('sessionsModal')) {
            viewActiveSessions();
        }
    }, 30000);
</script>
</body>
</html>