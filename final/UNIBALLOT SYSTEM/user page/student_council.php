<?php
// student_council.php
session_start();

// 1. Prevent Browser Caching
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

// 2. Login Check
if (!isset($_SESSION['stu_no']) || empty($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

// 3. Database connection
require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];

// ==========================================
// 4. FIX: PROFILE DATA FETCHING
// ==========================================
$firstname = $_SESSION['firstname'] ?? '';
$lastname = $_SESSION['lastname'] ?? '';
$student_department = $_SESSION['department'] ?? '';

// Fetch from DB if ANY session data is missing
if (empty($firstname) || empty($lastname) || empty($student_department)) {
    $profile_sql = "SELECT firstname, lastname, department FROM students WHERE stu_no = ?";
    $profile_stmt = $conn->prepare($profile_sql);
    if ($profile_stmt) {
        $profile_stmt->bind_param('s', $student_no);
        $profile_stmt->execute();
        $profile_result = $profile_stmt->get_result();
        if ($row = $profile_result->fetch_assoc()) {
            $firstname = $row['firstname'];
            $lastname = $row['lastname'];
            $student_department = $row['department'];
            
            // Sync to session for other pages
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
            $_SESSION['department'] = $student_department;
        }
        $profile_stmt->close();
    }
}

// Fallbacks if DB is empty
if (empty($firstname)) $firstname = "Student";
if (empty($lastname)) $lastname = "User";

// Calculate Initials for Avatar
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));

// ==========================================
// 5. ELECTION STATUS (ID 2 for Council)
// ==========================================
$election_sql = "SELECT status FROM election_info WHERE id = 2";
$election_result = $conn->query($election_sql);
$row = ($election_result) ? $election_result->fetch_assoc() : null;
$db_status = $row['status'] ?? 'upcoming';

// ==========================================
// 6. FETCH STUDENT COUNCIL CANDIDATES
// ==========================================

// Define positions in display order
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

$candidates_by_position = array_fill_keys($positions, []);

// Fetch candidates matching 'Student Council' and the student's department
$sql = "SELECT * FROM candidates 
        WHERE election_type = 'Student Council' 
        AND department = ? 
        ORDER BY lastname ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('s', $student_department);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pos = $row['position'];
            if (array_key_exists($pos, $candidates_by_position)) {
                $candidates_by_position[$pos][] = $row;
            }
        }
    }
    $stmt->close();
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Council - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        .student-council-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        .sc-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            padding: 25px;
            border: 1px solid #f0f0f0;
            display: flex;
            flex-direction: column;
        }
        .sc-card h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2f3e3f;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #e8f0e8;
            padding-bottom: 10px;
        }
        .sc-card h3 .material-icons { color: #3b4d3b; }
        
        .candidate-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f2f2f2;
        }
        .candidate-item:last-child { border-bottom: none; }
        .candidate-photo {
            width: 60px; height: 60px; border-radius: 50%; object-fit: cover;
            background: #eee; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .candidate-meta strong { display: block; font-size: 15px; color: #1f2f30; }
        .candidate-meta span { font-size: 12px; color: #777; display: block; margin-top: 2px; }
        
        .empty-state {
            text-align: center; color: #999; font-size: 14px; padding: 20px;
            background: #fafafa; border-radius: 10px; border: 1px dashed #ddd;
        }
        .dept-display {
            background: var(--primary-green); color: white;
            padding: 15px 25px; border-radius: 12px; display: inline-flex;
            align-items: center; gap: 10px; margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>UniBallot</h1>
    </nav>

    <div class="overlay" id="drawerOverlay"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Election Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
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

    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box info">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">logout</span></div>
                <h2 class="modal-title">Signing Out?</h2>
                <p class="modal-desc">Are you sure you want to log out?</p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="cancelLogout">Cancel</button>
                <button class="btn-modal confirm-success" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="dashboard-header" style="background:none; box-shadow:none; padding:0; margin-bottom:30px;">
            <div class="dept-display">
                <span class="material-icons">domain</span>
                <div>
                    <div style="font-size:10px; text-transform:uppercase; opacity:0.8;">Your Department</div>
                    <div style="font-weight:700; font-size:16px;"><?php echo htmlspecialchars($student_department); ?></div>
                </div>
            </div>
            <h1 style="font-weight:800; font-size:28px;">Council Candidates</h1>
        </div>

        <div class="student-council-grid">
            <?php foreach ($positions as $position): ?>
                <div class="sc-card">
                    <h3><span class="material-icons">stars</span> <?php echo $position; ?></h3>
                    
                    <?php if (empty($candidates_by_position[$position])): ?>
                        <div class="empty-state">No candidates filed for this position.</div>
                    <?php else: ?>
                        <?php foreach ($candidates_by_position[$position] as $candidate): ?>
                            <div class="candidate-item">
                                <?php if (!empty($candidate['photo'])): ?>
                                    <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" class="candidate-photo">
                                <?php else: ?>
                                    <div class="candidate-photo" style="display:flex; align-items:center; justify-content:center; background:#eee;"><span class="material-icons" style="color:#aaa;">person</span></div>
                                <?php endif; ?>
                                <div class="candidate-meta">
                                    <strong><?php echo htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']); ?></strong>
                                    <span>Party: <?php echo htmlspecialchars($candidate['party']); ?></span>
                                    <span>Program: <?php echo htmlspecialchars($candidate['program'] ?? 'N/A'); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

     <!-- SCRIPTS -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuIcon = document.getElementById('menuIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');
            const closeIcon = document.getElementById('closeIcon');

            menuIcon.onclick = () => { drawer.classList.add('open'); drawerOverlay.classList.add('active'); };
            closeIcon.onclick = () => { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); };
            drawerOverlay.onclick = () => { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); };

            const logoutModal = document.getElementById('logoutModal');
            document.getElementById('logoutLink').onclick = (e) => { 
                e.preventDefault(); 
                drawer.classList.remove('open');
                drawerOverlay.classList.remove('active');
                logoutModal.classList.add('active'); 
            };
            document.getElementById('cancelLogout').onclick = () => logoutModal.classList.remove('active');
            document.getElementById('confirmLogout').onclick = () => window.location.href = "../logout.php";
        });
    </script>
</body>
</html>