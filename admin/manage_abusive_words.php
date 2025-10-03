<?php
// Enforce secure session settings (must be before session_start)
ini_set('session.cookie_secure', '0'); // Set to '0' for local testing; '1' for live server with HTTPS
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

// Start the session
session_start();

// Include database connection
require_once '../db_connect.php'; // Ensure this path points to dmu_complaints/db_connect.php

// Role check: Ensure the user is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) != 'admin') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$admin = null;

// Fetch admin details
$sql_admin_fetch = "SELECT fname, lname, email, role FROM users WHERE id = ?";
$stmt_admin_fetch = $db->prepare($sql_admin_fetch);
if ($stmt_admin_fetch) {
    $stmt_admin_fetch->bind_param("i", $user_id);
    $stmt_admin_fetch->execute();
    $result_admin = $stmt_admin_fetch->get_result();
    if ($result_admin->num_rows > 0) {
        $admin = $result_admin->fetch_assoc();
    } else {
        $_SESSION['error'] = "Admin profile not found. Please log in again.";
        unset($_SESSION['user_id'], $_SESSION['role']);
        header("Location: ../login.php");
        exit;
    }
    $stmt_admin_fetch->close();
} else {
    $_SESSION['error'] = "Error fetching admin details.";
    error_log("Failed to prepare admin fetch statement: " . $db->error);
    header("Location: ../login.php");
    exit;
}

// Handle form submission to add a new abusive word
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_word'])) {
    // Consider CSRF protection here
    $new_word = trim($_POST['new_word'] ?? '');
    if (!empty($new_word)) {
        // Check if the word already exists (case-insensitive check)
        $sql_check = "SELECT id FROM abusive_words WHERE LOWER(word) = LOWER(?)";
        $stmt_check = $db->prepare($sql_check);
        if ($stmt_check) {
            $stmt_check->bind_param("s", $new_word);
            $stmt_check->execute();
            $check_result = $stmt_check->get_result();
            if ($check_result->num_rows > 0) {
                $_SESSION['warning'] = "The word '" . htmlspecialchars($new_word) . "' (or a case variation) already exists.";
            } else {
                // Insert the word
                $sql_add = "INSERT INTO abusive_words (word, created_at) VALUES (?, NOW())";
                $stmt_add = $db->prepare($sql_add);
                if ($stmt_add) {
                    $stmt_add->bind_param("s", $new_word);
                    if ($stmt_add->execute()) {
                        $_SESSION['success'] = "Word '" . htmlspecialchars($new_word) . "' added successfully.";
                    } else {
                        $_SESSION['error'] = "Error adding word. Database error.";
                        error_log("Error adding abusive word '$new_word': " . $stmt_add->error);
                    }
                    $stmt_add->close();
                } else {
                     $_SESSION['error'] = "Error preparing to add word.";
                     error_log("Error preparing abusive word insert statement: " . $db->error);
                }
            }
            $stmt_check->close();
        } else {
             $_SESSION['error'] = "Error preparing to check word existence.";
             error_log("Error preparing abusive word check statement: " . $db->error);
        }
    } else {
        $_SESSION['error'] = "Please enter a word to add.";
    }
    // Redirect back to the same page to show messages and prevent resubmission
    header("Location: manage_abusive_words.php");
    exit;
}

// Handle deletion of an abusive word (using GET for simplicity, POST with CSRF is safer)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete'])) {
    $word_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);

    if ($word_id && $word_id > 0) {
        // Fetch the word before deleting for the message (optional)
        $word_to_delete = '';
        $stmt_get_word = $db->prepare("SELECT word FROM abusive_words WHERE id = ?");
        if($stmt_get_word) {
            $stmt_get_word->bind_param("i", $word_id);
            $stmt_get_word->execute();
            $result_get_word = $stmt_get_word->get_result();
            if($row = $result_get_word->fetch_assoc()) {
                $word_to_delete = $row['word'];
            }
            $stmt_get_word->close();
        }

        // Delete the word
        $sql_delete = "DELETE FROM abusive_words WHERE id = ?";
        $stmt_delete = $db->prepare($sql_delete);
        if ($stmt_delete) {
            $stmt_delete->bind_param("i", $word_id);
            if ($stmt_delete->execute()) {
                 if($stmt_delete->affected_rows > 0) {
                    $_SESSION['success'] = "Word '" . htmlspecialchars($word_to_delete) . "' (ID: $word_id) deleted successfully.";
                 } else {
                     $_SESSION['warning'] = "Word with ID $word_id not found or already deleted.";
                 }
            } else {
                $_SESSION['error'] = "Error deleting word. Database error.";
                error_log("Error deleting abusive word ID $word_id: " . $stmt_delete->error);
            }
            $stmt_delete->close();
        } else {
             $_SESSION['error'] = "Error preparing to delete word.";
             error_log("Error preparing abusive word delete statement: " . $db->error);
        }
    } else {
         $_SESSION['error'] = "Invalid word ID provided for deletion.";
    }
    // Redirect back to the same page
    header("Location: manage_abusive_words.php");
    exit;
}

// Fetch all abusive words for display
$words_list = [];
$sql_words = "SELECT id, word, created_at FROM abusive_words ORDER BY word ASC"; // Order alphabetically
$words_result = $db->query($sql_words);
if ($words_result) {
    $words_list = $words_result->fetch_all(MYSQLI_ASSOC);
    $words_result->free(); // Free result set early
} else {
    // Set error only if no other message is already set (e.g., from add/delete action)
    if (!isset($_SESSION['error']) && !isset($_SESSION['warning']) && !isset($_SESSION['success'])) {
       $_SESSION['error'] = "Error fetching abusive words list.";
    }
    error_log("Failed to fetch abusive words: " . $db->error);
}

