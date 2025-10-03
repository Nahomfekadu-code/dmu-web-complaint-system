<?php
// Enforce secure session settings (recommended)
// MUST be before session_start()
ini_set('session.cookie_secure', '0'); // Set to '0' for local testing (HTTP); '1' for live (HTTPS)
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

session_start(); // Now start the session AFTER setting configurations

// Include database connection
require_once '../db_connect.php'; // Ensure this path points correctly

// Role check: Ensure the user is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) != 'admin') {
    $_SESSION['error'] = "You do not have permission to access this page.";
    header("Location: ../login.php");
    exit;
}

// --- Database credentials ---
// VERIFY THESE MATCH YOUR XAMPP MYSQL SETUP
// CONSIDER MOVING THESE TO A CONFIG FILE OUTSIDE WEB ROOT FOR BETTER SECURITY
$host = 'localhost';
$dbname = 'dmu_complaints'; // Make sure this is the exact database name
$username = 'root';
$password = ''; // <-- IMPORTANT: Set this to your actual root password if you have one, leave blank if not

// --- Backup Directory Logic ---
$backup_dir = __DIR__ . '/backups/'; // __DIR__ ensures the path is relative to this file

// Check if directory exists, try to create it if not
if (!is_dir($backup_dir)) {
    if (!mkdir($backup_dir, 0755, true) && !is_dir($backup_dir)) {
        $_SESSION['error'] = "Fatal Error: Backup directory '$backup_dir' could not be created. Please check server permissions.";
        error_log("CRITICAL: Failed to create backup directory: " . $backup_dir);
        header("Location: dashboard.php");
        exit;
    }
} elseif (!is_writable($backup_dir)) {
    $_SESSION['error'] = "Configuration Error: Backup directory '$backup_dir' is not writable by the web server. Cannot create new backups.";
    error_log("WARNING: Backup directory not writable: " . $backup_dir);
    // Allow listing existing backups, but backup creation will fail below
}


// --- Handle Backup Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup'])) {
    // Consider adding CSRF token validation here
    if (!is_writable($backup_dir)) {
         $_SESSION['error'] = "Cannot create backup: Backup directory is not writable.";
         header("Location: backup_restore.php");
         exit;
    }

    $backup_file = $backup_dir . 'backup_' . $dbname . '_' . date('Y-m-d_H-i-s') . '.sql';

    // *** FIX: Specify the full path to mysqldump.exe for XAMPP ***
    // Adjust drive letter ('C:') if your XAMPP is installed elsewhere.
    $mysqldump_path = 'C:/xampp/mysql/bin/mysqldump.exe'; // Use forward slashes

    // Check if the executable exists at the specified path
    if (!is_executable($mysqldump_path)) {
         $_SESSION['error'] = "Error: mysqldump executable not found or not executable at '$mysqldump_path'. Please verify the path.";
         error_log("mysqldump path error: " . $mysqldump_path);
         header("Location: backup_restore.php");
         exit;
    }


    // Use escapeshellarg for safety
    // Using --result-file might be slightly more robust on Windows
    $command = sprintf(
        '"%s" --host=%s --user=%s %s --result-file=%s %s', // Enclose path in quotes
        $mysqldump_path,
        escapeshellarg($host),
        escapeshellarg($username),
        $password ? '--password=' . escapeshellarg($password) : '',
        escapeshellarg($backup_file),
        escapeshellarg($dbname)
    );

    // Execute the command
    exec($command . ' 2>&1', $output, $return_var); // Capture stderr for errors

    // Check result
    if ($return_var === 0 && file_exists($backup_file) && filesize($backup_file) > 0) {
        $_SESSION['success'] = "Backup created successfully: " . basename($backup_file);
    } else {
        $_SESSION['error'] = "Error creating backup (Code: $return_var). Check DB credentials, mysqldump path, and permissions. Output: " . htmlspecialchars(implode(" ", $output));
        error_log("mysqldump command failed. Return: $return_var, Command: [$command], Output: " . implode("\n", $output));
        if (file_exists($backup_file)) {
            @unlink($backup_file);
        }
    }
    header("Location: backup_restore.php");
    exit;
}

