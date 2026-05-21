<?php
// votepage.php

// ==========================================
// 1. SECURITY & SESSION SETUP
// ==========================================

// 1. Start the session immediately
session_start();

// 2. Prevent Browser Caching (Fixes "Back Button" Security Issue)
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// 3. Login Check
if (!isset($_SESSION['stu_no']) || empty($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit(); 
}

// ==========================================
// 2. BACKEND LOGIC
// ==========================================

// Database connection
require_once '../db_connect.php';
ensure_candidates_election_type_column($conn);

$student_no = $_SESSION['stu_no'];

// --- AJAX HANDLER FOR GUIDELINES ACCEPTANCE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_guidelines') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("UPDATE voters SET has_accepted_guidelines = 1 WHERE stu_no = ?");
    if ($stmt) {
        $stmt->bind_param("s", $student_no);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Execute failed']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Query failed']);
    }
    exit();
}

// --- FETCH STUDENT DATA ---
$sql = "SELECT v.lastname, v.has_accepted_guidelines, s.firstname, s.department, s.program 
        FROM voters v 
        LEFT JOIN students s ON v.stu_no = s.stu_no 
        WHERE v.stu_no = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database Error: " . $conn->error); 
}

$stmt->bind_param("s", $student_no);
$stmt->execute();
$result = $stmt->get_result();

$show_guidelines_modal = false;

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'] ?? 'Student'; 
    $lastname = $row['lastname'];
        $student_department = $_SESSION['department'] ?? $row['department'] ?? '';
        $student_program = $_SESSION['program'] ?? $row['program'] ?? '';

        if (!empty($student_department)) {
            $_SESSION['department'] = $student_department;
        }
        if (!empty($student_program)) {
            $_SESSION['program'] = $student_program;
        }

        if (!empty($firstname) && $firstname !== 'Student') {
            $initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));
        } else {
            $initials = strtoupper(substr($lastname, 0, 2));
        }
        
        if (empty($row['has_accepted_guidelines'])) {
            $show_guidelines_modal = true;
        }
    } else {
        $firstname = "User"; $lastname = ""; $initials = "US";
        $student_department = $_SESSION['department'] ?? '';
        $student_program = $_SESSION['program'] ?? '';
    }

// Fetch Voting Status
$vote_check_sql = "SELECT * FROM votes WHERE stu_no = ?";
$vote_stmt = $conn->prepare($vote_check_sql);
$vote_stmt->bind_param("s", $student_no);
$vote_stmt->execute();
$has_voted = $vote_stmt->get_result()->num_rows > 0;

// ==========================================
// 3. ELECTION STATUS LOGIC
// ==========================================

// Get Election Info
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
        'voting_end' => date('Y-m-d H:i:s', strtotime('+7 days')),
        'vote_counting' => 'pending',
        'winners_announced' => 'no',
        'voting_guidelines' => 'Please select your candidates carefully.'
    ];
}

// Determine Status Flags
$db_status = $election_info['status'];
$winners_announced = ($election_info['winners_announced'] === 'yes');

$election_closed = ($db_status === "closed");
$election_ongoing = ($db_status === "ongoing");
$election_upcoming = ($db_status === "upcoming");

// Fallback logic (Time based override)
if (!$election_closed && !$election_ongoing && !$election_upcoming) {
    $now = time();
    $start = strtotime($election_info['voting_start']);
    $end = strtotime($election_info['voting_end']);
    
    if ($now < $start) { $election_upcoming = true; $db_status = 'upcoming'; }
    elseif ($now > $end) { $election_closed = true; $db_status = 'closed'; }
    else { $election_ongoing = true; $db_status = 'ongoing'; }
}

$_SESSION['current_election_status'] = $db_status;

// --- AUTO-UPDATE DB STATUS LOGIC START ---

if ($winners_announced) {
    // 1. If Winners Announced -> Force status to COMPLETED
    if ($election_info['vote_counting'] !== 'completed') {
        $update_sql = "UPDATE election_info SET vote_counting = 'completed' WHERE id = 1";
        if ($conn->query($update_sql) === TRUE) {
            $election_info['vote_counting'] = 'completed';
        }
    }
} elseif ($election_closed) {
    // 2. If Winners NOT Announced, but Election CLOSED -> Force status to IN_PROGRESS
    if ($election_info['vote_counting'] !== 'in_progress') {
        $update_sql = "UPDATE election_info SET vote_counting = 'in_progress' WHERE id = 1";
        if ($conn->query($update_sql) === TRUE) {
            $election_info['vote_counting'] = 'in_progress';
        }
    }
}
// --- AUTO-UPDATE DB STATUS LOGIC END ---

