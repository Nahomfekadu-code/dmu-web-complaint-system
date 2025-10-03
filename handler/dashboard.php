<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    die("Database connection error. Please try again later.");
}

if ($_SESSION['role'] != 'handler') {
    header("Location: ../unauthorized.php");
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null;

// Fetch handler details
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler && $result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
    } else {
        error_log("Handler details not found for ID: " . $handler_id);
        $_SESSION['error'] = "Could not retrieve your details. Please contact support.";
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching your details.";
}

// Pagination and Filtering
$items_per_page = 5;

// Assigned Complaints Pagination & Filtering
$page_assigned = isset($_GET['page_assigned']) ? max(1, (int)$_GET['page_assigned']) : 1;
$offset_assigned = ($page_assigned - 1) * $items_per_page;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';

// Unassigned Complaints Pagination
$page_unassigned = isset($_GET['page_unassigned']) ? max(1, (int)$_GET['page_unassigned']) : 1;
$offset_unassigned = ($page_unassigned - 1) * $items_per_page;

// Decided Complaints Pagination
$page_decided = isset($_GET['page_decided']) ? max(1, (int)$_GET['page_decided']) : 1;
$offset_decided = ($page_decided - 1) * $items_per_page;

// Assigned Complaints
$assigned_complaints = [];
$total_assigned = 0;
$total_pages_assigned = 1;

$sql_count_assigned = "SELECT COUNT(c.id) as total
                       FROM complaints c
                       WHERE c.handler_id = ?";
$params_count_assigned = [$handler_id];
$types_count_assigned = "i";

$sql_assigned = "SELECT c.id, c.title, c.description, c.category, c.directorate, c.status, c.created_at,
                        c.needs_committee, c.committee_id, c.needs_video_chat,
                        u.fname as submitter_fname, u.lname as submitter_lname,
                        e.id as escalation_id, e.escalated_to, e.status as escalation_status, e.action_type,
                        (SELECT d.decision_text FROM decisions d WHERE d.complaint_id = c.id AND d.receiver_id = ?
                         ORDER BY d.created_at DESC LIMIT 1) as latest_decision
                 FROM complaints c
                 LEFT JOIN users u ON c.user_id = u.id
                 LEFT JOIN escalations e ON c.id = e.complaint_id AND e.id = (
                     SELECT MAX(id) FROM escalations WHERE complaint_id = c.id
                 )
                 WHERE c.handler_id = ?";
$params_assigned = [$handler_id, $handler_id];
$types_assigned = "ii";

if ($status_filter && in_array($status_filter, ['pending', 'in_progress', 'escalated', 'resolved', 'rejected'])) {
    $sql_count_assigned .= " AND c.status = ?";
    $sql_assigned .= " AND c.status = ?";
    $params_count_assigned[] = $status_filter;
    $params_assigned[] = $status_filter;
    $types_count_assigned .= "s";
    $types_assigned .= "s";
}
if ($category_filter && in_array($category_filter, ['academic', 'administrative'])) {
    $sql_count_assigned .= " AND c.category = ?";
    $sql_assigned .= " AND c.category = ?";
    $params_count_assigned[] = $category_filter;
    $params_assigned[] = $category_filter;
    $types_count_assigned .= "s";
    $types_assigned .= "s";
}

$sql_assigned .= " ORDER BY FIELD(c.status, 'pending', 'in_progress', 'escalated', 'resolved', 'rejected'), c.created_at DESC LIMIT ? OFFSET ?";
$params_assigned[] = $items_per_page;
$params_assigned[] = $offset_assigned;
$types_assigned .= "ii";

// Count Assigned Complaints
$stmt_count_assigned = $db->prepare($sql_count_assigned);
if ($stmt_count_assigned) {
    $stmt_count_assigned->bind_param($types_count_assigned, ...$params_count_assigned);
    $stmt_count_assigned->execute();
    $result_count = $stmt_count_assigned->get_result();
    $total_assigned = $result_count->fetch_assoc()['total'] ?? 0;
    $stmt_count_assigned->close();
} else {
    error_log("Error preparing count assigned query: " . $db->error);
    $_SESSION['error'] = "Database error counting assigned complaints.";
}
$total_pages_assigned = max(1, ceil($total_assigned / $items_per_page));

