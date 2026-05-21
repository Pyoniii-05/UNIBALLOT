<?php
session_start();

// 1. ENABLE ERROR REPORTING
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==========================================
// 2. INCLUDE PHPMAILER
// ==========================================
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$found = false;
if (file_exists('../PHPMailer/PHPMailer.php')) {
    require '../PHPMailer/Exception.php';
    require '../PHPMailer/PHPMailer.php';   
    require '../PHPMailer/SMTP.php';
    $found = true;
} elseif (file_exists('../PHPMailer/PHPMailer.php')) {
    require '../PHPMailer/Exception.php';
    require '../PHPMailer/PHPMailer.php';
    require '../PHPMailer/SMTP.php';
    $found = true;
}

if (!$found) {
    die("<div style='color:red; padding:20px; border:1px solid red; background:#ffe6e6;'>
            <strong>CRITICAL ERROR: PHPMailer not found.</strong>
         </div>");
}

// ==========================================
// 3. DATABASE CONNECTION
// ==========================================
require_once '../db_connect.php';

// ==========================================
// 4. HELPER FUNCTIONS
// ==========================================

function generateRandomPassword($length = 8) {
    $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    return substr(str_shuffle(str_repeat($chars, 5)), 0, $length);
}

function sendElectionEmail($toEmail, $studentName, $password, $type = 'new') {
    $mail = new PHPMailer(true); 
    try {
        $mail->SMTPDebug = 0; 
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'paulemberga001@gmail.com'; 
        $mail->Password   = 'afcufqqfynbngqyb'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('paulemberga001@gmail.com', 'UniBallot Election System'); 
        $mail->addAddress($toEmail, $studentName);

        $mail->isHTML(true);
        
        if ($type === 'new') {
            $mail->Subject = 'UniBallot Election: Voter Credentials';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2 style='color: #3b4d3b;'>Welcome, $studentName!</h2>
                    <p>You have been registered as a voter.</p>
                    <div style='background: #f0f0e6; padding: 15px; border-radius: 5px; margin: 20px 0; border: 1px solid #ccc;'>
                        <p><strong>Username:</strong> $toEmail (or Student ID)</p>
                        <p><strong>Password:</strong> <span style='font-size: 18px; font-weight: bold; color: #d4b200; background: #fff; padding: 2px 6px;'>$password</span></p>
                    </div>
                    <p>Please log in to cast your vote.</p>
                </div>";
        } else {
            $mail->Subject = 'UniBallot Election: Password Reset';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2>Password Reset</h2>
                    <p><strong>New Password:</strong> $password</p>
                </div>";
        }

        $mail->send();
        return true; 
    } catch (Exception $e) {
        return "Mailer Error: " . $mail->ErrorInfo;
    }
}

// ==========================================
// 5. AUTHENTICATION CHECK
// ==========================================
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

$student_no = $_SESSION['stu_no'];
$firstname = "Admin";
$lastname = "User";
$initials = "AD";

// Fetch Admin Details
if ($student_no === 'ORG') {
    $stmt = $conn->prepare("SELECT firstname, lastname FROM admins WHERE username = ?");
    $stmt->bind_param("s", $student_no);
} else {
    $stmt = $conn->prepare("SELECT firstname, lastname FROM voters WHERE stu_no = ?");
    $stmt->bind_param("s", $student_no);
}

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $firstname = $row['firstname'];
        $lastname = $row['lastname'];
        $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
    }
}

$message = '';
$message_type = '';

