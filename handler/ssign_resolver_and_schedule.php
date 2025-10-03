<?php
// Enforce secure session settings
ini_set('session.cookie_secure', '0'); // Set to '0' for local testing; '1' for live server with HTTPS
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is a handler
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

// Function to log actions
function log_complaint_action($db, $user_id, $action, $details) {
    $sql_log = "INSERT INTO complaint_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())";
    $stmt_log = $db->prepare($sql_log);
    if ($stmt_log) {
        $stmt_log->bind_param("iss", $user_id, $action, $details);
        if (!$stmt_log->execute()) {
            error_log("Failed to log complaint action: " . $stmt_log->error);
        }
        $stmt_log->close();
    } else {
        error_log("Failed to prepare log statement: " . $db->error);
    }
}

// Check if a complaint ID is provided
if (!isset($_GET['complaint_id'])) {
    $_SESSION['error'] = "No complaint ID provided.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details
$sql_complaint = "
    SELECT c.id, c.title, c.needs_video_chat, c.resolver_id, c.visibility, c.user_id,
           u.fname AS user_fname, u.lname AS user_lname
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    WHERE c.id = ? AND c.handler_id = ? AND c.status = 'pending'";
$stmt_complaint = $db->prepare($sql_complaint);
$stmt_complaint->bind_param("ii", $complaint_id, $handler_id);
$stmt_complaint->execute();
$result_complaint = $stmt_complaint->get_result();

if ($result_complaint->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found or you do not have permission to manage it.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result_complaint->fetch_assoc();
$user_id = $complaint['user_id'];
$stmt_complaint->close();

// Check if a resolver is already assigned
if ($complaint['resolver_id'] !== null) {
    $_SESSION['error'] = "A resolver has already been assigned to this complaint.";
    header("Location: dashboard.php");
    exit;
}

// Fetch available resolvers
$sql_resolvers = "SELECT id, fname, lname FROM users WHERE role = 'resolver' AND status = 'active'";
$result_resolvers = $db->query($sql_resolvers);
$resolvers = [];
while ($row = $result_resolvers->fetch_assoc()) {
    $resolvers[] = $row;
}

if (empty($resolvers)) {
    $_SESSION['error'] = "No active resolvers are available to assign.";
    header("Location: dashboard.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resolver_id = (int)$_POST['resolver_id'] ?? 0;
    $scheduled_at = $_POST['scheduled_at'] ?? null;
    $meeting_link = "https://meet.example.com/" . uniqid('meeting_'); // Placeholder link; integrate with a real API in production

    // Validate resolver ID
    $resolver_exists = false;
    $resolver_name = '';
    foreach ($resolvers as $resolver) {
        if ($resolver['id'] === $resolver_id) {
            $resolver_exists = true;
            $resolver_name = $resolver['fname'] . ' ' . $resolver['lname'];
            break;
        }
    }

    if (!$resolver_exists) {
        $_SESSION['error'] = "Invalid resolver selected.";
        header("Location: assign_resolver_and_schedule.php?complaint_id=$complaint_id");
        exit;
    }

    // Validate scheduled time if video chat is required
    if ($complaint['needs_video_chat'] && empty($scheduled_at)) {
        $_SESSION['error'] = "Please schedule a video chat time for this complaint.";
        header("Location: assign_resolver_and_schedule.php?complaint_id=$complaint_id");
        exit;
    }

    // Begin transaction
    $db->begin_transaction();

    try {
        // Assign resolver to the complaint
        $sql_update = "UPDATE complaints SET resolver_id = ? WHERE id = ?";
        $stmt_update = $db->prepare($sql_update);
        $stmt_update->bind_param("ii", $resolver_id, $complaint_id);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to assign resolver: " . $stmt_update->error);
        }
        $stmt_update->close();

        // Log the resolver assignment in committee_assignments
        $sql_assign = "INSERT INTO committee_assignments (complaint_id, handler_id, resolver_id) VALUES (?, ?, ?)";
        $stmt_assign = $db->prepare($sql_assign);
        $stmt_assign->bind_param("iii", $complaint_id, $handler_id, $resolver_id);
        if (!$stmt_assign->execute()) {
            throw new Exception("Failed to log committee assignment: " . $stmt_assign->error);
        }
        $stmt_assign->close();

        // Log the action
        $log_details = "Handler (assembler) ID $handler_id assigned resolver ID $resolver_id to complaint #$complaint_id.";
        log_complaint_action($db, $handler_id, "Resolver Assigned", $log_details);

        // Notify the resolver
        $notification_resolver = "You have been assigned as a resolver for complaint #$complaint_id" . 
            ($complaint['visibility'] === 'standard' ? " by " . $complaint['user_fname'] . " " . $complaint['user_lname'] : " (Anonymous)") . ".";
        $sql_notify_resolver = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
        $stmt_notify_resolver = $db->prepare($sql_notify_resolver);
        $stmt_notify_resolver->bind_param("iis", $resolver_id, $complaint_id, $notification_resolver);
        $stmt_notify_resolver->execute();
        $stmt_notify_resolver->close();

        // Notify the user
        $notification_user = "A resolver has been assigned to your complaint #$complaint_id.";
        $sql_notify_user = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
        $stmt_notify_user = $db->prepare($sql_notify_user);
        $stmt_notify_user->bind_param("iis", $user_id, $complaint_id, $notification_user);
        $stmt_notify_user->execute();
        $stmt_notify_user->close();

        // Schedule video chat if needed
        if ($complaint['needs_video_chat'] && $scheduled_at) {
            $scheduled_at = date('Y-m-d H:i:s', strtotime($scheduled_at));
            // Validate that the scheduled time is in the future
            $now = new DateTime();
            $scheduled_datetime = new DateTime($scheduled_at);
            if ($scheduled_datetime <= $now) {
                throw new Exception("The scheduled video chat time must be in the future.");
            }

            $sql_meeting = "INSERT INTO video_chat_meetings (complaint_id, scheduled_at, meeting_link) VALUES (?, ?, ?)";
            $stmt_meeting = $db->prepare($sql_meeting);
            $stmt_meeting->bind_param("iss", $complaint_id, $scheduled_at, $meeting_link);
            if (!$stmt_meeting->execute()) {
                throw new Exception("Failed to schedule video chat: " . $stmt_meeting->error);
            }
            $stmt_meeting->close();

            // Log the scheduling
            $meeting_details = "Video chat scheduled for complaint #$complaint_id at $scheduled_at. Link: $meeting_link";
            log_complaint_action($db, $handler_id, "Video Chat Scheduled", $meeting_details);

            // Notify participants about the video chat
            $notification_meeting = "A video chat has been scheduled for complaint #$complaint_id on $scheduled_at. Join here: $meeting_link";

            // Notify the user
            $stmt_notify_user = $db->prepare($sql_notify_user);
            $stmt_notify_user->bind_param("iis", $user_id, $complaint_id, $notification_meeting);
            $stmt_notify_user->execute();
            $stmt_notify_user->close();

            // Notify the resolver
            $stmt_notify_resolver = $db->prepare($sql_notify_resolver);
            $stmt_notify_resolver->bind_param("iis", $resolver_id, $complaint_id, $notification_meeting);
            $stmt_notify_resolver->execute();
            $stmt_notify_resolver->close();

            // Notify the handler (assembler)
            $notification_handler = "You have scheduled a video chat for complaint #$complaint_id on $scheduled_at. Join here: $meeting_link";
            $sql_notify_handler = "INSERT INTO notifications (user_id, complaint_id, description, created_at) VALUES (?, ?, ?, NOW())";
            $stmt_notify_handler = $db->prepare($sql_notify_handler);
            $stmt_notify_handler->bind_param("iis", $handler_id, $complaint_id, $notification_handler);
            $stmt_notify_handler->execute();
            $stmt_notify_handler->close();
        }

        // Commit transaction
        $db->commit();

        $_SESSION['success'] = "Resolver assigned successfully." . ($complaint['needs_video_chat'] && $scheduled_at ? " Video chat scheduled." : "");
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        error_log($e->getMessage());
        $_SESSION['error'] = "An error occurred: " . $e->getMessage();
        header("Location: assign_resolver_and_schedule.php?complaint_id=$complaint_id");
        exit;
    }
}

// Handle success/error messages
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Resolver and Schedule Video Chat | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #3f37c9;
            --accent: #4cc9f0;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 30px 40px;
            max-width: 800px;
            width: 100%;
        }

        h2 {
            margin-bottom: 30px;
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            text-align: center;
            position: relative;
            padding-bottom: 15px;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-group select,
        .form-group input[type="datetime-local"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #fdfdfd;
        }

        .form-group select:focus,
        .form-group input[type="datetime-local"]:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            background-color: white;
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23333'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 1em;
            padding-right: 40px;
        }

        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            width: 100%;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .btn:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            border-left-width: 5px;
            border-left-style: solid;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left-color: var(--success);
            color: #1c7430;
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left-color: var(--danger);
            color: #a51c2c;
        }

        .complaint-details {
            background-color: var(--light-gray);
            padding: 15px;
            border-radius: var(--radius);
            margin-bottom: 20px;
        }

        .complaint-details label {
            font-weight: 500;
            margin-right: 5px;
        }

        @media (max-width: 576px) {
            .container {
                padding: 20px;
            }
            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Assign Resolver and Schedule Video Chat</h2>

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

        <div class="complaint-details">
            <p><label>Complaint ID:</label> <?php echo htmlspecialchars($complaint_id); ?></p>
            <p><label>Title:</label> <?php echo htmlspecialchars($complaint['title']); ?></p>
            <p><label>Complainant:</label> 
                <?php echo $complaint['visibility'] === 'standard' ? htmlspecialchars($complaint['user_fname'] . ' ' . $complaint['user_lname']) : 'Anonymous'; ?>
            </p>
            <p><label>Requires Video Chat:</label> <?php echo $complaint['needs_video_chat'] ? 'Yes' : 'No'; ?></p>
        </div>

        <form method="POST" action="assign_resolver_and_schedule.php?complaint_id=<?php echo $complaint_id; ?>">
            <div class="form-group">
                <label for="resolver_id">Assign Resolver:</label>
                <select id="resolver_id" name="resolver_id" required>
                    <option value="" disabled selected>-- Select Resolver --</option>
                    <?php foreach ($resolvers as $resolver): ?>
                        <option value="<?php echo $resolver['id']; ?>">
                            <?php echo htmlspecialchars($resolver['fname'] . ' ' . $resolver['lname']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($complaint['needs_video_chat']): ?>
                <div class="form-group">
                    <label for="scheduled_at">Schedule Video Chat:</label>
                    <input type="datetime-local" id="scheduled_at" name="scheduled_at" required>
                    <small style="display: block; margin-top: 5px; color: var(--gray);">
                        Please select a date and time for the video chat meeting (must be in the future).
                    </small>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn"><?php echo $complaint['needs_video_chat'] ? 'Assign and Schedule Video Chat' : 'Assign Resolver'; ?></button>
        </form>
    </div>

    <script>
        // Ensure the scheduled time is in the future
        const scheduledAtInput = document.getElementById('scheduled_at');
        if (scheduledAtInput) {
            const now = new Date();
            const minTime = new Date(now.getTime() + 5 * 60000); // 5 minutes from now
            scheduledAtInput.min = minTime.toISOString().slice(0, 16);

            scheduledAtInput.addEventListener('change', function() {
                const selectedTime = new Date(this.value);
                if (selectedTime <= now) {
                    alert('Please select a time in the future.');
                    this.value = '';
                }
            });
        }
    </script>
</body>
</html>

<?php
$db->close();
?>