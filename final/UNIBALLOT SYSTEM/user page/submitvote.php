<?php
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db_connect.php';

$student_no = $_SESSION['stu_no'];
$election_type = $_POST['election_type'] ?? 'USP';
$message = '';
$status_type = 'error';

if (isset($_POST['return_home'])) {
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// ==========================================
// 1. SMART VOTE CHECK
// ==========================================
// We check if a row exists AND if the specific columns for this election are already filled
$check = $conn->prepare("SELECT prime_minister, sc_president FROM votes WHERE stu_no = ?");
$check->bind_param("s", $student_no);
$check->execute();
$voted_data = $check->get_result()->fetch_assoc();

$already_voted = false;
$is_update = false;

if ($voted_data) {
    $is_update = true; // A row exists, so we will use UPDATE instead of INSERT
    if ($election_type === 'USP' && !empty($voted_data['prime_minister'])) {
        $already_voted = true;
    } elseif ($election_type === 'SC' && !empty($voted_data['sc_president'])) {
        $already_voted = true;
    }
}

if ($already_voted) {
    $message = "You have already cast your ballot for the <b>$election_type</b> election.";
    $status_type = 'warning';
} else {
    // ==========================================
    // 2. PREPARE CANDIDATE NAMES
    // ==========================================
    $usp_positions = ['prime_minister', 'executive_prime_minister', 'secretary_general', 'treasurer', 'auditor'];
    $sc_positions = ['sc_president', 'sc_vice_president', 'sc_secretary', 'sc_treasurer', 'sc_auditor', 'sc_rep1', 'sc_rep2', 'sc_rep3', 'sc_rep4'];

    $active_positions = ($election_type === 'USP') ? $usp_positions : $sc_positions;
    $final_votes = [];

    foreach ($active_positions as $pos) {
        $candidate_id = $_POST[$pos] ?? 'abstain';
        if ($candidate_id !== 'abstain' && !empty($candidate_id)) {
            $name_stmt = $conn->prepare("SELECT firstname, lastname FROM candidates WHERE id = ?");
            $name_stmt->bind_param("i", $candidate_id);
            $name_stmt->execute();
            $res = $name_stmt->get_result();
            if ($data = $res->fetch_assoc()) {
                $final_votes[$pos] = $data['firstname'] . ' ' . $data['lastname'];
            } else {
                $final_votes[$pos] = 'Abstain';
            }
        } else {
            $final_votes[$pos] = 'Abstain';
        }
    }

    // ==========================================
    // 3. INSERT OR UPDATE
    // ==========================================
    if ($election_type === 'USP') {
        if ($is_update) {
            $sql = "UPDATE votes SET prime_minister=?, executive_prime_minister=?, secretary_general=?, treasurer=?, auditor=? WHERE stu_no=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssss", $final_votes['prime_minister'], $final_votes['executive_prime_minister'], $final_votes['secretary_general'], $final_votes['treasurer'], $final_votes['auditor'], $student_no);
        } else {
            $sql = "INSERT INTO votes (stu_no, prime_minister, executive_prime_minister, secretary_general, treasurer, auditor) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssss", $student_no, $final_votes['prime_minister'], $final_votes['executive_prime_minister'], $final_votes['secretary_general'], $final_votes['treasurer'], $final_votes['auditor']);
        }
    } else {
        if ($is_update) {
            $sql = "UPDATE votes SET sc_president=?, sc_vice_president=?, sc_secretary=?, sc_treasurer=?, sc_auditor=?, sc_rep1=?, sc_rep2=?, sc_rep3=?, sc_rep4=? WHERE stu_no=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssssss", $final_votes['sc_president'], $final_votes['sc_vice_president'], $final_votes['sc_secretary'], $final_votes['sc_treasurer'], $final_votes['sc_auditor'], $final_votes['sc_rep1'], $final_votes['sc_rep2'], $final_votes['sc_rep3'], $final_votes['sc_rep4'], $student_no);
        } else {
            $sql = "INSERT INTO votes (stu_no, sc_president, sc_vice_president, sc_secretary, sc_treasurer, sc_auditor, sc_rep1, sc_rep2, sc_rep3, sc_rep4) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssssss", $student_no, $final_votes['sc_president'], $final_votes['sc_vice_president'], $final_votes['sc_secretary'], $final_votes['sc_treasurer'], $final_votes['sc_auditor'], $final_votes['sc_rep1'], $final_votes['sc_rep2'], $final_votes['sc_rep3'], $final_votes['sc_rep4']);
        }
    }

    if ($stmt->execute()) {
        // Success Logic
        unset($_SESSION['temp_votes']);
        unset($_SESSION['active_confirmation_type']);
        $message = "Your vote for the <b>$election_type</b> election has been securely recorded.";
        $status_type = 'success';
    } else {
        $message = "Error: " . $conn->error;
        $status_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote Status - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary-green: #3b4d3b; 
            --accent-green: #5a7d5a;
            --success-color: #2e7d32;
            --warning-color: #f57c00;
            --error-color: #d32f2f;
        }
        
        body { 
            background-color: #5a7d5a; 
            font-family: 'Inter', sans-serif;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .status-card {
            background: white;
            width: 90%;
            max-width: 500px;
            padding: 40px;
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .icon-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }

        .icon-circle.success { background: #e8f5e9; color: var(--success-color); }
        .icon-circle.warning { background: #fff3e0; color: var(--warning-color); }
        .icon-circle.error { background: #ffebee; color: var(--error-color); }

        .icon-circle .material-icons { font-size: 48px; }

        h2 { color: var(--primary-green); font-size: 24px; font-weight: 800; margin: 0 0 12px; }
        p { color: #555; line-height: 1.6; font-size: 16px; margin-bottom: 30px; }

        .btn-exit {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: 0.3s;
            text-decoration: none;
        }

        .btn-exit:hover { background: #2d3b2d; transform: translateY(-2px); }

        .history-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--accent-green);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .history-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <div class="status-card">
        <div class="icon-circle <?php echo $status_type; ?>">
            <span class="material-icons">
                <?php 
                    if ($status_type === 'success') echo "check_circle";
                    elseif ($status_type === 'warning') echo "info";
                    else echo "error";
                ?>
            </span>
        </div>

        <h2>
            <?php 
                if ($status_type === 'success') echo "Submission Successful";
                elseif ($status_type === 'warning') echo "Attention Required";
                else echo "System Error";
            ?>
        </h2>

        <p><?php echo $message; ?></p>

        <form action="" method="POST">
            <button type="submit" name="return_home" class="btn-exit">
                EXIT SYSTEM
                <span class="material-icons">logout</span>
            </button>
        </form>

        <?php if ($status_type !== 'error'): ?>
            <a href="votinghistory.php" class="history-link">View My Voting History</a>
        <?php endif; ?>
    </div>

</body>
</html>