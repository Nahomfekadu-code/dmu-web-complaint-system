<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is 'cost_sharing'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
if ($_SESSION['role'] !== 'cost_sharing') {
    header("Location: ../unauthorized.php");
    exit;
}

$cost_sharing_id = $_SESSION['user_id'];
$cost_sharing_user = null;

// Fetch Cost Sharing user details
$sql_cost_sharing = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_cost_sharing = $db->prepare($sql_cost_sharing);
if ($stmt_cost_sharing) {
    $stmt_cost_sharing->bind_param("i", $cost_sharing_id);
    $stmt_cost_sharing->execute();
    $result_cost_sharing = $stmt_cost_sharing->get_result();
    if ($result_cost_sharing->num_rows > 0) {
        $cost_sharing_user = $result_cost_sharing->fetch_assoc();
    } else {
        $_SESSION['error'] = "Cost Sharing user details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_cost_sharing->close();
} else {
    error_log("Error preparing Cost Sharing query: " . $db->error);
    $_SESSION['error'] = "Database error fetching Cost Sharing user details.";
}

// Fetch complaint details
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
$complaint = null;
if (!$complaint_id || $complaint_id <= 0) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$sql_complaint = "SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname, e.status as escalation_status, e.escalated_to, e.escalated_to_id 
                 FROM complaints c 
                 LEFT JOIN users u ON c.user_id = u.id 
                 LEFT JOIN escalations e ON c.id = e.complaint_id 
                 WHERE c.id = ? AND e.escalated_to = 'cost_sharing' AND e.escalated_to_id = ?";
$stmt_complaint = $db->prepare($sql_complaint);
if ($stmt_complaint) {
    $stmt_complaint->bind_param("ii", $complaint_id, $cost_sharing_id);
    $stmt_complaint->execute();
    $result_complaint = $stmt_complaint->get_result();
    if ($result_complaint->num_rows > 0) {
        $complaint = $result_complaint->fetch_assoc();
        // Fetch stereotypes
        $sql_stereotypes = "SELECT s.label FROM complaint_stereotypes cs JOIN stereotypes s ON cs.stereotype_id = s.id WHERE cs.complaint_id = ?";
        $stmt_stereotypes = $db->prepare($sql_stereotypes);
        $stmt_stereotypes->bind_param("i", $complaint_id);
        $stmt_stereotypes->execute();
        $result_stereotypes = $stmt_stereotypes->get_result();
        $complaint['stereotypes'] = $result_stereotypes->fetch_all(MYSQLI_ASSOC);
        $stmt_stereotypes->close();
    } else {
        $_SESSION['error'] = "Complaint not found or you do not have access to it.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_complaint->close();
} else {
    error_log("Error preparing complaint query: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaint details.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint history (decisions)
$sql_decisions = "SELECT d.*, u_sender.fname as sender_fname, u_sender.lname as sender_lname, u_receiver.fname as receiver_fname, u_receiver.lname as receiver_lname 
                 FROM decisions d 
                 LEFT JOIN users u_sender ON d.sender_id = u_sender.id 
                 LEFT JOIN users u_receiver ON d.receiver_id = u_receiver.id 
                 WHERE d.complaint_id = ? 
                 ORDER BY d.created_at DESC";
$stmt_decisions = $db->prepare($sql_decisions);
$decisions = [];
if ($stmt_decisions) {
    $stmt_decisions->bind_param("i", $complaint_id);
    $stmt_decisions->execute();
    $decisions = $stmt_decisions->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_decisions->close();
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
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
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.15);
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
            transition: var(--transition);
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
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
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
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
            transition: var(--transition);
        }

        .horizontal-nav:hover {
            box-shadow: var(--shadow-hover);
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
            transform: scale(1.05);
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .alert::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--success);
        }

        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-success::before { background: var(--success); }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-danger::before { background: var(--danger); }
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }
        .alert-warning::before { background: var(--warning); }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }
        .alert-info::before { background: var(--info); }

        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
            flex-grow: 1;
            transition: var(--transition);
        }

        .content-container:hover {
            box-shadow: var(--shadow-hover);
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

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
            display: inline-block;
        }

        .complaint-details {
            background: linear-gradient(145deg, #f9f9f9, #e9ecef);
            border-radius: var(--radius);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .complaint-details:hover {
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .complaint-details p {
            margin: 10px 0;
            font-size: 0.95rem;
        }

        .complaint-details p strong {
            color: var(--primary-dark);
        }

        .status {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
            transition: var(--transition);
        }

        .status:hover {
            transform: scale(1.05);
        }

        .status-pending { background-color: rgba(255, 193, 7, 0.15); color: var(--warning); }
        .status-pending_more_info { background-color: rgba(23, 162, 184, 0.15); color: var(--info); }
        .status-validated { background-color: rgba(13, 202, 240, 0.15); color: var(--info); }
        .status-in_progress { background-color: rgba(23, 162, 184, 0.15); color: var(--info); }
        .status-resolved { background-color: rgba(40, 167, 69, 0.15); color: var(--success); }
        .status-rejected { background-color: rgba(220, 53, 69, 0.15); color: var(--danger); }
        .status-assigned { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-escalated { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-uncategorized { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
        }

        th, td {
            padding: 1.2rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        th {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th:first-child {
            border-top-left-radius: var(--radius);
        }

        th:last-child {
            border-top-right-radius: var(--radius);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background: #e8f4ff;
            transform: scale(1.01);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            background: linear-gradient(145deg, #f9f9f9, #e9ecef);
            border-radius: var(--radius);
            margin-top: 1rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

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

        @media (max-width: 992px) {
            .vertical-nav { width: 220px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { 
                width: 100%; 
                height: auto; 
                position: relative; 
                overflow-y: hidden; 
                padding: 15px 0;
            }
            .main-content { min-height: auto; }
            .horizontal-nav { 
                flex-direction: column; 
                gap: 10px; 
                padding: 10px;
            }
            .horizontal-menu { 
                flex-wrap: wrap; 
                justify-content: center; 
            }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }
            .complaint-details { padding: 1rem; }
            .table-responsive {
                border-radius: 0;
            }
            .table-responsive table,
            .table-responsive thead,
            .table-responsive tbody,
            .table-responsive th,
            .table-responsive td,
            .table-responsive tr { 
                display: block; 
            }
            .table-responsive thead tr { 
                position: absolute; 
                top: -9999px; 
                left: -9999px; 
            }
            .table-responsive tr { 
                margin-bottom: 15px; 
                border: 1px solid #ddd; 
                border-radius: 5px; 
                background-color: white; 
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            }
            .table-responsive tr:nth-child(even) { 
                background-color: white; 
            }
            .table-responsive td { 
                border: none; 
                border-bottom: 1px solid #eee; 
                position: relative; 
                padding-left: 50% !important; 
                text-align: left; 
                white-space: normal; 
                min-height: 30px; 
                display: flex; 
                align-items: center; 
            }
            .table-responsive td:before { 
                content: attr(data-label); 
                position: absolute; 
                left: 15px; 
                width: 45%; 
                padding-right: 10px; 
                font-weight: bold; 
                color: var(--primary); 
                white-space: normal; 
                text-align: left; 
            }
            .table-responsive td:last-child { 
                border-bottom: none; 
            }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .complaint-details { padding: 1rem; }
            .btn { 
                width: 100%; 
                margin-bottom: 5px; 
            }
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
            <?php if ($cost_sharing_user): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($cost_sharing_user['fname'] . ' ' . $cost_sharing_user['lname']); ?></h4>
                    <p>Cost Sharing</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard Overview</span>
            </a>
            <h3>Complaint Management</h3>
            <a href="view_assigned.php" class="nav-link <?php echo $current_page == 'view_assigned.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i><span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i><span>Resolved Complaints</span>
            </a>
            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i><span>Notifications</span>
            </a>
            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-key"></i><span>Edit Profile</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Registrar Complaint System - Cost Sharing</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Complaint #<?php echo $complaint_id; ?> Details</h2>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <div class="complaint-details">
                <h3>Complaint Information</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><strong>Category:</strong> <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?></p>
                <p><strong>Stereotypes:</strong> <?php echo !empty($complaint['stereotypes']) ? htmlspecialchars(implode(', ', array_column($complaint['stereotypes'], 'label'))) : '<span class="status status-uncategorized">None</span>'; ?></p>
                <p><strong>Status:</strong> <span class="status status-<?php echo strtolower($complaint['status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?></span></p>
                <p><strong>Escalation Status:</strong> <span class="status status-<?php echo strtolower($complaint['escalation_status']); ?>"><?php echo htmlspecialchars(ucfirst($complaint['escalation_status'])); ?></span></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></p>
                <p><strong>Submitted On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <div class="complaint-history">
                <h3>Complaint History</h3>
                <?php if (!empty($decisions)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Decision ID</th>
                                    <th>Sender</th>
                                    <th>Receiver</th>
                                    <th>Decision Text</th>
                                    <th>Status</th>
                                    <th>Created On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decisions as $decision): ?>
                                    <tr>
                                        <td data-label="Decision ID"><?php echo $decision['id']; ?></td>
                                        <td data-label="Sender"><?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?></td>
                                        <td data-label="Receiver"><?php echo $decision['receiver_id'] ? htmlspecialchars($decision['receiver_fname'] . ' ' . $decision['receiver_lname']) : 'N/A'; ?></td>
                                        <td data-label="Decision Text"><?php echo htmlspecialchars($decision['decision_text']); ?></td>
                                        <td data-label="Status"><span class="status status-<?php echo strtolower($decision['status']); ?>"><?php echo htmlspecialchars(ucfirst($decision['status'])); ?></span></td>
                                        <td data-label="Created On"><?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No decisions have been made for this complaint yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Registrar Complaint System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

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
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>