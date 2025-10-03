<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['role'] != 'handler') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null; // Initialize handler variable

// --- Fetch handler details (Copied from dashboard.php/view_assigned_complaints.php) ---
$sql_handler = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_handler = $db->prepare($sql_handler);
if ($stmt_handler) {
    $stmt_handler->bind_param("i", $handler_id);
    $stmt_handler->execute();
    $result_handler = $stmt_handler->get_result();
    if ($result_handler->num_rows > 0) {
        $handler = $result_handler->fetch_assoc();
    } else {
        $_SESSION['error'] = "Handler details not found.";
        header("Location: ../logout.php"); // Or login page
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching handler details.";
    header("Location: ../logout.php"); // Or login page
    exit;
}
// --- End Fetch handler details ---


// Initialize filter variables
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
$start_resolution_date = isset($_POST['start_resolution_date']) ? $_POST['start_resolution_date'] : '';
$end_resolution_date = isset($_POST['end_resolution_date']) ? $_POST['end_resolution_date'] : '';
$category = isset($_POST['category']) ? $_POST['category'] : '';
$status = isset($_POST['status']) ? $_POST['status'] : '';
$visibility = isset($_POST['visibility']) ? $_POST['visibility'] : '';
$handled_by = isset($_POST['handled_by']) ? $_POST['handled_by'] : '';

// Flag to check if a report was generated (form submitted)
$report_generated = ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['export_csv']));

// Build the dynamic query for complaints
$where_clauses = [];
$params = [];
$types = '';

$sql_base = "
    SELECT
        c.*,
        u_submitter.fname as submitter_fname,
        u_submitter.lname as submitter_lname,
        u_handler.fname as handler_fname,
        u_handler.lname as handler_lname
    FROM complaints c
    JOIN users u_submitter ON c.user_id = u_submitter.id
    LEFT JOIN users u_handler ON c.handler_id = u_handler.id
    WHERE 1=1";

// Add filters to the query
if ($start_date) {
    $where_clauses[] = "c.created_at >= ?";
    $params[] = $start_date . ' 00:00:00'; // Ensure start of day
    $types .= 's';
}
if ($end_date) {
    $where_clauses[] = "c.created_at <= ?";
    $params[] = $end_date . ' 23:59:59'; // Ensure end of day
    $types .= 's';
}
if ($start_resolution_date) {
    $where_clauses[] = "c.resolution_date >= ?";
    $params[] = $start_resolution_date . ' 00:00:00'; // Ensure start of day
    $types .= 's';
}
if ($end_resolution_date) {
    $where_clauses[] = "c.resolution_date <= ?";
    $params[] = $end_resolution_date . ' 23:59:59'; // Ensure end of day
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
if ($handled_by) {
    if ($handled_by == 'me') {
        $where_clauses[] = "c.handler_id = ?";
        $params[] = $handler_id;
        $types .= 'i';
    } elseif ($handled_by == 'others') {
        // Ensure handler_id is not null and not the current handler
        $where_clauses[] = "(c.handler_id IS NOT NULL AND c.handler_id != ?)";
        $params[] = $handler_id;
        $types .= 'i';
    }
    // Add more conditions if needed, e.g., 'unassigned'
}

// Add WHERE clauses to the query
if (!empty($where_clauses)) {
    $sql_base .= " AND " . implode(" AND ", $where_clauses);
}

// Order the results
$sql_base .= " ORDER BY c.created_at DESC";

$stmt = $db->prepare($sql_base);
$complaints_data = []; // Store fetched data for potential re-use (CSV export)
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $complaints_result = $stmt->get_result();
    if ($complaints_result) {
        while ($row = $complaints_result->fetch_assoc()) {
            $complaints_data[] = $row; // Store data
        }
        // Reset pointer if needed later, e.g., for CSV export after displaying table
        // $complaints_result->data_seek(0);
    } else {
         error_log("Error getting result for complaints query: " . $stmt->error);
        $_SESSION['error'] = "Database error fetching complaints results.";
    }
    $stmt->close();
} else {
    error_log("Error preparing complaints query: " . $db->error);
    $_SESSION['error'] = "Database error preparing to fetch complaints.";
}


// Fetch summary statistics only if report was generated
$stats = [
    'total_complaints' => 0,
    'status_breakdown' => [],
    'category_breakdown' => [],
    'avg_resolution_time' => 0
];

