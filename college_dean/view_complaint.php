<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'college_dean'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'college_dean') {
    header("Location: ../unauthorized.php");
    exit;
}

$dean_id = $_SESSION['user_id'];
$dean = null;

// Fetch College Dean details
$sql_dean = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_dean = $db->prepare($sql_dean);
if ($stmt_dean) {
    $stmt_dean->bind_param("i", $dean_id);
    $stmt_dean->execute();
    $result_dean = $stmt_dean->get_result();
    if ($result_dean->num_rows > 0) {
        $dean = $result_dean->fetch_assoc();
    }
    $stmt_dean->close();
} else {
    error_log("Error preparing college dean query: " . $db->error);
    $_SESSION['error'] = "Database error fetching college dean details.";
}

// Check if complaint_id is provided
if (!isset($_GET['complaint_id']) || !filter_var($_GET['complaint_id'], FILTER_VALIDATE_INT)) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details
$complaint = null;
$sql_complaint = "
    SELECT c.*, 
           u_submitter.fname as submitter_fname, u_submitter.lname as submitter_lname,
           e.id as escalation_id, e.status as escalation_status, e.escalated_to, e.escalated_to_id, e.action_type
    FROM complaints c
    LEFT JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.escalated_to = 'college_dean' AND e.escalated_to_id = ?";
$stmt_complaint = $db->prepare($sql_complaint);
if ($stmt_complaint) {
    $stmt_complaint->bind_param("ii", $complaint_id, $dean_id);
    $stmt_complaint->execute();
    $result_complaint = $stmt_complaint->get_result();
    if ($result_complaint->num_rows > 0) {
        $complaint = $result_complaint->fetch_assoc();
    } else {
        $_SESSION['error'] = "Complaint not found or you do not have permission to view it.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_complaint->close();
} else {
    error_log("Error preparing complaint query: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaint details.";
}

// Fetch stereotypes for the complaint
$stereotypes = [];
$sql_stereotypes = "
    SELECT s.label
    FROM complaint_stereotypes cs
    JOIN stereotypes s ON cs.stereotype_id = s.id
    WHERE cs.complaint_id = ?";
$stmt = $db->prepare($sql_stereotypes);
if ($stmt) {
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $stereotypes[] = $row['label'];
    }
    $stmt->close();
}

// Fetch escalation history
$escalation_history = [];
$sql_escalations = "
    SELECT e.*, u_sender.fname as sender_fname, u_sender.lname as sender_lname,
           u_receiver.fname as receiver_fname, u_receiver.lname as receiver_lname
    FROM escalations e
    LEFT JOIN users u_sender ON e.escalated_by_id = u_sender.id
    LEFT JOIN users u_receiver ON e.escalated_to_id = u_receiver.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at ASC";
$stmt = $db->prepare($sql_escalations);
if ($stmt) {
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $escalation_history[] = $row;
    }
    $stmt->close();
}

// Fetch decision history
$decision_history = [];
$sql_decisions = "
    SELECT d.*, u_sender.fname as sender_fname, u_sender.lname as sender_lname,
           u_receiver.fname as receiver_fname, u_receiver.lname as receiver_lname
    FROM decisions d
    LEFT JOIN users u_sender ON d.sender_id = u_sender.id
    LEFT JOIN users u_receiver ON d.receiver_id = u_receiver.id
    WHERE d.complaint_id = ?
    ORDER BY d.created_at ASC";
$stmt = $db->prepare($sql_decisions);
if ($stmt) {
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $decision_history[] = $row;
    }
    $stmt->close();
}

// Fetch the Academic Vice President (for escalation)
$vp_query = "SELECT id FROM users WHERE role = 'academic_vp' LIMIT 1";
$vp_result = $db->query($vp_query);
if ($vp_result && $vp_result->num_rows > 0) {
    $vp = $vp_result->fetch_assoc();
    $academic_vp_id = $vp['id'];
} else {
    $academic_vp_id = null;
    error_log("No Academic Vice President found in the system.");
}

