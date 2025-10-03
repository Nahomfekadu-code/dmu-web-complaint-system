<?php
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details to determine their college and department
$sql = "SELECT college, department FROM users WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$college = $user['college'];
$department = $user['department'];

// Check if complaint_id is provided
if (!isset($_GET['complaint_id'])) {
    $_SESSION['error'] = "No complaint selected for escalation.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = $_GET['complaint_id'];

// Verify that the complaint belongs to the user and is pending
$sql = "SELECT id, status FROM complaints WHERE id = ? AND user_id = ? AND status = 'pending'";
$stmt = $db->prepare($sql);
$stmt->bind_param("ii", $complaint_id, $user_id);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$complaint) {
    $_SESSION['error'] = "Complaint not found or not eligible for escalation.";
    header("Location: dashboard.php");
    exit;
}

// Find a handler (we'll assign to a default handler for simplicity)
$sql = "SELECT id FROM users WHERE role = 'handler' LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->execute();
$handler = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$handler) {
    $_SESSION['error'] = "No handler found.";
    header("Location: dashboard.php");
    exit;
}

$handler_id = $handler['id'];

// Escalate the complaint to the handler
$sql = "INSERT INTO escalations (complaint_id, escalated_to, escalated_by, college, department, original_handler_id) 
        VALUES (?, 'handler', ?, ?, ?, ?)";
$stmt = $db->prepare($sql);
$stmt->bind_param("iisssi", $complaint_id, $user_id, $college, $department, $handler_id);

if ($stmt->execute()) {
    // Update the complaint status to in_progress
    $sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $_SESSION['success'] = "Complaint escalated to handler successfully!";
} else {
    $_SESSION['error'] = "Failed to escalate complaint. Please try again.";
}

$stmt->close();
header("Location: dashboard.php");
exit;
?>