<?php
// student_council.php

session_start();

if (!isset($_SESSION['stu_no']) || empty($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];
$firstname = 'Student';
$lastname = 'User';
$initials = 'SU';

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
        }
        $profile_stmt->close();
    }
}

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

$stmt->bind_param('sssssssss',
    $positions[0],
    $positions[1],
    $positions[2],
    $positions[3],
    $positions[4],
    $positions[5],
    $positions[6],
    $positions[7],
    $positions[8]
);

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
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css?v=<?php echo time(); ?>">
    <style>
        .student-council-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .sc-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            padding: 20px;
            border: 1px solid #f0f0f0;
        }
        .sc-card h3 {
            font-size: 18px;
            margin-bottom: 16px;
            color: #2f3e3f;
        }
        .candidate-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #f2f2f2;
        }
        .candidate-item:last-child { border-bottom: none; }
        .candidate-photo {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: cover;
            background: #e5e5e5;
            flex-shrink: 0;
        }
        .candidate-meta {
            display: grid;
            gap: 4px;
        }
        .candidate-meta strong {
            font-size: 14px;
            color: #1f2f30;
        }
        .candidate-meta span {
            font-size: 12px;
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
            font-size: 24px;
            color: var(--primary-green);
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
            <h2>Student Menu</h2>
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

    <div class="container">
        <div class="dashboard-header">
            <div>
                <h1 class="dashboard-title"><span class="material-icons" style="color: var(--primary-green); font-size: 34px;">groups</span> Student Council</h1>
                <p style="margin-top: 8px; color: #586168;">Review all registered Student Council positions and their candidates.</p>
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
                        <p style="color: #6d7578; line-height: 1.6;">No registered candidates have been added for this position yet.</p>
                    <?php else: ?>
                        <?php foreach ($candidates_by_position[$position] as $candidate): ?>
                            <div class="candidate-item">
                                <?php if (!empty($candidate['photo']) && file_exists(__DIR__ . '/../assets/candidates/' . $candidate['photo'])): ?>
                                    <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" alt="Photo" class="candidate-photo">
                                <?php else: ?>
                                    <div class="candidate-photo"></div>
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

    <script>
        const menuIcon = document.getElementById('menuIcon');
        const drawer = document.getElementById('drawer');
        const overlay = document.getElementById('drawerOverlay');
        const closeIcon = document.getElementById('closeIcon');

        menuIcon.addEventListener('click', () => {
            drawer.classList.add('open');
            overlay.classList.add('active');
        });

        closeIcon.addEventListener('click', () => {
            drawer.classList.remove('open');
            overlay.classList.remove('active');
        });

        overlay.addEventListener('click', () => {
            drawer.classList.remove('open');
            overlay.classList.remove('active');
        });

        document.getElementById('logoutLink').addEventListener('click', () => {
            window.location.href = 'logout.php';
        });
    </script>
</body>
</html>
