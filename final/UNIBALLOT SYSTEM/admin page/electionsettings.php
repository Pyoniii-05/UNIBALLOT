<?php
session_start();

// ==========================================
// 1. DATABASE CONNECTION & INITIALIZATION
// ==========================================
require_once '../db_connect.php';

// Ensure tables are updated to support per-department content
$conn->query("CREATE TABLE IF NOT EXISTS election_info (
    id INT PRIMARY KEY,
    election_name VARCHAR(255) NOT NULL DEFAULT 'USP Student Election',
    description TEXT,
    voting_start DATETIME,
    voting_end DATETIME,
    status ENUM('upcoming', 'ongoing', 'closed') DEFAULT 'upcoming',
    manual_override ENUM('yes', 'no') DEFAULT 'no',
    winners_announced ENUM('yes', 'no') DEFAULT 'no',
    eligibility_requirements TEXT,
    voting_guidelines TEXT,
    contact_info TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Updated dept_settings to hold all content
$conn->query("CREATE TABLE IF NOT EXISTS dept_settings (
    department VARCHAR(100) PRIMARY KEY,
    election_name VARCHAR(255),
    description TEXT,
    voting_start DATETIME,
    voting_end DATETIME,
    status ENUM('upcoming', 'ongoing', 'closed') DEFAULT 'upcoming',
    manual_override ENUM('yes', 'no') DEFAULT 'no',
    winners_announced ENUM('yes', 'no') DEFAULT 'no',
    eligibility_requirements TEXT,
    voting_guidelines TEXT,
    contact_info TEXT
)");

$conn->query("INSERT IGNORE INTO election_info (id, election_name, status) VALUES (1, 'USP Student Election', 'upcoming')");
$conn->query("INSERT IGNORE INTO election_info (id, election_name, status) VALUES (2, 'Global Student Council', 'upcoming')");

// ==========================================
// 2. AUTHENTICATION
// ==========================================
if (!isset($_SESSION['stu_no']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: ../index.php");
    exit();
}

$student_no = $_SESSION['stu_no'];
$firstname = "Admin"; $lastname = "User"; $initials = "AD";
$stmt = $conn->prepare("SELECT firstname, lastname FROM admins WHERE username = ?");
$stmt->bind_param("s", $student_no);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $firstname = $row['firstname']; $lastname = $row['lastname'];
    $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
}

// ==========================================
// 3. AJAX ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $active_tab = $_POST['active_tab'];
    $dept = isset($_POST['dept']) ? $conn->real_escape_string($_POST['dept']) : null;

    if ($_POST['ajax_action'] === 'update_logic') {
        $field = $_POST['field']; // manual_override or status
        $val = $conn->real_escape_string($_POST['value']);
        if ($active_tab === 'usp') {
            $conn->query("UPDATE election_info SET $field = '$val' WHERE id = 1");
        } else if ($dept) {
            $conn->query("INSERT INTO dept_settings (department, $field) VALUES ('$dept', '$val') ON DUPLICATE KEY UPDATE $field = '$val'");
        }
        echo json_encode(['status' => 'success', 'message' => 'Logic updated']);
        exit();
    }

    if ($_POST['ajax_action'] === 'toggle_winners') {
        $val = $_POST['status'];
        if ($active_tab === 'usp') {
            $conn->query("UPDATE election_info SET winners_announced = '$val' WHERE id = 1");
        } else if ($dept) {
            $conn->query("INSERT INTO dept_settings (department, winners_announced) VALUES ('$dept', '$val') ON DUPLICATE KEY UPDATE winners_announced = '$val'");
        }
        echo json_encode(['status' => 'success', 'message' => 'Result Visibility updated']);
        exit();
    }

    if ($_POST['ajax_action'] === 'save_settings') {
        $name = $conn->real_escape_string($_POST['election_name']);
        $desc = $conn->real_escape_string($_POST['election_description']);
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        $elig = $conn->real_escape_string($_POST['eligibility_requirements']);
        $guide = $conn->real_escape_string($_POST['voting_guidelines']);
        $contact = $conn->real_escape_string($_POST['contact_info']);

        if ($active_tab === 'usp') {
            $sql = "UPDATE election_info SET election_name='$name', description='$desc', voting_start='$start', voting_end='$end', eligibility_requirements='$elig', voting_guidelines='$guide', contact_info='$contact' WHERE id = 1";
        } else {
            $sql = "INSERT INTO dept_settings (department, election_name, description, voting_start, voting_end, eligibility_requirements, voting_guidelines, contact_info) 
                    VALUES ('$dept', '$name', '$desc', '$start', '$end', '$elig', '$guide', '$contact') 
                    ON DUPLICATE KEY UPDATE election_name='$name', description='$desc', voting_start='$start', voting_end='$end', eligibility_requirements='$elig', voting_guidelines='$guide', contact_info='$contact'";
        }
        
        if ($conn->query($sql)) echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully']);
        else echo json_encode(['status' => 'error', 'message' => $conn->error]);
        exit();
    }
}

