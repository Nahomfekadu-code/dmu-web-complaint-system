<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is 'sims'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sims') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$sims_id = $_SESSION['user_id'];

// Check if both complaint_id and escalation_id are provided
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id']) || 
    !isset($_GET['escalation_id']) || !is_numeric($_GET['escalation_id'])) {
    $_SESSION['error'] = "Invalid complaint or escalation ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];
$escalation_id = (int)$_GET['escalation_id'];

// Fetch complaint details (only complaints assigned to this SIMS user)
$stmt = $db->prepare("
    SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, 
           u.fname, u.lname, e.escalated_by_id, e.original_handler_id
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    JOIN escalations e ON c.id = e.complaint_id
    WHERE c.id = ? AND e.id = ? AND e.escalated_to = 'sims' AND e.escalated_to_id = ? AND e.status = 'pending'
");
if (!$stmt) {
    error_log("Prepare failed: " . $db->error);
    $_SESSION['error'] = "Database error while fetching complaint.";
    header("Location: dashboard.php");
    exit;
}

$stmt->bind_param("iii", $complaint_id, $escalation_id, $sims_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found, not assigned to you, or already processed.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$handler_id = $complaint['original_handler_id'];
$stmt->close();

// Fetch campus registrar for escalation
$sql_registrar = "SELECT id FROM users WHERE role = 'campus_registrar' LIMIT 1";
$result_registrar = $db->query($sql_registrar);
if ($result_registrar && $result_registrar->num_rows > 0) {
    $campus_registrar = $result_registrar->fetch_assoc();
    $campus_registrar_id = $campus_registrar['id'];
} else {
    $campus_registrar_id = null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $decision_text = trim(filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_STRING));

    if (!$action || !$decision_text || !in_array($action, ['resolve', 'send_back', 'escalate'])) {
        $_SESSION['error'] = "Invalid action or decision text.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    // Validate handler_id for send_back or campus_registrar_id for escalate
    if ($action === 'send_back' && !$handler_id) {
        $_SESSION['error'] = "No handler available to send back the complaint.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
    if ($action === 'escalate' && !$campus_registrar_id) {
        $_SESSION['error'] = "No Campus Registrar available for escalation.";
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }

    $db->begin_transaction();
    try {
        if ($action === 'resolve') {
            // Update complaint status to resolved
            $update_complaint_sql = "UPDATE complaints SET status = 'resolved', resolution_details = ?, resolution_date = NOW() WHERE id = ?";
            $update_complaint_stmt = $db->prepare($update_complaint_sql);
            $update_complaint_stmt->bind_param("si", $decision_text, $complaint_id);
            $update_complaint_stmt->execute();
            $update_complaint_stmt->close();

            // Update escalation status to resolved
            $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
            $update_escalation_stmt = $db->prepare($update_escalation_sql);
            $update_escalation_stmt->bind_param("si", $decision_text, $escalation_id);
            $update_escalation_stmt->execute();
            $update_escalation_stmt->close();

            // Insert decision
            $decision_sql = "INSERT INTO decisions (escalation_id, complaint_id, sender_id, receiver_id, decision_text, status) VALUES (?, ?, ?, ?, ?, 'final')";
            $decision_stmt = $db->prepare($decision_sql);
            $decision_stmt->bind_param("iiiis", $escalation_id, $complaint_id, $sims_id, $handler_id, $decision_text);
            $decision_stmt->execute();
            $decision_stmt->close();

            // Notify the Handler
            if ($handler_id) {
                $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
                $notification_desc = "A final decision has been made on Complaint #$complaint_id by SIMS.";
                $notify_handler_stmt = $db->prepare($notify_handler_sql);
                $notify_handler_stmt->bind_param("iis", $handler_id, $complaint_id, $notification_desc);
                $notify_handler_stmt->execute();
                $notify_handler_stmt->close();
            }

            // Notify the complainant
            $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                               SELECT user_id, ?, 'Your complaint has been resolved: $decision_text' 
                               FROM complaints WHERE id = ?";
            $notify_user_stmt = $db->prepare($notify_user_sql);
            $notify_user_stmt->bind_param("ii", $complaint_id, $complaint_id);
            $notify_user_stmt->execute();
            $notify_user_stmt->close();

            $_SESSION['success'] = "Complaint #$complaint_id has been resolved successfully.";
        } elseif ($action === 'send_back') {
            // Insert decision
            $decision_sql = "INSERT INTO decisions (escalation_id, complaint_id, sender_id, receiver_id, decision_text, status) VALUES (?, ?, ?, ?, ?, 'pending')";
            $decision_stmt = $db->prepare($decision_sql);
            $decision_stmt->bind_param("iiiis", $escalation_id, $complaint_id, $sims_id, $handler_id, $decision_text);
            $decision_stmt->execute();
            $decision_stmt->close();

            // Update escalation status to resolved
            $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ? WHERE id = ?";
            $update_escalation_stmt = $db->prepare($update_escalation_sql);
            $update_escalation_stmt->bind_param("si", $decision_text, $escalation_id);
            $update_escalation_stmt->execute();
            $update_escalation_stmt->close();

            // Update complaint status to pending
            $update_complaint_sql = "UPDATE complaints SET status = 'pending' WHERE id = ?";
            $update_complaint_stmt = $db->prepare($update_complaint_sql);
            $update_complaint_stmt->bind_param("i", $complaint_id);
            $update_complaint_stmt->execute();
            $update_complaint_stmt->close();

            // Notify the Handler
            $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
            $notification_desc = "A decision has been sent back to you for Complaint #$complaint_id: $decision_text";
            $notify_handler_stmt = $db->prepare($notify_handler_sql);
            $notify_handler_stmt->bind_param("iis", $handler_id, $complaint_id, $notification_desc);
            $notify_handler_stmt->execute();
            $notify_handler_stmt->close();

            // Notify the complainant
            $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                               SELECT user_id, ?, 'Your complaint has been updated with a decision: $decision_text' 
                               FROM complaints WHERE id = ?";
            $notify_user_stmt = $db->prepare($notify_user_sql);
            $notify_user_stmt->bind_param("ii", $complaint_id, $complaint_id);
            $notify_user_stmt->execute();
            $notify_user_stmt->close();

            $_SESSION['success'] = "Decision for Complaint #$complaint_id has been sent back to the Handler.";
        } else {
            // Action: Escalate to Campus Registrar
            // Update complaint status to in_progress
            $update_complaint_sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
            $update_complaint_stmt = $db->prepare($update_complaint_sql);
            $update_complaint_stmt->bind_param("i", $complaint_id);
            $update_complaint_stmt->execute();
            $update_complaint_stmt->close();

            // Update escalation
            $update_escalation_sql = "UPDATE escalations 
                                     SET status = 'escalated', escalated_to = 'campus_registrar', escalated_to_id = ?, resolution_details = ?, updated_at = NOW() 
                                     WHERE id = ?";
            $update_escalation_stmt = $db->prepare($update_escalation_sql);
            $update_escalation_stmt->bind_param("isi", $campus_registrar_id, $decision_text, $escalation_id);
            $update_escalation_stmt->execute();
            $update_escalation_stmt->close();

            // Insert decision
            $decision_sql = "INSERT INTO decisions (escalation_id, complaint_id, sender_id, receiver_id, decision_text, status) VALUES (?, ?, ?, ?, ?, 'escalated')";
            $decision_stmt = $db->prepare($decision_sql);
            $decision_stmt->bind_param("iiiis", $escalation_id, $complaint_id, $sims_id, $campus_registrar_id, $decision_text);
            $decision_stmt->execute();
            $decision_stmt->close();

            // Notify the Campus Registrar
            $notify_registrar_sql = "INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)";
            $notification_desc = "Complaint #$complaint_id has been escalated to you by SIMS: $decision_text";
            $notify_registrar_stmt = $db->prepare($notify_registrar_sql);
            $notify_registrar_stmt->bind_param("iis", $campus_registrar_id, $complaint_id, $notification_desc);
            $notify_registrar_stmt->execute();
            $notify_registrar_stmt->close();

            // Notify the complainant
            $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                               SELECT user_id, ?, 'Your complaint has been escalated to the Campus Registrar: $decision_text' 
                               FROM complaints WHERE id = ?";
            $notify_user_stmt = $db->prepare($notify_user_sql);
            $notify_user_stmt->bind_param("ii", $complaint_id, $complaint_id);
            $notify_user_stmt->execute();
            $notify_user_stmt->close();

            $_SESSION['success'] = "Complaint #$complaint_id has been escalated to the Campus Registrar.";
        }

        $db->commit();
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "An error occurred: " . $e->getMessage();
        error_log("Decision error: " . $e->getMessage());
        header("Location: decide_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Decide on Complaint #<?php echo $complaint_id; ?> | DMU  Complaint System</title>
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

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary-dark);
        }

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
            resize: vertical;
        }

        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
        }

        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background-color: #fff;
            color: var(--dark);
            transition: border-color 0.3s ease;
        }

        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.2);
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
    </style>
</head>
<body>
    <div class="main-content">
        <div class="content-container">
            <h2>Decide on Complaint #<?php echo $complaint_id; ?></h2>

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
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?: 'Not categorized')); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?></p>
                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <!-- Decision Form -->
            <form method="POST">
                <div class="form-group">
                    <label for="decision_text">Decision/Resolution Details</label>
                    <textarea name="decision_text" id="decision_text" rows="5" required placeholder="Enter your decision or reason for action..."></textarea>
                </div>
                <div class="form-group">
                    <label for="action">Action</label>
                    <select name="action" id="action" required>
                        <option value="" disabled selected>Select an action</option>
                        <option value="resolve">Resolve Complaint</option>
                        <option value="send_back" <?php echo !$handler_id ? 'disabled' : ''; ?>>Send Back to Handler</option>
                        <option value="escalate" <?php echo !$campus_registrar_id ? 'disabled' : ''; ?>>Escalate to Campus Registrar</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Submit Decision</button>
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