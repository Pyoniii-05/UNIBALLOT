<?php
// votinghistory.php
date_default_timezone_set('Asia/Manila'); 
session_start();

if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';
$student_no = $_SESSION['stu_no'];

// 1. Fetch Student Data for Drawer
$sql = "SELECT firstname, lastname FROM students WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$firstname = $user['firstname'] ?? 'User';
$lastname = $user['lastname'] ?? '';
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));

// 2. Fetch Voting Record
$vote_sql = "SELECT * FROM votes WHERE stu_no = ?";
$v_stmt = $conn->prepare($vote_sql);
$v_stmt->bind_param("s", $student_no);
$v_stmt->execute();
$vote_data = $v_stmt->get_result()->fetch_assoc();

// 3. Define Position Maps
$usp_map = [
    'prime_minister' => 'Prime Minister',
    'executive_prime_minister' => 'Executive Prime Minister',
    'secretary_general' => 'Secretary General',
    'treasurer' => 'Treasurer',
    'auditor' => 'Auditor'
];
$sc_map = [
    'sc_president' => 'President',
    'sc_vice_president' => 'Vice President',
    'sc_secretary' => 'Secretary',
    'sc_treasurer' => 'Treasurer',
    'sc_auditor' => 'Auditor',
    'sc_rep1' => 'Representative 1',
    'sc_rep2' => 'Representative 2',
    'sc_rep3' => 'Representative 3',
    'sc_rep4' => 'Representative 4'
];

// 4. Process Logic
function processElectionHistory($conn, $data, $map) {
    if (!$data) return ['has_voted' => false, 'history' => [], 'timestamp' => 'N/A'];
    $history = [];
    $vote_exists = false;
    foreach ($map as $col => $label) {
        $val = $data[$col] ?? null;
        if (!$val) continue;
        $vote_exists = true;
        if (strtolower($val) === 'abstain') {
            $history[] = ['position' => $label, 'name' => 'Abstained', 'party' => 'No selection made', 'photo' => null, 'is_abstain' => true];
        } else {
            $c_sql = "SELECT photo, party FROM candidates WHERE CONCAT(firstname, ' ', lastname) = ? LIMIT 1";
            $c_stmt = $conn->prepare($c_sql);
            $c_stmt->bind_param("s", $val);
            $c_stmt->execute();
            $cand = $c_stmt->get_result()->fetch_assoc();
            $history[] = ['position' => $label, 'name' => $val, 'party' => $cand['party'] ?? 'Independent', 'photo' => $cand['photo'] ?? null, 'is_abstain' => false];
        }
    }
    $timestamp = ($vote_exists && isset($data['voted_at'])) ? date('M j, Y g:i A', strtotime($data['voted_at'])) : "N/A";
    return ['has_voted' => $vote_exists, 'history' => $history, 'timestamp' => $timestamp];
}

$usp_record = processElectionHistory($conn, $vote_data, $usp_map);
$sc_record = processElectionHistory($conn, $vote_data, $sc_map);

