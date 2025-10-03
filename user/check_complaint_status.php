<?php
session_start();
require_once '../db_connect.php'; 

// Role check: Ensure the user is logged in and is a 'user'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'user') {
    // Redirect non-users or handle appropriately
    // For example, redirect to a specific dashboard based on role or show an unauthorized page
    // header("Location: ../unauthorized.php"); // Or redirect based on role
    // exit;
    // If handlers/admins can see their own complaints (if applicable), adjust logic
    // For now, strictly enforce 'user' role for this page
    header("Location: ../unauthorized.php"); // Assuming an unauthorized page exists
     exit;
}


$user_id = $_SESSION['user_id'];

// Fetch user details from database if not in session (Good practice)
if (!isset($_SESSION['fname']) || !isset($_SESSION['lname'])) {
    $sql_user = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_user = $db->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();
            $_SESSION['fname'] = $user['fname'];
            $_SESSION['lname'] = $user['lname'];
        } else {
            // Handle case where user details couldn't be fetched (e.g., user deleted?)
            $_SESSION['error'] = "Could not fetch user details.";
            // Optionally log out or redirect
        }
        $stmt_user->close();
    } else {
        error_log("Error preparing user details query: " . $db->error);
        $_SESSION['error'] = "Database error fetching user details.";
    }
}

// Fetch complaints using prepared statement
// Corrected ORDER BY clause from c.submission_date to c.created_at
$sql = "SELECT c.*,
               (SELECT COUNT(*) FROM escalations e WHERE e.complaint_id = c.id) as escalation_count,
               (SELECT escalated_to FROM escalations e WHERE e.complaint_id = c.id ORDER BY e.id DESC LIMIT 1) as last_escalation
        FROM complaints c
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC"; // <-- FIX: Changed from submission_date to created_at

