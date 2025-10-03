<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is 'sims'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
if ($_SESSION['role'] !== 'sims') {
    header("Location: ../unauthorized.php");
    exit;
}

$sims_id = $_SESSION['user_id'];
$sims_user = null;

// Fetch SIMS user details
$sql_sims = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_sims = $db->prepare($sql_sims);
if ($stmt_sims) {
    $stmt_sims->bind_param("i", $sims_id);
    $stmt_sims->execute();
    $result_sims = $stmt_sims->get_result();
    if ($result_sims->num_rows > 0) {
        $sims_user = $result_sims->fetch_assoc();
    } else {
        $_SESSION['error'] = "SIMS user details not found.";
        header("Location: ../logout.php");
        exit;
    }
    $stmt_sims->close();
} else {
    error_log("Error preparing SIMS query: " . $db->error);
    $_SESSION['error'] = "Database error fetching SIMS user details.";
}

// Fetch the campus registrar (for escalation)
$registrar_query = "SELECT id FROM users WHERE role = 'campus_registrar' LIMIT 1";
$registrar_result = $db->query($registrar_query);
if ($registrar_result && $registrar_result->num_rows > 0) {
    $registrar = $registrar_result->fetch_assoc();
    $campus_registrar_id = $registrar['id'];
} else {
    $campus_registrar_id = null;
    error_log("No campus registrar found in the system.");
}

