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
}

if (!$found) {
    die("<div style='color:red; padding:20px; border:1px solid red; background:#ffe6e6;'><strong>CRITICAL ERROR: PHPMailer not found in the directory.</strong></div>");
}

// ==========================================
// 3. DATABASE CONNECTION
// ==========================================
require_once '../db_connect.php';

// ==========================================
// 4. AUTHENTICATION & ADMIN CHECK
// ==========================================
if (!isset($_SESSION['stu_no']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// UPDATED LOGOUT LOGIC (Matching candidates.php)
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$student_no = $_SESSION['stu_no'];
$active_tab = $_GET['tab'] ?? 'USP'; // USP or SC

// Fetch Admin Details
$firstname = "Admin"; $lastname = "User";
$stmt = $conn->prepare("SELECT firstname, lastname FROM admins WHERE username = ?");
$stmt->bind_param("s", $student_no);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
}
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));

// ==========================================
// 5. HELPER FUNCTIONS
// ==========================================
function generateRandomPassword($length = 8) {
    $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    return substr(str_shuffle(str_repeat($chars, 5)), 0, $length);
}

function sendElectionEmail($toEmail, $studentName, $password, $type = 'new') {
    $mail = new PHPMailer(true); 
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'paulemberga001@gmail.com'; 
        $mail->Password   = 'afcufqqfynbngqyb'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;
        $mail->setFrom('paulemberga001@gmail.com', 'UniBallot Election System'); 
        $mail->addAddress($toEmail, $studentName);
        $mail->isHTML(true);
        $mail->Subject = ($type === 'new') ? 'UniBallot Election: Voter Credentials' : 'UniBallot Election: Password Reset';
        $mail->Body    = "<div style='font-family: Arial, sans-serif; color: #333;'>
                            <h2>" . (($type === 'new') ? "Welcome, $studentName!" : "Password Reset") . "</h2>
                            <p>Your login details are below:</p>
                            <div style='background: #f0f0e6; padding: 15px; border: 1px solid #ccc;'>
                                <p><strong>Username:</strong> $toEmail</p>
                                <p><strong>Password:</strong> <span style='font-size: 18px; font-weight: bold; color: #d4b200;'>$password</span></p>
                            </div>
                         </div>";
        $mail->send();
        return true; 
    } catch (Exception $e) { return "Mailer Error: " . $mail->ErrorInfo; }
}

$message = ''; $message_type = '';

// ==========================================
// 6. POST ACTIONS (ADD, DELETE, RESET)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_voter'])) {
        $v_stu_no = trim($_POST['stu_no']);
        $v_email = trim($_POST['email']);
        
        $chk = $conn->prepare("SELECT lastname, program, department FROM students WHERE stu_no = ?");
        $chk->bind_param("s", $v_stu_no);
        $chk->execute();
        $s_data = $chk->get_result()->fetch_assoc();

        if (!$s_data) {
            $message = "Error: Student ID not found in Master List."; $message_type = "error";
        } else {
            $chk_v = $conn->prepare("SELECT stu_no FROM voters WHERE stu_no = ?");
            $chk_v->bind_param("s", $v_stu_no);
            $chk_v->execute();
            if ($chk_v->get_result()->num_rows > 0) {
                $message = "Error: Student already registered."; $message_type = "error";
            } else {
                $plain_p = generateRandomPassword(8);
                $hashed_p = password_hash($plain_p, PASSWORD_DEFAULT);
                $ins = $conn->prepare("INSERT INTO voters (stu_no, lastname, email, program, department, password, status) VALUES (?, ?, ?, ?, ?, ?, 'not_voted')");
                $ins->bind_param("ssssss", $v_stu_no, $s_data['lastname'], $v_email, $s_data['program'], $s_data['department'], $hashed_p);
                if ($ins->execute()) {
                    sendElectionEmail($v_email, $s_data['lastname'], $plain_p, 'new');
                    $message = "Voter added and credentials emailed!"; $message_type = "success";
                }
            }
        }
    }
    elseif (isset($_POST['delete_voter'])) {
        $del_id = $_POST['delete_stu_no'];
        $conn->query("DELETE FROM votes WHERE stu_no = '$del_id'");
        $conn->query("DELETE FROM voters WHERE stu_no = '$del_id'");
        $message = "Voter removed successfully."; $message_type = "success";
    }
    elseif (isset($_POST['reset_password'])) {
        $res_id = $_POST['reset_stu_no'];
        $v_info = $conn->query("SELECT email, lastname FROM voters WHERE stu_no = '$res_id'")->fetch_assoc();
        $new_plain = generateRandomPassword(8);
        $new_hash = password_hash($new_plain, PASSWORD_DEFAULT);
        $conn->query("UPDATE voters SET password = '$new_hash' WHERE stu_no = '$res_id'");
        sendElectionEmail($v_info['email'], $v_info['lastname'], $new_plain, 'reset');
        $message = "Password reset and emailed to voter!"; $message_type = "success";
    }
}

