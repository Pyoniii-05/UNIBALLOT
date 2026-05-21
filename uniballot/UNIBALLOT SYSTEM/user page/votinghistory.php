<?php
// votinghistory.php

// ==========================================
// 1. BACKEND LOGIC & SESSION SETUP
// ==========================================

date_default_timezone_set('Asia/Manila'); 
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

// Fetch Voting Status & Voter Details
$vote_check_sql = "SELECT * FROM votes WHERE stu_no = ?";
$vote_check_stmt = $conn->prepare($vote_check_sql);
$vote_check_stmt->bind_param("s", $student_no);
$vote_check_stmt->execute();
$has_voted_record = $vote_check_stmt->get_result();
$has_voted = ($has_voted_record->num_rows > 0);

// ==========================================
// 2. ELECTION STATUS LOGIC
// ==========================================

$election_info = [];
$election_sql = "SELECT * FROM election_info WHERE id = 1";
$election_result = $conn->query($election_sql);

if ($election_result && $election_result->num_rows > 0) {
    $election_info = $election_result->fetch_assoc();
} else {
    $election_info = [
        'election_name' => 'UniBallot Student Election',
        'status' => 'upcoming',
        'voting_start' => date('Y-m-d H:i:s'),
        'voting_end' => date('Y-m-d H:i:s', strtotime('+7 days'))
    ];
}

$db_status = $election_info['status'];

// Fallback logic for status
$election_closed = ($db_status === "closed");
$election_ongoing = ($db_status === "ongoing");
$election_upcoming = ($db_status === "upcoming");

if (!$election_closed && !$election_ongoing && !$election_upcoming) {
    $now = time();
    $start = strtotime($election_info['voting_start']);
    $end = strtotime($election_info['voting_end']);
    
    if ($now < $start) { $election_upcoming = true; $db_status = 'upcoming'; }
    elseif ($now > $end) { $election_closed = true; $db_status = 'closed'; }
    else { $election_ongoing = true; $db_status = 'ongoing'; }
}

// ==========================================
// 3. FETCH VOTING HISTORY
// ==========================================

$voting_history = [];
$vote_timestamp = "N/A";

