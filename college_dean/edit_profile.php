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

// Fetch current user details (including fname, lname, role for sidebar)
$stmt_user = $db->prepare("SELECT username, fname, lname, email, role FROM users WHERE id = ?");
if ($stmt_user) {
    $stmt_user->bind_param("i", $dean_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $dean = $result_user->fetch_assoc(); // Store all details in $dean
    } else {
        // Handle case where user details are not found
        $_SESSION['error'] = "User details not found.";
        error_log("User details not found for ID: " . $dean_id);
        // Redirect or display error
        header("Location: ../logout.php"); // Example: log out if user doesn't exist
        exit;
    }
    $stmt_user->close();
} else {
    error_log("Error preparing user details query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    // Handle DB error appropriately, maybe show a generic error page
}


// Fetch notification count (for sidebar)
$notification_count = 0; // Default value
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $dean_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
     error_log("Error preparing notification count query: " . $db->error);
}


// Handle form submission (Keep existing logic)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
    // Note: We'll fetch the current username from $dean['username'] instead of re-querying if needed.

    $current_password = filter_input(INPUT_POST, 'current_password', FILTER_SANITIZE_STRING);
    $new_password = filter_input(INPUT_POST, 'new_password', FILTER_SANITIZE_STRING);
    $confirm_password = filter_input(INPUT_POST, 'confirm_password', FILTER_SANITIZE_STRING);

    $errors = []; // Use an array to collect errors

    // Validate username
    if (empty($new_username)) {
        $errors[] = "Username is required.";
    } elseif (strlen($new_username) < 4) {
        $errors[] = "Username must be at least 4 characters long.";
    } else {
        // Check if the new username is already taken by another user
        $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        if ($check_stmt) {
            $check_stmt->bind_param("si", $new_username, $dean_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                $errors[] = "Username '" . htmlspecialchars($new_username) . "' is already taken.";
            }
            $check_stmt->close();
        } else {
            $errors[] = "Database error checking username.";
            error_log("Prepare failed for username check: " . $db->error);
        }
    }

    // Validate password fields (only if user wants to change password)
    $password_updated = false;
    if (!empty($new_password) || !empty($confirm_password) || !empty($current_password)) { // Check if any password field is touched
        if (empty($current_password)) {
            $errors[] = "Current password is required to change your password.";
        } else {
             // Verify current password first
            $pass_stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $current_db_password_hash = null;
            if ($pass_stmt) {
                $pass_stmt->bind_param("i", $dean_id);
                $pass_stmt->execute();
                $pass_result = $pass_stmt->get_result()->fetch_assoc();
                $current_db_password_hash = $pass_result ? $pass_result['password'] : null;
                $pass_stmt->close();
            } else {
                 $errors[] = "Database error verifying current password.";
                 error_log("Prepare failed for password verification: " . $db->error);
            }

            if ($current_db_password_hash && !password_verify($current_password, $current_db_password_hash)) {
                $errors[] = "Current password is incorrect.";
            } else if ($current_db_password_hash) { // Only proceed if current password is correct
                // Now validate new password fields
                if (empty($new_password)) {
                    $errors[] = "New password cannot be empty if you provide the current password.";
                } elseif ($new_password !== $confirm_password) {
                    $errors[] = "New password and confirmation do not match.";
                } elseif (strlen($new_password) < 8) {
                    $errors[] = "New password must be at least 8 characters long.";
                } else {
                    $password_updated = true; // Mark password for update
                }
            }
        }
    }

    // If no errors, proceed with the update
    if (empty($errors)) {
        $db->begin_transaction();
        try {
            $username_changed = ($dean['username'] !== $new_username);

            // Update username only if it changed
            if ($username_changed) {
                $update_username_stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
                if (!$update_username_stmt) {
                    throw new Exception("Prepare failed for username update: " . $db->error);
                }
                $update_username_stmt->bind_param("si", $new_username, $dean_id);
                if (!$update_username_stmt->execute()) {
                     throw new Exception("Execute failed for username update: " . $update_username_stmt->error);
                }
                $update_username_stmt->close();
            }

            // Update password if marked for update
            if ($password_updated) {
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password_stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                if (!$update_password_stmt) {
                    throw new Exception("Prepare failed for password update: " . $db->error);
                }
                $update_password_stmt->bind_param("si", $hashed_new_password, $dean_id);
                 if (!$update_password_stmt->execute()) {
                     throw new Exception("Execute failed for password update: " . $update_password_stmt->error);
                }
                $update_password_stmt->close();
            }

            if ($username_changed || $password_updated) {
                $db->commit();
                $_SESSION['success'] = "Profile updated successfully.";
                // Update $dean array in case page reloads without redirect (though we redirect)
                if($username_changed) $dean['username'] = $new_username;
                header("Location: edit_profile.php"); // Redirect back to edit profile page to show success
                exit;
            } else {
                 // No actual changes made
                 $db->rollback(); // Rollback just in case (though nothing should have executed)
                 $_SESSION['info'] = "No changes were made to your profile."; // Use info message
                 header("Location: edit_profile.php");
                 exit;
            }

        } catch (Exception $e) {
            $db->rollback();
            error_log("Profile update error: " . $e->getMessage());
            $_SESSION['error'] = "An error occurred while updating your profile. Please try again.";
            header("Location: edit_profile.php");
            exit;
        }
    } else {
        // Store errors in session to display after redirect
        $_SESSION['form_errors'] = $errors;
        // Store submitted username to repopulate form (optional, but good practice)
        $_SESSION['form_data'] = ['username' => $new_username];
        header("Location: edit_profile.php");
        exit;
    }
}

