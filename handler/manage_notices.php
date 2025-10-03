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
    header("Location: ../login.php");
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
        header("Location: ../logout.php");
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching handler details.";
    header("Location: ../logout.php");
    exit;
}
// --- End Fetch handler details ---


// Handle notice posting
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['post_notice'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);

    // Basic server-side validation
    if (empty($title) || empty($description)) {
        $_SESSION['error'] = "Notice Title and Description cannot be empty.";
    } elseif (strlen($title) > 255) {
         $_SESSION['error'] = "Notice Title is too long (max 255 characters).";
    }
    else {
        $sql = "INSERT INTO notices (handler_id, title, description, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $db->prepare($sql);
         if ($stmt) {
            $stmt->bind_param("iss", $handler_id, $title, $description);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Notice posted successfully.";
            } else {
                $_SESSION['error'] = "Error posting notice: " . $stmt->error;
                error_log("Notice post error: " . $stmt->error); // Log DB error
            }
            $stmt->close();
        } else {
             $_SESSION['error'] = "Database error preparing notice statement: " . $db->error;
             error_log("Notice prepare error: " . $db->error);
        }
    }
    // Redirect back to the same page to show message and prevent resubmission
    header("Location: manage_notices.php");
    exit;
}

// Handle notice deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_notice'])) {
     $delete_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);

    if($delete_id) {
         // Verify the handler owns this notice before deleting
        $sql = "DELETE FROM notices WHERE id = ? AND handler_id = ?";
        $stmt = $db->prepare($sql);
        if($stmt) {
            $stmt->bind_param("ii", $delete_id, $handler_id);
            if ($stmt->execute()) {
                 if ($stmt->affected_rows > 0) {
                    $_SESSION['success'] = "Notice #{$delete_id} deleted successfully.";
                 } else {
                     $_SESSION['warning'] = "Notice #{$delete_id} not found or you don't have permission to delete it.";
                 }
            } else {
                $_SESSION['error'] = "Error deleting notice: " . $stmt->error;
                 error_log("Notice delete error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error preparing delete statement: " . $db->error;
            error_log("Notice delete prepare error: " . $db->error);
        }
    } else {
        $_SESSION['error'] = "Invalid Notice ID for deletion.";
    }
    header("Location: manage_notices.php");
    exit;
}

// Fetch all notices posted by this handler
$sql_fetch = "SELECT n.*, u.fname, u.lname
              FROM notices n
              JOIN users u ON n.handler_id = u.id
              WHERE n.handler_id = ?
              ORDER BY n.created_at DESC";
$stmt_fetch = $db->prepare($sql_fetch);
$notices_result = null;
$notices_data = []; // Store data
if ($stmt_fetch) {
    $stmt_fetch->bind_param("i", $handler_id);
    $stmt_fetch->execute();
    $notices_result = $stmt_fetch->get_result();
    if ($notices_result) {
        $notices_data = $notices_result->fetch_all(MYSQLI_ASSOC); // Fetch all data
    }
    $stmt_fetch->close();
} else {
    error_log("Error preparing fetch notices query: " . $db->error);
    $_SESSION['error'] = "Database error fetching notices.";
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Notices | DMU Complaint System</title>
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

        /* Form Styles */
        .notice-form-card .form-group { margin-bottom: 20px; }
        .notice-form-card label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--text-color); font-size: 0.9rem;}
        .notice-form-card input[type="text"],
        .notice-form-card textarea {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--border-color); border-radius: var(--radius);
            font-size: 0.95rem; transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .notice-form-card input[type="text"]:focus,
        .notice-form-card textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }
        .notice-form-card textarea { min-height: 120px; resize: vertical; }
        .notice-form-card .btn { margin-top: 10px; }

        /* Table Styles */
        .table-responsive { overflow-x: auto; width: 100%; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px 15px; text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle; font-size: 0.9rem;
        }
        th { background-color: #f8f9fa; font-weight: 600; color: var(--dark); white-space: nowrap; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: #f1f5f9; }
        td .description-cell {
             max-width: 400px; /* Adjust width as needed */
             white-space: normal;
             word-wrap: break-word;
             line-height: 1.5;
         }
        .actions-cell { white-space: nowrap; }
        .actions-cell .btn { margin-left: 5px; } /* Space between buttons */
        .text-muted { color: var(--text-muted); font-style: italic; }

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
        /* Add other button styles (.btn-success, .btn-warning) if needed */

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
             td .description-cell { max-width: 250px; } /* Adjust max width for smaller screens */
        }
         @media (max-width: 576px) {
            .content-container { padding: 1rem; }
            .card { padding: 15px;}
            .page-header h2 { font-size: 1.3rem; }
            .btn { padding: 7px 12px; font-size: 0.85rem;}
             .btn-small { padding: 5px 10px; font-size: 0.75rem;}
            .horizontal-nav .logo span { font-size: 1.1rem;}
             .nav-header .logo-text { font-size: 1.1rem;}
             td .description-cell { max-width: 180px; }
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
                <h2>Manage Notices</h2>
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

            <!-- Notice Posting Form Card -->
            <div class="card notice-form-card">
                <div class="card-header">
                    <i class="fas fa-pen-to-square"></i> Post a New Notice
                </div>
                <div class="card-body">
                    <form method="post" action="manage_notices.php">
                        <div class="form-group">
                            <label for="title">Notice Title</label>
                            <input type="text" name="title" id="title" required maxlength="255" placeholder="Enter a concise title for the notice">
                        </div>
                        <div class="form-group">
                            <label for="description">Notice Description</label>
                            <textarea name="description" id="description" rows="5" required placeholder="Enter the full details of the notice..."></textarea>
                        </div>
                        <button type="submit" name="post_notice" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Post Notice
                        </button>
                    </form>
                </div>
            </div>

            <!-- Display Existing Notices Card -->
            <div class="card notice-list-card">
                <div class="card-header">
                    <i class="fas fa-list-ul"></i> Your Posted Notices
                </div>
                <div class="card-body">
                    <?php if (!empty($notices_data)): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Description</th>
                                        <th>Posted On</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($notices_data as $notice): ?>
                                        <tr>
                                            <td><?php echo $notice['id']; ?></td>
                                            <td><?php echo htmlspecialchars($notice['title']); ?></td>
                                            <td class="description-cell"><?php echo nl2br(htmlspecialchars($notice['description'])); ?></td>
                                            <td><?php echo date("M j, Y, g:i a", strtotime($notice['created_at'])); ?></td>
                                            <td class="actions-cell">
                                                <form method="POST" action="manage_notices.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete Notice #<?php echo $notice['id']; ?>? This cannot be undone.');">
                                                    <input type="hidden" name="delete_id" value="<?php echo $notice['id']; ?>">
                                                    <button type="submit" name="delete_notice" class="btn btn-danger btn-small" title="Delete Notice">
                                                        <i class="fas fa-trash-alt"></i> Delete
                                                    </button>
                                                </form>
                                                 <!-- Edit button (link to edit_notice.php) -->
                                                 <!-- <a href="edit_notice.php?id=<?php echo $notice['id']; ?>" class="btn btn-info btn-small" title="Edit Notice"><i class="fas fa-edit"></i> Edit</a> -->
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="text-align: center; padding: 20px 0;">You haven't posted any notices yet.</p>
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
                }, 7000); // Hide after 7 seconds
            });
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