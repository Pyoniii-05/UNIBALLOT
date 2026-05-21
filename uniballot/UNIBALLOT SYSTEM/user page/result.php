<?php
// result.php

// ==========================================
// 1. BACKEND LOGIC & SESSION SETUP
// ==========================================

session_start();

// Redirect if not logged in
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];

// Fetch Student Data
$sql = "SELECT firstname, lastname FROM students WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
    $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
} else {
    $firstname = "User"; $lastname = ""; $initials = "US";
}

// ==========================================
// 2. ELECTION STATUS & RESULTS LOGIC
// ==========================================

// Get Election Info
$election_info = [];
$election_sql = "SELECT * FROM election_info WHERE id = 1";
$election_result = $conn->query($election_sql);

if ($election_result && $election_result->num_rows > 0) {
    $election_info = $election_result->fetch_assoc();
} else {
    $election_info = [
        'status' => 'upcoming',
        'vote_counting' => 'pending',
        'winners_announced' => 'no'
    ];
}

$db_status = $election_info['status']; // 'upcoming', 'ongoing', 'closed'
$winners_announced = ($election_info['winners_announced'] === 'yes');

// --- UPDATED LOGIC START ---

if ($winners_announced) {
    // 1. CASE: Winners ARE Announced -> Status should be COMPLETED
    if ($election_info['vote_counting'] !== 'completed') {
        $update_sql = "UPDATE election_info SET vote_counting = 'completed' WHERE id = 1";
        if ($conn->query($update_sql) === TRUE) {
            $election_info['vote_counting'] = 'completed';
        }
    }
    $display_status = 'COMPLETED';

} elseif ($db_status === 'closed') {
    // 2. CASE: Winners NOT announced, but Election is CLOSED -> Status should be IN_PROGRESS
    if ($election_info['vote_counting'] !== 'in_progress') {
        $update_sql = "UPDATE election_info SET vote_counting = 'in_progress' WHERE id = 1";
        if ($conn->query($update_sql) === TRUE) {
            $election_info['vote_counting'] = 'in_progress';
        }
    }
    // Set display text (using space instead of underscore for cleaner UI)
    $display_status = 'IN PROGRESS';

} else {
    // 3. CASE: Election is Upcoming or Ongoing -> Use DB value (e.g., 'pending')
    $display_status = $election_info['vote_counting'];
}

// --- UPDATED LOGIC END ---

// Determine if results should be shown (Only if winners are announced)
$show_results = $winners_announced;

// Fetch Total Votes Cast (Overall)
$total_ballots = 0;
$count_res = $conn->query("SELECT COUNT(*) as c FROM votes");
if($count_res) $total_ballots = $count_res->fetch_assoc()['c'];

// ==========================================
// 3. FETCH CANDIDATE DATA & COUNTS
// ==========================================

$candidates_by_position = [];
$total_votes_by_position = []; 

if ($show_results) {
    $position_map = [
        'Prime Minister'           => 'prime_minister',
        'Executive Prime Minister' => 'executive_prime_minister',
        'Secretary General'        => 'secretary_general',
        'Treasurer'                => 'treasurer',
        'Auditor'                  => 'auditor'
    ];

    foreach ($position_map as $position_title => $db_column) {
        
        $check = $conn->query("SHOW COLUMNS FROM votes LIKE '$db_column'");
        if ($check->num_rows == 0) continue;

        $pos_candidates = [];
        $total_pos_votes = 0;

        // Count votes for real candidates
        $sql = "SELECT id, firstname, lastname, party, photo, 
                (SELECT COUNT(*) FROM votes 
                 WHERE votes.$db_column = CONCAT(candidates.firstname, ' ', candidates.lastname)
                ) as vote_count
                FROM candidates 
                WHERE position = ?
                ORDER BY vote_count DESC";
        
        $c_stmt = $conn->prepare($sql);
        
        if ($c_stmt) {
            $c_stmt->bind_param("s", $position_title);
            $c_stmt->execute();
            $res = $c_stmt->get_result();

            while($row = $res->fetch_assoc()){
                $row['is_abstain'] = false;
                $pos_candidates[] = $row;
                $total_pos_votes += $row['vote_count'];
            }
            $c_stmt->close();
        }

        // Count abstains
        $ab_sql = "SELECT COUNT(*) as c FROM votes 
                   WHERE $db_column = 'abstain' 
                      OR $db_column = 'ABSTAINED'";
                      
        $ab_res = $conn->query($ab_sql);
        $ab_count = $ab_res ? $ab_res->fetch_assoc()['c'] : 0;

        if ($ab_count > 0 || !empty($pos_candidates)) {
            $pos_candidates[] = [
                'id' => 'abstain',
                'firstname' => 'Abstain',
                'lastname' => '',
                'party' => 'No Selection',
                'photo' => '',
                'vote_count' => $ab_count,
                'is_abstain' => true
            ];
            $total_pos_votes += $ab_count;
        }

        // Sort candidates by vote count
        usort($pos_candidates, function($a, $b) {
            return $b['vote_count'] - $a['vote_count'];
        });

        if (!empty($pos_candidates)) {
            $candidates_by_position[$position_title] = $pos_candidates;
            $total_votes_by_position[$position_title] = $total_pos_votes;
        }
    }
}

