<?php
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch resolved complaints handled by this handler
$sql = "SELECT e.id as escalation_id, e.complaint_id, c.title, c.description, c.category, c.status, c.submission_date, c.resolution_date, u.fname, u.lname 
        FROM escalations e 
        JOIN complaints c ON e.complaint_id = c.id 
        JOIN users u ON c.user_id = u.id 
        WHERE e.escalated_to = 'handler' 
        AND e.status = 'resolved'";
$stmt = $db->prepare($sql);
$stmt->execute();
$resolved_complaints = $stmt->get_result();
$stmt->close();

// Handle success/error messages
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success']);
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Resolved Complaints | Handler</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #3f37c9;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #4bb543;
            --danger: #ff3333;
            --warning: #ffd700;
            --info: #17a2b8;
            --radius: 12px;
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 40px;
            background: white;
            padding: 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        header h1 {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 10px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        nav {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        nav a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: var(--radius);
            transition: var(--transition);
        }

        nav a:hover {
            background: var(--primary);
            color: white;
        }

        main {
            background: white;
            padding: 40px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            margin-bottom: 30px;
            color: var(--primary);
            font-size: 1.8rem;
            position: relative;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        .alert {
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: var(--success);
            color: white;
        }

        .alert-danger {
            background: var(--danger);
            color: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: var(--primary);
            color: white;
        }

        tr:hover {
            background: var(--light);
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>DMU Complaint Management System</h1>
            <nav>
                <a href="dashboard.php">Dashboard</a>
                <a href="escalated.php">Escalated Complaints</a>
                <a href="resolved.php">Resolved Complaints</a>
                <a href="send_decision.php">Send Decision</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </header>
        <main>
            <h2>Resolved Complaints</h2>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($resolved_complaints->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Complaint ID</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Submitted By</th>
                            <th>Submission Date</th>
                            <th>Resolution Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($complaint = $resolved_complaints->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $complaint['complaint_id']; ?></td>
                                <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                <td><?php echo htmlspecialchars($complaint['description']); ?></td>
                                <td><?php echo htmlspecialchars($complaint['category']); ?></td>
                                <td><?php echo htmlspecialchars($complaint['status']); ?></td>
                                <td><?php echo htmlspecialchars($complaint['fname'] . ' ' . $complaint['lname']); ?></td>
                                <td><?php echo $complaint['submission_date']; ?></td>
                                <td><?php echo $complaint['resolution_date']; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No resolved complaints found.</p>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
<?php $db->close(); ?>