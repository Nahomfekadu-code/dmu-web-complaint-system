<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'student_service_directorate'
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'student_service_directorate') {
    error_log("Role check failed. Role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'not set'));
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$ssd_id = $_SESSION['user_id'];
$ssd = null;

// Fetch Student Service Directorate details
$sql_ssd = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_ssd = $db->prepare($sql_ssd);
if ($stmt_ssd) {
    $stmt_ssd->bind_param("i", $ssd_id);
    $stmt_ssd->execute();
    $result_ssd = $stmt_ssd->get_result();
    if ($result_ssd->num_rows > 0) {
        $ssd = $result_ssd->fetch_assoc();
    } else {
        $_SESSION['error'] = "Student Service Directorate details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_ssd->close();
} else {
    error_log("Error preparing Student Service Directorate query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: ../logout.php");
    exit;
}

// Handle marking a single notification as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = filter_input(INPUT_GET, 'mark_read', FILTER_VALIDATE_INT);
    $update_sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0";
    $update_stmt = $db->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param("ii", $notification_id, $ssd_id);
        $update_stmt->execute();
        if ($update_stmt->affected_rows > 0) {
            $_SESSION['success'] = "Notification marked as read.";
        }
        $update_stmt->close();
    } else {
        error_log("Error preparing mark read query: " . $db->error);
        $_SESSION['error'] = "An error occurred while marking the notification as read.";
    }
    header("Location: view_notifications.php");
    exit;
}

// Handle marking all notifications as read
if (isset($_GET['mark_all_read']) && $_GET['mark_all_read'] === '1') {
    $update_all_sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $update_all_stmt = $db->prepare($update_all_sql);
    if ($update_all_stmt) {
        $update_all_stmt->bind_param("i", $ssd_id);
        $update_all_stmt->execute();
        if ($update_all_stmt->affected_rows > 0) {
            $_SESSION['success'] = "All notifications marked as read.";
        }
        $update_all_stmt->close();
    } else {
        error_log("Error preparing mark all read query: " . $db->error);
        $_SESSION['error'] = "An error occurred while marking all notifications as read.";
    }
    header("Location: view_notifications.php");
    exit;
}

// Fetch notifications
$notifications = [];
$notif_query = "
    SELECT n.id, n.complaint_id, n.description, n.is_read, n.created_at,
           c.status as complaint_status,
           e.id as escalation_id, e.status as escalation_status
    FROM notifications n
    LEFT JOIN complaints c ON n.complaint_id = c.id
    LEFT JOIN escalations e ON c.id = e.complaint_id AND e.escalated_to = 'student_service_directorate' AND e.escalated_to_id = ?
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC";
$notif_stmt = $db->prepare($notif_query);
if ($notif_stmt) {
    $notif_stmt->bind_param("ii", $ssd_id, $ssd_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result();
    while ($row = $notif_result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $notif_stmt->close();
} else {
    error_log("Error preparing notifications query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching notifications.";
}

// Count unread notifications
$unread_count = 0;
$unread_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$unread_stmt = $db->prepare($unread_query);
if ($unread_stmt) {
    $unread_stmt->bind_param("i", $ssd_id);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result()->fetch_assoc();
    $unread_count = $unread_result['count'];
    $unread_stmt->close();
} else {
    error_log("Error preparing unread notifications count query: " . $db->error);
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Student Service Directorate - DMU Complaint System</title>
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

        .notification-unread {
            background-color: #fff3cd;
            font-weight: 500;
        }

        .notification-read {
            background-color: #f8f9fa;
        }

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
        }

        .action-btn i {
            font-size: 0.9rem;
        }

        .btn-mark-read {
            background: var(--info);
            color: white;
        }

        .btn-mark-read:hover {
            background: #117a8b;
        }

        .btn-view {
            background: var(--primary);
            color: white;
        }

        .btn-view:hover {
            background: var(--primary-dark);
        }

        .mark-all-btn {
            display: inline-flex;
            padding: 0.6rem 1.2rem;
            background: var(--success);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            text-decoration: none;
        }

        .mark-all-btn:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .no-notifications {
            text-align: center;
            padding: 2rem;
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
            .action-btn, .mark-all-btn { width: 100%; justify-content: center; }
            .mark-all-btn { padding: 0.5rem; }
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
                    <h4><?php echo htmlspecialchars($ssd['fname'] . ' ' . $ssd['lname']); ?></h4>
                    <p>Student Service Directorate</p>
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
                <?php if ($unread_count > 0): ?>
                    <span class="badge badge-danger"><?php echo $unread_count; ?></span>
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
                <span>DMU Complaint System - Student Service Directorate</span>
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
            <h2>Notifications</h2>

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

            <?php if ($unread_count > 0): ?>
                <a href="view_notifications.php?mark_all_read=1" class="mark-all-btn">
                    <i class="fas fa-check-double"></i> Mark All as Read (<?php echo $unread_count; ?> Unread)
                </a>
            <?php endif; ?>

            <div class="table-container">
                <?php if (empty($notifications)): ?>
                    <p class="no-notifications">No notifications at this time.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Complaint ID</th>
                                <th>Received On</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr class="<?php echo $notification['is_read'] ? 'notification-read' : 'notification-unread'; ?>">
                                    <td><?php echo htmlspecialchars($notification['description']); ?></td>
                                    <td>
                                        <?php if ($notification['complaint_id']): ?>
                                            #<?php echo htmlspecialchars($notification['complaint_id']); ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></td>
                                    <td>
                                        <?php if ($notification['is_read']): ?>
                                            Read
                                        <?php else: ?>
                                            Unread
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$notification['is_read']): ?>
                                            <a href="view_notifications.php?mark_read=<?php echo $notification['id']; ?>" class="action-btn btn-mark-read">
                                                <i class="fas fa-check"></i> Mark as Read
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($notification['complaint_id'] && $notification['escalation_id'] && $notification['escalation_status'] === 'pending' && $notification['complaint_status'] !== 'resolved'): ?>
                                            <a href="view_complaint.php?complaint_id=<?php echo $notification['complaint_id']; ?>&escalation_id=<?php echo $notification['escalation_id']; ?>" class="action-btn btn-view">
                                                <i class="fas fa-eye"></i> View Complaint
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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

            // Confirmation for mark all as read
            const markAllBtn = document.querySelector('.mark-all-btn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', function(event) {
                    if (!confirm('Are you sure you want to mark all notifications as read?')) {
                        event.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>