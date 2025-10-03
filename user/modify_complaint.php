<?php
session_start();
require_once '../db_connect.php'; // Ensure this path is correct

// Role check: Ensure the user is logged in and is a 'user'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    $_SESSION['error'] = "You must be logged in as a user to modify a complaint.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT); // Basic validation

// Fetch user details from database if not in session (for sidebar display)
if (!isset($_SESSION['fname']) || !isset($_SESSION['lname'])) {
    $sql_user = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_user = $db->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($result_user->num_rows > 0) {
            $user_details = $result_user->fetch_assoc();
            $_SESSION['fname'] = $user_details['fname'];
            $_SESSION['lname'] = $user_details['lname'];
        } else {
            // Handle case where user details are missing but session exists
            $_SESSION['fname'] = 'Unknown';
            $_SESSION['lname'] = 'User';
        }
        $stmt_user->close();
    } else {
         // Handle DB error during user fetch
         error_log("Modify Complaint: Failed to prepare user fetch statement - " . $db->error);
         $_SESSION['fname'] = 'DB';
         $_SESSION['lname'] = 'Error';
    }
}

// --- Complaint Fetching and Validation ---

if (!$complaint_id) {
    $_SESSION['error'] = "No complaint ID provided.";
    header("Location: check_complaint_status.php");
    exit;
}

// Fetch the specific complaint, ensuring it belongs to the user and is 'pending'
$sql_complaint = "SELECT id, user_id, title, description, visibility, evidence_file, status
                  FROM complaints
                  WHERE id = ? AND user_id = ?";
$stmt_complaint = $db->prepare($sql_complaint);

if (!$stmt_complaint) {
    $_SESSION['error'] = "Database error preparing to fetch complaint.";
    error_log("Modify Complaint: Failed to prepare complaint fetch statement - " . $db->error);
    header("Location: check_complaint_status.php");
    exit;
}

$stmt_complaint->bind_param("ii", $complaint_id, $user_id);
$stmt_complaint->execute();
$result_complaint = $stmt_complaint->get_result();
$complaint = $result_complaint->fetch_assoc();
$stmt_complaint->close();

// Check if complaint exists, belongs to user, and is pending
if (!$complaint) {
    $_SESSION['error'] = "Complaint not found or you do not have permission to modify it.";
    header("Location: check_complaint_status.php");
    exit;
} elseif ($complaint['status'] != 'pending') {
     $_SESSION['error'] = "This complaint cannot be modified as its status is no longer 'pending'.";
     header("Location: check_complaint_status.php");
     exit;
}