// Fetch Assigned Complaints
$stmt_assigned = $db->prepare($sql_assigned);
if ($stmt_assigned) {
    $stmt_assigned->bind_param($types_assigned, ...$params_assigned);
    $stmt_assigned->execute();
    $result_assigned = $stmt_assigned->get_result();
    while ($row = $result_assigned->fetch_assoc()) {
        $assigned_complaints[] = $row;
    }
    $stmt_assigned->close();
} else {
    error_log("Error preparing assigned complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching assigned complaints.";
}

// Unassigned Complaints
$unassigned_complaints = [];
$total_unassigned = 0;
$total_pages_unassigned = 1;

// Count Unassigned
$sql_count_unassigned = "SELECT COUNT(*) as total FROM complaints WHERE handler_id IS NULL AND status = 'pending'";
$result_count_unassigned = $db->query($sql_count_unassigned);
if ($result_count_unassigned) {
    $total_unassigned = $result_count_unassigned->fetch_assoc()['total'] ?? 0;
} else {
    error_log("Error counting unassigned complaints: " . $db->error);
}
$total_pages_unassigned = max(1, ceil($total_unassigned / $items_per_page));

// Fetch Unassigned
$sql_unassigned = "SELECT c.id, c.title, c.description, c.category, c.directorate, c.created_at,
                         u.fname as submitter_fname, u.lname as submitter_lname
                  FROM complaints c
                  LEFT JOIN users u ON c.user_id = u.id
                  WHERE c.handler_id IS NULL AND c.status = 'pending'
                  ORDER BY c.created_at ASC
                  LIMIT ? OFFSET ?";
$stmt_unassigned = $db->prepare($sql_unassigned);
if ($stmt_unassigned) {
    $stmt_unassigned->bind_param("ii", $items_per_page, $offset_unassigned);
    $stmt_unassigned->execute();
    $result_unassigned = $stmt_unassigned->get_result();
    while ($row = $result_unassigned->fetch_assoc()) {
        $unassigned_complaints[] = $row;
    }
    $stmt_unassigned->close();
} else {
    error_log("Error preparing unassigned complaints query: " . $db->error);
    $_SESSION['error'] = "Database error fetching unassigned complaints.";
}

// Complaints with Decisions Received
$decided_complaints = [];
$total_decided = 0;
$total_pages_decided = 1;

// Count Decided Complaints
$sql_count_decided = "SELECT COUNT(DISTINCT d.id) as total
                      FROM decisions d
                      JOIN complaints c ON d.complaint_id = c.id
                      WHERE d.receiver_id = ? AND c.handler_id = ?";
$stmt_count_decided = $db->prepare($sql_count_decided);
if ($stmt_count_decided) {
    $stmt_count_decided->bind_param("ii", $handler_id, $handler_id);
    $stmt_count_decided->execute();
    $result_count_decided = $stmt_count_decided->get_result();
    $total_decided = $result_count_decided->fetch_assoc()['total'] ?? 0;
    $stmt_count_decided->close();
} else {
    error_log("Error counting decided complaints: " . $db->error);
}
$total_pages_decided = max(1, ceil($total_decided / $items_per_page));

// Fetch Decided Complaints
$sql_decided = "SELECT d.id as decision_id, c.id as complaint_id, c.title, c.description, c.category, c.directorate,
                       c.status, c.created_at, d.decision_text, d.created_at as decision_received,
                       u.fname as sender_fname, u.lname as sender_lname
                FROM decisions d
                JOIN complaints c ON d.complaint_id = c.id
                JOIN users u ON d.sender_id = u.id
                WHERE d.receiver_id = ? AND c.handler_id = ?
                ORDER BY d.created_at DESC
                LIMIT ? OFFSET ?";
$stmt_decided = $db->prepare($sql_decided);
if ($stmt_decided) {
    $stmt_decided->bind_param("iiii", $handler_id, $handler_id, $items_per_page, $offset_decided);
    $stmt_decided->execute();
    $result_decided = $stmt_decided->get_result();
    while ($row = $result_decided->fetch_assoc()) {
        $decided_complaints[] = $row;
    }
    $stmt_decided->close();
} else {
    error_log("Error fetching decided complaints: " . $db->error);
    $_SESSION['error'] = "Database error fetching decided complaints.";
}

// Notices
$notices_data = [];
$sql_notices = "SELECT title, description, created_at FROM notices WHERE handler_id = ? ORDER BY created_at DESC LIMIT 5";
$stmt_notices = $db->prepare($sql_notices);
if ($stmt_notices) {
    $stmt_notices->bind_param("i", $handler_id);
    $stmt_notices->execute();
    $result_notices = $stmt_notices->get_result();
    while ($row = $result_notices->fetch_assoc()) {
        $notices_data[] = $row;
    }
    $stmt_notices->close();
} else {
    error_log("Error preparing notices query: " . $db->error);
    $_SESSION['error'] = "Database error fetching notices.";
}

// Summary Statistics
$stats = [
    'total_assigned' => 0, 'pending' => 0, 'in_progress' => 0,
    'escalated' => 0, 'resolved' => 0, 'unassigned' => $total_unassigned
];
$sql_stats = "SELECT c.status, COUNT(c.id) as count
              FROM complaints c
              WHERE c.handler_id = ?
              GROUP BY c.status";
$stmt_stats = $db->prepare($sql_stats);
if ($stmt_stats) {
    $stmt_stats->bind_param("i", $handler_id);
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    while ($row = $result_stats->fetch_assoc()) {
        $count = $row['count'] ?? 0;
        $stats['total_assigned'] += $count;
        if ($row['status'] == 'pending') {
            $stats['pending'] += $count;
        } elseif ($row['status'] == 'in_progress') {
            $stats['in_progress'] += $count;
        } elseif ($row['status'] == 'escalated') {
            $stats['escalated'] += $count;
        } elseif ($row['status'] == 'resolved') {
            $stats['resolved'] += $count;
        }
    }
    $stmt_stats->close();
} else {
    error_log("Error preparing stats query: " . $db->error);
}

// Function to build pagination query string
function build_query_string($exclude_key = '') {
    $params = $_GET;
    if ($exclude_key) {
        unset($params[$exclude_key]);
    }
    $defaults = [
        'status' => '',
        'category' => '',
        'page_assigned' => 1,
        'page_unassigned' => 1,
        'page_decided' => 1
    ];
    $params = array_merge($defaults, $params);
    return http_build_query($params);
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handler Dashboard | DMU Complaint System</title>
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

        html {
            scroll-behavior: smooth;
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
            z-index: 10;
            flex-shrink: 0;
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
            overflow-y: auto;
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
            position: sticky;
            top: 0;
            z-index: 5;
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
            margin-top: 2rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
            width: 100%;
        }

        h3:first-of-type {
            margin-top: 0;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.2rem;
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

        .summary-card.pending { border-left-color: var(--warning); }
        .summary-card.in_progress { border-left-color: var(--info); }
        .summary-card.assigned_to_authority { border-left-color: var(--orange); }
        .summary-card.escalated { border-left-color: var(--purple); }
        .summary-card.resolved { border-left-color: var(--success); }
        .summary-card.unassigned { border-left-color: var(--gray); }

        .summary-card i {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            transition: var(--transition);
        }

        .summary-card:hover i {
            transform: scale(1.1);
        }

        .summary-card.pending i { color: var(--warning); }
        .summary-card.in_progress i { color: var(--info); }
        .summary-card.assigned_to_authority i { color: var(--orange); }
        .summary-card.escalated i { color: var(--purple); }
        .summary-card.resolved i { color: var(--success); }
        .summary-card.unassigned i { color: var(--gray); }

        .summary-card h4 {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
        }

        .summary-card p {
            font-size: 1.4rem;
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

        .filter-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-form label {
            font-weight: 500;
            color: var(--gray);
        }

        .filter-form select {
            padding: 0.75rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            background: white;
            cursor: pointer;
            transition: var(--transition);
            min-width: 150px;
        }

        .filter-form select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 5px rgba(67, 97, 238, 0.3);
            outline: none;
        }

        .filter-form select:hover {
            border-color: var(--primary-light);
        }

        .filter-form button {
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }

        .filter-form button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-radius: var(--radius);
            overflow: hidden;
        }

        thead {
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            color: white;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            font-size: 0.9rem;
        }

        th {
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        tbody tr {
            border-bottom: 1px solid var(--light-gray);
            transition: var(--transition);
        }

        tbody tr:hover {
            background: #f8f9fa;
            transform: scale(1.005);
        }

        td.description, td.decision-text {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        td.description:hover, td.decision-text:hover {
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            background: #f0f4ff;
            border-radius: 5px;
            padding: 0.8rem;
            position: relative;
            z-index: 1;
        }

        .status {
            padding: 0.3rem 0.6rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-pending { background: #fff3cd; color: #664d03; }
        .status-in_progress { background: #cff4fc; color: #055160; }
        .status-escalated { background: #7209b7; color: white; }
        .status-resolved { background: #c3e6cb; color: #155724; }
        .status-rejected { background: #f8d7da; color: #842029; }
        .status-assigned { background: #fd7e14; color: white; }
        .status-uncategorized { background: #e9ecef; color: #6c757d; }

        .na-italic {
            font-style: italic;
            color: var(--gray);
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
            text-decoration: none;
            color: white;
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        .btn-primary { background: var(--primary); }
        .btn-primary:hover { background: var(--primary-dark); }

        .btn-info { background: var(--info); }
        .btn-info:hover { background: #138496; }

        .btn-success { background: var(--success); }
        .btn-success:hover { background: #218838; }

        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #c82333; }

        .btn-warning { background: var(--warning); color: #212529; }
        .btn-warning:hover { background: #e0a800; }

        .btn-orange { background: var(--orange); }
        .btn-orange:hover { background: #e06c00; }

        .btn-purple { background: var(--purple); }
        .btn-purple:hover { background: #5a057f; }

        .btn-clear {
            background: var(--gray);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }

        .btn-clear:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn:hover, .btn-clear:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        .tooltip {
            position: relative;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 120px;
            background-color: #555;
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 5px 0;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.8rem;
        }

        .tooltip .tooltiptext::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #555 transparent transparent transparent;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination a {
            background: var(--light);
            color: var(--primary);
            border: 1px solid var(--light-gray);
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination span {
            color: var(--gray);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            background: #f8f9fa;
            border-radius: var(--radius);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }

        .empty-state i {
            font-size: 2rem;
            color: var(--gray);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray);
            font-size: 1rem;
        }

        footer {
            margin-top: auto;
            padding: 1.5rem 0;
            background: white;
            border-top: 1px solid var(--light-gray);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .group-name {
            font-weight: 500;
            color: var(--gray);
        }

        .social-links a {
            color: var(--primary);
            margin: 0 10px;
            font-size: 1.2rem;
            transition: var(--transition);
        }

        .social-links a:hover {
            color: var(--primary-dark);
            transform: translateY(-3px);
        }

        .copyright {
            color: var(--gray);
            font-size: 0.9rem;
        }

        @media (max-width: 1024px) {
            .vertical-nav {
                width: 250px;
            }

            .content-container {
                padding: 1.5rem;
            }

            .summary-cards {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }

            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                padding: 15px 0;
            }

            .main-content {
                padding: 15px;
            }

            .horizontal-nav {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .horizontal-menu {
                justify-content: center;
                flex-wrap: wrap;
            }

            .content-container {
                padding: 1rem;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .profile-card {
                flex-direction: column;
                text-align: center;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-form select, .filter-form button, .btn-clear {
                width: 100%;
                margin-bottom: 0.5rem;
            }

            th, td {
                font-size: 0.85rem;
                padding: 0.8rem;
            }

            td.description, td.decision-text {
                max-width: 150px;
            }

            .footer-content {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            h2 {
                font-size: 1.5rem;
            }

            h3 {
                font-size: 1.1rem;
            }

            .summary-card {
                padding: 1rem;
            }

            .summary-card p {
                font-size: 1.2rem;
            }

            .btn, .btn-clear {
                padding: 0.5rem;
                font-size: 0.9rem;
            }

            .btn-small {
                padding: 0.3rem 0.6rem;
                font-size: 0.8rem;
            }

            .pagination a, .pagination span {
                padding: 0.4rem 0.8rem;
                font-size: 0.9rem;
            }

            th, td {
                font-size: 0.8rem;
                padding: 0.6rem;
            }

            td.description, td.decision-text {
                max-width: 100px;
            }
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo" onerror="this.style.display='none'">
                <span class="logo-text">DMU CS</span>
            </div>
            <?php if ($handler): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($handler['role'])); ?></p>
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
            <a href="view_assigned_complaints.php" class="nav-link <?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                <i class="fas fa-list-alt"></i><span>Assigned Complaints</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i><span>Resolved Complaints</span>
            </a>
            <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i><span>Decisions Received</span>
            </a>
            <a href="send_decision.php" class="nav-link <?php echo $current_page == 'send_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i><span>Send Decision</span>
            </a>
            <a href="assign_committee.php" class="nav-link <?php echo $current_page == 'assign_committee.php' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i><span>Assign Committee</span>
            </a>
            <h3>Communication</h3>
            <a href="manage_notices.php" class="nav-link <?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                <i class="fas fa-bullhorn"></i><span>Manage Notices</span>
            </a>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i><span>View Notifications</span>
            </a>
            <a href="view_feedback.php" class="nav-link <?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                <i class="fas fa-comment-dots"></i><span>Complaint Feedback</span>
            </a>
            <h3>Reports</h3>
            <a href="generate_report.php" class="nav-link <?php echo $current_page == 'generate_report.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i><span>Generate Reports</span>
            </a>
            <h3>Account</h3>
            <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                <i class="fas fa-key"></i><span>Change Password</span>
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Handler</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <div class="content-container">
            <?php if ($handler): ?>
            <h2>Welcome, <?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?>!</h2>
            <?php else: ?>
            <h2>Handler Dashboard</h2>
            <?php endif; ?>

            <!-- Display Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card pending">
                    <i class="fas fa-hourglass-half"></i>
                    <h4>Pending</h4>
                    <p><?php echo $stats['pending']; ?></p>
                </div>
                <div class="summary-card in_progress">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h4>In Progress</h4>
                    <p><?php echo $stats['in_progress']; ?></p>
                </div>
                <div class="summary-card escalated">
                    <i class="fas fa-arrow-up"></i>
                    <h4>Escalated</h4>
                    <p><?php echo $stats['escalated']; ?></p>
                </div>
                <div class="summary-card resolved">
                    <i class="fas fa-check-double"></i>
                    <h4>Resolved</h4>
                    <p><?php echo $stats['resolved']; ?></p>
                </div>
                <div class="summary-card unassigned">
                    <i class="fas fa-inbox"></i>
                    <h4>Unassigned</h4>
                    <p><?php echo $stats['unassigned']; ?></p>
                </div>
            </div>

            <!-- Profile Section -->
            <?php if ($handler): ?>
            <div class="user-profile">
                <h3>Your Profile</h3>
                <div class="profile-card">
                    <div class="profile-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="profile-details">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($handler['email']); ?></p>
                        <p><strong>Role:</strong> <?php echo htmlspecialchars(ucfirst($handler['role'])); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Assigned Complaints Section -->
            <div id="assigned-complaints-section" class="assigned-complaints-section">
                <h3>Your Assigned Complaints</h3>
                <form class="filter-form" method="GET" action="dashboard.php#assigned-complaints-section" onsubmit="setTimeout(() => document.querySelector('#assigned-complaints-section').scrollIntoView({ behavior: 'smooth' }), 100);">
                    <input type="hidden" name="page_assigned" value="1">
                    <input type="hidden" name="page_unassigned" value="<?php echo $page_unassigned; ?>">
                    <input type="hidden" name="page_decided" value="<?php echo $page_decided; ?>">
                    <label for="status-filter">Status:</label>
                    <select name="status" id="status-filter" aria-label="Filter by status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="escalated" <?php echo $status_filter == 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                        <option value="resolved" <?php echo $status_filter == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <label for="category-filter">Category:</label>
                    <select name="category" id="category-filter" aria-label="Filter by category">
                        <option value="">All Categories</option>
                        <option value="academic" <?php echo $category_filter == 'academic' ? 'selected' : ''; ?>>Academic</option>
                        <option value="administrative" <?php echo $category_filter == 'administrative' ? 'selected' : ''; ?>>Administrative</option>
                    </select>
                    <button type="submit" aria-label="Apply filters"><i class="fas fa-filter"></i> Filter</button>
                    <a href="dashboard.php?page_assigned=1&page_unassigned=<?php echo $page_unassigned; ?>&page_decided=<?php echo $page_decided; ?>#assigned-complaints-section" class="btn btn-clear" aria-label="Clear filters"><i class="fas fa-times"></i> Clear</a>
                </form>

                <?php if (!empty($assigned_complaints)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Directorate</th>
                                    <th>Status</th>
                                    <th>Assignment Status</th>
                                    <th>Last Action</th>
                                    <th>Decision Received</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assigned_complaints as $complaint): ?>
    <?php
    // No need to check handler_id since the query ensures handler_id matches
    $can_start_committee_chat = !is_null($complaint['committee_id']) && $complaint['needs_video_chat'] == 1;
    ?>
    <tr>
        <td data-label="ID"><?php echo $complaint['id']; ?></td>
        <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
        <td data-label="Description" class="description"><?php echo htmlspecialchars($complaint['description']); ?></td>
        <td data-label="Category">
            <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
        </td>
        <td data-label="Directorate">
            <?php echo !empty($complaint['directorate']) ? htmlspecialchars($complaint['directorate']) : '<span class="na-italic">N/A</span>'; ?>
        </td>
        <td data-label="Status">
            <span class="status status-<?php echo strtolower($complaint['status']); ?>">
                <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?>
            </span>
        </td>
        <td data-label="Assignment Status">
            <?php
            if ($complaint['escalation_id']) {
                $status_class = $complaint['action_type'] == 'assignment' ? 'status-assigned' : 'status-escalated';
                $status_text = $complaint['action_type'] == 'assignment' ? 'Assigned: ' : 'Escalated: ';
                $target_name = ucwords(str_replace('_', ' ', $complaint['escalated_to'] ?? 'N/A'));
                $status_text .= $target_name;
                if ($complaint['escalation_status'] == 'pending') {
                    $status_text .= ' (Pending)';
                    $status_class .= " status-pending";
                } elseif ($complaint['escalation_status'] == 'resolved') {
                    $status_text .= ' (Resolved)';
                    $status_class .= " status-resolved";
                }
                echo "<span class='status {$status_class} tooltip'>{$status_text}<span class='tooltiptext'>Status of assignment/escalation sent to {$target_name}</span></span>";
            } else {
                echo '<span class="status status-uncategorized"><i class="na-italic">N/A</i></span>';
            }
            ?>
        </td>
        <td data-label="Last Action">
            <?php
            $last_action_text = '';
            $last_action_class = 'status-uncategorized';
            $tooltip_action = '';
            if ($complaint['escalation_id']) {
                $target = ucwords(str_replace('_', ' ', $complaint['escalated_to'] ?? 'N/A'));
                if ($complaint['escalation_status'] == 'pending') {
                    if ($complaint['action_type'] == 'assignment') {
                        $last_action_text = "Assignment Pending";
                        $tooltip_action = "Waiting for {$target} to accept assignment";
                        $last_action_class = 'status-assigned status-pending';
                    } else {
                        $last_action_text = "Escalation Pending";
                        $tooltip_action = "Waiting for {$target} response";
                        $last_action_class = 'status-escalated status-pending';
                    }
                } elseif ($complaint['escalation_status'] == 'resolved') {
                    $last_action_text = "Decision Received";
                    $tooltip_action = "Decision received from {$target}.";
                    $last_action_class = 'status-resolved';
                }
            } else {
                switch ($complaint['status']) {
                    case 'pending':
                        $last_action_text = 'Pending Review';
                        $tooltip_action = 'Awaiting initial review';
                        $last_action_class = 'status-pending';
                        break;
                    case 'in_progress':
                        $last_action_text = 'Processing';
                        $tooltip_action = 'Complaint is being processed';
                        $last_action_class = 'status-in_progress';
                        break;
                    case 'escalated':
                        $last_action_text = 'Escalated';
                        $tooltip_action = 'Complaint escalated';
                        $last_action_class = 'status-escalated';
                        break;
                    case 'resolved':
                        $last_action_text = 'Resolved';
                        $tooltip_action = 'Complaint resolved';
                        $last_action_class = 'status-resolved';
                        break;
                    case 'rejected':
                        $last_action_text = 'Rejected';
                        $tooltip_action = 'Complaint rejected';
                        $last_action_class = 'status-rejected';
                        break;
                    default:
                        $last_action_text = 'Unknown';
                        $tooltip_action = 'Current state unknown';
                        break;
                }
            }
            echo "<span class='status {$last_action_class} tooltip'>" . htmlspecialchars($last_action_text) . "<span class='tooltiptext'>" . htmlspecialchars($tooltip_action) . "</span></span>";
            ?>
        </td>
        <td data-label="Decision Received" class="decision-text">
            <?php echo !empty($complaint['latest_decision']) ? htmlspecialchars($complaint['latest_decision']) : '<i class="na-italic">N/A</i>'; ?>
        </td>
        <td data-label="Submitted"><?php echo date('M j, Y', strtotime($complaint['created_at'])); ?></td>
        <td data-label="Actions">
            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-info btn-small tooltip" aria-label="View complaint details"><i class="fas fa-eye"></i><span class="tooltiptext">View Details</span></a>
            <?php if ($complaint['needs_committee'] == 1): ?>
                <?php if (is_null($complaint['committee_id'])): ?>
                    <a href="assign_committee.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-purple btn-small tooltip" aria-label="Assign a committee"><i class="fas fa-users"></i><span class="tooltiptext">Assign Committee</span></a>
                <?php else: ?>
                    <?php if ($can_start_committee_chat): ?>
                        <a href="start_committee_chat.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-purple btn-small tooltip" aria-label="Start committee video chat"><i class="fas fa-video"></i><span class="tooltiptext">Start Committee Chat</span></a>
                    <?php else: ?>
                        <a href="../chat.php?committee_id=<?php echo $complaint['committee_id']; ?>" class="btn btn-purple btn-small tooltip" aria-label="View committee chat"><i class="fas fa-comments"></i><span class="tooltiptext">View Committee Chat</span></a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($complaint['status'], ['pending', 'in_progress'])): ?>
                <a href="assign_complaint_to_authority.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-orange btn-small tooltip" aria-label="Escalate complaint"><i class="fas fa-level-up-alt"></i><span class="tooltiptext">Escalate</span></a>
                <a href="resolve_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-success btn-small tooltip" aria-label="Resolve complaint"><i class="fas fa-check"></i><span class="tooltiptext">Resolve</span></a>
            <?php endif; ?>
            <?php if ($complaint['status'] == 'pending'): ?>
                <a href="reject_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-danger btn-small tooltip" aria-label="Reject complaint" onclick="return confirm('Are you sure you want to reject this complaint?');"><i class="fas fa-times"></i><span class="tooltiptext">Reject</span></a>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php
                        $pagination_params = $_GET;
                        unset($pagination_params['page_assigned']);
                        $base_pagination_url = "dashboard.php?" . http_build_query($pagination_params) . "&page_assigned=";
                        if ($page_assigned > 1) {
                            $prev_page = $page_assigned - 1;
                            echo "<a href='{$base_pagination_url}{$prev_page}#assigned-complaints-section' aria-label='Previous Page'>« Previous</a>";
                        }
                        $start_page = max(1, $page_assigned - 2);
                        $end_page = min($total_pages_assigned, $page_assigned + 2);
                        if ($start_page > 1) {
                            echo "<a href='{$base_pagination_url}1#assigned-complaints-section'>1</a>";
                            if ($start_page > 2) echo "<span>...</span>";
                        }
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $active_class = $i == $page_assigned ? 'active' : '';
                            echo "<a href='{$base_pagination_url}{$i}#assigned-complaints-section' class='{$active_class}' aria-label='Page {$i}'>{$i}</a>";
                        }
                        if ($end_page < $total_pages_assigned) {
                            if ($end_page < $total_pages_assigned - 1) echo "<span>...</span>";
                            echo "<a href='{$base_pagination_url}{$total_pages_assigned}#assigned-complaints-section' aria-label='Last Page'>{$total_pages_assigned}</a>";
                        }
                        if ($page_assigned < $total_pages_assigned) {
                            $next_page = $page_assigned + 1;
                            echo "<a href='{$base_pagination_url}{$next_page}#assigned-complaints-section' aria-label='Next Page'>Next »</a>";
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>No assigned complaints found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Unassigned Complaints Section -->
            <div id="unassigned-complaints-section" class="unassigned-complaints-section">
                <h3>Unassigned Complaints (Pending)</h3>
                <?php if (!empty($unassigned_complaints)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th>Directorate</th>
                                    <th>Status</th>
                                    <th>Submitted By</th>
                                    <th>Submitted On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unassigned_complaints as $complaint): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $complaint['id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
                                        <td data-label="Description" class="description"><?php echo htmlspecialchars($complaint['description']); ?></td>
                                        <td data-label="Category">
                                            <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                                        </td>
                                        <td data-label="Directorate">
                                            <?php echo !empty($complaint['directorate']) ? htmlspecialchars($complaint['directorate']) : '<span class="na-italic">N/A</span>'; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($complaint['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Submitted By">
                                            <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?>
                                        </td>
                                        <td data-label="Submitted On"><?php echo date('M j, Y', strtotime($complaint['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['id']; ?>" class="btn btn-info btn-small tooltip" aria-label="View complaint details"><i class="fas fa-eye"></i><span class="tooltiptext">View Details</span></a>
                                            <a href="assign_complaint.php?complaint_id=<?php echo $complaint['id']; ?>&page_assigned=<?php echo $page_assigned; ?>&page_unassigned=<?php echo $page_unassigned; ?>&page_decided=<?php echo $page_decided; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $category_filter ? '&category=' . urlencode($category_filter) : ''; ?>" class="btn btn-primary btn-small tooltip" aria-label="Claim this complaint"><i class="fas fa-hand-paper"></i><span class="tooltiptext">Claim Complaint</span></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php
                        $pagination_params = $_GET;
                        unset($pagination_params['page_unassigned']);
                        $base_pagination_url = "dashboard.php?" . http_build_query($pagination_params) . "&page_unassigned=";
                        if ($page_unassigned > 1) {
                            $prev_page = $page_unassigned - 1;
                            echo "<a href='{$base_pagination_url}{$prev_page}#unassigned-complaints-section' aria-label='Previous Page'>« Previous</a>";
                        }
                        $start_page = max(1, $page_unassigned - 2);
                        $end_page = min($total_pages_unassigned, $page_unassigned + 2);
                        if ($start_page > 1) {
                            echo "<a href='{$base_pagination_url}1#unassigned-complaints-section'>1</a>";
                            if ($start_page > 2) echo "<span>...</span>";
                        }
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $active_class = $i == $page_unassigned ? 'active' : '';
                            echo "<a href='{$base_pagination_url}{$i}#unassigned-complaints-section' class='{$active_class}' aria-label='Page {$i}'>{$i}</a>";
                        }
                        if ($end_page < $total_pages_unassigned) {
                            if ($end_page < $total_pages_unassigned - 1) echo "<span>...</span>";
                            echo "<a href='{$base_pagination_url}{$total_pages_unassigned}#unassigned-complaints-section' aria-label='Last Page'>{$total_pages_unassigned}</a>";
                        }
                        if ($page_unassigned < $total_pages_unassigned) {
                            $next_page = $page_unassigned + 1;
                            echo "<a href='{$base_pagination_url}{$next_page}#unassigned-complaints-section' aria-label='Next Page'>Next »</a>";
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>No unassigned complaints available at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Decisions Received Section -->
            <div id="decided-complaints-section" class="decided-complaints-section">
                <h3>Complaints with Decisions Received</h3>
                <?php if (!empty($decided_complaints)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Decision</th>
                                    <th>Category</th>
                                    <th>Directorate</th>
                                    <th>Status</th>
                                    <th>Sent By</th>
                                    <th>Received On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decided_complaints as $complaint): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $complaint['complaint_id']; ?></td>
                                        <td data-label="Title"><?php echo htmlspecialchars($complaint['title']); ?></td>
                                        <td data-label="Decision" class="decision-text"><?php echo htmlspecialchars($complaint['decision_text']); ?></td>
                                        <td data-label="Category">
                                            <?php echo !empty($complaint['category']) ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="status status-uncategorized">Unset</span>'; ?>
                                        </td>
                                        <td data-label="Directorate">
                                            <?php echo !empty($complaint['directorate']) ? htmlspecialchars($complaint['directorate']) : '<span class="na-italic">N/A</span>'; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status status-<?php echo strtolower($complaint['status']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Sent By">
                                            <?php echo htmlspecialchars($complaint['sender_fname'] . ' ' . $complaint['sender_lname']); ?>
                                        </td>
                                        <td data-label="Received On"><?php echo date('M j, Y', strtotime($complaint['decision_received'])); ?></td>
                                        <td data-label="Actions">
                                            <a href="view_complaint.php?complaint_id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-info btn-small tooltip" aria-label="View complaint details"><i class="fas fa-eye"></i><span class="tooltiptext">View Details</span></a>
                                            <a href="reply_to_decision.php?decision_id=<?php echo $complaint['decision_id']; ?>" class="btn btn-primary btn-small tooltip" aria-label="Reply to decision"><i class="fas fa-reply"></i><span class="tooltiptext">Reply</span></a>
                                            <a href="assign_complaint_to_authority.php?complaint_id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-orange btn-small tooltip" aria-label="Escalate complaint"><i class="fas fa-level-up-alt"></i><span class="tooltiptext">Escalate</span></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php
                        $pagination_params = $_GET;
                        unset($pagination_params['page_decided']);
                        $base_pagination_url = "dashboard.php?" . http_build_query($pagination_params) . "&page_decided=";
                        if ($page_decided > 1) {
                            $prev_page = $page_decided - 1;
                            echo "<a href='{$base_pagination_url}{$prev_page}#decided-complaints-section' aria-label='Previous Page'>« Previous</a>";
                        }
                        $start_page = max(1, $page_decided - 2);
                        $end_page = min($total_pages_decided, $page_decided + 2);
                        if ($start_page > 1) {
                            echo "<a href='{$base_pagination_url}1#decided-complaints-section'>1</a>";
                            if ($start_page > 2) echo "<span>...</span>";
                        }
                        for ($i = $start_page; $i <= $end_page; $i++) {
                            $active_class = $i == $page_decided ? 'active' : '';
                            echo "<a href='{$base_pagination_url}{$i}#decided-complaints-section' class='{$active_class}' aria-label='Page {$i}'>{$i}</a>";
                        }
                        if ($end_page < $total_pages_decided) {
                            if ($end_page < $total_pages_decided - 1) echo "<span>...</span>";
                            echo "<a href='{$base_pagination_url}{$total_pages_decided}#decided-complaints-section' aria-label='Last Page'>{$total_pages_decided}</a>";
                        }
                        if ($page_decided < $total_pages_decided) {
                            $next_page = $page_decided + 1;
                            echo "<a href='{$base_pagination_url}{$next_page}#decided-complaints-section' aria-label='Next Page'>Next »</a>";
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>No complaints with decisions received at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>

           <!-- Recent Notices Section -->
           <div class="notices-section">
                <h3>Recent Notices</h3>
                <?php if (!empty($notices_data)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Posted On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notices_data as $notice): ?>
                                    <tr>
                                        <td data-label="Title"><?php echo htmlspecialchars($notice['title']); ?></td>
                                        <td data-label="Description" class="description"><?php echo htmlspecialchars($notice['description']); ?></td>
                                        <td data-label="Posted On"><?php echo date('M j, Y', strtotime($notice['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>No recent notices available.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer>
            <div class="footer-content">
                <div class="group-name">Group 4</div>
                <div class="social-links">
                    <a href="https://github.com/YourGroupRepo" target="_blank" rel="noopener noreferrer" aria-label="GitHub"><i class="fab fa-github"></i></a>
                    <a href="mailto:group4@example.com" aria-label="Email"><i class="fas fa-envelope"></i></a>
                </div>
                <div class="copyright">© 2025 DMU Complaint System. All rights reserved.</div>
            </div>
        </footer>
    </div>
</body>
</html>