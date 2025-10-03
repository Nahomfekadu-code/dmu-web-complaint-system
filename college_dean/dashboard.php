<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'college_dean'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'college_dean') {
    header("Location: ../unauthorized.php");
    exit;
}

$dean_id = $_SESSION['user_id'];
$dean = null;

// Fetch College Dean details
$sql_dean = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_dean = $db->prepare($sql_dean);
if ($stmt_dean) {
    $stmt_dean->bind_param("i", $dean_id);
    $stmt_dean->execute();
    $result_dean = $stmt_dean->get_result();
    if ($result_dean->num_rows > 0) {
        $dean = $result_dean->fetch_assoc();
    } else {
        $_SESSION['error'] = "College Dean details not found.";
        error_log("College Dean details not found for ID: " . $dean_id);
    }
    $stmt_dean->close();
} else {
    error_log("Error preparing college dean query: " . $db->error);
    $_SESSION['error'] = "Database error fetching college dean details.";
}

// Fetch the Academic Vice President (for escalation)
$vp_query = "SELECT id FROM users WHERE role = 'academic_vp' LIMIT 1";
$vp_result = $db->query($vp_query);
if ($vp_result && $vp_result->num_rows > 0) {
    $vp = $vp_result->fetch_assoc();
    $academic_vp_id = $vp['id'];
} else {
    $academic_vp_id = null;
    error_log("No Academic Vice President found in the system.");
}

// Handle escalation to Academic VP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['escalate_complaint']) && $academic_vp_id) {
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $decision_text = filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$complaint_id || empty($decision_text)) {
        $_SESSION['error'] = "Please provide a valid complaint ID and reason for escalation.";
    } else {
        $db->begin_transaction();
        try {
            // Insert the decision into the decisions table (escalate to Academic VP)
            $insert_query = "
                INSERT INTO decisions (complaint_id, sender_id, receiver_id, decision_text, status, created_at)
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ";
            $stmt = $db->prepare($insert_query);
            if (!$stmt) {
                throw new Exception("Failed to prepare decision insert statement: " . $db->error);
            }
            $stmt->bind_param("iiis", $complaint_id, $dean_id, $academic_vp_id, $decision_text);
            $stmt->execute();
            $stmt->close();

            // Notify the Academic Vice President
            $notification_desc = "Complaint #$complaint_id has been escalated to you by the College Dean.";
            $notification_query = "
                INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ";
            $stmt_notify = $db->prepare($notification_query);
            if (!$stmt_notify) {
                throw new Exception("Failed to prepare notification statement: " . $db->error);
            }
            $stmt_notify->bind_param("iis", $academic_vp_id, $complaint_id, $notification_desc);
            $stmt_notify->execute();
            $stmt_notify->close();

            // Update the escalation status to 'pending' and set action_type to 'escalation'
            $update_escalation_query = "
                UPDATE escalations
                SET status = 'pending',
                    escalated_to = 'academic_vp',
                    escalated_to_id = ?,
                    action_type = 'escalation'
                WHERE complaint_id = ? AND escalated_to = 'college_dean' AND escalated_to_id = ?
            ";
            $stmt_update = $db->prepare($update_escalation_query);
            if (!$stmt_update) {
                throw new Exception("Failed to prepare escalation update statement: " . $db->error);
            }
            $stmt_update->bind_param("iii", $academic_vp_id, $complaint_id, $dean_id);
            $stmt_update->execute();
            $stmt_update->close();

            // Update complaint status
            $update_complaint = "UPDATE complaints SET status = 'escalated' WHERE id = ?";
            $stmt_complaint = $db->prepare($update_complaint);
            if (!$stmt_complaint) {
                throw new Exception("Failed to prepare complaint update statement: " . $db->error);
            }
            $stmt_complaint->bind_param("i", $complaint_id);
            $stmt_complaint->execute();
            $stmt_complaint->close();

            $db->commit();
            $_SESSION['success'] = "Complaint escalated successfully to the Academic Vice President.";
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error'] = "Error escalating complaint: " . $e->getMessage();
            error_log("Escalation error: " . $e->getMessage());
        }
        header("Location: dashboard.php");
        exit;
    }
}

// Pagination for Complaints Escalated to College Dean
$items_per_page = 5;
$page_escalated = isset($_GET['page_escalated']) ? max(1, (int)$_GET['page_escalated']) : 1;
$offset_escalated = ($page_escalated - 1) * $items_per_page;

