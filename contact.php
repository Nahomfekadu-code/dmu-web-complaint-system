<?php
session_start();
require_once 'db_connect.php'; // Included for consistency, might not be strictly needed for form

// No redirection needed if logged in, anyone can contact

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error']);
unset($_SESSION['success']);

// To repopulate form on error
$name = $_SESSION['form_data']['name'] ?? '';
$email = $_SESSION['form_data']['email'] ?? '';
$subject = $_SESSION['form_data']['subject'] ?? '';
// We typically don't repopulate the message field
unset($_SESSION['form_data']); // Clear form data after retrieving

// Handle Contact Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name']);
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    // Store submitted data for repopulation in case of error
    $_SESSION['form_data'] = ['name' => $name, 'email' => $email, 'subject' => $subject];

    // Validation
    $errors = [];
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $errors[] = "All fields are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if (strlen($message) < 10) {
        $errors[] = "Message seems too short. Please provide more details.";
    }

    if (empty($errors)) {
        // --- Email Sending Logic Would Go Here ---
        // Example: Use mail() function or a library like PHPMailer
        // $to = "your_dmu_support_email@dmu.edu.et"; // ** REPLACE with actual support email **
        // $email_subject = "Contact Form Submission: " . $subject;
        // $headers = "From: noreply@dmu-complaints.example.com" . "\r\n"; // Use a no-reply address
        // $headers .= "Reply-To: " . $email . "\r\n";
        // $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        // $email_body = "You have received a new message from the contact form.\n\n";
        // $email_body .= "Name: $name\n";
        // $email_body .= "Email: $email\n";
        // $email_body .= "Subject: $subject\n";
        // $email_body .= "Message:\n$message\n";
        //
        // if (mail($to, $email_subject, $email_body, $headers)) {
        //    $_SESSION['success'] = "Your message has been sent successfully! We'll get back to you soon.";
        //    unset($_SESSION['form_data']);
        // } else {
        //    $_SESSION['error'] = "There was an error sending your message. Please try again later.";
        //    error_log("Mail sending failed for contact form.");
        // }
        // --- End of Email Sending Logic ---

        // ** SIMULATION for this example **
        $_SESSION['success'] = "Your message has been received (simulation)! We'll get back to you soon.";
        unset($_SESSION['form_data']); // Clear form data on success

    } else {
        // If validation errors occurred
        $_SESSION['error'] = implode("<br>", $errors);
    }

    // Redirect back to the contact page
    header("Location: contact.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | DMU Complaint System</title>
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
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        /* --- Simple Top Nav --- */
        .public-nav {
            position: absolute; top: 20px; left: 20px;
            display: flex; align-items: center; gap: 10px; z-index: 10;
        }
         .public-nav img.logo-img { height: 35px; }
         .public-nav a { color: var(--grey); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: var(--transition); }
         .public-nav a:hover { color: var(--primary); }

        /* --- Contact Card --- */
        .contact-container {
            width: 100%;
            max-width: 650px; /* Wider card for contact form + info */
            margin: 20px auto;
        }

        .contact-card {
            background-color: var(--card-bg);
            padding: 40px 45px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            animation: fadeInUp 0.6s ease-out;
        }

        .contact-header { text-align: center; margin-bottom: 30px; }
        .contact-header h2 {
            font-size: 1.8rem; font-weight: 600;
            color: var(--primary-dark); margin-bottom: 10px;
        }
        .contact-header p { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 0; }

        /* --- Contact Info (Optional Section) --- */
        .contact-info {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px dashed var(--border-color);
            font-size: 0.95rem;
            color: var(--text-muted);
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }
        .info-item { display: flex; align-items: center; gap: 8px; }
        .info-item i { color: var(--primary); font-size: 1.1rem; }

        /* --- Form Styles --- */
        .form-group { margin-bottom: 20px; position: relative; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 0.9rem; color: var(--text-color); }
        .input-wrapper { position: relative; }
        .form-group input, .form-group textarea {
            width: 100%; padding: 12px 15px; padding-left: 40px;
            border: 1px solid var(--border-color); border-radius: var(--radius);
            font-size: 0.95rem; transition: var(--transition);
        }
         .form-group textarea { padding-left: 15px; min-height: 120px; resize: vertical; } /* Textarea specific */
        .form-group input:focus, .form-group textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2);
        }
         .input-icon {
             position: absolute; left: 12px; top: 14px; /* Adjusted for input padding */
             color: var(--grey); font-size: 1rem; pointer-events: none;
         }
         .form-group input:focus + .input-icon { color: var(--primary); }

        /* --- Buttons --- */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 20px; border: none; border-radius: var(--radius);
            font-size: 1rem; font-weight: 600; cursor: pointer;
            transition: var(--transition); text-decoration: none; line-height: 1.5;
            width: 100%; margin-top: 10px;
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
        .alert ul { margin-left: 20px; padding: 0; list-style: disc; }
         .alert-danger ul li { margin-bottom: 5px; }

        /* --- Footer --- */
        .simple-footer { text-align: center; margin-top: 30px; font-size: 0.85rem; color: var(--grey); }

        /* --- Animations --- */
         @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* --- Responsive --- */
         @media (max-width: 500px) {
             .contact-card { padding: 30px 25px; }
             .btn { font-size: 0.95rem; }
             .public-nav { justify-content: center; width: calc(100% - 40px); }
             .contact-info { flex-direction: column; align-items: center; gap: 10px; }
         }

    </style>
