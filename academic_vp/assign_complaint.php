<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'academic_vp'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'academic_vp') {
    header("Location: ../login.php");
    exit;
}

$vp_id = $_SESSION['user_id'];
$vp = null;
$complaint = null;

// Prevent direct access without valid referer
if (!isset($_SERVER['HTTP_REFERER']) || !str_contains($_SERVER['HTTP_REFERER'], 'dashboard.php')) {
    error_log("Unauthorized access to assign_complaint.php from: " . ($_SERVER['HTTP_REFERER'] ?? 'unknown'));
    $_SESSION['error'] = "Please select a complaint from the dashboard to assign.";
    header("Location: dashboard.php");
    exit;
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch Academic VP details
$sql_vp = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_vp = $db->prepare($sql_vp);
if ($stmt_vp) {
    $stmt_vp->bind_param("i", $vp_id);
    $stmt_vp->execute();
    $result_vp = $stmt_vp->get_result();
    if ($result_vp->num_rows > 0) {
        $vp = $result_vp->fetch_assoc();
    } else {
        $_SESSION['error'] = "Academic Vice President details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_vp->close();
} else {
    error_log("Error preparing Academic VP query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
}

// Validate complaint_id and escalation_id
$complaint_id = isset($_GET['complaint_id']) ? filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT) : null;
$escalation_id = isset($_GET['escalation_id']) ? filter_input(INPUT_GET, 'escalation_id', FILTER_VALIDATE_INT) : null;

if (!$complaint_id || !$escalation_id) {
    $debug_message = "Missing or invalid parameters in assign_complaint.php: " . json_encode([
        'complaint_id' => $complaint_id,
        'escalation_id' => $escalation_id,
        'referer' => $_SERVER['HTTP_REFERER'] ?? 'unknown'
    ]);
    error_log($debug_message);
    $_SESSION['error'] = "Invalid complaint or escalation ID. Please select a complaint from the dashboard.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details (complaints escalated to this Academic VP)
$stmt = $db->prepare("
    SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, 
           u.fname, u.lname, e.escalated_by_id, e.department_id
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.id = ? AND e.escalated_to_id = ? 
    AND e.escalated_to = 'academic_vp' AND e.status = 'pending'
    AND e.action_type = 'escalation'
");
if (!$stmt) {
    error_log("Prepare failed: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching the complaint. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

$stmt->bind_param("iii", $complaint_id, $escalation_id, $vp_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Debug: Log why the validation failed
    $debug_sql = "SELECT * FROM escalations WHERE complaint_id = ? AND id = ?";
    $debug_stmt = $db->prepare($debug_sql);
    $debug_stmt->bind_param("ii", $complaint_id, $escalation_id);
    $debug_stmt->execute();
    $debug_result = $debug_stmt->get_result();
    $escalation = $debug_result->fetch_assoc();
    $debug_stmt->close();

    $debug_message = "Complaint validation failed in assign_complaint.php. Details: " . json_encode([
        'complaint_id' => $complaint_id,
        'escalation_id' => $escalation_id,
        'vp_id' => $vp_id,
        'escalation_found' => !empty($escalation),
        'escalation_details' => $escalation
    ]);
    error_log($debug_message);

    // Provide a more specific error message
    if ($escalation) {
        if ($escalation['status'] !== 'pending') {
            $_SESSION['error'] = "This complaint has already been processed (status: " . htmlspecialchars($escalation['status']) . "). Return to the dashboard to view current complaints.";
        } elseif ($escalation['escalated_to'] !== 'academic_vp' || $escalation['escalated_to_id'] != $vp_id) {
            $_SESSION['error'] = "This complaint is not assigned to you. Select a complaint from the dashboard.";
        } else {
            $_SESSION['error'] = "Complaint not found or not accessible. Try selecting a complaint from the dashboard.";
        }
    } else {
        $_SESSION['error'] = "No escalation record found for this complaint. Please select a valid complaint from the dashboard.";
    }
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$escalated_by_id = $complaint['escalated_by_id'];
$department_id = $complaint['department_id'];
$stmt->close();

// Fetch stereotypes for the complaint
$sql_stereotypes = "
    SELECT s.label
    FROM complaint_stereotypes cs
    JOIN stereotypes s ON cs.stereotype_id = s.id
    WHERE cs.complaint_id = ?";
$stmt_stereotypes = $db->prepare($sql_stereotypes);
$stereotypes = [];
if ($stmt_stereotypes) {
    $stmt_stereotypes->bind_param("i", $complaint_id);
    $stmt_stereotypes->execute();
    $result_stereotypes = $stmt_stereotypes->get_result();
    while ($row = $result_stereotypes->fetch_assoc()) {
        $stereotypes[] = $row['label'];
    }
    $stmt_stereotypes->close();
} else {
    error_log("Error preparing stereotypes query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaint stereotypes.";
}

// Fetch College Dean details (who escalated the complaint)
$dean_query = "SELECT id, fname, lname, role FROM users WHERE id = ?";
$stmt_dean = $db->prepare($dean_query);
if ($stmt_dean) {
    $stmt_dean->bind_param("i", $escalated_by_id);
    $stmt_dean->execute();
    $dean_result = $stmt_dean->get_result();
    $dean = $dean_result->num_rows > 0 ? $dean_result->fetch_assoc() : null;
    $stmt_dean->close();
}

if (!$dean) {
    error_log("Dean not found for escalated_by_id: $escalated_by_id, complaint_id: $complaint_id");
    $_SESSION['error'] = "User who escalated this complaint not found.";
    header("Location: dashboard.php");
    exit;
}

// Fetch available handlers in the same department
$handlers_query = "SELECT id, fname, lname FROM users WHERE role = 'handler' AND department_id = ?";
$stmt_handlers = $db->prepare($handlers_query);
$handlers = [];
if ($stmt_handlers) {
    $stmt_handlers->bind_param("i", $department_id);
    $stmt_handlers->execute();
    $result_handlers = $stmt_handlers->get_result();
    while ($row = $result_handlers->fetch_assoc()) {
        $handlers[] = $row;
    }
    $stmt_handlers->close();
}

if (empty($handlers)) {
    error_log("No handlers found for department_id: $department_id, complaint_id: $complaint_id");
    $_SESSION['error'] = "No handlers are available in the department to assign this complaint. Contact the system administrator.";
    header("Location: dashboard.php");
    exit;
}

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '') {
    // Fetch complaint details
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    if (!$stmt_complaint) {
        error_log("Prepare failed for complaint fetch: " . $db->error);
        $_SESSION['error'] = "Failed to fetch complaint details for report generation.";
        return false;
    }
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) {
        error_log("Complaint #$complaint_id not found for report generation.");
        $_SESSION['error'] = "Complaint not found for report generation.";
        $stmt_complaint->close();
        return false;
    }
    $complaint = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    // Fetch sender details
    $sql_sender = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_sender = $db->prepare($sql_sender);
    if (!$stmt_sender) {
        error_log("Prepare failed for sender fetch: " . $db->error);
        $_SESSION['error'] = "Failed to fetch sender details for report generation.";
        return false;
    }
    $stmt_sender->bind_param("i", $sender_id);
    $stmt_sender->execute();
    $sender_result = $stmt_sender->get_result();
    if ($sender_result->num_rows === 0) {
        error_log("Sender #$sender_id not found for report generation.");
        $_SESSION['error'] = "Sender not found for report generation.";
        $stmt_sender->close();
        return false;
    }
    $sender = $sender_result->fetch_assoc();
    $stmt_sender->close();

    // Fetch the President
    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) {
        error_log("No user with role 'president' found.");
        $_SESSION['error'] = "No President found to receive the report.";
        return false;
    }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];

    // Generate stereotyped report content
    $report_content = "Complaint Report\n";
    $report_content .= "----------------\n";
    $report_content .= "Report Type: " . ucfirst($report_type) . "\n";
    $report_content .= "Complaint ID: {$complaint['id']}\n";
    $report_content .= "Title: {$complaint['title']}\n";
    $report_content .= "Description: {$complaint['description']}\n";
    $report_content .= "Category: " . ($complaint['category'] ? ucfirst($complaint['category']) : 'Not categorized') . "\n";
    $report_content .= "Status: " . ucfirst($complaint['status']) . "\n";
    $report_content .= "Submitted By: {$complaint['submitter_fname']} {$complaint['submitter_lname']}\n";
    $report_content .= "Processed By: {$sender['fname']} {$sender['lname']}\n";
    $report_content .= "Created At: " . date('M j, Y H:i', strtotime($complaint['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info: $additional_info\n";
    }

    // Insert the report into the stereotyped_reports table
    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt_report = $db->prepare($sql_report);
    if (!$stmt_report) {
        error_log("Prepare failed for report insertion: " . $db->error);
        $_SESSION['error'] = "Failed to generate the report for the President.";
        return false;
    }
    $stmt_report->bind_param("iiiss", $complaint_id, $sender_id, $recipient_id, $report_type, $report_content);
    $stmt_report->execute();
    $stmt_report->close();

    // Notify the President
    $notification_desc = "A new $report_type report for Complaint #{$complaint['id']} has been submitted by {$sender['fname']} {$sender['lname']} on " . date('M j, Y H:i') . ".";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
    $stmt_notify = $db->prepare($sql_notify);
    if ($stmt_notify) {
        $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
        $stmt_notify->execute();
        $stmt_notify->close();
    } else {
        error_log("Failed to prepare notification for President: " . $db->error);
        $_SESSION['error'] = "Failed to notify the President of the report.";
        return false;
    }

    return true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    $submitted_csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);
    if (!$submitted_csrf_token || $submitted_csrf_token !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid CSRF token. Please try again.";
        header("Location: assign_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $handler_id = filter_input(INPUT_POST, 'handler_id', FILTER_VALIDATE_INT);
    $assignment_details = trim(filter_input(INPUT_POST, 'assignment_details', FILTER_SANITIZE_SPECIAL_CHARS));

    // Validate handler_id
    if (!$handler_id || !in_array($handler_id, array_column($handlers, 'id'))) {
        $_SESSION['error'] = "Please select a valid handler.";
        header("Location: assign_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    // Validate assignment_details
    if (empty($assignment_details)) {
        $_SESSION['error'] = "Please provide assignment details.";
        header("Location: assign_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if (strlen($assignment_details) < 10) {
        $_SESSION['error'] = "Assignment details must be at least 10 characters long.";
        header("Location: assign_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if (strlen($assignment_details) > 1000) {
        $_SESSION['error'] = "Assignment details cannot exceed 1000 characters.";
        header("Location: assign_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $db->begin_transaction();
    try {
        // Mark the current escalation as forwarded
        $update_escalation_sql = "UPDATE escalations SET status = 'forwarded', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
        $update_escalation_stmt = $db->prepare($update_escalation_sql);
        if (!$update_escalation_stmt) {
            throw new Exception("An error occurred while updating the escalation status.");
        }
        $update_escalation_stmt->bind_param("si", $assignment_details, $escalation_id);
        $update_escalation_stmt->execute();
        $update_escalation_stmt->close();

        // Create a new assignment record for the handler
        $new_assignment_sql = "INSERT INTO escalations (complaint_id, escalated_by_id, escalated_to_id, escalated_to, department_id, action_type, status, created_at, original_handler_id) 
                               VALUES (?, ?, ?, 'handler', ?, 'assignment', 'pending', NOW(), ?)";
        $new_assignment_stmt = $db->prepare($new_assignment_sql);
        if (!$new_assignment_stmt) {
            throw new Exception("An error occurred while creating a new assignment record.");
        }
        $new_assignment_stmt->bind_param("iiiii", $complaint_id, $vp_id, $handler_id, $department_id, $handler_id);
        $new_assignment_stmt->execute();
        $new_assignment_stmt->close();

        // Update complaint status to assigned
        $update_complaint_sql = "UPDATE complaints SET status = 'assigned', resolution_details = NULL, resolution_date = NULL WHERE id = ?";
        $update_complaint_stmt = $db->prepare($update_complaint_sql);
        if (!$update_complaint_stmt) {
            throw new Exception("An error occurred while updating the complaint status.");
        }
        $update_complaint_stmt->bind_param("i", $complaint_id);
        $update_complaint_stmt->execute();
        $update_complaint_stmt->close();

        // Notify the Handler
        $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Complaint #$complaint_id has been assigned to you by {$vp['fname']} {$vp['lname']}: $assignment_details";
        $notify_handler_stmt = $db->prepare($notify_handler_sql);
        if (!$notify_handler_stmt) {
            throw new Exception("An error occurred while notifying the Handler.");
        }
        $notify_handler_stmt->bind_param("iis", $handler_id, $complaint_id, $notification_desc);
        $notify_handler_stmt->execute();
        $notify_handler_stmt->close();

        // Notify the complainant
        $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) 
                           SELECT user_id, ?, ?, 0, NOW() 
                           FROM complaints WHERE id = ?";
        $notification_desc = "Your complaint #$complaint_id has been assigned to a handler by {$vp['fname']} {$vp['lname']}: $assignment_details";
        $notify_user_stmt = $db->prepare($notify_user_sql);
        if (!$notify_user_stmt) {
            throw new Exception("An error occurred while notifying the complainant.");
        }
        $notify_user_stmt->bind_param("isi", $complaint_id, $notification_desc, $complaint_id);
        $notify_user_stmt->execute();
        $notify_user_stmt->close();

        // Notify the user who escalated the complaint
        $notify_prev_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notification_desc = "Complaint #$complaint_id, which you escalated, has been assigned to a handler by {$vp['fname']} {$vp['lname']}: $assignment_details";
        $notify_prev_user_stmt = $db->prepare($notify_prev_user_sql);
        if (!$notify_prev_user_stmt) {
            throw new Exception("An error occurred while notifying the previous user.");
        }
        $notify_prev_user_stmt->bind_param("iis", $escalated_by_id, $complaint_id, $notification_desc);
        $notify_prev_user_stmt->execute();
        $notify_prev_user_stmt->close();

        // Send stereotyped report to the President
        $additional_info = "Assigned by Academic Vice President: $assignment_details";
        if (!sendStereotypedReport($db, $complaint_id, $vp_id, 'assigned', $additional_info)) {
            throw new Exception("Failed to send the report to the President.");
        }

        // Log successful assignment
        error_log("Complaint #$complaint_id assigned to handler #$handler_id by VP #$vp_id");
        $_SESSION['success'] = "Complaint #$complaint_id has been assigned successfully.";
        $db->commit();
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "Error assigning complaint: " . $e->getMessage();
        error_log("Assignment error: " . $e->getMessage());
        header("Location: assign_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $vp_id);
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
    <title>Assign Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Unchanged CSS from original code */
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
        /* ... rest of CSS unchanged ... */
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
                    <h4><?php echo htmlspecialchars($vp['fname'] . ' ' . $vp['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $vp['role']))); ?></p>
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
            <a href="view_escalated.php" class="nav-link <?php echo $current_page == 'view_escalated.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up"></i>
                <span>View Escalated Complaints</span>
            </a>
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'assign_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to assign from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-user-plus"></i>
                <span>Assign Complaint</span>
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
                <span>DMU Complaint System - Academic Vice President</span>
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
            <h2>Assign Complaint #<?php echo $complaint_id; ?></h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details -->
            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></p>
                <p><strong>Stereotypes:</strong> <?php echo !empty($stereotypes) ? htmlspecialchars(implode(', ', array_map('ucfirst', $stereotypes))) : 'None'; ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?></p>
                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                <p><strong>Escalated By:</strong> <?php echo htmlspecialchars($dean['fname'] . ' ' . $dean['lname']); ?> (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dean['role']))); ?>)</p>
            </div>

            <!-- Assignment Form -->
            <form method="POST" onsubmit="return confirm('Are you sure you want to assign this complaint? This action cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="handler_id">Select Handler</label>
                    <select name="handler_id" id="handler_id" required>
                        <option value="" disabled selected>Select a handler</option>
                        <?php foreach ($handlers as $handler): ?>
                            <option value="<?php echo $handler['id']; ?>">
                                <?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="assignment_details">Assignment Details</label>
                    <textarea name="assignment_details" id="assignment_details" rows="5" required placeholder="Enter assignment details or instructions for the handler..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Assign Complaint</button>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
            </form>
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