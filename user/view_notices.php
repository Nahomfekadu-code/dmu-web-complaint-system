<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'user'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch all notices
$sql = "SELECT n.*, u.fname, u.lname FROM notices n JOIN users u ON n.handler_id = u.id ORDER BY n.created_at DESC";
$result = $db->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Notices | DMU Complaint System</title>
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
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
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
        }

        .horizontal-menu {
            display: flex;
            gap: 10px;
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
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
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
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

        /* Notice Cards */
        .notice-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: var(--transition);
            border-left: 4px solid var(--primary);
        }

        .notice-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }

        .notice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--light-gray);
        }

        .notice-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .badge-new {
            background-color: var(--success);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 50px;
            font-weight: 600;
        }

        .notice-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .notice-meta i {
            color: var(--primary);
            width: 20px;
            text-align: center;
        }

        .notice-content {
            line-height: 1.6;
            padding: 1rem 0;
            white-space: pre-line;
        }

        .notice-date {
            font-size: 0.85rem;
            color: var(--gray);
            text-align: right;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
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
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .horizontal-nav {
                flex-direction: column;
                gap: 10px;
            }
            
            .horizontal-menu {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .content-container {
                padding: 1.5rem;
            }
            
            h2 {
                font-size: 1.5rem;
            }

            .notice-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        @media (max-width: 576px) {
            .content-container {
                padding: 1.25rem;
            }
            
            h2 {
                font-size: 1.3rem;
            }
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
                    <h4><?php echo isset($_SESSION['fname']) && isset($_SESSION['lname']) ? htmlspecialchars($_SESSION['fname'] . ' ' . $_SESSION['lname']) : 'User'; ?></h4>
                    <p><?php echo isset($_SESSION['role']) ? htmlspecialchars(ucfirst($_SESSION['role'])) : 'User'; ?></p>
                </div>
            </div>
        </div>
        
        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Overview</span>
            </a>
            
            <h3>Complaints</h3>
            <a href="submit_complaint.php" class="nav-link <?php echo $current_page == 'submit_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i>
                <span>Submit Complaint</span>
            </a>
            <a href="modify_complaint.php" class="nav-link <?php echo $current_page == 'modify_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i>
                <span>Modify Complaint</span>
            </a>
            <a href="check_complaint_status.php" class="nav-link <?php echo $current_page == 'check_complaint_status.php' ? 'active' : ''; ?>">
                <i class="fas fa-search"></i>
                <span>Check Status</span>
            </a>
            
            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </a>
            <a href="send_feedback.php" class="nav-link <?php echo $current_page == 'send_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-alt"></i>
                <span>Send Feedback</span>
            </a>
            <a href="view_decision.php" class="nav-link <?php echo $current_page == 'view_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span>View Decisions</span>
            </a>
            
            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="view_notices.php" class="nav-link <?php echo $current_page == 'view_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i>
                <span>View Notices</span>
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
                <a href="../index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Contact
                </a>
                <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>">
                    <i class="fas fa-info-circle"></i> About
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Notices Content -->
        <div class="content-container">
            <h2>Important Notices</h2>
            
            <?php if ($result->num_rows === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-bullhorn"></i>
                    <h3>No Notices Available</h3>
                    <p>There are currently no notices posted. Please check back later.</p>
                </div>
            <?php else: ?>
                <?php while ($notice = $result->fetch_assoc()): ?>
                    <div class="notice-card">
                        <div class="notice-header">
                            <div class="notice-title">
                                <?php echo htmlspecialchars($notice['title']); ?>
                                <?php
                                $notice_date = new DateTime($notice['created_at']);
                                $now = new DateTime();
                                $interval = $now->diff($notice_date);
                                $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                                if ($minutes <= 3) {
                                    echo '<span class="badge-new">New</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="notice-meta">
                            <div class="notice-meta-item">
                                <i class="fas fa-user"></i>
                                <span>Posted by: <?php echo htmlspecialchars($notice['fname'] . ' ' . $notice['lname']); ?></span>
                            </div>
                            <div class="notice-meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                <span>Posted on: <?php echo date('M j, Y \a\t g:i a', strtotime($notice['created_at'])); ?></span>
                            </div>
                        </div>
                        
                        <div class="notice-content">
                            <?php echo nl2br(htmlspecialchars($notice['description'])); ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint Management System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        // Add animation to notice cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.notice-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'fadeIn 0.4s ease-out forwards';
                card.style.opacity = '0';
            });
        });
    </script>
</body>
</html>
<?php $db->close(); ?>