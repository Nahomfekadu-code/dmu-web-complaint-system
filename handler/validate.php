<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

// Handle POST request (form submission for categorization or validation)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate complaint_id
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    if (!$complaint_id || $complaint_id <= 0) {
        $_SESSION['error'] = "Invalid request. No complaint ID provided.";
        header("Location: dashboard.php");
        exit;
    }

    $db->begin_transaction();

    try {
        // Fetch and lock the complaint row
        $sql_fetch_lock = "SELECT status, user_id, category FROM complaints WHERE id = ? FOR UPDATE";
        $stmt_lock = $db->prepare($sql_fetch_lock);
        if (!$stmt_lock) {
            throw new Exception("Database error preparing lock query: " . $db->error);
        }
        $stmt_lock->bind_param("i", $complaint_id);
        $stmt_lock->execute();
        $result_lock = $stmt_lock->get_result();
        $complaint = $result_lock->fetch_assoc();
        $stmt_lock->close();

        if (!$complaint) {
            $_SESSION['error'] = "Complaint not found.";
            $db->rollback();
            header("Location: dashboard.php");
            exit;
        }

        $complaint_user_id = $complaint['user_id'];

        // Handle Categorization
        if (isset($_POST['action']) && $_POST['action'] == 'categorize') {
            $category_type = $_POST['type'] ?? null;

            if (empty($category_type)) {
                $_SESSION['error'] = "Please select a category.";
                $db->rollback();
                header("Location: validate.php?complaint_id=" . $complaint_id);
                exit;
            }

            // Validate category type
            $allowed_categories = ['academic', 'administrative'];
            if (!in_array($category_type, $allowed_categories)) {
                $_SESSION['error'] = "Invalid category selected.";
                $db->rollback();
                header("Location: validate.php?complaint_id=" . $complaint_id);
                exit;
            }

            // Update the complaint category (allow overwrite)
            $sql_update = "UPDATE complaints SET category = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            if (!$stmt_update) {
                throw new Exception("Database error preparing update query: " . $db->error);
            }
            $stmt_update->bind_param("si", $category_type, $complaint_id);

            if (!$stmt_update->execute()) {
                throw new Exception("Error executing complaint update: " . $stmt_update->error);
            }

            $affected_rows = $stmt_update->affected_rows;
            $stmt_update->close();

            if ($affected_rows > 0 || $complaint['category'] == $category_type) {
                $message = empty($complaint['category']) ? 
                    "Complaint #" . $complaint_id . " categorized as '" . htmlspecialchars($category_type) . "'." :
                    "Complaint #" . $complaint_id . " re-categorized to '" . htmlspecialchars($category_type) . "'.";
                $_SESSION['success'] = $message;

                // Send notification to the user
                if ($complaint_user_id) {
                    $notification_desc = empty($complaint['category']) ?
                        "Your Complaint #" . $complaint_id . " has been categorized as '" . htmlspecialchars($category_type) . "'." :
                        "Your Complaint #" . $complaint_id . " has been re-categorized to '" . htmlspecialchars($category_type) . "'.";
                    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
                    $stmt_notify = $db->prepare($sql_notify);
                    if ($stmt_notify) {
                        $stmt_notify->bind_param("iis", $complaint_user_id, $complaint_id, $notification_desc);
                        if (!$stmt_notify->execute()) {
                            error_log("Categorize: Failed to send notification to user " . $complaint_user_id . " for complaint " . $complaint_id . ": " . $stmt_notify->error);
                        }
                        $stmt_notify->close();
                    } else {
                        error_log("Categorize: Failed to prepare notification statement: " . $db->error);
                    }
                }

                $db->commit();
            } else {
                $_SESSION['warning'] = "Complaint #" . $complaint_id . " could not be categorized.";
                $db->rollback();
            }

            header("Location: validate.php?complaint_id=" . $complaint_id);
            exit;
        }

        // Handle Validation
        if (isset($_POST['action']) && $_POST['action'] == 'validate') {
            // Check if the complaint is pending
            if ($complaint['status'] != 'pending') {
                $_SESSION['warning'] = "Complaint cannot be validated. It is already " . htmlspecialchars($complaint['status']) . ".";
                $db->rollback();
                header("Location: dashboard.php");
                exit;
            }

            // Check if the complaint has a category
            if (empty($complaint['category'])) {
                $_SESSION['error'] = "Complaint cannot be validated. It must be categorized first.";
                $db->rollback();
                header("Location: validate.php?complaint_id=" . $complaint_id);
                exit;
            }

            // Update the complaint status to 'validated'
            $sql_update = "UPDATE complaints SET status = 'validated', updated_at = NOW() WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            if (!$stmt_update) {
                throw new Exception("Database error preparing update query: " . $db->error);
            }
            $stmt_update->bind_param("i", $complaint_id);

            if (!$stmt_update->execute()) {
                throw new Exception("Error executing complaint update: " . $stmt_update->error);
            }

            $affected_rows = $stmt_update->affected_rows;
            $stmt_update->close();

            if ($affected_rows > 0) {
                $_SESSION['success'] = "Complaint #" . $complaint_id . " validated successfully.";

                // Send notification to the user
                if ($complaint_user_id) {
                    $notification_desc = "Your Complaint #" . $complaint_id . " has been validated.";
                    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
                    $stmt_notify = $db->prepare($sql_notify);
                    if ($stmt_notify) {
                        $stmt_notify->bind_param("iis", $complaint_user_id, $complaint_id, $notification_desc);
                        if (!$stmt_notify->execute()) {
                            error_log("Validate: Failed to send validation notification to user " . $complaint_user_id . " for complaint " . $complaint_id . ": " . $stmt_notify->error);
                        }
                        $stmt_notify->close();
                    } else {
                        error_log("Validate: Failed to prepare notification statement: " . $db->error);
                    }
                }

                $db->commit();
            } else {
                $_SESSION['warning'] = "Complaint #" . $complaint_id . " could not be validated.";
                $db->rollback();
            }

            header("Location: dashboard.php");
            exit;
        }

    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "An error occurred: " . $e->getMessage();
        error_log("Complaint Error (Complaint ID: " . $complaint_id . "): " . $e->getMessage());
        header("Location: validate.php?complaint_id=" . $complaint_id);
        exit;
    }
}

