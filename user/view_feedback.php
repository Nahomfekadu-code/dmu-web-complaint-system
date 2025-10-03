<?php
session_start();
require_once '../db_connect.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$feedbacks = $db->query("SELECT f.*, u.fname, u.lname 
                         FROM feedback f 
                         JOIN users u ON f.user_id = u.id 
                         WHERE f.user_id = $user_id 
                         ORDER BY f.created_at DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Feedback | DMU Complaint System</title>
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
        }

        nav a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            margin: 0 15px;
            transition: var(--transition);
        }

        nav a:hover {
            color: var(--secondary);
            text-decoration: underline;
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
            text-align: center;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            font-weight: 600;
        }

        tr:hover {
            background: var(--light);
        }

        @media (max-width: 600px) {
            main {
                padding: 20px;
            }

            h2 {
                font-size: 1.5rem;
            }

            th, td {
                padding: 10px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>DMU Complaint Management System</h1>
            <nav>
                <a href="../index.php">Home</a>
                <a href="edit_profile.php">Edit Profile</a>
                <a href="submit_complaint.php">Submit Complaint</a>
                <a href="modify_complaint.php">Modify Complaint</a>
                <a href="check_complaint_status.php">Check Complaint Status</a>
                <a href="view_decision.php">View Decision</a>
                <a href="send_feedback.php">Send Feedback</a>
                <a href="view_feedback.php">View Feedback</a>
                <a href="view_notification.php">Notifications</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </header>
        <main>
            <h2>View Feedback</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Description</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($feedback = $feedbacks->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $feedback['id']; ?></td>
                                <td><?php echo htmlspecialchars($feedback['fname'] . ' ' . $feedback['lname']); ?></td>
                                <td><?php echo htmlspecialchars($feedback['description']); ?></td>
                                <td><?php echo $feedback['created_at']; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
<?php $db->close(); ?>