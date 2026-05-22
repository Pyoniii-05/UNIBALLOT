<?php
// votepage.php
session_start();

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['stu_no']) || empty($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit(); 
}

require_once '../db_connect.php';
$student_no = $_SESSION['stu_no'];

// ==========================================
// 1. DATA FETCHING
// ==========================================
$sql = "SELECT s.firstname, s.lastname, s.department FROM students s WHERE s.stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

$firstname = $row['firstname'] ?? 'Student';
$lastname = $row['lastname'] ?? '';
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
$student_department = $row['department'] ?? 'General';

// Helper for status calculation
function getStatus($info) {
    if (!$info) return 'closed';
    if (isset($info['manual_override']) && $info['manual_override'] === 'yes') return $info['status'];
    $now = time();
    $start = !empty($info['voting_start']) ? strtotime($info['voting_start']) : 0;
    $end = !empty($info['voting_end']) ? strtotime($info['voting_end']) : 0;
    if ($now < $start) return 'upcoming';
    if ($now > $end) return 'closed';
    return 'ongoing';
}

$usp_info = $conn->query("SELECT * FROM election_info WHERE id = 1")->fetch_assoc();
$sc_stmt = $conn->prepare("SELECT * FROM dept_settings WHERE department = ?");
$sc_stmt->bind_param("s", $student_department);
$sc_stmt->execute();
$sc_info = $sc_stmt->get_result()->fetch_assoc();
if (!$sc_info) $sc_info = $conn->query("SELECT * FROM election_info WHERE id = 2")->fetch_assoc();

$usp_status = getStatus($usp_info);
$sc_status = getStatus($sc_info);

// Check if already voted
$has_voted_usp = false;
$has_voted_sc = false;
$vote_check = $conn->prepare("SELECT prime_minister, sc_president FROM votes WHERE stu_no = ?");
$vote_check->bind_param("s", $student_no);
$vote_check->execute();
$res = $vote_check->get_result();
while($v = $res->fetch_assoc()){
    if(!empty($v['prime_minister'])) $has_voted_usp = true;
    if(!empty($v['sc_president'])) $has_voted_sc = true;
}

$temp_votes = $_SESSION['temp_votes'] ?? []; 

// ==========================================
// 2. CANDIDATE FETCHING
// ==========================================
$usp_map = ['Prime Minister' => 'prime_minister', 'Executive Prime Minister' => 'executive_prime_minister', 'Secretary General' => 'secretary_general', 'Treasurer' => 'treasurer', 'Auditor' => 'auditor'];
$sc_map = ['President' => 'sc_president', 'Vice President' => 'sc_vice_president', 'Secretary' => 'sc_secretary', 'Treasurer' => 'sc_treasurer', 'Auditor' => 'sc_auditor', 'Representative 1' => 'sc_rep1', 'Representative 2' => 'sc_rep2', 'Representative 3' => 'sc_rep3', 'Representative 4' => 'sc_rep4'];

