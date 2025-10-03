<?php
session_start();
require_once '../db_connect.php'; // Ensure this path is correct

// Check if the user is logged in and has the 'user' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    $_SESSION['error'] = "You must be logged in as a user to view notifications.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details from database if not in session (for sidebar)
if (!isset($_SESSION['fname']) || !isset($_SESSION['lname'])) {
    $sql_user = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_user = $db->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($result_user->num_rows > 0) {
            $user_details = $result_user->fetch_assoc();
            $_SESSION['fname'] = $user_details['fname'];
            $_SESSION['lname'] = $user_details['lname'];
        } else {
            // Handle case where user details are missing but session exists
            $_SESSION['fname'] = 'Unknown';
            $_SESSION['lname'] = 'User';
        }
        $stmt_user->close();
    } else {
        // Handle DB error during user fetch
        error_log("View Notifications: Failed to prepare user fetch statement - " . $db->error);
        $_SESSION['fname'] = 'DB';
        $_SESSION['lname'] = 'Error';
    }
}

// --- Fetch Notifications ---
$notifications = null; // Initialize notifications variable
$sql_notifications = "SELECT n.id, n.description, n.created_at, n.is_read, c.title as complaint_title, n.complaint_id
                      FROM notifications n
                      LEFT JOIN complaints c ON n.complaint_id = c.id  -- Use LEFT JOIN in case complaint is deleted or notification is general
                      WHERE n.user_id = ?
                      ORDER BY n.created_at DESC";

$stmt = $db->prepare($sql_notifications);

