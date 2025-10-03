<?php
session_start();
require_once 'db_connect.php';

// Authentication and Role Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], [
    'handler', 'department_head', 'college_dean', 'academic_vp', 'president',
    'university_registrar', 'campus_registrar', 'sims', 'cost_sharing',
    'student_service_directorate', 'dormitory_service', 'students_food_service',
    'library_service', 'hrm', 'finance', 'general_service'
])) {
    header("Location: login.php");
    exit();
}

$committee_id = isset($_GET['committee_id']) ? (int)$_GET['committee_id'] : 0;
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Verify user is part of the committee
$stmt_check = $db->prepare("SELECT 1 FROM committee_members WHERE committee_id = ? AND user_id = ?");
$stmt_check->bind_param("ii", $committee_id, $user_id);
$stmt_check->execute();
$result_check = $stmt_check->get_result();
if ($result_check->num_rows === 0) {
    $_SESSION['error_message'] = "Access denied: You are not a member of this committee.";
    header("Location: dashboard.php");
    exit();
}
$stmt_check->close();

// Fetch committee details
$stmt_committee = $db->prepare("SELECT c.id, c.complaint_id, comp.title, comp.description, comp.visibility, comp.status, comp.user_id as complainant_id
                                FROM committees c
                                LEFT JOIN complaints comp ON c.complaint_id = comp.id
                                WHERE c.id = ?");
if (!$stmt_committee) {
    $_SESSION['error_message'] = "Error preparing committee query: " . $db->error;
    header("Location: dashboard.php");
    exit();
}
$stmt_committee->bind_param("i", $committee_id);
$stmt_committee->execute();
$committee_result = $stmt_committee->get_result();
if ($committee_result->num_rows === 0) {
    $_SESSION['error_message'] = "Error: Committee not found.";
    header("Location: dashboard.php");
    exit();
}
$committee = $committee_result->fetch_assoc();
$complainant_id = $committee['complainant_id'];
$stmt_committee->close();

// Fetch committee members
$stmt_members = $db->prepare("SELECT cm.user_id, u.role FROM committee_members cm JOIN users u ON cm.user_id = u.id WHERE cm.committee_id = ?");
$stmt_members->bind_param("i", $committee_id);
$stmt_members->execute();
$members_result = $stmt_members->get_result();
$committee_members = [];
$total_members = 0;
while ($row = $members_result->fetch_assoc()) {
    $committee_members[$row['user_id']] = $row['role'];
    $total_members++;
}
$stmt_members->close();

// Check if a decision has been proposed and fetch agreements
$proposed_decision = null;
$agreements = [];
$all_agreed = false;
$stmt_decision = $db->prepare("SELECT cd.id, cd.decision_text, cd.proposed_by, cd.created_at
                               FROM committee_decisions cd
                               WHERE cd.committee_id = ? AND cd.status = 'pending'
                               ORDER BY cd.created_at DESC LIMIT 1");
$stmt_decision->bind_param("i", $committee_id);
$stmt_decision->execute();
$decision_result = $stmt_decision->get_result();
if ($decision_result->num_rows > 0) {
    $proposed_decision = $decision_result->fetch_assoc();
    // Fetch agreements
    $stmt_agree = $db->prepare("SELECT user_id FROM decision_agreements WHERE committee_decision_id = ?");
    $stmt_agree->bind_param("i", $proposed_decision['id']);
    $stmt_agree->execute();
    $agree_result = $stmt_agree->get_result();
    while ($row = $agree_result->fetch_assoc()) {
        $agreements[] = $row['user_id'];
    }
    $stmt_agree->close();
    // Check if all members have agreed
    $all_agreed = count($agreements) === $total_members;
}
$stmt_decision->close();

// Handle decision proposal (handler only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['propose_decision']) && $user_role === 'handler') {
    $decision_text = trim($_POST['decision_text']);
    if ($decision_text && !$proposed_decision) {
        $db->begin_transaction();
        try {
            $stmt_insert = $db->prepare("INSERT INTO committee_decisions (committee_id, decision_text, proposed_by, status) VALUES (?, ?, ?, 'pending')");
            $stmt_insert->bind_param("isi", $committee_id, $decision_text, $user_id);
            $stmt_insert->execute();
            $stmt_insert->close();

            // Notify committee members via system message in chat
            $system_message = "Handler has proposed a decision: \"$decision_text\". Please review and agree.";
            $stmt_chat = $db->prepare("INSERT INTO committee_messages (committee_id, sender_id, message_text, message_type) VALUES (?, ?, ?, 'system')");
            $stmt_chat->bind_param("iis", $committee_id, $user_id, $system_message);
            $stmt_chat->execute();
            $stmt_chat->close();

            $db->commit();
            header("Location: chat.php?committee_id=$committee_id");
            exit();
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error_message'] = "Error proposing decision: " . $e->getMessage();
        }
    }
}

// Handle agreement submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agree_decision']) && $proposed_decision) {
    if (!in_array($user_id, $agreements)) {
        $db->begin_transaction();
        try {
            $stmt_agree = $db->prepare("INSERT INTO decision_agreements (committee_decision_id, user_id) VALUES (?, ?)");
            $stmt_agree->bind_param("ii", $proposed_decision['id'], $user_id);
            $stmt_agree->execute();
            $stmt_agree->close();

            // Add system message to chat
            $system_message = "User ID $user_id has agreed to the proposed decision.";
            $stmt_chat = $db->prepare("INSERT INTO committee_messages (committee_id, sender_id, message_text, message_type) VALUES (?, ?, ?, 'system')");
            $stmt_chat->bind_param("iis", $committee_id, $user_id, $system_message);
            $stmt_chat->execute();
            $stmt_chat->close();

            $db->commit();
            header("Location: chat.php?committee_id=$committee_id");
            exit();
        } catch (Exception $e) {
            $db->rollback();
            $_SESSION['error_message'] = "Error recording agreement: " . $e->getMessage();
        }
    }
}

