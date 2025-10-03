<?php
require_once '../db_connect.php';
session_start();

if ($_SESSION['role'] !== 'handler') {
    die(json_encode(['error' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaint_id = $_POST['complaint_id'];
    $member_ids = $_POST['member_ids']; // Array of user IDs

    // Validate complaint
    $stmt = $db->prepare("SELECT needs_committee FROM complaints WHERE id = ? AND handler_id = ?");
    $stmt->bind_param("ii", $complaint_id, $_SESSION['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0 || $result->fetch_assoc()['needs_committee'] != 1) {
        die(json_encode(['error' => 'Invalid complaint or not committee-eligible']));
    }
    $stmt->close();

    // Ensure at least 2 members (excluding handler)
    if (count($member_ids) < 2) {
        die(json_encode(['error' => 'At least 2 members required']));
    }

    // Create committee
    $stmt = $db->prepare("INSERT INTO committees (handler_id, complaint_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $_SESSION['id'], $complaint_id);
    $stmt->execute();
    $committee_id = $db->insert_id;
    $stmt->close();

    // Add handler as committee member
    $stmt = $db->prepare("INSERT INTO committee_members (committee_id, user_id, is_handler) VALUES (?, ?, 1)");
    $stmt->bind_param("ii", $committee_id, $_SESSION['id']);
    $stmt->execute();
    $stmt->close();

    // Add other members
    $stmt = $db->prepare("INSERT INTO committee_members (committee_id, user_id, is_handler) VALUES (?, ?, 0)");
    foreach ($member_ids as $member_id) {
        $stmt->bind_param("ii", $committee_id, $member_id);
        $stmt->execute();
    }
    $stmt->close();

    // Update complaint
    $stmt = $db->prepare("UPDATE complaints SET committee_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $committee_id, $complaint_id);
    $stmt->execute();
    $stmt->close();

    // Notify members
    $stmt = $db->prepare("INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)");
    $description = "You have been assigned to the committee for complaint #$complaint_id.";
    foreach ($member_ids as $member_id) {
        $stmt->bind_param("iis", $member_id, $complaint_id, $description);
        $stmt->execute();
    }
    $stmt->close();

    echo json_encode(['success' => 'Committee created']);
}
?>