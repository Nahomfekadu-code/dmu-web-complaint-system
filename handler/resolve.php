<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

// Check if complaint ID is provided
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details
$stmt = $db->prepare("SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname 
                      FROM complaints c 
                      JOIN users u ON c.user_id = u.id 
                      WHERE c.id = ? AND c.handler_id = ?");
$stmt->bind_param("ii", $complaint_id, $handler_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found or you are not authorized to resolve it.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$stmt->close();

// Check if the complaint can be resolved
if ($complaint['status'] === 'resolved' || $complaint['status'] === 'rejected') {
    $_SESSION['error'] = "This complaint has already been resolved or rejected.";
    header("Location: dashboard.php");
    exit;
}

// Check if there’s a decision from a higher authority
$decision = null;
$decision_sql = "SELECT d.*, u.fname as sender_fname, u.lname as sender_lname, u.role as sender_role 
                 FROM decisions d 
                 JOIN users u ON d.sender_id = u.id 
                 WHERE d.complaint_id = ? AND d.receiver_id = ? AND d.status = 'final' 
                 ORDER BY d.created_at DESC LIMIT 1";
$decision_stmt = $db->prepare($decision_sql);
$decision_stmt->bind_param("ii", $complaint_id, $handler_id);
$decision_stmt->execute();
$decision_result = $decision_stmt->get_result();
if ($decision_result->num_rows > 0) {
    $decision = $decision_result->fetch_assoc();
}
$decision_stmt->close();

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $handler_id, $report_type, $additional_info = '') {
    // Fetch complaint details
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint = $stmt_complaint->get_result()->fetch_assoc();
    $stmt_complaint->close();

    // Fetch handler details
    $sql_handler = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_handler = $db->prepare($sql_handler);
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $handler = $stmt_handler->get_result()->fetch_assoc();
    $stmt_handler->close();

    // Fetch the President
    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if ($result_president->num_rows === 0) {
        error_log("No user with role 'president' found.");
        return;
    }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];

    // Generate stereotyped report content
    $report_content = "Complaint Report\n";
    $report_content .= "----------------\n";
    $report_content .= "Report Type: " . ucfirst($report_type) . "\n";
    $report_content .= "Complaint ID: {$complaint['id']}\n";
    $report_content .= "Title: {$complaint['title']}\n";
    $report_content .= "Description: {$complaint['description']}\n";
    $report_content .= "Category: " . ($complaint['category'] ? ucfirst($complaint['category']) : 'Not categorized') . "\n";
    $report_content .= "Status: " . ucfirst($complaint['status']) . "\n";
    $report_content .= "Submitted By: {$complaint['submitter_fname']} {$complaint['submitter_lname']}\n";
    $report_content .= "Handler: {$handler['fname']} {$handler['lname']}\n";
    $report_content .= "Created At: " . date('M j, Y H:i', strtotime($complaint['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info: $additional_info\n";
    }

    // Insert the report into the stereotyped_reports table
    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content) VALUES (?, ?, ?, ?, ?)";
    $stmt_report = $db->prepare($sql_report);
    $stmt_report->bind_param("iiiss", $complaint_id, $handler_id, $recipient_id, $report_type, $report_content);
    $stmt_report->execute();
    $stmt_report->close();

    // Notify the President
    $notification_desc = "A new $report_type report for Complaint #{$complaint['id']} has been submitted by {$handler['fname']} {$handler['lname']}.";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
    $stmt_notify = $db->prepare($sql_notify);
    $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
    $stmt_notify->execute();
    $stmt_notify->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $resolution_details = filter_input(INPUT_POST, 'resolution_details', FILTER_SANITIZE_STRING);

    if (empty($resolution_details)) {
        $_SESSION['error'] = "Please provide resolution details.";
        header("Location: resolve.php?complaint_id=$complaint_id");
        exit;
    }

    $db->begin_transaction();
    try {
        // Update the complaint status to resolved
        $update_sql = "UPDATE complaints SET status = 'resolved', resolution_details = ?, resolution_date = NOW() WHERE id = ?";
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("si", $resolution_details, $complaint_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Update or insert resolution details in the escalations table
        $escalation_sql = "INSERT INTO escalations (complaint_id, escalated_by, escalated_to, status, resolution_details, original_handler_id) 
                           VALUES (?, ?, 'handler', 'resolved', ?, ?)
                           ON DUPLICATE KEY UPDATE status = 'resolved', resolution_details = ?, resolved_at = NOW()";
        $escalation_stmt = $db->prepare($escalation_sql);
        $escalation_stmt->bind_param("iissi", $complaint_id, $handler_id, $resolution_details, $handler_id, $resolution_details);
        $escalation_stmt->execute();
        $escalation_stmt->close();

        // Notify the user
        $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                           SELECT user_id, ?, ? 
                           FROM complaints WHERE id = ?";
        $notification_desc = "Your complaint has been resolved: $resolution_details";
        $notify_user_stmt = $db->prepare($notify_user_sql);
        $notify_user_stmt->bind_param("isi", $complaint_id, $notification_desc, $complaint_id);
        $notify_user_stmt->execute();
        $notify_user_stmt->close();

        // Determine the report type and additional info
        $report_type = $decision ? 'decision_received' : 'resolved';
        $additional_info = $decision ? "Decision received from {$decision['sender_fname']} {$decision['sender_lname']} ({$decision['sender_role']}): {$decision['decision_text']}" : '';

        // Send stereotyped report to the President
        sendStereotypedReport($db, $complaint_id, $handler_id, $report_type, $additional_info);

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been resolved successfully.";
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "An error occurred while resolving the complaint: " . $e->getMessage();
        error_log("Resolve error: " . $e->getMessage());
        header("Location: resolve.php?complaint_id=$complaint_id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolve Complaint | DMU Complaint System</title>
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

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
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
        }

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

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            resize: vertical;
            font-size: 0.95rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
        }

        .complaint-details {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }

        .complaint-details p {
            margin: 0.5rem 0;
        }

        .decision-details {
            background: #e9ecef;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }

        .decision-details p {
            margin: 0.5rem 0;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="content-container">
            <h2>Resolve Complaint #<?php echo $complaint_id; ?></h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details -->
            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'])); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></p>
                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <!-- Decision Details (if any) -->
            <?php if ($decision): ?>
                <div class="decision-details">
                    <h3>Decision from Higher Authority</h3>
                    <p><strong>Sender:</strong> <?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']); ?> (<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['sender_role']))); ?>)</p>
                    <p><strong>Decision:</strong> <?php echo nl2br(htmlspecialchars($decision['decision_text'])); ?></p>
                    <p><strong>Received At:</strong> <?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></p>
                </div>
            <?php endif; ?>

            <!-- Resolution Form -->
            <form method="POST">
                <div class="form-group">
                    <label for="resolution_details">Resolution Details</label>
                    <textarea name="resolution_details" id="resolution_details" rows="5" required placeholder="Provide the resolution details for this complaint."><?php echo $decision ? htmlspecialchars($decision['decision_text']) : ''; ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Resolve Complaint</button>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
            </form>
        </div>
    </div>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>