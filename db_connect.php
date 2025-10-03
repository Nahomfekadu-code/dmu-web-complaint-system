<?php
// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "dmu_complaints";

// Create connection
$db = new mysqli($host, $username, $password);

// Check connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Create database if it doesn't exist
if (!$db->query("CREATE DATABASE IF NOT EXISTS $database")) {
    die("Error creating database: " . $db->error);
}

// Select the database
$db->select_db($database);
?>