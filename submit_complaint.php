<?php
session_start();
require_once 'db_connect.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: login.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = $_POST["title"];
    $description = $_POST["description"];
    $user_id = $_SESSION['user_id'];
    if (empty($title) || empty($description)) {
        $error = "Title and description are required.";
    } else {
        $sql = "INSERT INTO complaints (user_id, title, description, status) VALUES (?, ?, ?, 'pending')";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iss", $user_id, $title, $description);
        if ($stmt->execute()) {
            header("Location: dashboard.php?success=Complaint submitted");
        } else {
            $error = "Error submitting complaint: " . $db->error;