<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'university_registrar'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'university_registrar') {
    header("Location: ../login.php");
    exit;
}

$registrar_id = $_SESSION['user_id'];
$registrar = null;

// Fetch University Registrar details
$sql_registrar = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_registrar = $db->prepare($sql_registrar);
if ($stmt_registrar) {
    $stmt_registrar->bind_param("i", $registrar_id);
    $stmt_registrar->execute();
    $result_registrar = $stmt_registrar->get_result();
    if ($result_registrar->num_rows > 0) {
        $registrar = $result_registrar->fetch_assoc();
    } else {
        $_SESSION['error'] = "University Registrar details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_registrar->close();
} else {
    error_log("Error preparing University Registrar query: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching your details. Please try again later.";
    header("Location: dashboard.php");
    exit;
}

// Validate complaint_id and escalation_id
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
$escalation_id = filter_input(INPUT_GET, 'escalation_id', FILTER_VALIDATE_INT);

if (!$complaint_id || !$escalation_id) {
    $_SESSION['error'] = "Invalid complaint or escalation ID.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint and escalation details
$complaint_query = "
    SELECT c.id, c.user_id, c.title, c.description, c.category, c.status, c.created_at, 
           e.escalated_by_id, e.department_id, e.original_handler_id, e.action_type, e.status as escalation_status
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.id = ? AND e.escalated_to_id = ? AND e.escalated_to = 'university_registrar'";
$stmt_complaint = $db->prepare($complaint_query);
if (!$stmt_complaint) {
    error_log("Prepare failed for complaint fetch: " . $db->error);
    $_SESSION['error'] = "An error occurred while fetching complaint details.";
    header("Location: dashboard.php");
    exit;
}
$stmt_complaint->bind_param("iii", $complaint_id, $escalation_id, $registrar_id);
$stmt_complaint->execute();
$complaint_result = $stmt_complaint->get_result();
if ($complaint_result->num_rows === 0) {
    $debug_query = "
        SELECT c.id as complaint_exists, e.id as escalation_exists, e.escalated_to_id, e.escalated_to, e.status
        FROM complaints c
        LEFT JOIN escalations e ON c.id = e.complaint_id AND e.id = ?
        WHERE c.id = ?";
    $debug_stmt = $db->prepare($debug_query);
    $debug_stmt->bind_param("ii", $escalation_id, $complaint_id);
    $debug_stmt->execute();
    $debug_result = $debug_stmt->get_result()->fetch_assoc();
    $debug_info = "Complaint ID: $complaint_id, Escalation ID: $escalation_id, Registrar ID: $registrar_id\n";
    $debug_info .= "Complaint Exists: " . ($debug_result['complaint_exists'] ? 'Yes' : 'No') . "\n";
    $debug_info .= "Escalation Exists: " . ($debug_result['escalation_exists'] ? 'Yes' : 'No') . "\n";
    $debug_info .= "Escalated To ID: " . ($debug_result['escalated_to_id'] ?? 'N/A') . "\n";
    $debug_info .= "Escalated To: " . ($debug_result['escalated_to'] ?? 'N/A') . "\n";
    $debug_info .= "Escalation Status: " . ($debug_result['status'] ?? 'N/A');
    error_log("Complaint access error: " . $debug_info);
    $_SESSION['error'] = "Complaint not found or not accessible.";
    header("Location: dashboard.php");
    exit;
}
$complaint = $complaint_result->fetch_assoc();
$stmt_complaint->close();

// Fetch the user who escalated the complaint to University Registrar (could be Campus Registrar or Handler)
$escalator = null;
$send_back_to_escalator_id = $complaint['escalated_by_id'];
$escalator_role = null;
$can_send_back_to_escalator = false;

if ($send_back_to_escalator_id) {
    $escalator_query = "SELECT fname, lname, role FROM users WHERE id = ?";
    $escalator_stmt = $db->prepare($escalator_query);
    if (!$escalator_stmt) {
        error_log("Prepare failed for escalator fetch: " . $db->error);
        $_SESSION['error'] = "An error occurred while fetching the escalator's details.";
        header("Location: dashboard.php");
        exit;
    }
    $escalator_stmt->bind_param("i", $send_back_to_escalator_id);
    $escalator_stmt->execute();
    $escalator_result = $escalator_stmt->get_result();
    if ($escalator_result->num_rows > 0) {
        $escalator = $escalator_result->fetch_assoc();
        $escalator_role = $escalator['role'];
        if (in_array($escalator_role, ['campus_registrar', 'handler'])) {
            $can_send_back_to_escalator = true;
        } else {
            error_log("Escalator with ID $send_back_to_escalator_id for complaint #$complaint_id has invalid role: $escalator_role");
        }
    } else {
        error_log("Escalator with ID $send_back_to_escalator_id for complaint #$complaint_id not found in users table.");
    }
    $escalator_stmt->close();
} else {
    error_log("No escalated_by_id found for complaint #$complaint_id in escalations table.");
}

// Fetch original handler details (for "Send Back" option to original handler)
$handler = null;
$send_back_to_handler_id = $complaint['original_handler_id'];
$can_send_back_to_handler = false;

if ($send_back_to_handler_id) {
    $handler_query = "SELECT fname, lname, role FROM users WHERE id = ?";
    $handler_stmt = $db->prepare($handler_query);
    if (!$handler_stmt) {
        error_log("Prepare failed for handler fetch: " . $db->error);
        $_SESSION['error'] = "An error occurred while fetching the original handler details.";
        header("Location: dashboard.php");
        exit;
    }
    $handler_stmt->bind_param("i", $send_back_to_handler_id);
    $handler_stmt->execute();
    $handler_result = $handler_stmt->get_result();
    if ($handler_result->num_rows > 0) {
        $handler = $handler_result->fetch_assoc();
        if ($handler['role'] === 'handler') {
            $can_send_back_to_handler = true;
        } else {
            error_log("Original handler with ID $send_back_to_handler_id for complaint #$complaint_id has invalid role: " . $handler['role']);
        }
    } else {
        error_log("Original handler with ID $send_back_to_handler_id for complaint #$complaint_id not found in users table.");
    }
    $handler_stmt->close();
} else {
    error_log("No original_handler_id found for complaint #$complaint_id in escalations table.");
}

// Check if "Send Back" is possible at all
if (!$can_send_back_to_escalator && !$can_send_back_to_handler) {
    error_log("Cannot determine any recipient for sending back complaint #$complaint_id. Escalator: " . ($can_send_back_to_escalator ? 'Found' : 'Not Found') . ", Original Handler: " . ($can_send_back_to_handler ? 'Found' : 'Not Found'));
    $_SESSION['error'] = "Error: Cannot determine any recipient to send the decision back to.";
    header("Location: dashboard.php");
    exit;
}

// Fetch users for escalation (only Academic VP)
$roles_to_escalate = ['academic_vp'];
$escalation_options = [];

foreach ($roles_to_escalate as $role) {
    $sql_escalate_options = "SELECT id, fname, lname, role FROM users WHERE role = ? ORDER BY fname, lname";
    $stmt_escalate_options = $db->prepare($sql_escalate_options);
    if (!$stmt_escalate_options) {
        error_log("Prepare failed for role fetch ($role): " . $db->error);
        continue;
    }
    $stmt_escalate_options->bind_param("s", $role);
    $stmt_escalate_options->execute();
    $result_escalate_options = $stmt_escalate_options->get_result();
    while ($user_option = $result_escalate_options->fetch_assoc()) {
        $escalation_options[$role][] = $user_option;
    }
    $stmt_escalate_options->close();
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '') {
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

    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) {
        error_log("No user with role 'president' found.");
        $_SESSION['error'] = "No President found to receive the report.";
        return false;
    }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];

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
    $submitted_csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $resolution_details = trim(filter_input(INPUT_POST, 'resolution_details', FILTER_SANITIZE_SPECIAL_CHARS));
    $escalated_to_role = filter_input(INPUT_POST, 'escalated_to_role', FILTER_SANITIZE_STRING);
    $escalated_to_id = filter_input(INPUT_POST, 'escalated_to_id', FILTER_VALIDATE_INT);
    $send_back_to = filter_input(INPUT_POST, 'send_back_to', FILTER_SANITIZE_STRING);

    $errors = [];

    if (!$submitted_csrf_token || $submitted_csrf_token !== $csrf_token) {
        $errors[] = "Invalid CSRF token. Please try again.";
    }

    if (!$action || !in_array($action, ['resolve', 'send_back', 'escalate'])) {
        $errors[] = "Invalid action selected.";
    }

    if (empty($resolution_details)) {
        $errors[] = "Please provide resolution details.";
    } elseif (strlen($resolution_details) < 10) {
        $errors[] = "Resolution details must be at least 10 characters long.";
    } elseif (strlen($resolution_details) > 1000) {
        $errors[] = "Resolution details cannot exceed 1000 characters.";
    }

    if ($action === 'escalate') {
        if (!$escalated_to_role || !$escalated_to_id || !in_array($escalated_to_role, $roles_to_escalate)) {
            $errors[] = "Invalid escalation target selected.";
        }
    }

    if ($action === 'send_back') {
        if (!$send_back_to || !in_array($send_back_to, ['escalator', 'original_handler'])) {
            $errors[] = "Invalid send back target selected.";
        }
    }

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            if ($action === 'resolve') {
                // Mark the escalation as resolved
                $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
                $update_escalation_stmt = $db->prepare($update_escalation_sql);
                if (!$update_escalation_stmt) {
                    throw new Exception("An error occurred while updating the escalation status.");
                }
                $update_escalation_stmt->bind_param("si", $resolution_details, $escalation_id);
                $update_escalation_stmt->execute();
                $update_escalation_stmt->close();

                // Update complaint status to resolved
                $update_complaint_sql = "UPDATE complaints SET status = 'resolved', resolution_details = ?, resolution_date = NOW() WHERE id = ?";
                $update_complaint_stmt = $db->prepare($update_complaint_sql);
                if (!$update_complaint_stmt) {
                    throw new Exception("An error occurred while updating the complaint status.");
                }
                $update_complaint_stmt->bind_param("si", $resolution_details, $complaint_id);
                $update_complaint_stmt->execute();
                $update_complaint_stmt->close();

                // Notify the complainant
                $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $notification_desc = "Your complaint #$complaint_id has been resolved by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
                $notify_user_stmt = $db->prepare($notify_user_sql);
                if (!$notify_user_stmt) {
                    throw new Exception("An error occurred while notifying the complainant.");
                }
                $notify_user_stmt->bind_param("iis", $complaint['user_id'], $complaint_id, $notification_desc);
                $notify_user_stmt->execute();
                $notify_user_stmt->close();

                // Notify the escalator (Campus Registrar or Handler)
                if ($can_send_back_to_escalator && $send_back_to_escalator_id != $registrar_id) {
                    $notify_escalator_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                    $notification_desc = "Complaint #$complaint_id, which you escalated, has been resolved by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
                    $notify_escalator_stmt = $db->prepare($notify_escalator_sql);
                    if (!$notify_escalator_stmt) {
                        throw new Exception("An error occurred while notifying the escalator.");
                    }
                    $notify_escalator_stmt->bind_param("iis", $send_back_to_escalator_id, $complaint_id, $notification_desc);
                    $notify_escalator_stmt->execute();
                    $notify_escalator_stmt->close();
                }

                // Notify the original handler (if not already notified as the escalator)
                if ($can_send_back_to_handler && $send_back_to_handler_id != $registrar_id && $send_back_to_handler_id != $send_back_to_escalator_id) {
                    $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                    $notification_desc = "Complaint #$complaint_id, which you handled, has been resolved by {$registrar['fname']} {$registrar['lname']}: $resolution_details";
                    $notify_handler_stmt = $db->prepare($notify_handler_sql);
                    if (!$notify_handler_stmt) {
                        throw new Exception("An error occurred while notifying the handler.");
                    }
                    $notify_handler_stmt->bind_param("iis", $send_back_to_handler_id, $complaint_id, $notification_desc);
                    $notify_handler_stmt->execute();
                    $notify_handler_stmt->close();
                }

                // Send stereotyped report to the President
                $additional_info = "Resolved: $resolution_details";
                if (!sendStereotypedReport($db, $complaint_id, $registrar_id, 'resolved', $additional_info)) {
                    throw new Exception("Failed to send the report to the President.");
                }

                $_SESSION['success'] = "Complaint #$complaint_id has been resolved successfully.";

            } elseif ($action === 'send_back') {
                $send_back_to_id = ($send_back_to === 'escalator') ? $send_back_to_escalator_id : $send_back_to_handler_id;
                $send_back_to_role = ($send_back_to === 'escalator') ? ucfirst(str_replace('_', ' ', $escalator_role)) : 'Original Handler';

                // Insert decision record
                $decision_sql = "INSERT INTO decisions (complaint_id, escalation_id, sender_id, receiver_id, decision_text, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
                $stmt_decision = $db->prepare($decision_sql);
                if (!$stmt_decision) {
                    throw new Exception("An error occurred while recording the decision.");
                }
                $stmt_decision->bind_param("iiiis", $complaint_id, $escalation_id, $registrar_id, $send_back_to_id, $resolution_details);
                $stmt_decision->execute();
                $stmt_decision->close();

                // Mark the current escalation as resolved
                $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
                $update_escalation_stmt = $db->prepare($update_escalation_sql);
                if (!$update_escalation_stmt) {
                    throw new Exception("An error occurred while updating the escalation status.");
                }
                $res_detail = "Sent back to $send_back_to_role by University Registrar with decision: $resolution_details";
                $update_escalation_stmt->bind_param("si", $res_detail, $escalation_id);
                $update_escalation_stmt->execute();
                $update_escalation_stmt->close();

                // Update complaint status to pending
                $update_complaint_sql = "UPDATE complaints SET status = 'pending' WHERE id = ?";
                $update_complaint_stmt = $db->prepare($update_complaint_sql);
                if (!$update_complaint_stmt) {
                    throw new Exception("An error occurred while updating the complaint status.");
                }
                $update_complaint_stmt->bind_param("i", $complaint_id);
                $update_complaint_stmt->execute();
                $update_complaint_stmt->close();

                // Notify the send-back recipient
                $notify_recipient_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $recipient_notification_desc = "A decision requires your attention for Complaint #$complaint_id (sent by University Registrar): $resolution_details";
                $notify_recipient_stmt = $db->prepare($notify_recipient_sql);
                if (!$notify_recipient_stmt) {
                    throw new Exception("An error occurred while notifying the $send_back_to_role.");
                }
                $notify_recipient_stmt->bind_param("iis", $send_back_to_id, $complaint_id, $recipient_notification_desc);
                $notify_recipient_stmt->execute();
                $notify_recipient_stmt->close();

                // Notify the complainant
                $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $user_notification_desc = "Your Complaint (#$complaint_id: {$complaint['title']}) has been reviewed by the University Registrar and sent back to the $send_back_to_role for further action.";
                $notify_user_stmt = $db->prepare($notify_user_sql);
                if (!$notify_user_stmt) {
                    throw new Exception("An error occurred while notifying the complainant.");
                }
                $notify_user_stmt->bind_param("iis", $complaint['user_id'], $complaint_id, $user_notification_desc);
                $notify_user_stmt->execute();
                $notify_user_stmt->close();

                // Notify the other party (if sending to escalator, notify original handler, and vice versa)
                $other_party_id = ($send_back_to === 'escalator') ? $send_back_to_handler_id : $send_back_to_escalator_id;
                $other_party_role = ($send_back_to === 'escalator') ? 'Original Handler' : ucfirst(str_replace('_', ' ', $escalator_role));
                if ($other_party_id && $other_party_id != $registrar_id && $other_party_id != $send_back_to_id) {
                    $notify_other_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                    $other_notification_desc = "Complaint #$complaint_id has been sent back to the $send_back_to_role by the University Registrar: $resolution_details";
                    $notify_other_stmt = $db->prepare($notify_other_sql);
                    if (!$notify_other_stmt) {
                        throw new Exception("An error occurred while notifying the $other_party_role.");
                    }
                    $notify_other_stmt->bind_param("iis", $other_party_id, $complaint_id, $other_notification_desc);
                    $notify_other_stmt->execute();
                    $notify_other_stmt->close();
                }

                // Send stereotyped report to the President
                $additional_info = "Sent back to $send_back_to_role: $resolution_details";
                if (!sendStereotypedReport($db, $complaint_id, $registrar_id, 'decision_sent_back', $additional_info)) {
                    throw new Exception("Failed to send the report to the President.");
                }

                $_SESSION['success'] = "Decision for Complaint #$complaint_id has been sent back to the $send_back_to_role successfully.";

            } elseif ($action === 'escalate') {
                // Insert new escalation record
                $escalation_sql = "INSERT INTO escalations (complaint_id, escalated_to, escalated_to_id, escalated_by_id, status, original_handler_id, action_type, created_at)
                                   VALUES (?, ?, ?, ?, 'pending', ?, 'escalation', NOW())";
                $stmt_escalation = $db->prepare($escalation_sql);
                if (!$stmt_escalation) {
                    throw new Exception("An error occurred while creating the escalation.");
                }
                $stmt_escalation->bind_param("isiii", $complaint_id, $escalated_to_role, $escalated_to_id, $registrar_id, $complaint['original_handler_id']);
                $stmt_escalation->execute();
                $stmt_escalation->close();

                // Mark the current escalation as resolved
                $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
                $update_escalation_stmt = $db->prepare($update_escalation_sql);
                if (!$update_escalation_stmt) {
                    throw new Exception("An error occurred while updating the escalation status.");
                }
                $res_detail = "Escalated to Academic Vice President by University Registrar. Reason: $resolution_details";
                $update_escalation_stmt->bind_param("si", $res_detail, $escalation_id);
                $update_escalation_stmt->execute();
                $update_escalation_stmt->close();

                // Update complaint status to in_progress
                $update_complaint_sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
                $update_complaint_stmt = $db->prepare($update_complaint_sql);
                if (!$update_complaint_stmt) {
                    throw new Exception("An error occurred while updating the complaint status.");
                }
                $update_complaint_stmt->bind_param("i", $complaint_id);
                $update_complaint_stmt->execute();
                $update_complaint_stmt->close();

                // Notify the escalated-to user (Academic VP)
                $notify_escalated_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $escalated_notification_desc = "Complaint #$complaint_id has been escalated to you (Academic Vice President) by the University Registrar for review. Reason: $resolution_details";
                $notify_escalated_stmt = $db->prepare($notify_escalated_sql);
                if (!$notify_escalated_stmt) {
                    throw new Exception("An error occurred while notifying the Academic Vice President.");
                }
                $notify_escalated_stmt->bind_param("iis", $escalated_to_id, $complaint_id, $escalated_notification_desc);
                $notify_escalated_stmt->execute();
                $notify_escalated_stmt->close();

                // Notify the complainant
                $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $user_notification_desc = "Your Complaint (#$complaint_id: {$complaint['title']}) has been escalated to the Academic Vice President by the University Registrar.";
                $notify_user_stmt = $db->prepare($notify_user_sql);
                if (!$notify_user_stmt) {
                    throw new Exception("An error occurred while notifying the complainant.");
                }
                $notify_user_stmt->bind_param("iis", $complaint['user_id'], $complaint_id, $user_notification_desc);
                $notify_user_stmt->execute();
                $notify_user_stmt->close();

                // Notify the escalator (Campus Registrar or Handler)
                if ($can_send_back_to_escalator && $send_back_to_escalator_id != $registrar_id) {
                    $notify_escalator_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                    $escalator_notification_desc = "Complaint #$complaint_id, which you escalated, has been further escalated to the Academic Vice President by the University Registrar. Reason: $resolution_details";
                    $notify_escalator_stmt = $db->prepare($notify_escalator_sql);
                    if (!$notify_escalator_stmt) {
                        throw new Exception("An error occurred while notifying the escalator.");
                    }
                    $notify_escalator_stmt->bind_param("iis", $send_back_to_escalator_id, $complaint_id, $escalator_notification_desc);
                    $notify_escalator_stmt->execute();
                    $notify_escalator_stmt->close();
                }

                // Notify the original handler (if not already notified as the escalator)
                if ($can_send_back_to_handler && $send_back_to_handler_id != $registrar_id && $send_back_to_handler_id != $send_back_to_escalator_id) {
                    $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                    $handler_notification_desc = "Complaint #$complaint_id, which you handled, has been escalated by the University Registrar to the Academic Vice President. Reason: $resolution_details";
                    $notify_handler_stmt = $db->prepare($notify_handler_sql);
                    if (!$notify_handler_stmt) {
                        throw new Exception("An error occurred while notifying the handler.");
                    }
                    $notify_handler_stmt->bind_param("iis", $send_back_to_handler_id, $complaint_id, $handler_notification_desc);
                    $notify_handler_stmt->execute();
                    $notify_handler_stmt->close();
                }

                // Send stereotyped report to the President
                $additional_info = "Escalated by University Registrar to Academic Vice President. Reason: $resolution_details";
                if (!sendStereotypedReport($db, $complaint_id, $registrar_id, 'escalated', $additional_info)) {
                    throw new Exception("Failed to send the report to the President.");
                }

                $_SESSION['success'] = "Complaint #$complaint_id has been escalated to the Academic Vice President successfully.";
            }

            $db->commit();
            header("Location: dashboard.php");
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "Error processing complaint: " . $e->getMessage();
            error_log("Processing error: " . $e->getMessage());
            header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
            exit;
        }
    } else {
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
}

