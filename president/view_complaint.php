<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'president'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'president') {
    header("Location: ../login.php");
    exit;
}

$president_id = $_SESSION['user_id'];
$president = null;

// Fetch President details
$sql_president = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_president = $db->prepare($sql_president);
if ($stmt_president) {
    $stmt_president->bind_param("i", $president_id);
    $stmt_president->execute();
    $result_president = $stmt_president->get_result();
    if ($result_president->num_rows > 0) {
        $president = $result_president->fetch_assoc();
    } else {
        $_SESSION['error'] = "President details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_president->close();
} else {
    error_log("Error preparing President query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: ../logout.php");
    exit;
}

$complaint_id = isset($_GET['complaint_id']) ? (int)$_GET['complaint_id'] : 0;
$from = isset($_GET['from']) ? $_GET['from'] : 'dashboard'; // Determine where the user came from

// Validate complaint_id
if ($complaint_id <= 0) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details
$complaint_query = "
    SELECT c.id, c.title, c.description, c.category, c.status as complaint_status, c.created_at, c.resolution_date, c.resolution_details,
           CONCAT(u.fname, ' ', u.lname) as complainant_name, u.id as complainant_id
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    WHERE c.id = ?";
$stmt_complaint = $db->prepare($complaint_query);
if (!$stmt_complaint) {
    error_log("Error preparing complaint query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaint details.";
    header("Location: dashboard.php");
    exit;
}

$stmt_complaint->bind_param("i", $complaint_id);
$stmt_complaint->execute();
$result_complaint = $stmt_complaint->get_result();
if ($result_complaint->num_rows == 0) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result_complaint->fetch_assoc();
$stmt_complaint->close();

// Fetch escalation history
$escalations = [];
$escalation_query = "
    SELECT e.id, e.escalated_to, e.escalated_to_id, e.status as escalation_status, e.created_at as escalated_at, e.resolved_at, e.resolution_details,
           CONCAT(eu.fname, ' ', eu.lname) as escalated_by_name, e.escalated_by_id,
           CONCAT(tu.fname, ' ', tu.lname) as escalated_to_name,
           hu.id as handler_id, CONCAT(hu.fname, ' ', hu.lname) as handler_name
    FROM escalations e
    JOIN users eu ON e.escalated_by_id = eu.id
    LEFT JOIN users tu ON e.escalated_to_id = tu.id
    JOIN users hu ON e.original_handler_id = hu.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at ASC";
$stmt_escalation = $db->prepare($escalation_query);
if ($stmt_escalation) {
    $stmt_escalation->bind_param("i", $complaint_id);
    $stmt_escalation->execute();
    $result_escalation = $stmt_escalation->get_result();
    while ($row = $result_escalation->fetch_assoc()) {
        $escalations[] = $row;
    }
    $stmt_escalation->close();
} else {
    error_log("Error preparing escalation query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching escalation history.";
}

// Fetch decisions
$decisions = [];
$decision_query = "
    SELECT d.id, d.decision_text, d.status as decision_status, d.created_at as decided_at,
           CONCAT(su.fname, ' ', su.lname) as sender_name, su.role as sender_role,
           CONCAT(ru.fname, ' ', ru.lname) as receiver_name, ru.role as receiver_role
    FROM decisions d
    JOIN users su ON d.sender_id = su.id
    JOIN users ru ON d.receiver_id = ru.id
    WHERE d.complaint_id = ?
    ORDER BY d.created_at ASC";
$stmt_decision = $db->prepare($decision_query);
if ($stmt_decision) {
    $stmt_decision->bind_param("i", $complaint_id);
    $stmt_decision->execute();
    $result_decision = $stmt_decision->get_result();
    while ($row = $result_decision->fetch_assoc()) {
        $decisions[] = $row;
    }
    $stmt_decision->close();
} else {
    error_log("Error preparing decision query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching decisions.";
}

// Fetch complaint logs
$logs = [];
$log_query = "
    SELECT cl.details, cl.created_at, cl.action,
           CONCAT(u.fname, ' ', u.lname) as user_name, u.role as user_role
    FROM complaint_logs cl
    JOIN users u ON cl.user_id = u.id
    ORDER BY cl.created_at ASC";
$stmt_log = $db->prepare($log_query);
if ($stmt_log) {
    $stmt_log->execute();
    $result_log = $stmt_log->get_result();
    while ($row = $result_log->fetch_assoc()) {
        // Parse the details field to extract the complaint ID
        if (preg_match('/Complaint #(\d+)/', $row['details'], $matches)) {
            $log_complaint_id = (int)$matches[1];
            if ($log_complaint_id === $complaint_id) {
                $logs[] = $row;
            }
        }
    }
    $stmt_log->close();
} else {
    error_log("Error preparing log query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaint logs.";
}

// Determine if the President can decide on this complaint
$can_decide = false;
$current_escalation = null;
foreach ($escalations as $escalation) {
    if ($escalation['escalated_to'] == 'president' && $escalation['escalated_to_id'] == $president_id && $escalation['escalation_status'] != 'resolved') {
        $can_decide = true;
        $current_escalation = $escalation;
        break;
    }
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $president_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result['count'];
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
    <title>View Complaint #<?php echo htmlspecialchars($complaint_id); ?> | President - DMU Complaint System</title>
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

        h3 {
            color: var(--primary-dark);
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }

        .complaint-details, .history-section {
            background: var(--light);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.05);
        }

        .complaint-details p {
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .complaint-details p strong {
            color: var(--dark);
            display: inline-block;
            width: 150px;
        }

        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 0.9rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }

        tr:hover {
            background-color: #f9f9f9;
        }

        .status-pending { color: var(--warning); font-weight: 500; }
        .status-validated { color: var(--info); font-weight: 500; }
        .status-in-progress { color: var(--info); font-weight: 500; }
        .status-resolved { color: var(--success); font-weight: 500; }
        .status-rejected { color: var(--danger); font-weight: 500; }
        .status-pending_more_info { color: var(--orange); font-weight: 500; }
        .status-escalated { color: var(--purple); font-weight: 500; }
        .status-final { color: var(--success); font-weight: 500; }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-right: 0.5rem;
        }

        .action-btn i {
            font-size: 0.9rem;
        }

        .btn-decide {
            background: var(--primary);
            color: white;
        }

        .btn-decide:hover {
            background: var(--primary-dark);
        }

        .btn-back {
            background: var(--gray);
            color: white;
        }

        .btn-back:hover {
            background: var(--dark);
        }

        .no-records {
            text-align: center;
            padding: 1rem;
            color: var(--gray);
            font-style: italic;
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
            th, td { font-size: 0.9rem; padding: 0.7rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .complaint-details p strong { width: 100px; }
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
                    <h4><?php echo htmlspecialchars($president['fname'] . ' ' . $president['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $president['role']))); ?></p>
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
                <span>DMU Complaint System - President</span>
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
            <h2>View Complaint #<?php echo htmlspecialchars($complaint_id); ?></h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'])); ?></p>
                <p><strong>Status:</strong> <span class="status-<?php echo strtolower($complaint['complaint_status']); ?>"><?php echo htmlspecialchars(ucfirst($complaint['complaint_status'])); ?></span></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['complainant_name'] ?? 'Anonymous'); ?></p>
                <p><strong>Submitted On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                <?php if ($complaint['complaint_status'] == 'resolved' && $complaint['resolution_date']): ?>
                    <p><strong>Resolved On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['resolution_date'])); ?></p>
                    <p><strong>Resolution Details:</strong> <?php echo htmlspecialchars($complaint['resolution_details'] ?? 'No details provided.'); ?></p>
                <?php endif; ?>
            </div>

            <div class="history-section">
                <h3>Escalation History</h3>
                <?php if (empty($escalations)): ?>
                    <p class="no-records">No escalation history available.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Escalation ID</th>
                                    <th>Escalated By</th>
                                    <th>Escalated To</th>
                                    <th>Original Handler</th>
                                    <th>Status</th>
                                    <th>Escalated On</th>
                                    <th>Resolved On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($escalations as $escalation): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($escalation['id']); ?></td>
                                        <td><?php echo htmlspecialchars($escalation['escalated_by_name']); ?></td>
                                        <td><?php echo htmlspecialchars($escalation['escalated_to_name'] ?? ucfirst($escalation['escalated_to'])); ?></td>
                                        <td><?php echo htmlspecialchars($escalation['handler_name']); ?></td>
                                        <td><span class="status-<?php echo strtolower($escalation['escalation_status']); ?>"><?php echo htmlspecialchars(ucfirst($escalation['escalation_status'])); ?></span></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($escalation['escalated_at'])); ?></td>
                                        <td><?php echo $escalation['resolved_at'] ? date('M j, Y H:i', strtotime($escalation['resolved_at'])) : 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="history-section">
                <h3>Decisions</h3>
                <?php if (empty($decisions)): ?>
                    <p class="no-records">No decisions have been made yet.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Decision ID</th>
                                    <th>Decision Text</th>
                                    <th>Status</th>
                                    <th>Made By</th>
                                    <th>Sent To</th>
                                    <th>Decided On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decisions as $decision): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($decision['id']); ?></td>
                                        <td><?php echo htmlspecialchars($decision['decision_text']); ?></td>
                                        <td><span class="status-<?php echo strtolower($decision['decision_status']); ?>"><?php echo htmlspecialchars(ucfirst($decision['decision_status'])); ?></span></td>
                                        <td><?php echo htmlspecialchars($decision['sender_name']) . ' (' . htmlspecialchars(ucfirst($decision['sender_role'])) . ')'; ?></td>
                                        <td><?php echo htmlspecialchars($decision['receiver_name']) . ' (' . htmlspecialchars(ucfirst($decision['receiver_role'])) . ')'; ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($decision['decided_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="history-section">
                <h3>Activity Log</h3>
                <?php if (empty($logs)): ?>
                    <p class="no-records">No activity logs available for this complaint.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>Performed By</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(ucfirst($log['action'])); ?></td>
                                        <td><?php echo htmlspecialchars($log['details']); ?></td>
                                        <td><?php echo htmlspecialchars($log['user_name']) . ' (' . htmlspecialchars(ucfirst($log['user_role'])) . ')'; ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($log['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="action-buttons">
                <a href="<?php echo $from == 'resolved' ? 'view_resolved.php' : 'dashboard.php'; ?>" class="action-btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <?php if ($can_decide): ?>
                    <a href="decide_complaint.php?complaint_id=<?php echo $complaint_id; ?>&escalation_id=<?php echo $current_escalation['id']; ?>" class="action-btn btn-decide">
                        <i class="fas fa-gavel"></i> Decide
                    </a>
                <?php endif; ?>
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