</head>
<body>

    <nav class="public-nav">
         <a href="index.php" title="Go to Homepage"> <!-- Changed from ../index.php assuming contact.php is in root -->
             <img src="../images/logo.jpg" alt="Debre Markos University Logo" class="logo-img"> <!-- ** ADJUST LOGO PATH/NAME ** -->
         </a>
    </nav>

    <div class="contact-container">
        <div class="contact-card">
            <div class="contact-header">
                <h2>Get In Touch</h2>
                <p>Have questions or need assistance? Send us a message!</p>
            </div>

             <!-- Optional Contact Info Section -->
             <div class="contact-info">
                <div class="info-item">
                    <i class="fas fa-map-marker-alt"></i> Debre Markos University, Ethiopia <!-- ** UPDATED ** -->
                </div>
                <div class="info-item">
                    <i class="fas fa-phone"></i> +251 587 71 XXXX <!-- ** UPDATED (Replace XXXX) ** -->
                </div>
                <div class="info-item">
                    <i class="fas fa-envelope"></i> <a href="mailto:support@dmu-cms.edu.et" style="color: var(--text-muted); text-decoration: none;">support@dmu-cms.edu.et</a> <!-- ** UPDATED (Replace with actual email) ** -->
                </div>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                 <div class="alert alert-danger">
                     <i class="fas fa-exclamation-triangle"></i>
                     <div><?php echo $error; // Allows <br> for multiple errors ?></div>
                 </div>
            <?php endif; ?>

            <form method="post" action="contact.php">
                <div class="form-group">
                    <label for="name">Your Name</label>
                     <div class="input-wrapper">
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required placeholder="e.g., Abebe Bekele">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Your Email Address</label>
                     <div class="input-wrapper">
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required placeholder="e.g., abebe.b@example.com">
                         <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="subject">Subject</label>
                    <div class="input-wrapper">
                        <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($subject); ?>" required placeholder="e.g., Question about submission process">
                         <span class="input-icon"><i class="fas fa-heading"></i></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" required placeholder="Enter your message here..."></textarea>
                    <!-- No icon for textarea usually -->
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
            </form>

        </div>

        <footer class="simple-footer">
             © <?php echo date("Y"); ?> Debre Markos University Complaint Management System <!-- ** UPDATED ** -->
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