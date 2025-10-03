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
        $_SESSION['error'] = "Admin profile not found. Please log in again."; // Clearer message
        unset($_SESSION['user_id'], $_SESSION['role']);
        header("Location: ../login.php");
        exit;
    }
    $stmt_admin_fetch->close();
} else {
    $_SESSION['error'] = "Error fetching admin details.";
    error_log("Failed to prepare admin fetch statement: " . $db->error);
    header("Location: ../login.php"); // Redirect on error
    exit;
}

// Fetch system statistics
// Use default values in case queries fail
$total_users = 0;
$total_complaints = 0;
$unresolved_complaints = 0;
$active_handlers = 0;

// Total users
$sql_total_users = "SELECT COUNT(*) as total_users FROM users";
if ($result_total_users = $db->query($sql_total_users)) {
    $row = $result_total_users->fetch_assoc();
    $total_users = $row['total_users'] ?? 0;
    $result_total_users->free();
} else {
    error_log("Failed to fetch total users: " . $db->error);
    // Set an error message if this is critical, otherwise just log
    // $_SESSION['warning'] = "Could not fetch total user count.";
}

// Total complaints
$sql_total_complaints = "SELECT COUNT(*) as total_complaints FROM complaints";
if ($result_total_complaints = $db->query($sql_total_complaints)) {
    $row = $result_total_complaints->fetch_assoc();
    $total_complaints = $row['total_complaints'] ?? 0;
    $result_total_complaints->free();
} else {
    error_log("Failed to fetch total complaints: " . $db->error);
    // $_SESSION['warning'] = "Could not fetch total complaint count.";
}

// Unresolved complaints (status not 'resolved')
$sql_unresolved = "SELECT COUNT(*) as unresolved_complaints FROM complaints WHERE status != 'resolved'";
if ($result_unresolved = $db->query($sql_unresolved)) {
    $row = $result_unresolved->fetch_assoc();
    $unresolved_complaints = $row['unresolved_complaints'] ?? 0;
    $result_unresolved->free();
} else {
    error_log("Failed to fetch unresolved complaints: " . $db->error);
    // $_SESSION['warning'] = "Could not fetch unresolved complaint count.";
}

// Active handlers
$sql_handlers = "SELECT COUNT(*) as active_handlers FROM users WHERE role = 'handler' AND status = 'active'";
if ($result_handlers = $db->query($sql_handlers)) {
    $row = $result_handlers->fetch_assoc();
    $active_handlers = $row['active_handlers'] ?? 0;
    $result_handlers->free();
} else {
    error_log("Failed to fetch active handlers: " . $db->error);
    // $_SESSION['warning'] = "Could not fetch active handler count.";
}

// Get current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Handle success/error messages from session
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
$warning = $_SESSION['warning'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | DMU Complaint System</title>
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

        <!-- Dashboard Container -->
        <div class="container">
            <h2>Welcome, <?php echo htmlspecialchars($admin['fname'] . ' ' . $admin['lname']); ?>!</h2>
            <p>Your Role: <?php echo htmlspecialchars(ucfirst($admin['role'])); ?></p>

            <!-- Display Session Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
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

            <!-- System Statistics -->
            <div class="stats">
                <h3>System Overview</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <i class="fas fa-users"></i>
                        <h4>Total Users</h4>
                        <p><?php echo $total_users; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-file-alt"></i>
                        <h4>Total Complaints</h4>
                        <p><?php echo $total_complaints; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-exclamation-circle"></i>
                        <h4>Unresolved Complaints</h4>
                        <p><?php echo $unresolved_complaints; ?></p>
                    </div>
                    <div class="stat-card">
                        <i class="fas fa-user-shield"></i>
                        <h4>Active Handlers</h4>
                        <p><?php echo $active_handlers; ?></p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="actions-grid">
                    <a href="add_user.php" class="action-card">
                        <i class="fas fa-user-plus"></i>
                        <span>Add New User</span>
                    </a>
                    <a href="manage_users.php" class="action-card">
                        <i class="fas fa-users-cog"></i> <!-- Changed icon for consistency -->
                        <span>Manage Users</span>
                    </a>
                     <a href="review_logs.php" class="action-card">
                        <i class="fas fa-history"></i>
                        <span>Review Logs</span>
                    </a>
                    <a href="backup_restore.php" class="action-card">
                        <i class="fas fa-database"></i>
                        <span>Backup/Restore</span>
                    </a>
                     <!-- Add more relevant quick actions if needed -->
                </div>
            </div>
        </div> <!-- End Container -->

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
    </div> <!-- End Main Content -->

    <!-- Consolidated Script -->
    <script src="scripts.js" defer></script>
</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>