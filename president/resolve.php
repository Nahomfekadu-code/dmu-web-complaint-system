<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'president'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'president') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// CSRF token validation
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: view_escalated.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: view_escalated.php");
    exit;
}

// Validate escalation_id
$escalation_id = isset($_POST['escalation_id']) ? filter_var($_POST['escalation_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($escalation_id === false) {
    $_SESSION['error'] = "Invalid escalation ID.";
    header("Location: view_escalated.php");
    exit;
}

// Validate resolution details
$resolution_details = trim($_POST['resolution_details'] ?? '');
if (empty($resolution_details)) {
    $_SESSION['error'] = "Please provide resolution details.";
    header("Location: view_escalated.php");
    exit;
}

try {
    // Verify the escalation exists, is pending, and is assigned to the president
    $sql_verify = "SELECT e.complaint_id, c.title, c.user_id as complainant_id, e.escalated_by_id as handler_id
                   FROM escalations e
                   JOIN complaints c ON e.complaint_id = c.id
                   WHERE e.id = ? AND e.escalated_to = 'president' AND e.status = 'pending'";
    $stmt_verify = $db->prepare($sql_verify);
    if (!$stmt_verify) {
        throw new Exception("Prepare failed for verification: " . $db->error);
    }
    $stmt_verify->bind_param("i", $escalation_id);
    $stmt_verify->execute();
    $result = $stmt_verify->get_result();
    $escalation = $result->fetch_assoc();
    $stmt_verify->close();

    if (!$escalation) {
        $_SESSION['error'] = "Escalation not found, already resolved, or not assigned to you.";
        header("Location: view_escalated.php");
        exit;
    }

    $complaint_id = $escalation['complaint_id'];
    $complaint_title = $escalation['title'];
    $complainant_id = $escalation['complainant_id'];
    $handler_id = $escalation['handler_id'];

    // Update the escalation
    $sql_update = "UPDATE escalations 
                   SET status = 'resolved', 
                       resolved_at = CURRENT_TIMESTAMP, 
                       resolution_details = ? 
                   WHERE id = ? AND escalated_to = 'president'";
    $stmt_update = $db->prepare($sql_update);
    if (!$stmt_update) {
        throw new Exception("Prepare failed for update: " . $db->error);
    }
    $stmt_update->bind_param("si", $resolution_details, $escalation_id);
    $stmt_update->execute();

    if ($stmt_update->affected_rows > 0) {
        // Update the complaint status to 'resolved'
        $sql_complaint = "UPDATE complaints SET status = 'resolved', resolution_details = ?, resolution_date = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt_complaint = $db->prepare($sql_complaint);
        if (!$stmt_complaint) {
            throw new Exception("Prepare failed for complaint update: " . $db->error);
        }
        $stmt_complaint->bind_param("si", $resolution_details, $complaint_id);
        $stmt_complaint->execute();
        $stmt_complaint->close();

        // Notify the complainant
        $notif_message_complainant = "Your complaint '$complaint_title' has been resolved by the President.";
        $sql_notif_complainant = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)";
        $stmt_notif_complainant = $db->prepare($sql_notif_complainant);
        if (!$stmt_notif_complainant) {
            throw new Exception("Prepare failed for complainant notification: " . $db->error);
        }
        $stmt_notif_complainant->bind_param("is", $complainant_id, $notif_message_complainant);
        $stmt_notif_complainant->execute();
        $stmt_notif_complainant->close();

        // Notify the handler (Academic Vice President or whoever escalated)
        $notif_message_handler = "The escalation for complaint '$complaint_title' has been resolved by the President.";
        $sql_notif_handler = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)";
        $stmt_notif_handler = $db->prepare($sql_notif_handler);
        if (!$stmt_notif_handler) {
            throw new Exception("Prepare failed for handler notification: " . $db->error);
        }
        $stmt_notif_handler->bind_param("is", $handler_id, $notif_message_handler);
        $stmt_notif_handler->execute();
        $stmt_notif_handler->close();

        $_SESSION['success'] = "Escalation for complaint '$complaint_title' resolved successfully.";
    } else {
        $_SESSION['error'] = "Failed to resolve escalation for complaint '$complaint_title'.";
    }
    $stmt_update->close();
} catch (Exception $e) {
    error_log("Error in resolve.php: " . $e->getMessage());
    $_SESSION['error'] = "An unexpected error occurred while resolving the escalation.";
} finally {
    header("Location: view_escalated.php");
    exit;
}

$db->close();
?>