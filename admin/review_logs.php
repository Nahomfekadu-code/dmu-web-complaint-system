<?php
// Enforce secure session settings (must be before session_start)
ini_set('session.cookie_secure', '0'); // Set to '0' for local testing; '1' for live server with HTTPS
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

// Start the session
session_start();

// Include database connection
require_once '../db_connect.php'; // Ensure this path points to dmu_complaints/db_connect.php

// Role check: Ensure the user is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) != 'admin') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$admin = null;

// Fetch admin details
$sql_admin_fetch = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_admin_fetch = $db->prepare($sql_admin_fetch);
if ($stmt_admin_fetch) {
    $stmt_admin_fetch->bind_param("i", $user_id);
    $stmt_admin_fetch->execute();
    $result_admin = $stmt_admin_fetch->get_result();
    if ($result_admin->num_rows > 0) {
        $admin = $result_admin->fetch_assoc();
    } else {
        $_SESSION['error'] = "Admin profile not found. Please log in again.";
        unset($_SESSION['user_id'], $_SESSION['role']);
        header("Location: ../login.php");
        exit;
    }
    $stmt_admin_fetch->close();
} else {
    $_SESSION['error'] = "Error fetching admin details.";
    error_log("Failed to prepare admin fetch statement: " . $db->error);
    header("Location: ../login.php");
    exit;
}

// Fetch logs
// Consider adding pagination and filtering for large log tables
$logs = [];
$fetch_error = null; // Variable to store fetch error

$sql_logs = "
    SELECT cl.id, cl.user_id, cl.action, cl.details, cl.created_at, u.username
    FROM complaint_logs cl
    LEFT JOIN users u ON cl.user_id = u.id /* LEFT JOIN in case user was deleted */
    ORDER BY cl.created_at DESC
    LIMIT 100"; // Limit results for performance, add pagination later

$logs_result = $db->query($sql_logs);

if ($logs_result) {
    $logs = $logs_result->fetch_all(MYSQLI_ASSOC);
    $logs_result->free();
} else {
    $fetch_error = "Error fetching complaint logs."; // Set error message if query fails
    error_log("Failed to fetch complaint logs: " . $db->error);
    // Keep $logs as empty array
}

// Get current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Handle session messages (get them AFTER potential redirects and DB operations)
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? $fetch_error; // Use fetch error if no other error set
$warning = $_SESSION['warning'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning']); // Clear session messages
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Logs | DMU Complaint System</title>
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Consolidated Stylesheet -->
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <!-- Vertical Navigation (Include) -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <?php if ($admin): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($admin['fname'] . ' ' . $admin['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($admin['role'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="nav-menu">
             <!-- Navigation Links -->
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Overview</span>
            </a>
            <h3>User Management</h3>
            <a href="add_user.php" class="nav-link <?php echo $current_page == 'add_user.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-plus fa-fw"></i>
                <span>Add User</span>
            </a>
            <a href="manage_users.php" class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users-cog fa-fw"></i>
                <span>Manage Users</span>
            </a>
            <h3>Content Moderation</h3>
            <a href="manage_abusive_words.php" class="nav-link <?php echo $current_page == 'manage_abusive_words.php' ? 'active' : ''; ?>">
                <i class="fas fa-filter fa-fw"></i>
                <span>Manage Abusive Words</span>
            </a>
            <a href="review_logs.php" class="nav-link <?php echo $current_page == 'review_logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-history fa-fw"></i>
                <span>Review Logs</span>
            </a>
            <h3>System Management</h3>
            <a href="backup_restore.php" class="nav-link <?php echo $current_page == 'backup_restore.php' ? 'active' : ''; ?>">
                <i class="fas fa-database fa-fw"></i>
                <span>Backup/Restore</span>
            </a>
            <h3>Account</h3>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation (Include) -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Admin Panel</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Contact</a>
                <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About</a>
            </div>
        </nav>

        <!-- Review Logs Container -->
        <div class="container">
            <h2><i class="fas fa-history" style="margin-right: 10px;"></i> Review Complaint Logs</h2>

            <!-- Display Session Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): // Display error (from session or fetch) ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($warning): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($warning); ?></span>
                </div>
            <?php endif; ?>

            <!-- Complaint Submission Logs Table -->
            <div class="card"> <!-- Wrap table in card for consistent styling -->
                 <h3>Recent Logs (Last 100 Entries)</h3>
                 <div class="table-responsive">
                    <table class="logs-table"> <!-- Add specific class -->
                        <thead>
                            <tr>
                                <th>Log ID</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($logs)): ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td data-label="Log ID"><?php echo htmlspecialchars($log['id']); ?></td>
                                        <td data-label="User">
                                            <?php echo htmlspecialchars($log['username'] ?? 'N/A'); // Handle potentially deleted users ?>
                                            (ID: <?php echo htmlspecialchars($log['user_id']); ?>)
                                        </td>
                                        <td data-label="Action"><?php echo htmlspecialchars($log['action']); ?></td>
                                        <td data-label="Details" class="details-cell"><?php echo nl2br(htmlspecialchars($log['details'])); // Use nl2br for multi-line details ?></td>
                                        <td data-label="Timestamp"><?php echo htmlspecialchars(date('M j, Y, g:i:s a', strtotime($log['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php elseif (!$error): // Show "No logs" only if fetch didn't error ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px; color: var(--gray);">No complaint logs found.</td>
                                </tr>
                             <?php endif; ?>
                             <?php if ($error && empty($logs)): // Show specific message if fetch failed ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 20px; color: var(--danger);">Could not load logs due to an error.</td>
                                </tr>
                             <?php endif; ?>
                        </tbody>
                    </table>
                 </div><!-- end table-responsive -->
                 <?php if (count($logs) >= 100): ?>
                    <p style="text-align: center; margin-top: 15px; font-size: 0.9em; color: var(--gray);">Displaying the last 100 log entries. Consider implementing pagination or filters for older logs.</p>
                 <?php endif; ?>
            </div> <!-- end card -->
        </div> <!-- end container -->

        <!-- Footer (Include) -->
        <footer>
            <div class="footer-content">
                <div class="group-name">Group 5</div>
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

    </div> <!-- end main-content -->

    <!-- Consolidated Script -->
    <script src="scripts.js" defer></script>
</body>
</html>
<?php
// Close the database connection if it's still open
if (isset($db) && $db instanceof mysqli && !empty($db->thread_id)) {
    $db->close();
}
?>