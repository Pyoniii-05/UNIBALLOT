<?php
// result.php

// ==========================================
// 1. BACKEND LOGIC & SESSION SETUP
// ==========================================
session_start();

if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];

// Fetch Student Data
$sql = "SELECT firstname, lastname, department FROM students WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
    $student_department = $row['department']; 
    $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
} else {
    $firstname = "User"; $lastname = ""; $initials = "US"; $student_department = null;
}

// ==========================================
// 2. ELECTION STATUS & RESULTS VISIBILITY LOGIC
// ==========================================
$active_tab = $_GET['tab'] ?? 'usp'; 
$election_info = [];

if ($active_tab === 'usp') {
    // Check USP Global Settings (ID 1)
    $stmt_info = $conn->prepare("SELECT * FROM election_info WHERE id = 1");
    $stmt_info->execute();
    $election_info = $stmt_info->get_result()->fetch_assoc();
} else {
    // Check Department Specific Settings
    $stmt_info = $conn->prepare("SELECT * FROM dept_settings WHERE department = ?");
    $stmt_info->bind_param("s", $student_department);
    $stmt_info->execute();
    $election_info = $stmt_info->get_result()->fetch_assoc();

    // Fallback to Global SC template (ID 2) if no department-specific row exists
    if (!$election_info) {
        $stmt_fallback = $conn->prepare("SELECT * FROM election_info WHERE id = 2");
        $stmt_fallback->execute();
        $election_info = $stmt_fallback->get_result()->fetch_assoc();
    }
}

// Set visibility and status based on the fetched settings
$db_status = $election_info['status'] ?? 'upcoming';
$winners_announced_for_current_tab = (($election_info['winners_announced'] ?? 'no') === 'yes');
$show_results_for_current_tab = $winners_announced_for_current_tab;

// Update "Vote Counting" status text for the UI
if ($winners_announced_for_current_tab) {
    $display_status = 'COMPLETED';
} elseif ($db_status === 'closed') {
    $display_status = 'IN PROGRESS';
} else {
    $display_status = strtoupper($db_status);
}

// ==========================================
// 3. SELECTION LOGIC (LOCKED TO USER DEPT)
// ==========================================
$selected_dept = $student_department ?: '';

// ==========================================
// 4. FETCH CANDIDATE DATA & COUNTS
// ==========================================

