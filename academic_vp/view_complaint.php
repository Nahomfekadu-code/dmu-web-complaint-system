<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'academic_vp'
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to access this page.";
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'academic_vp') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../unauthorized.php");
    exit;
}

$vp_id = $_SESSION['user_id'];

// Fetch Academic VP details
$vp_query = "SELECT fname, lname, email FROM users WHERE id = ?";
$stmt_vp = $db->prepare($vp_query);
if (!$stmt_vp) {
    $_SESSION['error'] = "Database error occurred.";
    header("Location: ../logout.php");
    exit;
}
$stmt_vp->bind_param("i", $vp_id);
$stmt_vp->execute();
$vp = $stmt_vp->get_result()->fetch_assoc();
$stmt_vp->close();

if (!$vp) {
    $_SESSION['error'] = "User details not found.";
    header("Location: ../logout.php");
    exit;
}

// Ensure a complaint ID is provided
$complaint_id = isset($_GET['complaint_id']) ? filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT) : null;
if (!$complaint_id) {
    $_SESSION['error'] = "No complaint ID provided.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details with related user and department information
$complaint_query = "
    SELECT c.*, 
           u.fname AS user_fname, u.lname AS user_lname, u.role AS user_role,
           h.fname AS handler_fname, h.lname AS handler_lname, h.role AS handler_role,
           d.name AS department_name
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN users h ON c.handler_id = h.id
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE c.id = ?
";
$stmt_complaint = $db->prepare($complaint_query);
if (!$stmt_complaint) {
    $_SESSION['error'] = "Error fetching complaint: " . $db->error;
    header("Location: dashboard.php");
    exit;
}
$stmt_complaint->bind_param("i", $complaint_id);
$stmt_complaint->execute();
$complaint = $stmt_complaint->get_result()->fetch_assoc();
$stmt_complaint->close();

if (!$complaint) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}

// Fetch stereotypes associated with the complaint
$stereotypes_query = "
    SELECT s.label, s.description, u.fname AS tagged_by_fname, u.lname AS tagged_by_lname
    FROM complaint_stereotypes cs
    JOIN stereotypes s ON cs.stereotype_id = s.id
    JOIN users u ON cs.tagged_by = u.id
    WHERE cs.complaint_id = ?
";
$stmt_stereotypes = $db->prepare($stereotypes_query);
$stereotypes = [];
if ($stmt_stereotypes) {
    $stmt_stereotypes->bind_param("i", $complaint_id);
    $stmt_stereotypes->execute();
    $result = $stmt_stereotypes->get_result();
    while ($row = $result->fetch_assoc()) {
        $stereotypes[] = $row;
    }
    $stmt_stereotypes->close();
}

// Fetch escalations related to the complaint
$escalations_query = "
    SELECT e.*, 
           eb.fname AS escalated_by_fname, eb.lname AS escalated_by_lname,
           et.fname AS escalated_to_fname, et.lname AS escalated_to_lname,
           d.name AS department_name
    FROM escalations e
    LEFT JOIN users eb ON e.escalated_by_id = eb.id
    LEFT JOIN users et ON e.escalated_to_id = et.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at DESC
";
$stmt_escalations = $db->prepare($escalations_query);
$escalations = [];
if ($stmt_escalations) {
    $stmt_escalations->bind_param("i", $complaint_id);
    $stmt_escalations->execute();
    $result = $stmt_escalations->get_result();
    while ($row = $result->fetch_assoc()) {
        $escalations[] = $row;
    }
    $stmt_escalations->close();
}

// Fetch decisions related to the complaint
$decisions_query = "
    SELECT d.*, 
           s.fname AS sender_fname, s.lname AS sender_lname, s.role AS sender_role,
           r.fname AS receiver_fname, r.lname AS receiver_lname, r.role AS receiver_role
    FROM decisions d
    LEFT JOIN users s ON d.sender_id = s.id
    LEFT JOIN users r ON d.receiver_id = r.id
    WHERE d.complaint_id = ?
    ORDER BY d.created_at DESC
";
$stmt_decisions = $db->prepare($decisions_query);
$decisions = [];
if ($stmt_decisions) {
    $stmt_decisions->bind_param("i", $complaint_id);
    $stmt_decisions->execute();
    $result = $stmt_decisions->get_result();
    while ($row = $result->fetch_assoc()) {
        $decisions[] = $row;
    }
    $stmt_decisions->close();
}

// Fetch notifications related to the complaint for the Academic VP
$notifications_query = "
    SELECT n.*
    FROM notifications n
    WHERE n.complaint_id = ? AND n.user_id = ?
    ORDER BY n.created_at DESC
