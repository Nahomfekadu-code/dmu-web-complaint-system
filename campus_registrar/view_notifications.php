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

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle marking notifications as read
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $submitted_csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);

    // Validate CSRF token
    if (!$submitted_csrf_token || $submitted_csrf_token !== $csrf_token) {
        $_SESSION['error'] = "Invalid CSRF token. Please try again.";
        header("Location: view_notifications.php");
        exit;
    }

    if ($action === 'mark_read') {
        $notification_id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        if ($notification_id) {
            // Mark a single notification as read
            $update_query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
            $stmt_update = $db->prepare($update_query);
            if ($stmt_update) {
                $stmt_update->bind_param("ii", $notification_id, $registrar_id);
                $stmt_update->execute();
                if ($stmt_update->affected_rows > 0) {
                    $_SESSION['success'] = "Notification marked as read.";
                } else {
                    $_SESSION['error'] = "Failed to mark notification as read or you don't have permission.";
                }
                $stmt_update->close();
            } else {
                error_log("Prepare failed for marking notification as read: " . $db->error);
                $_SESSION['error'] = "An error occurred while marking the notification as read.";
            }
        } else {
            $_SESSION['error'] = "Invalid notification ID.";
        }
    } elseif ($action === 'mark_all_read') {
        // Mark all notifications as read
        $update_all_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        $stmt_update_all = $db->prepare($update_all_query);
        if ($stmt_update_all) {
            $stmt_update_all->bind_param("i", $registrar_id);
            $stmt_update_all->execute();
            if ($stmt_update_all->affected_rows > 0) {
                $_SESSION['success'] = "All notifications marked as read.";
            } else {
                $_SESSION['error'] = "No unread notifications to mark as read.";
            }
            $stmt_update_all->close();
        } else {
            error_log("Prepare failed for marking all notifications as read: " . $db->error);
            $_SESSION['error'] = "An error occurred while marking all notifications as read.";
        }
    }
    header("Location: view_notifications.php");
    exit;
}

// Pagination settings
$notifications_per_page = 10;
$page = isset($_GET['page']) ? max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT)) : 1;
$offset = ($page - 1) * $notifications_per_page;

// Count total notifications for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM notifications n
    WHERE n.user_id = ?
";
$stmt_count = $db->prepare($count_query);
if (!$stmt_count) {
    error_log("Prepare failed for count query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching notifications.";
    header("Location: dashboard.php");
    exit;
}
$stmt_count->bind_param("i", $registrar_id);
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_notifications = $count_result->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = max(1, ceil($total_notifications / $notifications_per_page));

// Fetch notifications
$notifications_query = "
    SELECT n.id, n.complaint_id, n.description, n.is_read, n.created_at,
           c.title as complaint_title
    FROM notifications n
    LEFT JOIN complaints c ON n.complaint_id = c.id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt_notifications = $db->prepare($notifications_query);
if (!$stmt_notifications) {
    error_log("Prepare failed for notifications query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching notifications.";
    header("Location: dashboard.php");
    exit;
}
$stmt_notifications->bind_param("iii", $registrar_id, $notifications_per_page, $offset);
$stmt_notifications->execute();
$notifications_result = $stmt_notifications->get_result();
$notifications = [];
while ($row = $notifications_result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt_notifications->close();

// Fetch notification count for the sidebar
$notification_count = 0;
$notif_count_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_count_stmt) {
    $notif_count_stmt->bind_param("i", $registrar_id);
    $notif_count_stmt->execute();
    $notif_count_result = $notif_count_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_count_result ? $notif_count_result['count'] : 0;
    $notif_count_stmt->close();
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
    <title>View Notifications | DMU Complaint System</title>
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

        /* Notification Table */
        .notifications-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            background: #fff;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .notifications-table th,
        .notifications-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }

        .notifications-table th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
        }

        .notifications-table tr {
            transition: background 0.3s ease;
        }

        .notifications-table tr:hover {
            background: #f9f9f9;
        }

        .notifications-table td {
            font-size: 0.95rem;
            color: var(--dark);
        }

        .unread {
            background: #fff3cd;
            font-weight: 500;
        }

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

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #218838 100%);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, var(--success) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
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

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 1rem;
            justify-content: flex-end;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 2rem;
        }

        .pagination a {
            padding: 8px 15px;
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--primary);
            background: #fff;
            border: 1px solid var(--light-gray);
            transition: var(--transition);
            font-weight: 500;
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
        }

        .pagination a.disabled {
            color: var(--gray);
            pointer-events: none;
            background: #f5f5f5;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 0;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .empty-state p {
            font-size: 1.1rem;
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
            .notifications-table th, .notifications-table td { padding: 0.75rem; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; overflow-y: hidden; }
            .main-content { min-height: calc(100vh - HeightOfVerticalNav); }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            .notifications-table { font-size: 0.9rem; }
            .notifications-table th, .notifications-table td { padding: 0.5rem; }
            .action-buttons { flex-direction: column; align-items: stretch; }
            .btn { width: 100%; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .notifications-table { display: block; overflow-x: auto; }
            .btn { margin-bottom: 5px; }
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
                <span>View Resolved Complaints</span>
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
            <h2>View Notifications</h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Mark All as Read Button -->
            <?php if ($notification_count > 0): ?>
                <div class="action-buttons">
                    <form method="POST" onsubmit="return confirm('Are you sure you want to mark all notifications as read?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-success"><i class="fas fa-check-double"></i> Mark All as Read</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Notifications Table -->
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <p>No notifications found.</p>
                </div>
            <?php else: ?>
                <table class="notifications-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Related Complaint</th>
                            <th>Status</th>
                            <th>Received At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $notification): ?>
                            <tr class="<?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
                                <td><?php echo htmlspecialchars($notification['description']); ?></td>
                                <td>
                                    <?php if ($notification['complaint_id']): ?>
                                        <a href="view_complaint.php?complaint_id=<?php echo $notification['complaint_id']; ?>">
                                            #<?php echo $notification['complaint_id']; ?> - <?php echo htmlspecialchars($notification['complaint_title']); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?></td>
                                <td><?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></td>
                                <td>
                                    <?php if (!$notification['is_read']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Mark as Read</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($notification['complaint_id']): ?>
                                        <a href="view_complaint.php?complaint_id=<?php echo $notification['complaint_id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-eye"></i> View Complaint
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-left"></i> Previous</a>
                    <?php else: ?>
                        <a href="#" class="disabled"><i class="fas fa-chevron-left"></i> Previous</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>">Next <i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <a href="#" class="disabled">Next <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
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
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>