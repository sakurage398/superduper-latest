<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection and audit functions
require_once 'db_connection.php';
require_once 'audit_functions.php';

// Include PHPMailer manually (Option B)
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set content type header FIRST
header('Content-Type: application/json');

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // Buffer output to prevent any unwanted output
    ob_start();
    
    try {
        switch ($action) {
            case 'add':
                addUser($conn);
                break;
            case 'edit':
                editUser($conn);
                break;
            case 'delete':
                deleteUser($conn);
                break;
            case 'getUsers':
                getUsers($conn);
                break;
            case 'getUser':
                getUser($conn);
                break;
            case 'getUsersForAudit':
                getUsersForAudit($conn);
                break;
            case 'getUserAuditLogs':
                getUserAuditLogs($conn);
                break;
            default:
                echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        // Clear any output buffer
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
    }

    // End buffering and send output
    ob_end_flush();
    
    // Close connection
    $conn->close();
    exit();
}

/**
 * Custom function to hash password and limit to 8 characters
 */
function customHash($password) {
    return substr(md5($password), 0, 8);
}

/**
 * Send email with user credentials using PHPMailer
 */
function sendUserCredentials($email, $name, $username, $password, $isNewUser = true) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings for Gmail
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'alamslibrary101425@gmail.com'; // Your Gmail address
        $mail->Password = 'sipqrmnqlieeqmsu'; // Your Gmail app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('noreply@alams.com', 'ALAMS System');
        $mail->addAddress($email, $name);
        $mail->addReplyTo('noreply@alams.com', 'ALAMS System');

        // Content
        $mail->isHTML(true);
        $mail->Subject = $isNewUser ? "Your ALAMS Account Credentials" : "Your ALAMS Account Has Been Updated";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
                .header { background: #8869BB; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 25px; background: #f9f9f9; }
                .credentials { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8869BB; }
                .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; border-top: 1px solid #ddd; margin-top: 20px; }
                .info-box { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>ALAMS System</h1>
                    <p>Automated Library Attendance Monitoring System</p>
                </div>
                <div class='content'>
                    <h2>Hello " . htmlspecialchars($name) . "!</h2>
                    
                    <p>" . ($isNewUser ? 
                        "Your administrator account has been created successfully." : 
                        "Your administrator account information has been updated.") . "</p>
                    
                    <div class='credentials'>
                        <h3>Your Login Credentials</h3>
                        <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                        <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                        <p><strong>Login URL:</strong> <a href='" . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . '/login.html' : '#') . "'>Access System</a></p>
                    </div>
                    
                    <div class='info-box'>
                        <h4>Important Security Information</h4>
                        <ul>
                            <li>Keep your credentials secure and do not share them with anyone</li>
                            <li>You will be required to enter a PIN code sent to your email when logging in</li>
                            <li>The PIN code expires after 2 minutes for security reasons</li>
                            <li>You can request a new PIN code using the 'Resend' button if needed</li>
                        </ul>
                    </div>
                    
                    <p>If you did not request this account or believe this is an error, please contact your system administrator immediately.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " ALAMS. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $message;
        
        // Alternative plain text version for non-HTML email clients
        $mail->AltBody = "Hello $name!\n\n" .
            ($isNewUser ? 
                "Your administrator account has been created successfully." : 
                "Your administrator account information has been updated.") .
            "\n\nYour Login Credentials:\n" .
            "Username: $username\n" .
            "Password: $password\n" .
            "Login URL: " . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . '/login.html' : 'Your system login page') .
            "\n\nImportant: Keep your credentials secure. You will receive a PIN code via email when logging in.\n\n" .
            "This is an automated message. Please do not reply.";

        $mail->send();
        return "Credentials sent successfully to " . $email;
        
    } catch (Exception $e) {
        return "Email could not be sent. Error: " . $mail->ErrorInfo;
    }
}

/**
 * Add a new user to the database
 */
