<?php
session_start();
require_once '../db_connect.php';

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'errors.log');

// Role check: Ensure user is logged in and is a handler
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../" . (!isset($_SESSION['user_id']) ? "login.php" : "unauthorized.php"));
    exit;
}

if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    $_SESSION['error'] = "Database connection error. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null;
$decision = null;
$complaint = null;
$error = '';
$success = '';
$debug_log = []; // For detailed debugging

// Fetch handler details
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler && $result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
        $debug_log[] = "Fetched handler details for ID: $handler_id";
    } else {
        error_log("Handler details not found for ID: $handler_id");
        $_SESSION['error'] = "Could not retrieve your details. Please contact support.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching your details.";
    header("Location: dashboard.php");
    exit;
}

// Validate decision_id
$decision_id = filter_input(INPUT_GET, 'decision_id', FILTER_VALIDATE_INT);
if (!$decision_id || $decision_id <= 0) {
    error_log("Invalid decision_id: " . var_export($decision_id, true));
    $_SESSION['error'] = "Invalid or missing decision ID.";
    header("Location: dashboard.php");
    exit;
}

// Fetch decision and complaint details
$sql_decision = "SELECT d.id, d.complaint_id, d.escalation_id, d.sender_id, d.decision_text, d.created_at,
                       c.title, c.description, c.category, c.directorate, c.status, c.created_at as complaint_created,
                       u.fname as sender_fname, u.lname as sender_lname,
                       cu.fname as complainant_fname, cu.lname as complainant_lname, cu.id as complainant_id
                FROM decisions d
                JOIN complaints c ON d.complaint_id = c.id
                JOIN users u ON d.sender_id = u.id
                JOIN users cu ON c.user_id = cu.id
                WHERE d.id = ? AND d.receiver_id = ? AND c.handler_id = ?";