// --- Handle Restore Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore']) && isset($_FILES['backup_file'])) {
    // Consider adding CSRF protection
    $file = $_FILES['backup_file'];

    // --- Validation ---
    $allowed_ext = 'sql';
    $max_size = 50 * 1024 * 1024; // 50 MB limit
    $upload_error = $file['error'];
    $upload_tmp = $file['tmp_name'];
    $upload_name = $file['name'];
    $upload_size = $file['size'];
    $file_ext = '';
    if ($upload_name) {
        $file_ext = strtolower(pathinfo($upload_name, PATHINFO_EXTENSION));
    }


    if ($upload_error !== UPLOAD_ERR_OK) {
        $upload_errors = [
             UPLOAD_ERR_INI_SIZE   => "File exceeds server's upload_max_filesize limit.",
             UPLOAD_ERR_FORM_SIZE  => "File exceeds the form's MAX_FILE_SIZE limit.",
             UPLOAD_ERR_PARTIAL    => "File was only partially uploaded.",
             UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
             UPLOAD_ERR_NO_TMP_DIR => "Server missing a temporary folder.",
             UPLOAD_ERR_CANT_WRITE => "Server failed to write file to disk.",
             UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload.",
        ];
        $_SESSION['error'] = $upload_errors[$upload_error] ?? "Unknown upload error (Code: $upload_error).";
    } elseif ($file_ext !== $allowed_ext) {
        $_SESSION['error'] = "Invalid file type. Only '.sql' files are allowed.";
    } elseif ($upload_size > $max_size) {
        $_SESSION['error'] = "File is too large. Max size: " . ($max_size / 1024 / 1024) . " MB.";
    } elseif ($upload_size === 0) {
         $_SESSION['error'] = "Uploaded file is empty.";
    } elseif (!is_uploaded_file($upload_tmp)) {
        $_SESSION['error'] = "File upload failed (security check).";
    } else {
        // --- Proceed with Restore ---
        // *** FIX: Specify the full path to mysql.exe for XAMPP ***
         $mysql_path = 'C:/xampp/mysql/bin/mysql.exe'; // Use forward slashes

         // Check if the executable exists
        if (!is_executable($mysql_path)) {
            $_SESSION['error'] = "Error: mysql executable not found or not executable at '$mysql_path'. Please verify the path.";
            error_log("mysql path error: " . $mysql_path);
            header("Location: backup_restore.php");
            exit;
        }

        $command = sprintf(
            '"%s" --host=%s --user=%s %s %s < %s', // Enclose path in quotes
             $mysql_path,
            escapeshellarg($host),
            escapeshellarg($username),
            $password ? '--password=' . escapeshellarg($password) : '',
            escapeshellarg($dbname),
            escapeshellarg($upload_tmp) // Use the temporary uploaded file path
        );

        // Execute the command
        exec($command . ' 2>&1', $output, $return_var); // Capture stderr

        // Check result
        if ($return_var === 0) {
            // It's hard to definitively check success from mysql CLI import without parsing output
            // Assume success if return code is 0, but add a note
            $_SESSION['success'] = "Database restore command executed for: " . htmlspecialchars($upload_name) . ". Please verify the data.";
        } else {
            $error_detail = implode("\n", $output);
            $_SESSION['error'] = "Error restoring database (Code: $return_var). Check file, credentials, mysql path. Details: " . htmlspecialchars(substr($error_detail, 0, 200)) . (strlen($error_detail)>200 ? '...' : '');
             error_log("mysql command failed. Return: $return_var, Command: [$command], Output: " . $error_detail);
        }
    }
    header("Location: backup_restore.php");
    exit;
}

