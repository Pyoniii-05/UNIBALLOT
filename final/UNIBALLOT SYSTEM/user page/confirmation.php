<?php
// confirmation.php
session_start();

// Prevent Browser Caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 1. AUTHENTICATION & DATABASE
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';
$student_no = $_SESSION['stu_no'];

// 2. FETCH STUDENT DATA
$sql = "SELECT firstname, lastname FROM students WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_no);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$firstname = $user['firstname'] ?? 'Student';
$lastname = $user['lastname'] ?? '';
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));

// 3. DETERMINE ELECTION TYPE & HANDLE POST DATA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $election_type = $_POST['election_type'] ?? 'USP'; 
    $_SESSION['active_confirmation_type'] = $election_type;
    $_SESSION['temp_votes'] = $_POST;
} else {
    $election_type = $_SESSION['active_confirmation_type'] ?? '';
    if (!$election_type) { header("Location: votepage.php"); exit(); }
}

$raw_votes = $_SESSION['temp_votes'] ?? [];

// 4. PREPARE DISPLAY DATA (Mapping database IDs to Names/Photos)
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

$active_map = ($election_type === 'USP') ? $usp_map : $sc_map;
$display_list = [];

foreach ($active_map as $db_col => $label) {
    $candidate_id = $raw_votes[$db_col] ?? 'abstain';
    
    if ($candidate_id !== 'abstain' && !empty($candidate_id)) {
        // Fetch candidate details by ID
        $c_stmt = $conn->prepare("SELECT firstname, lastname, photo, party FROM candidates WHERE id = ? LIMIT 1");
        $c_stmt->bind_param("i", $candidate_id);
        $c_stmt->execute();
        $c_data = $c_stmt->get_result()->fetch_assoc();
        
        if ($c_data) {
            $display_list[] = [
                'label' => $label,
                'name' => $c_data['firstname'] . ' ' . $c_data['lastname'],
                'db_col' => $db_col,
                'val' => $candidate_id,
                'party' => $c_data['party'] ?? 'Independent',
                'photo' => $c_data['photo'] ?? null,
                'is_abstain' => false
            ];
        } else {
            $candidate_id = 'abstain'; // Fallback if ID not found
        }
    }
    
    if ($candidate_id === 'abstain') {
        $display_list[] = [
            'label' => $label,
            'name' => 'Abstain',
            'db_col' => $db_col,
            'val' => 'abstain',
            'party' => 'No selection made',
            'photo' => null,
            'is_abstain' => true
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Ballot - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        :root { 
            --primary-green: #3b4d3b; 
            --accent-green: #5a7d5a;
            --soft-bg: #f4f7f4; 
            --text-dark: #1e291e;
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        
        body { 
            background-color: #5a7d5a; /* Theme Green Background */
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            margin: 0;
        }

        /* Hero Header Section */
        .page-header {
            background-color: var(--primary-green);
            padding: 60px 20px 100px;
            text-align: center;
            color: white;
        }
        .page-header h1 { font-size: 32px; font-weight: 800; margin-bottom: 10px; margin-top: 0; }
        .page-header p { opacity: 0.8; font-size: 16px; margin: 0; }

        .container { 
            max-width: 900px; 
            margin: -60px auto 40px; 
            padding: 0 20px; 
        }

        .conf-list { display: flex; flex-direction: column; gap: 16px; }

        /* The Card */
        .conf-card { 
            background: #fff; 
            border-radius: 16px; 
            padding: 20px 30px; 
            display: flex; 
            align-items: center; 
            justify-content: space-between;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        .conf-card:hover { transform: translateY(-2px); }

        .card-left { display: flex; align-items: center; gap: 24px; }

        .conf-img { 
            width: 64px; 
            height: 64px; 
            border-radius: 14px; 
            overflow: hidden; 
            background: #f0f0f0; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            border: 2px solid #eee;
        }
        .conf-img img { width: 100%; height: 100%; object-fit: cover; }

        .conf-info { display: flex; flex-direction: column; }
        .conf-label { font-size: 11px; font-weight: 800; color: var(--accent-green); text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 4px; }
        .conf-name { font-size: 20px; font-weight: 700; color: var(--text-dark); }
        .conf-party { font-size: 14px; color: #666; margin-top: 2px; }

        /* Change Button - White text and icon */
        .btn-change { 
            text-decoration: none; 
            color: #ffffff; 
            background: var(--primary-green);
            font-size: 13px; 
            font-weight: 700; 
            padding: 10px 20px; 
            border-radius: 50px; 
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-change:hover { background: var(--accent-green); transform: scale(1.05); }
        .btn-change .material-icons { font-size: 18px; color: #ffffff; }

        /* Action Bar */
        .action-bar { 
            margin-top: 40px;
            display: flex; 
            gap: 16px; 
            justify-content: flex-end; 
            padding-bottom: 50px;
        }

        .btn-footer {
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-cancel { background: white; color: var(--primary-green); border: 2px solid white; }
        .btn-cancel:hover { background: #e2e8e2; }

        .btn-submit { background: var(--primary-green); color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .btn-submit:hover { background: #2d3b2d; transform: scale(1.02); }

        /* Abstain State */
        .conf-card.abstain { 
            background: rgba(255, 255, 255, 0.8); 
            border: 2px dashed rgba(0,0,0,0.1); 
            box-shadow: none;
        }
        .conf-card.abstain .conf-name { color: #888; }
        .conf-card.abstain .conf-label { color: #999; }
    </style>
</head>
<body>

    <nav class="navbar">
        <span class="material-icons" id="menuIcon" style="cursor:pointer; color: white;">menu</span>
        <h1 style="color: white; margin-left: 15px;">UniBallot</h1>
    </nav>

    <div class="page-header">
        <h1>Final Review</h1>
        <p>Please double-check your selections for the <strong><?php echo htmlspecialchars($election_type); ?></strong> election.</p>
    </div>

    <div class="container">
        <form action="submitvote.php" method="POST" id="confirmForm">
            <input type="hidden" name="election_type" value="<?php echo htmlspecialchars($election_type); ?>">
            
            <div class="conf-list">
                <?php foreach ($display_list as $item): ?>
                    <div class="conf-card <?php echo $item['is_abstain'] ? 'abstain' : ''; ?>">
                        
                        <div class="card-left">
                            <div class="conf-img">
                                <?php if ($item['photo']): ?>
                                    <img src="../assets/candidates/<?php echo htmlspecialchars($item['photo']); ?>" alt="">
                                <?php else: ?>
                                    <span class="material-icons" style="color:#bcc4bc; font-size: 32px;">
                                        <?php echo $item['is_abstain'] ? 'block' : 'person'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="conf-info">
                                <span class="conf-label"><?php echo $item['label']; ?></span>
                                <div class="conf-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="conf-party"><?php echo htmlspecialchars($item['party']); ?></div>
                            </div>
                        </div>

                        <a href="votepage.php?edit=<?php echo $item['db_col']; ?>" class="btn-change">
                            <span class="material-icons">edit</span>
                            Change
                        </a>
                        
                        <!-- Hidden inputs to send IDs to submitvote.php -->
                        <input type="hidden" name="<?php echo $item['db_col']; ?>" value="<?php echo htmlspecialchars($item['val']); ?>">
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="action-bar">
                <button type="button" onclick="window.location.href='votepage.php'" class="btn-footer btn-cancel">
                    <span class="material-icons">arrow_back</span>
                    BACK TO BALLOT
                </button>
                <button type="submit" class="btn-footer btn-submit">
                    CONFIRM & SUBMIT VOTE
                    <span class="material-icons">check_circle</span>
                </button>
            </div>
        </form>
    </div>

    <!-- DRAWER -->
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
            <a href="votinghistory.php" class="nav-item"><span class="material-icons">history</span>Voting History</a>
            <a href="electioninfo.php" class="nav-item"><span class="material-icons">info</span>Election Info</a>
            <a href="result.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="settings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <script>
        const menuIcon = document.getElementById('menuIcon');
        const drawer = document.getElementById('drawer');
        const overlay = document.getElementById('drawerOverlay');
        
        menuIcon.onclick = () => { drawer.classList.add('open'); overlay.classList.add('active'); };
        const closeMenu = () => { drawer.classList.remove('open'); overlay.classList.remove('active'); };
        document.getElementById('closeIcon').onclick = closeMenu;
        overlay.onclick = closeMenu;

        document.getElementById('confirmForm').onsubmit = function() { 
            const btn = this.querySelector('.btn-submit');
            btn.disabled = true; 
            btn.style.opacity = "0.7";
            btn.innerHTML = "CASTING VOTE..."; 
        };
    </script>
</body>
</html>