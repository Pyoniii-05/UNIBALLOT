<?php
// index.php

session_start();

// Database connection
require_once 'db_connect.php';

// Initialize error variables
$login_error = false;
$error_title = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $student_no = isset($_POST['stu_no']) ? trim($_POST['stu_no']) : '';
    $password   = isset($_POST['password']) ? $_POST['password'] : '';
    
    // ===============================================
    // 1. ADMIN LOGIN CHECK (Specific to "ORG")
    // ===============================================
    if ($student_no === 'ORG') {
        
        $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->bind_param("s", $student_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            if (password_verify($password, $row['password'])) {
                // ✅ Admin Success
                $_SESSION['stu_no'] = $row['username'];
                $_SESSION['is_admin'] = 1; 
                $_SESSION['firstname'] = $row['firstname'];
                $_SESSION['lastname'] = $row['lastname'];
                
                header("Location: ./admin page/dashboard.php");
                exit();
            } else {
                $login_error = true;
                $error_title = "Admin Access Denied";
                $error_message = "The Administrator Password you entered is incorrect.";
            }
        } else {
            $login_error = true;
            $error_title = "Admin Access Denied";
            $error_message = "Administrator account not found.";
        }
        $stmt->close();

    } 
    // ===============================================
    // 2. STUDENT LOGIN CHECK (Everything else)
    // ===============================================
    else {
        
        $stmt = $conn->prepare("SELECT * FROM voters WHERE stu_no = ?");
        $stmt->bind_param("s", $student_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();

            if (password_verify($password, $row['password'])) {
                
                // ---------------------------------------------------------
                // 2A. SECURITY CHECK (Is this a new device?)
                // ---------------------------------------------------------
                require_once 'security_manager.php'; 
                
                // This function detects new devices & sends the email if needed.
                $verification_required = register_new_login($conn, $row['stu_no'], $row['email']);

                if ($verification_required) {
                    // 🛑 NEW DEVICE DETECTED!
                    // 1. Set a TEMPORARY session (we don't fully log them in yet)
                    $_SESSION['temp_stu_no'] = $row['stu_no'];

                    // 2. Send them to the "Waiting Room" to wait for Phone Approval
                    header("Location: ./user page/waiting_room.php");
                    exit(); 
                }

                // ---------------------------------------------------------
                // 2B. SAFE LOGIN (Same device / No verification needed)
                // ---------------------------------------------------------
                $_SESSION['stu_no'] = $row['stu_no'];
                $_SESSION['email'] = $row['email'];
                $_SESSION['department'] = $row['department'];
                $_SESSION['program'] = $row['program'];
                $_SESSION['is_admin'] = 0;
                $_SESSION['fresh_login'] = true; 

                // ---------------------------------------------------------
                // 2C. CHECK VOTING STATUS & REDIRECT
                // ---------------------------------------------------------
                if ($row['status'] === 'voted') {
                    // If they have voted, send straight to Votepage (View Mode)
                    header("Location: ./user page/votepage.php");
                } else {
                    // If they haven't voted, send to Consent Page
                    header("Location: ./user page/consent.php");
                }
                exit(); 

            } else {
                // Wrong Password
                $login_error = true;
                $error_title = "Login Failed";
                $error_message = "The Student Number or Password you entered is incorrect.";
            }
        } else {
            // User Not Found
            $login_error = true;
            $error_title = "Login Failed";
            $error_message = "The Student Number or Password you entered is incorrect.";
        }
        $stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - UniBallot Election</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./styles/user.css">
</head>
<body class="flex-center">

    <!-- ERROR MODAL -->
    <div class="modal-overlay" id="errorModal">
        <div class="modal-box warning">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">error_outline</span></div>
                 <h2 class="modal-title">
                    <?php echo isset($error_title) ? $error_title : 'Login Failed'; ?>
                </h2>
               <p class="modal-desc">
                    <?php echo isset($error_message) ? $error_message : 'An error occurred.'; ?>
                </p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal confirm-danger" id="closeModal">Try Again</button>
            </div>
        </div>
    </div>

    <!-- LOGIN CARD -->
    <div class="login-wrapper">
        <div class="login-card">
            <div class="header-icon-circle">
                <span class="material-icons">person</span>
            </div>
            <h2 class="login-title">LOG IN</h2>

            <form method="POST" action="">
                <div class="input-group">
                    <label for="stu_no">Student Number</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">badge</span>
                        <input class="form-control" name="stu_no" id="stu_no" type="text" placeholder="e.g. 00-00000" required>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">lock</span>
                        <input class="form-control" name="password" id="password" type="password" placeholder="••••••••" required>
                        <button type="button" class="password-toggle" id="togglePassword">
                            <span class="material-icons">visibility</span>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">Log In</button>
            </form>
            
            <div class="login-links">
                <span>New here? <a href="./user page/signuppage.php">Create an Account</a></span>
                <a href="./user page/forgot_password.php">Forgot Password?</a>
            </div>
        </div>
    </div>

    <script>
        sessionStorage.setItem('drawerOpen', '0');
        
        document.addEventListener('DOMContentLoaded', function() {
            // Password Toggle Logic
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const toggleIcon = togglePassword.querySelector('.material-icons');
            
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                toggleIcon.textContent = type === 'password' ? 'visibility' : 'visibility_off';
            });

            // Modal Logic
            const errorModal = document.getElementById("errorModal");
            const closeModal = document.getElementById("closeModal");

            closeModal.addEventListener("click", () => {
                errorModal.classList.remove("active");
            });

            // Trigger Modal on PHP Error
            <?php if (isset($login_error) && $login_error): ?>
                errorModal.classList.add("active");
            <?php endif; ?>
        });
    </script>
</body>
</html>