// Fetch total escalated complaints for pagination
$sql_count_escalated = "
    SELECT COUNT(*) as total
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    WHERE e.escalated_to = 'college_dean' 
    AND e.escalated_to_id = ? 
    AND e.status IN ('pending', 'escalated')";
$stmt_count_escalated = $db->prepare($sql_count_escalated);
$total_escalated = 0;
if ($stmt_count_escalated) {
    $stmt_count_escalated->bind_param("i", $dean_id);
    $stmt_count_escalated->execute();
    $result = $stmt_count_escalated->get_result();
    $row = $result->fetch_assoc();
    $total_escalated = $row ? $row['total'] : 0;
    $stmt_count_escalated->close();
}

$total_pages_escalated = max(1, ceil($total_escalated / $items_per_page));

// Fetch complaints escalated TO this College Dean that are PENDING or ESCALATED with pagination
$escalated_complaints = [];
$stmt = $db->prepare("
    SELECT c.id as complaint_id, c.title, c.description, c.category, c.status as complaint_status, c.created_at,
           e.id as escalation_id, e.status as escalation_status, e.action_type, e.escalated_by_id,
           u_submitter.fname as submitter_fname, u_submitter.lname as submitter_lname,
           u_sender.fname as sender_fname, u_sender.lname as sender_lname, u_sender.role as sender_role
    FROM complaints c
    JOIN escalations e ON c.id = e.complaint_id
    LEFT JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN users u_sender ON e.escalated_by_id = u_sender.id
    WHERE e.escalated_to = 'college_dean'
    AND e.escalated_to_id = ?
    AND e.status IN ('pending', 'escalated')
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
");
if ($stmt) {
    $stmt->bind_param("iii", $dean_id, $items_per_page, $offset_escalated);
    $stmt->execute();
    $escalated_complaints_result = $stmt->get_result();
    $escalated_complaints_data = [];
    while ($row = $escalated_complaints_result->fetch_assoc()) {
        $escalated_complaints_data[] = $row;
    }
    $stmt->close();

    // Fetch stereotypes for each complaint
    foreach ($escalated_complaints_data as $complaint) {
        $complaint_id = $complaint['complaint_id'];
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
            $escalated_complaints[] = $complaint;
        }
    }
} else {
    error_log("Error preparing escalated complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching escalated complaints.";
}

// Pagination for Pending Decisions Received by College Dean
$page_decisions = isset($_GET['page_decisions']) ? max(1, (int)$_GET['page_decisions']) : 1;
$offset_decisions = ($page_decisions - 1) * $items_per_page;

// Fetch total pending decisions for pagination
$sql_count_decisions = "
    SELECT COUNT(*) as total
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    WHERE d.receiver_id = ?
    AND d.status = 'pending'";
$stmt_count_decisions = $db->prepare($sql_count_decisions);
$total_decisions = 0;
if ($stmt_count_decisions) {
    $stmt_count_decisions->bind_param("i", $dean_id);
    $stmt_count_decisions->execute();
    $result = $stmt_count_decisions->get_result();
    $row = $result->fetch_assoc();
    $total_decisions = $row ? $row['total'] : 0;
    $stmt_count_decisions->close();
}

$total_pages_decisions = max(1, ceil($total_decisions / $items_per_page));

// Fetch pending decisions received by this College Dean
$received_decisions = [];
$stmt = $db->prepare("
    SELECT d.id as decision_id, d.complaint_id, d.decision_text, d.created_at,
           c.title, c.description, c.category, c.status as complaint_status,
           u_sender.fname as sender_fname, u_sender.lname as sender_lname, u_sender.role as sender_role
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    LEFT JOIN users u_sender ON d.sender_id = u_sender.id
    WHERE d.receiver_id = ?
    AND d.status = 'pending'
    ORDER BY d.created_at DESC
    LIMIT ? OFFSET ?
");
if ($stmt) {
    $stmt->bind_param("iii", $dean_id, $items_per_page, $offset_decisions);
    $stmt->execute();
    $decisions_result = $stmt->get_result();
    $decisions_data = [];
    while ($row = $decisions_result->fetch_assoc()) {
        $decisions_data[] = $row;
    }
    $stmt->close();

    // Fetch stereotypes for each complaint
    foreach ($decisions_data as $decision) {
        $complaint_id = $decision['complaint_id'];
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
            $decision['stereotypes'] = $stereotypes;
            $received_decisions[] = $decision;
        }
    }
} else {
    error_log("Error preparing received decisions query: " . $db->error);
    $_SESSION['error'] = "Database error fetching received decisions.";
}

// Pagination for Processed Decisions by College Dean
$page_processed = isset($_GET['page_processed']) ? max(1, (int)$_GET['page_processed']) : 1;
$offset_processed = ($page_processed - 1) * $items_per_page;

// Fetch total processed decisions for pagination
$sql_count_processed = "
    SELECT COUNT(*) as total
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    WHERE d.receiver_id = ?
    AND d.status = 'final'";
$stmt_count_processed = $db->prepare($sql_count_processed);
$total_processed = 0;
if ($stmt_count_processed) {
    $stmt_count_processed->bind_param("i", $dean_id);
    $stmt_count_processed->execute();
    $result = $stmt_count_processed->get_result();
    $row = $result->fetch_assoc();
    $total_processed = $row ? $row['total'] : 0;
    $stmt_count_processed->close();
}

$total_pages_processed = max(1, ceil($total_processed / $items_per_page));

// Fetch processed decisions by this College Dean
$processed_decisions = [];
$stmt = $db->prepare("
    SELECT d.id as decision_id, d.complaint_id, d.decision_text, d.created_at,
           c.title, c.description, c.category, c.status as complaint_status,
           u_sender.fname as sender_fname, u_sender.lname as sender_lname, u_sender.role as sender_role
    FROM decisions d
    JOIN complaints c ON d.complaint_id = c.id
    LEFT JOIN users u_sender ON d.sender_id = u_sender.id
    WHERE d.receiver_id = ?
    AND d.status = 'final'
    ORDER BY d.created_at DESC
    LIMIT ? OFFSET ?
");
if ($stmt) {
    $stmt->bind_param("iii", $dean_id, $items_per_page, $offset_processed);
    $stmt->execute();
    $processed_result = $stmt->get_result();
    $processed_data = [];
    while ($row = $processed_result->fetch_assoc()) {
        $processed_data[] = $row;
    }
    $stmt->close();

    // Fetch stereotypes for each complaint
    foreach ($processed_data as $decision) {
        $complaint_id = $decision['complaint_id'];
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
            $decision['stereotypes'] = $stereotypes;
            $processed_decisions[] = $decision;
        }
    }
} else {
    error_log("Error preparing processed decisions query: " . $db->error);
    $_SESSION['error'] = "Database error fetching processed decisions.";
}

// Fetch committees the college dean is a member of
$sql_committees = "
    SELECT cm.committee_id, co.name AS committee_name, c.id AS complaint_id, c.title AS complaint_title
    FROM committee_members cm
    JOIN committees co ON cm.committee_id = co.id
    JOIN complaints c ON c.committee_id = cm.committee_id
    WHERE cm.user_id = ?";
$stmt_committees = $db->prepare($sql_committees);
$committees = [];
if ($stmt_committees) {
    $stmt_committees->bind_param("i", $dean_id);
    $stmt_committees->execute();
    $result_committees = $stmt_committees->get_result();
    while ($row = $result_committees->fetch_assoc()) {
        $committees[] = $row;
    }
    $stmt_committees->close();
} else {
    error_log("Error preparing committees query: " . $db->error);
}

// Fetch notification count
$notification_count = 0;
$notif_stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($notif_stmt) {
    $notif_stmt->bind_param("i", $dean_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result()->fetch_assoc();
    $notification_count = $notif_result ? $notif_result['count'] : 0;
    $notif_stmt->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// Fetch Summary Statistics
$stats = [
    'pending_escalated' => $total_escalated,
    'resolved_by_dean' => 0,
    'pending_decisions' => $total_decisions,
    'processed_decisions' => $total_processed, // NEW: Added stat for processed decisions
];

// Count complaints resolved by this dean
$sql_stats_resolved = "
    SELECT COUNT(DISTINCT e.complaint_id) as count
    FROM escalations e
    WHERE e.escalated_to = 'college_dean' AND e.escalated_to_id = ? AND e.status = 'resolved'";
$stmt_stats_resolved = $db->prepare($sql_stats_resolved);
if ($stmt_stats_resolved) {
    $stmt_stats_resolved->bind_param("i", $dean_id);
    $stmt_stats_resolved->execute();
    $result_stats_resolved = $stmt_stats_resolved->get_result();
    $row = $result_stats_resolved->fetch_assoc();
    $stats['resolved_by_dean'] = $row ? $row['count'] : 0;
    $stmt_stats_resolved->close();
} else {
    error_log("Error preparing resolved count query: " . $db->error);
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College Dean Dashboard | DMU Complaint System</title>
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
        .stat-card.decisions { border-left-color: var(--info); }
        .stat-card.decisions i { color: var(--info); }
        .stat-card.processed { border-left-color: var(--purple); }
        .stat-card.processed i { color: var(--purple); }

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

        td.description, td.decision-text {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        td.description[title]:hover, td.decision-text[title]:hover {
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
            td.description, td.decision-text { max-width: 100%; white-space: normal; }
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
            <?php if ($dean): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dean['fname'] . ' ' . $dean['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $dean['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>College Dean</h4>
                    <p>Role: College Dean</p>
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

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - College Dean</span>
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
            <h2>Welcome, <?php echo $dean ? htmlspecialchars($dean['fname'] . ' ' . $dean['lname']) : 'College Dean'; ?>!</h2>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card pending">
                    <i class="fas fa-hourglass-half"></i>
                    <div class="number"><?php echo $stats['pending_escalated']; ?></div>
                    <div class="label">Pending Escalated Complaints</div>
                </div>
                <div class="stat-card resolved">
                    <i class="fas fa-check-double"></i>
                    <div class="number"><?php echo $stats['resolved_by_dean']; ?></div>
                    <div class="label">Complaints Resolved by You</div>
                </div>
                <div class="stat-card decisions">
                    <i class="fas fa-envelope-open-text"></i>
                    <div class="number"><?php echo $stats['pending_decisions']; ?></div>
                    <div class="label">Pending Handler Responses</div>
                </div>
                <div class="stat-card processed">
                    <i class="fas fa-check-square"></i>
                    <div class="number"><?php echo $stats['processed_decisions']; ?></div>
                    <div class="label">Processed Handler Responses</div>
                </div>
            </div>

            <!-- Responses from Handlers Section -->
            <div class="decisions-list">
                <h3>Responses from Handlers</h3>
                <p style="color: var(--gray); margin-bottom: 1rem;">
                    This section shows responses sent by handlers for complaints you have reviewed. You can resolve, send back, or escalate these complaints.
                </p>
                <?php if (!empty($received_decisions)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Handler Response</th>
                                    <th>Category</th>
                                    <th>Stereotypes</th>
                                    <th>Status</th>
                                    <th>Sent By</th>
                                    <th>Received On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($received_decisions as $decision): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $decision['complaint_id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($decision['title']); ?></td>
                                        <td data-label="Handler Response" class="decision-text" title="<?php echo htmlspecialchars($decision['decision_text']); ?>">
                                            <?php echo htmlspecialchars($decision['decision_text']); ?>
                                        </td>
                                        <td data-label="Category">
                                            <?php echo !empty($decision['category']) ? htmlspecialchars(ucfirst($decision['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                                        </td>
                                        <td data-label="Stereotypes">
                                            <?php
                                            if (!empty($decision['stereotypes'])) {
                                                echo htmlspecialchars(implode(', ', array_map('ucfirst', $decision['stereotypes'])));
                                            } else {
                                                echo '<span class="status status-uncategorized">None</span>';
                                            }
                                            ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($decision['complaint_status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['complaint_status']))); ?>
                                            </span>
                                        </td>
                                        <td data-label="Sent By">
                                            <?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']) . ' (' . htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['sender_role']))) . ')'; ?>
                                        </td>
                                        <td data-label="Received On"><?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $decision['complaint_id']; ?>" class="btn btn-info btn-small" title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="decide_complaint.php?complaint_id=<?php echo $decision['complaint_id']; ?>&decision_id=<?php echo $decision['decision_id']; ?>" class="btn btn-purple btn-small" title="Make Decision">
                                                <i class="fas fa-gavel"></i> Decide
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_decisions; $i++): ?>
                            <a href="?page_decisions=<?php echo $i; ?>" class="<?php echo $i == $page_decisions ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No handler responses are currently pending your review.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Processed Handler Responses Section -->
            <div class="processed-decisions-list">
                <h3>Processed Handler Responses</h3>
                <p style="color: var(--gray); margin-bottom: 1rem;">
                    This section shows handler responses you have resolved, sent back, or escalated.
                </p>
                <?php if (!empty($processed_decisions)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Handler Response</th>
                                    <th>Category</th>
                                    <th>Stereotypes</th>
                                    <th>Status</th>
                                    <th>Sent By</th>
                                    <th>Processed On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($processed_decisions as $decision): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $decision['complaint_id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($decision['title']); ?></td>
                                        <td data-label="Handler Response" class="decision-text" title="<?php echo htmlspecialchars($decision['decision_text']); ?>">
                                            <?php echo htmlspecialchars($decision['decision_text']); ?>
                                        </td>
                                        <td data-label="Category">
                                            <?php echo !empty($decision['category']) ? htmlspecialchars(ucfirst($decision['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                                        </td>
                                        <td data-label="Stereotypes">
                                            <?php
                                            if (!empty($decision['stereotypes'])) {
                                                echo htmlspecialchars(implode(', ', array_map('ucfirst', $decision['stereotypes'])));
                                            } else {
                                                echo '<span class="status status-uncategorized">None</span>';
                                            }
                                            ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($decision['complaint_status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['complaint_status']))); ?>
                                            </span>
                                        </td>
                                        <td data-label="Sent By">
                                            <?php echo htmlspecialchars($decision['sender_fname'] . ' ' . $decision['sender_lname']) . ' (' . htmlspecialchars(ucfirst(str_replace('_', ' ', $decision['sender_role']))) . ')'; ?>
                                        </td>
                                        <td data-label="Processed On"><?php echo date('M j, Y H:i', strtotime($decision['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $decision['complaint_id']; ?>" class="btn btn-info btn-small" title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_processed; $i++): ?>
                            <a href="?page_processed=<?php echo $i; ?>" class="<?php echo $i == $page_processed ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-square"></i>
                        <p>You have not processed any handler responses yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pending Complaints Escalated to You -->
            <div class="complaints-list">
                <h3>Pending Complaints Escalated to You</h3>
                <p style="color: var(--gray); margin-bottom: 1rem;">
                    This section shows complaints escalated to you by the Handler or Department Head for action or further escalation.
                </p>
                <?php if (!empty($escalated_complaints) && $academic_vp_id): ?>
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
                                    <th>Escalated By</th>
                                    <th>Submitted On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($escalated_complaints as $complaint): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $complaint['complaint_id']; ?></td>
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
                                            <span class="status status-<?php echo strtolower($complaint['complaint_status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['complaint_status']))); ?>
                                            </span>
                                            <span class="status status-escalated">Escalated</span>
                                        </td>
                                        <td data-label="Escalated By">
                                            <?php echo htmlspecialchars($complaint['sender_fname'] . ' ' . $complaint['sender_lname']) . ' (' . htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['sender_role']))) . ')'; ?>
                                        </td>
                                        <td data-label="Submitted On"><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-info btn-small" title="View Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="decide_complaint.php?complaint_id=<?php echo $complaint['complaint_id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-purple btn-small" title="Make Decision">
                                                <i class="fas fa-gavel"></i> Decide
                                            </a>
                                            <button class="btn btn-warning btn-small escalate-btn" data-complaint-id="<?php echo $complaint['complaint_id']; ?>" title="Escalate to Academic VP">
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
                            <a href="?page_escalated=<?php echo $i; ?>" class="<?php echo $i == $page_escalated ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No complaints are currently escalated to you for review<?php echo $academic_vp_id ? '' : ', or no Academic Vice President is available for escalation.'; ?>.</p>
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
        </div>

        <div id="escalationModal" class="modal">
            <div class="modal-content">
                <span class="close">×</span>
                <h4>Escalate Complaint to Academic Vice President</h4>
                <form id="escalationForm" method="POST" action="">
                    <input type="hidden" name="complaint_id" id="escalationComplaintId">
                    <div class="form-group">
                        <label for="decision_text">Reason for Escalation:</label>
                        <textarea name="decision_text" id="decision_text" required placeholder="Explain why this complaint needs to be escalated to the Academic Vice President..."></textarea>
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
    </div>

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
                }, 5000);
            });

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