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
$dept_head = null;

// Fetch Department Head details
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
        header("Location: ../logout.php");
        exit;
    }
    $stmt_dept_head->close();
} else {
    error_log("Error preparing department head query: " . $db->error);
    $_SESSION['error'] = "Database error fetching department head details.";
}

// Fetch the college dean (for escalation)
$dean_query = "SELECT id, fname, lname FROM users WHERE role = 'college_dean' LIMIT 1";
$dean_result = $db->query($dean_query);
if ($dean_result && $dean_result->num_rows > 0) {
    $dean = $dean_result->fetch_assoc();
    $college_dean_id = $dean['id'];
} else {
    $college_dean_id = null;
    error_log("No college dean found in the system.");
}

// Handle escalation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escalate_complaint']) && $college_dean_id) {
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $decision_text = trim(filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_SPECIAL_CHARS));

    // Validate input
    if (!$complaint_id || empty($decision_text)) {
        $_SESSION['error'] = "Please provide a valid complaint ID and reason for escalation.";
    } else {
        $db->begin_transaction();
        try {
            // Insert the decision into the decisions table (escalate to college dean)
            $insert_query = "
                INSERT INTO decisions (complaint_id, sender_id, receiver_id, decision_text, status, created_at)
                VALUES (?, ?, ?, ?, 'action_required', NOW())
            ";
            $stmt = $db->prepare($insert_query);
            $stmt->bind_param("iiis", $complaint_id, $dept_head_id, $college_dean_id, $decision_text);
            $stmt->execute();
            $stmt->close();

            // Update the escalation status
            $update_escalation_query = "
                UPDATE escalations
                SET status = 'escalated',
                    escalated_to = 'college_dean',
                    escalated_to_id = ?
                WHERE complaint_id = ? AND escalated_to = 'department_head' AND escalated_to_id = ?
            ";
            $stmt_update = $db->prepare($update_escalation_query);
            $stmt_update->bind_param("iii", $college_dean_id, $complaint_id, $dept_head_id);
            $stmt_update->execute();
            $stmt_update->close();

            // Update complaint status
            $update_complaint = "UPDATE complaints SET status = 'escalated' WHERE id = ?";
            $stmt_complaint = $db->prepare($update_complaint);
            $stmt_complaint->bind_param("i", $complaint_id);
            $stmt_complaint->execute();
            $stmt_complaint->close();

            // Notify the college dean
            $notification_desc = "Complaint #$complaint_id has been escalated to you by the Department Head: " . htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']);
            $notification_query = "
                INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ";
            $stmt_notify = $db->prepare($notification_query);
            $stmt_notify->bind_param("iis", $college_dean_id, $complaint_id, $notification_desc);
            $stmt_notify->execute();
            $stmt_notify->close();

            $db->commit();
            $_SESSION['success'] = "Complaint escalated successfully to the College Dean.";
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "Error escalating complaint: " . $e->getMessage();
            error_log("Escalation error: " . $e->getMessage());
        }
    }
    header("Location: dashboard.php");
    exit;
}

// Pagination for Assigned Complaints
$items_per_page = 5;
$page_assigned = isset($_GET['page_assigned']) ? max(1, (int)$_GET['page_assigned']) : 1;
$offset_assigned = ($page_assigned - 1) * $items_per_page;

// Fetch assigned complaints with pagination
$sql_count_assigned = "
    SELECT COUNT(*) as total
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'department_head' AND e.escalated_to_id = ? AND e.status = 'pending'";
$stmt_count_assigned = $db->prepare($sql_count_assigned);
$total_assigned = 0;
if ($stmt_count_assigned) {
    $stmt_count_assigned->bind_param("i", $dept_head_id);
    $stmt_count_assigned->execute();
    $result = $stmt_count_assigned->get_result();
    $row = $result->fetch_assoc();
    $total_assigned = $row ? $row['total'] : 0;
    $stmt_count_assigned->close();
}

$total_pages_assigned = max(1, ceil($total_assigned / $items_per_page));

