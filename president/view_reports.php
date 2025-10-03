<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'president'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'president') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$president_id = $_SESSION['user_id'];
$president = null;

// Fetch President details (for sidebar)
$sql_president = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_president = $db->prepare($sql_president);
if ($stmt_president) {
    $stmt_president->bind_param("i", $president_id);
    $stmt_president->execute();
    $result_president = $stmt_president->get_result();
    if ($result_president->num_rows > 0) {
        $president = $result_president->fetch_assoc();
    } else {
        $_SESSION['error'] = "President details not found.";
        error_log("President details not found for ID: " . $president_id);
        header("Location: ../logout.php");
        exit;
    }
    $stmt_president->close();
} else {
    error_log("Error preparing president query: " . $db->error);
    $_SESSION['error'] = "Database error fetching user details.";
}

// --- Initialize filter variables ---
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
$start_resolution_date = isset($_POST['start_resolution_date']) ? $_POST['start_resolution_date'] : '';
$end_resolution_date = isset($_POST['end_resolution_date']) ? $_POST['end_resolution_date'] : '';
$category = isset($_POST['category']) ? $_POST['category'] : '';
$status = isset($_POST['status']) ? $_POST['status'] : '';
$visibility = isset($_POST['visibility']) ? $_POST['visibility'] : '';
$department = isset($_POST['department']) ? $_POST['department'] : '';

// --- Pagination for complaints table ---
$complaints_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $complaints_per_page;

// --- Build base query and dynamic WHERE clause ---
$where_clauses = [];
$params = [];
$types = '';

$sql_base_select = "
    SELECT
        c.*,
        u_submitter.fname as submitter_fname,
        u_submitter.lname as submitter_lname,
        u_handler.fname as handler_fname,
        u_handler.lname as handler_lname,
        u_handler.department as handler_department
    FROM complaints c
    JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN users u_handler ON c.handler_id = u_handler.id
    WHERE 1=1";

// Add filters to the query conditions
if ($start_date) {
    $where_clauses[] = "c.created_at >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if ($end_date) {
    $where_clauses[] = "c.created_at <= ?";
    $params[] = $end_date . ' 23:59:59';
    $types .= 's';
}
if ($start_resolution_date) {
    $where_clauses[] = "c.resolution_date >= ?";
    $params[] = $start_resolution_date;
    $types .= 's';
}
if ($end_resolution_date) {
    $where_clauses[] = "c.resolution_date <= ?";
    $params[] = $end_resolution_date . ' 23:59:59';
    $types .= 's';
}
if ($category) {
    $where_clauses[] = "c.category = ?";
    $params[] = $category;
    $types .= 's';
}
if ($status) {
    $where_clauses[] = "c.status = ?";
    $params[] = $status;
    $types .= 's';
}
if ($visibility) {
    $where_clauses[] = "c.visibility = ?";
    $params[] = $visibility;
    $types .= 's';
}
if ($department) {
    if ($department === 'Unassigned') {
        $where_clauses[] = "(u_handler.department IS NULL OR u_handler.department = '')";
    } else {
        $where_clauses[] = "u_handler.department = ?";
        $params[] = $department;
        $types .= 's';
    }
}

// Combine WHERE clauses into a reusable string
$sql_where = "";
if (!empty($where_clauses)) {
    $sql_where = " AND " . implode(" AND ", $where_clauses);
}

// --- Fetch total number of complaints for pagination ---
$total_sql = "SELECT COUNT(*) as total FROM complaints c
              JOIN users u_submitter ON c.user_id = u_submitter.id
              LEFT JOIN users u_handler ON c.handler_id = u_handler.id
              WHERE 1=1" . $sql_where;
$stmt_total = $db->prepare($total_sql);
$total_complaints = 0;
if ($stmt_total) {
    if (!empty($params)) {
        $stmt_total->bind_param($types, ...$params);
    }
    if ($stmt_total->execute()) {
        $total_result = $stmt_total->get_result();
        $total_complaints = $total_result->fetch_assoc()['total'];
        $total_result->free();
    } else {
        error_log("Error executing total complaints query for pagination: " . $stmt_total->error);
    }
    $stmt_total->close();
} else {
    error_log("Error preparing total complaints query for pagination: " . $db->error);
}
$total_pages = ceil($total_complaints / $complaints_per_page);

