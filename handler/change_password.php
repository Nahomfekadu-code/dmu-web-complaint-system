<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../login.php");
    exit;
}

// Initialize variables for form handling
$current_password = '';
$new_password = '';
$confirm_password = '';
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validate inputs
    if (empty($current_password)) {
        $errors[] = "Current password is required.";
    }
    if (empty($new_password)) {
        $errors[] = "New password is required.";
    }
    if (empty($confirm_password)) {
        $errors[] = "Confirm password is required.";
    }
    if ($new_password !== $confirm_password) {
        $errors[] = "New password and confirm password do not match.";
    }
    if (strlen($new_password) < 8) {
        $errors[] = "New password must be at least 8 characters long.";
    }

    // If no validation errors, proceed to verify current password and update
    if (empty($errors)) {
        // Fetch the current user's password hash
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Verify the current password
        if (!$user || !password_verify($current_password, $user['password'])) {
            $errors[] = "Current password is incorrect.";
        } else {
            // Hash the new password and update the database
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $new_password_hash, $_SESSION['user_id']);
            if ($stmt->execute()) {
                $_SESSION['success'] = "Password changed successfully.";
                header("Location: change_password.php");
                exit;
            } else {
                $errors[] = "Failed to update password: " . $db->error;
            }
            $stmt->close();
        }
    }

    // If there are errors, store them in the session to display
    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
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
    <title>Change Password | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --grey: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
            --orange: #fd7e14;
            --background: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 8px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50;
            --navbar-link: #bdc3c7;
            --navbar-link-hover: #34495e;
            --navbar-link-active: #3498db;
            --topbar-bg: #ffffff;
            --topbar-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body { background-color: var(--background); color: var(--text-color); line-height: 1.6; }

        .vertical-navbar {
            width: 260px; height: 100vh; position: fixed; left: 0; top: 0;
            background-color: var(--navbar-bg); color: #ecf0f1;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000;
            display: flex; flex-direction: column; transition: width 0.3s ease;
        }
        .nav-header { padding: 25px 20px; text-align: center; border-bottom: 1px solid #34495e; flex-shrink: 0;}
        .nav-header h3 { margin: 0; font-size: 1.3rem; color: #ecf0f1; font-weight: 600; }
        .nav-links { list-style: none; padding: 0; margin: 15px 0; overflow-y: auto; flex-grow: 1;}
        .nav-links h3 {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 20px 25px 10px;
            color: #ecf0f1;
            opacity: 0.7;
        }
        .nav-links li a {
            display: flex; align-items: center; padding: 14px 25px;
            color: var(--navbar-link); text-decoration: none; transition: all 0.3s ease;
            font-size: 0.95rem; white-space: nowrap;
        }
        .nav-links li a:hover { background-color: var(--navbar-link-hover); color: #ecf0f1; }
        .nav-links li a.active { background-color: var(--navbar-link-active); color: white; font-weight: 500; }
        .nav-links li a i { margin-right: 15px; width: 20px; text-align: center; font-size: 1.1em; }
        .nav-footer { padding: 20px; text-align: center; border-top: 1px solid #34495e; font-size: 0.85rem; color: #7f8c8d; flex-shrink: 0; }

        .horizontal-navbar {
            display: flex; justify-content: space-between; align-items: center;
            height: 70px; padding: 0 30px; background-color: var(--topbar-bg);
            box-shadow: var(--topbar-shadow); position: fixed; top: 0; right: 0; left: 260px;
            z-index: 999; transition: left 0.3s ease;
        }
        .top-nav-left .page-title { color: var(--dark); font-size: 1.1rem; font-weight: 500; }
        .top-nav-right { display: flex; align-items: center; gap: 20px; }
        .notification-icon i { font-size: 1.3rem; color: var(--grey); cursor: pointer; }

        .main-content {
            margin-left: 260px; padding: 30px; padding-top: 100px;
            transition: margin-left 0.3s ease; min-height: calc(100vh - 70px);
        }
        .page-header h2 {
            font-size: 1.8rem; font-weight: 600; color: var(--primary-dark);
            margin-bottom: 25px; border-bottom: 2px solid var(--primary);
            padding-bottom: 10px; display: inline-block;
        }

        .card {
            background-color: var(--card-bg); padding: 25px; border-radius: var(--radius);
            box-shadow: var(--shadow); margin-bottom: 30px; border: 1px solid var(--border-color);
        }
        .card-header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 25px;
            color: var(--primary-dark); font-size: 1.3rem; font-weight: 600;
            padding-bottom: 15px; border-bottom: 1px solid var(--border-color);
        }
        .card-header i { font-size: 1.5rem; color: var(--primary); }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block; font-weight: 500; margin-bottom: 8px; color: var(--primary-dark);
        }
        .form-group input {
            width: 100%; padding: 10px; border: 1px solid var(--border-color);
            border-radius: var(--radius); font-size: 0.95rem; color: var(--text-color);
            transition: border-color 0.3s ease;
        }
        .form-group input:focus {
            border-color: var(--primary); outline: none; box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 500;
            cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5;
        }
        .btn i { font-size: 1em; }
        .btn-small { padding: 5px 10px; font-size: 0.8rem; gap: 5px; }
        .btn-info { background-color: var(--info); color: #fff; }
        .btn-info:hover { background-color: #0baccc; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(13,202,240,0.3); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c21d2c; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(220,53,69,0.3); }
        .btn-success { background-color: var(--success); color: #fff; }
        .btn-success:hover { background-color: #218838; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(40,167,69,0.3); }

        .alert {
            padding: 15px 20px; margin-bottom: 25px; border-radius: var(--radius);
            border: 1px solid transparent; display: flex; align-items: center;
            gap: 12px; font-weight: 500; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }

        .main-footer {
            background-color: var(--card-bg); padding: 15px 30px; margin-top: 30px;
            margin-left: 260px; border-top: 1px solid var(--border-color);
            text-align: center; font-size: 0.9rem; color: var(--text-muted);
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .vertical-navbar { width: 70px; }
            .vertical-navbar .nav-header h3, .vertical-navbar .nav-links span, .vertical-navbar .nav-footer { display: none; }
            .vertical-navbar .nav-links h3 { display: none; }
            .vertical-navbar .nav-links li a { justify-content: center; padding: 15px 10px; }
            .vertical-navbar .nav-links li a i { margin-right: 0; font-size: 1.3rem; }
            .horizontal-navbar { left: 70px; }
            .main-content { margin-left: 70px; }
            .main-footer { margin-left: 70px; }
        }
        @media (max-width: 768px) {
            .horizontal-navbar { padding: 0 15px; height: auto; flex-direction: column; align-items: flex-start; }
            .top-nav-left { padding: 10px 0; }
            .top-nav-right { padding-bottom: 10px; width: 100%; justify-content: flex-end; }
            .main-content { padding: 15px; padding-top: 120px; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <div class="vertical-navbar">
        <div class="nav-header">
            <h3>DMU Handler</h3>
        </div>
        <ul class="nav-links">
            <h3>Dashboard</h3>
            <li>
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard Overview</span>
                </a>
            </li>
            
            <h3>Complaint Management</h3>
            <li>
                <a href="view_assigned_complaints.php" class="<?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                    <i class="fas fa-list-alt fa-fw"></i> <span>Assigned Complaints</span>
                </a>
            </li>
            <li>
                <a href="view_resolved.php" class="<?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle fa-fw"></i> <span>Resolved Complaints</span>
                </a>
            </li>
            <li>
                <a href="stereotype.php" class="<?php echo $current_page == 'stereotype.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tags fa-fw"></i> <span>Manage Stereotypes</span>
                </a>
            </li>
            
            <h3>Communication</h3>
            <li>
                <a href="manage_notices.php" class="<?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn fa-fw"></i> <span>Manage Notices</span>
                </a>
            </li>
            <li>
                <a href="view_notifications.php" class="<?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                    <i class="fas fa-bell fa-fw"></i> <span>View Notifications</span>
                </a>
            </li>
            <li>
                <a href="view_decisions.php" class="<?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                    <i class="fas fa-gavel fa-fw"></i> <span>Decisions Received</span>
                </a>
            </li>
            <li>
                <a href="view_feedback.php" class="<?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                    <i class="fas fa-comment-dots fa-fw"></i> <span>Complaint Feedback</span>
                </a>
            </li>
            
            <h3>Reports</h3>
            <li>
                <a href="generate_report.php" class="<?php echo $current_page == 'generate_report.php' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt fa-fw"></i> <span>Generate Reports</span>
                </a>
            </li>
            
            <h3>Account</h3>
            <li>
                <a href="change_password.php" class="<?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                    <i class="fas fa-key fa-fw"></i> <span>Change Password</span>
                </a>
            </li>
            <li>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt fa-fw"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
        <div class="nav-footer">
            <p>© <?php echo date("Y"); ?> DMU CMS</p>
        </div>
    </div>

    <nav class="horizontal-navbar">
        <div class="top-nav-left">
            <span class="page-title">Change Password</span>
        </div>
        <div class="top-nav-right">
            <div class="notification-icon" title="View Notifications">
                <a href="view_notifications.php" style="color: inherit; text-decoration: none;"><i class="fas fa-bell"></i></a>
            </div>
            <div class="user-dropdown">
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h2>Change Password</h2>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-key"></i> Update Your Password
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required value="<?php echo htmlspecialchars($current_password); ?>">
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required value="<?php echo htmlspecialchars($new_password); ?>">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required value="<?php echo htmlspecialchars($confirm_password); ?>">
                    </div>
                    <div style="text-align: center;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Change Password
                        </button>
                        <a href="dashboard.php" class="btn btn-info">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="main-footer">
        © <?php echo date("Y"); ?> DMU Complaint Management System | Handler Panel
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000);
            });
        });
    </script>
</body>
</html>
<?php
$db->close();
?>