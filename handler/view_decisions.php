<?php
session_start();
require_once '../db_connect.php'; // Ensure this path is correct

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'handler') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php"); // Or appropriate dashboard
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null; // Initialize handler variable

// --- Fetch handler details ---
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
    } else {
        $_SESSION['error'] = "Handler details not found.";
        header("Location: ../logout.php"); // Logout if handler not found
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching handler details.";
    header("Location: ../logout.php"); // Logout on DB error
    exit;
}
// --- End Fetch handler details ---


// Fetch decisions received by the handler
$sql_decisions = "
    SELECT
        d.id as decision_id,
        d.complaint_id,
        d.decision_text,
        d.status as decision_status,
        d.created_at as sent_on,
        c.title as complaint_title,
        c.status as complaint_status,
        u_sender.fname as sender_fname,
        u_sender.lname as sender_lname,
        u_sender.role as sender_role
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    JOIN users u_sender ON d.sender_id = u_sender.id
    WHERE d.receiver_id = ?
    ORDER BY d.created_at DESC";
$stmt_decisions = $db->prepare($sql_decisions);
$decisions_result = null;
$decisions_data = []; // Store data

if ($stmt_decisions) {
    $stmt_decisions->bind_param("i", $handler_id);
    $stmt_decisions->execute();
    $decisions_result = $stmt_decisions->get_result();
    if ($decisions_result) {
        $decisions_data = $decisions_result->fetch_all(MYSQLI_ASSOC); // Fetch all data
    }
    $stmt_decisions->close();
} else {
    error_log("Error preparing decisions query: " . $db->error);
    $_SESSION['error'] = "Database error fetching decisions.";
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Received Decisions | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Paste the FULL CSS from previous examples (e.g., generate_report.php) */
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
            --info: #17a2b8; /* Consistent info color */
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

        /* Vertical Navigation */
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
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px;}
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px;}
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Horizontal Navigation */
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
             color: var(--grey);
        }
         .horizontal-menu a:hover i, .horizontal-menu a.active i {
             color: var(--primary-dark);
         }

        .notification-icon {
            position: relative;
        }
        .notification-icon i {
            font-size: 1.3rem;
            color: var(--grey);
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .notification-icon:hover i {
            color: var(--primary);
        }
        .notification-icon a i.active-page {
            color: var(--primary);
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
            box-shadow: 0 3px 8px rgba(0,0,0,0.07);
        }
        .alert i { font-size: 1.2rem; margin-right: 5px;}
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-warning { background-color: #fff8e1; border-color: #ffecb3; color: #856404; }
        .alert-info { background-color: #e1f5fe; border-color: #b3e5fc; color: #01579b; }

        /* Content Container */
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

        /* Page Header Styling */
         .page-header h2 {
             font-size: 1.8rem; font-weight: 600; color: var(--primary-dark);
             margin-bottom: 25px; border-bottom: 3px solid var(--primary);
             padding-bottom: 10px; display: inline-block;
         }

        /* Card Styling */
         .card {
             background-color: var(--card-bg); padding: 25px; border-radius: var(--radius);
             box-shadow: var(--shadow); margin-bottom: 30px; border: 1px solid var(--border-color);
         }
         .card-header {
             display: flex; justify-content: space-between; align-items: center;
             gap: 12px; margin-bottom: 25px;
             color: var(--primary-dark); font-size: 1.3rem; font-weight: 600;
             padding-bottom: 15px; border-bottom: 1px solid var(--border-color);
         }
         .card-header i { font-size: 1.4rem; color: var(--primary); margin-right: 8px;}

        /* Table Styles */
        .table-responsive { overflow-x: auto; width: 100%; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px 15px; text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle; font-size: 0.9rem;
        }
        th {
            background-color: #f8f9fa; font-weight: 600; color: var(--dark);
            white-space: nowrap; cursor: pointer; position: relative;
            padding-right: 25px;
        }
        th:hover { background-color: #e9ecef; }
        th::after {
            content: '\f0dc';
            font-family: "Font Awesome 6 Free"; font-weight: 900;
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            opacity: 0.3; transition: opacity 0.2s ease;
        }
        th:hover::after { opacity: 0.6; }
        th.asc::after { content: '\f0de'; opacity: 1; }
        th.desc::after { content: '\f0dd'; opacity: 1; }

        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: #f1f5f9; }
        td .decision-text-cell {
            max-width: 300px; /* Adjust width */
            white-space: normal; word-wrap: break-word;
            font-size: 0.9rem; color: var(--text-color); line-height: 1.5;
        }
        .actions-cell { white-space: nowrap; }
        .actions-cell .btn { margin-left: 5px; }
        .text-muted { color: var(--text-muted); font-style: italic; }

        /* Status Badges */
        .status {
            padding: 4px 10px; border-radius: var(--radius); font-size: 0.75rem;
            font-weight: 600; text-transform: capitalize; letter-spacing: 0.5px;
            color: #fff; text-align: center; display: inline-block; line-height: 1.2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); white-space: nowrap;
        }
        .status-pending { background-color: var(--warning); color: var(--dark); }
        .status-final { background-color: var(--success); } /* For decision status */
        .status-validated { background-color: var(--info); }
        .status-in_progress { background-color: var(--primary); }
        .status-resolved { background-color: var(--success); } /* For complaint status */
        .status-rejected { background-color: var(--danger); }
        .status-pending_more_info { background-color: var(--orange); }
        .status-unknown { background-color: var(--grey); }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 500;
            cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5;
            white-space: nowrap;
        }
        .btn i { font-size: 1em; line-height: 1;}
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-info { background-color: var(--info); color: #fff; }
        .btn-info:hover { background-color: #12a1b6; transform: translateY(-1px); box-shadow: var(--shadow-hover); }

        /* Footer */
        .main-footer {
            background-color: var(--card-bg); padding: 15px 30px; margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center; font-size: 0.9rem; color: var(--text-muted);
            flex-shrink: 0;
            transition: margin-left 0.3s ease;
        }

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav { width: 75px; }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info, .vertical-nav .nav-menu h3, .vertical-nav .nav-link span { display: none; }
            .vertical-nav .nav-header .user-profile-mini i { font-size: 1.8rem; }
            .vertical-nav .user-profile-mini { padding: 8px; justify-content: center;}
            .vertical-nav .nav-link { justify-content: center; padding: 15px 10px; }
            .vertical-nav .nav-link i { margin-right: 0; font-size: 1.3rem; }
            .main-content { margin-left: 75px; }
            .horizontal-nav { left: 75px; }
            .main-footer { margin-left: 75px; }
        }
        @media (max-width: 768px) {
            body { flex-direction: column; }
             .vertical-nav {
                 width: 100%; height: auto; position: relative; box-shadow: none;
                 border-bottom: 2px solid var(--primary-dark); flex-direction: column;
             }
             .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block;}
             .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 10px;}
             .nav-menu { display: flex; flex-wrap: wrap; justify-content: center; padding: 5px 0; overflow-y: visible;}
             .nav-menu h3 { display: none; }
             .nav-link { flex-direction: row; width: auto; padding: 8px 12px; }
             .nav-link i { margin-right: 8px; margin-bottom: 0; font-size: 1rem; }
             .nav-link span { display: inline; font-size: 0.85rem; }

            .horizontal-nav {
                position: static; left: auto; right: auto; width: 100%;
                padding: 10px 15px; height: auto; flex-direction: column; align-items: stretch;
                border-radius: 0;
            }
            .top-nav-left { padding: 5px 0; text-align: center;}
            .top-nav-right { padding-top: 5px; justify-content: center; gap: 15px;}
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .main-footer { margin-left: 0; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            th, td { font-size: 0.85rem; padding: 10px 8px; }
            /* Responsive table adjustments */
             table.responsive-table,
             table.responsive-table thead,
             table.responsive-table tbody,
             table.responsive-table th,
             table.responsive-table td,
             table.responsive-table tr { display: block; }

             table.responsive-table thead tr { position: absolute; top: -9999px; left: -9999px; }
             table.responsive-table tr { border: 1px solid #ccc; border-radius: var(--radius); margin-bottom: 10px; background: var(--card-bg); }
             table.responsive-table td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 45%; text-align: right; display: flex; justify-content: flex-end; align-items: center; min-height: 40px;}
             table.responsive-table td:before {
                 position: absolute; top: 50%; left: 10px; transform: translateY(-50%);
                 width: 40%; padding-right: 10px; white-space: nowrap;
                 content: attr(data-label); font-weight: bold; text-align: left; font-size: 0.8rem; color: var(--primary-dark);
            }
             table.responsive-table td.decision-text-cell {
                 padding-left: 10px; text-align: left; justify-content: flex-start;
                 border-bottom: 1px solid #eee;
             }
             table.responsive-table td.decision-text-cell:before { display: none; }

             table.responsive-table td.actions-cell { border-bottom: none; padding-top: 10px; padding-bottom: 10px; justify-content: flex-end;}
             table.responsive-table td.actions-cell:before { display: none; }
             table.responsive-table tr td:last-child { border-bottom: none; }
        }
         @media (max-width: 576px) {
            .content-container { padding: 1rem; }
            .card { padding: 15px;}
            .page-header h2 { font-size: 1.3rem; }
            .btn { padding: 7px 12px; font-size: 0.85rem;}
             .btn-small { padding: 5px 10px; font-size: 0.75rem;}
            .horizontal-nav .logo span { font-size: 1.1rem;}
             .nav-header .logo-text { font-size: 1.1rem;}
             table.responsive-table td:before { width: 35%; font-size: 0.75rem;}
             table.responsive-table td { padding-left: 40%; }
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
            <?php if ($handler): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($handler['role']); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
             <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
             <a href="view_assigned_complaints.php" class="nav-link <?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                 <i class="fas fa-list-alt fa-fw"></i>
                 <span>Assigned Complaints</span>
             </a>
             <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                 <i class="fas fa-check-circle fa-fw"></i>
                 <span>Resolved Complaints</span>
             </a>
             <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i><span>Decisions Received</span>
            </a>
            <a href="send_decision.php" class="nav-link <?php echo $current_page == 'send_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i><span>Send Decision</span>
            </a>
             <a href="stereotype.php" class="nav-link <?php echo $current_page == 'stereotype.php' ? 'active' : ''; ?>">
                 <i class="fas fa-tags fa-fw"></i>
                 <span>Manage Stereotypes</span>
             </a>

            <h3>Communication</h3>
             <a href="manage_notices.php" class="nav-link <?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                 <i class="fas fa-bullhorn fa-fw"></i>
                 <span>Manage Notices</span>
             </a>
             <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                 <i class="fas fa-bell fa-fw"></i>
                 <span>View Notifications</span>
             </a>
              <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel fa-fw"></i>
                <span>Decisions Received</span>
            </a>
             <a href="view_feedback.php" class="nav-link <?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                 <i class="fas fa-comment-dots fa-fw"></i>
                 <span>Complaint Feedback</span>
             </a>

            <h3>Reports</h3>
            <a href="generate_report.php" class="nav-link <?php echo $current_page == 'generate_report.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt fa-fw"></i>
                <span>Generate Report</span>
            </a>

            <h3>Account</h3>
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
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                 <a href="../index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <div class="notification-icon" title="View Notifications">
                    <a href="view_notifications.php" style="color: inherit; text-decoration: none;">
                        <i class="fas fa-bell"></i>
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
                <h2>Received Decisions</h2>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Decisions Table Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-gavel"></i> Decisions Received from Authorities
                </div>
                <div class="card-body">
                    <?php if (!empty($decisions_data)): ?>
                        <div class="table-responsive">
                            <table id="decisionsTable" class="responsive-table">
                                <thead>
                                    <tr>
                                        <th data-sort="complaint_id">Complaint ID</th>
                                        <th data-sort="complaint_title">Complaint Title</th>
                                        <th data-sort="sender">Sender</th>
                                        <th>Decision Text</th>
                                        <th data-sort="decision_status">Decision Status</th>
                                        <th data-sort="sent_on">Sent On</th>
                                        <th>Complaint Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($decisions_data as $decision): ?>
                                        <tr>
                                            <td data-label="Complaint ID"><?php echo htmlspecialchars($decision['complaint_id']); ?></td>
                                            <td data-label="Complaint Title">
                                                 <a href="view_complaint.php?complaint_id=<?php echo $decision['complaint_id']; ?>" title="View Complaint Details">
                                                    <?php echo htmlspecialchars($decision['complaint_title']); ?>
                                                 </a>
                                            </td>
                                            <td data-label="Sender"><?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname'] . ' (' . ucfirst($decision['sender_role']) . ')'); ?></td>
                                            <td data-label="Decision" class="decision-text-cell"><?php echo nl2br(htmlspecialchars($decision['decision_text'])); ?></td>
                                            <td data-label="Decision Status">
                                                <span class="status status-<?php echo strtolower(htmlspecialchars($decision['decision_status'] ?? 'unknown')); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($decision['decision_status'] ?? 'Unknown')); ?>
                                                </span>
                                            </td>
                                            <td data-label="Sent On"><?php echo date("M j, Y, g:i a", strtotime($decision['sent_on'])); ?></td>
                                            <td data-label="Complaint Status">
                                                <span class="status status-<?php echo strtolower(htmlspecialchars($decision['complaint_status'] ?? 'unknown')); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($decision['complaint_status'] ?? 'Unknown')); ?>
                                                </span>
                                            </td>
                                            <td data-label="Actions" class="actions-cell">
                                                 <!-- Provide link to view complaint -->
                                                 <a href="view_complaint.php?complaint_id=<?php echo $decision['complaint_id']; ?>" class="btn btn-info btn-small" title="View Complaint Details">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                 <?php
                                                 // Potentially allow marking as resolved based on the decision
                                                 // Check if complaint is still 'in_progress' AND decision is 'final' (or similar)
                                                 if ($decision['complaint_status'] == 'in_progress' && $decision['decision_status'] == 'final'):
                                                 ?>
                                                    <a href="resolve_complaint.php?complaint_id=<?php echo $decision['complaint_id']; ?>&decision_id=<?php echo $decision['decision_id']; ?>" class="btn btn-success btn-small" title="Mark Complaint Resolved based on this Decision">
                                                        <i class="fas fa-check-double"></i> Resolve
                                                    </a>
                                                 <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="text-align: center; padding: 20px 0;">No decisions have been received yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div> <!-- End Content Container -->

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Handler Panel
        </footer>
    </div> <!-- End Main Content -->

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

            // Table sorting function (same as generate_report.php)
            function sortTableByColumn(table, column, asc = true) {
                const dirModifier = asc ? 1 : -1;
                const tBody = table.tBodies[0];
                const rows = Array.from(tBody.querySelectorAll("tr"));

                const sortedRows = rows.sort((a, b) => {
                    // Adjust querySelector index based on visual table order
                    let aColText = a.querySelector(`td:nth-child(${ column + 1 })`)?.textContent.trim().toLowerCase() || '';
                    let bColText = b.querySelector(`td:nth-child(${ column + 1 })`)?.textContent.trim().toLowerCase() || '';

                    const header = table.querySelector(`th:nth-child(${ column + 1 })`);
                    const sortKey = header?.dataset.sort;

                    if (sortKey === 'complaint_id') {
                         aColText = parseInt(aColText) || 0;
                         bColText = parseInt(bColText) || 0;
                    } else if (sortKey === 'sent_on') {
                         aColText = aColText === 'n/a' ? 0 : new Date(aColText).getTime() || 0;
                         bColText = bColText === 'n/a' ? 0 : new Date(bColText).getTime() || 0;
                    }

                    return aColText > bColText ? (1 * dirModifier) : (-1 * dirModifier);
                });

                while (tBody.firstChild) {
                    tBody.removeChild(tBody.firstChild);
                }

                tBody.append(...sortedRows);

                table.querySelectorAll("th").forEach(th => th.classList.remove("asc", "desc"));
                table.querySelector(`th:nth-child(${ column + 1 })`).classList.toggle("asc", asc);
                table.querySelector(`th:nth-child(${ column + 1 })`).classList.toggle("desc", !asc);
            }

            const tableElement = document.getElementById('decisionsTable');
            if (tableElement) {
                tableElement.querySelectorAll("th[data-sort]").forEach((headerCell) => {
                     const index = Array.prototype.indexOf.call(headerCell.parentNode.children, headerCell);
                     headerCell.addEventListener("click", () => {
                        const currentIsAscending = headerCell.classList.contains("asc");
                        sortTableByColumn(tableElement, index, !currentIsAscending);
                    });
                });
            }

        });
    </script>
</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>