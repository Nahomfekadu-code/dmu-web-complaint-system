<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'handler') {
    header("Location: ../login.php");
    exit();
}

// Check database connection
if ($db->connect_error) {
    error_log("Connection failed: " . $db->connect_error);
    die("Database connection error. Please try again later.");
}

$handler_id = $_SESSION['user_id'];
$complaint_id = isset($_GET['complaint_id']) ? (int)$_GET['complaint_id'] : 0;

if ($complaint_id <= 0) {
    $_SESSION['error'] = "Invalid complaint ID provided.";
    header("Location: dashboard.php");
    exit();
}

// Verify the complaint exists, is assigned to this handler, and needs a committee
$query = "SELECT c.id, c.title, c.description, c.category, c.status, c.created_at, c.evidence_file, c.visibility, 
                 c.committee_id, u.fname AS submitter_fname, u.lname AS submitter_lname, u.email AS submitter_email
          FROM complaints c
          JOIN users u ON c.user_id = u.id
          WHERE c.id = ? AND c.handler_id = ? AND c.needs_committee = 1";
$stmt = $db->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $db->error);
    die("Database error. Please try again later.");
}
$stmt->bind_param("ii", $complaint_id, $handler_id);
$stmt->execute();
$result = $stmt->get_result();
$complaint = $result->fetch_assoc();
$stmt->close();

// Debug and provide detailed error messages
if (!$complaint) {
    $check_complaint = $db->prepare("SELECT id, handler_id, needs_committee, committee_id FROM complaints WHERE id = ?");
    $check_complaint->bind_param("i", $complaint_id);
    $check_complaint->execute();
    $complaint_check = $check_complaint->get_result()->fetch_assoc();
    
    if (!$complaint_check) {
        error_log("Complaint ID $complaint_id does not exist in the database.");
        $_SESSION['error'] = "Complaint does not exist.";
    } else {
        $reasons = [];
        if ($complaint_check['handler_id'] != $handler_id) {
            $reasons[] = "This complaint is not assigned to you.";
        }
        if ($complaint_check['needs_committee'] != 1) {
            $reasons[] = "This complaint does not require a committee.";
        }
        if ($complaint_check['committee_id']) {
            error_log("Complaint ID $complaint_id already has a committee assigned (Committee ID: {$complaint_check['committee_id']}).");
            $reasons[] = "A committee is already assigned to this complaint.";
        }
        error_log("Complaint ID $complaint_id failed conditions: " . implode(", ", $reasons));
        $_SESSION['error'] = "Invalid complaint or you do not have access to it. " . implode(" ", $reasons);
    }
    $check_complaint->close();
} elseif ($complaint && $complaint['committee_id']) {
    error_log("Complaint ID $complaint_id already has a committee assigned (Committee ID: {$complaint['committee_id']}).");
    $_SESSION['error'] = "A committee is already assigned to this complaint.";
}

if (isset($_SESSION['error'])) {
    header("Location: dashboard.php");
    exit();
}

$complaint_title = $complaint['title'];

// Expanded list of roles for potential committee members
$eligible_roles = [
    'department_head',
    'college_dean',
    'academic_vp',
    'president',
    'university_registrar',
    'campus_registrar',
    'sims',
    'cost_sharing',
    'student_service_directorate',
    'dormitory_service',
    'students_food_service',
    'library_service',
    'hrm',
    'finance',
    'general_service'
];

// Fetch users who can be committee members based on the expanded roles
$roles_placeholder = implode(',', array_fill(0, count($eligible_roles), '?'));
$potential_members_query = "SELECT id, fname, lname, role 
                           FROM users 
                           WHERE role IN ($roles_placeholder) 
                           AND id != ? 
                           AND status = 'active' 
                           ORDER BY role, fname";
$stmt = $db->prepare($potential_members_query);
if (!$stmt) {
    error_log("Prepare failed: " . $db->error);
    die("Database error fetching potential committee members.");
}

