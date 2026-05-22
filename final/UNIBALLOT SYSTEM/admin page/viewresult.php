<?php
session_start();

// ==========================================
// 1. AUTHENTICATION & DATABASE
// ==========================================
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

if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';
$student_no = $_SESSION['stu_no'];

// AJAX RESET HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_votes') {
    header('Content-Type: application/json');
    if ($conn->query("TRUNCATE TABLE votes") && $conn->query("UPDATE voters SET has_accepted_guidelines = 0")) {
        echo json_encode(['status' => 'success', 'message' => 'All election data has been reset.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to reset data.']);
    }
    exit();
}

// Fetch Admin Info
$firstname = "Admin"; $lastname = "User";
$sql = "SELECT firstname, lastname FROM admins WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
}
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));

// ==========================================
// 3. SELECTION LOGIC (Tabs & Departments)
// ==========================================
$active_tab = $_GET['tab'] ?? 'usp'; 
$selected_dept = $_GET['dept'] ?? '';

$departments = [];
$dept_res = $conn->query("SELECT DISTINCT department FROM students WHERE department IS NOT NULL ORDER BY department ASC");
while($d = $dept_res->fetch_assoc()) { $departments[] = $d['department']; }
if (empty($selected_dept) && !empty($departments)) { $selected_dept = $departments[0]; }

// ==========================================
// 4. POSITION MAPPING & TALLYING
// ==========================================
if ($active_tab === 'usp') {
    $current_title = "USP Parliament Results";
    $position_map = ['Prime Minister' => 'prime_minister', 'Executive Prime Minister' => 'executive_prime_minister', 'Secretary General' => 'secretary_general', 'Treasurer' => 'treasurer', 'Auditor' => 'auditor'];
    $election_type_filter = "USP";
} else {
    $current_title = "Council Results: " . $selected_dept;
    $position_map = ['President' => 'sc_president', 'Vice President' => 'sc_vice_president', 'Secretary' => 'sc_secretary', 'Treasurer' => 'sc_treasurer', 'Auditor' => 'sc_auditor', 'Representative 1' => 'sc_rep1', 'Representative 2' => 'sc_rep2', 'Representative 3' => 'sc_rep3', 'Representative 4' => 'sc_rep4'];
    $election_type_filter = "Student Council";
}

$results_data = [];
if ($active_tab === 'usp') {
    $total_voters_in_view = $conn->query("SELECT COUNT(*) FROM voters")->fetch_row()[0];
    $total_ballots_in_view = $conn->query("SELECT COUNT(*) FROM votes")->fetch_row()[0];
} else {
    $pop_q = $conn->prepare("SELECT COUNT(*) FROM students WHERE department = ?");
    $pop_q->bind_param("s", $selected_dept); $pop_q->execute();
    $total_voters_in_view = $pop_q->get_result()->fetch_row()[0];
    $ballot_q = $conn->prepare("SELECT COUNT(*) FROM votes v JOIN students s ON v.stu_no = s.stu_no WHERE s.department = ?");
    $ballot_q->bind_param("s", $selected_dept); $ballot_q->execute();
    $total_ballots_in_view = $ballot_q->get_result()->fetch_row()[0];
}

