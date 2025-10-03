<?php
// Enforce secure session settings (must be before session_start)
ini_set('session.cookie_secure', '0'); // Set to '0' for local testing; '1' for live server with HTTPS
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

// Start the session
session_start();

// Include database connection
require_once '../db_connect.php'; // Ensure this path points to dmu_complaints/db_connect.php

// Role check: Ensure the user is logged in and is a 'user'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user = null; // Initialize user variable

// Fetch user details for sidebar and check suspension status
$sql_user = "SELECT fname, lname, email, role, status, suspended_until FROM users WHERE id = ?";
$stmt_user = $db->prepare($sql_user);
if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $user = $result_user->fetch_assoc();
    } else {
        $_SESSION['error'] = "User not found.";
        unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['fname'], $_SESSION['lname']);
        header("Location: ../login.php");
        exit;
    }
    $stmt_user->close();
} else {
    $_SESSION['error'] = "Error fetching user details.";
    error_log("Failed to prepare user fetch statement: " . $db->error);
    header("Location: ../login.php");
    exit;
}

// If user fetch failed
if ($user === null) {
    $_SESSION['error'] = "Could not load user information.";
    header("Location: ../login.php");
    exit;
}

// Check if user is suspended and calculate remaining time
$is_suspended = false;
$remaining_seconds = 0;
if ($user['status'] === 'suspended' && $user['suspended_until'] !== null) {
    $current_time = new DateTime();
    $suspended_until = new DateTime($user['suspended_until']);
    if ($current_time < $suspended_until) {
        $is_suspended = true;
        $interval = $current_time->diff($suspended_until);
        $remaining_seconds = ($interval->days * 24 * 3600) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        $_SESSION['error'] = "Your account is suspended due to a previous violation. You can submit complaints again when the suspension ends.";
    } else {
        // Suspension has expired, reactivate the user
        $sql_reactivate = "UPDATE users SET status = 'active', suspended_until = NULL WHERE id = ?";
        $stmt_reactivate = $db->prepare($sql_reactivate);
        if ($stmt_reactivate) {
            $stmt_reactivate->bind_param("i", $user_id);
            $stmt_reactivate->execute();
            $stmt_reactivate->close();
            $is_suspended = false; // Update status
        }
    }
}

// Rate limiting: Prevent spam submissions (only if not suspended)
if (!$is_suspended) {
    $time_window = 3600; // 1 hour in seconds
    $max_complaints = 3; // Maximum complaints allowed in the time window
    $sql_rate_limit = "
        SELECT COUNT(*) as recent_complaints 
        FROM complaints 
        WHERE user_id = ? 
        AND created_at >= NOW() - INTERVAL ? SECOND";
    $stmt_rate_limit = $db->prepare($sql_rate_limit);
    if ($stmt_rate_limit) {
        $stmt_rate_limit->bind_param("ii", $user_id, $time_window);
        $stmt_rate_limit->execute();
        $rate_limit_result = $stmt_rate_limit->get_result()->fetch_assoc();
        $stmt_rate_limit->close();

        if ($rate_limit_result['recent_complaints'] >= $max_complaints) {
            $_SESSION['error'] = "You have exceeded the maximum number of complaints allowed per hour ($max_complaints). Please try again later.";
            header("Location: submit_complaint.php");
            exit;
        } else {
            $remaining_complaints = $max_complaints - $rate_limit_result['recent_complaints'];
            if ($remaining_complaints < $max_complaints) {
                $_SESSION['warning'] = "You can submit $remaining_complaints more complaints in the next hour.";
            }
        }
    } else {
        error_log("Rate limit query failed: " . $db->error);
        $_SESSION['error'] = "An error occurred while checking submission limits.";
        header("Location: submit_complaint.php");
        exit;
    }
}

// Function to log actions to complaint_logs table
function log_complaint_action($db, $user_id, $action, $details) {
    $sql_log = "INSERT INTO complaint_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())";
    $stmt_log = $db->prepare($sql_log);
    if ($stmt_log) {
        $stmt_log->bind_param("iss", $user_id, $action, $details);
        if (!$stmt_log->execute()) {
            error_log("Failed to log complaint action: " . $stmt_log->error);
        }
        $stmt_log->close();
    } else {
        error_log("Failed to prepare log statement: " . $db->error);
    }
}

