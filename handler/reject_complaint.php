<?php
session_start();
require_once '../db_connect.php'; // Adjust path to match your project structure

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "You must be logged in to perform this action.";
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'handler') {
    $_SESSION['error'] = "You do not have permission to reject complaints.";
    header("Location: ../unauthorized.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

// Validate complaint_id from GET request
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
if (!$complaint_id || $complaint_id <= 0) {
    $_SESSION['error'] = "Invalid complaint ID provided.";
    header("Location: dashboard.php");
    exit;
}

// Initialize variables
$redirect_url = "dashboard.php"; // Default redirect

// Process rejection
$db->begin_transaction();
try {
    // Check complaint status and handler assignment
    $check_sql = "SELECT status, handler_id, user_id FROM complaints WHERE id = ? FOR UPDATE";
    $check_stmt = $db->prepare($check_sql);
    if (!$check_stmt) {
        throw new Exception("Database error preparing check statement: " . $db->error);
    }

    $check_stmt->bind_param("i", $complaint_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $complaint = $result ? $result->fetch_assoc() : null;
    $check_stmt->close();

    if (!$complaint) {
        throw new Exception("Complaint not found.");
    }

    // Verify complaint is in rejectable status and assigned to this handler
    if (!in_array($complaint['status'], ['pending', 'validated'])) {
        throw new Exception("This complaint cannot be rejected. It is not in a rejectable status (pending or validated).");
    }

    if ($complaint['handler_id'] != $handler_id) {
        throw new Exception("You are not assigned to this complaint.");
    }

    // Update complaint status to 'rejected'
    $update_sql = "UPDATE complaints SET status = 'rejected', updated_at = NOW() WHERE id = ?";
    $update_stmt = $db->prepare($update_sql);
    if (!$update_stmt) {
        throw new Exception("Database error preparing update statement: " . $db->error);
    }

    $update_stmt->bind_param("i", $complaint_id);
    $executed = $update_stmt->execute();
    $affected_rows = $update_stmt->affected_rows;
    $update_stmt->close();

    if (!$executed || $affected_rows == 0) {
        throw new Exception("Failed to reject the complaint. It may have been modified concurrently.");
    }

    // Send notification to the complainant
    $user_id = $complaint['user_id'];
    $notify_sql = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
    $notify_stmt = $db->prepare($notify_sql);
    if ($notify_stmt) {
        $description = "Your complaint (#$complaint_id) has been rejected by the handler.";
        $notify_stmt->bind_param("iis", $user_id, $complaint_id, $description);
        $notify_stmt->execute();
        $notify_stmt->close();
    } else {
        error_log("Error preparing notification statement: " . $db->error);
        // Log error but don't fail the transaction, as notification is secondary
    }

    // Log the rejection action
    error_log("Complaint #$complaint_id rejected by handler ID $handler_id at " . date('Y-m-d H:i:s'));

    // Commit transaction
    $db->commit();
    $_SESSION['success'] = "Complaint #$complaint_id has been rejected successfully.";
} catch (Exception $e) {
    $db->rollback();
    $_SESSION['error'] = $e->getMessage();
    error_log("Error rejecting complaint #$complaint_id: " . $e->getMessage());
}

// Redirect back to the dashboard
header("Location: $redirect_url");
exit;
?>