// Get current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Handle session messages (get them AFTER potential redirects and processing)
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
$warning = $_SESSION['warning'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning']); // Clear session messages
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Abusive Words | DMU Complaint System</title>
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Consolidated Stylesheet -->
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <!-- Vertical Navigation (Include) -->
    <nav class="vertical-nav">
        <div class="nav-header">
            <div class="logo">
                <img src="../images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DMU CS</span>
            </div>
            <?php if ($admin): ?>
            <div class="user-profile-mini">
                <i class="fas fa-user-circle"></i>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($admin['fname'] . ' ' . $admin['lname']); ?></h4>
                    <p><?php echo htmlspecialchars(ucfirst($admin['role'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="nav-menu">
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt fa-fw"></i><span>Overview</span></a>
            <h3>User Management</h3>
            <a href="add_user.php" class="nav-link <?php echo $current_page == 'add_user.php' ? 'active' : ''; ?>"><i class="fas fa-user-plus fa-fw"></i><span>Add User</span></a>
            <a href="manage_users.php" class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>"><i class="fas fa-users-cog fa-fw"></i><span>Manage Users</span></a>
            <h3>Content Moderation</h3>
            <a href="manage_abusive_words.php" class="nav-link <?php echo $current_page == 'manage_abusive_words.php' ? 'active' : ''; ?>"><i class="fas fa-filter fa-fw"></i><span>Manage Abusive Words</span></a>
            <a href="review_logs.php" class="nav-link <?php echo $current_page == 'review_logs.php' ? 'active' : ''; ?>"><i class="fas fa-history fa-fw"></i><span>Review Logs</span></a>
            <h3>System Management</h3>
            <a href="backup_restore.php" class="nav-link <?php echo $current_page == 'backup_restore.php' ? 'active' : ''; ?>"><i class="fas fa-database fa-fw"></i><span>Backup/Restore</span></a>
            <h3>Account</h3>
            <a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt fa-fw"></i><span>Logout</span></a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Horizontal Navigation (Include) -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Admin</span>
            </div>
            <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Contact</a>
                <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About</a>
            </div>
        </nav>

        <!-- Manage Abusive Words Container -->
        <div class="container">
            <h2><i class="fas fa-filter" style="margin-right: 10px;"></i> Manage Abusive Words</h2>

            <!-- Display Session Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($warning): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($warning); ?></span>
                </div>
            <?php endif; ?>

            <!-- Add New Abusive Word Form -->
            <div class="card" style="margin-bottom: 30px;">
                <h3>Add New Abusive Word</h3>
                <form action="manage_abusive_words.php" method="POST">
                    <!-- Add CSRF token field here if implementing -->
                    <div class="form-group">
                        <label for="new_word">New Word:</label>
                        <input type="text" id="new_word" name="new_word" required placeholder="Enter word to filter..." autocomplete="off">
                    </div>
                    <button type="submit" name="add_word" class="btn btn-primary"> <!-- Use primary style -->
                        <i class="fas fa-plus-circle" style="margin-right: 5px;"></i> Add Word
                    </button>
                </form>
            </div>

            <!-- Existing Abusive Words Table -->
            <div class="card">
                <h3>Existing Abusive Words List</h3>
                 <div class="table-responsive">
                    <table class="abusive-words-table"> <!-- Add specific class -->
                        <thead>
                            <tr>
                                <!-- <th>ID</th> -->
                                <th>Word</th>
                                <th>Added On</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($words_list) && !$error): // Show message only if list is empty and no fetch error occurred ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; padding: 20px; color: var(--gray);">No abusive words have been added yet.</td>
                                </tr>
                            <?php elseif (!empty($words_list)): ?>
                                <?php foreach ($words_list as $word): ?>
                                    <tr>
                                        <!-- <td data-label="ID"><?php //echo htmlspecialchars($word['id']); ?></td> -->
                                        <td data-label="Word"><?php echo htmlspecialchars($word['word']); ?></td>
                                        <td data-label="Added On"><?php echo htmlspecialchars(date('M j, Y, g:i a', strtotime($word['created_at']))); ?></td>
                                        <td data-label="Action" class="action-cell">
                                            <a href="manage_abusive_words.php?delete=<?php echo $word['id']; ?>" class="btn btn-danger btn-xs" title="Delete this word"> <!-- Use btn-xs for smaller button -->
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                            <!-- Removed inline onclick -->
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($error && empty($words_list)): // Show error message if fetch failed ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--danger); padding: 20px;">Could not load the list of words.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div><!-- end table-responsive -->
            </div><!-- end card -->
        </div> <!-- end container -->

        <!-- Footer (Include) -->
        <footer>
            <div class="footer-content">
                <div class="group-name">Group 5</div>
                <div class="social-links">
                    <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                    <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
                <div class="copyright">
                    © <?php echo date('Y'); ?> DMU Complaint Management System. All rights reserved.
                </div>
            </div>
        </footer>
    </div> <!-- end main-content -->

    <!-- Consolidated Script -->
    <script src="scripts.js" defer></script>
</body>
</html>
<?php
// Close the database connection
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>