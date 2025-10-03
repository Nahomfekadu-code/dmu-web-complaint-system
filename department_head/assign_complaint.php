<?php
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['handler', 'admin'])) {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];
$complaints = [];
$departments = [];

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch handler details
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
    } else {
        $_SESSION['error'] = "Handler details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
}

// Fetch open complaints assigned to the handler
$sql_complaints = "SELECT c.id, c.title, c.description, c.status, c.created_at, u.fname, u.lname 
                   FROM complaints c 
                   JOIN users u ON c.user_id = u.id 
                   WHERE c.handler_id = ? AND c.status IN ('pending', 'validated')";
$stmt_complaints = $db->prepare($sql_complaints);
if ($stmt_complaints) {
    $stmt_complaints->bind_param("i", $handler_id);
    $stmt_complaints->execute();
    $result = $stmt_complaints->get_result();
    while ($row = $result->fetch_assoc()) {
        // Fetch stereotypes for each complaint
        $sql_stereotypes = "
            SELECT s.label
            FROM complaint_stereotypes cs
            JOIN stereotypes s ON cs.stereotype_id = s.id
            WHERE cs.complaint_id = ?";
        $stmt_stereotypes = $db->prepare($sql_stereotypes);
        $stereotypes = [];
        if ($stmt_stereotypes) {
            $stmt_stereotypes->bind_param("i", $row['id']);
            $stmt_stereotypes->execute();
            $result_stereotypes = $stmt_stereotypes->get_result();
            while ($stereotype_row = $result_stereotypes->fetch_assoc()) {
                $stereotypes[] = $stereotype_row['label'];
            }
            $stmt_stereotypes->close();
        }
        $row['stereotypes'] = $stereotypes;
        $complaints[] = $row;
    }
    $stmt_complaints->close();
} else {
    error_log("Error preparing complaints query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaints. Please try again later.";
}

// Fetch departments and their heads
$sql_depts = "SELECT d.id, d.name, d.head_id, u.fname as head_fname, u.lname as head_lname 
              FROM departments d 
              LEFT JOIN users u ON d.head_id = u.id";
$result_depts = $db->query($sql_depts);
if ($result_depts) {
    while ($row = $result_depts->fetch_assoc()) {
        $departments[] = $row;
    }
} else {
    error_log("Error fetching departments: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching departments. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $handler_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Handle complaint assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign'])) {
    // Validate CSRF token
    $submitted_csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);
    if (!$submitted_csrf_token || $submitted_csrf_token !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid CSRF token. Please try again.";
        header("Location: assign_complaint.php");
        exit;
    }

    $complaint_id = (int)$_POST['complaint_id'];
    $department_id = (int)$_POST['department_id'];

    // Validate inputs
    if (!$complaint_id || !$department_id) {
        $_SESSION['error'] = "Invalid complaint or department selected.";
        header("Location: assign_complaint.php");
        exit;
    }

    // Fetch the department head
    $sql_head = "SELECT head_id FROM departments WHERE id = ?";
    $stmt_head = $db->prepare($sql_head);
    if (!$stmt_head) {
        error_log("Error preparing department head query: " . $db->error);
        $_SESSION['error'] = "An error occurred while fetching the department head.";
        header("Location: assign_complaint.php");
        exit;
    }
    $stmt_head->bind_param("i", $department_id);
    $stmt_head->execute();
    $result_head = $stmt_head->get_result();
    $head = $result_head->fetch_assoc();
    $stmt_head->close();

    if (!$head || !$head['head_id']) {
        $_SESSION['error'] = "No department head assigned to the selected department.";
        header("Location: assign_complaint.php");
        exit;
    }

    $head_id = $head['head_id'];

    $db->begin_transaction();
    try {
        // Update the complaint with the department and assigned head
        $sql_update = "UPDATE complaints 
                       SET department_id = ?, status = 'in_progress', updated_at = NOW() 
                       WHERE id = ? AND handler_id = ?";
        $stmt_update = $db->prepare($sql_update);
        if (!$stmt_update) {
            throw new Exception("An error occurred while assigning the complaint.");
        }
        $stmt_update->bind_param("iii", $department_id, $complaint_id, $handler_id);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to assign the complaint to the department.");
        }
        $stmt_update->close();

        // Create a notification for the department head
        $message = "A new complaint (ID: $complaint_id) has been assigned to you by {$handler['fname']} {$handler['lname']}.";
        $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) 
                       VALUES (?, ?, ?, 0, NOW())";
        $stmt_notify = $db->prepare($sql_notify);
        if (!$stmt_notify) {
            throw new Exception("An error occurred while notifying the department head.");
        }
        $stmt_notify->bind_param("iis", $head_id, $complaint_id, $message);
        $stmt_notify->execute();
        $stmt_notify->close();

        // Create an escalation record
        $sql_escalate = "INSERT INTO escalations (complaint_id, escalated_by_id, escalated_to_id, escalated_to, department_id, action_type, status, created_at, original_handler_id) 
                         VALUES (?, ?, ?, 'department_head', ?, 'assignment', 'pending', NOW(), ?)";
        $stmt_escalate = $db->prepare($sql_escalate);
        if (!$stmt_escalate) {
            throw new Exception("An error occurred while creating the escalation record.");
        }
        $stmt_escalate->bind_param("iiiii", $complaint_id, $handler_id, $head_id, $department_id, $handler_id);
        $stmt_escalate->execute();
        $stmt_escalate->close();

        // Optionally, send an email to the department head
        $sql_email = "SELECT email, fname, lname FROM users WHERE id = ?";
        $stmt_email = $db->prepare($sql_email);
        if ($stmt_email) {
            $stmt_email->bind_param("i", $head_id);
            $stmt_email->execute();
            $result_email = $stmt_email->get_result();
            $head_user = $result_email->fetch_assoc();
            $stmt_email->close();

            $to = $head_user['email'];
            $subject = "New Complaint Assigned to You";
            $body = "Dear {$head_user['fname']} {$head_user['lname']},\n\nA new complaint (ID: $complaint_id) has been assigned to you.\nDescription: {$_POST['description']}\nPlease review it at your earliest convenience.\n\nRegards,\nDMU Complaint System";
            $headers = "From: no-reply@dmucomplaintsystem.com";
            if (mail($to, $subject, $body, $headers)) {
                $message .= " Email notification sent.";
            } else {
                $message .= " Failed to send email notification.";
            }
        } else {
            error_log("Error preparing email query: " . $db->error);
            $message .= " Failed to fetch email for notification.";
        }

        $_SESSION['success'] = "Complaint assigned to department head successfully. $message";
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        error_log("Assignment error: " . $e->getMessage());
        $_SESSION['error'] = "Error assigning complaint: " . $e->getMessage();
    }

    header("Location: dashboard.php");
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
    <title>Assign Complaints | DMU Complaint System</title>
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

        .nav-link .badge {
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
.ma        }

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

        /* Table Styling */
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        tbody td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--light-gray);
            font-size: 0.95rem;
        }

        tbody tr:hover {
            background-color: var(--light);
        }

        /* Form Styling */
        .form-inline {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        select {
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
        }

        select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.85rem;
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

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav { width: 220px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; overflow-y: hidden; }
            .main-content { min-height: calc(100vh - HeightOfVerticalNav); }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            tbody td { font-size: 0.9rem; }
            .btn { width: 100%; margin-bottom: 5px; }
            .form-inline { flex-direction: column; align-items: stretch; }
            select { width: 100%; }
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
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $handler['role']))); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="assign_complaint.php" class="nav-link <?php echo $current_page == 'assign_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i>
                <span>Assign Complaints</span>
            </a>
            <a href="view_assigned.php" class="nav-link <?php echo $current_page == 'view_assigned.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>View Assigned Complaints</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to escalate from the dashboard.'); window.location.href='dashboard.php'; return false;">
                <i class="fas fa-arrow-up"></i>
                <span>Escalate Complaint</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="badge badge-danger"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a>

            <h3>Account</h3>
            <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                <i class="fas fa-key"></i>
                <span>Change Password</span>
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
                <span>DMU Complaint System - <?php echo htmlspecialchars(ucfirst($handler['role'])); ?></span>
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

        <div class="content-container">
            <h2>Assign Complaints to Department Heads</h2>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Complaint ID</th>
                            <th>Submitted By</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Stereotypes</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Assign to Department</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($complaints)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">No complaints to assign.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($complaint['id']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['description']); ?></td>
                                    <td><?php echo !empty($complaint['stereotypes']) ? htmlspecialchars(implode(', ', array_map('ucfirst', $complaint['stereotypes']))) : 'None'; ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></td>
                                    <td>
                                        <form method="post" class="form-inline" onsubmit="return confirm('Are you sure you want to assign this complaint to the selected department?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                                            <input type="hidden" name="description" value="<?php echo htmlspecialchars($complaint['description']); ?>">
                                            <select name="department_id" required>
                                                <option value="" disabled selected>Select Department</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>">
                                                        <?php echo htmlspecialchars($dept['name']) . " (Head: " . ($dept['head_fname'] ? $dept['head_fname'] . ' ' . $dept['head_lname'] : 'None') . ")"; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                    </td>
                                    <td>
                                            <button type="submit" name="assign" class="btn btn-primary"><i class="fas fa-check"></i> Assign</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>