<?php
// settings.php

// ==========================================
// 1. BACKEND LOGIC
// ==========================================

session_start();

// Redirect if not logged in
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];

// Fetch Student Data
$sql = "SELECT firstname, lastname FROM students WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
    $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
} else {
    $firstname = "User"; $lastname = ""; $initials = "US";
}

// Fetch Account Data
$voter_sql = "SELECT email, password, program, status FROM voters WHERE stu_no = ?";
$voter_stmt = $conn->prepare($voter_sql);
$voter_stmt->bind_param("s", $student_no);
$voter_stmt->execute();
$voter_result = $voter_stmt->get_result();

if ($voter_result && $voter_result->num_rows > 0) {
    $voter = $voter_result->fetch_assoc();
    $current_email = $voter['email'];
    $current_password_hash = $voter['password'];
    $program = $voter['program'];
    $has_voted = ($voter['status'] === 'voted');
} else {
    $current_email = ""; $current_password_hash = ""; $program = ""; $has_voted = false;
}

// Election Status Logic
$election_info = [];
$election_sql = "SELECT * FROM election_info WHERE id = 1";
$election_result = $conn->query($election_sql);

if ($election_result && $election_result->num_rows > 0) {
    $election_info = $election_result->fetch_assoc();
    $db_status = $election_info['status'];
    
    // Time-based fallback
    $current_timestamp = time();
    $start_timestamp = strtotime($election_info['voting_start']);
    $end_timestamp = strtotime($election_info['voting_end']);
    
    if ($current_timestamp > $end_timestamp) $db_status = 'closed';
    elseif ($current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp) $db_status = 'ongoing';
    else $db_status = 'upcoming';
} else {
    $db_status = 'upcoming';
}

// ==========================================
// 2. FORM PROCESSING
// ==========================================