$stmt = $db->prepare($sql); // This was the line causing the error (line 47 in the original)
$complaints = null; // Initialize $complaints
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $complaints = $stmt->get_result();
    } else {
        // Log the specific execution error
        error_log("Error executing complaints query: " . $stmt->error);
        $_SESSION['error'] = "Database error fetching complaints (execute failed).";
    }
    $stmt->close();
} else {
    // Log the preparation error
    error_log("Error preparing complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaints (prepare failed).";
}


// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Complaint Status | DMU Complaint System</title>
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
            overflow-y: auto; /* Add scroll if menu is long */
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
             border-radius: 50%; /* Optional: make logo image round */
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
            min-height: 100vh; /* Ensure footer stays at bottom */
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
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            gap: 10px; /* Add gap for wrapped items */
        }
        .horizontal-nav .logo { /* Style for the logo in horizontal nav */
             font-weight: 600;
             font-size: 1.1rem;
             color: var(--primary-dark);
        }

        .horizontal-menu {
            display: flex;
            gap: 10px;
            flex-wrap: wrap; /* Allow menu items to wrap */
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
             display: inline-flex; /* Align icon and text */
            align-items: center;
            gap: 5px; /* Space between icon and text */
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
            animation: fadeIn 0.5s ease-out; /* Add fade-in animation */
        }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }

        /* Content Container */
        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1; /* Allow container to grow */
            animation: fadeIn 0.5s ease-out;
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

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: 0 0 0 1px var(--light-gray); /* Subtle border */
        }

        table {
            width: 100%;
            border-collapse: separate; /* Use separate for border-radius */
            border-spacing: 0;
            min-width: 900px; /* Increased min-width for more columns */
        }

        thead {
           /* position: sticky; /* Make header sticky */
           /* top: 0; /* Stick to the top */
           /* z-index: 10; /* Ensure it's above table body */
        }

        th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            font-weight: 600;
            padding: 1rem 1.2rem; /* Increased padding */
            text-align: left;
             text-transform: uppercase; /* Uppercase headers */
            font-size: 0.85rem; /* Slightly smaller font */
            letter-spacing: 0.5px; /* Add letter spacing */
            white-space: nowrap; /* Prevent header text wrapping */
        }
        th:first-child { border-top-left-radius: var(--radius); } /* Round corners */
        th:last-child { border-top-right-radius: var(--radius); }

        td {
            padding: 1rem 1.2rem; /* Match header padding */
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
            font-size: 0.9rem;
            color: var(--dark); /* Use dark color for text */
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:nth-child(even) td { /* Use td selector for zebra striping with separate borders */
             background-color: #fdfdff; /* Very light background for even rows */
        }

        tr:hover td {
            background-color: rgba(72, 149, 239, 0.05); /* Subtle hover effect */
        }
        /* Add animation to rows */
        tbody tr {
             opacity: 0;
        }

        /* Status Badges */
        .status {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 50px; /* Pill shape */
            font-size: 0.8rem;
            font-weight: 600; /* Bolder status */
            text-transform: capitalize;
            white-space: nowrap; /* Prevent wrapping */
        }
        .status-pending { background-color: rgba(255, 193, 7, 0.15); color: #b98900; } /* Darker warning */
        .status-validated { background-color: rgba(40, 167, 69, 0.15); color: var(--success); }
        .status-in_progress { background-color: rgba(0, 123, 255, 0.15); color: #0056b3; } /* Added style for in_progress */
        .status-resolved { background-color: rgba(23, 162, 184, 0.15); color: var(--info); }
        .status-rejected { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); } /* Added style for rejected */
        /* Specific Escalation Status Color (if needed) */
        .status-escalated { background-color: rgba(220, 53, 69, 0.15); color: var(--danger); }


        /* Escalation Info */
        .escalation-info {
            font-size: 0.85rem;
            color: var(--gray);
             white-space: nowrap; /* Prevent wrapping */
        }
        .escalation-info i {
            margin-right: 5px;
            color: var(--danger);
        }
        .escalation-info .last-escalation {
             font-weight: 500;
             color: var(--secondary); /* Highlight the last escalation role */
        }

        /* Resolution Info */
        .resolution-info {
            font-size: 0.85rem;
            color: var(--gray);
             white-space: nowrap; /* Prevent wrapping */
        }
        .resolution-info i {
            margin-right: 5px;
            color: var(--info);
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
             white-space: nowrap; /* Prevent button text wrapping */
        }
        .action-buttons { /* Container for buttons */
            display: flex;
            gap: 8px;
            flex-wrap: nowrap; /* Keep buttons on one line if possible */
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

        .btn-info {
            background: linear-gradient(135deg, var(--info) 0%, #107585 100%); /* Darker info gradient */
            color: white;
        }
        .btn-info:hover {
            background: linear-gradient(135deg, #107585 0%, var(--info) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        .btn i { font-size: 0.9em; } /* Slightly smaller icons in buttons */


        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            border: 2px dashed var(--light-gray);
            border-radius: var(--radius-lg);
            margin-top: 2rem;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }
         .empty-state h3 {
             color: var(--dark);
             margin-bottom: 0.5rem;
         }
         .empty-state a {
             color: var(--primary);
             font-weight: 500;
             text-decoration: none;
         }
         .empty-state a:hover {
             text-decoration: underline;
         }


        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto; /* Push footer to bottom */
            border-radius: var(--radius-lg) var(--radius-lg) 0 0; /* Rounded top corners */
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
             .content-container { padding: 2rem; }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative; /* Change from sticky */
                 box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); /* Adjust shadow for top */
            }
            .main-content { padding: 15px; }
            .horizontal-nav {
                 padding: 10px 15px;
            }
            .horizontal-menu { justify-content: center; } /* Center menu items when wrapped */
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            th, td { padding: 0.8rem 1rem; } /* Adjust table padding */
            .btn { padding: 0.4rem 0.8rem; font-size: 0.8rem; } /* Smaller buttons */
            .action-buttons { flex-wrap: wrap; } /* Allow buttons to wrap */
        }

        @media (max-width: 576px) {
             .vertical-nav { display: none; } /* Hide vertical nav completely */
             .main-content { padding: 10px; }
             .horizontal-nav .logo { font-size: 1rem; }
             .horizontal-menu a { padding: 6px 10px; font-size: 0.9rem; }
             .content-container { padding: 1.25rem; }
             h2 { font-size: 1.3rem; }
             th, td { font-size: 0.85rem; padding: 0.6rem 0.8rem; }
             .status { font-size: 0.75rem; padding: 0.3rem 0.6rem; }
        }
    </style>
</head>
<body>
    <!-- Vertical Navigation -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <!-- Make sure the path to your logo is correct -->
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo isset($_SESSION['fname']) && isset($_SESSION['lname']) ? htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname']) : 'User'; ?></h4>
                    <p><?php echo isset($_SESSION['role']) ? htmlspecialchars(ucfirst($_SESSION['role'])) : 'User'; ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i> <!-- Added fa-fw for fixed width -->
                <span>Overview</span>
            </a>

            <h3>Complaints</h3>
            <a href="submit_complaint.php" class="nav-link <?php echo $current_page == 'submit_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle fa-fw"></i>
                <span>Submit Complaint</span>
            </a>
            <a href="modify_complaint.php" class="nav-link <?php echo $current_page == 'modify_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-edit fa-fw"></i>
                <span>Modify Complaint</span>
            </a>
            <a href="check_complaint_status.php" class="nav-link <?php echo $current_page == 'check_complaint_status.php' ? 'active' : ''; ?>">
                <i class="fas fa-search fa-fw"></i>
                <span>Check Status</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>Notifications</span>
            </a>
            <a href="send_feedback.php" class="nav-link <?php echo $current_page == 'send_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-alt fa-fw"></i>
                <span>Send Feedback</span>
            </a>
            <a href="view_decision.php" class="nav-link <?php echo $current_page == 'view_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel fa-fw"></i>
                <span>View Decisions</span>
            </a>

            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit fa-fw"></i>
                <span>Edit Profile</span>
            </a>
            <a href="view_notices.php" class="nav-link <?php echo $current_page == 'view_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list fa-fw"></i>
                <span>View Notices</span>
            </a>
             <a href="../logout.php" class="nav-link"> <!-- Logout moved to bottom -->
                 <i class="fas fa-sign-out-alt fa-fw"></i>
                 <span>Logout</span>
             </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                <!-- Adjust links as needed, ensure paths are correct -->
                 <a href="../index.php" class="<?php echo basename(dirname($_SERVER['PHP_SELF'])) == 'dmu_complaints' && $current_page == 'index.php' ? 'active' : ''; ?>">
                     <i class="fas fa-home"></i> Home
                 </a>
                 <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>">
                     <i class="fas fa-envelope"></i> Contact
                 </a>
                 <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>">
                     <i class="fas fa-info-circle"></i> About
                 </a>
                <!-- Logout button is now primarily in the vertical nav -->
                 <!-- <a href="../logout.php">
                     <i class="fas fa-sign-out-alt"></i> Logout
                 </a> -->
            </div>
        </nav>

        <!-- Complaint Status Content -->
        <div class="content-container">
            <h2>Your Complaint Status</h2>

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

            <?php if ($complaints === null): // Check if query failed ?>
                 <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Could not load complaints due to a database error. Please try again later.</div>
            <?php elseif ($complaints->num_rows === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Complaints Found</h3>
                    <p>You haven't submitted any complaints yet. <a href="submit_complaint.php">Submit a new complaint</a> to get started.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Visibility</th>
                                <th>Submitted On</th>
                                <th>Status</th>
                                <th>Escalation</th>
                                <th>Resolution</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($complaint = $complaints->fetch_assoc()):
                                // Sanitize status for class name (e.g., "in_progress" -> "in-progress")
                                $status_class_raw = strtolower(str_replace(' ', '_', $complaint['status']));
                                $status_class = 'status-' . htmlspecialchars($status_class_raw);

                                $escalation_info = "Not escalated";
                                if ($complaint['escalation_count'] > 0) {
                                     $last_escalation_role = $complaint['last_escalation'] ? ucfirst(str_replace('_', ' ', $complaint['last_escalation'])) : 'N/A';
                                     $escalation_info = "Escalated " . $complaint['escalation_count'] . " time(s)";
                                     $escalation_info .= " - Last: <span class='last-escalation'>" . htmlspecialchars($last_escalation_role) . "</span>";
                                }

                                $resolution_info = "Not resolved";
                                if ($complaint['status'] == 'resolved' && $complaint['resolution_date']) {
                                    try {
                                        $resolution_date = new DateTime($complaint['resolution_date']);
                                        $resolution_info = "Resolved on " . $resolution_date->format('M j, Y');
                                    } catch (Exception $e) {
                                        $resolution_info = "Resolved (Invalid Date)"; // Handle potential invalid date format
                                    }
                                } elseif ($complaint['status'] == 'rejected') {
                                     $resolution_info = "Rejected"; // Or show rejection details if available
                                }

                                // Format created_at date
                                $submitted_date_formatted = 'N/A';
                                if ($complaint['created_at']) {
                                    try {
                                         $submitted_date = new DateTime($complaint['created_at']);
                                         $submitted_date_formatted = $submitted_date->format('M j, Y, g:i A'); // Include time
                                    } catch (Exception $e) {
                                         $submitted_date_formatted = 'Invalid Date';
                                    }
                                }

                            ?>
                                <tr>
                                    <td>#<?php echo $complaint['id']; ?></td>
                                    <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                    <td><?php echo ucfirst(htmlspecialchars($complaint['visibility'])); ?></td>
                                    <td><?php echo $submitted_date_formatted; // <-- FIX: Using created_at ?></td>
                                    <td>
                                        <span class="status <?php echo $status_class; ?>">
                                            <?php echo ucfirst(htmlspecialchars(str_replace('_', ' ', $complaint['status']))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="escalation-info">
                                            <?php if ($complaint['escalation_count'] > 0): ?>
                                                <i class="fas fa-level-up-alt"></i>
                                            <?php endif; ?>
                                            <?php echo $escalation_info; // Already contains HTML, so no extra htmlspecialchars needed here ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="resolution-info">
                                            <?php if ($complaint['status'] == 'resolved'): ?>
                                                <i class="fas fa-check-circle"></i>
                                            <?php elseif ($complaint['status'] == 'rejected'): ?>
                                                <i class="fas fa-times-circle"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($resolution_info); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-info" title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php // Allow modification only if status is 'pending'
                                            if ($complaint['status'] == 'pending'): ?>
                                                <a href="modify_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-primary" title="Modify Complaint">
                                                    <i class="fas fa-edit"></i> Modify
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($complaints instanceof mysqli_result) $complaints->free(); // Free result set if it exists ?>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div> <!-- Replace with your group name -->
                <div class="social-links">
                    <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint Management System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation to table rows if they exist
            const rows = document.querySelectorAll('tbody tr');
            if (rows.length > 0) {
                rows.forEach((row, index) => {
                    row.style.animationDelay = `${index * 0.05}s`;
                    row.style.animation = 'fadeIn 0.4s ease-out forwards';
                    row.style.opacity = '0'; // Start hidden
                });
            }

            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500); // Remove from DOM after fade out
                }, 7000); // 7 seconds
            });
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