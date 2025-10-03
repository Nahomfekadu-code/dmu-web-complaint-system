<?php
session_start();
require_once '../db_connect.php';

// If the user is already logged in, redirect them to the appropriate dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    if ($role == 'user') {
        // Redirect user dashboard or specific user page
        header("Location: dashboard.php"); // Assuming user dashboard exists in user/
    } elseif ($role == 'handler') {
        header("Location: ../handler/dashboard.php");
    } elseif ($role == 'admin') {
        header("Location: ../admin/dashboard.php");
    }
    // Add other role redirects if necessary
    exit;
}

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error']);
unset($_SESSION['success']);

$fname = $_SESSION['form_data']['fname'] ?? '';
$lname = $_SESSION['form_data']['lname'] ?? '';
$email = $_SESSION['form_data']['email'] ?? '';
$username = $_SESSION['form_data']['username'] ?? '';
unset($_SESSION['form_data']); // Clear form data after retrieving


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Store form data in session to repopulate on error
    $_SESSION['form_data'] = $_POST;

    // Validation
    $errors = [];
    if (empty($fname) || empty($lname) || empty($email) || empty($username) || empty($password) || empty($confirm_password)) {
        $errors[] = "All fields are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
     // Simple password complexity check (example)
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    // Check username format (example: alphanumeric + underscore)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
         $errors[] = "Username can only contain letters, numbers, and underscores.";
    }


    if (empty($errors)) {
        // Check if email or username already exists
        $sql_check = "SELECT id FROM users WHERE email = ? OR username = ?";
        $stmt_check = $db->prepare($sql_check);
        if($stmt_check) {
            $stmt_check->bind_param("ss", $email, $username);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows > 0) {
                $errors[] = "Email or username already taken. Please choose another.";
            }
            $stmt_check->close();
        } else {
             $errors[] = "Database error checking existing user.";
             error_log("DB Prepare Error (check user): " . $db->error);
        }
    }

    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $role = 'user'; // Default role for registration

        // Insert the new user into the database
        $sql_insert = "INSERT INTO users (fname, lname, email, username, password, role, created_at, sex)
                       VALUES (?, ?, ?, ?, ?, ?, NOW(), 'other')"; // Added default 'other' for sex
        $stmt_insert = $db->prepare($sql_insert);
        if ($stmt_insert) {
            // Assuming sex is required, added 'other' as default. Adjust if needed.
             // Check your table structure: order is fname, lname, email, username, password, role
            $stmt_insert->bind_param("ssssss", $fname, $lname, $email, $username, $hashed_password, $role);
            if ($stmt_insert->execute()) {
                unset($_SESSION['form_data']); // Clear form data on success
                $_SESSION['success'] = "Account created successfully! Please log in.";
                header("Location: ../login.php"); // Redirect to login page
                exit;
            } else {
                $errors[] = "Error creating account. Please try again later.";
                error_log("DB Execute Error (insert user): " . $stmt_insert->error);
            }
            $stmt_insert->close();
        } else {
            $errors[] = "Database error preparing registration.";
             error_log("DB Prepare Error (insert user): " . $db->error);
        }
    }

    // If errors occurred, store them in the session
    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
    }

    header("Location: create_account.php"); // Redirect back to registration page
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4A90E2; /* Brighter blue */
            --primary-dark: #3A5FCD;
            --secondary: #50E3C2; /* Teal accent */
            --light: #f8f9fa;
            --dark: #343a40;
            --grey: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
            --background: #f0f4f8; /* Lighter, cooler background */
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 8px;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease-in-out;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            background-color: var(--background);
            color: var(--text-color);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            align-items: center; /* Center content vertically */
            justify-content: center; /* Center content horizontally */
            min-height: 100vh;
            padding: 20px;
        }

        /* --- Simple Top Nav for Public Pages --- */
        .public-nav {
            position: absolute; /* Position relative to body */
            top: 20px;
            left: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 10;
        }
         .public-nav img.logo-img { height: 35px; }
         .public-nav a {
             color: var(--grey);
             text-decoration: none;
             font-weight: 500;
             font-size: 0.95rem;
             transition: var(--transition);
         }
         .public-nav a:hover { color: var(--primary); }

        /* --- Registration Card --- */
        .register-container {
            width: 100%;
            max-width: 550px; /* Slightly wider card */
            margin: 20px auto; /* Auto margins for centering */
        }

        .register-card {
            background-color: var(--card-bg);
            padding: 40px 45px; /* More padding */
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            animation: fadeInUp 0.6s ease-out;
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 10px;
        }
        .register-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0;
        }

        /* --- Form Styles --- */
        .form-group {
            margin-bottom: 20px;
            position: relative; /* For icon positioning */
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--text-color);
        }
        .input-wrapper {
             position: relative;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            padding-left: 40px; /* Space for icon */
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); /* Focus ring */
        }
         .input-icon {
             position: absolute;
             left: 12px;
             top: 50%;
             transform: translateY(-50%);
             color: var(--grey);
             font-size: 1rem;
             pointer-events: none; /* Allow clicks to pass through */
         }
         .form-group input:focus + .input-icon {
              color: var(--primary); /* Change icon color on focus */
         }
         .name-group {
             display: grid;
             grid-template-columns: 1fr 1fr;
             gap: 15px;
         }


        /* --- Buttons --- */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 20px; border: none; border-radius: var(--radius);
            font-size: 1rem; font-weight: 600; cursor: pointer;
            transition: var(--transition); text-decoration: none; line-height: 1.5;
            width: 100%; /* Full width button */
            margin-top: 10px;
        }
        .btn i { font-size: 1.1em; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 10px rgba(74, 144, 226, 0.3); }

        /* --- Alerts --- */
        .alert {
            padding: 12px 18px; margin-bottom: 25px; border-radius: var(--radius);
            border: 1px solid transparent; display: flex; align-items: center;
            gap: 10px; font-weight: 500; font-size: 0.9rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.1rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert ul { margin-left: 20px; padding: 0; list-style: disc; } /* For multiple errors */
         .alert-danger ul li { margin-bottom: 5px; }

        /* --- Login Link --- */
        .login-link {
            text-align: center;
            margin-top: 25px;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        .login-link a {
            color: var(--primary);
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition);
        }
        .login-link a:hover {
            text-decoration: underline;
            color: var(--primary-dark);
        }

         /* --- Footer --- */
        .simple-footer {
            text-align: center;
            margin-top: 30px;
            font-size: 0.85rem;
            color: var(--grey);
        }

        /* --- Animations --- */
         @keyframes fadeInUp {
             from { opacity: 0; transform: translateY(30px); }
             to { opacity: 1; transform: translateY(0); }
         }

        /* --- Responsive --- */
         @media (max-width: 500px) {
             .register-card { padding: 30px 25px; }
             .name-group { grid-template-columns: 1fr; gap: 20px;} /* Stack name fields */
             .btn { font-size: 0.95rem; }
             .public-nav { justify-content: center; width: calc(100% - 40px); } /* Center nav */
         }

    </style>
</head>
<body>

    <nav class="public-nav">
         <a href="../index.php" title="Go to Homepage">
             <img src="../images/logo.jpg" alt="DMU Logo" class="logo-img"> <!-- ** ADJUST PATH ** -->
         </a>
        <!-- <a href="../index.php">Home</a> -->
    </nav>

    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <h2>Create Your Account</h2>
                <p>Join the DMU Complaint System</p>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                 <div class="alert alert-danger">
                     <i class="fas fa-exclamation-triangle"></i>
                     <div><?php echo $error; // Already includes <br> if multiple errors ?></div>
                 </div>
            <?php endif; ?>

            <form method="post" action="create_account.php">
                <div class="name-group">
                    <div class="form-group">
                        <label for="fname">First Name</label>
                         <div class="input-wrapper">
                            <input type="text" id="fname" name="fname" value="<?php echo htmlspecialchars($fname); ?>" >
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="lname">Last Name</label>
                         <div class="input-wrapper">
                            <input type="text" id="lname" name="lname" value="<?php echo htmlspecialchars($lname); ?>" required  >
                             <span class="input-icon"><i class="fas fa-user"></i></span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                     <div class="input-wrapper">
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required placeholder="e.g., nahomfekedu@example.com">
                         <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required placeholder="Choose a unique username" pattern="[a-zA-Z0-9_]+" title="Only letters, numbers, and underscores allowed">
                         <span class="input-icon"><i class="fas fa-at"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                     <div class="input-wrapper">
                        <input type="password" id="password" name="password" required placeholder="Minimum 8 characters">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter your password">
                        <span class="input-icon"><i class="fas fa-check-circle"></i></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>

            <div class="login-link">
                Already have an account? <a href="../login.php">Log In</a>
            </div>
        </div>

        <footer class="simple-footer">
             © <?php echo date("Y"); ?> DMU Complaint Management System
        </footer>
    </div>

</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>