// --- Prepare and execute the main complaints query with pagination ---
$sql_complaints_list = $sql_base_select . $sql_where . " ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
$stmt_complaints = $db->prepare($sql_complaints_list);
$complaints_result = null;
if ($stmt_complaints) {
    $complaint_params = $params;
    $complaint_types = $types . 'ii';
    $complaint_params[] = $complaints_per_page;
    $complaint_params[] = $offset;
    if (!empty($complaint_params)) {
        $stmt_complaints->bind_param($complaint_types, ...$complaint_params);
    }
    if (!$stmt_complaints->execute()) {
        error_log("Error executing complaints list query: " . $stmt_complaints->error);
        $_SESSION['error'] = "Database error fetching complaint details.";
    } else {
        $complaints_result = $stmt_complaints->get_result();
    }
    $stmt_complaints->close();
} else {
    error_log("Error preparing complaints list query: " . $db->error);
    $_SESSION['error'] = "Database error preparing complaint details query.";
}

// --- Fetch summary statistics ---
$stats = [
    'total_complaints' => 0,
    'escalated_complaints' => 0,
    'resolved_complaints' => 0,
    'status_breakdown' => [],
    'category_breakdown' => [],
    'department_breakdown' => [],
    'avg_resolution_time' => 0,
    'monthly_trend' => []
];

// Base FROM and JOIN for statistics
$sql_stats_base_from = " FROM complaints c
                        LEFT JOIN users u_handler ON c.handler_id = u_handler.id
                        WHERE 1=1" . $sql_where;

// 1. Total complaints, escalated, and resolved
$total_sql = "SELECT
    COUNT(c.id) as total,
    SUM(CASE WHEN c.status = 'escalated' THEN 1 ELSE 0 END) as escalated,
    SUM(CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END) as resolved"
    . $sql_stats_base_from;

$stmt_total = $db->prepare($total_sql);
if ($stmt_total) {
    if (!empty($params)) {
        $stmt_total->bind_param($types, ...$params);
    }
    if ($stmt_total->execute()) {
        $result = $stmt_total->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['total_complaints'] = (int)($row['total'] ?? 0);
            $stats['escalated_complaints'] = (int)($row['escalated'] ?? 0);
            $stats['resolved_complaints'] = (int)($row['resolved'] ?? 0);
        }
        $result->free();
    } else {
        error_log("Error executing total stats query: " . $stmt_total->error);
    }
    $stmt_total->close();
} else {
    error_log("Error preparing total stats query: " . $db->error);
}

// 2. Status breakdown query
$status_breakdown_sql = "SELECT c.status, COUNT(c.id) as count"
                        . $sql_stats_base_from . " GROUP BY c.status";
$stmt_status = $db->prepare($status_breakdown_sql);
if ($stmt_status) {
    if (!empty($params)) {
        $stmt_status->bind_param($types, ...$params);
    }
    if ($stmt_status->execute()) {
        $result = $stmt_status->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['status'])) {
                $stats['status_breakdown'][htmlspecialchars($row['status'])] = (int)$row['count'];
            }
        }
        $result->free();
    } else {
        error_log("Error executing status breakdown query: " . $stmt_status->error);
    }
    $stmt_status->close();
} else {
    error_log("Error preparing status breakdown query: " . $db->error);
    $_SESSION['error'] = "Database error generating report statistics (Status).";
}

// 3. Category breakdown query
$category_breakdown_sql = "SELECT c.category, COUNT(c.id) as count"
                          . $sql_stats_base_from . " GROUP BY c.category";
$stmt_category = $db->prepare($category_breakdown_sql);
if ($stmt_category) {
    if (!empty($params)) {
        $stmt_category->bind_param($types, ...$params);
    }
    if ($stmt_category->execute()) {
        $result = $stmt_category->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['category'])) {
                $stats['category_breakdown'][htmlspecialchars($row['category'])] = (int)$row['count'];
            }
        }
        $result->free();
    } else {
        error_log("Error executing category breakdown query: " . $stmt_category->error);
    }
    $stmt_category->close();
} else {
    error_log("Error preparing category breakdown query: " . $db->error);
}

// 4. Department breakdown query
$department_breakdown_sql = "SELECT u_handler.department, COUNT(c.id) as count"
                            . $sql_stats_base_from . " GROUP BY u_handler.department";