if ($report_generated || isset($_POST['export_csv'])) {
    $stats['total_complaints'] = count($complaints_data);

    // Calculate breakdowns from fetched data
    foreach ($complaints_data as $complaint) {
        // Status breakdown
        $current_status = $complaint['status'] ?? 'unknown';
        $stats['status_breakdown'][$current_status] = ($stats['status_breakdown'][$current_status] ?? 0) + 1;

        // Category breakdown
        $current_category = $complaint['category'] ?? 'unknown';
        $stats['category_breakdown'][$current_category] = ($stats['category_breakdown'][$current_category] ?? 0) + 1;
    }

    // Calculate average resolution time from fetched data (for resolved)
    $total_resolution_hours = 0;
    $resolved_count = 0;
    foreach ($complaints_data as $complaint) {
        if ($complaint['status'] == 'resolved' && $complaint['resolution_date'] && $complaint['created_at']) {
             try {
                 $created = new DateTime($complaint['created_at']);
                 $resolved = new DateTime($complaint['resolution_date']);
                 $diff = $resolved->getTimestamp() - $created->getTimestamp(); // Difference in seconds
                 if ($diff >= 0) {
                     $total_resolution_hours += $diff / 3600; // Convert seconds to hours
                     $resolved_count++;
                 }
             } catch (Exception $e) {
                 // Handle potential date parsing errors if necessary
                 error_log("Date parsing error for complaint ID " . $complaint['id'] . ": " . $e->getMessage());
             }
        }
    }
    $stats['avg_resolution_time'] = ($resolved_count > 0) ? round($total_resolution_hours / $resolved_count, 2) : 0;
}


