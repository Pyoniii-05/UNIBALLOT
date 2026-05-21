<?php
// student_council.php

session_start();

// 1. Prevent Browser Caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// 2. Login Check
if (!isset($_SESSION['stu_no']) || empty($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

// 3. Database connection
require_once '../db_connect.php';
// require_once '../security_manager.php'; // Assuming this is not strictly needed for this page

$student_no = $_SESSION['stu_no'];
$firstname = 'Student';
$lastname = 'User';
$initials = 'SU';

// Fetch student profile for drawer display if not already in session
if (!empty($_SESSION['firstname']) && !empty($_SESSION['lastname'])) {
    $firstname = $_SESSION['firstname'];
    $lastname = $_SESSION['lastname'];
    $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
} else {
    $profile_sql = "SELECT firstname, lastname FROM students WHERE stu_no = ?";
    $profile_stmt = $conn->prepare($profile_sql);
    if ($profile_stmt) {
        $profile_stmt->bind_param('s', $student_no);
        $profile_stmt->execute();
        $profile_result = $profile_stmt->get_result();
        if ($profile_result && $profile_result->num_rows > 0) {
            $profile_row = $profile_result->fetch_assoc();
            $firstname = $profile_row['firstname'] ?? $firstname;
            $lastname = $profile_row['lastname'] ?? $lastname;
            $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
            // Update session for future pages
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
        }
        $profile_stmt->close();
    }
}

// ==========================================
// 4. ELECTION STATUS LOGIC (Integrated from votepage.php for consistency)
// ==========================================

$election_info = [];
$election_sql = "SELECT election_name, status, voting_start, voting_end, vote_counting, winners_announced, voting_guidelines FROM election_info WHERE id = 1";
$election_result = $conn->query($election_sql);

if ($election_result && $election_result->num_rows > 0) {
    $election_info = $election_result->fetch_assoc();
} else {
    // Fallback if no election info is found in DB
    $election_info = [
        'election_name' => 'UniBallot Student Election',
        'status' => 'upcoming',
        'voting_start' => date('Y-m-d H:i:s'),
        'voting_end' => date('Y-m-d H:i:s', strtotime('+7 days')),
        'vote_counting' => 'pending',
        'winners_announced' => 'no',
        'voting_guidelines' => 'Please select your candidates carefully.'
    ];
}

// Determine current status based on DB or time
$db_status = $election_info['status'];
$winners_announced = ($election_info['winners_announced'] === 'yes');

$election_closed = ($db_status === "closed");
$election_ongoing = ($db_status === "ongoing");
$election_upcoming = ($db_status === "upcoming");

// Time-based override for status if not explicitly set in DB or seems off
if (!$election_closed && !$election_ongoing && !$election_upcoming) {
    $now = time();
    $start = strtotime($election_info['voting_start']);
    $end = strtotime($election_info['voting_end']);
    
    if ($now < $start) { $election_upcoming = true; $db_status = 'upcoming'; }
    elseif ($now > $end) { $election_closed = true; $db_status = 'closed'; }
    else { $election_ongoing = true; $db_status = 'ongoing'; }
}

// Update session variable
$_SESSION['current_election_status'] = $db_status;

// Auto-update DB status for vote counting based on election state
if ($winners_announced) {
    if ($election_info['vote_counting'] !== 'completed') {
        $update_sql = "UPDATE election_info SET vote_counting = 'completed' WHERE id = 1";
        $conn->query($update_sql); // Execute without checking result here for brevity
    }
} elseif ($election_closed) {
    if ($election_info['vote_counting'] !== 'in_progress') {
        $update_sql = "UPDATE election_info SET vote_counting = 'in_progress' WHERE id = 1";
        $conn->query($update_sql); // Execute without checking result here for brevity
    }
}

// ==========================================
// 5. Fetch Candidates for Student Council
// ==========================================

$positions = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Representative 1',
    'Representative 2',
    'Representative 3',
    'Representative 4'
];

$placeholders = implode(', ', array_fill(0, count($positions), '?'));
$sql = "SELECT * FROM candidates WHERE position IN ($placeholders) ORDER BY position, lastname, firstname";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Database Query Error: ' . $conn->error);
}

// Dynamically bind parameters based on the number of positions
$stmt->bind_param(str_repeat('s', count($positions)), ...$positions);

