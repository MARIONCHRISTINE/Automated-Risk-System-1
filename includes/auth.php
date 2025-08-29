<?php
session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        // Redirect to appropriate dashboard based on current role
        switch ($_SESSION['role']) {
            case 'admin':
                header('Location: admin_dashboard.php');
                break;
            case 'risk_owner':
                header('Location: risk_owner_dashboard.php');
                break;
            case 'staff':
                header('Location: staff_dashboard.php');
                break;
            case 'compliance_team':
                header('Location: compliance_dashboard.php');
                break;
            default:
                header('Location: login.php');
                break;
        }
        exit();
    }
}

function requireAnyRole($roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles)) {
        // Redirect to appropriate dashboard based on current role
        switch ($_SESSION['role']) {
            case 'admin':
                header('Location: admin_dashboard.php');
                break;
            case 'risk_owner':
                header('Location: risk_owner_dashboard.php');
                break;
            case 'staff':
                header('Location: staff_dashboard.php');
                break;
            case 'compliance_team':
                header('Location: compliance_dashboard.php');
                break;
            default:
                header('Location: login.php');
                break;
        }
        exit();
    }
}

function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'department' => $_SESSION['department'] ?? null
    ];
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Auth class wrapper for compatibility with IDE dashboard
class Auth {
    public function requireLogin() {
        requireLogin();
    }
    
    public function requireRole($role) {
        requireRole($role);
    }
    
    public function requireAnyRole($roles) {
        requireAnyRole($roles);
    }
    
    public function getCurrentUser() {
        return getCurrentUser();
    }
    
    public function isLoggedIn() {
        return isLoggedIn();
    }
    
    public function hasRole($role) {
        return hasRole($role);
    }
}
?>