// Retrieve errors and form data from session if they exist
$form_errors = $_SESSION['form_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

$display_resolution_details = $form_data['resolution_details'] ?? '';
$display_action = $form_data['action'] ?? '';
$display_escalated_role = $form_data['escalated_to_role'] ?? '';
$display_escalated_id = $form_data['escalated_to_id'] ?? '';
$display_send_back_to = $form_data['send_back_to'] ?? '';

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $registrar_id);
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
    <title>Decide Complaint | DMU Complaint System</title>
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

        .alert ul {
            margin: 0;
            padding-left: 20px;
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

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.6rem;
            color: var(--primary-dark);
        }

        .form-group textarea, .form-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group textarea:focus, .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .form-group select option[disabled] {
            color: #999;
            font-style: italic;
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

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            transform: translateY(-1px);
        }

        #escalation-options, #send-back-options {
            display: none;
            border-left: 3px solid var(--secondary);
            padding-left: 1rem;
            margin-top: 1rem;
            background-color: #faf9ff;
            padding-top: 0.5rem;
            padding-bottom: 0.1rem;
            border-radius: 0 var(--radius) var(--radius) 0;
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
            margin | 0 auto;
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
                    <h4><?php echo htmlspecialchars($registrar['fname'] . ' ' . $registrar['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $registrar['role']))); ?></p>
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
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
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
                <span>DMU Complaint System - University Registrar</span>
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
            <h2>Decide on Complaint #<?php echo htmlspecialchars($complaint['id']); ?></h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($form_errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        Please correct the following errors:
                        <ul>
                            <?php foreach ($form_errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'Not categorized')); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted On:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <h3>Submit Your Decision</h3>
            <form method="POST" action="decide_complaint.php?complaint_id=<?php echo $complaint_id; ?>&escalation_id=<?php echo $escalation_id; ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="resolution_details">Decision / Reason / Resolution Details *</label>
                    <textarea name="resolution_details" id="resolution_details" rows="5" required placeholder="Enter your decision, reason for sending back, or reason for escalation here..."><?php echo htmlspecialchars($display_resolution_details); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="action">Action *</label>
                    <select name="action" id="action" required onchange="toggleAdditionalOptions()">
                        <option value="" disabled <?php echo empty($display_action) ? 'selected' : ''; ?>>-- Select an Action --</option>
                        <option value="resolve" <?php echo $display_action === 'resolve' ? 'selected' : ''; ?>>Resolve Complaint</option>
                        <option value="send_back" <?php echo $display_action === 'send_back' ? 'selected' : ''; ?>>Send Back</option>
                        <option value="escalate" <?php echo $display_action === 'escalate' ? 'selected' : ''; ?>>Escalate to Academic Vice President</option>
                    </select>
                </div>

                <div id="send-back-options">
                    <div class="form-group">
                        <label for="send_back_to">Send Back To *</label>
                        <select name="send_back_to" id="send_back_to">
                            <option value="" disabled <?php echo empty($display_send_back_to) ? 'selected' : ''; ?>>-- Select Recipient --</option>
                            <?php if ($can_send_back_to_escalator): ?>
                                <option value="escalator" <?php echo $display_send_back_to === 'escalator' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $escalator_role))) . " (" . $escalator['fname'] . " " . $escalator['lname'] . ")"; ?>
                                </option>
                            <?php endif; ?>
                            <?php if ($can_send_back_to_handler && $send_back_to_handler_id != $send_back_to_escalator_id): ?>
                                <option value="original_handler" <?php echo $display_send_back_to === 'original_handler' ? 'selected' : ''; ?>>
                                    Original Handler (<?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?>)
                                </option>
                            <?php endif; ?>
                            <?php if (!$can_send_back_to_escalator && !$can_send_back_to_handler): ?>
                                <option value="" disabled>No recipients available to send back to.</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div id="escalation-options">
                    <div class="form-group">
                        <label for="escalation_target_select">Select Academic Vice President *</label>
                        <select name="escalation_target_select" id="escalation_target_select" onchange="updateEscalationFields(this)">
                            <option value="" disabled selected>-- Select Academic Vice President --</option>
                            <?php if (!empty($escalation_options['academic_vp'])): ?>
                                <?php foreach ($escalation_options['academic_vp'] as $user_option): ?>
                                    <?php
                                        $option_value = 'academic_vp|' . $user_option['id'];
                                        $is_selected = ($display_escalated_role === 'academic_vp' && (int)$display_escalated_id === $user_option['id']);
                                    ?>
                                    <option value="<?php echo $option_value; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars("{$user_option['fname']} {$user_option['lname']}"); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No Academic Vice Presidents found.</option>
                            <?php endif; ?>
                        </select>
                        <input type="hidden" name="escalated_to_role" id="escalated_to_role" value="<?php echo htmlspecialchars($display_escalated_role); ?>">
                        <input type="hidden" name="escalated_to_id" id="escalated_to_id" value="<?php echo htmlspecialchars($display_escalated_id); ?>">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Action</button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
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

    <script>
        function toggleAdditionalOptions() {
            const actionSelect = document.getElementById('action');
            const escalationOptionsDiv = document.getElementById('escalation-options');
            const sendBackOptionsDiv = document.getElementById('send-back-options');
            const escalationTargetSelect = document.getElementById('escalation_target_select');
            const escalationRoleInput = document.getElementById('escalated_to_role');
            const escalationIdInput = document.getElementById('escalated_to_id');
            const sendBackSelect = document.getElementById('send_back_to');

            if (!actionSelect || !escalationOptionsDiv || !sendBackOptionsDiv || !escalationTargetSelect || !escalationRoleInput || !escalationIdInput || !sendBackSelect) {
                console.error("One or more form elements are missing in toggleAdditionalOptions.");
                return;
            }

            escalationOptionsDiv.style.display = 'none';
            sendBackOptionsDiv.style.display = 'none';
            escalationTargetSelect.removeAttribute('required');
            sendBackSelect.removeAttribute('required');
            escalationRoleInput.value = '';
            escalationIdInput.value = '';
            sendBackSelect.value = '';

            if (actionSelect.value === 'escalate') {
                escalationOptionsDiv.style.display = 'block';
                escalationTargetSelect.setAttribute('required', 'required');
            } else if (actionSelect.value === 'send_back') {
                sendBackOptionsDiv.style.display = 'block';
                sendBackSelect.setAttribute('required', 'required');
            }
        }

        function updateEscalationFields(selectElement) {
            const selectedValue = selectElement.value;
            const escalationRoleInput = document.getElementById('escalated_to_role');
            const escalationIdInput = document.getElementById('escalated_to_id');

            if (!escalationRoleInput || !escalationIdInput) {
                console.error("Escalation role or ID input elements are missing in updateEscalationFields.");
                return;
            }

            if (selectedValue) {
                const parts = selectedValue.split('|');
                if (parts.length === 2) {
                    escalationRoleInput.value = parts[0];
                    escalationIdInput.value = parts[1];
                } else {
                    console.error("Invalid option value format:", selectedValue);
                    escalationRoleInput.value = '';
                    escalationIdInput.value = '';
                }
            } else {
                escalationRoleInput.value = '';
                escalationIdInput.value = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize form behavior
            toggleAdditionalOptions();
            const escalationTargetSelect = document.getElementById('escalation_target_select');
            if (escalationTargetSelect) {
                updateEscalationFields(escalationTargetSelect);
            } else {
                console.error("Escalation target select element not found.");
            }

            // Handle alerts
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

            // Handle form submission with confirmation
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(event) {
                    const actionSelect = document.getElementById('action');
                    if (!actionSelect) {
                        console.error("Action select element not found during form submission.");
                        event.preventDefault();
                        return;
                    }
                    const action = actionSelect.value;
                    let confirmMessage = '';
                    if (action === 'resolve') {
                        confirmMessage = 'Are you sure you want to resolve this complaint? This action cannot be undone.';
                    } else if (action === 'send_back') {
                        const sendBackSelect = document.getElementById('send_back_to');
                        const sendBackTo = sendBackSelect.options[sendBackSelect.selectedIndex].text;
                        confirmMessage = `Are you sure you want to send this complaint back to the ${sendBackTo}?`;
                    } else if (action === 'escalate') {
                        confirmMessage = 'Are you sure you want to escalate this complaint to the Academic Vice President?';
                    }
                    if (confirmMessage && !confirm(confirmMessage)) {
                        event.preventDefault();
                    }
                });
            } else {
                console.error("Form element not found on the page.");
            }
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>