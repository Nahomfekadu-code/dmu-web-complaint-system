<?php
// Include the database connection file
require_once 'db_connect.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Initialize error log file
$log_file = 'setup_errors.log';

echo "Starting database setup...<br>";

// Start transaction for atomicity
$db->begin_transaction();
try {
    // Step 1: Drop and recreate the database to ensure a clean state
    echo "Dropping and recreating database 'dmu_complaints'...<br>";
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    if (!$db->query("DROP DATABASE IF EXISTS dmu_complaints")) {
        throw new Exception("Error dropping database: " . $db->error);
    }
    if (!$db->query("CREATE DATABASE dmu_complaints CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
        throw new Exception("Error creating database: " . $db->error);
    }
    if (!$db->query("USE dmu_complaints")) {
        throw new Exception("Error selecting database: " . $db->error);
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
    echo "Database 'dmu_complaints' created and selected successfully.<br>";

    // Step 2: Verify InnoDB support
    $result = $db->query("SHOW ENGINES");
    $innodb_enabled = false;
    while ($row = $result->fetch_assoc()) {
        if ($row['Engine'] === 'InnoDB' && ($row['Support'] === 'YES' || $row['Support'] === 'DEFAULT')) {
            $innodb_enabled = true;
            break;
        }
    }
    if (!$innodb_enabled) {
        throw new Exception("Error: InnoDB is not enabled. Please enable InnoDB in my.ini.");
    }
    echo "InnoDB is enabled.<br>";

    // Step 3: Set default storage engine to InnoDB
    if (!$db->query("SET default_storage_engine=INNODB")) {
        throw new Exception("Error setting default storage engine: " . $db->error);
    }
    echo "Default storage engine set to InnoDB.<br>";

    // Step 4: Disable foreign key checks temporarily
    if (!$db->query("SET FOREIGN_KEY_CHECKS = 0")) {
        throw new Exception("Error disabling foreign key checks: " . $db->error);
    }
    echo "Foreign key checks disabled.<br>";

    // Step 5: Drop any existing tables
    $tables_to_drop = [
        'complaint_stereotypes', 'stereotypes', 'stereotyped_reports', 'reports', 'feedback',
        'notifications', 'decisions', 'decision_agreements', 'committee_decisions', 'escalations',
        'password_resets', 'complaints', 'committee_messages', 'committee_members', 'committees',
        'departments', 'notices', 'abusive_words', 'complaint_logs', 'users'
    ];
    echo "Dropping existing tables (if any)...<br>";
    foreach ($tables_to_drop as $table) {
        if (!$db->query("DROP TABLE IF EXISTS `$table`")) {
            echo "Warning: Could not drop table '$table': " . $db->error . "<br>";
            error_log("Warning: Could not drop table '$table': " . $db->error, 3, $log_file);
        } else {
            echo "Table '$table' dropped successfully or did not exist.<br>";
        }
    }
    echo "Finished dropping tables.<br>";

    // --- Create Tables ---
    echo "Creating tables...<br>";

    // Create users table
    $create_users = "CREATE TABLE users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('user', 'handler', 'admin', 'sims', 'cost_sharing', 'campus_registrar', 'university_registrar', 'academic_vp', 'president', 'academic', 'department_head', 'college_dean', 'administrative_vp', 'student_service_directorate', 'dormitory_service', 'students_food_service', 'library_service', 'hrm', 'finance', 'general_service') NOT NULL,
        fname VARCHAR(50) NOT NULL,
        lname VARCHAR(50) NOT NULL,
        phone VARCHAR(15),
        sex ENUM('male', 'female', 'other') NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        department VARCHAR(100) DEFAULT NULL,
        college VARCHAR(100) DEFAULT NULL,
        status ENUM('active', 'blocked', 'suspended') DEFAULT 'active',
        suspended_until DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_users)) {
        throw new Exception("Error creating users table: " . $db->error);
    }
    echo "Table 'users' created successfully!<br>";

    // Create departments table
    $create_departments = "CREATE TABLE departments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        head_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (head_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_departments)) {
        throw new Exception("Error creating departments table: " . $db->error);
    }
    echo "Table 'departments' created successfully!<br>";

    // Create password_resets table
    $create_password_resets = "CREATE TABLE password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_password_resets)) {
        throw new Exception("Error creating password_resets table: " . $db->error);
    }
    echo "Table 'password_resets' created successfully!<br>";

    // Create indexes for password_resets
    $create_index_user = "CREATE INDEX idx_password_resets_user_id ON password_resets(user_id)";
    if (!$db->query($create_index_user)) {
        echo "Warning: Could not create user_id index on password_resets: " . $db->error . "<br>";
        error_log("Warning: Could not create user_id index on password_resets: " . $db->error, 3, $log_file);
    } else {
        echo "Index 'idx_password_resets_user_id' created successfully.<br>";
    }
    $create_index_expires = "CREATE INDEX idx_password_resets_expires_at ON password_resets(expires_at)";
    if (!$db->query($create_index_expires)) {
        echo "Warning: Could not create expires_at index on password_resets: " . $db->error . "<br>";
        error_log("Warning: Could not create expires_at index on password_resets: " . $db->error, 3, $log_file);
    } else {
        echo "Index 'idx_password_resets_expires_at' created successfully.<br>";
    }

    // Create committees table
    $create_committees = "CREATE TABLE committees (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        handler_id INT UNSIGNED NOT NULL,
        complaint_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (handler_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_committees)) {
        throw new Exception("Error creating committees table: " . $db->error);
    }
    echo "Table 'committees' created successfully!<br>";

    // Create committee_members table
    $create_committee_members = "CREATE TABLE committee_members (
        committee_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        is_handler TINYINT(1) DEFAULT 0,
        assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (committee_id, user_id),
        FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_committee_members)) {
        throw new Exception("Error creating committee_members table: " . $db->error);
    }
    echo "Table 'committee_members' created successfully!<br>";

    // Create committee_decisions table
    $create_committee_decisions = "CREATE TABLE committee_decisions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        committee_id INT UNSIGNED NOT NULL,
        decision_text TEXT NOT NULL,
        proposed_by INT UNSIGNED NOT NULL,
        status ENUM('pending', 'sent') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
        FOREIGN KEY (proposed_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_committee_decisions)) {
        throw new Exception("Error creating committee_decisions table: " . $db->error);
    }
    echo "Table 'committee_decisions' created successfully!<br>";

    // Create decision_agreements table
    $create_decision_agreements = "CREATE TABLE decision_agreements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        committee_decision_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (committee_decision_id) REFERENCES committee_decisions(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE (committee_decision_id, user_id)
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_decision_agreements)) {
        throw new Exception("Error creating decision_agreements table: " . $db->error);
    }
    echo "Table 'decision_agreements' created successfully!<br>";

    // Create committee_messages table
    $create_committee_messages = "CREATE TABLE committee_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        committee_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        message_text TEXT NOT NULL,
        message_type ENUM('user', 'system') DEFAULT 'user' NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_committee_messages)) {
        throw new Exception("Error creating committee_messages table: " . $db->error);
    }
    echo "Table 'committee_messages' created successfully!<br>";

    // Create complaints table
    $create_complaints = "CREATE TABLE complaints (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        handler_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        category ENUM('academic', 'administrative') NOT NULL,
        directorate ENUM('HRM', 'Finance', 'General Service', '') DEFAULT '',
        status ENUM('pending', 'validated', 'in_progress', 'resolved', 'rejected', 'pending_more_info', 'escalated') DEFAULT 'pending',
        visibility ENUM('standard', 'anonymous') DEFAULT 'standard',
        needs_video_chat BOOLEAN DEFAULT FALSE,
        needs_committee TINYINT(1) DEFAULT 0,
        committee_id INT UNSIGNED NULL,
        department_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        resolution_date TIMESTAMP NULL,
        resolution_details TEXT NULL,
        rejection_reason TEXT DEFAULT NULL,
        evidence_file VARCHAR(255) NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (handler_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE SET NULL,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_complaints)) {
        throw new Exception("Error creating complaints table: " . $db->error);
    }
    echo "Table 'complaints' created successfully!<br>";

    // Create stereotypes table
    $create_stereotypes = "CREATE TABLE stereotypes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(50) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_stereotypes)) {
        throw new Exception("Error creating stereotypes table: " . $db->error);
    }
    echo "Table 'stereotypes' created successfully!<br>";

    // Create complaint_stereotypes table
    $create_complaint_stereotypes = "CREATE TABLE complaint_stereotypes (
        complaint_id INT UNSIGNED NOT NULL,
        stereotype_id INT UNSIGNED NOT NULL,
        tagged_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (complaint_id, stereotype_id),
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        FOREIGN KEY (stereotype_id) REFERENCES stereotypes(id) ON DELETE CASCADE,
        FOREIGN KEY (tagged_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_complaint_stereotypes)) {
        throw new Exception("Error creating complaint_stereotypes table: " . $db->error);
    }
    echo "Table 'complaint_stereotypes' created successfully!<br>";

    // Create escalations table
    $create_escalations = "CREATE TABLE escalations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT UNSIGNED NOT NULL,
        escalated_to ENUM('sims', 'cost_sharing', 'campus_registrar', 'university_registrar', 'academic_vp', 'president', 'academic', 'department_head', 'college_dean', 'administrative_vp', 'student_service_directorate', 'dormitory_service', 'students_food_service', 'library_service', 'hrm', 'finance', 'general_service') NOT NULL,
        escalated_to_id INT UNSIGNED NULL,
        escalated_by_id INT UNSIGNED NOT NULL,
        college VARCHAR(100),
        department_id INT UNSIGNED NULL,
        directorate ENUM('HRM', 'Finance', 'General Service', 'administrative_vp', '') DEFAULT '',
        status ENUM('pending', 'resolved', 'escalated') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        resolution_details TEXT NULL,
        original_handler_id INT UNSIGNED NOT NULL,
        action_type ENUM('assignment', 'escalation') DEFAULT 'assignment',
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        FOREIGN KEY (escalated_to_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (escalated_by_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
        FOREIGN KEY (original_handler_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_escalations)) {
        throw new Exception("Error creating escalations table: " . $db->error);
    }
    echo "Table 'escalations' created successfully!<br>";

    // Create decisions table
    $create_decisions = "CREATE TABLE decisions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        escalation_id INT UNSIGNED NULL,
        complaint_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        receiver_id INT UNSIGNED NULL,
        decision_text TEXT NOT NULL,
        status ENUM('pending', 'final') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        file_path VARCHAR(255) DEFAULT NULL,
        FOREIGN KEY (escalation_id) REFERENCES escalations(id) ON DELETE CASCADE,
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_decisions)) {
        throw new Exception("Error creating decisions table: " . $db->error);
    }
    echo "Table 'decisions' created successfully!<br>";

    // Create notifications table
    $create_notifications = "CREATE TABLE notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        complaint_id INT UNSIGNED NULL,
        description TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_notifications)) {
        throw new Exception("Error creating notifications table: " . $db->error);
    }
    echo "Table 'notifications' created successfully!<br>";

    // Create feedback table
    $create_feedback = "CREATE TABLE feedback (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        description TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_feedback)) {
        throw new Exception("Error creating feedback table: " . $db->error);
    }
    echo "Table 'feedback' created successfully!<br>";

    // Create notices table
    $create_notices = "CREATE TABLE notices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        handler_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (handler_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_notices)) {
        throw new Exception("Error creating notices table: " . $db->error);
    }
    echo "Table 'notices' created successfully!<br>";

    // Create reports table
    $create_reports = "CREATE TABLE reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        report_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        report_type ENUM('weekly', 'monthly') NOT NULL,
        academic_complaints INT NOT NULL,
        administrative_complaints INT NOT NULL,
        total_pending INT NOT NULL,
        total_in_progress INT NOT NULL,
        total_resolved INT NOT NULL,
        total_rejected INT NOT NULL,
        sent_to INT UNSIGNED NOT NULL,
        FOREIGN KEY (sent_to) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_reports)) {
        throw new Exception("Error creating reports table: " . $db->error);
    }
    echo "Table 'reports' created successfully!<br>";

    // Create stereotyped_reports table
    $create_stereotyped_reports = "CREATE TABLE stereotyped_reports (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT UNSIGNED NOT NULL,
        handler_id INT UNSIGNED NOT NULL,
        recipient_id INT UNSIGNED NOT NULL,
        report_type ENUM('assigned', 'resolved', 'escalated', 'decision_received') NOT NULL,
        report_content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        FOREIGN KEY (handler_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_stereotyped_reports)) {
        throw new Exception("Error creating stereotyped_reports table: " . $db->error);
    }
    echo "Table 'stereotyped_reports' created successfully!<br>";

    // Create abusive_words table
    $create_abusive_words = "CREATE TABLE abusive_words (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        word VARCHAR(255) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_abusive_words)) {
        throw new Exception("Error creating abusive_words table: " . $db->error);
    }
    echo "Table 'abusive_words' created successfully!<br>";

    // Create complaint_logs table
    $create_complaint_logs = "CREATE TABLE complaint_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL,
        details TEXT,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$db->query($create_complaint_logs)) {
        throw new Exception("Error creating complaint_logs table: " . $db->error);
    }
    echo "Table 'complaint_logs' created successfully!<br>";

    // Re-enable foreign key checks
    if (!$db->query("SET FOREIGN_KEY_CHECKS = 1")) {
        echo "Warning: Could not re-enable foreign key checks: " . $db->error . "<br>";
        error_log("Warning: Could not re-enable foreign key checks: " . $db->error, 3, $log_file);
    } else {
        echo "Foreign key checks re-enabled.<br>";
    }
    echo "Finished creating tables.<br><br>";

    // --- Insert Sample Data ---
    echo "Inserting sample data...<br>";
