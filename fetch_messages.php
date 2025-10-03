<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: text/html; charset=utf-8');

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p class="placeholder">Please log in to view messages.</p>';
    exit();
}

$committee_id = isset($_GET['committee_id']) ? (int)$_GET['committee_id'] : 0;
$user_id = $_SESSION['user_id'];

// Validate committee_id
if ($committee_id <= 0) {
    http_response_code(400);
    echo '<p class="placeholder">Invalid committee ID.</p>';
    exit();
}

// Verify user is part of the committee
$stmt = $db->prepare("SELECT 1 FROM committee_members WHERE committee_id = ? AND user_id = ?");
$stmt->bind_param("ii", $committee_id, $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(403);
    echo '<p class="placeholder">Access denied: You are not a member of this committee.</p>';
    exit();
}
$stmt->close();

// Fetch complaint details for visibility and status (remove evidence_file)
$stmt = $db->prepare("SELECT comp.visibility, comp.status, comp.user_id, u.fname, u.lname
                      FROM committees c
                      JOIN complaints comp ON c.complaint_id = comp.id
                      JOIN users u ON comp.user_id = u.id
                      WHERE c.id = ?");
$stmt->bind_param("i", $committee_id);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$complaint) {
    http_response_code(404);
    echo '<p class="placeholder">Complaint not found.</p>';
    exit();
}

// Fetch messages with sender details
$stmt = $db->prepare("
    SELECT cm.id, cm.sender_id, cm.message_text, cm.sent_at, cm.message_type,
           u.fname, u.lname, u.role 
    FROM committee_messages cm 
    LEFT JOIN users u ON cm.sender_id = u.id 
    WHERE cm.committee_id = ? 
    ORDER BY cm.sent_at ASC
");
$stmt->bind_param("i", $committee_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = $row;
}

if (empty($messages)) {
    echo '<p class="placeholder">No messages yet. Start the conversation!</p>';
    exit();
}

// Display messages
foreach ($messages as $msg) {
    $is_self = $msg['sender_id'] == $user_id;
    $is_initial_message = strpos($msg['message_text'], 'Complaint Details:') === 0;
    $is_system_message = isset($msg['message_type']) && $msg['message_type'] === 'system';

    // Determine sender display
    if ($is_system_message || $is_initial_message) {
        // System or initial complaint details message
        $sender_name = 'System';
        $sender_role = 'System';
        $message_class = 'system-message';

        // Modify message text to respect visibility and exclude evidence
        if ($is_initial_message) {
            $message_lines = explode("\n", $msg['message_text']);
            $filtered_lines = [];
            $skip_next = false;
            foreach ($message_lines as $line) {
                // Skip lines containing submitter's name or email
                if (strpos($line, 'Submitted By:') !== false) {
                    if ($complaint['visibility'] == 'anonymous' && $complaint['status'] != 'resolved') {
                        $filtered_lines[] = 'Submitted By: Anonymous';
                    } else {
                        $filtered_lines[] = 'Submitted By: ' . htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']);
                    }
                    $skip_next = true; // Skip email if present
                    continue;
                }
                if ($skip_next && strpos($line, 'Email:') !== false) {
                    continue; // Skip email line
                }
                if (strpos($line, 'Evidence File:') !== false) {
                    continue; // Skip evidence file line entirely
                }
                $filtered_lines[] = $line;
            }
            $message_text = htmlspecialchars(implode("\n", $filtered_lines));
        } else {
            $message_text = htmlspecialchars($msg['message_text']);
        }
    } else {
        // Regular message
        $sender_name = $msg['fname'] ? htmlspecialchars($msg['fname'] . ' ' . $msg['lname']) : 'Unknown';
        $sender_role = $msg['role'] ? htmlspecialchars($msg['role']) : 'N/A';
        $message_text = htmlspecialchars($msg['message_text']);
        $message_class = $is_self ? 'my-message' : 'other-message';
    }

    $sent_at = htmlspecialchars(date('M j, Y, g:i A', strtotime($msg['sent_at'])));

    echo "<div class='message $message_class'>";
    echo "<div class='sender-info'>$sender_name ($sender_role)</div>";
    echo "<p>" . nl2br($message_text) . "</p>";
    echo "<div class='timestamp'>$sent_at</div>";
    echo "</div>";
}

// Mark messages as read (except those sent by the current user)
$stmt = $db->prepare("UPDATE committee_messages SET is_read = 1 WHERE committee_id = ? AND sender_id != ?");
$stmt->bind_param("ii", $committee_id, $user_id);
$stmt->execute();

$stmt->close();
$db->close();
?>