// Handle escalation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escalate_complaint']) && $academic_vp_id) {
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $decision_text = filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_STRING);

    if (!$complaint_id || empty($decision_text)) {
        $_SESSION['error'] = "Please provide a valid complaint ID and reason for escalation.";
    } else {
        // Insert the decision into the decisions table
        $insert_query = "
            INSERT INTO decisions (complaint_id, sender_id, receiver_id, decision_text, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ";
        $stmt = $db->prepare($insert_query);
        if ($stmt) {
            $stmt->bind_param("iiis", $complaint_id, $dean_id, $academic_vp_id, $decision_text);
            if ($stmt->execute()) {
                // Notify the Academic Vice President
                $notification_query = "
                    INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                    VALUES (?, ?, ?, 0, NOW())
                ";
                $notification_desc = "Complaint #$complaint_id has been escalated to you by the College Dean.";
                $stmt_notify = $db->prepare($notification_query);
                if ($stmt_notify) {
                    $stmt_notify->bind_param("iis", $academic_vp_id, $complaint_id, $notification_desc);
                    $stmt_notify->execute();
                    $stmt_notify->close();
                }

                // Update the escalation status
                $update_escalation_query = "
                    UPDATE escalations
                    SET status = 'escalated',
                        escalated_to = 'academic_vp',
                        escalated_to_id = ?
                    WHERE complaint_id = ? AND escalated_to = 'college_dean' AND escalated_to_id = ?
                ";
                $stmt_update = $db->prepare($update_escalation_query);
                if ($stmt_update) {
                    $stmt_update->bind_param("iii", $academic_vp_id, $complaint_id, $dean_id);
                    $stmt_update->execute();
                    $stmt_update->close();
                }

                $_SESSION['success'] = "Complaint escalated successfully to the Academic Vice President.";
            } else {
                $_SESSION['error'] = "Failed to escalate the complaint. Please try again.";
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error during escalation.";
        }
    }
    header("Location: view_complaint.php?complaint_id=$complaint_id");
    exit;
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $dean_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
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
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }

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

        .complaint-details {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .complaint-details p {
            margin: 0.5rem 0;
        }

        .complaint-details p strong {
            color: var(--primary-dark);
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border: 1px solid var(--light-gray);
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background: #e8f4ff;
        }

        .status {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-pending { background-color: rgba(255, 193, 7, 0.15); color: var(--warning); }
        .status-validated { background-color: rgba(13, 202, 240, 0.15); color: var(--info); }
        .status-in_progress { background-color: rgba(23, 162, 184, 0.15); color: var(--info); }
        .status-resolved { background-color: rgba(40, 167, 69, 0.15); color: var(--success); }
        .status-rejected { background-color: rgba(220, 53, 69, 0.15); color: var(--danger); }
        .status-assigned { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-escalated { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-uncategorized { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }

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
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-info { background: var(--info); color: white; }
        .btn-info:hover { background: #0baccc; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #218838; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-warning { background: var(--warning); color: var(--dark); }
        .btn-warning:hover { background: #e0a800; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-accent { background: var(--accent); color: white; }
        .btn-accent:hover { background: #3abde0; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-purple { background: var(--secondary); color: white; }
        .btn-purple:hover { background: #5a0a92; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-small { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content h4 {
            margin-bottom: 1rem;
            color: var(--primary-dark);
        }

        .modal-content .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-content .close:hover {
            color: var(--danger);
        }

        .modal-content form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .modal-content textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            resize: vertical;
            min-height: 120px;
        }

        .modal-content textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            background-color: #f9f9f9;
            border-radius: var(--radius);
            margin-top: 1rem;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
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
            .table-responsive table,
            .table-responsive thead,
            .table-responsive tbody,
            .table-responsive th,
            .table-responsive td,
            .table-responsive tr { display: block; }
            .table-responsive thead tr { position: absolute; top: -9999px; left: -9999px; }
            .table-responsive tr { margin-bottom: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: white; }
            .table-responsive tr:nth-child(even) { background-color: white; }
            .table-responsive td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 50% !important; text-align: left; white-space: normal; min-height: 30px; display: flex; align-items: center; }
            .table-responsive td:before { content: attr(data-label); position: absolute; left: 15px; width: 45%; padding-right: 10px; font-weight: bold; color: var(--primary); white-space: normal; text-align: left; }
            .table-responsive td:last-child { border-bottom: none; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .btn, .btn-small { width: 100%; margin-bottom: 5px; }
            .table-responsive td .btn, .table-responsive td .btn-small { width: auto; margin-bottom: 0;}
            td[data-label="Actions"] {
                padding-left: 15px !important;
                display: flex;
                flex-direction: column;
                gap: 5px;
                align-items: flex-start;
            }
            td[data-label="Actions"]::before {
                display: none;
            }
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
            <?php if ($dean): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dean['fname'] . ' ' . $dean['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dean['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>College Dean</h4>
                    <p>Role: College Dean</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up"></i>
                <span>Escalate Complaint</span>
            </a>

            <h3>Communication</h3>
            <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
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
                <span>DMU Complaint System - College Dean</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Complaint #<?php echo $complaint_id; ?> Details</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <div class="complaint-details">
                <h3>Complaint Information</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><strong>Category:</strong> <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?></p>
                <p><strong>Stereotypes:</strong> <?php echo !empty($stereotypes) ? htmlspecialchars(implode(', ', array_map('ucfirst', $stereotypes))) : '<span class="status status-uncategorized">None</span>'; ?></p>
                <p><strong>Status:</strong> <span class="status status-<?php echo strtolower($complaint['status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?></span></p>
                <p><strong>Escalation Status:</strong> <span class="status status-<?php echo strtolower($complaint['escalation_status']); ?>"><?php echo htmlspecialchars(ucfirst($complaint['escalation_status'])); ?></span></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></p>
                <p><strong>Submitted On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <div class="actions">
                <h3>Actions</h3>
                <?php if ($complaint['escalation_status'] == 'pending'): ?>
                    <a href="decide_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-purple" title="Make Decision">
                        <i class="fas fa-gavel"></i> Decide on Complaint
                    </a>
                    <?php if ($academic_vp_id): ?>
                        <button class="btn btn-warning escalate-btn" data-complaint-id="<?php echo $complaint['id']; ?>" title="Escalate to Academic VP">
                            <i class="fas fa-arrow-up"></i> Escalate to Academic VP
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No actions available. The complaint has already been handled or escalated further.</p>
                <?php endif; ?>
            </div>

            <div class="escalation-history">
                <h3>Escalation History</h3>
                <?php if (!empty($escalation_history)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Escalated By</th>
                                    <th>Escalated To</th>
                                    <th>Action Type</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($escalation_history as $escalation): ?>
                                    <tr>
                                        <td data-label="Escalated By"><?php echo htmlspecialchars($escalation['sender_fname'] . ' ' . $escalation['sender_lname']); ?></td>
                                        <td data-label="Escalated To"><?php echo htmlspecialchars($escalation['receiver_fname'] . ' ' . $escalation['receiver_lname']); ?></td>
                                        <td data-label="Action Type"><?php echo htmlspecialchars(ucfirst($escalation['action_type'])); ?></td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($escalation['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($escalation['status'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Date"><?php echo date('M j, Y H:i', strtotime($escalation['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No escalation history available for this complaint.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="decision-history">
                <h3>Decision History</h3>
                <?php if (!empty($decision_history)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Decision By</th>
                                    <th>Sent To</th>
                                    <th>Decision Text</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decision_history as $decision): ?>
                                    <tr>
                                        <td data-label="Decision By"><?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?></td>
                                        <td data-label="Sent To"><?php echo htmlspecialchars($decision['receiver_fname'] . ' ' . $decision['receiver_lname']); ?></td>
                                        <td data-label="Decision Text"><?php echo htmlspecialchars($decision['decision_text']); ?></td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($decision['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($decision['status'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Date"><?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-gavel"></i>
                        <p>No decision history available for this complaint.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="escalationModal" class="modal">
            <div class="modal-content">
                <span class="close">×</span>
                <h4>Escalate Complaint to Academic Vice President</h4>
                <form id="escalationForm" method="POST" action="">
                    <input type="hidden" name="complaint_id" id="escalationComplaintId" value="<?php echo $complaint_id; ?>">
                    <div class="form-group">
                        <label for="decision_text">Reason for Escalation:</label>
                        <textarea name="decision_text" id="decision_text" required placeholder="Explain why this complaint needs to be escalated to the Academic Vice President..."></textarea>
                    </div>
                    <button type="submit" name="escalate_complaint" class="btn btn-warning">Escalate Complaint</button>
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

            const modal = document.getElementById('escalationModal');
            const escalateButtons = document.querySelectorAll('.escalate-btn');
            const closeBtn = document.querySelector('.modal-content .close');
            const form = document.getElementById('escalationForm');

            escalateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    modal.style.display = 'flex';
                });
            });

            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                form.reset();
            });

            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    form.reset();
                }
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