$candidates = ['USP' => [], 'SC' => []];
foreach ($usp_map as $title => $col) {
    $c_stmt = $conn->prepare("SELECT id, firstname, lastname, party, photo, position, year_level, department, program, message FROM candidates WHERE position = ? AND election_type = 'USP' ORDER BY firstname ASC");
    $c_stmt->bind_param("s", $title); $c_stmt->execute();
    $candidates['USP'][$title] = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
foreach ($sc_map as $title => $col) {
    $c_stmt = $conn->prepare("SELECT id, firstname, lastname, party, photo, position, year_level, department, program, message FROM candidates WHERE position = ? AND election_type = 'Student Council' AND department = ? ORDER BY firstname ASC");
    $c_stmt->bind_param("ss", $title, $student_department); $c_stmt->execute();
    $candidates['SC'][$title] = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Now - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        :root { --primary-green: #3b4d3b; --danger: #85211a; }
        #mainContent { transition: filter 0.4s ease; min-height: 100vh; }
        #mainContent.blur-active { filter: blur(8px); pointer-events: none; }
        .election-tabs { display: flex; background: #fff; margin-bottom: 20px; border-radius: 12px; padding: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); position: sticky; top: 70px; z-index: 10; }
        .tab-btn { flex: 1; padding: 15px; border: none; background: none; font-weight: 700; cursor: pointer; border-radius: 8px; transition: 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; color: #666; }
        .tab-btn.active { background: var(--primary-green); color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        
        .chart-card { border: 2px solid transparent; transition: 0.3s; position: relative; background: #fff; border-radius: 12px; padding: 15px; display: flex; flex-direction: column; align-items: center; }
        .chart-card.selected { background: var(--primary-green) !important; border-color: var(--primary-green); transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
        .chart-card.selected div { color: white !important; }
        .chart-card.selected::after { content: 'check_circle'; font-family: 'Material Icons'; position: absolute; top: 10px; right: 10px; color: white; font-size: 24px; }
        
        .card-actions { display: flex; gap: 8px; width: 100%; margin-top: 15px; }
        .btn-details, .btn-select { flex: 1; padding: 10px 5px; border: none; border-radius: 8px; font-weight: 700; font-size: 11px; cursor: pointer; transition: 0.2s; text-transform: uppercase; }
        .btn-details { background: #f0f0f0; color: #444; }
        .btn-select { background: #e8f5e9; color: #2e7d32; }
        
        .chart-card.selected .btn-details { background: rgba(255,255,255,0.2); color: white; }
        .chart-card.selected .btn-select { background: white; color: var(--primary-green); }

        .submit-wrapper { margin: 40px 0; display: flex; justify-content: center; }
        .submit-main-btn { width: 100%; max-width: 400px; padding: 18px; background: var(--primary-green); color: white; border: none; border-radius: 12px; font-weight: 800; cursor: pointer; }
        .locked-state { text-align: center; padding: 60px 20px; background: rgba(255,255,255,0.5); border-radius: 20px; border: 2px dashed #ccc; margin: 20px 0; }
        .voted-badge { background: #e8f5e9; color: #2e7d32; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }

        #detailsModal .modal-box { max-width: 450px; width: 90%; }
        .details-header { display: flex; gap: 15px; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .details-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; background: #eee; }
        .msg-box { background: #f8f8f8; padding: 15px; border-radius: 10px; font-size: 0.9rem; line-height: 1.5; color: #555; white-space: pre-line; }

        .edit-highlight { animation: highlight-pulse 2s ease-out; }
        @keyframes highlight-pulse { 0% { background-color: #fff9c4; } 100% { background-color: transparent; } }

        .status-change-icon { font-size: 60px; color: #fbc02d; margin-bottom: 10px; }
        .status-change-btn { background: var(--primary-green); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 15px; width: 100%; }

        /* Fix for radio validation visibility */
        .hidden-radio {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
            pointer-events: none;
        }
    </style>
</head>
<body>

    <div id="mainContent">
        <nav class="navbar">
            <span class="material-icons" id="menuIcon" style="cursor:pointer;">menu</span>
            <h1>UniBallot</h1>
        </nav>

        <div class="container">
            <div class="election-tabs">
                <button class="tab-btn active" id="btn-usp" onclick="switchTab('usp-pane')">
                    <span class="material-icons">account_balance</span> USP 
                    <?php if($has_voted_usp): ?><span class="voted-badge">VOTED</span><?php endif; ?>
                </button>
                <button class="tab-btn" id="btn-sc" onclick="switchTab('sc-pane')">
                    <span class="material-icons">groups</span> SC 
                    <?php if($has_voted_sc): ?><span class="voted-badge">VOTED</span><?php endif; ?>
                </button>
            </div>

            <div id="usp-status-header" class="status-pane"><?php renderStatusBanner($has_voted_usp, $usp_status, 'USP'); ?></div>
            <div id="sc-status-header" class="status-pane" style="display:none;"><?php renderStatusBanner($has_voted_sc, $sc_status, 'SC'); ?></div>

            <div class="dashboard-header">
                <div class="dashboard-title" id="current-election-title">USP Parliament Election</div>
                <div class="summary-grid">
                    <div class="summary-card"><div class="material-icons summary-icon">event</div><div class="summary-label">Start</div><div class="summary-value" id="disp-start">...</div></div>
                    <div class="summary-card"><div class="material-icons summary-icon">event_busy</div><div class="summary-label">End</div><div class="summary-value" id="disp-end">...</div></div>
                    <div class="summary-card"><div class="material-icons summary-icon">info</div><div class="summary-label">Status</div><div class="mini-badge" id="disp-status">...</div></div>
                </div>
            </div>

            <!-- USP CONTENT -->
            <div id="usp-pane" class="tab-content active">
                <?php if (!$has_voted_usp && $usp_status === 'ongoing'): ?>
                    <form action="confirmation.php" method="post">
                        <input type="hidden" name="election_type" value="USP">
                        <?php foreach ($usp_map as $title => $db_col): ?>
                            <div class="chart-section" id="<?php echo $db_col; ?>">
                                <h2 class="chart-title"><span class="material-icons">stars</span> <?php echo strtoupper($title); ?></h2>
                                <div class="charts-container"><?php renderCandidates($candidates['USP'][$title], $db_col, $temp_votes); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="submit-wrapper"><button type="submit" class="submit-main-btn">SUBMIT USP BALLOT</button></div>
                    </form>
                <?php else: ?>
                    <div class="locked-state"><span class="material-icons" style="font-size:64px; color:#999;">lock</span><h3>USP Ballot Locked</h3><p><?php echo $has_voted_usp ? "You have already cast your USP vote." : "Voting is not yet open or has already closed."; ?></p></div>
                <?php endif; ?>
            </div>

            <!-- SC CONTENT -->
            <div id="sc-pane" class="tab-content">
                <?php if (!$has_voted_sc && $sc_status === 'ongoing'): ?>
                    <form action="confirmation.php" method="post">
                        <input type="hidden" name="election_type" value="SC">
                        <?php foreach ($sc_map as $title => $db_col): ?>
                            <div class="chart-section" id="<?php echo $db_col; ?>">
                                <h2 class="chart-title"><span class="material-icons">groups</span> <?php echo strtoupper($title); ?></h2>
                                <div class="charts-container"><?php renderCandidates($candidates['SC'][$title], $db_col, $temp_votes); ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="submit-wrapper"><button type="submit" class="submit-main-btn">SUBMIT SC BALLOT</button></div>
                    </form>
                <?php else: ?>
                    <div class="locked-state"><span class="material-icons" style="font-size:64px; color:#999;">lock</span><h3>SC Ballot Locked</h3><p><?php echo $has_voted_sc ? "You have already cast your SC vote." : "Voting is not yet open or has already closed."; ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status Change Modal -->
    <div class="modal-overlay" id="statusChangeModal">
        <div class="modal-box" style="background:white; padding:30px; border-radius:16px; text-align:center; max-width:400px;">
            <span class="material-icons status-change-icon">notification_important</span>
            <h2 id="statusChangeTitle" style="margin-bottom:10px;">Status Updated</h2>
            <p id="statusChangeMsg" style="color:#666; font-size:15px; line-height:1.4;"></p>
            <button class="status-change-btn" onclick="location.reload()">REFRESH BALLOT</button>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-box" style="background:white; padding:25px; border-radius:16px; text-align:left;">
            <div class="details-header">
                <img id="det-img" src="" class="details-img">
                <div>
                    <h3 id="det-name" style="margin:0; color:var(--primary-green);"></h3>
                    <p id="det-party" style="margin:2px 0; font-size:0.85rem; font-weight:600; color:#666;"></p>
                    <p id="det-info" style="margin:2px 0; font-size:0.75rem; color:#999;"></p>
                </div>
            </div>
            <div class="msg-box" id="det-msg"></div>
            <button class="btn-modal" onclick="document.getElementById('detailsModal').classList.remove('active')" style="width:100%; margin-top:20px; background:#eee; padding:12px; border:none; border-radius:8px; font-weight:700; cursor:pointer;">Close</button>
        </div>
    </div>

    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Election Menu</h2><span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info"><h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3><p>ID: <?php echo htmlspecialchars($student_no); ?></p></div>
        </div>
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item active"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="settings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box" style="background:white; padding:30px; border-radius:16px; text-align:center; max-width:350px;">
            <span class="material-icons" style="font-size:50px; color:var(--primary-green)">logout</span>
            <h2 style="margin:10px 0;">Signing Out?</h2>
            <div style="display:flex; gap:10px; margin-top: 20px;">
                <button class="btn-modal" id="cancelLogout" style="flex:1; padding:12px; border-radius:8px; border:none; background:#eee; cursor:pointer; font-weight:700;">Cancel</button>
                <button class="btn-modal" id="confirmLogout" style="flex:1; padding:12px; border-radius:8px; border:none; background:var(--primary-green); color:white; cursor:pointer; font-weight:700;">Logout</button>
            </div>
        </div>
    </div>

    <script>
        let currentUspStatus = "<?php echo strtoupper($usp_status); ?>";
        let currentScStatus = "<?php echo strtoupper($sc_status); ?>";

        let electionData = {
            'usp-pane': { title: "<?php echo htmlspecialchars($usp_info['election_name'] ?? 'USP Election'); ?>", start: "<?php echo !empty($usp_info['voting_start']) ? date('M j, g:i A', strtotime($usp_info['voting_start'])) : 'TBA'; ?>", end: "<?php echo !empty($usp_info['voting_end']) ? date('M j, g:i A', strtotime($usp_info['voting_end'])) : 'TBA'; ?>", status: currentUspStatus },
            'sc-pane': { title: "<?php echo htmlspecialchars($sc_info['election_name'] ?? ($student_department . ' Council')); ?>", start: "<?php echo !empty($sc_info['voting_start']) ? date('M j, g:i A', strtotime($sc_info['voting_start'])) : 'TBA'; ?>", end: "<?php echo !empty($sc_info['voting_end']) ? date('M j, g:i A', strtotime($sc_info['voting_end'])) : 'TBA'; ?>", status: currentScStatus }
        };

        function switchTab(tabId, saveState = true) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabId === 'usp-pane' ? 'btn-usp' : 'btn-sc').classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            document.getElementById('usp-status-header').style.display = (tabId === 'usp-pane') ? 'block' : 'none';
            document.getElementById('sc-status-header').style.display = (tabId === 'sc-pane') ? 'block' : 'none';
            document.getElementById('current-election-title').textContent = electionData[tabId].title;
            document.getElementById('disp-start').textContent = electionData[tabId].start;
            document.getElementById('disp-end').textContent = electionData[tabId].end;
            document.getElementById('disp-status').textContent = electionData[tabId].status;
            if(saveState) localStorage.setItem('voter_active_tab', tabId);
        }

        async function autoUpdateUI() {
            try {
                const res = await fetch('../update_election_status.php');
                const data = await res.json();
                
                let uspChanged = data.usp.toUpperCase() !== currentUspStatus;
                let scChanged = data.sc.toUpperCase() !== currentScStatus;

                if (uspChanged || scChanged || data.status_changed) {
                    let msg = "";
                    if(uspChanged) msg += `USP Election status is now ${data.usp.toUpperCase()}. `;
                    if(scChanged) msg += `SC Election status is now ${data.sc.toUpperCase()}.`;

                    document.getElementById('statusChangeMsg').textContent = msg || "The election timeline has been updated by the administrator.";
                    document.getElementById('statusChangeModal').classList.add('active');
                    
                    const pageResp = await fetch(window.location.href);
                    const html = await pageResp.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    
                    ['usp-status-header', 'sc-status-header', 'usp-pane', 'sc-pane'].forEach(id => {
                        const target = document.getElementById(id);
                        const source = doc.getElementById(id);
                        if(target && source) target.innerHTML = source.innerHTML;
                    });
                    
                    currentUspStatus = data.usp.toUpperCase();
                    currentScStatus = data.sc.toUpperCase();
                    electionData['usp-pane'].status = currentUspStatus;
                    electionData['sc-pane'].status = currentScStatus;
                    
                    const currentTab = document.querySelector('.tab-btn.active').id === 'btn-usp' ? 'usp-pane' : 'sc-pane';
                    switchTab(currentTab, false);
                }
            } catch (e) { console.error("Heartbeat loop failed", e); }
        }

        function selectCard(btnElement) {
            const card = btnElement.closest('.chart-card');
            const container = card.closest('.charts-container');
            container.querySelectorAll('.chart-card').forEach(c => {
                c.classList.remove('selected');
                const sBtn = c.querySelector('.btn-select');
                if(sBtn) sBtn.textContent = 'SELECT';
            });
            card.classList.add('selected');
            btnElement.textContent = 'SELECTED';
            const radio = card.querySelector('input[type="radio"]');
            if(radio) radio.checked = true;
        }

        function showDetails(data) {
            document.getElementById('det-name').textContent = data.firstname + " " + data.lastname;
            document.getElementById('det-party').textContent = data.party;
            document.getElementById('det-info').textContent = `${data.position} | ${data.year_level} | ${data.program}`;
            document.getElementById('det-msg').textContent = data.message || "No platform message provided.";
            document.getElementById('det-img').src = data.photo ? `../assets/candidates/${data.photo}` : '../assets/default-user.png';
            document.getElementById('detailsModal').classList.add('active');
        }

        window.onload = () => {
            const urlParams = new URLSearchParams(window.location.search);
            const editId = urlParams.get('edit');
            if (editId) {
                const targetTab = editId.startsWith('sc_') ? 'sc-pane' : 'usp-pane';
                switchTab(targetTab, false);
                setTimeout(() => {
                    const targetEl = document.getElementById(editId);
                    if (targetEl) {
                        targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        targetEl.classList.add('edit-highlight');
                    }
                }, 200);
            } else {
                switchTab(localStorage.getItem('voter_active_tab') || 'usp-pane', false);
            }
        };

        setInterval(autoUpdateUI, 3000);

        const menuIcon = document.getElementById('menuIcon'), drawer = document.getElementById('drawer'), overlay = document.getElementById('drawerOverlay'), mainContent = document.getElementById('mainContent');
        menuIcon.onclick = () => { drawer.classList.add('open'); overlay.classList.add('active'); mainContent.classList.add('blur-active'); };
        const closeMenu = () => { drawer.classList.remove('open'); overlay.classList.remove('active'); mainContent.classList.remove('blur-active'); };
        document.getElementById('closeIcon').onclick = closeMenu;
        overlay.onclick = closeMenu;
        document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); closeMenu(); document.getElementById('logoutModal').classList.add('active'); };
        document.getElementById('cancelLogout').onclick = () => document.getElementById('logoutModal').classList.remove('active');
        document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";
    </script>
</body>
</html>

<?php
function renderStatusBanner($voted, $status, $type) {
    if ($voted) $style = "background:#e8f5e9; color:#2e7d32;";
    elseif ($status === 'closed') $style = "background:#ffebee; color:#c62828;";
    elseif ($status === 'upcoming') $style = "background:#fff8e1; color:#f57f17;";
    else $style = "background:#e8f5e9; color:#2e7d32;";
    $label = $voted ? "ALREADY VOTED" : strtoupper($type . " " . $status);
    $icon = $voted ? "check_circle" : "info";
    echo "<div class='status-banner' style='$style padding:15px; text-align:center; font-weight:800; border-radius:12px; margin-bottom:15px;'><span class='material-icons' style='vertical-align:middle; margin-right:8px;'>$icon</span>$label</div>";
}

function renderCandidates($list, $inputName, $temp_votes) {
    $saved_id = $temp_votes[$inputName] ?? null;
    foreach ($list as $c) {
        $sel = ($saved_id == $c['id']) ? 'selected' : '';
        $c_json = htmlspecialchars(json_encode($c));
        echo "<div class='chart-card $sel'>";
        echo "<div style='width:70px; height:70px; border-radius:50%; background:#eee; margin-bottom:10px; overflow:hidden;'>";
        if($c['photo']) echo "<img src='../assets/candidates/{$c['photo']}' style='width:100%; height:100%; object-fit:cover;'>";
        else echo "<span class='material-icons' style='font-size:40px; color:#ccc; margin-top:15px;'>person</span>";
        echo "</div>";
        echo "<div style='text-align:center; font-weight:700;'>".htmlspecialchars($c['firstname']." ".$c['lastname'])."</div>";
        echo "<div style='text-align:center; font-size:10px; color:#888;'>".htmlspecialchars($c['party'])."</div>";
        echo "<div class='card-actions'>";
        echo "<button type='button' class='btn-details' onclick='showDetails($c_json)'>Details</button>";
        echo "<button type='button' class='btn-select' onclick='selectCard(this)'>".($sel?'Selected':'Select')."</button>";
        echo "</div>";
        // Fixed: Use hidden-radio class instead of inline display:none
        echo "<input type='radio' name='$inputName' value='{$c['id']}' class='hidden-radio' ".($sel?'checked':'')." required>";
        echo "</div>";
    }
    $abs = ($saved_id === 'abstain') ? 'selected' : '';
    echo "<div class='chart-card $abs' style='background:#f9f9f9;'>";
    echo "<div style='text-align:center; padding:10px;'><span class='material-icons' style='font-size:30px; color:#999;'>block</span><br>Abstain</div>";
    echo "<div class='card-actions'><button type='button' class='btn-select' style='flex:1;' onclick='selectCard(this)'>".($abs?'Selected':'Select')."</button></div>";
    // Fixed: Use hidden-radio class
    echo "<input type='radio' name='$inputName' value='abstain' class='hidden-radio' ".($abs?'checked':'')." required>";
    echo "</div>";
}
?>