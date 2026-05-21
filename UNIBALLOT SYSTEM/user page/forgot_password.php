<?php
require '../PHPMailer/Exception.php';
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

session_start();

// Database connection
require_once '../db_connect.php';

// Determine current step (1=Email, 2=OTP, 3=New Pass)
$step = isset($_SESSION['fp_step']) ? $_SESSION['fp_step'] : 1;
$error_msg = "";
$success_msg = "";
$show_success_modal = false; // Flag to control the success modal

// ==========================================
// LOGIC HANDLER
// ==========================================

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- STEP 1: SEND OTP ---
    if (isset($_POST['action']) && $_POST['action'] == 'send_otp') {
        $email = trim($_POST['email']);
        
        // Check if email exists in database
        $stmt = $conn->prepare("SELECT * FROM voters WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $otp = rand(100000, 999999);
            $_SESSION['temp_email'] = $email;
            $_SESSION['temp_otp'] = $otp;
            $_SESSION['otp_expiry'] = time() + (60 * 5); // 5 minutes

            // SEND EMAIL
            $mail = new PHPMailer(true);
            try {
                // SMTP SETTINGS
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'paulemberga001@gmail.com';     // Your Email
                $mail->Password   = 'jfza lwhv lkym fpkl';        // Your App Password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                // Recipients
                $mail->setFrom('no-reply@uspelect.com', 'UniBallot Election System');
                $mail->addAddress($email);

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Code';
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                        <h2>Password Reset Request</h2>
                        <p>Your One-Time Password (OTP) is:</p>
                        <h1 style='color: #3b4d3b; letter-spacing: 5px;'>$otp</h1>
                        <p>This code expires in 5 minutes.</p>
                    </div>
                ";

                $mail->send();
                $_SESSION['fp_step'] = 2; // Move to next step
                $step = 2;
                $success_msg = "Code sent to " . htmlspecialchars($email);
            } catch (Exception $e) {
                $error_msg = "Email could not be sent. Error: {$mail->ErrorInfo}";
            }
        } else {
            $error_msg = "This email is not registered.";
        }
    }

    // --- STEP 2: VERIFY OTP ---
    elseif (isset($_POST['action']) && $_POST['action'] == 'verify_otp') {
        $user_otp = implode("", $_POST['otp_digit']); 
        
        if (!isset($_SESSION['otp_expiry']) || time() > $_SESSION['otp_expiry']) {
            $error_msg = "Code expired. Please try again.";
            $_SESSION['fp_step'] = 1;
            $step = 1;
        } elseif ($user_otp == $_SESSION['temp_otp']) {
            $_SESSION['fp_step'] = 3;
            $step = 3;
            $success_msg = "Code verified!";
        } else {
            $error_msg = "Invalid Code. Please check your email.";
        }
    }

    // --- STEP 3: RESET PASSWORD ---
    elseif (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
        $pass1 = $_POST['password'];
        $pass2 = $_POST['confirm_password'];

        if ($pass1 !== $pass2) {
            $error_msg = "Passwords do not match.";
        } elseif (strlen($pass1) < 6) {
             $error_msg = "Password must be at least 6 characters.";
        } else {
            // Hash and Update
            $hashed_password = password_hash($pass1, PASSWORD_DEFAULT);
            
            if(isset($_SESSION['temp_email'])) {
                $email = $_SESSION['temp_email'];
                $stmt = $conn->prepare("UPDATE voters SET password = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed_password, $email);
                
                if ($stmt->execute()) {
                    // Success! Destroy session and show modal
                    session_destroy(); 
                    $show_success_modal = true; 
                } else {
                    $error_msg = "Database update failed.";
                }
            } else {
                $error_msg = "Session expired. Please start over.";
                $step = 1;
            }
        }
    }

    // --- RESEND LOGIC ---
    elseif (isset($_POST['action']) && $_POST['action'] == 'resend') {
        $_SESSION['fp_step'] = 1;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - USP Election</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #3b4d3b; --secondary-green: #566b53; 
            --light-green: #7d9679; --card-bg: #c4c2a5; 
            --text-dark: #121a1a; --danger: #85211a; --white-soft: #f0f0e6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        
        body { 
            background-color: var(--light-green); 
            color: var(--text-dark); 
            min-height: 100vh; 
            display: flex; justify-content: center; align-items: center; padding: 20px;
        }

        .login-wrapper { width: 100%; max-width: 420px; animation: slideUp 0.5s ease; }
        .login-card { 
            background: var(--white-soft); border-radius: 20px; 
            box-shadow: 0 20px 50px rgba(0,0,0,0.3); padding: 40px 30px; 
            text-align: center; border: 1px solid rgba(255,255,255,0.5);
            position: relative; z-index: 1;
        }

        .header-icon-circle {
            width: 70px; height: 70px; border-radius: 50%; background-color: #e3e8e3; 
            color: var(--primary-green); display: flex; align-items: center; justify-content: center; 
            margin: 0 auto 20px; font-size: 35px;
        }

        h2 { margin-bottom: 10px; color: var(--text-dark); font-weight: 800; }
        p { color: #666; font-size: 14px; margin-bottom: 25px; line-height: 1.4; }

        .input-group { margin-bottom: 20px; text-align: left; }
        .input-group label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 700; color: #4a574a; margin-left: 5px; }
        
        .input-wrapper { position: relative; }
        .field-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--secondary-green); font-size: 22px; }
        
        .form-control {
            width: 100%; padding: 14px 15px 14px 45px; border-radius: 12px;
            border: 2px solid #ccc; font-size: 15px; font-weight: 500; transition: all 0.2s;
        }
        .form-control:focus { outline: none; border-color: var(--primary-green); box-shadow: 0 0 0 4px rgba(59, 77, 59, 0.1); }

        .btn-login {
            width: 100%; padding: 15px; border-radius: 12px; font-weight: 800; font-size: 14px;
            cursor: pointer; border: none; background: var(--primary-green); color: white;
            text-transform: uppercase; margin-top: 10px; transition: 0.2s;
        }
        .btn-login:hover { background: var(--secondary-green); transform: translateY(-2px); }

        /* OTP Input Styling */
        .otp-container { display: flex; justify-content: space-between; gap: 5px; margin-bottom: 20px; }
        .otp-input {
            width: 100%; height: 50px; text-align: center; font-size: 20px; font-weight: bold;
            border: 2px solid #ccc; border-radius: 8px;
        }
        .otp-input:focus { border-color: var(--primary-green); outline: none; }

        .alert { padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; text-align: center; }
        .alert-error { background: #ffebee; color: var(--danger); border: 1px solid #ffcdd2; }
        .alert-success { background: #e8f5e9; color: var(--primary-green); border: 1px solid #c8e6c9; }

        .back-link { margin-top: 20px; display: block; font-size: 14px; color: var(--secondary-green); text-decoration: none; font-weight: 600; }
        .back-link:hover { text-decoration: underline; color: var(--primary-green); }

        /* Show Password Checkbox Styling */
        .show-pass-wrapper {
            display: flex; align-items: center; justify-content: flex-start;
            margin-bottom: 15px; padding-left: 5px;
        }
        .show-pass-wrapper input[type="checkbox"] {
            width: 16px; height: 16px; accent-color: var(--primary-green);
            cursor: pointer; margin-right: 8px;
        }
        .show-pass-wrapper label {
            font-size: 13px; font-weight: 600; color: #4a574a; cursor: pointer; user-select: none;
        }

        /* --- MODAL STYLES --- */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(59, 77, 59, 0.8); /* Semi-transparent green-ish dark */
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: flex; justify-content: center; align-items: center;
            animation: fadeIn 0.4s ease;
        }
        .modal-box {
            background: #fff; padding: 40px 30px; border-radius: 20px;
            text-align: center; width: 90%; max-width: 400px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            animation: scaleUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .modal-icon {
            font-size: 60px; color: var(--primary-green); margin-bottom: 15px;
            display: inline-block;
        }
        .modal-title {
            font-size: 24px; font-weight: 800; color: var(--text-dark); margin-bottom: 10px;
        }
        .modal-text {
            color: #666; font-size: 15px; margin-bottom: 25px; line-height: 1.5;
        }
        .modal-btn {
            display: block; width: 100%; padding: 14px; background: var(--primary-green);
            color: white; font-weight: 700; border-radius: 12px; text-decoration: none;
            transition: 0.2s; text-transform: uppercase; font-size: 14px;
        }
        .modal-btn:hover {
            background: var(--secondary-green); transform: translateY(-2px);
        }

        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes scaleUp { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>

<!-- MODAL IMPLEMENTATION -->
<?php if ($show_success_modal): ?>
<div class="modal-overlay">
    <div class="modal-box">
        <span class="material-icons modal-icon">check_circle</span>
        <h3 class="modal-title">Success!</h3>
        <p class="modal-text">Your password has been changed successfully. You can now login with your new credentials.</p>
        <a href="../index.php" class="modal-btn">Back to Login</a>
    </div>
</div>
<?php endif; ?>

<div class="login-wrapper">
    <div class="login-card">
        
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><?php echo $error_msg; ?></div>
        <?php endif; ?>
        <?php if ($success_msg && !$show_success_modal): ?>
            <div class="alert alert-success"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <!-- STEP 1: ENTER EMAIL -->
        <?php if ($step == 1): ?>
            <div class="header-icon-circle"><span class="material-icons">lock_reset</span></div>
            <h2>Forgot Password?</h2>
            <p>Enter the email address associated with your account and we'll send you a code.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="send_otp">
                <div class="input-group">
                    <label>Email Address</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">email</span>
                        <input type="email" name="email" class="form-control" placeholder="student@gmail.com" required>
                    </div>
                </div>
                <button type="submit" class="btn-login">Send Code</button>
            </form>

        <!-- STEP 2: ENTER OTP -->
        <?php elseif ($step == 2): ?>
            <div class="header-icon-circle"><span class="material-icons">password</span></div>
            <h2>Verify Code</h2>
            <p>We sent a 6-digit code to <b><?php echo isset($_SESSION['temp_email']) ? htmlspecialchars($_SESSION['temp_email']) : 'your email'; ?></b>.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="verify_otp">
                <div class="otp-container">
                    <?php for($i=0; $i<6; $i++): ?>
                        <input type="text" name="otp_digit[]" class="otp-input" maxlength="1" pattern="[0-9]" required 
                        oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value.length >= this.maxLength) { var next = this.nextElementSibling; if(next) next.focus(); }">
                    <?php endfor; ?>
                </div>
                <button type="submit" class="btn-login">Verify</button>
            </form>
            <form method="POST" style="margin-top:10px;">
                <input type="hidden" name="action" value="resend">
                <button type="submit" style="background:none; border:none; color:var(--primary-green); font-weight:600; cursor:pointer; font-size:13px;">Resend Code?</button>
            </form>

        <!-- STEP 3: NEW PASSWORD -->
        <?php elseif ($step == 3): ?>
            <div class="header-icon-circle"><span class="material-icons">check_circle</span></div>
            <h2>Reset Password</h2>
            <p>Please enter your new password below.</p>
            
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                
                <div class="input-group">
                    <label>New Password</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">lock</span>
                        <input type="password" name="password" id="new_pass" class="form-control" placeholder="••••••••" required minlength="6">
                    </div>
                </div>

                <div class="input-group">
                    <label>Confirm Password</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">lock_outline</span>
                        <input type="password" name="confirm_password" id="confirm_pass" class="form-control" placeholder="••••••••" required>
                    </div>
                </div>

                <!-- Single Checkbox for visibility -->
                <div class="show-pass-wrapper">
                    <input type="checkbox" id="togglePass" onclick="toggleBothPasswords()">
                    <label for="togglePass">Show Password</label>
                </div>

                <button type="submit" class="btn-login">Update Password</button>
            </form>
        <?php endif; ?>

        <a href="../index.php" class="back-link">
            <span class="material-icons" style="vertical-align: middle; font-size: 16px;">arrow_back</span> 
            Back to Log In
        </a>
    </div>
</div>

<script>
    // Toggle visibility for both password fields at once
    function toggleBothPasswords() {
        var p1 = document.getElementById("new_pass");
        var p2 = document.getElementById("confirm_pass");
        var checkBox = document.getElementById("togglePass");

        if (checkBox.checked) {
            p1.type = "text";
            p2.type = "text";
        } else {
            p1.type = "password";
            p2.type = "password";
        }
    }
</script>

</body>
</html>