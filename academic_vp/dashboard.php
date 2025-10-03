<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is an 'academic_vp'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'academic_vp') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$vp_id = $_SESSION['user_id'];

// Fetch Academic VP details
$sql_vp = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_vp = $db->prepare($sql_vp);
if (!$stmt_vp) {
    error_log("Prepare failed for user fetch: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
    header("Location: ../logout.php");
    exit;
}
$stmt_vp->bind_param("i", $vp_id);
$stmt_vp->execute();
$vp_result = $stmt_vp->get_result();
$vp = $vp_result->fetch_assoc();
$stmt_vp->close();

if (!$vp) {
    $_SESSION['error'] = "Academic Vice President details not found.";
    header("Location: ../logout.php");
    exit;
}

// Fetch the President (for escalation)
$president_query = "SELECT id, fname, lname FROM users WHERE role = 'president' LIMIT 1";
$president_result = $db->query($president_query);
$president_id = null;
if ($president_result && $president_result->num_rows > 0) {
    $president = $president_result->fetch_assoc();
    $president_id = $president['id'];
} else {
    error_log("No President found in the system. Escalation feature will be limited.");
}

// Function to send a stereotyped report to the President
function sendStereotypedReport($db, $complaint_id, $sender_id, $report_type, $additional_info = '') {
    $sql_complaint = "SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname FROM complaints c JOIN users u ON c.user_id = u.id WHERE c.id = ?";
    $stmt_complaint = $db->prepare($sql_complaint);
    if (!$stmt_complaint) {
        error_log("Prepare failed for complaint fetch: " . $db->error);
        $_SESSION['error'] = "Failed to fetch complaint details for report generation.";
        return false;
    }
    $stmt_complaint->bind_param("i", $complaint_id);
    $stmt_complaint->execute();
    $complaint_result = $stmt_complaint->get_result();
    if ($complaint_result->num_rows === 0) {
        error_log("Complaint #$complaint_id not found for report generation.");
        $_SESSION['error'] = "Complaint not found for report generation.";
        $stmt_complaint->close();
        return false;
    }
    $complaint = $complaint_result->fetch_assoc();
    $stmt_complaint->close();

    $sql_sender = "SELECT fname, lname FROM users WHERE id = ?";
    $stmt_sender = $db->prepare($sql_sender);
    if (!$stmt_sender) {
        error_log("Prepare failed for sender fetch: " . $db->error);
        $_SESSION['error'] = "Failed to fetch sender details for report generation.";
        return false;
    }
    $stmt_sender->bind_param("i", $sender_id);
    $stmt_sender->execute();
    $sender_result = $stmt_sender->get_result();
    if ($sender_result->num_rows === 0) {
        error_log("Sender #$sender_id not found for report generation.");
        $_SESSION['error'] = "Sender not found for report generation.";
        $stmt_sender->close();
        return false;
    }
    $sender = $sender_result->fetch_assoc();
    $stmt_sender->close();

    $sql_president = "SELECT id FROM users WHERE role = 'president' LIMIT 1";
    $result_president = $db->query($sql_president);
    if (!$result_president || $result_president->num_rows === 0) {
        error_log("No user with role 'president' found when trying to send report.");
        $_SESSION['error'] = "No President found to receive the report.";
        return false;
    }
    $president_data = $result_president->fetch_assoc();
    $recipient_id = $president_data['id'];

    $report_content = "Complaint Report\n";
    $report_content .= "----------------\n";
    $report_content .= "Report Type: " . ucfirst($report_type) . "\n";
    $report_content .= "Complaint ID: {$complaint['id']}\n";
    $report_content .= "Title: {$complaint['title']}\n";
    $report_content .= "Description: {$complaint['description']}\n";
    $report_content .= "Category: " . ($complaint['category'] ? ucfirst($complaint['category']) : 'Not categorized') . "\n";
    $report_content .= "Status: " . ucfirst($complaint['status']) . "\n";
    $report_content .= "Submitted By: {$complaint['submitter_fname']} {$complaint['submitter_lname']}\n";
    $report_content .= "Processed By: {$sender['fname']} {$sender['lname']}\n";
    $report_content .= "Created At: " . date('M j, Y H:i', strtotime($complaint['created_at'])) . "\n";
    if ($additional_info) {
        $report_content .= "Additional Info: $additional_info\n";
    }

    $sql_report = "INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt_report = $db->prepare($sql_report);
    if (!$stmt_report) {
        error_log("Prepare failed for report insertion: " . $db->error);
        $_SESSION['error'] = "Failed to generate the report for the President.";
        return false;
    }
    $stmt_report->bind_param("iiiss", $complaint_id, $sender_id, $recipient_id, $report_type, $report_content);
    if (!$stmt_report->execute()) {
        error_log("Report insertion failed: " . $stmt_report->error);
        $_SESSION['error'] = "Failed to store the report for the President.";
        $stmt_report->close();
        return false;
    }
    $stmt_report->close();

    $notification_desc = "A new $report_type report for Complaint #{$complaint['id']} has been submitted by {$sender['fname']} {$sender['lname']} on " . date('M j, Y H:i') . ".";
    $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
    $stmt_notify = $db->prepare($sql_notify);
    if ($stmt_notify) {
        $stmt_notify->bind_param("iis", $recipient_id, $complaint_id, $notification_desc);
        if (!$stmt_notify->execute()) {
            error_log("Notification insertion for President failed: " . $stmt_notify->error);
        }
        $stmt_notify->close();
    } else {
        error_log("Failed to prepare notification for President: " . $db->error);
    }

    return true;
}

// Handle escalation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escalate_complaint'])) {
    if (!$president_id) {
        $_SESSION['error'] = "Cannot escalate: No President found in the system.";
        header("Location: dashboard.php");
        exit;
    }

    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $decision_text = trim(filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_SPECIAL_CHARS));

    if (!$complaint_id || empty($decision_text)) {
        $_SESSION['error'] = "Please provide a valid complaint ID and reason for escalation.";
        header("Location: dashboard.php");
        exit;
    }

    if (strlen($decision_text) < 10 || strlen($decision_text) > 1000) {
        $_SESSION['error'] = "Escalation reason must be between 10 and 1000 characters.";
        header("Location: dashboard.php");
        exit;
    }

    $stmt_validate = $db->prepare("
        SELECT e.id as escalation_id, e.escalated_by_id, e.original_handler_id, e.department_id
        FROM escalations e
        WHERE e.complaint_id = ?
          AND e.escalated_to = 'academic_vp'
          AND e.escalated_to_id = ?
          AND e.status = 'pending'
          AND e.action_type IN ('escalation', 'assignment')
        ORDER BY e.created_at DESC
        LIMIT 1
    ");
    if (!$stmt_validate) {
        error_log("Prepare failed (escalation validation): " . $db->error);
        $_SESSION['error'] = "An error occurred while validating the complaint for escalation.";
        header("Location: dashboard.php");
        exit;
    }
    $stmt_validate->bind_param("ii", $complaint_id, $vp_id);
    $stmt_validate->execute();
    $result_validate = $stmt_validate->get_result();

    if ($result_validate->num_rows === 0) {
        $_SESSION['error'] = "Complaint not found, not currently assigned to you, or already processed.";
        header("Location: dashboard.php");
        exit;
    }

    $escalation_data = $result_validate->fetch_assoc();
    $escalation_id = $escalation_data['escalation_id'];
    $escalated_by_id = $escalation_data['escalated_by_id'];
    $handler_id = $escalation_data['original_handler_id'];
    $department_id = $escalation_data['department_id'];
    $stmt_validate->close();

    $db->begin_transaction();
    try {
        $update_escalation_sql = "UPDATE escalations SET status = 'forwarded', resolution_details = ?, resolved_at = NOW() WHERE id = ?";
        $update_escalation_stmt = $db->prepare($update_escalation_sql);
        $update_escalation_stmt->bind_param("si", $decision_text, $escalation_id);
        $update_escalation_stmt->execute();
        $update_escalation_stmt->close();

        $new_escalation_sql = "INSERT INTO escalations (complaint_id, escalated_by_id, escalated_to_id, escalated_to, department_id, action_type, status, created_at, original_handler_id)
                              VALUES (?, ?, ?, 'president', ?, 'escalation', 'pending', NOW(), ?)";
        $new_escalation_stmt = $db->prepare($new_escalation_sql);
        $new_escalation_stmt->bind_param("iiiii", $complaint_id, $vp_id, $president_id, $department_id, $handler_id);
        $new_escalation_stmt->execute();
        $new_escalation_stmt->close();

        $update_complaint_sql = "UPDATE complaints SET status = 'escalated', resolution_details = NULL, resolution_date = NULL WHERE id = ?";
        $update_complaint_stmt = $db->prepare($update_complaint_sql);
        $update_complaint_stmt->bind_param("i", $complaint_id);
        $update_complaint_stmt->execute();
        $update_complaint_stmt->close();

        $notification_desc_pres = "Complaint #$complaint_id has been escalated to you by Academic VP {$vp['fname']} {$vp['lname']}. Reason: $decision_text";
        $notify_pres_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $stmt_notify_pres = $db->prepare($notify_pres_sql);
        $stmt_notify_pres->bind_param("iis", $president_id, $complaint_id, $notification_desc_pres);
        $stmt_notify_pres->execute();
        $stmt_notify_pres->close();

        $submitter_id_sql = "SELECT user_id FROM complaints WHERE id = ?";
        $submitter_stmt = $db->prepare($submitter_id_sql);
        $submitter_stmt->bind_param("i", $complaint_id);
        $submitter_stmt->execute();
        $submitter_result = $submitter_stmt->get_result();
        if ($submitter_result->num_rows > 0) {
            $submitter_data = $submitter_result->fetch_assoc();
            $submitter_id = $submitter_data['user_id'];
            $submitter_stmt->close();

            $notification_desc_user = "Update on Complaint #$complaint_id: Your complaint has been escalated to the President by Academic VP {$vp['fname']} {$vp['lname']}. Reason: $decision_text";
            $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $stmt_notify_user = $db->prepare($notify_user_sql);
            $stmt_notify_user->bind_param("iis", $submitter_id, $complaint_id, $notification_desc_user);
            $stmt_notify_user->execute();
            $stmt_notify_user->close();
        } else {
            $submitter_stmt->close();
            error_log("Could not find original submitter for complaint #$complaint_id during escalation notification.");
        }

        if ($handler_id && $handler_id != $vp_id && $handler_id != $submitter_id) {
            $notification_desc_handler = "Update on Complaint #$complaint_id (originally handled by you): Escalated to President by Academic VP {$vp['fname']} {$vp['lname']}. Reason: $decision_text";
            $notify_handler_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $stmt_notify_handler = $db->prepare($notify_handler_sql);
            $stmt_notify_handler->bind_param("iis", $handler_id, $complaint_id, $notification_desc_handler);
            $stmt_notify_handler->execute();
            $stmt_notify_handler->close();
        }

        if ($escalated_by_id && $escalated_by_id != $vp_id && $escalated_by_id != $submitter_id && $escalated_by_id != $handler_id) {
            $notification_desc_dean = "Update on Complaint #$complaint_id (which you escalated): Further escalated to President by Academic VP {$vp['fname']} {$vp['lname']}. Reason: $decision_text";
            $notify_dean_sql = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
            $stmt_notify_dean = $db->prepare($notify_dean_sql);
            $stmt_notify_dean->bind_param("iis", $escalated_by_id, $complaint_id, $notification_desc_dean);
            $stmt_notify_dean->execute();
            $stmt_notify_dean->close();
        }

        $additional_info_report = "Escalated by Academic Vice President {$vp['fname']} {$vp['lname']}. Reason: $decision_text";
        if (!sendStereotypedReport($db, $complaint_id, $vp_id, 'escalated', $additional_info_report)) {
            throw new Exception($_SESSION['error'] ?? "Failed to send the report to the President.");
        }

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been successfully escalated to the President.";
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "Error escalating complaint: " . $e->getMessage();
        error_log("Escalation transaction error for complaint #$complaint_id by VP #$vp_id: " . $e->getMessage());
    }

    header("Location: dashboard.php");
    exit;
}

