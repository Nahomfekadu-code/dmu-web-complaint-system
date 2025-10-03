<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'handler') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null;

// --- Fetch handler details ---
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
    $_SESSION['error'] = "Database error fetching handler details.";
    header("Location: ../logout.php");
    exit;
}

// Check if complaint ID is provided in the URL
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details with user and handler information using a prepared statement
$stmt_complaint = $db->prepare("
    SELECT
        c.*,
        u_submitter.fname AS submitter_fname,
        u_submitter.lname AS submitter_lname,
        u_submitter.email AS submitter_email,
        u_handler.fname AS handler_fname,
        u_handler.lname AS handler_lname
    FROM complaints c
    JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN users u_handler ON c.handler_id = u_handler.id
    WHERE c.id = ?
");
$stmt_complaint->bind_param("i", $complaint_id);
$stmt_complaint->execute();
$result_complaint = $stmt_complaint->get_result();

if ($result_complaint->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result_complaint->fetch_assoc();
$stmt_complaint->close();

// Check if the current handler is authorized to view this complaint
if ($complaint['handler_id'] != $handler_id) {
    $_SESSION['error'] = "You are not authorized to view this complaint.";
    header("Location: dashboard.php");
    exit();
}

// Fetch escalation/assignment history with action_type and user who performed the action
$stmt_escalations = $db->prepare("
    SELECT
        e.*,
        u_actor.fname AS actor_fname,
        u_actor.lname AS actor_lname
    FROM escalations e
    JOIN users u_actor ON e.escalated_by_id = u_actor.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at ASC
");
if (!$stmt_escalations) {
    error_log("Prepare failed for escalations query: " . $db->error);
    $_SESSION['error'] = "Database error fetching escalation history.";
    header("Location: dashboard.php");
    exit;
}
$stmt_escalations->bind_param("i", $complaint_id);
$stmt_escalations->execute();
$escalations_result = $stmt_escalations->get_result();
$escalations = [];
if ($escalations_result) {
    $escalations = $escalations_result->fetch_all(MYSQLI_ASSOC);
}
$stmt_escalations->close();

// Determine if the complaint can be assigned, resolved, categorized, validated, or assigned to a committee
$can_assign = false;
$can_resolve = false;
$can_categorize = false;
$can_validate = false;
$can_assign_committee = false;
$can_start_committee_chat = false;

// Check if the current user is the assigned handler for this complaint
$is_assigned_handler = ($complaint['handler_id'] == $handler_id);

if ($is_assigned_handler) {
    // Categorize if pending and no category set
    if ($complaint['status'] === 'pending' && empty($complaint['category'])) {
        $can_categorize = true;
    }

    // Validate if pending, category is set, and no pending escalation/assignment actions
    if ($complaint['status'] === 'pending' && !empty($complaint['category'])) {
        $has_pending_action = false;
        if (!empty($escalations)) {
            $latest_action = end($escalations);
            if ($latest_action['status'] === 'pending' && in_array($latest_action['action_type'], ['escalation', 'assignment'])) {
                $has_pending_action = true;
            }
        }
        if (!$has_pending_action) {
            $can_validate = true;
        }
    }

    // Initialize $has_pending_action for other checks
    $has_pending_action = false;
    if (!empty($escalations)) {
        $latest_action = end($escalations);
        if ($latest_action['status'] === 'pending') {
            $has_pending_action = true;
        }
    }

    // Check if the complaint status is 'validated' and does not need a committee
    if ($complaint['status'] === 'validated' && $complaint['needs_committee'] != 1) {
        if (!$has_pending_action) {
            $can_assign = true;
        }
    }

    // Check if the complaint status is 'in_progress' and does not need a committee
    if ($complaint['status'] === 'in_progress' && $complaint['needs_committee'] != 1) {
        if (!empty($escalations)) {
            $latest_action = end($escalations);
            if ($latest_action && $latest_action['status'] === 'resolved') {
                $can_resolve = true;
            }
        }
    }

    // Handler can resolve a 'validated' complaint directly if no pending actions and does not need a committee
    if ($complaint['status'] === 'validated' && !$has_pending_action && $complaint['needs_committee'] != 1) {
        $can_resolve = true;
    }

    // Check if the complaint can have a committee assigned
    if ($complaint['status'] === 'validated' && $complaint['needs_committee'] == 1 && is_null($complaint['committee_id'])) {
        $can_assign_committee = true;
    }

    // Check if a committee chat can be started
    if (!is_null($complaint['committee_id']) && $complaint['needs_video_chat'] == 1) {
        $can_start_committee_chat = true;
    }
}

// Handle marking the complaint as needing a committee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_needs_committee'])) {
    $stmt = $db->prepare("UPDATE complaints SET needs_committee = 1 WHERE id = ? AND handler_id = ?");
    $stmt->bind_param("ii", $complaint_id, $handler_id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        $_SESSION['success'] = "Complaint marked as needing a committee.";
        header("Location: view_complaint.php?complaint_id=$complaint_id");
        exit();
    } else {
        $_SESSION['error'] = "Failed to mark complaint as needing a committee.";
    }
    $stmt->close();
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaint #<?php echo $complaint_id; ?> Details | DMU Complaint System</title>
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

        /* Vertical Navigation */
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
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px;}
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px;}
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Horizontal Navigation */
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
            box-shadow: 0 3px 8px rgba(0,0,0,0.07);
        }
        .alert i { font-size: 1.2rem; margin-right: 5px;}
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-warning { background-color: #fff8e1; border-color: #ffecb3; color: #856404; }
        .alert-info { background-color: #e1f5fe; border-color: #b3e5fc; color: #01579b; }

        /* Content Container */
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

        /* Page Header Styling */
        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }

        /* Card Styling */
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

        /* Complaint Details Specific Styling */
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            padding: 10px 0;
        }

        .detail-item {
            padding: 15px;
            background: var(--light);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 3px;
        }
        .detail-label i {
            color: var(--primary);
            width: 16px;
            text-align: center;
        }
        .detail-value {
            font-size: 0.95rem;
            color: var(--text-color);
            word-wrap: break-word;
        }
        .detail-value p {
            margin-bottom: 5px;
        }
        .detail-value p:last-child {
            margin-bottom: 0;
        }

        .description-item {
            grid-column: 1 / -1;
        }
        .escalation-item {
            grid-column: 1 / -1;
        }

        .status {
            padding: 4px 10px;
            border-radius: var(--radius);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
            letter-spacing: 0.5px;
            color: #fff;
            text-align: center;
            display: inline-block;
            line-height: 1.2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }
        .status-resolved { background-color: var(--success); }
        .status-pending { background-color: var(--warning); color: var(--dark); }
        .status-validated { background-color: var(--info); }
        .status-in_progress { background-color: var(--primary); }
        .status-rejected { background-color: var(--danger); }
        .status-pending_more_info { background-color: var(--orange); }
        .status-assigned { background-color: var(--orange); }
        .status-escalated { background-color: var(--orange); }
        .status-unknown { background-color: var(--gray); }

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
        .btn i {
            font-size: 1em;
            line-height: 1;
        }
        .btn-small {
            padding: 6px 12px;
            font-size: 0.8rem;
            gap: 5px;
        }
        .btn-info {
            background-color: var(--info);
            color: #fff;
        }
        .btn-info:hover {
            background-color: #12a1b6;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-danger {
            background-color: var(--danger);
            color: #fff;
        }
        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-warning {
            background-color: var(--warning);
            color: var(--dark);
        }
        .btn-warning:hover {
            background-color: #e0a800;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-success {
            background-color: var(--success);
            color: #fff;
        }
        .btn-success:hover {
            background-color: #218838;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-primary {
            background-color: var(--primary);
            color: #fff;
        }
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-accent {
            background-color: var(--accent);
            color: white;
        }
        .btn-accent:hover {
            background-color: #3abde0;
            box-shadow: var(--shadow-hover);
            transform: translateY(-1px);
        }
        .btn-purple {
            background-color: var(--secondary);
            color: white;
        }
        .btn-purple:hover {
            background-color: #5a067f;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .escalation-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .escalation-list li {
            padding: 12px 15px;
            background: #f9f9f9;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            margin-bottom: 10px;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .escalation-list li:last-child {
            margin-bottom: 0;
        }
        .escalation-list li strong {
            color: var(--primary-dark);
        }

        .action-buttons {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }

        /* Footer */
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

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav {
                width: 75px;
            }
            .vertical-nav .nav-header .logo-text,
            .vertical-nav .user-info,
            .vertical-nav .nav-menu h3,
            .vertical-nav .nav-link span {
                display: none;
            }
            .vertical-nav .nav-header .user-profile-mini i {
                font-size: 1.8rem;
            }
            .vertical-nav .user-profile-mini {
                padding: 8px;
                justify-content: center;
            }
            .vertical-nav .nav-link {
                justify-content: center;
                padding: 15px 10px;
            }
            .vertical-nav .nav-link i {
                margin-right: 0;
                font-size: 1.3rem;
            }
            .main-content {
                margin-left: 75px;
            }
            .horizontal-nav {
                left: 75px;
            }
            .main-footer {
                margin-left: 75px;
            }
        }
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 2px solid var(--primary-dark);
                flex-direction: column;
            }
            .vertical-nav .nav-header .logo-text,
            .vertical-nav .user-info {
                display: block;
            }
            .nav-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: none;
                padding-bottom: 10px;
            }
            .nav-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                padding: 5px 0;
                overflow-y: visible;
            }
            .nav-menu h3 {
                display: none;
            }
            .nav-link {
                flex-direction: row;
                width: auto;
                padding: 8px 12px;
            }
            .nav-link i {
                margin-right: 8px;
                margin-bottom: 0;
                font-size: 1rem;
            }
            .nav-link span {
                display: inline;
                font-size: 0.85rem;
            }
            .horizontal-nav {
                position: static;
                left: auto;
                right: auto;
                width: 100%;
                padding: 10px 15px;
                height: auto;
                flex-direction: column;
                align-items: stretch;
                border-radius: 0;
            }
            .top-nav-left {
                padding: 5px 0;
                text-align: center;
            }
            .top-nav-right {
                padding-top: 5px;
                justify-content: center;
                gap: 15px;
            }
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-top: 20px;
            }
            .main-footer {
                margin-left: 0;
            }
            .page-header h2 {
                font-size: 1.5rem;
            }
            .card {
                padding: 20px;
            }
            .card-header {
                font-size: 1.1rem;
            }
            .btn {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .escalation-list li {
                padding: 10px;
                font-size: 0.85rem;
            }
        }
        @media (max-width: 576px) {
            .content-container {
                padding: 1rem;
            }
            .card {
                padding: 15px;
            }
            .page-header h2 {
                font-size: 1.3rem;
            }
            .btn {
                padding: 7px 12px;
                font-size: 0.85rem;
                width: 100%;
            }
            .btn-small {
                padding: 5px 10px;
                font-size: 0.75rem;
            }
            .horizontal-nav .logo span {
                font-size: 1.1rem;
            }
            .nav-header .logo-text {
                font-size: 1.1rem;
            }
            .action-buttons {
                flex-direction: column;
            }
            .action-buttons .btn {
                width: 100%;
                margin-right: 0;
            }
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
            <?php if ($handler): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($handler['role']); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_assigned_complaints.php" class="nav-link <?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt fa-fw"></i>
                <span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle fa-fw"></i>
                <span>Resolved Complaints</span>
            </a>

            <h3>Communication</h3>
            <a href="manage_notices.php" class="nav-link <?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-bullhorn fa-fw"></i>
                <span>Manage Notices</span>
            </a>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>View Notifications</span>
            </a>
            <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel fa-fw"></i>
                <span>Decisions Received</span>
            </a>
            <a href="view_feedback.php" class="nav-link <?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-dots fa-fw"></i>
                <span>Complaint Feedback</span>
            </a>

            <h3>Reports</h3>
            <a href="generate_report.php" class="nav-link <?php echo $current_page == 'generate_report.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt fa-fw"></i>
                <span>Generate Report</span>
            </a>

            <h3>Account</h3>
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
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <div class="notification-icon" title="View Notifications">
                    <a href="view_notifications.php" style="color: inherit; text-decoration: none;">
                        <i class="fas fa-bell"></i>
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
                <h2>Complaint Details</h2>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Complaint #<?php echo $complaint['id']; ?> - <?php echo htmlspecialchars($complaint['title']); ?>
                </div>
                <div class="card-body">
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-id-badge"></i> Complaint ID</div>
                            <div class="detail-value">#<?php echo $complaint['id']; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-info-circle"></i> Status</div>
                            <div class="detail-value">
                                <span class="status status-<?php echo strtolower(htmlspecialchars($complaint['status'] ?? 'unknown')); ?>">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status'] ?? 'Unknown'))); ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-tag"></i> Category</div>
                            <div class="detail-value">
                                <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="text-muted">Not Categorized</span>'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-user"></i> Submitted By</div>
                            <div class="detail-value">
                                <?php echo ($complaint['visibility'] == 'anonymous' && $complaint['status'] != 'resolved') ? '<span class="text-muted">Anonymous</span>' : htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?>
                                <?php if ($complaint['visibility'] == 'standard'): ?>
                                    <br><small>(<?php echo htmlspecialchars($complaint['submitter_email']); ?>)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-calendar-alt"></i> Submitted On</div>
                            <div class="detail-value"><?php echo date('M j, Y, g:i A', strtotime($complaint['created_at'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-user-tie"></i> Assigned Handler</div>
                            <div class="detail-value">
                                <?php echo $complaint['handler_fname'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname']) : '<span class="text-muted">Not assigned</span>'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-user-tie"></i> Handler ID</div>
                            <div class="detail-value"><?php echo $complaint['handler_id'] ?? 'Not assigned'; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-users"></i> Needs Committee</div>
                            <div class="detail-value"><?php echo $complaint['needs_committee'] ? 'Yes' : 'No'; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-users"></i> Committee ID</div>
                            <div class="detail-value"><?php echo $complaint['committee_id'] ?? 'Not assigned'; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-eye"></i> Visibility</div>
                            <div class="detail-value"><?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-video"></i> Needs Video Chat</div>
                            <div class="detail-value"><?php echo $complaint['needs_video_chat'] ? 'Yes' : 'No'; ?></div>
                        </div>
                        <?php if ($complaint['evidence_file']): ?>
                            <div class="detail-item">
                                <div class="detail-label"><i class="fas fa-paperclip"></i> Evidence File</div>
                                <div class="detail-value">
                                    <a href="../Uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank" class="btn btn-info btn-small">
                                        <i class="fas fa-download"></i> View/Download Evidence
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="detail-item description-item">
                            <div class="detail-label"><i class="fas fa-align-left"></i> Description</div>
                            <div class="detail-value"><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></div>
                        </div>
                        <?php if ($complaint['status'] === 'resolved' && $complaint['resolution_details']): ?>
                            <div class="detail-item description-item">
                                <div class="detail-label"><i class="fas fa-check-double"></i> Resolution Details</div>
                                <div class="detail-value">
                                    <p><strong>Resolved On:</strong> <?php echo $complaint['resolution_date'] ? date('M j, Y, g:i A', strtotime($complaint['resolution_date'])) : 'N/A'; ?></p>
                                    <p><?php echo nl2br(htmlspecialchars($complaint['resolution_details'])); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($escalations)): ?>
                            <div class="detail-item escalation-item">
                                <div class="detail-label"><i class="fas fa-history"></i> Assignment/Escalation History</div>
                                <div class="detail-value">
                                    <ul class="escalation-list">
                                        <?php foreach ($escalations as $escalation): ?>
                                            <li>
                                                <strong>Action:</strong> <?php echo htmlspecialchars(ucfirst($escalation['action_type'])); ?><br>
                                                <strong><?php echo $escalation['action_type'] === 'assignment' ? 'Assigned To' : 'Escalated To'; ?>:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $escalation['escalated_to']))); ?><br>
                                                <strong>Performed By:</strong> <?php echo htmlspecialchars($escalation['actor_fname'] . ' ' . $escalation['actor_lname']); ?><br>
                                                <strong>On:</strong> <?php echo date('M j, Y, g:i A', strtotime($escalation['created_at'])); ?><br>
                                                <strong>Status:</strong>
                                                <span class="status status-<?php echo strtolower(htmlspecialchars($escalation['status'] ?? 'unknown')); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($escalation['status'] ?? 'Unknown')); ?>
                                                </span>
                                                <?php if ($escalation['status'] === 'resolved' && $escalation['resolved_at']): ?>
                                                    <br><strong>Resolved On:</strong> <?php echo date('M j, Y, g:i A', strtotime($escalation['resolved_at'])); ?>
                                                <?php endif; ?>
                                                <?php if ($escalation['resolution_details']): ?>
                                                    <br><strong>Action Details:</strong> <?php echo nl2br(htmlspecialchars($escalation['resolution_details'])); ?>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="action-buttons">
                            <a href="view_assigned_complaints.php" class="btn btn-info">
                                <i class="fas fa-arrow-left"></i> Back to Assigned List
                            </a>
                            <?php if ($is_assigned_handler && $complaint['status'] === 'validated' && $complaint['needs_committee'] != 1): ?>
                                <form method="POST">
                                    <input type="hidden" name="mark_needs_committee" value="1">
                                    <button type="submit" class="btn btn-purple">Mark as Needing Committee</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $can_categorize): ?>
                                <a href="validate.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-primary" title="Categorize Complaint">
                                    <i class="fas fa-sitemap"></i> Categorize
                                </a>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $can_validate): ?>
                                <a href="validate.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-primary" title="Validate Complaint">
                                    <i class="fas fa-check-circle"></i> Validate
                                </a>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $can_assign): ?>
                                <a href="assign_complaint_to_authority.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-warning">
                                    <i class="fas fa-level-up-alt"></i> Assign
                                </a>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $can_assign_committee): ?>
                                <a href="assign_committee.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-purple">
                                    <i class="fas fa-users"></i> Assign Committee
                                </a>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $can_start_committee_chat): ?>
                                <a href="start_committee_chat.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-accent">
                                    <i class="fas fa-video"></i> Start Committee Chat
                                </a>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $can_resolve): ?>
                                <a href="resolve_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-success">
                                    <i class="fas fa-check-double"></i> Mark as Resolved
                                </a>
                            <?php endif; ?>
                            <?php if ($is_assigned_handler && $complaint['status'] == 'pending_more_info'): ?>
                                <a href="request_info.php?complaint_id=<?php echo $complaint['id']; ?>&action=remind" class="btn btn-warning">
                                    <i class="fas fa-bell"></i> Remind User for Info
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Handler Panel
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