$email_message = '';
$password_message = '';
$msg_type_email = '';
$msg_type_pass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Change Email
    if (isset($_POST['change_email'])) {
        $new_email = trim($_POST['email']);
        if (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $update_sql = "UPDATE voters SET email = ? WHERE stu_no = ?";
            $u_stmt = $conn->prepare($update_sql);
            $u_stmt->bind_param("ss", $new_email, $student_no);
            if ($u_stmt->execute()) {
                $current_email = $new_email;
                $email_message = 'Email updated successfully!';
                $msg_type_email = 'success';
            } else {
                $email_message = 'Database error. Try again.';
                $msg_type_email = 'error';
            }
            $u_stmt->close();
        } else {
            $email_message = 'Invalid email format.';
            $msg_type_email = 'error';
        }
    }
    
    // Change Password
    if (isset($_POST['change_password'])) {
        $current_pass_input = $_POST['current_password'];
        $new_pass_input = $_POST['new_password'];
        $confirm_pass_input = $_POST['confirm_password'];
        
        if (!password_verify($current_pass_input, $current_password_hash)) {
            $password_message = 'Current password is incorrect.';
            $msg_type_pass = 'error';
        } elseif ($new_pass_input !== $confirm_pass_input) {
            $password_message = 'New passwords do not match.';
            $msg_type_pass = 'error';
        } elseif (strlen($new_pass_input) < 6) {
            $password_message = 'Password must be at least 6 characters.';
            $msg_type_pass = 'error';
        } else {
            $new_hash = password_hash($new_pass_input, PASSWORD_DEFAULT);
            $update_sql = "UPDATE voters SET password = ? WHERE stu_no = ?";
            $u_stmt = $conn->prepare($update_sql);
            $u_stmt->bind_param("ss", $new_hash, $student_no);
            if ($u_stmt->execute()) {
                $password_message = 'Password updated successfully!';
                $msg_type_pass = 'success';
                $current_password_hash = $new_hash;
            } else {
                $password_message = 'Database error. Try again.';
                $msg_type_pass = 'error';
            }
            $u_stmt->close();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot - SETTINGS</h1>
    </nav>

    <!-- DRAWER OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

    <!-- SIDEBAR -->
    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Election Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>
        
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
            </div>
        </div>
        
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="settings.php" class="nav-item active"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box info">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">logout</span></div>
                <h2 style="margin: 10px 0;">Signing Out?</h2>
                <p style="color:#666; font-size:14px;">You are about to end your session. Do you want to continue?</p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="cancelLogout">Cancel</button>
                <button class="btn-modal confirm-success" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="container">
        <!-- HEADER -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <span class="material-icons" style="color: var(--primary-green); font-size: 36px;">manage_accounts</span>
                Account Settings
            </div>
            <p style="color: #4a574a;">Manage your personal information and security credentials.</p>
        </div>

        <div class="settings-grid">
            
            <!-- EMAIL SETTINGS -->
            <div class="settings-card">
                <div class="card-header"><span class="material-icons">email</span> Update Email</div>
                
                <?php if ($email_message): ?>
                    <div class="alert-box <?php echo $msg_type_email; ?>">
                        <span class="material-icons"><?php echo $msg_type_email == 'success' ? 'check_circle' : 'error'; ?></span>
                        <?php echo $email_message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <div class="input-wrapper">
                            <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($current_email); ?>" required>
                        </div>
                    </div>
                    <button type="submit" name="change_email" class="btn-submit">
                        UPDATE EMAIL <span class="material-icons" style="font-size:18px;">send</span>
                    </button>
                </form>
            </div>

            <!-- PASSWORD SETTINGS -->
            <div class="settings-card">
                <div class="card-header"><span class="material-icons">lock</span> Change Password</div>

                <?php if ($password_message): ?>
                    <div class="alert-box <?php echo $msg_type_pass; ?>">
                        <span class="material-icons"><?php echo $msg_type_pass == 'success' ? 'check_circle' : 'error'; ?></span>
                        <?php echo $password_message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="current_password" id="cur_pass" class="form-input" required>
                            <span class="material-icons toggle-password" onclick="togglePass('cur_pass', this)">visibility</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="new_password" id="new_pass" class="form-input" required>
                            <span class="material-icons toggle-password" onclick="togglePass('new_pass', this)">visibility</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-wrapper">
                            <input type="password" name="confirm_password" id="conf_pass" class="form-input" required>
                            <span class="material-icons toggle-password" onclick="togglePass('conf_pass', this)">visibility</span>
                        </div>
                    </div>

                    <button type="submit" name="change_password" class="btn-submit">
                        UPDATE PASSWORD <span class="material-icons" style="font-size:18px;">security</span>
                    </button>
                </form>
            </div>

        </div>
    </div>

    <!-- SCRIPTS -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Drawer Logic ---
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');
            const closeIcon = document.getElementById('closeIcon');
            
            const toggleDrawer = (open) => { 
                if(open) { 
                    drawer.classList.add('open'); 
                    drawerOverlay.classList.add('active'); 
                } else { 
                    drawer.classList.remove('open'); 
                    drawerOverlay.classList.remove('active'); 
                }
            };
            menuIcon.onclick = () => toggleDrawer(true);
            closeIcon.onclick = () => toggleDrawer(false);
            drawerOverlay.onclick = () => toggleDrawer(false);

            // --- Logout Logic ---
            const logoutLink = document.getElementById('logoutLink');
            const logoutModal = document.getElementById('logoutModal');
            if(logoutLink) {
                logoutLink.onclick = (e) => { e.preventDefault(); toggleDrawer(false); logoutModal.classList.add('active'); };
            }
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";

            // --- Password Toggle ---
            window.togglePass = function(fieldId, icon) {
                const field = document.getElementById(fieldId);
                if (field.type === 'password') {
                    field.type = 'text';
                    icon.textContent = 'visibility_off';
                    icon.style.color = 'var(--primary-green)';
                } else {
                    field.type = 'password';
                    icon.textContent = 'visibility';
                    icon.style.color = '#666';
                }
            };

            // --- Auto Hide Alerts ---
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert-box');
                alerts.forEach(alert => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>