// Handle GET request (display the confirmation page)
$complaint_id = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT);
if (!$complaint_id || $complaint_id <= 0) {
    $_SESSION['error'] = "No valid complaint ID provided.";
    header("Location: dashboard.php");
    exit;
}

$sql_fetch = "SELECT c.id, c.user_id, c.title, c.description, c.category, c.status, c.visibility, c.evidence_file, c.created_at,
                     u.fname, u.lname
              FROM complaints c
              LEFT JOIN users u ON c.user_id = u.id
              WHERE c.id = ?";
$stmt_fetch = $db->prepare($sql_fetch);
if (!$stmt_fetch) {
    $_SESSION['error'] = "Database error preparing fetch query: " . $db->error;
    error_log("Validate Page Fetch Error (Prepare): " . $db->error);
    header("Location: dashboard.php");
    exit;
}
$stmt_fetch->bind_param("i", $complaint_id);
if (!$stmt_fetch->execute()) {
    $_SESSION['error'] = "Database error executing fetch query: " . $stmt_fetch->error;
    error_log("Validate Page Fetch Error (Execute): " . $stmt_fetch->error);
    $stmt_fetch->close();
    header("Location: dashboard.php");
    exit;
}
$result_fetch = $stmt_fetch->get_result();
$complaint = $result_fetch->fetch_assoc();
$stmt_fetch->close();

