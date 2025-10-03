<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is 'sims'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sims') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$sims_id = $_SESSION['user_id'];
$complaints = [];

// Fetch the campus registrar
$sql_registrar = "SELECT id, username, email FROM users WHERE role = 'campus_registrar' LIMIT 1";
$result_registrar = $db->query($sql_registrar);
if ($result_registrar && $result_registrar->num_rows > 0) {
    $campus_registrar = $result_registrar->fetch_assoc();
    $campus_registrar_id = $campus_registrar['id'];
} else {
    $_SESSION['error'] = "No Campus Registrar available for escalation.";
    $campus_registrar_id = null;
}

// Fetch open complaints assigned to the sims user
$sql_complaints = "SELECT c.id, c.title, c.description, u.username 
                   FROM complaints c 
                   JOIN escalations e ON c.id = e.complaint_id 
                   JOIN users u ON c.user_id = u.id 
                   WHERE e.escalated_to = 'sims' AND e.escalated_to_id = ? AND e.status = 'pending'";
$stmt_complaints = $db->prepare($sql_complaints);
if ($stmt_complaints) {
    $stmt_complaints->bind_param("i", $sims_id);
    $stmt_complaints->execute();
    $result = $stmt_complaints->get_result();
    while ($row = $result->fetch_assoc()) {
        $complaints[] = $row;
    }
    $stmt_complaints->close();
} else {
    $_SESSION['error'] = "Error fetching complaints: " . $db->error;
}

// Handle complaint escalation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['escalate']) && $campus_registrar_id) {
    $complaint_id = (int)$_POST['complaint_id'];

    // Update the complaint status
    $sql_update = "UPDATE complaints 
                   SET status = 'in_progress', updated_at = NOW() 
                   WHERE id = ?";
    $stmt_update = $db->prepare($sql_update);
    $stmt_update->bind_param("i", $complaint_id);
    if ($stmt_update->execute()) {
        // Update the existing escalation
        $sql_escalate_update = "UPDATE escalations 
                                SET status = 'escalated', escalated_to = 'campus_registrar', escalated_to_id = ?, updated_at = NOW() 
                                WHERE complaint_id = ? AND escalated_to = 'sims' AND escalated_to_id = ?";
        $stmt_escalate_update = $db->prepare($sql_escalate_update);
        $stmt_escalate_update->bind_param("iii", $campus_registrar_id, $complaint_id, $sims_id);
        $stmt_escalate_update->execute();
        $stmt_escalate_update->close();

        // Create a notification for the campus registrar
        $message = "A complaint (ID: $complaint_id) has been escalated to you by SIMS.";
        $sql_notify = "INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) 
                       VALUES (?, ?, ?, 0, NOW())";
        $stmt_notify = $db->prepare($sql_notify);
        $stmt_notify->bind_param("iis", $campus_registrar_id, $complaint_id, $message);
        $stmt_notify->execute();
        $stmt_notify->close();

        // Optionally, send an email to the campus registrar
        $to = $campus_registrar['email'];
        $subject = "New Complaint Escalated to You";
        $body = "Dear {$campus_registrar['username']},\n\nA complaint (ID: $complaint_id) has been escalated to you by SIMS.\nDescription: {$_POST['description']}\nPlease review it at your earliest convenience.\n\nRegards,\nRegistrar Complaint System";
        $headers = "From: no-reply@registrarsystem.com";
        if (mail($to, $subject, $body, $headers)) {
            $message .= " Email notification sent.";
        } else {
            $message .= " Failed to send email notification.";
        }

        $_SESSION['success'] = "Complaint escalated to Campus Registrar successfully. $message";
    } else {
        $_SESSION['error'] = "Failed to escalate complaint.";
    }
    $stmt_update->close();
    header("Location: assign_complaint.php");
    exit;
}

$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escalate Complaints | DMU  Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --radius: 12px;
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            font-family: 'Poppins', sans-serif;
        }
        .vertical-nav {
            width: 280px;
            background: linear-gradient(135deg, var(--primary) 0%, #3f37c9 100%);
            color: white;
            height: 100vh;
            position: sticky;
            top: 0;
            padding: 20px 0;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
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
        .main-content {
            flex: 1;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px 40px;
        }
        h2 {
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: 1.8rem;
            text-align: center;
        }
        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left: 5px solid var(--success);
            color: #1c7430;
        }
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 5px solid var(--danger);
            color: #a51c2c;
        }
        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: left;
        }
        tbody td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--light-gray);
        }
        .form-inline {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            color: white;
            background-color: var(--primary);
        }
        .btn:hover {
            background-color: var(--primary-dark);
        }
    </style>
</head>
<body>
    <nav class="vertical-nav">
        <div class="nav-menu">
            <a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="assign_complaint.php" class="nav-link active"><i class="fas fa-tasks"></i> Escalate Complaints</a>
            <a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="container">
            <h2>Escalate Complaints to Campus Registrar</h2>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Complaint ID</th>
                            <th>User</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($complaints) || !$campus_registrar_id): ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">
                                    <?php echo $campus_registrar_id ? "No complaints to escalate." : "No Campus Registrar available."; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $complaint): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($complaint['id']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['username']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['description']); ?></td>
                                    <td>
                                        <form method="post" class="form-inline">
                                            <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                                            <input type="hidden" name="description" value="<?php echo htmlspecialchars($complaint['description']); ?>">
                                            <button type="submit" name="escalate" class="btn">Escalate</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
<?php $db->close(); ?>