function addUser($conn) {
    // Validate input
    if (empty($_POST['name']) || empty($_POST['role']) || empty($_POST['username']) || empty($_POST['password']) || empty($_POST['email'])) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
        return;
    }

    $name = $conn->real_escape_string($_POST['name']);
    $role = 'Admin'; // Force Admin role only
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = $conn->real_escape_string($_POST['password']);
    $sendEmail = isset($_POST['send_email']) && $_POST['send_email'] == 'true';
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
        return;
    }
    
    // Check if username already exists
    $checkQuery = "SELECT id FROM users WHERE username = '$username'";
    $result = $conn->query($checkQuery);
    
    if ($result === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database query error: ' . $conn->error]);
        return;
    }
    
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
        return;
    }
    
    // Check if email already exists
    $checkEmailQuery = "SELECT id FROM users WHERE email = '$email'";
    $emailResult = $conn->query($checkEmailQuery);
    
    if ($emailResult->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
        return;
    }
    
    // Hash password with the custom 8-character hash
    $hashedPassword = customHash($password);
    
    // Get current user info for audit trail
    $currentUser = getCurrentUserInfo();
    
    // Insert new user
    $query = "INSERT INTO users (name, role, username, email, password) VALUES ('$name', '$role', '$username', '$email', '$hashedPassword')";
    
    if ($conn->query($query) === TRUE) {
        $userId = $conn->insert_id;
        
        // Log the action in audit trail
        logAuditTrail(
            $conn,
            $currentUser['id'],
            $currentUser['username'],
            'USER_CREATE',
            "Created new admin user: $name ($username)",
            'users',
            $userId,
            null,
            ['name' => $name, 'role' => $role, 'username' => $username, 'email' => $email]
        );
        
        // Send email with credentials if requested
        $emailStatus = '';
        if ($sendEmail) {
            $emailStatus = sendUserCredentials($email, $name, $username, $password, true);
        }
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'User added successfully' . ($sendEmail ? ' and email sent' : ''),
            'email_status' => $emailStatus,
            'user' => [
                'id' => $userId,
                'name' => $name,
                'role' => $role,
                'username' => $username,
                'email' => $email
            ]
        ]);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'Error adding user: ' . $conn->error]);
    }
}

/**
 * Edit an existing user
 */
function editUser($conn) {
    // Validate input
    if (empty($_POST['id']) || empty($_POST['name']) || empty($_POST['username']) || empty($_POST['email'])) {
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        return;
    }
    
    $id = (int)$_POST['id'];
    $name = $conn->real_escape_string($_POST['name']);
    $role = 'Admin'; // Force Admin role only
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = isset($_POST['password']) ? $conn->real_escape_string($_POST['password']) : '';
    $sendEmail = isset($_POST['send_email']) && $_POST['send_email'] == 'true';
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
        return;
    }
    
    // Get current user data for audit trail
    $oldDataQuery = "SELECT name, role, username, email FROM users WHERE id = $id";
    $oldDataResult = $conn->query($oldDataQuery);
    $oldData = $oldDataResult->fetch_assoc();
    
    // Check if username already exists and is not the current user
    $checkQuery = "SELECT id FROM users WHERE username = '$username' AND id != $id";
    $result = $conn->query($checkQuery);
    
    if ($result === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database query error: ' . $conn->error]);
        return;
    }
    
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
        return;
    }
    
    // Check if email already exists and is not the current user
    $checkEmailQuery = "SELECT id FROM users WHERE email = '$email' AND id != $id";
    $emailResult = $conn->query($checkEmailQuery);
    
    if ($emailResult->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
        return;
    }
    
    // Update user
    $queryParts = ["name = '$name'", "role = '$role'", "username = '$username'", "email = '$email'"];
    
    // Only update password if a new one was provided
    if (!empty($password)) {
        $hashedPassword = customHash($password);
        $queryParts[] = "password = '$hashedPassword'";
    }
    
    $updateClause = implode(", ", $queryParts);
    $query = "UPDATE users SET $updateClause WHERE id = $id";
    
    if ($conn->query($query) === TRUE) {
        // Get current user info for audit trail
        $currentUser = getCurrentUserInfo();
        
        // Prepare new values for audit trail
        $newValues = ['name' => $name, 'role' => $role, 'username' => $username, 'email' => $email];
        if (!empty($password)) {
            $newValues['password'] = '***'; // Don't log actual password
        }
        
        // Log the action in audit trail
        logAuditTrail(
            $conn,
            $currentUser['id'],
            $currentUser['username'],
            'USER_UPDATE',
            "Updated admin user: $name ($username)",
            'users',
            $id,
            $oldData,
            $newValues
        );
        
        // Send email with updated credentials if requested and password was changed
        $emailStatus = '';
        if ($sendEmail && !empty($password)) {
            $emailStatus = sendUserCredentials($email, $name, $username, $password, false);
        }
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'User updated successfully' . ($sendEmail && !empty($password) ? ' and email sent' : ''),
            'email_status' => $emailStatus,
            'user' => [
                'id' => $id,
                'name' => $name,
                'role' => $role,
                'username' => $username,
                'email' => $email
            ]
        ]);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'Error updating user: ' . $conn->error]);
    }
}

/**
 * Delete a user
 */