$stmt_dept = $db->prepare($department_breakdown_sql);
if ($stmt_dept) {
    if (!empty($params)) {
        $stmt_dept->bind_param($types, ...$params);
    }
    if ($stmt_dept->execute()) {
        $result = $stmt_dept->get_result();
        while ($row = $result->fetch_assoc()) {
            $dept_name = (!empty($row['department'])) ? htmlspecialchars($row['department']) : 'Unassigned';
            $stats['department_breakdown'][$dept_name] = (int)$row['count'];
        }
        $result->free();
    } else {
        error_log("Error executing department breakdown query: " . $stmt_dept->error);
    }
    $stmt_dept->close();
} else {
    error_log("Error preparing department breakdown query: " . $db->error);
}

// 5. Average resolution time (for resolved complaints)
$avg_resolution_sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, c.created_at, c.resolution_date)) as avg_time"
                      . $sql_stats_base_from . " AND c.status = 'resolved'";
$stmt_avg = $db->prepare($avg_resolution_sql);
if ($stmt_avg) {
    if (!empty($params)) {
        $stmt_avg->bind_param($types, ...$params);
    }
    if ($stmt_avg->execute()) {
        $result = $stmt_avg->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['avg_resolution_time'] = $row['avg_time'] ? round((float)$row['avg_time'], 2) : 0;
        }
        $result->free();
    } else {
        error_log("Error executing average resolution time query: " . $stmt_avg->error);
    }
    $stmt_avg->close();
} else {
    error_log("Error preparing average resolution time query: " . $db->error);
}

// 6. Monthly trend (complaints submitted per month)
$monthly_trend_sql = "SELECT DATE_FORMAT(c.created_at, '%Y-%m') as month, COUNT(c.id) as count"
                     . $sql_stats_base_from . " GROUP BY month ORDER BY month ASC";
$stmt_trend = $db->prepare($monthly_trend_sql);
if ($stmt_trend) {
    if (!empty($params)) {
        $stmt_trend->bind_param($types, ...$params);
    }
    if ($stmt_trend->execute()) {
        $result = $stmt_trend->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['month'])) {
                $stats['monthly_trend'][$row['month']] = (int)$row['count'];
            }
        }
        $result->free();
    } else {
        error_log("Error executing monthly trend query: " . $stmt_trend->error);
    }
    $stmt_trend->close();
} else {
    error_log("Error preparing monthly trend query: " . $db->error);
}

// --- Fetch Stereotyped Reports (Updated to match setup.php schema) ---
$stereotyped_reports = [];
$stereotyped_sql = "
    SELECT sr.id, sr.complaint_id, sr.report_type, sr.report_content as details, sr.created_at,
           u.fname as submitter_fname, u.lname as submitter_lname,
           c.title as complaint_title
    FROM stereotyped_reports sr
    JOIN users u ON sr.handler_id = u.id
    JOIN complaints c ON sr.complaint_id = c.id
    ORDER BY sr.created_at DESC";
$stmt_stereotyped = $db->prepare($stereotyped_sql);
if ($stmt_stereotyped) {
    if ($stmt_stereotyped->execute()) {
        $result = $stmt_stereotyped->get_result();
        while ($row = $result->fetch_assoc()) {
            $stereotyped_reports[] = $row;
        }
        $result->free();
    } else {
        error_log("Error executing stereotyped reports query: " . $stmt_stereotyped->error);
    }
    $stmt_stereotyped->close();
} else {
    error_log("Error preparing stereotyped reports query: " . $db->error);
}

// --- Fetch all distinct departments for the filter dropdown ---
$departments = [];
$dept_sql = "SELECT DISTINCT department FROM users WHERE role IN ('handler', 'department_head', 'college_dean', 'admin_vp', 'academic_vp') AND department IS NOT NULL AND department <> '' ORDER BY department";
$dept_result = $db->query($dept_sql);
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['department'];
    }
    $dept_result->free();
} else {
    error_log("Error fetching departments: " . $db->error);
}

