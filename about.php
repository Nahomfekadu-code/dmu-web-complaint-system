<?php
session_start();
require_once 'db_connect.php';
// No database connection needed typically for a static 'About' page,
// but include if header/footer might eventually require user data.
// require_once 'db_connect.php';

// No specific PHP logic needed for a simple about page
// No redirection check needed here - anyone can view 'About'

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error']);
unset($_SESSION['success']); // Clear any leftover messages

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | DMU Complaint System</title>
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
            line-height: 1.7; /* Increased line height for readability */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start; /* Start content from top */
            min-height: 100vh;
            padding: 80px 20px 40px 20px; /* Add padding top to account for nav */
        }

        /* --- Simple Top Nav --- */
        .public-nav {
            position: absolute; top: 20px; left: 20px; /* Fixed position */
            display: flex; align-items: center; gap: 15px; z-index: 10;
             background-color: rgba(255, 255, 255, 0.8); /* Optional background for contrast */
             padding: 5px 15px;
             border-radius: var(--radius);
             box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
         .public-nav img.logo-img { height: 35px; }
         .public-nav a { color: var(--grey); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: var(--transition); }
         .public-nav a:hover { color: var(--primary); }
         .public-nav span { /* Separator */ color: var(--border-color); }


        /* --- About Card --- */
        .about-container {
            width: 100%;
            max-width: 800px; /* Wider card for content */
            margin: 20px auto;
        }

        .about-card {
            background-color: var(--card-bg);
            padding: 40px 45px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            animation: fadeInUp 0.6s ease-out;
        }

        .about-header { text-align: center; margin-bottom: 30px; }
        .about-header h2 {
            font-size: 2rem; font-weight: 700;
            color: var(--primary-dark); margin-bottom: 10px;
        }
        .about-header p.subtitle { color: var(--text-muted); font-size: 1.05rem; margin-bottom: 0; }

        /* --- About Content Styling --- */
        .about-content h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
            margin-top: 30px;
            margin-bottom: 15px;
             display: flex;
             align-items: center;
             gap: 8px;
        }
         .about-content h3 i {
             font-size: 1.1em;
         }
        .about-content p {
            margin-bottom: 20px;
            font-size: 1rem;
            color: var(--text-color);
        }
        .about-content ul {
            list-style: none; /* Remove default bullets */
            padding-left: 0;
            margin-bottom: 20px;
        }
         .about-content li {
             position: relative;
             padding-left: 25px; /* Space for icon */
             margin-bottom: 10px;
             font-size: 1rem;
         }
         .about-content li::before {
            content: '\f00c'; /* Font Awesome check icon */
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 0;
            top: 4px;
            color: var(--success); /* Green check */
            font-size: 0.9em;
         }


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

        /* --- Footer --- */
        .simple-footer { text-align: center; margin-top: 40px; font-size: 0.85rem; color: var(--grey); }

        /* --- Animations --- */
         @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }

        /* --- Responsive --- */
         @media (max-width: 768px) {
             body { padding: 60px 15px 30px 15px; }
             .public-nav { left: 15px; top: 15px; }
             .about-header h2 { font-size: 1.6rem; }
             .about-content h3 { font-size: 1.15rem; }
             .about-content p, .about-content li { font-size: 0.95rem; }
             .about-card { padding: 30px 25px; }
         }
         @media (max-width: 500px) {
             .public-nav { justify-content: center; width: calc(100% - 40px); }
             .about-card { padding: 25px 20px; }
         }

    </style>
</head>
<body>

    <nav class="public-nav">
         <a href="index.php" title="Go to Homepage">
             <img src="images/logo.jpg" alt="Debre Markos University Logo" class="logo-img"> <!-- ** ADJUST PATH ** -->
         </a>
         <span>|</span>
         <a href="login.php">Login</a>
         <span>|</span>
         <a href="user/create_account.php">Register</a>
    </nav>

    <div class="about-container">
        <div class="about-card">
            <div class="about-header">
                <h2>About the Complaint Management System</h2>
                <p class="subtitle">Serving the Debre Markos University Community</p>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                 <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="about-content">
                <p>Welcome to the official Complaint Management System (CMS) for <strong>Debre Markos University, Ethiopia</strong>. This platform is designed to provide a streamlined, transparent, and efficient way for students, staff, and faculty to submit, track, and manage complaints or grievances.</p>

                <h3><i class="fas fa-bullseye"></i> Our Mission</h3>
                <p>Our primary goal is to foster a responsive and supportive university environment by ensuring that all concerns are heard, addressed promptly, and resolved fairly. We aim to enhance communication channels and improve accountability within the university structure.</p>

                <h3><i class="fas fa-cogs"></i> How It Works</h3>
                <ul>
                    <li>Users register and log in securely.</li>
                    <li>Complaints are submitted through a structured form.</li>
                    <li>Users can track the status of their submissions via their dashboard.</li>
                    <li>Designated handlers review, categorize, validate, and manage complaints.</li>
                    <li>Complaints may be resolved directly or escalated to appropriate bodies if necessary.</li>
                    <li>Notifications keep users informed about updates to their complaints.</li>
                </ul>

                 <h3><i class="fas fa-star"></i> Key Features</h3>
                <ul>
                    <li>Secure user authentication and role-based access.</li>
                    <li>Intuitive complaint submission process.</li>
                    <li>Real-time status tracking for submitted complaints.</li>
                    <li>Options for standard or anonymous submissions.</li>
                    <li>Efficient workflow for complaint handlers and administrators.</li>
                    <li>Notification system for updates and actions.</li>
                </ul>

                <h3><i class="fas fa-users"></i> About the Project Team</h3>
                <p>This system was developed as a Final Year Project by <strong>Group 4</strong> at Debre Markos University. Our team is dedicated to providing a robust and reliable tool to serve the university community.</p>

                 <p style="margin-top: 30px; font-style: italic; color: var(--text-muted);">We value your feedback to help us continuously improve this system. Please use the <a href="contact.php" style="color: var(--primary); text-decoration: none;">Contact Us</a> page for suggestions or technical issues.</p>

            </div>
        </div>

        <footer class="simple-footer">
             © <?php echo date("Y"); ?> Debre Markos University Complaint Management System
        </footer>
    </div>

</body>
</html>
<?php $db->close(); ?>