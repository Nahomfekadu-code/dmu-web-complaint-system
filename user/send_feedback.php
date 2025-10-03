<?php
session_start();
require_once '../db_connect.php'; // Ensure this path is correct

// Check if the user is logged in and has the 'user' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    $_SESSION['error'] = "You must be logged in as a user to send feedback.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details from database if not in session (for sidebar)
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
         error_log("Send Feedback: Failed to prepare user fetch statement - " . $db->error);
         $_SESSION['fname'] = 'DB';
         $_SESSION['lname'] = 'Error';
     }
}

// Handle Form Submission (POST request)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Trim whitespace from the description
    $description = trim($_POST['description']);

    // Validate: Ensure description is not empty
    if (empty($description)) {
        $_SESSION['error'] = "Feedback description cannot be empty.";
    } else {
        // Sanitize description (optional, depending on whether you allow HTML)
        // Using htmlspecialchars here prevents basic XSS if feedback is displayed elsewhere unescaped
        $sanitized_description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        // Prepare the SQL statement
        $sql_insert = "INSERT INTO feedback (user_id, description, created_at) VALUES (?, ?, NOW())";
        $stmt_insert = $db->prepare($sql_insert);

        if ($stmt_insert === false) {
             // Handle prepare error
             $_SESSION['error'] = "Database error preparing feedback submission.";
             error_log("Send Feedback: Failed to prepare insert statement - " . $db->error);
        } else {
            $stmt_insert->bind_param("is", $user_id, $sanitized_description); // Use sanitized description

            // Execute the statement
            if ($stmt_insert->execute()) {
                $_SESSION['success'] = "Thank you! Your feedback has been submitted successfully.";
            } else {
                $_SESSION['error'] = "Error submitting feedback: " . $stmt_insert->error;
                error_log("Send Feedback: Failed to execute insert statement - " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }
    }
    // Redirect back to the same page to show messages (PRG Pattern)
    header("Location: send_feedback.php");
    exit;
}

// Get current page name for active link highlighting in navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Feedback | DMU Complaint System</title>
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
             border-radius: 50%; /* Optional */
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
            flex-direction: column; /* Ensure footer stays at bottom */
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
            flex-grow: 1; /* Take available space */
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
            max-width: 700px; /* Slightly wider form */
            margin: 1.5rem auto 0; /* Add margin top */
        }

        .form-group {
            margin-bottom: 25px; /* More space between elements */
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc; /* Slightly darker border */
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            min-height: 180px; /* Taller textarea */
            resize: vertical;
             background-color: #fdfdfd;
        }

        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
             background-color: white;
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

        /* Buttons */
        .btn {
            padding: 12px 30px; /* More horizontal padding */
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600; /* Bolder text */
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
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
            transform: translateY(-3px); /* Lift effect */
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

        <!-- Feedback Form Content -->
        <div class="content-container">
            <h2>Send Feedback</h2>
            <p style="text-align: center; color: var(--gray); margin-top: -1rem; margin-bottom: 2rem;">We value your input! Please share any feedback you have about the complaint system or the process.</p>

            <!-- Display Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <!-- Changed icon -->
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="send_feedback.php" class="form">
                <div class="form-group">
                    <label for="description">Your Feedback:</label>
                    <textarea id="description" name="description" placeholder="Please share your thoughts, suggestions, or concerns about the system or complaint handling process..." required></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit Feedback
                </button>
            </form>
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
        // Auto-hide alerts after a delay
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500); // Remove from DOM after fade out
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