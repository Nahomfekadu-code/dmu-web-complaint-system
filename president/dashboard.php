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

// Fetch complaint counts
$pending_count = $in_progress_count = $resolved_count = 0;

// Pending complaints (escalated to President, status 'pending')
$pending_query = "
    SELECT COUNT(*) as count 
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'president' 
    AND e.escalated_to_id = ? 
    AND e.status = 'pending'";
$pending_stmt = $db->prepare($pending_query);
if ($pending_stmt) {
    $pending_stmt->bind_param("i", $president_id);
    $pending_stmt->execute();
    $pending_result = $pending_stmt->get_result()->fetch_assoc();
    $pending_count = $pending_result['count'];
    $pending_stmt->close();
} else {
    error_log("Error preparing pending complaints query: " . $db->error);
}

// In-progress complaints (escalated to President, status 'in_progress')
$in_progress_query = "
    SELECT COUNT(*) as count 
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'president' 
    AND e.escalated_to_id = ? 
    AND e.status = 'in_progress'";
$in_progress_stmt = $db->prepare($in_progress_query);
if ($in_progress_stmt) {
    $in_progress_stmt->bind_param("i", $president_id);
    $in_progress_stmt->execute();
    $in_progress_result = $in_progress_stmt->get_result()->fetch_assoc();
    $in_progress_count = $in_progress_result['count'];
    $in_progress_stmt->close();
} else {
    error_log("Error preparing in-progress complaints query: " . $db->error);
}

// Resolved complaints (handled by President)
$resolved_query = "
    SELECT COUNT(*) as count 
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'president' 
    AND e.escalated_to_id = ? 
    AND e.status = 'resolved'";
$resolved_stmt = $db->prepare($resolved_query);
if ($resolved_stmt) {
    $resolved_stmt->bind_param("i", $president_id);
    $resolved_stmt->execute();
    $resolved_result = $resolved_stmt->get_result()->fetch_assoc();
    $resolved_count = $resolved_result['count'];
    $resolved_stmt->close();
} else {
    error_log("Error preparing resolved complaints query: " . $db->error);
}

// Fetch complaints escalated to President
$complaints = [];
$complaints_query = "
    SELECT c.id, c.title, c.description, c.category, c.status as complaint_status, c.created_at, 
           e.id as escalation_id, e.status as escalation_status, e.created_at as escalated_at,
           CONCAT(u.fname, ' ', u.lname) as escalated_by_name, e.escalated_by_id
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    JOIN users u ON e.escalated_by_id = u.id
    WHERE e.escalated_to = 'president' 
    AND e.escalated_to_id = ?
    ORDER BY e.created_at DESC";