// Handle decision/escalation form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decide_complaint'])) {
    $complaint_id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT);
    $decision_text = filter_input(INPUT_POST, 'decision_text', FILTER_SANITIZE_STRING);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

    // Validate input
    if (!$complaint_id || $complaint_id <= 0) {
        $_SESSION['error'] = "Invalid complaint ID.";
    } elseif (empty($decision_text)) {
        $_SESSION['error'] = "Decision text is required.";
    } elseif (!in_array($action, ['resolve', 'send_back', 'escalate'])) {
        $_SESSION['error'] = "Invalid action selected.";
    } elseif ($action === 'escalate' && !$campus_registrar_id) {
        $_SESSION['error'] = "Cannot escalate: No Campus Registrar available.";
    } else {
        // Verify complaint exists
        $check_complaint_query = "SELECT id, handler_id FROM complaints WHERE id = ? FOR UPDATE";
        $stmt_check = $db->prepare($check_complaint_query);
        if ($stmt_check) {
            $stmt_check->bind_param("i", $complaint_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows === 0) {
                error_log("Invalid complaint_id submitted: $complaint_id");
                $_SESSION['error'] = "Invalid complaint: The complaint does not exist.";
                $stmt_check->close();
                header("Location: dashboard.php");
                exit;
            }
            $complaint_data = $result_check->fetch_assoc();
            $original_handler_id = $complaint_data['handler_id'];
            $stmt_check->close();
        } else {
            error_log("Error preparing complaint check query: " . $db->error);
            $_SESSION['error'] = "Database error while verifying complaint.";
            header("Location: dashboard.php");
            exit;
        }

        $handler_id = null;
        if ($action === 'send_back') {
            // Use the original handler_id if available, otherwise find a new handler
            if ($original_handler_id) {
                $handler_id = $original_handler_id;
            } else {
                $handler_query = "SELECT id FROM users WHERE role = 'handler' LIMIT 1";
                $handler_result = $db->query($handler_query);
                if ($handler_result && $handler_result->num_rows > 0) {
                    $handler = $handler_result->fetch_assoc();
                    $handler_id = $handler['id'];
                } else {
                    $_SESSION['error'] = "No handler available to send back the complaint.";
                    header("Location: dashboard.php");
                    exit;
                }
            }
        }

        $receiver_id = ($action === 'escalate') ? $campus_registrar_id : ($action === 'send_back' ? $handler_id : null);
        $status = ($action === 'resolve') ? 'resolved' : 'pending';

        // Validate receiver_id if not null
        if ($receiver_id !== null) {
            $check_user_query = "SELECT id FROM users WHERE id = ?";
            $stmt_user_check = $db->prepare($check_user_query);
            if ($stmt_user_check) {
                $stmt_user_check->bind_param("i", $receiver_id);
                $stmt_user_check->execute();
                $result_user_check = $stmt_user_check->get_result();
                if ($result_user_check->num_rows === 0) {
                    error_log("Invalid receiver_id: $receiver_id");
                    $_SESSION['error'] = "Invalid receiver: The specified user does not exist.";
                    $stmt_user_check->close();
                    header("Location: dashboard.php");
                    exit;
                }
                $stmt_user_check->close();
            } else {
                error_log("Error preparing user check query: " . $db->error);
                $_SESSION['error'] = "Database error while verifying receiver.";
                header("Location: dashboard.php");
                exit;
            }
        }

        // Process the action within a transaction
        $db->begin_transaction();
        try {
            // Insert decision
            $insert_query = "INSERT INTO decisions (complaint_id, sender_id, receiver_id, decision_text, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = $db->prepare($insert_query);
            if (!$stmt) {
                throw new Exception("Error preparing decision insert query: " . $db->error);
            }
            $stmt->bind_param("iiiss", $complaint_id, $sims_id, $receiver_id, $decision_text, $status);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert decision for complaint #$complaint_id: " . $stmt->error);
            }
            $stmt->close();

            // Update complaint status
            $complaint_status = ($action === 'resolve') ? 'resolved' : 'in_progress';
            if ($action === 'send_back') {
                $complaint_status = 'pending_more_info';
                $update_complaint_query = "UPDATE complaints SET status = ?, handler_id = ? WHERE id = ?";
                $stmt_update = $db->prepare($update_complaint_query);
                if (!$stmt_update) {
                    throw new Exception("Error preparing complaint update query: " . $db->error);
                }
                $stmt_update->bind_param("sii", $complaint_status, $handler_id, $complaint_id);
            } else {
                $update_complaint_query = "UPDATE complaints SET status = ? WHERE id = ?";
                $stmt_update = $db->prepare($update_complaint_query);
                if (!$stmt_update) {
                    throw new Exception("Error preparing complaint update query: " . $db->error);
                }
                $stmt_update->bind_param("si", $complaint_status, $complaint_id);
            }
            if (!$stmt_update->execute()) {
                throw new Exception("Failed to update complaint #$complaint_id: " . $stmt_update->error);
            }
            $stmt_update->close();

                        // Update escalation
                        $update_escalation_query = "UPDATE escalations SET status = ?, escalated_to = ?, escalated_to_id = ? WHERE complaint_id = ? AND escalated_to = 'sims' AND escalated_to_id = ?";
                        $stmt_update = $db->prepare($update_escalation_query);
                        if (!$stmt_update) {
                            throw new Exception("Error preparing escalation update query: " . $db->error);
                        }
                        $new_status = ($action === 'escalate') ? 'escalated' : ($action === 'resolve' ? 'resolved' : 'pending');
                        $new_role = ($action === 'escalate') ? 'campus_registrar' : ($action === 'send_back' ? 'handler' : null);
                        $new_id = ($action === 'escalate') ? $campus_registrar_id : ($action === 'send_back' ? $handler_id : null);
            
                        // Handle NULL values for escalated_to and escalated_to_id
                        $escalated_to_param = $new_role ?? null;
                        $escalated_to_id_param = $new_id ?? null;
            
                        $stmt_update->bind_param("ssiii", $new_status, $escalated_to_param, $escalated_to_id_param, $complaint_id, $sims_id);
                        if (!$stmt_update->execute()) {
                            throw new Exception("Failed to update escalation for complaint #$complaint_id: " . $stmt_update->error);
                        }
                        $stmt_update->close();
            
                        // Notify receiver
                        if ($receiver_id) {
                            $notification_query = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
                            $notification_desc = ($action === 'escalate') ? "Complaint #$complaint_id escalated to you by SIMS." : "Complaint #$complaint_id sent back to you by SIMS with more info requested.";
                            $stmt_notify = $db->prepare($notification_query);
                            if (!$stmt_notify) {
                                throw new Exception("Error preparing notification query: " . $db->error);
                            }
                            $stmt_notify->bind_param("iis", $receiver_id, $complaint_id, $notification_desc);
                            if (!$stmt_notify->execute()) {
                                throw new Exception("Failed to insert notification for user #$receiver_id: " . $stmt_notify->error);
                            }
                            $stmt_notify->close();
                        }
            $db->commit();
            $_SESSION['success'] = "Complaint action processed successfully.";
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error processing complaint #$complaint_id: " . $e->getMessage());
            $_SESSION['error'] = "Failed to process complaint action: " . htmlspecialchars($e->getMessage());
        }
    }
    header("Location: dashboard.php");
    exit;
}

// Pagination for Assigned Complaints
$items_per_page = 5;
$page_assigned = isset($_GET['page_assigned']) ? max(1, (int)$_GET['page_assigned']) : 1;
$offset_assigned = ($page_assigned - 1) * $items_per_page;

