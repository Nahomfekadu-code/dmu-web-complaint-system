<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'department_head'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'department_head') {
    $_SESSION['error'] = "You are not authorized to access this page.";
    header("Location: ../login.php");
    exit;
}

$dept_head_id = $_SESSION['user_id'];

// Fetch Department Head details
$sql_dept_head = "SELECT fname, lname, email, role, department FROM users WHERE id = ?";
$stmt_dept_head = $db->prepare($sql_dept_head);
$stmt_dept_head->bind_param("i", $dept_head_id);
$stmt_dept_head->execute();
$dept_head = $stmt_dept_head->get_result()->fetch_assoc();
$stmt_dept_head->close();

if (!$dept_head) {
    $_SESSION['error'] = "Department Head details not found.";
    header("Location: ../logout.php");
    exit;
}

// Mark notifications as read when the page is loaded
$sql_update = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
$stmt_update = $db->prepare($sql_update);
$stmt_update->bind_param("i", $dept_head_id);
$stmt_update->execute();
$stmt_update->close();

// Fetch notifications for this Department Head
$sql_notifications = "
    SELECT n.id, n.complaint_id, n.description, n.is_read, n.created_at 
    FROM notifications n 
    WHERE n.user_id = ? 
    ORDER BY n.created_at DESC";
$stmt_notifications = $db->prepare($sql_notifications);
$stmt_notifications->bind_param("i", $dept_head_id);
$stmt_notifications->execute();
$notifications = $stmt_notifications->get_result();
$stmt_notifications->close();