// ==========================================
// 4. DATA FETCHING
// ==========================================
$active_tab = $_GET['tab'] ?? 'usp';
$selected_dept = $_GET['dept'] ?? '';

// Departments for dropdown
$departments = [];
$dept_res = $conn->query("SELECT DISTINCT department FROM students WHERE department IS NOT NULL ORDER BY department ASC");
while($d = $dept_res->fetch_assoc()) { $departments[] = $d['department']; }

// Load Data Logic
if ($active_tab === 'usp') {
    $info = $conn->query("SELECT * FROM election_info WHERE id = 1")->fetch_assoc();
} else {
    // If SC, try to get specific department data
    $info = $conn->query("SELECT * FROM dept_settings WHERE department = '$selected_dept'")->fetch_assoc();
    
    // If department has no custom data yet, fallback to the Global SC template (ID 2)
    if (!$info) {
        $info = $conn->query("SELECT * FROM election_info WHERE id = 2")->fetch_assoc();
        $info['winners_announced'] = 'no'; // Default fallback
    }
}

$current_status = $info['status'] ?? 'upcoming';
$current_override = $info['manual_override'] ?? 'no';
$voting_start = !empty($info['voting_start']) ? date('Y-m-d\TH:i', strtotime($info['voting_start'])) : '';
$voting_end = !empty($info['voting_end']) ? date('Y-m-d\TH:i', strtotime($info['voting_end'])) : '';

if (isset($_GET['ajax_load'])) {
    renderSettingsContent($active_tab, $departments, $selected_dept, $info, $current_status, $current_override, $voting_start, $voting_end);
    exit;
}

