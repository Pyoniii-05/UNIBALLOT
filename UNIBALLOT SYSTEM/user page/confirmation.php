<?php
// confirmation.php

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
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

// Fetch user info for Drawer
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

// Fetch Voting Status
$vote_check_sql = "SELECT * FROM votes WHERE stu_no = ?";
$vote_stmt = $conn->prepare($vote_check_sql);
$vote_stmt->bind_param("s", $student_no);
$vote_stmt->execute();
$db_status_row = $vote_stmt->get_result()->fetch_assoc();
$has_voted = $db_status_row ? true : false;

// Status Badge Logic
$status_class = 'ongoing';
if($has_voted) $status_class = 'closed';

// ==========================================
// 2. PROCESS VOTES FOR DISPLAY
// ==========================================

$student_department = $_SESSION['department'] ?? '';
$student_program = $_SESSION['program'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $votes = [
        'prime_minister' => $_POST['prime_minister'] ?? 'abstain',
        'executive_prime_minister' => $_POST['executive_prime_minister'] ?? 'abstain',
        'secretary_general' => $_POST['secretary_general'] ?? 'abstain',
        'treasurer' => $_POST['treasurer'] ?? 'abstain',
        'auditor' => $_POST['auditor'] ?? 'abstain',
        'student_council' => $_POST['student_council'] ?? 'abstain'
    ];
    $_SESSION['votes'] = $votes;
} else {
    if (isset($_SESSION['votes'])) {
        $votes = $_SESSION['votes'];
    } else {
        header("Location: votepage.php");
        exit();
    }
}

// Prepare Data
$selected_candidates = [];
$positions_map = [
    'prime_minister' => 'Prime Minister',
    'executive_prime_minister' => 'Executive Prime Minister',
    'secretary_general' => 'Secretary General',
    'treasurer' => 'Treasurer',
    'auditor' => 'Auditor',
    'student_council' => 'Student Council'
];

foreach ($positions_map as $key => $label) {
    $val = $votes[$key];
    $candidate_found = false;

    if (!empty($val) && $val !== 'abstain') {
        if ($key === 'student_council') {
            if (!empty($student_department) && !empty($student_program)) {
                $sql = "SELECT id, firstname, lastname, photo, party FROM candidates WHERE id = ? AND election_type = 'Student Council' AND department = ? AND program = ?";
                $c_stmt = $conn->prepare($sql);
                $c_stmt->bind_param("iss", $val, $student_department, $student_program);
            } elseif (!empty($student_department)) {
                $sql = "SELECT id, firstname, lastname, photo, party FROM candidates WHERE id = ? AND election_type = 'Student Council' AND department = ?";
                $c_stmt = $conn->prepare($sql);
                $c_stmt->bind_param("is", $val, $student_department);
            } else {
                $sql = "SELECT id, firstname, lastname, photo, party FROM candidates WHERE id = ? AND election_type = 'Student Council'";
                $c_stmt = $conn->prepare($sql);
                $c_stmt->bind_param("i", $val);
            }
        } else {
            $sql = "SELECT id, firstname, lastname, photo, party FROM candidates WHERE id = ?";
            $c_stmt = $conn->prepare($sql);
            $c_stmt->bind_param("i", $val);
        }
        $c_stmt->execute();
        $res = $c_stmt->get_result();
        
        if ($res && $res->num_rows > 0) {
            $data = $res->fetch_assoc();
            $selected_candidates[$key] = [
                'id' => $data['id'],
                'name' => $data['firstname'] . ' ' . $data['lastname'],
                'party' => $data['party'],
                'photo' => $data['photo'],
                'is_abstain' => false
            ];
            $candidate_found = true;
        }
        $c_stmt->close();
    } 

    if ($val === 'abstain' || !$candidate_found) {
        $votes[$key] = 'abstain'; 
        $selected_candidates[$key] = [
            'id' => 'abstain',
            'name' => 'Abstain',
            'party' => 'No Selection',
            'photo' => null,
            'is_abstain' => true
        ];
    }
}

