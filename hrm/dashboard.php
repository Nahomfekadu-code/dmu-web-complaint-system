<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is 'hrm' (case-insensitive)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'hrm') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user = null;

// Fetch Human Resource Management details
$sql_user = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_user = $db->prepare($sql_user);
if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $user = $result_user->fetch_assoc();
    } else {
        $_SESSION['error'] = "User details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_user->close();
} else {
    error_log("Error preparing user query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details.";
    header("Location: ../logout.php");
    exit;
}

// Fetch complaints assigned to or escalated to Human Resource Management
$sql_complaints = "
    SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, c.resolution_date,
           e.id as escalation_id, e.escalated_by_id, e.status as escalation_status,
           u.fname as submitter_fname, u.lname as submitter_lname
    FROM complaints c
    LEFT JOIN escalations e ON c.id = e.complaint_id 
        AND e.escalated_to = 'hrm' 
        AND e.escalated_to_id = ?
        AND e.status = 'pending'
    JOIN users u ON c.user_id = u.id
    WHERE (e.id IS NOT NULL OR c.status = 'pending')
    ORDER BY c.created_at DESC";
$stmt_complaints = $db->prepare($sql_complaints);
if (!$stmt_complaints) {
    error_log("Prepare failed for complaints fetch: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaints.";
    header("Location: ../logout.php");
    exit;
}
$stmt_complaints->bind_param("i", $user_id);
$stmt_complaints->execute();
$complaints_result = $stmt_complaints->get_result();
$complaints = $complaints_result->fetch_all(MYSQLI_ASSOC);
$stmt_complaints->close();

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $user_id);
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
    <title>Dashboard | Human Resource Management</title>
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
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: var(--radius);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        th, td {
            padding: 1rem 1.2rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background-color: var(--light);
        }

        td {
            font-size: 0.95rem;
            color: var(--dark);
        }

        .status-pending { color: var(--warning); font-weight: 600; }
        .status-in_progress { color: var(--info); font-weight: 600; }
        .status-resolved { color: var(--success); font-weight: 600; }
        .status-rejected { color: var(--danger); font-weight: 600; }

        .action-buttons {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            text-align: center;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            transform: translateY(-1px);
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
            .main-content { min-height: auto; }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }
            .action-buttons { flex-direction: column; align-items: flex-start; }
            .btn { width: 100%; justify-content: center; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            th, td { padding: 0.8rem; font-size: 0.85rem; }
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
                    <h4><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))); ?></p>
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
                <span>DMU Complaint System - Human Resource Management</span>
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
            <h2>Dashboard Overview</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <h3>Pending Complaints</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Submitted By</th>
                            <th>Created On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($complaints)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--gray);">
                                    No pending complaints found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($complaint['id']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></td>
                                    <td>
                                        <span class="status-<?php echo htmlspecialchars(strtolower($complaint['status'])); ?>">
                                            <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($complaint['escalation_id']): ?>
                                                <a href="decide_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-primary">
                                                    <i class="fas fa-gavel"></i> Decide
                                                </a>
                                            <?php endif; ?>
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-secondary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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