$stmt->execute();
$result = $stmt->get_result();
$candidates_by_position = array_fill_keys($positions, []);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (in_array($row['position'], $positions, true)) {
            $candidates_by_position[$row['position']][] = $row;
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
    <title>Student Council - UniBallot</title>
    <!-- Prevent caching in meta tags as a backup -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        /* Specific styles for this page to enhance presentation */
        .student-council-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* Slightly wider cards */
            gap: 25px; /* Increased gap */
            margin-top: 30px;
        }
        .sc-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            padding: 25px; /* Increased padding */
            border: 1px solid #f0f0f0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .sc-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        }
        .sc-card h3 {
            font-size: 19px; /* Slightly larger heading */
            margin-bottom: 18px;
            color: #2f3e3f;
            font-weight: 600;
        }
        .candidate-item {
            display: flex;
            align-items: center;
            gap: 16px; /* Increased gap */
            padding: 14px 0;
            border-bottom: 1px solid #f2f2f2;
        }
        .candidate-item:last-child { border-bottom: none; }
        .candidate-photo {
            width: 50px; /* Slightly larger photo */
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            background: #e5e5e5;
            flex-shrink: 0;
            border: 2px solid #f8f9fa; /* Small border for depth */
        }
        .candidate-photo.default { 
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px; /* Larger icon */
            color: #888;
        }
        .candidate-meta {
            display: grid;
            gap: 4px;
        }
        .candidate-meta strong {
            font-size: 15px; /* Slightly larger name */
            color: #1f2f30;
            font-weight: 500;
        }
        .candidate-meta span {
            font-size: 13px; /* Slightly larger details */
            color: #6d7578;
        }
        .position-header {
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #1d383d;
        }
        .position-header .material-icons {
            font-size: 26px; /* Larger icon */
            color: var(--primary-green);
        }
        .empty-state {
            padding: 20px;
            text-align: center;
            color: #888;
            background-color: #f9f9f9;
            border: 1px dashed #e0e0e0;
            border-radius: 10px;
            margin-top: 15px;
            font-style: italic;
        }
    </style>
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
            <h2>Student Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>

        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
                <div class="status-badge <?php echo $db_status; ?>">
                    <?php echo ucfirst($db_status); ?>
                </div>
            </div>
        </div>

        <div class="drawer-nav">
            <a href="votepage.php" class="nav-item"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item active"><span class="material-icons">groups</span>Student Council</a>
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
        <div class="dashboard-header">
            <div>
                <h1 class="dashboard-title"><span class="material-icons" style="color: var(--primary-green); font-size: 34px;">groups</span> Student Council</h1>
                <p style="margin-top: 8px; color: #586168;">Review all registered Student Council positions and their candidates for the current election.</p>
            </div>
        </div>

        <div class="student-council-grid">
            <?php foreach ($positions as $position): ?>
                <div class="sc-card">
                    <div class="position-header">
                        <span class="material-icons">badge</span>
                        <h3><?php echo htmlspecialchars($position); ?></h3>
                    </div>

                    <?php if (empty($candidates_by_position[$position])): ?>
                        <div class="empty-state">
                            No registered candidates for this position yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($candidates_by_position[$position] as $candidate): ?>
                            <div class="candidate-item">
                                <?php 
                                $candidate_photo_path = '../assets/candidates/' . $candidate['photo'];
                                if (!empty($candidate['photo']) && file_exists(__DIR__ . '/' . $candidate_photo_path)): ?>
                                    <img src="<?php echo htmlspecialchars($candidate_photo_path); ?>" alt="Candidate Photo" class="candidate-photo">
                                <?php else: ?>
                                    <div class="candidate-photo default"><span class="material-icons">person</span></div>
                                <?php endif; ?>
                                <div class="candidate-meta">
                                    <strong><?php echo htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']); ?></strong>
                                    <span><?php echo htmlspecialchars($candidate['party']); ?></span>
                                    <span><?php echo htmlspecialchars($candidate['program'] . ' | ' . $candidate['department']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SCRIPTS -->
    <?php 
    // Logic to decide if we show the peek animation
    // Only play animation if NOT a POST request AND NOT from submission
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !(isset($_GET['from']) && $_GET['from'] === 'submission')): 
    ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- 1. Drawer Logic ---
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
            
            // Only close overlay if it's NOT the logout modal blocking it (similar to votepage's logic for guidelines)
            drawerOverlay.onclick = () => {
                if(drawer.classList.contains('open') && !logoutModal.classList.contains('active')) {
                    toggleDrawer(false);
                }
            };

            // --- 2. Logout Modal Logic ---
            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { 
                e.preventDefault(); // Prevent default link behavior
                toggleDrawer(false); // Close the drawer first
                logoutModal.classList.add('active'); // Show the logout modal
            };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            
            // Confirm logout directs to the logout script
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";

            // --- 3. Election Status Checker (Auto-reload if status changes) ---
            function checkElectionStatus() {
                fetch('../update_election_status.php?t=' + Date.now()) // Append timestamp to prevent caching
                    .then(response => { 
                        if(response.ok) return response.json(); 
                        throw new Error('Network response not ok.');
                    })
                    .then(data => {
                        if (data && data.status_changed) {
                            console.log('Election status changed. Reloading page...');
                            // Potentially show a quick toast here before reloading
                            window.location.reload(true); // Force reload from server
                        }
                    })
                    .catch(e => console.warn('Election status check failed or skipped:', e.message));
            }
            setInterval(checkElectionStatus, 15000); // Check every 15 seconds
        });
    </script>
</body>
</html>