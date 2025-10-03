<?php
// Ensure session cookie is accessible across paths
session_set_cookie_params(0, '/', "", false, true);
session_start();
require_once 'db_connect.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize error and success messages
$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error']);
unset($_SESSION['success']);

// Role Definitions and Dashboard Paths
$dashboard_paths = [
    'user' => 'user/dashboard.php',
    'handler' => 'handler/dashboard.php',
    'admin' => 'admin/dashboard.php',
    'sims' => 'sims/dashboard.php',
    'cost_sharing' => 'cost_sharing/dashboard.php',
    'campus_registrar' => 'campus_registrar/dashboard.php',
    'university_registrar' => 'university_registrar/dashboard.php',
    'academic_vp' => 'academic_vp/dashboard.php',
    'president' => 'president/dashboard.php',
    'academic' => 'academic/dashboard.php',
    'department_head' => 'department_head/dashboard.php',
    'college_dean' => 'college_dean/dashboard.php',
    'administrative_vp' => 'administrative_vp/dashboard.php',
    'student_service_directorate' => 'student_service_directorate/dashboard.php',
    'dormitory_service' => 'dormitory_service/dashboard.php',
    'students_food_service' => 'students_food_service/dashboard.php',
    'library_service' => 'library_service/library_service_dashboard.php',
    'hrm' => 'hrm/dashboard.php',
    'finance' => 'finance/dashboard.php',
    'general_service' => 'general_service/dashboard.php'
];

// Redirect Logged-in Users
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    if (isset($dashboard_paths[$role])) {
        header("Location: " . $dashboard_paths[$role]);
        exit;
    } else {
        $error = "Invalid user role detected ($role). Please log in again.";
        error_log("Invalid role detected for user in session: {$_SESSION['username']} (role: $role)");
        session_unset();
        session_destroy();
        session_start();
        session_set_cookie_params(0, '/', "", false, true);
        $_SESSION['error'] = $error;
        header("Location: login.php");
        exit;
    }
}