// Handle form submission (only if not suspended)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_suspended) {
    // Sanitize and validate inputs
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? '';
    $visibility = $_POST['visibility'] ?? 'standard';
    $needs_video_chat = isset($_POST['needs_video_chat']) ? 1 : 0;
    $evidence_file = null;

    // Validate required fields
    if (empty($title) || empty($description) || empty($category) || empty($visibility)) {
        $_SESSION['error'] = "Title, Description, Category, and Visibility are required fields.";
        header("Location: submit_complaint.php");
        exit;
    }

    // Validate category and visibility
    $valid_categories = ['academic', 'administrative'];
    $valid_visibilities = ['standard', 'anonymous'];
    if (!in_array($category, $valid_categories)) {
        $_SESSION['error'] = "Invalid category selected.";
        header("Location: submit_complaint.php");
        exit;
    }
    if (!in_array($visibility, $valid_visibilities)) {
        $_SESSION['error'] = "Invalid visibility option selected.";
        header("Location: submit_complaint.php");
        exit;
    }

    // Abusive word filtering
    $sql_abusive_words = "SELECT word FROM abusive_words";
    $abusive_words_result = $db->query($sql_abusive_words);
    $abusive_words = [];
    if ($abusive_words_result) {
        while ($row = $abusive_words_result->fetch_assoc()) {
            $abusive_words[] = strtolower($row['word']);
        }
        $abusive_words_result->free();
    } else {
        error_log("Failed to fetch abusive words: " . $db->error);
    }

    // Normalize text for abusive word detection
    function normalize_text($text) {
        $text = strtolower($text);
        $replacements = [
            '@' => 'a',
            '1' => 'i',
            '!' => 'i',
            '0' => 'o',
            '5' => 's',
            '$' => 's'
        ];
        $text = strtr($text, $replacements);
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    $text_to_check = normalize_text($title . ' ' . $description);
    $found_abusive_words = [];
    foreach ($abusive_words as $word) {
        $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
        if (preg_match($pattern, $text_to_check)) {
            $found_abusive_words[] = $word;
        }
    }

    if (!empty($found_abusive_words)) {
        // Log the abusive attempt
        $abusive_details = "User attempted to submit a complaint with abusive words: " . implode(', ', $found_abusive_words);
        log_complaint_action($db, $user_id, "Abusive Complaint Attempt", $abusive_details);

        // Suspend the user for 2 hours (default duration, can be changed by admin later)
        $suspension_duration = 2 * 3600; // 2 hours in seconds
        $suspended_until = date('Y-m-d H:i:s', time() + $suspension_duration);
        $sql_suspend = "UPDATE users SET status = 'suspended', suspended_until = ? WHERE id = ?";
        $stmt_suspend = $db->prepare($sql_suspend);
        if ($stmt_suspend) {
            $stmt_suspend->bind_param("si", $suspended_until, $user_id);
            if ($stmt_suspend->execute()) {
                // Log the suspension action
                $suspension_details = "User suspended for 2 hours due to abusive content: " . implode(', ', $found_abusive_words);
                log_complaint_action($db, $user_id, "User Suspended", $suspension_details);

                // Notify the user of suspension
                $notification_user = "Your account has been suspended for 2 hours due to the use of inappropriate language: " . implode(', ', $found_abusive_words) . ".";
                $sql_notify_user = "INSERT INTO notifications (user_id, description, created_at) VALUES (?, ?, NOW())";
                $stmt_notify_user = $db->prepare($sql_notify_user);
                if ($stmt_notify_user) {
                    $stmt_notify_user->bind_param("is", $user_id, $notification_user);
                    $stmt_notify_user->execute();
                    $stmt_notify_user->close();
                } else {
                    error_log("Failed to prepare suspension notification statement: " . $db->error);
                }

                $_SESSION['error'] = "Your account has been suspended for 2 hours due to the use of inappropriate language: " . implode(', ', $found_abusive_words) . ".";
            } else {
                error_log("Failed to suspend user: " . $stmt_suspend->error);
                $_SESSION['error'] = "Error suspending account. Please contact support.";
            }
            $stmt_suspend->close();
        } else {
            error_log("Failed to prepare suspend statement: " . $db->error);
            $_SESSION['error'] = "Error suspending account. Please contact support.";
        }

        error_log("User ID $user_id suspended for 2 hours due to abusive words: " . implode(', ', $found_abusive_words));
        header("Location: submit_complaint.php");
        exit;
    }

    // Handle file upload (optional)
    if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/'; // Points to dmu_complaints/uploads/
        $max_file_size = 5 * 1024 * 1024; // 5MB
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];

        // Create upload directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                error_log("Failed to create directory: " . $upload_dir);
                $_SESSION['error'] = "Failed to create upload directory. Please contact support.";
                header("Location: submit_complaint.php");
                exit;
            }
        }

        $file_info = pathinfo($_FILES['evidence_file']['name']);
        $file_extension = isset($file_info['extension']) ? strtolower($file_info['extension']) : '';
        $file_mime_type = $_FILES['evidence_file']['type'];
        $file_size = $_FILES['evidence_file']['size'];
        $tmp_name = $_FILES['evidence_file']['tmp_name'];

        // Validate file
        if (!in_array($file_extension, $allowed_extensions)) {
            $_SESSION['error'] = "Invalid file type (extension). Allowed: " . implode(', ', $allowed_extensions);
        } elseif (!in_array($file_mime_type, $allowed_types)) {
            $_SESSION['error'] = "Invalid file type (MIME). Allowed: " . implode(', ', $allowed_types);
        } elseif ($file_size > $max_file_size) {
            $_SESSION['error'] = "File is too large. Maximum size is " . ($max_file_size / 1024 / 1024) . "MB.";
        } else {
            // Generate secure unique file name
            $file_name = hash('sha256', uniqid('evidence_', true)) . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;

            if (move_uploaded_file($tmp_name, $file_path)) {
                $evidence_file = $file_name;
            } else {
                error_log("move_uploaded_file failed for " . $tmp_name . " to " . $file_path);
                $_SESSION['error'] = "Failed to upload evidence file. Check server permissions.";
            }
        }
    } elseif (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] != UPLOAD_ERR_NO_FILE && $_FILES['evidence_file']['error'] != UPLOAD_ERR_OK) {
        $_SESSION['error'] = "File upload error: Code " . $_FILES['evidence_file']['error'];
        error_log("File upload error for user $user_id: Code " . $_FILES['evidence_file']['error']);
    }

    // Proceed only if there are no errors
    if (!isset($_SESSION['error'])) {
        // Find an available handler with the fewest pending complaints
        $sql_handler = "SELECT id FROM users WHERE role = 'handler' AND status = 'active' ORDER BY (SELECT COUNT(*) FROM complaints WHERE handler_id = users.id AND status = 'pending') ASC LIMIT 1";
        $result_handler = $db->query($sql_handler);
        $handler_id = $result_handler->num_rows > 0 ? $result_handler->fetch_assoc()['id'] : null;

        // Build the SQL query dynamically
        $columns = "user_id, handler_id, title, description, category, status, visibility, needs_video_chat";
        $placeholders = "?, ?, ?, ?, ?, 'pending', ?, ?";
        $types = "iissssi";
        $params = [$user_id, $handler_id, $title, $description, $category, $visibility, $needs_video_chat];

        if ($evidence_file !== null) {
            $columns .= ", evidence_file";
            $placeholders .= ", ?";
            $types .= "s";
            $params[] = $evidence_file;
        }

        $sql_insert = "INSERT INTO complaints ($columns) VALUES ($placeholders)";
        $stmt_insert = $db->prepare($sql_insert);

        if ($stmt_insert === false) {
            error_log("Prepare failed: (" . $db->errno . ") " . $db->error . " SQL: " . $sql_insert);
            $_SESSION['error'] = "Error preparing complaint statement: " . $db->error;
        } else {
            $stmt_insert->bind_param($types, ...$params);

            if ($stmt_insert->execute()) {
                $complaint_id = $stmt_insert->insert_id;

                // Log the successful submission
                $success_details = "User submitted complaint #$complaint_id: " . substr($title, 0, 50) . "...";
                log_complaint_action($db, $user_id, "Complaint Filed", $success_details);

                // Notify the user
                $notification_user = "Your complaint \"$title\" has been submitted and is pending review.";
                $sql_notify_user = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
                $stmt_notify_user = $db->prepare($sql_notify_user);
                if ($stmt_notify_user) {
                    $stmt_notify_user->bind_param("iis", $user_id, $complaint_id, $notification_user);
                    $stmt_notify_user->execute();
                    $stmt_notify_user->close();
                } else {
                    error_log("Failed to prepare user notification statement: " . $db->error);
                }

                // Notify the handler if assigned
                if ($handler_id) {
                    $notification_handler = "A new complaint (#$complaint_id) has been assigned to you.";
                    $sql_notify_handler = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
                    $stmt_notify_handler = $db->prepare($sql_notify_handler);
                    if ($stmt_notify_handler) {
                        $stmt_notify_handler->bind_param("iis", $handler_id, $complaint_id, $notification_handler);
                        $stmt_notify_handler->execute();
                        $stmt_notify_handler->close();
                    } else {
                        error_log("Failed to prepare handler notification statement: " . $db->error);
                    }
                } else {
                    // Log if no handler was assigned
                    $no_handler_details = "Complaint #$complaint_id submitted but no active handler available to assign.";
                    log_complaint_action($db, $user_id, "No Handler Assigned", $no_handler_details);
                    $_SESSION['warning'] = "Complaint submitted successfully, but no handler is currently available. It will be assigned soon.";
                }

                $_SESSION['success'] = "Complaint submitted successfully.";
                header("Location: dashboard.php"); // Redirect to dashboard
                exit;
            } else {
                error_log("Execute failed: (" . $stmt_insert->errno . ") " . $stmt_insert->error);
                $_SESSION['error'] = "Error submitting complaint: " . $stmt_insert->error;
                // Clean up uploaded file if DB insert fails
                if ($evidence_file !== null && file_exists($upload_dir . $evidence_file)) {
                    unlink($upload_dir . $evidence_file);
                }
            }
            $stmt_insert->close();
        }
    }
    header("Location: submit_complaint.php");
    exit;
}

