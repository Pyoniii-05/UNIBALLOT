<?php
// ==========================================
// 1. BACKEND LOGIC
// ==========================================

session_start();

// --- SECURITY CHECK: VOTERS ONLY ---
// If the student number is not set in the session, redirect to login page.
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}
// -----------------------------------

// Database connection
require_once '../db_connect.php';

// Get logged-in user info
$student_no = $_SESSION['stu_no'];
$firstname = ""; $lastname = ""; $initials = "";
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$has_voted = false;

// Fetch Student Details
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
    // Fallback if session exists but DB record doesn't (rare edge case)
    $firstname = "Student";
    $lastname = "User";
    $initials = "SU";
}

// Check Voting Status
$voter_sql = "SELECT status FROM voters WHERE stu_no = ?";
$voter_stmt = $conn->prepare($voter_sql);
$voter_stmt->bind_param("s", $student_no);
$voter_stmt->execute();
$voter_result = $voter_stmt->get_result();
if ($voter_result && $voter_result->num_rows > 0) {
    $has_voted = ($voter_result->fetch_assoc()['status'] === 'voted');
}

// ========== GET ELECTION INFORMATION ==========
$election_info = [];
$current_timestamp = time();

// Fetch election info
$info_sql = "SELECT * FROM election_info WHERE id = 1";
$info_result = $conn->query($info_sql);

if ($info_result && $info_result->num_rows > 0) {
    $election_info = $info_result->fetch_assoc();
    
    // Calculate Status Logic
    $start_timestamp = strtotime($election_info['voting_start']);
    $end_timestamp = strtotime($election_info['voting_end']);
    
    if ($current_timestamp > $end_timestamp) {
        $db_status = 'closed';
    } elseif ($current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp) {
        $db_status = 'ongoing';
    } else {
        $db_status = 'upcoming';
    }
} else {
    // Fallback Defaults
    $election_info = [
        'election_name' => 'UniBallot Student Election',
        'description' => 'Annual Student Government Election',
        'voting_start' => date('Y-m-d H:i:s'),
        'voting_end' => date('Y-m-d H:i:s', strtotime('+7 days')),
        'status' => 'upcoming',
        'vote_counting' => 'pending',
        'winners_announced' => 'no',
        'eligibility_requirements' => "Currently enrolled USP student\nValid student ID\nRegistered for current semester",
        'voting_guidelines' => "One vote per student\nSelect one candidate per position\nVoting closes automatically",
        'contact_info' => "election@usp.ac.fj\nOffice: Student Affairs"
    ];
    $db_status = 'upcoming';
}