function getElectionResults($conn, $active_tab, $selected_dept, $show_results_for_current_tab) {
    $results_output = [
        'candidates_by_position' => [],
        'total_votes_by_position' => [],
        'total_ballots_in_view' => 0,
        'election_title' => ''
    ];

    if (!$show_results_for_current_tab) {
        return $results_output;
    }

    $election_type_filter = ($active_tab === 'usp') ? 'USP' : 'Student Council';

    if ($active_tab === 'usp') {
        $results_output['election_title'] = "USP Parliament Results";
        $position_map = [
            'Prime Minister' => 'prime_minister',
            'Executive Prime Minister' => 'executive_prime_minister',
            'Secretary General' => 'secretary_general',
            'Treasurer' => 'treasurer',
            'Auditor' => 'auditor'
        ];
        $total_votes_query = $conn->query("SELECT COUNT(*) FROM votes");
        $results_output['total_ballots_in_view'] = $total_votes_query->fetch_row()[0];
    } else { 
        $results_output['election_title'] = "Council Results: " . htmlspecialchars($selected_dept);
        $position_map = [
            'President' => 'sc_president',
            'Vice President' => 'sc_vice_president',
            'Secretary' => 'sc_secretary',
            'Treasurer' => 'sc_treasurer',
            'Auditor' => 'sc_auditor',
            'Representative 1' => 'sc_rep1',
            'Representative 2' => 'sc_rep2',
            'Representative 3' => 'sc_rep3',
            'Representative 4' => 'sc_rep4'
        ];
        $ballot_q = $conn->prepare("SELECT COUNT(*) FROM votes v JOIN students s ON v.stu_no = s.stu_no WHERE s.department = ?");
        $ballot_q->bind_param("s", $selected_dept); $ballot_q->execute();
        $results_output['total_ballots_in_view'] = $ballot_q->get_result()->fetch_row()[0];
        $ballot_q->close();
    }

    foreach ($position_map as $position_title => $db_column) {
        $check = $conn->query("SHOW COLUMNS FROM votes LIKE '$db_column'");
        if ($check->num_rows == 0) continue;

        $pos_candidates = [];
        $total_pos_votes = 0;

        $sql = "SELECT id, firstname, lastname, party, photo, 
                (SELECT COUNT(*) FROM votes v JOIN students s ON v.stu_no = s.stu_no 
                 WHERE v.$db_column = CONCAT(candidates.firstname, ' ', candidates.lastname) " .
                 ($active_tab === 'sc' ? "AND s.department = ?" : "") . ") as vote_count
                FROM candidates 
                WHERE position = ? AND election_type = ? " .
                ($active_tab === 'sc' ? "AND department = ?" : "") . "
                ORDER BY vote_count DESC";
        
        $c_stmt = $conn->prepare($sql);
        
        if ($c_stmt) {
            if ($active_tab === 'sc') {
                $c_stmt->bind_param("ssss", $selected_dept, $position_title, $election_type_filter, $selected_dept);
            } else {
                $c_stmt->bind_param("ss", $position_title, $election_type_filter);
            }
            $c_stmt->execute();
            $res = $c_stmt->get_result();
            while($row = $res->fetch_assoc()){
                $row['is_abstain'] = false;
                $pos_candidates[] = $row;
                $total_pos_votes += $row['vote_count'];
            }
            $c_stmt->close();
        }

        $abstain_sql = "SELECT COUNT(*) as c FROM votes v JOIN students s ON v.stu_no = s.stu_no WHERE v.$db_column = 'abstain' " .
                       ($active_tab === 'sc' ? "AND s.department = ?" : "");
        
        $ab_stmt = $conn->prepare($abstain_sql);
        if ($ab_stmt) {
            if ($active_tab === 'sc') { $ab_stmt->bind_param("s", $selected_dept); }
            $ab_stmt->execute();
            $ab_res = $ab_stmt->get_result();
            $ab_count = $ab_res ? $ab_res->fetch_assoc()['c'] : 0;
            $ab_stmt->close();
        } else { $ab_count = 0; }

        if ($ab_count > 0 || !empty($pos_candidates)) {
            $pos_candidates[] = [
                'id' => 'abstain', 'firstname' => 'Abstain', 'lastname' => '', 'party' => 'No Selection', 'photo' => '', 'vote_count' => $ab_count, 'is_abstain' => true
            ];
            $total_pos_votes += $ab_count;
        }

        usort($pos_candidates, function($a, $b) { return $b['vote_count'] - $a['vote_count']; });

        if (!empty($pos_candidates)) {
            $results_output['candidates_by_position'][$position_title] = $pos_candidates;
            $results_output['total_votes_by_position'][$position_title] = $total_pos_votes;
        }
    }
    return $results_output;
}

$initial_results = getElectionResults($conn, $active_tab, $selected_dept, $show_results_for_current_tab);
$candidates_by_position = $initial_results['candidates_by_position'];
$total_votes_by_position = $initial_results['total_votes_by_position'];
$current_title = $initial_results['election_title'];
$total_ballots = $initial_results['total_ballots_in_view'];

function renderMainContent($show_results_for_current_tab, $display_status, $total_ballots, $candidates_by_position, $total_votes_by_position, $current_title) {
    ?>
    <div class="dashboard-header">
        <div class="dashboard-title">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">poll</span>
                <?php echo $current_title; ?>
            </div>
        </div>
        
        <div class="summary-grid">
            <div class="summary-card">
                <div class="material-icons summary-icon">how_to_vote</div>
                <div class="summary-label">Total Ballots</div>
                <div class="summary-value"><?php echo $show_results_for_current_tab ? $total_ballots : 'Hidden'; ?></div>
            </div>
            <div class="summary-card">
                <div class="material-icons summary-icon">visibility</div>
                <div class="summary-label">Availability</div>
                <div class="summary-value" style="font-size:14px; margin-top:5px;"><?php echo $show_results_for_current_tab ? 'Public' : 'Hidden'; ?></div>
            </div>
            <div class="summary-card">
                <div class="material-icons summary-icon">track_changes</div>
                <div class="summary-label">Status</div>
                <div class="mini-badge" style="background:var(--primary-green); color:white; border:none;">
                    <?php echo strtoupper($display_status); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$show_results_for_current_tab): ?>
        <div class="status-banner closed"><span class="material-icons">lock</span> RESULTS HIDDEN - Waiting for official announcement.</div>
        <div style="text-align: center; color: #555; margin-top: 50px;">
            <span class="material-icons" style="font-size: 64px; color: rgba(0,0,0,0.2);">visibility_off</span>
            <p style="margin-top:15px;">Official results for this election have not been declared yet.</p>
        </div>
    <?php elseif (empty($candidates_by_position)): ?>
        <div class="status-banner info">No results found for your department.</div>
    <?php else: ?>
        <div class="status-banner results"><span class="material-icons">bar_chart</span> OFFICIAL TALLY SHEET</div>
        <?php foreach ($candidates_by_position as $position_name => $candidates): ?>
            <div class="chart-section">
                <h2 class="chart-title"><span class="material-icons">stars</span><?php echo strtoupper($position_name); ?></h2>
                <div class="charts-container">
                    <?php 
                    $highest_vote = $candidates[0]['vote_count'];
                    $total_for_pos = $total_votes_by_position[$position_name];
                    foreach ($candidates as $candidate):
                        $is_abstain = $candidate['is_abstain'];
                        $is_winner = ($candidate['vote_count'] == $highest_vote && $highest_vote > 0 && !$is_abstain);
                        $fullname = $is_abstain ? 'ABSTAIN' : htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']);
                        $percent = ($total_for_pos > 0) ? round(($candidate['vote_count'] / $total_for_pos) * 100, 1) : 0;
                        $card_classes = "chart-card" . ($is_winner ? " winner" : "") . ($is_abstain ? " abstain-card" : "");
                    ?>
                        <div class="<?php echo $card_classes; ?>">
                            <?php if ($is_winner): ?><div class="winner-tag">WINNER</div><?php endif; ?>
                            <div class="modal-icon-circle">
                                <?php if ($is_abstain): ?><span class="material-icons" style="font-size: 50px;">not_interested</span>
                                <?php elseif ($candidate['photo']): ?><img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Img" style="width:100%; height:100%; object-fit:cover;">
                                <?php else: ?><span class="material-icons" style="font-size: 50px;">person</span><?php endif; ?>
                            </div>
                            <div class="c-info">
                                <div class="c-name"><?php echo $fullname; ?></div>
                                <div class="c-party"><?php echo $is_abstain ? 'No Selection' : htmlspecialchars($candidate['party']); ?></div>
                            </div>
                            <div class="result-stats-container">
                                <div class="stat-row"><span><?php echo $candidate['vote_count']; ?> Votes</span><span><?php echo $percent; ?>%</span></div>
                                <div class="progress-track"><div class="progress-fill" style="width: <?php echo $percent; ?>%"></div></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif;
}

