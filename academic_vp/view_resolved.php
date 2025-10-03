<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'academic_vp'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'academic_vp') {
    header("Location: ../unauthorized.php");
    exit;
}

$vp_id = $_SESSION['user_id'];
$vp = null; // Initialize $vp

// Fetch Academic VP details (Needed for the navigation bar)
$sql_vp = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_vp = $db->prepare($sql_vp);
if ($stmt_vp) {
    $stmt_vp->bind_param("i", $vp_id);
    $stmt_vp->execute();
    $result_vp = $stmt_vp->get_result();
    if ($result_vp->num_rows > 0) {
        $vp = $result_vp->fetch_assoc();
    } else {
        // Handle case where VP details might not be found, though unlikely if logged in
        error_log("Academic Vice President details not found for ID: " . $vp_id);
        // You might want to redirect or show a generic message
    }
    $stmt_vp->close();
} else {
    error_log("Error preparing academic vp query: " . $db->error);
    // Handle database error if needed
}


// Fetch notification count
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$notif_stmt->bind_param("i", $vp_id);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result()->fetch_assoc();
$notification_count = $notif_result['count'];
$notif_stmt->close();

// Fetch resolved complaints where the Academic VP was involved
$stmt = $db->prepare("
    SELECT c.id, c.title, c.category, c.status,
           u.fname as submitter_fname, u.lname as submitter_lname,
           e.resolution_details, e.resolved_at, e.action_type
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'academic_vp'
    AND e.escalated_to_id = ?
    AND e.status = 'resolved'
    ORDER BY e.resolved_at DESC
");
if ($stmt) {
    $stmt->bind_param("i", $vp_id);
    $stmt->execute();
    $resolved_complaints = $stmt->get_result();
    $stmt->close();
} else {
    error_log("Error preparing resolved complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching resolved complaints.";
    $resolved_complaints = null; // Ensure it's defined even on error
}


// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Resolved Complaints | DMU Complaint System</title>
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
            display: flex; /* Changed */
        }

        /* Vertical Navigation (Copied from dashboard.php) */
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
            display: flex; /* Added for flex column layout */
            flex-direction: column; /* Added for flex column layout */
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
            flex-grow: 1; /* Added to push logout down */
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
        /* Added rule for notification badge */
        .nav-link .badge-danger {
            margin-left: auto; /* Pushes badge to the right */
            background-color: var(--danger);
            color: white;
            font-size: 0.8em;
            padding: 0.2em 0.5em;
            border-radius: 0.75rem; /* Make it more pill-shaped */
        }

        .nav-logout { /* Added class for logout link positioning */
             margin-top: auto; /* Pushes logout to bottom */
             padding: 0 10px 10px; /* Added padding */
        }


        /* Main Content (Copied from dashboard.php) */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            /* Removed margin-left from old structure */
        }

        /* Horizontal Navigation (Copied from dashboard.php) */
        .horizontal-nav {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0; /* Prevent shrinking */
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
            display: inline-flex; /* Align icon and text */
            align-items: center; /* Align icon and text */
            gap: 5px; /* Space between icon and text */
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary);
            color: white;
        }

        /* Alerts (Copied from dashboard.php) */
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

        /* Content Container (Copied from dashboard.php) */
        .content-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1; /* Ensure it fills available space */
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
            text-align: center; /* Centered like dashboard */
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%; /* Centered like dashboard */
            transform: translateX(-50%); /* Centered like dashboard */
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        h3 { /* Style from dashboard */
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
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
        }

        .badge-assigned { background: #007bff; color: white; }
        .badge-escalated { background: #dc3545; color: white; }
        /* Added .badge-success for resolved status */
        .badge-success { background-color: var(--success); color: white; }

        /* Footer (Copied from dashboard.php) */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto; /* Pushes footer to bottom */
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            width: 100%;
            flex-shrink: 0; /* Prevent shrinking */
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
    <!-- Vertical Navigation (Copied from dashboard.php) -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <?php if ($vp): // Check if $vp details were fetched ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($vp['fname'] . ' ' . $vp['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $vp['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>Academic VP</h4>
                    <p>Role: Academic Vice President</p>
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
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
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
            <!-- Moved Logout to its own div for positioning -->
        </div>
         <div class="nav-logout">
             <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
         </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation (Copied from dashboard.php) -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Academic Vice President</span>
            </div>
            <div class="horizontal-menu">
                 <a href="dashboard.php">
                    <i class="fas fa-home"></i> Home
                </a>
                 <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Specific Content for View Resolved Complaints -->
        <div class="content-container">
            <h2>Resolved Complaints</h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Resolved Complaints Table -->
            <?php if ($resolved_complaints && $resolved_complaints->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Action Type</th>
                            <th>Submitted By</th>
                            <th>Resolution Details</th>
                            <th>Resolved At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($complaint = $resolved_complaints->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $complaint['id']; ?></td>
                                <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></td>
                                <td>
                                    <span class="badge badge-success"> <!-- Use success badge for resolved -->
                                        <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $complaint['action_type'] === 'assignment' ? 'assigned' : 'escalated'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($complaint['action_type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></td>
                                <td><?php echo nl2br(htmlspecialchars($complaint['resolution_details'] ?? 'No details provided.')); // Use nl2br for line breaks ?></td>
                                <td><?php echo $complaint['resolved_at'] ? date('M j, Y H:i', strtotime($complaint['resolved_at'])) : 'N/A'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No resolved complaints found where you were involved.</p>
            <?php endif; ?>
        </div>

        <!-- Footer (Copied from dashboard.php) -->
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
        // Auto-hide alerts (Copied from dashboard.php)
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 5000); // 5 seconds
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