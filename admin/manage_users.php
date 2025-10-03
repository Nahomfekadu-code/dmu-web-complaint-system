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

$current_user_id = $_SESSION['user_id'];
$admin = null;

// Fetch admin details
$sql_admin = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_admin = $db->prepare($sql_admin);
if ($stmt_admin) {
    $stmt_admin->bind_param("i", $current_user_id);
    $stmt_admin->execute();
    $result_admin = $stmt_admin->get_result();
    if ($result_admin->num_rows > 0) {
        $admin = $result_admin->fetch_assoc();
    } else {
        $_SESSION['error'] = "Admin profile not found. Please log in again.";
        unset($_SESSION['user_id'], $_SESSION['role']);
        header("Location: ../login.php");
        exit;
    }
    $stmt_admin->close();
} else {
    $_SESSION['error'] = "Error fetching admin details.";
    error_log("Failed to prepare admin fetch statement: " . $db->error);
    header("Location: ../login.php");
    exit;
}

// --- Form Handling ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Consider adding CSRF token validation here for all POST actions

    if (!isset($_POST['user_id']) || !filter_var($_POST['user_id'], FILTER_VALIDATE_INT)) {
        $_SESSION['error'] = "Invalid user ID specified.";
        header("Location: manage_users.php" . (!empty($_SERVER['QUERY_STRING']) ? "?" . $_SERVER['QUERY_STRING'] : ""));
        exit;
    }
    $user_id_to_modify = (int)$_POST['user_id'];

    // Prevent self-modification
    if ($user_id_to_modify === $current_user_id) {
        $_SESSION['error'] = "You cannot modify your own account status or details here.";
        header("Location: manage_users.php" . (!empty($_SERVER['QUERY_STRING']) ? "?" . $_SERVER['QUERY_STRING'] : ""));
        exit;
    }

    // Block/Unblock Action
    if (isset($_POST['block'])) {
        $current_status = $_POST['current_status'] ?? '';
        $new_status = ($current_status == 'active' || $current_status == 'suspended') ? 'blocked' : 'active';
        // Clear suspension if unblocking, otherwise keep it if blocking (user might be re-activated later)
        $suspended_until_update = ($new_status === 'active') ? ", suspended_until = NULL" : "";
        $sql_block = "UPDATE users SET status = ? {$suspended_until_update} WHERE id = ?";
        $stmt_block = $db->prepare($sql_block);
        if ($stmt_block) {
            $stmt_block->bind_param("si", $new_status, $user_id_to_modify);
            if ($stmt_block->execute()) {
                $_SESSION['success'] = "User status updated to '{$new_status}' successfully.";
            } else {
                $_SESSION['error'] = "Error updating user status: " . $db->error;
                error_log("Block/Unblock Error (User ID: $user_id_to_modify): " . $db->error);
            }
            $stmt_block->close();
        } else {
             $_SESSION['error'] = "Failed to prepare status update statement.";
             error_log("Prepare Block/Unblock Error: " . $db->error);
        }

    // Delete Action
    } elseif (isset($_POST['delete'])) {
        // Check for associated records (Example: complaints) before deleting
        $sql_check_complaints = "SELECT COUNT(*) as count FROM complaints WHERE user_id = ?";
        $stmt_check_complaints = $db->prepare($sql_check_complaints);
        $can_delete = true;
        if($stmt_check_complaints) {
            $stmt_check_complaints->bind_param("i", $user_id_to_modify);
            $stmt_check_complaints->execute();
            $result_check = $stmt_check_complaints->get_result();
            $complaint_count = $result_check->fetch_assoc()['count'];
            if($complaint_count > 0) {
                $_SESSION['error'] = "Cannot delete user (ID: $user_id_to_modify). They have associated complaints ($complaint_count). Consider blocking instead.";
                $can_delete = false;
            }
            $stmt_check_complaints->close();
        } // Add checks for other related tables if necessary

        if ($can_delete) {
            $sql_delete = "DELETE FROM users WHERE id = ?";
            $stmt_delete = $db->prepare($sql_delete);
             if ($stmt_delete) {
                $stmt_delete->bind_param("i", $user_id_to_modify);
                if ($stmt_delete->execute()) {
                    if($stmt_delete->affected_rows > 0) {
                         $_SESSION['success'] = "User (ID: $user_id_to_modify) deleted successfully.";
                    } else {
                         $_SESSION['warning'] = "User (ID: $user_id_to_modify) not found or already deleted.";
                    }
                } else {
                    // Check for other foreign key constraints if needed
                    if ($db->errno == 1451) {
                        $_SESSION['error'] = "Cannot delete user (ID: $user_id_to_modify). They have other associated records. Consider blocking instead.";
                    } else {
                        $_SESSION['error'] = "Error deleting user: " . $db->error;
                         error_log("Delete Error (User ID: $user_id_to_modify): " . $db->error);
                    }
                }
                $stmt_delete->close();
             } else {
                  $_SESSION['error'] = "Failed to prepare delete statement.";
                  error_log("Prepare Delete Error: " . $db->error);
             }
        }

    // Update Suspension Action
    } elseif (isset($_POST['update_suspension'])) {
        $adjust_hours = filter_input(INPUT_POST, 'suspension_hours', FILTER_VALIDATE_INT);
        $adjust_minutes = filter_input(INPUT_POST, 'suspension_minutes', FILTER_VALIDATE_INT);

        // Check if values are valid integers
        if ($adjust_hours === false || $adjust_minutes === false) {
             $_SESSION['error'] = "Invalid suspension time input provided.";
        } else {
            $total_adjustment_seconds = ($adjust_hours * 3600) + ($adjust_minutes * 60);

            // Fetch current user status and suspension time
            $sql_user = "SELECT status, suspended_until FROM users WHERE id = ?";
            $stmt_user = $db->prepare($sql_user);
            $user = null;
            if($stmt_user) {
                $stmt_user->bind_param("i", $user_id_to_modify);
                $stmt_user->execute();
                $result_user = $stmt_user->get_result();
                $user = $result_user->fetch_assoc();
                $stmt_user->close();
            } else {
                 $_SESSION['error'] = "Failed to fetch user details for suspension update.";
                 error_log("Prepare Suspension User Fetch Error: " . $db->error);
            }


            if ($user) {
                try {
                    $current_time = new DateTime();
                    $is_currently_suspended = ($user['status'] === 'suspended' && $user['suspended_until'] !== null);
                    $new_suspended_until_time = null;
                    $new_status = $user['status']; // Start with current status

                    if ($is_currently_suspended) {
                        $suspended_until = new DateTime($user['suspended_until']);
                        // Check if suspension is still active
                        if ($current_time < $suspended_until) {
                            $interval = $current_time->diff($suspended_until);
                            $remaining_seconds = ($interval->days * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
                            $new_remaining_seconds = $remaining_seconds + $total_adjustment_seconds;

                            if ($new_remaining_seconds <= 0) { // If adjustment clears suspension
                                $new_status = 'active';
                                $new_suspended_until_time = null;
                                $_SESSION['success'] = "Suspension cleared for user ID $user_id_to_modify.";
                            } else { // Adjust remaining time
                                $new_suspended_until_time = (new DateTime())->add(new DateInterval('PT' . $new_remaining_seconds . 'S'));
                                $new_status = 'suspended'; // Ensure status remains suspended
                                $_SESSION['success'] = "Suspension adjusted for user ID $user_id_to_modify.";
                            }
                        } else { // Suspension has expired
                            if ($total_adjustment_seconds > 0) { // Apply new suspension if adjustment is positive
                                $new_suspended_until_time = (new DateTime())->add(new DateInterval('PT' . $total_adjustment_seconds . 'S'));
                                $new_status = 'suspended';
                                $_SESSION['success'] = "New suspension set for user ID $user_id_to_modify (previous expired).";
                            } else { // Clear expired suspension
                                $new_status = 'active';
                                $new_suspended_until_time = null;
                                $_SESSION['success'] = "Expired suspension cleared for user ID $user_id_to_modify.";
                            }
                        }
                    } else { // User is not currently suspended
                        if ($total_adjustment_seconds > 0) { // Apply new suspension if adjustment is positive
                            $new_suspended_until_time = (new DateTime())->add(new DateInterval('PT' . $total_adjustment_seconds . 'S'));
                            $new_status = 'suspended';
                            $_SESSION['success'] = "Suspension set for user ID $user_id_to_modify.";
                        } else { // No action if adjustment is zero or negative for non-suspended user
                             if ($total_adjustment_seconds != 0) {
                                 $_SESSION['warning'] = "Cannot apply negative suspension time to a non-suspended user (ID $user_id_to_modify). Status remains '{$user['status']}'.";
                             }
                             // Keep existing status (active or blocked)
                              $new_status = ($user['status'] === 'blocked') ? 'blocked' : 'active';
                              $new_suspended_until_time = null;
                        }
                    }

                    // Update the database
                    $db_suspended_until = ($new_suspended_until_time instanceof DateTime) ? $new_suspended_until_time->format('Y-m-d H:i:s') : null;
                    $sql_update = "UPDATE users SET status = ?, suspended_until = ? WHERE id = ?";
                    $stmt_update = $db->prepare($sql_update);
                    if ($stmt_update) {
                        $stmt_update->bind_param("ssi", $new_status, $db_suspended_until, $user_id_to_modify);
                        if (!$stmt_update->execute()) {
                            $_SESSION['error'] = "Failed to update user suspension status: " . $db->error;
                             error_log("Suspension Update Error (User ID: $user_id_to_modify): " . $db->error);
                        }
                        $stmt_update->close();
                    } else {
                         $_SESSION['error'] = "Failed to prepare suspension update statement.";
                         error_log("Prepare Suspension Update Error: " . $db->error);
                    }

                } catch (Exception $e) {
                    $_SESSION['error'] = "Error processing suspension time: " . $e->getMessage();
                     error_log("DateTime Error during suspension update for User ID $user_id_to_modify: " . $e->getMessage());
                }
            } else {
                 $_SESSION['error'] = "User not found for suspension update (ID: $user_id_to_modify).";
            }
        } // End check for valid integer input
    }

    // Redirect back to the same page with filters preserved
    header("Location: manage_users.php" . (!empty($_SERVER['QUERY_STRING']) ? "?" . $_SERVER['QUERY_STRING'] : ""));
    exit;
} // End POST handling

