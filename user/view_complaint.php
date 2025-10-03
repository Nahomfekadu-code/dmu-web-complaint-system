<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../login.php");
    exit;
}

// Check if complaint ID is provided in the URL
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details with user and handler information using a prepared statement
$stmt = $db->prepare("
    SELECT 
        c.*,
        u_submitter.fname AS submitter_fname,
        u_submitter.lname AS submitter_lname,
        u_handler.fname AS handler_fname,
        u_handler.lname AS handler_lname
    FROM complaints c 
    JOIN users u_submitter ON c.user_id = u_submitter.id 
    LEFT JOIN users u_handler ON c.handler_id = u_handler.id 
    WHERE c.id = ?
");
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$stmt->close();

// Fetch escalation history with action_type
$stmt_escalations = $db->prepare("
    SELECT 
        e.*,
        u_escalator.fname AS escalator_fname,
        u_escalator.lname AS escalator_lname
    FROM escalations e
    JOIN users u_escalator ON e.escalated_by = u_escalator.id
    WHERE e.complaint_id = ?
    ORDER BY e.created_at ASC
");
$stmt_escalations->bind_param("i", $complaint_id);
$stmt_escalations->execute();
$escalations_result = $stmt_escalations->get_result();
$escalations = $escalations_result->fetch_all(MYSQLI_ASSOC);
$stmt_escalations->close();

// Determine if the complaint can be assigned or resolved
$can_assign = false;
$can_resolve = false;

if ($complaint['status'] === 'validated') {
    // Check if there is a pending assignment
    $has_pending_assignment = false;
    foreach ($escalations as $escalation) {
        if ($escalation['action_type'] === 'assignment' && $escalation['status'] === 'pending') {
            $has_pending_assignment = true;
            break;
        }
    }
    if (!$has_pending_assignment) {
        $can_assign = true;
    }
}

if ($complaint['status'] === 'in_progress') {
    // Check if the latest escalation is resolved
    $latest_escalation = end($escalations);
    if ($latest_escalation && $latest_escalation['status'] === 'resolved') {
        $can_resolve = true;
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
    <title>Complaint Details | DMU Complaint System</title>
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

        .details-container {
            padding: 20px;
            background: var(--light);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
        }
        .detail-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .detail-label {
            font-weight: 600;
            color: var(--primary-dark);
            min-width: 150px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .detail-label i { color: var(--primary); }
        .detail-value { flex: 1; color: var(--text-color); }

        .status {
            padding: 5px 10px; border-radius: 15px; font-size: 0.75rem;
            font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
            color: #fff; text-align: center; display: inline-block; line-height: 1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .status-resolved { background-color: var(--success); }
        .status-pending { background-color: var(--warning); color: var(--dark); }
        .status-validated { background-color: var(--info); }
        .status-in_progress { background-color: var(--primary); }
        .status-rejected { background-color: var(--danger); }
        .status-assigned { background-color: var(--orange); }
        .status-escalated { background-color: var(--orange); }

        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 500;
            cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5;
        }
        .btn i { font-size: 1em; }
        .btn-small { padding: 5px 10px; font-size: 0.8rem; gap: 5px; }
        .btn-info { background-color: var(--info); color: #fff; }
        .btn-info:hover { background-color: #0baccc; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(13,202,240,0.3);}
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c21d2c; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(220,53,69,0.3); }
        .btn-warning { background-color: var(--warning); color: var(--dark); }
        .btn-warning:hover { background-color: #e0a800; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(255,193,7,0.3); }
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
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }

        .escalation-list { list-style: none; padding: 0; margin: 0; }
        .escalation-list li {
            padding: 15px; background: var(--card-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius); margin-bottom: 10px; font-size: 0.9rem; line-height: 1.5;
        }
        .escalation-list li:last-child { margin-bottom: 0; }

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
            .top-nav-right { padding-bottom: 10px; width: 100%; justify-content: flex-end;}
            .main-content { padding: 15px; padding-top: 120px; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .detail-item { flex-direction: column; gap: 5px; }
            .detail-label { min-width: 100%; }
            .escalation-list li { padding: 10px; font-size: 0.85rem; }
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
            <span class="page-title">Complaint Details</span>
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
            <h2>Complaint Details</h2>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> Complaint #<?php echo $complaint['id']; ?>
            </div>
            <div class="card-body">
                <div class="details-container">
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-id-badge"></i> Complaint ID:</div>
                        <div class="detail-value">#<?php echo $complaint['id']; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-heading"></i> Title:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($complaint['title']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-align-left"></i> Description:</div>
                        <div class="detail-value"><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-user"></i> Submitted By:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-user-tie"></i> Assigned Handler:</div>
                        <div class="detail-value">
                            <?php echo $complaint['handler_fname'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname']) : '<span style="color: var(--text-muted);">Not assigned</span>'; ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-calendar-alt"></i> Submission Date:</div>
                        <div class="detail-value"><?php echo date('M j, Y, g:i A', strtotime($complaint['created_at'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-info-circle"></i> Status:</div>
                        <div class="detail-value">
                            <span class="status status-<?php echo strtolower(htmlspecialchars($complaint['status'])); ?>">
                                <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-tag"></i> Category:</div>
                        <div class="detail-value"><?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-eye"></i> Visibility:</div>
                        <div class="detail-value"><?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label"><i class="fas fa-video"></i> Needs Video Chat:</div>
                        <div class="detail-value"><?php echo $complaint['needs_video_chat'] ? 'Yes' : 'No'; ?></div>
                    </div>
                    <?php if ($complaint['evidence_file']): ?>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-file-alt"></i> Evidence File:</div>
                            <div class="detail-value">
                                <a href="../uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank" class="btn btn-info btn-small">
                                    <i class="fas fa-download"></i> View/Download
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php
                    // Fetch resolution details from the escalations table if the complaint is resolved
                    $resolution_details = null;
                    $resolution_date = null;
                    $resolution_action_type = null;
                    foreach ($escalations as $escalation) {
                        if ($escalation['status'] === 'resolved' && $escalation['resolution_details']) {
                            $resolution_details = $escalation['resolution_details'];
                            $resolution_date = $escalation['resolved_at']; // Use resolved_at instead of updated_at
                            $resolution_action_type = $escalation['action_type'];
                            break;
                        }
                    }
                    if ($complaint['status'] === 'resolved' && $resolution_details && $resolution_date):
                    ?>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-check-circle"></i> Resolution Details:</div>
                            <div class="detail-value">
                                <p><strong>Resolved On:</strong> <?php echo $resolution_date ? date('M j, Y, g:i A', strtotime($resolution_date)) : 'N/A'; ?></p>
                                <p><strong>Action Type:</strong> <?php echo htmlspecialchars(ucfirst($resolution_action_type)); ?></p>
                                <p><strong>Details:</strong> <?php echo nl2br(htmlspecialchars($resolution_details)); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($escalations)): ?>
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-level-up-alt"></i> Assignment/Escalation History:</div>
                            <div class="detail-value">
                                <ul class="escalation-list">
                                    <?php foreach ($escalations as $escalation): ?>
                                        <li>
                                            <strong>Action Type:</strong> <?php echo htmlspecialchars(ucfirst($escalation['action_type'])); ?><br>
                                            <strong><?php echo $escalation['action_type'] === 'assignment' ? 'Assigned To' : 'Escalated To'; ?>:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $escalation['escalated_to']))); ?><br>
                                            <strong>By:</strong> <?php echo htmlspecialchars($escalation['escalator_fname'] . ' ' . $escalation['escalator_lname']); ?><br>
                                            <strong>On:</strong> <?php echo date('M j, Y, g:i A', strtotime($escalation['created_at'])); ?><br>
                                            <strong>Status:</strong> 
                                            <span class="status status-<?php echo $escalation['action_type'] === 'assignment' ? 'assigned' : 'escalated'; ?>">
                                                <?php echo htmlspecialchars(ucfirst($escalation['status'])); ?>
                                            </span><br>
                                            <?php if ($escalation['resolution_details']): ?>
                                                <strong>Resolution Details:</strong> <?php echo nl2br(htmlspecialchars($escalation['resolution_details'])); ?><br>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="text-align: center; margin-top: 2rem;">
                    <a href="view_assigned_complaints.php" class="btn btn-info">
                        <i class="fas fa-arrow-left"></i> Back to Assigned Complaints
                    </a>
                    <?php if ($can_assign): ?>
                        <a href="assign_complaint_to_authority.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-warning">
                            <i class="fas fa-level-up-alt"></i> Assign to Responsible Body
                        </a>
                    <?php endif; ?>
                    <?php if ($can_resolve): ?>
                        <a href="resolve_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Resolve
                        </a>
                    <?php endif; ?>
                </div>
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