$conn->close();
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
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot - RESULT</h1>
    </nav>

    <!-- DRAWER OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

    <!-- SIDEBAR -->
    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Election Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>
        
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
                <div class="status-badge <?php echo $db_status; ?>">
                    <?php echo ucfirst($db_status); ?>
                </div>
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

    <!-- MAIN CONTAINER -->
    <div class="container">

        <div class="dashboard-header">
            <div class="dashboard-title">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">poll</span>
                    Election Results
                </div>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="material-icons summary-icon">how_to_vote</div>
                    <div class="summary-label">Total Ballots</div>
                    <div class="summary-value"><?php echo $show_results ? $total_ballots : 'Hidden'; ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">visibility</div>
                    <div class="summary-label">Availability</div>
                    <div class="summary-value" style="font-size:14px; margin-top:5px;">
                        <?php echo $winners_announced ? 'Public' : 'Hidden'; ?>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">track_changes</div>
                    <div class="summary-label">Status</div>
                    <!-- Displaying the updated status -->
                    <div class="mini-badge" style="background:var(--primary-green); color:white; border:none;">
                        <?php echo strtoupper($display_status); ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$show_results): ?>
            <div class="status-banner closed">
                <span class="material-icons">lock</span>
                RESULTS HIDDEN - Waiting for official announcement.
            </div>
            <div style="text-align: center; color: #555; margin-top: 50px;">
                <span class="material-icons" style="font-size: 64px; color: rgba(0,0,0,0.2);">visibility_off</span>
                <p style="margin-top:15px;">Official results have not been declared yet.</p>
            </div>
        <?php elseif (empty($candidates_by_position)): ?>
            <div class="status-banner info">No results found (Check database position names).</div>
        <?php else: ?>
            
            <div class="status-banner results">
                <span class="material-icons">bar_chart</span> OFFICIAL TALLY SHEET
            </div>

            <?php foreach ($candidates_by_position as $position_name => $candidates): ?>
                <div class="chart-section">
                    <h2 class="chart-title">
                        <span class="material-icons">stars</span>
                        <?php echo strtoupper($position_name); ?>
                    </h2>
                    
                    <div class="charts-container">
                        <?php 
                        $highest_vote = -1;
                        if (!empty($candidates)) {
                            $highest_vote = $candidates[0]['vote_count'];
                        }
                        
                        $total_for_pos = $total_votes_by_position[$position_name];

                        foreach ($candidates as $candidate):
                            $is_abstain = $candidate['is_abstain'];
                            $is_winner = ($candidate['vote_count'] == $highest_vote && $highest_vote > 0 && !$is_abstain);
                            
                            $fullname = $is_abstain ? 'ABSTAIN' : htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']);
                            $party = $is_abstain ? 'No Selection' : htmlspecialchars($candidate['party']);
                            
                            $percent = ($total_for_pos > 0) ? round(($candidate['vote_count'] / $total_for_pos) * 100, 1) : 0;
                            
                            $card_classes = "chart-card";
                            if($is_winner) $card_classes .= " winner";
                            if($is_abstain) $card_classes .= " abstain-card";
                        ?>
                            <div class="<?php echo $card_classes; ?>">
                                <?php if ($is_winner): ?>
                                    <div class="winner-tag">WINNER</div>
                                <?php endif; ?>

                                <div class="modal-icon-circle">
                                    <?php if ($is_abstain): ?>
                                        <span class="material-icons" style="font-size: 50px;">not_interested</span>
                                    <?php elseif ($candidate['photo']): ?>
                                        <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Img" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <span class="material-icons" style="font-size: 50px;">person</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="c-info">
                                    <div class="c-name"><?php echo $fullname; ?></div>
                                    <div class="c-party"><?php echo $party; ?></div>
                                </div>

                                <div class="result-stats-container">
                                    <div class="stat-row">
                                        <span><?php echo $candidate['vote_count']; ?> Votes</span>
                                        <span><?php echo $percent; ?>%</span>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width: <?php echo $percent; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            setTimeout(() => {
                const bars = document.querySelectorAll('.progress-fill');
                bars.forEach(bar => {
                    const width = bar.style.width;
                    bar.style.width = '0';
                    setTimeout(() => {
                        bar.style.width = width;
                    }, 100);
                });
            }, 300);
        });
    </script>
</body>
</html>