$stmt_decision = $db->prepare($sql_decision);
if ($stmt_decision) {
    $stmt_decision->bind_param("iii", $decision_id, $handler_id, $handler_id);
    $stmt_decision->execute();
    $result_decision = $stmt_decision->get_result();
    if ($result_decision && $result_decision->num_rows > 0) {
        $decision = $result_decision->fetch_assoc();
        $complaint = [
            'id' => $decision['complaint_id'],
            'title' => $decision['title'],
            'description' => $decision['description'],
            'category' => $decision['category'],
            'directorate' => $decision['directorate'],
            'status' => $decision['status'],
            'created_at' => $decision['complaint_created'],
            'complainant_fname' => $decision['complainant_fname'],
            'complainant_lname' => $decision['complainant_lname'],
            'complainant_id' => $decision['complainant_id']
        ];
        $debug_log[] = "Fetched decision details for decision_id: $decision_id";
    } else {
        error_log("Decision not found for ID: $decision_id, handler_id: $handler_id");
        $_SESSION['error'] = "Decision not found or you are not authorized to respond to it.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_decision->close();
} else {
    error_log("Error preparing decision query: " . $db->error);
    $_SESSION['error'] = "Database error fetching decision details.";
    header("Location: dashboard.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response_text = trim(filter_input(INPUT_POST, 'response_text', FILTER_SANITIZE_STRING));
    
    // Validate input
    if (empty($response_text)) {
        $error = "Please provide a response.";
    } elseif (strlen($response_text) > 1000) {
        $error = "Response must be 1000 characters or less.";
    } else {
        $db->begin_transaction();
        try {
            $debug_log[] = "Starting transaction for complaint_id: {$decision['complaint_id']}";

            // Handle nullable escalation_id
            $escalation_id = $decision['escalation_id'] ?? null;

            // Insert new decision (handler's response)
            $response_sql = "INSERT INTO decisions (complaint_id, escalation_id, sender_id, receiver_id, decision_text, status, created_at)
                            VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
            $stmt_response = $db->prepare($response_sql);
            if (!$stmt_response) {
                throw new Exception("Error preparing response insert: " . $db->error);
            }
            $stmt_response->bind_param(
                "iiiis",
                $decision['complaint_id'],
                $escalation_id,
                $handler_id,
                $decision['sender_id'],
                $response_text
            );
            if (!$stmt_response->execute()) {
                throw new Exception("Error executing response insert: " . $stmt_response->error);
            }
            $stmt_response->close();
            $debug_log[] = "Inserted decision for complaint_id: {$decision['complaint_id']}";

            // Update complaint status to 'in_progress'
            $update_complaint_sql = "UPDATE complaints SET status = 'in_progress', updated_at = NOW() WHERE id = ?";
            $stmt_complaint = $db->prepare($update_complaint_sql);
            if (!$stmt_complaint) {
                throw new Exception("Error preparing complaint update: " . $db->error);
            }
            $stmt_complaint->bind_param("i", $decision['complaint_id']);
            if (!$stmt_complaint->execute()) {
                throw new Exception("Error executing complaint update: " . $stmt_complaint->error);
            }
            $stmt_complaint->close();
            $debug_log[] = "Updated complaint status to in_progress for complaint_id: {$decision['complaint_id']}";

            // Notify the sender (e.g., College Dean)
            $sender_notification_desc = "A response has been received for Complaint #{$decision['complaint_id']} from Handler: " . htmlspecialchars($response_text);
            $notify_sender_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                                 VALUES (?, ?, ?, 0, NOW())";
            $stmt_notify_sender = $db->prepare($notify_sender_sql);
            if (!$stmt_notify_sender) {
                throw new Exception("Error preparing sender notification: " . $db->error);
            }
            $stmt_notify_sender->bind_param("iis", $decision['sender_id'], $decision['complaint_id'], $sender_notification_desc);
            if (!$stmt_notify_sender->execute()) {
                throw new Exception("Error executing sender notification: " . $stmt_notify_sender->error);
            }
            $stmt_notify_sender->close();
            $debug_log[] = "Notified sender_id: {$decision['sender_id']}";

            // Notify the complainant
            $complainant_notification_desc = "Your Complaint (#{$decision['complaint_id']}: {$decision['title']}) has received a response from the handler and is being processed.";
            $notify_complainant_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                                      VALUES (?, ?, ?, 0, NOW())";
            $stmt_notify_complainant = $db->prepare($notify_complainant_sql);
            if (!$stmt_notify_complainant) {
                throw new Exception("Error preparing complainant notification: " . $db->error);
            }
            $stmt_notify_complainant->bind_param("iis", $decision['complainant_id'], $decision['complaint_id'], $complainant_notification_desc);
            if (!$stmt_notify_complainant->execute()) {
                throw new Exception("Error executing complainant notification: " . $stmt_notify_complainant->error);
            }
            $stmt_notify_complainant->close();
            $debug_log[] = "Notified complainant_id: {$decision['complainant_id']}";

            // Send stereotyped report to President (if exists)
            $president_id = getPresidentId($db);
            if ($president_id) {
                $report_content = "Complaint Response Report\n" .
                                 "Report Type: Handler Response\n" .
                                 "Complaint ID: {$decision['complaint_id']}\n" .
                                 "Complaint Title: {$decision['title']}\n" .
                                 "Handler: {$handler['fname']} {$handler['lname']}\n" .
                                 "Response: " . htmlspecialchars($response_text) . "\n" .
                                 "Sent to: {$decision['sender_fname']} {$decision['sender_lname']}\n" .
                                 "Date: " . date('Y-m-d H:i:s');
                $report_sql = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at)
                              VALUES (?, ?, ?, 'handler_response', ?, NOW())";
                $stmt_report = $db->prepare($report_sql);
                if (!$stmt_report) {
                    throw new Exception("Error preparing report: " . $db->error);
                }
                $stmt_report->bind_param("iiis", $decision['complaint_id'], $handler_id, $president_id, $report_content);
                if (!$stmt_report->execute()) {
                    throw new Exception("Error executing report: " . $stmt_report->error);
                }
                $stmt_report->close();
                $debug_log[] = "Inserted report for president_id: $president_id";

                // Notify President
                $president_notification_desc = "A new response report is available for Complaint #{$decision['complaint_id']}.";
                $notify_president_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                                        VALUES (?, ?, ?, 0, NOW())";
                $stmt_notify_president = $db->prepare($notify_president_sql);
                if (!$stmt_notify_president) {
                    throw new Exception("Error preparing president notification: " . $db->error);
                }
                $stmt_notify_president->bind_param("iis", $president_id, $decision['complaint_id'], $president_notification_desc);
                if (!$stmt_notify_president->execute()) {
                    throw new Exception("Error executing president notification: " . $stmt_notify_president->error);
                }
                $stmt_notify_president->close();
                $debug_log[] = "Notified president_id: $president_id";
            } else {
                $debug_log[] = "No president found; skipping report and notification";
            }

            $db->commit();
            $debug_log[] = "Transaction committed";
            error_log("Response submitted successfully for decision_id: $decision_id, complaint_id: {$decision['complaint_id']}");
            error_log("Debug log: " . implode("; ", $debug_log));
            $_SESSION['success'] = "Response for Complaint #{$decision['complaint_id']} sent successfully.";
            header("Location: dashboard.php#decided-complaints-section");
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $debug_log[] = "Transaction rolled back due to: " . $e->getMessage();
            error_log("Error processing response for decision_id: $decision_id, complaint_id: {$decision['complaint_id']}, error: " . $e->getMessage());
            error_log("Debug log: " . implode("; ", $debug_log));
            $error = "An error occurred while sending your response. Please try again or contact support.";
            // For debugging (uncomment in development):
            // $error .= " Debug: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Function to get President's user ID
function getPresidentId($db) {
    $sql = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        $president_id = $result->fetch_assoc()['id'];
        error_log("Found president_id: $president_id");
        return $president_id;
    }
    error_log("President ID not found.");
    return null;
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply to Decision | DMU Complaint System</title>
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
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', 'Montserrat', sans-serif; }
        html { scroll-behavior: smooth; }
        body { background-color: #f5f7ff; color: var(--dark); line-height: 1.6; min-height: 100vh; display: flex; }

        .vertical-nav {
            width: 280px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white;
            height: 100vh; position: sticky; top: 0; padding: 20px 0; box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            overflow-y: auto; transition: var(--transition); z-index: 10; flex-shrink: 0;
        }

        .nav-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
        .nav-header .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .nav-header img { height: 40px; border-radius: 50%; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2); }
        .nav-header .logo-text { font-size: 1.3rem; font-weight: 700; letter-spacing: 1px; }

        .user-profile-mini { display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(255, 255, 255, 0.1); border-radius: var(--radius); }
        .user-profile-mini i { font-size: 2.5rem; color: white; }
        .user-info h4 { font-size: 0.9rem; margin-bottom: 2px; }
        .user-info p { font-size: 0.8rem; opacity: 0.8; }

        .nav-menu { padding: 0 10px; }
        .nav-menu h3 { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; margin: 20px 10px 10px; opacity: 0.7; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: white; text-decoration: none; border-radius: var(--radius); margin-bottom: 5px; transition: var(--transition); }
        .nav-link:hover, .nav-link.active { background: rgba(255, 255, 255, 0.2); transform: translateX(5px); box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.1); }
        .nav-link i { width: 20px; text-align: center; }

        .main-content { flex: 1; padding: 20px; display: flex; flex-direction: column; min-height: 100vh; overflow-y: auto; }
        .horizontal-nav { background: white; border-radius: var(--radius); box-shadow: var(--shadow); padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; transition: var(--transition); position: sticky; top: 0; z-index: 5; }
        .horizontal-nav:hover { box-shadow: var(--shadow-hover); }
        .horizontal-nav .logo span { font-size: 1.2rem; font-weight: 600; color: var(--primary-dark); }
        .horizontal-menu { display: flex; gap: 10px; }
        .horizontal-menu a { color: var(--dark); text-decoration: none; padding: 8px 15px; border-radius: var(--radius); transition: var(--transition); font-weight: 500; }
        .horizontal-menu a:hover, .horizontal-menu a.active { background: var(--primary); color: white; transform: scale(1.05); }

        .alert { padding: 15px 20px; margin-bottom: 25px; border-radius: var(--radius); border: 1px solid transparent; display: flex; align-items: center; gap: 12px; font-weight: 500; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); position: relative; overflow: hidden; }
        .alert::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 4px; background: var(--success); }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-success::before { background: var(--success); }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-danger::before { background: var(--danger); }

        .content-container { background: white; padding: 2.5rem; border-radius: var(--radius-lg); box-shadow: var(--shadow); margin-bottom: 2rem; animation: fadeIn 0.5s ease-out; flex-grow: 1; transition: var(--transition); }
        .content-container:hover { box-shadow: var(--shadow-hover); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

        h2 { color: var(--primary-dark); font-size: 1.8rem; margin-bottom: 1.5rem; position: relative; padding-bottom: 0.5rem; text-align: center; }
        h2::after { content: ''; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 80px; height: 4px; background: linear-gradient(90deg, var(--primary), var(--accent)); border-radius: 2px; }
        h3 { color: var(--primary); font-size: 1.3rem; margin-top: 2rem; margin-bottom: 1rem; border-bottom: 2px solid var(--light-gray); padding-bottom: 0.5rem; }

        .complaint-details, .decision-details { background: #f8f9fa; padding: 1.5rem; border-radius: var(--radius); margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05); }
        .complaint-details p, .decision-details p { margin: 0.5rem 0; font-size: 0.95rem; }
        .complaint-details p strong, .decision-details p strong { color: var(--primary-dark); }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 500; color: var(--gray); margin-bottom: 0.5rem; }
        .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid var(--light-gray); border-radius: var(--radius); font-size: 0.95rem; resize: vertical; transition: var(--transition); }
        .form-group textarea:focus { border-color: var(--primary); box-shadow: 0 0 5px rgba(67, 97, 238, 0.3); outline: none; }
        .form-group textarea:hover { border-color: var(--primary-light); }

        .btn { padding: 0.5rem 1rem; border: none; border-radius: var(--radius); font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: var(--transition); text-decoration: none; color: white; }
        .btn-primary { background: var(--primary); }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #c82333; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1); }
        .btn-container { display: flex; gap: 1rem; justify-content: center; margin-top: 2rem; }

        footer { margin-top: auto; padding: 1.5rem 0; background: white; border-top: 1px solid var(--light-gray); box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05); }
        .footer-content { display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; padding: 0 20px; flex-wrap: wrap; gap: 1rem; }
        .group-name { font-weight: 500; color: var(--gray); }
        .social-links a { color: var(--primary); margin: 0 10px; font-size: 1.2rem; transition: var(--transition); }
        .social-links a:hover { color: var(--primary-dark); transform: translateY(-3px); }
        .copyright { color: var(--gray); font-size: 0.9rem; }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; padding: 15px 0; }
            .main-content { padding: 15px; }
            .horizontal-nav { flex-direction: column; gap: 10px; text-align: center; }
            .horizontal-menu { justify-content: center; flex-wrap: wrap; }
            .content-container { padding: 1rem; }
            .btn-container { flex-direction: column; align-items: stretch; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo" onerror="this.style.display='none'">
                <span class="logo-text">DMU CS</span>
            </div>
            <?php if ($handler): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($handler['role'])); ?></p>
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
            <a href="view_assigned_complaints.php" class="nav-link <?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i><span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i><span>Resolved Complaints</span>
            </a>
            <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i><span>Decisions Received</span>
            </a>
            <a href="send_decision.php" class="nav-link <?php echo $current_page == 'send_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i><span>Send Decision</span>
            </a>
            <a href="assign_committee.php" class="nav-link <?php echo $current_page == 'assign_committee.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i><span>Assign Committee</span>
            </a>
            <h3>Communication</h3>
            <a href="manage_notices.php" class="nav-link <?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-bullhorn"></i><span>Manage Notices</span>
            </a>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i><span>View Notifications</span>
            </a>
            <a href="view_feedback.php" class="nav-link <?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-dots"></i><span>Complaint Feedback</span>
            </a>
            <h3>Reports</h3>
            <a href="generate_report.php" class="nav-link <?php echo $current_page == 'generate_report.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i><span>Generate Reports</span>
            </a>
            <h3>Account</h3>
            <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                <i class="fas fa-key"></i><span>Change Password</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Handler</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Reply to Decision</h2>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($complaint && $decision): ?>
                <div class="complaint-details">
                    <h3>Complaint Details</h3>
                    <p><strong>Complaint ID:</strong> <?php echo $complaint['id']; ?></p>
                    <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                    <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                    <p><strong>Category:</strong> <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : 'Unset'; ?></p>
                    <p><strong>Directorate:</strong> <?php echo !empty($complaint['directorate']) ? htmlspecialchars($complaint['directorate']) : 'N/A'; ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                    <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['complainant_fname'] . ' ' . $complaint['complainant_lname']); ?></p>
                    <p><strong>Submitted On:</strong> <?php echo date('M j, Y', strtotime($complaint['created_at'])); ?></p>
                </div>

                <div class="decision-details">
                    <h3>Decision Received</h3>
                    <p><strong>Sent By:</strong> <?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?></p>
                    <p><strong>Decision Text:</strong> <?php echo htmlspecialchars($decision['decision_text']); ?></p>
                    <p><strong>Received On:</strong> <?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></p>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="response_text">Your Response</label>
                        <textarea name="response_text" id="response_text" rows="6" maxlength="1000" required placeholder="Enter your response to the decision..."></textarea>
                    </div>
                    <div class="btn-container">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Response</button>
                        <a href="dashboard.php#decided-complaints-section" class="btn btn-danger"><i class="fas fa-times"></i> Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> No decision or complaint details available.</div>
            <?php endif; ?>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="https://github.com/YourGroupRepo" target="_blank" rel="noopener noreferrer" aria-label="GitHub"><i class="fab fa-github"></i></a>
                    <a href="mailto:group4@example.com" aria-label="Email"><i class="fas fa-envelope"></i></a>
                </div>
                <div class="copyright">© 2025 DMU Complaint System. All rights reserved.</div>
            </div>
        </footer>
    </div>
</body>
</html>