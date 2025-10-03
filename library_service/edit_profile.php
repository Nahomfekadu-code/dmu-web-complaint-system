<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'library_service'
if (!isset($_SESSION['user_id'])) {
    error_log("Session user_id not set. Redirecting to login.");
    $_SESSION['error'] = "Please log in to access this page.";
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'library_service') {
    error_log("User role is {$_SESSION['role']}, not library_service. Redirecting to unauthorized.");
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../unauthorized.php");
    exit;
}

$library_user_id = $_SESSION['user_id'];
$library_user = null;

// Fetch current user details
$sql_library_user = "SELECT fname, lname, email, role, password FROM users WHERE id = ?";
$stmt_library_user = $db->prepare($sql_library_user);
if ($stmt_library_user) {
    $stmt_library_user->bind_param("i", $library_user_id);
    $stmt_library_user->execute();
    $result_library_user = $stmt_library_user->get_result();
    if ($result_library_user->num_rows > 0) {
        $library_user = $result_library_user->fetch_assoc();
    } else {
        error_log("Library Service user details not found for ID: $library_user_id");
        $_SESSION['error'] = "User details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_library_user->close();
} else {
    error_log("Error preparing user query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: dashboard.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname = trim(filter_input(INPUT_POST, 'fname', FILTER_SANITIZE_STRING));
    $lname = trim(filter_input(INPUT_POST, 'lname', FILTER_SANITIZE_STRING));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation
    $errors = [];
    // Profile fields
    if (empty($fname)) {
        $errors[] = "First name is required.";
    }
    if (empty($lname)) {
        $errors[] = "Last name is required.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    } else {
        // Check for duplicate email (excluding current user)
        $sql_check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
        $stmt_check_email = $db->prepare($sql_check_email);
        if ($stmt_check_email) {
            $stmt_check_email->bind_param("si", $email, $library_user_id);
            $stmt_check_email->execute();
            if ($stmt_check_email->get_result()->num_rows > 0) {
                $errors[] = "This email is already in use by another user.";
            }
            $stmt_check_email->close();
        } else {
            error_log("Error preparing email check query: " . $db->error);
            $errors[] = "Database error checking email.";
        }
    }

    // Password fields (only validate if current_password is provided)
    $update_password = !empty($current_password);
    if ($update_password) {
        if (!password_verify($current_password, $library_user['password'])) {
            $errors[] = "Current password is incorrect.";
        }
        if (strlen($new_password) < 8) {
            $errors[] = "New password must be at least 8 characters long.";
        }
        if ($new_password !== $confirm_password) {
            $errors[] = "New password and confirmation do not match.";
        }
    }

    if (empty($errors)) {
        // Prepare update query
        if ($update_password) {
            $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $sql_update = "UPDATE users SET fname = ?, lname = ?, email = ?, password = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            if ($stmt_update) {
                $stmt_update->bind_param("ssssi", $fname, $lname, $email, $new_password_hashed, $library_user_id);
            }
        } else {
            $sql_update = "UPDATE users SET fname = ?, lname = ?, email = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            if ($stmt_update) {
                $stmt_update->bind_param("sssi", $fname, $lname, $email, $library_user_id);
            }
        }

        if ($stmt_update) {
            if ($stmt_update->execute()) {
                $_SESSION['success'] = "Profile updated successfully." . ($update_password ? " Password changed." : "");
                // Update session user details
                $library_user['fname'] = $fname;
                $library_user['lname'] = $lname;
                $library_user['email'] = $email;
                header("Location: edit_profile.php");
                exit;
            } else {
                error_log("Error updating profile for user ID $library_user_id: " . $stmt_update->error);
                $_SESSION['error'] = "Failed to update profile. Please try again.";
            }
            $stmt_update->close();
        } else {
            error_log("Error preparing update query: " . $db->error);
            $_SESSION['error'] = "Database error updating profile.";
        }
    } else {
        $_SESSION['error'] = implode(" ", $errors);
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
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
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
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }

        /* Content Container */
        .content-container {
            background: white;
            padding: 2.5rem;
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

        /* Form Styling */
        .form-container {
            background: #f9f9f9;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
            max-width: 600px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: var(--transition);
            background: white;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .form-group input::placeholder {
            color: var(--gray);
            opacity: 0.7;
        }

        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
            font-size: 1rem;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--light-gray);
            color: var(--dark);
        }

        .btn-secondary:hover {
            background: #d8d8d8;
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
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
            .vertical-nav {
                width: 220px;
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
                overflow-y: hidden;
            }
            .main-content {
                min-height: auto;
            }
            .horizontal-nav {
                flex-direction: column;
                gap: 10px;
            }
            .horizontal-menu {
                flex-wrap: wrap;
                justify-content: center;
            }
            .content-container {
                padding: 1.5rem;
            }
            h2 {
                font-size: 1.5rem;
            }
            .form-container {
                padding: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .content-container {
                padding: 1.25rem;
            }
            h2 {
                font-size: 1.3rem;
            }
            .form-container {
                padding: 1rem;
            }
            .button-group {
                flex-direction: column;
                gap: 0.75rem;
            }
            .btn {
                width: 100%;
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
            <?php if ($library_user): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($library_user['fname'] . ' ' . $library_user['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $library_user['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4>Library Service</h4>
                    <p>Role: Library Service</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
        <h3>Dashboard</h3>
            <a href="library_service_dashboard.php" class="nav-link <?php echo $current_page == 'library_service_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Overview</span>
            </a>
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
                <span>DMU Complaint System - Library Service</span>
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

        <!-- Profile Edit Form -->
        <div class="content-container">
            <h2>Edit Profile</h2>

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

            <!-- Form -->
            <div class="form-container">
                <form method="POST" action="edit_profile.php">
                    <div class="form-group">
                        <label for="fname"><i class="fas fa-user"></i> First Name</label>
                        <input type="text" id="fname" name="fname" value="<?php echo htmlspecialchars($library_user['fname']); ?>" placeholder="Enter your first name" required>
                    </div>
                    <div class="form-group">
                        <label for="lname"><i class="fas fa-user"></i> Last Name</label>
                        <input type="text" id="lname" name="lname" value="<?php echo htmlspecialchars($library_user['lname']); ?>" placeholder="Enter your last name" required>
                    </div>
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($library_user['email']); ?>" placeholder="Enter your email address" required>
                    </div>
                    <div class="form-group">
                        <label for="current_password"><i class="fas fa-lock"></i> Current Password</label>
                        <input type="password" id="current_password" name="current_password" placeholder="Enter current password (optional)">
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('current_password', this)"></i>
                    </div>
                    <div class="form-group">
                        <label for="new_password"><i class="fas fa-lock"></i> New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Enter new password (optional)">
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('new_password', this)"></i>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password', this)"></i>
                    </div>
                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div><!-- End content-container -->

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
    </div> <!-- End main-content -->

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000);
            });
        });

        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>