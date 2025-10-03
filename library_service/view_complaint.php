<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'library_service'
if (!isset($_SESSION['user_id'])) {
    error_log("Session user_id not set. Redirecting to login.");
    $_SESSION['error'] = "Please log in to access this page.";
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'library_service') {
    error_log("User role is {$_SESSION['role']}, not library_service. Redirecting to unauthorized.");
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../unauthorized.php");
    exit;
}

$library_user_id = $_SESSION['user_id'];
$library_user = null;

// Fetch Library Service user details
$sql_user = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_user = $db->prepare($sql_user);
if ($stmt_user) {
    $stmt_user->bind_param("i", $library_user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $library_user = $result_user->fetch_assoc();
    } else {
        error_log("Library Service user details not found for ID: $library_user_id");
        $_SESSION['error'] = "User details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_user->close();
} else {
    error_log("Error preparing user query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: dashboard.php");
    exit;
}

// Validate complaint_id (check both 'complaint_id' and 'id')
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
if (!$complaint_id || $complaint_id <= 0) {
    $complaint_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}
if (!$complaint_id || $complaint_id <= 0) {
    error_log("Invalid or missing complaint ID: complaint_id=" . ($_GET['complaint_id'] ?? 'not set') . ", id=" . ($_GET['id'] ?? 'not set'));
    $_SESSION['error'] = "Invalid complaint ID.";
    // Redirect to notifications if coming from there
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'view_notifications.php') !== false) {
        header("Location: view_notifications.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

// Check if the complaint is accessible to the library service user
$access_check_query = "
    SELECT c.id
    FROM complaints c
    LEFT JOIN escalations e ON c.id = e.complaint_id
    LEFT JOIN decisions d ON c.id = d.complaint_id
    WHERE c.id = ?
    AND (
        c.handler_id = ?
        OR e.escalated_by_id = ?
        OR e.escalated_to_id = ?
        OR d.sender_id = ?
    )";
$stmt_access = $db->prepare($access_check_query);
if (!$stmt_access) {
    error_log("Prepare failed for access check: " . $db->error);
    $_SESSION['error'] = "Database error checking complaint access.";
    header("Location: dashboard.php");
    exit;
}
$stmt_access->bind_param("iiiii", $complaint_id, $library_user_id, $library_user_id, $library_user_id, $library_user_id);
$stmt_access->execute();
$access_result = $stmt_access->get_result();
if ($access_result->num_rows == 0) {
    error_log("Access denied for complaint ID: $complaint_id, user_id: $library_user_id");
    $_SESSION['error'] = "You do not have access to this complaint or it does not exist.";
    // Redirect to notifications if coming from there
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'view_notifications.php') !== false) {
        header("Location: view_notifications.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}
$stmt_access->close();

// Fetch complaint details
$complaint_query = "
    SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname, u.email as submitter_email
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    WHERE c.id = ?";
$stmt_complaint = $db->prepare($complaint_query);
if (!$stmt_complaint) {
    error_log("Prepare failed for complaint fetch: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaint details.";
    header("Location: dashboard.php");
    exit;
}
$stmt_complaint->bind_param("i", $complaint_id);
$stmt_complaint->execute();
$complaint_result = $stmt_complaint->get_result();
if ($complaint_result->num_rows == 0) {
    error_log("Complaint not found for ID: $complaint_id");
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}
$complaint = $complaint_result->fetch_assoc();
$stmt_complaint->close();

// Fetch stereotypes
$sql_stereotypes = "
    SELECT s.label
    FROM complaint_stereotypes cs
    JOIN stereotypes s ON cs.stereotype_id = s.id
    WHERE cs.complaint_id = ?";
$stmt_stereotypes = $db->prepare($sql_stereotypes);
if ($stmt_stereotypes) {
    $stmt_stereotypes->bind_param("i", $complaint_id);
    $stmt_stereotypes->execute();
    $stereotypes_result = $stmt_stereotypes->get_result();
    $stereotypes = [];
    while ($row = $stereotypes_result->fetch_assoc()) {
        $stereotypes[] = $row['label'];
    }
    $stmt_stereotypes->close();
} else {
    error_log("Prepare failed for stereotypes fetch: " . $db->error);
    $stereotypes = [];
}

// Fetch escalation history
$escalation_query = "
    SELECT e.*, u_sender.fname as sender_fname, u_sender.lname as sender_lname
    FROM escalations e
    LEFT JOIN users u_sender ON e.escalated_by_id = u_sender.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at DESC";
$stmt_escalation = $db->prepare($escalation_query);
if ($stmt_escalation) {
    $stmt_escalation->bind_param("i", $complaint_id);
    $stmt_escalation->execute();
    $escalation_result = $stmt_escalation->get_result();
    $escalations = [];
    while ($row = $escalation_result->fetch_assoc()) {
        $escalations[] = $row;
    }
    $stmt_escalation->close();
} else {
    error_log("Prepare failed for escalation fetch: " . $db->error);
    $escalations = [];
}

// Fetch decision history
$decision_query = "
    SELECT d.*, u_sender.fname as sender_fname, u_sender.lname as sender_lname,
           u_receiver.fname as receiver_fname, u_receiver.lname as receiver_lname
    FROM decisions d
    LEFT JOIN users u_sender ON d.sender_id = u_sender.id
    LEFT JOIN users u_receiver ON d.receiver_id = u_receiver.id
    WHERE d.complaint_id = ?
    ORDER BY d.created_at DESC";
$stmt_decision = $db->prepare($decision_query);
if ($stmt_decision) {
    $stmt_decision->bind_param("i", $complaint_id);
    $stmt_decision->execute();
    $decision_result = $stmt_decision->get_result();
    $decisions = [];
    while ($row = $decision_result->fetch_assoc()) {
        $decisions[] = $row;
    }
    $stmt_decision->close();
} else {
    error_log("Prepare failed for decision fetch: " . $db->error);
    $decisions = [];
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint #<?php echo htmlspecialchars($complaint_id); ?> | DMU Complaint System</title>
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
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }

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
        .complaint-details, .history-section {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .complaint-details p, .history-section p {
            margin: 0.5rem 0;
            font-size: 0.95rem;
        }

        .complaint-details p strong, .history-section p strong {
            color: var(--primary-dark);
        }

        /* Status Badges */
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

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); box-shadow: var(--shadow-hover); transform: translateY(-2px); }

        /* History Table */
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

        /* Empty State */
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
            .main-content { min-height: auto; }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }

            /* Responsive Table Stacking */
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
            .complaint-details { flex-direction: column; text-align: center; }
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
            <?php if ($library_user): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($library_user['fname'] . ' ' . $library_user['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $library_user['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4>Library Service</h4>
                    <p>Role: Library Service</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
        <h3>Dashboard</h3>
            <a href="library_service_dashboard.php" class="nav-link <?php echo $current_page == 'library_service_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'library_service_decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='library_service_dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
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
                <span>DMU Complaint System - Library Service</span>
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

        <!-- Complaint Details Content -->
        <div class="content-container">
            <h2>Complaint #<?php echo htmlspecialchars($complaint_id); ?> Details</h2>

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

            <!-- Complaint Details Section -->
            <div class="complaint-details">
                <h3>Complaint Information</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><strong>Category:</strong> 
                    <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                </p>
                <p><strong>Stereotypes:</strong> 
                    <?php
                    if (!empty($stereotypes)) {
                        echo htmlspecialchars(implode(', ', array_map('ucfirst', $stereotypes)));
                    } else {
                        echo '<span class="status status-uncategorized">None</span>';
                    }
                    ?>
                </p>
                <p><strong>Status:</strong> 
                    <span class="status status-<?php echo strtolower($complaint['status']); ?>">
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?>
                    </span>
                </p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?> (<?php echo htmlspecialchars($complaint['submitter_email']); ?>)</p>
                <p><strong>Submitted On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                <?php if (!empty($complaint['evidence_file'])): ?>
                    <p><strong>Evidence File:</strong> <a href="../Uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank"><?php echo htmlspecialchars($complaint['evidence_file']); ?></a></p>
                <?php endif; ?>
            </div>

            <!-- Escalation History Section -->
            <div class="history-section">
                <h3>Escalation History</h3>
                <?php if (!empty($escalations)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Escalated To</th>
                                    <th>Escalated By</th>
                                    <th>Status</th>
                                    <th>Escalated On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($escalations as $escalation): ?>
                                    <tr>
                                        <td data-label="Escalated To"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $escalation['escalated_to']))); ?></td>
                                        <td data-label="Escalated By"><?php echo htmlspecialchars($escalation['sender_fname'] . ' ' . $escalation['sender_lname']); ?></td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($escalation['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($escalation['status'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Escalated On"><?php echo date('M j, Y H:i', strtotime($escalation['created_at'])); ?></td>
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

            <!-- Decision History Section -->
            <div class="history-section">
                <h3>Decision History</h3>
                <?php if (!empty($decisions)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Sent By</th>
                                    <th>Sent To</th>
                                    <th>Decision</th>
                                    <th>Status</th>
                                    <th>Sent On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decisions as $decision): ?>
                                    <tr>
                                        <td data-label="Sent By"><?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?></td>
                                        <td data-label="Sent To"><?php echo htmlspecialchars($decision['receiver_fname'] . ' ' . $decision['receiver_lname']); ?></td>
                                        <td data-label="Decision"><?php echo htmlspecialchars($decision['decision_text']); ?></td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($decision['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($decision['status'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Sent On"><?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-gavel"></i>
                        <p>No decisions have been made for this complaint yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Back Button -->
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div><!-- End content-container -->

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
    </div> <!-- End main-content -->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
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