";
$stmt_notifications = $db->prepare($notifications_query);
$notifications = [];
if ($stmt_notifications) {
    $stmt_notifications->bind_param("ii", $complaint_id, $vp_id);
    $stmt_notifications->execute();
    $result = $stmt_notifications->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt_notifications->close();
}

// Fetch notification count for unread notifications
$sql_notif_count = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif_count = $db->prepare($sql_notif_count);
$stmt_notif_count->bind_param("i", $vp_id);
$stmt_notif_count->execute();
$notification_count = $stmt_notif_count->get_result()->fetch_assoc()['count'];
$stmt_notif_count->close();

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint #<?php echo htmlspecialchars($complaint_id); ?> | Academic VP | DMU Complaint System</title>
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
            align-items: center;
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
            font-size: 1.4rem;
            margin: 1.5rem 0 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        /* Complaint Details */
        .complaint-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-item {
            background: var(--light);
            padding: 1rem;
            border-radius: var(--radius);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .detail-item strong {
            display: block;
            color: var(--primary-dark);
            margin-bottom: 0.3rem;
        }

        .detail-item p {
            margin: 0;
            color: var(--gray);
        }

        /* Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }

        td {
            background: #fff;
        }

        tr:hover td {
            background: var(--light);
        }

        .badge {
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            color: white;
        }

        .badge.unread { background-color: var(--warning); color: var(--dark); }
        .badge.read { background-color: var(--success); }

        /* Responsive Table */
        @media (max-width: 768px) {
            .complaint-details {
                grid-template-columns: 1fr;
            }

            table {
                display: block;
                overflow-x: auto;
            }

            thead {
                display: none;
            }

            tr {
                display: block;
                margin-bottom: 1rem;
                border-bottom: 2px solid var(--light-gray);
            }

            td {
                display: block;
                text-align: right;
                padding: 0.5rem 1rem;
                position: relative;
                border: none;
                background: #fff;
            }

            td::before {
                content: attr(data-label);
                position: absolute;
                left: 1rem;
                font-weight: 600;
                color: var(--primary-dark);
                text-transform: uppercase;
                font-size: 0.85rem;
            }

            td:last-child {
                border-bottom: none;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        /* Button Styling */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.9rem;
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
                    <p>Academic Vice President</p>
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
            <a href="assign_complaint.php" class="nav-link <?php echo $current_page == 'assign_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i>
                <span>Assign Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up"></i>
                <span>Escalate Complaint</span>
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
                <span>DMU Complaint System - Academic Vice President</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php">
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
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Content -->
        <div class="content-container">
            <h2>Complaint #<?php echo htmlspecialchars($complaint_id); ?>: <?php echo htmlspecialchars($complaint['title']); ?></h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details -->
            <div class="complaint-details">
                <div class="detail-item">
                    <strong>Submitted By</strong>
                    <p><?php echo $complaint['user_id'] ? htmlspecialchars($complaint['user_fname'] . ' ' . $complaint['user_lname'] . ' (' . ucfirst($complaint['user_role']) . ')') : 'N/A'; ?></p>
                </div>
                <div class="detail-item">
                    <strong>Handler</strong>
                    <p><?php echo $complaint['handler_id'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname'] . ' (' . ucfirst($complaint['handler_role']) . ')') : 'Not Assigned'; ?></p>
                </div>
                <div class="detail-item">
                    <strong>Category</strong>
                    <p><?php echo htmlspecialchars(ucfirst($complaint['category'])); ?></p>
                </div>
                <div class="detail-item">
                    <strong>Status</strong>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?></p>
                </div>
                <div class="detail-item">
                    <strong>Visibility</strong>
                    <p><?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></p>
                </div>
                <div class="detail-item">
                    <strong>Department</strong>
                    <p><?php echo $complaint['department_name'] ? htmlspecialchars($complaint['department_name']) : 'N/A'; ?></p>
                </div>
                <div class="detail-item">
                    <strong>Created At</strong>
                    <p><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                </div>
                <div class="detail-item">
                    <strong>Updated At</strong>
                    <p><?php echo $complaint['updated_at'] ? date('M j, Y H:i', strtotime($complaint['updated_at'])) : 'N/A'; ?></p>
                </div>
                <div class="detail-item">
                    <strong>Resolution Date</strong>
                    <p><?php echo $complaint['resolution_date'] ? date('M j, Y H:i', strtotime($complaint['resolution_date'])) : 'N/A'; ?></p>
                </div>
                <div class="detail-item">
                    <strong>Evidence File</strong>
                    <p>
                        <?php if ($complaint['evidence_file']): ?>
                            <a href="../uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank">View File</a>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Description -->
            <h3>Description</h3>
            <div class="detail-item" style="grid-column: span 2;">
                <p><?php echo htmlspecialchars($complaint['description']); ?></p>
            </div>

            <!-- Resolution Details -->
            <?php if ($complaint['resolution_details'] || $complaint['rejection_reason']): ?>
                <h3>Resolution Details</h3>
                <div class="detail-item" style="grid-column: span 2;">
                    <strong>Resolution Details:</strong>
                    <p><?php echo $complaint['resolution_details'] ? htmlspecialchars($complaint['resolution_details']) : 'N/A'; ?></p>
                    <strong>Rejection Reason (if applicable):</strong>
                    <p><?php echo $complaint['rejection_reason'] ? htmlspecialchars($complaint['rejection_reason']) : 'N/A'; ?></p>
                </div>
            <?php endif; ?>

            <!-- Stereotypes -->
            <h3>Stereotypes</h3>
            <?php if (!empty($stereotypes)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Description</th>
                                <th>Tagged By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stereotypes as $stereotype): ?>
                                <tr>
                                    <td data-label="Label"><?php echo htmlspecialchars($stereotype['label']); ?></td>
                                    <td data-label="Description"><?php echo htmlspecialchars($stereotype['description']); ?></td>
                                    <td data-label="Tagged By"><?php echo htmlspecialchars($stereotype['tagged_by_fname'] . ' ' . $stereotype['tagged_by_lname']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-tags"></i>
                    <p>No stereotypes tagged for this complaint.</p>
                </div>
            <?php endif; ?>

            <!-- Escalations -->
            <h3>Escalation History</h3>
            <?php if (!empty($escalations)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Escalated To</th>
                                <th>Escalated To User</th>
                                <th>Escalated By</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Action Type</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($escalations as $escalation): ?>
                                <tr>
                                    <td data-label="Escalated To"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $escalation['escalated_to']))); ?></td>
                                    <td data-label="Escalated To User"><?php echo $escalation['escalated_to_id'] ? htmlspecialchars($escalation['escalated_to_fname'] . ' ' . $escalation['escalated_to_lname']) : 'N/A'; ?></td>
                                    <td data-label="Escalated By"><?php echo htmlspecialchars($escalation['escalated_by_fname'] . ' ' . $escalation['escalated_by_lname']); ?></td>
                                    <td data-label="Department"><?php echo $escalation['department_name'] ? htmlspecialchars($escalation['department_name']) : 'N/A'; ?></td>
                                    <td data-label="Status"><?php echo htmlspecialchars(ucfirst($escalation['status'])); ?></td>
                                    <td data-label="Action Type"><?php echo htmlspecialchars(ucfirst($escalation['action_type'])); ?></td>
                                    <td data-label="Created At"><?php echo date('M j, Y H:i', strtotime($escalation['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-arrow-up"></i>
                    <p>No escalations found for this complaint.</p>
                </div>
            <?php endif; ?>

            <!-- Decisions -->
            <h3>Decisions</h3>
            <?php if (!empty($decisions)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Decision Text</th>
                                <th>Status</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decisions as $decision): ?>
                                <tr>
                                    <td data-label="Sender"><?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname'] . ' (' . ucfirst($decision['sender_role']) . ')'); ?></td>
                                    <td data-label="Receiver"><?php echo $decision['receiver_id'] ? htmlspecialchars($decision['receiver_fname'] . ' ' . $decision['receiver_lname'] . ' (' . ucfirst($decision['receiver_role']) . ')') : 'N/A'; ?></td>
                                    <td data-label="Decision Text"><?php echo htmlspecialchars($decision['decision_text']); ?></td>
                                    <td data-label="Status"><?php echo htmlspecialchars(ucfirst($decision['status'])); ?></td>
                                    <td data-label="Created At"><?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-gavel"></i>
                    <p>No decisions recorded for this complaint.</p>
                </div>
            <?php endif; ?>

            <!-- Notifications -->
            <h3>Related Notifications</h3>
            <?php if (!empty($notifications)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Received At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td data-label="Description"><?php echo htmlspecialchars($notification['description']); ?></td>
                                    <td data-label="Status">
                                        <span class="badge <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                                            <?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?>
                                        </span>
                                    </td>
                                    <td data-label="Received At"><?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bell"></i>
                    <p>No notifications related to this complaint.</p>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <h3>Actions</h3>
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <?php if ($complaint['status'] != 'resolved' && $complaint['status'] != 'rejected'): ?>
                    <a href="escalate_complaint.php?complaint_id=<?php echo htmlspecialchars($complaint_id); ?>" class="btn btn-primary"><i class="fas fa-arrow-up"></i> Escalate Complaint</a>
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
            // Auto-hide alerts
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
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>