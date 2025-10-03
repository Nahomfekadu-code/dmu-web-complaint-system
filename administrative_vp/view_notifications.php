<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'administrative_vp'
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to access this page.";
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'administrative_vp') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../unauthorized.php");
    exit;
}

$vp_id = $_SESSION['user_id'];

// Fetch Administrative VP details
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

// Handle marking a notification as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_as_read'])) {
    $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
    if ($notification_id) {
        $update_query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
        $stmt_update = $db->prepare($update_query);
        if ($stmt_update) {
            $stmt_update->bind_param("ii", $notification_id, $vp_id);
            if ($stmt_update->execute()) {
                $_SESSION['success'] = "Notification marked as read.";
            } else {
                $_SESSION['error'] = "Failed to mark notification as read.";
            }
            $stmt_update->close();
        } else {
            $_SESSION['error'] = "Database error while marking notification as read.";
        }
    } else {
        $_SESSION['error'] = "Invalid notification ID.";
    }
    header("Location: view_notifications.php");
    exit;
}

// Handle marking all notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_as_read'])) {
    $update_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $stmt_update = $db->prepare($update_query);
    if ($stmt_update) {
        $stmt_update->bind_param("i", $vp_id);
        if ($stmt_update->execute()) {
            $_SESSION['success'] = "All notifications marked as read.";
        } else {
            $_SESSION['error'] = "Failed to mark all notifications as read.";
        }
        $stmt_update->close();
    } else {
        $_SESSION['error'] = "Database error while marking all notifications as read.";
    }
    header("Location: view_notifications.php");
    exit;
}

// Pagination, Sorting, and Filtering
$items_per_page = 10;
$page = isset($_GET['page']) ? max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT)) : 1;
$offset = ($page - 1) * $items_per_page;

$sort_column = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';
$valid_sort_columns = ['id', 'description', 'is_read', 'created_at'];
$sort_column = in_array($sort_column, $valid_sort_columns) ? $sort_column : 'created_at';

$filter_read_status = isset($_GET['read_status']) ? trim(filter_input(INPUT_GET, 'read_status', FILTER_SANITIZE_SPECIAL_CHARS)) : '';
$valid_read_statuses = ['0', '1', ''];
$filter_read_status = in_array($filter_read_status, $valid_read_statuses) ? $filter_read_status : '';

// Build the conditions for the WHERE clause
$conditions = ["user_id = ?"]; // This will be qualified as n.user_id in the query
$params = [$vp_id];
$types = "i";

