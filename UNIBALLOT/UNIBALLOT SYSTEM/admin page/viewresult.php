<?php
session_start();

// ==========================================
// 1. AUTHENTICATION (ADMIN CHECK REMOVED)
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

// Redirect if not logged in (Basic Session Check Only)
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

// ==========================================
// 2. DATABASE CONNECTION
// ==========================================
require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];

// Fetch user info (Logic matched from Dashboard)
$firstname = "User";
$lastname = "Name";
$initials = "UN";

if ($student_no === 'ORG') {
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
    // Fallback to voters/students table
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
// 3. ACTIONS (RESET VOTES)
// ==========================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_votes'])) {
    $reset_votes_sql = "TRUNCATE TABLE votes";
    $reset_voters_sql = "UPDATE voters SET status = 'not_voted', voted_at = NULL"; 

    if ($conn->query($reset_votes_sql) === TRUE) {
        if ($conn->query($reset_voters_sql) === TRUE) {
            $message = "All votes have been reset and timestamps cleared successfully!";
            $message_type = "success";
        } else {
            $message = "Votes deleted, but error resetting voter status: " . $conn->error;
            $message_type = "warning"; // Use warning class for errors
        }
    } else {
        $message = "Error resetting votes: " . $conn->error;
        $message_type = "warning";
    }
}

// ==========================================
// 4. ELECTION STATUS & STATS
// ==========================================

// Fetch Election Info
$election_info = [];
$election_sql = "SELECT * FROM election_info WHERE id = 1";
$election_result = $conn->query($election_sql);

if ($election_result && $election_result->num_rows > 0) {
    $election_info = $election_result->fetch_assoc();
} else {
    $election_info = ['election_name' => 'Student Election', 'status' => 'upcoming'];
}

$db_status = $election_info['status'];
$election_status = strtoupper($db_status);

// Stats
$total_voters = 0;
$res = $conn->query("SELECT COUNT(*) as total FROM voters");
if($res) $total_voters = $res->fetch_assoc()['total'];

$total_votes_cast = 0;
$res = $conn->query("SELECT COUNT(DISTINCT stu_no) as total FROM votes");
if($res) $total_votes_cast = $res->fetch_assoc()['total'];

$voter_turnout = $total_voters > 0 ? round(($total_votes_cast / $total_voters) * 100, 1) : 0;

// ==========================================
// 5. FETCH RESULTS BY POSITION
// ==========================================
$candidates_by_position = [];
$total_votes_by_position = [];
$position_statistics = [];

$position_map = [
    'Prime Minister'           => 'prime_minister',
    'Executive Prime Minister' => 'executive_prime_minister',
    'Secretary General'        => 'secretary_general',
    'Treasurer'                => 'treasurer',
    'Auditor'                  => 'auditor'
];

