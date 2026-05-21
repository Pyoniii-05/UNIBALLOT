<?php
// security_manager.php

// (Keep your PHPMailer requires here...)
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function register_new_login($conn, $stu_no, $email) {
    // 1. Get current DB data
    $stmt = $conn->prepare("SELECT active_session_id, device_token FROM voters WHERE stu_no = ?");
    if (!$stmt) return false;
    
    $stmt->bind_param("s", $stu_no);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) return false; 

    // 2. CHECK FOR TRUSTED DEVICE COOKIE
    // Does the user have a cookie? And does it match the DB?
    if (isset($_COOKIE['uspelect_device']) && $_COOKIE['uspelect_device'] === $row['device_token']) {
        // ✅ TRUSTED DEVICE! 
        // Update session ID silently and skip verification
        session_regenerate_id(true);
        $new_sid = session_id();
        
        $update = $conn->prepare("UPDATE voters SET active_session_id = ? WHERE stu_no = ?");
        $update->bind_param("ss", $new_sid, $stu_no);
        $update->execute();
        
        return false; // FALSE means "No verification needed"
    }

    // 3. IF NOT TRUSTED, START VERIFICATION
    $old_sid = $row['active_session_id'] ?? '';
    session_regenerate_id(true); 
    $new_sid = session_id();

    // Generate Tokens
    $token = bin2hex(random_bytes(16)); 
    
    // Send Email
    _send_security_alert($email, $stu_no, $token);

    // Set Status to PENDING
    $update = $conn->prepare("UPDATE voters SET login_token = ?, verify_status = 'PENDING' WHERE stu_no = ?");
    $update->bind_param("ss", $token, $stu_no);
    $update->execute();

    return true; // TRUE means "Stop! Go to Waiting Room"


    // NORMAL LOGIN
    $update = $conn->prepare("UPDATE voters SET active_session_id = ? WHERE stu_no = ?");
    $update->bind_param("ss", $new_sid, $stu_no);
    $update->execute();
    return false; 
}

function _send_security_alert($to_email, $stu_no, $token) {
    // AUTOMATIC IP DETECTION
    $host_name = gethostname(); 
    $my_ip = gethostbyname($host_name); 
    $folder_name = "UNIBALLOT%20SYSTEM"; // Change if needed
    $base_url = "http://" . $my_ip . "/" . $folder_name;

    // POINT TO APPROVE_LOGIN.PHP
    $verify_link = $base_url . "/approve_login.php?t=" . $token . "&u=" . $stu_no;

    $mail = new PHPMailer(true);
    // (Keep your existing SMTP settings here...)
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'paulemberga001@gmail.com'; 
        $mail->Password   = 'jfza lwhv lkym fpkl'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;

        $mail->setFrom('no-reply@uspelection.com', 'USP Election System');
        $mail->addAddress($to_email);
        $mail->isHTML(true);
        $mail->Subject = 'Approve Login Request';
        $mail->Body = "
            <h2>New Login Attempt</h2>
            <p>Someone (hopefully you) is trying to log in.</p>
            <p>Click below to approve this login on your other device:</p>
            <p><a href='$verify_link' style='background:blue; color:white; padding:10px;'>APPROVE LOGIN</a></p>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) { return false; }
}
?>