if ($has_voted) {
    // Reset pointer
    $has_voted_record->data_seek(0);
    $vote_data = $has_voted_record->fetch_assoc();
    
    if(isset($vote_data['vote_timestamp'])) {
        $vote_timestamp = date('M j, Y g:i A', strtotime($vote_data['vote_timestamp']));
    }

    $position_map = [
        'prime_minister' => 'Prime Minister',
        'executive_prime_minister' => 'Executive Prime Minister',
        'secretary_general' => 'Secretary General',
        'treasurer' => 'Treasurer',
        'auditor' => 'Auditor'
    ];

    foreach ($position_map as $db_column => $position_name) {
        $selection_value = $vote_data[$db_column] ?? null;

        if ($selection_value) {
            if ($selection_value === 'abstain') {
                // Handle Abstain
                $voting_history[] = [
                    'position' => $position_name,
                    'type' => 'abstain',
                    'candidate_name' => 'Abstained',
                    'candidate_party' => 'No Selection made',
                    'candidate_photo' => null
                ];
            } else {
                
                $c_sql = "SELECT * FROM candidates WHERE id = ? OR CONCAT(firstname, ' ', lastname) = ?";
                $c_stmt = $conn->prepare($c_sql);
                
                $c_stmt->bind_param("ss", $selection_value, $selection_value);
                $c_stmt->execute();
                $c_res = $c_stmt->get_result();

                if ($c_res->num_rows > 0) {
                    $cand = $c_res->fetch_assoc();
                    $voting_history[] = [
                        'position' => $position_name,
                        'type' => 'candidate',
                        'candidate_name' => $cand['firstname'] . ' ' . $cand['lastname'],
                        'candidate_party' => $cand['party'],
                        'candidate_photo' => $cand['photo']
                    ];
                } else {
                    // Fallback if candidate deleted
                    $voting_history[] = [
                        'position' => $position_name,
                        'type' => 'candidate',
                        'candidate_name' => 'Unknown Candidate',
                        'candidate_party' => 'N/A',
                        'candidate_photo' => null
                    ];
                }
            }
        }
    }
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voting History - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot - HISTORY</h1>
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

    <!-- MAIN CONTAINER -->
    <div class="container">

        <!-- STATUS BANNERS -->
        <?php if ($election_closed): ?>
            <div class="status-banner closed"><span class="material-icons">block</span> ELECTION CLOSED - Voting has ended.</div>
        <?php elseif ($has_voted): ?>
            <div class="status-banner voted"><span class="material-icons">check_circle</span> SUCCESS - Your ballot has been recorded.</div>
        <?php else: ?>
            <div class="status-banner ongoing"><span class="material-icons">info</span> NO RECORD - You haven't voted yet.</div>
        <?php endif; ?>

        <!-- DASHBOARD HEADER -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">receipt_long</span>
                    My Voting Record
                </div>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="material-icons summary-icon">how_to_reg</div>
                    <div class="summary-label">Voter Status</div>
                    <div class="summary-value"><?php echo $has_voted ? 'Voted' : 'Not Voted'; ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">event_available</div>
                    <div class="summary-label">Date Submitted</div>
                    <div class="summary-value"><?php echo $vote_timestamp; ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">how_to_vote</div>
                    <div class="summary-label">Election</div>
                    <div class="summary-value"><?php echo htmlspecialchars($election_info['election_name']); ?></div>
                </div>
            </div>
        </div>

        <!-- HISTORY GRID -->
        <div class="history-section">
            <h2 class="section-title">
                <span class="material-icons">history_edu</span>
                Submitted Ballot
            </h2>

            <?php if (!$has_voted): ?>
                <div class="no-vote-box">
                    <span class="material-icons" style="font-size: 64px; color: #999; margin-bottom: 20px;">pending_actions</span>
                    <h3>No Voting History Found</h3>
                    <p>You have not cast your vote for the current election yet.</p>
                    <br>
                    <?php if($election_ongoing): ?>
                        <a href="votepage.php?no_peek=1" class="btn-action">GO TO VOTING PAGE</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="charts-container">
                    <?php foreach ($voting_history as $vote): 
                        $is_abstain = ($vote['type'] === 'abstain');
                    ?>
                        <div class="history-card <?php echo $is_abstain ? 'abstain' : ''; ?>">
                            <div class="h-pos-label"><?php echo htmlspecialchars($vote['position']); ?></div>
                            
                            <div class="h-img-circle">
                                <?php if ($is_abstain): ?>
                                    <span class="material-icons" style="font-size: 45px;">not_interested</span>
                                <?php elseif ($vote['candidate_photo']): ?>
                                    <img src="../assets/candidates/<?php echo htmlspecialchars($vote['candidate_photo']); ?>" alt="img" style="width:100%; height:100%; object-fit:cover;">
                                <?php else: ?>
                                    <span class="material-icons" style="font-size: 45px;">person</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="h-info">
                                <div class="h-name"><?php echo htmlspecialchars($vote['candidate_name']); ?></div>
                                <div class="h-party"><?php echo htmlspecialchars($vote['candidate_party']); ?></div>
                                <div class="h-badge">
                                    <span class="material-icons" style="font-size:14px; vertical-align:middle; margin-right:4px;">check</span>
                                    SUBMITTED
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- SCRIPTS -->
    <?php 
    // Check if the user came from the submission page
    $is_from_submission = isset($_GET['from']) && $_GET['from'] === 'submission';

    // Only load the peek animation if NOT a POST request AND NOT from submission
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$is_from_submission): 
    ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Drawer Logic
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

            // Logout Logic
            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); toggleDrawer(false); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";
        });
    </script>
</body>
</html>