// --- Fetch notification count ---
$sql_notif_count = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif_count = $db->prepare($sql_notif_count);
$notification_count = 0;
if ($stmt_notif_count) {
    $stmt_notif_count->bind_param("i", $president_id);
    if ($stmt_notif_count->execute()) {
        $notif_result = $stmt_notif_count->get_result();
        $notification_count = (int)($notif_result->fetch_assoc()['count'] ?? 0);
        $notif_result->free();
    } else {
        error_log("Error fetching notification count: " . $stmt_notif_count->error);
    }
    $stmt_notif_count->close();
} else {
    error_log("Error preparing notification count query: " . $db->error);
}

// --- Handle CSV Export ---
if (isset($_POST['export_csv'])) {
    $stmt_export = $db->prepare($sql_base_select . $sql_where . " ORDER BY c.created_at DESC");
    if ($stmt_export) {
        if (!empty($params)) {
            $stmt_export->bind_param($types, ...$params);
        }
        if ($stmt_export->execute()) {
            $export_result = $stmt_export->get_result();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="president_complaints_report_' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($output, [
                'ID', 'Title', 'Category', 'Submitted By', 'Handled By', 'Handler Department', 'Visibility',
                'Status', 'Created On', 'Resolved On', 'Resolution Details'
            ]);

            while ($complaint = $export_result->fetch_assoc()) {
                fputcsv($output, [
                    $complaint['id'],
                    $complaint['title'],
                    ucfirst($complaint['category']),
                    htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']),
                    $complaint['handler_fname'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname']) : 'N/A',
                    htmlspecialchars($complaint['handler_department'] ?: 'Unassigned'),
                    ucfirst($complaint['visibility']),
                    ucfirst($complaint['status']),
                    $complaint['created_at'] ? date("Y-m-d H:i:s", strtotime($complaint['created_at'])) : 'N/A',
                    $complaint['resolution_date'] ? date("Y-m-d H:i:s", strtotime($complaint['resolution_date'])) : 'N/A',
                    $complaint['resolution_details'] ?? 'N/A'
                ]);
            }

            fclose($output);
            $export_result->free();
            $stmt_export->close();
            exit;
        } else {
            error_log("Error executing query for CSV export: " . $stmt_export->error);
            $_SESSION['error'] = "Could not generate data for export.";
        }
        if (isset($stmt_export)) $stmt_export->close();
    } else {
        error_log("Error preparing query for CSV export: " . $db->error);
        $_SESSION['error'] = "Error preparing data for export.";
    }
    header("Location: view_reports.php");
    exit;
}