$stmt_complaints = $db->prepare($complaints_query);
if ($stmt_complaints) {
    $stmt_complaints->bind_param("i", $president_id);
    $stmt_complaints->execute();
    $result_complaints = $stmt_complaints->get_result();
    while ($row = $result_complaints->fetch_assoc()) {
        $complaints[] = $row;
    }
    $stmt_complaints->close();
} else {
    error_log("Error preparing complaints query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaints.";
}

// Fetch committees the president is a member of
$sql_committees = "
    SELECT cm.committee_id, co.name AS committee_name, c.id AS complaint_id, c.title AS complaint_title
    FROM committee_members cm
    JOIN committees co ON cm.committee_id = co.id
    JOIN complaints c ON c.committee_id = cm.committee_id
    WHERE cm.user_id = ?";
$stmt_committees = $db->prepare($sql_committees);
$committees = [];
if ($stmt_committees) {
    $stmt_committees->bind_param("i", $president_id);
    $stmt_committees->execute();
    $result_committees = $stmt_committees->get_result();
    while ($row = $result_committees->fetch_assoc()) {
        $committees[] = $row;
    }
    $stmt_committees->close();
} else {
    error_log("Error preparing committees query: " . $db->error);
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
    <title>Dashboard | President - DMU Complaint System</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-card.pending i { color: var(--warning); }
        .stat-card.in-progress i { color: var(--info); }
        .stat-card.resolved i { color: var(--success); }

        .stat-card h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .stat-card p {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
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
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
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
        .status-in-progress { color: var(--info); font-weight: 500; }
        .status-resolved { color: var(--success); font-weight: 500; }

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
            margin-right: �0.5rem;
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

        .btn-view {
            background: var(--info);
            color: white;
        }

        .btn-view:hover {
            background: #138496;
        }

        .btn-accent {
            background: var(--accent);
            color: white;
        }

        .btn-accent:hover {
            background: #3abde0;
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .no-complaints, .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            font-style: italic;
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
            .table-container table,
            .table-container thead,
            .table-container tbody,
            .table-container th,
            .table-container td,
            .table-container tr { display: block; }
            .table-container thead tr { position: absolute; top: -9999px; left: -9999px; }
            .table-container tr { margin-bottom: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: white; }
            .table-container tr:nth-child(even) { background-color: white; }
            .table-container td { 
                border: none; 
                border-bottom: 1px solid #eee; 
                position: relative; 
                padding-left: 50% !important; 
                text-align: left; 
                white-space: normal; 
                min-height: 30px; 
                display: flex; 
                align-items: center; 
            }
            .table-container td:before { 
                content: attr(data-label); 
                position: absolute; 
                left: 15px; 
                width: 45%; 
                padding-right: 10px; 
                font-weight: bold; 
                color: var(--primary); 
                white-space: normal; 
                text-align: left; 
            }
            .table-container td:last-child { border-bottom: none; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .action-btn { width: 100%; justify-content: center; margin-bottom: 0.5rem; margin-right: 0; }
            .stats-grid { grid-template-columns: 1fr; }
            .table-container td[data-label="Actions"] {
                padding-left: 15px !important;
                display: flex;
                flex-direction: column;
                gap: 5px;
                align-items: flex-start;
            }
            .table-container td[data-label="Actions"]::before {
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
            <a href="view_reports.php" class="nav-link <?php echo $current_page == 'view_reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>View Reports</span>
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
            <h2>President Dashboard</h2>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card pending">
                    <i class="fas fa-hourglass-half"></i>
                    <h3>Pending Complaints</h3>
                    <p><?php echo $pending_count; ?></p>
                </div>
                <div class="stat-card in-progress">
                    <i class="fas fa-spinner"></i>
                    <h3>In-Progress Complaints</h3>
                    <p><?php echo $in_progress_count; ?></p>
                </div>
                <div class="stat-card resolved">
                    <i class="fas fa-check-circle"></i>
                    <h3>Resolved Complaints</h3>
                    <p><?php echo $resolved_count; ?></p>
                </div>
            </div>

            <h2>Complaints Assigned to President</h2>
            <div class="table-container">
                <?php if (empty($complaints)): ?>
                    <p class="no-complaints">No complaints escalated to you at this time.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Complaint ID</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Complaint Status</th>
                                <th>Escalation Status</th>
                                <th>Escalated On</th>
                                <th>Escalated By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
                                    <td data-label="Complaint ID"><?php echo htmlspecialchars($complaint['id']); ?></td>
                                    <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
                                    <td data-label="Category"><?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></td>
                                    <td data-label="Complaint Status">
                                        <span class="status-<?php echo strtolower($complaint['complaint_status']); ?>">
                                            <?php echo htmlspecialchars(ucfirst($complaint['complaint_status'])); ?>
                                        </span>
                                    </td>
                                    <td data-label="Escalation Status">
                                        <span class="status-<?php echo strtolower($complaint['escalation_status']); ?>">
                                            <?php echo htmlspecialchars(ucfirst($complaint['escalation_status'])); ?>
                                        </span>
                                    </td>
                                    <td data-label="Escalated On"><?php echo date('M j, Y H:i', strtotime($complaint['escalated_at'])); ?></td>
                                    <td data-label="Escalated By"><?php echo htmlspecialchars($complaint['escalated_by_name']); ?></td>
                                    <td data-label="Actions">
                                        <?php if ($complaint['escalation_status'] != 'resolved'): ?>
                                            <a href="decide_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="action-btn btn-decide">
                                                <i class="fas fa-gavel"></i> Decide
                                            </a>
                                        <?php else: ?>
                                            <span class="status-resolved">Resolved</span>
                                        <?php endif; ?>
                                        <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&from=dashboard" class="action-btn btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Committees Section -->
            <div class="committees-list">
                <h3>Your Committees</h3>
                <?php if (!empty($committees)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Committee Name</th>
                                    <th>Related Complaint</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($committees as $committee): ?>
                                    <tr>
                                        <td data-label="Committee Name"><?php echo htmlspecialchars($committee['committee_name']); ?></td>
                                        <td data-label="Related Complaint">
                                            #<?php echo $committee['complaint_id']; ?> - <?php echo htmlspecialchars($committee['complaint_title']); ?>
                                        </td>
                                        <td data-label="Actions">
                                            <a href="../chat.php?committee_id=<?php echo $committee['committee_id']; ?>" class="action-btn btn-accent" title="Join Committee Chat">
                                                <i class="fas fa-comments"></i> Join Chat
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>You are not currently a member of any committees.</p>
                    </div>
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