// ==========================================
// 6. HANDLE POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- ADD VOTER ---
    if (isset($_POST['add_voter'])) {
        $stu_no = trim($_POST['stu_no']);
        $email = trim($_POST['email']);
        
        // 1. Check Master List
        $sql = "SELECT lastname, program, department FROM students WHERE stu_no = ?";
        $check_student_stmt = $conn->prepare($sql);
        
        if (!$check_student_stmt) {
            die("<div style='background:#ffe6e6; border:2px solid red; padding:20px; font-family:sans-serif;'>
                    <h2 style='color:red;'>DATABASE ERROR</h2>
                    <p>" . $conn->error . "</p>
                 </div>");
        }

        $check_student_stmt->bind_param("s", $stu_no);
        $check_student_stmt->execute();
        $student_result = $check_student_stmt->get_result();
        
        if ($student_result->num_rows === 0) {
            $message = "Error: Student ID ($stu_no) NOT FOUND in Master List (students table).";
            $message_type = "error";
        } else {
            $student_data = $student_result->fetch_assoc();
            
            // 2. Check if Already Registered
            $check_voter_stmt = $conn->prepare("SELECT stu_no FROM voters WHERE stu_no = ?");
            if (!$check_voter_stmt) { die("Error checking voters: " . $conn->error); }
            
            $check_voter_stmt->bind_param("s", $stu_no);
            $check_voter_stmt->execute();
            
            if ($check_voter_stmt->get_result()->num_rows > 0) {
                $message = "Error: This student is ALREADY registered as a voter.";
                $message_type = "error";
            } else {
                // 3. Register
                $actual_lastname = $student_data['lastname'];
                $actual_program = $student_data['program'];
                $actual_department = $student_data['department'];
                $plain_password = generateRandomPassword(8);
                $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
                
                $insert_sql = "INSERT INTO voters (stu_no, lastname, email, program, department, password, status) VALUES (?, ?, ?, ?, ?, ?, 'not_voted')";
                
                $insert_stmt = $conn->prepare($insert_sql);
                if (!$insert_stmt) { die("Error inserting voter: " . $conn->error); }
                
                $insert_stmt->bind_param("ssssss", $stu_no, $actual_lastname, $email, $actual_program, $actual_department, $hashed_password);
                
                if ($insert_stmt->execute()) {
                    // 4. Send Email
                    $emailResult = sendElectionEmail($email, $actual_lastname, $plain_password, 'new');
                    
                    if ($emailResult === true) {
                        $message = "Voter added & Email sent successfully!";
                        $message_type = "success";
                    } else {
                        $message = "Voter added to DB, but Email Failed: " . $emailResult;
                        $message_type = "error"; 
                    }
                } else {
                    $message = "Database Insert Error: " . $conn->error;
                    $message_type = "error";
                }
            }
        }
    }
    
    // --- DELETE VOTER ---
    elseif (isset($_POST['delete_voter'])) {
        $stu_no = $_POST['delete_stu_no'];
        $conn->query("DELETE FROM votes WHERE stu_no = '$stu_no'");
        $conn->query("DELETE FROM voters WHERE stu_no = '$stu_no'");
        $message = "Voter deleted successfully!";
        $message_type = "success";
    }
    
    // --- RESET PASSWORD ---
    elseif (isset($_POST['reset_password'])) {
        $stu_no = $_POST['reset_stu_no'];
        $stmt = $conn->prepare("SELECT email, lastname FROM voters WHERE stu_no = ?");
        if (!$stmt) { die("Error resetting: " . $conn->error); }
        
        $stmt->bind_param("s", $stu_no);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $email = $row['email'];
            $name = $row['lastname'];
            $new_pass = generateRandomPassword(8);
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);

            $conn->query("UPDATE voters SET password = '$new_hash' WHERE stu_no = '$stu_no'");
            $emailResult = sendElectionEmail($email, $name, $new_pass, 'reset');

            if ($emailResult === true) {
                $message = "Password reset & Emailed!";
                $message_type = "success";
            } else {
                $message = "Password reset, but Email Failed: " . $emailResult;
                $message_type = "error";
            }
        }
    }
}

