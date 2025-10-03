<?php
session_start();
require_once '../db_connect.php';
require_once '../functions.php'; // Include the functions.php file where sendStereotypedReportToPresident is defined

// Role check: Ensure the user is a handler
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $complaint_id = $_POST['complaint_id'] ?? 0;
    $details = trim($_POST['details'] ?? '');

    // Validate complaint ID
    if ($complaint_id <= 0) {
        $_SESSION['error'] = "Invalid complaint ID.";
        header("Location: dashboard.php");
        exit;
    }

    // Fetch the complaint to ensure it exists
    $sql_complaint = "SELECT * FROM complaints WHERE id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows == 0) {
        $_SESSION['error'] = "Complaint not found.";
        header("Location: dashboard.php");
        exit;
    }
    $complaint = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    // Process the action
    $success = false;
    $message = '';
    $new_status = $complaint['status'];

    switch ($action) {
        case 'resolve':
            // Check if the handler is authorized to resolve this complaint
            if ($complaint['handler_id'] != $handler_id || $complaint['status'] != 'in_progress') {
                $_SESSION['error'] = "You are not authorized to resolve this complaint.";
                header("Location: dashboard.php");
                exit;
            }
            $new_status = 'resolved';
            $resolution_details = $details ?: 'Complaint resolved by handler.';
            $sql_update = "UPDATE complaints SET status = ?, resolution_details = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            $stmt_update->bind_param("ssi", $new_status, $resolution_details, $complaint_id);
            $success = $stmt_update->execute();
            $stmt_update->close();
            $message = "Complaint resolved successfully.";
            break;

        case 'reject':
            // Check if the handler is authorized to reject this complaint
            if ($complaint['handler_id'] != $handler_id || $complaint['status'] != 'in_progress') {
                $_SESSION['error'] = "You are not authorized to reject this complaint.";
                header("Location: dashboard.php");
                exit;
            }
            $new_status = 'rejected';
            $rejection_reason = $details ?: 'Complaint rejected by handler.';
            $sql_update = "UPDATE complaints SET status = ?, resolution_details = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            $stmt_update->bind_param("ssi", $new_status, $rejection_reason, $complaint_id);
            $success = $stmt_update->execute();
            $stmt_update->close();
            $message = "Complaint rejected successfully.";
            break;

        case 'assign':
            // Check if the complaint can be assigned
            if ($complaint['status'] != 'pending' && $complaint['status'] != 'validated') {
                $_SESSION['error'] = "This complaint cannot be assigned.";
                header("Location: dashboard.php");
                exit;
            }
            $new_status = 'in_progress';
            $sql_update = "UPDATE complaints SET status = ?, handler_id = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            $stmt_update->bind_param("sii", $new_status, $handler_id, $complaint_id);
            $success = $stmt_update->execute();
            $stmt_update->close();
            $message = "Complaint assigned successfully.";
            break;

        case 'request_evidence':
            // Check if the handler is authorized to request evidence
            if ($complaint['handler_id'] != $handler_id || $complaint['status'] != 'in_progress') {
                $_SESSION['error'] = "You are not authorized to request evidence for this complaint.";
                header("Location: dashboard.php");
                exit;
            }
            $new_status = 'pending'; // Or a custom status like 'awaiting_evidence'
            $request_details = $details ?: 'Handler requested more evidence.';
            $sql_update = "UPDATE complaints SET status = ?, resolution_details = ? WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            $stmt_update->bind_param("ssi", $new_status, $request_details, $complaint_id);
            $success = $stmt_update->execute();
            $stmt_update->close();
            $message = "Evidence requested successfully.";
            break;

        default:
            $_SESSION['error'] = "Invalid action.";
            header("Location: dashboard.php");
            exit;
    }

    // If the action was successful, send a stereotyped report to the President
    if ($success) {
        $report_success = sendStereotypedReportToPresident($db, $complaint_id, $handler_id, $action, $details);
        if (!$report_success) {
            error_log("Failed to send stereotyped report for complaint ID $complaint_id, action: $action");
        }

        // Notify the user who submitted the complaint (optional)
        $notification_message = "Your complaint (ID: $complaint_id) has been " . ($action == 'request_evidence' ? 'requested for more evidence' : $action . "ed") . " by the handler.";
        $sql_notify = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
        $stmt_notify = $db->prepare($sql_notify);
        $stmt_notify->bind_param("is", $complaint['user_id'], $notification_message);
        $stmt_notify->execute();
        $stmt_notify->close();

        $_SESSION['success'] = $message;
    } else {
        $_SESSION['error'] = "Failed to process the action.";
    }

    header("Location: dashboard.php");
    exit;
}

$db->close();
?>