foreach ($position_map as $pos_title => $db_col) {
    $candidates = [];
    $sql = "SELECT id, firstname, lastname, party, photo, 
            (SELECT COUNT(*) FROM votes v JOIN students s ON v.stu_no = s.stu_no 
             WHERE v.$db_col = CONCAT(candidates.firstname, ' ', candidates.lastname) " . 
             ($active_tab === 'sc' ? "AND s.department = '$selected_dept'" : "") . ") as vote_count 
            FROM candidates WHERE position = ? AND election_type = ? " . 
            ($active_tab === 'sc' ? "AND department = '$selected_dept'" : "") . " ORDER BY vote_count DESC";

    $stmt_c = $conn->prepare($sql);
    $stmt_c->bind_param("ss", $pos_title, $election_type_filter);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    
    $max_votes = 0;
    while($row = $res_c->fetch_assoc()) {
        if ($row['vote_count'] > $max_votes) $max_votes = $row['vote_count'];
        $row['is_abstain'] = false;
        $candidates[] = $row;
    }

    $abstain_sql = "SELECT COUNT(*) FROM votes v JOIN students s ON v.stu_no = s.stu_no WHERE v.$db_col = 'abstain' " . 
                   ($active_tab === 'sc' ? "AND s.department = '$selected_dept'" : "");
    $ab_count = $conn->query($abstain_sql)->fetch_row()[0];
    
    if ($ab_count > 0 || !empty($candidates)) {
        $candidates[] = ['id' => 'abs', 'firstname' => 'Abstain', 'lastname' => '', 'party' => 'No Selection', 'photo' => '', 'vote_count' => $ab_count, 'is_abstain' => true];
    }
    $results_data[$pos_title] = ['list' => $candidates, 'max' => $max_votes, 'total' => array_sum(array_column($candidates, 'vote_count'))];
}
$voter_turnout = $total_voters_in_view > 0 ? round(($total_ballots_in_view / $total_voters_in_view) * 100, 1) : 0;

// If this is an AJAX call for data, only return the inner parts
if (isset($_GET['ajax'])) {
    renderMainContent($active_tab, $departments, $selected_dept, $current_title, $total_ballots_in_view, $total_voters_in_view, $voter_turnout, $results_data);
    exit();
}

function renderMainContent($active_tab, $departments, $selected_dept, $current_title, $total_ballots_in_view, $total_voters_in_view, $voter_turnout, $results_data) {
?>
    <!-- TABS -->
    <div class="analytics-tabs">
        <button class="tab-btn <?php echo $active_tab === 'usp' ? 'active' : ''; ?>" onclick="loadData('usp', '')"><span class="material-icons">account_balance</span> USP Results</button>
        <button class="tab-btn <?php echo $active_tab === 'sc' ? 'active' : ''; ?>" onclick="loadData('sc', '<?php echo $selected_dept; ?>')"><span class="material-icons">groups</span> Council Results</button>
    </div>

    <?php if($active_tab === 'sc'): ?>
    <div class="filter-container">
        <span class="material-icons">filter_alt</span>
        <label style="font-weight:700;">Select Department:</label>
        <select class="dept-select" onchange="loadData('sc', this.value)">
            <?php foreach($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $selected_dept === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="dashboard-header">
        <div class="dashboard-title"><span class="material-icons">emoji_events</span> <?php echo $current_title; ?></div>
        <div class="summary-grid">
            <div class="summary-card"><div class="summary-number" id="sum-ballots"><?php echo $total_ballots_in_view; ?></div><div class="summary-label">Ballots Cast</div></div>
            <div class="summary-card"><div class="summary-number" id="sum-voters"><?php echo $total_voters_in_view; ?></div><div class="summary-label">Total Voters</div></div>
            <div class="summary-card"><div class="summary-number" id="sum-turnout"><?php echo $voter_turnout; ?>%</div><div class="summary-label">Turnout</div></div>
        </div>

        <div class="admin-controls" style="margin-top: 25px; display: flex; gap: 15px; border-top: 1px solid rgba(0,0,0,0.1); padding-top: 20px;">
            <button class="action-btn btn-reset" onclick="document.getElementById('resetModal').classList.add('active')">
                <span class="material-icons">delete_sweep</span> Reset All Votes
            </button>
            <button class="action-btn btn-print" style="margin-left: auto;" onclick="window.print()">
                <span class="material-icons">print</span> Print Report
            </button>
        </div>
    </div>

    <?php foreach ($results_data as $pos => $data): ?>
        <div class="chart-section" style="background: var(--secondary-green); border-radius: 16px; padding: 25px; margin-bottom: 30px;">
            <h2 style="color: white; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 10px; font-weight: 800; letter-spacing: 0.5px;"><?php echo strtoupper($pos); ?></h2>
            <div class="charts-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                <?php foreach ($data['list'] as $c): 
                    $is_winner = (!$c['is_abstain'] && $c['vote_count'] == $data['max'] && $data['max'] > 0);
                    $percent = $data['total'] > 0 ? round(($c['vote_count'] / $data['total']) * 100, 1) : 0;
                ?>
                    <div class="chart-card <?php echo $is_winner ? 'winner-card' : ''; ?>" style="background: var(--card-bg); padding: 20px; border-radius: 12px; display: block; height: auto;">
                        <?php if($is_winner): ?><div class="winner-badge">WINNER</div><?php endif; ?>
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                            <div style="width: 60px; height: 60px; border-radius: 50%; background: #eee; overflow: hidden; border: 2px solid white;">
                                <?php if($c['is_abstain']): ?><div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #999;"><span class="material-icons">not_interested</span></div>
                                <?php elseif($c['photo']): ?><img src="../assets/candidates/<?php echo $c['photo']; ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                <?php else: ?><div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #999;"><span class="material-icons">person</span></div><?php endif; ?>
                            </div>
                            <div>
                                <div style="font-weight: 800; font-size: 16px; color: var(--primary-green);"><?php echo htmlspecialchars($c['firstname'].' '.$c['lastname']); ?></div>
                                <div style="font-size: 12px; opacity: 0.8; font-weight: 600;"><?php echo htmlspecialchars($c['party']); ?></div>
                            </div>
                        </div>
                        <div class="stat-row" style="display: flex; justify-content: space-between; margin-bottom: 5px; font-weight: 700; font-size: 14px;">
                            <span><?php echo $c['vote_count']; ?> Votes</span><span><?php echo $percent; ?>%</span>
                        </div>
                        <div class="progress-track" style="background: rgba(0,0,0,0.1); height: 10px; border-radius: 10px; overflow: hidden;">
                            <div style="width: <?php echo $percent; ?>%; background: var(--primary-green); height: 100%; transition: 1s;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; 
} ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Election Results</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        :root { --primary-green: #3b4d3b; --secondary-green: #566b53; --light-green: #7d9679; --card-bg: #c4c2a5; --overview-bg: #aabf9d; --danger: #85211a; }
        body { background-color: var(--light-green); font-family: 'Inter', sans-serif; }
        .analytics-tabs { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab-btn { padding: 12px 24px; border: none; border-radius: 30px; background: #c4c2a5; color: var(--primary-green); font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
        .tab-btn.active { background: var(--primary-green); color: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .filter-container { background: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .dept-select { padding: 10px; border-radius: 8px; border: 1px solid #ccc; font-weight: 600; outline: none; flex: 1; max-width: 300px; }
        .winner-card { border: 2px solid var(--primary-green) !important; background: linear-gradient(145deg, #c4c2a5, #d6d4ba) !important; position: relative; }
        .winner-badge { position: absolute; top: -10px; right: -10px; background: var(--primary-green); color: white; padding: 5px 12px; border-radius: 20px; font-size: 10px; font-weight: 800; }
        .action-btn { display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; transition: 0.2s; border: none; }
        .btn-reset { background: white; color: var(--danger); border: 2px solid var(--danger); }
        .btn-reset:hover { background: var(--danger); color: white; }
        .btn-print { background: var(--primary-green); color: white; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; opacity: 0; transition: 0.3s; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-box { background: white; padding: 35px; border-radius: 24px; max-width: 400px; width: 90%; text-align: center; }
        .modal-buttons { display: flex; gap: 12px; }
        .btn-modal-cancel { flex: 1; background: #f3f4f6; color: #4b5563; padding: 14px; border-radius: 12px; border: none; font-weight: 700; cursor: pointer; }
        .btn-modal-confirm { flex: 1; background: var(--danger); color: white; padding: 14px; border-radius: 12px; border: none; font-weight: 700; cursor: pointer; }
        #main-results-area { transition: opacity 0.3s ease; }
        #main-results-area.loading { opacity: 0.5; pointer-events: none; }
        @media print { .navbar, .drawer, .analytics-tabs, .filter-container, .admin-controls, .overlay, .modal-overlay { display: none !important; } }
    </style>
</head>
<body>
    <nav class="navbar"><span class="material-icons" id="menuBtn">menu</span><h1>ADMIN - RESULTS</h1></nav>
    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Admin Menu</h2><span class="material-icons" id="closeBtn">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h3>
                <p>ID: <?php echo $student_no; ?></p>
                <div class="admin-badge">Administrator</div>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item active"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <div class="container" id="main-results-area">
        <?php renderMainContent($active_tab, $departments, $selected_dept, $current_title, $total_ballots_in_view, $total_voters_in_view, $voter_turnout, $results_data); ?>
    </div>

    <!-- MODALS -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-box">
            <div class="modal-icon-circle"><span class="material-icons" style="font-size: 40px; color: var(--danger)">warning</span></div>
            <h3 style="font-size: 22px; font-weight: 800; margin-bottom: 10px;">Reset Election Data?</h3>
            <p style="color: #666; margin-bottom: 25px;">This will <strong>permanently delete</strong> all votes. This cannot be undone.</p>
            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="document.getElementById('resetModal').classList.remove('active')">Cancel</button>
                <button type="button" class="btn-modal-confirm" onclick="confirmReset()">Yes, Reset Everything</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box">
            <div class="modal-icon-circle"><span class="material-icons" style="font-size: 40px; color: var(--primary-green)">logout</span></div>
            <h3 style="font-size: 22px; font-weight: 800; margin-bottom: 10px;">Signing Out?</h3>
            <p style="color: #666; margin-bottom: 25px;">Do you want to end your session?</p>
            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" id="cancelLogout">Cancel</button>
                <button type="button" class="btn-modal-confirm" id="confirmLogout" style="background: var(--primary-green)">Yes, Logout</button>
            </div>
        </div>
    </div>

    <div id="toastNotification" style="position: fixed; bottom: 20px; right: 20px; background: #333; color: #fff; padding: 12px 24px; border-radius: 8px; display: none; z-index: 3000;"></div>


     <!-- SCRIPTS -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>
    
    <script>
        const container = document.getElementById('main-results-area');

        // AJAX DATA LOADER
        async function loadData(tab, dept) {
            container.classList.add('loading');
            const url = `viewresult.php?ajax=1&tab=${tab}&dept=${encodeURIComponent(dept)}`;
            
            try {
                const response = await fetch(url);
                const html = await response.text();
                container.innerHTML = html;
                
                // Update URL without refresh
                const newUrl = `viewresult.php?tab=${tab}&dept=${encodeURIComponent(dept)}`;
                window.history.pushState({ tab, dept }, '', newUrl);
            } catch (err) {
                console.error("Fetch error:", err);
            } finally {
                container.classList.remove('loading');
            }
        }

        // RESET LOGIC
        async function confirmReset() {
            const formData = new FormData();
            formData.append('action', 'reset_votes');
            
            try {
                const response = await fetch('viewresult.php', { method: 'POST', body: formData });
                const res = await response.json();
                
                if (res.status === 'success') {
                    document.getElementById('resetModal').classList.remove('active');
                    showToast(res.message);
                    // Refresh data area
                    const urlParams = new URLSearchParams(window.location.search);
                    loadData(urlParams.get('tab') || 'usp', urlParams.get('dept') || '');
                }
            } catch (err) {
                showToast("Error resetting data.");
            }
        }

        function showToast(msg) {
            const toast = document.getElementById('toastNotification');
            toast.innerText = msg;
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 3000);
        }

        // SIDEBAR & LOGOUT
        document.getElementById('menuBtn').onclick = () => { document.getElementById('drawer').classList.add('open'); document.getElementById('drawerOverlay').classList.add('active'); };
        document.getElementById('closeBtn').onclick = () => { document.getElementById('drawer').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('active'); };
        document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); document.getElementById('logoutModal').classList.add('active'); };
        document.getElementById('cancelLogout').onclick = () => document.getElementById('logoutModal').classList.remove('active');
        document.getElementById('confirmLogout').onclick = () => window.location.href = "?logout=1";

        // Handle browser back/forward buttons
        window.onpopstate = function(e) {
            if (e.state) {
                loadData(e.state.tab, e.state.dept);
            }
        };
    </script>
</body>
</html>