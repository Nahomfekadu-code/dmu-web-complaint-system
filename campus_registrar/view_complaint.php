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
$complaint = null;

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

// Validate complaint_id
$complaint_id = isset($_GET['complaint_id']) ? filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT) : null;
if (!$complaint_id) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: view_resolved.php");
    exit;
}

// Check if the Campus Registrar has interacted with this complaint
$access_check_query = "
    SELECT 1
    FROM escalations e
    WHERE e.complaint_id = ?
    AND (e.escalated_to_id = ? OR e.escalated_by_id = ?)
    AND e.escalated_to = 'campus_registrar'
";
$stmt_access = $db->prepare($access_check_query);
if (!$stmt_access) {
    error_log("Prepare failed for access check: " . $db->error);
    $_SESSION['error'] = "An error occurred while verifying access to the complaint.";
    header("Location: view_resolved.php");
    exit;
}
$stmt_access->bind_param("iii", $complaint_id, $registrar_id, $registrar_id);
$stmt_access->execute();
$access_result = $stmt_access->get_result();
if ($access_result->num_rows === 0) {
    $_SESSION['error'] = "You do not have permission to view this complaint.";
    header("Location: view_resolved.php");
    exit;
}
$stmt_access->close();

// Fetch complaint details
$stmt = $db->prepare("
    SELECT c.id, c.title, c.description, c.category, c.status, c.visibility, c.created_at, c.updated_at,
           c.resolution_date, c.resolution_details, c.evidence_file,
           u.fname, u.lname, u.email as user_email,
           h.fname as handler_fname, h.lname as handler_lname, h.email as handler_email
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN users h ON c.handler_id = h.id
    WHERE c.id = ?
");
if (!$stmt) {
    error_log("Prepare failed for complaint fetch: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching the complaint details.";
    header("Location: view_resolved.php");
    exit;
}
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: view_resolved.php");
    exit;
}
$complaint = $result->fetch_assoc();
$stmt->close();

// Fetch stereotypes for the complaint
$sql_stereotypes = "
    SELECT s.label, u.fname as tagged_by_fname, u.lname as tagged_by_lname, cs.created_at as tagged_at
    FROM complaint_stereotypes cs
    JOIN stereotypes s ON cs.stereotype_id = s.id
    JOIN users u ON cs.tagged_by = u.id
    WHERE cs.complaint_id = ?
";
$stmt_stereotypes = $db->prepare($sql_stereotypes);
$stereotypes = [];
if ($stmt_stereotypes) {
    $stmt_stereotypes->bind_param("i", $complaint_id);
    $stmt_stereotypes->execute();
    $result_stereotypes = $stmt_stereotypes->get_result();
    while ($row = $result_stereotypes->fetch_assoc()) {
        $stereotypes[] = $row;
    }
    $stmt_stereotypes->close();
} else {
    error_log("Error preparing stereotypes query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaint stereotypes.";
}

// Fetch escalation history
$sql_escalations = "
    SELECT e.id, e.escalated_to, e.status, e.created_at, e.updated_at, e.resolved_at, e.resolution_details,
           e.action_type, e.college, d.name as department_name,
           eb.fname as escalated_by_fname, eb.lname as escalated_by_lname,
           et.fname as escalated_to_fname, et.lname as escalated_to_lname
    FROM escalations e
    LEFT JOIN users eb ON e.escalated_by_id = eb.id
    LEFT JOIN users et ON e.escalated_to_id = et.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at ASC
";
$stmt_escalations = $db->prepare($sql_escalations);
$escalations = [];
if ($stmt_escalations) {
    $stmt_escalations->bind_param("i", $complaint_id);
    $stmt_escalations->execute();
    $result_escalations = $stmt_escalations->get_result();
    while ($row = $result_escalations->fetch_assoc()) {
        $escalations[] = $row;
    }
    $stmt_escalations->close();
} else {
    error_log("Error preparing escalations query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching escalation history.";
}

// Fetch decisions related to the complaint
$sql_decisions = "
    SELECT d.id, d.decision_text, d.status, d.created_at,
           s.fname as sender_fname, s.lname as sender_lname,
           r.fname as receiver_fname, r.lname as receiver_lname
    FROM decisions d
    LEFT JOIN users s ON d.sender_id = s.id
    LEFT JOIN users r ON d.receiver_id = r.id
    WHERE d.complaint_id = ?
    ORDER BY d.created_at ASC
";
$stmt_decisions = $db->prepare($sql_decisions);
$decisions = [];
if ($stmt_decisions) {
    $stmt_decisions->bind_param("i", $complaint_id);
    $stmt_decisions->execute();
    $result_decisions = $stmt_decisions->get_result();
    while ($row = $result_decisions->fetch_assoc()) {
        $decisions[] = $row;
    }
    $stmt_decisions->close();
} else {
    error_log("Error preparing decisions query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching decisions.";
}

// Fetch notifications related to the complaint
$sql_notifications = "
    SELECT n.id, n.description, n.is_read, n.created_at,
           u.fname, u.lname
    FROM notifications n
    JOIN users u ON n.user_id = u.id
    WHERE n.complaint_id = ?
    ORDER BY n.created_at DESC
";
$stmt_notifications = $db->prepare($sql_notifications);
$notifications = [];
if ($stmt_notifications) {
    $stmt_notifications->bind_param("i", $complaint_id);
    $stmt_notifications->execute();
    $result_notifications = $stmt_notifications->get_result();
    while ($row = $result_notifications->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt_notifications->close();
} else {
    error_log("Error preparing notifications query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching notifications.";
}

// Fetch notification count for the sidebar
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

        /* Content Container */
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

        /* Complaint Details */
        .complaint-details, .history-section, .stereotype-section, .notification-section {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .complaint-details p, .history-section p, .stereotype-section p, .notification-section p {
            margin: 0.5rem 0;
            font-size: 0.95rem;
        }

        .evidence-file a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .evidence-file a:hover {
            text-decoration: underline;
        }

        /* Timeline for History */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding-left: 2rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0.5rem;
            width: 1rem;
            height: 1rem;
            background: var(--primary);
            border-radius: 50%;
            border: 2px solid white;
        }

        .timeline-item p {
            margin: 0.3rem 0;
        }

        /* Stereotypes and Notifications */
        .stereotype-item, .notification-item {
            border-bottom: 1px solid var(--light-gray);
            padding-bottom: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .stereotype-item:last-child, .notification-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .status-pending { color: var(--warning); font-weight: 500; }
        .status-resolved { color: var(--success); font-weight: 500; }
        .status-escalated { color: var(--info); font-weight: 500; }
        .status-rejected { color: var(--danger); font-weight: 500; }
        .status-final { color: var(--success); font-weight: 500; }

        /* Buttons */
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

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 1rem 0;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.95rem;
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

        /* Responsive Adjustments */
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
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .complaint-details p, .history-section p, .stereotype-section p, .notification-section p { font-size: 0.9rem; }
            .btn { width: 100%; margin-bottom: 5px; }
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
            <a href="javascript:void(0);" class="nav-link" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
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

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
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
            <h2>View Complaint #<?php echo $complaint_id; ?></h2>

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
                <p><strong>Status:</strong> <span class="status-<?php echo strtolower($complaint['status']); ?>"><?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></span></p>
                <p><strong>Visibility:</strong> <?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?> (<?php echo htmlspecialchars($complaint['user_email'] ?? 'N/A'); ?>)</p>
                <p><strong>Initial Handler:</strong> <?php echo $complaint['handler_fname'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname'] . ' (' . $complaint['handler_email'] . ')') : 'Not assigned'; ?></p>
                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                <p><strong>Last Updated At:</strong> <?php echo $complaint['updated_at'] ? date('M j, Y H:i', strtotime($complaint['updated_at'])) : 'N/A'; ?></p>
                <p><strong>Resolved At:</strong> <?php echo $complaint['resolution_date'] ? date('M j, Y H:i', strtotime($complaint['resolution_date'])) : 'N/A'; ?></p>
                <p><strong>Resolution Details:</strong> <?php echo htmlspecialchars($complaint['resolution_details'] ?? 'Not resolved yet.'); ?></p>
                <p class="evidence-file"><strong>Evidence File:</strong> 
                    <?php if ($complaint['evidence_file']): ?>
                        <a href="../uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank"><?php echo htmlspecialchars($complaint['evidence_file']); ?></a>
                    <?php else: ?>
                        None
                    <?php endif; ?>
                </p>
            </div>

            <!-- Stereotypes -->
            <div class="stereotype-section">
                <h3>Stereotypes</h3>
                <?php if (empty($stereotypes)): ?>
                    <div class="empty-state">
                        <i class="fas fa-tags"></i>
                        <p>No stereotypes tagged for this complaint.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($stereotypes as $stereotype): ?>
                        <div class="stereotype-item">
                            <p><strong>Label:</strong> <?php echo htmlspecialchars(ucfirst($stereotype['label'])); ?></p>
                            <p><strong>Tagged By:</strong> <?php echo htmlspecialchars($stereotype['tagged_by_fname'] . ' ' . $stereotype['tagged_by_lname']); ?></p>
                            <p><strong>Tagged At:</strong> <?php echo date('M j, Y H:i', strtotime($stereotype['tagged_at'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Escalation History -->
            <div class="history-section">
                <h3>Escalation History</h3>
                <?php if (empty($escalations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No escalation history available.</p>
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($escalations as $escalation): ?>
                            <div class="timeline-item">
                                <p><strong>Action:</strong> <?php echo htmlspecialchars(ucfirst($escalation['action_type'])); ?></p>
                                <p><strong>Escalated To:</strong> 
                                    <?php 
                                        echo htmlspecialchars(ucfirst(str_replace('_', ' ', $escalation['escalated_to'])));
                                        if ($escalation['escalated_to_fname']) {
                                            echo ' (' . htmlspecialchars($escalation['escalated_to_fname'] . ' ' . $escalation['escalated_to_lname']) . ')';
                                        }
                                    ?>
                                </p>
                                <p><strong>Escalated By:</strong> <?php echo htmlspecialchars($escalation['escalated_by_fname'] . ' ' . $escalation['escalated_by_lname']); ?></p>
                                <p><strong>Status:</strong> <span class="status-<?php echo strtolower($escalation['status']); ?>"><?php echo htmlspecialchars(ucfirst($escalation['status'])); ?></span></p>
                                <p><strong>College:</strong> <?php echo htmlspecialchars($escalation['college'] ?? 'N/A'); ?></p>
                                <p><strong>Department:</strong> <?php echo htmlspecialchars($escalation['department_name'] ?? 'N/A'); ?></p>
                                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($escalation['created_at'])); ?></p>
                                <p><strong>Updated At:</strong> <?php echo $escalation['updated_at'] ? date('M j, Y H:i', strtotime($escalation['updated_at'])) : 'N/A'; ?></p>
                                <p><strong>Resolved At:</strong> <?php echo $escalation['resolved_at'] ? date('M j, Y H:i', strtotime($escalation['resolved_at'])) : 'N/A'; ?></p>
                                <p><strong>Resolution Details:</strong> <?php echo htmlspecialchars($escalation['resolution_details'] ?? 'N/A'); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Decisions -->
            <div class="history-section">
                <h3>Decisions</h3>
                <?php if (empty($decisions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-gavel"></i>
                        <p>No decisions recorded for this complaint.</p>
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($decisions as $decision): ?>
                            <div class="timeline-item">
                                <p><strong>Decision:</strong> <?php echo htmlspecialchars($decision['decision_text']); ?></p>
                                <p><strong>Status:</strong> <span class="status-<?php echo strtolower($decision['status']); ?>"><?php echo htmlspecialchars(ucfirst($decision['status'])); ?></span></p>
                                <p><strong>Sent By:</strong> <?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?></p>
                                <p><strong>Sent To:</strong> <?php echo $decision['receiver_fname'] ? htmlspecialchars($decision['receiver_fname'] . ' ' . $decision['receiver_lname']) : 'N/A'; ?></p>
                                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Notifications -->
            <div class="notification-section">
                <h3>Related Notifications</h3>
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bell"></i>
                        <p>No notifications related to this complaint.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item">
                            <p><strong>Description:</strong> <?php echo htmlspecialchars($notification['description']); ?></p>
                            <p><strong>Recipient:</strong> <?php echo htmlspecialchars($notification['fname'] . ' ' . $notification['lname']); ?></p>
                            <p><strong>Status:</strong> <?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?></p>
                            <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Back Button -->
            <a href="view_resolved.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Resolved Complaints</a>
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
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>