foreach ($position_map as $position_title => $db_column) {
    // Check if column exists
    $check_col = $conn->query("SHOW COLUMNS FROM `votes` LIKE '$db_column'");
    if ($check_col->num_rows == 0) continue; 

    // A. FETCH REAL CANDIDATES
    $sql = "SELECT c.id, c.firstname, c.lastname, c.department, c.party, c.photo, 
                   COUNT(v.stu_no) as vote_count 
            FROM candidates c 
            LEFT JOIN votes v ON v.$db_column = CONCAT(c.firstname, ' ', c.lastname) 
            WHERE c.position = ? 
            GROUP BY c.id 
            ORDER BY vote_count DESC, c.lastname ASC";

    $stmt_cand = $conn->prepare($sql);
    $stmt_cand->bind_param("s", $position_title);
    $stmt_cand->execute();
    $result_cand = $stmt_cand->get_result();
    
    $candidates = [];
    $pos_total_votes = 0;
    $max_votes = 0; 

    while ($candidate = $result_cand->fetch_assoc()) {
        $candidates[] = $candidate;
        $pos_total_votes += $candidate['vote_count'];
        
        if ($candidate['vote_count'] > $max_votes) {
            $max_votes = $candidate['vote_count'];
        }
    }
    $stmt_cand->close();

    // B. FETCH ABSTAIN COUNT
    $abstain_sql = "SELECT COUNT(*) as count FROM votes WHERE $db_column = 'Abstain'";
    $abstain_res = $conn->query($abstain_sql);
    $abstain_count = 0;
    if($abstain_res) {
        $abstain_count = $abstain_res->fetch_assoc()['count'];
    }

    // C. ADD ABSTAIN TO LIST
    if ($abstain_count > 0) {
        $pos_total_votes += $abstain_count; 
        
        // Update max_votes if abstain is winning
        if($abstain_count > $max_votes) {
            $max_votes = $abstain_count;
        }

        $candidates[] = [
            'id' => 'ABSTAINED',
            'firstname' => 'Abstain',
            'lastname' => '',
            'department' => '',
            'party' => 'Neutral',
            'photo' => 'abstain_icon', 
            'vote_count' => $abstain_count
        ];
    }
    
    if (!empty($candidates)) {
        $candidates_by_position[$position_title] = $candidates;
        $total_votes_by_position[$position_title] = $pos_total_votes;
        $position_statistics[$position_title] = ['max_votes' => $max_votes];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Election Results</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================
           VARIABLES & RESET (Matched Dashboard)
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

        /* ============================
           DASHBOARD/RESULTS CONTENT
           ============================ */
        .container { max-width: 1600px; width: 95%; margin: 30px auto; padding: 20px; }
        
        .dashboard-header { background: var(--overview-bg); border-radius: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); padding: 35px; margin-bottom: 35px; border: 1px solid rgba(0,0,0,0.05); }
        .dashboard-title { font-size: 28px; font-weight: 800; color: var(--text-dark); margin-bottom: 25px; display: flex; align-items: center; gap: 12px; }
        
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .summary-card { background: #e3e1c8; border-radius: 12px; padding: 25px 20px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.08); transition: transform 0.3s ease; position: relative; overflow: hidden; border: 1px solid #d1cfb8; }
        .summary-card:hover { transform: translateY(-5px); }
        .summary-card::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 5px; background: var(--primary-green); }
        .summary-card.status::after { background: #cc9a06; }
        .summary-icon { font-size: 36px; margin-bottom: 12px; color: var(--primary-green); opacity: 0.9; }
        .summary-number { font-size: 32px; font-weight: 800; color: var(--text-dark); }
        .summary-label { font-size: 14px; color: #333; font-weight: 700; text-transform: uppercase; }

        .admin-controls { margin-top: 30px; display: flex; gap: 15px; border-top: 2px solid rgba(0,0,0,0.1); padding-top: 25px; flex-wrap: wrap; }

        /* ============================
           CANDIDATE / RESULTS SPECIFIC
           ============================ */
        .position-section { background: var(--secondary-green); border-radius: 16px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15); padding: 30px; margin-bottom: 40px; }
        .position-title { font-size: 24px; font-weight: 800; color: #f2f2f2; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid rgba(255,255,255,0.2); display: flex; align-items: center; gap: 12px; }
        
        .candidates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px; }
        .candidate-card { background: var(--card-bg); border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid rgba(0,0,0,0.05); transition: transform 0.2s; }
        .candidate-card:hover { transform: translateY(-3px); }
        .candidate-card.winner { background: linear-gradient(145deg, #abbfab, #c6d9cc); box-shadow: 0 10px 25px rgba(40, 77, 45, 0.25); border: 2px solid rgba(59, 77, 59, 0.4); }
        
        .candidate-header { display: flex; align-items: center; margin-bottom: 20px; }
        .candidate-avatar { width: 70px; height: 70px; border-radius: 50%; background: var(--primary-green); display: flex; align-items: center; justify-content: center; color: white; margin-right: 18px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.2); border: 3px solid #e3e1c8; }
        .candidate-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .candidate-name { font-size: 19px; font-weight: 800; color: var(--text-dark); }
        .vote-results { display: flex; align-items: center; justify-content: space-between; margin-top: 15px; margin-bottom: 8px; }
        .progress-bar { width: 100%; height: 12px; background: rgba(0,0,0,0.2); border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background-color: var(--primary-green); border-radius: 10px; transition: width 1s ease-in-out; }
        .candidate-card.winner .progress-fill { background-color: var(--primary-green); }

        /* Toast */
        .custom-toast { position: fixed; top: 30px; right: 30px; background-color: #2b3636; color: #fff; padding: 16px 24px; border-radius: 12px; display: flex; align-items: center; gap: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 9999; transform: translateX(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .custom-toast.show { transform: translateX(0); }
        .custom-toast.success { border-left: 5px solid var(--primary-green); }
        .custom-toast.warning { border-left: 5px solid var(--danger); }

        /* ============================
           PRINT STYLES
           ============================ */
        @media print {
            @page { size: A4; margin: 1.5cm; }
            body { background-color: white !important; color: black !important; font-family: 'Times New Roman', Georgia, serif !important; font-size: 12pt; line-height: 1.4; min-height: auto; }
            .container { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .navbar, .drawer, #menuIcon, .admin-controls, .overlay, .modal-overlay, #drawerOverlay, .candidate-avatar, .progress-bar, .material-icons, .summary-icon, .summary-card::after, #logoutLink, .summary-card.status { display: none !important; }
            .dashboard-header { background: none !important; box-shadow: none !important; border: none !important; border-bottom: 2px solid black !important; padding: 0 0 20px 0 !important; margin-bottom: 30px !important; border-radius: 0 !important; }
            .dashboard-title { color: black !important; font-size: 24pt !important; justify-content: center; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px !important; }
            .dashboard-header::after { content: "Official Election Report - Generated on <?php echo date('F j, Y, g:i a'); ?>"; display: block; text-align: center; font-size: 10pt; color: #555; font-style: italic; margin-top: 5px; }
            .summary-grid { display: flex !important; justify-content: space-around !important; margin-top: 20px !important; border-top: 1px solid #ccc; padding-top: 15px !important; }
            .summary-card { background: none !important; border: none !important; box-shadow: none !important; padding: 0 !important; text-align: center !important; }
            .summary-number { font-size: 14pt !important; color: black !important; }
            .summary-label { font-size: 10pt !important; color: #333 !important; font-weight: normal !important; }
            .position-section { background: none !important; box-shadow: none !important; padding: 0 !important; margin-bottom: 30px !important; border-radius: 0 !important; page-break-inside: avoid; }
            .position-title { color: black !important; border-bottom: 1px solid black !important; font-size: 16pt !important; padding-bottom: 5px !important; margin-bottom: 15px !important; display: block !important; }
            .candidates-grid { display: block !important; }
            .candidate-card { background: none !important; box-shadow: none !important; border: none !important; border-bottom: 1px dotted #999 !important; padding: 8px 0 !important; border-radius: 0 !important; display: flex !important; justify-content: space-between !important; align-items: center !important; }
            .candidate-card:last-child { border-bottom: none !important; }
            .candidate-header { margin: 0 !important; width: 60%; }
            .candidate-name { font-size: 12pt !important; color: black !important; }
            .vote-results { margin: 0 !important; width: 35%; justify-content: flex-end !important; gap: 20px; font-size: 12pt !important; }
            .candidate-card.winner .candidate-name::after { content: " (WINNER)"; font-weight: bold; font-size: 10pt; }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>ADMIN - ELECTION RESULTS</h1>
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
            <!-- "Back to Voting" REMOVED -->
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item active"><span class="material-icons">bar_chart</span>View Results</a>
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

    <!-- RESET MODAL -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-box warning">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">warning_amber</span></div>
                <h2 class="modal-title">Reset All Votes?</h2>
                <p class="modal-desc"><strong>Warning:</strong> This creates a permanent data loss. Counts will be zeroed and voters reset.</p>
            </div>
            <form method="POST" class="modal-actions" id="resetForm" style="width:100%; margin:0;">
                <button type="button" class="btn-modal cancel" id="cancelReset">Cancel</button>
                <button type="submit" name="reset_votes" class="btn-modal confirm-danger">Yes, Reset Data</button>
            </form>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="container">
        
        <!-- Toast Notification -->
        <?php if ($message): ?>
            <div id="toastNotification" class="custom-toast show <?php echo $message_type; ?>">
                <span class="material-icons"><?php echo $message_type === 'success' ? 'check_circle' : 'warning'; ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Dashboard Header / Summary -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">bar_chart</span>
                    Election Results
                </div>
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
                    <div class="summary-label">Total Voters</div>
                </div>
                <div class="summary-card info">
                    <div class="material-icons summary-icon">trending_up</div>
                    <div class="summary-number"><?php echo $voter_turnout; ?>%</div>
                    <div class="summary-label">Turnout</div>
                </div>
                <div class="summary-card status">
                    <div class="material-icons summary-icon">schedule</div>
                    <div class="summary-number" style="font-size: 20px;"><?php echo $election_status; ?></div>
                    <div class="summary-label">Status</div>
                </div>
            </div>

            <div class="admin-controls">
                <button class="btn-modal confirm-danger" style="flex:0 0 auto; width:auto; padding:12px 20px;" id="resetVotesBtn">
                    <span class="material-icons" style="vertical-align:bottom; margin-right:5px; font-size:18px;">delete_sweep</span> Reset Votes
                </button>
                <button class="btn-modal confirm-success" style="flex:0 0 auto; width:auto; padding:12px 20px; margin-left: auto;" onclick="window.print()">
                    <span class="material-icons" style="vertical-align:bottom; margin-right:5px; font-size:18px;">print</span> Print Report
                </button>
            </div>
        </div>

        <!-- Results Loop -->
        <?php foreach ($candidates_by_position as $position => $candidates): ?>
            <div class="position-section">
                <h2 class="position-title">
                    <span class="material-icons">emoji_events</span>
                    <?php echo htmlspecialchars($position); ?>
                </h2>
                <div class="candidates-grid">
                    <?php 
                    $total_votes = $total_votes_by_position[$position];
                    $max_votes = $position_statistics[$position]['max_votes'];
                    
                    foreach ($candidates as $candidate): 
                        // Logic to determine winner (ignores 0 votes unless that's all there is, checks against max)
                        $is_abstain = ($candidate['id'] === 'ABSTAINED' || $candidate['firstname'] === 'Abstain');
                        $is_winner = ($candidate['vote_count'] == $max_votes && $max_votes > 0 && !$is_abstain);
                        
                        $percentage = $total_votes > 0 ? round(($candidate['vote_count'] / $total_votes) * 100, 1) : 0;
                    ?>
                        <div class="candidate-card <?php echo $is_winner ? 'winner' : ''; ?>">
                            <div class="candidate-header">
                                <div class="candidate-avatar">
                                    <?php if (isset($candidate['photo']) && $candidate['photo'] === 'abstain_icon'): ?>
                                        <span class="material-icons" style="font-size: 30px; color: #fff;">not_interested</span>
                                    <?php elseif (!empty($candidate['photo'])): ?>
                                        <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Cand">
                                    <?php else: ?>
                                        <span class="material-icons">person</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="candidate-name">
                                        <?php echo htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']); ?>
                                        <?php if ($is_winner): ?>
                                            <span class="material-icons" style="color:#2e7d32; font-size:16px; margin-left:5px; vertical-align:middle; display:inline-block;">star</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-style:italic; font-size:14px; color:#444;"><?php echo htmlspecialchars($candidate['party']); ?></div>
                                </div>
                            </div>
                            <div class="vote-results">
                                <strong><?php echo $candidate['vote_count']; ?> votes</strong>
                                <span><?php echo $percentage; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Scripts -->
    <?php 
    // Logic to prevent drawer-peek if coming from login
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $came_from_login = (stripos($referer, 'index.php') !== false);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$came_from_login): 
    ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ---------------------------------------------
            // 1. SCROLL POSITION LOGIC (Prevent jumping to top on reset)
            // ---------------------------------------------
            const scrollPos = localStorage.getItem('votePageScrollPos');
            if (scrollPos) {
                window.scrollTo(0, parseInt(scrollPos));
                localStorage.removeItem('votePageScrollPos');
            }

            const resetForm = document.getElementById('resetForm');
            if (resetForm) {
                resetForm.addEventListener('submit', function() {
                    localStorage.setItem('votePageScrollPos', window.scrollY);
                });
            }

            // ---------------------------------------------
            // 2. DRAWER & MODAL LOGIC
            // ---------------------------------------------
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');

            document.getElementById('closeIcon').onclick = () => { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); };
            menuIcon.onclick = () => { drawer.classList.add('open'); drawerOverlay.classList.add('active'); };
            drawerOverlay.onclick = () => { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); };

            // Logout Modal
            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "?logout=true";

            // Reset Modal
            const resetModal = document.getElementById('resetModal');
            const resetBtn = document.getElementById('resetVotesBtn');
            if(resetBtn) {
                resetBtn.onclick = (e) => { e.preventDefault(); resetModal.classList.add('active'); };
            }
            document.getElementById('cancelReset').onclick = () => resetModal.classList.remove('active');

            // Toast Auto-dismiss
            const toast = document.getElementById('toastNotification');
            if (toast) {
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 400); 
                }, 4000);
            }
        });
    </script>
</body>
</html>