// Get current page name for active link highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Notifications | DMU Complaint System</title>
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
        .nav-menu::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px; }
        .nav-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
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

        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

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

        .alert i { font-size: 1.2rem; margin-right: 5px; }
        .alert-success { background-color: #e9f7ef; border-color: #c3e6cb; color: #155724; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }

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

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            display: inline-block;
        }

        .card {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.3rem;
            font-weight: 600;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header i {
            font-size: 1.4rem;
            color: var(--primary);
            margin-right: 8px;
        }

        .table-responsive { overflow-x: auto; width: 100%; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
            font-size: 0.9rem;
        }

        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
            cursor: pointer;
            position: relative;
            padding-right: 25px;
        }

        th:hover { background-color: #e9ecef; }
        th::after {
            content: '\f0dc';
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
        th.asc::after { content: '\f0de'; opacity: 1; }
        th.desc::after { content: '\f0dd'; opacity: 1; }

        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background-color: #f1f5f9; }
        td .description-cell {
            max-width: 300px;
            white-space: normal;
            word-wrap: break-word;
            font-size: 0.9rem;
            color: var(--text-color);
            line-height: 1.5;
        }

        .text-muted { color: var(--text-muted); font-style: italic; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            line-height: 1.5;
            white-space: nowrap;
        }

        .btn i { font-size: 1em; line-height: 1; }
        .btn-small { padding: 6px 12px; font-size: 0.8rem; gap: 5px; }
        .btn-primary { background-color: var(--primary); color: #fff; }
        .btn-primary:hover { background-color: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .btn-danger { background-color: var(--danger); color: #fff; }
        .btn-danger:hover { background-color: #c82333; transform: translateY(-1px); box-shadow: var(--shadow-hover); }

        .main-footer {
            background-color: var(--card-bg);
            padding: 15px 30px;
            margin-top: 30px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-muted);
            flex-shrink: 0;
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .vertical-nav { width: 75px; }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info, .vertical-nav .nav-menu h3, .vertical-nav .nav-link span { display: none; }
            .vertical-nav .nav-header .user-profile-mini i { font-size: 1.8rem; }
            .vertical-nav .user-profile-mini { padding: 8px; justify-content: center; }
            .vertical-nav .nav-link { justify-content: center; padding: 15px 10px; }
            .vertical-nav .nav-link i { margin-right: 0; font-size: 1.3rem; }
            .main-content { margin-left: 75px; }
            .horizontal-nav { left: 75px; }
            .main-footer { margin-left: 75px; }
        }

        @media (max-width: 768px) {
            body { flex-direction: column; }
            .vertical-nav {
                width: 100%;
                height: auto;
                position: relative;
                box-shadow: none;
                border-bottom: 2px solid var(--primary-dark);
                flex-direction: column;
            }
            .vertical-nav .nav-header .logo-text, .vertical-nav .user-info { display: block; }
            .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 10px; }
            .nav-menu { display: flex; flex-wrap: wrap; justify-content: center; padding: 5px 0; overflow-y: visible; }
            .nav-menu h3 { display: none; }
            .nav-link { flex-direction: row; width: auto; padding: 8px 12px; }
            .nav-link i { margin-right: 8px; margin-bottom: 0; font-size: 1rem; }
            .nav-link span { display: inline; font-size: 0.85rem; }
            .main-content { margin-left: 0; padding: 15px; padding-top: 20px; }
            .main-footer { margin-left: 0; }
            .page-header h2 { font-size: 1.5rem; }
            .card { padding: 20px; }
            .card-header { font-size: 1.1rem; }
            .btn { padding: 8px 15px; font-size: 0.9rem; }
            .btn-small { padding: 5px 10px; font-size: 0.75rem; }
            th, td { font-size: 0.85rem; padding: 10px 8px; }
            table.responsive-table,
            table.responsive-table thead,
            table.responsive-table tbody,
            table.responsive-table th,
            table.responsive-table td,
            table.responsive-table tr { display: block; }
            table.responsive-table thead tr { position: absolute; top: -9999px; left: -9999px; }
            table.responsive-table tr { border: 1px solid #ccc; border-radius: var(--radius); margin-bottom: 10px; background: var(--card-bg); }
            table.responsive-table td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 45%; text-align: right; display: flex; justify-content: flex-end; align-items: center; min-height: 40px; }
            table.responsive-table td:before {
                position: absolute;
                top: 50%;
                left: 10px;
                transform: translateY(-50%);
                width: 40%;
                padding-right: 10px;
                white-space: nowrap;
                content: attr(data-label);
                font-weight: bold;
                text-align: left;
                font-size: 0.8rem;
                color: var(--primary-dark);
            }
            table.responsive-table td.description-cell {
                padding-left: 10px;
                text-align: left;
                justify-content: flex-start;
                border-bottom: 1px solid #eee;
            }
            table.responsive-table td.description-cell:before { display: none; }
            table.responsive-table tr td:last-child { border-bottom: none; }
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
            <div class="user-profile-mini">
                <i class="fas fa-user-shield"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($dept_head['fname'] . ' ' . $dept_head['lname']); ?></h4>
                    <p><?php echo htmlspecialchars($dept_head['role']); ?> (<?php echo htmlspecialchars($dept_head['department']); ?>)</p>
                </div>
            </div>
        </div>

        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Dashboard Overview</span>
            </a>

            <h3>Complaint Management</h3>
            <a href="view_escalated.php" class="nav-link <?php echo $current_page == 'view_escalated.php' ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up fa-fw"></i>
                <span>View Escalated Complaints</span>
            </a>
          

            <h3>Communication</h3>
            <a href="view_notifications.php" class="nav-link <?php echo $current_page == 'view_notifications.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell fa-fw"></i>
                <span>View Notifications</span>
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
                <a href="dashboard.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
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
                <h2>Notifications</h2>
            </div>

            <!-- Display Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Notifications -->
            <div class="card">
                <div class="card-header">
                    <span><i class="fas fa-bell"></i> Your Notifications</span>
                </div>
                <div class="card-body">
                    <?php if ($notifications->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="notificationsTable" class="responsive-table">
                                <thead>
                                    <tr>
                                        <th data-sort="description">Description</th>
                                        <th data-sort="complaint_id">Complaint ID</th>
                                        <th data-sort="created_at">Received On</th>
                                        <th data-sort="status">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($notification = $notifications->fetch_assoc()): ?>
                                        <tr>
                                            <td data-label="Description" class="description-cell"><?php echo htmlspecialchars($notification['description']); ?></td>
                                            <td data-label="Complaint ID"><?php echo $notification['complaint_id'] ? htmlspecialchars($notification['complaint_id']) : 'N/A'; ?></td>
                                            <td data-label="Received On"><?php echo date("M j, Y, g:i a", strtotime($notification['created_at'])); ?></td>
                                            <td data-label="Status"><?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="text-align: center; padding: 20px 0;">No notifications available.</p>
                    <?php endif; ?>
                    <?php $notifications->free(); ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="main-footer">
            © <?php echo date("Y"); ?> DMU Complaint Management System | Department Head Panel
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
                }, 7000);
            });

            function sortTableByColumn(table, column, asc = true) {
                const dirModifier = asc ? 1 : -1;
                const tBody = table.tBodies[0];
                const rows = Array.from(tBody.querySelectorAll("tr"));

                const sortedRows = rows.sort((a, b) => {
                    let aColText = a.querySelector(`td:nth-child(${column + 1})`)?.textContent.trim().toLowerCase() || '';
                    let bColText = b.querySelector(`td:nth-child(${column + 1})`)?.textContent.trim().toLowerCase() || '';

                    const header = table.querySelector(`th:nth-child(${column + 1})`);
                    const sortKey = header?.dataset.sort;

                    if (sortKey === 'created_at') {
                        aColText = aColText === 'n/a' ? 0 : new Date(aColText).getTime() || 0;
                        bColText = bColText === 'n/a' ? 0 : new Date(bColText).getTime() || 0;
                    }

                    return aColText > bColText ? (1 * dirModifier) : (-1 * dirModifier);
                });

                while (tBody.firstChild) {
                    tBody.removeChild(tBody.firstChild);
                }

                tBody.append(...sortedRows);

                table.querySelectorAll("th").forEach(th => th.classList.remove("asc", "desc"));
                table.querySelector(`th:nth-child(${column + 1})`).classList.toggle("asc", asc);
                table.querySelector(`th:nth-child(${column + 1})`).classList.toggle("desc", !asc);
            }

            const notificationsTable = document.getElementById('notificationsTable');
            if (notificationsTable) {
                notificationsTable.querySelectorAll("th[data-sort]").forEach((headerCell) => {
                    const index = Array.prototype.indexOf.call(headerCell.parentNode.children, headerCell);
                    headerCell.addEventListener("click", () => {
                        const currentIsAscending = headerCell.classList.contains("asc");
                        sortTableByColumn(notificationsTable, index, !currentIsAscending);
                    });
                });
            }
        });
    </script>
</body>
</html>
<?php
$db->close();
?>