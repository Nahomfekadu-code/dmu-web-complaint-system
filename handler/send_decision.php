<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'handler') {
    header("Location: ../unauthorized.php");
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null;
$error = null;
$success = null;

// Fetch handler details (needed for nav bar)
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching handler details.";
}

// Fetch decisions received by the handler from higher authorities
$decisions_query = "
    SELECT d.id, c.id AS complaint_id, c.title, d.decision_text, d.status AS decision_status, 
           CONCAT(u.fname, ' ', u.lname) AS sender_name, u.role AS sender_role, 
           CONCAT(u2.fname, ' ', u2.lname) AS complainant_name,
           DATE_FORMAT(d.created_at, '%b %d, %Y, %h:%i %p') AS sent_on,
           d.file_path
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    JOIN users u ON d.sender_id = u.id
    JOIN users u2 ON c.user_id = u2.id
    WHERE d.receiver_id = ?
    AND u.role IN ('department_head', 'college_dean', 'academic_vp', 'president', 'sims', 'campus_registrar', 'university_registrar', 'cost_sharing', 'student_service_directorate', 'dormitory_service', 'students_food_service')
    AND d.status IN ('pending', 'final')
    ORDER BY d.created_at DESC
";
$stmt_decisions = $db->prepare($decisions_query);
if ($stmt_decisions === false) {
    error_log("Error preparing decisions query: " . $db->error);
    $_SESSION['error'] = "Database error fetching decisions.";
}
$stmt_decisions->bind_param("i", $handler_id);
$stmt_decisions->execute();
$decisions_result = $stmt_decisions->get_result();
$received_decisions = [];
while ($row = $decisions_result->fetch_assoc()) {
    $received_decisions[] = $row;
}
// Debug logging
error_log("Number of decisions received for handler $handler_id: " . count($received_decisions));
if (!empty($received_decisions)) {
    error_log("Decision statuses: " . implode(", ", array_column($received_decisions, 'decision_status')));
}
$stmt_decisions->close();

// Handle form submission to send decision to complainant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_decision'])) {
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $decision_text = trim(filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_SPECIAL_CHARS));

    // Validate input
    if (empty($complaint_id) || empty($decision_text)) {
        $error = "Please select a complaint and provide a decision.";
    } else {
        $db->begin_transaction();
        try {
            // 1. Get the complainant's ID
            $query_user = "SELECT user_id, status FROM complaints WHERE id = ?";
            $stmt_user = $db->prepare($query_user);
            if ($stmt_user === false) {
                throw new Exception("Failed to prepare complaint query: " . $db->error);
            }
            $stmt_user->bind_param("i", $complaint_id);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            $complaint_details = $result_user->fetch_assoc();
            $stmt_user->close();

            if (!$complaint_details) {
                throw new Exception("Complaint not found.");
            }
            if ($complaint_details['status'] !== 'resolved') {
                throw new Exception("Cannot send decision for a complaint that is not resolved.");
            }

            $complainant_id = $complaint_details['user_id'];

            // 2. Check if a final decision has already been sent
            $check_query = "SELECT id FROM decisions WHERE complaint_id = ? AND sender_id = ? AND receiver_id = ? AND status = 'final'";
            $check_stmt = $db->prepare($check_query);
            if ($check_stmt === false) {
                throw new Exception("Failed to prepare check decision query: " . $db->error);
            }
            $check_stmt->bind_param("iii", $complaint_id, $handler_id, $complainant_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_stmt->close();

            if ($check_result->num_rows > 0) {
                throw new Exception("A final decision has already been sent for this complaint.");
            }

            // 3. Generate text file
            $file_dir = __DIR__ . '/decisions/';
            if (!is_dir($file_dir)) {
                if (!mkdir($file_dir, 0777, true)) {
                    throw new Exception("Failed to create decisions directory.");
                }
            }

            $file_filename = 'decision_' . $complaint_id . '_' . time() . '.txt';
            $file_path = $file_dir . $file_filename;

            // Create text file content
            $content = "Decision Report\n";
            $content .= "==============\n\n";
            $content .= "Complaint ID: $complaint_id\n";
            $content .= "Decision Text: $decision_text\n";
            $content .= "Status: Final\n";
            $content .= "Sent By: " . $handler['fname'] . ' ' . $handler['lname'] . " (Handler)\n";
            $content .= "Date: " . date('M d, Y, h:i A') . "\n";

            // Save text file
            $file_handle = fopen($file_path, 'w');
            if ($file_handle === false) {
                throw new Exception("Failed to open text file for writing.");
            }
            if (fwrite($file_handle, $content) === false) {
                fclose($file_handle);
                throw new Exception("Failed to write to text file.");
            }
            fclose($file_handle);

            // 4. Insert the final decision with file path
            $insert_query = "
                INSERT INTO decisions (complaint_id, sender_id, receiver_id, decision_text, status, created_at, file_path)
                VALUES (?, ?, ?, ?, 'final', NOW(), ?)
            ";
            $stmt_insert = $db->prepare($insert_query);
            if ($stmt_insert === false) {
                throw new Exception("Failed to prepare insert decision query: " . $db->error);
            }
            $relative_file_path = 'decisions/' . $file_filename;
            $stmt_insert->bind_param("iiiss", $complaint_id, $handler_id, $complainant_id, $decision_text, $relative_file_path);
            $stmt_insert->execute();

            if ($stmt_insert->affected_rows === 0) {
                throw new Exception("Failed to record the decision.");
            }
            $stmt_insert->close();

            // 5. Notify the complainant
            $notification_query = "
                INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ";
            $notification_desc = "You received a final decision regarding Complaint #$complaint_id.";
            $stmt_notify = $db->prepare($notification_query);
            if ($stmt_notify === false) {
                throw new Exception("Failed to prepare notification query: " . $db->error);
            }
            $stmt_notify->bind_param("iis", $complainant_id, $complaint_id, $notification_desc);
            $stmt_notify->execute();
            $stmt_notify->close();

            $db->commit();
            $_SESSION['success'] = "Decision sent successfully to the complainant for Complaint #$complaint_id.";
            header("Location: send_decision.php");
            exit;

        } catch (Exception $e) {
            $db->rollback();
            $error = "Error sending decision: " . $e->getMessage();
            error_log("Send decision error: " . $e->getMessage());
        }
    }
}