function renderSettingsContent($active_tab, $departments, $selected_dept, $info, $current_status, $current_override, $voting_start, $voting_end) {
?>
    <div class="analytics-tabs">
        <button class="tab-btn <?php echo $active_tab === 'usp' ? 'active' : ''; ?>" onclick="switchTab('usp')"><span class="material-icons">account_balance</span> USP Settings</button>
        <button class="tab-btn <?php echo $active_tab === 'sc' ? 'active' : ''; ?>" onclick="switchTab('sc', '<?php echo $selected_dept; ?>')"><span class="material-icons">groups</span> Council Settings</button>
    </div>

    <?php if($active_tab === 'sc'): ?>
    <div class="filter-container">
        <span class="material-icons">filter_alt</span>
        <label style="font-weight:700;">Edit Department:</label>
        <select class="dept-select" onchange="switchTab('sc', this.value)">
            <option value="" disabled <?php echo empty($selected_dept) ? 'selected' : ''; ?>>-- Choose Department --</option>
            <?php foreach($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $selected_dept === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="dashboard-header">
        <div style="font-size: 22px; font-weight: 800; display:flex; align-items:center; gap:10px;">
            <span class="material-icons">edit_note</span> 
            <?php echo ($active_tab === 'usp' ? 'USP Global Election' : ($selected_dept ?: 'Select a Department')); ?>
        </div>
        
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Manual Override</div>
                <div class="toggle-container">
                    <label class="toggle-switch">
                        <input type="checkbox" onchange="updateLogic('manual_override', this.checked ? 'yes' : 'no')" <?php echo $current_override === 'yes' ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                    <span style="font-size:14px; font-weight:700;"><?php echo strtoupper($current_override); ?></span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Live Status</div>
                <select class="form-control" onchange="updateLogic('status', this.value)" <?php echo $current_override === 'no' ? 'disabled' : ''; ?>>
                    <option value="upcoming" <?php echo $current_status === 'upcoming' ? 'selected' : ''; ?>>UPCOMING</option>
                    <option value="ongoing" <?php echo $current_status === 'ongoing' ? 'selected' : ''; ?>>ONGOING</option>
                    <option value="closed" <?php echo $current_status === 'closed' ? 'selected' : ''; ?>>CLOSED</option>
                </select>
            </div>

            <div class="summary-card">
                <div class="summary-label">Result Visibility</div>
                <div class="toggle-container">
                    <label class="toggle-switch">
                        <input type="checkbox" onchange="handleWinnerToggle(this)" <?php echo $info['winners_announced'] === 'yes' ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                    <span id="visibilityDisplay" style="font-size:14px; font-weight:700;"><?php echo strtoupper($info['winners_announced']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <form id="electionSettingsForm">
        <input type="hidden" name="ajax_action" value="save_settings">
        <input type="hidden" name="active_tab" value="<?php echo $active_tab; ?>">
        <input type="hidden" name="dept" value="<?php echo $selected_dept; ?>">
        
        <div class="settings-grid">
            <div class="chart-section">
                <h2 class="chart-title"><span class="material-icons">display_settings</span> Display Content</h2>
                <div class="form-group"><label>Election Name (Header)</label><input type="text" name="election_name" class="form-control" value="<?php echo htmlspecialchars($info['election_name'] ?? ''); ?>" required></div>
                <div class="form-group"><label>Sub-Description</label><textarea name="election_description" class="form-control"><?php echo htmlspecialchars($info['description'] ?? ''); ?></textarea></div>
            </div>

            <div class="chart-section">
                <h2 class="chart-title"><span class="material-icons">history_toggle_off</span> Standard Timeline</h2>
                <div class="form-group"><label>Voting Starts</label><input type="datetime-local" name="start_date" class="form-control" value="<?php echo $voting_start; ?>" required></div>
                <div class="form-group"><label>Voting Ends</label><input type="datetime-local" name="end_date" class="form-control" value="<?php echo $voting_end; ?>" required></div>
            </div>

            <div class="chart-section">
                <h2 class="chart-title"><span class="material-icons">info</span> Requirements & Info</h2>
                <div class="form-group"><label>Eligibility</label><textarea name="eligibility_requirements" class="form-control"><?php echo htmlspecialchars($info['eligibility_requirements'] ?? ''); ?></textarea></div>
                <div class="form-group"><label>Voting Guidelines</label><textarea name="voting_guidelines" class="form-control"><?php echo htmlspecialchars($info['voting_guidelines'] ?? ''); ?></textarea></div>
                <div class="form-group"><label>Contact / Footer Info</label><textarea name="contact_info" class="form-control"><?php echo htmlspecialchars($info['contact_info'] ?? ''); ?></textarea></div>
            </div>
        </div>

        <button type="submit" class="btn-submit"><span class="material-icons">save</span> Update Department Settings</button>
    </form>
<?php 
} 
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
        :root { --primary-green: #3b4d3b; --secondary-green: #566b53; --light-green: #7d9679; --card-bg: #c4c2a5; --overview-bg: #aabf9d; --text-dark: #121a1a; --danger: #85211a; --white-soft: #f0f0e6; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-green); color: var(--text-dark); min-height: 100vh; overflow-x: hidden;}
        #mainContent { transition: filter 0.4s ease; min-height: 100vh; }
        #mainContent.blur-active { filter: blur(8px); pointer-events: none; }
        .navbar { background: var(--primary-green); padding: 15px 30px; display: flex; align-items: center; color: white; position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .navbar .material-icons { color: #f2f2f2; margin-right: 15px; font-size: 28px; cursor: pointer; }
        .navbar h1 { font-size: 20px; font-weight: 700; color: #f2f2f2; }
        .drawer { height: 100%; width: 280px; position: fixed; z-index: 500; top: 0; left: -300px; background-color: var(--white-soft); transition: 0.4s; box-shadow: 5px 0 25px rgba(0,0,0,0.3); }
        .drawer.open { left: 0; }
        .drawer-header { background-color: var(--primary-green); padding: 20px; display: flex; justify-content: space-between; align-items: center; color: white; }
        .drawer-profile { padding: 25px 20px; background: #d6d4ba; border-bottom: 1px solid #c9c7ad; display: flex; align-items: center; gap: 15px; }
        .profile-avatar { width: 55px; height: 55px; border-radius: 50%; background: var(--primary-green); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; border: 2px solid #fff; }
        .nav-item { display: flex; align-items: center; padding: 12px 20px; color: var(--text-dark); text-decoration: none; border-radius: 8px; margin: 5px 10px; font-weight: 500; cursor: pointer;}
        .nav-item.active { background: var(--primary-green); color: white; }
        .nav-item .material-icons { margin-right: 15px; }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 400; opacity: 0; visibility: hidden; transition: 0.3s; }
        .overlay.active { opacity: 1; visibility: visible; }
        .analytics-tabs { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab-btn { padding: 12px 24px; border: none; border-radius: 30px; background: #c4c2a5; color: var(--primary-green); font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
        .tab-btn.active { background: var(--primary-green); color: white; }
        .filter-container { background: white; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; }
        .dept-select { padding: 10px; border-radius: 8px; border: 1px solid #ccc; font-weight: 600; flex: 1; max-width: 300px; }
        .container { max-width: 1400px; width: 95%; margin: 30px auto; padding: 0 20px; }
        .dashboard-header { background: var(--overview-bg); border-radius: 16px; padding: 30px; margin-bottom: 30px; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .summary-card { background: #e3e1c8; border-radius: 12px; padding: 20px; border: 1px solid #d1cfb8; }
        .summary-label { font-size: 11px; text-transform: uppercase; font-weight: 700; color: #555; }
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; }
        .chart-section { background: var(--white-soft); border-radius: 16px; padding: 25px; border: 1px solid #dcdac5; }
        .chart-title { font-size: 16px; font-weight: 800; color: var(--primary-green); margin-bottom: 15px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #e3e1c8; padding-bottom: 10px; }
        .toggle-container { display: flex; align-items: center; gap: 10px; padding: 5px 0; }
        .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary-green); }
        input:checked + .slider:before { transform: translateX(20px); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 700; font-size: 13px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; }
        textarea.form-control { min-height: 80px; }
        .btn-submit { background-color: var(--primary-green); color: white; padding: 15px 40px; border: none; border-radius: 30px; font-weight: 800; cursor: pointer; display: block; margin: 30px auto; }
        .custom-toast { position: fixed; top: 30px; right: 30px; background: #2b3636; color: #fff; padding: 16px 24px; border-radius: 12px; display: flex; align-items: center; gap: 12px; z-index: 9999; transform: translateX(150%); transition: 0.4s; }
        .custom-toast.show { transform: translateX(0); }
    </style>
</head>
<body>

    <div id="mainContent">
        <nav class="navbar">
            <span class="material-icons" id="menuIcon">menu</span>
            <h1>ADMIN - ELECTION SETTINGS</h1>
        </nav>

        <div class="container" id="settings-area">
            <?php renderSettingsContent($active_tab, $departments, $selected_dept, $info, $current_status, $current_override, $voting_start, $voting_end); ?>
        </div>
    </div>

    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Admin Menu</h2><span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item active"><span class="material-icons">settings</span>Settings</a>
            <a href="?logout=1" class="nav-item"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <div id="toastNotification" class="custom-toast"><span class="material-icons">check_circle</span> <span id="toastMessage"></span></div>

 <!-- SCRIPTS -->
    <?php 
    // Logic: Only play drawer peek if not a POST request AND the tab hasn't been explicitly selected yet.
    // This prevents the peek animation from triggering when clicking the "USP" or "SC" tab links.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['tab'])): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        const menuIcon = document.getElementById('menuIcon'), drawer = document.getElementById('drawer'), overlay = document.getElementById('drawerOverlay'), mainContent = document.getElementById('mainContent');
        menuIcon.onclick = () => { drawer.classList.add('open'); overlay.classList.add('active'); mainContent.classList.add('blur-active'); };
        const closeSidebar = () => { drawer.classList.remove('open'); overlay.classList.remove('active'); mainContent.classList.remove('blur-active'); };
        document.getElementById('closeIcon').onclick = overlay.onclick = closeSidebar;

        async function switchTab(tab, dept = '') {
            const area = document.getElementById('settings-area');
            area.style.opacity = '0.5';
            const url = `electionsettings.php?ajax_load=1&tab=${tab}&dept=${encodeURIComponent(dept)}`;
            try {
                const response = await fetch(url);
                area.innerHTML = await response.text();
                window.history.pushState({tab, dept}, '', `electionsettings.php?tab=${tab}&dept=${encodeURIComponent(dept)}`);
                bindForm();
            } catch (e) { console.error(e); }
            area.style.opacity = '1';
        }

        function updateLogic(field, val) {
            const params = new URLSearchParams(window.location.search);
            const formData = new FormData();
            formData.append('ajax_action', 'update_logic');
            formData.append('active_tab', params.get('tab') || 'usp');
            formData.append('dept', params.get('dept') || '');
            formData.append('field', field);
            formData.append('value', val);

            fetch('electionsettings.php', { method: 'POST', body: formData })
                .then(r => r.json()).then(data => {
                    showToast(data.message);
                    switchTab(params.get('tab') || 'usp', params.get('dept') || '');
                });
        }

        function handleWinnerToggle(checkbox) {
            const params = new URLSearchParams(window.location.search);
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_winners');
            formData.append('active_tab', params.get('tab') || 'usp');
            formData.append('dept', params.get('dept') || '');
            formData.append('status', checkbox.checked ? 'yes' : 'no');

            fetch('electionsettings.php', { method: 'POST', body: formData })
                .then(r => r.json()).then(data => {
                    showToast(data.message);
                    document.getElementById('visibilityDisplay').innerText = (checkbox.checked ? 'YES' : 'NO');
                });
        }

        function bindForm() {
            const form = document.getElementById('electionSettingsForm');
            if(!form) return;
            form.onsubmit = function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                fetch('electionsettings.php', { method: 'POST', body: formData })
                    .then(r => r.json()).then(data => showToast(data.message));
            };
        }

        function showToast(msg) {
            const toast = document.getElementById('toastNotification');
            document.getElementById('toastMessage').innerText = msg;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        window.onload = bindForm;
        window.onpopstate = (e) => { if(e.state) switchTab(e.state.tab, e.state.dept); };
    </script>
</body>
</html>