if (isset($_GET['ajax'])) {
    renderMainContent($show_results_for_current_tab, $display_status, $total_ballots, $candidates_by_position, $total_votes_by_position, $current_title);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
    <style>
        .results-tabs { display: flex; gap: 10px; margin-bottom: 25px; }
        .tab-btn { padding: 12px 24px; border: none; border-radius: 30px; background: #c4c2a5; color: var(--primary-green); font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
        .tab-btn.active { background: var(--primary-green); color: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        #results-content-area.loading { opacity: 0.5; pointer-events: none; }
    </style>
</head>
<body>
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot - RESULT</h1>
    </nav>
    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header"><h2>Election Menu</h2><span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span></div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
            </div>
        </div>
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item active"><span class="material-icons">bar_chart</span>View Results</a>
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

    <div class="container">
        <div class="results-tabs">
            <button class="tab-btn <?php echo $active_tab === 'usp' ? 'active' : ''; ?>" onclick="loadResults('usp')">
                <span class="material-icons">account_balance</span> USP Results
            </button>
            <button class="tab-btn <?php echo $active_tab === 'sc' ? 'active' : ''; ?>" onclick="loadResults('sc')">
                <span class="material-icons">groups</span>Student Council (<?php echo htmlspecialchars($student_department); ?>)
            </button>
        </div>

        <div id="results-content-area">
            <?php renderMainContent($show_results_for_current_tab, $display_status, $total_ballots, $candidates_by_position, $total_votes_by_position, $current_title); ?>
        </div>
    </div>

    <script>
        const resultsContentArea = document.getElementById('results-content-area');
        const tabButtons = document.querySelectorAll('.tab-btn');

        async function loadResults(tab) {
            resultsContentArea.classList.add('loading');
            tabButtons.forEach(btn => btn.classList.remove('active'));
            const targetButton = document.querySelector(`.tab-btn[onclick="loadResults('${tab}')"]`);
            if (targetButton) targetButton.classList.add('active');

            const url = `result.php?ajax=1&tab=${tab}`;
            
            try {
                const response = await fetch(url);
                const html = await response.text();
                resultsContentArea.innerHTML = html;
                window.history.pushState({ tab }, '', `result.php?tab=${tab}`);

                setTimeout(() => {
                    document.querySelectorAll('.progress-fill').forEach(bar => {
                        const width = bar.style.width;
                        bar.style.width = '0';
                        setTimeout(() => bar.style.width = width, 100);
                    });
                }, 100);
            } catch (err) { console.error(err); } 
            finally { resultsContentArea.classList.remove('loading'); }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');
            const closeIcon = document.getElementById('closeIcon');
            
            const toggleDrawer = (open) => { 
                if(open) { drawer.classList.add('open'); drawerOverlay.classList.add('active'); }
                else { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); }
            };
            menuIcon.onclick = () => toggleDrawer(true);
            closeIcon.onclick = () => toggleDrawer(false);
            drawerOverlay.onclick = () => toggleDrawer(false);

            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); toggleDrawer(false); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";
        });
    </script>
</body>
</html>