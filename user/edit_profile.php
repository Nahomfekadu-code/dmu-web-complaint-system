<?php
session_start();
require_once '../db_connect.php'; // Ensure this path is correct

// Check if the user is logged in and has the 'user' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    $_SESSION['error'] = "You must be logged in as a user to edit your profile.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user = null; // Initialize user variable

// Fetch current user details directly from the database to ensure freshness
$sql_fetch = "SELECT fname, lname, email FROM users WHERE id = ?";
$stmt_fetch = $db->prepare($sql_fetch);

if ($stmt_fetch) {
    $stmt_fetch->bind_param("i", $user_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Update session variables with potentially fresh data (optional, but can be good)
        $_SESSION['fname'] = $user['fname'];
        $_SESSION['lname'] = $user['lname'];
    } else {
        // Critical error: Session user ID doesn't exist in DB. Force logout.
        session_unset();
        session_destroy();
        $_SESSION['error'] = "Your user account could not be found. Please log in again.";
        header("Location: ../login.php");
        exit;
    }
    $stmt_fetch->close();
} else {
    // Database error fetching user details
    $_SESSION['error'] = "Error fetching your profile data.";
    error_log("Edit Profile: Failed to prepare user fetch statement - " . $db->error);
    // Allow page to load but show error; user data might be stale from session
    // Or redirect: header("Location: dashboard.php"); exit;
}

// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve and trim input data
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $email = trim($_POST['email']);
    $password = $_POST['password']; // Don't trim password

    // Basic Validation
    if (empty($fname) || empty($lname) || empty($email)) {
        $_SESSION['error'] = "First Name, Last Name, and Email are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email format provided.";
    } else {
        // --- Check if email is already taken by another user ---
        $email_changed = ($user && $email !== $user['email']);
        $email_conflict = false;

        if ($email_changed) {
            $sql_check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
            $stmt_check_email = $db->prepare($sql_check_email);
            if ($stmt_check_email) {
                $stmt_check_email->bind_param("si", $email, $user_id);
                $stmt_check_email->execute();
                $stmt_check_email->store_result(); // Important for num_rows
                if ($stmt_check_email->num_rows > 0) {
                    $_SESSION['error'] = "This email address is already in use by another account.";
                    $email_conflict = true;
                }
                $stmt_check_email->close();
            } else {
                $_SESSION['error'] = "Database error checking email uniqueness.";
                error_log("Edit Profile: Failed to prepare email check statement - " . $db->error);
                $email_conflict = true; // Prevent update if check fails
            }
        }

        // --- Proceed with Update if no validation errors or email conflict ---
        if (!isset($_SESSION['error']) && !$email_conflict) {
            // Sanitize names for database storage (optional, htmlspecialchars here is mainly for output safety)
             $db_fname = htmlspecialchars($fname, ENT_QUOTES, 'UTF-8');
             $db_lname = htmlspecialchars($lname, ENT_QUOTES, 'UTF-8');
             $db_email = $email; // Email already validated

            // Prepare SQL based on whether password needs updating
            if (!empty($password)) {
                // Validate password strength (optional but recommended)
                // Example: if (strlen($password) < 8) { $_SESSION['error'] = "Password must be at least 8 characters."; }
                // else { ... }

                // Hash the new password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql_update = "UPDATE users SET fname = ?, lname = ?, email = ?, password = ? WHERE id = ?";
                $stmt_update = $db->prepare($sql_update);
                $types = "ssssi";
                $params = [$db_fname, $db_lname, $db_email, $hashed_password, $user_id];
            } else {
                // Update without changing password
                $sql_update = "UPDATE users SET fname = ?, lname = ?, email = ? WHERE id = ?";
                $stmt_update = $db->prepare($sql_update);
                $types = "sssi";
                $params = [$db_fname, $db_lname, $db_email, $user_id];
            }

            // Execute the update
            if ($stmt_update === false) {
                 $_SESSION['error'] = "Error preparing profile update statement.";
                 error_log("Edit Profile: Prepare failed - " . $db->error);
            } else {
                $stmt_update->bind_param($types, ...$params); // Use splat operator for variable params

                if ($stmt_update->execute()) {
                    $_SESSION['success'] = "Profile updated successfully!";
                    // Update session variables immediately
                    $_SESSION['fname'] = $fname; // Use original trimmed value for session display
                    $_SESSION['lname'] = $lname;
                    // Re-fetch user data to display updated info immediately (optional, redirection handles this too)
                    // $user = ['fname' => $fname, 'lname' => $lname, 'email' => $email];
                } else {
                    $_SESSION['error'] = "Error updating profile: " . $stmt_update->error;
                    error_log("Edit Profile: Execute failed - " . $stmt_update->error);
                }
                $stmt_update->close();
            }
        }
    }
    // Redirect back to the edit profile page to show messages
    header("Location: edit_profile.php");
    exit;
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Ensure $user is set for the form, even if initial fetch failed but session exists
if ($user === null) {
    $user = [
        'fname' => $_SESSION['fname'] ?? 'N/A',
        'lname' => $_SESSION['lname'] ?? 'N/A',
        'email' => 'N/A' // Don't show email if fetch failed
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | DMU Complaint System</title>
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
            flex-grow: 1; /* Take remaining space */
            animation: fadeIn 0.5s ease-out;
            /* Remove max-width and margin: 0 auto if horizontal nav is present */
             /* max-width: 800px; */
             /* margin: 0 auto; */
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

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.95rem;
            border-left: 5px solid transparent; /* Use thicker border */
             animation: fadeIn 0.5s ease-out;
        }

        .alert i {
            font-size: 1.2rem;
             flex-shrink: 0;
        }

        .alert.success {
            color: #1c7430; /* Darker success text */
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: var(--success);
        }

        .alert.error {
            color: #a51c2c; /* Darker error text */
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger);
        }

        /* Form Styles */
        .form {
             max-width: 700px; /* Center form within container */
             margin: 1rem auto 0; /* Add margin */
            display: flex;
            flex-direction: column;
            gap: 1.5rem; /* Space between form groups */
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem; /* Space between label and input */
        }

        .form-group label {
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            padding: 0.9rem 1rem;
            border: 1px solid #ccc; /* Use standard border */
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: var(--transition);
            width: 100%;
             background-color: #fdfdfd;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
             background-color: white;
        }

        .form-control::placeholder {
            color: var(--gray);
            opacity: 0.7; /* Slightly less opaque */
        }

        /* Button Styles */
        .btn {
            padding: 1rem 1.5rem; /* Adjust padding */
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex; /* Use inline-flex */
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
             box-shadow: 0 4px 15px rgba(0,0,0,0.1);
             text-decoration: none; /* Remove underline if used as link */
             align-self: flex-start; /* Align button left in flex container */
             margin-top: 0.5rem; /* Add margin top */
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

        /* Password Toggle */
        .password-group { /* Wrap label and input for positioning */
             position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px; /* Adjust position */
            /* Position relative to the input bottom */
             bottom: 14px; /* Adjust vertical position */
            cursor: pointer;
            color: var(--gray);
            font-size: 1.1rem; /* Slightly larger icon */
        }
        .toggle-password:hover {
            color: var(--primary);
        }

        /* Profile Picture Section - Placeholder */
        .profile-picture-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem; /* More space below picture */
             padding: 1.5rem;
             background-color: #f8f9fa;
             border: 1px solid var(--light-gray);
             border-radius: var(--radius);
             max-width: 400px; /* Limit width */
             margin-left: auto;
             margin-right: auto;
        }

        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover; /* Ensure image covers space */
            border: 4px solid var(--primary-light);
            box-shadow: var(--shadow);
            background-color: var(--light-gray); /* Background if no image */
        }

        .change-photo-btn {
             background: var(--light-gray);
             color: var(--dark);
             padding: 0.6rem 1.2rem;
             border-radius: var(--radius);
             font-size: 0.9rem;
             cursor: pointer;
             transition: var(--transition);
             border: 1px solid var(--gray); /* Subtle border */
             font-weight: 500;
        }

        .change-photo-btn:hover {
            background: var(--gray);
            color: white;
             border-color: var(--dark);
        }
        /* Hidden file input for actual upload */
         #profile_picture_input {
            display: none;
         }


        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto; /* Push to bottom */
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            flex-shrink: 0; /* Prevent shrinking */
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
            .vertical-nav { width: 220px; }
            .user-info h4 { max-width: 140px; }
             .content-container { padding: 2rem; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); }
             .main-content { padding: 15px; }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
             .form { max-width: 100%; } /* Allow form to take full width */
        }

        @media (max-width: 576px) {
            .vertical-nav { display: none; }
            .main-content { padding: 10px; }
            .horizontal-nav .logo { display: none; }
            .horizontal-menu a { padding: 6px 10px; font-size: 0.9rem; }
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
             .profile-picture-section { max-width: 100%; }
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
                     <!-- Use session data directly as it's updated on success -->
                    <h4><?php echo isset($_SESSION['fname'], $_SESSION['lname']) ? htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname']) : 'User'; ?></h4>
                    <p><?php echo isset($_SESSION['role']) ? htmlspecialchars(ucfirst($_SESSION['role'])) : 'Role'; ?></p>
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

        <!-- Profile Edit Content -->
        <div class="content-container">
            <h2>Edit Your Profile</h2>

            <!-- Display Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['success']); ?></span>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert error">
                    <i class="fas fa-times-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['error']); ?></span>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Profile Picture Placeholder Section -->
            <div class="profile-picture-section">
                <!-- Add logic here to display actual user profile picture if available -->
                 <img src="../images/default-profile.png" alt="Profile Picture" class="profile-picture" id="profile-picture-preview">
                 <input type="file" id="profile_picture_input" name="profile_picture" accept="image/png, image/jpeg" style="display: none;">
                 <button type="button" class="change-photo-btn" onclick="document.getElementById('profile_picture_input').click();">
                     <i class="fas fa-camera"></i> Change Photo
                 </button>
                 <small style="color: var(--gray); text-align: center;">(Note: Profile picture upload requires additional backend implementation)</small>
            </div>

            <!-- Check if $user data is available before rendering the form -->
            <?php if ($user): ?>
            <form method="post" action="edit_profile.php" class="form">
                <div class="form-group">
                    <label for="fname">First Name</label>
                    <input type="text" id="fname" name="fname" class="form-control"
                           value="<?php echo htmlspecialchars($user['fname']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="lname">Last Name</label>
                    <input type="text" id="lname" name="lname" class="form-control"
                           value="<?php echo htmlspecialchars($user['lname']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="password-group">
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="Leave blank to keep current password" autocomplete="new-password">
                        <i class="fas fa-eye toggle-password" id="togglePassword"></i>
                    </div>
                    <small style="color: var(--gray); margin-top: 5px;">Enter a new password only if you want to change it.</small>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Profile
                </button>
            </form>
             <?php else: ?>
                 <div class="alert error">
                     <i class="fas fa-exclamation-triangle"></i>
                     <span>Could not load profile data. Please try refreshing the page or contact support if the problem persists.</span>
                 </div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password toggle functionality
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');

            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    // Toggle icon class
                    this.classList.toggle('fa-eye');
                    this.classList.toggle('fa-eye-slash');
                });
            }

             // Basic image preview for profile picture (no upload logic)
             const profilePicInput = document.getElementById('profile_picture_input');
             const profilePicPreview = document.getElementById('profile-picture-preview');

             if (profilePicInput && profilePicPreview) {
                 profilePicInput.addEventListener('change', function(event) {
                     const file = event.target.files[0];
                     if (file && file.type.startsWith('image/')) {
                         const reader = new FileReader();
                         reader.onload = function(e) {
                             profilePicPreview.src = e.target.result;
                         }
                         reader.readAsDataURL(file);
                     } else {
                         // Optionally reset to default or show an error if file is not an image
                          // profilePicPreview.src = '../images/default-profile.png';
                          if (file) { // Only alert if a file was selected but wasn't an image
                               alert('Please select a valid image file (JPEG or PNG).');
                          }
                     }
                 });
             }

            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000); // 7 seconds
            });
        });
    </script>
</body>
</html>
<?php
// Close the database connection if it's open
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>