<?php
session_start();
require_once '../db_connect.php';

// Role check: Ensure the user is logged in and is a 'handler'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'handler') {
    header("Location: ../login.php");
    exit;
}

$handler_id = $_SESSION['user_id'];

// Check if complaint ID is provided
if (!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])) {
    $_SESSION['error'] = "Invalid complaint ID.";
    header("Location: dashboard.php");
    exit;
}

$complaint_id = (int)$_GET['complaint_id'];

// Fetch complaint details
$stmt = $db->prepare("SELECT c.*, u.fname as submitter_fname, u.lname as submitter_lname 
                      FROM complaints c 
                      JOIN users u ON c.user_id = u.id 
                      WHERE c.id = ? AND c.handler_id = ?");
$stmt->bind_param("ii", $complaint_id, $handler_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Complaint not found or you are not authorized to tag it.";
    header("Location: dashboard.php");
    exit;
}

$complaint = $result->fetch_assoc();
$stmt->close();

// Fetch all available stereotypes
$stereotypes = [];
$sql_stereotypes = "SELECT * FROM stereotypes";
$result = $db->query($sql_stereotypes);
while ($row = $result->fetch_assoc()) {
    $stereotypes[] = $row;
}

// Fetch existing stereotypes for this complaint
$existing_stereotypes = [];
$sql_existing = "SELECT stereotype_id FROM complaint_stereotypes WHERE complaint_id = ?";
$stmt = $db->prepare($sql_existing);
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $existing_stereotypes[] = $row['stereotype_id'];
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $selected_stereotypes = isset($_POST['stereotypes']) ? (array)$_POST['stereotypes'] : [];
    $selected_stereotypes = array_map('intval', $selected_stereotypes);

    $db->begin_transaction();
    try {
        // Delete existing stereotypes for this complaint
        $delete_sql = "DELETE FROM complaint_stereotypes WHERE complaint_id = ?";
        $delete_stmt = $db->prepare($delete_sql);
        $delete_stmt->bind_param("i", $complaint_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        // Insert new stereotypes
        if (!empty($selected_stereotypes)) {
            $insert_sql = "INSERT INTO complaint_stereotypes (complaint_id, stereotype_id, tagged_by) VALUES (?, ?, ?)";
            $insert_stmt = $db->prepare($insert_sql);
            foreach ($selected_stereotypes as $stereotype_id) {
                $insert_stmt->bind_param("iii", $complaint_id, $stereotype_id, $handler_id);
                $insert_stmt->execute();
            }
            $insert_stmt->close();

            // Notify the user
            $notify_user_sql = "INSERT INTO notifications (user_id, complaint_id, description) 
                               SELECT user_id, ?, 'Your complaint has been tagged with stereotypes.' 
                               FROM complaints WHERE id = ?";
            $notify_user_stmt = $db->prepare($notify_user_sql);
            $notify_user_stmt->bind_param("ii", $complaint_id, $complaint_id);
            $notify_user_stmt->execute();
            $notify_user_stmt->close();
        }

        $db->commit();
        $_SESSION['success'] = "Complaint #$complaint_id has been tagged successfully.";
        header("Location: dashboard.php");
        exit;
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "An error occurred while tagging the complaint: " . $e->getMessage();
        error_log("Stereotype tagging error: " . $e->getMessage());
        header("Location: stereotype.php?complaint_id=$complaint_id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tag Stereotypes | DMU Complaint System</title>
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
            display: flex;
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .content-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        h2 {
            color: var(--primary-dark);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
            text-align: center;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 2px;
        }

        h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
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
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .alert i { font-size: 1.2rem; }
        .alert-success { background-color: #d1e7dd; border-color: #badbcc; color: #0f5132; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c2c7; color: #842029; }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: var(--gray);
            color: white;
        }

        .btn-secondary:hover {
            background: var(--dark);
        }

        .complaint-details {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
        }

        .complaint-details p {
            margin: 0.5rem 0;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="content-container">
            <h2>Tag Stereotypes for Complaint #<?php echo $complaint_id; ?></h2>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <!-- Complaint Details -->
            <div class="complaint-details">
                <h3>Complaint Details</h3>
                <p><strong>Title:</strong> <?php echo htmlspecialchars($complaint['title']); ?></p>
                <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars(ucfirst($complaint['category'])); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($complaint['status'])); ?></p>
                <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($complaint['submitter_fname'] . ' ' . $complaint['submitter_lname']); ?></p>
                <p><strong>Created At:</strong> <?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></p>
            </div>

            <!-- Stereotype Tagging Form -->
            <form method="POST">
                <div class="form-group">
                    <label>Select Stereotypes</label>
                    <div class="checkbox-group">
                        <?php foreach ($stereotypes as $stereotype): ?>
                            <label>
                                <input type="checkbox" name="stereotypes[]" value="<?php echo $stereotype['id']; ?>"
                                    <?php echo in_array($stereotype['id'], $existing_stereotypes) ? 'checked' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($stereotype['label'])); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-tags"></i> Tag Stereotypes</button>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
            </form>
        </div>
    </div>
</body>
</html>
<?php
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>