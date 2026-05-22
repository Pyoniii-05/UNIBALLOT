<?php
// electioninfo.php
date_default_timezone_set('Asia/Manila'); 
session_start();

// --- SECURITY CHECK: VOTERS ONLY ---
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';

// Get logged-in user info
$student_no = $_SESSION['stu_no'];
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

$sql = "SELECT firstname, lastname, department FROM students WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$firstname = $user['firstname'] ?? 'Student';
$lastname = $user['lastname'] ?? 'User';
$user_dept = $user['department'] ?? ''; 
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
$stmt->close();

// ========== DETERMINE IF AJAX REQUEST ==========
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ========== GET ELECTION INFORMATION BASED ON TAB & DEPT ==========
$active_tab = $_GET['tab'] ?? 'USP'; 
$election_info = null;

if ($active_tab === 'USP') {
    // Fetch USP Global Settings (ID 1)
    $info_stmt = $conn->prepare("SELECT * FROM election_info WHERE id = 1");
    $info_stmt->execute();
    $election_info = $info_stmt->get_result()->fetch_assoc();
} else {
    // Fetch Department Specific Settings
    $info_stmt = $conn->prepare("SELECT * FROM dept_settings WHERE department = ?");
    $info_stmt->bind_param("s", $user_dept);
    $info_stmt->execute();
    $election_info = $info_stmt->get_result()->fetch_assoc();

    // Fallback to Global SC template (ID 2) if department settings don't exist
    if (!$election_info) {
        $fallback_stmt = $conn->prepare("SELECT * FROM election_info WHERE id = 2");
        $fallback_stmt->execute();
        $election_info = $fallback_stmt->get_result()->fetch_assoc();
    }
}

// ========== CALCULATE STATUS (LOGIC FROM ADMIN SETTINGS) ==========
$current_timestamp = time();
$start_ts = strtotime($election_info['voting_start'] ?? '');
$end_ts = strtotime($election_info['voting_end'] ?? '');

if (($election_info['manual_override'] ?? 'no') === 'yes') {
    $db_status = $election_info['status'];
} else {
    if ($current_timestamp < $start_ts) $db_status = 'upcoming';
    elseif ($current_timestamp > $end_ts) $db_status = 'closed';
    else $db_status = 'ongoing';
}

// --- Fetch Candidates based on context ---
$candidates_by_group = []; 
if ($active_tab === 'USP') {
    $positions = ['Prime Minister', 'Executive Prime Minister', 'Secretary General', 'Treasurer', 'Auditor'];
    foreach ($positions as $pos) {
        $c_stmt = $conn->prepare("SELECT * FROM candidates WHERE position = ? AND election_type = 'USP' ORDER BY firstname, lastname");
        $c_stmt->bind_param("s", $pos);
        $c_stmt->execute();
        $res = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if(!empty($res)) $candidates_by_group[$pos] = $res;
    }
} else {
    $sc_positions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'Representative 1', 'Representative 2', 'Representative 3', 'Representative 4'];
    if (!empty($user_dept)) {
        foreach ($sc_positions as $pos) {
            $c_stmt = $conn->prepare("SELECT * FROM candidates WHERE position = ? AND election_type = 'Student Council' AND department = ? ORDER BY firstname, lastname");
            $c_stmt->bind_param("ss", $pos, $user_dept);
            $c_stmt->execute();
            $results = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!empty($results)) $candidates_by_group[$pos] = $results;
        }
    }
}

if ($is_ajax) {
    renderMainContent($db_status, $election_info, $active_tab, $is_admin, $candidates_by_group, $user_dept);
    exit;
}

