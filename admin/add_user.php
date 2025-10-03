<?php
// Enforce secure session settings
ini_set('session.cookie_secure', '0'); // Set to '0' for local testing; '1' for HTTPS
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

session_start();
require_once '../db_connect.php'; // Adjust path to your db_connect.php

// Role check: Ensure user is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
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

// Define colleges and departments
$colleges = [
    'College of Business and Economics' => ['Accounting and Finance', 'Economics', 'Management'],
    'College of Technology' => ['Computer Science'],
    'College of Agriculture and Natural Science' => ['Agri-Business', 'Agro-Forestry', 'Animal Science', 'Plant Science', 'Natural Resource Management'],
    'College of Social Sciences and Humanities' => ['Peace and Development']
];
// Flat list of departments for initial dropdown
$all_departments = [];
foreach ($colleges as $college => $departments) {
    $all_departments = array_merge($all_departments, $departments);
}
sort($all_departments);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password_plain = $_POST['password'];
    $role = $_POST['role'];
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $phone = trim($_POST['phone']);
    $sex = $_POST['sex'];
    $email = trim(strtolower($_POST['email']));
    $department = isset($_POST['department']) && !empty($_POST['department']) ? trim($_POST['department']) : null;
    $college = isset($_POST['college']) && !empty($_POST['college']) ? trim($_POST['college']) : null;

    // Input validation
    $errors = [];
    if (empty($username)) $errors[] = "Username is required.";
    if (empty($password_plain)) $errors[] = "Password is required.";
    elseif (strlen($password_plain) < 6) $errors[] = "Password must be at least 6 characters long.";
    if (empty($role)) $errors[] = "Role is required.";
    if (empty($fname)) $errors[] = "First Name is required.";
    if (empty($lname)) $errors[] = "Last Name is required.";
    if (empty($phone)) $errors[] = "Phone number is required.";
    elseif (!preg_match('/^[0-9]{9,15}$/', $phone)) $errors[] = "Invalid phone number format.";
    if (empty($sex)) $errors[] = "Sex is required.";
    if (empty($email)) $errors[] = "Email is required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format.";

    // Role-specific validation
    $service_roles = ['library_service', 'dormitory_service', 'students_food_service', 'hrm', 'finance', 'general_service'];
    if ($role === 'department_head' && empty($department)) {
        $errors[] = "Department is required for Department Head role.";
    } elseif ($role === 'college_dean' && empty($college)) {
        $errors[] = "College is required for College Dean role.";
    } elseif (($role === 'user' || $role === 'academic') && (empty($college) || empty($department))) {
        $errors[] = "Both College and Department are required for User/Student or Academic roles.";
    } elseif (in_array($role, $service_roles) && (!empty($college) || !empty($department))) {
        // Service roles should not have college/department
        $college = null;
        $department = null;
    }

    // Auto-assign college for department_head if not set
    if ($role === 'department_head' && !empty($department) && empty($college)) {
        $found_college = false;
        foreach ($colleges as $c_name => $depts) {
            if (in_array($department, $depts)) {
                $college = $c_name;
                $found_college = true;
                break;
            }
        }
        if (!$found_college) {
            $errors[] = "Could not determine college for the selected department.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
    } else {
        // Check for existing username or email
        $sql_check_exist = "SELECT id FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)";
        $stmt_check_exist = $db->prepare($sql_check_exist);
        if (!$stmt_check_exist) {
            $_SESSION['error'] = "Database error while checking existing user.";
            error_log("Failed to prepare check existing user statement: " . $db->error);
        } else {
            $stmt_check_exist->bind_param("ss", $username, $email);
            $stmt_check_exist->execute();
            $result_check_exist = $stmt_check_exist->get_result();

            if ($result_check_exist->num_rows > 0) {
                $_SESSION['error'] = "Username or Email already exists in the system.";
            } else {
                // Hash password
                $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);

                // Insert user
                $sql_insert = "INSERT INTO users (username, password, role, fname, lname, phone, sex, email, department, college, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                $stmt_insert = $db->prepare($sql_insert);
                if (!$stmt_insert) {
                    $_SESSION['error'] = "Database error while preparing user insertion.";
                    error_log("Failed to prepare user insert statement: " . $db->error);
                } else {
                    $stmt_insert->bind_param("ssssssssss", $username, $password_hashed, $role, $fname, $lname, $phone, $sex, $email, $department, $college);

                    if ($stmt_insert->execute()) {
                        $_SESSION['success'] = "User '" . htmlspecialchars($username) . "' added successfully.";
                        header("Location: manage_users.php");
                        exit;
                    } else {
                        $_SESSION['error'] = "Failed to add user. Database error.";
                        error_log("Add user failed for username '$username': " . $stmt_insert->error);
                    }
                    $stmt_insert->close();
                }
            }
            $stmt_check_exist->close();
        }
    }
    header("Location: add_user.php");
    exit;
}

