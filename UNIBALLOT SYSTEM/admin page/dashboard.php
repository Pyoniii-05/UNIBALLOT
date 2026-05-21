<?php
session_start();

// Set Timezone if needed (e.g., date_default_timezone_set('Asia/Manila');)

// ==========================================
// 1. AUTHENTICATION & ADMIN CHECK
// ==========================================

// Handle Logout Request
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

// Redirect if not logged in OR not admin
if (!isset($_SESSION['stu_no']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// ==========================================
// 2. DATABASE CONNECTION
// ==========================================
require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];

// Fetch admin info
$firstname = "Admin";
$lastname = "User";
$initials = "AD";

if ($student_no === 'ORG') {
    // Check the admins table
    $sql = "SELECT firstname, lastname FROM admins WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $student_no);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $firstname = $row['firstname'];
        $lastname = $row['lastname'];
    }
} else {
    // Check the students/voters table (fallback if you have other admins)
    $sql = "SELECT firstname, lastname FROM voters WHERE stu_no = ?"; 
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $student_no);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $firstname = $row['firstname'];
        $lastname = $row['lastname'];
    }
}

// Generate initials
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));

// ==========================================
// 3. ELECTION STATUS & OVERRIDE LOGIC
// ==========================================

// Handle Manual Override Toggle (ON/OFF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_override'])) {
    $new_override = $_POST['override_status']; // 'yes' or 'no'
    $conn->query("UPDATE election_info SET manual_override = '$new_override' WHERE id = 1");
    $_SESSION['toast_message'] = "Manual Override turned " . strtoupper($new_override === 'yes' ? 'ON' : 'OFF');
    $_SESSION['toast_type'] = $new_override === 'yes' ? "warning" : "success";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Specific Status Change (Upcoming/Ongoing/Closed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_election_status'])) {
    $new_status = $_POST['selected_status'];
    
    $up_stmt = $conn->prepare("UPDATE election_info SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
    $up_stmt->bind_param("s", $new_status);
    
    if ($up_stmt->execute()) {
        $_SESSION['toast_message'] = "Election status updated to " . strtoupper($new_status);
        $_SESSION['toast_type'] = "success";
    }
    $up_stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch Current Election Info
$election_info = [];
$election_sql = "SELECT * FROM election_info WHERE id = 1";
$election_result = $conn->query($election_sql);

if ($election_result && $election_result->num_rows > 0) {
    $election_info = $election_result->fetch_assoc();
} else {
    $election_info = ['status' => 'upcoming', 'manual_override' => 'no'];
}

$db_status = $election_info['status'];
$is_override_on = ($election_info['manual_override'] === 'yes');

// ==========================================
// 4. FETCH STATISTICS
// ==========================================

// Total Voters
$total_voters = 0;
$res = $conn->query("SELECT COUNT(*) as total FROM voters");
if($res) $total_voters = $res->fetch_assoc()['total'];

// Votes Cast
$total_votes_cast = 0;
$res = $conn->query("SELECT COUNT(DISTINCT stu_no) as total FROM votes");
if($res) $total_votes_cast = $res->fetch_assoc()['total'];

// Turnout
$voter_turnout = $total_voters > 0 ? round(($total_votes_cast / $total_voters) * 100, 1) : 0;

// ==========================================
// 5. FETCH DATA FOR CHARTS (JSON)
// ==========================================

// A. Positions Data
$positions_data = [];
$position_map = [
    'Prime Minister' => 'prime_minister',
    'Executive Prime Minister' => 'executive_prime_minister',
    'Secretary General' => 'secretary_general',
    'Treasurer' => 'treasurer',
    'Auditor' => 'auditor'
];

foreach ($position_map as $pos_title => $col_name) {
    $check = $conn->query("SHOW COLUMNS FROM `votes` LIKE '$col_name'");
    if($check->num_rows == 0) continue;

    $p_sql = "SELECT COUNT(*) as cnt FROM votes WHERE $col_name IS NOT NULL AND $col_name != ''";
    $p_res = $conn->query($p_sql);
    $p_count = $p_res ? $p_res->fetch_assoc()['cnt'] : 0;

    if($p_count > 0) {
        $positions_data[] = ['position' => $pos_title, 'votes' => $p_count];
    }
}

// B. Department Data
$dept_data = [];
$dept_sql = "SELECT s.department, COUNT(DISTINCT v.stu_no) as vote_count 
             FROM votes v 
             JOIN students s ON v.stu_no = s.stu_no 
             GROUP BY s.department 
             ORDER BY vote_count DESC";

// Check if department column exists
$check_dept = $conn->query("SHOW COLUMNS FROM `students` LIKE 'department'");
if ($check_dept && $check_dept->num_rows > 0) {
    $dept_res = $conn->query($dept_sql);
    if ($dept_res) {
        while ($row = $dept_res->fetch_assoc()) {
            $d_name = !empty($row['department']) ? $row['department'] : 'Unassigned';
            $dept_data[] = ['dept' => $d_name, 'count' => $row['vote_count']];
        }
    }
} else {
    $dept_data[] = ['dept' => 'No Data', 'count' => 0];
}

// Pass data to JS
$dashboard_data = [
    'registered' => $total_voters,
    'votes' => $total_votes_cast,
    'not_voted' => $total_voters - $total_votes_cast,
    'status' => $db_status,
    'override' => $is_override_on
];

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Dashboard</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-green); color: var(--text-dark); min-height: 100vh; }

        /* ============================
           NAVBAR & DRAWER
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
           MODAL & BUTTONS
           ============================ */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; opacity: 0; visibility: hidden; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.4); backdrop-filter: blur(3px); transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(18, 26, 26, 0.6); backdrop-filter: blur(5px); z-index: 150; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; }
        .overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #f0f0e6; width: 90%; max-width: 420px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.4); transform: scale(0.9) translateY(20px); opacity: 0; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s ease; overflow: hidden; border: 1px solid rgba(255,255,255,0.5); }
        .modal-overlay.active .modal-box { transform: scale(1) translateY(0); opacity: 1; }
        .modal-header { padding: 30px 20px 10px; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .modal-icon-circle { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; font-size: 32px; }
        .modal-box.warning .modal-icon-circle { background-color: #fde8e8; color: var(--danger); }
        .modal-box.info .modal-icon-circle { background-color: #e3e8e3; color: var(--primary-green); }
        .modal-title { font-size: 22px; font-weight: 800; color: var(--text-dark); margin-bottom: 10px; }
        .modal-desc { font-size: 15px; color: #666; line-height: 1.5; padding: 0 10px; }
        
        .modal-actions { padding: 25px; display: flex; gap: 15px; justify-content: center; }
        
        /* UPDATED BUTTON STYLES WITH HOVER */
        .btn-modal {
            flex: 1; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px;
            cursor: pointer; border: none; transition: all 0.2s; text-transform: uppercase;
        }
        .btn-modal.cancel { background: #d6d4ba; color: #4a574a; }
        .btn-modal.cancel:hover { background: #c4c2a5; color: var(--text-dark); }
        
        .btn-modal.confirm-success { background: var(--primary-green); color: white; }
        .btn-modal.confirm-success:hover { background: var(--secondary-green); transform: translateY(-2px); }
        
        .btn-modal.confirm-danger { background: var(--danger); color: white; }
        .btn-modal.confirm-danger:hover { background: #6b1b15; transform: translateY(-2px); }

        .status-select-container { width: 100%; padding: 0 25px; margin-top: 15px; }
        .status-select { width: 100%; padding: 12px; border-radius: 8px; border: 2px solid #ccc; font-size: 16px; font-weight: 600; color: var(--text-dark); background: #fff; }

        /* ============================
           DASHBOARD CONTENT
           ============================ */
        .container { 
            max-width: 1600px; 
            width: 95%; 
            margin: 30px auto; 
            padding: 20px; 
        }
        
        .dashboard-header { background: var(--overview-bg); border-radius: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); padding: 35px; margin-bottom: 35px; border: 1px solid rgba(0,0,0,0.05); }
        .dashboard-title { font-size: 28px; font-weight: 800; color: var(--text-dark); margin-bottom: 25px; display: flex; align-items: center; gap: 12px; justify-content: space-between; flex-wrap: wrap; }
        
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
        .summary-card { background: #e3e1c8; border-radius: 12px; padding: 25px 20px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.08); transition: transform 0.3s ease; position: relative; overflow: hidden; border: 1px solid #d1cfb8; }
        .summary-card:hover { transform: translateY(-5px); }
        .summary-card::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 5px; background: var(--primary-green); }
        .summary-card.status::after { background: #cc9a06; }
        
        .summary-icon { font-size: 36px; margin-bottom: 12px; color: var(--primary-green); opacity: 0.9; }
        .summary-number { font-size: 32px; font-weight: 800; color: var(--text-dark); }
        .summary-label { font-size: 14px; color: #333; font-weight: 700; text-transform: uppercase; }

        /* Toggle & Override Controls */
        .override-controls { display: flex; align-items: center; gap: 15px; background: rgba(255,255,255,0.3); padding: 10px 20px; border-radius: 30px; }
        .override-label { font-size: 14px; font-weight: 700; color: var(--text-dark); }
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 26px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #555; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--danger); }
        input:checked + .slider:before { transform: translateX(24px); }

        .summary-card.status.editable { cursor: pointer; border: 2px dashed #cc9a06; background: #fffdf5; }
        .summary-card.status.editable:hover { background: #fff; }
        .edit-icon { position: absolute; top: 10px; right: 10px; font-size: 18px; color: #cc9a06; display: none; }
        .summary-card.status.editable .edit-icon { display: block; }
        .summary-card.status.locked { opacity: 0.8; cursor: not-allowed; }

        /* Chart Sections */
        .chart-section { background: var(--secondary-green); border-radius: 16px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15); padding: 30px; margin-bottom: 40px; }
        .chart-title { font-size: 24px; font-weight: 800; color: #f2f2f2; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(255,255,255,0.2); display: flex; align-items: center; gap: 12px; }
        
        .charts-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 25px; }
        
        .chart-card { background: var(--card-bg); border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid rgba(0,0,0,0.05); min-height: 400px; display: flex; flex-direction: column; align-items: center; }
        .chart-header { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 20px; width: 100%; text-align: center; }
        
        .canvas-wrapper { width: 100%; height: 350px; position: relative; }

        .dept-section { margin-top: 30px; }

        /* Toast */
        .custom-toast { position: fixed; top: 30px; right: 30px; background-color: #2b3636; color: #fff; padding: 16px 24px; border-radius: 12px; display: flex; align-items: center; gap: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 9999; transform: translateX(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .custom-toast.show { transform: translateX(0); }
        .custom-toast.success { border-left: 5px solid var(--primary-green); }
        .custom-toast.warning { border-left: 5px solid var(--danger); }

        @media (max-width: 768px) { 
            .container { padding: 15px; width: 100%; } 
            .charts-container { grid-template-columns: 1fr; } 
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>ADMIN - DASHBOARD</h1>
    </nav>

    <!-- DRAWER & OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

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
            <a href="dashboard.php" class="nav-item active"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box info">
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

    <!-- STATUS CHANGE MODAL -->
    <div class="modal-overlay" id="statusModal">
        <div class="modal-box warning">
            <form method="POST">
                <div class="modal-header">
                    <div class="modal-icon-circle"><span class="material-icons">settings_applications</span></div>
                    <h2 class="modal-title">Manual Override</h2>
                    <p class="modal-desc">Select the new election status below. This will bypass automated timers.</p>
                </div>
                
                <div class="status-select-container">
                    <select name="selected_status" class="status-select">
                        <option value="upcoming" <?php echo $db_status == 'upcoming' ? 'selected' : ''; ?>>UPCOMING</option>
                        <option value="ongoing" <?php echo $db_status == 'ongoing' ? 'selected' : ''; ?>>ONGOING</option>
                        <option value="closed" <?php echo $db_status == 'closed' ? 'selected' : ''; ?>>CLOSED</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <input type="hidden" name="update_election_status" value="1">
                    <button type="button" class="btn-modal cancel" id="cancelStatus">Cancel</button>
                    <button type="submit" class="btn-modal confirm-success">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="container">
        
        <!-- Toast Notification & Animation Stopper Logic -->
        <?php 
        $just_updated = false;
        if (isset($_SESSION['toast_message'])): 
            $just_updated = true;
        ?>
            <div id="toastNotification" class="custom-toast show <?php echo $_SESSION['toast_type']; ?>">
                <span class="material-icons"><?php echo $_SESSION['toast_type'] === 'success' ? 'check_circle' : 'warning'; ?></span>
                <span><?php echo htmlspecialchars($_SESSION['toast_message']); ?></span>
            </div>
            <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
        <?php endif; ?>

        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">analytics</span>
                    System Overview
                </div>
                
                <!-- MANUAL OVERRIDE TOGGLE FORM -->
                <form method="POST" id="overrideForm" class="override-controls">
                    <span class="override-label">Manual Override: <?php echo $is_override_on ? 'ON' : 'OFF'; ?></span>
                    <label class="toggle-switch">
                        <input type="hidden" name="toggle_override" value="1">
                        <input type="hidden" name="override_status" id="overrideInput" value="<?php echo $is_override_on ? 'no' : 'yes'; ?>">
                        <input type="checkbox" id="overrideCheckbox" <?php echo $is_override_on ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </form>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="material-icons summary-icon">how_to_vote</div>
                    <div class="summary-number"><?php echo $total_votes_cast; ?></div>
                    <div class="summary-label">Votes Cast</div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">people</div>
                    <div class="summary-number"><?php echo $total_voters; ?></div>
                    <div class="summary-label">Registered Voters</div>
                </div>
                <div class="summary-card info">
                    <div class="material-icons summary-icon">trending_up</div>
                    <div class="summary-number"><?php echo $voter_turnout; ?>%</div>
                    <div class="summary-label">Voter Turnout</div>
                </div>
                
                <!-- Status Card -->
                <div class="summary-card status <?php echo $is_override_on ? 'editable' : 'locked'; ?>" 
                     id="statusCard">
                    <span class="material-icons edit-icon">edit</span>
                    <div class="material-icons summary-icon">schedule</div>
                    <div class="summary-number" style="font-size: 20px; text-transform: uppercase;">
                        <?php echo $db_status; ?>
                    </div>
                    <div class="summary-label">
                        <?php echo $is_override_on ? 'Status (Click to Change)' : 'Status (Auto)'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Visual Analytics Section -->
        <div class="chart-section">
            <h2 class="chart-title">
                <span class="material-icons">pie_chart</span>
                Visual Analytics
            </h2>
            <div class="charts-container">
                <!-- Chart 1: Participation -->
                <div class="chart-card">
                    <div class="chart-header">Voter Participation</div>
                    <div class="canvas-wrapper">
                        <canvas id="participationChart"></canvas>
                    </div>
                </div>
                <!-- Chart 2: Votes by Position -->
                <div class="chart-card">
                    <div class="chart-header">Votes per Position</div>
                    <div class="canvas-wrapper">
                        <canvas id="positionsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Department Analytics -->
            <div class="dept-section">
                <h2 class="chart-title" style="margin-top: 30px; border-top: 1px solid rgba(255,255,255,0.1); padding-top:20px;">
                    <span class="material-icons">domain</span>
                    Votes by Department
                </h2>
                <div class="chart-card" style="width: 100%;">
                    <div class="chart-header">Department Voter Turnout</div>
                    <div class="canvas-wrapper" style="height: 400px;">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Scripts -->
    <?php 
    // Logic to prevent drawer-peek if coming from index.php (login)
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $came_from_login = (stripos($referer, 'index.php') !== false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$just_updated && !$came_from_login): 
    ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>
    
    <script>
        const dashboardData = <?php echo json_encode($dashboard_data); ?>;
        const positionsData = <?php echo json_encode($positions_data); ?>;
        const deptData = <?php echo json_encode($dept_data); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // Drawer & Modal Logic
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');

            document.getElementById('closeIcon').onclick = () => { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); };
            menuIcon.onclick = () => { drawer.classList.add('open'); drawerOverlay.classList.add('active'); };
            drawerOverlay.onclick = () => { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); };

            // Logout
            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "?logout=true";

            // Override Toggle
            const overrideCheckbox = document.getElementById('overrideCheckbox');
            if(overrideCheckbox) {
                overrideCheckbox.addEventListener('change', function() {
                    document.getElementById('overrideForm').submit();
                });
            }

            // Status Change (Only if Override ON)
            const statusCard = document.getElementById('statusCard');
            const statusModal = document.getElementById('statusModal');
            const isOverrideOn = dashboardData.override;

            if (isOverrideOn && statusCard) {
                statusCard.addEventListener('click', () => {
                    statusModal.classList.add('active');
                });
            }
            document.getElementById('cancelStatus').onclick = () => statusModal.classList.remove('active');

            // Toast Auto-dismiss
            const toast = document.getElementById('toastNotification');
            if (toast) {
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 400); 
                }, 4000);
            }

            initCharts();
        });

        function initCharts() {
            const theme = { 
                greenPrimary: '#3b4d3b', 
                greenSecondary: '#566b53', 
                lightGreen: '#7d9679',
                gold: '#d4b200',
                danger: '#85211a',
                white: '#f0f0e6' 
            };

            // 1. Participation (Doughnut)
            new Chart(document.getElementById('participationChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Voted', 'Not Voted'],
                    datasets: [{
                        data: [dashboardData.votes, dashboardData.not_voted],
                        backgroundColor: [theme.greenPrimary, '#6b6b6b'],
                        borderColor: theme.white, borderWidth: 2
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });

            // 2. Positions (PIE CHART) - MODIFIED HERE
            if(positionsData.length > 0) {
                new Chart(document.getElementById('positionsChart'), {
                    type: 'pie',
                    data: {
                        labels: positionsData.map(p => p.position),
                        datasets: [{ 
                            label: 'Votes Cast', 
                            data: positionsData.map(p => p.votes), 
                            backgroundColor: [
                                theme.greenPrimary,
                                theme.gold,
                                theme.greenSecondary,
                                theme.danger,
                                theme.lightGreen,
                                '#2b3636'
                            ],
                            borderColor: theme.white,
                            borderWidth: 2
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { legend: { display: true, position: 'bottom' } }
                    }
                });
            } else {
                document.getElementById('positionsChart').parentElement.innerHTML = '<div style="display:flex;justify-content:center;align-items:center;height:100%;color:#555;">No votes yet.</div>';
            }

            // 3. Department Analytics (Bar)
            if(deptData.length > 0) {
                new Chart(document.getElementById('deptChart'), {
                    type: 'bar',
                    data: {
                        labels: deptData.map(d => d.dept),
                        datasets: [{ label: 'Voters per Dept', data: deptData.map(d => d.count), backgroundColor: theme.greenPrimary, borderColor: theme.greenSecondary, borderWidth: 1, borderRadius: 4 }]
                    },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } } } }
                });
            } else {
                document.getElementById('deptChart').parentElement.innerHTML = '<div style="display:flex;justify-content:center;align-items:center;height:100%;color:#555;">No department data.</div>';
            }
        }
    </script>
</body>
</html>