// Retrieve errors and form data from session if they exist (after redirect)
$form_errors = $_SESSION['form_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

// Use submitted username if available after error, otherwise use current DB username
$display_username = $form_data['username'] ?? ($dean['username'] ?? '');


// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Using the consistent CSS -->
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
            --info: #17a2b8; /* Added info color */
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
            align-items: flex-start; /* Align icon top */
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.2rem; margin-top: 2px; flex-shrink: 0; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }
        .alert ul { margin: 0; padding-left: 20px; } /* Style for error list */


        /* Content Container */
        .content-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1; /* Allow it to grow */
            max-width: 700px; /* Adjust max width if needed */
            margin-left: auto;
            margin-right: auto; /* Center the form container */
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

        /* Form Specific Styles */
         .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600; /* Slightly bolder */
            margin-bottom: 0.6rem;
            color: var(--primary-dark);
        }

        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="email"] { /* Add email if needed */
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15); /* Subtle focus ring */
        }
         .form-group input::placeholder {
            color: var(--gray);
            opacity: 0.8;
         }

        .form-group .optional-info { /* Renamed class */
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.4rem;
            display: block;
        }

        .form-actions { /* Wrapper for buttons */
             margin-top: 2rem;
             display: flex;
             gap: 1rem;
             justify-content: flex-start; /* Align buttons left */
        }

        .btn {
            padding: 0.6rem 1.2rem; /* Slightly larger padding */
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 600; /* Bolder text */
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none; /* For link buttons */
            text-align: center;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-secondary { background: var(--gray); color: white; }
        .btn-secondary:hover { background: var(--dark); transform: translateY(-1px);}


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
            <?php if ($dean): // Check if $dean has data ?>
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
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
            <i class="fas fa-arrow-up"></i>
            <span>Escalate Complaint</span>
        </a>

            <h3>Communication</h3>
            <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
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
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Content Container -->
        <div class="content-container">
            <h2>Edit Profile</h2>

            <!-- Display Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
             <?php if (isset($_SESSION['info'])): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
            <?php endif; ?>

            <!-- Display Validation Errors from Redirect -->
            <?php if (!empty($form_errors)): ?>
                <div class="alert alert-danger">
                     <i class="fas fa-exclamation-triangle"></i>
                     <div>
                         Please correct the following errors:
                         <ul>
                             <?php foreach ($form_errors as $error): ?>
                                 <li><?php echo htmlspecialchars($error); ?></li>
                             <?php endforeach; ?>
                         </ul>
                    </div>
                </div>
            <?php endif; ?>


            <!-- Edit Profile Form -->
            <form method="POST" action="edit_profile.php" novalidate>
                 <div class="form-group">
                    <label for="fname">First Name</label>
                    <input type="text" id="fname" value="<?php echo htmlspecialchars($dean['fname'] ?? ''); ?>" readonly disabled style="background-color: #e9ecef; cursor: not-allowed;">
                     <span class="optional-info">First name cannot be changed here.</span>
                </div>
                 <div class="form-group">
                    <label for="lname">Last Name</label>
                    <input type="text" id="lname" value="<?php echo htmlspecialchars($dean['lname'] ?? ''); ?>" readonly disabled style="background-color: #e9ecef; cursor: not-allowed;">
                     <span class="optional-info">Last name cannot be changed here.</span>
                </div>
                 <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" value="<?php echo htmlspecialchars($dean['email'] ?? ''); ?>" readonly disabled style="background-color: #e9ecef; cursor: not-allowed;">
                     <span class="optional-info">Email cannot be changed here.</span>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" required minlength="4" value="<?php echo htmlspecialchars($display_username); ?>" placeholder="Enter your desired username">
                </div>
                <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--light-gray);">
                 <h3 style="margin-bottom: 1rem; font-size: 1.2rem; color: var(--secondary);">Change Password (Optional)</h3>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" name="current_password" id="current_password" placeholder="Enter current password to change">
                    <span class="optional-info">Required only if changing password.</span>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" minlength="8" placeholder="Enter new password (min 8 characters)">
                     <span class="optional-info">Leave blank to keep current password.</span>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your new password">
                     <span class="optional-info">Must match new password.</span>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
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

    <!-- JavaScript for Alerts -->
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
                }, 7000); // Increased time to 7 seconds for potentially longer error lists
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