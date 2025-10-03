<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'department_head'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'department_head') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$dept_head_id = $_SESSION['user_id'];

// Fetch Department Head details
$sql_dept_head = "SELECT fname, lname, email, role, department FROM users WHERE id = ?";
$stmt_dept_head = $db->prepare($sql_dept_head);
$stmt_dept_head->bind_param("i", $dept_head_id);
$stmt_dept_head->execute();
$dept_head = $stmt_dept_head->get_result()->fetch_assoc();
$stmt_dept_head->close();

if (!$dept_head) {
    $_SESSION['error'] = "Department Head details not found.";
    header("Location: ../logout.php");
    exit;
}

// Handle password change form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['error'] = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 6) {
        $_SESSION['error'] = "New password must be at least 6 characters long.";
    } else {
        // Fetch the current password hash from the database
        $sql = "SELECT password FROM users WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $dept_head_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Verify the current password
        if (!password_verify($current_password, $user['password'])) {
            $_SESSION['error'] = "Current password is incorrect.";
        } else {
            // Hash the new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // Update the password in the database
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("si", $new_password_hash, $dept_head_id);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Password changed successfully!";
                $stmt->close();
                // Log the user out to ensure they log in with the new password
                header("Location: ../logout.php");
                exit;
            } else {
                $_SESSION['error'] = "Failed to change password. Please try again.";
                error_log("Error changing password: " . $stmt->error);
                $stmt->close();
            }
        }
    }

    // Redirect to the same page to display the message
    header("Location: change_password.php");
    exit;
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | DMU Complaint System</title>
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
            --background: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 6px 18px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50;
            --navbar-link: #bdc3c7;
            --navbar-link-hover: #34495e;
            --navbar-link-active: var(--primary);
            --topbar-bg: #ffffff;
            --topbar-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Montserrat', sans-serif;
        }

        body {
            background-color: var(--background);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--navbar-bg) 0%, #34495e 100%);
            color: #ecf0f1;
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
        }

        .nav-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(236, 240, 241, 0.1);
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%;
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.05);
            padding: 8px 12px;
            border-radius: var(--radius);
            margin-top: 10px;
        }

        .user-profile-mini i {
            font-size: 2rem;
            color: var(--accent);
        }

        .user-info h4 {
            font-size: 0.95rem;
            margin-bottom: 2px;
            font-weight: 500;
        }

        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: capitalize;
        }

        .nav-menu {
            padding: 0 10px;
            flex-grow: 1;
            overflow-y: auto;
        }

        .nav-menu::-webkit-scrollbar { width: 6px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }

        .nav-menu h3 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 25px 15px 10px;
            opacity: 0.6;
            font-weight: 600;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            color: var(--navbar-link);
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
            font-size: 0.95rem;
            font-weight: 400;
            white-space: nowrap;
        }

        .nav-link:hover {
            background: var(--navbar-link-hover);
            color: #ecf0f1;
            transform: translateX(3px);
        }

        .nav-link.active {
            background: var(--navbar-link-active);
            color: white;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1em;
            opacity: 0.8;
        }

        .nav-link.active i {
            opacity: 1;
        }

        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .horizontal-nav {
            background: var(--topbar-bg);
            border-radius: var(--radius);
            box-shadow: var(--topbar-shadow);
            padding: 12px 25px;
            margin-bottom: 25px;
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
            align-items: center;
            gap: 15px;
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
            gap: 8px;
            font-size: 0.95rem;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .horizontal-menu a i {
            font-size: 1rem;
            color: var(--grey);
        }

        .horizontal-menu a:hover i, .horizontal-menu a.active i {
            color: var(--primary-dark);
        }

        .notification-icon {
            position: relative;
        }

        .notification-icon i {
            font-size: 1.3rem;
            color: var(--grey);
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .notification-icon:hover i {
            color: var(--primary);
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 3px 8px rgba(0,0,0,0.07);
        }

        .alert i { font-size: 1.2rem; margin-right: 5px; }
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }

        .content-container {
            background: var(--card-bg);
            padding: 2rem;
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

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }

        .card {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.3rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header i {
            font-size: 1.4rem;
            color: var(--primary);
            margin-right: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--primary-dark);
        }

        .form-group input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--text-color);
            transition: border-color 0.3s ease;
        }

        .form-group input[type="password"]:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            line-height: 1.5;
            white-space: nowrap;
        }

        .btn i { font-size: 1em; line-height: 1; }
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: var(--shadow-hover); }

        .main-footer {
            background-color: var(--card-bg);
            padding: 15px 30px;
            margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-muted);
            flex-shrink: 0;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .vertical-nav { width: 75px; }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info, .vertical-nav .nav-menu h3, .vertical-nav .nav-link span { display: none; }
            .vertical-nav .nav-header .user-profile-mini i { font-size: 1.8rem; }
            .vertical-nav .user-profile-mini { padding: 8px; justify-content: center; }
            .vertical-nav .nav-link { justify-content: center; padding: 15px 10px; }
            .vertical-nav .nav-link i { margin-right: 0; font-size: 1.3rem; }
            .main-content { margin-left: 75px; }
            .horizontal-nav { left: 75px; }
            .main-footer { margin-left: 75px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 2px solid var(--primary-dark);
                flex-direction: column;
            }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block; }
            .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 10px; }
            .nav-menu { display: flex; flex-wrap: wrap; justify-content: center; padding: 5px 0; overflow-y: visible; }
            .nav-menu h3 { display: none; }
            .nav-link { flex-direction: row; width: auto; padding: 8px 12px; }
            .nav-link i { margin-right: 8px; margin-bottom: 0; font-size: 1rem; }
            .nav-link span { display: inline; font-size: 0.85rem; }
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .main-footer { margin-left: 0; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .btn-small { padding: 5px 10px; font-size: 0.75rem; }
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
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($dept_head['role']); ?> (<?php echo htmlspecialchars($dept_head['department']); ?>)</p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_escalated.php" class="nav-link <?php echo $current_page == 'view_escalated.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up fa-fw"></i>
                <span>View Escalated Complaints</span>
            </a>
           

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>View Notifications</span>
            </a>

            <h3>Account</h3>
            <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                <i class="fas fa-key fa-fw"></i>
                <span>Change Password</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <div class="notification-icon" title="View Notifications">
                    <a href="view_notifications.php" style="color: inherit; text-decoration: none;">
                        <i class="fas fa-bell"></i>
                    </a>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Page Specific Content -->
        <div class="content-container">
            <div class="page-header">
                <h2>Change Password</h2>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Change Password Form -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-key"></i> Update Your Password</span>
                </div>
                <div class="card-body">
                    <form action="change_password.php" method="POST">
                        <div class="form-group">
                            <label for="current_password">Current Password:</label>
                            <input type="password" name="current_password" id="current_password" required placeholder="Enter your current password">
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" name="new_password" id="new_password" required placeholder="Enter your new password">
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password:</label>
                            <input type="password" name="confirm_password" id="confirm_password" required placeholder="Confirm your new password">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Department Head Panel
        </footer>
    </div>

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
                }, 7000);
            });
        });
    </script>
</body>
</html>
<?php
$db->close();
?>