<?php
session_start();
require_once '../db_connect.php';

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

// Check if complaint ID is provided in the URL
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details to verify access and committee assignment
$stmt_complaint = $db->prepare("
    SELECT c.handler_id, c.committee_id, c.needs_video_chat, c.title
    FROM complaints c
    WHERE c.id = ?
");
$stmt_complaint->bind_param("i", $complaint_id);
$stmt_complaint->execute();
$result_complaint = $stmt_complaint->get_result();

if ($result_complaint->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result_complaint->fetch_assoc();
$stmt_complaint->close();

// Check if the current handler is authorized to start the chat
if ($complaint['handler_id'] != $handler_id) {
    $_SESSION['error'] = "You are not authorized to start a chat for this complaint.";
    header("Location: dashboard.php");
    exit();
}

// Check if a committee is assigned and video chat is needed
if (is_null($complaint['committee_id']) || $complaint['needs_video_chat'] != 1) {
    $_SESSION['error'] = "Cannot start a committee chat for this complaint.";
    header("Location: view_complaint.php?complaint_id=$complaint_id");
    exit();
}

// Fetch committee members (assuming a `committees` and `committee_members` table exists)
// For this example, I'll use a placeholder list since the schema for committee members isn't provided
$committee_members = [
    ['name' => 'Committee Member 1', 'email' => 'member1@example.com'],
    ['name' => 'Committee Member 2', 'email' => 'member2@example.com'],
    ['name' => 'Committee Member 3', 'email' => 'member3@example.com'],
];

// In a real implementation, you'd fetch this from the database:
// $stmt_committee = $db->prepare("
//     SELECT u.fname, u.lname, u.email 
//     FROM users u 
//     JOIN committee_members cm ON u.id = cm.user_id 
//     WHERE cm.committee_id = ?
// ");
// $stmt_committee->bind_param("i", $complaint['committee_id']);
// $stmt_committee->execute();
// $committee_members = $stmt_committee->get_result()->fetch_all(MYSQLI_ASSOC);
// $stmt_committee->close();

// Placeholder: Generate a chat link or session (e.g., using Zoom API, Jitsi, or WebRTC)
// For now, we'll use a placeholder link
$chat_link = "#"; // Replace with actual video chat link (e.g., "https://zoom.us/j/123456789")
$_SESSION['success'] = "Committee chat session prepared. Use the link below to start the meeting.";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start Committee Chat | DMU Complaint System</title>
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
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }

        /* Card Styling */
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

        .member-list {
            list-style: none;
            padding: 0;
            margin: 20px 0;
        }
        .member-list li {
            padding: 10px 15px;
            background: var(--light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            margin-bottom: 10px;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .member-list li:last-child {
            margin-bottom: 0;
        }

        .action-buttons {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }

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
        .btn i {
            font-size: 1em;
            line-height: 1;
        }
        .btn-small {
            padding: 6px 12px;
            font-size: 0.8rem;
            gap: 5px;
        }
        .btn-info {
            background-color: var(--info);
            color: #fff;
        }
        .btn-info:hover {
            background-color: #12a1b6;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-danger {
            background-color: var(--danger);
            color: #fff;
        }
        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }
        .btn-accent {
            background-color: var(--accent);
            color: white;
        }
        .btn-accent:hover {
            background-color: #3abde0;
            box-shadow: var(--shadow-hover);
            transform: translateY(-1px);
        }

        /* Footer */
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

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav {
                width: 75px;
            }
            .vertical-nav .nav-header .logo-text,
            .vertical-nav .user-info,
            .vertical-nav .nav-menu h3,
            .vertical-nav .nav-link span {
                display: none;
            }
            .vertical-nav .nav-header .user-profile-mini i {
                font-size: 1.8rem;
            }
            .vertical-nav .user-profile-mini {
                padding: 8px;
                justify-content: center;
            }
            .vertical-nav .nav-link {
                justify-content: center;
                padding: 15px 10px;
            }
            .vertical-nav .nav-link i {
                margin-right: 0;
                font-size: 1.3rem;
            }
            .main-content {
                margin-left: 75px;
            }
            .horizontal-nav {
                left: 75px;
            }
            .main-footer {
                margin-left: 75px;
            }
        }
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 2px solid var(--primary-dark);
                flex-direction: column;
            }
            .vertical-nav .nav-header .logo-text,
            .vertical-nav .user-info {
                display: block;
            }
            .nav-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: none;
                padding-bottom: 10px;
            }
            .nav-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                padding: 5px 0;
                overflow-y: visible;
            }
            .nav-menu h3 {
                display: none;
            }
            .nav-link {
                flex-direction: row;
                width: auto;
                padding: 8px 12px;
            }
            .nav-link i {
                margin-right: 8px;
                margin-bottom: 0;
                font-size: 1rem;
            }
            .nav-link span {
                display: inline;
                font-size: 0.85rem;
            }
            .horizontal-nav {
                position: static;
                left: auto;
                right: auto;
                width: 100%;
                padding: 10px 15px;
                height: auto;
                flex-direction: column;
                align-items: stretch;
                border-radius: 0;
            }
            .main-content {
                margin-left: 0;
                padding: 15px;
                padding-top: 20px;
            }
            .main-footer {
                margin-left: 0;
            }
            .page-header h2 {
                font-size: 1.5rem;
            }
            .card {
                padding: 20px;
            }
            .card-header {
                font-size: 1.1rem;
            }
            .btn {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
        }
        @media (max-width: 576px) {
            .content-container {
                padding: 1rem;
            }
            .card {
                padding: 15px;
            }
            .page-header h2 {
                font-size: 1.3rem;
            }
            .btn {
                padding: 7px 12px;
                font-size: 0.85rem;
                width: 100%;
            }
            .btn-small {
                padding: 5px 10px;
                font-size: 0.75rem;
            }
            .horizontal-nav .logo span {
                font-size: 1.1rem;
            }
            .nav-header .logo-text {
                font-size: 1.1rem;
            }
            .action-buttons {
                flex-direction: column;
            }
            .action-buttons .btn {
                width: 100%;
                margin-right: 0;
            }
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
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['role']); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_assigned_complaints.php" class="nav-link">
                <i class="fas fa-list-alt fa-fw"></i>
                <span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link">
                <i class="fas fa-check-circle fa-fw"></i>
                <span>Resolved Complaints</span>
            </a>

            <h3>Communication</h3>
            <a href="manage_notices.php" class="nav-link">
                <i class="fas fa-bullhorn fa-fw"></i>
                <span>Manage Notices</span>
            </a>
            <a href="view_notifications.php" class="nav-link">
                <i class="fas fa-bell fa-fw"></i>
                <span>View Notifications</span>
            </a>
            <a href="view_decisions.php" class="nav-link">
                <i class="fas fa-gavel fa-fw"></i>
                <span>Decisions Received</span>
            </a>
            <a href="view_feedback.php" class="nav-link">
                <i class="fas fa-comment-dots fa-fw"></i>
                <span>Complaint Feedback</span>
            </a>

            <h3>Reports</h3>
            <a href="generate_report.php" class="nav-link">
                <i class="fas fa-file-alt fa-fw"></i>
                <span>Generate Report</span>
            </a>

            <h3>Account</h3>
            <a href="change_password.php" class="nav-link">
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
                <a href="../index.php">
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
                <h2>Start Committee Chat</h2>
            </div>

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

            <!-- Chat Initiation Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-video"></i> Committee Chat for Complaint #<?php echo $complaint_id; ?>
                </div>
                <div class="card-body">
                    <p><strong>Complaint Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                    <p><strong>Committee ID:</strong> <?php echo htmlspecialchars($complaint['committee_id']); ?></p>
                    <h3 style="font-size: 1.1rem; margin: 20px 0 10px;">Committee Members</h3>
                    <ul class="member-list">
                        <?php foreach ($committee_members as $member): ?>
                            <li>
                                <span><?php echo htmlspecialchars($member['name']); ?></span>
                                <span style="color: var(--text-muted);"><?php echo htmlspecialchars($member['email']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="action-buttons">
                        <a href="<?php echo htmlspecialchars($chat_link); ?>" target="_blank" class="btn btn-accent">
                            <i class="fas fa-video"></i> Join Video Chat
                        </a>
                        <a href="view_complaint.php?complaint_id=<?php echo $complaint_id; ?>" class="btn btn-info">
                            <i class="fas fa-arrow-left"></i> Back to Complaint
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Handler Panel
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