// Handle Login Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        try {
            $sql = "SELECT id, username, password, role, status, suspended_until FROM users WHERE username = ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                $error = "Database error during login preparation. Please try again later.";
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();

                if ($user) {
                    // Check account status
                    if ($user['status'] === 'blocked') {
                        $error = "Your account is currently blocked. Please contact support.";
                    } elseif ($user['status'] === 'suspended' && $user['suspended_until'] !== null) {
                        try {
                            $current_time = new DateTimeImmutable();
                            $suspended_until = new DateTimeImmutable($user['suspended_until']);

                            if ($current_time < $suspended_until) {
                                $remaining_time = $current_time->diff($suspended_until);
                                $remaining_minutes = ($remaining_time->days * 24 * 60) + ($remaining_time->h * 60) + $remaining_time->i;
                                if ($remaining_minutes < 1) $remaining_minutes = 1;
                                $error = "Account suspended. Please try again in approximately $remaining_minutes minute(s).";
                            } else {
                                // Reactivate user if suspension expired
                                $sql_reactivate = "UPDATE users SET status = 'active', suspended_until = NULL WHERE id = ?";
                                $stmt_reactivate = $db->prepare($sql_reactivate);
                                if ($stmt_reactivate) {
                                    $stmt_reactivate->bind_param("i", $user['id']);
                                    $stmt_reactivate->execute();
                                    $stmt_reactivate->close();
                                    $user['status'] = 'active';
                                    $user['suspended_until'] = null;
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Error processing suspension date: " . $e->getMessage());
                            $error = "An error occurred checking account status. Please try again.";
                        }
                    }

                    // Verify password if no error and account is active
                    if (!$error && $user['status'] === 'active' && password_verify($password, $user['password'])) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['login_time'] = time();

                        $role = strtolower($user['role']);
                        if (isset($dashboard_paths[$role])) {
                            header("Location: " . $dashboard_paths[$role]);
                            exit;
                        } else {
                            $error = "Login successful, but your role ('$role') dashboard is not configured. Please contact the administrator.";
                            session_unset();
                            session_destroy();
                            session_start();
                            session_set_cookie_params(0, '/', "", false, true);
                            $_SESSION['error'] = $error;
                            header("Location: login.php");
                            exit;
                        }
                    } else if (!$error) {
                        $error = "Invalid username or password.";
                    }
                } else {
                    $error = "Invalid username or password.";
                }
            }
        } catch (Exception $e) {
            $error = "An unexpected error occurred during login. Please try again later.";
        }
    }

    if ($error) {
        $_SESSION['error'] = $error;
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Original Header and Footer Styles */
        :root {
            --primary: #4A90E2;
            --primary-dark: #3A7BCD;
            --secondary: #50E3C2;
            --accent: #F5A623;
            --light: #f8f9fa;
            --dark: #2c3e50;
            --grey: #7f8c8d;
            --white: #ffffff;
            --card-bg-index: rgba(255, 255, 255, 0.5);
            --radius: 15px;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.07);
            --shadow-hover: 0 15px 45px rgba(0, 0, 0, 0.12);
            --transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            --transition-smooth: all 0.35s ease-in-out;
            --glass-border: rgba(255, 255, 255, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            color: var(--dark);
            line-height: 1.7;
            background-color: var(--light);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Original Top Navigation */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            padding: 15px 0;
            transition: padding 0.3s ease-in-out, background-color 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
        }

        .top-nav.scrolled {
            padding: 10px 0;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
        }

        .nav-container {
            width: 100%;
            max-width: 1250px;
            margin: 0 auto;
            padding: 0 25px;
            display: flex;
            justify-content: space-between;
            align-items: left;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-left: 0;
            align-self: left;
        }

        .logo img {
            height: 50px;
            transition: height 0.3s ease-in-out;
            border-radius: 50%;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }

        .top-nav.scrolled .logo img {
            height: 40px;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            transition: font-size 0.3s ease-in-out;
        }

        .top-nav.scrolled .logo-text {
            font-size: 1.3rem;
        }

        .main-nav ul {
            list-style: none;
            display: flex;
            gap: 25px;
            padding-left: 0;
            justify-content: flex-end;
            flex-wrap: no;
        }

        .main-nav a {
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 18px;
            border-radius: 8px;
            transition: var(--transition-smooth);
            position: relative;
            font-size: 1rem;
            display: inline-block;
        }

        .main-nav a::after {
            content: '';
            position: absolute;
            bottom: 0px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 0.3s ease-out;
            border-radius: 2px;
        }

        .main-nav a:hover::after {
            width: 60%;
        }

        .main-nav a:hover {
            color: var(--primary);
            background-color: rgba(74, 144, 226, 0.08);
        }

        .main-nav a.nav-button {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 5px 18px rgba(74, 144, 226, 0.3);
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 600;
        }

        .main-nav a.nav-button:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            transform: translateY(-3px) scale(1.03);
            box-shadow: 0 8px 25px rgba(74, 144, 226, 0.45);
            background-color: transparent;
        }

        .main-nav a.nav-button::after {
            display: none;
        }

        /* Main Content Area - Using the enhanced login form */
        .main-content {
            flex-grow: 1;
            padding-top: 120px;
            padding-bottom: 60px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: url('https://source.unsplash.com/random/1920x1080/?university,campus') no-repeat center center;
            background-size: cover;
            position: relative;
        }

        .main-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.85) 0%, rgba(118, 75, 162, 0.85) 100%);
            z-index: 0;
        }

        /* Enhanced Login Container Styles */
        .login-container {
            width: 100%;
            max-width: 450px;
            perspective: 1000px;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.18);
            transform-style: preserve-3d;
            transition: all 0.3s ease;
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            overflow: hidden;
        }

        .login-card:hover {
            transform: translateY(-5px) rotateX(5deg);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                to bottom right,
                rgba(255, 255, 255, 0.1) 0%,
                rgba(255, 255, 255, 0) 50%,
                rgba(255, 255, 255, 0.1) 100%
            );
            transform: rotate(30deg);
            z-index: -1;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .login-logo {
            height: 80px;
            width: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
            transition: all 0.5s cubic-bezier(0.68, -0.6, 0.32, 1.6);
        }

        .login-logo:hover {
            transform: scale(1.1) rotate(10deg);
        }

        .login-header h2 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .login-header p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.95rem;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--white);
            font-size: 0.9rem;
        }

        .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 14px 20px 14px 50px;
            border: none;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            color: var(--white);
            font-size: 1rem;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.2);
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.1rem;
        }

        .toggle-password {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            font-size: 1.1rem;
            transition: all 0.15s ease;
        }

        .toggle-password:hover {
            color: var(--white);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px 25px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.5s cubic-bezier(0.68, -0.6, 0.32, 1.6);
            text-decoration: none;
            width: 100%;
            margin-top: 10px;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .btn i {
            font-size: 1.1em;
        }

        .btn-primary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: -1;
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .btn-primary:hover::before {
            opacity: 1;
        }

        .btn-primary:active {
            transform: translateY(1px) scale(0.98);
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            font-size: 0.95rem;
            animation: fadeIn 0.5s ease-out;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert i {
            font-size: 1.2rem;
        }

        .alert-success {
            background: rgba(74, 222, 128, 0.2);
            color: var(--white);
            border-color: rgba(74, 222, 128, 0.3);
        }

        .alert-danger {
            background: rgba(248, 113, 113, 0.2);
            color: var(--white);
            border-color: rgba(248, 113, 113, 0.3);
        }

        /* Extra Links */
        .extra-links {
            text-align: center;
            margin-top: 25px;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .extra-links a {
            color: var(--white);
            font-weight: 500;
            text-decoration: none;
            transition: all 0.15s ease;
            position: relative;
            padding-bottom: 2px;
        }

        .extra-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 1px;
            background: var(--white);
            transition: all 0.3s ease;
        }

        .extra-links a:hover::after {
            width: 100%;
        }

        .extra-links .forgot-password-link {
            display: block;
            margin-bottom: 15px;
        }

        .extra-links hr {
            border: none;
            height: 1px;
            background: rgba(255, 255, 255, 0.2);
            margin: 20px auto;
            width: 70%;
        }

        /* Original Footer Styles */
        footer {
            background-color: var(--dark);
            color: rgba(255, 255, 255, 0.85);
            padding: 60px 25px;
            text-align: center;
            position: relative;
            margin-top: auto;
            flex-shrink: 0;
        }

        .footer-container {
            max-width: 1250px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .footer-links {
            margin-bottom: 45px;
        }

        .footer-links a {
            color: var(--secondary);
            text-decoration: none;
            margin: 0 20px;
            transition: color 0.3s ease, transform 0.3s ease;
            position: relative;
            padding-bottom: 8px;
            font-weight: 500;
            display: inline-block;
        }

        .footer-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--secondary);
            transition: width 0.35s ease-out;
            border-radius: 1px;
        }

        .footer-links a:hover {
            color: var(--white);
            transform: translateY(-3px);
        }

        .footer-links a:hover::after {
            width: 100%;
        }

        .social-links {
            margin: 45px 0;
        }

        .social-links a {
            color: rgba(255, 255, 255, 0.75);
            font-size: 1.8rem;
            margin: 0 18px;
            transition: var(--transition-smooth);
            display: inline-block;
        }

        .social-links a:hover {
            color: var(--secondary);
            transform: translateY(-6px) scale(1.25) rotate(5deg);
            filter: drop-shadow(0 4px 8px rgba(80, 227, 194, 0.3));
        }

        .copyright {
            font-size: 0.95rem;
            color: var(--grey);
            margin-top: 40px;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translate3d(0, 30px, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .nav-container { padding: 0 20px; }
            .main-content { padding-top: 110px; padding-bottom: 50px; }
            .footer-links a { margin: 0 15px; }
            footer { padding: 50px 20px; }
        }

        @media (max-width: 768px) {
            .top-nav { padding: 12px 0; }
            .top-nav.scrolled { padding: 8px 0; }
            .nav-container { padding: 0 15px; }
            .logo { gap: 8px; }
            .logo img { height: 40px; }
            .top-nav.scrolled .logo img { height: 35px; }
            .logo-text { font-size: 1.3rem; }
            .top-nav.scrolled .logo-text { font-size: 1.2rem; }
            .main-nav { margin-top: 0; width: auto; }
            .main-nav ul { justify-content: flex-end; gap: 15px; }
            .main-nav a { font-size: 0.95rem; padding: 8px 12px;}
            .main-nav a.nav-button { padding: 8px 20px;}
            .main-content { padding-top: 90px; padding-bottom: 40px; }
            .login-card { padding: 30px 25px; }
            .login-header h2 { font-size: 1.6rem; }
            .login-header p { font-size: 0.9rem; }
            .btn { padding: 12px 20px; font-size: 0.95rem; }
            .footer-links a { margin: 0 10px; font-size: 0.95rem;}
            .social-links a { margin: 0 12px; font-size: 1.6rem;}
            footer { padding: 40px 15px;}
        }

        @media (max-width: 480px) {
            .main-content { padding-top: 80px; padding-left: 15px; padding-right: 15px; }
            .login-logo { height: 70px; width: 70px; }
            .login-header h2 { font-size: 1.5rem; }
            .footer-links a { display: block; margin: 12px auto; }
            .social-links { margin: 35px 0;}
            .social-links a { margin: 0 10px; font-size: 1.5rem;}
            footer { padding: 35px 15px;}
        }
    </style>
</head>
<body>
    <!-- Original Top Navigation -->
    <nav class="top-nav" id="topNav">
        <div class="nav-container">
            <div class="logo">
                <img src="images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DEBRE MARKOS UNIVERSITY COMPLAINT MANAGEMENT SYSTEM</span>
            </div>
            <div class="main-nav">
                <ul>
                    <li><a href="index.php">Home</a></li>
                   
                    <li><a href="contact.php">Contact</a></li>
                    <li><a href="help.php">Help</a></li>
                    
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content Area with Enhanced Login Form -->
    <main class="main-content">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <img src="images/logo.jpg" alt="DMU Logo" class="login-logo">
                    <h2>Account Login</h2>
                    <p>DMU Complaint System</p>
                </div>

                <!-- Error/Success Messages -->
                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="login.php" id="loginForm" novalidate>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <input type="text" id="username" name="username" required placeholder="Enter your username" autocomplete="username">
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                            <span class="input-icon"><i class="fas fa-lock"></i></span>
                            <span class="toggle-password" title="Show/Hide password" tabindex="0" role="button" aria-pressed="false">
                                <i class="fas fa-eye" id="togglePasswordIcon"></i>
                            </span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Log In
                    </button>
                </form>

                <!-- Extra Links -->
                <div class="extra-links">
                    <a href="forgot_password.php" class="forgot-password-link">Forgot Password?</a>
                    <hr>
                    Don't have an account? <a href="user/create_account.php">Register Here</a>
                </div>
            </div>
        </div>
    </main>

    <!-- Original Footer -->
    <footer>
        <div class="footer-container">
            <div class="footer-links">
                <a href="index.php">Home</a>
              
                <a href="about.php">About</a>
                <a href="contact.php">Contact</a>
                <a href="help.php">Help</a>
                <a href="#">FAQ</a>
            </div>
            <div class="social-links">
                <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" title="Telegram"><i class="fab fa-telegram-plane"></i></a>
                <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
            </div>
            <div class="copyright">
                © <?php echo date("Y"); ?> DMU Complaint Management System - Group 4 Project. All rights reserved.
            </div>
        </div>
    </footer>

    <!-- JavaScript -->
    <script>
        // Navbar scroll effect
        document.addEventListener('DOMContentLoaded', function() {
            const navbar = document.getElementById('topNav');
            
            window.addEventListener('scroll', function() {
                if (window.scrollY > 60) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            });
            
            // Password visibility toggle
            const togglePassword = document.querySelector('.toggle-password');
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('togglePasswordIcon');
            
            if (togglePassword && passwordInput && toggleIcon) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    toggleIcon.classList.toggle('fa-eye');
                    toggleIcon.classList.toggle('fa-eye-slash');
                    this.setAttribute('aria-pressed', type === 'text');
                });
                
                togglePassword.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });
            }
            
            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'all 0.5s ease';
                    alert.style.opacity = '0';
                    alert.style.height = '0';
                    alert.style.margin = '0';
                    alert.style.padding = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000);
            });
            
            // Form validation
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', function(e) {
                    const username = document.getElementById('username');
                    const password = document.getElementById('password');
                    
                    if (!username.value.trim()) {
                        e.preventDefault();
                        username.focus();
                        return false;
                    }
                    
                    if (!password.value.trim()) {
                        e.preventDefault();
                        password.focus();
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
</body>
</html>
<?php
// Close the database connection if it's open
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>