function deleteUser($conn) {
    // Validate input
    if (empty($_POST['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
        return;
    }
    
    $id = (int)$_POST['id'];
    
    // Check if user exists
    $checkQuery = "SELECT id, name, username, email FROM users WHERE id = $id";
    $result = $conn->query($checkQuery);
    
    if ($result === false) {
        echo json_encode(['status' => 'error', 'message' => 'Database query error: ' . $conn->error]);
        return;
    }
    
    if ($result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        return;
    }
    
    $userData = $result->fetch_assoc();
    
    // Prevent deletion of the current user
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $id) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete your own account']);
        return;
    }
    
    // Get current user info for audit trail
    $currentUser = getCurrentUserInfo();
    
    // Delete user
    $query = "DELETE FROM users WHERE id = $id";
    
    if ($conn->query($query) === TRUE) {
        // Log the action in audit trail
        logAuditTrail(
            $conn,
            $currentUser['id'],
            $currentUser['username'],
            'USER_DELETE',
            "Deleted admin user: {$userData['name']} ({$userData['username']})",
            'users',
            $id,
            $userData,
            null
        );
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'Error deleting user: ' . $conn->error]);
    }
}

/**
 * Get user details
 */
function getUser($conn) {
    if (empty($_POST['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
        return;
    }
    
    $id = (int)$_POST['id'];
    $query = "SELECT id, name, role, username, email, created_at FROM users WHERE id = $id";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode(['status' => 'success', 'user' => $user]);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }
}

/**
 * Get all users based on role filter
 */
function getUsers($conn) {
    $role = 'Admin'; // Only get Admin users
    $searchTerm = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
    
    $whereClause = ["role = 'Admin'"]; // Only Admin users
    
    if (!empty($searchTerm)) {
        $whereClause[] = "(name LIKE '%$searchTerm%' OR username LIKE '%$searchTerm%' OR email LIKE '%$searchTerm%')";
    }
    
    $whereStatement = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    $query = "SELECT id, name, role, username, email, created_at FROM users $whereStatement ORDER BY name";
    $result = $conn->query($query);
    
    if ($result) {
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'role' => $row['role'],
                'username' => $row['username'],
                'email' => $row['email'],
                'created_at' => $row['created_at']
            ];
        }
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode(['status' => 'success', 'users' => $users]);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'Error fetching users: ' . $conn->error]);
    }
}

/**
 * Get all users for audit trail view
 */
function getUsersForAudit($conn) {
    $searchTerm = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
    
    $whereClause = ["role = 'Admin'"];
    
    if (!empty($searchTerm)) {
        $whereClause[] = "(name LIKE '%$searchTerm%' OR username LIKE '%$searchTerm%' OR email LIKE '%$searchTerm%')";
    }
    
    $whereStatement = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    $query = "SELECT id, name, role, username, email FROM users $whereStatement ORDER BY name";
    $result = $conn->query($query);
    
    if ($result) {
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'role' => $row['role'],
                'username' => $row['username'],
                'email' => $row['email']
            ];
        }
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode(['status' => 'success', 'users' => $users]);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'Error fetching users: ' . $conn->error]);
    }
}

/**
 * Get audit logs for specific user
 */
function getUserAuditLogs($conn) {
    if (empty($_POST['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
        return;
    }
    
    $userId = (int)$_POST['user_id'];
    $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
    $offset = ($page - 1) * $limit;
    
    $search = isset($_POST['search']) ? $conn->real_escape_string($_POST['search']) : '';
    $actionFilter = isset($_POST['action_filter']) ? $conn->real_escape_string($_POST['action_filter']) : '';
    
    $whereClause = ["user_id = $userId"];
    
    if (!empty($search)) {
        $whereClause[] = "(description LIKE '%$search%')";
    }
    
    if (!empty($actionFilter)) {
        $whereClause[] = "action = '$actionFilter'";
    }
    
    $whereStatement = !empty($whereClause) ? "WHERE " . implode(" AND ", $whereClause) : "";
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM audit_trail $whereStatement";
    $countResult = $conn->query($countQuery);
    $totalRows = $countResult->fetch_assoc()['total'];
    
    // Get logs
    $query = "SELECT * FROM audit_trail $whereStatement ORDER BY timestamp DESC LIMIT $limit OFFSET $offset";
    $result = $conn->query($query);
    
    if ($result) {
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'action' => $row['action'],
                'description' => $row['description'],
                'table_name' => $row['table_name'],
                'record_id' => $row['record_id'],
                'old_values' => $row['old_values'] ? json_decode($row['old_values'], true) : null,
                'new_values' => $row['new_values'] ? json_decode($row['new_values'], true) : null,
                'ip_address' => $row['ip_address'],
                'timestamp' => $row['timestamp']
            ];
        }
        
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode([
            'status' => 'success', 
            'logs' => $logs,
            'total' => $totalRows,
            'page' => $page,
            'total_pages' => ceil($totalRows / $limit)
        ]);
    } else {
        // Clean any output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['status' => 'error', 'message' => 'Error fetching user audit logs: ' . $conn->error]);
    }
}
?>