// Fetch complaints for the dropdown (only resolved complaints for which a final decision hasn't been sent)
$dropdown_query = "
    SELECT c.id, c.title, CONCAT(u.fname, ' ', u.lname) AS complainant_name
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN decisions d_sent ON c.id = d_sent.complaint_id 
        AND d_sent.sender_id = ?
        AND d_sent.receiver_id = c.user_id
        AND d_sent.status = 'final'
    WHERE c.status = 'resolved'
    AND d_sent.id IS NULL
    ORDER BY c.id
";
$stmt_dropdown = $db->prepare($dropdown_query);
if ($stmt_dropdown === false) {
    error_log("Error preparing dropdown query: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaints.";
}
$stmt_dropdown->bind_param("i", $handler_id);
$stmt_dropdown->execute();
$dropdown_result = $stmt_dropdown->get_result();

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

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Decision | Handler | DMU Complaint System</title>
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
            margin-top: 2rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
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

        td.description {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        td.description[title]:hover {
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
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
        .status-action_required { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-informational { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }
        .status-uncategorized { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }
        .status-final { background-color: rgba(40, 167, 69, 0.15); color: var(--success); }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        select:focus, textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
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
        }

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-download { background: linear-gradient(135deg, var(--success) 0%, #218838 100%); color: white; }
        .btn-download:hover { background: linear-gradient(135deg, #218838 0%, var(--success) 100%); box-shadow: var(--shadow-hover); transform: translateY(-2px); }

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
            td.description { max-width: 100%; white-space: normal; }
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
            <?php if ($handler): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $handler['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4>Handler</h4>
                    <p>Role: Handler</p>
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
            <a href="assign_complaint.php" class="nav-link <?php echo $current_page == 'assign_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i>
                <span>Assign Complaint</span>
            </a>
            <a href="send_decision.php" class="nav-link <?php echo $current_page == 'send_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span>Send Decision</span>
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
            <h2>Send Decision to Complainant</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="complaint_id">Select Complaint</label>
                    <select name="complaint_id" id="complaint_id" required>
                        <option value="">-- Select a Complaint --</option>
                        <?php while ($complaint = $dropdown_result->fetch_assoc()): ?>
                            <option value="<?php echo $complaint['id']; ?>">
                                Complaint #<?php echo $complaint['id']; ?> - <?php echo htmlspecialchars($complaint['title']); ?> (Complainant: <?php echo htmlspecialchars($complaint['complainant_name']); ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="decision_text">Decision</label>
                    <textarea name="decision_text" id="decision_text" required placeholder="Enter the final decision to send to the complainant..."></textarea>
                </div>
                <button type="submit" name="send_decision" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Send Decision
                </button>
            </form>

            <h3>Decisions Received from Authorities</h3>
            <?php if (!empty($received_decisions)): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Complaint ID</th>
                                <th>Title</th>
                                <th>Complainant</th>
                                <th>Decision</th>
                                <th>Status</th>
                                <th>Sent By</th>
                                <th>Sent On</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($received_decisions as $decision): ?>
                                <tr>
                                    <td data-label="Complaint ID"><?php echo $decision['complaint_id']; ?></td>
                                    <td data-label="Title"><?php echo htmlspecialchars($decision['title']); ?></td>
                                    <td data-label="Complainant"><?php echo htmlspecialchars($decision['complainant_name']); ?></td>
                                    <td data-label="Decision" class="description" title="<?php echo htmlspecialchars($decision['decision_text']); ?>">
                                        <?php echo htmlspecialchars($decision['decision_text']); ?>
                                    </td>
                                    <td data-label="Status">
                                        <span class="status status-<?php echo strtolower($decision['decision_status']); ?>">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['decision_status']))); ?>
                                        </span>
                                    </td>
                                    <td data-label="Sent By">
                                        <?php echo htmlspecialchars($decision['sender_name']) . ' (' . htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['sender_role']))) . ')'; ?>
                                    </td>
                                    <td data-label="Sent On"><?php echo $decision['sent_on']; ?></td>
                                    <td data-label="Download">
                                        <?php if (!empty($decision['file_path']) && file_exists(__DIR__ . '/' . $decision['file_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($decision['file_path']); ?>" class="btn btn-download" download>
                                                <i class="fas fa-download"></i> Download File
                                            </a>
                                        <?php else: ?>
                                            <span>No File</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No decisions have been received from authorities yet.</p>
                </div>
            <?php endif; ?>
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
        });
    </script>
</body>
</html>
<?php
$stmt_dropdown->close();
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>