// 5. Determine active tab from URL for initial load
$active_tab = $_GET['tab'] ?? 'USP';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting History - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        .history-tabs { display: flex; gap: 10px; margin-bottom: 25px; background: rgba(0,0,0,0.05); padding: 6px; border-radius: 14px; }
        .tab-item { flex: 1; padding: 12px; text-align: center; font-weight: 700; cursor: pointer; border-radius: 10px; transition: 0.3s; color: #555; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .tab-item.active { background: var(--primary-green); color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .status-banner.not-voted { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }

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

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon" style="cursor:pointer; color: white;">menu</span>
        <h1 style="color: white; margin-left: 15px;">UniBallot</h1>
    </nav>

    <!-- DRAWER / SIDEBAR -->
    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="votinghistory.php" class="nav-item active"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="settings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box info">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">logout</span></div>
                <h2 style="margin: 10px 0;">Signing Out?</h2>
                <p style="color:#666; font-size:14px;">You are about to end your session. Do you want to continue?</p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="cancelLogout">Cancel</button>
                <button class="btn-modal confirm-success" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <div class="container" style="margin-top: 20px;">
        
        <div class="history-tabs">
            <div class="tab-item <?php echo $active_tab === 'USP' ? 'active' : ''; ?>" data-tab="USP">
                <span class="material-icons">account_balance</span> USP Election
            </div>
            <div class="tab-item <?php echo $active_tab === 'SC' ? 'active' : ''; ?>" data-tab="SC">
                <span class="material-icons">groups</span> Student Council
            </div>
        </div>

        <!-- USP TAB -->
        <div id="USP" class="tab-pane <?php echo $active_tab === 'USP' ? 'active' : ''; ?>">
            <?php renderElectionContent($usp_record, "USP Executive"); ?>
        </div>

        <!-- SC TAB -->
        <div id="SC" class="tab-pane <?php echo $active_tab === 'SC' ? 'active' : ''; ?>">
            <?php renderElectionContent($sc_record, "Student Council"); ?>
        </div>

    </div>

    <?php
    // Helper function to render UI content to keep HTML clean
    function renderElectionContent($record, $title) { ?>
        <?php if ($record['has_voted']): ?>
            <div class="status-banner voted"><span class="material-icons">check_circle</span> SUCCESS - Your <?php echo $title; ?> ballot has been recorded.</div>
        <?php else: ?>
            <div class="status-banner not-voted"><span class="material-icons">pending_actions</span> NO RECORD - You haven't voted for <?php echo $title; ?> yet.</div>
        <?php endif; ?>

        <div class="dashboard-header">
            <div class="summary-grid">
                <div class="summary-card <?php echo !$record['has_voted'] ? 'not-voted' : ''; ?>">
                    <div class="material-icons summary-icon">how_to_reg</div>
                    <div class="summary-label">Status</div>
                    <div class="summary-value"><?php echo $record['has_voted'] ? 'Voted' : 'Not Voted'; ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">event_available</div>
                    <div class="summary-label">Submission</div>
                    <div class="summary-value"><?php echo $record['timestamp']; ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">how_to_vote</div>
                    <div class="summary-label">Election</div>
                    <div class="summary-value"><?php echo $title; ?></div>
                </div>
            </div>
        </div>

        <div class="history-section">
            <h2 class="section-title"><span class="material-icons">history_edu</span> Submitted Ballot</h2>
            <?php if (!$record['has_voted']): ?>
                <div class="no-vote-box"><p>No voting history found for this election.</p></div>
            <?php else: ?>
                <div class="charts-container">
                    <?php foreach ($record['history'] as $vote): ?>
                        <div class="history-card <?php echo $vote['is_abstain'] ? 'abstain' : ''; ?>">
                            <div class="h-pos-label"><?php echo htmlspecialchars($vote['position']); ?></div>
                            <div class="h-img-circle">
                                <?php if ($vote['photo']): ?>
                                    <img src="../assets/candidates/<?php echo htmlspecialchars($vote['photo']); ?>" alt="">
                                <?php else: ?>
                                    <span class="material-icons" style="font-size: 45px; color:#ccc;"><?php echo $vote['is_abstain'] ? 'block' : 'person'; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="h-info">
                                <div class="h-name"><?php echo htmlspecialchars($vote['name']); ?></div>
                                <div class="h-party"><?php echo htmlspecialchars($vote['party']); ?></div>
                                <div class="h-badge"><span class="material-icons" style="font-size:14px; margin-right:4px;">check</span>SUBMITTED</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php } ?>

     <!-- SCRIPTS -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Drawer & Modal Logic ---
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const overlay = document.getElementById('drawerOverlay');
            const closeIcon = document.getElementById('closeIcon');
            const logoutLink = document.getElementById('logoutLink');
            const logoutModal = document.getElementById('logoutModal');

            menuIcon.onclick = () => { drawer.classList.add('open'); overlay.classList.add('active'); };
            const closeMenu = () => { drawer.classList.remove('open'); overlay.classList.remove('active'); };
            closeIcon.onclick = closeMenu;
            overlay.onclick = closeMenu;

            logoutLink.onclick = (e) => { e.preventDefault(); closeMenu(); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";

            // --- AJAX-style Tab Logic ---
            const tabs = document.querySelectorAll('.tab-item');
            const panes = document.querySelectorAll('.tab-pane');

            tabs.forEach(tab => {
                tab.onclick = function() {
                    const target = this.getAttribute('data-tab');
                    
                    // Update UI
                    tabs.forEach(t => t.classList.remove('active'));
                    panes.forEach(p => p.classList.remove('active'));
                    this.classList.add('active');
                    document.getElementById(target).classList.add('active');

                    // Update URL without refresh (keeps history state)
                    const url = new URL(window.location);
                    url.searchParams.set('tab', target);
                    window.history.pushState({}, '', url);
                };
            });
        });
    </script>
</body>
</html>