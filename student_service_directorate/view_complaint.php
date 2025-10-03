<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$ssd = null;

// Fetch Student Service Directorate details
$sql_ssd = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_ssd = $db->prepare($sql_ssd);
if ($stmt_ssd) {
    $stmt_ssd->bind_param("i", $ssd_id);
    $stmt_ssd->execute();
    $result_ssd = $stmt_ssd->get_result();
    if ($result_ssd->num_rows > 0) {
        $ssd = $result_ssd->fetch_assoc();
    } else {
        $_SESSION['error'] = "Student Service Directorate details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_ssd->close();
} else {
    error_log("Error preparing Student Service Directorate query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

// Validate complaint_id
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
if (!$complaint_id || $complaint_id <= 0) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details
$complaint_query = "
    SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, c.resolution_details, c.resolution_date
    FROM complaints c
    WHERE c.id = ?";
$stmt_complaint = $db->prepare($complaint_query);
if (!$stmt_complaint) {
    error_log("Prepare failed for complaint fetch: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaint details.";
    header("Location: dashboard.php");
    exit;
}
$stmt_complaint->bind_param("i", $complaint_id);
$stmt_complaint->execute();
$complaint_result = $stmt_complaint->get_result();
if ($complaint_result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}
$complaint = $complaint_result->fetch_assoc();
$stmt_complaint->close();

// Fetch escalation history (only where Student Service Directorate is involved)
$escalations = [];
$escalation_query = "
    SELECT e.id, e.escalated_to, e.escalated_to_id, e.escalated_by_id, e.status as escalation_status, 
           e.resolution_details, e.resolved_at, e.created_at, e.action_type,
           u1.fname as escalated_by_fname, u1.lname as escalated_by_lname,
           u2.fname as escalated_to_fname, u2.lname as escalated_to_lname
    FROM escalations e
    LEFT JOIN users u1 ON e.escalated_by_id = u1.id
    LEFT JOIN users u2 ON e.escalated_to_id = u2.id
    WHERE e.complaint_id = ?
    AND e.escalated_to = 'student_service_directorate'
    AND e.escalated_to_id = ?
    ORDER BY e.created_at ASC";
$escalation_stmt = $db->prepare($escalation_query);
if ($escalation_stmt) {
    $escalation_stmt->bind_param("ii", $complaint_id, $ssd_id);
    $escalation_stmt->execute();
    $escalation_result = $escalation_stmt->get_result();
    while ($row = $escalation_result->fetch_assoc()) {
        $escalations[] = $row;
    }
    $escalation_stmt->close();
} else {
    error_log("Prepare failed for escalation fetch: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching escalation history.";
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $ssd_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint #<?php echo htmlspecialchars($complaint['id']); ?> | Student Service Directorate - DMU Complaint System</title>
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

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            margin-top: 1rem;
        }

        .complaint-details {
            background: var(--light);
            border: 1px solid var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.04);
        }

        .complaint-details h3 {
            margin-top: 0;
            margin-bottom: 1rem;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
            padding-bottom: 0.5rem;
        }

        .complaint-details p {
            margin: 0.6rem 0;
            line-height: 1.7;
        }

        .complaint-details strong {
            font-weight: 600;
            color: var(--dark);
            margin-right: 5px;
        }

        .escalation-history {
            margin-top: 2rem;
        }

        .escalation-history h3 {
            margin-bottom: 1.5rem;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary-light);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
            padding-left: 2rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0.5rem;
            width: 1rem;
            height: 1rem;
            background: var(--primary);
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px var(--primary-light);
        }

        .timeline-item h4 {
            font-size: 1.1rem;
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .timeline-item p {
            margin: 0.3rem 0;
            color: var(--gray);
        }

        .timeline-item p strong {
            color: var(--dark);
        }

        .resolution-details {
            background: #e6f4ea;
            border: 1px solid #badbcc;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-top: 2rem;
        }

        .resolution-details h3 {
            color: var(--success);
        }

        .form-actions {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-start;
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
            text-align: center;
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
            h3 { font-size: 1.2rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
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
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($ssd['fname'] . ' ' . $ssd['lname']); ?></h4>
                    <p>Student Service Directorate</p>
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

        <div class="content-container">
            <h2>View Complaint #<?php echo htmlspecialchars($complaint['id']); ?></h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <?php if (!empty($escalations)): ?>
                <div class="escalation-history">
                    <h3>Escalation History</h3>
                    <div class="timeline">
                        <?php foreach ($escalations as $escalation): ?>
                            <div class="timeline-item">
                                <h4>
                                    <?php
                                    echo htmlspecialchars($escalation['escalated_by_fname'] . ' ' . $escalation['escalated_by_lname']) . 
                                         ' assigned to ' . 
                                         htmlspecialchars($escalation['escalated_to_fname'] . ' ' . $escalation['escalated_to_lname']) . 
                                         ' (' . ucfirst(str_replace('_', ' ', $escalation['escalated_to'])) . ')';
                                    ?>
                                </h4>
                                <p><strong>Date:</strong> <?php echo date('M j, Y H:i', strtotime($escalation['created_at'])); ?></p>
                                <p><strong>Action Type:</strong> <?php echo ucfirst(htmlspecialchars($escalation['action_type'])); ?></p>
                                <p><strong>Status:</strong> <?php echo ucfirst(htmlspecialchars($escalation['escalation_status'])); ?></p>
                                <?php if ($escalation['resolution_details']): ?>
                                    <p><strong>Resolution Details:</strong> <?php echo htmlspecialchars($escalation['resolution_details']); ?></p>
                                <?php endif; ?>
                                <?php if ($escalation['resolved_at']): ?>
                                    <p><strong>Resolved On:</strong> <?php echo date('M j, Y H:i', strtotime($escalation['resolved_at'])); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> No escalation history found for this complaint involving the Student Service Directorate.</div>
            <?php endif; ?>

            <?php if ($complaint['status'] === 'resolved' && $complaint['resolution_details']): ?>
                <div class="resolution-details">
                    <h3>Resolution</h3>
                    <p><strong>Resolution Details:</strong> <?php echo htmlspecialchars($complaint['resolution_details']); ?></p>
                    <p><strong>Resolved On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['resolution_date'])); ?></p>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <?php if (isset($_GET['from']) && $_GET['from'] === 'resolved'): ?>
                    <a href="view_resolved.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Resolved Complaints</a>
                <?php else: ?>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <?php endif; ?>
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
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>