// --- POST Request Handling (Form Submission) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Basic input trimming and retrieval
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $visibility = $_POST['visibility'] ?? 'standard'; // Default if not set
    $new_evidence_file_name = $complaint['evidence_file']; // Default to existing file unless changed
    $upload_error = false; // Flag for upload issues

    // Validate required fields
    if (empty($title) || empty($description)) {
        $_SESSION['error'] = "Title and description are required.";
    } else {
        // --- Handle File Upload (if a new file is provided) ---
        if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/'; // Ensure this directory exists and is writable
            $max_file_size = 5 * 1024 * 1024; // 5MB
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            // More robust check using finfo
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $allowed_mime_types = ['image/jpeg', 'image/png', 'application/pdf'];

            if (!is_dir($upload_dir)) {
                // Try creating with more secure permissions
                if (!mkdir($upload_dir, 0755, true)) {
                    $_SESSION['error'] = "Server error: Cannot create upload directory.";
                    error_log("Modify Complaint: Failed to create directory: " . $upload_dir);
                    $upload_error = true;
                }
            }

            if (!$upload_error) {
                $file_info = pathinfo($_FILES['evidence_file']['name']);
                $file_extension = isset($file_info['extension']) ? strtolower($file_info['extension']) : '';
                $file_tmp_name = $_FILES['evidence_file']['tmp_name'];
                $file_size = $_FILES['evidence_file']['size'];
                $file_mime_type = $finfo->file($file_tmp_name); // Get MIME from content

                // Validate file
                if (!in_array($file_extension, $allowed_extensions)) {
                    $_SESSION['error'] = "Invalid file type (extension). Allowed: " . implode(', ', $allowed_extensions);
                    $upload_error = true;
                } elseif (!in_array($file_mime_type, $allowed_mime_types)) {
                     $_SESSION['error'] = "Invalid file content type. Allowed: JPEG, PNG, PDF.";
                     $upload_error = true;
                } elseif ($file_size > $max_file_size) {
                    $_SESSION['error'] = "File is too large. Maximum size is " . ($max_file_size / 1024 / 1024) . "MB.";
                    $upload_error = true;
                } else {
                    // Generate unique name and move file
                    $generated_name = uniqid('evidence_', true) . '.' . $file_extension;
                    $file_path = $upload_dir . $generated_name;

                    if (move_uploaded_file($file_tmp_name, $file_path)) {
                        // Successfully uploaded new file
                        $old_evidence_file = $complaint['evidence_file']; // Get old filename
                        $new_evidence_file_name = $generated_name; // Update variable for DB insert

                        // Delete old file if it exists and is different from the new one
                        if ($old_evidence_file && $old_evidence_file !== $new_evidence_file_name && file_exists($upload_dir . $old_evidence_file)) {
                            if (!unlink($upload_dir . $old_evidence_file)) {
                                // Log failure to delete old file, but don't stop the update
                                error_log("Modify Complaint: Failed to delete old evidence file: " . $upload_dir . $old_evidence_file);
                            }
                        }
                    } else {
                        $_SESSION['error'] = "Failed to upload evidence file. Please check server permissions.";
                        error_log("Modify Complaint: move_uploaded_file failed for " . $file_tmp_name . " to " . $file_path);
                        $upload_error = true;
                    }
                }
            }
        } elseif (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] != UPLOAD_ERR_NO_FILE && $_FILES['evidence_file']['error'] != UPLOAD_ERR_OK) {
             // Handle other upload errors explicitly
             $_SESSION['error'] = "File upload error: Code " . $_FILES['evidence_file']['error'];
             error_log("Modify Complaint: File upload error for user " . $user_id . ": Code " . $_FILES['evidence_file']['error']);
             $upload_error = true;
        }

        // --- Database Update ---
        // Proceed only if validation passed and no critical upload error occurred
        if (!isset($_SESSION['error']) && !$upload_error) {
            // Build the SQL query dynamically
            // Use htmlspecialchars on output, not necessarily on DB input with prepared statements
            $sql_update = "UPDATE complaints SET title = ?, description = ?, visibility = ?";
            $types = "sss";
            // Parameters array - use original trimmed values for DB
            $params = [$title, $description, $visibility];

            // Only add evidence_file to update if it actually changed
            if ($new_evidence_file_name !== $complaint['evidence_file']) {
                $sql_update .= ", evidence_file = ?";
                $types .= "s";
                $params[] = $new_evidence_file_name;
            }

            // Add WHERE clause
            $sql_update .= " WHERE id = ? AND user_id = ?"; // Extra check on user_id
            $types .= "ii";
            $params[] = $complaint_id;
            $params[] = $user_id;

            $stmt_update = $db->prepare($sql_update);

            if ($stmt_update === false) {
                 $_SESSION['error'] = "Error preparing update statement: " . $db->error;
                 error_log("Modify Complaint: Prepare failed: (" . $db->errno . ") " . $db->error . " SQL: " . $sql_update);
                 // If update fails after file upload, delete the newly uploaded file
                 if ($new_evidence_file_name !== $complaint['evidence_file'] && file_exists($upload_dir . $new_evidence_file_name)) {
                     unlink($upload_dir . $new_evidence_file_name);
                 }
            } else {
                 $stmt_update->bind_param($types, ...$params);

                 if ($stmt_update->execute()) {
                     $_SESSION['success'] = "Complaint updated successfully.";
                     header("Location: check_complaint_status.php"); // Redirect on success
                     exit;
                 } else {
                     $_SESSION['error'] = "Error updating complaint: " . $stmt_update->error;
                     error_log("Modify Complaint: Execute failed: (" . $stmt_update->errno . ") " . $stmt_update->error);
                     // If update fails after file upload, delete the newly uploaded file
                     if ($new_evidence_file_name !== $complaint['evidence_file'] && file_exists($upload_dir . $new_evidence_file_name)) {
                         unlink($upload_dir . $new_evidence_file_name);
                     }
                 }
                 $stmt_update->close();
            }
        }
    }
    // If errors occurred during POST, redirect back to the form to show messages
    // This uses the PRG pattern, but loses entered data. Can be improved with session flash data if needed.
    header("Location: modify_complaint.php?complaint_id=" . $complaint_id);
    exit;
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modify Complaint | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Montserrat', sans-serif;
        }

        body {
            background-color: #f5f7ff;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
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

        /* Horizontal Navigation */
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

        /* Content Container */
        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.8rem;
            text-align: center;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        /* Form Styles */
        .form {
            max-width: 700px; /* Slightly wider */
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
        .form-group textarea,
        .form-group select,
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #fdfdfd;
        }

        /* File input styling */
         .form-group input[type="file"] {
            padding: 5px;
        }
        .form-group input[type="file"]::file-selector-button {
            padding: 8px 15px;
            border: none;
            border-radius: calc(var(--radius) - 4px);
            background-color: var(--primary-light);
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-right: 10px;
        }
        .form-group input[type="file"]::file-selector-button:hover {
            background-color: var(--primary);
        }


        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
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

        .current-evidence {
            margin-top: 10px;
            font-size: 0.9rem;
            color: var(--gray);
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-radius: var(--radius);
            border: 1px solid var(--light-gray);
             display: inline-block; /* Fit content */
        }

        .current-evidence a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
             margin-left: 5px;
        }

        .current-evidence a:hover {
            text-decoration: underline;
        }
        .current-evidence i {
             margin-right: 5px;
             color: var(--info);
        }

        /* Alert Messages */
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
             flex-shrink: 0;
         }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger);
            color: #a51c2c;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: var(--success);
            color: #1c7430;
        }

        /* Buttons */
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.7rem; /* Slightly more gap */
             box-shadow: 0 4px 15px rgba(0,0,0,0.1);
             text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }
        .btn-primary:active {
             transform: translateY(-1px);
             box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }


        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto; /* Push footer down */
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            flex-shrink: 0;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
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

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav {
                width: 220px;
            }
            .user-info h4 { max-width: 140px; }
             .content-container { padding: 2rem; }
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
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
        }

        @media (max-width: 576px) {
            .vertical-nav { display: none; }
            .main-content { padding: 10px; }
             .horizontal-nav .logo { display: none; }
             .horizontal-menu a { padding: 6px 10px; font-size: 0.9rem; }
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
             .btn { width: 100%; } /* Full width button */
        }
    </style>