// Get current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Handle success/error messages from session
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
$warning = $_SESSION['warning'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Complaint | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #3f37c9;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --radius: 12px;
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
            display: flex;
        }

        /* Vertical Navigation */
        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .nav-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%;
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-profile-mini i {
            font-size: 2.5rem;
            color: white;
        }

        .user-info h4 {
            font-size: 0.9rem;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }

        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .nav-menu {
            padding: 0 10px;
        }

        .nav-menu h3 {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 20px 10px 10px;
            opacity: 0.7;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: white;
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .nav-link span {
            flex-grow: 1;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }

        .horizontal-nav {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .horizontal-nav .logo {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--primary-dark);
        }

        .horizontal-menu {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .horizontal-menu a:hover {
            background: var(--light-gray);
            color: var(--primary);
        }

        .horizontal-menu a.active {
            background: var(--primary);
            color: white;
        }

        .container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px 40px;
            animation: fadeIn 0.6s ease-out;
            flex-grow: 1;
            margin-bottom: 20px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            margin-bottom: 30px;
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            position: relative;
            text-align: center;
            padding-bottom: 15px;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        .form {
            max-width: 800px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="file"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #fdfdfd;
        }

        .form-group input[type="file"] {
            padding: 5px;
        }

        .form-group input[type="file"]::file-selector-button {
            padding: 8px 15px;
            border: none;
            border-radius: calc(var(--radius) - 4px);
            background-color: var(--primary);
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-right: 10px;
        }

        .form-group input[type="file"]::file-selector-button:hover {
            background-color: var(--primary-dark);
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            background-color: white;
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23333'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 1em;
            padding-right: 40px;
        }

        .form-group.checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            background-color: #f8f9fa;
            padding: 10px 15px;
            border-radius: var(--radius);
            border: 1px solid #eee;
        }

        .form-group.checkbox input[type="checkbox"] {
            width: auto;
            height: 1.2em;
            width: 1.2em;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .form-group.checkbox label {
            margin-bottom: 0;
            font-weight: 400;
            cursor: pointer;
        }

        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .btn:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .btn:disabled {
            background: var(--gray);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            border-left-width: 5px;
            border-left-style: solid;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: var(--success);
            color: #1c7430;
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger);
            color: #a51c2c;
        }

        .alert-warning {
            background-color: rgba(255, 193, 7, 0.1);
            border-left-color: var(--warning);
            color: #b98900;
        }

        .countdown-timer {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--danger);
            text-align: center;
            margin-top: 10px;
        }

        .form.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        footer {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            border-radius: var(--radius) var(--radius) 0 0;
            margin-top: auto;
            flex-shrink: 0;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .group-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 15px 0;
        }

        .social-links a {
            color: white;
            font-size: 1.5rem;
            transition: var(--transition);
        }

        .social-links a:hover {
            transform: translateY(-3px);
            color: var(--accent);
        }

        .copyright {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        @media (max-width: 992px) {
            .vertical-nav {
                width: 220px;
            }
            .user-info h4 { max-width: 140px; }
            .container { padding: 25px 30px; }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }
            .main-content { padding: 15px; }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { justify-content: center; }
            .container { padding: 20px; }
        }

        @media (max-width: 576px) {
            .vertical-nav { display: block !important; } /* Force display on small screens */
            .main-content { padding: 10px; }
            .horizontal-nav .logo { display: none; }
            .horizontal-menu a { padding: 6px 10px; font-size: 0.9rem; }
            .container { padding: 15px; }
            h2 { font-size: 1.5rem; }
            .btn { width: 100%; padding: 12px; }
        }
    </style>