if ($stmt === false) {
    // Handle preparation error
    error_log("View Notifications: Failed to prepare notifications statement - " . $db->error);
    $_SESSION['error'] = "Error loading notifications. Please try again later.";
    // Optionally redirect or just show an error message in the HTML
} else {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $notifications = $stmt->get_result();
    } else {
        // Handle execution error
        error_log("View Notifications: Failed to execute notifications statement - " . $stmt->error);
        $_SESSION['error'] = "Error fetching notifications. Please try again later.";
    }
    // Statement will be closed after the loop in the HTML part
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
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
            flex-shrink: 0; /* Prevent icon shrinking */
        }
         .nav-link span {
            flex-grow: 1; /* Allow text to take space */
        }


        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
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
            flex-wrap: wrap;
            gap: 10px;
        }
         .horizontal-nav .logo {
             font-weight: 600;
             font-size: 1.1rem;
             color: var(--primary-dark);
         }

        .horizontal-menu {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            gap: 5px;
        }

        .horizontal-menu a:hover {
            background: var(--light-gray);
            color: var(--primary);
        }

        .horizontal-menu a.active {
            background: var(--primary);
            color: white;
        }

        /* Content Container */
        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1; /* Allow container to fill space */
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.8rem;
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

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: 0 0 0 1px var(--light-gray);
            margin-top: 1.5rem; /* Space after title */
        }

        table {
            width: 100%;
            border-collapse: separate; /* Use separate for radius */
            border-spacing: 0;
            min-width: 600px;
        }

        thead {
            /* position: sticky; /* Optional: make header sticky */
            /* top: 0; */
            /* z-index: 10; */
        }

        th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
            padding: 1rem 1.2rem;
            text-align: left;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        th:first-child { border-top-left-radius: var(--radius); }
        th:last-child { border-top-right-radius: var(--radius); }

        td {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
            font-size: 0.95rem; /* Slightly larger text */
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:nth-child(even) td {
            background-color: #fdfdff;
        }

        tr:hover td {
            background-color: rgba(72, 149, 239, 0.05);
        }

        .notification-item {
            transition: background-color 0.2s ease; /* Smooth hover */
        }
        .notification-item.unread td {
            font-weight: 500; /* Make unread notifications slightly bolder */
             /* Add a subtle visual indicator for unread, e.g., a left border */
             border-left: 3px solid var(--primary-light);
        }
        .notification-item.unread td:first-child {
             padding-left: calc(1.2rem - 3px); /* Adjust padding for border */
        }


        .notification-title a {
            font-weight: 500;
            color: var(--primary-dark);
            text-decoration: none;
            transition: color 0.2s ease;
        }
         .notification-title a:hover {
            color: var(--primary);
             text-decoration: underline;
         }
         .notification-title .general-notice {
            font-style: italic;
            color: var(--gray);
         }


        .notification-date {
            color: var(--gray);
            font-size: 0.85rem; /* Smaller date */
            white-space: nowrap;
        }

        .no-notifications {
            text-align: center;
            padding: 3rem 1rem;
            background-color: #f8f9fa;
            border: 1px dashed var(--light-gray);
            border-radius: var(--radius-lg);
            margin-top: 2rem;
            color: var(--gray);
        }
        .no-notifications i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }
         .no-notifications p {
             font-size: 1.1rem;
             font-weight: 500;
             color: var(--dark);
         }

         /* Alerts (for potential DB errors) */
        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
             border-left-width: 5px;
             border-left-style: solid;
             animation: fadeIn 0.5s ease-out;
        }
         .alert i { font-size: 1.2rem; }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger);
            color: #a51c2c;
        }


        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto; /* Push footer down */
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            flex-shrink: 0; /* Prevent footer shrinking */
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

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav { width: 220px; }
            .user-info h4 { max-width: 140px; }
             .content-container { padding: 2rem; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); }
            .main-content { padding: 15px; }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
        }

        @media (max-width: 576px) {
            .vertical-nav { display: none; }
            .main-content { padding: 10px; }
            .horizontal-nav .logo { display: none; }
            .horizontal-menu a { padding: 6px 10px; font-size: 0.9rem; }
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            table { min-width: 100%; }
            th, td { padding: 0.75rem; font-size: 0.9rem; }
            .notification-date { font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <!-- Vertical Navigation -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <!-- Make sure this path is correct -->
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo isset($_SESSION['fname'], $_SESSION['lname']) ? htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname']) : 'User'; ?></h4>
                    <p><?php echo isset($_SESSION['role']) ? htmlspecialchars(ucfirst($_SESSION['role'])) : 'Role'; ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <!-- Navigation Links using $current_page -->
             <h3>Dashboard</h3>
             <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                 <i class="fas fa-tachometer-alt fa-fw"></i>
                 <span>Overview</span>
             </a>

             <h3>Complaints</h3>
             <a href="submit_complaint.php" class="nav-link <?php echo $current_page == 'submit_complaint.php' ? 'active' : ''; ?>">
                 <i class="fas fa-plus-circle fa-fw"></i>
                 <span>Submit Complaint</span>
             </a>
             <a href="modify_complaint.php" class="nav-link <?php echo $current_page == 'modify_complaint.php' ? 'active' : ''; ?>">
                 <i class="fas fa-edit fa-fw"></i>
                 <span>Modify Complaint</span>
             </a>
             <a href="check_complaint_status.php" class="nav-link <?php echo $current_page == 'check_complaint_status.php' ? 'active' : ''; ?>">
                 <i class="fas fa-search fa-fw"></i>
                 <span>Check Status</span>
             </a>

             <h3>Communication</h3>
             <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                 <i class="fas fa-bell fa-fw"></i>
                 <span>Notifications</span>
             </a>
             <a href="send_feedback.php" class="nav-link <?php echo $current_page == 'send_feedback.php' ? 'active' : ''; ?>">
                 <i class="fas fa-comment-alt fa-fw"></i>
                 <span>Send Feedback</span>
             </a>
             <a href="view_decision.php" class="nav-link <?php echo $current_page == 'view_decision.php' ? 'active' : ''; ?>">
                 <i class="fas fa-gavel fa-fw"></i>
                 <span>View Decisions</span>
             </a>

             <h3>Account</h3>
             <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                 <i class="fas fa-user-edit fa-fw"></i>
                 <span>Edit Profile</span>
             </a>
             <a href="view_notices.php" class="nav-link <?php echo $current_page == 'view_notices.php' ? 'active' : ''; ?>">
                 <i class="fas fa-clipboard-list fa-fw"></i>
                 <span>View Notices</span>
             </a>
              <a href="../logout.php" class="nav-link">
                  <i class="fas fa-sign-out-alt fa-fw"></i>
                  <span>Logout</span>
              </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                 <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                 <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Contact</a>
                 <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About</a>
                 <!-- <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a> -->
            </div>
        </nav>

        <!-- Notifications Content -->
        <div class="content-container">
            <h2>Your Notifications</h2>

             <!-- Display Errors (e.g., DB connection issues) -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Check if notifications were fetched successfully -->
            <?php if ($notifications === null && !isset($_SESSION['error'])): ?>
                 <div class="alert alert-danger">
                      <i class="fas fa-exclamation-triangle"></i>
                      Could not load notifications due to an unexpected error.
                 </div>
            <?php elseif ($notifications !== null && $notifications->num_rows == 0): ?>
                <div class="no-notifications">
                    <i class="far fa-bell-slash"></i>
                    <p>You have no notifications at this time.</p>
                </div>
            <?php elseif ($notifications !== null): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject / Complaint</th>
                                <th>Notification</th>
                                <th>Date Received</th>
                                <!-- Optional: Add Action like 'Mark as Read' or 'View Complaint' -->
                                <!-- <th>Action</th> -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($notification = $notifications->fetch_assoc()):
                                $is_unread = $notification['is_read'] == 0;
                                $row_class = $is_unread ? 'notification-item unread' : 'notification-item';

                                // Format the date
                                $date_received = 'N/A';
                                try {
                                    $date = new DateTime($notification['created_at']);
                                    // Example format: Jan 15, 2024 09:30 AM
                                    $date_received = $date->format('M j, Y g:i A');
                                } catch (Exception $e) {
                                    error_log("View Notifications: Invalid date format for notification ID " . $notification['id']);
                                }
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td class="notification-title">
                                        <?php if ($notification['complaint_id'] && $notification['complaint_title']): ?>
                                            <!-- Link to the specific complaint details page -->
                                            <a href="view_complaint.php?complaint_id=<?php echo $notification['complaint_id']; ?>" title="View Complaint Details">
                                                <?php echo htmlspecialchars($notification['complaint_title']); ?>
                                                 (ID: #<?php echo $notification['complaint_id']; ?>)
                                            </a>
                                        <?php else: ?>
                                            <!-- Handle general notifications or cases where complaint title is missing -->
                                             <span class="general-notice">General Notice</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($notification['description']); ?></td>
                                    <td class="notification-date"><?php echo $date_received; ?></td>
                                    <!-- Optional Action Column -->
                                    <!--
                                    <td>
                                        <?php //if ($is_unread): ?>
                                            <a href="mark_notification_read.php?id=<?php //echo $notification['id']; ?>" class="btn btn-sm btn-outline-primary" title="Mark as Read"><i class="fas fa-check"></i></a>
                                        <?php //endif; ?>
                                        <?php //if ($notification['complaint_id']): ?>
                                             <a href="view_complaint.php?complaint_id=<?php //echo $notification['complaint_id']; ?>" class="btn btn-sm btn-info" title="View Complaint"><i class="fas fa-eye"></i></a>
                                         <?php //endif; ?>
                                    </td>
                                    -->
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php
                   // Free the result set
                   $notifications->free();
                ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div> <!-- Replace -->
                <div class="social-links">
                     <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                     <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                     <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                     <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint Management System. All rights reserved.
                </div>
            </div>
        </footer>
    </div> <!-- End Main Content -->

    <script>
        // Optional: Add animation to notification items if table exists
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr.notification-item');
            if(rows.length > 0) {
                rows.forEach((row, index) => {
                    row.style.animationDelay = `${index * 0.05}s`;
                    row.style.animation = 'fadeIn 0.4s ease-out forwards';
                    row.style.opacity = '0'; // Start hidden for animation
                });
            }

            // Optional: Add interaction, e.g., mark as read on click (requires AJAX/backend)
            // rows.forEach(row => {
            //     row.addEventListener('click', function() {
            //         if (this.classList.contains('unread')) {
            //             // Example: Send request to mark as read
            //             // fetch('mark_notification_read.php?id=' + this.dataset.notificationId)
            //             // .then(...)
            //             // .then(() => this.classList.remove('unread'));
            //             console.log('Clicked unread notification'); // Placeholder
            //         }
            //         // Optionally redirect to complaint details if linked
            //         const link = this.querySelector('.notification-title a');
            //         if (link) {
            //             window.location.href = link.href;
            //         }
            //     });
            // });
        });
    </script>
</body>
</html>
<?php
// Close statement if it was prepared successfully
if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
// Close the database connection if it's open
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>