</head>
<body>
    <!-- Vertical Navigation -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <!-- Make sure this path is correct -->
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
             <!-- Navigation Links using $current_page -->
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
             <a href="view_decision.php" class="nav-link <?php echo $current_page == 'view_decision.php' ? 'active' : ''; ?>">
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
                 <!-- <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a> -->
            </div>
        </nav>

        <!-- Form Content -->
        <div class="content-container">
            <h2>Modify Complaint #<?php echo htmlspecialchars($complaint['id']); ?></h2>

             <!-- Display Session Messages -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <!-- Changed icon -->
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): // Success message might not be seen due to immediate redirect ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Ensure $complaint is not null before rendering form -->
            <?php if ($complaint): ?>
            <form method="POST" action="modify_complaint.php?complaint_id=<?php echo htmlspecialchars($complaint['id']); ?>" class="form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Complaint Title</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($complaint['title']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="6" required><?php echo htmlspecialchars($complaint['description']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="visibility">Visibility</label>
                    <select id="visibility" name="visibility" required>
                        <option value="standard" <?php echo ($complaint['visibility'] ?? 'standard') == 'standard' ? 'selected' : ''; ?>>Standard (Your name will be visible)</option>
                        <option value="anonymous" <?php echo ($complaint['visibility'] ?? '') == 'anonymous' ? 'selected' : ''; ?>>Anonymous (Your name will be hidden)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="evidence_file">Update Evidence (Optional)</label>
                    <input type="file" id="evidence_file" name="evidence_file" accept=".jpg,.jpeg,.png,.pdf">
                    <?php if (!empty($complaint['evidence_file'])): ?>
                        <div class="current-evidence">
                             <i class="fas fa-paperclip"></i> Current file:
                             <!-- Ensure uploads path is correct -->
                             <a href="../uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank" title="View current evidence">
                                 <?php echo htmlspecialchars($complaint['evidence_file']); ?>
                            </a>
                        </div>
                    <?php else: ?>
                         <div class="current-evidence" style="opacity: 0.7;">
                             <i class="fas fa-times-circle"></i> No current evidence file attached.
                         </div>
                    <?php endif; ?>
                     <small style="display: block; margin-top: 5px; color: var(--gray);">Allowed types: JPEG, PNG, PDF. Max size: 5MB.</small>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Complaint
                </button>
                 <a href="check_complaint_status.php" class="btn" style="background: var(--gray); color: white; margin-left: 10px;">
                     <i class="fas fa-times"></i> Cancel
                 </a>
            </form>
            <?php else: ?>
                 <p>Complaint data could not be loaded.</p>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div> <!-- Replace -->
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
    </div> <!-- End Main Content -->

    <!-- Optional JavaScript -->
     <script>
        // Example: Basic check for file size client-side (doesn't replace server validation)
        const fileInput = document.getElementById('evidence_file');
        const maxSize = 5 * 1024 * 1024; // 5MB

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    if (this.files[0].size > maxSize) {
                        alert('File is too large! Maximum size is 5MB.');
                        // Clear the file input
                        this.value = '';
                    }
                }
            });
        }
    </script>

</body>
</html>
<?php
// Close the database connection if it's open
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>