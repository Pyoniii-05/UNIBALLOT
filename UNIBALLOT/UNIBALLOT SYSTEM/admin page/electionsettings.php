<?php
session_start();

// ==========================================
// 1. DATABASE CONNECTION
// ==========================================
require_once '../db_connect.php';

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS election_info (
    id INT PRIMARY KEY,
    election_name VARCHAR(255) NOT NULL DEFAULT 'USP Student Election',
    description TEXT,
    voting_start DATETIME,
    voting_end DATETIME,
    status ENUM('upcoming', 'ongoing', 'closed') DEFAULT 'upcoming',
    vote_counting ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    winners_announced ENUM('yes', 'no') DEFAULT 'no',
    eligibility_requirements TEXT,
    voting_guidelines TEXT,
    contact_info TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("INSERT IGNORE INTO election_info (id, election_name, status, vote_counting, winners_announced) 
              VALUES (1, 'USP Student Election 2025', 'upcoming', 'pending', 'no')");

// ==========================================
// 2. AJAX REQUEST HANDLING
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // -- A. HANDLE WINNERS TOGGLE --
    if ($_POST['ajax_action'] === 'toggle_winners') {
        $new_status = $_POST['status']; 
        $stmt = $conn->prepare("UPDATE election_info SET winners_announced = ? WHERE id = 1");
        $stmt->bind_param("s", $new_status);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Visibility updated to ' . strtoupper($new_status)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        exit();
    }

    // -- B. HANDLE MAIN FORM SAVE --
    if ($_POST['ajax_action'] === 'save_settings') {
        $election_name = trim($_POST['election_name']);
        $description = trim($_POST['election_description']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $eligibility = trim($_POST['eligibility_requirements']);
        $guidelines = trim($_POST['voting_guidelines']);
        $contact = trim($_POST['contact_info']);

        if (empty($election_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Election name is required']);
            exit();
        }
        
        $start_ts = strtotime($start_date);
        $end_ts = strtotime($end_date);
        $curr_ts = time();

        if ($end_ts <= $start_ts) {
            echo json_encode(['status' => 'error', 'message' => 'End date must be after start date']);
            exit();
        }

        $new_status = 'upcoming';
        $new_counting = 'pending';

        if ($end_ts > 0 && $curr_ts > $end_ts) {
            $new_status = 'closed';
            $new_counting = 'completed';
        } elseif ($start_ts > 0 && $end_ts > 0 && $curr_ts >= $start_ts && $curr_ts <= $end_ts) {
            $new_status = 'ongoing';
            $new_counting = 'in_progress';
        }

        $sql = "UPDATE election_info SET 
                election_name=?, description=?, voting_start=?, voting_end=?, 
                status=?, vote_counting=?, eligibility_requirements=?, 
                voting_guidelines=?, contact_info=?, updated_at=CURRENT_TIMESTAMP 
                WHERE id = 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssss", 
            $election_name, $description, $start_date, $end_date,
            $new_status, $new_counting, $eligibility, $guidelines, $contact
        );

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Configuration saved successfully!',
                'new_status' => $new_status 
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save settings']);
        }
        exit();
    }
}

// ==========================================
// 3. AUTHENTICATION (STRICT ADMIN ONLY)
// ==========================================

// Handle Logout
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

$student_no = $_SESSION['stu_no'];

// ==========================================
// STRICT CHECK: ONLY LOOK IN 'ADMINS' TABLE
// ==========================================
$firstname = "Admin";
$lastname = "User";
$initials = "AD";

// Query strictly the admins table.
// If the user ID is not found here, they are redirected to login.
$stmt = $conn->prepare("SELECT firstname, lastname FROM admins WHERE username = ?");
$stmt->bind_param("s", $student_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    // ✅ Valid Admin Found
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
    $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
} else {
    // ❌ Not an admin (might be a student trying to access URL directly)
    // Destroy session and kick out
    session_destroy();
    header("Location: ../index.php");
    exit();
}
$stmt->close();


// Fetch Current Settings
$election_info = [];
$load_sql = "SELECT * FROM election_info WHERE id = 1";
$load_result = $conn->query($load_sql);
if ($load_result && $load_result->num_rows > 0) {
    $election_info = $load_result->fetch_assoc();
    if (!empty($election_info['voting_start'])) $election_info['voting_start'] = date('Y-m-d\TH:i', strtotime($election_info['voting_start']));
    if (!empty($election_info['voting_end'])) $election_info['voting_end'] = date('Y-m-d\TH:i', strtotime($election_info['voting_end']));
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Election Settings</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ============================
           VARIABLES & RESET
           ============================ */
        :root {
            --primary-green: #3b4d3b; --secondary-green: #566b53; 
            --light-green: #7d9679; --card-bg: #c4c2a5; 
            --overview-bg: #aabf9d; --text-dark: #121a1a; 
            --danger: #85211a; --gold: #d4b200;
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
           MODAL & OVERLAY
           ============================ */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; opacity: 0; visibility: hidden; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.4); backdrop-filter: blur(3px); transition: all 0.3s ease; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(18, 26, 26, 0.6); backdrop-filter: blur(5px); z-index: 150; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; }
        .overlay.active { opacity: 1; visibility: visible; }
        .modal-box { background: #f0f0e6; width: 90%; max-width: 420px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.4); transform: scale(0.9) translateY(20px); opacity: 0; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s ease; overflow: hidden; border: 1px solid rgba(255,255,255,0.5); }
        .modal-overlay.active .modal-box { transform: scale(1) translateY(0); opacity: 1; }
        .modal-header { padding: 30px 20px 10px; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .modal-icon-circle { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 15px; font-size: 32px; }
        .modal-box.info .modal-icon-circle { background-color: #e3e8e3; color: var(--primary-green); }
        .modal-title { font-size: 22px; font-weight: 800; color: var(--text-dark); margin-bottom: 10px; }
        .modal-desc { font-size: 15px; color: #666; line-height: 1.5; padding: 0 10px; }
        .modal-actions { padding: 25px; display: flex; gap: 15px; justify-content: center; }
        .btn-modal { flex: 1; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; border: none; transition: all 0.2s; text-transform: uppercase; }
        .btn-modal.cancel { background: #d6d4ba; color: #4a574a; }
        .btn-modal.cancel:hover { background: #c4c2a5; color: var(--text-dark); }
        .btn-modal.confirm-success { background: var(--primary-green); color: white; }
        .btn-modal.confirm-success:hover { background: var(--secondary-green); transform: translateY(-2px); }

        /* ============================
           DASHBOARD CONTENT LAYOUT
           ============================ */
        .container { max-width: 1600px; width: 95%; margin: 30px auto; padding: 20px; }
        .dashboard-header { background: var(--overview-bg); border-radius: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); padding: 35px; margin-bottom: 35px; border: 1px solid rgba(0,0,0,0.05); }
        .dashboard-title { font-size: 28px; font-weight: 800; color: var(--text-dark); margin-bottom: 25px; display: flex; align-items: center; gap: 12px; justify-content: space-between; flex-wrap: wrap; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px; }
        
        .summary-card { 
            background: #e3e1c8; border-radius: 12px; padding: 25px 20px; text-align: center; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.08); transition: transform 0.3s ease; position: relative; 
            overflow: hidden; border: 1px solid #d1cfb8; 
        }
        .summary-card:hover { transform: translateY(-5px); }
        .summary-card::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 5px; background: var(--primary-green); }
        .summary-card.info::after { background: var(--gold); }

        .summary-icon { font-size: 36px; margin-bottom: 12px; color: var(--primary-green); opacity: 0.9; }
        .summary-number { font-size: 24px; font-weight: 800; color: var(--text-dark); text-transform: uppercase;}
        .summary-label { font-size: 14px; color: #333; font-weight: 700; text-transform: uppercase; }

        /* ============================
           SETTINGS FORM & TOGGLE
           ============================ */
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 25px; }
        
        .chart-section { background: var(--white-soft); border-radius: 16px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15); padding: 30px; margin-bottom: 0; border: 1px solid #dcdac5; }
        .chart-title { font-size: 20px; font-weight: 800; color: var(--primary-green); margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #e3e1c8; display: flex; align-items: center; gap: 12px; }
        
        /* Publication Specific Card */
        .publication-card { border: 2px solid var(--gold); background: #fffdf5; }
        .publication-card .chart-title { color: #b08d00; border-bottom-color: #ffe680; }

        /* TOGGLE SWITCH STYLES */
        .toggle-container { display: flex; align-items: center; gap: 15px; background: rgba(255,215,0,0.1); padding: 15px; border-radius: 12px; border: 1px solid rgba(212, 178, 0, 0.3); }
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 26px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary-green); }
        input:checked + .slider:before { transform: translateX(24px); }
        .toggle-label { font-weight: 700; font-size: 16px; color: #5c4500; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 700; font-size: 14px; color: #444; }
        
        .form-control { 
            width: 100%; padding: 14px; border: 2px solid #ccc; border-radius: 10px; 
            font-size: 15px; font-weight: 500; background: #fff; transition: border-color 0.2s;
        }
        .form-control:focus { outline: none; border-color: var(--primary-green); }
        textarea.form-control { min-height: 120px; resize: vertical; line-height: 1.5; }

        /* Save Button */
        .save-bar { text-align: center; margin: 40px 0; }
        .btn-submit {
            background-color: var(--primary-green); color: white; padding: 16px 40px; 
            border: none; border-radius: 50px; font-size: 16px; font-weight: 800; cursor: pointer; 
            display: inline-flex; align-items: center; gap: 10px; transition: all 0.2s;
            box-shadow: 0 5px 15px rgba(59, 77, 59, 0.3); letter-spacing: 0.5px;
        }
        .btn-submit:hover { transform: translateY(-3px); background-color: var(--secondary-green); box-shadow: 0 8px 20px rgba(59, 77, 59, 0.4); }

        /* Toast */
        .custom-toast { position: fixed; top: 30px; right: 30px; background-color: #2b3636; color: #fff; padding: 16px 24px; border-radius: 12px; display: flex; align-items: center; gap: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 9999; transform: translateX(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .custom-toast.show { transform: translateX(0); }
        .custom-toast.success { border-left: 5px solid var(--primary-green); }
        .custom-toast.warning { border-left: 5px solid var(--gold); }
        .custom-toast.error { border-left: 5px solid var(--danger); }

        @media (max-width: 768px) { 
            .container { padding: 15px; width: 100%; } 
            .settings-grid { grid-template-columns: 1fr; } 
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>ADMIN - ELECTION SETTINGS</h1>
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
            <!-- Removed Back to Voting Link -->
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item active"><span class="material-icons">settings</span>Settings</a>
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

    <!-- TOAST CONTAINER (Hidden by default) -->
    <div id="toastNotification" class="custom-toast">
        <span class="material-icons" id="toastIcon">check_circle</span>
        <span id="toastMessage">Message goes here</span>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="container">
        
        <!-- Dashboard Header / Overview -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">tune</span>
                    Configuration Overview
                </div>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="material-icons summary-icon">event</div>
                    <div class="summary-number" id="statusDisplay"><?php echo $election_info['status']; ?></div>
                    <div class="summary-label">Current Timeline Status</div>
                </div>
                
                <div class="summary-card info">
                    <div class="material-icons summary-icon" style="color:var(--gold);">visibility</div>
                    <div class="summary-number" id="visibilityDisplay"><?php echo strtoupper($election_info['winners_announced']); ?></div>
                    <div class="summary-label">Public Result Visibility</div>
                </div>
            </div>
        </div>

        <!-- FORM CONTENT -->
        <!-- Note: No action attribute, submission handled by JS -->
        <form id="electionSettingsForm">
            <input type="hidden" name="ajax_action" value="save_settings">
            
            <div class="settings-grid">
                
                <!-- Section 1: General Info -->
                <div class="chart-section">
                    <h2 class="chart-title"><span class="material-icons">description</span> General Information</h2>
                    <div class="form-group">
                        <label>Election Name *</label>
                        <input type="text" name="election_name" class="form-control" value="<?php echo htmlspecialchars($election_info['election_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description / Subtitle</label>
                        <textarea name="election_description" class="form-control"><?php echo htmlspecialchars($election_info['description']); ?></textarea>
                    </div>
                </div>

                <!-- Section 2: Publication Control (INDEPENDENT TOGGLE) -->
                <div class="chart-section publication-card">
                    <h2 class="chart-title"><span class="material-icons">campaign</span> Result Publication Control</h2>
                    <p style="font-size:14px; margin-bottom:15px; color:#856d0f; font-weight:500;">
                        <span class="material-icons" style="font-size:16px; vertical-align:middle;">info</span>
                        This switch is independent. Changes save immediately.
                    </p>
                    
                    <div class="toggle-container">
                        <label class="toggle-switch">
                            <input type="checkbox" id="winnersToggle" <?php echo $election_info['winners_announced'] === 'yes' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span class="toggle-label" id="toggleLabel">
                            <?php echo $election_info['winners_announced'] === 'yes' ? 'Results Visible (YES)' : 'Results Hidden (NO)'; ?>
                        </span>
                    </div>
                </div>

                <!-- Section 3: Schedule -->
                <div class="chart-section">
                    <h2 class="chart-title"><span class="material-icons">schedule</span> Schedule</h2>
                    <div class="form-group">
                        <label>Voting Starts *</label>
                        <input type="datetime-local" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($election_info['voting_start']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Voting Ends *</label>
                        <input type="datetime-local" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($election_info['voting_end']); ?>" required>
                    </div>
                </div>

                <!-- Section 4: Rules -->
                <div class="chart-section">
                    <h2 class="chart-title"><span class="material-icons">gavel</span> Rules & Contact</h2>
                    <div class="form-group">
                        <label>Eligibility Requirements</label>
                        <textarea name="eligibility_requirements" class="form-control" placeholder="E.g. Must be enrolled..." required><?php echo htmlspecialchars($election_info['eligibility_requirements']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Voting Guidelines</label>
                        <textarea name="voting_guidelines" class="form-control" placeholder="E.g. Select one candidate per..." required><?php echo htmlspecialchars($election_info['voting_guidelines']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Contact Info (Footer)</label>
                        <textarea name="contact_info" class="form-control" style="min-height:80px;" required><?php echo htmlspecialchars($election_info['contact_info']); ?></textarea>
                    </div>
                </div>

            </div>

            <!-- SAVE BUTTON -->
            <div class="save-bar">
                <button type="submit" class="btn-submit">
                    <span class="material-icons">save</span> Save Configuration
                </button>
            </div>
        </form>

    </div>

   <!-- Scripts -->
    <?php 
    // Only load the peek script if 'no_peek' is NOT set in the URL
    if (!isset($_GET['no_peek']) || $_GET['no_peek'] != '1') {
        echo '<script src="../drawer-peek.js"></script>';
    }
    ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ========================
            // 1. DRAWER & MODAL LOGIC
            // ========================
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

            // ========================
            // 2. TOAST FUNCTION
            // ========================
            function showToast(message, type = 'success') {
                const toast = document.getElementById('toastNotification');
                const msgSpan = document.getElementById('toastMessage');
                const icon = document.getElementById('toastIcon');

                // Reset classes
                toast.className = 'custom-toast'; 
                toast.classList.add(type);
                
                msgSpan.innerText = message;
                icon.innerText = type === 'success' ? 'check_circle' : (type === 'error' ? 'error' : 'priority_high');

                toast.classList.add('show');
                
                // Clear existing timeout if any (simple implementation)
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 4000);
            }

            // ========================
            // 3. TOGGLE WINNERS LOGIC (AJAX)
            // ========================
            const winnersToggle = document.getElementById('winnersToggle');
            const toggleLabel = document.getElementById('toggleLabel');
            const visibilityDisplay = document.getElementById('visibilityDisplay');

            winnersToggle.addEventListener('change', function() {
                const newVal = this.checked ? 'yes' : 'no';
                const formData = new FormData();
                formData.append('ajax_action', 'toggle_winners');
                formData.append('status', newVal);

                fetch('electionsettings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        // Update UI Texts
                        toggleLabel.innerText = newVal === 'yes' ? 'Results Visible (YES)' : 'Results Hidden (NO)';
                        visibilityDisplay.innerText = newVal.toUpperCase();
                    } else {
                        showToast(data.message, 'error');
                        // Revert toggle if failed
                        this.checked = !this.checked; 
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('Communication Error', 'error');
                    this.checked = !this.checked;
                });
            });

            // ========================
            // 4. MAIN FORM SAVE (AJAX)
            // ========================
            const settingsForm = document.getElementById('electionSettingsForm');
            const statusDisplay = document.getElementById('statusDisplay');

            settingsForm.addEventListener('submit', function(e) {
                e.preventDefault(); // STOP PAGE RELOAD

                const formData = new FormData(this);

                fetch('electionsettings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        // Update Status Card in case dates changed status
                        if(data.new_status) {
                            statusDisplay.innerText = data.new_status;
                        }
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showToast('Communication Error', 'error');
                });
            });
        });
    </script>
</body>
</html>