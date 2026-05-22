<?php
session_start();

// Initialize variables for drawer-peek logic
$just_updated = false;
if (isset($_SESSION['toast_message'])) {
    $just_updated = true;
    unset($_SESSION['toast_message']);
}

// ==========================================
// 1. AUTHENTICATION & ADMIN CHECK
// ==========================================
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

if (!isset($_SESSION['stu_no']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';
$student_no = $_SESSION['stu_no'];

// ==========================================
// 2. AJAX HANDLER (PER-DEPARTMENT LOGIC)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $type = $_POST['type']; // 'usp' or 'sc'
    $target_id = intval($_POST['election_id'] ?? 0);
    $dept = isset($_POST['dept']) ? $conn->real_escape_string($_POST['dept']) : null;
    
    if (isset($_POST['toggle_override'])) {
        $new_val = $conn->real_escape_string($_POST['override_status']);
        if ($type === 'usp') {
            $conn->query("UPDATE election_info SET manual_override = '$new_val' WHERE id = 1");
        } else {
            $conn->query("INSERT INTO dept_settings (department, manual_override) VALUES ('$dept', '$new_val') 
                          ON DUPLICATE KEY UPDATE manual_override = '$new_val'");
        }
        echo json_encode(['status' => 'success', 'new_val' => $new_val]);
    } 
    elseif (isset($_POST['update_election_status'])) {
        $new_status = $conn->real_escape_string($_POST['selected_status']);
        if ($type === 'usp') {
            $conn->query("UPDATE election_info SET status = '$new_status' WHERE id = 1");
        } else {
            $conn->query("INSERT INTO dept_settings (department, status) VALUES ('$dept', '$new_status') 
                          ON DUPLICATE KEY UPDATE status = '$new_status'");
        }
        echo json_encode(['status' => 'success', 'new_val' => $new_status]);
    }
    exit(); 
}

// ==========================================
// 3. DATA FETCHING (PAGE LOAD)
// ==========================================

// Admin Info
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

// USP Global Settings
$usp_election = $conn->query("SELECT * FROM election_info WHERE id = 1")->fetch_assoc();

// Fetch Per-Department Settings
$dept_configs = [];
$dept_settings_res = $conn->query("SELECT * FROM dept_settings");
while($ds = $dept_settings_res->fetch_assoc()) {
    $dept_configs[$ds['department']] = $ds;
}

// Departments List (From Voters table for consistency)
$departments = [];
$dept_res = $conn->query("SELECT DISTINCT department FROM voters WHERE department IS NOT NULL AND stu_no != 'ORG' ORDER BY department ASC");
while($d = $dept_res->fetch_assoc()) { $departments[] = $d['department']; }

// USP Analytics (Count from voters table)
$total_voters = $conn->query("SELECT COUNT(*) as c FROM voters WHERE stu_no != 'ORG'")->fetch_assoc()['c'];
$usp_votes = $conn->query("SELECT COUNT(*) as c FROM votes v JOIN voters vt ON v.stu_no = vt.stu_no WHERE v.prime_minister IS NOT NULL AND v.prime_minister != ''")->fetch_assoc()['c'];

// SC Analytics
$sc_analytics = [];
foreach ($departments as $dept) {
    // Registered in this department
    $reg = $conn->query("SELECT COUNT(*) as c FROM voters WHERE department = '$dept' AND stu_no != 'ORG'")->fetch_assoc()['c'];
    // Voted in SC for this department
    $voted = $conn->query("SELECT COUNT(*) as c FROM votes v JOIN voters vt ON v.stu_no = vt.stu_no 
                           WHERE vt.department = '$dept' AND v.sc_president IS NOT NULL AND v.sc_president != ''")->fetch_assoc()['c'];
    
    $sc_analytics[$dept] = [
        'voted' => (int)$voted,
        'reg' => (int)$reg,
        'turnout' => $reg > 0 ? round(($voted / $reg) * 100, 1) : 0,
        'status' => $dept_configs[$dept]['status'] ?? 'upcoming',
        'override' => $dept_configs[$dept]['manual_override'] ?? 'no'
    ];
}

$election_settings = [
    'usp' => [
        'id' => 1, 'name' => "USP Election",
        'status' => $usp_election['status'] ?? 'upcoming',
        'override' => $usp_election['manual_override'] ?? 'no',
        'analytics' => [
            'voted' => (int)$usp_votes, 'reg' => (int)$total_voters,
            'turnout' => $total_voters > 0 ? round(($usp_votes / $total_voters) * 100, 1) : 0
        ]
    ],
    'sc' => [
        'id' => 2, 'name' => "Student Council",
        'dept_data' => $sc_analytics
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Dashboard</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary-green: #3b4d3b; --secondary-green: #566b53; --light-green: #7d9679; --card-bg: #c4c2a5; --overview-bg: #aabf9d; --text-dark: #121a1a; --danger: #85211a; --white-soft: #f0f0e6; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-green); color: var(--text-dark); min-height: 100vh; overflow-x: hidden; }

        #mainContent { transition: filter 0.4s ease; min-height: 100vh; }
        #mainContent.blur-active { filter: blur(8px); pointer-events: none; user-select: none; }

        .navbar { background: var(--primary-green); padding: 15px 30px; display: flex; align-items: center; color: white; position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .navbar .material-icons { color: #f2f2f2; margin-right: 15px; font-size: 28px; cursor: pointer; }
        .navbar h1 { font-size: 20px; font-weight: 700; color: #f2f2f2; }

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

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .analytics-tabs { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab-btn { padding: 12px 24px; border: none; border-radius: 30px; background: #c4c2a5; color: var(--primary-green); font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
        .tab-btn.active { background: var(--primary-green); color: white; }

        .dashboard-header { background: var(--overview-bg); border-radius: 16px; padding: 30px; margin-bottom: 30px; box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-top: 20px; }
        .summary-card { background: #e3e1c8; border-radius: 12px; padding: 25px; text-align: center; border: 1px solid #d1cfb8; }
        .summary-number { font-size: 32px; font-weight: 800; }
        .summary-label { font-size: 12px; text-transform: uppercase; font-weight: 700; color: #555; }
        
        .status-card.editable { cursor: pointer; border: 2px dashed var(--danger); background: #fffdf5; transition: 0.2s; }
        .status-card.editable:hover { background: #fff; transform: scale(1.02); }

        .chart-section { background: var(--secondary-green); border-radius: 16px; padding: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; }
        .chart-card { background: var(--card-bg); border-radius: 12px; padding: 20px; height: 400px; display: flex; flex-direction: column; align-items: center; }
        .canvas-wrapper { width: 100%; height: 300px; position: relative; }

        /* TOGGLE */
        .override-controls { display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.3); padding: 10px 20px; border-radius: 30px; font-size: 14px; font-weight: 700; }
        .toggle-switch { position: relative; width: 44px; height: 22px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #888; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background: var(--danger); }
        input:checked + .slider:before { transform: translateX(22px); }

        /* MODAL STYLES (MATCHING candidates.php) */
        .modal-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(0,0,0,0.5); backdrop-filter: blur(3px);
            z-index: 1000; display: none; align-items: center; justify-content: center; 
        }
        .modal-overlay.active { display: flex; }
        .modal-box { 
            background: white; padding: 30px; border-radius: 20px; 
            width: 400px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); 
        }
        .modal-header { display: flex; flex-direction: column; align-items: center; text-align: center; }
        .modal-icon-circle { 
            width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; 
            justify-content: center; margin-bottom: 15px; font-size: 32px; 
            background-color: #f0f0e6; color: var(--primary-green); 
        }
        .modal-title { font-size: 22px; font-weight: 800; color: var(--text-dark); margin-bottom: 10px; }
        .modal-desc { font-size: 15px; color: #666; line-height: 1.5; margin-bottom: 20px; }
        .modal-actions { display: flex; gap: 15px; justify-content: center; width: 100%; }
        
        .btn-modal { 
            flex: 1; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px; 
            cursor: pointer; border: none; transition: 0.2s; text-transform: uppercase; 
        }
        .btn-modal.cancel { background: #ddd; color: #333; }
        .btn-modal.confirm-success { background: var(--primary-green); color: white; }
    </style>
</head>
<body>

    <div id="mainContent">
        <nav class="navbar">
            <span class="material-icons" id="menuBtn">menu</span>
            <h1>ADMIN DASHBOARD</h1>
        </nav>

        <div class="container">
            <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div class="analytics-tabs">
                    <button class="tab-btn active" id="tab-usp" onclick="switchTab('usp')"><span class="material-icons">account_balance</span> USP Election</button>
                    <button class="tab-btn" id="tab-sc" onclick="switchTab('sc')"><span class="material-icons">groups</span> Student Council</button>
                </div>

                <div class="override-controls">
                    <span id="override-label">Manual Override: OFF</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="overrideCheckbox" onchange="handleOverride(this)">
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <div id="scFilter" style="display:none; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 12px; align-items: center; gap: 15px;">
                <span class="material-icons">filter_alt</span>
                <label style="font-weight:700;">Select Department Settings:</label>
                <select id="deptSelector" style="padding:10px; border-radius:8px; border: 1px solid #ccc;" onchange="updateSCDashboard()">
                    <?php foreach($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="dashboard-header">
                <h2 id="header-title">System Overview</h2>
                <div class="summary-grid">
                    <div class="summary-card"><div class="summary-number" id="card-voted">0</div><div class="summary-label">Votes Cast</div></div>
                    <div class="summary-card"><div class="summary-number" id="card-reg">0</div><div class="summary-label">Registered</div></div>
                    <div class="summary-card"><div class="summary-number" id="card-turnout">0%</div><div class="summary-label">Turnout</div></div>
                    <div class="summary-card status-card" id="statusCard">
                        <div class="summary-number" id="card-status-text" style="font-size: 18px;">--</div>
                        <div class="summary-label" id="card-status-label">Status</div>
                    </div>
                </div>
            </div>

            <div class="chart-section">
                <div class="chart-card"><h3>Participation Ratio</h3><div class="canvas-wrapper"><canvas id="pieChart"></canvas></div></div>
                <div class="chart-card"><h3>Comparison Bar</h3><div class="canvas-wrapper"><canvas id="barChart"></canvas></div></div>
            </div>
        </div>
    </div>

    <!-- DRAWER -->
    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Admin Menu</h2><span class="material-icons" id="closeBtn" style="cursor:pointer;">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info">
                <h3 style="font-size:16px;"><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h3>
                <p style="font-size:12px; color:#666;">ID: <?php echo $student_no; ?></p>
                <div class="admin-badge">Administrator</div>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="dashboard.php" class="nav-item active"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
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

    <div class="modal-overlay" id="statusModal">
        <div class="modal-box">
            <h3>Update <span id="modal-election-name">Election</span> Status</h3>
            <select id="modalStatusSelect" style="width:100%; padding:12px; margin: 20px 0; border-radius:8px; border: 1px solid #ccc; font-weight: bold;">
                <option value="upcoming">Upcoming</option>
                <option value="ongoing">Ongoing</option>
                <option value="closed">Closed</option>
            </select>
            <button onclick="handleStatusUpdate()" style="width:100%; background:var(--primary-green); color:white; padding:12px; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Save Status</button>
            <button type="button" style="margin-top:15px; color:#666; cursor:pointer; background:none; border:none;" onclick="document.getElementById('statusModal').classList.remove('active')">Cancel</button>
        </div>
    </div>

    <script>
        const config = <?php echo json_encode($election_settings); ?>;
        let activeType = 'usp';
        let pieChart, barChart;

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
            document.getElementById('logoutModal').classList.add('active');
        });
        document.getElementById('cancelLogout').addEventListener('click', () => {
            document.getElementById('logoutModal').classList.remove('active');
        });
        document.getElementById('confirmLogout').addEventListener('click', () => {
            window.location.href = "?logout=true";
        });

        function switchTab(type) {
            activeType = type;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-' + type).classList.add('active');
            
            if (type === 'sc') {
                document.getElementById('scFilter').style.display = 'flex';
                updateSCDashboard();
            } else {
                document.getElementById('scFilter').style.display = 'none';
                applySettingsToUI(config.usp.status, config.usp.override, config.usp.name);
                updateUI(config.usp.analytics, "USP System Overview");
            }
        }

        function updateSCDashboard() {
            const dept = document.getElementById('deptSelector').value;
            const data = config.sc.dept_data[dept] || {voted: 0, reg: 0, turnout: 0, status: 'upcoming', override: 'no'};
            applySettingsToUI(data.status, data.override, "Council: " + dept);
            updateUI(data, "Council Overview: " + dept);
        }

        function applySettingsToUI(status, override, displayName) {
            const checkbox = document.getElementById('overrideCheckbox');
            const statusCard = document.getElementById('statusCard');
            const overrideLabel = document.getElementById('override-label');

            checkbox.checked = (override === 'yes');
            overrideLabel.innerText = `Manual Override: ${override.toUpperCase()}`;
            document.getElementById('card-status-text').innerText = status.toUpperCase();

            if (override === 'yes') {
                statusCard.classList.add('editable');
                document.getElementById('card-status-label').innerText = "Status (Click to Edit)";
                statusCard.onclick = () => {
                    document.getElementById('modal-election-name').innerText = displayName;
                    document.getElementById('modalStatusSelect').value = status;
                    document.getElementById('statusModal').classList.add('active');
                };
            } else {
                statusCard.classList.remove('editable');
                document.getElementById('card-status-label').innerText = "Status (Auto)";
                statusCard.onclick = null;
            }
        }

        function updateUI(data, title) {
            document.getElementById('header-title').innerText = title;
            document.getElementById('card-voted').innerText = data.voted;
            document.getElementById('card-reg').innerText = data.reg;
            document.getElementById('card-turnout').innerText = data.turnout + "%";
            renderCharts(data);
        }

        async function handleOverride(checkbox) {
            const newVal = checkbox.checked ? 'yes' : 'no';
            const dept = document.getElementById('deptSelector').value;
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('toggle_override', '1');
            formData.append('type', activeType);
            formData.append('override_status', newVal);
            if(activeType === 'sc') formData.append('dept', dept);

            try {
                await fetch('dashboard.php', { method: 'POST', body: formData });
                if(activeType === 'usp') config.usp.override = newVal;
                else if(config.sc.dept_data[dept]) config.sc.dept_data[dept].override = newVal;
                switchTab(activeType); 
            } catch (e) { console.error(e); }
        }

        async function handleStatusUpdate() {
            const newVal = document.getElementById('modalStatusSelect').value;
            const dept = document.getElementById('deptSelector').value;
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('update_election_status', '1');
            formData.append('type', activeType);
            formData.append('selected_status', newVal);
            if(activeType === 'sc') formData.append('dept', dept);

            try {
                await fetch('dashboard.php', { method: 'POST', body: formData });
                if(activeType === 'usp') config.usp.status = newVal;
                else if(config.sc.dept_data[dept]) config.sc.dept_data[dept].status = newVal;
                document.getElementById('statusModal').classList.remove('active');
                switchTab(activeType);
            } catch (e) { console.error(e); }
        }

        function renderCharts(data) {
            const ctx1 = document.getElementById('pieChart').getContext('2d'), ctx2 = document.getElementById('barChart').getContext('2d');
            if (pieChart) pieChart.destroy(); if (barChart) barChart.destroy();

            pieChart = new Chart(ctx1, {
                type: 'doughnut',
                data: { labels: ['Voted', 'Remaining'], datasets: [{ data: [data.voted, Math.max(0, data.reg - data.voted)], backgroundColor: ['#3b4d3b', '#d6d4ba'] }] },
                options: { responsive: true, maintainAspectRatio: false }
            });

            barChart = new Chart(ctx2, {
                type: 'bar',
                data: { labels: ['Registered', 'Actual Votes'], datasets: [{ label: 'Count', data: [data.reg, data.voted], backgroundColor: ['#7d9679', '#3b4d3b'] }] },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        window.onload = () => switchTab('usp');
    </script>
</body>
</html>