if (!$complaint) {
    $_SESSION['error'] = "Complaint #" . $complaint_id . " not found.";
    header("Location: dashboard.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validate Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #3f37c9;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
            --radius: 8px;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: #f4f7f6;
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
            display: flex;
        }

        .sidebar {
            width: 260px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 20px 0;
            height: 100vh;
            position: sticky;
            top: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 3px 0 15px rgba(0,0,0,0.1);
        }
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); text-align: center;}
        .sidebar-header img { height: 40px; border-radius: 50%; margin-bottom: 10px; }
        .sidebar-header h3 { font-size: 1.1rem; margin-bottom: 5px;}
        .sidebar-nav { flex-grow: 1; overflow-y: auto; padding: 10px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: white; text-decoration: none; border-radius: var(--radius); margin-bottom: 5px; transition: var(--transition); }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: rgba(255, 255, 255, 0.15); transform: translateX(3px); }
        .sidebar-nav a i { width: 20px; text-align: center; flex-shrink: 0; }
        .sidebar-footer { padding: 15px; border-top: 1px solid rgba(255,255,255,0.1); text-align: center; font-size: 0.8rem; }
        .sidebar-footer a { color: var(--accent); }

        .main-content-area {
            flex: 1;
            padding: 25px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            animation: fadeIn 0.5s ease-out;
            flex-grow: 1;
            margin-bottom: 20px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.6rem;
            text-align: center;
            border-bottom: 2px solid var(--accent);
            padding-bottom: 10px;
            font-weight: 600;
        }

        .complaint-details {
            margin-bottom: 30px;
            background-color: #fdfdff;
            padding: 20px 25px;
            border-radius: var(--radius);
            border: 1px solid var(--light-gray);
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.04);
        }

        .complaint-details p {
            margin: 10px 0;
            font-size: 0.98rem;
            color: #444;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .complaint-details p strong {
            color: var(--dark);
            min-width: 130px;
            flex-shrink: 0;
            font-weight: 600;
        }
        .complaint-details p span:not([class^='status-']) {
            flex-grow: 1;
        }
        .complaint-details p span[class^='status-'] {
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.9rem;
            display: inline-block;
            text-transform: capitalize;
        }
        .status-pending { background-color: rgba(255, 193, 7, 0.15); color: #b98900; }
        .status-validated { background-color: rgba(13, 202, 240, 0.15); color: #087990; }
        .status-in_progress { background-color: rgba(67, 97, 238, 0.15); color: var(--primary); }
        .status-resolved { background-color: rgba(40, 167, 69, 0.15); color: var(--success); }
        .status-rejected { background-color: rgba(220, 53, 69, 0.15); color: var(--danger); }

        .evidence-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .evidence-link a:hover {
            text-decoration: underline;
        }
        .evidence-link i { margin-right: 5px; }

        form#action-form {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px 20px;
            margin-top: 25px;
            padding: 20px;
            background-color: #f8f9fa;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
        }

        form#action-form label {
            font-weight: 500;
            margin-bottom: 0;
        }

        form#action-form select {
            padding: 10px 15px;
            border: 1px solid #ccc;
            border-radius: var(--radius);
            min-width: 220px;
            background-color: white;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
        form#action-form select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }

        button, .btn-link {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-align: center;
            line-height: 1.5;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }

        .btn-back {
            background: var(--light-gray);
            color: var(--dark);
            border: 1px solid #ccc;
            margin-left: 10px;
        }
        .btn-back:hover {
            background: #d3d9df;
            border-color: #bbb;
        }

        .message {
            padding: 12px 18px;
            margin-bottom: 20px;
            border-radius: var(--radius);
            text-align: center;
            font-weight: 500;
            font-size: 0.95rem;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message i { font-size: 1.1rem; }
        .message.error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .message.success { background-color: #d1e7dd; color: #0f5132; border-color: #badbcc; }
        .message.warning { background-color: #fff3cd; color: #664d03; border-color: #ffecb5; }
        .message.info { background-color: #cff4fc; color: #055160; border-color: #b6effb; }

        .page-footer {
            text-align: center;
            padding: 15px 0;
            margin-top: auto;
            font-size: 0.85rem;
            color: var(--gray);
            border-top: 1px solid var(--light-gray);
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
            .main-content-area { padding: 15px; }
            .container { padding: 20px; }
            h2 { font-size: 1.4rem; }
            .complaint-details p { flex-direction: column; align-items: flex-start; gap: 2px; }
            .complaint-details p strong { min-width: auto; margin-bottom: 3px; }
        }
        @media (max-width: 600px) {
            form#action-form { flex-direction: column; align-items: stretch; }
            form#action-form select, form#action-form button, .btn-link { width: 100%; margin-left: 0; }
            .btn-back { margin-top: 10px; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../images/logo.jpg" alt="Logo">
            <h3>Handler Panel</h3>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['fname'] ?? 'Handler'); ?></p>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i><span>Dashboard</span>
            </a>
            <a href="view_assigned_complaints.php" class="<?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                <i class="fas fa-tasks fa-fw"></i><span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="<?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-double fa-fw"></i><span>Resolved Complaints</span>
            </a>
            <a href="manage_notices.php" class="<?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-bullhorn fa-fw"></i><span>Manage Notices</span>
            </a>
            <a href="view_notifications.php" class="<?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i><span>Notifications</span>
            </a>
            <a href="view_feedback.php" class="<?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comments fa-fw"></i><span>View Feedback</span>
            </a>
            <a href="../logout.php">
                <i class="fas fa-sign-out-alt fa-fw"></i><span>Logout</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            © <?php echo date('Y'); ?> DMU CMS
        </div>
    </aside>

    <div class="main-content-area">
        <div class="container">
            <h2>Validate Complaint #<?php echo htmlspecialchars($complaint['id']); ?></h2>

            <?php if (isset($_SESSION['error'])): ?>
                <p class="message error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></p>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <p class="message success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <p class="message warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></p>
            <?php endif; ?>

            <?php if ($complaint): ?>
            <div class="complaint-details">
                <p><strong>Complaint ID:</strong> <span><?php echo $complaint['id']; ?></span></p>
                <p><strong>Title:</strong> <span><?php echo htmlspecialchars($complaint['title']); ?></span></p>
                <p><strong>Description:</strong> <span style="white-space: pre-wrap;"><?php echo htmlspecialchars($complaint['description']); ?></span></p>
                <p><strong>Submitted By:</strong> <span><?php echo htmlspecialchars(($complaint['fname'] ?? 'N/A') . ' ' . ($complaint['lname'] ?? '')); ?></span></p>
                <p><strong>Visibility:</strong> <span><?php echo ucfirst(htmlspecialchars($complaint['visibility'])); ?></span></p>
                <p><strong>Current Status:</strong> <span class="status-<?php echo str_replace(' ', '_', strtolower(htmlspecialchars($complaint['status']))); ?>"><?php echo ucfirst(htmlspecialchars($complaint['status'])); ?></span></p>

                <?php
                    $submitted_date_formatted = 'N/A';
                    if ($complaint['created_at']) {
                        try {
                            $submitted_date = new DateTime($complaint['created_at']);
                            $submitted_date_formatted = $submitted_date->format('F j, Y, g:i a');
                        } catch (Exception $e) {
                            $submitted_date_formatted = 'Invalid Date';
                        }
                    }
                ?>
                <p><strong>Submitted On:</strong> <span><?php echo $submitted_date_formatted; ?></span></p>

                <?php if (!empty($complaint['evidence_file'])): ?>
                    <p><strong>Evidence:</strong>
                        <span class="evidence-link">
                            <a href="../Uploads/<?php echo htmlspecialchars($complaint['evidence_file']); ?>" target="_blank" title="View Evidence File">
                                <i class="fas fa-paperclip"></i> View File (<?php echo htmlspecialchars($complaint['evidence_file']); ?>)
                            </a>
                        </span>
                    </p>
                <?php endif; ?>

                <?php if (!empty($complaint['category'])): ?>
                    <p><strong>Category:</strong> <span style="font-weight:bold; color: var(--primary);"><?php echo ucfirst(htmlspecialchars($complaint['category'])); ?></span></p>
                <?php endif; ?>
            </div>

            <?php if ($complaint['status'] == 'pending'): ?>
                <form id="action-form" method="post" action="validate.php">
                    <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                    <input type="hidden" name="action" value="categorize">
                    <label for="category_type"><strong>Assign Category:</strong></label>
                    <select name="type" id="category_type" required>
                        <option value="" disabled <?php echo empty($complaint['category']) ? 'selected' : ''; ?>>-- Select Category --</option>
                        <option value="academic" <?php echo $complaint['category'] == 'academic' ? 'selected' : ''; ?>>Academic</option>
                        <option value="administrative" <?php echo $complaint['category'] == 'administrative' ? 'selected' : ''; ?>>Administrative</option>
                    </select>
                    <button type="submit" class="btn-primary"><i class="fas fa-tag"></i> <?php echo empty($complaint['category']) ? 'Categorize' : 'Re-categorize'; ?></button>
                </form>

                <?php if (!empty($complaint['category'])): ?>
                    <form id="action-form" method="post" action="validate.php" style="margin-top: 20px;">
                        <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                        <input type="hidden" name="action" value="validate">
                        <button type="submit" class="btn-primary"><i class="fas fa-check-circle"></i> Validate Complaint</button>
                    </form>
                <?php endif; ?>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="dashboard.php" class="btn-link btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
            <?php else: ?>
                <p class="message info"><i class="fas fa-info-circle"></i> This complaint is already <strong><?php echo htmlspecialchars($complaint['status']); ?></strong>. No further action is needed here.</p>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="dashboard.php" class="btn-link btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
            <?php endif; ?>

            <?php else: ?>
                <p class="message error"><i class="fas fa-exclamation-triangle"></i> Complaint data could not be loaded.</p>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="dashboard.php" class="btn-link btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
            <?php endif; ?>

        </div>
        <footer class="page-footer">
            © <?php echo date('Y'); ?> DMU Complaint Management System
        </footer>
    </div>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli && $db->ping()) {
    $db->close();
}
?>