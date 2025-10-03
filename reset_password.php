<?php
session_start();
require_once 'db_connect.php'; // Ensure this path is correct

// Enable error reporting for debugging
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null; // Not typically used here, maybe for intermediate steps
unset($_SESSION['error']);
unset($_SESSION['success']);

// Redirect if user is logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php"); // Or appropriate dashboard
    exit;
}

// Check if a reset is even pending (basic check)
if (!isset($_SESSION['reset_pending_user_id'])) {
     $_SESSION['error'] = "No password reset process initiated or session expired. Please request a token again.";
     header("Location: forgot_password.php");
     exit;
}

// Get the user ID for whom the reset is pending
$pending_user_id = $_SESSION['reset_pending_user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $entered_token = trim($_POST['token']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Retrieve expected token and expiry from session (using the pending user ID)
    $expected_token = $_SESSION['reset_token_for_user_' . $pending_user_id] ?? null;
    $token_expiry = $_SESSION['reset_token_expiry_for_user_' . $pending_user_id] ?? 0;

    // Validation
    if (empty($entered_token) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields: Token, New Password, and Confirm Password.";
    } elseif ($new_password !== $confirm_password) {
        $error = "The new passwords do not match.";
    } elseif (empty($expected_token)) {
         $error = "No reset token found in session. Please request a new one."; // Session might have expired or cleared
    } elseif (time() > $token_expiry) {
         $error = "The reset token has expired. Please request a new one.";
         // Clear expired token info
         unset($_SESSION['reset_token_for_user_' . $pending_user_id]);
         unset($_SESSION['reset_token_expiry_for_user_' . $pending_user_id]);
         unset($_SESSION['reset_pending_user_id']);
    } elseif (!hash_equals($expected_token, $entered_token)) { // Use hash_equals for timing attack resistance (though less critical for demo session token)
        $error = "Invalid reset token entered.";
    } else {
        // --- All checks passed - Update the password ---
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $sql_update = "UPDATE users SET password = ? WHERE id = ?";
        $stmt_update = $db->prepare($sql_update);

        if ($stmt_update) {
            $stmt_update->bind_param("si", $hashed_password, $pending_user_id);
            if ($stmt_update->execute()) {
                // Success! Clear session reset info and redirect to login
                unset($_SESSION['reset_token_for_user_' . $pending_user_id]);
                unset($_SESSION['reset_token_expiry_for_user_' . $pending_user_id]);
                unset($_SESSION['reset_pending_user_id']);

                $_SESSION['success'] = "Your password has been successfully reset. Please log in with your new password.";
                header("Location: login.php");
                exit;
            } else {
                $error = "Database error updating password. Please try again.";
                error_log("Reset Password DB Error (Update Execute): " . $stmt_update->error);
            }
            $stmt_update->close();
        } else {
            $error = "Database error preparing password update. Please try again.";
            error_log("Reset Password DB Error (Update Prepare): " . $db->error);
        }
    }

    // If errors occurred during POST, set session variable before redirecting back
     if ($error) {
        $_SESSION['error'] = $error;
        // Redirect back to reset page itself to show error
        header("Location: reset_password.php");
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Use the same CSS as forgot_password.php / login.php -->
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
        .form-group input[type="password"] { padding-right: 45px; /* Space for toggle icon */ }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); }
        .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--grey); font-size: 1rem; pointer-events: none; }
        .form-group input:focus + .input-icon { color: var(--primary); }
        .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--grey); cursor: pointer; font-size: 1.1rem; z-index: 2; }
        .toggle-password:hover { color: var(--primary); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; border: none; border-radius: var(--radius); font-size: 1rem; font-weight: 600; cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5; width: 100%; margin-top: 10px; }
        .btn i { font-size: 1.1em; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(74, 144, 226, 0.3); }
        .alert { padding: 12px 18px; margin-bottom: 25px; border-radius: var(--radius); border: 1px solid transparent; display: flex; align-items: center; gap: 10px; font-weight: 500; font-size: 0.9rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); animation: fadeIn 0.5s ease-out; }
        .alert i { font-size: 1.1rem; flex-shrink: 0; }
        .alert span { flex-grow: 1; }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .extra-links { text-align: center; margin-top: 25px; font-size: 0.9rem; color: var(--text-muted); }
        .extra-links a { color: var(--primary); font-weight: 500; text-decoration: none; transition: var(--transition); margin: 0 5px; }
        .extra-links a:hover { text-decoration: underline; color: var(--primary-dark); }
        .simple-footer { text-align: center; margin-top: 30px; font-size: 0.85rem; color: var(--grey); }
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
                <h2>Reset Password Step 2</h2>
                <p>Enter the token you received and set a new password.</p>
            </div>

            <?php if ($success): // Might not be used often here ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>

            <form method="POST" action="reset_password.php">
                 <div class="form-group">
                    <label for="token">Reset Token</label>
                    <div class="input-wrapper">
                        <input type="text" id="token" name="token" required placeholder="Enter the token from Step 1">
                        <span class="input-icon"><i class="fas fa-ticket-alt"></i></span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="new_password" name="new_password" required placeholder="Enter your new password">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <span class="toggle-password"><i class="fas fa-eye" id="togglePass1"></i></span>
                    </div>
                </div>
                 <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm your new password">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                         <span class="toggle-password"><i class="fas fa-eye" id="togglePass2"></i></span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Set New Password
                </button>
            </form>

            <div class="extra-links">
                 Need a token? <a href="forgot_password.php">Request Again</a> | <a href="login.php">Back to Login</a>
            </div>
        </div>

        <footer class="simple-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System
        </footer>
    </div>

    <script>
         document.addEventListener('DOMContentLoaded', function() {
            // Function to toggle password visibility
            function addPasswordToggle(toggleId, inputId) {
                const toggle = document.getElementById(toggleId);
                const input = document.getElementById(inputId);
                if (toggle && input) {
                    toggle.addEventListener('click', function() {
                        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                        input.setAttribute('type', type);
                        this.querySelector('i').classList.toggle('fa-eye');
                        this.querySelector('i').classList.toggle('fa-eye-slash');
                    });
                }
            }
            // Apply toggle to both password fields
            addPasswordToggle('togglePass1', 'new_password');
            addPasswordToggle('togglePass2', 'confirm_password');

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