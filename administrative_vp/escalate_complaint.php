<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'administrative_vp'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'administrative_vp') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$vp_id = $_SESSION['user_id'];

// Prevent direct access without valid referer
if (!isset($_SERVER['HTTP_REFERER']) || !str_contains($_SERVER['HTTP_REFERER'], 'dashboard.php')) {
    error_log("Unauthorized access to escalate_complaint.php from: " . ($_SERVER['HTTP_REFERER'] ?? 'unknown'));
    $_SESSION['error'] = "Please select a complaint from the dashboard to escalate.";
    header("Location: dashboard.php");
    exit;
}

// Generate CSRF token if not already set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch Administrative VP details
$sql_vp = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_vp = $db->prepare($sql_vp);
if (!$stmt_vp) {
    error_log("Prepare failed for user fetch: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: ../logout.php");
    exit;
}
$stmt_vp->bind_param("i", $vp_id);
$stmt_vp->execute();
$vp = $stmt_vp->get_result()->fetch_assoc();
$stmt_vp->close();

if (!$vp) {
    $_SESSION['error'] = "Administrative Vice President details not found.";
    header("Location: ../logout.php");
    exit;
}

// Fetch notification count
$sql_notif_count = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif_count = $db->prepare($sql_notif_count);
$stmt_notif_count->bind_param("i", $vp_id);
$stmt_notif_count->execute();
$notification_count = $stmt_notif_count->get_result()->fetch_assoc()['count'];
$stmt_notif_count->close();