// Handle CSV export
if (isset($_POST['export_csv'])) {
    // Check if there is data to export
    if (empty($complaints_data)) {
         $_SESSION['warning'] = "No data matching the filters to export.";
         // Redirect back to the report page to show the message
         header("Location: generate_report.php");
         exit;
    }

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="complaints_report_' . date('Y-m-d') . '.csv"');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Write UTF-8 BOM if needed (for Excel compatibility with special characters)
    fputs($output, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

    // Write CSV headers
    fputcsv($output, [
        'ID', 'Title', 'Category', 'Submitted By', 'Handled By', 'Visibility',
        'Status', 'Submitted On', 'Resolved On', 'Resolution Details'
    ]);

    // Write CSV rows using the stored data
    foreach ($complaints_data as $complaint) {
        fputcsv($output, [
            $complaint['id'],
            $complaint['title'],
            $complaint['category'] ? ucfirst($complaint['category']) : 'N/A',
            ($complaint['visibility'] == 'anonymous' && $complaint['status'] != 'resolved') ? 'Anonymous' : ($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']),
            $complaint['handler_fname'] ? $complaint['handler_fname'] . ' ' . $complaint['handler_lname'] : 'N/A',
            ucfirst($complaint['visibility']),
            ucfirst($complaint['status']),
            $complaint['created_at'] ? date("Y-m-d H:i:s", strtotime($complaint['created_at'])) : 'N/A',
            $complaint['resolution_date'] ? date("Y-m-d H:i:s", strtotime($complaint['resolution_date'])) : 'N/A',
            $complaint['resolution_details'] ?? 'N/A'
        ]);
    }

    fclose($output);
    exit; // Stop script execution after sending the file
}

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Report | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Include Chart.js for graphical representation -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Paste the FULL CSS from view_assigned_complaints.php or dashboard.php here */
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
            --info: #17a2b8; /* Changed from #0dcaf0 to #17a2b8 for better contrast? */
            --orange: #fd7e14;
            --background: #f4f7f6; /* Slightly off-white background */
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 10px; /* Uniform radius */
            --radius-lg: 15px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08); /* Softer shadow */
            --shadow-hover: 0 6px 18px rgba(0, 0, 0, 0.12); /* Hover shadow */
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50; /* Dark blue for nav */
            --navbar-link: #bdc3c7; /* Light grey links */
            --navbar-link-hover: #34495e; /* Slightly darker hover */
            --navbar-link-active: var(--primary); /* Use primary color for active */
            --topbar-bg: #ffffff;
            --topbar-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Montserrat', sans-serif;
        }

        body {
            background-color: var(--background);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
        }

        /* Vertical Navigation */
        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--navbar-bg) 0%, #34495e 100%); /* Gradient nav */
            color: #ecf0f1; /* Light text */
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            transition: width 0.3s ease;
            z-index: 1000;
        }

        .nav-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(236, 240, 241, 0.1); /* Lighter border */
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 12px; /* Slightly more gap */
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%; /* Circular logo */
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 600; /* Slightly less bold */
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.05); /* Subtle background */
            padding: 8px 12px;
            border-radius: var(--radius);
            margin-top: 10px;
        }

        .user-profile-mini i {
            font-size: 2rem; /* Smaller icon */
            color: var(--accent); /* Accent color */
        }

        .user-info h4 {
            font-size: 0.95rem; /* Slightly larger */
            margin-bottom: 2px;
            font-weight: 500;
        }

        .user-info p {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: capitalize;
        }

        .nav-menu {
            padding: 0 10px;
            flex-grow: 1;
            overflow-y: auto;
        }
        /* Custom scrollbar for nav menu */
        .nav-menu::-webkit-scrollbar { width: 6px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px;}
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px;}
        .nav-menu::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }


        .nav-menu h3 {
            font-size: 0.8rem; /* Smaller headings */
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 25px 15px 10px; /* Adjust spacing */
            opacity: 0.6; /* More subtle */
            font-weight: 600;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 15px; /* More gap */
            padding: 12px 20px; /* Adjust padding */
            color: var(--navbar-link);
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
            font-size: 0.95rem;
            font-weight: 400;
        }

        .nav-link:hover {
            background: var(--navbar-link-hover);
            color: #ecf0f1; /* White text on hover */
            transform: translateX(3px);
        }
        .nav-link.active {
            background: var(--navbar-link-active);
            color: white;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3); /* Add shadow to active link */
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1em; /* Slightly larger icons */
            opacity: 0.8;
        }
        .nav-link.active i {
            opacity: 1;
        }


        /* Main Content */
        .main-content {
            flex: 1;
            padding: 25px; /* Consistent padding */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        /* Horizontal Navigation */
        .horizontal-nav {
            background: var(--topbar-bg);
            border-radius: var(--radius);
            box-shadow: var(--topbar-shadow);
            padding: 12px 25px; /* Adjust padding */
            margin-bottom: 25px; /* Space below */
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .horizontal-nav .logo span {
             font-size: 1.2rem; /* Slightly larger */
             font-weight: 600;
             color: var(--primary-dark);
        }

        .horizontal-menu {
            display: flex;
            align-items: center; /* Vertically align items */
            gap: 15px; /* Spacing */
        }

        .horizontal-menu a {
            color: var(--dark);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px; /* Icon spacing */
            font-size: 0.95rem;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary-light); /* Lighter primary bg */
            color: var(--primary-dark); /* Darker primary text */
        }
        .horizontal-menu a i {
             font-size: 1rem;
             color: var(--grey); /* Grey icons */
        }
         .horizontal-menu a:hover i, .horizontal-menu a.active i {
             color: var(--primary-dark); /* Darker icon color on hover/active */
         }

        .notification-icon { /* Specific style for notification icon */
            position: relative;
        }
        .notification-icon i {
            font-size: 1.3rem;
            color: var(--grey);
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .notification-icon:hover i {
            color: var(--primary);
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
            box-shadow: 0 3px 8px rgba(0,0,0,0.07); /* Slightly stronger shadow */
        }
        .alert i { font-size: 1.2rem; margin-right: 5px;}
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-warning { background-color: #fff8e1; border-color: #ffecb3; color: #856404; }
        .alert-info { background-color: #e1f5fe; border-color: #b3e5fc; color: #01579b; }


        /* Content Container (Used for filter form and results) */
        .content-container {
            background: var(--card-bg);
            padding: 2rem; /* More padding */
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
            flex-grow: 1; /* Takes remaining vertical space */
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Page Header Styling */
         .page-header h2 {
             font-size: 1.8rem; font-weight: 600; color: var(--primary-dark);
             margin-bottom: 25px; border-bottom: 3px solid var(--primary); /* Thicker border */
             padding-bottom: 10px; display: inline-block;
         }

        /* Card Styling (Used for filter section and report results) */
         .card {
             background-color: var(--card-bg); padding: 25px; border-radius: var(--radius);
             box-shadow: var(--shadow); margin-bottom: 30px; border: 1px solid var(--border-color);
         }
         .card-header {
             display: flex; justify-content: space-between; align-items: center; /* Align items */
             gap: 12px; margin-bottom: 25px;
             color: var(--primary-dark); font-size: 1.3rem; font-weight: 600;
             padding-bottom: 15px; border-bottom: 1px solid var(--border-color);
         }
         .card-header i { font-size: 1.4rem; color: var(--primary); margin-right: 8px;} /* Slightly smaller icon */


        /* Filter Form Specifics */
        .filter-form {
            display: grid; /* Use grid for better layout */
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); /* Responsive columns */
            gap: 20px;
        }
        .form-group label {
            display: block; margin-bottom: 6px; font-weight: 500; color: var(--dark); font-size: 0.9rem;
        }
        .form-group input[type="date"], .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border-color);
            border-radius: var(--radius); font-size: 0.95rem; color: var(--text-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: var(--primary); outline: none; box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }
        .form-group.full-width { /* Class for button group spanning full width */
            grid-column: 1 / -1; /* Span all columns */
            text-align: right; /* Align button right */
            margin-top: 10px;
        }
        .form-group label[for="submit_button"] { /* Hide label for button if not needed */
            display: none;
        }


        /* Report Results Specifics */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); /* Responsive grid */
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
             padding: 20px; background-color: var(--card-bg);
             border-radius: var(--radius); box-shadow: var(--shadow); border-left: 4px solid; /* Side border */
             text-align: center; transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        .stat-card h4 { font-size: 1rem; margin-bottom: 8px; color: var(--text-muted); font-weight: 500; }
        .stat-card p { font-size: 1.8rem; font-weight: 600; color: var(--dark); margin: 0; }
        /* Specific colors for stat cards */
        .stat-card.total { border-left-color: var(--primary); }
        .stat-card.resolved { border-left-color: var(--success); }
        .stat-card.avg-time { border-left-color: var(--info); }


        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        .chart-card { /* Wrap each chart in a card */
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }
        .chart-card h4 {
            text-align: center;
            margin-bottom: 15px;
            font-size: 1.1rem;
            color: var(--primary-dark);
            font-weight: 500;
        }
        .chart-container {
            position: relative; /* Needed for chart responsiveness */
            height: 300px; /* Fixed height for charts */
            width: 100%;
        }

        /* Table Specifics */
        .table-responsive { overflow-x: auto; width: 100%; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px 15px; /* Adjust padding */
            text-align: left; border-bottom: 1px solid var(--border-color);
            vertical-align: middle; font-size: 0.9rem;
        }
        th {
            background-color: #f8f9fa; font-weight: 600; color: var(--dark);
            white-space: nowrap; cursor: pointer; position: relative; /* For sort icon */
            padding-right: 25px; /* Space for sort icon */
        }
        th:hover { background-color: #e9ecef; }
        /* Sort icons */
        th::after {
            content: '\f0dc'; /* Default sort icon */
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.3;
            transition: opacity 0.2s ease;
        }
        th:hover::after { opacity: 0.6; }
        th.asc::after { content: '\f0de'; opacity: 1; } /* Up arrow */
        th.desc::after { content: '\f0dd'; opacity: 1; } /* Down arrow */

        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: #f1f5f9; }
        td .resolution-details-cell {
            max-width: 300px; /* Adjust width */
            white-space: normal; word-wrap: break-word;
            font-size: 0.85rem; color: var(--text-muted); line-height: 1.4;
        }
        .text-muted { color: var(--text-muted); font-style: italic; }

        /* Status Badges */
        .status {
            padding: 4px 10px; border-radius: var(--radius); font-size: 0.75rem;
            font-weight: 600; text-transform: capitalize; letter-spacing: 0.5px;
            color: #fff; text-align: center; display: inline-block; line-height: 1.2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); white-space: nowrap;
        }
        .status-pending { background-color: var(--warning); color: var(--dark); }
        .status-validated { background-color: var(--info); }
        .status-in_progress { background-color: var(--primary); }
        .status-resolved { background-color: var(--success); }
        .status-rejected { background-color: var(--danger); }
        .status-pending_more_info { background-color: var(--orange); }
        .status-unknown { background-color: var(--grey); } /* For unknown status */


        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 500;
            cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5;
            white-space: nowrap;
        }
        .btn i { font-size: 1em; line-height: 1;}
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; } /* Adjust small button */
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 2px 5px rgba(67,97,238,0.3); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(220,53,69,0.3); }
        .btn-success { background-color: var(--success); color: #fff; }
        .btn-success:hover { background-color: #218838; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(40,167,69,0.3); }


        /* Footer */
        .main-footer {
            background-color: var(--card-bg); padding: 15px 30px; margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center; font-size: 0.9rem; color: var(--text-muted);
            flex-shrink: 0; /* Prevent shrinking */
            transition: margin-left 0.3s ease;
        }

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .vertical-nav { width: 75px; }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info, .vertical-nav .nav-menu h3, .vertical-nav .nav-link span { display: none; }
            .vertical-nav .nav-header .user-profile-mini i { font-size: 1.8rem; }
            .vertical-nav .user-profile-mini { padding: 8px; justify-content: center;}
            .vertical-nav .nav-link { justify-content: center; padding: 15px 10px; }
            .vertical-nav .nav-link i { margin-right: 0; font-size: 1.3rem; }
            .main-content { margin-left: 75px; }
            .horizontal-nav { left: 75px; }
            .main-footer { margin-left: 75px; }
        }
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav {
                 width: 100%; height: auto; position: relative; box-shadow: none;
                 border-bottom: 2px solid var(--primary-dark); flex-direction: column;
             }
             .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block;} /* Re-show some text */
             .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 10px;}
             .nav-menu { display: flex; flex-wrap: wrap; justify-content: center; padding: 5px 0; overflow-y: visible;}
             .nav-menu h3 { display: none; }
             .nav-link { flex-direction: row; width: auto; padding: 8px 12px; }
             .nav-link i { margin-right: 8px; margin-bottom: 0; font-size: 1rem; }
             .nav-link span { display: inline; font-size: 0.85rem; }


            .horizontal-nav {
                position: static; left: auto; right: auto; width: 100%;
                padding: 10px 15px; height: auto; flex-direction: column; align-items: stretch;
                border-radius: 0;
            }
            .top-nav-left { padding: 5px 0; text-align: center;}
            .top-nav-right { padding-top: 5px; justify-content: center; gap: 15px;}
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .main-footer { margin-left: 0; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; flex-direction: column; align-items: flex-start;}
            .card-header form { margin-left: 0; margin-top: 10px; width: 100%; text-align: right;}
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            th, td { font-size: 0.85rem; padding: 10px 8px; }
            .filter-form { grid-template-columns: 1fr; } /* Stack filters */
            .stats-container { grid-template-columns: 1fr; } /* Stack stats */
            .charts-container { grid-template-columns: 1fr; } /* Stack charts */
            .resolution-details-cell { max-width: 200px; }
        }
        @media (max-width: 576px) {
            .content-container { padding: 1rem; }
            .card { padding: 15px;}
            .page-header h2 { font-size: 1.3rem; }
            th, td { font-size: 0.8rem; padding: 8px 6px;}
            .btn { padding: 7px 12px; font-size: 0.85rem; }
            .btn-small { padding: 5px 10px; font-size: 0.75rem;}
            .horizontal-nav .logo span { font-size: 1.1rem;}
             .nav-header .logo-text { font-size: 1.1rem;}
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
            <?php if ($handler): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($handler['fname'] . ' ' . $handler['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($handler['role']); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
             <a href="view_assigned_complaints.php" class="nav-link <?php echo $current_page == 'view_assigned_complaints.php' ? 'active' : ''; ?>">
                 <i class="fas fa-list-alt fa-fw"></i>
                 <span>Assigned Complaints</span>
             </a>
             <a href="view_resolved.php" class="nav-link <?php echo $current_page == 'view_resolved.php' ? 'active' : ''; ?>">
                 <i class="fas fa-check-circle fa-fw"></i>
                 <span>Resolved Complaints</span>
             </a>
             <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i><span>Decisions Received</span>
            </a>
            <a href="send_decision.php" class="nav-link <?php echo $current_page == 'send_decision.php' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i><span>Send Decision</span>
            </a>

            <h3>Communication</h3>
             <a href="manage_notices.php" class="nav-link <?php echo $current_page == 'manage_notices.php' ? 'active' : ''; ?>">
                 <i class="fas fa-bullhorn fa-fw"></i>
                 <span>Manage Notices</span>
             </a>
             <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                 <i class="fas fa-bell fa-fw"></i>
                 <span>View Notifications</span>
             </a>
              <a href="view_decisions.php" class="nav-link <?php echo $current_page == 'view_decisions.php' ? 'active' : ''; ?>">
                <i class="fas fa-gavel"></i>
                <span>Decisions Received</span>
            </a>
             <a href="view_feedback.php" class="nav-link <?php echo $current_page == 'view_feedback.php' ? 'active' : ''; ?>">
                 <i class="fas fa-comment-dots fa-fw"></i>
                 <span>Complaint Feedback</span>
             </a>

            <h3>Reports</h3>
            <a href="generate_report.php" class="nav-link <?php echo $current_page == 'generate_report.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt fa-fw"></i>
                <span>Generate Report</span>
            </a>

            <h3>Account</h3>
             <a href="change_password.php" class="nav-link <?php echo $current_page == 'change_password.php' ? 'active' : ''; ?>">
                 <i class="fas fa-key fa-fw"></i>
                 <span>Change Password</span>
             </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="main-content">
        <!-- Horizontal Navigation -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System</span>
            </div>
            <div class="horizontal-menu">
                 <a href="../index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Home
                </a>
                <div class="notification-icon" title="View Notifications">
                    <a href="view_notifications.php" style="color: inherit; text-decoration: none;">
                        <i class="fas fa-bell"></i>
                        <!-- Optional: Add notification count badge here -->
                    </a>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-small" title="Logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>

        <!-- Page Specific Content -->
        <div class="content-container">
            <div class="page-header">
                <h2>Generate Complaints Report</h2>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['warning'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>

            <!-- Filter Form Card -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-filter"></i> Filter Complaints
                </div>
                <div class="card-body">
                    <form method="POST" class="filter-form">
                        <div class="form-group">
                            <label for="start_date">Submission Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">Submission End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="form-group">
                            <label for="start_resolution_date">Resolution Start Date</label>
                            <input type="date" id="start_resolution_date" name="start_resolution_date" value="<?php echo htmlspecialchars($start_resolution_date); ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_resolution_date">Resolution End Date</label>
                            <input type="date" id="end_resolution_date" name="end_resolution_date" value="<?php echo htmlspecialchars($end_resolution_date); ?>">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="">All Categories</option>
                                <option value="academic" <?php echo $category == 'academic' ? 'selected' : ''; ?>>Academic</option>
                                <option value="administrative" <?php echo $category == 'administrative' ? 'selected' : ''; ?>>Administrative</option>
                                <!-- Add other categories if any -->
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
                                <option value="pending_more_info" <?php echo $status == 'pending_more_info' ? 'selected' : ''; ?>>Pending More Info</option>
                                <!-- Add other statuses if any -->
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
                            <label for="handled_by">Handled By</label>
                            <select id="handled_by" name="handled_by">
                                <option value="">All Handlers</option>
                                <option value="me" <?php echo $handled_by == 'me' ? 'selected' : ''; ?>>Me</option>
                                <option value="others" <?php echo $handled_by == 'others' ? 'selected' : ''; ?>>Others</option>
                                <!-- Add 'unassigned' if needed -->
                            </select>
                        </div>
                        <div class="form-group full-width">
                             <label id="submit_button"> </label>
                             <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Generate Report</button>
                         </div>
                    </form>
                </div>
            </div>

            <!-- Report Results Area (Only show if form submitted) -->
            <?php if ($report_generated): ?>
                <!-- Summary Statistics -->
                <div class="stats-container">
                    <div class="stat-card total">
                        <h4>Total Complaints</h4>
                        <p><?php echo $stats['total_complaints']; ?></p>
                    </div>
                    <div class="stat-card resolved">
                        <h4>Resolved</h4>
                        <p><?php echo $stats['status_breakdown']['resolved'] ?? 0; ?></p>
                    </div>
                    <div class="stat-card avg-time">
                        <h4>Avg. Resolution</h4>
                        <p><?php echo $stats['avg_resolution_time'] ? $stats['avg_resolution_time'] . ' hrs' : 'N/A'; ?></p>
                    </div>
                     <!-- Add more stat cards if needed -->
                </div>

                <!-- Charts -->
                <div class="charts-container">
                    <div class="chart-card">
                        <h4>Complaints by Status</h4>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                     <div class="chart-card">
                         <h4>Complaints by Category</h4>
                         <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                     </div>
                </div>

                <!-- Complaints Table Card -->
                <div class="card">
                    <div class="card-header">
                        <div><i class="fas fa-file-alt"></i> Complaints Report Details</div>
                        <!-- Export Button Form -->
                        <form method="POST" style="display: inline;">
                            <!-- Pass all filters again for export -->
                            <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                            <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                            <input type="hidden" name="start_resolution_date" value="<?php echo htmlspecialchars($start_resolution_date); ?>">
                            <input type="hidden" name="end_resolution_date" value="<?php echo htmlspecialchars($end_resolution_date); ?>">
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                            <input type="hidden" name="visibility" value="<?php echo htmlspecialchars($visibility); ?>">
                            <input type="hidden" name="handled_by" value="<?php echo htmlspecialchars($handled_by); ?>">
                            <button type="submit" name="export_csv" class="btn btn-success btn-small">
                                <i class="fas fa-download"></i> Export as CSV
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($complaints_data)): ?>
                            <div class="table-responsive">
                                <table id="complaintsTable">
                                    <thead>
                                        <tr>
                                            <th data-sort="id">ID</th>
                                            <th data-sort="title">Title</th>
                                            <th data-sort="category">Category</th>
                                            <th data-sort="submitter">Submitted By</th>
                                            <th data-sort="handler">Handled By</th>
                                            <th data-sort="visibility">Visibility</th>
                                            <th data-sort="status">Status</th>
                                            <th data-sort="created_at">Submitted On</th>
                                            <th data-sort="resolution_date">Resolved On</th>
                                            <th>Resolution Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($complaints_data as $complaint): ?>
                                            <tr>
                                                <td><?php echo $complaint['id']; ?></td>
                                                <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                                <td><?php echo $complaint['category'] ? htmlspecialchars(ucfirst($complaint['category'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                                <td><?php echo ($complaint['visibility'] == 'anonymous' && $complaint['status'] != 'resolved') ? '<span class="text-muted">Anonymous</span>' : htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></td>
                                                <td><?php echo $complaint['handler_fname'] ? htmlspecialchars($complaint['handler_fname'] . ' ' . $complaint['handler_lname']) : '<span class="text-muted">N/A</span>'; ?></td>
                                                <td><?php echo htmlspecialchars(ucfirst($complaint['visibility'])); ?></td>
                                                <td>
                                                    <span class="status status-<?php echo strtolower(htmlspecialchars($complaint['status'] ?? 'unknown')); ?>">
                                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $complaint['status'] ?? 'Unknown'))); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $complaint['created_at'] ? date("M j, Y, g:i a", strtotime($complaint['created_at'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                                <td><?php echo $complaint['resolution_date'] ? date("M j, Y, g:i a", strtotime($complaint['resolution_date'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                                <td class="resolution-details-cell"><?php echo $complaint['resolution_details'] ? nl2br(htmlspecialchars($complaint['resolution_details'])) : '<span class="text-muted">N/A</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: var(--text-muted); padding: 20px 0;">No complaints match the selected filters.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                 <div class="card">
                     <div class="card-body">
                        <p style="text-align: center; color: var(--text-muted); padding: 20px 0;">No complaints match the selected filters.</p>
                    </div>
                 </div>
            <?php endif; ?>
        </div> <!-- End Content Container -->

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Handler Panel
        </footer>
    </div> <!-- End Main Content -->


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert) { // Check if alert still exists
                        alert.style.transition = 'opacity 0.5s ease';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.remove(), 500);
                    }
                }, 7000); // 7 seconds
            });

            // Table sorting function
            function sortTableByColumn(table, column, asc = true) {
                const dirModifier = asc ? 1 : -1;
                const tBody = table.tBodies[0];
                const rows = Array.from(tBody.querySelectorAll("tr"));

                // Sort each row
                const sortedRows = rows.sort((a, b) => {
                    let aColText = a.querySelector(`td:nth-child(${ column + 1 })`)?.textContent.trim().toLowerCase() || '';
                    let bColText = b.querySelector(`td:nth-child(${ column + 1 })`)?.textContent.trim().toLowerCase() || '';

                    // Attempt numeric sort for ID and date columns
                    const header = table.querySelector(`th:nth-child(${ column + 1 })`);
                    const sortKey = header?.dataset.sort;

                    if (sortKey === 'id') {
                         aColText = parseInt(aColText) || 0;
                         bColText = parseInt(bColText) || 0;
                    } else if (sortKey === 'created_at' || sortKey === 'resolution_date') {
                         // Handle 'N/A' or invalid dates gracefully
                         aColText = aColText === 'n/a' ? 0 : new Date(aColText).getTime() || 0;
                         bColText = bColText === 'n/a' ? 0 : new Date(bColText).getTime() || 0;
                    }

                    return aColText > bColText ? (1 * dirModifier) : (-1 * dirModifier);
                });

                // Remove all existing TRs from the table
                while (tBody.firstChild) {
                    tBody.removeChild(tBody.firstChild);
                }

                // Re-add the newly sorted rows
                tBody.append(...sortedRows);

                // Remember how the column is currently sorted
                table.querySelectorAll("th").forEach(th => th.classList.remove("asc", "desc"));
                table.querySelector(`th:nth-child(${ column + 1 })`).classList.toggle("asc", asc);
                table.querySelector(`th:nth-child(${ column + 1 })`).classList.toggle("desc", !asc);
            }

            const tableElement = document.getElementById('complaintsTable');
            if (tableElement) {
                tableElement.querySelectorAll("th[data-sort]").forEach((headerCell, index) => {
                    headerCell.addEventListener("click", () => {
                        const currentIsAscending = headerCell.classList.contains("asc");
                        sortTableByColumn(tableElement, index, !currentIsAscending);
                    });
                });
            }


            // --- Chart Generation ---
            const statusData = <?php echo json_encode($stats['status_breakdown'] ?? []); ?>;
            const categoryData = <?php echo json_encode($stats['category_breakdown'] ?? []); ?>;

            const statusColors = {
                'pending': 'rgba(255, 193, 7, 0.7)', // warning
                'validated': 'rgba(23, 162, 184, 0.7)', // info (use var(--info))
                'in_progress': 'rgba(67, 97, 238, 0.7)', // primary
                'resolved': 'rgba(40, 167, 69, 0.7)', // success
                'rejected': 'rgba(220, 53, 69, 0.7)', // danger
                'pending_more_info': 'rgba(253, 126, 20, 0.7)', // orange
                'unknown': 'rgba(108, 117, 125, 0.7)' // grey
            };
             const categoryColors = {
                'academic': 'rgba(67, 97, 238, 0.7)', // primary
                'administrative': 'rgba(40, 167, 69, 0.7)', // success
                'unknown': 'rgba(108, 117, 125, 0.7)' // grey
             };


            // Status Breakdown Chart
            const statusCtx = document.getElementById('statusChart')?.getContext('2d');
            if (statusCtx && Object.keys(statusData).length > 0) {
                 const statusLabels = Object.keys(statusData);
                 const statusCounts = Object.values(statusData);
                 const backgroundColors = statusLabels.map(label => statusColors[label] || statusColors['unknown']);

                 new Chart(statusCtx, {
                     type: 'doughnut', // Changed to doughnut
                     data: {
                         labels: statusLabels.map(l => l.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase())), // Format labels
                         datasets: [{
                             label: 'Complaints by Status',
                             data: statusCounts,
                             backgroundColor: backgroundColors,
                             borderColor: '#fff', // White border for segments
                             borderWidth: 2
                         }]
                     },
                     options: {
                         responsive: true,
                         maintainAspectRatio: false,
                         plugins: {
                            legend: { position: 'bottom' },
                            title: { display: false } // Title moved to card header
                        }
                     }
                 });
            }

            // Category Breakdown Chart
            const categoryCtx = document.getElementById('categoryChart')?.getContext('2d');
            if (categoryCtx && Object.keys(categoryData).length > 0) {
                const categoryLabels = Object.keys(categoryData);
                const categoryCounts = Object.values(categoryData);
                const backgroundColors = categoryLabels.map(label => categoryColors[label] || categoryColors['unknown']);

                 new Chart(categoryCtx, {
                     type: 'pie', // Changed to pie
                     data: {
                         labels: categoryLabels.map(l => l.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase())), // Format labels
                         datasets: [{
                             label: 'Complaints by Category',
                             data: categoryCounts,
                             backgroundColor: backgroundColors,
                             borderColor: '#fff',
                             borderWidth: 2
                         }]
                     },
                    options: {
                         responsive: true,
                         maintainAspectRatio: false,
                          plugins: {
                            legend: { position: 'bottom' },
                            title: { display: false } // Title moved to card header
                        }
                     }
                 });
            }

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