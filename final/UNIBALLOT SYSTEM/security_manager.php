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
    // Verification removed. Keep this function safe if any page still calls it.
    session_regenerate_id(true);
    $new_sid = session_id();

    $update = $conn->prepare("UPDATE voters SET active_session_id = ? WHERE stu_no = ?");
    if ($update) {
        $update->bind_param("ss", $new_sid, $stu_no);
        $update->execute();
        $update->close();
    }

    return false; // Never require verification
}

function _send_security_alert($to_email, $stu_no, $token) {
    // LOCALHOST ONLY: build the approval URL from the current script path
    $base_url = 'http://localhost';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $script_path = dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME']));
        $script_path = rtrim($script_path, '/');
        if ($script_path !== '' && $script_path !== '.') {
            $base_url .= $script_path;
        }
    }

    // POINT TO APPROVE_LOGIN.PHP
    $verify_link = $base_url . '/approve_login.php?t=' . urlencode($token) . '&u=' . urlencode($stu_no);

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