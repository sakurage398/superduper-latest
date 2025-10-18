<?php
session_start();
require 'db_connection.php';

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header("Content-Type: application/json");

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$pincode = trim($_POST['pincode'] ?? '');
$verify_pin = isset($_POST['verify_pin']);
$resend_pin = isset($_POST['resend_pin']);

if ($resend_pin && !empty($username)) {
    resendPincode($username, $conn);
    exit();
}

if ($verify_pin && !empty($username) && !empty($pincode)) {
    verifyPincode($username, $pincode, $conn);
    exit();
}

if (empty($username) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Missing username or password."]);
    exit();
}

processLogin($username, $password, $conn);

function processLogin($username, $password, $conn) {
    $stmt = $conn->prepare("SELECT id, name, role, password, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        $hashedPassword = customHash($password);
        if ($hashedPassword === $user['password']) {
            $pin = generatePincode();
            $expiry_time = time() + 300; // 5 minutes
            
            $_SESSION['login_pin'] = $pin;
            $_SESSION['login_pin_expiry'] = $expiry_time;
            $_SESSION['pending_user'] = $user;
            $_SESSION['pending_username'] = $username;
            
            $emailSent = sendPincodeEmail($user['email'], $user['name'], $pin);
            
            echo json_encode([
                "status" => "pincode_required",
                "email" => $user['email'],
                "expiry_time" => $expiry_time * 1000, // Convert to milliseconds for JavaScript
                "message" => $emailSent ? "PIN sent to your email" : "PIN generated but email failed"
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Incorrect password."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "User account is invalid. This account does not exist."]);
    }
    $stmt->close();
}

function verifyPincode($username, $pincode, $conn) {
    if (!isset($_SESSION['login_pin']) || !isset($_SESSION['login_pin_expiry']) || !isset($_SESSION['pending_user'])) {
        echo json_encode(["status" => "error", "message" => "No pending login session. Please login again."]);
        exit();
    }
    
    if (time() > $_SESSION['login_pin_expiry']) {
        unset($_SESSION['login_pin']);
        unset($_SESSION['login_pin_expiry']);
        unset($_SESSION['pending_user']);
        
        echo json_encode(["status" => "error", "message" => "PIN has expired. Please request a new one."]);
        exit();
    }
    
    if ($pincode === $_SESSION['login_pin']) {
        $user = $_SESSION['pending_user'];
        
       $insertLogin = $conn->prepare("INSERT INTO login_logs (user_id, login_time) VALUES (?, DATE_FORMAT(NOW(), '%Y-%m-%d %h:%i:%s %p'))");
        $insertLogin->bind_param("i", $user['id']);
        $insertLogin->execute();
        $login_log_id = $conn->insert_id;
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        $_SESSION['user_type'] = $user['role'];
        $_SESSION['login_log_id'] = $login_log_id;
        $_SESSION['name'] = $user['name'];
        
        unset($_SESSION['login_pin']);
        unset($_SESSION['login_pin_expiry']);
        unset($_SESSION['pending_user']);
        unset($_SESSION['pending_username']);
        
        $redirect = "";
        if ($user['role'] === 'Admin') {
            $redirect = "admin-dashboard.html";
        } else if ($user['role'] === 'Super Admin') {
            $redirect = "superadmin-user.html";
        }
        
        echo json_encode([
            "status" => "success", 
            "redirect" => $redirect,
            "login_log_id" => $login_log_id
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid PIN code. Please try again."]);
    }
}

function resendPincode($username, $conn) {
    $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        $pin = generatePincode();
        $expiry_time = time() + 300; // 5 minutes
        
        $_SESSION['login_pin'] = $pin;
        $_SESSION['login_pin_expiry'] = $expiry_time;
        
        $emailSent = sendPincodeEmail($user['email'], $user['name'], $pin);
        
        echo json_encode([
            "status" => "success",
            "expiry_time" => $expiry_time * 1000, // Convert to milliseconds for JavaScript
            "message" => $emailSent ? "New PIN sent to your email" : "New PIN generated but email failed"
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "User not found."]);
    }
    $stmt->close();
}

function generatePincode() {
    return sprintf("%06d", mt_rand(1, 999999));
}

function sendPincodeEmail($email, $name, $pincode) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'alamslibrary101425@gmail.com';
        $mail->Password = 'sipqrmnqlieeqmsu';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('noreply@alams.com', 'ALAMS System');
        $mail->addAddress($email, $name);
        $mail->addReplyTo('noreply@alams.com', 'ALAMS System');

        $mail->isHTML(true);
        $mail->Subject = "Your ALAMS Login PIN Code";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px; }
                .header { background: #8869BB; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 25px; background: #f9f9f9; }
                .pin-code { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #8869BB; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 10px; color: #8869BB; }
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
                    
                    <p>Your login verification PIN code is:</p>
                    
                    <div class='pin-code'>" . $pincode . "</div>
                    
                    <div class='info-box'>
                        <h4>Important Security Information</h4>
                        <ul>
                            <li>This PIN code will expire in <strong>5 minutes</strong></li>
                            <li>Do not share this PIN with anyone</li>
                            <li>If you didn't request this PIN, please ignore this email</li>
                            <li>You can request a new PIN using the 'Resend' button</li>
                        </ul>
                    </div>
                    
                    <p>Enter this PIN code in the login window to complete your authentication.</p>
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
        $mail->AltBody = "Hello $name!\n\nYour ALAMS login PIN code is: $pincode\n\nThis PIN will expire in 5 minutes.\n\nDo not share this PIN with anyone.\n\nThis is an automated message.";

        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return false;
    }
}

function customHash($password) {
    return substr(md5($password), 0, 8);
}
?>