// Handle sending the decision to the user (handler only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_decision']) && $user_role === 'handler' && $all_agreed) {
    $db->begin_transaction();
    try {
        // Insert decision into decisions table
        $receiver_role = $committee_members[$complainant_id] ?? 'user';
        $stmt_decision = $db->prepare("INSERT INTO decisions (complaint_id, decision_text, sender_id, receiver_id, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt_decision->bind_param("isii", $committee['complaint_id'], $proposed_decision['decision_text'], $user_id, $complainant_id);
        $stmt_decision->execute();
        $stmt_decision->close();

        // Update committee_decisions status
        $stmt_update = $db->prepare("UPDATE committee_decisions SET status = 'sent' WHERE id = ?");
        $stmt_update->bind_param("i", $proposed_decision['id']);
        $stmt_update->execute();
        $stmt_update->close();

        // Notify the user
        $notification_desc = "A decision has been made on your complaint (#{$committee['complaint_id']}): \"{$proposed_decision['decision_text']}\"";
        $stmt_notify = $db->prepare("INSERT INTO notifications (user_id, complaint_id, description) VALUES (?, ?, ?)");
        $stmt_notify->bind_param("iis", $complainant_id, $committee['complaint_id'], $notification_desc);
        $stmt_notify->execute();
        $stmt_notify->close();

        // Add system message to chat
        $system_message = "Decision has been sent to the user: \"{$proposed_decision['decision_text']}\"";
        $stmt_chat = $db->prepare("INSERT INTO committee_messages (committee_id, sender_id, message_text, message_type) VALUES (?, ?, ?, 'system')");
        $stmt_chat->bind_param("iis", $committee_id, $user_id, $system_message);
        $stmt_chat->execute();
        $stmt_chat->close();

        $db->commit();
        header("Location: chat.php?committee_id=$committee_id");
        exit();
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error_message'] = "Error sending decision: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Committee Chat - Complaint #<?php echo htmlspecialchars($committee['complaint_id'] ?? 'N/A'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f4f7f6;
            color: #333;
            line-height: 1.6;
            padding-top: 20px;
        }

        .chat-container {
            max-width: 850px;
            margin: 20px auto;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: calc(100vh - 80px);
            max-height: 700px;
        }

        .chat-header {
            background-color: #4a90e2;
            color: white;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            text-align: center;
        }

        .chat-header h2 {
            margin: 0 0 8px;
            font-size: 1.3em;
            font-weight: 500;
        }

        .chat-header small {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .complaint-description {
            margin-top: 10px;
            padding: 10px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 5px;
            font-size: 0.95em;
            line-height: 1.5;
            text-align: left;
            max-height: 100px;
            overflow-y: auto;
        }

        .complaint-description p {
            margin: 0;
            white-space: pre-wrap;
        }

        .decision-section {
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            background-color: #f9f9f9;
        }

        .decision-section h3 {
            font-size: 1.1em;
            margin-bottom: 10px;
            color: #4a90e2;
        }

        .decision-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            resize: vertical;
            font-size: 0.95em;
        }

        .decision-form button, .agree-form button, .send-decision-form button {
            background-color: #4a90e2;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.95em;
            margin-top: 5px;
        }

        .decision-form button:hover, .agree-form button:hover, .send-decision-form button:hover {
            background-color: #357abd;
        }

        .decision-status {
            margin-top: 10px;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
        }

        .decision-status p {
            margin: 0;
            font-size: 0.95em;
        }

        #chat-box {
            flex-grow: 1;
            overflow-y: auto;
            padding: 20px;
            background-color: #f9f9f9;
            display: flex;
            flex-direction: column;
        }

        #chat-box::-webkit-scrollbar {
            width: 8px;
        }

        #chat-box::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        #chat-box::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 10px;
        }

        #chat-box::-webkit-scrollbar-thumb:hover {
            background: #aaa;
        }

        .message {
            padding: 10px 15px;
            margin-bottom: 12px;
            border-radius: 18px;
            max-width: 75%;
            word-wrap: break-word;
            position: relative;
            clear: both;
        }

        .message p {
            margin: 0 0 3px 0;
            font-size: 0.95em;
        }

        .message .sender-info {
            font-size: 0.8em;
            color: #555;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .message .timestamp {
            font-size: 0.75em;
            color: #888;
            margin-top: 5px;
            display: block;
            text-align: inherit;
        }

        .my-message {
            background-color: #d1e7ff;
            color: #333;
            margin-left: auto;
            border-bottom-right-radius: 5px;
            text-align: left;
        }

        .my-message .timestamp {
            text-align: right;
        }

        .other-message {
            background-color: #e9ecef;
            color: #333;
            margin-right: auto;
            border-bottom-left-radius: 5px;
            text-align: left;
        }

        .other-message .timestamp {
            text-align: left;
        }

        .system-message {
            background-color: #f0f0f0;
            color: #555;
            margin: 10px auto;
            max-width: 85%;
            text-align: center;
            border-radius: 10px;
            font-style: italic;
        }

        .system-message .sender-info {
            color: #777;
            font-style: normal;
        }

        .system-message .timestamp {
            text-align: center;
        }

        #chat-form {
            display: flex;
            padding: 15px 20px;
            border-top: 1px solid #ddd;
            background-color: #fdfdfd;
        }

        textarea#message {
            flex-grow: 1;
            resize: none;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: 20px;
            font-size: 1em;
            margin-right: 10px;
            min-height: 48px;
            max-height: 120px;
            overflow-y: auto;
            transition: border-color 0.2s ease;
            white-space: pre-wrap;
        }

        textarea#message:focus {
            outline: none;
            border-color: #4a90e2;
            box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.2);
        }

        button[type="submit"] {
            background-color: #4a90e2;
            color: white;
            padding: 0 25px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 500;
            transition: background-color 0.2s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }

        button[type="submit"]:hover {
            background-color: #357abd;
        }

        button[type="submit"]:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }

        #chat-box .placeholder {
            text-align: center;
            color: #888;
            margin: auto;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <h2>Committee Chat: <?php echo htmlspecialchars($committee['title'] ?? 'Complaint Discussion'); ?></h2>
            <small>(Complaint #<?php echo htmlspecialchars($committee['complaint_id'] ?? 'N/A'); ?>)</small>
            <?php if (!empty($committee['description'])): ?>
                <div class="complaint-description">
                    <p><?php echo htmlspecialchars($committee['description']); ?></p>
                </div>
            <?php else: ?>
                <div class="complaint-description">
                    <p>No description available.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Decision Section -->
        <div class="decision-section">
            <h3>Decision Proposal</h3>
            <?php if (isset($_SESSION['error_message'])): ?>
                <p style="color: red;"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></p>
            <?php endif; ?>

            <?php if ($proposed_decision): ?>
                <div class="decision-status">
                    <p><strong>Proposed Decision:</strong> <?php echo htmlspecialchars($proposed_decision['decision_text']); ?></p>
                    <p><strong>Proposed by:</strong> Handler (User ID: <?php echo $proposed_decision['proposed_by']; ?>) on <?php echo date('M j, Y H:i', strtotime($proposed_decision['created_at'])); ?></p>
                    <p><strong>Agreements:</strong> <?php echo count($agreements); ?> / <?php echo $total_members; ?> members</p>
                    <?php if (!in_array($user_id, $agreements)): ?>
                        <form class="agree-form" method="POST">
                            <input type="hidden" name="agree_decision" value="1">
                            <button type="submit">Agree to Decision</button>
                        </form>
                    <?php else: ?>
                        <p>You have agreed to this decision.</p>
                    <?php endif; ?>
                    <?php if ($all_agreed && $user_role === 'handler'): ?>
                        <form class="send-decision-form" method="POST">
                            <input type="hidden" name="send_decision" value="1">
                            <button type="submit">Send Decision to User</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php elseif ($user_role === 'handler'): ?>
                <form class="decision-form" method="POST">
                    <textarea name="decision_text" required placeholder="Write the decision sentence..."></textarea>
                    <input type="hidden" name="propose_decision" value="1">
                    <button type="submit">Propose Decision</button>
                </form>
            <?php else: ?>
                <p>Waiting for the handler to propose a decision.</p>
            <?php endif; ?>
        </div>

        <div id="chat-box">
            <p class="placeholder">Loading messages...</p>
        </div>

        <form id="chat-form">
            <input type="hidden" name="committee_id" value="<?php echo $committee_id; ?>">
            <textarea name="message" id="message" rows="1" required placeholder="Type your message..."></textarea>
            <button type="submit" id="send-button">Send</button>
        </form>
    </div>

    <script>
        const chatBox = document.getElementById('chat-box');
        const chatForm = document.getElementById('chat-form');
        const messageInput = document.getElementById('message');
        const sendButton = document.getElementById('send-button');
        const committeeId = <?php echo $committee_id; ?>;
        const currentUserId = <?php echo $user_id; ?>;
        let messagePollingInterval;

        function displayMessages(messagesHtml) {
            console.log('Received response:', messagesHtml); // Debug the response
            if (messagesHtml && typeof messagesHtml === 'string' && messagesHtml.trim().startsWith('<')) {
                chatBox.innerHTML = messagesHtml;
            } else if (messagesHtml.includes('No messages yet')) {
                chatBox.innerHTML = `<p class="placeholder">No messages yet. Start the conversation!</p>`;
            } else {
                console.error("Invalid response format from fetch_messages.php:", messagesHtml);
                chatBox.innerHTML = '<p class="placeholder">Error loading messages or invalid format.</p>';
            }
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        function loadMessages() {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `fetch_messages.php?committee_id=${committeeId}&t=${Date.now()}`, true);
            xhr.setRequestHeader('Accept', 'text/html'); // Explicitly request HTML
            xhr.onload = function() {
                if (xhr.status === 200) {
                    displayMessages(xhr.responseText);
                } else {
                    console.error("Error loading messages. Status:", xhr.status, "Response:", xhr.responseText);
                    chatBox.innerHTML = '<p class="placeholder">Error loading messages: ' + xhr.status + '</p>';
                }
            };
            xhr.onerror = function() {
                console.error("Network error while loading messages.");
                chatBox.innerHTML = '<p class="placeholder">Network error. Could not load messages.</p>';
            };
            xhr.send();
        }

        function sendMessage(event) {
            event.preventDefault();
            const message = messageInput.value.trim();
            if (!message) {
                messageInput.style.borderColor = 'red';
                setTimeout(() => { messageInput.style.borderColor = '#ccc'; }, 2000);
                return;
            }

            sendButton.disabled = true;
            sendButton.textContent = 'Sending...';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'send_message.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                    loadMessages();
                    console.log('Send message response:', xhr.responseText); // Debug the response
                } else {
                    console.error("Error sending message. Status:", xhr.status, "Response:", xhr.responseText);
                    alert('Error sending message. Please try again.');
                }
                sendButton.disabled = false;
                sendButton.textContent = 'Send';
            };
            xhr.onerror = function() {
                console.error("Network error while sending message.");
                alert('Network error. Could not send message.');
                sendButton.disabled = false;
                sendButton.textContent = 'Send';
            };
            const formData = `committee_id=${encodeURIComponent(committeeId)}&message=${encodeURIComponent(message)}`;
            xhr.send(formData);
        }

        chatForm.addEventListener('submit', sendMessage);

        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        messageInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendButton.click();
            }
        });

        loadMessages();
        messagePollingInterval = setInterval(loadMessages, 5000);

        window.addEventListener('beforeunload', () => {
            clearInterval(messagePollingInterval);
        });
    </script>
</body>
</html>
<?php
if ($db && !$db->connect_error) {
    $db->close();
}
?>