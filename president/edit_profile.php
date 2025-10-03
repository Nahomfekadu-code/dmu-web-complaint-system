<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'president'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'president') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$president_id = $_SESSION['user_id'];
$president = null;

// Fetch President details
$sql_president = "SELECT fname, lname, email, phone, role FROM users WHERE id = ?";
$stmt_president = $db->prepare($sql_president);
if ($stmt_president) {
    $stmt_president->bind_param("i", $president_id);
    $stmt_president->execute();
    $result_president = $stmt_president->get_result();
    if ($result_president->num_rows > 0) {
        $president = $result_president->fetch_assoc();
    } else {
        $_SESSION['error'] = "President details not found.";
        error_log("President details not found for ID: " . $president_id);
        header("Location: ../logout.php");
        exit;
    }
    $stmt_president->close();
} else {
    error_log("Error preparing president query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
}

// Fetch notification count
$sql_notif_count = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif_count = $db->prepare($sql_notif_count);
$notification_count = 0;
if ($stmt_notif_count) {
    $stmt_notif_count->bind_param("i", $president_id);
    if ($stmt_notif_count->execute()) {
        $notif_result = $stmt_notif_count->get_result();
        $notification_count = (int)($notif_result->fetch_assoc()['count'] ?? 0);
        $notif_result->free();
    } else {
        error_log("Error fetching notification count: " . $stmt_notif_count->error);
    }
    $stmt_notif_count->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate required fields
    if (empty($fname) || empty($lname) || empty($email)) {
        $_SESSION['error'] = "First name, last name, and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format.";
    } elseif (!empty($phone) && !preg_match('/^\+?[1-9]\d{1,14}$/', $phone)) {
        $_SESSION['error'] = "Invalid phone number format.";
    } else {
        // Check if email is already in use by another user
        $sql_check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
        $stmt_check_email = $db->prepare($sql_check_email);
        if ($stmt_check_email) {
            $stmt_check_email->bind_param("si", $email, $president_id);
            $stmt_check_email->execute();
            $email_result = $stmt_check_email->get_result();
            if ($email_result->num_rows > 0) {
                $_SESSION['error'] = "This email is already in use by another user.";
            }
            $stmt_check_email->close();
        }

        // If no email conflict, proceed
        if (!isset($_SESSION['error'])) {
            // Handle password update if provided
            if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
                // All password fields must be filled
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $_SESSION['error'] = "Please fill in all password fields to update your password.";
                } else {
                    // Verify current password
                    $sql_verify_password = "SELECT password FROM users WHERE id = ?";
                    $stmt_verify = $db->prepare($sql_verify_password);
                    if ($stmt_verify) {
                        $stmt_verify->bind_param("i", $president_id);
                        $stmt_verify->execute();
                        $result_verify = $stmt_verify->get_result();
                        $user = $result_verify->fetch_assoc();
                        if (!password_verify($current_password, $user['password'])) {
                            $_SESSION['error'] = "Current password is incorrect.";
                        } elseif ($new_password !== $confirm_password) {
                            $_SESSION['error'] = "New password and confirmation do not match.";
                        } elseif (strlen($new_password) < 8) {
                            $_SESSION['error'] = "New password must be at least 8 characters long.";
                        }
                        $stmt_verify->close();
                    }
                }
            }

            // If no errors, update the profile
            if (!isset($_SESSION['error'])) {
                $sql_update = "UPDATE users SET fname = ?, lname = ?, phone = ?, email = ? WHERE id = ?";
                $params = [$fname, $lname, $phone ?: null, $email, $president_id];
                $types = "ssssi";

                if (!empty($new_password) && !isset($_SESSION['error'])) {
                    $sql_update = "UPDATE users SET fname = ?, lname = ?, phone = ?, email = ?, password = ? WHERE id = ?";
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $params = [$fname, $lname, $phone ?: null, $email, $hashed_password, $president_id];
                    $types = "sssssi";
                }

                $stmt_update = $db->prepare($sql_update);
                if ($stmt_update) {
                    $stmt_update->bind_param($types, ...$params);
                    if ($stmt_update->execute()) {
                        $_SESSION['success'] = "Profile updated successfully.";
                        // Update session data if email changes
                        $president['fname'] = $fname;
                        $president['lname'] = $lname;
                        $president['phone'] = $phone;
                        $president['email'] = $email;
                    } else {
                        $_SESSION['error'] = "Error updating profile: " . $stmt_update->error;
                        error_log("Error updating profile for user ID $president_id: " . $stmt_update->error);
                    }
                    $stmt_update->close();
                } else {
                    $_SESSION['error'] = "Database error preparing update query.";
                    error_log("Error preparing update query: " . $db->error);
                }
            }
        }
    }

    // Redirect to avoid form resubmission
    header("Location: edit_profile.php");
    exit;
}

$current_page = 'edit_profile.php';
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
            flex-shrink: 0;
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

        .nav-link .badge {
            margin-left: auto;
            font-size: 0.8rem;
            padding: 2px 6px;
            background-color: var(--danger);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-y: auto;
        }

        .horizontal-nav {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 15px 20px;
            margin-bottom: 20px;
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

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .alert i {
            font-size: 1.2rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .alert-success {
            background-color: #d1e7dd;
            border-color: #badbcc;
            color: #0f5132;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }

        .alert-info {
            background-color: #cff4fc;
            border-color: #b6effb;
            color: #055160;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #664d03;
        }

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

        .profile-form {
            max-width: 600px;
            margin: 0 auto;
            display: grid;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--dark);
            transition: border-color 0.3s ease;
            background-color: #fff;
        }

        .form-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }

        .form-group.note {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: -10px;
            margin-bottom: 10px;
        }

        .form-group-submit {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding-top: 10px;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            transform: translateY(-1px);
        }

        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            width: 100%;
            flex-shrink: 0;
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

        @media (max-width: 992px) {
            .vertical-nav { width: 70px; }
            .nav-header .logo-text, .nav-menu h3, .nav-link span, .user-info { display: none; }
            .nav-link { justify-content: center; padding: 12px; }
            .nav-link i { margin: 0; font-size: 1.3rem; }
            .main-content { padding: 15px; }
        }

        @media (max-width: 768px) {
            .horizontal-nav { flex-direction: column; align-items: flex-start; }
            .horizontal-menu { width: 100%; justify-content: flex-end; }
            .profile-form { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .btn { padding: 0.5rem 1rem; font-size: 0.9rem; }
            .form-group label { font-size: 0.85rem; }
            .form-group input { font-size: 0.9rem; padding: 8px; }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <?php if ($president): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($president['fname'] . ' ' . $president['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $president['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>President</h4>
                    <p>Role: President</p>
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
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span> Resolved Complaints</span>
            </a>
            <a href="view_reports.php" class="nav-link <?php echo $current_page == 'view_reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>View Reports</span>
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
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - President</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Edit Profile</h2>

            <!-- Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Profile Edit Form -->
            <form method="POST" class="profile-form">
                <div class="form-group">
                    <label for="fname">First Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="fname" name="fname" value="<?php echo htmlspecialchars($president['fname']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="lname">Last Name <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="lname" name="lname" value="<?php echo htmlspecialchars($president['lname']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($president['phone'] ?? ''); ?>" placeholder="+1234567890">
                </div>
                <div class="form-group">
                    <label for="email">Email <span style="color: var(--danger);">*</span></label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($president['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password">
                    <div class="form-group note">Leave password fields blank if you do not wish to change your password.</div>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password">
                </div>
                <div class="form-group-submit">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

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
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
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