if ($filter_read_status !== '') {
    $conditions[] = "is_read = ?"; // Will be qualified as n.is_read
    $params[] = $filter_read_status;
    $types .= "i";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
$sort_clause = "ORDER BY $sort_column $sort_order";

// Count total notifications for pagination (optimized to avoid JOIN)
$count_query = "SELECT COUNT(*) as total FROM notifications $where_clause";
$stmt_count = $db->prepare($count_query);
if ($stmt_count) {
    if ($params) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_notifications = $stmt_count->get_result()->fetch_assoc()['total'];
    $stmt_count->close();
} else {
    $total_notifications = 0;
    $_SESSION['error'] = "Error fetching notification count.";
}

$total_pages = max(1, ceil($total_notifications / $items_per_page));

// Fetch notifications with pagination (fixed user_id ambiguity)
$notifications_query = "
    SELECT n.*, c.title as complaint_title
    FROM notifications n
    LEFT JOIN complaints c ON n.complaint_id = c.id
    WHERE n." . implode(" AND n.", $conditions) . "
    $sort_clause
    LIMIT ? OFFSET ?
";
$types .= "ii";
$params[] = $items_per_page;
$params[] = $offset;

$stmt_notifications = $db->prepare($notifications_query);
$notifications = [];
if ($stmt_notifications) {
    $stmt_notifications->bind_param($types, ...$params);
    $stmt_notifications->execute();
    $result = $stmt_notifications->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt_notifications->close();
} else {
    $_SESSION['error'] = "Error fetching notifications: " . $db->error;
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
    <title>View Notifications | Administrative VP | DMU Complaint System</title>
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

        /* Filter Form */
        .filter-form {
            display: flex;
            gap: 15px;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-form select, .filter-form button {
            padding: 0.5rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .filter-form select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
        }

        .filter-form button {
            background: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
        }

        .filter-form button:hover {
            background: var(--primary-dark);
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

        th a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        th a:hover {
            color: var(--accent);
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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 2rem;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            font-weight: 600;
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
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $_SESSION['role']))); ?></p>
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
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up"></i>
                <span>Escalate Complaint</span>
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
                <span>DMU Complaint System - Administrative Vice President</span>
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
            <h2>Notifications</h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Filter Form and Mark All as Read -->
            <div class="filter-form">
                <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <select name="read_status">
                        <option value="">All Notifications</option>
                        <option value="0" <?php echo $filter_read_status === '0' ? 'selected' : ''; ?>>Unread</option>
                        <option value="1" <?php echo $filter_read_status === '1' ? 'selected' : ''; ?>>Read</option>
                    </select>
                    <button type="submit">Filter</button>
                </form>
                <?php if ($notification_count > 0): ?>
                    <form method="POST" style="margin-left: auto;">
                        <button type="submit" name="mark_all_as_read" class="btn btn-success btn-small">
                            <i class="fas fa-check"></i> Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Notifications Table -->
            <?php if (!empty($notifications)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <a href="?sort=id&order=<?php echo $sort_column === 'id' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&read_status=<?php echo urlencode($filter_read_status); ?>">
                                        ID
                                        <?php if ($sort_column === 'id'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?sort=description&order=<?php echo $sort_column === 'description' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&read_status=<?php echo urlencode($filter_read_status); ?>">
                                        Description
                                        <?php if ($sort_column === 'description'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Related Complaint</th>
                                <th>
                                    <a href="?sort=is_read&order=<?php echo $sort_column === 'is_read' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&read_status=<?php echo urlencode($filter_read_status); ?>">
                                        Status
                                        <?php if ($sort_column === 'is_read'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?sort=created_at&order=<?php echo $sort_column === 'created_at' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&read_status=<?php echo urlencode($filter_read_status); ?>">
                                        Received At
                                        <?php if ($sort_column === 'created_at'): ?>
                                            <i class="fas fa-sort-<?php echo $sort_order === 'ASC' ? 'up' : 'down'; ?>"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td data-label="ID"><?php echo htmlspecialchars($notification['id']); ?></td>
                                    <td data-label="Description"><?php echo htmlspecialchars($notification['description']); ?></td>
                                    <td data-label="Related Complaint">
                                        <?php if ($notification['complaint_id']): ?>
                                            <a href="view_complaint.php?complaint_id=<?php echo htmlspecialchars($notification['complaint_id']); ?>">
                                                <?php echo htmlspecialchars($notification['complaint_title'] ?: "Complaint #{$notification['complaint_id']}"); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                                            <?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?>
                                        </span>
                                    </td>
                                    <td data-label="Received At"><?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></td>
                                    <td data-label="Actions">
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="notification_id" value="<?php echo htmlspecialchars($notification['id']); ?>">
                                                <button type="submit" name="mark_as_read" class="btn btn-success btn-small">
                                                    <i class="fas fa-check"></i> Mark as Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($notification['complaint_id']): ?>
                                            <a href="view_complaint.php?complaint_id=<?php echo htmlspecialchars($notification['complaint_id']); ?>" class="btn btn-primary btn-small">
                                                <i class="fas fa-eye"></i> View Complaint
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>&read_status=<?php echo urlencode($filter_read_status); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No notifications found.</p>
                </div>
            <?php endif; ?>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 7</div>
                <div class="social-links">
                    <a href="https://github.com"><i class="fab fa-github"></i></a>
                    <a href="https://linkedin.com"><i class="fab fa-linkedin"></i></a>
                    <a href="mailto:group7@example.com"><i class="fas fa-envelope"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint System. All rights reserved.
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