// Fetch distinct categories and statuses for filtering
$categories = [];
$sql_categories = "SELECT DISTINCT category FROM complaints WHERE category IS NOT NULL AND category <> '' ORDER BY category";
$result_categories = $db->query($sql_categories);
if ($result_categories) {
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

$statuses = [];
$sql_statuses = "SELECT DISTINCT status FROM complaints ORDER BY status";
$result_statuses = $db->query($sql_statuses);
if ($result_statuses) {
    while ($row = $result_statuses->fetch_assoc()) {
        $statuses[] = $row['status'];
    }
}

// Pagination for Escalated/Assigned Complaints
$items_per_page = 5;
$page_escalated = isset($_GET['page_escalated']) ? max(1, (int)$_GET['page_escalated']) : 1;
$offset_escalated = ($page_escalated - 1) * $items_per_page;

// Sorting and Filtering for Escalated/Assigned Complaints
$sort_column = isset($_GET['sort']) && in_array($_GET['sort'], ['id', 'title', 'category', 'status', 'created_at']) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) && strtolower($_GET['order']) === 'asc' ? 'ASC' : 'DESC';

$filter_category = isset($_GET['category']) ? trim($_GET['category']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';

$where_conditions_escalated = [];
$where_params_escalated = [$vp_id];
$where_types_escalated = "i";

if ($filter_category) {
    $where_conditions_escalated[] = "c.category = ?";
    $where_params_escalated[] = $filter_category;
    $where_types_escalated .= "s";
}
if ($filter_status) {
    $where_conditions_escalated[] = "c.status = ?";
    $where_params_escalated[] = $filter_status;
    $where_types_escalated .= "s";
}
$where_clause_escalated = !empty($where_conditions_escalated) ? " AND " . implode(" AND ", $where_conditions_escalated) : "";

$sql_count_escalated = "
    SELECT COUNT(DISTINCT c.id) as total
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'academic_vp'
      AND e.escalated_to_id = ?
      AND e.status = 'pending'
      AND e.action_type IN ('escalation', 'assignment')
      $where_clause_escalated
      AND e.id = (
          SELECT MAX(e2.id)
          FROM escalations e2
          WHERE e2.complaint_id = c.id
            AND e2.escalated_to = 'academic_vp'
            AND e2.status = 'pending'
      )";
$stmt_count_escalated = $db->prepare($sql_count_escalated);
$total_escalated = 0;
if ($stmt_count_escalated) {
    $stmt_count_escalated->bind_param($where_types_escalated, ...$where_params_escalated);
    $stmt_count_escalated->execute();
    $result_count = $stmt_count_escalated->get_result();
    $row_count = $result_count->fetch_assoc();
    $total_escalated = $row_count ? $row_count['total'] : 0;
    $stmt_count_escalated->close();
} else {
    error_log("Error preparing escalated count query: " . $db->error);
}
$total_pages_escalated = max(1, ceil($total_escalated / $items_per_page));

$sql_escalated = "
    SELECT c.*, u_submitter.fname as submitter_fname, u_submitter.lname as submitter_lname,
           e.id as escalation_id, e.status as escalation_status, e.action_type, e.escalated_by_id,
           e.original_handler_id, e.department_id, e.created_at as escalation_created_at,
           u_sender.fname as sender_fname, u_sender.lname as sender_lname, u_sender.role as sender_role
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    LEFT JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN users u_sender ON e.escalated_by_id = u_sender.id
    WHERE e.escalated_to = 'academic_vp'
      AND e.escalated_to_id = ?
      AND e.status = 'pending'
      AND e.action_type IN ('escalation', 'assignment')
      $where_clause_escalated
      AND e.id = (
          SELECT MAX(e2.id)
          FROM escalations e2
          WHERE e2.complaint_id = c.id
            AND e2.escalated_to = 'academic_vp'
            AND e2.status = 'pending'
      )
    ORDER BY c.$sort_column $sort_order
    LIMIT ? OFFSET ?";
$stmt_escalated = $db->prepare($sql_escalated);
$escalated_complaints = [];
if ($stmt_escalated) {
    $param_types = $where_types_escalated . "ii";
    $params = array_merge($where_params_escalated, [$items_per_page, $offset_escalated]);
    $stmt_escalated->bind_param($param_types, ...$params);
    $stmt_escalated->execute();
    $escalated_result = $stmt_escalated->get_result();
    $escalated_complaints_data = [];
    while ($row = $escalated_result->fetch_assoc()) {
        $escalated_complaints_data[] = $row;
    }
    $stmt_escalated->close();

    foreach ($escalated_complaints_data as $complaint) {
        $complaint_id = $complaint['id'];
        $sql_stereotypes = "SELECT s.label FROM complaint_stereotypes cs JOIN stereotypes s ON cs.stereotype_id = s.id WHERE cs.complaint_id = ?";
        $stmt_stereo = $db->prepare($sql_stereotypes);
        if ($stmt_stereo) {
            $stmt_stereo->bind_param("i", $complaint_id);
            $stmt_stereo->execute();
            $result_stereo = $stmt_stereo->get_result();
            $stereotypes = [];
            while ($stereo_row = $result_stereo->fetch_assoc()) {
                $stereotypes[] = $stereo_row['label'];
            }
            $stmt_stereo->close();
            $complaint['stereotypes'] = $stereotypes;
            $escalated_complaints[] = $complaint;
        }
    }
} else {
    error_log("Error preparing escalated complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching escalated complaints.";
}

// Pagination for Decided Complaints
$page_decided = isset($_GET['page_decided']) ? max(1, (int)$_GET['page_decided']) : 1;
$offset_decided = ($page_decided - 1) * $items_per_page;

$sort_column_decided = isset($_GET['sort_decided']) && in_array($_GET['sort_decided'], ['complaint_id', 'title', 'category', 'status', 'sent_on']) ? $_GET['sort_decided'] : 'sent_on';
$sort_order_decided = isset($_GET['order_decided']) && strtolower($_GET['order_decided']) === 'asc' ? 'ASC' : 'DESC';

$filter_category_decided = isset($_GET['category_decided']) ? trim($_GET['category_decided']) : '';
$filter_status_decided = isset($_GET['status_decided']) ? trim($_GET['status_decided']) : '';

$where_conditions_decided = [];
$where_params_decided = [$vp_id];
$where_types_decided = "i";

if ($filter_category_decided) {
    $where_conditions_decided[] = "c.category = ?";
    $where_params_decided[] = $filter_category_decided;
    $where_types_decided .= "s";
}
if ($filter_status_decided) {
    $where_conditions_decided[] = "c.status = ?";
    $where_params_decided[] = $filter_status_decided;
    $where_types_decided .= "s";
}
$where_clause_decided = !empty($where_conditions_decided) ? " AND " . implode(" AND ", $where_conditions_decided) : "";

$sql_count_decided = "
    SELECT COUNT(DISTINCT d.id) as total
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    JOIN users u_receiver ON d.receiver_id = u_receiver.id
    WHERE d.sender_id = ?
      AND d.status = 'action_required'
      AND u_receiver.role IN ('handler', 'college_dean')
      $where_clause_decided
      AND NOT EXISTS (
          SELECT 1
          FROM decisions d2
          WHERE d2.complaint_id = d.complaint_id
            AND d2.sender_id = d.receiver_id
            AND d2.created_at > d.created_at
      )";
$stmt_count_decided = $db->prepare($sql_count_decided);
$total_decided = 0;
if ($stmt_count_decided) {
    $stmt_count_decided->bind_param($where_types_decided, ...$where_params_decided);
    $stmt_count_decided->execute();
    $result = $stmt_count_decided->get_result();
    $row = $result->fetch_assoc();
    $total_decided = $row ? $row['total'] : 0;
    $stmt_count_decided->close();
} else {
    error_log("Error preparing decided count query: " . $db->error);
}
$total_pages_decided = max(1, ceil($total_decided / $items_per_page));

$sql_decided = "
    SELECT c.id as complaint_id, c.title, c.description, c.category, c.status,
           d.created_at AS sent_on,
           CONCAT(u_receiver.fname, ' ', u_receiver.lname) AS receiver_name,
           u_receiver.role as receiver_role,
           d.decision_text,
           d.id as decision_id
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    JOIN users u_receiver ON d.receiver_id = u_receiver.id
    WHERE d.sender_id = ?
      AND d.status = 'action_required'
      AND u_receiver.role IN ('handler', 'college_dean')
      $where_clause_decided
      AND NOT EXISTS (
          SELECT 1
          FROM decisions d2
          WHERE d2.complaint_id = d.complaint_id
            AND d2.sender_id = d.receiver_id
            AND d2.created_at > d.created_at
      )
    ORDER BY " . ($sort_column_decided === 'sent_on' ? 'd.created_at' : "c.$sort_column_decided") . " $sort_order_decided
    LIMIT ? OFFSET ?";
$stmt_decided = $db->prepare($sql_decided);
$decided_complaints = [];
if ($stmt_decided) {
    $param_types = $where_types_decided . "ii";
    $params = array_merge($where_params_decided, [$items_per_page, $offset_decided]);
    $stmt_decided->bind_param($param_types, ...$params);
    $stmt_decided->execute();
    $decided_result = $stmt_decided->get_result();
    while ($row = $decided_result->fetch_assoc()) {
        $decided_complaints[] = $row;
    }
    $stmt_decided->close();
} else {
    error_log("Error preparing decided complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching decided complaints.";
}

// Fetch summary statistics
$stats = [
    'pending_escalated' => $total_escalated,
    'resolved_by_me' => 0,
    'decisions_sent_pending' => $total_decided,
    'escalated_by_me' => 0
];

$sql_stats_resolved = "
    SELECT COUNT(DISTINCT c.id) as count
    FROM complaints c
    JOIN decisions d ON c.id = d.complaint_id
    WHERE c.status = 'resolved'
      AND d.sender_id = ?
      AND d.status = 'final'
      AND d.id = (
            SELECT MAX(d_latest.id)
            FROM decisions d_latest
            WHERE d_latest.complaint_id = c.id
       )";
$stmt_stats_resolved = $db->prepare($sql_stats_resolved);
if ($stmt_stats_resolved) {
    $stmt_stats_resolved->bind_param("i", $vp_id);
    $stmt_stats_resolved->execute();
    $result_stats_resolved = $stmt_stats_resolved->get_result();
    $row = $result_stats_resolved->fetch_assoc();
    $stats['resolved_by_me'] = $row ? $row['count'] : 0;
    $stmt_stats_resolved->close();
} else {
    error_log("Error preparing resolved stats query: " . $db->error);
}

$sql_stats_escalated = "
    SELECT COUNT(DISTINCT e.complaint_id) as count
    FROM escalations e
    WHERE e.escalated_by_id = ?
      AND e.escalated_to = 'president'";
$stmt_stats_escalated = $db->prepare($sql_stats_escalated);
if ($stmt_stats_escalated) {
    $stmt_stats_escalated->bind_param("i", $vp_id);
    $stmt_stats_escalated->execute();
    $result_stats_escalated = $stmt_stats_escalated->get_result();
    $row = $result_stats_escalated->fetch_assoc();
    $stats['escalated_by_me'] = $row ? $row['count'] : 0;
    $stmt_stats_escalated->close();
} else {
    error_log("Error preparing escalated-by-me stats query: " . $db->error);
}

// Fetch notification count
$sql_notif_count = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif_count = $db->prepare($sql_notif_count);
$notification_count = 0;
if ($stmt_notif_count) {
    $stmt_notif_count->bind_param("i", $vp_id);
    $stmt_notif_count->execute();
    $result_notif = $stmt_notif_count->get_result();
    $row_notif = $result_notif->fetch_assoc();
    $notification_count = $row_notif ? $row_notif['count'] : 0;
    $stmt_notif_count->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic VP Dashboard | DMU Complaint System</title>
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
            border-radius: 50%;
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

        .nav-link .badge {
            margin-left: auto;
            font-size: 0.8rem;
            padding: 2px 6px;
            background-color: var(--danger);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

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

        .horizontal-nav .logo span {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
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
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }

        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
            flex-grow: 1;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--light-gray) 0%, #ffffff 100%);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            border-left: 5px solid var(--primary);
        }

        .stat-card i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.25rem;
        }

        .stat-card .label {
            font-size: 0.95rem;
            color: var(--gray);
            font-weight: 500;
        }

        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.pending i { color: var(--warning); }
        .stat-card.resolved { border-left-color: var(--success); }
        .stat-card.resolved i { color: var(--success); }
        .stat-card.decisions { border-left-color: var(--orange); }
        .stat-card.decisions i { color: var(--orange); }
        .stat-card.escalated { border-left-color: var(--purple); }
        .stat-card.escalated i { color: var(--purple); }

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
            border: 1px solid var(--light-gray);
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        th a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        th a i {
            margin-left: 5px;
            font-size: 0.9rem;
            opacity: 0.7;
        }

        th a:hover i {
            opacity: 1;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background: #e8f4ff;
        }

        td.description {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        td.description[title]:hover {
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
        }

        .status {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-pending { background-color: rgba(255, 193, 7, 0.15); color: var(--warning); }
        .status-validated { background-color: rgba(13, 202, 240, 0.15); color: var(--info); }
        .status-in_progress { background-color: rgba(23, 162, 184, 0.15); color: var(--info); }
        .status-resolved { background-color: rgba(40, 167, 69, 0.15); color: var(--success); }
        .status-rejected { background-color: rgba(220, 53, 69, 0.15); color: var(--danger); }
        .status-assigned { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-escalated { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-uncategorized { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }
        .status-action_required { background-color: rgba(255, 193, 7, 0.2); color: #b98900; }

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

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-info { background: var(--info); color: white; }
        .btn-info:hover { background: #0baccc; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #218838; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-warning { background: var(--warning); color: var(--dark); }
        .btn-warning:hover { background: #e0a800; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-accent { background: var(--accent); color: white; }
        .btn-accent:hover { background: #3abde0; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-purple { background: var(--secondary); color: white; }
        .btn-purple:hover { background: #5a0a92; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-small { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        .filter-form {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-form select, .filter-form button, .filter-form a.btn-secondary {
            padding: 8px 12px;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--dark);
            background-color: white;
            transition: var(--transition);
            height: 40px;
        }

        .filter-form select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
        }

        .filter-form button, .filter-form a.btn-secondary {
            background-color: var(--primary);
            color: white;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .filter-form button:hover, .filter-form a.btn-secondary:hover {
            background-color: var(--primary-dark);
        }

        .filter-form a.btn-secondary {
            background-color: var(--gray);
        }

        .filter-form a.btn-secondary:hover {
            background-color: var(--dark);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content h4 {
            margin-bottom: 1rem;
            color: var(--primary-dark);
        }

        .modal-content .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-content .close:hover {
            color: var(--danger);
        }

        .modal-content form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .modal-content textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            resize: vertical;
            min-height: 120px;
        }

        .modal-content textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--primary);
            transition: var(--transition);
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
        }

        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            background-color: #f9f9f9;
            border-radius: var(--radius);
            margin-top: 1rem;
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            width: 100%;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .group-name { font-weight: 600; font-size: 1.1rem; margin-bottom: 10px; }
        .social-links { display: flex; justify-content: center; gap: 20px; margin: 15px 0; }
        .social-links a { color: white; font-size: 1.5rem; transition: var(--transition); }
        .social-links a:hover { transform: translateY(-3px); color: var(--accent); }
        .copyright { font-size: 0.9rem; opacity: 0.8; }

        @media (max-width: 992px) {
            .vertical-nav { width: 220px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav { width: 100%; height: auto; position: relative; overflow-y: hidden; }
            .main-content { min-height: calc(100vh - HeightOfVerticalNav); }
            .horizontal-nav { flex-direction: column; gap: 10px; }
            .horizontal-menu { flex-wrap: wrap; justify-content: center; }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: 1fr; }

            .table-responsive table,
            .table-responsive thead,
            .table-responsive tbody,
            .table-responsive th,
            .table-responsive td,
            .table-responsive tr { display: block; }
            .table-responsive thead tr { position: absolute; top: -9999px; left: -9999px; }
            .table-responsive tr { margin-bottom: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: white; }
            .table-responsive tr:nth-child(even) { background-color: white; }
            .table-responsive td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 50% !important; text-align: left; white-space: normal; min-height: 30px; display: flex; align-items: center; }
            .table-responsive td:before { content: attr(data-label); position: absolute; left: 15px; width: 45%; padding-right: 10px; font-weight: bold; color: var(--primary); white-space: normal; text-align: left; }
            .table-responsive td:last-child { border-bottom: none; }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .profile-card { flex-direction: column; text-align: center; }
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-form select, .filter-form button, .filter-form a.btn-secondary { width: 100%; }
            td.description { max-width: 100%; white-space: normal; }
            .btn, .btn-small { width: 100%; margin-bottom: 5px; }
            .table-responsive td .btn, .table-responsive td .btn-small { width: auto; margin-bottom: 0;}
            td[data-label="Actions"] {
                padding-left: 15px !important;
                display: flex;
                flex-direction: column;
                gap: 5px;
                align-items: flex-start;
            }
            td[data-label="Actions"]::before {
                display: none;
            }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($vp['fname'] . ' ' . $vp['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $vp['role']))); ?></p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
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
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="escalate_complaint.php" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up"></i>
                <span>Escalate Complaint</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
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

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Academic VP</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php" class="active">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Welcome, <?php echo htmlspecialchars($vp['fname'] . ' ' . $vp['lname']); ?>!</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card pending">
                    <i class="fas fa-hourglass-half"></i>
                    <div class="number"><?php echo $stats['pending_escalated']; ?></div>
                    <div class="label">Pending Complaints</div>
                </div>
                <div class="stat-card resolved">
                    <i class="fas fa-check-double"></i>
                    <div class="number"><?php echo $stats['resolved_by_me']; ?></div>
                    <div class="label">Complaints Resolved by You</div>
                </div>
                <div class="stat-card decisions">
                    <i class="fas fa-reply"></i>
                    <div class="number"><?php echo $stats['decisions_sent_pending']; ?></div>
                    <div class="label">Decisions Awaiting Action</div>
                </div>
                <div class="stat-card escalated">
                    <i class="fas fa-arrow-up"></i>
                    <div class="number"><?php echo $stats['escalated_by_me']; ?></div>
                    <div class="label">Complaints Escalated to President</div>
                </div>
            </div>

            <div class="complaints-list">
                <h3>Pending Complaints Assigned/Escalated to You</h3>
                <p style="color: var(--gray); margin-bottom: 1rem;">
                    This section shows complaints assigned or escalated to you for action or further escalation.
                </p>

                <form class="filter-form" method="GET" action="dashboard.php#escalated-section">
                    <input type="hidden" name="page_escalated" value="1">
                    <select name="category" aria-label="Filter by Category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $filter_category === $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($category)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" aria-label="Filter by Status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $filter_status === $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="dashboard.php#escalated-section" class="btn btn-secondary"><i class="fas fa-times"></i> Reset</a>
                </form>

                <?php if (!empty($escalated_complaints) && $president_id): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th><a href="?sort=id&order=<?php echo $sort_column === 'id' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&category=<?php echo urlencode($filter_category); ?>&status=<?php echo urlencode($filter_status); ?>#escalated-section">ID <?php if ($sort_column === 'id') echo $sort_order === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                                    <th><a href="?sort=title&order=<?php echo $sort_column === 'title' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&category=<?php echo urlencode($filter_category); ?>&status=<?php echo urlencode($filter_status); ?>#escalated-section">Title <?php if ($sort_column === 'title') echo $sort_order === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                                    <th>Description</th>
                                    <th><a href="?sort=category&order=<?php echo $sort_column === 'category' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&category=<?php echo urlencode($filter_category); ?>&status=<?php echo urlencode($filter_status); ?>#escalated-section">Category <?php if ($sort_column === 'category') echo $sort_order === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                                    <th>Stereotypes</th>
                                    <th><a href="?sort=status&order=<?php echo $sort_column === 'status' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&category=<?php echo urlencode($filter_category); ?>&status=<?php echo urlencode($filter_status); ?>#escalated-section">Status <?php if ($sort_column === 'status') echo $sort_order === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                                    <th>Assigned/Escalated By</th>
                                    <th><a href="?sort=created_at&order=<?php echo $sort_column === 'created_at' && $sort_order === 'ASC' ? 'desc' : 'asc'; ?>&category=<?php echo urlencode($filter_category); ?>&status=<?php echo urlencode($filter_status); ?>#escalated-section">Submitted On <?php if ($sort_column === 'created_at') echo $sort_order === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($escalated_complaints as $complaint): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $complaint['id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
                                        <td data-label="Description" class="description" title="<?php echo htmlspecialchars($complaint['description']); ?>"><?php echo htmlspecialchars($complaint['description']); ?></td>
                                        <td data-label="Category">
                                            <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                                        </td>
                                        <td data-label="Stereotypes">
                                            <?php
                                            if (!empty($complaint['stereotypes'])) {
                                                echo htmlspecialchars(implode(', ', array_map('ucfirst', $complaint['stereotypes'])));
                                            } else {
                                                echo '<span class="status status-uncategorized">None</span>';
                                            }
                                            ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($complaint['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?>
                                            </span>
                                            <?php if ($complaint['action_type'] === 'escalation'): ?>
                                                <span class="status status-escalated">Escalated</span>
                                            <?php else: ?>
                                                <span class="status status-assigned">Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Assigned/Escalated By">
                                            <?php
                                            if ($complaint['action_type'] === 'escalation') {
                                                echo htmlspecialchars($complaint['sender_fname'] . ' ' . $complaint['sender_lname']) . ' (' . htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['sender_role']))) . ')';
                                            } elseif ($complaint['action_type'] === 'assignment') {
                                                echo htmlspecialchars($complaint['sender_fname'] . ' ' . $complaint['sender_lname']) . ' (Handler - Assigned)';
                                            } else {
                                                echo 'Directly Assigned';
                                            }
                                            ?>
                                        </td>
                                        <td data-label="Submitted On"><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-info btn-small" title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="decide_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-purple btn-small" title="Make Decision">
                                                <i class="fas fa-gavel"></i> Decide
                                            </a>
                                            <button class="btn btn-warning btn-small escalate-btn" data-complaint-id="<?php echo $complaint['id']; ?>" title="Escalate to President">
                                                <i class="fas fa-arrow-up"></i> Escalate
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_escalated; $i++): ?>
                            <a href="?page_escalated=<?php echo $i; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>&category=<?php echo urlencode($filter_category); ?>&status=<?php echo urlencode($filter_status); ?>#escalated-section" class="<?php echo $i == $page_escalated ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No complaints are currently assigned or escalated to you for review<?php echo $president_id ? '' : ', or no President is available for escalation.'; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="complaints-list">
                <h3>Your Decisions Awaiting Action Below</h3>
                <p style="color: var(--gray); margin-bottom: 1rem;">
                    This section shows decisions you've sent to handlers or deans that are still pending action.
                </p>

                <form class="filter-form" method="GET" action="dashboard.php#decided-section">
    <input type="hidden" name="page_decided" value="1">
    <select name="category_decided" aria-label="Filter by Category">
        <option value="">All Categories</option>
        <?php foreach ($categories as $category): ?>
            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $filter_category_decided === $category ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(ucfirst($category)); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="status_decided" aria-label="Filter by Status">
        <option value="">All Statuses</option>
        <?php foreach ($statuses as $status): ?>
            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $filter_status_decided === $status ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $status))); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
    <a href="dashboard.php#decided-section" class="btn btn-secondary"><i class="fas fa-times"></i> Reset</a>
</form>

<?php if (!empty($decided_complaints)): ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th><a href="?sort_decided=complaint_id&order_decided=<?php echo $sort_column_decided === 'complaint_id' && $sort_order_decided === 'ASC' ? 'desc' : 'asc'; ?>&category_decided=<?php echo urlencode($filter_category_decided); ?>&status_decided=<?php echo urlencode($filter_status_decided); ?>#decided-section">ID <?php if ($sort_column_decided === 'complaint_id') echo $sort_order_decided === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                    <th><a href="?sort_decided=title&order_decided=<?php echo $sort_column_decided === 'title' && $sort_order_decided === 'ASC' ? 'desc' : 'asc'; ?>&category_decided=<?php echo urlencode($filter_category_decided); ?>&status_decided=<?php echo urlencode($filter_status_decided); ?>#decided-section">Title <?php if ($sort_column_decided === 'title') echo $sort_order_decided === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                    <th>Description</th>
                    <th><a href="?sort_decided=category&order_decided=<?php echo $sort_column_decided === 'category' && $sort_order_decided === 'ASC' ? 'desc' : 'asc'; ?>&category_decided=<?php echo urlencode($filter_category_decided); ?>&status_decided=<?php echo urlencode($filter_status_decided); ?>#decided-section">Category <?php if ($sort_column_decided === 'category') echo $sort_order_decided === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                    <th><a href="?sort_decided=status&order_decided=<?php echo $sort_column_decided === 'status' && $sort_order_decided === 'ASC' ? 'desc' : 'asc'; ?>&category_decided=<?php echo urlencode($filter_category_decided); ?>&status_decided=<?php echo urlencode($filter_status_decided); ?>#decided-section">Status <?php if ($sort_column_decided === 'status') echo $sort_order_decided === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                    <th><a href="?sort_decided=sent_on&order_decided=<?php echo $sort_column_decided === 'sent_on' && $sort_order_decided === 'ASC' ? 'desc' : 'asc'; ?>&category_decided=<?php echo urlencode($filter_category_decided); ?>&status_decided=<?php echo urlencode($filter_status_decided); ?>#decided-section">Sent On <?php if ($sort_column_decided === 'sent_on') echo $sort_order_decided === 'ASC' ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>'; ?></a></th>
                    <th>Sent To (Role)</th>
                    <th>Your Decision</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($decided_complaints as $row): ?>
                    <tr>
                        <td data-label="ID"><?php echo $row['complaint_id']; ?></td>
                        <td data-label="Title"><?php echo htmlspecialchars($row['title']); ?></td>
                        <td data-label="Description" class="description" title="<?php echo htmlspecialchars($row['description']); ?>"><?php echo htmlspecialchars($row['description']); ?></td>
                        <td data-label="Category">
                            <?php echo !empty($row['category']) ? htmlspecialchars(ucfirst($row['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                        </td>
                        <td data-label="Status">
                            <span class="status status-<?php echo strtolower($row['status']); ?>">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))); ?>
                            </span>
                        </td>
                        <td data-label="Sent On"><?php echo date('M j, Y H:i', strtotime($row['sent_on'])); ?></td>
                        <td data-label="Sent To (Role)"><?php echo htmlspecialchars($row['receiver_name']) . ' (' . htmlspecialchars(ucfirst(str_replace('_', ' ', $row['receiver_role']))) . ')'; ?></td>
                        <td data-label="Your Decision" class="description" title="<?php echo htmlspecialchars($row['decision_text']); ?>"><?php echo htmlspecialchars($row['decision_text']); ?></td>
                        <td data-label="Actions">
                            <a href="view_complaint.php?complaint_id=<?php echo $row['complaint_id']; ?>" class="btn btn-info btn-small" title="View Details">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages_decided; $i++): ?>
            <a href="?page_decided=<?php echo $i; ?>&sort_decided=<?php echo $sort_column_decided; ?>&order_decided=<?php echo $sort_order_decided; ?>&category_decided=<?php echo urlencode($filter_category_decided); ?>&status_decided=<?php echo urlencode($filter_status_decided); ?>#decided-section" class="<?php echo $i == $page_decided ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <p>No decisions are currently awaiting action from handlers or deans.</p>
    </div>
<?php endif; ?>
</div>

<!-- Modal for Escalation -->
<div id="escalateModal" class="modal">
    <div class="modal-content">
        <span class="close">×</span>
        <h4>Escalate Complaint to President</h4>
        <form id="escalateForm" method="POST" action="dashboard.php">
            <input type="hidden" name="escalate_complaint" value="1">
            <input type="hidden" name="complaint_id" id="escalateComplaintId">
            <label for="decision_text">Reason for Escalation:</label>
            <textarea name="decision_text" id="decision_text" required placeholder="Provide the reason for escalating this complaint to the President (10-1000 characters)"></textarea>
            <button type="submit" class="btn btn-warning"><i class="fas fa-arrow-up"></i> Escalate to President</button>
        </form>
    </div>
</div>

</div>

<footer>
    <div class="footer-content">
        <div class="group-name">Group 11</div>
        <div class="social-links">
            <a href="https://github.com" target="_blank" rel="noopener noreferrer"><i class="fab fa-github"></i></a>
            <a href="https://linkedin.com" target="_blank" rel="noopener noreferrer"><i class="fab fa-linkedin"></i></a>
            <a href="https://twitter.com" target="_blank" rel="noopener noreferrer"><i class="fab fa-twitter"></i></a>
        </div>
        <div class="copyright">© 2025 DMU Complaint System. All rights reserved.</div>
    </div>
</footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const escalateButtons = document.querySelectorAll('.escalate-btn');
    const modal = document.getElementById('escalateModal');
    const closeModal = document.querySelector('.modal .close');
    const escalateForm = document.getElementById('escalateForm');
    const complaintIdInput = document.getElementById('escalateComplaintId');

    escalateButtons.forEach(button => {
        button.addEventListener('click', function () {
            const complaintId = this.getAttribute('data-complaint-id');
            complaintIdInput.value = complaintId;
            modal.style.display = 'flex';
        });
    });

    closeModal.addEventListener('click', function () {
        modal.style.display = 'none';
        escalateForm.reset();
    });

    window.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.style.display = 'none';
            escalateForm.reset();
        }
    });

    escalateForm.addEventListener('submit', function (e) {
        const decisionText = document.getElementById('decision_text').value.trim();
        if (decisionText.length < 10 || decisionText.length > 1000) {
            e.preventDefault();
            alert('The reason for escalation must be between 10 and 1000 characters.');
        }
    });
});
</script>

</body>
</html>

<?php
$db->close();
?>