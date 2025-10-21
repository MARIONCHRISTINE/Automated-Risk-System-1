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

// Get all notifications for the user
$all_notifications = getNotifications($db, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Management Procedures - Airtel Risk Register</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap">
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
        
        /* Updated header styles to match dashboard exactly */
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
        
        /* Updated navigation styles to match dashboard exactly */
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
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-top: 4px solid #E60012;
        }
        
        .card-header {
            padding: 2rem 2rem 1rem 2rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .card-title {
            color: #333;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0 0 0.5rem 0;
        }
        
        /* Procedures Specific Styles */
        .procedure-section {
            margin: 2.5rem;
            padding: 2rem;
            border-left: 5px solid #E60012;
            background: linear-gradient(to right, #fff5f5 0%, #ffffff 100%);
            border-radius: 0 12px 12px 0;
            box-shadow: 0 2px 12px rgba(230, 0, 18, 0.08);
            transition: all 0.3s ease;
        }
        
        .procedure-section:hover {
            border-left-width: 8px;
            box-shadow: 0 4px 20px rgba(230, 0, 18, 0.15);
            transform: translateX(4px);
        }
        
        .procedure-title {
            color: #E60012;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .procedure-title::before {
            content: '';
            width: 6px;
            height: 6px;
            background: #E60012;
            border-radius: 50%;
            box-shadow: 0 0 0 4px rgba(230, 0, 18, 0.2);
        }
        
        .procedure-section h4 {
            color: #2c3e50;
            font-size: 1.15rem;
            font-weight: 600;
            margin: 2rem 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .procedure-section p {
            color: #4a5568;
            line-height: 1.8;
            margin-bottom: 1.25rem;
            font-size: 1rem;
        }
        
        .procedure-section ul {
            margin-left: 1.5rem;
            color: #4a5568;
            line-height: 2;
        }
        
        .procedure-section li {
            margin-bottom: 0.75rem;
            padding-left: 0.5rem;
        }
        
        .procedure-section li strong {
            color: #2c3e50;
            font-weight: 600;
        }
        
        .field-definition {
            background: white;
            border: 2px solid #e8ecf1;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            transition: all 0.3s ease;
        }
        
        .field-definition:hover {
            border-color: #E60012;
            box-shadow: 0 4px 16px rgba(230, 0, 18, 0.1);
            transform: translateY(-2px);
        }
        
        .field-name {
            font-weight: 700;
            color: #E60012;
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .field-name::before {
            content: '▸';
            font-size: 1.2rem;
        }
        
        .field-description {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.8;
        }
        
        .field-description strong {
            color: #2c3e50;
            font-weight: 600;
        }
        
        .risk-matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 1.5rem 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .risk-matrix-table th,
        .risk-matrix-table td {
            border: 1px solid #dee2e6;
            padding: 1rem;
            text-align: center;
            font-weight: 500;
        }
        
        .risk-matrix-table th {
            background: linear-gradient(135deg, #E60012 0%, #c00010 100%);
            color: white;
            font-weight: 700;
            font-size: 0.95rem;
        }
        
        .risk-matrix-table tbody th {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
        }
        
        .matrix-1 { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            font-weight: 600;
        }
        .matrix-2 { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            font-weight: 600;
        }
        .matrix-3 { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            font-weight: 600;
        }
        .matrix-4 { 
            background: linear-gradient(135deg, #f5c6cb 0%, #f1b0b7 100%);
            color: #721c24;
            font-weight: 700;
        }
        .matrix-5 { 
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            font-weight: 700;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .toc {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 2rem;
            border-radius: 12px;
            margin: 2.5rem;
            border: 2px solid #dee2e6;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .toc h3 {
            color: #E60012;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .toc ul {
            list-style: none;
            padding-left: 0;
        }
        
        .toc li {
            margin: 0.75rem 0;
            padding-left: 1.5rem;
            position: relative;
        }
        
        .toc li::before {
            content: '→';
            position: absolute;
            left: 0;
            color: #E60012;
            font-weight: 700;
        }
        
        .toc a {
            text-decoration: none;
            color: #2c3e50;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .toc a:hover {
            color: #E60012;
            transform: translateX(4px);
        }
        
        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin: 1.5rem 0;
        }
        
        .department-card {
            background: white;
            border: 2px solid #e8ecf1;
            border-radius: 12px;
            padding: 1.75rem;
            border-left: 5px solid #E60012;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .department-card:hover {
            border-left-width: 8px;
            box-shadow: 0 6px 20px rgba(230, 0, 18, 0.15);
            transform: translateY(-4px);
        }
        
        .department-title {
            font-weight: 700;
            font-size: 1.15rem;
            margin-bottom: 0.75rem;
            color: #2c3e50;
        }
        
        .auto-assignment-flow {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin: 1.5rem 0;
            border: 2px solid #e8ecf1;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        
        .auto-assignment-flow h4 {
            color: #E60012;
            margin-bottom: 1.5rem;
            font-size: 1.25rem;
            font-weight: 700;
        }
        
        .flow-step {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            padding: 1.25rem;
            margin: 0.75rem 0;
            background: linear-gradient(to right, #fff5f5 0%, #ffffff 100%);
            border-radius: 10px;
            border-left: 4px solid #E60012;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .flow-step:hover {
            border-left-width: 6px;
            box-shadow: 0 4px 16px rgba(230, 0, 18, 0.12);
            transform: translateX(4px);
        }
        
        .flow-step i {
            color: #E60012;
            font-size: 1.75rem;
            width: 40px;
            text-align: center;
            flex-shrink: 0;
        }
        
        .flow-step span {
            color: #4a5568;
            font-weight: 500;
            font-size: 1rem;
        }
        
        /* Scroll behavior */
        html {
            scroll-behavior: smooth;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding-top: 160px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
            }
            
            .nav-menu {
                flex-direction: column;
            }
            
            .procedure-section {
                margin: 1.5rem;
                padding: 1.5rem;
            }
            
            .toc {
                margin: 1.5rem;
                padding: 1.5rem;
            }
            
            .department-grid {
                grid-template-columns: 1fr;
            }
            
            .card-title {
                font-size: 1.5rem;
            }
            
            .procedure-title {
                font-size: 1.25rem;
            }
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
                    <a href="risk_procedures.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk_procedures.php' ? 'active' : ''; ?>">
                        📋 Procedures
                    </a>
                </li>
                <li class="nav-item notification-nav-item">
                </li>
            </ul>
        </div>
    </nav>
    
    <main class="main-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📋 Risk Management Procedures</h2>
                <p style="margin: 0; color: #666; font-size: 1rem;">Complete guide for risk owners in the Airtel Risk Management System</p>
            </div>
            
            <!-- Table of Contents -->
            <div class="toc">
                <h3>📑 Table of Contents</h3>
                <ul>
                    <li><a href="#role-overview">1. Risk Owner Role Overview</a></li>
                    <li><a href="#risk-assessment">2. Risk Assessment Process</a></li>
                    <li><a href="#risk-matrix">3. Risk Assessment Matrix</a></li>
                    <li><a href="#department-management">4. Department Risk Management</a></li>
                    <li><a href="#auto-assignment">5. Automatic Risk Assignment</a></li>
                    <li><a href="#best-practices">6. Best Practices</a></li>
                </ul>
            </div>
            
            <!-- Role Overview -->
            <div class="procedure-section" id="role-overview">
                <h3 class="procedure-title">1. Risk Owner Role Overview</h3>
                <p>As a Risk Owner in the Airtel Risk Management System, you are responsible for managing risks within your department and ensuring proper risk assessment and treatment.</p>
                
                <h4>Key Responsibilities:</h4>
                <ul>
                    <li><strong>Risk Assessment:</strong> Evaluate and rate risks assigned to you</li>
                    <li><strong>Risk Treatment:</strong> Develop and implement risk mitigation strategies</li>
                    <li><strong>Department Oversight:</strong> Monitor all risks within your department</li>
                    <li><strong>Reporting:</strong> Report new risks discovered in your area</li>
                    <li><strong>Status Updates:</strong> Keep risk statuses current and accurate</li>
                </ul>
            </div>
            
            <!-- Risk Assessment Process -->
            <div class="procedure-section" id="risk-assessment">
                <h3 class="procedure-title">2. Risk Assessment Process</h3>
                <p>The risk assessment process involves evaluating both the likelihood and impact of identified risks.</p>
                
                <h4>Assessment Fields:</h4>
                <div class="field-definition">
                    <div class="field-name">Inherent Risk (Before Controls)</div>
                    <div class="field-description">
                        <strong>Inherent Likelihood:</strong> Probability of the risk occurring without any controls (1-5 scale)<br>
                        <strong>Inherent Consequence:</strong> Impact if the risk occurs without controls (1-5 scale)
                    </div>
                </div>
                
                <div class="field-definition">
                    <div class="field-name">Residual Risk (After Controls)</div>
                    <div class="field-description">
                        <strong>Residual Likelihood:</strong> Probability after implementing controls (1-5 scale)<br>
                        <strong>Residual Consequence:</strong> Impact after implementing controls (1-5 scale)
                    </div>
                </div>
                
                <div class="field-definition">
                    <div class="field-name">Current Risk Assessment</div>
                    <div class="field-description">
                        <strong>Probability:</strong> Current likelihood of occurrence (1-5 scale)<br>
                        <strong>Impact:</strong> Current potential impact (1-5 scale)
                    </div>
                </div>
            </div>
            
            <!-- Risk Matrix -->
            <div class="procedure-section" id="risk-matrix">
                <h3 class="procedure-title">3. Risk Assessment Matrix</h3>
                <p>Use this matrix to determine risk levels based on likelihood and impact ratings:</p>
                
                <table class="risk-matrix-table">
                    <thead>
                        <tr>
                            <th>Impact →<br>Likelihood ↓</th>
                            <th>1 (Very Low)</th>
                            <th>2 (Low)</th>
                            <th>3 (Medium)</th>
                            <th>4 (High)</th>
                            <th>5 (Very High)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th>5 (Very Likely)</th>
                            <td class="matrix-2">5 - Medium</td>
                            <td class="matrix-3">10 - High</td>
                            <td class="matrix-4">15 - Critical</td>
                            <td class="matrix-5">20 - Critical</td>
                            <td class="matrix-5">25 - Critical</td>
                        </tr>
                        <tr>
                            <th>4 (Likely)</th>
                            <td class="matrix-2">4 - Medium</td>
                            <td class="matrix-2">8 - Medium</td>
                            <td class="matrix-3">12 - High</td>
                            <td class="matrix-4">16 - Critical</td>
                            <td class="matrix-5">20 - Critical</td>
                        </tr>
                        <tr>
                            <th>3 (Possible)</th>
                            <td class="matrix-1">3 - Low</td>
                            <td class="matrix-2">6 - Medium</td>
                            <td class="matrix-3">9 - High</td>
                            <td class="matrix-3">12 - High</td>
                            <td class="matrix-4">15 - Critical</td>
                        </tr>
                        <tr>
                            <th>2 (Unlikely)</th>
                            <td class="matrix-1">2 - Low</td>
                            <td class="matrix-2">4 - Medium</td>
                            <td class="matrix-2">6 - Medium</td>
                            <td class="matrix-2">8 - Medium</td>
                            <td class="matrix-3">10 - High</td>
                        </tr>
                        <tr>
                            <th>1 (Very Unlikely)</th>
                            <td class="matrix-1">1 - Low</td>
                            <td class="matrix-1">2 - Low</td>
                            <td class="matrix-1">3 - Low</td>
                            <td class="matrix-2">4 - Medium</td>
                            <td class="matrix-2">5 - Medium</td>
                        </tr>
                    </tbody>
                </table>
                
                <h4>Risk Level Definitions:</h4>
                <ul>
                    <li><strong>Low (1-3):</strong> Acceptable risk, monitor regularly</li>
                    <li><strong>Medium (4-8):</strong> Manageable risk, implement controls</li>
                    <li><strong>High (9-14):</strong> Significant risk, immediate action required</li>
                    <li><strong>Critical (15-25):</strong> Unacceptable risk, urgent action required</li>
                </ul>
            </div>
            
            <!-- Department Management -->
            <div class="procedure-section" id="department-management">
                <h3 class="procedure-title">4. Department Risk Management</h3>
                <p>As a risk owner, you have access to manage risks within your specific department:</p>
                
                <div class="department-grid">
                    <?php
                    // Dynamically list departments based on user's department
                    $departments = [
                        'IT' => 'Information Technology risks including cybersecurity, system failures, and data breaches',
                        'Finance' => 'Financial risks including fraud, compliance, and budget overruns',
                        'Operations' => 'Operational risks including process failures and service disruptions',
                        'HR' => 'Human resources risks including compliance and employee-related issues',
                        'Legal' => 'Legal and regulatory compliance risks',
                        'Marketing' => 'Marketing and brand reputation risks',
                        'Airtel Money' => 'Mobile money service risks including fraud, compliance, and operational issues'
                    ];
                    
                    // Display user's department prominently
                    if (isset($departments[$department])) {
                        echo "<div class='department-card' style='border-left-color: #E60012; background: #fff5f5;'>";
                        echo "<div class='department-title' style='color: #E60012;'>🏢 {$department} (Your Department)</div>";
                        echo "<p style='margin: 0; color: #666;'>{$departments[$department]}</p>";
                        echo "</div>";
                    } else {
                        // If department not in predefined list, still show it
                        echo "<div class='department-card' style='border-left-color: #E60012; background: #fff5f5;'>";
                        echo "<div class='department-title' style='color: #E60012;'>🏢 {$department} (Your Department)</div>";
                        echo "<p style='margin: 0; color: #666;'>Manage all risks within your department</p>";
                        echo "</div>";
                    }
                    ?>
                </div>
                
                <h4>Department Access Levels:</h4>
                <ul>
                    <li><strong>View All Department Risks:</strong> See all risks reported within your department</li>
                    <li><strong>Take Ownership:</strong> Assign unowned risks to yourself</li>
                    <li><strong>Transfer Ownership:</strong> Reassign risks to other department members</li>
                    <li><strong>Update Status:</strong> Change risk status and add progress notes</li>
                </ul>
            </div>
            
            <!-- Auto Assignment -->
            <div class="procedure-section" id="auto-assignment">
                <h3 class="procedure-title">5. Automatic Risk Assignment</h3>
                <p>The system automatically assigns risks to appropriate risk owners based on department and expertise:</p>
                
                <div class="auto-assignment-flow">
                    <h4>🔄 Auto-Assignment Process:</h4>
                    <div class="flow-step">
                        <i class="fas fa-upload"></i>
                        <span>Risk is reported by any user</span>
                    </div>
                    <div class="flow-step">
                        <i class="fas fa-search"></i>
                        <span>System identifies the risk category and department</span>
                    </div>
                    <div class="flow-step">
                        <i class="fas fa-users"></i>
                        <span>System finds available risk owners in that department</span>
                    </div>
                    <div class="flow-step">
                        <i class="fas fa-user-check"></i>
                        <span>Risk is automatically assigned to the most suitable risk owner</span>
                    </div>
                    <div class="flow-step">
                        <i class="fas fa-bell"></i>
                        <span>Risk owner receives notification of new assignment</span>
                    </div>
                </div>
                
                <h4>Manual Override Options:</h4>
                <ul>
                    <li><strong>Self-Assignment:</strong> Take ownership of unassigned risks in your department</li>
                    <li><strong>Transfer:</strong> Reassign risks to other qualified team members</li>
                    <li><strong>Escalation:</strong> Escalate complex risks to senior management</li>
                    <li><strong>Collaboration:</strong> Involve other departments for cross-functional risks</li>
                </ul>
            </div>
            
            <!-- Best Practices -->
            <div class="procedure-section" id="best-practices">
                <h3 class="procedure-title">6. Best Practices</h3>
                
                <h4>🎯 Risk Assessment Best Practices:</h4>
                <ul>
                    <li><strong>Be Objective:</strong> Base assessments on facts and data, not assumptions</li>
                    <li><strong>Consider Context:</strong> Evaluate risks within the current business environment</li>
                    <li><strong>Document Rationale:</strong> Explain your reasoning for risk ratings</li>
                    <li><strong>Regular Reviews:</strong> Reassess risks periodically as conditions change</li>
                    <li><strong>Stakeholder Input:</strong> Consult with relevant team members and experts</li>
                </ul>
                
                <h4>📊 Risk Management Best Practices:</h4>
                <ul>
                    <li><strong>Prioritize by Impact:</strong> Focus on high and critical risks first</li>
                    <li><strong>Develop Action Plans:</strong> Create specific, measurable mitigation strategies</li>
                    <li><strong>Monitor Progress:</strong> Track implementation of risk treatments</li>
                    <li><strong>Communicate Effectively:</strong> Keep stakeholders informed of risk status</li>
                    <li><strong>Learn from Experience:</strong> Use past incidents to improve risk management</li>
                </ul>
                
                <h4>🚨 Escalation Guidelines:</h4>
                <ul>
                    <li><strong>Critical Risks:</strong> Immediately escalate to senior management</li>
                    <li><strong>Cross-Department Risks:</strong> Coordinate with other department risk owners</li>
                    <li><strong>Resource Constraints:</strong> Escalate when additional resources are needed</li>
                    <li><strong>Regulatory Issues:</strong> Involve legal and compliance teams</li>
                </ul>
            </div>
        </div>
    </main>
</body>
</html>
