<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'department_head'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'department_head') {
    header("Location: ../unauthorized.php");
    exit;
}

$dept_head_id = $_SESSION['user_id'];
$dept_head = null; // Initialize department head variable

// Fetch Department Head details (for sidebar)
$sql_dept_head = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_dept_head = $db->prepare($sql_dept_head);
if ($stmt_dept_head) {
    $stmt_dept_head->bind_param("i", $dept_head_id);
    $stmt_dept_head->execute();
    $result_dept_head = $stmt_dept_head->get_result();
    if ($result_dept_head->num_rows > 0) {
        $dept_head = $result_dept_head->fetch_assoc();
    } else {
        $_SESSION['error'] = "Department Head details not found.";
        error_log("Department Head details not found for ID: " . $dept_head_id);
        header("Location: ../logout.php");
        exit;
    }
    $stmt_dept_head->close();
} else {
    error_log("Error preparing department head query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    // Allow script to continue but sidebar might lack info
}

// Check if both complaint_id and escalation_id are provided
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id']) ||
    !isset($_GET['escalation_id']) || !is_numeric($_GET['escalation_id'])) {
    $_SESSION['error'] = "Invalid complaint or escalation ID specified for escalation.";
    header("Location: dashboard.php"); // Redirect to dashboard if IDs are missing/invalid
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];
$escalation_id = (int)$_GET['escalation_id']; // This is the Department Head's escalation record ID