function renderMainContent($db_status, $election_info, $active_tab, $is_admin, $candidates_by_group, $user_dept) {
    ?>
    <div class="status-banner <?php echo $db_status; ?>">
        <span class="material-icons">
            <?php 
            if($db_status == 'ongoing') echo 'play_circle';
            elseif($db_status == 'closed') echo 'lock';
            else echo 'schedule';
            ?>
        </span> 
        ELECTION STATUS: <?php echo strtoupper($db_status); ?>
    </div>

    <div class="dashboard-header">
        <div class="summary-grid">
            <div class="summary-card">
                <div class="material-icons summary-icon">campaign</div>
                <div class="summary-label">Election Name</div>
                <div class="summary-value" style="font-size: 14px;"><?php echo htmlspecialchars($election_info['election_name'] ?? 'Election'); ?></div>
            </div>
            <div class="summary-card">
                <div class="material-icons summary-icon">event_available</div>
                <div class="summary-label">Voting Starts</div>
                <div class="summary-value"><?php echo $election_info['voting_start'] ? date('M j, g:i A', strtotime($election_info['voting_start'])) : 'N/A'; ?></div>
            </div>
            <div class="summary-card">
                <div class="material-icons summary-icon">event_busy</div>
                <div class="summary-label">Voting Ends</div>
                <div class="summary-value"><?php echo $election_info['voting_end'] ? date('M j, g:i A', strtotime($election_info['voting_end'])) : 'N/A'; ?></div>
            </div>
        </div>
        <?php if ($is_admin): ?>
            <a href="../admin page/electionsettings.php?tab=<?php echo strtolower($active_tab); ?>&dept=<?php echo urlencode($user_dept); ?>" class="admin-edit-link">
                <span class="material-icons">edit</span> Edit Election Details
            </a>
        <?php endif; ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="history-section" style="margin-top: 0;">
            <h2 class="section-title"><span class="material-icons">gavel</span> Guidelines</h2>
            <div class="info-text-content">
                <?php echo nl2br(htmlspecialchars($election_info['voting_guidelines'] ?? 'No guidelines provided.')); ?>
            </div>
        </div>
        <div class="history-section" style="margin-top: 0;">
            <h2 class="section-title"><span class="material-icons">verified</span> Eligibility</h2>
            <div class="info-text-content">
                <?php echo nl2br(htmlspecialchars($election_info['eligibility_requirements'] ?? 'No eligibility requirements listed.')); ?>
            </div>
        </div>
    </div>

    <div class="history-section">
        <h2 class="section-title">
            <span class="material-icons">people</span> 
            <?php echo ($active_tab === 'SC') ? "Candidates for $user_dept" : "Registered Candidates"; ?>
        </h2>
        
        <?php if (empty($candidates_by_group)): ?>
            <div style="text-align: center; padding: 40px; color: #ccc;">
                <span class="material-icons" style="font-size: 48px;">person_off</span>
                <p>No candidates registered yet.</p>
            </div>
        <?php endif; ?>

        <?php foreach ($candidates_by_group as $pos => $list): ?>
            <div class="pos-sub-title"><?php echo $pos; ?></div>
            <div class="charts-container">
                <?php foreach ($list as $cand): ?>
                    <div class="history-card">
                        <div class="h-img-circle">
                            <?php if ($cand['photo']): ?>
                                <img src="../assets/candidates/<?php echo htmlspecialchars($cand['photo']); ?>" alt="">
                            <?php else: ?>
                                <span class="material-icons" style="font-size: 45px; color:#ccc;">person</span>
                            <?php endif; ?>
                        </div>
                        <div class="h-info">
                            <div class="h-name"><?php echo htmlspecialchars($cand['firstname'].' '.$cand['lastname']); ?></div>
                            <div class="h-party"><?php echo htmlspecialchars($cand['party']); ?></div>
                            <div class="h-badge" style="background: #f0f0f0; color: #666;">
                                <span class="material-icons" style="font-size:14px; margin-right:4px;">school</span>
                                <?php echo htmlspecialchars($cand['department']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Info - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        .history-tabs { display: flex; gap: 10px; margin-bottom: 25px; background: rgba(0,0,0,0.05); padding: 6px; border-radius: 14px; }
        .tab-item { flex: 1; padding: 12px; text-align: center; font-weight: 700; cursor: pointer; border-radius: 10px; transition: 0.3s; color: #555; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; }
        .tab-item.active { background: var(--primary-green); color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .status-banner { padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 14px; }
        .status-banner.upcoming { background: #e3f2fd; color: #1976d2; border: 1px solid #bbdefb; }
        .status-banner.ongoing { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .status-banner.closed { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .admin-edit-link { display: inline-flex; align-items: center; gap: 5px; padding: 8px 15px; background: var(--primary-green); color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; margin-top: 10px; }
        .pos-sub-title { font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: white; margin: 20px 0 10px 10px; font-weight: 700; border-left: 3px solid var(--accent-green); padding-left: 10px; }
        .info-text-content { padding: 15px; font-size: 14px; line-height: 1.6; color: #ffffff; }
        .history-section .section-title { color: white; }
        .loader-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.1); display: flex; justify-content: center; align-items: center; z-index: 100; opacity: 0; visibility: hidden; }
        .loader-overlay.active { opacity: 1; visibility: visible; }
        .spinner { border: 4px solid rgba(255, 255, 255, 0.1); border-left-color: white; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #main-content-area { position: relative; min-height: 400px; }

         .h-img-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scale(1.0); /* 1.0 is normal, 0.85 is 15% zoomed out */
            transition: transform 0.3s ease;
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <span class="material-icons" id="menuIcon" style="cursor:pointer; color: white;">menu</span>
        <h1 style="color: white; margin-left: 15px;">UniBallot - INFO</h1>
    </nav>

    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Menu</h2><span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
                <p style="font-size: 11px; opacity: 0.8;"><?php echo htmlspecialchars($user_dept); ?></p>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item active"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="settings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <div class="container" style="margin-top: 20px;">
        <div class="history-tabs">
            <a href="?tab=USP" class="tab-item <?php echo $active_tab === 'USP' ? 'active' : ''; ?>" data-tab="USP">
                <span class="material-icons">account_balance</span> USP Election
            </a>
            <a href="?tab=SC" class="tab-item <?php echo $active_tab === 'SC' ? 'active' : ''; ?>" data-tab="SC">
                <span class="material-icons">groups</span> Student Council
            </a>
        </div>

        <div id="main-content-area">
            <div class="loader-overlay" id="content-loader"><div class="spinner"></div></div>
            <?php renderMainContent($db_status, $election_info, $active_tab, $is_admin, $candidates_by_group, $user_dept); ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const overlay = document.getElementById('drawerOverlay');
            const mainContentArea = document.getElementById('main-content-area');
            const contentLoader = document.getElementById('content-loader');
            const tabItems = document.querySelectorAll('.history-tabs .tab-item');

            menuIcon.onclick = () => { drawer.classList.add('open'); overlay.classList.add('active'); };
            const closeMenu = () => { drawer.classList.remove('open'); overlay.classList.remove('active'); };
            document.getElementById('closeIcon').onclick = overlay.onclick = closeMenu;

            function loadTabContent(targetTab, pushHistory = true) {
                const url = `electioninfo.php?tab=${targetTab}`;
                tabItems.forEach(item => item.classList.toggle('active', item.dataset.tab === targetTab));
                contentLoader.classList.add('active');

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(res => res.text())
                .then(html => {
                    contentLoader.classList.remove('active');
                    mainContentArea.innerHTML = html;
                    if (pushHistory) history.pushState({ tab: targetTab }, '', url);
                });
            }

            tabItems.forEach(tab => {
                tab.onclick = (e) => { e.preventDefault(); loadTabContent(tab.dataset.tab); };
            });

            window.onpopstate = (e) => {
                const tab = (e.state && e.state.tab) || 'USP';
                loadTabContent(tab, false);
            };
        });
    </script>
</body>
</html>