// Auto-Scroll Logic
$edit_position = $_GET['edit_position'] ?? '';
$scroll_to_position = '';
$position_names = [
    'Prime Minister' => 'prime_minister',
    'Executive Prime Minister' => 'executive_prime_minister', 
    'Secretary General' => 'secretary_general',
    'Treasurer' => 'treasurer',
    'Auditor' => 'auditor',
    'Student Council' => 'student_council'
];
$pos_key_map = [
    'prime_minister' => 'Prime Minister',
    'executive_prime_minister' => 'Executive Prime Minister',
    'secretary_general' => 'Secretary General',
    'treasurer' => 'Treasurer',
    'auditor' => 'Auditor'
    , 'student_council' => 'Student Council'
];

if (!empty($edit_position) && isset($pos_key_map[$edit_position])) {
    $scroll_to_position = $pos_key_map[$edit_position];
}

// Fetch Candidates
$student_program = $_SESSION['program'] ?? $student_program;
$positions = array_keys($position_names);
$candidates_by_position = [];
foreach ($positions as $pos) {
    if ($pos === 'Student Council' && !empty($student_department) && !empty($student_program)) {
        $c_sql = "SELECT * FROM candidates WHERE election_type = 'Student Council' AND department = ? AND program = ? ORDER BY position, firstname, lastname";
        $c_stmt = $conn->prepare($c_sql);
        $c_stmt->bind_param("ss", $student_department, $student_program);
    } elseif ($pos === 'Student Council' && !empty($student_department)) {
        $c_sql = "SELECT * FROM candidates WHERE election_type = 'Student Council' AND department = ? ORDER BY position, firstname, lastname";
        $c_stmt = $conn->prepare($c_sql);
        $c_stmt->bind_param("s", $student_department);
    } elseif ($pos === 'Student Council') {
        $c_sql = "SELECT * FROM candidates WHERE election_type = 'Student Council' ORDER BY position, firstname, lastname";
        $c_stmt = $conn->prepare($c_sql);
    } else {
        $c_sql = "SELECT * FROM candidates WHERE position = ? AND election_type = 'USP' ORDER BY firstname, lastname";
        $c_stmt = $conn->prepare($c_sql);
        $c_stmt->bind_param("s", $pos);
    }
    $c_stmt->execute();
    $candidates_by_position[$pos] = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();
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
    <title>Vote Now - UniBallot</title>
    <!-- Prevent caching in meta tags as a backup -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
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
            <a href="votepage.php" class="nav-item active"><span class="material-icons">how_to_vote</span>Vote Now</a>
            <a href="student_council.php" class="nav-item"><span class="material-icons">groups</span>Student Council</a>
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

    <!-- DETAILS MODAL -->
    <div class="modal-overlay" id="detailsModal">
        <div class="modal-box">
            <div style="padding: 30px 25px 10px;">
                <h2 style="text-align:center; margin-bottom:20px; color:var(--primary-green);">Candidate Details</h2>
                <div class="detail-row"><span>Year Level</span><span id="m_year">--</span></div>
                <div class="detail-row"><span>Department</span><span id="m_dept">--</span></div>
                <div class="detail-row"><span>Program</span><span id="m_prog">--</span></div>
                <div class="quote-box" id="m_msg">"Message here"</div>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="closeDetails">Close</button>
            </div>
        </div>
    </div>

    <!-- NEW USER GUIDELINES MODAL -->
    <div class="modal-overlay" id="guidelinesModal">
        <div class="modal-box info guidelines-modal">
            <div class="modal-header">
                <div class="modal-icon-circle" style="background:#d4b200; color:white;"><span class="material-icons">verified_user</span></div>
                <h2 class="modal-title">Welcome to <?php echo htmlspecialchars($election_info['election_name']); ?></h2>
                <p class="modal-desc">Please review the voting guidelines before proceeding.</p>
                
                <div class="guidelines-text">
                    <?php echo nl2br(htmlspecialchars($election_info['voting_guidelines'])); ?>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn-modal confirm-success" id="acceptGuidelinesBtn">I Understand & Agree</button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div id="toastNotification" class="custom-toast warning">
        <span class="material-icons">info</span>
        <span id="toastMessage">Notification Message</span>
    </div>

    <!-- MAIN CONTAINER -->
    <div class="container">

        <!-- STATUS BANNERS -->
        <?php if ($election_closed): ?>
            <div class="status-banner closed"><span class="material-icons">block</span> ELECTION CLOSED - Voting has ended.</div>
        <?php elseif ($election_upcoming): ?>
            <div class="status-banner upcoming"><span class="material-icons">schedule</span> UPCOMING - Voting starts: <?php echo date('M j, g:i A', strtotime($election_info['voting_start'])); ?></div>
        <?php elseif ($has_voted): ?>
            <div class="status-banner voted"><span class="material-icons">check_circle</span> VOTE RECORDED - You have already submitted your vote.</div>
        <?php else: ?>
            <div class="status-banner ongoing"><span class="material-icons">how_to_vote</span> ELECTION ONGOING - Cast your votes below!</div>
        <?php endif; ?>

        <!-- HEADER CARD -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">campaign</span>
                    <?php echo htmlspecialchars($election_info['election_name']); ?>
                </div>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="material-icons summary-icon">event</div>
                    <div class="summary-label">Start Date</div>
                    <div class="summary-value"><?php echo date('M j, g:i A', strtotime($election_info['voting_start'])); ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">event_busy</div>
                    <div class="summary-label">End Date</div>
                    <div class="summary-value"><?php echo date('M j, g:i A', strtotime($election_info['voting_end'])); ?></div>
                </div>
                <div class="summary-card">
                    <div class="material-icons summary-icon">how_to_reg</div>
                    <div class="summary-label">Counting</div>
                    <!-- Updated to replace underscore with space and capitalize -->
                    <div class="mini-badge"><?php echo strtoupper(str_replace('_', ' ', $election_info['vote_counting'])); ?></div>
                </div>
            </div>
        </div>

        <!-- VOTING FORM -->
        <?php if (!$has_voted && !$election_closed && !$election_upcoming): ?>

        <form action="confirmation.php" method="post" id="votingForm">
            
            <?php 
            foreach ($position_names as $position_title => $form_name): 
                $candidates = $candidates_by_position[$position_title];
            ?>
                <!-- Position Section -->
                <div class="chart-section" id="section-<?php echo $form_name; ?>">
                    <h2 class="chart-title">
                        <span class="material-icons">stars</span>
                        <?php echo strtoupper($position_title); ?>
                    </h2>
                    
                    <div class="charts-container">
                        <?php 
                        if (!empty($candidates)):
                            foreach ($candidates as $candidate): 
                                $is_selected = (isset($_SESSION['votes'][$form_name]) && $_SESSION['votes'][$form_name] == $candidate['id']);
                                $fullname = htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']);
                        ?>
                            <label class="chart-card <?php echo $is_selected ? 'selected' : ''; ?>" 
                                   onclick="selectCard(this)">
                                
                                <div class="modal-icon-circle" style="width: 100px; height: 100px; margin-bottom: 10px; background: #b0bfa5; color: var(--primary-green); font-size: 40px; overflow: hidden; padding:0; border: 4px solid rgba(255,255,255,0.3);">
                                    <?php if ($candidate['photo']): ?>
                                        <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Img" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <span class="material-icons" style="font-size: 50px;">person</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="c-info">
                                    <div class="c-name"><?php echo $fullname; ?></div>
                                    <div class="c-party"><?php echo htmlspecialchars($candidate['party']); ?></div>
                                </div>
                                
                                <input type="radio" name="<?php echo $form_name; ?>" value="<?php echo htmlspecialchars($candidate['id']); ?>" <?php echo $is_selected ? 'checked' : ''; ?> style="display:none;">
                                
                                <button type="button" class="btn-card-action vote">
                                    <?php echo $is_selected ? 'SELECTED' : 'VOTE'; ?>
                                </button>
                                
                                <button type="button" class="btn-card-action details" 
                                        onclick="event.stopPropagation(); event.preventDefault(); openDetails(
                                            '<?php echo addslashes($fullname); ?>',
                                            '<?php echo addslashes($candidate['party']); ?>',
                                            '<?php echo addslashes($candidate['year_level'] ?? 'N/A'); ?>',
                                            '<?php echo addslashes($candidate['department'] ?? 'N/A'); ?>',
                                            '<?php echo addslashes($candidate['program'] ?? 'N/A'); ?>',
                                            '<?php echo addslashes($candidate['message'] ?? 'No message.'); ?>'
                                        )">
                                    View Details
                                </button>
                            </label>
                        <?php endforeach; endif; ?>

                        <?php if (empty($candidates)): ?>
                            <div class="empty-state" style="padding:20px; width:100%; text-align:center; color:#666; border:1px solid #e0e0e0; border-radius:12px; background:#fafafa;">
                                No candidates are available for your department in this position.
                            </div>
                        <?php endif; ?>

                        <!-- ABSTAIN OPTION -->
                        <?php $is_abstained = (isset($_SESSION['votes'][$form_name]) && $_SESSION['votes'][$form_name] === 'abstain'); ?>
                        <label class="chart-card abstain-card <?php echo $is_abstained ? 'selected' : ''; ?>" 
                               onclick="selectCard(this)">
                            <div class="modal-icon-circle" style="width: 100px; height: 100px; margin-bottom: 10px; background: #e9ecef; color: var(--grey-neutral); font-size: 40px; border: 4px solid rgba(0,0,0,0.1);">
                                <span class="material-icons" style="font-size: 50px;">not_interested</span>
                            </div>
                            <div class="c-info">
                                <div class="c-name" style="color: var(--grey-neutral);">Abstain</div>
                                <div class="c-party">No Selection</div>
                            </div>
                            <input type="radio" name="<?php echo $form_name; ?>" value="abstain" <?php echo $is_abstained ? 'checked' : ''; ?> style="display:none;">
                            <button type="button" class="btn-card-action vote">
                                <?php echo $is_abstained ? 'SELECTED' : 'ABSTAIN'; ?>
                            </button>
                        </label>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="submit-wrapper">
                <button type="submit" class="submit-main-btn" id="submitVotes">
                    SUBMIT BALLOT <span class="material-icons">send</span>
                </button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($has_voted || $election_closed || $election_upcoming): ?>
            <div style="text-align: center; color: #555; margin-top: 50px;">
                <span class="material-icons" style="font-size: 64px; color: rgba(0,0,0,0.2);">how_to_vote</span>
                <p style="margin-top:15px;">Voting is currently disabled.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- SCRIPTS -->
    <?php 
    // Logic to decide if we show the peek animation
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        
        // Check if coming from confirmation page
        $from_confirmation = isset($_GET['from_confirmation']);

        // Check if explicitly told not to peek (e.g. from history page)
        $no_peek = isset($_GET['no_peek']);

        // Check if fresh login
        $fresh_login = isset($_SESSION['fresh_login']) && $_SESSION['fresh_login'] === true;

        if ($fresh_login) {
            $_SESSION['fresh_login'] = false; // Reset session flag
        } 
        
        // Only play animation if NOT fresh login AND NOT from confirmation AND NOT explicitly no_peek
        if (!$fresh_login && !$from_confirmation && !$no_peek) {
            echo '<script src="../drawer-peek.js"></script>';
        }
    } 
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- 0. NEW USER GUIDELINES MODAL LOGIC ---
            const shouldShowGuidelines = <?php echo $show_guidelines_modal ? 'true' : 'false'; ?>;
            const guidelinesModal = document.getElementById('guidelinesModal');
            const acceptBtn = document.getElementById('acceptGuidelinesBtn');

            if (shouldShowGuidelines) {
                // Show modal immediately
                guidelinesModal.classList.add('active');
                
                // Handle acceptance
                acceptBtn.onclick = function() {
                    const originalText = acceptBtn.innerHTML;
                    acceptBtn.innerHTML = 'Processing...';
                    
                    const formData = new FormData();
                    formData.append('action', 'accept_guidelines');

                    fetch('votepage.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            guidelinesModal.classList.remove('active');
                            showToast("Welcome! You may now vote.", "success");
                        } else {
                            acceptBtn.innerHTML = originalText;
                            showToast("Error updating status. Please try again.", "warning");
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        acceptBtn.innerHTML = originalText;
                    });
                };
            }

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
            
            // Only close overlay if it's NOT the guidelines modal blocking it
            drawerOverlay.onclick = () => {
                if(drawer.classList.contains('open')) {
                    toggleDrawer(false);
                }
            };

            // --- 2. Logout ---
            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { e.preventDefault(); toggleDrawer(false); logoutModal.classList.add('active'); };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            
            // Updated Logout to direct to index.php
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";

            // --- 3. Details Modal ---
            const detailsModal = document.getElementById('detailsModal');
            window.openDetails = function(name, party, year, dept, prog, msg) {
                document.getElementById('m_year').textContent = year;
                document.getElementById('m_dept').textContent = dept;
                document.getElementById('m_prog').textContent = prog;
                document.getElementById('m_msg').textContent = `"${msg}"`;
                detailsModal.classList.add('active');
            };
            document.getElementById('closeDetails').onclick = () => detailsModal.classList.remove('active');
            detailsModal.addEventListener('click', function(e) { if (e.target === detailsModal) detailsModal.classList.remove('active'); });

            // --- 4. Card Selection ---
            window.selectCard = function(cardElement) {
                const input = cardElement.querySelector('input[type="radio"]');
                input.checked = true;
                const container = cardElement.closest('.charts-container');
                container.querySelectorAll('.chart-card').forEach(card => {
                    card.classList.remove('selected');
                    const btn = card.querySelector('.btn-card-action.vote');
                    const isAbstain = card.classList.contains('abstain-card');
                    btn.textContent = isAbstain ? "ABSTAIN" : "VOTE";
                });
                cardElement.classList.add('selected');
                cardElement.querySelector('.btn-card-action.vote').textContent = "SELECTED";
                const section = cardElement.closest('.chart-section');
                section.classList.remove('error-shake');
                section.style.borderColor = 'transparent';
            };

            // --- 5. Custom Validation ---
            const votingForm = document.getElementById('votingForm');
            if (votingForm) {
                votingForm.addEventListener('submit', function(e) {
                    let missing = false;
                    let firstMissingSection = null;
                    const sections = document.querySelectorAll('.chart-section');
                    sections.forEach(section => {
                        const inputName = section.id.replace('section-', '');
                        const isChecked = section.querySelector(`input[name="${inputName}"]:checked`);
                        if (!isChecked) {
                            missing = true;
                            section.classList.add('error-shake');
                            section.style.borderColor = '#85211a'; 
                            setTimeout(() => section.classList.remove('error-shake'), 500);
                            if (!firstMissingSection) firstMissingSection = section;
                        } else {
                            section.style.borderColor = 'transparent';
                        }
                    });
                    if (missing) {
                        e.preventDefault(); 
                        showToast("Please vote (or abstain) for every position.", "warning");
                        if (firstMissingSection) firstMissingSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            }

            // --- 6. Toast Helper ---
            function showToast(message, type = 'info') {
                const toast = document.getElementById('toastNotification');
                const msgSpan = document.getElementById('toastMessage');
                toast.className = 'custom-toast ' + type;
                msgSpan.textContent = message;
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 3500);
            }

            // --- 7. Auto-Scroll ---
            const scrollToId = "<?php echo !empty($scroll_to_position) ? 'section-'.$position_names[$scroll_to_position] : ''; ?>";
            if (scrollToId) {
                const el = document.getElementById(scrollToId);
                if(el) {
                    setTimeout(() => {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.classList.add('error-shake');
                    }, 500);
                }
            }

            // --- 8. Status Checker ---
            function checkElectionStatus() {
                // Ensure this file exists, otherwise this will error in console
                fetch('../update_election_status.php?t=' + Date.now())
                    .then(response => { if(response.ok) return response.json(); })
                    .then(data => {
                        if (data && data.status_changed) {
                            showToast(`Status updated: ${data.new_status.toUpperCase()}. Reloading...`, "warning");
                            setTimeout(() => window.location.reload(true), 2000);
                        }
                    })
                    .catch(e => console.log('Status check skipped.'));
            }
            setInterval(checkElectionStatus, 15000);
        });
    </script>
</body>
</html>