<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'student_service_directorate'
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'student_service_directorate') {
    error_log("Role check failed. Role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'not set'));
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$ssd_id = $_SESSION['user_id'];
$user = null; // Initialize user details

// Fetch Student Service Directorate details (including fields for the form)
$sql_user = "SELECT fname, lname, email, username, role FROM users WHERE id = ?";
$stmt_user = $db->prepare($sql_user);
if ($stmt_user) {
    $stmt_user->bind_param("i", $ssd_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $user = $result_user->fetch_assoc();
    } else {
        $_SESSION['error'] = "Student Service Directorate details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_user->close();
} else {
    error_log("Error preparing user details query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: dashboard.php");
    exit;
}

// Fetch notification count
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$notification_count = 0; // Default
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $ssd_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result['count'] ?? 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_fname = trim(filter_input(INPUT_POST, 'fname', FILTER_SANITIZE_STRING));
    $new_lname = trim(filter_input(INPUT_POST, 'lname', FILTER_SANITIZE_STRING));
    $new_email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $new_username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING));
    $current_password = filter_input(INPUT_POST, 'current_password', FILTER_SANITIZE_STRING);
    $new_password = filter_input(INPUT_POST, 'new_password', FILTER_SANITIZE_STRING);
    $confirm_password = filter_input(INPUT_POST, 'confirm_password', FILTER_SANITIZE_STRING);

    // Validate first name
    if (empty($new_fname)) {
        $_SESSION['error'] = "First name is required.";
    } elseif (strlen($new_fname) < 2) {
        $_SESSION['error'] = "First name must be at least 2 characters long.";
    }

    // Validate last name
    if (!isset($_SESSION['error']) && empty($new_lname)) {
        $_SESSION['error'] = "Last name is required.";
    } elseif (strlen($new_lname) < 2) {
        $_SESSION['error'] = "Last name must be at least 2 characters long.";
    }

    // Validate email
    if (!isset($_SESSION['error']) && empty($new_email)) {
        $_SESSION['error'] = "Email is required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
    } else {
        // Check if the new email is already taken by another user
        $check_email_stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        if ($check_email_stmt) {
            $check_email_stmt->bind_param("si", $new_email, $ssd_id);
            $check_email_stmt->execute();
            $check_email_result = $check_email_stmt->get_result();
            if ($check_email_result->num_rows > 0) {
                $_SESSION['error'] = "Email is already taken.";
            }
            $check_email_stmt->close();
        } else {
            error_log("Error preparing email check query: " . $db->error);
            $_SESSION['error'] = "Error checking email availability.";
        }
    }

    // Validate username
    if (!isset($_SESSION['error']) && empty($new_username)) {
        $_SESSION['error'] = "Username is required.";
    } elseif (strlen($new_username) < 4) {
        $_SESSION['error'] = "Username must be at least 4 characters long.";
    } else {
        // Check if the new username is already taken by another user
        $check_username_stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        if ($check_username_stmt) {
            $check_username_stmt->bind_param("si", $new_username, $ssd_id);
            $check_username_stmt->execute();
            $check_username_result = $check_username_stmt->get_result();
            if ($check_username_result->num_rows > 0) {
                $_SESSION['error'] = "Username is already taken.";
            }
            $check_username_stmt->close();
        } else {
            error_log("Error preparing username check query: " . $db->error);
            $_SESSION['error'] = "Error checking username availability.";
        }
    }

    // Validate password fields (only if user wants to change password)
    $password_updated = false;
    if (!isset($_SESSION['error']) && (!empty($new_password) || !empty($confirm_password) || !empty($current_password))) {
        // Require current password ONLY if new password fields are filled
        if (!empty($new_password) || !empty($confirm_password)) {
            if (empty($current_password)) {
                $_SESSION['error'] = "Current password is required to set a new password.";
            } elseif ($new_password !== $confirm_password) {
                $_SESSION['error'] = "New password and confirmation do not match.";
            } elseif (strlen($new_password) < 8) {
                $_SESSION['error'] = "New password must be at least 8 characters long.";
            } else {
                // Verify current password
                $pass_stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                if ($pass_stmt) {
                    $pass_stmt->bind_param("i", $ssd_id);
                    $pass_stmt->execute();
                    $pass_result = $pass_stmt->get_result()->fetch_assoc();
                    $pass_stmt->close();

                    if (!$pass_result || !password_verify($current_password, $pass_result['password'])) {
                        $_SESSION['error'] = "Current password is incorrect.";
                    } else {
                        $password_updated = true; // Mark password for update
                    }
                } else {
                    error_log("Error preparing password check query: " . $db->error);
                    $_SESSION['error'] = "Error verifying current password.";
                }
            }
        }
    }

    // If no errors, proceed with the update
    if (!isset($_SESSION['error'])) {
        $db->begin_transaction();
        try {
            $fname_changed = ($user && $new_fname !== $user['fname']);
            $lname_changed = ($user && $new_lname !== $user['lname']);
            $email_changed = ($user && $new_email !== $user['email']);
            $username_changed = ($user && $new_username !== $user['username']);

            // Update user details
            if ($fname_changed || $lname_changed || $email_changed || $username_changed || $password_updated) {
                $update_stmt = $db->prepare("UPDATE users SET fname = ?, lname = ?, email = ?, username = ?" . ($password_updated ? ", password = ?" : "") . " WHERE id = ?");
                if (!$update_stmt) {
                    throw new Exception("Prepare failed for user update: " . $db->error);
                }
                if ($password_updated) {
                    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt->bind_param("sssssi", $new_fname, $new_lname, $new_email, $new_username, $hashed_new_password, $ssd_id);
                } else {
                    $update_stmt->bind_param("ssssi", $new_fname, $new_lname, $new_email, $new_username, $ssd_id);
                }
                if (!$update_stmt->execute()) {
                    throw new Exception("Execute failed for user update: " . $update_stmt->error);
                }
                $update_stmt->close();

                // Update session user details
                $user['fname'] = $new_fname;
                $user['lname'] = $new_lname;
                $user['email'] = $new_email;
                $user['username'] = $new_username;

                $db->commit();
                $_SESSION['success'] = "Profile updated successfully.";
            } else {
                $_SESSION['info'] = "No changes were made to your profile.";
            }

            header("Location: edit_profile.php");
            exit;

        } catch (Exception $e) {
            $db->rollback();
            error_log("Profile update error: " . $e->getMessage());
            $_SESSION['error'] = "An error occurred while updating your profile. Please try again.";
            header("Location: edit_profile.php");
            exit;
        }
    } else {
        header("Location: edit_profile.php");
        exit;
    }
}

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
            position: relative;
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
        }

        .nav-link .badge-count {
            margin-left: auto;
            background-color: var(--danger);
            color: white;
            padding: 2px 6px;
            font-size: 0.7rem;
            border-radius: 5px;
            font-weight: 600;
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
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }

        /* Content Container */
        .content-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
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

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"] {
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
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .form-group .optional {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.3rem;
            display: block;
        }

        /* Button Styles */
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            vertical-align: middle;
            margin-right: 0.5rem;
        }
        .btn:last-child {
            margin-right: 0;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            box-shadow: var(--shadow-hover);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
            border: 1px solid var(--gray);
        }
        .btn-secondary:hover {
            background: var(--dark);
            border-color: var(--dark);
            box-shadow: var(--shadow-hover);
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
            <?php if ($user): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></h4>
                    <p>Student Service Directorate</p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>Student Service Directorate</h4>
                    <p>Role: Student Service Directorate</p>
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

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="badge-count"><?php echo $notification_count; ?></span>
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

    <!-- Main Content Wrapper -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Student Service Directorate</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
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

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['info'])): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
            <?php endif; ?>

            <!-- Edit Profile Form -->
            <form method="POST" action="edit_profile.php">
                <div class="form-group">
                    <label for="fname">First Name</label>
                    <input type="text" name="fname" id="fname" required value="<?php echo htmlspecialchars($user['fname'] ?? ''); ?>" placeholder="Enter your first name">
                </div>
                <div class="form-group">
                    <label for="lname">Last Name</label>
                    <input type="text" name="lname" id="lname" required value="<?php echo htmlspecialchars($user['lname'] ?? ''); ?>" placeholder="Enter your last name">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" placeholder="Enter your email">
                </div>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" required value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" placeholder="Enter your username">
                </div>
                <hr style="border: none; border-top: 1px solid var(--light-gray); margin: 1.5rem 0;">
                <p style="font-weight: 500; color: var(--secondary); margin-bottom: 1rem;">Change Password (optional)</p>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" name="current_password" id="current_password" placeholder="Enter current password to change">
                    <span class="optional">Required only if setting a new password.</span>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" name="new_password" id="new_password" placeholder="Enter new password (min 8 chars)">
                    <span class="optional">Leave blank to keep current password.</span>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your new password">
                </div>
                <div>
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

    <!-- JavaScript for auto-hiding alerts -->
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
                }, 5000);
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