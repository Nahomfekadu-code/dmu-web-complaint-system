<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'president'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'president') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../unauthorized.php");
    exit;
}

$president_id = $_SESSION['user_id'];
$complaint_id = isset($_GET['complaint_id']) ? filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT) : null;
$complaint = null;
$handler = null;

// Validate complaint ID and fetch complaint details
if (!$complaint_id) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: view_escalated.php");
    exit;
}

// Fetch complaint details
$sql_complaint = "
    SELECT c.*, 
           u_submitter.fname as submitter_fname, u_submitter.lname as submitter_lname,
           u_handler.fname as handler_fname, u_handler.lname as handler_lname, u_handler.id as handler_id
    FROM complaints c
    JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN users u_handler ON c.handler_id = u_handler.id
    WHERE c.id = ? AND c.status = 'escalated'";
$stmt_complaint = $db->prepare($sql_complaint);
if ($stmt_complaint) {
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $result = $stmt_complaint->get_result();
    if ($result->num_rows > 0) {
        $complaint = $result->fetch_assoc();
        $handler = [
            'id' => $complaint['handler_id'],
            'fname' => $complaint['handler_fname'],
            'lname' => $complaint['handler_lname']
        ];
    }
    $stmt_complaint->close();
}

if (!$complaint || !$handler) {
    $_SESSION['error'] = "Complaint not found, not escalated, or no handler assigned.";
    header("Location: view_escalated.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $decision_text = trim($_POST['decision_text'] ?? '');
    $attachment_path = null;

    // Validate decision text
    if (empty($decision_text)) {
        $_SESSION['error'] = "Decision text is required.";
        header("Location: send_decision.php?complaint_id=$complaint_id");
        exit;
    }

    // Handle file upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] != UPLOAD_ERR_NO_FILE) {
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if ($_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            if (!in_array($_FILES['attachment']['type'], $allowed_types)) {
                $_SESSION['error'] = "Invalid file type. Allowed types: PDF, JPEG, PNG, DOC, DOCX.";
                header("Location: send_decision.php?complaint_id=$complaint_id");
                exit;
            }

            if ($_FILES['attachment']['size'] > $max_size) {
                $_SESSION['error'] = "File size exceeds 5MB limit.";
                header("Location: send_decision.php?complaint_id=$complaint_id");
                exit;
            }

            $upload_dir = '../uploads/decisions/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_name = uniqid() . '_' . basename($_FILES['attachment']['name']);
            $attachment_path = $upload_dir . $file_name;

            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $attachment_path)) {
                $_SESSION['error'] = "Failed to upload the attachment.";
                header("Location: send_decision.php?complaint_id=$complaint_id");
                exit;
            }
        } else {
            $_SESSION['error'] = "Error uploading file: " . $_FILES['attachment']['error'];
            header("Location: send_decision.php?complaint_id=$complaint_id");
            exit;
        }
    }

    // Begin transaction
    $db->begin_transaction();
    try {
        // Insert decision into decisions table
        $sql_decision = "INSERT INTO decisions (complaint_id, sender_id, receiver_id, decision_text, decision_status, attachment_path) VALUES (?, ?, ?, ?, 'pending', ?)";
        $stmt_decision = $db->prepare($sql_decision);
        if ($stmt_decision) {
            $stmt_decision->bind_param("iiiss", $complaint_id, $president_id, $handler['id'], $decision_text, $attachment_path);
            $stmt_decision->execute();
            $stmt_decision->close();
        } else {
            throw new Exception("Error preparing decision insert: " . $db->error);
        }

        // Update complaint status to 'in_progress'
        $sql_update = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
        $stmt_update = $db->prepare($sql_update);
        if ($stmt_update) {
            $stmt_update->bind_param("i", $complaint_id);
            $stmt_update->execute();
            $stmt_update->close();
        } else {
            throw new Exception("Error preparing complaint update: " . $db->error);
        }

        // Create notification for the handler
        $message = "The president has sent a decision for complaint #$complaint_id: " . htmlspecialchars($complaint['title']);
        $sql_notification = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
        $stmt_notification = $db->prepare($sql_notification);
        if ($stmt_notification) {
            $stmt_notification->bind_param("is", $handler['id'], $message);
            $stmt_notification->execute();
            $stmt_notification->close();
        } else {
            throw new Exception("Error preparing notification insert: " . $db->error);
        }

        // Commit transaction
        $db->commit();
        $_SESSION['success'] = "Decision sent to handler successfully.";
        header("Location: view_escalated.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        if ($attachment_path && file_exists($attachment_path)) {
            unlink($attachment_path); // Remove uploaded file on failure
        }
        $_SESSION['error'] = "Failed to send decision: " . $e->getMessage();
        error_log("Decision sending error: " . $e->getMessage());
        header("Location: send_decision.php?complaint_id=$complaint_id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Decision | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --grey: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
            --orange: #fd7e14;
            --background: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 8px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50;
            --navbar-link: #bdc3c7;
            --navbar-link-hover: #34495e;
            --navbar-link-active: #3498db;
            --topbar-bg: #ffffff;
            --topbar-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body { background-color: var(--background); color: var(--text-color); line-height: 1.6; }

        .vertical-navbar {
            width: 260px; height: 100vh; position: fixed; left: 0; top: 0;
            background-color: var(--navbar-bg); color: #ecf0f1;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000;
            display: flex; flex-direction: column; transition: width 0.3s ease;
        }
        .nav-header { padding: 25px 20px; text-align: center; border-bottom: 1px solid #34495e; flex-shrink: 0;}
        .nav-header h3 { margin: 0; font-size: 1.3rem; color: #ecf0f1; font-weight: 600; }
        .nav-links { list-style: none; padding: 0; margin: 15px 0; overflow-y: auto; flex-grow: 1;}
        .nav-links li a {
            display: flex; align-items: center; padding: 14px 25px;
            color: var(--navbar-link); text-decoration: none; transition: all 0.3s ease;
            font-size: 0.95rem; white-space: nowrap;
        }
        .nav-links li a:hover { background-color: var(--navbar-link-hover); color: #ecf0f1; }
        .nav-links li a.active { background-color: var(--navbar-link-active); color: white; font-weight: 500; }
        .nav-links li a i { margin-right: 15px; width: 20px; text-align: center; font-size: 1.1em; }
        .nav-footer { padding: 20px; text-align: center; border-top: 1px solid #34495e; font-size: 0.85rem; color: #7f8c8d; flex-shrink: 0; }

        .horizontal-navbar {
            display: flex; justify-content: space-between; align-items: center;
            height: 70px; padding: 0 30px; background-color: var(--topbar-bg);
            box-shadow: var(--topbar-shadow); position: fixed; top: 0; right: 0; left: 260px;
            z-index: 999; transition: left 0.3s ease;
        }
        .top-nav-left .page-title { color: var(--dark); font-size: 1.1rem; font-weight: 500; }
        .top-nav-right { display: flex; align-items: center; gap: 20px; }
        .notification-icon i { font-size: 1.3rem; color: var(--grey); cursor: pointer; }

        .main-content {
            margin-left: 260px; padding: 30px; padding-top: 100px;
            transition: margin-left 0.3s ease; min-height: calc(100vh - 70px);
        }
        .page-header h2 {
            font-size: 1.8rem; font-weight: 600; color: var(--primary-dark);
            margin-bottom: 25px; border-bottom: 2px solid var(--primary);
            padding-bottom: 10px; display: inline-block;
        }

        .card {
            background-color: var(--card-bg); padding: 25px; border-radius: var(--radius);
            box-shadow: var(--shadow); margin-bottom: 30px; border: 1px solid var(--border-color);
        }
        .card-header {
            display: flex; align-items: center; gap: 12px; margin-bottom: 25px;
            color: var(--primary-dark); font-size: 1.3rem; font-weight: 600;
            padding-bottom: 15px; border-bottom: 1px solid var(--border-color);
        }
        .card-header i { font-size: 1.5rem; color: var(--primary); }

        .complaint-details p {
            margin-bottom: 10px; color: var(--text-muted);
        }
        .complaint-details p strong {
            color: var(--text-color); font-weight: 500; min-width: 120px; display: inline-block;
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block; margin-bottom: 5px; font-weight: 500; color: var(--dark);
        }
        .form-group textarea, .form-group input[type="file"] {
            width: 100%; padding: 10px; border: 1px solid var(--border-color);
            border-radius: var(--radius); font-size: 0.95rem; color: var(--text-color);
            transition: border-color 0.3s ease;
        }
        .form-group textarea { min-height: 150px; resize: vertical; }
        .form-group textarea:focus, .form-group input[type="file"]:focus {
            border-color: var(--primary); outline: none;
        }

        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 500;
            cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5;
        }
        .btn i { font-size: 1em; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 2px 5px rgba(67,97,238,0.3); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(220,53,69,0.3); }

        .alert {
            padding: 15px 20px; margin-bottom: 25px; border-radius: var(--radius);
            border: 1px solid transparent; display: flex; align-items: center;
            gap: 12px; font-weight: 500; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }

        .main-footer {
            background-color: var(--card-bg); padding: 15px 30px; margin-top: 30px;
            margin-left: 260px; border-top: 1px solid var(--border-color);
            text-align: center; font-size: 0.9rem; color: var(--text-muted);
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .vertical-navbar { width: 70px; }
            .vertical-navbar .nav-header h3, .vertical-navbar .nav-links span, .vertical-navbar .nav-footer { display: none; }
            .vertical-navbar .nav-links li a { justify-content: center; padding: 15px 10px; }
            .vertical-navbar .nav-links li a i { margin-right: 0; font-size: 1.3rem; }
            .horizontal-navbar { left: 70px; }
            .main-content { margin-left: 70px; }
            .main-footer { margin-left: 70px; }
        }
        @media (max-width: 768px) {
            .horizontal-navbar { padding: 0 15px; height: auto; flex-direction: column; align-items: flex-start; }
            .top-nav-left { padding: 10px 0; }
            .top-nav-right { padding-bottom: 10px; width: 100%; justify-content: flex-end; }
            .main-content { padding: 15px; padding-top: 120px; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .complaint-details p { font-size: 0.9rem; }
            .complaint-details p strong { min-width: 100px; }
        }
    </style>
</head>
<body>
    <!-- Vertical Navbar -->
    <div class="vertical-navbar">
        <div class="nav-header">
            <h3>DMU President</h3>
        </div>
        <ul class="nav-links">
            <li><a href="dashboard.php"><i class="fas fa-tachometer-alt fa-fw"></i> <span>Dashboard</span></a></li>
            <li><a href="view_complaints.php"><i class="fas fa-list-alt fa-fw"></i> <span>All Complaints</span></a></li>
            <li><a href="view_escalated.php"><i class="fas fa-level-up-alt fa-fw"></i> <span>Escalated</span></a></li>
            <li><a href="view_resolved.php"><i class="fas fa-check-circle fa-fw"></i> <span>Resolved</span></a></li>
            <li><a href="view_notifications.php"><i class="fas fa-bell fa-fw"></i> <span>Notifications</span></a></li>
            <li><a href="view_report.php"><i class="fas fa-file-alt fa-fw"></i> <span>View Report</span></a></li>
        </ul>
        <div class="nav-footer">
            <p>© <?php echo date("Y"); ?> DMU CMS</p>
        </div>
    </div>

    <!-- Horizontal Navbar -->
    <nav class="horizontal-navbar">
        <div class="top-nav-left">
            <span class="page-title">Send Decision</span>
        </div>
        <div class="top-nav-right">
            <div class="notification-icon" title="View Notifications">
                <a href="view_notifications.php" style="color: inherit; text-decoration: none;"><i class="fas fa-bell"></i></a>
            </div>
            <div class="user-dropdown">
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <h2>Send Decision for Complaint #<?php echo $complaint_id; ?></h2>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
        <?php endif; ?>

        <!-- Complaint Details -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-file-alt"></i> Complaint Details
            </div>
            <div class="card-body">
                <div class="complaint-details">
                    <p><strong>ID:</strong> <?php echo htmlspecialchars($complaint['id']); ?></p>
                    <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                    <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                    <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'])); ?></p>
                    <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                    <p><strong>Visibility:</strong> <?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></p>
                    <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></p>
                    <p><strong>Handler:</strong> <?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></p>
                    <p><strong>Submission Date:</strong> <?php echo date("M j, Y, g:i a", strtotime($complaint['submission_date'])); ?></p>
                </div>
            </div>
        </div>

        <!-- Decision Form -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-gavel"></i> Send Decision to Handler
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="decision_text">Decision Details <span style="color: var(--danger);">*</span></label>
                        <textarea id="decision_text" name="decision_text" required placeholder="Enter your decision for the handler..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="attachment">Attach Supporting Document (Optional)</label>
                        <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                            Allowed types: PDF, JPEG, PNG, DOC, DOCX. Max size: 5MB.
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Decision</button>
                    <a href="view_escalated.php" class="btn btn-danger" style="margin-left: 10px;"><i class="fas fa-times"></i> Cancel</a>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        © <?php echo date("Y"); ?> DMU Complaint Management System | President Panel
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000);
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