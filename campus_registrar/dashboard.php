<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'campus_registrar'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'campus_registrar') {
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

// Fetch Campus Registrar details
$sql_registrar = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_registrar = $db->prepare($sql_registrar);
if ($stmt_registrar) {
    $stmt_registrar->bind_param("i", $registrar_id);
    $stmt_registrar->execute();
    $result_registrar = $stmt_registrar->get_result();
    if ($result_registrar->num_rows > 0) {
        $registrar = $result_registrar->fetch_assoc();
    } else {
        $_SESSION['error'] = "Campus Registrar details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_registrar->close();
} else {
    error_log("Error preparing Campus Registrar query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaints assigned or escalated to this Campus Registrar
$complaints = [];
$stmt = $db->prepare("
    SELECT c.id, c.title, c.category, c.status, c.created_at, 
           e.id as escalation_id, e.department_id, e.escalated_by_id, e.original_handler_id, e.action_type,
           u.role as escalated_by_role
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    JOIN users u ON e.escalated_by_id = u.id
    WHERE e.escalated_to_id = ? 
    AND e.escalated_to = 'campus_registrar' 
    AND e.status = 'pending' 
    AND (e.action_type = 'assignment' OR e.action_type = 'escalation')
");
if ($stmt) {
    $stmt->bind_param("i", $registrar_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $complaints[] = $row;
    }
    $stmt->close();
} else {
    error_log("Error preparing complaints query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaints.";
}

// Fetch University Registrars for escalation
$uni_registrars = [];
$uni_registrars_by_dept = [];

// Fetch all departments
$departments_query = "SELECT id, name FROM departments";
$departments_result = $db->query($departments_query);
$departments = [];
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments[$row['id']] = $row['name'];
    }
}

// Fetch University Registrars and group by department
$uni_registrars_query = "SELECT id, fname, lname, department FROM users WHERE role = 'university_registrar'";
$result = $db->query($uni_registrars_query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $uni_registrars[] = $row;
        $dept_name = $row['department'];
        $uni_registrars_by_dept[$dept_name][] = $row;
    }
} else {
    error_log("Error fetching University Registrars: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching University Registrars.";
}

// Function to send a stereotyped report to the President
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
    $uni_registrar_id = filter_input(INPUT_POST, 'uni_registrar_id', FILTER_VALIDATE_INT);
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
        header("Location: dashboard.php");
        exit;
    }
    if (strlen($resolution_details) < 10) {
        $_SESSION['error'] = "Escalation reason must be at least 10 characters long.";
        header("Location: dashboard.php");
        exit;
    }
    if (strlen($resolution_details) > 1000) {
        $_SESSION['error'] = "Escalation reason cannot exceed 1000 characters.";
        header("Location: dashboard.php");
        exit;
    }

    if (!$uni_registrar_id || !in_array($uni_registrar_id, array_column($uni_registrars, 'id'))) {
        $_SESSION['error'] = "Please select a valid University Registrar.";
        header("Location: dashboard.php");
        exit;
    }

    $complaint_query = "
        SELECT c.id, c.user_id, e.escalated_by_id, e.department_id, e.original_handler_id
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

    $user_id = $complaint_data['user_id'];
    $escalated_by_id = $complaint_data['escalated_by_id'];
    $department_id = $complaint_data['department_id'];
    $original_handler_id = $complaint_data['original_handler_id'];

    $db->begin_transaction();
    try {
        $update_escalation_sql = "UPDATE escalations SET status = 'escalated', resolution_details = ?, resolved_at = NULL WHERE id = ?";
        $update_escalation_stmt = $db->prepare($update_escalation_sql);
        if (!$update_escalation_stmt) {
            throw new Exception("An error occurred while updating the escalation status.");
        }
        $update_escalation_stmt->bind_param("si", $resolution_details, $escalation_id);
        $update_escalation_stmt->execute();
        $update_escalation_stmt->close();

        $new_escalation_sql = "INSERT INTO escalations (complaint_id, escalated_by_id, escalated_to_id, escalated_to, department_id, action_type, status, created_at, original_handler_id) 
                               VALUES (?, ?, ?, 'university_registrar', ?, 'escalation', 'pending', NOW(), ?)";
        $new_escalation_stmt = $db->prepare($new_escalation_sql);
        if (!$new_escalation_stmt) {
            throw new Exception("An error occurred while creating a new escalation record.");
        }
        $new_escalation_stmt->bind_param("iiiii", $complaint_id, $registrar_id, $uni_registrar_id, $department_id, $original_handler_id);
        $new_escalation_stmt->execute();
        $new_escalation_stmt->close();

        $update_complaint_sql = "UPDATE complaints SET status = 'escalated', resolution_details = NULL, resolution_date = NULL WHERE id = ?";
        $update_complaint_stmt = $db->prepare($update_complaint_sql);
        if (!$update_complaint_stmt) {
            throw new Exception("An error occurred while updating the complaint status.");
        }
        $update_complaint_stmt->bind_param("i", $complaint_id);
        $update_complaint_stmt->execute();
        $update_complaint_stmt->close();

        $notify_uni_registrar_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Complaint #$complaint_id has been escalated to you by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
        $notify_uni_registrar_stmt = $db->prepare($notify_uni_registrar_sql);
        if (!$notify_uni_registrar_stmt) {
            throw new Exception("An error occurred while notifying the University Registrar.");
        }
        $notify_uni_registrar_stmt->bind_param("iis", $uni_registrar_id, $complaint_id, $notification_desc);
        $notify_uni_registrar_stmt->execute();
        $notify_uni_registrar_stmt->close();

        $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Your complaint #$complaint_id has been escalated to the University Registrar by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
        $notify_user_stmt = $db->prepare($notify_user_sql);
        if (!$notify_user_stmt) {
            throw new Exception("An error occurred while notifying the complainant.");
        }
        $notify_user_stmt->bind_param("iis", $user_id, $complaint_id, $notification_desc);
        $notify_user_stmt->execute();
        $notify_user_stmt->close();

        $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Complaint #$complaint_id, which you assigned, has been escalated to the University Registrar by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
        $notify_handler_stmt = $db->prepare($notify_handler_sql);
        if (!$notify_handler_stmt) {
            throw new Exception("An error occurred while notifying the handler.");
        }
        $notify_handler_stmt->bind_param("iis", $escalated_by_id, $complaint_id, $notification_desc);
        $notify_handler_stmt->execute();
        $notify_handler_stmt->close();

        $additional_info = "Escalated to University Registrar: $resolution_details";
        if (!sendStereotypedReport($db, $complaint_id, $registrar_id, 'escalated', $additional_info)) {
            throw new Exception("Failed to send the report to the President.");
        }

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been escalated successfully.";
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

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $registrar_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Registrar Dashboard | DMU Complaint System</title>
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
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }

        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
            flex-grow: 1;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
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
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        .table-wrapper {
            overflow-x: auto;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        th, td {
            padding: 1rem;
            text-align: left;
            font-size: 0.95rem;
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }

        tr {
            border-bottom: 1px solid var(--light-gray);
            transition: background 0.3s ease;
        }

        tr:hover {
            background: var(--light);
        }

        td {
            color: var(--dark);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            margin-right: 5px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info) 0%, #138496 100%);
            color: white;
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #138496 0%, var(--info) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, var(--orange) 100%);
            color: var(--dark);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--orange) 0%, var(--warning) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow);
            position: relative;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content h3 {
            margin-bottom: 1.5rem;
            color: var(--primary-dark);
        }

        .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-btn:hover {
            color: var(--dark);
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

        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            width: 100%;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .group-name { font-weight: 600; font-size: 1.1rem; margin-bottom: 10px; }
        .social-links { display: flex; justify-content: center; gap: 20px; margin: 15px 0; }
        .social-links a { color: white; font-size: 1.5rem; transition: var(--transition); }
        .social-links a:hover { transform: translateY(-3px); color: var(--accent); }
        .copyright { font-size: 0.9rem; opacity: 0.8; }

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
            h3 { font-size: 1.2rem; }
            th, td { font-size: 0.9rem; padding: 0.75rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            th, td { font-size: 0.85rem; padding: 0.5rem; }
            .btn { width: 100%; margin-bottom: 5px; margin-right: 0; }
            .modal-content { padding: 1.5rem; }
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
                <span> Resolved Complaints</span>
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
                <span>DMU Complaint System - Campus Registrar</span>
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
            <h2>Campus Registrar Dashboard</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <h3>Assigned Complaints</h3>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Submitted On</th>
                            <th>Source</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($complaints)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center;">No complaints assigned to you at this time.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($complaint['id']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></td>
                                    <td>
                                        <?php
                                        $source = $complaint['action_type'] == 'escalation' ? 'Escalated by ' . ucfirst(str_replace('_', ' ', $complaint['escalated_by_role'])) : 'Assigned';
                                        echo htmlspecialchars($source);
                                        ?>
                                    </td>
                                    <td>
                                        <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="decide_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-gavel"></i> Decide
                                        </a>
                                        <button class="btn btn-warning btn-sm escalate-btn" data-complaint-id="<?php echo $complaint['id']; ?>" data-escalation-id="<?php echo $complaint['escalation_id']; ?>" data-department-id="<?php echo $complaint['department_id']; ?>">
                                            <i class="fas fa-arrow-up"></i> Escalate
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="escalateModal" class="modal">
            <div class="modal-content">
                <span class="close-btn">×</span>
                <h3>Escalate Complaint</h3>
                <form method="POST" id="escalateForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="escalate">
                    <input type="hidden" name="complaint_id" id="modal_complaint_id">
                    <input type="hidden" name="escalation_id" id="modal_escalation_id">
                    <div class="form-group">
                        <label for="resolution_details">Reason for Escalation</label>
                        <textarea name="resolution_details" id="resolution_details" rows="5" required placeholder="Enter the reason for escalating this complaint..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="uni_registrar_id">Select University Registrar</label>
                        <select name="uni_registrar_id" id="uni_registrar_id" required>
                            <option value="" disabled selected>Select a University Registrar</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-arrow-up"></i> Escalate</button>
                    <button type="button" class="btn btn-secondary close-modal"><i class="fas fa-times"></i> Cancel</button>
                </form>
            </div>
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
        const escalateButtons = document.querySelectorAll('.escalate-btn');
        const modal = document.getElementById('escalateModal');
        const closeButtons = document.querySelectorAll('.close-btn, .close-modal');
        const complaintIdInput = document.getElementById('modal_complaint_id');
        const escalationIdInput = document.getElementById('modal_escalation_id');
        const uniRegistrarSelect = document.getElementById('uni_registrar_id');

        const uniRegistrarsByDept = <?php echo json_encode($uni_registrars_by_dept); ?>;
        const departments = <?php echo json_encode($departments); ?>;

        escalateButtons.forEach(button => {
            button.addEventListener('click', function() {
                const complaintId = this.getAttribute('data-complaint-id');
                const escalationId = this.getAttribute('data-escalation-id');
                const departmentId = this.getAttribute('data-department-id');
                const departmentName = departments[departmentId] || null;

                complaintIdInput.value = complaintId;
                escalationIdInput.value = escalationId;

                uniRegistrarSelect.innerHTML = '<option value="" disabled selected>Select a University Registrar</option>';
                let registrars = [];
                if (departmentName && uniRegistrarsByDept[departmentName]) {
                    registrars = uniRegistrarsByDept[departmentName];
                } else {
                    Object.values(uniRegistrarsByDept).forEach(deptRegistrars => {
                        registrars = registrars.concat(deptRegistrars);
                    });
                }

                const uniqueRegistrars = Array.from(new Map(registrars.map(r => [r.id, r])).values());
                uniqueRegistrars.forEach(registrar => {
                    const option = document.createElement('option');
                    option.value = registrar.id;
                    option.textContent = `${registrar.fname} ${registrar.lname}`;
                    uniRegistrarSelect.appendChild(option);
                });

                modal.style.display = 'flex';
            });
        });

        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                modal.style.display = 'none';
                document.getElementById('escalateForm').reset();
            });
        });

        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
                document.getElementById('escalateForm').reset();
            }
        });

        document.getElementById('escalateForm').addEventListener('submit', function(event) {
            if (!confirm('Are you sure you want to escalate this complaint? This action cannot be undone.')) {
                event.preventDefault();
            }
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>