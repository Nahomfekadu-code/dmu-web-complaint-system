<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'college_dean'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'college_dean') {
    header("Location: ../unauthorized.php");
    exit;
}

$dean_id = $_SESSION['user_id'];
$dean = null; // Initialize dean variable

// Fetch College Dean details (Needed for sidebar)
$sql_dean = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_dean = $db->prepare($sql_dean);
if ($stmt_dean) {
    $stmt_dean->bind_param("i", $dean_id);
    $stmt_dean->execute();
    $result_dean = $stmt_dean->get_result();
    if ($result_dean->num_rows > 0) {
        $dean = $result_dean->fetch_assoc();
    } else {
        // Handle case where dean details are not found
        error_log("College Dean details not found for ID: " . $dean_id);
        // Allow page to load but with default info
    }
    $stmt_dean->close();
} else {
    error_log("Error preparing college dean query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
}


// Mark notifications as read *before* fetching the count for the sidebar
$update_stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
if ($update_stmt) {
    $update_stmt->bind_param("i", $dean_id);
    $update_stmt->execute();
    $update_stmt->close();
} else {
     error_log("Error preparing notification update query: " . $db->error);
}


// --- Fetch notification count AFTER marking as read (will be 0) ---
// This count is mainly for the sidebar *before* this page loads.
// Re-fetching it here isn't strictly necessary for this page's display
// but keeps the pattern consistent if needed elsewhere.
$notification_count = 0; // Should be 0 now
// $notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
// if($notif_stmt) {
//     $notif_stmt->bind_param("i", $dean_id);
//     $notif_stmt->execute();
//     $notif_result = $notif_stmt->get_result()->fetch_assoc();
//     $notification_count = $notif_result ? $notif_result['count'] : 0;
//     $notif_stmt->close();
// } else {
//      error_log("Error preparing notification count query: " . $db->error);
// }


// Fetch all notifications for display (including already read ones)
$notifications_data = [];
$stmt = $db->prepare("
    SELECT n.*, c.title as complaint_title,
           e.id as escalation_id, e.status as escalation_status -- Get escalation ID and status
    FROM notifications n
    LEFT JOIN complaints c ON n.complaint_id = c.id
    LEFT JOIN escalations e ON n.complaint_id = e.complaint_id AND e.escalated_to = 'college_dean' AND e.escalated_to_id = ?
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
");
// Note: The JOIN condition on escalations ensures we only link to escalations meant for *this* dean.

if ($stmt) {
    $stmt->bind_param("ii", $dean_id, $dean_id); // Bind dean_id twice
    $stmt->execute();
    $notifications_result = $stmt->get_result();
    while ($row = $notifications_result->fetch_assoc()) {
        $notifications_data[] = $row;
    }
    $stmt->close();
} else {
    error_log("Error preparing notifications fetch query: " . $db->error);
    $_SESSION['error'] = "Database error fetching notifications.";
}


// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Using the same CSS as dashboard.php / view_resolved.php -->
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
         .nav-link .badge { /* Style for badge inside nav link */
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
            text-align: center; /* Center title */
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%; /* Center pseudo-element */
            transform: translateX(-50%); /* Center pseudo-element */
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        /* Notification Specific Styles */
        .notification-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .notification-item {
            padding: 1rem 1.5rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start; /* Align icon to top */
            gap: 1rem;
            transition: var(--transition);
            background-color: #fff; /* Default background */
        }

        .notification-item:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .notification-item.unread {
             background-color: #eff3ff; /* Slightly different background for unread */
             border-left: 5px solid var(--primary);
        }

        .notification-icon {
             font-size: 1.3rem;
             color: var(--primary);
             padding-top: 0.2rem; /* Align icon better with text */
             flex-shrink: 0; /* Prevent icon from shrinking */
        }

        .notification-content {
            flex-grow: 1;
        }

        .notification-description {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
            color: var(--dark);
        }

        .notification-description strong {
            color: var(--primary-dark);
        }

        .notification-action a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-block;
            margin-top: 0.3rem;
            padding: 3px 8px;
            border: 1px solid var(--primary-light);
            border-radius: 5px;
            background-color: var(--primary-light);
            color: white;
            transition: var(--transition);
        }

        .notification-action a:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            text-decoration: none;
        }

        .notification-time {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.5rem;
            display: block; /* Make time appear below */
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
            <?php if ($dean): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dean['fname'] . ' ' . $dean['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dean['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>College Dean</h4>
                    <p>Role: College Dean</p>
                </div>
            </div>
            <?php endif; ?>
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
            <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                 <?php /* Badge logic removed here as count is 0 after page load */ ?>
                 <?php // if ($notification_count > 0): ?>
                    <!-- <span class="badge badge-danger"><?php echo $notification_count; ?></span> -->
                 <?php // endif; ?>
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
                <span>DMU Complaint System - College Dean</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php"> <!-- No active class here -->
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Content Container -->
        <div class="content-container">
            <h2>Notifications</h2>

             <!-- Alerts (Optional: If you set session messages) -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Notification List -->
            <ul class="notification-list">
                <?php if (!empty($notifications_data)): ?>
                    <?php foreach ($notifications_data as $notification): ?>
                        <?php
                            // Determine if the notification was 'unread' before this page load
                            // Since we update immediately, we can check the original 'is_read' value fetched
                            $was_unread = $notification['is_read'] == 0; // Check original status before update
                        ?>
                        <li class="notification-item <?php // echo $was_unread ? 'unread' : ''; // Optional: style differently if needed ?>">
                            <i class="notification-icon fas fa-bell"></i>
                            <div class="notification-content">
                                <p class="notification-description">
                                    <?php echo htmlspecialchars($notification['description']); ?>
                                    <?php if ($notification['complaint_id'] && $notification['complaint_title']): ?>
                                        - <strong>Complaint: <?php echo htmlspecialchars($notification['complaint_title']); ?></strong>
                                    <?php endif; ?>
                                </p>
                                <?php
                                    // Check if there's a related escalation ID and if its status is 'pending'
                                    $can_decide = $notification['escalation_id'] && $notification['escalation_status'] === 'pending';
                                ?>
                                <?php if ($notification['complaint_id'] && $notification['escalation_id'] && $can_decide): ?>
                                     <div class="notification-action">
                                        <a href="decide_complaint.php?complaint_id=<?php echo $notification['complaint_id']; ?>&escalation_id=<?php echo $notification['escalation_id']; ?>">
                                            <i class="fas fa-gavel"></i> View & Decide
                                        </a>
                                     </div>
                                <?php elseif ($notification['complaint_id']): ?>
                                    <!-- Maybe provide a link to view the complaint details even if not decidable -->
                                    <div class="notification-action">
                                        <a href="view_complaint.php?complaint_id=<?php echo $notification['complaint_id']; ?>"> <!-- Adjust link if needed -->
                                             <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <span class="notification-time"><?php echo date('M j, Y H:i', strtotime($notification['created_at'])); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="notification-item">
                        <i class="notification-icon fas fa-info-circle"></i>
                        <p>No notifications found.</p>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Footer -->
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

    <!-- Optional JavaScript -->
    <script>
        // Optional JS if needed, e.g., for dynamic interactions
    </script>
</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>