// --- List Available Backups ---
$backups = [];
$listing_error = null;
if (is_dir($backup_dir) && is_readable($backup_dir)) {
    $backup_files = glob($backup_dir . '*.sql');
    if ($backup_files !== false) {
        $backup_files = array_filter($backup_files, 'is_file');
        if (!empty($backup_files)) {
            usort($backup_files, function ($a, $b) { return filemtime($b) - filemtime($a); });
        }
        $backups = $backup_files;
    } else {
         $listing_error = "Could not read backup directory contents (glob failed).";
         error_log($listing_error . " Directory: " . $backup_dir);
    }
} elseif (!is_dir($backup_dir)) {
     $listing_error = "Backup directory '$backup_dir' does not exist.";
     error_log($listing_error);
} else {
     $listing_error = "Backup directory '$backup_dir' is not readable.";
     error_log($listing_error);
}
if ($listing_error && !isset($_SESSION['error']) && !isset($_SESSION['success']) && !isset($_SESSION['warning'])) {
    $_SESSION['error'] = $listing_error;
}


// Get session messages
$success_msg = $_SESSION['success'] ?? null;
$error_msg = $_SESSION['error'] ?? null;
$warning_msg = $_SESSION['warning'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['warning']);

// Get current page for nav highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch admin details for nav
$admin = null;
if (isset($_SESSION['user_id'])) { // Check if user_id is set before querying
    $sql_admin = "SELECT fname, lname, role FROM users WHERE id = ?";
    $stmt_admin = $db->prepare($sql_admin);
    if ($stmt_admin) {
        $stmt_admin->bind_param("i", $_SESSION['user_id']);
        $stmt_admin->execute();
        $result_admin = $stmt_admin->get_result();
        $admin = $result_admin->fetch_assoc(); // Will be null if not found
        $stmt_admin->close();
    } else {
        error_log("Failed to prepare statement to fetch admin nav details: " . $db->error);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Restore | DMU Complaint System</title>
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
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
             <!-- Navigation Links -->
            <h3>Dashboard</h3>
            <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt fa-fw"></i>
                <span>Overview</span>
            </a>
            <h3>User Management</h3>
            <a href="add_user.php" class="nav-link <?php echo $current_page == 'add_user.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-plus fa-fw"></i>
                <span>Add User</span>
            </a>
            <a href="manage_users.php" class="nav-link <?php echo $current_page == 'manage_users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users-cog fa-fw"></i>
                <span>Manage Users</span>
            </a>
            <h3>Content Moderation</h3>
            <a href="manage_abusive_words.php" class="nav-link <?php echo $current_page == 'manage_abusive_words.php' ? 'active' : ''; ?>">
                <i class="fas fa-filter fa-fw"></i>
                <span>Manage Abusive Words</span>
            </a>
            <a href="review_logs.php" class="nav-link <?php echo $current_page == 'review_logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-history fa-fw"></i>
                <span>Review Logs</span>
            </a>
            <h3>System Management</h3>
            <a href="backup_restore.php" class="nav-link <?php echo $current_page == 'backup_restore.php' ? 'active' : ''; ?>">
                <i class="fas fa-database fa-fw"></i>
                <span>Backup/Restore</span>
            </a>
            <h3>Account</h3>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt fa-fw"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

     <!-- Main Content Area -->
    <div class="main-content">
         <!-- Horizontal Navigation (Include) -->
        <nav class="horizontal-nav">
            <div class="logo">
                <span>DMU Complaint System - Admin Panel</span>
            </div>
             <div class="horizontal-menu">
                <a href="../index.php"><i class="fas fa-home"></i> Home</a>
                <a href="contact.php" class="<?php echo $current_page == 'contact.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Contact</a>
                <a href="about.php" class="<?php echo $current_page == 'about.php' ? 'active' : ''; ?>"><i class="fas fa-info-circle"></i> About</a>
            </div>
        </nav>

        <div class="container">
            <h2><i class="fas fa-database" style="margin-right: 10px;"></i> Database Backup & Restore</h2>

            <!-- Display Session Messages -->
            <?php if ($success_msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success_msg); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
             <?php if ($warning_msg): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?php echo htmlspecialchars($warning_msg); ?></span>
                </div>
            <?php endif; ?>

            <!-- Create Backup Card -->
            <div class="card">
                <h3>Create New Backup</h3>
                <form method="post" class="form">
                     <!-- Add CSRF token field here if implementing -->
                    <p style="margin-bottom: 1rem; font-size: 0.9em; color: var(--gray);">Click the button below to generate a full SQL backup of the current database (<code><?php echo htmlspecialchars($dbname); ?></code>).</p>
                    <button type="submit" name="backup" class="btn btn-primary">
                        <i class="fas fa-download"></i> Generate Backup Now
                    </button>
                </form>
            </div>

            <!-- Restore Database Card -->
            <div class="card">
                <h3>Restore Database</h3>
                 <!-- Add CSRF token field here if implementing -->
                <form method="post" enctype="multipart/form-data" class="form" data-confirm-message="WARNING: This will overwrite the current database (<?php echo htmlspecialchars($dbname); ?>) with the selected backup file. All existing data will be lost. Are you absolutely sure?">
                     <p style="margin-bottom: 1rem; font-size: 0.9em; color: var(--danger); font-weight: bold;">Warning: Restoring will overwrite all current data. Proceed with caution.</p>
                    <div class="form-group">
                        <div class="file-input">
                            <label class="file-input-label" id="fileLabel" for="backupFile"> <!-- Added 'for' attribute -->
                                <span>Choose backup file (.sql)</span>
                                <i class="fas fa-cloud-upload-alt"></i>
                            </label>
                            <input type="file" name="backup_file" id="backupFile" accept=".sql" required>
                        </div>
                        <div class="file-name" id="fileName"></div> <!-- Display selected file name -->
                         <small style="font-size: 0.8em; color: var(--gray); margin-top: 4px; display: block;">Max file size: 50MB.</small>
                    </div>
                    <button type="submit" name="restore" class="btn btn-danger">
                        <i class="fas fa-history"></i> Restore Database
                    </button>
                     <!-- Removed inline onsubmit -->
                </form>
            </div>

            <!-- Available Backups Card -->
            <div class="card">
                <h3>Available Backups</h3>
                <?php if (empty($backups) && !$listing_error): ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <p>No backup files found in '<code><?php echo htmlspecialchars(basename($backup_dir)); ?></code>' directory.</p>
                    </div>
                 <?php elseif ($listing_error): ?>
                     <div class="empty-state">
                         <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                         <p><?php echo htmlspecialchars($listing_error); ?></p>
                         <p style="font-size: 0.85em; margin-top: 10px;">Please ensure the directory exists and the web server has read permissions.</p>
                     </div>
                <?php else: ?>
                    <ul class="backup-list">
                        <?php foreach ($backups as $backup):
                            $filename = basename($backup);
                            // Ensure the link points correctly relative to the web root if needed
                            // This assumes 'backups' is inside the same directory as this PHP script
                            $download_path = 'backups/' . rawurlencode($filename);
                            $date = date('M j, Y, g:i a', filemtime($backup));
                            $size = filesize($backup);
                            $size_display = ($size > 1024*1024) ? round($size / 1024 / 1024, 2) . ' MB' : round($size / 1024, 1) . ' KB';
                        ?>
                            <li class="backup-item">
                                <div class="backup-info">
                                    <span class="backup-name"><?php echo htmlspecialchars($filename); ?></span>
                                    <span class="backup-date"><?php echo $date; ?> • <?php echo $size_display; ?></span>
                                </div>
                                <div class="backup-actions">
                                    <a href="<?php echo htmlspecialchars($download_path); ?>" download class="backup-link" title="Download this backup file">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                    <!-- Optional: Add delete button here (requires separate handling script or POST logic) -->
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div><!-- End Container -->

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

    </div> <!-- End Main Content -->

    <!-- Consolidated Script -->
    <script src="scripts.js" defer></script>
</body>
</html>
<?php
// Close DB connection if it was opened by require_once
if (isset($db) && $db instanceof mysqli && $db->thread_id) {
    $db->close();
}
?>