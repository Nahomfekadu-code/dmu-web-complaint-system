<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: text/plain; charset=utf-8');

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Access denied: Not logged in.");
}

$committee_id = isset($_POST['committee_id']) ? (int)$_POST['committee_id'] : 0;
$message = trim($_POST['message'] ?? '');
$user_id = $_SESSION['user_id'];

// Validate inputs
if ($committee_id <= 0) {
    http_response_code(400);
    die("Invalid committee ID.");
}
if (empty($message)) {
    http_response_code(400);
    die("Message cannot be empty.");
}

// Verify user is part of the committee
$stmt = $db->prepare("SELECT * FROM committee_members WHERE committee_id = ? AND user_id = ?");
$stmt->bind_param("ii", $committee_id, $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    die("Access denied: You are not a member of this committee.");
}

// Fetch committee and complaint details for notification
$stmt = $db->prepare("SELECT c.complaint_id, comp.title FROM committees c JOIN complaints comp ON c.complaint_id = comp.id WHERE c.id = ?");
$stmt->bind_param("i", $committee_id);
$stmt->execute();
$committee = $stmt->get_result()->fetch_assoc();
if (!$committee) {
    http_response_code(404);
    die("Committee not found.");
}
$complaint_id = $committee['complaint_id'];
$complaint_title = $committee['title'];

// Insert the message into committee_messages table
$message_type = 'user'; // Set message_type to 'user' for regular messages
$stmt = $db->prepare("INSERT INTO committee_messages (committee_id, sender_id, message_text, message_type) VALUES (?, ?, ?, ?)");
$stmt->bind_param("iiss", $committee_id, $user_id, $message, $message_type);
if (!$stmt->execute()) {
    http_response_code(500);
    die("Error sending message: " . $stmt->error);
}

// Send notifications to other committee members
$description = "New message in committee chat for complaint #$complaint_id: " . htmlspecialchars($complaint_title);
$stmt = $db->prepare("INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
$stmt_members = $db->prepare("SELECT user_id FROM committee_members WHERE committee_id = ? AND user_id != ?");
$stmt_members->bind_param("ii", $committee_id, $user_id);
$stmt_members->execute();
$members = $stmt_members->get_result();

$notification_success = true;
while ($member = $members->fetch_assoc()) {
    $stmt->bind_param("iis", $member['user_id'], $complaint_id, $description);
    if (!$stmt->execute()) {
        $notification_success = false;
    }
}

$stmt->close();
$stmt_members->close();
$db->close();

if ($notification_success) {
    echo "Message sent successfully, and notifications sent to committee members.";
} else {
    echo "Message sent successfully, but some notifications failed to send.";
}
?>