$password = password_hash('password', PASSWORD_DEFAULT);

    // Insert sample users and store generated IDs
$stmt = $db->prepare("INSERT INTO users (username, password, role, fname, lname, phone, sex, email, department, college, status, suspended_until, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$users = [
    ['user1', $password, 'user', 'John', 'Doe', '1234567890', 'male', 'john.doe@example.com', NULL, NULL, 'suspended', '2025-05-01 14:30:00', '2025-04-01 08:00:00'],
    ['user2', $password, 'user', 'Jane', 'Smith', '9876543210', 'female', 'jane.smith@example.com', 'Physics', 'College of Science', 'active', NULL, '2025-04-01 08:05:00'],
    ['handler1', $password, 'handler', 'Michael', 'Green', '0987654321', 'male', 'michael.green@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:10:00'],
        ['admin1', $password, 'admin', 'Admin', 'User', '5555555555', 'other', 'admin@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:15:00'],
        ['sims1', $password, 'sims', 'Carol', 'Jones', '7778889999', 'female', 'carol.jones@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:20:00'],
        ['costsharing1', $password, 'cost_sharing', 'Tom', 'Brown', '2223334444', 'male', 'tom.brown@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:25:00'],
        ['campusreg1', $password, 'campus_registrar', 'Sarah', 'Johnson', '1112223333', 'female', 'sarah.johnson@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:30:00'],
        ['univreg1', $password, 'university_registrar', 'David', 'Lee', '4445556666', 'male', 'david.lee@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:35:00'],
        ['academicvp1', $password, 'academic_vp', 'Emily', 'Davis', '9990001111', 'female', 'emily.davis@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:40:00'],
        ['president1', $password, 'president', 'Robert', 'Smith', '1231231234', 'male', 'president@example.com', NULL, NULL, 'active', NULL, '2025-04-01 08:45:00'],
        ['academic1', $password, 'academic', 'Eve', 'Wilson', '6667778888', 'female', 'eve.wilson@example.com', 'Physics', 'College of Science', 'active', NULL, '2025-04-01 08:50:00'],
        ['depthead1', $password, 'department_head', 'Henry', 'Clark', '3334445555', 'male', 'henry.clark@example.com', 'Computer Science', 'College of Technology', 'active', NULL, '2025-04-01 08:55:00'],
        ['collegedean1', $password, 'college_dean', 'Grace', 'Taylor', '8889990000', 'female', 'grace.taylor@example.com', NULL, 'College of Technology', 'active', NULL, '2025-04-01 09:00:00'],
        ['depthead2', $password, 'department_head', 'Samuel', 'Aschalew', '5556667777', 'male', 'samuel.aschalew@example.com', 'Computer Science', 'College of Technology', 'active', NULL, '2025-04-01 09:05:00'],
        ['adminvp1', $password, 'administrative_vp', 'Laura', 'Adams', '7776665555', 'female', 'laura.adams@example.com', NULL, NULL, 'active', NULL, '2025-04-01 09:10:00'],
        ['ssd1', $password, 'student_service_directorate', 'Alex', 'Miller', '6665554444', 'male', 'alex.miller@example.com', NULL, NULL, 'active', NULL, '2025-04-01 09:15:00'],
        ['dormservice1', $password, 'dormitory_service', 'Mary', 'Johnson', '5556667777', 'female', 'mary.johnson@example.com', NULL, NULL, 'active', NULL, '2025-04-01 09:20:00'],
        ['foodservice1', $password, 'students_food_service', 'Peter', 'Williams', '4445556666', 'male', 'peter.williams@example.com', NULL, NULL, 'active', NULL, '2025-04-01 09:25:00'],
        ['libraryservice1', $password, 'library_service', 'Lisa', 'Brown', '3334445555', 'female', 'lisa.brown@example.com', NULL, NULL, 'active', NULL, '2025-04-01 09:30:00'],
        ['hrm1', $password, 'hrm', 'HRM', 'Director', '1112223333', 'male', 'hrm@dmu.edu', NULL, NULL, 'active', NULL, '2025-04-01 09:35:00'],
        ['finance1', $password, 'finance', 'Finance', 'Director', '2223334444', 'female', 'finance@dmu.edu', NULL, NULL, 'active', NULL, '2025-04-01 09:40:00'],
        ['generalservice1', $password, 'general_service', 'General', 'Service', '3334445555', 'male', 'general@dmu.edu', NULL, NULL, 'active', NULL, '2025-04-01 09:45:00']
    ];
    $user_ids = [];
    foreach ($users as $index => $user) {
        $stmt->bind_param("sssssssssssss", ...$user);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into users: " . $stmt->error);
        }
        $user_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'users'.<br>";

    // Insert sample departments
    $stmt = $db->prepare("INSERT INTO departments (name, head_id, created_at) VALUES (?, ?, ?)");