$stmt->close();
$vote_stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Ballot - UniBallot Elect</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot</h1>
    </nav>

    <!-- DRAWER & OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Menu</h2>
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
                <div class="status-badge <?php echo $status_class; ?>">
                    <?php echo $has_voted ? 'VOTED' : 'CONFIRMING'; ?>
                </div>
            </div>
        </div>
        
        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
            <a href="#" class="nav-item active"><span class="material-icons">check_circle</span>Confirmation</a>
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
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

        <?php if ($has_voted): ?>
            <!-- ALREADY VOTED STATE -->
            <div class="voted-state">
                <span class="material-icons voted-icon">task_alt</span>
                <h2 style="font-weight: 800; font-size: 28px; color: var(--primary-green);">Vote Recorded!</h2>
                <p style="color: #555; margin-top: 10px; font-weight: 500;">You have already submitted your ballot for this election.</p>
                <div style="margin-top: 30px;">
                    <a href="votinghistory.php" class="btn-submit-final" style="background: var(--primary-green); text-decoration:none;">View My History</a>
                </div>
            </div>

        <?php else: ?>
            <!-- CONFIRMATION FORM -->
            <div class="dashboard-header">
                <div class="dashboard-title">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 36px;">fact_check</span>
                    Confirm Your Ballot
                </div>
                <div class="dashboard-subtitle">
                    Please review your selections below.
                </div>
            </div>

            <form action="submitvote.php" method="POST" id="confirmForm">
                
                <!-- SCROLLABLE LIST CONTAINER (FLEX) -->
                <div class="conf-list">
                    
                    <?php foreach ($positions_map as $key => $label): 
                        $candidate = $selected_candidates[$key];
                        $is_abstain = $candidate['is_abstain'];
                    ?>
                        <!-- CANDIDATE ROW -->
                        <div class="conf-card <?php echo $is_abstain ? 'abstain' : ''; ?>">
                            
                            <!-- POSITION HEADER -->
                            <div class="conf-card-header">
                                <?php echo strtoupper($label); ?>
                            </div>

                            <!-- CARD BODY -->
                            <div class="conf-card-body">
                                <div class="conf-candidate-info">
                                    <div class="conf-img">
                                        <?php if ($is_abstain): ?>
                                            <span class="material-icons">not_interested</span>
                                        <?php elseif (!empty($candidate['photo'])): ?>
                                            <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Cand">
                                        <?php else: ?>
                                            <span class="material-icons">person</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="conf-text-group">
                                        <div class="name"><?php echo htmlspecialchars($candidate['name']); ?></div>
                                        <div class="party"><?php echo htmlspecialchars($candidate['party']); ?></div>
                                    </div>
                                </div>

                                <a href="votepage.php?edit_position=<?php echo $key; ?>&from_confirmation=1" class="btn-edit">
                                    <span class="material-icons" style="font-size:16px;">edit</span> Change
                                </a>
                            </div>

                            <!-- HIDDEN INPUT FOR SUBMISSION -->
                            <input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($votes[$key]); ?>">
                        </div>
                    <?php endforeach; ?>

                </div>

                <div class="action-footer">
                    <a href="votepage.php?from_confirmation=1" class="btn-back-link">
                        <span class="material-icons" style="vertical-align:middle; font-size:18px;">arrow_back</span> Return to Ballot
                    </a>
                    <button type="submit" class="btn-submit-final">
                        SUBMIT FINAL VOTE <span class="material-icons">send</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>

    </div>

    <!-- SCRIPTS -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- 1. Drawer ---
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

            // --- 2. Logout ---
            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); toggleDrawer(false); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";

            // --- 3. Prevent Double Submits ---
            const form = document.getElementById('confirmForm');
            if(form) {
                form.addEventListener('submit', function() {
                    const btn = document.querySelector('.btn-submit-final');
                    btn.innerHTML = 'SUBMITTING...';
                    btn.style.opacity = '0.7';
                    btn.style.pointerEvents = 'none';
                });
            }
        });
    </script>
</body>
</html>