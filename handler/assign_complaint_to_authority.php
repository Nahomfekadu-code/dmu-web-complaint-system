<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'handler') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

// Fetch handler details for display
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
    } else {
        $_SESSION['error'] = "Handler details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

// Check if complaint ID is provided
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details
$stmt = $db->prepare("SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname 
                      FROM complaints c 
                      JOIN users u ON c.user_id = u.id 
                      WHERE c.id = ? AND c.handler_id = ?");
if (!$stmt) {
    error_log("Prepare failed for complaint fetch: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaint.";
    header("Location: dashboard.php");
    exit;
}
$stmt->bind_param("ii", $complaint_id, $handler_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found or you are not authorized to assign it.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$stmt->close();

// Check if the complaint can be assigned
if ($complaint['status'] === 'resolved' || $complaint['status'] === 'rejected') {
    $_SESSION['error'] = "This complaint has already been resolved or rejected and cannot be assigned.";
    header("Location: dashboard.php");
    exit;
}

// Fetch all users for each responsible role, including new roles
$roles_to_assign = [
    'department_head', 'college_dean', 'academic_vp', 'president',
    'university_registrar', 'campus_registrar', 'sims', 'cost_sharing',
    'student_service_directorate', 'dormitory_service', 'students_food_service', 'library_service',
    'hrm', 'finance', 'general_service'
];
$assignment_options = [];

foreach ($roles_to_assign as $role) {
    $sql = "SELECT id, fname, lname, role, department, college FROM users WHERE role = ? ORDER BY fname, lname";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for role fetch ($role): " . $db->error);
        continue;
    }
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($user = $result->fetch_assoc()) {
        $assignment_options[$role][] = $user;
    }
    if (empty($assignment_options[$role])) {
        error_log("No users found for role: $role");
    }
    $stmt->close();
}

// Check if any assignment options are available
if (empty($assignment_options)) {
    $_SESSION['error'] = "No users available to assign this complaint.";
    header("Location: dashboard.php");
    exit;
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $handler_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $handler_id, $report_type, $additional_info = '') {
    // Fetch complaint details
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    if (!$stmt_complaint) {
        error_log("Prepare failed for complaint fetch in report: " . $db->error);
        return;
    }
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) {
        error_log("Complaint #$complaint_id not found for report generation.");
        $stmt_complaint->close();
        return;
    }
    $complaint = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    // Fetch handler details
    $sql_handler = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_handler = $db->prepare($sql_handler);
    if (!$stmt_handler) {
        error_log("Prepare failed for handler fetch in report: " . $db->error);
        return;
    }
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $handler_result = $stmt_handler->get_result();
    if ($handler_result->num_rows === 0) {
        error_log("Handler #$handler_id not found for report generation.");
        $stmt_handler->close();
        return;
    }
    $handler = $handler_result->fetch_assoc();
    $stmt_handler->close();

    // Fetch the President
    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) {
        error_log("No user with role 'president' found.");
        return;
    }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];

    // Generate stereotyped report content
    $report_content = "Complaint Report\n";
    $report_content .= "----------------\n";
    $report_content .= "Report Type: " . ucfirst($report_type) . "\n";
    $report_content .= "Complaint ID: {$complaint['id']}\n";
    $report_content .= "Title: {$complaint['title']}\n";
    $report_content .= "Description: {$complaint['description']}\n";
    $report_content .= "Category: " . ($complaint['category'] ? ucfirst($complaint['category']) : 'Not categorized') . "\n";
    $report_content .= "Status: " . ucfirst($complaint['status']) . "\n";
    $report_content .= "Submitted By: {$complaint['submitter_fname']} {$complaint['submitter_lname']}\n";
    $report_content .= "Handler: {$handler['fname']} {$handler['lname']}\n";
    $report_content .= "Created At: " . date('M j, Y H:i', strtotime($complaint['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info: $additional_info\n";
    }

    // Insert the report into the stereotyped_reports table
    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content) VALUES (?, ?, ?, ?, ?)";
    $stmt_report = $db->prepare($sql_report);
    if (!$stmt_report) {
        error_log("Prepare failed for report insertion: " . $db->error);
        return;
    }
    $stmt_report->bind_param("iiiss", $complaint_id, $handler_id, $recipient_id, $report_type, $report_content);
    $stmt_report->execute();
    $stmt_report->close();

    // Notify the President
    $notification_desc = "A new $report_type report for Complaint #{$complaint['id']} has been submitted by {$handler['fname']} {$handler['lname']}.";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
    $stmt_notify = $db->prepare($sql_notify);
    if ($stmt_notify) {
        $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
        $stmt_notify->execute();
        $stmt_notify->close();
    } else {
        error_log("Prepare failed for president notification: " . $db->error);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $assigned_to = filter_input(INPUT_POST, 'assigned_to', FILTER_SANITIZE_STRING);
    $assigned_to_id = filter_input(INPUT_POST, 'assigned_to_id', FILTER_VALIDATE_INT);

    if (!$assigned_to || !$assigned_to_id || !in_array($assigned_to, $roles_to_assign)) {
        $_SESSION['error'] = "Invalid assignment target.";
        header("Location: assign_complaint_to_authority.php?complaint_id=$complaint_id");
        exit;
    }

    $db->begin_transaction();
    try {
        // Insert assignment record into escalations table
        $action_type = 'assignment';
        $assignment_sql = "INSERT INTO escalations (complaint_id, escalated_to, escalated_to_id, escalated_by_id, status, original_handler_id, action_type) 
                           VALUES (?, ?, ?, ?, 'pending', ?, ?)";
        $assignment_stmt = $db->prepare($assignment_sql);
        if (!$assignment_stmt) {
            throw new Exception("Prepare failed for escalation insert: " . $db->error);
        }
        $assignment_stmt->bind_param("isiiis", $complaint_id, $assigned_to, $assigned_to_id, $handler_id, $handler_id, $action_type);
        $assignment_stmt->execute();
        $assignment_stmt->close();

        // Update complaint status to in_progress
        $update_sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
        $update_stmt = $db->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed for complaint update: " . $db->error);
        }
        $update_stmt->bind_param("i", $complaint_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Notify the assigned user
        $notify_assigned_sql = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
        $notification_desc = "A complaint (ID #$complaint_id) has been assigned to you for review.";
        $notify_assigned_stmt = $db->prepare($notify_assigned_sql);
        if (!$notify_assigned_stmt) {
            throw new Exception("Prepare failed for assigned user notification: " . $db->error);
        }
        $notify_assigned_stmt->bind_param("iis", $assigned_to_id, $complaint_id, $notification_desc);
        $notify_assigned_stmt->execute();
        $notify_assigned_stmt->close();

        // Notify the user who submitted the complaint
        $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                           SELECT user_id, ?, 'Your complaint has been assigned to a higher authority.' 
                           FROM complaints WHERE id = ?";
        $notify_user_stmt = $db->prepare($notify_user_sql);
        if (!$notify_user_stmt) {
            throw new Exception("Prepare failed for user notification: " . $db->error);
        }
        $notify_user_stmt->bind_param("ii", $complaint_id, $complaint_id);
        $notify_user_stmt->execute();
        $notify_user_stmt->close();

        // Send stereotyped report to the President
        $additional_info = "Assigned to: " . ucfirst(str_replace('_', ' ', $assigned_to));
        sendStereotypedReport($db, $complaint_id, $handler_id, 'assigned', $additional_info);

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been assigned successfully.";
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "An error occurred while assigning the complaint: " . $e->getMessage();
        error_log("Assignment error: " . $e->getMessage());
        header("Location: assign_complaint_to_authority.php?complaint_id=$complaint_id");
        exit;
    }
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Complaint #<?php echo $complaint_id; ?> to Authority | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --orange: #fd7e14;
            --purple: #7209b7;
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Montserrat', sans-serif;
        }

        body {
            background-color: #f5f7ff;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        /* Vertical Navigation */
        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
            flex-shrink: 0;
        }

        .nav-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%;
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-profile-mini i {
            font-size: 2.5rem;
            color: white;
        }

        .user-info h4 {
            font-size: 0.9rem;
            margin-bottom: 2px;
        }

        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .nav-menu {
            padding: 0 10px;
        }

        .nav-menu h3 {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 20px 10px 10px;
            opacity: 0.7;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: white;
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
        }

        .nav-link .badge {
            margin-left: auto;
            font-size: 0.8rem;
            padding: 2px 6px;
            background-color: var(--danger);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-y: auto;
        }

        /* Horizontal Navigation */
        .horizontal-nav {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .horizontal-nav .logo span {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .horizontal-menu {
            display: flex;
            gap: 10px;
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary);
            color: white;
        }

        /* Content Container */
        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1;
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
            text-align: center;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
        }

        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        .form-group select option[disabled] {
            color: #999;
            font-style: italic;
        }

        .form-group select optgroup {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .form-group select option {
            padding: 5px;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
        }

        .complaint-details {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }

        .complaint-details p {
            margin: 0.5rem 0;
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            width: 100%;
            flex-shrink: 0;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .group-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 15px 0;
        }

        .social-links a {
            color: white;
            font-size: 1.5rem;
            transition: var(--transition);
        }

        .social-links a:hover {
            transform: translateY(-3px);
            color: var(--accent);
        }

        .copyright {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        @media (max-width: 992px) {
            .vertical-nav { width: 220px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; overflow-y: hidden; }
            .main-content { min-height: auto; }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Vertical Navigation -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $handler['role']))); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_complaints.php" class="nav-link <?php echo $current_page == 'view_complaints.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span>View Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a>

            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Handler</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Assign Complaint #<?php echo $complaint_id; ?> to Authority</h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details -->
            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></p>
                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <!-- Assignment Form -->
            <form method="POST">
                <div class="form-group">
                    <label for="assigned_to">Assign To</label>
                    <select name="assigned_to" id="assigned_to" required onchange="updateHiddenField(this)">
                        <option value="" disabled selected>Select a user to assign to</option>
                        <?php foreach ($roles_to_assign as $role): ?>
                            <?php if (!empty($assignment_options[$role])): ?>
                                <optgroup label="<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $role))); ?>">
                                    <?php foreach ($assignment_options[$role] as $user): ?>
                                        <option value="<?php echo htmlspecialchars($role); ?>" data-id="<?php echo $user['id']; ?>">
                                            <?php 
                                                $display_name = htmlspecialchars("{$user['fname']} {$user['lname']}");
                                                if ($role === 'department_head' && !empty($user['department'])) {
                                                    $display_name .= " - Dept: " . htmlspecialchars($user['department']);
                                                } elseif ($role === 'college_dean' && !empty($user['college'])) {
                                                    $display_name .= " - College: " . htmlspecialchars($user['college']);
                                                }
                                                echo $display_name;
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="assigned_to_id" id="assigned_to_id">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Assign Complaint</button>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
            </form>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint Management System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        function updateHiddenField(select) {
            const selectedOption = select.options[select.selectedIndex];
            const userId = selectedOption.getAttribute('data-id');
            document.getElementById('assigned_to_id').value = userId;
        }

        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 5000);
            });
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>