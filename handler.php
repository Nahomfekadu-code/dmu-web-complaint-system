<?php
session_start();
require_once 'db_connect.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: login.php");
    exit;
}

$complaints = $db->query("SELECT c.*, u.fname, u.lname FROM complaints c JOIN users u ON c.user_id = u.id ORDER BY c.submission_date");
$sub_actors = $db->query("SELECT id, fname, lname FROM users WHERE role IN ('unit_coordinator', 'college_dean', 'academic_vp', 'directorate_officer', 'admin_vp')");
$sub_actor_list = [];
while ($actor = $sub_actors->fetch_assoc()) {
    $sub_actor_list[] = $actor;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $complaint_id = $_POST["complaint_id"];
    if (isset($_POST["categorize"])) {
        $type = $_POST["type"];
        $sql = "UPDATE complaints SET type = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("si", $type, $complaint_id);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST["escalate"])) {
        $needs_video_chat = isset($_POST["needs_video_chat"]) ? 1 : 0;
        $sql = "UPDATE complaints SET status = 'escalated', needs_video_chat = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $needs_video_chat, $complaint_id);
        $stmt->execute();
        $stmt->close();
        if ($needs_video_chat) {
            $members = json_encode([$_POST["member1"], $_POST["member2"]]);
            $room = "committee_$complaint_id";
            $db->query("INSERT INTO committees (complaint_id, members, video_chat_room) VALUES ($complaint_id, '$members', '$room')");
            $db->query("UPDATE complaints SET committee_id = LAST_INSERT_ID() WHERE id = $complaint_id");
        }
        $message = "Your complaint '$complaint_id' has been escalated.";
        $db->query("INSERT INTO notifications (user_id, complaint_id, description) VALUES ((SELECT user_id FROM complaints WHERE id=$complaint_id), $complaint_id, '$message')");
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Handler Dashboard</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Handler Dashboard</h1>
            <nav>
                <a href="index.php">Home</a>
                <a href="logout.php">Logout</a>
            </nav>
        </header>
        <main>
            <h2>Complaints</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>User</th>
                            <th>Submitted On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($complaint = $complaints->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $complaint['id']; ?></td>
                                <td><?php echo htmlspecialchars($complaint['title']); ?></td>
                                <td><?php echo $complaint['fname'] . ' ' . $complaint['lname']; ?></td>
                                <td><?php echo $complaint['submission_date']; ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                                        <select name="type">
                                            <option value="academic">Academic</option>
                                            <option value="administrative">Administrative</option>
                                        </select>
                                        <button type="submit" name="categorize" class="btn btn-primary">Categorize</button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="complaint_id" value="<?php echo $complaint['id']; ?>">
                                        <input type="checkbox" name="needs_video_chat"> Needs Video Chat
                                        <select name="member1">
                                            <?php foreach ($sub_actor_list as $actor): ?>
                                                <option value="<?php echo $actor['id']; ?>">
                                                    <?php echo $actor['fname'] . ' ' . $actor['lname']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="member2">
                                            <?php foreach ($sub_actor_list as $actor): ?>
                                                <option value="<?php echo $actor['id']; ?>">
                                                    <?php echo $actor['fname'] . ' ' . $actor['lname']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="escalate" class="btn btn-primary">Escalate</button>
                                    </form>
                                    <?php if ($complaint['status'] == 'escalated' && $complaint['needs_video_chat']): ?>
                                        <a href="committee.php?id=<?php echo $complaint['committee_id']; ?>" class="btn btn-primary">Join Video Chat</a>
                                    <?php endif; ?>
                                </td>
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