$current_page = 'view_reports.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reports | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
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
            flex-shrink: 0;
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
            align-items: flex-start;
            gap: 12px;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .alert i {
            font-size: 1.2rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .alert-success {
            background-color: #d1e7dd;
            border-color: #badbcc;
            color: #0f5132;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }

        .alert-info {
            background-color: #cff4fc;
            border-color: #b6effb;
            color: #055160;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #664d03;
        }

        .content-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            flex-grow: 1;
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

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--text-color);
            transition: border-color 0.3s ease;
            background-color: #fff;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }

        .form-group-submit {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-start;
            align-items: flex-end;
            padding-top: 10px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            padding: 20px;
            background-color: var(--light);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--light-gray);
            text-align: center;
        }

        .stat-card h4 {
            font-size: 1rem;
            margin-bottom: 10px;
            color: var(--gray);
            font-weight: 500;
        }

        .stat-card p {
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }

        .chart-container {
            background: var(--light);
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--light-gray);
            min-height: 300px;
            display: flex;
            flex-direction: column;
        }

        .chart-container h5 {
            text-align: center;
            margin-bottom: 15px;
            font-size: 1.1rem;
            color: var(--primary-dark);
            font-weight: 500;
            flex-shrink: 0;
        }

        .chart-container canvas {
            flex-grow: 1;
            max-height: 350px;
        }

        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        th {
            background-color: var(--primary);
            color: white;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
        }

        th.sortable:hover {
            background-color: var(--primary-dark);
        }

        th .sort-icon {
            font-size: 0.8em;
            margin-left: 5px;
            color: white;
            opacity: 0.5;
        }

        th.asc .sort-icon, th.desc .sort-icon {
            opacity: 1;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: var(--light);
            transform: translateY(-2px);
        }

        td .resolution-details-cell {
            max-width: 300px;
            min-width: 200px;
            white-space: normal;
            word-wrap: break-word;
            font-size: 0.85rem;
            color: var(--gray);
            line-height: 1.4;
        }

        .text-muted {
            color: var(--gray) !important;
        }

        .status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
            letter-spacing: 0.5px;
            color: #fff;
            text-align: center;
            display: inline-block;
            line-height: 1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            min-width: 80px;
        }

        .status-pending { background-color: var(--warning); color: var(--dark); }
        .status-validated { background-color: var(--info); }
        .status-in_progress, .status-in-progress { background-color: var(--primary); }
        .status-resolved { background-color: var(--success); }
        .status-rejected { background-color: var(--danger); }
        .status-escalated { background-color: var(--orange); }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 2rem;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }

        .pagination a.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination a:hover:not(.active) {
            background: var(--primary-light);
            color: white;
            border-color: var(--primary-light);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 0.8rem;
            gap: 5px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
            transform: translateY(-1px);
        }

        footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            width: 100%;
            flex-shrink: 0;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .group-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 15px 0;
        }

        .social-links a {
            color: white;
            font-size: 1.5rem;
            transition: var(--transition);
        }

        .social-links a:hover {
            transform: translateY(-3px);
            color: var(--accent);
        }

        .copyright {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        @media (max-width: 992px) {
            .vertical-nav { width: 70px; }
            .nav-header .logo-text, .nav-menu h3, .nav-link span, .user-info { display: none; }
            .nav-link { justify-content: center; padding: 12px; }
            .nav-link i { margin: 0; font-size: 1.3rem; }
            .main-content { padding: 15px; }
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .filter-form { grid-template-columns: 1fr; }
            .stats-container { grid-template-columns: 1fr; }
            .horizontal-nav { flex-direction: column; align-items: flex-start; }
            .horizontal-menu { width: 100%; justify-content: flex-end; }
            th, td { font-size: 0.85rem; padding: 10px 8px; }
            .resolution-details-cell { max-width: 180px; min-width: 150px; }
        }

        @media (max-width: 480px) {
            th, td { font-size: 0.8rem; padding: 8px 5px; }
            .status { font-size: 0.7rem; padding: 4px 8px; min-width: 70px; }
            .resolution-details-cell { max-width: 150px; min-width: 100px; font-size: 0.8rem; }
            .btn { padding: 0.5rem 1rem; font-size: 0.9rem; }
            .btn-small { padding: 4px 8px; font-size: 0.7rem; }
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
            <?php if ($president): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($president['fname'] . ' ' . $president['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $president['role']))); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-tie"></i>
                <div class="user-info">
                    <h4>President</h4>
                    <p>Role: President</p>
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
            <a href="javascript:void(0);" class="nav-link <?php echo $current_page == 'decide_complaint.php' ? 'active' : ''; ?>" onclick="alert('Please select a complaint to decide on from the dashboard.'); window.location.href='dashboard.php';">
                <i class="fas fa-gavel"></i>
                <span>Decide Complaint</span>
            </a>
            <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i>
                <span> Resolved Complaints</span>
            </a>
            <a href="view_reports.php" class="nav-link <?php echo $current_page == 'view_reports.php' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>View Reports</span>
            </a>

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
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
                <span>DMU Complaint System - President</span>
            </div>
            <div class="horizontal-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Home</a>
                <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </nav>

        <div class="content-container">
            <h2>System-Wide Reports</h2>

            <!-- Session Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Filter Form -->
            <div class="content-container">
                <h2>Filter Complaints</h2>
                <form method="POST" class="filter-form" action="view_reports.php">
                    <div class="form-group">
                        <label for="start_date">Submission Start</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_date">Submission End</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="start_resolution_date">Resolution Start</label>
                        <input type="date" id="start_resolution_date" name="start_resolution_date" value="<?php echo htmlspecialchars($start_resolution_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_resolution_date">Resolution End</label>
                        <input type="date" id="end_resolution_date" name="end_resolution_date" value="<?php echo htmlspecialchars($end_resolution_date); ?>" max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <option value="academic" <?php echo $category == 'academic' ? 'selected' : ''; ?>>Academic</option>
                            <option value="administrative" <?php echo $category == 'administrative' ? 'selected' : ''; ?>>Administrative</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="validated" <?php echo $status == 'validated' ? 'selected' : ''; ?>>Validated</option>
                            <option value="in_progress" <?php echo $status == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="escalated" <?php echo $status == 'escalated' ? 'selected' : ''; ?>>Escalated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="visibility">Visibility</label>
                        <select id="visibility" name="visibility">
                            <option value="">All Visibilities</option>
                            <option value="standard" <?php echo $visibility == 'standard' ? 'selected' : ''; ?>>Standard</option>
                            <option value="anonymous" <?php echo $visibility == 'anonymous' ? 'selected' : ''; ?>>Anonymous</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="department">Handler Department</label>
                        <select id="department" name="department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department == $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="Unassigned" <?php echo $department == 'Unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                        </select>
                    </div>
                    <div class="form-group form-group-submit">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Generate Report</button>
                    </div>
                </form>
            </div>

            <!-- Report Results Section -->
            <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['export_csv'])): ?>
                <!-- Summary Statistics -->
                <div class="content-container">
                    <h2>Report Summary</h2>
                    <div class="stats-container">
                        <div class="stat-card">
                            <h4>Total Filtered</h4>
                            <p><?php echo $stats['total_complaints']; ?></p>
                        </div>
                        <div class="stat-card">
                            <h4>Escalated</h4>
                            <p><?php echo $stats['escalated_complaints']; ?></p>
                        </div>
                        <div class="stat-card">
                            <h4>Resolved</h4>
                            <p><?php echo $stats['resolved_complaints']; ?></p>
                        </div>
                        <div class="stat-card">
                            <h4>Avg. Resolution (Hrs)</h4>
                            <p><?php echo $stats['avg_resolution_time'] ?: 'N/A'; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="content-container">
                    <h2>Visual Breakdown</h2>
                    <?php if ($stats['total_complaints'] > 0): ?>
                        <div class="charts-grid">
                            <?php if (!empty($stats['status_breakdown'])): ?>
                            <div class="chart-container">
                                <h5>By Status</h5>
                                <canvas id="statusChart"></canvas>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($stats['category_breakdown'])): ?>
                            <div class="chart-container">
                                <h5>By Category</h5>
                                <canvas id="categoryChart"></canvas>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($stats['department_breakdown'])): ?>
                            <div class="chart-container">
                                <h5>By Department</h5>
                                <canvas id="departmentChart"></canvas>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($stats['monthly_trend'])): ?>
                            <div class="chart-container">
                                <h5>Monthly Trend (Submissions)</h5>
                                <canvas id="trendChart"></canvas>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($stats['status_breakdown']) && empty($stats['category_breakdown']) && empty($stats['department_breakdown']) && empty($stats['monthly_trend'])): ?>
                            <div class="alert alert-info"><i class="fas fa-info-circle"></i> No breakdown data available for the selected filters.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> No data available for charts based on the selected filters.</div>
                    <?php endif; ?>
                </div>

                <!-- Detailed Complaints Table -->
                <div class="content-container">
                    <h2>Detailed Complaints List</h2>
                    <form method="POST" action="view_reports.php" style="display: inline;">
                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        <input type="hidden" name="start_resolution_date" value="<?php echo htmlspecialchars($start_resolution_date); ?>">
                        <input type="hidden" name="end_resolution_date" value="<?php echo htmlspecialchars($end_resolution_date); ?>">
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                        <input type="hidden" name="visibility" value="<?php echo htmlspecialchars($visibility); ?>">
                        <input type="hidden" name="department" value="<?php echo htmlspecialchars($department); ?>">
                        <button type="submit" name="export_csv" value="1" class="btn btn-success btn-small" <?php echo ($stats['total_complaints'] == 0) ? 'disabled' : ''; ?>>
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </form>
                    <?php if ($complaints_result && $complaints_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="complaintsTable">
                                <thead>
                                    <tr>
                                        <th class="sortable" data-sort="id">ID <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="title">Title <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="category">Category <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="submitter">Submitted By <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="handler">Handled By <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="department">Department <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="visibility">Visibility <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="status">Status <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="created_at">Created On <i class="fas fa-sort sort-icon"></i></th>
                                        <th class="sortable" data-sort="resolution_date">Resolved On <i class="fas fa-sort sort-icon"></i></th>
                                        <th>Resolution Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($complaint = $complaints_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $complaint['id']; ?></td>
                                            <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($complaint['category'])); ?></td>
                                            <td><?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></td>
                                            <td><?php echo $complaint['handler_fname'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname']) : '<span class="text-muted">N/A</span>'; ?></td>
                                            <td><?php echo htmlspecialchars($complaint['handler_department'] ?: 'Unassigned'); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></td>
                                            <td>
                                                <?php $status_class = strtolower(str_replace(' ', '_', htmlspecialchars($complaint['status']))); ?>
                                                <span class="status status-<?php echo $status_class; ?>">
                                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status']))); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $complaint['created_at'] ? date("M j, Y, g:i a", strtotime($complaint['created_at'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                            <td><?php echo $complaint['resolution_date'] ? date("M j, Y, g:i a", strtotime($complaint['resolution_date'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                            <td class="resolution-details-cell"><?php echo $complaint['resolution_details'] ? nl2br(htmlspecialchars($complaint['resolution_details'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="view_reports.php?page=<?php echo $page - 1; ?>">« Previous</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="view_reports.php?page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="view_reports.php?page=<?php echo $page + 1; ?>">Next »</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> No complaints match the selected filters.</div>
                    <?php endif; ?>
                    <?php if ($complaints_result) $complaints_result->free(); ?>
                </div>

                <!-- Stereotyped Reports Section -->
                <div class="content-container">
                    <h2>Stereotyped Reports</h2>
                    <?php if (!empty($stereotyped_reports)): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Complaint</th>
                                        <th>Report Type</th>
                                        <th>Handler</th>
                                        <th>Created On</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stereotyped_reports as $report): ?>
                                        <tr>
                                            <td><?php echo $report['id']; ?></td>
                                            <td>
                                                <a href="view_complaint.php?complaint_id=<?php echo $report['complaint_id']; ?>">
                                                    #<?php echo $report['complaint_id']; ?>: <?php echo htmlspecialchars($report['complaint_title']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars(ucfirst($report['report_type'])); ?></td>
                                            <td><?php echo htmlspecialchars($report['submitter_fname'] . ' ' . $report['submitter_lname']); ?></td>
                                            <td><?php echo date('M j, Y H:i', strtotime($report['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($report['details'] ?: 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> No stereotyped reports available.</div>
                    <?php endif; ?>
                </div>
            <?php elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['export_csv'])): ?>
                <div class="content-container">
                    <div class="alert alert-info"><i class="fas fa-info-circle"></i> No complaints match the selected filters.</div>
                </div>
            <?php endif; ?>
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
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) {
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 7000);
            });

            // Table Sorting
            const getCellValue = (tr, idx) => {
                const cell = tr.children[idx];
                if (!cell) return '';
                const sortVal = cell.dataset.sortValue || cell.innerText || cell.textContent;
                return sortVal.trim();
            };

            const comparer = (idx, asc) => (a, b) => ((v1, v2) =>
                v1 !== '' && v2 !== '' && !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.toString().localeCompare(v2)
            )(getCellValue(asc ? a : b, idx), getCellValue(asc ? b : a, idx));

            document.querySelectorAll('#complaintsTable th.sortable').forEach(th => th.addEventListener('click', (() => {
                const table = th.closest('table');
                const tbody = table.querySelector('tbody');
                const headerIndex = Array.from(th.parentNode.children).indexOf(th);
                const currentIsAscending = th.classList.contains('asc');

                table.querySelectorAll('th.sortable').forEach(h => {
                    h.classList.remove('asc', 'desc');
                    const icon = h.querySelector('.sort-icon');
                    if (icon) icon.className = 'fas fa-sort sort-icon';
                });

                const direction = currentIsAscending ? 'desc' : 'asc';
                th.classList.toggle(direction, true);
                const icon = th.querySelector('.sort-icon');
                if (icon) icon.className = `fas fa-sort-${direction} sort-icon`;

                Array.from(tbody.querySelectorAll('tr'))
                    .sort(comparer(headerIndex, direction === 'asc'))
                    .forEach(tr => tbody.appendChild(tr));
            })));

            // Chart Generation
            if (typeof Chart === 'undefined') {
                console.error('Chart.js is not loaded.');
                return;
            }

            const chartInstances = {};

            const chartColors = {
                status: {
                    pending: 'rgba(255, 193, 7, 0.8)',
                    validated: 'rgba(13, 202, 240, 0.8)',
                    in_progress: 'rgba(67, 97, 238, 0.8)',
                    'in-progress': 'rgba(67, 97, 238, 0.8)',
                    resolved: 'rgba(40, 167, 69, 0.8)',
                    rejected: 'rgba(220, 53, 69, 0.8)',
                    escalated: 'rgba(253, 126, 20, 0.8)'
                },
                category: [
                    'rgba(67, 97, 238, 0.8)', 'rgba(111, 66, 193, 0.8)', 'rgba(25, 135, 84, 0.8)',
                    'rgba(253, 126, 20, 0.8)', 'rgba(108, 117, 125, 0.8)', 'rgba(214, 51, 132, 0.8)'
                ],
                department: 'rgba(67, 97, 238, 0.8)',
                trendLine: 'rgba(67, 97, 238, 1)'
            };

            const generateChart = (canvasId, type, labels, data, chartLabel, backgroundColors, borderColors) => {
                const ctx = document.getElementById(canvasId)?.getContext('2d');
                if (!ctx || !labels || labels.length === 0 || !data || data.length === 0) {
                    console.warn(`Chart data missing or canvas not found for ${canvasId}`);
                    return;
                }

                if (chartInstances[canvasId]) {
                    chartInstances[canvasId].destroy();
                }

                chartInstances[canvasId] = new Chart(ctx, {
                    type: type,
                    data: {
                        labels: labels,
                        datasets: [{
                            label: chartLabel,
                            data: data,
                            backgroundColor: backgroundColors,
                            borderColor: borderColors || backgroundColors,
                            borderWidth: type === 'line' ? 2 : 1,
                            tension: type === 'line' ? 0.2 : 0,
                            fill: type === 'line' ? false : true,
                            hoverOffset: type === 'pie' || type === 'doughnut' ? 4 : 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: (type === 'bar' || type === 'line') ? {
                            y: {
                                beginAtZero: true,
                                ticks: { precision: 0 }
                            },
                            x: {
                                ticks: {
                                    autoSkip: true,
                                    maxRotation: 45,
                                    minRotation: 0
                                }
                            }
                        } : {},
                        plugins: {
                            legend: {
                                display: (type === 'pie' || type === 'doughnut' || labels.length > 10) ? true : false,
                                position: (type === 'pie' || type === 'doughnut') ? 'top' : 'bottom',
                            },
                            tooltip: { enabled: true }
                        }
                    }
                });
            };

            <?php if (!empty($stats['status_breakdown'])): ?>
                const statusData = <?php echo json_encode($stats['status_breakdown']); ?>;
                const statusLabels = Object.keys(statusData);
                const statusCounts = Object.values(statusData);
                const statusBackgrounds = statusLabels.map(label => chartColors.status[label.toLowerCase().replace(' ', '_')] || chartColors.department);
                generateChart('statusChart', 'bar', statusLabels, statusCounts, 'Complaints by Status', statusBackgrounds);
            <?php endif; ?>

            <?php if (!empty($stats['category_breakdown'])): ?>
                const categoryData = <?php echo json_encode($stats['category_breakdown']); ?>;
                const categoryLabels = Object.keys(categoryData);
                const categoryCounts = Object.values(categoryData);
                const categoryBackgrounds = categoryLabels.map((_, index) => chartColors.category[index % chartColors.category.length]);
                generateChart('categoryChart', 'pie', categoryLabels, categoryCounts, 'Complaints by Category', categoryBackgrounds);
            <?php endif; ?>

            <?php if (!empty($stats['department_breakdown'])): ?>
                const departmentData = <?php echo json_encode($stats['department_breakdown']); ?>;
                const departmentLabels = Object.keys(departmentData);
                const departmentCounts = Object.values(departmentData);
                const departmentBackgrounds = departmentLabels.map((_, i) => `rgba(67, 97, 238, ${1 - (i*0.08 % 0.6)})`);
                generateChart('departmentChart', 'bar', departmentLabels, departmentCounts, 'Complaints by Department', departmentBackgrounds);
            <?php endif; ?>

            <?php if (!empty($stats['monthly_trend'])): ?>
                const trendData = <?php echo json_encode($stats['monthly_trend']); ?>;
                const trendLabels = Object.keys(trendData);
                const trendCounts = Object.values(trendData);
                generateChart('trendChart', 'line', trendLabels, trendCounts, 'Submissions Over Time', chartColors.trendLine, chartColors.trendLine);
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>