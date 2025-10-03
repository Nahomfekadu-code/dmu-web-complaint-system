<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'department_head'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'department_head') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$dept_head_id = $_SESSION['user_id'];
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);

if (!$complaint_id) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: department_head_dashboard.php");
    exit;
}

// Verify that the complaint is assigned to this Department Head
$sql = "
    SELECT c.id, c.title, c.status 
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? 
    AND e.escalated_to = 'department_head' 
    AND e.status = 'pending'
    AND e.department_id = (SELECT id FROM departments WHERE head_id = ? LIMIT 1)";
$stmt = $db->prepare($sql);
$stmt->bind_param("ii", $complaint_id, $dept_head_id);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$complaint) {
    $_SESSION['error'] = "Complaint not found or you are not authorized to update it.";
    header("Location: department_head_dashboard.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_status = trim($_POST['status'] ?? '');

    if (!in_array($new_status, ['pending', 'in_progress', 'resolved', 'rejected'])) {
        $_SESSION['error'] = "Invalid status selected.";
    } else {
        $db->begin_transaction();
        try {
            // Update complaint status
            $sql = "UPDATE complaints SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("si", $new_status, $complaint_id);
            $stmt->execute();
            $stmt->close();

            // If resolved, update escalation status
            if ($new_status == 'resolved') {
                $sql = "UPDATE escalations SET status = 'resolved', resolved_at = NOW() WHERE complaint_id = ? AND escalated_to = 'department_head'";
                $stmt = $db->prepare($sql);
                $stmt->bind_param("i", $complaint_id);
                $stmt->execute();
                $stmt->close();
            }

            // Insert notification for the complainant
            $sql = "SELECT user_id FROM complaints WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $complaint_id);
            $stmt->execute();
            $complainant_id = $stmt->get_result()->fetch_assoc()['user_id'];
            $stmt->close();

            $notification_message = "Your complaint #$complaint_id status has been updated to '$new_status' by the Department Head.";
            $sql = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("iis", $complainant_id, $complaint_id, $notification_message);
            $stmt->execute();
            $stmt->close();

            $db->commit();
            $_SESSION['success'] = "Complaint status updated successfully!";
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "Failed to update status: " . $e->getMessage();
            error_log("Error updating status: " . $e->getMessage());
        }
    }
    header("Location: department_head_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Complaint Status | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Reuse the same CSS as in department_head_dashboard.php */
        /* (Omitted for brevity; copy the CSS from department_head_dashboard.php) */
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
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($dept_head['role']); ?> (<?php echo htmlspecialchars($dept_head['department']); ?>)</p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="department_head_dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_escalated.php" class="nav-link">
                <i class="fas fa-arrow-up fa-fw"></i>
                <span>View Escalated Complaints</span>
            </a>
            <a href="send_decision.php" class="nav-link">
                <i class="fas fa-paper-plane fa-fw"></i>
                <span>Send Decision</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link">
                <i class="fas fa-bell fa-fw"></i>
                <span>View Notifications</span>
            </a>

            <h3>Account</h3>
            <a href="change_password.php" class="nav-link">
                <i class="fas fa-key fa-fw"></i>
                <span>Change Password</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php">
                    <i class="fas fa-home"></i> Home
                </a>
                <div class="notification-icon" title="View Notifications">
                    <a href="view_notifications.php" style="color: inherit; text-decoration: none;">
                        <i class="fas fa-bell"></i>
                    </a>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Page Specific Content -->
        <div class="content-container">
            <div class="page-header">
                <h2>Update Complaint Status</h2>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Update Status Form -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-edit"></i> Update Status for Complaint #<?php echo $complaint['id']; ?></span>
                </div>
                <div class="card-body">
                    <form action="update_status.php?complaint_id=<?php echo $complaint['id']; ?>" method="POST">
                        <div class="form-group">
                            <label for="status">New Status:</label>
                            <select name="status" id="status" required>
                                <option value="">-- Select Status --</option>
                                <option value="pending" <?php echo $complaint['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $complaint['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo $complaint['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="rejected" <?php echo $complaint['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Department Head Panel
        </footer>
    </div>
</body>
</html>
<?php
$db->close();
?>