// Current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Handle session messages
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
    <title>Add User | DMU Registrar Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #3f37c9;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --radius: 12px;
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
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

        .nav-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
        .nav-header .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .nav-header img { height: 40px; border-radius: 50%; }
        .nav-header .logo-text { font-size: 1.3rem; font-weight: 700; }
        .user-profile-mini { display: flex; align-items: center; gap: 10px; }
        .user-profile-mini i { font-size: 2.5rem; color: white; }
        .user-info h4 { font-size: 0.9rem; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        .user-info p { font-size: 0.8rem; opacity: 0.8; }
        .nav-menu { padding: 0 10px; }
        .nav-menu h3 { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; margin: 20px 10px 10px; opacity: 0.7; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: white; text-decoration: none; border-radius: var(--radius); margin-bottom: 5px; transition: var(--transition); }
        .nav-link:hover, .nav-link.active { background: rgba(255, 255, 255, 0.15); transform: translateX(5px); }
        .nav-link i { width: 20px; text-align: center; flex-shrink: 0; }
        .nav-link span { flex-grow: 1; }

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
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
            flex-wrap: wrap;
            gap: 10px;
            flex-shrink: 0;
        }
        .horizontal-nav .logo { font-weight: 600; font-size: 1.1rem; color: var(--primary-dark); }
        .horizontal-menu { display: flex; gap: 10px; flex-wrap: wrap; }
        .horizontal-menu a { color: var(--dark); text-decoration: none; padding: 8px 15px; border-radius: var(--radius); transition: var(--transition); font-weight: 500; display: inline-flex; align-items: center; gap: 5px; }
        .horizontal-menu a:hover { background: var(--light-gray); color: var(--primary); }
        .horizontal-menu a.active { background: var(--primary); color: white; }

        .container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px 40px;
            animation: fadeIn 0.6s ease-out;
            flex-grow: 1;
            margin-bottom: 20px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            position: relative;
            text-align: center;
            padding-bottom: 15px;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-weight: 500;
            border-left-width: 5px;
            border-left-style: solid;
            animation: slideDown 0.5s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert i { font-size: 1.2rem; margin-top: 2px; }
        .alert span { flex: 1; }
        .alert-success { background-color: rgba(40, 167, 69, 0.1); border-left-color: var(--success); color: #1c7430; }
        .alert-danger { background-color: rgba(220, 53, 69, 0.1); border-left-color: var(--danger); color: #a51c2c; }
        .alert-warning { background-color: rgba(255, 193, 7, 0.1); border-left-color: var(--warning); color: #b98900; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
        }
        .form-group label .required {
            color: var(--danger);
            font-weight: bold;
            margin-left: 4px;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            background-color: var(--light);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        .password-wrapper {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
            font-size: 1.1rem;
        }
        .password-toggle:hover {
            color: var(--primary);
        }

        .conditional-field {
            background-color: #f0f4ff;
            padding: 15px;
            border-radius: var(--radius);
            border: 1px dashed var(--primary-light);
            margin-top: 10px;
            transition: all 0.3s ease;
            grid-column: span 1;
            opacity: 1;
            max-height: 500px;
        }
        .conditional-field.hidden {
            opacity: 0;
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
            margin-top: 0;
            overflow: hidden;
            border: none;
        }
        .conditional-field label {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .submit-button-container {
            text-align: center;
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid var(--light-gray);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 600;
            transition: var(--transition);
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.2);
        }
        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.3);
        }
        .btn-submit i {
            margin-right: 8px;
        }

        footer {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            border-radius: var(--radius) var(--radius) 0 0;
            margin-top: auto;
            flex-shrink: 0;
        }
        .footer-content { max-width: 1200px; margin: 0 auto; }
        .group-name { font-weight: 600; font-size: 1.1rem; margin-bottom: 10px; }
        .social-links { display: flex; justify-content: center; gap: 20px; margin: 15px 0; }
        .social-links a { color: white; font-size: 1.5rem; transition: var(--transition); }
        .social-links a:hover { transform: translateY(-3px); color: var(--accent); }
        .copyright { font-size: 0.9rem; opacity: 0.8; }

        @media (max-width: 1200px) {
            .container { padding: 25px 30px; }
        }
        @media (max-width: 992px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); overflow-y: visible; }
            .main-content { max-height: none; padding: 15px; }
            .container { padding: 20px; }
            .form-grid { grid-template-columns: 1fr; }
            .conditional-field { grid-column: span 1; }
        }
        @media (max-width: 768px) {
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { justify-content: center; }
            .container { padding: 20px; }
            h2 { font-size: 1.6rem; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 10px; }
            .horizontal-nav .logo { display: none; }
            .horizontal-menu a { padding: 6px 10px; font-size: 0.9rem; }
            .container { padding: 10px; }
            h2 { font-size: 1.4rem; margin-bottom: 20px; }
            .form-group input, .form-group select { padding: 10px 12px; font-size: 0.95rem; }
            .btn-submit { padding: 10px 25px; font-size: 1rem; }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU RCS</span>
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
                <i class="fas fa-tachometer-alt fa-fw"></i><span>Overview</span>
            </a>
            <h3>User Management</h3>
            <a href="add_user.php" class="nav-link <?php echo $current_page == 'add_user.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-plus fa-fw"></i><span>Add User</span>
            </a>
            <a href="manage_users.php" class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users-cog fa-fw"></i><span>Manage Users</span>
            </a>
            <h3>Content Moderation</h3>
            <a href="manage_abusive_words.php" class="nav-link <?php echo $current_page == 'manage_abusive_words.php' ? 'active' : ''; ?>">
                <i class="fas fa-filter fa-fw"></i><span>Manage Abusive Words</span>
            </a>
            <a href="review_logs.php" class="nav-link <?php echo $current_page == 'review_logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-history fa-fw"></i><span>Review Logs</span>
            </a>
            <h3>System Management</h3>
            <a href="backup_restore.php" class="nav-link <?php echo $current_page == 'backup_restore.php' ? 'active' : ''; ?>">
                <i class="fas fa-database fa-fw"></i><span>Backup/Restore</span>
            </a>
            <h3>Account</h3>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i><span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Registrar Complaint System - Admin Panel</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Contact</a>
                <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About</a>
            </div>
        </nav>

        <div class="container">
            <h2><i class="fas fa-user-plus" style="margin-right: 10px;"></i> Add New User</h2>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i><span><?php echo nl2br(htmlspecialchars($error)); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($warning): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($warning); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="add_user.php" id="addUserForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="username">Username:<span class="required">*</span></label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:<span class="required">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" required minlength="6">
                            <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                        </div>
                        <small style="font-size: 0.8em; color: var(--gray); margin-top: 4px; display: block;">Minimum 6 characters.</small>
                    </div>
                    <div class="form-group">
                        <label for="fname">First Name:<span class="required">*</span></label>
                        <input type="text" id="fname" name="fname" required>
                    </div>
                    <div class="form-group">
                        <label for="lname">Last Name:<span class="required">*</span></label>
                        <input type="text" id="lname" name="lname" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email:<span class="required">*</span></label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone:<span class="required">*</span></label>
                        <input type="text" id="phone" name="phone" required placeholder="e.g., 0911223344">
                    </div>
                    <div class="form-group">
                        <label for="sex">Sex:<span class="required">*</span></label>
                        <select id="sex" name="sex" required>
                            <option value="" disabled selected>Select sex...</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="role">Assign Role:<span class="required">*</span></label>
                        <select id="role" name="role" required>
                            <option value="" disabled selected>Select a role...</option>
                            <option value="user">User (Student/Staff)</option>
                            <option value="handler">Handler</option>
                            <option value="admin">Admin</option>
                            <option value="sims">SIMS</option>
                            <option value="cost_sharing">Cost Sharing</option>
                            <option value="campus_registrar">Campus Registrar</option>
                           yler
                            <option value="university_registrar">University Registrar</option>
                            <option value="academic_vp">Academic Vice President</option>
                            <option value="president">President</option>
                            <option value="academic">Academic</option>
                            <option value="department_head">Department Head</option>
                            <option value="college_dean">College Dean</option>
                            <option value="administrative_vp">Administrative Vice President</option>
                            <option value="student_service_directorate">Student Service Directorate</option>
                            <option value="dormitory_service">Dormitory Service</option>
                            <option value="students_food_service">Students Food Service</option>
                            <option value="library_service">Library Service</option>
                            <option value="hrm">Human Resource Management</option>
                            <option value="finance">Finance</option>
                            <option value="general_service">General Service</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group conditional-field hidden" id="college-group">
                        <label for="college">College:<span id="college-required" class="required" style="display: none;">*</span></label>
                        <select id="college" name="college">
                            <option value="" disabled selected>Select college...</option>
                            <?php foreach ($colleges as $college_name => $departments): ?>
                                <option value="<?php echo htmlspecialchars($college_name); ?>"><?php echo htmlspecialchars($college_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small id="college-hint" style="font-size: 0.8em; color: var(--gray); margin-top: 4px; display: block;">Required for College Dean, User/Student, & Academic roles.</small>
                    </div>
                    <div class="form-group conditional-field hidden" id="department-group">
                        <label for="department">Department:<span id="department-required" class="required" style="display: none;">*</span></label>
                        <select id="department" name="department">
                            <option value="" disabled selected>Select department...</option>
                        </select>
                        <small id="department-hint" style="font-size: 0.8em; color: var(--gray); margin-top: 4px; display: block;">Required for Department Head, User/Student, & Academic roles.</small>
                    </div>
                </div>
                <div class="submit-button-container">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-plus-circle"></i> Add User
                    </button>
                </div>
            </form>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU  Complaint System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.getElementById('role');
            const collegeGroup = document.getElementById('college-group');
            const departmentGroup = document.getElementById('department-group');
            const collegeSelect = document.getElementById('college');
            const departmentSelect = document.getElementById('department');
            const collegeRequiredSpan = document.getElementById('college-required');
            const departmentRequiredSpan = document.getElementById('department-required');

            // Colleges and their departments from PHP
            const collegesData = <?php echo json_encode($colleges); ?>;

            function updateDepartments() {
                const selectedCollege = collegeSelect.value;
                departmentSelect.innerHTML = '<option value="" disabled selected>Select department...</option>';

                if (selectedCollege && collegesData[selectedCollege]) {
                    const departments = collegesData[selectedCollege];
                    departments.sort(); // Sort departments alphabetically
                    departments.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept;
                        option.textContent = dept;
                        departmentSelect.appendChild(option);
                    });
                }
            }

            function toggleFields() {
                if (!roleSelect) return;

                const role = roleSelect.value;
                const serviceRoles = ['library_service', 'dormitory_service', 'students_food_service', 'hrm', 'finance', 'general_service'];

                // Hide fields and reset requirements
                collegeGroup.classList.add('hidden');
                departmentGroup.classList.add('hidden');
                collegeSelect.required = false;
                departmentSelect.required = false;
                collegeRequiredSpan.style.display = 'none';
                departmentRequiredSpan.style.display = 'none';

                // Reset dropdowns
                collegeSelect.value = '';
                departmentSelect.innerHTML = '<option value="" disabled selected>Select department...</option>';

                if (role === 'department_head') {
                    collegeGroup.classList.remove('hidden');
                    departmentGroup.classList.remove('hidden');
                    departmentSelect.required = true;
                    departmentRequiredSpan.style.display = 'inline';
                    collegeSelect.required = false;
                    collegeRequiredSpan.style.display = 'none';
                } else if (role === 'college_dean') {
                    collegeGroup.classList.remove('hidden');
                    collegeSelect.required = true;
                    collegeRequiredSpan.style.display = 'inline';
                    departmentGroup.classList.add('hidden');
                    departmentSelect.required = false;
                    departmentRequiredSpan.style.display = 'none';
                } else if (role === 'user' || role === 'academic') {
                    collegeGroup.classList.remove('hidden');
                    departmentGroup.classList.remove('hidden');
                    collegeSelect.required = true;
                    departmentSelect.required = true;
                    collegeRequiredSpan.style.display = 'inline';
                    departmentRequiredSpan.style.display = 'inline';
                } else if (serviceRoles.includes(role)) {
                    collegeGroup.classList.add('hidden');
                    departmentGroup.classList.add('hidden');
                    collegeSelect.required = false;
                    departmentSelect.required = false;
                }
            }

            if (roleSelect) {
                roleSelect.addEventListener('change', toggleFields);
                toggleFields();
            }

            if (collegeSelect) {
                collegeSelect.addEventListener('change', updateDepartments);
            }

            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');

            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function () {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.classList.toggle('fa-eye');
                    this.classList.toggle('fa-eye-slash');
                });
            }
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>