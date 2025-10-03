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
    header("Location: ../login.php"); // Redirect to login for unauthorized roles
    exit;
}

$handler_id = $_SESSION['user_id'];
$handler = null; // Initialize handler variable

// --- Fetch handler details ---
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
        header("Location: ../logout.php"); // Logout if handler not found
        exit;
    }
    $stmt_handler->close();
} else {
    error_log("Error preparing handler query: " . $db->error);
    $_SESSION['error'] = "Database error fetching handler details.";
    header("Location: ../logout.php"); // Logout on DB error
    exit;
}
// --- End Fetch handler details ---


// Initialize variables
$complaint_id = isset($_GET['complaint_id']) && is_numeric($_GET['complaint_id']) ? (int)$_GET['complaint_id'] : null;
$complaint = null;
$complaint_stereotypes = [];
$all_stereotypes = [];

// Fetch complaint details if complaint_id is provided
if ($complaint_id) {
    $stmt = $db->prepare("
        SELECT c.*, u.fname AS submitter_fname, u.lname AS submitter_lname
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $complaint_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $_SESSION['error'] = "Complaint #{$complaint_id} not found or you do not have permission to view it.";
            // Redirect intelligently based on where user likely came from
             if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'view_complaint.php') !== false) {
                 header("Location: " . $_SERVER['HTTP_REFERER']);
             } else {
                 header("Location: dashboard.php");
             }
             exit;
        }
        $complaint = $result->fetch_assoc();
        $stmt->close();

        // Fetch stereotypes associated with this specific complaint
        $stmt_cs = $db->prepare("
            SELECT s.id, s.label, s.description
            FROM stereotypes s
            JOIN complaint_stereotypes cs ON s.id = cs.stereotype_id
            WHERE cs.complaint_id = ?
        ");
        if ($stmt_cs) {
             $stmt_cs->bind_param("i", $complaint_id);
             $stmt_cs->execute();
             $complaint_stereotypes_result = $stmt_cs->get_result();
              if ($complaint_stereotypes_result) {
                    $complaint_stereotypes = $complaint_stereotypes_result->fetch_all(MYSQLI_ASSOC);
              }
              $stmt_cs->close();
         } else {
              error_log("Error preparing complaint stereotypes query: " . $db->error);
             $_SESSION['error'] = "Database error fetching complaint stereotypes.";
         }
    } else {
        error_log("Error preparing complaint details query: " . $db->error);
        $_SESSION['error'] = "Database error fetching complaint details.";
        header("Location: dashboard.php");
        exit;
    }
}

// Fetch all available stereotypes regardless of whether a complaint_id is set
$stmt_all = $db->prepare("SELECT id, label, description FROM stereotypes ORDER BY label ASC");
if ($stmt_all) {
    $stmt_all->execute();
    $all_stereotypes_result = $stmt_all->get_result();
     if ($all_stereotypes_result) {
         $all_stereotypes = $all_stereotypes_result->fetch_all(MYSQLI_ASSOC);
     }
    $stmt_all->close();
} else {
     error_log("Error preparing all stereotypes query: " . $db->error);
    $_SESSION['error'] = "Database error fetching stereotypes list.";
}


// Handle form submission to add a new stereotype
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_stereotype'])) {
    $label = trim($_POST['label'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($label)) {
        $_SESSION['error'] = "Stereotype label is required.";
    } else {
        // Check if the label already exists (case-insensitive check is often better)
        $stmt = $db->prepare("SELECT id FROM stereotypes WHERE LOWER(label) = LOWER(?)");
        if ($stmt) {
            $lower_label = strtolower($label);
            $stmt->bind_param("s", $lower_label);
            $stmt->execute();
            $result_check = $stmt->get_result();
            if ($result_check && $result_check->num_rows > 0) {
                $_SESSION['error'] = "A stereotype with this label already exists.";
            } else {
                // Insert the new stereotype
                $stmt_insert = $db->prepare("INSERT INTO stereotypes (label, description, created_at) VALUES (?, ?, NOW())");
                if ($stmt_insert) {
                    $stmt_insert->bind_param("ss", $label, $description);
                    if ($stmt_insert->execute()) {
                        $_SESSION['success'] = "Stereotype '$label' added successfully.";
                        // Redirect to refresh the page and list
                        header("Location: stereotype.php" . ($complaint_id ? "?complaint_id=$complaint_id" : ""));
                        exit;
                    } else {
                        $_SESSION['error'] = "Failed to add stereotype: " . $db->error;
                        error_log("Stereotype insert error: " . $stmt_insert->error);
                    }
                     $stmt_insert->close();
                } else {
                     $_SESSION['error'] = "Database error preparing insert statement.";
                     error_log("Stereotype prepare insert error: " . $db->error);
                }
            }
             $stmt->close();
        } else {
             $_SESSION['error'] = "Database error checking stereotype existence.";
             error_log("Stereotype prepare check error: " . $db->error);
        }
    }
    // Redirect back to the form if there was an error to show the message
    header("Location: stereotype.php" . ($complaint_id ? "?complaint_id=$complaint_id" : ""));
    exit;
}

