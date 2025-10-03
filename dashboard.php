<?php
session_start();
require_once 'db_connect.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'user') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$complaints = $db->query("SELECT * FROM complaints WHERE user_id = $user_id ORDER BY submission_date DESC");
$notices = $db->query("SELECT * FROM notices ORDER BY posted_date DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Dashboard</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>User Dashboard</h1>
            <nav>
                <a href="index.php">Home</a>
                <a href="submit_complaint.php">Submit Complaint</a>
                <a href="logout.php">Logout</a>
            </nav>
        </header>
        <main>
            <h2>Your Complaints</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Submitted On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($complaint = $complaints->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                <td><?php echo $complaint['status']; ?></td>
                                <td><?php echo $complaint['submission_date']; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <h2>Notices</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Content</th>
                            <th>Type</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($notice = $notices->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($notice['title']); ?></td>
                                <td><?php echo htmlspecialchars($notice['content']); ?></td>
                                <td><?php echo $notice['type']; ?></td>
                                <td><?php echo $notice['posted_date']; ?></td>
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