$sql_count_assigned = "SELECT COUNT(*) as total FROM complaints c JOIN escalations e ON c.id = e.complaint_id WHERE e.escalated_to = 'sims' AND e.escalated_to_id = ? AND e.status = 'pending'";
$stmt_count_assigned = $db->prepare($sql_count_assigned);
$total_assigned = 0;
if ($stmt_count_assigned) {
    $stmt_count_assigned->bind_param("i", $sims_id);
    $stmt_count_assigned->execute();
    $result = $stmt_count_assigned->get_result();
    $total_assigned = $result->fetch_assoc()['total'] ?? 0;
    $stmt_count_assigned->close();
}
$total_pages_assigned = max(1, ceil($total_assigned / $items_per_page));

$sql_assigned = "SELECT c.*, u_submitter.fname as submitter_fname, u_submitter.lname as submitter_lname, e.id as escalation_id, e.status as escalation_status, e.action_type, e.escalated_to, e.escalated_to_id,
                (SELECT d.decision_text FROM decisions d WHERE d.complaint_id = c.id AND d.receiver_id = ? ORDER BY d.created_at DESC LIMIT 1) as latest_decision
                 FROM complaints c 
                 JOIN escalations e ON c.id = e.complaint_id 
                 LEFT JOIN users u_submitter ON c.user_id = u_submitter.id
                 WHERE e.escalated_to = 'sims' AND e.escalated_to_id = ? AND e.status = 'pending' 
                 ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