// Check if both complaint_id and escalation_id are provided
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id']) || 
    !isset($_GET['escalation_id']) || !is_numeric($_GET['escalation_id'])) {
    $debug_message = "Invalid parameters in escalate_complaint.php: " . json_encode([
        'complaint_id' => $_GET['complaint_id'] ?? 'missing',
        'escalation_id' => $_GET['escalation_id'] ?? 'missing',
        'referer' => $_SERVER['HTTP_REFERER'] ?? 'unknown'
    ]);
    error_log($debug_message);
    $_SESSION['error'] = "Invalid complaint or escalation ID. Please select a complaint from the dashboard.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];
$escalation_id = (int)$_GET['escalation_id'];

// Fetch complaint details (only complaints escalated to this Administrative VP)
$stmt = $db->prepare("
    SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, 
           u.fname, u.lname, e.escalated_by_id, e.original_handler_id
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.id = ? AND e.escalated_to = 'administrative_vp' 
    AND e.escalated_to_id = ? AND e.status = 'pending' 
    AND e.action_type IN ('escalation', 'assignment')
");
if (!$stmt) {
    error_log("Prepare failed: " . $db->error);
    $_SESSION['error'] = "Database error while fetching complaint.";
    header("Location: dashboard.php");
    exit;
}

$stmt->bind_param("iii", $complaint_id, $escalation_id, $vp_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Debug: Log why the validation failed
    $debug_sql = "SELECT * FROM escalations WHERE complaint_id = ? AND id = ?";
    $debug_stmt = $db->prepare($debug_sql);
    $debug_stmt->bind_param("ii", $complaint_id, $escalation_id);
    $debug_stmt->execute();
    $debug_result = $debug_stmt->get_result();
    $escalation = $debug_result->fetch_assoc();
    $debug_stmt->close();

    $debug_message = "Complaint validation failed in escalate_complaint.php: " . json_encode([
        'complaint_id' => $complaint_id,
        'escalation_id' => $escalation_id,
        'vp_id' => $vp_id,
        'escalation_found' => !empty($escalation),
        'escalation_details' => $escalation
    ]);
    error_log($debug_message);

    // Provide specific error message
    if ($escalation) {
        if ($escalation['status'] !== 'pending') {
            $_SESSION['error'] = "This complaint has already been processed (status: " . htmlspecialchars($escalation['status']) . ").";
        } elseif ($escalation['escalated_to'] !== 'administrative_vp' || $escalation['escalated_to_id'] != $vp_id) {
            $_SESSION['error'] = "This complaint is not assigned or escalated to you.";
        } else {
            $_SESSION['error'] = "Complaint not found or not accessible.";
        }
    } else {
        $_SESSION['error'] = "No escalation or assignment record found for this complaint.";
    }
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$handler_id = $complaint['original_handler_id'];
$stmt->close();

// Validate handler
$handler_query = "SELECT id FROM users WHERE id = ? AND role = 'handler'";
$handler_stmt = $db->prepare($handler_query);
$handler_stmt->bind_param("i", $handler_id);
$handler_stmt->execute();
$handler_result = $handler_stmt->get_result();
if ($handler_result->num_rows === 0) {
    error_log("Invalid handler_id: $handler_id for complaint_id: $complaint_id");
    $_SESSION['error'] = "Invalid handler for this complaint.";
    header("Location: dashboard.php");
    exit;
}
$handler_stmt->close();

// Fetch the President for escalation
$sql = "SELECT id, fname, lname FROM users WHERE role = 'president' LIMIT 1";
$result = $db->query($sql);
if (!$result || $result->num_rows === 0) {
    error_log("No President found in users table.");
    $_SESSION['error'] = "No President available for escalation.";
    header("Location: dashboard.php");
    exit;
}
$president = $result->fetch_assoc();
$escalated_to_id = $president['id'];

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '') {
    // Fetch complaint details
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) {
        error_log("Complaint #$complaint_id not found for report generation.");
        $stmt_complaint->close();
        return "Complaint not found for report generation.";
    }
    $complaint = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    // Fetch sender details
    $sql_sender = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_sender = $db->prepare($sql_sender);
    $stmt_sender->bind_param("i", $sender_id);
    $stmt_sender->execute();
    $sender_result = $stmt_sender->get_result();
    if ($sender_result->num_rows === 0) {
        error_log("Sender #$sender_id not found for report generation.");
        $stmt_sender->close();
        return "Sender not found for report generation.";
    }
    $sender = $sender_result->fetch_assoc();
    $stmt_sender->close();

    // The recipient is the President
    $recipient_id = $GLOBALS['escalated_to_id'];

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
    $report_content .= "Processed By: {$sender['fname']} {$sender['lname']}\n";
    $report_content .= "Created At: " . date('M j, Y H:i', strtotime($complaint['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info: $additional_info\n";
    }

    // Insert the report into the stereotyped_reports table
    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content) VALUES (?, ?, ?, ?, ?)";
    $stmt_report = $db->prepare($sql_report);
    $stmt_report->bind_param("iiiss", $complaint_id, $sender_id, $recipient_id, $report_type, $report_content);
    if (!$stmt_report->execute()) {
        error_log("Failed to insert stereotyped report: " . $stmt_report->error);
        $stmt_report->close();
        return "Failed to generate the report.";
    }
    $stmt_report->close();

    // Notify the President
    $notification_desc = "A new $report_type report for Complaint #{$complaint['id']} has been submitted by {$sender['fname']} {$sender['lname']}.";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
    $stmt_notify = $db->prepare($sql_notify);
    $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
    if (!$stmt_notify->execute()) {
        error_log("Failed to notify President: " . $stmt_notify->error);
        $stmt_notify->close();
        return "Failed to notify the President.";
    }
    $stmt_notify->close();

    return true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid CSRF token.";
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $decision_text = trim(htmlspecialchars($_POST['decision_text'] ?? ''));

    if (!$decision_text) {
        $_SESSION['error'] = "Please provide a reason for escalation.";
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $escalated_to = 'president';

    $db->begin_transaction();
    try {
        // Insert new escalation record
        $escalation_sql = "INSERT INTO escalations (complaint_id, escalated_to, escalated_to_id, escalated_by_id, status, original_handler_id, action_type) 
                           VALUES (?, ?, ?, ?, 'pending', ?, 'escalation')";
        $escalation_stmt = $db->prepare($escalation_sql);
        $escalation_stmt->bind_param("isiii", $complaint_id, $escalated_to, $escalated_to_id, $vp_id, $handler_id);
        $escalation_stmt->execute();
        $escalation_stmt->close();

        // Update current escalation status to forwarded
        $update_escalation_sql = "UPDATE escalations SET status = 'forwarded', resolution_details = ? WHERE id = ?";
        $update_escalation_stmt = $db->prepare($update_escalation_sql);
        $update_escalation_stmt->bind_param("si", $decision_text, $escalation_id);
        $update_escalation_stmt->execute();
        $update_escalation_stmt->close();

        // Update complaint status to in_progress
        $update_complaint_sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
        $update_complaint_stmt = $db->prepare($update_complaint_sql);
        $update_complaint_stmt->bind_param("i", $complaint_id);
        $update_complaint_stmt->execute();
        $update_complaint_stmt->close();

        // Notify the President
        $notify_escalated_sql = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
        $notification_desc = "A complaint (ID #$complaint_id) has been escalated to you for review: $decision_text";
        $notify_escalated_stmt = $db->prepare($notify_escalated_sql);
        $notify_escalated_stmt->bind_param("iis", $escalated_to_id, $complaint_id, $notification_desc);
        $notify_escalated_stmt->execute();
        $notify_escalated_stmt->close();

        // Notify the handler
        $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
        $notification_desc = "Complaint #$complaint_id has been escalated to the President by the Administrative Vice President: $decision_text";
        $notify_handler_stmt = $db->prepare($notify_handler_sql);
        $notify_handler_stmt->bind_param("iis", $handler_id, $complaint_id, $notification_desc);
        $notify_handler_stmt->execute();
        $notify_handler_stmt->close();

        // Notify the complainant
        $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                           SELECT user_id, ?, 'Your complaint has been escalated to the President: $decision_text' 
                           FROM complaints WHERE id = ?";
        $notify_user_stmt = $db->prepare($notify_user_sql);
        $notify_user_stmt->bind_param("ii", $complaint_id, $complaint_id);
        $notify_user_stmt->execute();
        $notify_user_stmt->close();

        // Send stereotyped report to the President
        $additional_info = "Escalated to the President: $decision_text";
        $report_result = sendStereotypedReport($db, $complaint_id, $vp_id, 'escalated', $additional_info);
        if ($report_result !== true) {
            throw new Exception("Failed to send stereotyped report: $report_result");
        }

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been escalated to the President successfully.";
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        error_log("Escalation error: " . GEOIP_COUNTRY_CODE->getMessage());
        $_SESSION['error'] = "An error occurred: " . $e->getMessage();
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
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
    <title>Escalate Complaint #<?php echo $complaint_id; ?> | Administrative VP | DMU Complaint System</title>
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
            --background: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 6px 18px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50;
            --navbar-link: #bdc3c7;
            --navbar-link-hover: #34495e;
            --navbar-link-active: var(--primary);
            --topbar-bg: #ffffff;
            --topbar-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Montserrat', sans-serif;
        }

        body {
            background-color: var(--background);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--navbar-bg) 0%, #34495e 100%);
            color: #ecf0f1;
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
        }

        .nav-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(236, 240, 241, 0.1);
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%;
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.05);
            padding: 8px 12px;
            border-radius: var(--radius);
            margin-top: 10px;
        }

        .user-profile-mini i {
            font-size: 2rem;
            color: var(--accent);
        }

        .user-info h4 {
            font-size: 0.95rem;
            margin-bottom: 2px;
            font-weight: 500;
        }

        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: capitalize;
        }

        .nav-menu {
            padding: 0 10px;
            flex-grow: 1;
            overflow-y: auto;
        }

        .nav-menu::-webkit-scrollbar { width: 6px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }

        .nav-menu h3 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 25px 15px 10px;
            opacity: 0.6;
            font-weight: 600;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            color: var(--navbar-link);
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
            font-size: 0.95rem;
            font-weight: 400;
            white-space: nowrap;
        }

        .nav-link:hover {
            background: var(--navbar-link-hover);
            color: #ecf0f1;
            transform: translateX(3px);
        }

        .nav-link.active {
            background: var(--navbar-link-active);
            color: white;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1em;
            opacity: 0.8;
        }

        .nav-link.active i {
            opacity: 1;
        }

        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .horizontal-nav {
            background: var(--topbar-bg);
            border-radius: var(--radius);
            box-shadow: var(--topbar-shadow);
            padding: 12px 25px;
            margin-bottom: 25px;
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
            align-items: center;
            gap: 15px;
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .horizontal-menu a i {
            font-size: 1rem;
            color: var(--gray);
        }

        .horizontal-menu a:hover i, .horizontal-menu a.active i {
            color: var(--primary-dark);
        }

        .notification-icon {
            position: relative;
        }

        .notification-icon i {
            font-size: 1.3rem;
            color: var(--gray);
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .notification-icon:hover i {
            color: var(--primary);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -8px;
            background-color: var(--danger);
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
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
            box-shadow: 0 3px 8px rgba(0,0,0,0.07);
        }

        .alert i { font-size: 1.2rem; margin-right: 5px; }
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }

        .content-container {
            background: var(--card-bg);
            padding: 2rem;
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

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }

        .card {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.3rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header i {
            font-size: 1.4rem;
            color: var(--primary);
            margin-right: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--primary-dark);
        }

        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--text-color);
            transition: border-color 0.3s ease;
            resize: vertical;
            min-height: 100px;
        }

        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            line-height: 1.5;
            white-space: nowrap;
        }

        .btn i { font-size: 1em; line-height: 1; }
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-secondary { background-color: var(--gray); color: #fff; }
        .btn-secondary:hover { background-color: #5a6268; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: var(--shadow-hover); }

        .complaint-details {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }

        .complaint-details p {
            margin: 0.5rem 0;
        }

        .main-footer {
            background-color: var(--card-bg);
            padding: 15px 30px;
            margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-muted);
            flex-shrink: 0;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .vertical-nav { width: 75px; }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info, .vertical-nav .nav-menu h3, .vertical-nav .nav-link span { display: none; }
            .vertical-nav .nav-header .user-profile-mini i { font-size: 1.8rem; }
            .vertical-nav .user-profile-mini { padding: 8px; justify-content: center; }
            .vertical-nav .nav-link { justify-content: center; padding: 15px 10px; }
            .vertical-nav .nav-link i { margin-right: 0; font-size: 1.3rem; }
            .main-content { margin-left: 75px; }
            .horizontal-nav { left: 75px; }
            .main-footer { margin-left: 75px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 2px solid var(--primary-dark);
                flex-direction: column;
            }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block; }
            .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 10px; }
            .nav-menu { display: flex; flex-wrap: wrap; justify-content: center; padding: 5px 0; overflow-y: visible; }
            .nav-menu h3 { display: none; }
            .nav-link { flex-direction: row; width: auto; padding: 8px 12px; }
            .nav-link i { margin-right: 8px; margin-bottom: 0; font-size: 1rem; }
            .nav-link span { display: inline; font-size: 0.85rem; }
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .main-footer { margin-left: 0; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .btn-small { padding: 5px 10px; font-size: 0.75rem; }
            .complaint-details { padding: 1rem; }
            .form-group textarea { font-size: 0.85rem; min-height: 80px; }
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
                    <h4><?php echo htmlspecialchars($vp['fname'] . ' ' . $vp['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($vp['role']); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle fa-fw"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel fa-fw"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up fa-fw"></i>
                <span>Escalate Complaint</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>Notifications</span>
            </a>

            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit fa-fw"></i>
                <span>Edit Profile</span>
            </a>
            <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                <i class="fas fa-key fa-fw"></i>
                <span>Change Password</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Administrative VP</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <div class="notification-icon" title="View Notifications">
                    <a href="view_notifications.php" style="color: inherit; text-decoration: none;">
                        <i class="fas fa-bell"></i>
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Page Specific Content -->
        <div class="content-container">
            <div class="page-header">
                <h2>Escalate Complaint #<?php echo $complaint_id; ?></h2>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details Card -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-file-alt"></i> Complaint Details</span>
                </div>
                <div class="card-body">
                    <div class="complaint-details">
                        <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                        <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                        <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></p>
                        <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                        <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?></p>
                        <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Escalation Form Card -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-arrow-up"></i> Escalate to President</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label>Escalate To President</label>
                            <p>Escalating to: <?php echo htmlspecialchars("{$president['fname']} {$president['lname']}"); ?></p>
                        </div>
                        <div class="form-group">
                            <label for="decision_text">Reason for Escalation</label>
                            <textarea name="decision_text" id="decision_text" rows="5" required placeholder="Enter the reason for escalating this complaint..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-up"></i> Escalate Complaint</button>
                        <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
                    </form>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Administrative Vice President Panel
        </footer>
    </div>

    <script>
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