</head>
<body>
    <!-- Vertical Navigation -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($user['role'])); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Overview</span>
            </a>

            <h3>Complaints</h3>
            <a href="submit_complaint.php" class="nav-link <?php echo $current_page == 'submit_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle fa-fw"></i>
                <span>Submit Complaint</span>
            </a>
            <a href="modify_complaint.php" class="nav-link <?php echo $current_page == 'modify_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-edit fa-fw"></i>
                <span>Modify Complaint</span>
            </a>
            <a href="check_complaint_status.php" class="nav-link <?php echo $current_page == 'check_complaint_status.php' ? 'active' : ''; ?>">
                <i class="fas fa-search fa-fw"></i>
                <span>Check Status</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>Notifications</span>
            </a>
            <a href="send_feedback.php" class="nav-link <?php echo $current_page == 'send_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-alt fa-fw"></i>
                <span>Send Feedback</span>
            </a>
            <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel fa-fw"></i>
                <span>View Decisions</span>
            </a>

            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit fa-fw"></i>
                <span>Edit Profile</span>
            </a>
            <a href="view_notices.php" class="nav-link <?php echo $current_page == 'view_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list fa-fw"></i>
                <span>View Notices</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Contact</a>
                <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About</a>
            </div>
        </nav>

        <!-- Complaint Form Container -->
        <div class="container">
            <h2>Submit a Complaint</h2>

            <!-- Display Session Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <?php if ($is_suspended): ?>
                        <div class="countdown-timer">
                            Time remaining: <span id="countdown"><?php echo gmdate("H:i:s", $remaining_seconds); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($warning): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($warning); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="submit_complaint.php" class="form <?php echo $is_suspended ? 'disabled' : ''; ?>" enctype="multipart/form-data" id="complaint-form">
                <div class="form-group">
                    <label for="title">Complaint Title:</label>
                    <input type="text" id="title" name="title" required placeholder="e.g., Issue with course registration" <?php echo $is_suspended ? 'disabled' : ''; ?>>
                </div>
                <div class="form-group">
                    <label for="description">Detailed Description:</label>
                    <textarea id="description" name="description" rows="6" required placeholder="Please describe the issue in detail, including relevant dates, times, locations, or people involved." <?php echo $is_suspended ? 'disabled' : ''; ?>></textarea>
                </div>
                <div class="form-group">
                    <label for="category">Category:</label>
                    <select id="category" name="category" required <?php echo $is_suspended ? 'disabled' : ''; ?>>
                        <option value="" disabled selected>-- Select Category --</option>
                        <option value="academic">Academic (e.g., grading, course content, teaching)</option>
                        <option value="administrative">Administrative (e.g., registration, facilities, services)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="visibility">Visibility:</label>
                    <select id="visibility" name="visibility" required <?php echo $is_suspended ? 'disabled' : ''; ?>>
                        <option value="standard" selected>Standard (Your name will be visible to handlers)</option>
                        <option value="anonymous">Anonymous (Your name will be hidden)</option>
                    </select>
                </div>
               
                <div class="form-group">
                    <label for="evidence_file">Attach Evidence (Optional):</label>
                    <input type="file" id="evidence_file" name="evidence_file" accept=".jpg,.jpeg,.png,.pdf" <?php echo $is_suspended ? 'disabled' : ''; ?>>
                    <small style="display: block; margin-top: 5px; color: var(--gray);">Allowed types: JPEG, PNG, PDF. Max size: 5MB.</small>
                </div>
                <button type="submit" class="btn" <?php echo $is_suspended ? 'disabled' : ''; ?>>Submit Complaint</button>
            </form>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint Management System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <!-- Client-side JavaScript -->
    <script>
        // Client-side file size validation
        const fileInput = document.getElementById('evidence_file');
        const maxSize = 5 * 1024 * 1024; // 5MB

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    if (this.files[0].size > maxSize) {
                        alert('File is too large! Maximum size is 5MB.');
                        this.value = '';
                    }
                }
            });
        }

        // Countdown timer for suspension
        <?php if ($is_suspended): ?>
            let remainingSeconds = <?php echo $remaining_seconds; ?>;
            const countdownElement = document.getElementById('countdown');

            function updateCountdown() {
                if (remainingSeconds <= 0) {
                    // Suspension has ended, reload the page to update status
                    window.location.reload();
                    return;
                }

                const hours = Math.floor(remainingSeconds / 3600);
                const minutes = Math.floor((remainingSeconds % 3600) / 60);
                const seconds = remainingSeconds % 60;

                countdownElement.textContent = 
                    `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                remainingSeconds--;
            }

            // Update countdown every second
            updateCountdown();
            setInterval(updateCountdown, 1000);
        <?php endif; ?>
    </script>
</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>