$stmt_assigned = $db->prepare($sql_assigned);
$assigned_complaints = [];
if ($stmt_assigned) {
    $stmt_assigned->bind_param("iiii", $sims_id, $sims_id, $items_per_page, $offset_assigned);
    $stmt_assigned->execute();
    $result = $stmt_assigned->get_result();
    $assigned_complaints_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt_assigned->close();

    foreach ($assigned_complaints_data as &$complaint) {
        $sql_stereotypes = "SELECT s.label FROM complaint_stereotypes cs JOIN stereotypes s ON cs.stereotype_id = s.id WHERE cs.complaint_id = ?";
        $stmt = $db->prepare($sql_stereotypes);
        $stmt->bind_param("i", $complaint['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $complaint['stereotypes'] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $assigned_complaints[] = $complaint;
    }
}

// Stats
$stats = [
    'pending_assigned' => $total_assigned,
    'resolved_by_me' => 0,
    'sent_back_pending' => 0,
    'escalated_by_me' => 0
];

$sql_stats_resolved = "SELECT COUNT(DISTINCT c.id) as count FROM complaints c JOIN decisions d ON c.id = d.complaint_id WHERE c.status = 'resolved' AND d.sender_id = ? AND d.id = (SELECT MAX(id) FROM decisions WHERE complaint_id = c.id)";
$stmt_stats_resolved = $db->prepare($sql_stats_resolved);
if ($stmt_stats_resolved) {
    $stmt_stats_resolved->bind_param("i", $sims_id);
    $stmt_stats_resolved->execute();
    $stats['resolved_by_me'] = $stmt_stats_resolved->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt_stats_resolved->close();
}

$sql_stats_sent_back = "SELECT COUNT(*) as total FROM complaints c JOIN decisions d ON c.id = d.complaint_id JOIN users u ON d.receiver_id = u.id WHERE d.sender_id = ? AND d.status = 'pending' AND u.role = 'handler'";
$stmt_stats_sent_back = $db->prepare($sql_stats_sent_back);
if ($stmt_stats_sent_back) {
    $stmt_stats_sent_back->bind_param("i", $sims_id);
    $stmt_stats_sent_back->execute();
    $stats['sent_back_pending'] = $stmt_stats_sent_back->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt_stats_sent_back->close();
}

$sql_stats_escalated = "SELECT COUNT(DISTINCT d.id) as count FROM decisions d JOIN escalations e ON d.complaint_id = e.complaint_id WHERE d.sender_id = ? AND e.escalated_to = 'campus_registrar' AND e.status = 'escalated'";
$stmt_stats_escalated = $db->prepare($sql_stats_escalated);
if ($stmt_stats_escalated) {
    $stmt_stats_escalated->bind_param("i", $sims_id);
    $stmt_stats_escalated->execute();
    $stats['escalated_by_me'] = $stmt_stats_escalated->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt_stats_escalated->close();
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIMS Dashboard | DMU Complaint System</title>
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
            --shadow-hover: 0 8px 25px rgba(0, 0, 0, 0.15);
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
            transition: var(--transition);
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
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
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
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
            transition: var(--transition);
        }

        .horizontal-nav:hover {
            box-shadow: var(--shadow-hover);
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
            transform: scale(1.05);
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .alert::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--success);
        }

        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-success::before { background: var(--success); }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }
        .alert-danger::before { background: var(--danger); }
        .alert-warning { background-color: #fff3cd; border-color: #ffecb5; color: #664d03; }
        .alert-warning::before { background: var(--warning); }
        .alert-info { background-color: #cff4fc; border-color: #b6effb; color: #055160; }
        .alert-info::before { background: var(--info); }

        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
            flex-grow: 1;
            transition: var(--transition);
        }

        .content-container:hover {
            box-shadow: var(--shadow-hover);
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
            display: inline-block;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
            border-left: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.1), rgba(0, 0, 0, 0.05));
            opacity: 0;
            transition: var(--transition);
        }

        .summary-card:hover::before {
            opacity: 1;
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
            transition: var(--transition);
        }

        .summary-card:hover i {
            transform: scale(1.1);
        }

        .summary-card.pending_assigned i { color: var(--warning); }
        .summary-card.resolved_by_me i { color: var(--success); }
        .summary-card.decisions_sent_pending i { color: var(--orange); }
        .summary-card.escalated_by_me i { color: var(--purple); }

        .summary-card h4 {
            font-size: 1rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
        }

        .summary-card p {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .profile-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 1.5rem;
            background: linear-gradient(145deg, #f9f9f9, #e9ecef);
            border-radius: var(--radius);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
        }

        .profile-card:hover {
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .profile-icon i {
            font-size: 3rem;
            color: var(--primary);
            transition: var(--transition);
        }

        .profile-card:hover .profile-icon i {
            transform: rotate(10deg);
        }

        .profile-details p {
            margin: 5px 0;
            font-size: 0.95rem;
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
        }

        th, td {
            padding: 1.2rem;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        th {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th:first-child {
            border-top-left-radius: var(--radius);
        }

        th:last-child {
            border-top-right-radius: var(--radius);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background: #e8f4ff;
            transform: scale(1.01);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        td.description, td.decision-text {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }

        td.description:hover, td.decision-text:hover {
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            background: #f0f4ff;
            border-radius: 5px;
            padding: 0.8rem;
        }

        .status {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: capitalize;
            transition: var(--transition);
        }

        .status:hover {
            transform: scale(1.05);
        }

        .status-pending { background-color: rgba(255, 193, 7, 0.15); color: var(--warning); }
        .status-pending_more_info { background-color: rgba(23, 162, 184, 0.15); color: var(--info); }
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%); }
        .btn-info { background: var(--info); color: white; }
        .btn-info:hover { background: #0baccc; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: var(--warning); color: var(--dark); }
        .btn-warning:hover { background: #e0a800; }
        .btn-accent { background: var(--accent); color: white; }
        .btn-accent:hover { background: #3abde0; }
        .btn-purple { background: var(--secondary); color: white; }
        .btn-purple:hover { background: #5a0a92; }
        .btn-orange { background: var(--orange); color: white; }
        .btn-orange:hover { background: #e06c00; }
        .btn-small { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        .tooltip {
            position: relative;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 120px;
            background-color: var(--dark);
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }

        .modal-content {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 550px;
            position: relative;
            animation: slideIn 0.3s ease-out;
            border: 1px solid var(--light-gray);
        }

        @keyframes slideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content h4 {
            margin-bottom: 1.5rem;
            color: var(--primary-dark);
            font-size: 1.5rem;
            text-align: center;
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
            transform: rotate(90deg);
        }

        .modal-content form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .modal-content .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .modal-content label {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--dark);
        }

        .modal-content textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            resize: vertical;
            min-height: 120px;
            transition: var(--transition);
        }

        .modal-content textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
        }

        .modal-content select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .modal-content select:focus {
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
            font-weight: 500;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            transform |: scale(1.05);
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
            background: linear-gradient(145deg, #f9f9f9, #e9ecef);
            border-radius: var(--radius);
            margin-top: 1rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
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
            .vertical-nav { 
                width: 100%; 
                height: auto; 
                position: relative; 
                overflow-y: hidden; 
                padding: 15px 0;
            }
            .main-content { min-height: auto; }
            .horizontal-nav { 
                flex-direction: column; 
                gap: 10px; 
                padding: 10px;
            }
            .horizontal-menu { 
                flex-wrap: wrap; 
                justify-content: center; 
            }
            .content-container { padding: 1.5rem; }
            h2 { font-size: 1.5rem; }
            h3 { font-size: 1.2rem; }
            .summary-cards { 
                grid-template-columns: 1fr; 
                gap: 1rem;
            }
            .summary-card {
                padding: 1rem;
            }
            .summary-card i {
                font-size: 1.8rem;
            }
            .summary-card p {
                font-size: 1.3rem;
            }

            .table-responsive {
                border-radius: 0;
            }

            .table-responsive table,
            .table-responsive thead,
            .table-responsive tbody,
            .table-responsive th,
            .table-responsive td,
            .table-responsive tr { 
                display: block; 
            }
            .table-responsive thead tr { 
                position: absolute; 
                top: -9999px; 
                left: -9999px; 
            }
            .table-responsive tr { 
                margin-bottom: 15px; 
                border: 1px solid #ddd; 
                border-radius: 5px; 
                background-color: white; 
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            }
            .table-responsive tr:nth-child(even) { 
                background-color: white; 
            }
            .table-responsive td { 
                border: none; 
                border-bottom: 1px solid #eee; 
                position: relative; 
                padding-left: 50% !important; 
                text-align: left; 
                white-space: normal; 
                min-height: 30px; 
                display: flex; 
                align-items: center; 
            }
            .table-responsive td:before { 
                content: attr(data-label); 
                position: absolute; 
                left: 15px; 
                width: 45%; 
                padding-right: 10px; 
                font-weight: bold; 
                color: var(--primary); 
                white-space: normal; 
                text-align: left; 
            }
            .table-responsive td:last-child { 
                border-bottom: none; 
            }
        }

        @media (max-width: 576px) {
            .content-container { padding: 1.25rem; }
            h2 { font-size: 1.3rem; }
            .profile-card { 
                flex-direction: column; 
                text-align: center; 
                padding: 1rem;
            }
            .profile-icon i {
                font-size: 2.5rem;
            }
            td.description, td.decision-text { 
                max-width: 100%; 
                white-space: normal; 
            }
            .btn, .btn-small { 
                width: 100%; 
                margin-bottom: 5px; 
            }
            .table-responsive td .btn, .table-responsive td .btn-small { 
                width: auto; 
                margin-bottom: 0;
            }
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
            .modal-content {
                padding: 1.5rem;
                max-width: 90%;
            }
            .modal-content h4 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU RCS</span>
            </div>
            <?php if ($sims_user): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-cog"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($sims_user['fname'] . ' ' . $sims_user['lname']); ?></h4>
                    <p>SIMS</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard Overview</span>
            </a>
            <h3>Complaint Management</h3>
            <a href="view_assigned.php" class="nav-link <?php echo $current_page == 'view_assigned.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i><span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i><span>Resolved Complaints</span>
            </a>
            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i><span>Notifications</span>
            </a>
            <h3>Account</h3>
            <a href="edit_profile.php" class="nav-link <?php echo $current_page == 'edit_profile.php' ? 'active' : ''; ?>">
                <i class="fas fa-key"></i><span>Edit Profile</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Registrar Complaint System - SIMS</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <div class="content-container">
            <h2>Welcome, <?php echo htmlspecialchars($sims_user['fname'] . ' ' . $sims_user['lname']); ?>!</h2>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

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
                    <h4>Sent Back (Pending)</h4>
                    <p><?php echo $stats['sent_back_pending']; ?></p>
                </div>
                <div class="summary-card escalated_by_me">
                    <i class="fas fa-arrow-up"></i>
                    <h4>Escalated By You</h4>
                    <p><?php echo $stats['escalated_by_me']; ?></p>
                </div>
            </div>

            <?php if ($sims_user): ?>
            <div class="user-profile">
                <h3>Your Profile</h3>
                <div class="profile-card">
                    <div class="profile-icon">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <div class="profile-details">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($sims_user['fname'] . ' ' . $sims_user['lname']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($sims_user['email']); ?></p>
                        <p><strong>Role:</strong> SIMS</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="complaints-list">
                <h3>Complaints Assigned To You (Pending Decision)</h3>
                <?php if (!empty($assigned_complaints) && $campus_registrar_id): ?>
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
                                    <th>Latest Decision</th>
                                    <th>Submitted On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assigned_complaints as $complaint): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $complaint['id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
                                        <td data-label="Description" class="description" title="<?php echo htmlspecialchars($complaint['description']); ?>"><?php echo htmlspecialchars($complaint['description']); ?></td>
                                        <td data-label="Category"><?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?></td>
                                        <td data-label="Stereotypes"><?php echo !empty($complaint['stereotypes']) ? htmlspecialchars(implode(', ', array_column($complaint['stereotypes'], 'label'))) : '<span class="status status-uncategorized">None</span>'; ?></td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($complaint['status']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?></span>
                                            <span class="status status-assigned">Assigned</span>
                                        </td>
                                        <td data-label="Latest Decision" class="decision-text">
                                            <?php echo !empty($complaint['latest_decision']) ? htmlspecialchars($complaint['latest_decision']) : '<span class="status status-uncategorized">None</span>'; ?>
                                        </td>
                                        <td data-label="Submitted On"><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-info btn-small tooltip"><i class="fas fa-eye"></i> View<span class="tooltiptext">View complaint details</span></a>
                                            <button class="btn btn-purple btn-small decide-btn tooltip" data-complaint-id="<?php echo $complaint['id']; ?>"><i class="fas fa-gavel"></i> Decide<span class="tooltiptext">Make a decision</span></button>
                                            <?php if (isset($complaint['escalation_status'], $complaint['escalated_to'], $complaint['escalated_to_id']) && $complaint['escalation_status'] === 'pending' && $complaint['escalated_to'] === 'sims' && $complaint['escalated_to_id'] == $sims_id): ?>
                                                <a href="escalate_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&escalation_id=<?php echo $complaint['escalation_id']; ?>" class="btn btn-orange btn-small tooltip"><i class="fas fa-arrow-up"></i> Escalate<span class="tooltiptext">Escalate to Campus Registrar</span></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages_assigned; $i++): ?>
                            <a href="?page_assigned=<?php echo $i; ?>" class="<?php echo $i == $page_assigned ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No complaints are currently assigned to you<?php echo $campus_registrar_id ? '' : ', or no Campus Registrar is available for escalation.'; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="decisionModal" class="modal">
            <div class="modal-content">
                <span class="close" aria-label="Close modal">&times;</span>
                <h4>Process Complaint</h4>
                <form id="decisionForm" method="POST" action="">
                    <input type="hidden" name="complaint_id" id="decisionComplaintId" aria-label="Complaint ID">
                    <div class="form-group">
                        <label for="decision_text">Decision/Reason:</label>
                        <textarea name="decision_text" id="decision_text" required placeholder="Enter your decision or reason for action..." aria-required="true" aria-describedby="decision_text_help"></textarea>
                        <small id="decision_text_help" class="form-text">Provide a detailed reason for your decision.</small>
                    </div>
                    <div class="form-group">
                        <label for="action">Action:</label>
                        <select name="action" id="action" required aria-required="true">
                            <option value="" disabled selected>Select action...</option>
                            <option value="resolve">Resolve Complaint</option>
                            <option value="send_back">Send Back to Handler</option>
                            <option value="escalate">Escalate to Campus Registrar</option>
                        </select>
                    </div>
                    <button type="submit" name="decide_complaint" class="btn btn-purple">Submit Action</button>
                </form>
            </div>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Registrar Complaint System. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.summary-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'fadeIn 0.4s ease-out forwards';
                card.style.opacity = '0';
            });

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 7000);
            });

            const modal = document.getElementById('decisionModal');
            const decideButtons = document.querySelectorAll('.decide-btn');
            const closeBtn = document.querySelector('.modal-content .close');
            const complaintIdInput = document.getElementById('decisionComplaintId');
            const form = document.getElementById('decisionForm');
            const decisionText = document.getElementById('decision_text');

            decideButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const complaintId = this.getAttribute('data-complaint-id');
                    if (!complaintId || isNaN(complaintId) || complaintId <= 0) {
                        alert('Invalid complaint ID. Please try again.');
                        return;
                    }
                    complaintIdInput.value = complaintId;
                    modal.style.display = 'flex';
                    decisionText.focus(); // Focus on the textarea for better accessibility
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

            window.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && modal.style.display === 'flex') {
                    modal.style.display = 'none';
                    form.reset();
                }
            });

            // Prevent form submission with invalid complaint_id
            form.addEventListener('submit', function(event) {
                const complaintId = complaintIdInput.value;
                if (!complaintId || isNaN(complaintId) || complaintId <= 0) {
                    event.preventDefault();
                    alert('Invalid complaint ID. Please select a valid complaint.');
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