$departments = [
    ['Computer Science', $user_ids[12], '2025-04-01 08:55:00'],
    ['Physics', NULL, '2025-04-01 09:00:00']
];
$department_ids = [];
foreach ($departments as $index => $dept) {
    $stmt->bind_param("sis", ...$dept);
    if (!$stmt->execute()) {
        throw new Exception("Error inserting into departments: " . $stmt->error);
    }
    $department_ids[$index + 1] = $db->insert_id;
}
$stmt->close();
echo "Inserted sample data into 'departments'.<br>";
// Ensure uploads directory exists
$uploads_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
if (!is_dir($uploads_dir)) {
    if (!mkdir($uploads_dir, 0755, true)) {
        throw new Exception("Error creating uploads directory: $uploads_dir");
    }
    echo "Created 'uploads' directory.<br>";
}

    // Insert sample complaints with handler_id set to handler1 (user_ids[3])
$stmt = $db->prepare("INSERT INTO complaints (user_id, handler_id, title, description, category, directorate, status, visibility, department_id, evidence_file, created_at, updated_at, resolution_date, resolution_details, committee_id, needs_committee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$complaints = [
    [$user_ids[1], $user_ids[3], 'Incorrect Course Registration', 'I was enrolled in the wrong course for Fall 2025.', 'academic', '', 'in_progress', 'standard', NULL, 'uploads/schedule_screenshot.png', '2025-04-02 09:00:00', '2025-04-02 10:00:00', NULL, NULL, NULL, 0],
    [$user_ids[2], $user_ids[3], 'Payment Issue', 'Overcharged for tuition fees.', 'administrative', '', 'resolved', 'anonymous', NULL, NULL, '2025-04-02 09:15:00', '2025-04-03 14:00:00', '2025-04-03 14:00:00', 'Refund processed.', NULL, 0],
    [$user_ids[1], $user_ids[3], 'Grading Issue', 'Issue with grading process in CS101.', 'academic', '', 'in_progress', 'standard', $department_ids[1], NULL, '2025-04-12 22:30:00', '2025-04-12 22:40:00', NULL, NULL, NULL, 1],
       
        [$user_ids[2], $user_ids[3], 'Transcript Error', 'My transcript shows an incorrect grade.', 'academic', '', 'escalated', 'standard', NULL, 'uploads/transcript.pdf', '2025-04-13 08:00:00', '2025-04-14 09:00:00', NULL, NULL, NULL, 0],
        [$user_ids[1], $user_ids[3], 'Lab Access Issue', 'Unable to access the computer lab due to scheduling conflict.', 'academic', '', 'pending', 'standard', $department_ids[1], NULL, '2025-04-15 10:00:00', NULL, NULL, NULL, NULL, 1],
        [$user_ids[2], $user_ids[3], 'Fee Refund Delay', 'Requested a refund for a dropped course, but it has been delayed.', 'administrative', '', 'pending', 'anonymous', NULL, NULL, '2025-04-16 11:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[1], $user_ids[3], 'Administrative Delay in Fee Processing', 'Fee payment processed late, causing registration issues.', 'administrative', '', 'in_progress', 'standard', NULL, NULL, '2025-04-17 12:00:00', '2025-04-17 13:00:00', NULL, NULL, NULL, 0],
        [$user_ids[2], $user_ids[3], 'Incorrect Billing Statement', 'Received an incorrect billing statement for the semester.', 'administrative', '', 'pending', 'standard', NULL, 'uploads/billing_statement.pdf', '2025-04-18 09:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[1], $user_ids[3], 'Dormitory Cleanliness Issue', 'The dormitory bathroom is consistently unclean.', 'administrative', '', 'pending', 'standard', NULL, NULL, '2025-04-19 10:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[2], $user_ids[3], 'Cafeteria Food Quality', 'The cafeteria food is often stale.', 'administrative', '', 'pending', 'anonymous', NULL, NULL, '2025-04-19 11:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[1], $user_ids[3], 'Insufficient Library Resources', 'The library lacks sufficient resources for research students.', 'administrative', '', 'pending', 'standard', NULL, NULL, '2025-04-19 12:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[1], $user_ids[3], 'Staff Misconduct', 'Complaint about inappropriate behavior by a staff member.', 'administrative', 'HRM', 'pending', 'standard', NULL, NULL, '2025-04-20 10:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[2], $user_ids[3], 'Budget Allocation Issue', 'Incorrect budget allocation for department project.', 'administrative', 'Finance', 'pending', 'anonymous', NULL, NULL, '2025-04-20 11:00:00', NULL, NULL, NULL, NULL, 0],
        [$user_ids[1], $user_ids[3], 'Facility Maintenance', 'Broken classroom projector not repaired.', 'administrative', 'General Service', 'pending', 'standard', NULL, NULL, '2025-04-20 12:00:00', NULL, NULL, NULL, NULL, 0]
    ];
    $complaint_ids = [];
    foreach ($complaints as $index => $complaint) {
        if (!empty($complaint[9])) { // evidence_file is at index 9
            $evidence_path = $complaint[9];
            $full_path = $uploads_dir . basename($evidence_path);
            if (!file_exists($full_path)) {
                file_put_contents($full_path, 'Dummy content for ' . basename($evidence_path));
                echo "Created evidence file: $evidence_path<br>";
            }
        }
        $stmt->bind_param("iisssssiisssssii", ...$complaint);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into complaints: " . $stmt->error);
        }
        $complaint_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'complaints'.<br>";

   // Insert sample committees with handler as member
$stmt = $db->prepare("INSERT INTO committees (name, handler_id, complaint_id, created_at) VALUES (?, ?, ?, ?)");
$committees = [
    ['Academic Review Committee', $user_ids[3], $complaint_ids[3], '2025-04-12 22:40:00'],
    ['Administrative Review Committee', $user_ids[3], $complaint_ids[2], '2025-04-02 09:20:00'], // Added for complaint #2
    ['Lab Access Committee', $user_ids[3], $complaint_ids[5], '2025-04-15 10:05:00'] // Added for complaint #5
];
$committee_ids = [];
foreach ($committees as $index => $committee) {
    $stmt->bind_param("siss", ...$committee);
    if (!$stmt->execute()) {
        throw new Exception("Error inserting into committees: " . $stmt->error);
    }
    $committee_ids[$index + 1] = $db->insert_id;
}
// Update complaints with committee_id where needs_committee = 1
$stmt = $db->prepare("UPDATE complaints SET committee_id = ? WHERE id = ? AND needs_committee = 1");
$stmt->bind_param("ii", $committee_ids[1], $complaint_ids[3]);
$stmt->execute();
$stmt->bind_param("ii", $committee_ids[2], $complaint_ids[2]); // Assuming committee for resolved complaint
$stmt->execute();
$stmt->bind_param("ii", $committee_ids[3], $complaint_ids[5]);
$stmt->execute();
$stmt->close();
echo "Updated 'complaints' with committee_id.<br>";

// Insert sample committee members with handler as member of all committees
$stmt = $db->prepare("INSERT INTO committee_members (committee_id, user_id, is_handler, assigned_at) VALUES (?, ?, ?, ?)");
$committee_members = [];
foreach ($committee_ids as $cid) {
    $committee_members[] = [$cid, $user_ids[3], 1, '2025-04-12 22:40:00']; // Handler as member
    $committee_members[] = [$cid, $user_ids[12], 0, '2025-04-12 22:40:00']; // depthead1
    $committee_members[] = [$cid, $user_ids[13], 0, '2025-04-12 22:40:00']; // collegedean1
    $committee_members[] = [$cid, $user_ids[14], 0, '2025-04-12 22:40:00']; // depthead2
}
foreach ($committee_members as $member) {
    $stmt->bind_param("iiis", ...$member);
    if (!$stmt->execute()) {
        throw new Exception("Error inserting into committee_members: " . $stmt->error);
    }
}
$stmt->close();
echo "Inserted sample data into 'committee_members'.<br>";

    // Insert sample committee_decisions
    $stmt = $db->prepare("INSERT INTO committee_decisions (committee_id, decision_text, proposed_by, status, created_at) VALUES (?, ?, ?, ?, ?)");
    $committee_decisions = [
        [$committee_ids[1], 'The grading issue for complaint #3 should be reviewed and corrected by the department head.', $user_ids[3], 'pending', '2025-04-13 09:00:00']
    ];
    $decision_ids = [];
    foreach ($committee_decisions as $index => $decision) {
        $stmt->bind_param("isiss", ...$decision);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into committee_decisions: " . $stmt->error);
        }
        $decision_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'committee_decisions'.<br>";

    // Insert sample decision_agreements
    $stmt = $db->prepare("INSERT INTO decision_agreements (committee_decision_id, user_id, created_at) VALUES (?, ?, ?)");
    $decision_agreements = [
        [$decision_ids[1], $user_ids[12], '2025-04-13 09:05:00'], // depthead1 agrees
        [$decision_ids[1], $user_ids[13], '2025-04-13 09:10:00'], // collegedean1 agrees
        [$decision_ids[1], $user_ids[14], '2025-04-13 09:15:00']  // depthead2 agrees
    ];
    foreach ($decision_agreements as $agreement) {
        $stmt->bind_param("iis", ...$agreement);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into decision_agreements: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'decision_agreements'.<br>";

    // Insert sample committee_messages
    $stmt = $db->prepare("INSERT INTO committee_messages (committee_id, sender_id, message_text, message_type, is_read, sent_at) VALUES (?, ?, ?, ?, ?, ?)");
    $committee_messages = [
        [$committee_ids[1], $user_ids[3], "Let's discuss the grading issue for complaint #3. Can we meet tomorrow?", 'user', 0, '2025-04-12 22:45:00'],
        [$committee_ids[1], $user_ids[12], "Tomorrow works for me. What time?", 'user', 0, '2025-04-12 22:50:00'],
        [$committee_ids[1], $user_ids[13], "I suggest 10 AM. Does that work for everyone?", 'user', 0, '2025-04-12 22:55:00'],
        [$committee_ids[1], $user_ids[14], "10 AM is fine. I'll prepare some notes on the grading criteria.", 'user', 0, '2025-04-12 23:00:00']
    ];
    $committee_message_ids = [];
    foreach ($committee_messages as $index => $message) {
        $stmt->bind_param("iisisi", ...$message);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into committee_messages: " . $stmt->error);
        }
        $committee_message_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'committee_messages'.<br>";

    // Insert sample stereotypes
    $stmt = $db->prepare("INSERT INTO stereotypes (label, description, created_at) VALUES (?, ?, ?)");
    $stereotypes = [
        ['discrimination', 'Complaints involving discriminatory behavior or practices.', '2025-04-01 09:00:00'],
        ['harassment', 'Complaints involving harassment, bullying, or inappropriate behavior.', '2025-04-01 09:05:00'],
        ['bias', 'Complaints involving unfair bias or favoritism.', '2025-04-01 09:10:00'],
        ['accessibility', 'Complaints related to accessibility issues for students with disabilities.', '2025-04-01 09:15:00']
    ];
    $stereotype_ids = [];
    foreach ($stereotypes as $index => $stereotype) {
        $stmt->bind_param("sss", ...$stereotype);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into stereotypes: " . $stmt->error);
        }
        $stereotype_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'stereotypes'.<br>";

    // Insert sample complaint_stereotypes
    $stmt = $db->prepare("INSERT INTO complaint_stereotypes (complaint_id, stereotype_id, tagged_by, created_at) VALUES (?, ?, ?, ?)");
    $complaint_stereotypes = [
        [$complaint_ids[3], $stereotype_ids[3], $user_ids[3], '2025-04-12 22:35:00'],
        [$complaint_ids[5], $stereotype_ids[4], $user_ids[3], '2025-04-15 10:05:00'],
        [$complaint_ids[11], $stereotype_ids[4], $user_ids[3], '2025-04-19 12:05:00'],
        [$complaint_ids[12], $stereotype_ids[2], $user_ids[3], '2025-04-20 10:05:00']
    ];
    foreach ($complaint_stereotypes as $cs) {
        $stmt->bind_param("iiis", ...$cs);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into complaint_stereotypes: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'complaint_stereotypes'.<br>";

    // Insert sample escalations
    $stmt = $db->prepare("INSERT INTO escalations (complaint_id, escalated_to, escalated_to_id, escalated_by_id, college, department_id, directorate, status, created_at, updated_at, resolved_at, resolution_details, original_handler_id, action_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $escalations = [
        [$complaint_ids[1], 'sims', $user_ids[5], $user_ids[3], NULL, NULL, '', 'pending', '2025-04-02 10:00:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[2], 'cost_sharing', $user_ids[6], $user_ids[3], NULL, NULL, '', 'resolved', '2025-04-02 10:15:00', '2025-04-03 14:00:00', '2025-04-03 14:00:00', 'Refund processed.', $user_ids[3], 'assignment'],
        [$complaint_ids[3], 'department_head', $user_ids[14], $user_ids[3], 'College of Technology', $department_ids[1], '', 'pending', '2025-04-12 22:40:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[4], 'sims', $user_ids[5], $user_ids[3], NULL, NULL, '', 'escalated', '2025-04-13 08:30:00', '2025-04-13 09:00:00', NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[4], 'campus_registrar', $user_ids[7], $user_ids[5], NULL, NULL, '', 'pending', '2025-04-13 09:00:00', NULL, NULL, NULL, $user_ids[3], 'escalation'],
        [$complaint_ids[5], 'sims', $user_ids[5], $user_ids[3], 'College of Technology', $department_ids[1], '', 'pending', '2025-04-15 10:10:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[6], 'cost_sharing', $user_ids[6], $user_ids[3], NULL, NULL, '', 'pending', '2025-04-16 11:10:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[7], 'cost_sharing', $user_ids[6], $user_ids[3], NULL, NULL, '', 'in_progress', '2025-04-17 13:00:00', NULL, NULL, NULL, $user_ids[3], 'escalation'],
        [$complaint_ids[7], 'administrative_vp', $user_ids[15], $user_ids[6], NULL, NULL, '', 'pending', '2025-04-17 14:00:00', NULL, NULL, NULL, $user_ids[3], 'escalation'],
        [$complaint_ids[8], 'cost_sharing', $user_ids[6], $user_ids[3], NULL, NULL, '', 'pending', '2025-04-18 09:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[8], 'student_service_directorate', $user_ids[16], $user_ids[6], NULL, NULL, '', 'pending', '2025-04-18 10:00:00', NULL, NULL, NULL, $user_ids[3], 'escalation'],
        [$complaint_ids[9], 'dormitory_service', $user_ids[17], $user_ids[3], NULL, NULL, '', 'pending', '2025-04-19 10:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[10], 'students_food_service', $user_ids[18], $user_ids[3], NULL, NULL, '', 'pending', '2025-04-19 11:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[11], 'library_service', $user_ids[19], $user_ids[3], NULL, NULL, '', 'pending', '2025-04-19 12:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[12], 'hrm', $user_ids[20], $user_ids[3], NULL, NULL, 'HRM', 'pending', '2025-04-20 10:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[13], 'finance', $user_ids[21], $user_ids[3], NULL, NULL, 'Finance', 'pending', '2025-04-20 11:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[14], 'general_service', $user_ids[22], $user_ids[3], NULL, NULL, 'General Service', 'pending', '2025-04-20 12:30:00', NULL, NULL, NULL, $user_ids[3], 'assignment'],
        [$complaint_ids[12], 'administrative_vp', $user_ids[15], $user_ids[20], NULL, NULL, 'HRM', 'pending', '2025-04-20 11:00:00', NULL, NULL, 'Requires policy review.', $user_ids[3], 'escalation']
    ];
    $escalation_ids = [];
    foreach ($escalations as $index => $escalation) {
        $stmt->bind_param("isiisississsis", ...$escalation);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into escalations: " . $stmt->error);
        }
        $escalation_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'escalations'.<br>";

    // Insert sample decisions
    $stmt = $db->prepare("INSERT INTO decisions (escalation_id, complaint_id, sender_id, receiver_id, decision_text, status, created_at, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $decisions = [
        [$escalation_ids[2], $complaint_ids[2], $user_ids[6], $user_ids[3], 'Refund processed after verifying overcharge.', 'final', '2025-04-03 14:05:00', NULL],
        [$escalation_ids[5], $complaint_ids[4], $user_ids[5], $user_ids[7], 'Escalated to Campus Registrar for further review.', 'pending', '2025-04-13 09:05:00', NULL],
        [$escalation_ids[9], $complaint_ids[7], $user_ids[15], $user_ids[10], 'Escalated to President for final approval on fee processing delay.', 'pending', '2025-04-17 14:05:00', NULL],
        [$escalation_ids[11], $complaint_ids[8], $user_ids[16], $user_ids[3], 'Reviewing incorrect billing statement for further action.', 'pending', '2025-04-18 10:05:00', NULL],
        [$escalation_ids[12], $complaint_ids[9], $user_ids[17], $user_ids[3], 'Dormitory cleaning schedule updated to address complaint.', 'final', '2025-04-19 12:00:00', NULL],
        [$escalation_ids[13], $complaint_ids[10], $user_ids[18], $user_ids[3], 'Food quality issue escalated to Student Service Directorate for policy review.', 'pending', '2025-04-19 13:00:00', NULL],
        [$escalation_ids[14], $complaint_ids[11], $user_ids[19], $user_ids[3], 'Library resource issue under review; additional books ordered.', 'final', '2025-04-19 14:00:00', NULL],
        [$escalation_ids[15], $complaint_ids[12], $user_ids[20], $user_ids[3], 'Staff disciplined following investigation.', 'final', '2025-04-20 10:45:00', NULL],
        [$escalation_ids[18], $complaint_ids[12], $user_ids[15], $user_ids[3], 'Policy review completed; new staff conduct guidelines issued.', 'final', '2025-04-20 11:30:00', NULL]
    ];
    $decision_ids = [];
    foreach ($decisions as $index => $decision) {
        $stmt->bind_param("iiiissss", ...$decision);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into decisions: " . $stmt->error);
        }
        $decision_ids[$index + 1] = $db->insert_id;
    }
    $stmt->close();
    echo "Inserted sample data into 'decisions'.<br>";

    // Insert sample notifications
    $stmt = $db->prepare("INSERT INTO notifications (user_id, complaint_id, description, is_read, created_at) VALUES (?, ?, ?, ?, ?)");
       // Insert sample notifications (continued)
       $notifications = [
        [$user_ids[1], $complaint_ids[1], 'Your complaint "Incorrect Course Registration" has been assigned to SIMS.', 0, '2025-04-02 10:01:00'],
        [$user_ids[5], $complaint_ids[1], 'New complaint assigned: "Incorrect Course Registration".', 0, '2025-04-02 10:01:00'],
        [$user_ids[2], $complaint_ids[2], 'Your complaint "Payment Issue" has been resolved: Refund processed.', 0, '2025-04-03 14:01:00'],
        [$user_ids[6], $complaint_ids[2], 'New complaint assigned: "Payment Issue".', 0, '2025-04-02 10:16:00'],
        [$user_ids[1], $complaint_ids[3], 'Your complaint "Grading Issue" has been assigned to a committee.', 0, '2025-04-12 22:41:00'],
        [$user_ids[12], $complaint_ids[3], 'You have been assigned to the committee for complaint #3.', 0, '2025-04-12 22:41:00'],
        [$user_ids[13], $complaint_ids[3], 'You have been assigned to the committee for complaint #3.', 0, '2025-04-12 22:41:00'],
        [$user_ids[14], $complaint_ids[3], 'You have been assigned to the committee for complaint #3.', 0, '2025-04-12 22:41:00'],
        [$user_ids[5], $complaint_ids[4], 'New complaint escalated: "Transcript Error".', 0, '2025-04-13 08:31:00'],
        [$user_ids[7], $complaint_ids[4], 'Complaint "Transcript Error" escalated to you.', 0, '2025-04-13 09:01:00'],
        [$user_ids[2], $complaint_ids[6], 'Your complaint "Fee Refund Delay" has been assigned.', 0, '2025-04-16 11:11:00'],
        [$user_ids[6], $complaint_ids[6], 'New complaint assigned: "Fee Refund Delay".', 0, '2025-04-16 11:11:00'],
        [$user_ids[1], $complaint_ids[7], 'Your complaint "Administrative Delay in Fee Processing" has been escalated.', 0, '2025-04-17 14:01:00'],
        [$user_ids[15], $complaint_ids[7], 'Complaint "Administrative Delay in Fee Processing" escalated to you.', 0, '2025-04-17 14:01:00']
    ];
    foreach ($notifications as $notification) {
        $stmt->bind_param("iisis", ...$notification);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into notifications: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'notifications'.<br>";

    // Insert sample feedback
    $stmt = $db->prepare("INSERT INTO feedback (user_id, description, created_at) VALUES (?, ?, ?)");
    $feedback = [
        [$user_ids[1], 'The complaint process was smooth, but resolution took too long.', '2025-04-03 15:00:00'],
        [$user_ids[2], 'Great support from the handler on my payment issue.', '2025-04-03 15:05:00']
    ];
    foreach ($feedback as $fb) {
        $stmt->bind_param("iss", ...$fb);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into feedback: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'feedback'.<br>";

    // Insert sample notices
    $stmt = $db->prepare("INSERT INTO notices (handler_id, title, description, created_at) VALUES (?, ?, ?, ?)");
    $notices = [
        [$user_ids[3], 'New Complaint Procedure', 'Please review the updated complaint handling guidelines.', '2025-04-01 10:00:00'],
        [NULL, 'System Maintenance', 'The system will be down for maintenance on May 5th, 2025.', '2025-04-15 09:00:00']
    ];
    foreach ($notices as $notice) {
        $stmt->bind_param("isss", ...$notice);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into notices: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'notices'.<br>";

    // Insert sample reports
    $stmt = $db->prepare("INSERT INTO reports (report_date, report_type, academic_complaints, administrative_complaints, total_pending, total_in_progress, total_resolved, total_rejected, sent_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $reports = [
        ['2025-04-01 00:00:00', 'weekly', 2, 3, 5, 2, 1, 0, $user_ids[4]], // admin1
        ['2025-04-15 00:00:00', 'monthly', 5, 7, 10, 3, 2, 1, $user_ids[9]] // academicvp1
    ];
    foreach ($reports as $report) {
        $stmt->bind_param("ssiisiiii", ...$report);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into reports: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'reports'.<br>";

    // Insert sample stereotyped_reports
    $stmt = $db->prepare("INSERT INTO stereotyped_reports (complaint_id, handler_id, recipient_id, report_type, report_content, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stereotyped_reports = [
        [$complaint_ids[1], $user_ids[3], $user_ids[5], 'assigned', 'Assigned complaint #1 to SIMS for review.', '2025-04-02 10:02:00'],
        [$complaint_ids[2], $user_ids[3], $user_ids[6], 'resolved', 'Resolved complaint #2 with refund.', '2025-04-03 14:02:00'],
        [$complaint_ids[3], $user_ids[3], $user_ids[14], 'assigned', 'Assigned complaint #3 to department head.', '2025-04-12 22:42:00'],
        [$complaint_ids[4], $user_ids[5], $user_ids[7], 'escalated', 'Escalated complaint #4 to Campus Registrar.', '2025-04-13 09:02:00']
    ];
    foreach ($stereotyped_reports as $sr) {
        $stmt->bind_param("iiiiss", ...$sr);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into stereotyped_reports: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'stereotyped_reports'.<br>";

    // Insert sample abusive_words
    $stmt = $db->prepare("INSERT INTO abusive_words (word, created_at) VALUES (?, ?)");
    $abusive_words = [
        ['abuse', '2025-04-01 09:00:00'],
        ['hate', '2025-04-01 09:05:00']
    ];
    foreach ($abusive_words as $word) {
        $stmt->bind_param("ss", ...$word);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into abusive_words: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'abusive_words'.<br>";

    // Insert sample complaint_logs
    $stmt = $db->prepare("INSERT INTO complaint_logs (user_id, action, details, created_at) VALUES (?, ?, ?, ?)");
    $complaint_logs = [
        [$user_ids[1], 'filed', 'Filed complaint #1: Incorrect Course Registration', '2025-04-02 09:00:00'],
        [$user_ids[2], 'filed', 'Filed complaint #2: Payment Issue', '2025-04-02 09:15:00'],
        [$user_ids[1], 'updated', 'Updated complaint #3 status to in_progress', '2025-04-12 22:40:00']
    ];
    foreach ($complaint_logs as $log) {
        $stmt->bind_param("isss", ...$log);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting into complaint_logs: " . $stmt->error);
        }
    }
    $stmt->close();
    echo "Inserted sample data into 'complaint_logs'.<br>";

    // --- Create Triggers ---
    echo "Creating triggers...<br>";

    // Trigger to log complaint status changes
    $create_trigger_complaint_status = "
        CREATE TRIGGER after_complaint_update
        AFTER UPDATE ON complaints
        FOR EACH ROW
        BEGIN
            IF NEW.status != OLD.status THEN
                INSERT INTO complaint_logs (user_id, action, details, created_at)
                VALUES (NEW.handler_id, 'updated', CONCAT('Updated complaint #', NEW.id, ' status from ', OLD.status, ' to ', NEW.status), NOW());
            END IF;
        END;
    ";
    if (!$db->query($create_trigger_complaint_status)) {
        throw new Exception("Error creating trigger 'after_complaint_update': " . $db->error);
    }
    echo "Trigger 'after_complaint_update' created successfully!<br>";

    // --- Create Views ---
    echo "Creating views...<br>";

    // View for pending complaints
    $create_view_pending_complaints = "
        CREATE VIEW pending_complaints AS
        SELECT c.id, c.title, c.description, c.category, c.status, u.fname, u.lname, c.created_at
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.status = 'pending';
    ";
    if (!$db->query($create_view_pending_complaints)) {
        throw new Exception("Error creating view 'pending_complaints': " . $db->error);
    }
    echo "View 'pending_complaints' created successfully!<br>";

    // View for resolved complaints
    $create_view_resolved_complaints = "
        CREATE VIEW resolved_complaints AS
        SELECT c.id, c.title, c.resolution_details, c.resolution_date, u.fname, u.lname, c.created_at
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.status = 'resolved';
    ";
    if (!$db->query($create_view_resolved_complaints)) {
        throw new Exception("Error creating view 'resolved_complaints': " . $db->error);
    }
    echo "View 'resolved_complaints' created successfully!<br>";

    // Commit transaction
    $db->commit();
    echo "Database setup completed successfully!<br>";
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollback();
    echo "Error: " . $e->getMessage() . "<br>";
    error_log("Error: " . $e->getMessage() . "\n", 3, $log_file);
} finally {
    // Close the database connection
    $db->close();
    echo "Database connection closed.<br>";
}
?>