// Fetch complaint details and check if it's currently assigned to this Department Head and pending
// Also fetch complainant and original handler info
$stmt_complaint_details = $db->prepare("
    SELECT c.id, c.title, c.description, c.category, c.status as complaint_status, c.created_at, c.user_id as complainant_id,
           u_complainant.fname as complainant_fname, u_complainant.lname as complainant_lname,
           e.id as current_escalation_id, e.status as escalation_status, e.original_handler_id, e.escalated_by_id,
           u_escalator.fname as escalator_fname, u_escalator.lname as escalator_lname, u_escalator.role as escalator_role
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    JOIN users u_complainant ON c.user_id = u_complainant.id
    LEFT JOIN users u_escalator ON e.escalated_by_id = u_escalator.id -- Who escalated it TO the department head
    WHERE c.id = ?
      AND e.id = ?                   -- Match the specific escalation record for the Department Head
      AND e.escalated_to = 'department_head' -- Must be the Department Head's record
      AND e.escalated_to_id = ?      -- Must be *this* department head
      AND e.status = 'pending'         -- Must be pending action by the department head
");

if (!$stmt_complaint_details) {
    error_log("Prepare failed for complaint details (escalate page): " . $db->error);
    $_SESSION['error'] = "Database error while preparing complaint fetch.";
    header("Location: dashboard.php");
    exit;
}

$stmt_complaint_details->bind_param("iii", $complaint_id, $escalation_id, $dept_head_id);
$stmt_complaint_details->execute();
$result_complaint = $stmt_complaint_details->get_result();

if ($result_complaint->num_rows === 0) {
    $_SESSION['error'] = "Complaint #$complaint_id (Ref #$escalation_id) cannot be escalated: Not found, not assigned to you, or already processed.";
    $stmt_complaint_details->close();
    header("Location: dashboard.php");
    exit;
}

$complaint = $result_complaint->fetch_assoc();
// We need the original_handler_id for the new escalation record
$original_handler_id = $complaint['original_handler_id'];
$stmt_complaint_details->close();

// Fetch College Deans for the dropdown
$college_deans = [];
$sql_dean_fetch = "SELECT id, fname, lname FROM users WHERE role = 'college_dean' ORDER BY fname, lname";
$result_deans = $db->query($sql_dean_fetch);
if ($result_deans) {
    while ($user = $result_deans->fetch_assoc()) {
        $college_deans[] = $user;
    }
} else {
    error_log("Failed to fetch College Deans: " . $db->error);
    // Continue, but the dropdown will be empty or show an error message.
}

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '') {
    // Fetch complaint details
    $sql_complaint = "
        SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    if (!$stmt_complaint) { error_log("Prepare failed for report complaint fetch: " . $db->error); return; }
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) { error_log("Complaint #$complaint_id not found for report."); $stmt_complaint->close(); return; }
    $complaint_data = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    // Fetch sender details
    $sql_sender = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_sender = $db->prepare($sql_sender);
    if (!$stmt_sender) { error_log("Prepare failed for report sender fetch: " . $db->error); return; }
    $stmt_sender->bind_param("i", $sender_id);
    $stmt_sender->execute();
    $sender_result = $stmt_sender->get_result();
    if ($sender_result->num_rows === 0) { error_log("Sender #$sender_id not found for report."); $stmt_sender->close(); return; }
    $sender = $sender_result->fetch_assoc();
    $stmt_sender->close();

    // Fetch the President
    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) { error_log("No user with role 'president' found for report."); return; }
    $president = $result_president->fetch_assoc();
    $recipient_id = $president['id'];
    $result_president->free(); // Free the result set

    // Generate stereotyped report content
    $report_content = "Complaint Report\n" .
                      "----------------\n" .
                      "Report Type: " . ucfirst($report_type) . "\n" .
                      "Complaint ID: {$complaint_data['id']}\n" .
                      "Title: {$complaint_data['title']}\n" .
                      "Description: {$complaint_data['description']}\n" .
                      "Category: " . ($complaint_data['category'] ? ucfirst($complaint_data['category']) : 'N/A') . "\n" .
                      "Status: " . ucfirst($complaint_data['status']) . "\n" .
                      "Submitted By: {$complaint_data['submitter_fname']} {$complaint_data['submitter_lname']}\n" .
                      "Processed By: {$sender['fname']} {$sender['lname']} (Department Head)\n" .
                      "Created At: " . date('M j, Y H:i', strtotime($complaint_data['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info/Reason: $additional_info\n";
    }

    // Insert the report into the stereotyped_reports table
    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt_report = $db->prepare($sql_report);
    if (!$stmt_report) { error_log("Prepare failed for report insertion: " . $db->error); return; }
    $stmt_report->bind_param("iiiss", $complaint_id, $sender_id, $recipient_id, $report_type, $report_content);
    if (!$stmt_report->execute()) {
        error_log("Execute failed for report insertion: " . $stmt_report->error);
        return;
    }
    $stmt_report->close();

    // Notify the President
    $notification_desc = "A new " . ucfirst($report_type) . " report for Complaint #{$complaint_data['id']} has been submitted by Department Head {$sender['fname']} {$sender['lname']}.";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
    $stmt_notify = $db->prepare($sql_notify);
    if ($stmt_notify) {
        $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
        if (!$stmt_notify->execute()) {
            error_log("Execute failed for president notification: " . $stmt_notify->error);
            return;
        }
        $stmt_notify->close();
    } else {
        error_log("Prepare failed for president notification: " . $db->error);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $escalated_to_id = filter_input(INPUT_POST, 'escalated_to_id', FILTER_VALIDATE_INT);
    // Use htmlspecialchars on input for the reason, trim whitespace
    $reason_text = trim(htmlspecialchars($_POST['reason_text'] ?? '', ENT_QUOTES, 'UTF-8'));

    $errors = []; // Use error array

    if (!$escalated_to_id) {
        $errors[] = "Please select a College Dean.";
    }
    if (empty($reason_text)) {
        $errors[] = "Please provide a reason for escalation.";
    }

    // Verify the selected user is indeed a College Dean (security check)
    $is_valid_dean = false;
    if ($escalated_to_id) {
        $stmt_dean_check = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'college_dean'");
        if ($stmt_dean_check) {
            $stmt_dean_check->bind_param("i", $escalated_to_id);
            $stmt_dean_check->execute();
            $result_dean_check = $stmt_dean_check->get_result();
            if ($result_dean_check->num_rows > 0) {
                $is_valid_dean = true;
            }
            $stmt_dean_check->close();
        } else {
            $errors[] = "Database error verifying College Dean selection.";
            error_log("Prepare failed for College Dean check: " . $db->error);
        }
    }
    if ($escalated_to_id && !$is_valid_dean) {
        $errors[] = "The selected user is not a valid College Dean.";
    }

    if (empty($errors)) {
        $escalated_to_role = 'college_dean'; // Explicitly set the role for the new escalation record

        $db->begin_transaction();
        try {
            // 1. Insert new escalation record for the College Dean
            $escalation_sql = "INSERT INTO escalations (complaint_id, escalated_to, escalated_to_id, escalated_by_id, status, original_handler_id, action_type, created_at)
                               VALUES (?, ?, ?, ?, 'pending', ?, 'escalation', NOW())";
            $stmt1 = $db->prepare($escalation_sql);
            if (!$stmt1) throw new Exception("DB Error (Esc1): " . $db->error);
            $stmt1->bind_param("isiiis", $complaint_id, $escalated_to_role, $escalated_to_id, $dept_head_id, $original_handler_id);
            if (!$stmt1->execute()) throw new Exception("DB Error (Esc1a): " . $stmt1->error);
            $stmt1->close();

            // 2. Update *this* Department Head's escalation record status to resolved
            $update_escalation_sql = "UPDATE escalations SET status = 'resolved', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
            $stmt2 = $db->prepare($update_escalation_sql);
            if (!$stmt2) throw new Exception("DB Error (Esc2): " . $db->error);
            $res_detail = "Escalated to College Dean by Department Head. Reason: " . $reason_text; // Use the reason here
            $stmt2->bind_param("si", $res_detail, $escalation_id); // Use $escalation_id passed via GET
            if (!$stmt2->execute()) throw new Exception("DB Error (Esc2a): " . $stmt2->error);
            $stmt2->close();

            // 3. Update complaint status to 'in_progress'
            $update_complaint_sql = "UPDATE complaints SET status = 'in_progress' WHERE id = ?";
            $stmt3 = $db->prepare($update_complaint_sql);
            if (!$stmt3) throw new Exception("DB Error (Esc3): " . $db->error);
            $stmt3->bind_param("i", $complaint_id);
            if (!$stmt3->execute()) throw new Exception("DB Error (Esc3a): " . $stmt3->error);
            $stmt3->close();

            // 4. Notify the College Dean
            $notify_escalated_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $dean_notification_desc = "Complaint #$complaint_id has been escalated to you by the Department Head for review. Reason: $reason_text";
            $stmt4 = $db->prepare($notify_escalated_sql);
            if (!$stmt4) throw new Exception("DB Error (Esc4): " . $db->error);
            $stmt4->bind_param("iis", $escalated_to_id, $complaint_id, $dean_notification_desc);
            if (!$stmt4->execute()) throw new Exception("DB Error (Esc4a): " . $stmt4->error);
            $stmt4->close();

            // 5. Notify the original complainant
            $user_notification_desc = "Your Complaint (#$complaint_id: {$complaint['title']}) has been escalated to the College Dean for further review.";
            $stmt5 = $db->prepare($notify_escalated_sql); // Reuse query
            if (!$stmt5) throw new Exception("DB Error (Esc5): " . $db->error);
            $stmt5->bind_param("iis", $complaint['complainant_id'], $complaint_id, $user_notification_desc);
            if (!$stmt5->execute()) throw new Exception("DB Error (Esc5a): " . $stmt5->error);
            $stmt5->close();

            // 6. Notify the person who escalated it TO the department head (if different from department head and exists)
            $escalator_id = $complaint['escalated_by_id'];
            if ($escalator_id && $escalator_id != $dept_head_id) {
                $handler_notification_desc = "Complaint #$complaint_id, which you escalated, has been further escalated by the Department Head to the College Dean. Reason: $reason_text";
                $stmt_notify_handler = $db->prepare($notify_escalated_sql); // Reuse query structure
                if (!$stmt_notify_handler) throw new Exception("DB Error (Notify Handler Esc): " . $db->error);
                $stmt_notify_handler->bind_param("iis", $escalator_id, $complaint_id, $handler_notification_desc);
                if (!$stmt_notify_handler->execute()) throw new Exception("DB Error (Notify Handler Esc Exec): " . $stmt_notify_handler->error);
                $stmt_notify_handler->close();
            }

            // 7. Send stereotyped report to the President
            $additional_info_report = "Escalated by Department Head to College Dean. Reason: $reason_text";
            sendStereotypedReport($db, $complaint_id, $dept_head_id, 'escalated', $additional_info_report);

            $db->commit();
            $_SESSION['success'] = "Complaint #$complaint_id has been successfully escalated to the selected College Dean.";
            header("Location: dashboard.php"); // Redirect to dashboard after successful escalation
            exit;
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "An error occurred during escalation: " . $e->getMessage();
            error_log("Escalation processing error for Complaint #$complaint_id / Escalation #$escalation_id: " . $e->getMessage());
            // Redirect back to the escalation page to show the error
            header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
            exit;
        }
    } else {
        // If there were validation errors, store them in session and redirect back
        $_SESSION['form_errors'] = $errors;
        // Store submitted data to repopulate form
        $_SESSION['form_data'] = $_POST;
        header("Location: escalate_complaint.php?complaint_id=$complaint_id&escalation_id=$escalation_id");
        exit;
    }
}

// Retrieve errors and form data from session if they exist (after redirect)
$form_errors = $_SESSION['form_errors'] ?? [];
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

// Use submitted data if available after error, otherwise use empty values
$display_reason_text = $form_data['reason_text'] ?? '';
$display_dean_id = $form_data['escalated_to_id'] ?? '';

// Fetch notification count (for sidebar)
$notification_count = 0; // Default value
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $dept_head_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']); // Will highlight the 'Escalate Complaint' link if added
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escalate Complaint #<?php echo $complaint_id; ?> | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Using the consistent CSS -->
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
            --orange: #fd7e14;
            --purple: #7209b7;
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
            overflow-y: auto;
            flex-shrink: 0;
        }

        .nav-header { padding: 0 20px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); margin-bottom: 20px; }
        .nav-header .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .nav-header img { height: 40px; border-radius: 50%; }
        .nav-header .logo-text { font-size: 1.3rem; font-weight: 700; }
        .user-profile-mini { display: flex; align-items: center; gap: 10px; }
        .user-profile-mini i { font-size: 2.5rem; color: white; }
        .user-info h4 { font-size: 0.9rem; margin-bottom: 2px; }
        .user-info p { font-size: 0.8rem; opacity: 0.8; }
        .nav-menu { padding: 0 10px; }
        .nav-menu h3 { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; margin: 20px 10px 10px; opacity: 0.7; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: white; text-decoration: none; border-radius: var(--radius); margin-bottom: 5px; transition: var(--transition); }
        .nav-link:hover, .nav-link.active { background: rgba(255, 255, 255, 0.15); transform: translateX(5px); }
        .nav-link i { width: 20px; text-align: center; }
        .nav-link .badge { margin-left: auto; font-size: 0.8rem; padding: 2px 6px; background-color: var(--danger); }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-y: auto; /* Allow content to scroll if needed */
        }

        /* Horizontal Navigation */
        .horizontal-nav { background: white; border-radius: var(--radius); box-shadow: var(--shadow); padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;}
        .horizontal-nav .logo span { font-size: 1.2rem; font-weight: 600; color: var(--primary-dark); }
        .horizontal-menu { display: flex; gap: 10px; }
        .horizontal-menu a { color: var(--dark); text-decoration: none; padding: 8px 15px; border-radius: var(--radius); transition: var(--transition); font-weight: 500; }
        .horizontal-menu a:hover, .horizontal-menu a.active { background: var(--primary); color: white; }

        /* Alerts */
        .alert { padding: 15px 20px; margin-bottom: 25px; border-radius: var(--radius); border: 1px solid transparent; display: flex; align-items: flex-start; gap: 12px; font-weight: 500; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .alert i { font-size: 1.2rem; margin-top: 2px; flex-shrink: 0; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }
        .alert ul { margin: 0; padding-left: 20px; }

        /* Content Container */
        .content-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1; /* Take remaining space */
        }

        h2 { color: var(--primary-dark); font-size: 1.8rem; margin-bottom: 1.5rem; position: relative; padding-bottom: 0.5rem; text-align: center; }
        h2::after { content: ''; position: absolute; bottom: 0; left: 50%; transform: translateX(-50%); width: 80px; height: 4px; background: linear-gradient(90deg, var(--primary), var(--accent)); border-radius: 2px; }
        h3 { color: var(--primary); font-size: 1.3rem; margin-bottom: 1rem; margin-top: 1rem; }

        /* Complaint Details Box */
        .complaint-details {
            background: var(--light);
            border: 1px solid var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.04);
        }
        .complaint-details h3 { /* Style specific to details box */
            margin-top: 0;
            margin-bottom: 1rem;
            color: var(--primary-dark);
            border-bottom: 2px solid var(--primary-light);
            padding-bottom: 0.5rem;
        }
        .complaint-details p {
            margin: 0.6rem 0;
            line-height: 1.7;
        }
        .complaint-details strong {
            font-weight: 600;
            color: var(--dark);
            margin-right: 5px;
        }
        .badge {
            padding: 0.3rem 0.6rem; border-radius: var(--radius); font-size: 0.85rem; font-weight: 500; display: inline-block;
            color: white; /* Default text color */
        }
        .badge.pending { background-color: var(--warning); color: var(--dark); }
        .badge.in_progress { background-color: var(--info); }
        .badge.resolved { background-color: var(--success); }
        .badge.closed { background-color: var(--gray); }
        .badge.escalated { background-color: var(--orange); } /* Add specific status colors */

        /* Form Styles */
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.6rem; color: var(--primary-dark); }
        .form-group textarea, .form-group select { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--light-gray); border-radius: var(--radius); font-size: 0.95rem; background-color: #fff; color: var(--dark); transition: border-color 0.3s ease, box-shadow 0.3s ease; }
        .form-group textarea { resize: vertical; min-height: 120px;}
        .form-group textarea:focus, .form-group select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15); }
        .form-group select option[disabled] { color: #999; font-style: italic; }
        .form-actions { margin-top: 2rem; display: flex; gap: 1rem; justify-content: flex-start; }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 600; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; text-align: center; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-secondary { background: var(--gray); color: white; }
        .btn-secondary:hover { background: var(--dark); transform: translateY(-1px); }
        .btn-danger { background: var(--danger); color: white; } /* Specific button color */
        .btn-danger:hover { background: #bd2130; transform: translateY(-1px);}

        /* Footer */
        footer { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); color: white; padding: 1.5rem 0; text-align: center; margin-top: auto; border-radius: var(--radius-lg) var(--radius-lg) 0 0; width: 100%; flex-shrink: 0;}
        .footer-content { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .group-name { font-weight: 600; font-size: 1.1rem; margin-bottom: 10px; }
        .social-links { display: flex; justify-content: center; gap: 20px; margin: 15px 0; }
        .social-links a { color: white; font-size: 1.5rem; transition: var(--transition); }
        .social-links a:hover { transform: translateY(-3px); color: var(--accent); }
        .copyright { font-size: 0.9rem; opacity: 0.8; }
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
            <?php if ($dept_head): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dept_head['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>Department Head</h4>
                    <p>Role: Department Head</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
            <!-- Navigation Links tailored for Department Head -->
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span>Resolved Complaints</span>
            </a>

            <h3>Communication</h3>
            <a href="notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="badge badge-danger"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a>

            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Department Head</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Content Container -->
        <div class="content-container">
            <h2>Escalate Complaint #<?php echo $complaint_id; ?> to College Dean</h2>

            <!-- Display Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['info'])): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
            <?php endif; ?>

            <!-- Display Validation Errors from Redirect -->
            <?php if (!empty($form_errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        Please correct the following errors:
                        <ul>
                            <?php foreach ($form_errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Complaint Details Display -->
            <div class="complaint-details">
                <h3>Complaint Summary</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['complainant_fname'] . ' ' . $complaint['complainant_lname']); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'] ?? 'N/A')); ?></p>
                <p><strong>Escalated By:</strong> <?php echo htmlspecialchars($complaint['escalator_fname'] . ' ' . $complaint['escalator_lname'] . ' (' . ucfirst(str_replace('_', ' ', $complaint['escalator_role'])) . ')'); ?></p>
                <p><strong>Current Status:</strong> <span class="badge <?php echo htmlspecialchars($complaint['complaint_status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['complaint_status']))); ?></span></p>
                <p><strong>Description Snippet:</strong></p>
                <p style="white-space: pre-wrap; background: #fff; padding: 10px; border-radius: 5px; max-height: 150px; overflow-y: auto;"><?php echo htmlspecialchars($complaint['description']); ?></p>
                <p><a href="view_complaint_details.php?complaint_id=<?php echo $complaint_id; ?>" target="_blank">View Full Details</a> (Opens in new tab)</p> <!-- Optional: Link to full details -->
            </div>

            <!-- Escalation Form -->
            <h3>Escalate to College Dean</h3>
            <form method="POST" action="escalate_complaint.php?complaint_id=<?php echo $complaint_id; ?>&escalation_id=<?php echo $escalation_id; ?>" novalidate>
                <div class="form-group">
                    <label for="escalated_to_id">Select College Dean *</label>
                    <select name="escalated_to_id" id="escalated_to_id" required>
                        <option value="" disabled <?php echo empty($display_dean_id) ? 'selected' : ''; ?>>-- Select a College Dean --</option>
                        <?php if (empty($college_deans)): ?>
                            <option value="" disabled>No College Deans found in the system.</option>
                        <?php else: ?>
                            <?php foreach ($college_deans as $dean): ?>
                                <option value="<?php echo $dean['id']; ?>" <?php echo ($display_dean_id == $dean['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars("{$dean['fname']} {$dean['lname']}"); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($college_deans)): ?>
                        <p style="color: var(--danger); font-size: 0.9em; margin-top: 5px;">Cannot escalate - No College Deans configured.</p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="reason_text">Reason for Escalation *</label>
                    <textarea name="reason_text" id="reason_text" rows="5" required placeholder="Clearly state why this complaint needs escalation to the College Dean..."><?php echo htmlspecialchars($display_reason_text); ?></textarea>
                </div>

                <div class="form-actions">
                    <!-- Disable button if no College Deans -->
                    <button type="submit" class="btn btn-danger" <?php echo empty($college_deans) ? 'disabled' : ''; ?>><i class="fas fa-arrow-up"></i> Confirm Escalation</button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

        <!-- Footer -->
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

    <!-- JavaScript for Alerts -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 7000); // 7 seconds
            });
        });
    </script>
</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>