// ==========================================
// 7. DATA FETCHING
// ==========================================

// Get all departments for the filter
$depts_res = $conn->query("SELECT DISTINCT department FROM students WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departments_list = [];
while($d = $depts_res->fetch_assoc()){ $departments_list[] = $d['department']; }

// Determine which column to check for "Voted" status based on active tab
$voted_col = ($active_tab === 'USP') ? 'prime_minister' : 'sc_president';

// Fetch Voters Joined with Votes to see their status for the SPECIFIC tab
$sql = "SELECT v.*, 
        CASE WHEN vt.$voted_col IS NOT NULL AND vt.$voted_col != '' THEN 'voted' ELSE 'not voted' END as vote_status
        FROM voters v 
        LEFT JOIN votes vt ON v.stu_no = vt.stu_no
        WHERE v.stu_no != 'ORG' 
        ORDER BY v.stu_no ASC";
$voters = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Initial counts (Full list)
$total_registered = count($voters);
$total_voted = 0;
foreach($voters as $v) { if($v['vote_status'] === 'voted') $total_voted++; }
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
        :root { --primary-green: #3b4d3b; --secondary-green: #566b53; --light-green: #7d9679; --card-bg: #c4c2a5; --overview-bg: #aabf9d; --text-dark: #121a1a; --danger: #85211a; --white-soft: #f0f0e6; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-green); color: var(--text-dark); min-height: 100vh; overflow-x: hidden; }

        #mainContent { transition: filter 0.4s ease; min-height: 100vh; }
        #mainContent.blur-active { filter: blur(8px); pointer-events: none; }

        .navbar { background: var(--primary-green); padding: 15px 30px; display: flex; align-items: center; color: white; position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .navbar .material-icons { color: #f2f2f2; margin-right: 15px; font-size: 28px; cursor: pointer; }
        .navbar h1 { font-size: 20px; font-weight: 700; }

        .drawer { height: 100%; width: 280px; position: fixed; z-index: 500; top: 0; left: -300px; background: var(--white-soft); transition: 0.4s; box-shadow: 5px 0 25px rgba(0,0,0,0.3); }
        .drawer.open { left: 0; }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 400; opacity: 0; visibility: hidden; transition: 0.3s; }
        .overlay.active { opacity: 1; visibility: visible; }
        .drawer-header { background: var(--primary-green); padding: 20px; color: white; display: flex; justify-content: space-between; align-items: center; }
        .drawer-profile { padding: 25px 20px; background: #d6d4ba; border-bottom: 1px solid #c9c7ad; display: flex; align-items: center; gap: 15px; }
        .profile-avatar { width: 55px; height: 55px; border-radius: 50%; background: var(--primary-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; border: 2px solid #fff; }
        .admin-badge { background: linear-gradient(135deg, #FFD700, #FDB931); color: #5c4500; padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .drawer-nav { padding: 15px; }
        .nav-item { display: flex; align-items: center; padding: 12px; color: var(--text-dark); text-decoration: none; border-radius: 8px; margin-bottom: 5px; font-weight: 500; cursor: pointer;}
        .nav-item.active { background: var(--primary-green); color: white; }
        .nav-item .material-icons { margin-right: 15px; }

        .container { max-width: 1300px; margin: 30px auto; padding: 0 20px; }
        
        .history-tabs { display: flex; gap: 10px; margin-bottom: 25px; background: rgba(0,0,0,0.1); padding: 6px; border-radius: 40px; }
        .tab-item { flex: 1; padding: 12px; text-align: center; font-weight: 700; cursor: pointer; border-radius: 35px; transition: 0.3s; color: #3b4d3b; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .tab-item.active { background: var(--primary-green); color: white; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: var(--overview-bg); border-radius: 16px; padding: 25px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .summary-number { font-size: 32px; font-weight: 800; }
        .summary-label { font-size: 12px; text-transform: uppercase; font-weight: 700; opacity: 0.8; }

        .admin-controls { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; align-items: center; }
        .search-input, .filter-select { padding: 12px; border-radius: 10px; border: 1px solid #ccc; font-size: 14px; }
        .search-input { flex: 1; min-width: 200px; }

        /* SCROLLABLE TABLE CSS */
        .table-container { 
            background: white; 
            border-radius: 16px; 
            overflow-y: auto; 
            overflow-x: auto; 
            max-height: 500px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1); 
            border: 1px solid #eee;
        }
        .custom-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .custom-table th { 
            background: var(--secondary-green); 
            color: white; 
            padding: 15px; 
            text-align: left; 
            position: sticky; 
            top: 0; 
            z-index: 2; 
        }
        .custom-table td { padding: 15px; border-bottom: 1px solid #eee; font-size: 14px; }
        
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge.voted { background: #d4edda; color: #155724; }
        .badge.not-voted { background: #f8d7da; color: #721c24; }

        .btn-add { background: var(--primary-green); color: white; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .action-btn { background: none; border: none; cursor: pointer; margin-right: 5px; }
        .btn-pass { color: var(--secondary-green); }
        .btn-del { color: var(--danger); }

        /* MODAL STYLES (MATCHING candidates.php) */
        .modal-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.5); backdrop-filter: blur(3px);
            z-index: 1000; display: none; align-items: center; justify-content: center; 
        }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; padding: 30px; border-radius: 20px; width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .modal-header { display: flex; flex-direction: column; align-items: center; text-align: center; }
        .modal-icon-circle { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; font-size: 32px; background-color: #f0f0e6; color: var(--primary-green); }
        .modal-title { font-size: 22px; font-weight: 800; color: var(--text-dark); margin-bottom: 10px; }
        .modal-desc { font-size: 15px; color: #666; line-height: 1.5; margin-bottom: 20px; }
        .modal-actions { display: flex; gap: 15px; justify-content: center; width: 100%; }
        
        .btn-modal { flex: 1; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; border: none; transition: 0.2s; text-transform: uppercase; }
        .btn-modal.cancel { background: #ddd; color: #333; }
        .btn-modal.confirm-success { background: var(--primary-green); color: white; }
        .btn-modal.confirm-danger { background: var(--danger); color: white; }

        .form-group { text-align: left; margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
    </style>
</head>
<body>

    <div id="mainContent">
        <nav class="navbar">
            <span class="material-icons" id="menuBtn">menu</span>
            <h1>MANAGE VOTERS</h1>
        </nav>

        <div class="container">
            <div class="history-tabs">
                <a href="voters.php?tab=USP" class="tab-item <?php echo $active_tab === 'USP' ? 'active' : ''; ?>">
                    <span class="material-icons">account_balance</span> USP Election
                </a>
                <a href="voters.php?tab=SC" class="tab-item <?php echo $active_tab === 'SC' ? 'active' : ''; ?>">
                    <span class="material-icons">groups</span> Student Council
                </a>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-number" id="sumRegistered"><?php echo $total_registered; ?></div>
                    <div class="summary-label">Registered in Selection</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number" id="sumVoted"><?php echo $total_voted; ?></div>
                    <div class="summary-label">Total Voted (<?php echo $active_tab; ?>)</div>
                </div>
            </div>

            <div class="admin-controls">
                <button class="btn-add" onclick="openModal('addVoterModal')">
                    <span class="material-icons">person_add</span> Add Voter
                </button>
                
                <input type="text" id="searchInput" class="search-input" placeholder="Search ID or Name...">
                
                <select id="deptFilter" class="filter-select">
                    <option value="">All Departments</option>
                    <?php foreach($departments_list as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="statusFilter" class="filter-select">
                    <option value="">All Status</option>
                    <option value="voted">Voted</option>
                    <option value="not voted">Not Voted</option>
                </select>
            </div>

            <?php if($message): ?>
                <div style="padding:15px; margin-bottom:20px; border-radius:10px; background:<?php echo $message_type=='success'?'#d4edda':'#f8d7da'; ?>; color:<?php echo $message_type=='success'?'#155724':'#721c24'; ?>;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="table-container">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Dept</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="voterTableBody">
                        <?php foreach($voters as $v): ?>
                        <tr data-dept="<?php echo htmlspecialchars($v['department']); ?>" data-status="<?php echo $v['vote_status']; ?>">
                            <td><strong><?php echo $v['stu_no']; ?></strong></td>
                            <td><?php echo htmlspecialchars($v['lastname']); ?></td>
                            <td><?php echo htmlspecialchars($v['department']); ?></td>
                            <td>
                                <span class="badge <?php echo ($v['vote_status'] === 'voted') ? 'voted' : 'not-voted'; ?>">
                                    <?php echo $v['vote_status']; ?>
                                </span>
                            </td>
                            <td>
                                <button class="action-btn btn-pass" title="Reset" onclick="confirmReset('<?php echo $v['stu_no']; ?>')"><span class="material-icons">lock_reset</span></button>
                                <button class="action-btn btn-del" title="Delete" onclick="confirmDelete('<?php echo $v['stu_no']; ?>')"><span class="material-icons">delete</span></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODALS -->
    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Admin Menu</h2><span class="material-icons" id="closeBtn">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h3>
                <div class="admin-badge">Administrator</div>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item active"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <!-- UPDATED LOGOUT MODAL (MATCHING candidates.php) -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">logout</span></div>
                <h2 class="modal-title">Signing Out?</h2>
                <p class="modal-desc">You are about to end your session. Do you want to continue?</p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="cancelLogout">Cancel</button>
                <button class="btn-modal confirm-success" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="addVoterModal"><div class="modal-box"><h3>Add New Voter</h3><form method="POST" style="margin-top:20px;"><div class="form-group"><label>Student ID</label><input type="text" name="stu_no" required></div><div class="form-group"><label>Email</label><input type="email" name="email" required></div><button type="submit" name="add_voter" class="btn-add" style="width:100%; justify-content:center;">Register</button><button type="button" onclick="closeModal('addVoterModal')" style="width:100%; margin-top:10px; background:#ddd; border:none; padding:10px; border-radius:8px;">Cancel</button></form></div></div>
    <div class="modal-overlay" id="deleteModal"><div class="modal-box"><span class="material-icons" style="font-size:48px; color:var(--danger);">warning</span><h3>Remove Voter?</h3><form method="POST"><input type="hidden" name="delete_stu_no" id="del_stu_no"><button type="submit" name="delete_voter" style="width:100%; background:var(--danger); color:white; border:none; padding:12px; border-radius:8px;">Delete</button><button type="button" onclick="closeModal('deleteModal')" style="width:100%; margin-top:10px; background:#ddd; border:none; padding:10px; border-radius:8px;">Cancel</button></form></div></div>
    <div class="modal-overlay" id="resetModal"><div class="modal-box"><h3>Reset Password?</h3><form method="POST"><input type="hidden" name="reset_stu_no" id="res_stu_no"><button type="submit" name="reset_password" style="width:100%; background:var(--secondary-green); color:white; border:none; padding:12px; border-radius:8px;">Reset & Email</button><button type="button" onclick="closeModal('resetModal')" style="width:100%; margin-top:10px; background:#ddd; border:none; padding:10px; border-radius:8px;">Cancel</button></form></div></div>


     <!-- SCRIPTS -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['tab'])): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>
    
    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        function confirmDelete(id) { document.getElementById('del_stu_no').value = id; openModal('deleteModal'); }
        function confirmReset(id) { document.getElementById('res_stu_no').value = id; openModal('resetModal'); }

        const menuBtn = document.getElementById('menuBtn'), closeBtn = document.getElementById('closeBtn'),
              drawer = document.getElementById('drawer'), overlay = document.getElementById('drawerOverlay'),
              mainContent = document.getElementById('mainContent');

        menuBtn.onclick = () => { drawer.classList.add('open'); overlay.classList.add('active'); mainContent.classList.add('blur-active'); };
        const closeDrawer = () => { drawer.classList.remove('open'); overlay.classList.remove('active'); mainContent.classList.remove('blur-active'); };
        closeBtn.onclick = overlay.onclick = closeDrawer;

        // UPDATED LOGOUT JAVASCRIPT (MATCHING candidates.php)
        document.getElementById('logoutLink').addEventListener('click', (e) => {
            e.preventDefault(); 
            closeDrawer();
            openModal('logoutModal');
        });
        document.getElementById('cancelLogout').addEventListener('click', () => {
            closeModal('logoutModal');
        });
        document.getElementById('confirmLogout').addEventListener('click', () => {
            window.location.href = "?logout=true";
        });

        function filterTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const deptFilter = document.getElementById('deptFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('#voterTableBody tr');

            let visibleRegistered = 0;
            let visibleVoted = 0;

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const rowDept = row.getAttribute('data-dept');
                const rowStatus = row.getAttribute('data-status');

                const matchesSearch = text.includes(searchTerm);
                const matchesDept = !deptFilter || rowDept === deptFilter;
                const matchesStatus = !statusFilter || rowStatus === statusFilter;

                if (matchesSearch && matchesDept) {
                    visibleRegistered++;
                }

                if (matchesSearch && matchesDept && matchesStatus) {
                    row.style.display = '';
                    if (rowStatus === 'voted') {
                        visibleVoted++;
                    }
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('sumRegistered').innerText = visibleRegistered;
            document.getElementById('sumVoted').innerText = visibleVoted;
        }

        document.getElementById('searchInput').oninput = filterTable;
        document.getElementById('deptFilter').onchange = filterTable;
        document.getElementById('statusFilter').onchange = filterTable;
        
        window.onload = filterTable;
    </script>
</body>
</html>