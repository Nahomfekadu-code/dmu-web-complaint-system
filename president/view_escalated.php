<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'president'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'president') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Generate CSRF token if not already set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch user details (the logged-in President)
$sql_user = "SELECT fname, lname, email, phone, role, department, college FROM users WHERE id = ?";
$stmt_user = $db->prepare($sql_user);
if (!$stmt_user) {
    error_log("Prepare failed for user fetch: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: ../logout.php");
    exit;
}
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

if (!$user) {
    $_SESSION['error'] = "President details not found.";
    header("Location: ../logout.php");
    exit;
}

// Fetch notification count
$sql_notif_count = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif_count = $db->prepare($sql_notif_count);
$stmt_notif_count->bind_param("i", $user_id);
$stmt_notif_count->execute();
$notification_count = $stmt_notif_count->get_result()->fetch_assoc()['count'];
$stmt_notif_count->close();

// Pagination for Escalated Complaints
$items_per_page = 10;
$page = isset($_GET['page']) ? max(1, filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) : 1;
if ($page === false) {
    $page = 1;
}
$offset = ($page - 1) * $items_per_page;

// Count total escalated complaints for pagination
$sql_count = "SELECT COUNT(*) as total 
              FROM escalations e 
              WHERE e.escalated_to = 'president' 
              AND e.escalated_to_id = ? 
              AND e.status = 'pending'";
$stmt_count = $db->prepare($sql_count);
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$total_complaints = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = max(1, ceil($total_complaints / $items_per_page));

// Fetch escalated complaints for the President with pagination
$sql = "SELECT e.*, c.title, c.description, u.fname, u.lname 
        FROM escalations e 
        JOIN complaints c ON e.complaint_id = c.id 
        JOIN users u ON c.user_id = u.id 
        WHERE e.escalated_to = 'president' 
        AND e.escalated_to_id = ? 
        AND e.status = 'pending' 
        ORDER BY e.created_at DESC 
        LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    error_log("Error preparing escalated complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching escalated complaints.";
}
$stmt->bind_param("iii", $user_id, $items_per_page, $offset);
$stmt->execute();
$escalated_complaints_result = $stmt->get_result();
$stmt->close();

