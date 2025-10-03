<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'college_dean'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'college_dean') {
    header("Location: ../unauthorized.php");
    exit;
}

$dean_id = $_SESSION['user_id'];
$dean = null;

// Fetch College Dean details
$sql_dean = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_dean = $db->prepare($sql_dean);
if ($stmt_dean) {
    $stmt_dean->bind_param("i", $dean_id);
    $stmt_dean->execute();
    $result_dean = $stmt_dean->get_result();
    if ($result_dean->num_rows > 0) {
        $dean = $result_dean->fetch_assoc();
    } else {
        $_SESSION['error'] = "College Dean details not found.";
        error_log("College Dean details not found for ID: " . $dean_id);
        header("Location: ../logout.php");
        exit;
    }
    $stmt_dean->close();
} else {
    error_log("Error preparing college dean query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Check if complaint_id and either escalation_id or decision_id are provided
$complaint = null;
$decision = null;
$send_back_to_id = null;
$is_decision_context = false;

if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['info'] = "Please select a complaint to decide on from the dashboard.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];
$escalation_id = isset($_GET['escalation_id']) && is_numeric($_GET['escalation_id']) ? (int)$_GET['escalation_id'] : null;
$decision_id = isset($_GET['decision_id']) && is_numeric($_GET['decision_id']) ? (int)$_GET['decision_id'] : null;

if (!$escalation_id && !$decision_id) {
    $_SESSION['info'] = "Please provide an escalation or decision ID to proceed.";
    header("Location: dashboard.php");
    exit;
}

// Fetch complaint details based on decision or escalation
if ($decision_id) {
    // Handle handler response (decision where Dean is receiver)
    $stmt_complaint_details = $db->prepare("
        SELECT c.id, c.title, c.description, c.category, c.status as complaint_status, c.created_at, c.user_id as complainant_id,
               u_complainant.fname as complainant_fname, u_complainant.lname as complainant_lname,
               d.id as decision_id, d.decision_text, d.created_at as decision_created_at, d.sender_id as escalator_id,
               u_sender.fname as escalator_fname, u_sender.lname as escalator_lname, u_sender.role as escalator_role
        FROM complaints c
        JOIN decisions d ON c.id = d.complaint_id
        JOIN users u_complainant ON c.user_id = u_complainant.id
        JOIN users u_sender ON d.sender_id = u_sender.id
        WHERE c.id = ?
          AND d.id = ?
          AND d.receiver_id = ?
          AND d.status = 'pending'
    ");
    if (!$stmt_complaint_details) {
        error_log("Prepare failed for decision complaint details: " . $db->error);
        $_SESSION['error'] = "Database error while preparing complaint fetch.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_complaint_details->bind_param("iii", $complaint_id, $decision_id, $dean_id);
    $stmt_complaint_details->execute();
    $result_complaint = $stmt_complaint_details->get_result();
    if ($result_complaint->num_rows === 0) {
        $_SESSION['error'] = "Complaint #$complaint_id (Decision #$decision_id) not found or not assigned to you.";
        $stmt_complaint_details->close();
        header("Location: dashboard.php");
        exit;
    }
    $complaint = $result_complaint->fetch_assoc();
    $decision = [
        'id' => $complaint['decision_id'],
        'decision_text' => $complaint['decision_text'],
        'created_at' => $complaint['decision_created_at'],
        'sender_id' => $complaint['escalator_id'],
        'sender_fname' => $complaint['escalator_fname'],
        'sender_lname' => $complaint['escalator_lname'],
        'sender_role' => $complaint['escalator_role']
    ];
    $send_back_to_id = $complaint['escalator_id']; // Handler who sent the response
    $is_decision_context = true;
    $stmt_complaint_details->close();
} else {
    // Existing escalation logic
    $stmt_complaint_details = $db->prepare("
        SELECT c.id, c.title, c.description, c.category, c.status as complaint_status, c.created_at, c.user_id as complainant_id,
               u_complainant.fname as complainant_fname, u_complainant.lname as complainant_lname,
               e.id as current_escalation_id, e.status as escalation_status, e.escalated_by_id, e.original_handler_id,
               u_escalator.fname as escalator_fname, u_escalator.lname as escalator_lname, u_escalator.role as escalator_role
        FROM complaints c
        JOIN escalations e ON c.id = e.complaint_id
        JOIN users u_complainant ON c.user_id = u_complainant.id
        LEFT JOIN users u_escalator ON e.escalated_by_id = u_escalator.id
        WHERE c.id = ?
          AND e.id = ?
          AND e.escalated_to = 'college_dean'
          AND e.escalated_to_id = ?
          AND e.status = 'pending'
    ");
    if (!$stmt_complaint_details) {
        error_log("Prepare failed for escalation complaint details: " . $db->error);
        $_SESSION['error'] = "Database error while preparing complaint fetch.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_complaint_details->bind_param("iii", $complaint_id, $escalation_id, $dean_id);
    $stmt_complaint_details->execute();
    $result_complaint = $stmt_complaint_details->get_result();
    if ($result_complaint->num_rows === 0) {
        $_SESSION['error'] = "Complaint #$complaint_id (Escalation #$escalation_id) not found, not assigned to you, or already processed.";
        $stmt_complaint_details->close();
        header("Location: dashboard.php");
        exit;
    }
    $complaint = $result_complaint->fetch_assoc();
    $send_back_to_id = $complaint['escalated_by_id'] ?? $complaint['original_handler_id'];
    if (!$send_back_to_id) {
        error_log("Cannot determine recipient for sending back complaint #$complaint_id. Escalator: {$complaint['escalated_by_id']}, Handler: {$complaint['original_handler_id']}");
        $_SESSION['error'] = "Error: Cannot determine the recipient to send the decision back to.";
    }
    $stmt_complaint_details->close();
}

// Fetch users for escalation (only Academic Vice President)
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
    while ($user = $result_escalate_options->fetch_assoc()) {
        $escalation_options[$role][] = $user;
    }
    $stmt_escalate_options->close();
}

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '', $handler_response = '') {
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    if (!$stmt_complaint) {
        error_log("Prepare failed for report complaint fetch: " . $db->error);
        return;
    }
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) {
        error_log("Complaint #$complaint_id not found for report.");
        $stmt_complaint->close();
        return;
    }
    $complaint_data = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    $sql_sender = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_sender = $db->prepare($sql_sender);
    if (!$stmt_sender) {
        error_log("Prepare failed for report sender fetch: " . $db->error);
        return;
    }
    $stmt_sender->bind_param("i", $sender_id);
    $stmt_sender->execute();
    $sender_result = $stmt_sender->get_result();
    if ($sender_result->num_rows === 0) {
        error_log("Sender #$sender_id not found for report.");
        $stmt_sender->close();
        return;
    }
    $sender = $sender_result->fetch_assoc();
    $stmt_sender->close();

    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) {
        error_log("No user with role 'president' found for report.");
        return;
    }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];

    $report_content = "Complaint Report\n" .
                      "----------------\n" .
                      "Report Type: " . ucfirst($report_type) . "\n" .
                      "Complaint ID: {$complaint_data['id']}\n" .
                      "Title: {$complaint_data['title']}\n" .
                      "Description: {$complaint_data['description']}\n" .
                      "Category: " . ($complaint_data['category'] ? ucfirst($complaint_data['category']) : 'N/A') . "\n" .
                      "Status: " . ucfirst($complaint_data['status']) . "\n" .
                      "Submitted By: {$complaint_data['submitter_fname']} {$complaint_data['submitter_lname']}\n" .
                      "Processed By: {$sender['fname']} {$sender['lname']} (College Dean)\n" .
                      "Created At: " . date('M j, Y H:i', strtotime($complaint_data['created_at'])) . "\n";
    if ($handler_response) {
        $report_content .= "Handler Response: $handler_response\n";
    }
    if ($additional_info) {
        $report_content .= "Additional Info/Decision: $additional_info\n";
    }

    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt_report = $db->prepare($sql_report);
    if (!$stmt_report) {
        error_log("Prepare failed for report insertion: " . $db->error);
        return;
    }
    $stmt_report->bind_param("iiiss", $complaint_id, $sender_id, $recipient_id, $report_type, $report_content);
    if (!$stmt_report->execute()) {
        error_log("Execute failed for report insertion: " . $stmt_report->error);
    }
    $stmt_report->close();

    $notification_desc = "A new '$report_type' report for Complaint #{$complaint_data['id']} has been submitted by College Dean {$sender['fname']} {$sender['lname']}.";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
    $stmt_notify = $db->prepare($sql_notify);
    if ($stmt_notify) {
        $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
        if (!$stmt_notify->execute()) {
            error_log("Execute failed for president notification: " . $stmt_notify->error);
        }
        $stmt_notify->close();
    } else {
        error_log("Prepare failed for president notification: " . $db->error);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $decision_text = trim(filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_STRING));
    $escalated_to_role = filter_input(INPUT_POST, 'escalated_to_role', FILTER_SANITIZE_STRING);
    $escalated_to_id = filter_input(INPUT_POST, 'escalated_to_id', FILTER_VALIDATE_INT);

    $errors = [];

    if (!$action || !in_array($action, ['resolve', 'send_back', 'escalate'])) {
        $errors[] = "Invalid action selected.";
    }
    if (empty($decision_text)) {
        $errors[] = "Decision/Resolution details cannot be empty.";
    }
    if ($action === 'escalate') {
        if (!$escalated_to_role || !$escalated_to_id || !in_array($escalated_to_role, $roles_to_escalate)) {
            $errors[] = "Invalid escalation target selected.";
        }
    }
    if ($action === 'send_back' && !$send_back_to_id) {
        $errors[] = "Cannot send back: Recipient could not be determined.";
        error_log("Attempted send back for Complaint #$complaint_id failed, send_back_to_id is null.");
    }

    if (empty($errors)) {
        $db->begin_transaction();
        try {
            if ($action === 'resolve') {
                $update_complaint_sql = "UPDATE complaints SET status = 'resolved', resolution_details = ?, resolution_date = NOW() WHERE id = ?";
                $stmt1 = $db->prepare($update_complaint_sql);
                if (!$stmt1) throw new Exception("DB Error (1): " . $db->error);
                $stmt1->bind_param("si", $decision_text, $complaint_id);
                if (!$stmt1->execute()) throw new Exception("DB Error (1a): " . $stmt1->error);
                $stmt1->close();

                if ($is_decision_context) {
                    $update_decision_sql = "UPDATE decisions SET status = 'final', decision_text = ? WHERE id = ?";
                    $stmt2 = $db->prepare($update_decision_sql);
                    if (!$stmt2) throw new Exception("DB Error (D2): " . $db->error);
                    $stmt2->bind_param("si", $decision_text, $decision['id']);
                    if (!$stmt2->execute()) throw new Exception("DB Error (D2a): " . $stmt2->error);
                    $stmt2->close();
                } else {
                    $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
                    $stmt2 = $db->prepare($update_escalation_sql);
                    if (!$stmt2) throw new Exception("DB Error (E2): " . $db->error);
                    $stmt2->bind_param("si", $decision_text, $escalation_id);
                    if (!$stmt2->execute()) throw new Exception("DB Error (E2a): " . $stmt2->error);
                    $stmt2->close();
                }

                $decision_sql = "INSERT INTO decisions (complaint_id, escalation_id, sender_id, receiver_id, decision_text, status, created_at) VALUES (?, ?, ?, ?, ?, 'final', NOW())";
                $stmt3 = $db->prepare($decision_sql);
                if (!$stmt3) throw new Exception("DB Error (3): " . $db->error);
                $escalation_id_for_decision = $is_decision_context ? null : $escalation_id;
                $stmt3->bind_param("iiiis", $complaint_id, $escalation_id_for_decision, $dean_id, $complaint['complainant_id'], $decision_text);
                if (!$stmt3->execute()) throw new Exception("DB Error (3a): " . $stmt3->error);
                $stmt3->close();

                $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $user_notification_desc = "Your Complaint (#$complaint_id: {$complaint['title']}) has been resolved by the College Dean. Decision: $decision_text";
                $stmt4 = $db->prepare($notify_user_sql);
                if (!$stmt4) throw new Exception("DB Error (4): " . $db->error);
                $stmt4->bind_param("iis", $complaint['complainant_id'], $complaint_id, $user_notification_desc);
                if (!$stmt4->execute()) throw new Exception("DB Error (4a): " . $stmt4->error);
                $stmt4->close();

                if ($send_back_to_id && $send_back_to_id != $dean_id) {
                    $handler_notification_desc = "Complaint #$complaint_id, which you " . ($is_decision_context ? "responded to" : "escalated") . ", has been resolved by the College Dean. Decision: $decision_text";
                    $stmt_notify_handler = $db->prepare($notify_user_sql);
                    if (!$stmt_notify_handler) throw new Exception("DB Error (Notify Handler): " . $db->error);
                    $stmt_notify_handler->bind_param("iis", $send_back_to_id, $complaint_id, $handler_notification_desc);
                    if (!$stmt_notify_handler->execute()) throw new Exception("DB Error (Notify Handler Exec): " . $stmt_notify_handler->error);
                    $stmt_notify_handler->close();
                }

                $handler_response_text = $is_decision_context ? $decision['decision_text'] : '';
                sendStereotypedReport($db, $complaint_id, $dean_id, 'resolved', $decision_text, $handler_response_text);

                $_SESSION['success'] = "Complaint #$complaint_id has been resolved successfully.";

            } elseif ($action === 'send_back') {
                $decision_sql = "INSERT INTO decisions (complaint_id, escalation_id, sender_id, receiver_id, decision_text, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
                $stmt1 = $db->prepare($decision_sql);
                if (!$stmt1) throw new Exception("DB Error (SB1): " . $db->error);
                $escalation_id_for_decision = $is_decision_context ? null : $escalation_id;
                $stmt1->bind_param("iiiis", $complaint_id, $escalation_id_for_decision, $dean_id, $send_back_to_id, $decision_text);
                if (!$stmt1->execute()) throw new Exception("DB Error (SB1a): " . $stmt1->error);
                $new_decision_id = $stmt1->insert_id;
                $stmt1->close();

                if ($is_decision_context) {
                    $update_decision_sql = "UPDATE decisions SET status = 'final', decision_text = ? WHERE id = ?";
                    $stmt2 = $db->prepare($update_decision_sql);
                    if (!$stmt2) throw new Exception("DB Error (SB-D2): " . $db->error);
                    $stmt2->bind_param("si", $decision_text, $decision['id']);
                    if (!$stmt2->execute()) throw new Exception("DB Error (SB-D2a): " . $stmt2->error);
                    $stmt2->close();
                } else {
                    $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
                    $stmt2 = $db->prepare($update_escalation_sql);
                    if (!$stmt2) throw new Exception("DB Error (SB-E2): " . $db->error);
                    $res_detail = "Sent back to previous handler by Dean with decision: " . $decision_text;
                    $stmt2->bind_param("si", $res_detail, $escalation_id);
                    if (!$stmt2->execute()) throw new Exception("DB Error (SB-E2a): " . $stmt2->error);
                    $stmt2->close();
                }

                $update_complaint_sql = "UPDATE complaints SET status = 'pending' WHERE id = ?";
                $stmt3 = $db->prepare($update_complaint_sql);
                if (!$stmt3) throw new Exception("DB Error (SB3): " . $db->error);
                $stmt3->bind_param("i", $complaint_id);
                if (!$stmt3->execute()) throw new Exception("DB Error (SB3a): " . $stmt3->error);
                $stmt3->close();

                $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $handler_notification_desc = "A decision requires your attention for Complaint #$complaint_id (sent by College Dean): $decision_text";
                $stmt4 = $db->prepare($notify_handler_sql);
                if (!$stmt4) throw new Exception("DB Error (SB4): " . $db->error);
                $stmt4->bind_param("iis", $send_back_to_id, $complaint_id, $handler_notification_desc);
                if (!$stmt4->execute()) throw new Exception("DB Error (SB4a): " . $stmt4->error);
                $stmt4->close();

                $user_notification_desc = "Your Complaint (#$complaint_id: {$complaint['title']}) has been reviewed by the College Dean and sent back for further action.";
                $stmt5 = $db->prepare($notify_handler_sql);
                if (!$stmt5) throw new Exception("DB Error (SB5): " . $db->error);
                $stmt5->bind_param("iis", $complaint['complainant_id'], $complaint_id, $user_notification_desc);
                if (!$stmt5->execute()) throw new Exception("DB Error (SB5a): " . $stmt5->error);
                $stmt5->close();

                $handler_response_text = $is_decision_context ? $decision['decision_text'] : '';
                sendStereotypedReport($db, $complaint_id, $dean_id, 'decision_sent_back', $decision_text, $handler_response_text);

                $_SESSION['success'] = "Decision for Complaint #$complaint_id has been sent back successfully.";

            } elseif ($action === 'escalate') {
                $escalation_sql = "INSERT INTO escalations (complaint_id, escalated_to, escalated_to_id, escalated_by_id, status, original_handler_id, action_type, created_at)
                                   VALUES (?, ?, ?, ?, 'pending', ?, 'escalation', NOW())";
                $stmt1 = $db->prepare($escalation_sql);
                if (!$stmt1) throw new Exception("DB Error (E1): " . $db->error);
                $original_handler_id = $is_decision_context ? $send_back_to_id : $complaint['original_handler_id'];
                $stmt1->bind_param("isiii", $complaint_id, $escalated_to_role, $escalated_to_id, $dean_id, $original_handler_id);
                if (!$stmt1->execute()) throw new Exception("DB Error (E1a): " . $stmt1->error);
                $stmt1->close();

                if ($is_decision_context) {
                    $update_decision_sql = "UPDATE decisions SET status = 'final', decision_text = ? WHERE id = ?";
                    $stmt2 = $db->prepare($update_decision_sql);
                    if (!$stmt2) throw new Exception("DB Error (E-D2): " . $db->error);
                    $res_detail = "Escalated to Academic Vice President by Dean. Reason: " . $decision_text;
                    $stmt2->bind_param("si", $res_detail, $decision['id']);
                    if (!$stmt2->execute()) throw new Exception("DB Error (E-D2a): " . $stmt2->error);
                    $stmt2->close();
                } else {
                    $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
                    $stmt2 = $db->prepare($update_escalation_sql);
                    if (!$stmt2) throw new Exception("DB Error (E-E2): " . $db->error);
                    $res_detail = "Escalated to Academic Vice President by Dean. Reason: " . $decision_text;
                    $stmt2->bind_param("si", $res_detail, $escalation_id);
                    if (!$stmt2->execute()) throw new Exception("DB Error (E-E2a): " . $stmt2->error);
                    $stmt2->close();
                }

                $update_complaint_sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
                $stmt3 = $db->prepare($update_complaint_sql);
                if (!$stmt3) throw new Exception("DB Error (E3): " . $db->error);
                $stmt3->bind_param("i", $complaint_id);
                if (!$stmt3->execute()) throw new Exception("DB Error (E3a): " . $stmt3->error);
                $stmt3->close();

                $notify_escalated_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                $escalated_notification_desc = "Complaint #$complaint_id has been escalated to you by the College Dean for review. Reason: $decision_text";
                $stmt4 = $db->prepare($notify_escalated_sql);
                if (!$stmt4) throw new Exception("DB Error (E4): " . $db->error);
                $stmt4->bind_param("iis", $escalated_to_id, $complaint_id, $escalated_notification_desc);
                if (!$stmt4->execute()) throw new Exception("DB Error (E4a): " . $stmt4->error);
                $stmt4->close();

                $user_notification_desc = "Your Complaint (#$complaint_id: {$complaint['title']}) has been escalated to the Academic Vice President by the College Dean.";
                $stmt5 = $db->prepare($notify_escalated_sql);
                if (!$stmt5) throw new Exception("DB Error (E5): " . $db->error);
                $stmt5->bind_param("iis", $complaint['complainant_id'], $complaint_id, $user_notification_desc);
                if (!$stmt5->execute()) throw new Exception("DB Error (E5a): " . $stmt5->error);
                $stmt5->close();

                if ($send_back_to_id && $send_back_to_id != $dean_id) {
                    $handler_notification_desc = "Complaint #$complaint_id, which you " . ($is_decision_context ? "responded to" : "escalated") . ", has been further escalated by the College Dean to Academic Vice President. Reason: $decision_text";
                    $stmt_notify_handler = $db->prepare($notify_escalated_sql);
                    if (!$stmt_notify_handler) throw new Exception("DB Error (Notify Handler E): " . $db->error);
                    $stmt_notify_handler->bind_param("iis", $send_back_to_id, $complaint_id, $handler_notification_desc);
                    if (!$stmt_notify_handler->execute()) throw new Exception("DB Error (Notify Handler E Exec): " . $stmt_notify_handler->error);
                    $stmt_notify_handler->close();
                }

                $handler_response_text = $is_decision_context ? $decision['decision_text'] : '';
                $additional_info_report = "Escalated by College Dean to Academic Vice President. Reason: $decision_text";
                sendStereotypedReport($db, $complaint_id, $dean_id, 'escalated', $additional_info_report, $handler_response_text);

                $_SESSION['success'] = "Complaint #$complaint_id has been escalated to Academic Vice President successfully.";
            }

            $db->commit();
            header("Location: dashboard.php");
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "An error occurred while processing the decision: " . $e->getMessage();
            error_log("Decision processing error for Complaint #$complaint_id / " . ($is_decision_context ? "Decision #$decision_id" : "Escalation #$escalation_id") . ": " . $e->getMessage());
            header("Location: decide_complaint.php?complaint_id=$complaint_id&" . ($is_decision_context ? "decision_id=$decision_id" : "escalation_id=$escalation_id"));
            exit;
        }
    } else {
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header("Location: decide_complaint.php?complaint_id=$complaint_id&" . ($is_decision_context ? "decision_id=$decision_id" : "escalation_id=$escalation_id"));
        exit;
    }
}

// Retrieve errors and form data from session if they exist
$form_errors = $_SESSION['form_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

$display_decision_text = $form_data['decision_text'] ?? '';
$display_action = $form_data['action'] ?? '';
$display_escalated_role = $form_data['escalated_to_role'] ?? '';
$display_escalated_id = $form_data['escalated_to_id'] ?? '';

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $dean_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Decide Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
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
            --shadow-hover: 0 8px 25px rgba(0,0,0,0.12);
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

        .alert-info {
            background-color: #cff4fc;
            border-color: #b6effb;
            color: #055160;
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

        .form-group select optgroup {
            font-weight: 600;
            color: var(--primary-dark);
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

        #escalation-options {
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
            <?php if ($dean): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dean['fname'] . ' ' . $dean['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dean['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>College Dean</h4>
                    <p>Role: College Dean</p>
                </div>
            </div>
            <?php endif; ?>
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
            <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
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
                <span>DMU Complaint System - College Dean</span>
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

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['info'])): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
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
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['complainant_fname'] . ' ' . $complaint['complainant_lname']); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'N/A')); ?></p>
                <p><strong><?php echo $is_decision_context ? 'Responded By' : 'Escalated By'; ?>:</strong> <?php echo htmlspecialchars($complaint['escalator_fname'] . ' ' . $complaint['escalator_lname'] . ' (' . ucfirst(str_replace('_', ' ', $complaint['escalator_role'])) . ')'); ?></p>
                <p><strong>Current Status:</strong> <span class="badge badge-<?php echo htmlspecialchars($complaint['complaint_status']); ?>"><?php echo htmlspecialchars(ucfirst($complaint['complaint_status'])); ?></span></p>
                <p><strong>Submitted At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
                <p><strong>Description:</strong></p>
                <p style="white-space: pre-wrap; background: #fff; padding: 10px; border-radius: 5px;"><?php echo htmlspecialchars($complaint['description']); ?></p>
                <?php if ($is_decision_context && $decision): ?>
                    <p><strong>Handler Response:</strong></p>
                    <p style="white-space: pre-wrap; background: #f0f4ff; padding: 10px; border-radius: 5px; border-left: 3px solid var(--primary);">
                        <?php echo htmlspecialchars($decision['decision_text']); ?>
                        <br><small>Sent by <?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?> on <?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></small>
                    </p>
                <?php endif; ?>
            </div>

            <h3>Submit Your Decision</h3>
            <form method="POST" action="decide_complaint.php?complaint_id=<?php echo $complaint_id; ?>&<?php echo $is_decision_context ? 'decision_id=' . $decision_id : 'escalation_id=' . $escalation_id; ?>" novalidate>
                <div class="form-group">
                    <label for="decision_text">Decision / Reason / Resolution Details *</label>
                    <textarea name="decision_text" id="decision_text" rows="5" required placeholder="Enter your decision, reason for sending back, or reason for escalation here..."><?php echo htmlspecialchars($display_decision_text); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="action">Action *</label>
                    <select name="action" id="action" required onchange="toggleEscalationForm()">
                        <option value="" disabled <?php echo empty($display_action) ? 'selected' : ''; ?>>-- Select an Action --</option>
                        <option value="resolve" <?php echo $display_action === 'resolve' ? 'selected' : ''; ?>>Resolve Complaint</option>
                        <option value="send_back" <?php echo $display_action === 'send_back' ? 'selected' : ''; ?>>Send Back to <?php echo htmlspecialchars($complaint['escalator_fname'] . ' ' . $complaint['escalator_lname']); ?></option>
                        <option value="escalate" <?php echo $display_action === 'escalate' ? 'selected' : ''; ?>>Escalate to Academic Vice President</option>
                    </select>
                </div>

                <div id="escalation-options">
                    <div class="form-group">
                        <label for="escalation_target_select">Escalate To *</label>
                        <select name="escalation_target_select" id="escalation_target_select" onchange="updateEscalationFields(this)">
                            <option value="" disabled selected>-- Select Academic Vice President --</option>
                            <?php if (!empty($escalation_options['academic_vp'])): ?>
                                <?php foreach ($escalation_options['academic_vp'] as $user): ?>
                                    <?php
                                        $option_value = 'academic_vp|' . $user['id'];
                                        $is_selected = ($display_escalated_role === 'academic_vp' && (int)$display_escalated_id === $user['id']);
                                    ?>
                                    <option value="<?php echo $option_value; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars("{$user['fname']} {$user['lname']}"); ?>
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
        function toggleEscalationForm() {
            const actionSelect = document.getElementById('action');
            const escalationOptionsDiv = document.getElementById('escalation-options');
            const escalationTargetSelect = document.getElementById('escalation_target_select');
            const escalationRoleInput = document.getElementById('escalated_to_role');
            const escalationIdInput = document.getElementById('escalated_to_id');

            if (actionSelect.value === 'escalate') {
                escalationOptionsDiv.style.display = 'block';
                escalationTargetSelect.setAttribute('required', 'required');
            } else {
                escalationOptionsDiv.style.display = 'none';
                escalationTargetSelect.removeAttribute('required');
                escalationRoleInput.value = '';
                escalationIdInput.value = '';
            }
        }

        function updateEscalationFields(selectElement) {
            const selectedValue = selectElement.value;
            const escalationRoleInput = document.getElementById('escalated_to_role');
            const escalationIdInput = document.getElementById('escalated_to_id');

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
            toggleEscalationForm();
            updateEscalationFields(document.getElementById('escalation_target_select'));

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