// Handle form submission to tag a complaint with a stereotype
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tag_complaint']) && $complaint_id) {
    $stereotype_id = isset($_POST['stereotype_id']) && is_numeric($_POST['stereotype_id']) ? (int)$_POST['stereotype_id'] : null;

    if (!$stereotype_id) {
        $_SESSION['error'] = "Please select a stereotype to tag.";
    } else {
        // Check if the complaint is already tagged with this stereotype
        $stmt_check = $db->prepare("SELECT complaint_id FROM complaint_stereotypes WHERE complaint_id = ? AND stereotype_id = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("ii", $complaint_id, $stereotype_id);
            $stmt_check->execute();
            $result_check_tag = $stmt_check->get_result();
            if ($result_check_tag && $result_check_tag->num_rows > 0) {
                $_SESSION['warning'] = "This complaint is already tagged with the selected stereotype."; // Changed to warning
            } else {
                // Tag the complaint
                $stmt_tag = $db->prepare("INSERT INTO complaint_stereotypes (complaint_id, stereotype_id, tagged_by, created_at) VALUES (?, ?, ?, NOW())");
                if ($stmt_tag) {
                    $stmt_tag->bind_param("iii", $complaint_id, $stereotype_id, $handler_id); // Use $handler_id
                    if ($stmt_tag->execute()) {
                        $_SESSION['success'] = "Complaint tagged successfully.";
                    } else {
                        $_SESSION['error'] = "Failed to tag complaint: " . $db->error;
                        error_log("Tagging error: " . $stmt_tag->error);
                    }
                     $stmt_tag->close();
                } else {
                     $_SESSION['error'] = "Database error preparing tag statement.";
                      error_log("Tagging prepare error: " . $db->error);
                }
            }
             $stmt_check->close();
        } else {
            $_SESSION['error'] = "Database error checking tag existence.";
            error_log("Tagging check prepare error: " . $db->error);
        }
    }
    // Redirect back to the same page to show messages and updated list
    header("Location: stereotype.php?complaint_id=$complaint_id");
    exit;
}

// Handle request to untag a stereotype from a complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['untag_stereotype']) && $complaint_id) {
    $stereotype_to_untag_id = isset($_POST['stereotype_id']) && is_numeric($_POST['stereotype_id']) ? (int)$_POST['stereotype_id'] : null;

    if ($stereotype_to_untag_id) {
        $stmt_untag = $db->prepare("DELETE FROM complaint_stereotypes WHERE complaint_id = ? AND stereotype_id = ?");
         if ($stmt_untag) {
            $stmt_untag->bind_param("ii", $complaint_id, $stereotype_to_untag_id);
             if ($stmt_untag->execute()) {
                 $_SESSION['success'] = "Stereotype removed from complaint.";
             } else {
                 $_SESSION['error'] = "Failed to remove stereotype tag: " . $db->error;
                 error_log("Untagging error: " . $stmt_untag->error);
             }
              $stmt_untag->close();
         } else {
            $_SESSION['error'] = "Database error preparing untag statement.";
             error_log("Untagging prepare error: " . $db->error);
        }
    } else {
         $_SESSION['error'] = "Invalid stereotype ID provided for untagging.";
    }
    // Redirect back to the same page
    header("Location: stereotype.php?complaint_id=$complaint_id");
    exit;
}


// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stereotype Management<?php echo $complaint ? ' for Complaint #'.$complaint_id : ''; ?> | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Paste the FULL CSS from previous examples (e.g., generate_report.php) */
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
            --info: #17a2b8; /* Consistent info color */
            --orange: #fd7e14;
            --background: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 6px 18px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease-in-out;
            --navbar-bg: #2c3e50;
            --navbar-link: #bdc3c7;
            --navbar-link-hover: #34495e;
            --navbar-link-active: var(--primary);
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
            background: linear-gradient(135deg, var(--navbar-bg) 0%, #34495e 100%);
            color: #ecf0f1;
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
            border-bottom: 1px solid rgba(236, 240, 241, 0.1);
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .nav-header .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .nav-header img {
            height: 40px;
            border-radius: 50%;
        }

        .nav-header .logo-text {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.05);
            padding: 8px 12px;
            border-radius: var(--radius);
            margin-top: 10px;
        }

        .user-profile-mini i {
            font-size: 2rem;
            color: var(--accent);
        }

        .user-info h4 {
            font-size: 0.95rem;
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
        .nav-menu::-webkit-scrollbar { width: 6px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px;}
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px;}
        .nav-menu::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }


        .nav-menu h3 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 25px 15px 10px;
            opacity: 0.6;
            font-weight: 600;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            color: var(--navbar-link);
            text-decoration: none;
            border-radius: var(--radius);
            margin-bottom: 5px;
            transition: var(--transition);
            font-size: 0.95rem;
            font-weight: 400;
            white-space: nowrap;
        }

        .nav-link:hover {
            background: var(--navbar-link-hover);
            color: #ecf0f1;
            transform: translateX(3px);
        }
        .nav-link.active {
            background: var(--navbar-link-active);
            color: white;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1em;
            opacity: 0.8;
        }
        .nav-link.active i {
            opacity: 1;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 25px;
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
            padding: 12px 25px;
            margin-bottom: 25px;
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
            align-items: center;
            gap: 15px;
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
            gap: 8px;
            font-size: 0.95rem;
        }

        .horizontal-menu a:hover, .horizontal-menu a.active {
            background: var(--primary-light);
            color: var(--primary-dark);
        }
        .horizontal-menu a i {
             font-size: 1rem;
             color: var(--grey);
        }
         .horizontal-menu a:hover i, .horizontal-menu a.active i {
             color: var(--primary-dark);
         }

        .notification-icon {
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
            box-shadow: 0 3px 8px rgba(0,0,0,0.07);
        }
        .alert i { font-size: 1.2rem; margin-right: 5px;}
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-warning { background-color: #fff8e1; border-color: #ffecb3; color: #856404; }
        .alert-info { background-color: #e1f5fe; border-color: #b3e5fc; color: #01579b; }

        /* Content Container */
        .content-container {
            background: var(--card-bg);
            padding: 2rem;
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

        /* Page Header Styling */
         .page-header h2 {
             font-size: 1.8rem; font-weight: 600; color: var(--primary-dark);
             margin-bottom: 25px; border-bottom: 3px solid var(--primary);
             padding-bottom: 10px; display: inline-block;
         }

        /* Card Styling */
         .card {
             background-color: var(--card-bg); padding: 25px; border-radius: var(--radius);
             box-shadow: var(--shadow); margin-bottom: 30px; border: 1px solid var(--border-color);
         }
         .card-header {
             display: flex; justify-content: space-between; align-items: center;
             gap: 12px; margin-bottom: 25px;
             color: var(--primary-dark); font-size: 1.3rem; font-weight: 600;
             padding-bottom: 15px; border-bottom: 1px solid var(--border-color);
         }
         .card-header i { font-size: 1.4rem; color: var(--primary); margin-right: 8px;}

        /* Stereotype Page Specifics */
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block; font-weight: 500; margin-bottom: 8px; color: var(--primary-dark); font-size: 0.9rem;
        }
        .form-group input[type="text"], .form-group textarea, .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border-color);
            border-radius: var(--radius); font-size: 0.95rem; color: var(--text-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            border-color: var(--primary); outline: none; box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.2);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }

        .stereotype-list { list-style: none; padding: 0; margin: 0; max-height: 400px; overflow-y: auto; }
        .stereotype-list li {
            padding: 12px 15px; background: #f9f9f9; border: 1px solid var(--border-color);
            border-radius: var(--radius); margin-bottom: 10px; font-size: 0.9rem; line-height: 1.5;
            display: flex; /* Use flex for layout */
            justify-content: space-between; /* Space out content and button */
            align-items: flex-start; /* Align items top */
            gap: 10px;
        }
        .stereotype-list li:last-child { margin-bottom: 0; }
        .stereotype-list li .info { flex-grow: 1; } /* Allow text to take space */
        .stereotype-list li strong { color: var(--primary-dark); display: block; margin-bottom: 3px; }
        .stereotype-list li p { margin: 0; color: var(--text-muted); font-size: 0.85rem; }
        .stereotype-list li .actions { flex-shrink: 0; } /* Prevent button shrinking */

        .details-container { /* Basic styling for complaint info when tagging */
            padding: 15px;
            background: var(--light);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        .detail-item { margin-bottom: 8px; }
        .detail-label { font-weight: 600; color: var(--primary-dark); }
        .detail-value { color: var(--text-color); }
        .text-muted { color: var(--text-muted); font-style: italic; }


        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px;
            border: none; border-radius: var(--radius); font-size: 0.95rem; font-weight: 500;
            cursor: pointer; transition: var(--transition); text-decoration: none; line-height: 1.5;
            white-space: nowrap;
        }
        .btn i { font-size: 1em; line-height: 1;}
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; }
        .btn-info { background-color: var(--info); color: #fff; }
        .btn-info:hover { background-color: #12a1b6; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-success { background-color: var(--success); color: #fff; }
        .btn-success:hover { background-color: #218838; transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-hover); }


        /* Footer */
        .main-footer {
            background-color: var(--card-bg); padding: 15px 30px; margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center; font-size: 0.9rem; color: var(--text-muted);
            flex-shrink: 0;
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
             .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block;}
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
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .stereotype-list li { flex-direction: column; align-items: stretch; }
             .stereotype-list li .actions { margin-top: 8px; text-align: right; }
        }
         @media (max-width: 576px) {
            .content-container { padding: 1rem; }
            .card { padding: 15px;}
            .page-header h2 { font-size: 1.3rem; }
            .btn { padding: 7px 12px; font-size: 0.85rem;}
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
             <!-- Stereotype link - might be active -->
             <a href="stereotype.php" class="nav-link <?php echo $current_page == 'stereotype.php' ? 'active' : ''; ?>">
                 <i class="fas fa-tags fa-fw"></i>
                 <span>Manage Stereotypes</span>
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
                <i class="fas fa-gavel fa-fw"></i>
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
                <h2>Stereotype Management <?php echo $complaint ? ' for Complaint #'.$complaint_id : ''; ?></h2>
            </div>

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

            <!-- Tag Complaint with Stereotype (if complaint_id is provided) -->
            <?php if ($complaint): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-info-circle"></i> Complaint #<?php echo $complaint['id']; ?> Details
                    </div>
                    <div class="card-body">
                        <div class="details-container">
                             <div class="detail-item">
                                <div class="detail-label"><i class="fas fa-heading"></i> Title:</div>
                                <div class="detail-value"><?php echo htmlspecialchars($complaint['title']); ?></div>
                             </div>
                             <div class="detail-item">
                                 <div class="detail-label"><i class="fas fa-user"></i> Submitted By:</div>
                                 <div class="detail-value"><?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></div>
                             </div>
                        </div>

                        <h4><i class="fas fa-tags"></i> Current Stereotypes</h4>
                         <?php if (empty($complaint_stereotypes)): ?>
                             <p class="text-muted">This complaint is not currently tagged with any stereotypes.</p>
                         <?php else: ?>
                             <ul class="stereotype-list" style="max-height: 150px; margin-bottom: 20px;">
                                 <?php foreach ($complaint_stereotypes as $stereotype): ?>
                                     <li>
                                        <div class="info">
                                            <strong><?php echo htmlspecialchars($stereotype['label']); ?></strong>
                                            <p><?php echo htmlspecialchars($stereotype['description'] ?: 'No description.'); ?></p>
                                        </div>
                                        <div class="actions">
                                             <form method="POST" action="" style="display:inline;">
                                                 <input type="hidden" name="stereotype_id" value="<?php echo $stereotype['id']; ?>">
                                                 <button type="submit" name="untag_stereotype" class="btn btn-danger btn-small" title="Remove Tag">
                                                     <i class="fas fa-times"></i> Untag
                                                 </button>
                                             </form>
                                        </div>
                                     </li>
                                 <?php endforeach; ?>
                             </ul>
                         <?php endif; ?>

                        <h4><i class="fas fa-plus-circle"></i> Tag with New Stereotype</h4>
                         <form method="POST" action="">
                             <div class="form-group">
                                 <label for="stereotype_id">Select Stereotype to Tag</label>
                                 <select id="stereotype_id" name="stereotype_id" required>
                                     <option value="">-- Select an Available Stereotype --</option>
                                     <?php
                                     // Filter out stereotypes already tagged to this complaint
                                     $current_stereotype_ids = array_column($complaint_stereotypes, 'id');
                                     foreach ($all_stereotypes as $stereotype):
                                         if (!in_array($stereotype['id'], $current_stereotype_ids)):
                                     ?>
                                             <option value="<?php echo $stereotype['id']; ?>"><?php echo htmlspecialchars($stereotype['label']); ?></option>
                                     <?php
                                         endif;
                                     endforeach;
                                     ?>
                                     <?php if (empty(array_filter($all_stereotypes, fn($s) => !in_array($s['id'], $current_stereotype_ids)))): ?>
                                        <option value="" disabled>No other stereotypes available to tag</option>
                                     <?php endif; ?>
                                 </select>
                             </div>
                             <button type="submit" name="tag_complaint" class="btn btn-success">
                                 <i class="fas fa-tag"></i> Tag Complaint
                             </button>
                             <a href="view_complaint.php?complaint_id=<?php echo $complaint_id; ?>" class="btn btn-info" style="margin-left: 10px;">
                                <i class="fas fa-arrow-left"></i> Back to Complaint Details
                            </a>
                         </form>
                    </div>
                </div>
            <?php endif; ?>


            <!-- Add New Stereotype Form -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus-square"></i> Add New Stereotype Definition
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="label">Stereotype Label *</label>
                            <input type="text" id="label" name="label" required placeholder="e.g., Bullying, Harassment, Unfair Grading">
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" placeholder="Provide a brief description or definition of this stereotype category."></textarea>
                        </div>
                        <button type="submit" name="add_stereotype" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Stereotype Definition
                        </button>
                    </form>
                </div>
            </div>

            <!-- List of All Stereotypes -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list-ul"></i> All Available Stereotype Definitions
                </div>
                <div class="card-body">
                    <?php if (empty($all_stereotypes)): ?>
                        <p class="text-muted">No stereotype definitions have been added yet.</p>
                    <?php else: ?>
                        <ul class="stereotype-list">
                             <?php foreach ($all_stereotypes as $stereotype): ?>
                                 <li>
                                    <div class="info">
                                        <strong><?php echo htmlspecialchars($stereotype['label']); ?></strong>
                                        <p><?php echo htmlspecialchars($stereotype['description'] ?: 'No description provided.'); ?></p>
                                    </div>
                                     <!-- Optional: Add Edit/Delete buttons for stereotype definitions here if needed -->
                                     <!--
                                     <div class="actions">
                                         <a href="edit_stereotype.php?id=<?php echo $stereotype['id']; ?>" class="btn btn-warning btn-small"><i class="fas fa-edit"></i> Edit</a>
                                         <form method="POST" action="delete_stereotype.php" style="display:inline;" onsubmit="return confirm('Are you sure?');">
                                             <input type="hidden" name="stereotype_id" value="<?php echo $stereotype['id']; ?>">
                                             <button type="submit" name="delete_stereotype" class="btn btn-danger btn-small"><i class="fas fa-trash"></i> Delete</button>
                                         </form>
                                     </div>
                                     -->
                                 </li>
                             <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

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