// Bind parameters dynamically
$types = str_repeat('s', count($eligible_roles)) . 'i';
$params = array_merge($eligible_roles, [$handler_id]);
$refs = [];
foreach ($params as $key => $value) {
    $refs[$key] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));

$stmt->execute();
$potential_members = $stmt->get_result();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_members = isset($_POST['members']) ? array_map('intval', $_POST['members']) : [];

    if (count($selected_members) < 1) {
        $error = "Please select at least one member for the committee.";
    } else {
        // Validate selected members are in the potential members list
        $valid_member_ids = [];
        while ($member = $potential_members->fetch_assoc()) {
            $valid_member_ids[] = $member['id'];
        }
        $potential_members->data_seek(0);
        $invalid_members = array_diff($selected_members, $valid_member_ids);
        if (!empty($invalid_members)) {
            $error = "One or more selected members are invalid.";
        } else {
            // Start a transaction
            $db->begin_transaction();
            try {
                // Create a new committee
                $stmt = $db->prepare("INSERT INTO committees (handler_id, complaint_id, created_at) 
                                      VALUES (?, ?, NOW())");
                if (!$stmt) throw new Exception("Failed to prepare committee insertion: " . $db->error);
                $stmt->bind_param("ii", $handler_id, $complaint_id);
                $stmt->execute();
                $committee_id = $db->insert_id;
                $stmt->close();

                // Add the handler as a committee member
                $stmt = $db->prepare("INSERT INTO committee_members (committee_id, user_id, is_handler, assigned_at) 
                                      VALUES (?, ?, 1, NOW())");
                if (!$stmt) throw new Exception("Failed to prepare handler insertion: " . $db->error);
                $stmt->bind_param("ii", $committee_id, $handler_id);
                $stmt->execute();
                $stmt->close();

                // Add selected members to the committee
                $stmt = $db->prepare("INSERT INTO committee_members (committee_id, user_id, is_handler, assigned_at) 
                                      VALUES (?, ?, 0, NOW())");
                if (!$stmt) throw new Exception("Failed to prepare member insertion: " . $db->error);
                foreach ($selected_members as $member_id) {
                    $stmt->bind_param("ii", $committee_id, $member_id);
                    $stmt->execute();
                }
                $stmt->close();

                // Update the complaint with the committee_id
                $stmt = $db->prepare("UPDATE complaints SET committee_id = ? WHERE id = ?");
                if (!$stmt) throw new Exception("Failed to prepare complaint update: " . $db->error);
                $stmt->bind_param("ii", $committee_id, $complaint_id);
                $stmt->execute();
                $stmt->close();

                // Create notifications for committee members
                $stmt = $db->prepare("INSERT INTO notifications (user_id, complaint_id, description, created_at) 
                                      VALUES (?, ?, ?, NOW())");
                if (!$stmt) throw new Exception("Failed to prepare notification insertion: " . $db->error);
                $description = "You have been assigned to the committee for complaint #$complaint_id: $complaint_title.";
                foreach ($selected_members as $member_id) {
                    $stmt->bind_param("iis", $member_id, $complaint_id, $description);
                    $stmt->execute();
                }
                $stmt->close();

                // Send initial complaint details message to the committee chat
                $submitter_name = ($complaint['visibility'] == 'anonymous' && $complaint['status'] != 'resolved')
                    ? 'Anonymous'
                    : htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']);
                $submitter_info = $complaint['visibility'] == 'standard' ? " (Email: {$complaint['submitter_email']})" : '';
                $evidence_info = $complaint['evidence_file'] ? "Evidence File: Available (view in complaint details)" : "Evidence File: None";
                $message_text = "Complaint Details:\n" .
                                "Complaint ID: #{$complaint['id']}\n" .
                                "Title: {$complaint['title']}\n" .
                                "Description: {$complaint['description']}\n" .
                                "Category: " . ($complaint['category'] ? ucfirst($complaint['category']) : 'Not Categorized') . "\n" .
                                "Status: " . ucfirst(str_replace('_', ' ', $complaint['status'])) . "\n" .
                                "Submitted By: {$submitter_name}{$submitter_info}\n" .
                                "Submitted On: " . date('M j, Y, g:i A', strtotime($complaint['created_at'])) . "\n" .
                                $evidence_info . "\n" .
                                "This committee has been assigned to review this complaint.";

                $stmt = $db->prepare("INSERT INTO committee_messages (committee_id, sender_id, message_text, sent_at) 
                                      VALUES (?, ?, ?, NOW())");
                if (!$stmt) throw new Exception("Failed to prepare initial message insertion: " . $db->error);
                $stmt->bind_param("iis", $committee_id, $handler_id, $message_text);
                $stmt->execute();
                $stmt->close();

                // Commit the transaction
                $db->commit();
                $_SESSION['success'] = "Committee assigned successfully.";
                header("Location: dashboard.php");
                exit();
            } catch (Exception $e) {
                $db->rollback();
                error_log("Committee assignment failed: " . $e->getMessage());
                $error = "Error assigning committee: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Committee | DMU Complaint System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
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
            --radius: 10px;
            --radius-lg: 15px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 0.5rem;
        }

        .content-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 800px;
            margin-bottom: 2rem;
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: var(--radius);
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
        }

        .alert i {
            font-size: 1.2rem;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .members-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--light-gray);
            border-radius: var(--radius);
            padding: 1rem;
            background-color: #fafafa;
        }

        .members-list::-webkit-scrollbar {
            width: 8px;
        }
        .members-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .members-list::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 10px;
        }
        .members-list::-webkit-scrollbar-thumb:hover {
            background: #aaa;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .member-item:last-child {
            border-bottom: none;
        }

        .member-item label {
            flex: 1;
            cursor: pointer;
        }

        .member-item input[type="checkbox"] {
            margin-right: 0.5rem;
        }

        .role-label {
            font-size: 0.85rem;
            color: var(--gray);
            margin-left: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary) 100%);
            box-shadow: var(--shadow);
        }

        .btn-back {
            background: var(--gray);
            color: white;
        }

        .btn-back:hover {
            background: var(--dark);
            box-shadow: var(--shadow);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
            background-color: #f9f9f9;
            border-radius: var(--radius);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--light-gray);
        }

        @media (max-width: 768px) {
            .content-container {
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            h3 {
                font-size: 1.2rem;
            }

            .btn {
                padding: 0.6rem 1.2rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 576px) {
            .content-container {
                padding: 1rem;
            }

            h2 {
                font-size: 1.3rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
                padding: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <div class="content-container">
        <h2>Assign Committee for Complaint #<?php echo $complaint_id; ?></h2>
        <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint_title); ?></p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <h3>Select Committee Members</h3>
            <?php if ($potential_members->num_rows > 0): ?>
                <div class="members-list">
                    <?php 
                    $current_role = '';
                    while ($member = $potential_members->fetch_assoc()): 
                        $member_role = ucfirst(str_replace('_', ' ', $member['role']));
                        if ($current_role !== $member['role']): 
                            if ($current_role !== ''): ?>
                                </div>
                            <?php endif; ?>
                            <div class="role-group">
                                <div class="role-label"><?php echo htmlspecialchars($member_role); ?>s</div>
                            <?php $current_role = $member['role'];
                        endif; ?>
                        <div class="member-item">
                            <label>
                                <input type="checkbox" name="members[]" value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['fname'] . ' ' . $member['lname']); ?>
                            </label>
                        </div>
                    <?php endwhile; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-users"></i> Assign Committee
                </button>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <p>No eligible members available to assign to the committee.</p>
                </div>
            <?php endif; ?>
        </form>

        <a href="dashboard.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</body>
</html>
<?php
$stmt->close();
$db->close();
?>