// Handle success/error messages from session
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success']);
unset($_SESSION['error']);

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Escalated Complaints | DMU Complaint System</title>
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
            --background: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 6px 18px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50;
            --navbar-link: #bdc3c7;
            --navbar-link-hover: #34495e;
            --navbar-link-active: var(--primary);
            --topbar-bg: #ffffff;
            --topbar-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Montserrat', sans-serif;
        }

        body {
            background-color: var(--background);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--navbar-bg) 0%, #34495e 100%);
            color: #ecf0f1;
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
        }

        .nav-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(236, 240, 241, 0.1);
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%;
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.05);
            padding: 8px 12px;
            border-radius: var(--radius);
            margin-top: 10px;
        }

        .user-profile-mini i {
            font-size: 2rem;
            color: var(--accent);
        }

        .user-info h4 {
            font-size: 0.95rem;
            margin-bottom: 2px;
            font-weight: 500;
        }

        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: capitalize;
        }

        .nav-menu {
            padding: 0 10px;
            flex-grow: 1;
            overflow-y: auto;
        }

        .nav-menu::-webkit-scrollbar { width: 6px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }

        .nav-menu h3 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 25px 15px 10px;
            opacity: 0.6;
            font-weight: 600;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            color: var(--navbar-link);
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
            font-size: 0.95rem;
            font-weight: 400;
            white-space: nowrap;
        }

        .nav-link:hover {
            background: var(--navbar-link-hover);
            color: #ecf0f1;
            transform: translateX(3px);
        }

        .nav-link.active {
            background: var(--navbar-link-active);
            color: white;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1em;
            opacity: 0.8;
        }

        .nav-link.active i {
            opacity: 1;
        }

        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .horizontal-nav {
            background: var(--topbar-bg);
            border-radius: var(--radius);
            box-shadow: var(--topbar-shadow);
            padding: 12px 25px;
            margin-bottom: 25px;
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
            align-items: center;
            gap: 15px;
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .horizontal-menu a i {
            font-size: 1rem;
            color: var(--gray);
        }

        .horizontal-menu a:hover i, .horizontal-menu a.active i {
            color: var(--primary-dark);
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

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 3px 8px rgba(0,0,0,0.07);
        }

        .alert i { font-size: 1.2rem; margin-right: 5px; }
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }

        .content-container {
            background: var(--card-bg);
            padding: 2rem;
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

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }

        .card {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.3rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header i {
            font-size: 1.4rem;
            color: var(--primary);
            margin-right: 8px;
        }

        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: #f1f5f9;
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
        .status-escalated { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-uncategorized { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            line-height: 1.5;
            white-space: nowrap;
        }

        .btn i { font-size: 1em; line-height: 1; }
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-secondary { background-color: var(--gray); color: #fff; }
        .btn-secondary:hover { background-color: #5a6268; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-info { background-color: var(--info); color: #fff; }
        .btn-info:hover { background-color: #0baccc; transform: translateY(-1px); box-shadow: var(--shadow-hover); }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--primary);
            transition: var(--transition);
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
        }

        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .main-footer {
            background-color: var(--card-bg);
            padding: 15px 30px;
            margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-muted);
            flex-shrink: 0;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .vertical-nav { width: 75px; }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info, .vertical-nav .nav-menu h3, .vertical-nav .nav-link span { display: none; }
            .vertical-nav .nav-header .user-profile-mini i { font-size: 1.8rem; }
            .vertical-nav .user-profile-mini { padding: 8px; justify-content: center; }
            .vertical-nav .nav-link { justify-content: center; padding: 15px 10px; }
            .vertical-nav .nav-link i { margin-right: 0; font-size: 1.3rem; }
            .main-content { margin-left: 75px; }
            .horizontal-nav { left: 75px; }
            .main-footer { margin-left: 75px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 2px solid var(--primary-dark);
                flex-direction: column;
            }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block; }
            .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 10px; }
            .nav-menu { display: flex; flex-wrap: wrap; justify-content: center; padding: 5px 0; overflow-y: visible; }
            .nav-menu h3 { display: none; }
            .nav-link { flex-direction: row; width: auto; padding: 8px 12px; }
            .nav-link i { margin-right: 8px; margin-bottom: 0; font-size: 1rem; }
            .nav-link span { display: inline; font-size: 0.85rem; }
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .main-footer { margin-left: 0; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .btn-small { padding: 5px 10px; font-size: 0.75rem; }
            th, td { font-size: 0.85rem; padding: 10px 8px; }
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
                    <h4><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($user['role']); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_complaint.php" class="nav-link <?php echo $current_page == 'view_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt fa-fw"></i>
                <span>All Complaints</span>
            </a>
            <a href="view_escalated.php" class="nav-link <?php echo $current_page == 'view_escalated.php' ? 'active' : ''; ?>">
                <i class="fas fa-level-up-alt fa-fw"></i>
                <span> Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle fa-fw"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="view_reports.php" class="nav-link <?php echo $current_page == 'view_reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt fa-fw"></i>
                <span>View Reports</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>Notifications</span>
            </a>

            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit fa-fw"></i>
                <span>Edit Profile</span>
            </a>
            <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                <i class="fas fa-key fa-fw"></i>
                <span>Change Password</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - President</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
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
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Page Specific Content -->
        <div class="content-container">
            <div class="page-header">
                <h2>Escalated Complaints</h2>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Escalated Complaints Card -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-exclamation-triangle"></i> Pending Escalated Complaints</span>
                </div>
                <div class="card-body">
                    <?php if ($escalated_complaints_result && $escalated_complaints_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Complaint ID</th>
                                        <th>Title</th>
                                        <th>Description</th>
                                        <th>Submitted By</th>
                                        <th>Escalated At</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($complaint = $escalated_complaints_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $complaint['complaint_id']; ?></td>
                                            <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                            <td><?php echo htmlspecialchars($complaint['description']); ?></td>
                                            <td><?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?></td>
                                            <td><?php echo date("M j, Y, g:i a", strtotime($complaint['created_at'])); ?></td>
                                            <td>
                                                <a href="decide_complaint.php?complaint_id=<?php echo $complaint['complaint_id']; ?>&escalation_id=<?php echo $complaint['id']; ?>" class="btn btn-primary btn-small" title="Decide on Complaint">
                                                    <i class="fas fa-gavel"></i> Decide
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination -->
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: var(--text-muted); padding: 20px 0;">No pending escalated complaints assigned to you.</p>
                    <?php endif; ?>
                    <?php if ($escalated_complaints_result) $escalated_complaints_result->free(); ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | President Panel
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