// --- Fetching Users with Filters ---
$filter_role = trim($_GET['role'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_search = trim($_GET['search'] ?? '');

// Basic validation for filter values
// Ensure this list contains all roles available in your system
$allowed_roles = ['admin', 'user', 'handler', 'department_head', 'college_dean', 'sims', 'cost_sharing_customer_service', 'libraries_service_directorate', 'academic_vp', 'directorate_officer', 'admin_vp'];
$allowed_statuses = ['active', 'blocked', 'suspended'];
if (!empty($filter_role) && !in_array($filter_role, $allowed_roles)) {
    $filter_role = ''; // Reset if invalid
}
if (!empty($filter_status) && !in_array($filter_status, $allowed_statuses)) {
    $filter_status = ''; // Reset if invalid
}

// Build the SQL query
$sql_users = "SELECT id, username, role, fname, lname, email, status, suspended_until
              FROM users
              WHERE id != ? "; // Exclude current admin

$conditions = [];
$params = [$current_user_id];
$param_types = "i";

if (!empty($filter_role)) {
    $conditions[] = "role = ?";
    $params[] = $filter_role;
    $param_types .= "s";
}
if (!empty($filter_status)) {
    $conditions[] = "status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}
if (!empty($filter_search)) {
    // Search across multiple relevant fields
    $conditions[] = "(username LIKE ? OR email LIKE ? OR fname LIKE ? OR lname LIKE ?)";
    $search_term = "%" . $filter_search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "ssss";
}

if (!empty($conditions)) {
    $sql_users .= " AND " . implode(" AND ", $conditions);
}
$sql_users .= " ORDER BY username ASC"; // Consistent ordering

// Prepare and execute the query
$stmt_users = $db->prepare($sql_users);
$users = [];
if ($stmt_users) {
    // Use spread operator for binding if params exist beyond the first one
    if (count($params) > 1) {
        $stmt_users->bind_param($param_types, ...$params);
    } else {
         $stmt_users->bind_param($param_types, $current_user_id); // Only bind the user ID
    }

    $stmt_users->execute();
    $result_users = $stmt_users->get_result();

    // Process users and check/update expired suspensions
    while ($row = $result_users->fetch_assoc()) {
        $row['remaining_time_display'] = 'N/A'; // Default display
        if ($row['status'] === 'suspended' && $row['suspended_until']) {
            try {
                $current_time = new DateTime();
                $suspended_until = new DateTime($row['suspended_until']);
                if ($current_time < $suspended_until) { // Suspension active
                    $interval = $current_time->diff($suspended_until);
                    $row['remaining_time_display'] = sprintf(
                        '%dd %dh %dm', // Shorter format
                        $interval->days,
                        $interval->h,
                        $interval->i
                    );
                    // $row['remaining_seconds'] = ($interval->days * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s; // If needed later
                } else { // Suspension expired
                    $row['remaining_time_display'] = 'Expired';
                    // Update status in DB (run an UPDATE query here for this user ID)
                    $sql_clear = "UPDATE users SET status = 'active', suspended_until = NULL WHERE id = ? AND status = 'suspended'";
                    $stmt_clear = $db->prepare($sql_clear);
                    if ($stmt_clear) {
                        $stmt_clear->bind_param("i", $row['id']);
                        $stmt_clear->execute();
                        $stmt_clear->close();
                        // Update the row data for immediate display
                        $row['status'] = 'active';
                        $row['suspended_until'] = null;
                        $row['remaining_time_display'] = 'N/A';
                        // Log this auto-update if desired
                        // error_log("Auto-cleared expired suspension for user ID " . $row['id']);
                    } else {
                         error_log("Failed to prepare statement to clear expired suspension for user ID " . $row['id']);
                    }
                }
            } catch (Exception $e) {
                error_log("Error processing suspension date for user ID " . $row['id'] . ": " . $e->getMessage());
                $row['remaining_time_display'] = 'Date Error';
            }
        }
        $users[] = $row; // Add processed row to the list
    }
    $stmt_users->close();
} else {
    $_SESSION['error'] = "Error preparing user list query."; // More specific error
    error_log("Failed to prepare user fetch statement: " . $db->error);
}

// Get current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Handle session messages (get them AFTER potential redirects and processing)
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
    <title>Manage Users | DMU Complaint System</title>
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

        <div class="container">
            <h2><i class="fas fa-users-cog" style="margin-right: 10px;"></i> Manage System Users</h2>

            <!-- Filter Form -->
            <div class="filter-form">
                <form method="get" action="manage_users.php" class="form-inline filter-form-inner"> <!-- Added class for specific targeting -->
                    <div class="filter-group">
                        <label for="filter_role">Role:</label>
                        <select id="filter_role" name="role">
                            <option value="" <?php echo $filter_role === '' ? 'selected' : ''; ?>>All Roles</option>
                            <?php foreach ($allowed_roles as $role_option): // Use allowed roles for consistency ?>
                            <option value="<?php echo $role_option; ?>" <?php echo $filter_role === $role_option ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $role_option)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_status">Status:</label>
                        <select id="filter_status" name="status">
                             <option value="" <?php echo $filter_status === '' ? 'selected' : ''; ?>>All Statuses</option>
                             <?php foreach ($allowed_statuses as $status_option): ?>
                             <option value="<?php echo $status_option; ?>" <?php echo $filter_status === $status_option ? 'selected' : ''; ?>>
                                 <?php echo ucfirst($status_option); ?>
                             </option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter_search">Search:</label>
                        <input type="text" id="filter_search" name="search" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Username, Email, Name">
                    </div>
                     <div class="filter-group" style="flex-grow: 0;"> <!-- Prevent buttons from growing too much -->
                        <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                         <a href="manage_users.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                    </div>
                    <!-- Removed inline onchange -->
                </form>
            </div>

            <!-- Session Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($warning): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i> <span><?php echo htmlspecialchars($warning); ?></span>
                </div>
            <?php endif; ?>

            <!-- User Table -->
            <div class="table-responsive">
                <table class="manage-users-table"> <!-- Added specific class -->
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Suspension Info</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px; color: var(--gray);">No users found matching the criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td data-label="ID"><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td data-label="Username"><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td data-label="Role"><?php echo ucfirst(htmlspecialchars(str_replace('_', ' ', $user['role']))); // Make roles readable ?></td>
                                    <td data-label="Name" class="wrap-text"><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></td>
                                    <td data-label="Email" class="wrap-text"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td data-label="Status">
                                        <span class="status status-<?php echo htmlspecialchars($user['status']); ?>">
                                            <?php echo htmlspecialchars($user['status']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Suspension Info">
                                        <?php echo htmlspecialchars($user['remaining_time_display']); ?>
                                        <?php if ($user['status'] === 'suspended' && $user['suspended_until']): ?>
                                            <span style="font-size: 0.8em; color: var(--gray); display: block;" title="Expires On">
                                                (<?php echo htmlspecialchars(date('M d, Y H:i', strtotime($user['suspended_until']))); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-btns">
                                            <!-- Block/Unblock Form -->
                                            <form method="post" class="form-inline">
                                                <!-- Consider CSRF token -->
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="current_status" value="<?php echo $user['status']; ?>">
                                                <button type="submit" name="block" class="btn btn-xs <?php echo ($user['status'] == 'active' || $user['status'] == 'suspended') ? 'btn-block' : 'btn-unblock'; ?>" title="<?php echo ($user['status'] == 'active' || $user['status'] == 'suspended') ? 'Block User' : 'Unblock User'; ?>">
                                                    <i class="fas <?php echo ($user['status'] == 'active' || $user['status'] == 'suspended') ? 'fa-lock' : 'fa-unlock'; ?>"></i>
                                                    <span><?php echo ($user['status'] == 'active' || $user['status'] == 'suspended') ? 'Block' : 'Unblock'; ?></span>
                                                </button>
                                            </form>

                                            <!-- Delete Form -->
                                            <form method="post" class="form-inline">
                                                 <!-- Consider CSRF token -->
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="delete" class="btn btn-xs btn-danger" title="Delete User Permanently">
                                                    <i class="fas fa-trash-alt"></i> <span>Delete</span>
                                                </button>
                                                <!-- Removed inline onsubmit -->
                                            </form>

                                            <!-- Suspension Form -->
                                            <form method="post" class="form-inline">
                                                 <!-- Consider CSRF token -->
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <div class="suspension-form">
                                                    <label for="sus_hrs_<?php echo $user['id']; ?>" title="Hours">H:</label>
                                                    <input type="number" id="sus_hrs_<?php echo $user['id']; ?>" name="suspension_hours" value="0" min="-999" max="999" title="Hours (+/-)">
                                                    <label for="sus_min_<?php echo $user['id']; ?>" title="Minutes">M:</label>
                                                    <input type="number" id="sus_min_<?php echo $user['id']; ?>" name="suspension_minutes" value="0" min="-59" max="59" step="1" title="Minutes (+/-)">
                                                    <button type="submit" name="update_suspension" class="btn btn-xs btn-suspend" title="Adjust Suspension Time (+ to add, - to remove)">
                                                        <i class="fas fa-clock"></i> <span class="suspend-btn-text">Suspend</span>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div> <!-- End Table Responsive -->

            <!-- Floating Action Button -->
            <a href="add_user.php" class="fab" title="Add New User">
                <i class="fas fa-plus"></i>
            </a>
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
// Close the database connection if it's still open
if (isset($db) && $db instanceof mysqli && !empty($db->thread_id)) {
    $db->close();
}
?>