// Fetch Voters for Table
$voters = [];
$res = $conn->query("SELECT * FROM voters WHERE stu_no != '$student_no' ORDER BY stu_no ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $voters[] = $row; }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Voters</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================
           VARIABLES & RESET
           ============================ */
        :root {
            --primary-green: #3b4d3b; 
            --secondary-green: #566b53; 
            --light-green: #7d9679; 
            --card-bg: #c4c2a5; 
            --overview-bg: #aabf9d;
            --text-dark: #121a1a; 
            --danger: #85211a; 
            --gold: #d4b200;
            --white-soft: #f0f0e6;
            --input-border: #c9c7ad;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-green); color: var(--text-dark); min-height: 100vh; }

        /* ============================
           NAVBAR
           ============================ */
        .navbar { 
            background-color: var(--primary-green); 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25); 
            padding: 15px 30px; 
            display: flex; align-items: center; 
            position: sticky; top: 0; z-index: 100; 
        }
        .navbar .material-icons { color: #f2f2f2; margin-right: 15px; font-size: 28px; cursor: pointer; }
        .navbar h1 { font-size: 20px; font-weight: 700; color: #f2f2f2; letter-spacing: 0.5px; }

        /* ============================
           DRAWER (SIDEBAR) - Z-INDEX: 200
           ============================ */
        .drawer { 
            height: 100%; width: 280px; position: fixed; z-index: 200; 
            top: 0; left: -300px; 
            background-color: var(--white-soft); 
            transition: left 0.4s cubic-bezier(0.25, 0.8, 0.25, 1); 
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.3); 
        }
        .drawer.open { left: 0; }
        
        .drawer-header { background-color: var(--primary-green); padding: 20px; display: flex; justify-content: space-between; align-items: center; color: white; }
        
        .drawer-profile { padding: 25px 20px; background: linear-gradient(to bottom, #e3e1c8, #d6d4ba); border-bottom: 1px solid #c9c7ad; display: flex; align-items: center; gap: 15px; }
        .profile-avatar { width: 55px; height: 55px; border-radius: 50%; background: var(--primary-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; box-shadow: 0 4px 8px rgba(0,0,0,0.15); border: 2px solid #fff; flex-shrink: 0; }
        .profile-info h3 { font-size: 16px; font-weight: 800; margin-bottom: 2px; }
        .profile-info p { font-size: 13px; font-weight:600; color: #555; margin-bottom: 6px; }
        .admin-badge { background: linear-gradient(135deg, #FFD700, #FDB931); color: #5c4500; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 800; display: inline-flex; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .drawer-nav { padding: 15px 10px; }
        .nav-item { display: flex; align-items: center; padding: 12px 20px; color: var(--text-dark); text-decoration: none; transition: all 0.2s; font-weight: 500; border-radius: 8px; margin-bottom: 5px; }
        .nav-item:hover { background-color: rgba(86, 107, 83, 0.15); color: var(--primary-green); }
        .nav-item.active { background-color: var(--primary-green); color: white; font-weight: 600; }
        .nav-item .material-icons { margin-right: 15px; font-size: 22px; color: inherit; }

        /* ============================
           DRAWER OVERLAY - Z-INDEX: 150 (FIXED)
           ============================ */
        .overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.4); backdrop-filter: blur(3px); 
            z-index: 150; /* Lower than drawer (200) */
            opacity: 0; visibility: hidden; 
            transition: 0.3s; 
        }
        .overlay.active { opacity: 1; visibility: visible; }

        /* ============================
           MODAL OVERLAY - Z-INDEX: 1000
           ============================ */
        .modal-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.4); backdrop-filter: blur(3px); 
            z-index: 1000; /* Higher than drawer */
            opacity: 0; visibility: hidden; 
            display: flex; justify-content: center; align-items: center; 
            transition: 0.3s; 
        }
        .modal-overlay.active { opacity: 1; visibility: visible; }

        .modal-box { background: #f0f0e6; width: 90%; max-width: 480px; border-radius: 20px; transform: scale(0.9); transition: 0.3s; overflow: hidden; border: 1px solid rgba(255,255,255,0.5); }
        .modal-overlay.active .modal-box { transform: scale(1); }
        .modal-header { padding: 30px 20px 10px; text-align: center; }
        .modal-icon-circle { width: 70px; height: 70px; border-radius: 50%; display: flex; justify-content: center; align-items: center; margin: 0 auto 15px; font-size: 32px; }
        .modal-box.add .modal-icon-circle { background-color: #e3e8e3; color: #28a745; }
        .modal-box.info .modal-icon-circle { background-color: #e3e8e3; color: var(--primary-green); }
        .modal-box.warning .modal-icon-circle { background-color: #fde8e8; color: var(--danger); }
        .modal-body { padding: 0 30px 20px; }
        .modal-actions { padding: 25px; display: flex; gap: 15px; background: rgba(0,0,0,0.03); }
        
        .form-control { width: 100%; padding: 12px; border: 1px solid #d1cfb8; border-radius: 10px; margin-bottom: 15px; font-family: 'Inter', sans-serif; }
        
        .btn-modal { flex: 1; padding: 14px; border-radius: 12px; font-weight: 700; border: none; cursor: pointer; text-transform: uppercase; transition: all 0.2s; }
        .btn-modal.cancel { background: #d6d4ba; color: #4a574a; }
        .btn-modal.cancel:hover { background: #c4c2a5; color: var(--text-dark); }
        .btn-modal.confirm-success { background: var(--primary-green); color: white; }
        .btn-modal.confirm-success:hover { background: var(--secondary-green); transform: translateY(-2px); }
        .btn-modal.confirm-danger { background: var(--danger); color: white; }
        .btn-modal.confirm-danger:hover { background: #6b1b15; transform: translateY(-2px); }

        /* ============================
           DASHBOARD & TABLE
           ============================ */
        .container { max-width: 1600px; width: 95%; margin: 30px auto; padding: 20px; }
        .dashboard-header { background: var(--overview-bg); border-radius: 16px; padding: 35px; margin-bottom: 35px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid rgba(0,0,0,0.05); }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .summary-card { background: #e3e1c8; border-radius: 12px; padding: 25px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.08); border: 1px solid #d1cfb8; transition: transform 0.3s ease; }
        .summary-card:hover { transform: translateY(-5px); }
        .summary-number { font-size: 32px; font-weight: 800; color: var(--text-dark); }
        
        .admin-controls { display: flex; gap: 15px; border-top: 2px solid rgba(0,0,0,0.1); padding-top: 25px; flex-wrap: wrap; }
        .search-container { display: flex; gap: 10px; flex: 1; flex-wrap: wrap; }
        .search-input { flex: 1; padding: 12px 15px; border-radius: 10px; border: none; min-width: 200px; }
        .filter-select { padding: 12px; border-radius: 10px; border: none; cursor: pointer; }

        .table-container { max-height: 500px; overflow: auto; border-radius: 12px; background: white; box-shadow: 0 8px 20px rgba(0,0,0,0.1); border: 1px solid var(--input-border); }
        .custom-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .custom-table th { background: var(--secondary-green); color: white; padding: 18px 15px; text-align: left; position: sticky; top: 0; }
        .custom-table td { padding: 15px; border-bottom: 1px solid #d1cfb8; vertical-align: middle; }
        .badge { padding: 6px 12px; border-radius: 30px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge.voted { background: #d4edda; color: #155724; }
        .badge.not-voted { background: #f8d7da; color: #721c24; }
        
        .action-btn { width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer; color: white; margin-right: 5px; display: inline-flex; align-items: center; justify-content: center; transition: transform 0.2s; }
        .action-btn:hover { transform: scale(1.1); }
        .btn-pass { background: var(--gold); }
        .btn-del { background: var(--danger); }

        .student-info-box { background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 10px; padding: 15px; margin-bottom: 15px; display: none; }
        .student-info-box.visible { display: block; }
        .student-info-box h4 { margin: 0 0 10px; font-size: 14px; color: var(--primary-green); }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px; }
    </style>
</head>
<body>

    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>ADMIN - MANAGE VOTERS</h1>
    </nav>

    <!-- DRAWER OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

    <!-- DRAWER SIDEBAR -->
    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Admin Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
                <div class="admin-badge">Administrator</div>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item active"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <!-- RESULT MODAL -->
    <div class="modal-overlay" id="resultModal">
        <div class="modal-box info" id="resultModalBox"> 
            <div class="modal-header">
                <div class="modal-icon-circle" id="resultIcon"></div>
                <h2 id="resultTitle"></h2>
                <p id="resultMessage" style="margin-top:10px; line-height:1.5; word-wrap:break-word;"></p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal confirm-success" style="width:100%;" onclick="closeModal(this)">OK</button>
            </div>
        </div>
    </div>

    <!-- ADD VOTER MODAL -->
    <div class="modal-overlay" id="addVoterModal">
        <div class="modal-box add">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">person_add</span></div>
                <h2 style="margin:0; font-size:22px;">Add New Voter</h2>
                <p style="color:#666; font-size:14px;">Password will be auto-generated and emailed.</p>
            </div>
            <form method="POST" id="addVoterForm">
                <div class="modal-body">
                    <input type="text" name="stu_no" class="form-control" required placeholder="Student Number" oninput="fetchStudentData(this.value)">
                    <div class="student-info-box" id="studentInfo">
                        <h4>Student Found</h4>
                        <div class="info-grid">
                            <div><small>Name</small><div id="info_lastname" style="font-weight:bold;"></div></div>
                            <div><small>Program</small><div id="info_program" style="font-weight:bold;"></div></div>
                            <div style="grid-column:span 2"><small>Department</small><div id="info_department" style="font-weight:bold;"></div></div>
                        </div>
                    </div>
                    <input type="text" name="lastname" id="lastname" class="form-control" readonly placeholder="Last Name (Auto)">
                    <input type="email" name="email" class="form-control" required placeholder="Email Address">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-modal cancel" id="cancelAdd">Cancel</button>
                    <button type="submit" name="add_voter" class="btn-modal confirm-success">Generate & Email</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE MODAL -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box warning">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">warning_amber</span></div>
                <h2>Delete Voter?</h2>
                <p id="deleteText">This action cannot be undone.</p>
            </div>
            <form method="POST" class="modal-actions" style="margin:0; width:100%;">
                <input type="hidden" name="delete_stu_no" id="delete_stu_no">
                <button type="button" class="btn-modal cancel" id="cancelDelete">Cancel</button>
                <button type="submit" name="delete_voter" class="btn-modal confirm-danger">Delete</button>
            </form>
        </div>
    </div>

    <!-- RESET MODAL -->
    <div class="modal-overlay" id="resetPasswordModal">
        <div class="modal-box info">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">lock_reset</span></div>
                <h2>Reset Password</h2>
                <p>Generate new password for <strong id="reset_student_name"></strong>?</p>
            </div>
            <form method="POST" class="modal-actions" style="margin:0; width:100%;">
                <input type="hidden" name="reset_stu_no" id="reset_stu_no">
                <button type="button" class="btn-modal cancel" id="cancelReset">Cancel</button>
                <button type="submit" name="reset_password" class="btn-modal confirm-success">Generate & Email</button>
            </form>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box info">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">logout</span></div>
                <h2>Logout?</h2>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="cancelLogout">Cancel</button>
                <button class="btn-modal confirm-success" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="dashboard-header">
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-number"><?php echo count($voters); ?></div>
                    <div>Total Voters</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo count(array_filter($voters, function($v){return $v['status']=='voted';})); ?></div>
                    <div>Voted</div>
                </div>
            </div>
            <div class="admin-controls">
                <button class="btn-modal confirm-success" id="addVoterBtn" style="padding:12px 20px; width:auto;">Add Voter</button>
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="Search...">
                    <select id="statusFilter" class="filter-select"><option value="">All Status</option><option value="Voted">Voted</option><option value="Not Voted">Not Voted</option></select>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="custom-table">
                <thead><tr><th>ID</th><th>Name</th><th>Program</th><th>Dept</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($voters as $v): 
                        $status = ($v['status'] === 'voted') ? 'Voted' : 'Not Voted';
                        $badge = ($v['status'] === 'voted') ? 'voted' : 'not-voted';
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($v['stu_no']); ?></strong></td>
                        <td><?php echo htmlspecialchars($v['lastname']); ?></td>
                        <td><?php echo htmlspecialchars($v['program']); ?></td>
                        <td><?php echo htmlspecialchars($v['department']); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo $status; ?></span></td>
                        <td>
                            <button class="action-btn btn-pass" onclick="resetPassword('<?php echo $v['stu_no']; ?>','<?php echo htmlspecialchars($v['lastname']); ?>')"><span class="material-icons" style="font-size:16px;">lock_reset</span></button>
                            <button class="action-btn btn-del" onclick="deleteVoter('<?php echo $v['stu_no']; ?>','<?php echo htmlspecialchars($v['lastname']); ?>')"><span class="material-icons" style="font-size:16px;">delete</span></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?><script src="../drawer-peek.js"></script><?php endif; ?>
    <script>
        // Modal Logic
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(el) { el.closest('.modal-overlay').classList.remove('active'); }
        
        document.querySelectorAll('.btn-modal.cancel').forEach(btn => btn.onclick = function() { closeModal(this); });
        
        document.getElementById('addVoterBtn').onclick = () => {
            document.getElementById('addVoterForm').reset();
            document.getElementById('studentInfo').classList.remove('visible');
            openModal('addVoterModal');
        };

        // Actions
        function deleteVoter(id, name) {
            document.getElementById('delete_stu_no').value = id;
            document.getElementById('deleteText').innerHTML = `Delete <strong>${name} (${id})</strong>?`;
            openModal('deleteModal');
        }
        function resetPassword(id, name) {
            document.getElementById('reset_stu_no').value = id;
            document.getElementById('reset_student_name').innerText = name;
            openModal('resetPasswordModal');
        }
        document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); openModal('logoutModal'); };
        document.getElementById('confirmLogout').onclick = () => { window.location.href="?logout=true"; };

        // Fetch Data
        function fetchStudentData(id) {
            if(id.length < 3) return;
            fetch('get_student_data.php?stu_no='+encodeURIComponent(id))
            .then(r=>r.json()).then(d=>{
                if(d.exists) {
                    document.getElementById('studentInfo').classList.add('visible');
                    document.getElementById('info_lastname').innerText = d.lastname;
                    document.getElementById('info_program').innerText = d.program;
                    document.getElementById('info_department').innerText = d.department;
                    document.getElementById('lastname').value = d.lastname;
                } else {
                    document.getElementById('studentInfo').classList.remove('visible');
                    document.getElementById('lastname').value = '';
                }
            });
        }

        // Search Filter
        const rows = document.querySelectorAll('tbody tr');
        function filter() {
            const s = document.getElementById('searchInput').value.toLowerCase();
            const stat = document.getElementById('statusFilter').value;
            rows.forEach(r => {
                const txt = r.innerText.toLowerCase();
                const st = r.querySelector('.badge').innerText;
                r.style.display = (txt.includes(s) && (!stat || st === stat)) ? '' : 'none';
            });
        }
        document.getElementById('searchInput').oninput = filter;
        document.getElementById('statusFilter').onchange = filter;

        // Drawer
        const d = document.getElementById('drawer'), ov = document.getElementById('drawerOverlay');
        document.getElementById('menuIcon').onclick = () => { d.classList.add('open'); ov.classList.add('active'); };
        document.getElementById('closeIcon').onclick = () => { d.classList.remove('open'); ov.classList.remove('active'); };
        ov.onclick = () => { d.classList.remove('open'); ov.classList.remove('active'); };

        // -----------------------------------------------------
        // PHP MESSAGE HANDLER (Triggers the Result Modal)
        // -----------------------------------------------------
        <?php if ($message != ''): ?>
            const msgType = "<?php echo $message_type; ?>"; 
            const msgText = "<?php echo addslashes($message); ?>"; 

            const modalBox = document.getElementById('resultModalBox');
            const iconContainer = document.getElementById('resultIcon');
            const title = document.getElementById('resultTitle');
            const desc = document.getElementById('resultMessage');

            if(msgType === 'success') {
                modalBox.className = 'modal-box add'; 
                iconContainer.innerHTML = '<span class="material-icons">check_circle</span>';
                title.innerText = 'Success!';
            } else {
                modalBox.className = 'modal-box warning'; 
                iconContainer.innerHTML = '<span class="material-icons">error_outline</span>';
                title.innerText = 'Action Failed';
            }
            desc.innerHTML = msgText; 
            openModal('resultModal');
        <?php endif; ?>

    </script>
</body>
</html>