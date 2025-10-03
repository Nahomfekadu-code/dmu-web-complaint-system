<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'user'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user details
$sql = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    error_log("Error preparing user details query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: ../login.php");
    exit;
}

// Fetch complaint status summary
$status_summary = [
    'pending' => 0,
    'validated' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'rejected' => 0
];
$sql = "SELECT status, COUNT(*) as count FROM complaints WHERE user_id = ? GROUP BY status";
$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status_summary[$row['status']] = $row['count'];
    }
    $stmt->close();
} else {
    error_log("Error preparing status summary query: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaint status summary.";
}

// Fetch user's complaints
$sql = "SELECT * FROM complaints WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $complaints = $stmt->get_result();
    $stmt->close();
} else {
    error_log("Error preparing complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching complaints.";
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard | DMU Complaint System</title>
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

        /* Alerts */
        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }

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

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
            border-left: 4px solid;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .summary-card.pending { border-left-color: var(--warning); }
        .summary-card.validated { border-left-color: var(--success); }
        .summary-card.in_progress { border-left-color: var(--info); }
        .summary-card.resolved { border-left-color: var(--success); }
        .summary-card.rejected { border-left-color: var(--danger); }

        .summary-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .summary-card.pending i { color: var(--warning); }
        .summary-card.validated i { color: var(--success); }
        .summary-card.in_progress i { color: var(--info); }
        .summary-card.resolved i { color: var(--success); }
        .summary-card.rejected i { color: var(--danger); }

        .summary-card h4 {
            font-size: 1rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .summary-card p {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* User Profile */
        .profile-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 1.5rem;
            background: #f9f9f9;
            border-radius: var(--radius);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .profile-icon i {
            font-size: 3rem;
            color: var(--primary);
        }

        .profile-details p {
            margin: 5px 0;
            font-size: 0.95rem;
        }

        /* Complaints Table */
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background: #f9f9f9;
        }

        td.description {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Status Badges */
        .status {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-pending {
            background-color: rgba(255, 193, 7, 0.15);
            color: var(--warning);
        }

        .status-validated {
            background-color: rgba(40, 167, 69, 0.15);
            color: var(--success);
        }

        .status-in_progress {
            background-color: rgba(23, 162, 184, 0.15);
            color: var(--info);
        }

        .status-resolved {
            background-color: rgba(40, 167, 69, 0.15);
            color: var(--success);
        }

        .status-rejected {
            background-color: rgba(220, 53, 69, 0.15);
            color: var(--danger);
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 2rem;
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

            .summary-cards {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .content-container {
                padding: 1.25rem;
            }
            
            h2 {
                font-size: 1.3rem;
            }

            .profile-card {
                flex-direction: column;
                text-align: center;
            }

            .table-responsive {
                font-size: 0.85rem;
            }

            th, td {
                padding: 0.75rem;
            }

            td.description {
                max-width: 150px;
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
                    <h4><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($user['role'])); ?></p>
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

        <!-- Dashboard Content -->
        <div class="content-container">
            <h2>Welcome, <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?>!</h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Complaint Status Summary -->
            <div class="summary-cards">
                <div class="summary-card pending">
                    <i class="fas fa-hourglass-half"></i>
                    <h4>Pending</h4>
                    <p><?php echo $status_summary['pending']; ?></p>
                </div>
                <div class="summary-card validated">
                    <i class="fas fa-check-circle"></i>
                    <h4>Validated</h4>
                    <p><?php echo $status_summary['validated']; ?></p>
                </div>
                <div class="summary-card in_progress">
                    <i class="fas fa-spinner"></i>
                    <h4>In Progress</h4>
                    <p><?php echo $status_summary['in_progress']; ?></p>
                </div>
                <div class="summary-card resolved">
                    <i class="fas fa-check-double"></i>
                    <h4>Resolved</h4>
                    <p><?php echo $status_summary['resolved']; ?></p>
                </div>
                <div class="summary-card rejected">
                    <i class="fas fa-times-circle"></i>
                    <h4>Rejected</h4>
                    <p><?php echo $status_summary['rejected']; ?></p>
                </div>
            </div>

            <!-- User Profile Section -->
            <div class="user-profile">
                <h3>Your Profile</h3>
                <div class="profile-card">
                    <div class="profile-icon">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div class="profile-details">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                        <p><strong>Role:</strong> <?php echo htmlspecialchars(ucfirst($user['role'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Complaints Section -->
            <div class="complaints-list">
                <h3>Your Complaints</h3>
                <div style="margin-bottom: 1.5rem;">
                    <a href="submit_complaint.php" class="btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Submit New Complaint
                    </a>
                </div>
                <?php if ($complaints->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Visibility</th>
                                    <th>Status</th>
                                    <th>Submitted On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($complaint = $complaints->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $complaint['id']; ?></td>
                                        <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                        <td class="description"><?php echo htmlspecialchars($complaint['description']); ?></td>
                                        <td><?php echo ucfirst($complaint['category']); ?></td>
                                        <td><?php echo ucfirst($complaint['visibility']); ?></td>
                                        <td>
                                            <span class="status status-<?php echo strtolower($complaint['status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $complaint['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($complaint['created_at'])); ?></td>
                                        <td>
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-outline btn-small">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <p>You have not submitted any complaints yet.</p>
                    </div>
                <?php endif; ?>
                <?php if ($complaints) $complaints->free(); ?>
            </div>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation to summary cards
            const cards = document.querySelectorAll('.summary-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'fadeIn 0.4s ease-out forwards';
                card.style.opacity = '0';
            });

            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000);
            });

            // Tooltip for truncated descriptions
            const descriptions = document.querySelectorAll('td.description');
            descriptions.forEach(desc => {
                if (desc.scrollWidth > desc.clientWidth) {
                    desc.title = desc.textContent;
                }
            });
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>