// Fetch Candidates for Display
$positions = ['Prime Minister', 'Executive Prime Minister', 'Secretary General', 'Treasurer', 'Auditor'];
$candidates_by_position = [];
foreach ($positions as $position) {
    $c_sql = "SELECT * FROM candidates WHERE position = ? ORDER BY firstname, lastname";
    $c_stmt = $conn->prepare($c_sql);
    $c_stmt->bind_param("s", $position);
    $c_stmt->execute();
    $candidates_by_position[$position] = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Election Info - UniBallot Elect</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot - INFORMATION</h1>
    </nav>

    <!-- DRAWER & OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

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
                <?php if ($is_admin): ?>
                    <div class="admin-badge">Administrator</div>
                <?php endif; ?>
                <div class="status-badge <?php echo $db_status; ?>">
                    <?php echo ucfirst($db_status); ?>
                </div>
            </div>
        </div>
        
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item active"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="settings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <!-- Only logout needed, login removed since access is restricted -->
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

    <!-- MAIN CONTAINER -->
    <div class="container">

        <!-- HEADER CARD -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <span class="material-icons" style="color: var(--primary-green); font-size: 36px;">campaign</span>
                <?php echo htmlspecialchars($election_info['election_name']); ?>
            </div>
            <p class="dashboard-desc">
                <?php echo htmlspecialchars($election_info['description']); ?>
            </p>
            
            <div style="display:flex; gap: 25px; align-items: center; flex-wrap: wrap;">
                <span class="status-badge <?php echo $db_status; ?>" style="font-size: 14px; padding: 8px 20px;">
                    STATUS: <?php echo strtoupper($db_status); ?>
                </span>
                <?php if ($is_admin): ?>
                        <a href="../admin page/electionsettings.php?no_peek=1" class="admin-edit-btn" style="margin-top: 0;">
                            <span class="material-icons">edit</span> Edit Info
                        </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- INFO GRID -->
        <div class="info-grid">
            
            <!-- Schedule -->
            <div class="info-card">
                <div class="info-title"><span class="material-icons">event_note</span> Election Schedule</div>
                <ul class="info-list">
                    <li><span class="info-label">Voting Starts:</span> <span class="info-val"><?php echo date('M j, Y g:i A', strtotime($election_info['voting_start'])); ?></span></li>
                    <li><span class="info-label">Voting Ends:</span> <span class="info-val"><?php echo date('M j, Y g:i A', strtotime($election_info['voting_end'])); ?></span></li>
                    <li><span class="info-label">Counting:</span> 
                        <span class="mini-badge" style="background:var(--white-soft); padding: 2px 8px; border-radius:4px; font-size:12px; border:1px solid #ccc;">
                            <?php echo ucfirst($election_info['vote_counting']); ?>
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Guidelines -->
            <div class="info-card">
                <div class="info-title"><span class="material-icons">gavel</span> Guidelines</div>
                <ul class="info-list">
                    <?php 
                    $lines = explode("\n", $election_info['voting_guidelines']);
                    foreach($lines as $line): if(trim($line)): ?>
                        <li><span class="material-icons">check_circle</span> <?php echo htmlspecialchars(trim($line)); ?></li>
                    <?php endif; endforeach; ?>
                </ul>
            </div>

            <!-- Requirements / Contact -->
            <div class="info-card">
                <div class="info-title"><span class="material-icons">contact_support</span> Contact & Rules</div>
                <ul class="info-list">
                     <?php 
                    $reqs = explode("\n", $election_info['eligibility_requirements']);
                    foreach($reqs as $line): if(trim($line)): ?>
                        <li><span class="material-icons">verified</span> <?php echo htmlspecialchars(trim($line)); ?></li>
                    <?php endif; endforeach; ?>
                </ul>
                <div style="margin-top: 15px; padding-top:15px; border-top: 1px solid rgba(0,0,0,0.1); font-size:13px; color:#555;">
                     <?php echo nl2br(htmlspecialchars($election_info['contact_info'])); ?>
                </div>
            </div>
        </div>

        <!-- CANDIDATES LISTING -->
        <div class="section-header">
            <span class="material-icons">groups</span> REGISTERED CANDIDATES
        </div>

        <?php foreach ($positions as $pos): ?>
            <?php if (!empty($candidates_by_position[$pos])): ?>
            <div class="position-group">
                <h3 class="position-title"><?php echo strtoupper($pos); ?></h3>
                <div class="charts-container">
                    <?php foreach ($candidates_by_position[$pos] as $candidate): ?>
                        <div class="chart-card">
                            <?php if ($candidate['photo']): ?>
                                <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" class="card-img" alt="Img">
                            <?php else: ?>
                                <div class="card-icon"><span class="material-icons" style="font-size: 40px;">person</span></div>
                            <?php endif; ?>
                            
                            <div class="c-name"><?php echo htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']); ?></div>
                            <div class="c-party"><?php echo htmlspecialchars($candidate['party']); ?></div>
                            <div class="c-dept"><?php echo htmlspecialchars($candidate['department']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>

    </div>

    <!-- SCRIPTS -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Drawer Logic ---
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');
            const closeIcon = document.getElementById('closeIcon');
            
            const toggleDrawer = (open) => { 
                if(open) { 
                    drawer.classList.add('open'); 
                    drawerOverlay.classList.add('active'); 
                } else { 
                    drawer.classList.remove('open'); 
                    drawerOverlay.classList.remove('active'); 
                }
            };

            menuIcon.onclick = () => toggleDrawer(true);
            closeIcon.onclick = () => toggleDrawer(false);
            drawerOverlay.onclick = () => toggleDrawer(false);

            // --- Logout Logic ---
            const logoutLink = document.getElementById('logoutLink');
            const logoutModal = document.getElementById('logoutModal');
            
            if(logoutLink) {
                logoutLink.onclick = (e) => { 
                    e.preventDefault(); 
                    toggleDrawer(false); 
                    logoutModal.classList.add('active'); 
                };
            }
            
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";
        });
    </script>
</body>
</html>