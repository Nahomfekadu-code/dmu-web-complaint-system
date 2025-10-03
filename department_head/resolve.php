<?php
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'department_head') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $escalation_id = $_POST['escalation_id'];
    $resolution_details = trim($_POST['resolution_details']);

    if (empty($resolution_details)) {
        $_SESSION['error'] = "Please provide resolution details.";
        header("Location: dashboard.php");
        exit;
    }

    $sql = "UPDATE escalations 
            SET status = 'resolved', 
                resolved_at = CURRENT_TIMESTAMP, 
                resolution_details = ? 
            WHERE id = ? AND escalated_to = 'department_head'";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("si", $resolution_details, $escalation_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Escalation resolved successfully.";
    } else {
        $_SESSION['error'] = "Failed to resolve escalation.";
    }
    $stmt->close();
    header("Location: dashboard.php");
    exit;
}

$db->close();
?>