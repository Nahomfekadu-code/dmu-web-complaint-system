<?php
session_start();
require_once 'db_connect.php'; // Ensure this path is correct

// Enable error reporting for debugging
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
$reset_token = $_SESSION['display_reset_token'] ?? null; // Specific session var to display token

unset($_SESSION['error']);
unset($_SESSION['success']);
unset($_SESSION['display_reset_token']); // Clear display token after showing once

// Redirect if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php"); // Or appropriate dashboard
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);

    if (empty($username)) {
        $error = "Please enter your username.";
    } else {
        // Check if username exists
        $sql_check = "SELECT id FROM users WHERE username = ?";
        $stmt_check = $db->prepare($sql_check);

        if($stmt_check) {
            $stmt_check->bind_param("s", $username);
            $stmt_check->execute();
            $result = $stmt_check->get_result();

            if($result->num_rows > 0) {
                $user_data = $result->fetch_assoc();
                $user_id = $user_data['id'];

                // --- Generate and Store Token (Insecure Session Method for Demo) ---
                $token = substr(bin2hex(random_bytes(16)), 0, 8); // Shorter, simpler token for demo display

                // Store token and associated user ID in session.
                // WARNING: Session storage is NOT suitable for production password resets.
                $_SESSION['reset_token_for_user_' . $user_id] = $token;
                $_SESSION['reset_token_expiry_for_user_' . $user_id] = time() + 3600; // 1 hour expiry (simple check)
                $_SESSION['reset_pending_user_id'] = $user_id; // Track which user ID is pending reset

                // Set messages for redirect
                $_SESSION['success'] = "Username found. Copy the reset token below and use it on the reset page.";
                $_SESSION['display_reset_token'] = $token; // Flag to display the token on next load

                 error_log("Password reset initiated for user ID: " . $user_id . " with demo token: " . $token);

            } else {
                 // Username doesn't exist
                 $_SESSION['error'] = "Username not found.";
                 error_log("Password reset requested for non-existent username: " . $username);
            }
            $stmt_check->close();
        } else {
            $_SESSION['error'] = "Database error checking username. Please try again.";
            error_log("Forgot Password DB Error (Check Username): " . $db->error);
        }

        // Redirect back to the same page to display messages/token
        header("Location: forgot_password.php");
        exit;
    }
     // If validation failed (empty username), set error and redirect
     if ($error) {
        $_SESSION['error'] = $error;
        header("Location: forgot_password.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Use the same CSS as login.php -->
    <style>
        :root {
            --primary: #4A90E2; --primary-dark: #3A5FCD; --secondary: #50E3C2;
            --light: #f8f9fa; --dark: #343a40; --grey: #6c757d; --success: #28a745;
            --danger: #dc3545; --warning: #ffc107; --info: #0dcaf0; --background: #f0f4f8;
            --card-bg: #ffffff; --text-color: #333; --text-muted: #6c757d;
            --border-color: #dee2e6; --radius: 8px; --shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease-in-out;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            background-color: var(--background); color: var(--text-color); line-height: 1.6;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            min-height: 100vh; padding: 20px;
        }
        .public-nav { position: absolute; top: 20px; left: 20px; display: flex; align-items: center; gap: 10px; z-index: 10; }
        .public-nav img.logo-img { height: 35px; border-radius: 50%; }
        .public-nav a { color: var(--grey); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: var(--transition); }
        .public-nav a:hover { color: var(--primary); }
        .card-container { width: 100%; max-width: 480px; margin: 20px auto; }
        .card { background-color: var(--card-bg); padding: 40px 45px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border-color); animation: fadeInUp 0.6s ease-out; }
        .card-header { text-align: center; margin-bottom: 30px; }
        .card-header h2 { font-size: 1.8rem; font-weight: 600; color: var(--primary-dark); margin-bottom: 10px; }
        .card-header p { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 0; }
        .form-group { margin-bottom: 25px; position: relative; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.9rem; color: var(--text-color); }
        .input-wrapper { position: relative; }
        .form-group input { width: 100%; padding: 12px 15px; padding-left: 40px; border: 1px solid var(--border-color); border-radius: var(--radius); font-size: 0.95rem; transition: var(--transition); }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); }
        .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--grey); font-size: 1rem; pointer-events: none; }
        .form-group input:focus + .input-icon { color: var(--primary); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border: none; border-radius: var(--radius); font-size: 1rem; font-weight: 600; cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5; width: 100%; margin-top: 10px; }
        .btn i { font-size: 1.1em; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(74, 144, 226, 0.3); }
        .alert { padding: 12px 18px; margin-bottom: 25px; border-radius: var(--radius); border: 1px solid transparent; display: flex; align-items: center; gap: 10px; font-weight: 500; font-size: 0.9rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); animation: fadeIn 0.5s ease-out; }
        .alert i { font-size: 1.1rem; flex-shrink: 0; }
        .alert span { flex-grow: 1; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }
        .extra-links { text-align: center; margin-top: 25px; font-size: 0.9rem; color: var(--text-muted); }
        .extra-links a { color: var(--primary); font-weight: 500; text-decoration: none; transition: var(--transition); margin: 0 5px; }
        .extra-links a:hover { text-decoration: underline; color: var(--primary-dark); }
        .simple-footer { text-align: center; margin-top: 30px; font-size: 0.85rem; color: var(--grey); }
        .token-display {
            background-color: var(--light-gray); border: 1px solid var(--border-color);
            padding: 15px; border-radius: var(--radius); margin-top: 15px;
            text-align: center; font-family: 'Courier New', Courier, monospace;
            font-size: 1.2rem; font-weight: bold; letter-spacing: 2px; color: var(--primary-dark);
            user-select: all; /* Make it easy to copy */
        }
        .token-display-label { font-size: 0.9rem; color: var(--grey); margin-bottom: 5px; display: block; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @media (max-width: 500px) { .card { padding: 30px 25px; } .btn { font-size: 0.95rem; } .public-nav { justify-content: center; width: calc(100% - 40px); } }
    </style>
</head>
<body>
    <nav class="public-nav">
        <a href="index.php" title="Go to Homepage">
            <img src="images/logo.jpg" alt="DMU Logo" class="logo-img"> <!-- Ensure path is correct -->
        </a>
    </nav>

    <div class="card-container">
        <div class="card">
            <div class="card-header">
                <h2>Reset Password Step 1</h2>
                <p>Enter your username to start the password reset process.</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>

             <!-- Display Token if generated -->
            <?php if ($reset_token): ?>
                <div class="token-display-container">
                    <span class="token-display-label">Your Password Reset Token (Copy this):</span>
                    <div class="token-display"><?php echo htmlspecialchars($reset_token); ?></div>
                    <p style="text-align: center; margin-top: 15px; font-size: 0.9rem;">
                        Now <a href="reset_password.php">click here to go to the reset page</a> and enter this token.
                    </p>
                     <p style="text-align: center; font-size: 0.8rem; color: var(--danger); margin-top: 10px;">
                        <strong>Warning:</strong> This token is displayed for demonstration only. Never show reset tokens like this in a real application!
                    </p>
                </div>
                <hr style="margin: 25px 0; border: none; border-top: 1px solid var(--border-color);">
            <?php else: ?>
                 <!-- Show form only if token hasn't been generated -->
                <form method="POST" action="forgot_password.php">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <input type="text" id="username" name="username" required placeholder="Enter your account username">
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key"></i> Request Reset Token
                    </button>
                </form>
            <?php endif; ?>


            <div class="extra-links">
                Remembered your password? <a href="login.php">Log In</a>
            </div>
        </div>

        <footer class="simple-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System
        </footer>
    </div>

</body>
</html>
<?php
// Close the database connection if it's open
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>