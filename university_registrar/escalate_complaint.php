<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'university_registrar'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'university_registrar') {
    header("Location: ../login.php");
    exit;
}

$registrar_id = $_SESSION['user_id'];
$registrar = null;

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch University Registrar details
$sql_registrar = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_registrar = $db->prepare($sql_registrar);
if ($stmt_registrar) {
    $stmt_registrar->bind_param("i", $registrar_id);
    $stmt_registrar->execute();
    $result_registrar = $stmt_registrar->get_result();
    if ($result_registrar->num_rows > 0) {
        $registrar = $result_registrar->fetch_assoc();
    } else {
        $_SESSION['error'] = "University Registrar details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_registrar->close();
} else {
    error_log("Error preparing University Registrar query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $registrar_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result['count'];
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Validate complaint_id and escalation_id from URL
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
$escalation_id = filter_input(INPUT_GET, 'escalation_id', FILTER_VALIDATE_INT);

if (!$complaint_id || !$escalation_id) {
    $_SESSION['error'] = "Invalid complaint or escalation ID.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details
$complaint_query = "
    SELECT c.id, c.title, c.user_id, e.escalated_by_id, e.department_id, e.original_handler_id
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.id = ? AND e.escalated_to_id = ? AND e.status = 'pending'";
$stmt_complaint = $db->prepare($complaint_query);
if (!$stmt_complaint) {
    $_SESSION['error'] = "An error occurred while fetching complaint details.";
    header("Location: dashboard.php");
    exit;
}
$stmt_complaint->bind_param("iii", $complaint_id, $escalation_id, $registrar_id);
$stmt_complaint->execute();
$complaint_result = $stmt_complaint->get_result();
if ($complaint_result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found or not accessible.";
    header("Location: dashboard.php");
    exit;
}
$complaint_data = $complaint_result->fetch_assoc();
$stmt_complaint->close();

// Fetch Academic VPs for escalation options
$academic_vps = [];
$roles_query = "SELECT id, role, fname, lname FROM users WHERE role = 'academic_vp'";
$result = $db->query($roles_query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $academic_vps[] = $row;
    }
} else {
    error_log("Error fetching Academic VPs: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching Academic VPs.";
}

// Function to send a stereotyped report to the President (same as Campus Registrar's)
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '') {
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    if (!$stmt_complaint) {
        error_log("Prepare failed for complaint fetch: " . $db->error);
        $_SESSION['error'] = "Failed to fetch complaint details for report generation.";
        return false;
    }
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) {
        error_log("Complaint #$complaint_id not found for report generation.");
        $_SESSION['error'] = "Complaint not found for report generation.";
        $stmt_complaint->close();
        return false;
    }
    $complaint = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    $sql_sender = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_sender = $db->prepare($sql_sender);
    if (!$stmt_sender) {
        error_log("Prepare failed for sender fetch: " . $db->error);
        $_SESSION['error'] = "Failed to fetch sender details for report generation.";
        return false;
    }
    $stmt_sender->bind_param("i", $sender_id);
    $stmt_sender->execute();
    $sender_result = $stmt_sender->get_result();
    if ($sender_result->num_rows === 0) {
        error_log("Sender #$sender_id not found for report generation.");
        $_SESSION['error'] = "Sender not found for report generation.";
        $stmt_sender->close();
        return false;
    }
    $sender = $sender_result->fetch_assoc();
    $stmt_sender->close();

    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) {
        error_log("No user with role 'president' found.");
        $_SESSION['error'] = "No President found to receive the report.";
        return false;
    }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];

    $report_content = "Complaint Report\n";
    $report_content .= "----------------\n";
    $report_content .= "Report Type: " . ucfirst($report_type) . "\n";
    $report_content .= "Complaint ID: {$complaint['id']}\n";
    $report_content .= "Title: {$complaint['title']}\n";
    $report_content .= "Description: {$complaint['description']}\n";
    $report_content .= "Category: " . ($complaint['category'] ? ucfirst($complaint['category']) : 'Not categorized') . "\n";
    $report_content .= "Status: " . ucfirst($complaint['status']) . "\n";
    $report_content .= "Submitted By: {$complaint['submitter_fname']} {$complaint['submitter_lname']}\n";
    $report_content .= "Processed By: {$sender['fname']} {$sender['lname']}\n";
    $report_content .= "Created At: " . date('M j, Y H:i', strtotime($complaint['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info: $additional_info\n";
    }

    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt_report = $db->prepare($sql_report);
    if (!$stmt_report) {
        error_log("Prepare failed for report insertion: " . $db->error);
        $_SESSION['error'] = "Failed to generate the report for the President.";
        return false;
    }
    $stmt_report->bind_param("iiiss", $complaint_id, $sender_id, $recipient_id, $report_type, $report_content);
    $stmt_report->execute();
    $stmt_report->close();

    $notification_desc = "A new $report_type report for Complaint #{$complaint['id']} has been submitted by {$sender['fname']} {$sender['lname']} on " . date('M j, Y H:i') . ".";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
    $stmt_notify = $db->prepare($sql_notify);
    if ($stmt_notify) {
        $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
        $stmt_notify->execute();
        $stmt_notify->close();
    } else {
        error_log("Failed to prepare notification for President: " . $db->error);
        $_SESSION['error'] = "Failed to notify the President of the report.";
        return false;
    }

    return true;
}

// Handle escalation form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'escalate') {
    $submitted_csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $escalation_id = filter_input(INPUT_POST, 'escalation_id', FILTER_VALIDATE_INT);
    $escalated_to_id = filter_input(INPUT_POST, 'escalated_to_id', FILTER_VALIDATE_INT);
    $resolution_details = trim(filter_input(INPUT_POST, 'resolution_details', FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$submitted_csrf_token || $submitted_csrf_token !== $csrf_token) {
        $_SESSION['error'] = "Invalid CSRF token. Please try again.";
        header("Location: dashboard.php");
        exit;
    }

    if (!$complaint_id || !$escalation_id) {
        $_SESSION['error'] = "Invalid complaint or escalation ID.";
        header("Location: dashboard.php");
        exit;
    }

    if (empty($resolution_details)) {
        $_SESSION['error'] = "Please provide a reason for escalation.";
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if (strlen($resolution_details) < 10) {
        $_SESSION['error'] = "Escalation reason must be at least 10 characters long.";
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if (strlen($resolution_details) > 1000) {
        $_SESSION['error'] = "Escalation reason cannot exceed 1000 characters.";
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    if (!$escalated_to_id || !in_array($escalated_to_id, array_column($academic_vps, 'id'))) {
        $_SESSION['error'] = "Please select a valid Academic Vice President.";
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $user_id = $complaint_data['user_id'];
    $escalated_by_id = $complaint_data['escalated_by_id'];
    $department_id = $complaint_data['department_id'];
    $original_handler_id = $complaint_data['original_handler_id'];
    $escalated_to_role = 'academic_vp'; // Hardcoded since escalation is only to Academic VP

    $db->begin_transaction();
    try {
        // Update current escalation
        $update_escalation_sql = "UPDATE escalations SET status = 'escalated', resolution_details = ?, resolved_at = NULL WHERE id = ?";
        $update_escalation_stmt = $db->prepare($update_escalation_sql);
        if (!$update_escalation_stmt) {
            throw new Exception("An error occurred while updating the escalation status.");
        }
        $update_escalation_stmt->bind_param("si", $resolution_details, $escalation_id);
        $update_escalation_stmt->execute();
        $update_escalation_stmt->close();

        // Create new escalation record
        $new_escalation_sql = "INSERT INTO escalations (complaint_id, escalated_by_id, escalated_to_id, escalated_to, department_id, action_type, status, created_at, original_handler_id) 
                               VALUES (?, ?, ?, ?, ?, 'escalation', 'pending', NOW(), ?)";
        $new_escalation_stmt = $db->prepare($new_escalation_sql);
        if (!$new_escalation_stmt) {
            throw new Exception("An error occurred while creating a new escalation record.");
        }
        $new_escalation_stmt->bind_param("iiisii", $complaint_id, $registrar_id, $escalated_to_id, $escalated_to_role, $department_id, $original_handler_id);
        $new_escalation_stmt->execute();
        $new_escalation_stmt->close();

        // Update complaint status
        $update_complaint_sql = "UPDATE complaints SET status = 'escalated', resolution_details = NULL, resolution_date = NULL WHERE id = ?";
        $update_complaint_stmt = $db->prepare($update_complaint_sql);
        if (!$update_complaint_stmt) {
            throw new Exception("An error occurred while updating the complaint status.");
        }
        $update_complaint_stmt->bind_param("i", $complaint_id);
        $update_complaint_stmt->execute();
        $update_complaint_stmt->close();

        // Notify the Academic VP
        $notify_role_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Complaint #$complaint_id has been escalated to you (Academic Vice President) by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
        $notify_role_stmt = $db->prepare($notify_role_sql);
        if (!$notify_role_stmt) {
            throw new Exception("An error occurred while notifying the Academic Vice President.");
        }
        $notify_role_stmt->bind_param("iis", $escalated_to_id, $complaint_id, $notification_desc);
        $notify_role_stmt->execute();
        $notify_role_stmt->close();

        // Notify the complainant
        $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Your complaint #$complaint_id has been escalated to the Academic Vice President by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
        $notify_user_stmt = $db->prepare($notify_user_sql);
        if (!$notify_user_stmt) {
            throw new Exception("An error occurred while notifying the complainant.");
        }
        $notify_user_stmt->bind_param("iis", $user_id, $complaint_id, $notification_desc);
        $notify_user_stmt->execute();
        $notify_user_stmt->close();

        // Notify the original handler (escalated_by_id)
        $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Complaint #$complaint_id, which you assigned/escalated, has been escalated to the Academic Vice President by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
        $notify_handler_stmt = $db->prepare($notify_handler_sql);
        if (!$notify_handler_stmt) {
            throw new Exception("An error occurred while notifying the original handler.");
        }
        $notify_handler_stmt->bind_param("iis", $escalated_by_id, $complaint_id, $notification_desc);
        $notify_handler_stmt->execute();
        $notify_handler_stmt->close();

        // Send stereotyped report to President
        $additional_info = "Escalated to Academic Vice President: $resolution_details";
        if (!sendStereotypedReport($db, $complaint_id, $registrar_id, 'escalated', $additional_info)) {
            throw new Exception("Failed to send the report to the President.");
        }

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been escalated successfully to the Academic Vice President.";
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "Error escalating complaint: " . $e->getMessage();
        error_log("Escalation error: " . $e->getMessage());
        header("Location: dashboard.php");
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
    <title>Escalate Complaint | University Registrar - DMU Complaint System</title>
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

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-y: auto;
        }

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

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .alert i {
            font-size: 1.2rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .alert-success {
            background-color: #d1e7dd;
            border-color: #badbcc;
            color: #0f5132;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }

        .content-container {
            background: white;
            padding: 2rem;
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

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
            resize: vertical;
            min-height: 120px;
        }

        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
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

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-right: 0.5rem;
            text-decoration: none;
        }

        .action-btn i {
            font-size: 0.9rem;
        }

        .btn-escalate {
            background: var(--warning);
            color: white;
        }

        .btn-escalate:hover {
            background: #e0a800;
        }

        .btn-cancel {
            background: var(--gray);
            color: white;
        }

        .btn-cancel:hover {
            background: var(--dark);
        }

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
            .main-content { min-height: calc(100vh - HeightOfVerticalNav); }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .action-btn { width: 100%; justify-content: center; margin-bottom: 0.5rem; margin-right: 0; }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($registrar['fname'] . ' ' . $registrar['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $registrar['role']))); ?></p>
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
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
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
                    <span class="badge badge-danger"><?php echo $notification_count; ?></span>
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

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - University Registrar</span>
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
            <h2>Escalate Complaint #<?php echo htmlspecialchars($complaint_id); ?></h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="escalate">
                <input type="hidden" name="complaint_id" value="<?php echo htmlspecialchars($complaint_id); ?>">
                <input type="hidden" name="escalation_id" value="<?php echo htmlspecialchars($escalation_id); ?>">

                <div class="form-group">
                    <label for="resolution_details">Reason for Escalation</label>
                    <textarea name="resolution_details" id="resolution_details" rows="5" required placeholder="Enter the reason for escalating this complaint to the Academic Vice President..."></textarea>
                </div>

                <div class="form-group">
                    <label for="escalated_to_id">Select Academic Vice President</label>
                    <select name="escalated_to_id" id="escalated_to_id" required>
                        <option value="" disabled selected>Select an Academic Vice President</option>
                        <?php foreach ($academic_vps as $vp): ?>
                            <option value="<?php echo htmlspecialchars($vp['id']); ?>">
                                <?php echo htmlspecialchars("{$vp['fname']} {$vp['lname']}"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="action-btn btn-escalate">
                    <i class="fas fa-arrow-up"></i> Escalate to Academic VP
                </button>
                <a href="dashboard.php" class="action-btn btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </form>
        </div>

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
        document.querySelector('form').addEventListener('submit', function(event) {
            if (!confirm('Are you sure you want to escalate this complaint to the Academic Vice President? This action cannot be undone.')) {
                event.preventDefault();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 7000);
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