$sql_assigned = "
    SELECT c.*, u_submitter.fname as submitter_fname, u_submitter.lname as submitter_lname,
           e.id as escalation_id, e.status as escalation_status, e.action_type
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    LEFT JOIN users u_submitter ON c.user_id = u_submitter.id
    WHERE e.escalated_to = 'department_head' AND e.escalated_to_id = ? AND e.status = 'pending'
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?";
$stmt_assigned = $db->prepare($sql_assigned);
$assigned_complaints = [];
if ($stmt_assigned) {
    $stmt_assigned->bind_param("iii", $dept_head_id, $items_per_page, $offset_assigned);
    $stmt_assigned->execute();
    $assigned_complaints_result = $stmt_assigned->get_result();
    $assigned_complaints_data = [];
    while ($row = $assigned_complaints_result->fetch_assoc()) {
        $assigned_complaints_data[] = $row;
    }
    $stmt_assigned->close();

    // Fetch stereotypes for assigned complaints
    foreach ($assigned_complaints_data as $complaint) {
        $complaint_id = $complaint['id'];
        $sql_stereotypes = "
            SELECT s.label
            FROM complaint_stereotypes cs
            JOIN stereotypes s ON cs.stereotype_id = s.id
            WHERE cs.complaint_id = ?";
        $stmt = $db->prepare($sql_stereotypes);
        if ($stmt) {
            $stmt->bind_param("i", $complaint_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stereotypes = [];
            while ($row = $result->fetch_assoc()) {
                $stereotypes[] = $row['label'];
            }
            $stmt->close();
            $complaint['stereotypes'] = $stereotypes;
            $assigned_complaints[] = $complaint;
        }
    }
} else {
    error_log("Error preparing assigned complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching assigned complaints.";
}

// Pagination for Complaints with Decisions Sent Back
$page_decided = isset($_GET['page_decided']) ? max(1, (int)$_GET['page_decided']) : 1;
$offset_decided = ($page_decided - 1) * $items_per_page;

// Count total complaints with decisions sent back
$sql_count_decided = "
    SELECT COUNT(*) as total
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    JOIN users u ON d.receiver_id = u.id
    WHERE d.sender_id = ?
    AND d.status = 'action_required'
    AND u.role = 'handler'
    AND NOT EXISTS (
        SELECT 1
        FROM decisions d2
        WHERE d2.complaint_id = d.complaint_id
        AND d2.sender_id = d.receiver_id
        AND d2.status = 'final'
    )";
$stmt_count_decided = $db->prepare($sql_count_decided);
$total_decided = 0;
if ($stmt_count_decided) {
    $stmt_count_decided->bind_param("i", $dept_head_id);
    $stmt_count_decided->execute();
    $result = $stmt_count_decided->get_result();
    $row = $result->fetch_assoc();
    $total_decided = $row ? $row['total'] : 0;
    $stmt_count_decided->close();
}

$total_pages_decided = max(1, ceil($total_decided / $items_per_page));

// Fetch complaints with decisions sent back
$sql_decided = "
    SELECT c.id as complaint_id, c.title, c.description, c.category, c.status,
           DATE_FORMAT(d.created_at, '%b %d, %Y, %h:%i %p') AS sent_on,
           CONCAT(u_receiver.fname, ' ', u_receiver.lname) AS receiver_name,
           d.decision_text
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    JOIN users u_receiver ON d.receiver_id = u_receiver.id
    WHERE d.sender_id = ?
    AND d.status = 'action_required'
    AND u_receiver.role = 'handler'
    AND NOT EXISTS (
        SELECT 1
        FROM decisions d2
        WHERE d2.complaint_id = d.complaint_id
        AND d2.sender_id = d.receiver_id
        AND d2.status = 'final'
    )
    ORDER BY d.created_at DESC
    LIMIT ? OFFSET ?";
$stmt_decided = $db->prepare($sql_decided);
$decided_complaints = [];
if ($stmt_decided) {
    $stmt_decided->bind_param("iii", $dept_head_id, $items_per_page, $offset_decided);
    $stmt_decided->execute();
    $decided_complaints_result = $stmt_decided->get_result();
    while ($row = $decided_complaints_result->fetch_assoc()) {
        $decided_complaints[] = $row;
    }
    $stmt_decided->close();
}

// Fetch committees the department head is a member of
$sql_committees = "
    SELECT cm.committee_id, co.name AS committee_name, c.id AS complaint_id, c.title AS complaint_title
    FROM committee_members cm
    JOIN committees co ON cm.committee_id = co.id
    JOIN complaints c ON c.committee_id = cm.committee_id
    WHERE cm.user_id = ?";
$stmt_committees = $db->prepare($sql_committees);
$committees = [];
if ($stmt_committees) {
    $stmt_committees->bind_param("i", $dept_head_id);
    $stmt_committees->execute();
    $result_committees = $stmt_committees->get_result();
    while ($row = $result_committees->fetch_assoc()) {
        $committees[] = $row;
    }
    $stmt_committees->close();
} else {
    error_log("Error preparing committees query: " . $db->error);
}

// Fetch summary statistics
$stats = [
    'pending_assigned' => 0,
    'resolved_by_me' => 0,
    'decisions_sent_pending' => 0,
    'escalated_by_me' => 0
];

// Count assigned complaints with pending status for this dept head
$sql_stats_pending = "
    SELECT COUNT(*) as count
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'department_head' AND e.escalated_to_id = ? AND e.status = 'pending'";
$stmt_stats_pending = $db->prepare($sql_stats_pending);
if ($stmt_stats_pending) {
    $stmt_stats_pending->bind_param("i", $dept_head_id);
    $stmt_stats_pending->execute();
    $result_stats_pending = $stmt_stats_pending->get_result();
    $row = $result_stats_pending->fetch_assoc();
    $stats['pending_assigned'] = $row ? $row['count'] : 0;
    $stmt_stats_pending->close();
}

// Count complaints resolved by this dept head
$sql_stats_resolved = "
    SELECT COUNT(DISTINCT c.id) as count
    FROM complaints c
    JOIN decisions d ON c.id = d.complaint_id
    WHERE c.status = 'resolved'
    AND d.sender_id = ?
    AND d.status = 'final'
    AND d.id = (SELECT MAX(id) FROM decisions WHERE complaint_id = c.id)";
$stmt_stats_resolved = $db->prepare($sql_stats_resolved);
if ($stmt_stats_resolved) {
    $stmt_stats_resolved->bind_param("i", $dept_head_id);
    $stmt_stats_resolved->execute();
    $result_stats_resolved = $stmt_stats_resolved->get_result();
    $row = $result_stats_resolved->fetch_assoc();
    $stats['resolved_by_me'] = $row ? $row['count'] : 0;
    $stmt_stats_resolved->close();
}

// Count decisions sent back by this dept head that are still pending
$stats['decisions_sent_pending'] = $total_decided;

// Count complaints escalated by this dept head
$sql_stats_escalated = "
    SELECT COUNT(DISTINCT e.id) as count
    FROM escalations e
    JOIN decisions d ON e.complaint_id = d.complaint_id
    WHERE d.sender_id = ?
    AND e.escalated_to = 'college_dean'
    AND e.status = 'escalated'
    AND d.status = 'action_required'";
$stmt_stats_escalated = $db->prepare($sql_stats_escalated);
if ($stmt_stats_escalated) {
    $stmt_stats_escalated->bind_param("i", $dept_head_id);
    $stmt_stats_escalated->execute();
    $result_stats_escalated = $stmt_stats_escalated->get_result();
    $row = $result_stats_escalated->fetch_assoc();
    $stats['escalated_by_me'] = $row ? $row['count'] : 0;
    $stmt_stats_escalated->close();
}

// Fetch notification count
$notification_count = 0;
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
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Head Dashboard | DMU Complaint System</title>
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
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
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }

        /* Content Container */
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

        .summary-card.pending_assigned { border-left-color: var(--warning); }
        .summary-card.resolved_by_me { border-left-color: var(--success); }
        .summary-card.decisions_sent_pending { border-left-color: var(--orange); }
        .summary-card.escalated_by_me { border-left-color: var(--purple); }

        .summary-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .summary-card.pending_assigned i { color: var(--warning); }
        .summary-card.resolved_by_me i { color: var(--success); }
        .summary-card.decisions_sent_pending i { color: var(--orange); }
        .summary-card.escalated_by_me i { color: var(--purple); }

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

        /* Status Badges */
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
        .status-action_required { background-color: rgba(253, 126, 20, 0.15); color: var(--orange); }
        .status-uncategorized { background-color: rgba(108, 117, 125, 0.15); color: var(--gray); }

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

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-info { background: var(--info); color: white; }
        .btn-info:hover { background: #0baccc; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #218838; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-warning { background: var(--warning); color<iostream> color: var(--dark); }
        .btn-warning:hover { background: #e0a800; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-accent { background: var(--accent); color: white; }
        .btn-accent:hover { background: #3abde0; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-purple { background: var(--secondary); color: white; }
        .btn-purple:hover { background: #5a0a92; box-shadow: var(--shadow-hover); transform: translateY(-2px); }
        .btn-small { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        /* Modal Styling */
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

        /* Pagination */
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

        /* Empty State */
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

        /* Footer */
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

        /* Responsive Adjustments */
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
            .summary-cards { grid-template-columns: 1fr; }

            /* Responsive Table Stacking */
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
        <h3>Dashboard</h3>
        <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard Overview</span>
        </a>

        <h3>Complaint Management</h3>
        <a href="assign_complaint.php" class="nav-link <?php echo $current_page == 'assign_complaint.php' ? 'active' : ''; ?>">
            <i class="fas fa-list-alt"></i>
            <span>Assigned Complaints</span>
        </a>
        <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle"></i>
            <span>Resolved Complaints</span>
        </a>
        <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
            <i class="fas fa-gavel"></i>
            <span>Decide Complaint</span>
        </a>
        <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'escalate_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to escalate from the dashboard.'); window.location.href='dashboard.php';">
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
        <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i>
            <span>Change Password</span>
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
                <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Dashboard Content -->
        <div class="content-container">
            <h2>Welcome, <?php echo htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']); ?>!</h2>

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
                <div class="summary-card pending_assigned">
                    <i class="fas fa-inbox"></i>
                    <h4>Pending Assignment</h4>
                    <p><?php echo $stats['pending_assigned']; ?></p>
                </div>
                <div class="summary-card resolved_by_me">
                    <i class="fas fa-check-double"></i>
                    <h4>Resolved By You</h4>
                    <p><?php echo $stats['resolved_by_me']; ?></p>
                </div>
                <div class="summary-card decisions_sent_pending">
                    <i class="fas fa-paper-plane"></i>
                    <h4>Decisions Sent (Pending)</h4>
                    <p><?php echo $stats['decisions_sent_pending']; ?></p>
                </div>
                <div class="summary-card escalated_by_me">
                    <i class="fas fa-arrow-up"></i>
                    <h4>Escalated By You</h4>
                    <p><?php echo $stats['escalated_by_me']; ?></p>
                </div>
            </div>

            <!-- Department Head Profile Section -->
            <?php if ($dept_head): ?>
            <div class="user-profile">
                <h3>Your Profile</h3>
                <div class="profile-card">
                    <div class="profile-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="profile-details">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($dept_head['email']); ?></p>
                        <p><strong>Role:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dept_head['role']))); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Assigned Complaints Section -->
            <div class="complaints-list">
                <h3>Complaints Assigned To You (Pending Decision)</h3>
                <?php if (!empty($assigned_complaints) && $college_dean_id): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Stereotypes</th>
                                    <th>Status</th>
                                    <th>Submitted On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assigned_complaints as $complaint): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $complaint['id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
                                        <td data-label="Description" class="description" title="<?php echo htmlspecialchars($complaint['description']); ?>">
                                            <?php echo htmlspecialchars($complaint['description']); ?>
                                        </td>
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
                                            <span class="status status-assigned">Assigned</span>
                                        </td>
                                        <td data-label="Submitted On"><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-info btn-small" title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="decide_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-purple btn-small" title="Make Decision">
                                                <i class="fas fa-gavel"></i> Decide
                                            </a>
                                            <button class="btn btn-warning btn-small escalate-btn" data-complaint-id="<?php echo $complaint['id']; ?>" title="Escalate to College Dean">
                                                <i class="fas fa-arrow-up"></i> Escalate
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_assigned; $i++): ?>
                            <a href="?page_assigned=<?php echo $i; ?>" class="<?php echo $i == $page_assigned ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No complaints are currently assigned to you requiring a decision<?php echo $college_dean_id ? '' : ', or no College Dean is available for escalation.'; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Complaints with Decisions Sent Back (still pending handler action) -->
            <div class="complaints-list">
                <h3>Your Decisions Sent Back (Awaiting Handler Action)</h3>
                <?php if (!empty($decided_complaints)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Complaint ID</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Sent On</th>
                                    <th>Sent To (Handler)</th>
                                    <th>Your Decision</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decided_complaints as $row): ?>
                                    <tr>
                                        <td data-label="Complaint ID"><?php echo htmlspecialchars($row['complaint_id']); ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($row['title']); ?></td>
                                        <td data-label="Category"><?php echo htmlspecialchars(ucfirst($row['category'])); ?></td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($row['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))); ?>
                                            </span>
                                            <span class="status status-action_required">Action Required</span>
                                        </td>
                                        <td data-label="Sent On"><?php echo $row['sent_on']; ?></td>
                                        <td data-label="Sent To"><?php echo htmlspecialchars($row['receiver_name']); ?></td>
                                        <td data-label="Your Decision" class="description" title="<?php echo htmlspecialchars($row['decision_text']); ?>">
                                            <?php echo htmlspecialchars($row['decision_text']); ?>
                                        </td>
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
                    <!-- Pagination for Decided Complaints -->
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_decided; $i++): ?>
                            <a href="?page_decided=<?php echo $i; ?>" class="<?php echo $i == $page_decided ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check"></i>
                        <p>No decisions sent by you are currently pending further action.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Committees Section -->
            <div class="committees-list">
                <h3>Your Committees</h3>
                <?php if (!empty($committees)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Committee Name</th>
                                    <th>Related Complaint</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($committees as $committee): ?>
                                    <tr>
                                        <td data-label="Committee Name"><?php echo htmlspecialchars($committee['committee_name']); ?></td>
                                        <td data-label="Related Complaint">
                                            #<?php echo $committee['complaint_id']; ?> - <?php echo htmlspecialchars($committee['complaint_title']); ?>
                                        </td>
                                        <td data-label="Actions">
                                            <a href="../chat.php?committee_id=<?php echo $committee['committee_id']; ?>" class="btn btn-accent btn-small" title="Join Committee Chat">
                                                <i class="fas fa-comments"></i> Join Chat
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>You are not currently a member of any committees.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div><!-- End content-container -->

        <!-- Escalation Modal -->
        <div id="escalationModal" class="modal">
            <div class="modal-content">
                <span class="close">×</span>
                <h4>Escalate Complaint to College Dean</h4>
                <form id="escalationForm" method="POST" action="">
                    <input type="hidden" name="complaint_id" id="escalationComplaintId">
                    <div class="form-group">
                        <label for="decision_text">Reason for Escalation:</label>
                        <textarea name="decision_text" id="decision_text" required placeholder="Explain why this complaint needs to be escalated to the College Dean..."></textarea>
                    </div>
                    <button type="submit" name="escalate_complaint" class="btn btn-warning">Escalate Complaint</button>
                </form>
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
    </div> <!-- End main-content -->

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
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 5000);
            });

            // Modal functionality
            const modal = document.getElementById('escalationModal');
            const escalateButtons = document.querySelectorAll('.escalate-btn');
            const closeBtn = document.querySelector('.modal-content .close');
            const complaintIdInput = document.getElementById('escalationComplaintId');
            const form = document.getElementById('escalationForm');

            escalateButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const complaintId = this.getAttribute('data-complaint-id');
                    complaintIdInput.value = complaintId;
                    modal.style.display = 'flex';
                });
            });

            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                form.reset();
            });

            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    form.reset();
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