<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'administrative_vp'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'administrative_vp') {
    header("Location: ../login.php");
    exit;
}

$vp_id = $_SESSION['user_id'];
$vp = null;
$complaint = null;

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch Administrative VP details
$sql_vp = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_vp = $db->prepare($sql_vp);
if ($stmt_vp) {
    $stmt_vp->bind_param("i", $vp_id);
    $stmt_vp->execute();
    $result_vp = $stmt_vp->get_result();
    if ($result_vp->num_rows > 0) {
        $vp = $result_vp->fetch_assoc();
    } else {
        $_SESSION['error'] = "Administrative Vice President details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_vp->close();
} else {
    error_log("Error preparing Administrative VP query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
}

// Validate complaint_id and escalation_id
$complaint_id = isset($_GET['complaint_id']) ? filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT) : null;
$escalation_id = isset($_GET['escalation_id']) ? filter_input(INPUT_GET, 'escalation_id', FILTER_VALIDATE_INT) : null;

if (!$complaint_id || !$escalation_id) {
    $_SESSION['error'] = "Invalid complaint or escalation ID.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details (complaints escalated or assigned to this Administrative VP)
$stmt = $db->prepare("
    SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, 
           u.fname, u.lname, e.escalated_by_id, e.original_handler_id, e.department_id, e.action_type
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.id = ? AND e.escalated_to_id = ? 
    AND e.escalated_to = 'administrative_vp' AND e.status = 'pending'
    AND e.action_type IN ('escalation', 'assignment')
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

    $debug_message = "Complaint validation failed in decide_complaint.php. Details: " . json_encode([
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
            $_SESSION['error'] = "This complaint has already been processed (current status: " . htmlspecialchars($escalation['status']) . "). Please refresh the dashboard.";
        } elseif ($escalation['escalated_to'] !== 'administrative_vp' || $escalation['escalated_to_id'] != $vp_id) {
            $_SESSION['error'] = "This complaint is not assigned or escalated to you.";
        } else {
            $_SESSION['error'] = "Complaint not found or not accessible.";
        }
    } else {
        $_SESSION['error'] = "Escalation/assignment record not found for this complaint.";
    }
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$action_type = $complaint['action_type'];
$handler_id = $complaint['original_handler_id'];
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

// Fetch handler details
$handler_query = "SELECT id, fname, lname FROM users WHERE id = ? AND role = 'handler'";
$stmt_handler = $db->prepare($handler_query);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $handler_result = $stmt_handler->get_result();
    $handler = $handler_result->num_rows > 0 ? $handler_result->fetch_assoc() : null;
    $stmt_handler->close();
}

if (!$handler) {
    $_SESSION['error'] = "Handler not found for this complaint.";
    header("Location: dashboard.php");
    exit;
}

// Fetch College Dean details (who escalated or assigned the complaint)
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
    $_SESSION['error'] = "User who escalated or assigned this complaint not found.";
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
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $decision_text = trim(filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_SPECIAL_CHARS));

    // Additional server-side validation for decision_text
    if (empty($decision_text)) {
        $_SESSION['error'] = "Please provide decision or escalation details.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if (strlen($decision_text) < 10) {
        $_SESSION['error'] = "Decision details must be at least 10 characters long.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if (strlen($decision_text) > 1000) {
        $_SESSION['error'] = "Decision details cannot exceed 1000 characters.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    if (!$action || !in_array($action, ['resolve', 'escalate'])) {
        $_SESSION['error'] = "Please select a valid action.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $db->begin_transaction();
    try {
        if ($action === 'resolve') {
            // Resolve the complaint
            // Update complaint status to resolved
            $update_complaint_sql = "UPDATE complaints SET status = 'resolved', resolution_details = ?, resolution_date = NOW() WHERE id = ?";
            $update_complaint_stmt = $db->prepare($update_complaint_sql);
            if (!$update_complaint_stmt) {
                throw new Exception("An error occurred while updating the complaint status.");
            }
            $update_complaint_stmt->bind_param("si", $decision_text, $complaint_id);
            $update_complaint_stmt->execute();
            $update_complaint_stmt->close();

            // Update escalation/assignment status to resolved
            $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
            $update_escalation_stmt = $db->prepare($update_escalation_sql);
            if (!$update_escalation_stmt) {
                throw new Exception("An error occurred while updating the escalation/assignment status.");
            }
            $update_escalation_stmt->bind_param("si", $decision_text, $escalation_id);
            $update_escalation_stmt->execute();
            $update_escalation_stmt->close();

            // Insert decision
            $decision_sql = "INSERT INTO decisions (escalation_id, complaint_id, sender_id, receiver_id, decision_text, status, created_at) VALUES (?, ?, ?, ?, ?, 'final', NOW())";
            $decision_stmt = $db->prepare($decision_sql);
            if (!$decision_stmt) {
                throw new Exception("An error occurred while recording the decision.");
            }
            $decision_stmt->bind_param("iiiis", $escalation_id, $complaint_id, $vp_id, $handler_id, $decision_text);
            $decision_stmt->execute();
            $decision_stmt->close();

            // Notify the Handler
            $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $notification_desc = "A final decision has been made on Complaint #$complaint_id by {$vp['fname']} {$vp['lname']}: $decision_text. Please review the resolution on your dashboard.";
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
            $notification_desc = "Your complaint #$complaint_id has been resolved by {$vp['fname']} {$vp['lname']}: $decision_text";
            $notify_user_stmt = $db->prepare($notify_user_sql);
            if (!$notify_user_stmt) {
                throw new Exception("An error occurred while notifying the complainant.");
            }
            $notify_user_stmt->bind_param("isi", $complaint_id, $notification_desc, $complaint_id);
            $notify_user_stmt->execute();
            $notify_user_stmt->close();

            // Notify the user who escalated/assigned the complaint
            $notify_prev_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $notification_desc = "Complaint #$complaint_id, which you " . ($action_type === 'escalation' ? 'escalated' : 'assigned') . ", has been resolved by {$vp['fname']} {$vp['lname']}: $decision_text";
            $notify_prev_user_stmt = $db->prepare($notify_prev_user_sql);
            if (!$notify_prev_user_stmt) {
                throw new Exception("An error occurred while notifying the previous user.");
            }
            $notify_prev_user_stmt->bind_param("iis", $escalated_by_id, $complaint_id, $notification_desc);
            $notify_prev_user_stmt->execute();
            $notify_prev_user_stmt->close();

            // Send stereotyped report to the President
            $additional_info = "Resolved by Administrative Vice President: $decision_text";
            if (!sendStereotypedReport($db, $complaint_id, $vp_id, 'resolved', $additional_info)) {
                throw new Exception("Failed to send the report to the President.");
            }

            $_SESSION['success'] = "Complaint #$complaint_id has been resolved successfully.";
        } else {
            // Escalate to President
            // Fetch the President
            $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
            $result_president = $db->query($sql_president);
            if (!$result_president || $result_president->num_rows === 0) {
                throw new Exception("No user with role 'president' found.");
            }
            $president = $result_president->fetch_assoc();
            $president_id = $president['id'];

            // Mark the current escalation/assignment as forwarded
            $update_escalation_sql = "UPDATE escalations SET status = 'forwarded', resolution_details = ?, resolved_at = NULL WHERE id = ?";
            $update_escalation_stmt = $db->prepare($update_escalation_sql);
            if (!$update_escalation_stmt) {
                throw new Exception("An error occurred while updating the escalation/assignment status.");
            }
            $update_escalation_stmt->bind_param("si", $decision_text, $escalation_id);
            $update_escalation_stmt->execute();
            $update_escalation_stmt->close();

            // Create a new escalation record for the President
            $new_escalation_sql = "INSERT INTO escalations (complaint_id, escalated_by_id, escalated_to_id, escalated_to, department_id, action_type, status, created_at, original_handler_id) 
                                  VALUES (?, ?, ?, 'president', ?, 'escalation', 'pending', NOW(), ?)";
            $new_escalation_stmt = $db->prepare($new_escalation_sql);
            if (!$new_escalation_stmt) {
                throw new Exception("An error occurred while creating a new escalation record.");
            }
            $new_escalation_stmt->bind_param("iiiii", $complaint_id, $vp_id, $president_id, $department_id, $handler_id);
            $new_escalation_stmt->execute();
            $new_escalation_stmt->close();

            // Update complaint status to escalated
            $update_complaint_sql = "UPDATE complaints SET status = 'escalated', resolution_details = NULL, resolution_date = NULL WHERE id = ?";
            $update_complaint_stmt = $db->prepare($update_complaint_sql);
            if (!$update_complaint_stmt) {
                throw new Exception("An error occurred while updating the complaint status.");
            }
            $update_complaint_stmt->bind_param("i", $complaint_id);
            $update_complaint_stmt->execute();
            $update_complaint_stmt->close();

            // Notify the President
            $notify_president_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $notification_desc = "Complaint #$complaint_id has been escalated to you by {$vp['fname']} {$vp['lname']}: $decision_text";
            $notify_president_stmt = $db->prepare($notify_president_sql);
            if (!$notify_president_stmt) {
                throw new Exception("An error occurred while notifying the President.");
            }
            $notify_president_stmt->bind_param("iis", $president_id, $complaint_id, $notification_desc);
            $notify_president_stmt->execute();
            $notify_president_stmt->close();

            // Notify the complainant
            $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) 
                               SELECT user_id, ?, ?, 0, NOW() 
                               FROM complaints WHERE id = ?";
            $notification_desc = "Your complaint #$complaint_id has been escalated to the President by {$vp['fname']} {$vp['lname']}: $decision_text";
            $notify_user_stmt = $db->prepare($notify_user_sql);
            if (!$notify_user_stmt) {
                throw new Exception("An error occurred while notifying the complainant.");
            }
            $notify_user_stmt->bind_param("isi", $complaint_id, $notification_desc, $complaint_id);
            $notify_user_stmt->execute();
            $notify_user_stmt->close();

            // Notify the Handler
            $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $notification_desc = "Complaint #$complaint_id, which you handled, has been escalated to the President by {$vp['fname']} {$vp['lname']}: $decision_text";
            $notify_handler_stmt = $db->prepare($notify_handler_sql);
            if (!$notify_handler_stmt) {
                throw new Exception("An error occurred while notifying the Handler.");
            }
            $notify_handler_stmt->bind_param("iis", $handler_id, $complaint_id, $notification_desc);
            $notify_handler_stmt->execute();
            $notify_handler_stmt->close();

            // Notify the user who escalated/assigned the complaint
            $notify_prev_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $notification_desc = "Complaint #$complaint_id, which you " . ($action_type === 'escalation' ? 'escalated' : 'assigned') . ", has been escalated to the President by {$vp['fname']} {$vp['lname']}: $decision_text";
            $notify_prev_user_stmt = $db->prepare($notify_prev_user_sql);
            if (!$notify_prev_user_stmt) {
                throw new Exception("An error occurred while notifying the previous user.");
            }
            $notify_prev_user_stmt->bind_param("iis", $escalated_by_id, $complaint_id, $notification_desc);
            $notify_prev_user_stmt->execute();
            $notify_prev_user_stmt->close();

            // Send stereotyped report to the President
            $additional_info = "Escalated by Administrative Vice President: $decision_text";
            if (!sendStereotypedReport($db, $complaint_id, $vp_id, 'escalated', $additional_info)) {
                throw new Exception("Failed to send the report to the President.");
            }

            $_SESSION['success'] = "Complaint #$complaint_id has been escalated to the President successfully.";
        }

        $db->commit();
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "Error processing decision: " . $e->getMessage();
        error_log("Decision error: " . $e->getMessage());
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
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
    <title>Decide on Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
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

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        /* Complaint Details */
        .complaint-details {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .complaint-details p {
            margin: 0.5rem 0;
            font-size: 0.95rem;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
            resize: vertical;
            min-height: 120px;
        }

        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
        }

        .form-group select:focus {
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

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, var(--orange) 100%);
            color: var(--dark);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, var(--orange) 0%, var(--warning) 100%);
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
            h3 { font-size: 1.2rem; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .complaint-details p { font-size: 0.9rem; }
            .btn { width: 100%; margin-bottom: 5px; }
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
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
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

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Administrative Vice President</span>
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
            <h2>Decide on Complaint #<?php echo $complaint_id; ?></h2>

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
                <p><strong>Handler:</strong> <?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></p>
                <p><strong><?php echo $action_type === 'escalation' ? 'Escalated' : 'Assigned'; ?> By:</strong> <?php echo htmlspecialchars($dean['fname'] . ' ' . $dean['lname']); ?> (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dean['role']))); ?>)</p>
            </div>

            <!-- Decision Form -->
            <form method="POST" onsubmit="return confirm('Are you sure you want to submit this decision? This action cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="decision_text">Decision/Escalation Details</label>
                    <textarea name="decision_text" id="decision_text" rows="5" required placeholder="Enter your decision or escalation details here..."></textarea>
                </div>
                <div class="form-group">
                    <label for="action">Action</label>
                    <select name="action" id="action" required>
                        <option value="" disabled selected>Select an action</option>
                        <option value="resolve">Resolve Complaint</option>
                        <option value="escalate">Escalate to President</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Submit Decision</button>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
            </form>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 7</div>
                <div class="social-links">
                    <a href="https://github.com"><i class="fab fa-github"></i></a>
                    <a href="https://linkedin.com"><i class="fab fa-linkedin"></i></a>
                    <a href="mailto:group7@example.com"><i class="fas fa-envelope"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint System. All rights reserved.
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