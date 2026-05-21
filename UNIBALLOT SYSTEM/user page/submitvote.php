<?php
// ==========================================
// 1. BACKEND LOGIC (Working Version)
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
$student_department = $_SESSION['department'] ?? '';
$student_program = $_SESSION['program'] ?? '';
$message = '';
$status_type = 'error'; // Default status for UI styling (success, warning, error)

// Handle logout when returning to home
if (isset($_POST['return_home'])) {
    session_destroy();
    header("Location: ../logout.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['return_home'])) {
    // Get candidate IDs from form
    $prime_minister_id = $conn->real_escape_string($_POST['prime_minister'] ?? '');
    $executive_prime_minister_id = $conn->real_escape_string($_POST['executive_prime_minister'] ?? '');
    $secretary_general_id = $conn->real_escape_string($_POST['secretary_general'] ?? '');
    $treasurer_id = $conn->real_escape_string($_POST['treasurer'] ?? '');
    $auditor_id = $conn->real_escape_string($_POST['auditor'] ?? '');
    $student_council_id = $conn->real_escape_string($_POST['student_council'] ?? '');

    // Check if student has already voted
    $check = $conn->prepare("SELECT stu_no FROM votes WHERE stu_no = ?");
    $check->bind_param("s", $student_no);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $message = "You have already voted. Thank you for participating!";
        $status_type = 'warning';
    } else {
        // Get candidate names from their IDs
        $candidate_names = [];
        $positions = [
            'prime_minister' => $prime_minister_id,
            'executive_prime_minister' => $executive_prime_minister_id,
            'secretary_general' => $secretary_general_id,
            'treasurer' => $treasurer_id,
            'auditor' => $auditor_id,
            'student_council' => $student_council_id
        ];

        $student_department = trim($student_department);
        $invalid_student_council = false;

        if (!empty($student_council_id) && $student_council_id !== 'abstain') {
            $dept_sql = "SELECT department, program FROM candidates WHERE id = ? AND election_type = 'Student Council'";
            $dept_stmt = $conn->prepare($dept_sql);
            $dept_stmt->bind_param("s", $student_council_id);
            $dept_stmt->execute();
            $dept_result = $dept_stmt->get_result();
            if ($dept_row = $dept_result->fetch_assoc()) {
                if (!empty($student_department) && $dept_row['department'] !== $student_department) {
                    $invalid_student_council = true;
                }
                if (!empty($student_program) && $dept_row['program'] !== $student_program) {
                    $invalid_student_council = true;
                }
            } else {
                $student_council_id = 'abstain';
            }
            $dept_stmt->close();
        }

        if ($invalid_student_council) {
            $message = "Your Student Council selection is not valid for your department.";
            $status_type = 'error';
        } else {
            foreach ($positions as $position => $candidate_id) {
                $name_sql = "SELECT firstname, lastname FROM candidates WHERE id = ?";
                $name_stmt = $conn->prepare($name_sql);
                $name_stmt->bind_param("s", $candidate_id);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                if ($name_row = $name_result->fetch_assoc()) {
                    $candidate_names[$position] = $name_row['firstname'] . ' ' . $name_row['lastname'];
                } else {
                    $candidate_names[$position] = 'abstain';
                }
                $name_stmt->close();
            }

            // Ensure votes table has student_council column (add if missing)
            $col_check = $conn->query("SHOW COLUMNS FROM votes LIKE 'student_council'");
            if ($col_check && $col_check->num_rows === 0) {
                $alter_sql = "ALTER TABLE votes ADD COLUMN student_council VARCHAR(255) DEFAULT 'abstain' AFTER auditor";
                $conn->query($alter_sql);
            }

            // Insert vote into database
            $insert = $conn->prepare("INSERT INTO votes (stu_no, prime_minister, executive_prime_minister, secretary_general, treasurer, auditor, student_council) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert->bind_param("sssssss", 
                $student_no, 
                $candidate_names['prime_minister'],
                $candidate_names['executive_prime_minister'],
                $candidate_names['secretary_general'],
                $candidate_names['treasurer'],
                $candidate_names['auditor'],
                $candidate_names['student_council']
            );

            if ($insert->execute()) {
                // Update voter status
                $update_voter = $conn->prepare("UPDATE voters SET status = 'voted', voted_at = NOW() WHERE stu_no = ?");
                $update_voter->bind_param("s", $student_no);
                $update_voter->execute();
                $update_voter->close();
                
                // Clear session votes
                unset($_SESSION['votes']);
                $message = "Your vote has been successfully submitted! Thank you for voting!";
                $status_type = 'success';
            } else {
                $message = "There was an error submitting your vote. Please try again.";
                $status_type = 'error';
            }

            $insert->close();
        }
    }

    $check->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniBallot Voting Status</title>
    <!-- Material Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body class="flex-center">
    <div class="container">
        <div class="card-box">
            
            <!-- Icon Logic -->
            <div class="icon-wrapper <?php echo $status_type; ?>">
                <?php if ($status_type === 'success'): ?>
                    <span class="material-icons">check_circle</span>
                <?php elseif ($status_type === 'warning'): ?>
                    <span class="material-icons">priority_high</span>
                <?php else: ?>
                    <span class="material-icons">error</span>
                <?php endif; ?>
            </div>

            <!-- Title Logic -->
            <h2>
                <?php 
                    if ($status_type === 'success') echo "Voting Complete";
                    elseif ($status_type === 'warning') echo "Vote Recorded";
                    else echo "Submission Error";
                ?>
            </h2>

            <!-- Message Logic -->
            <div class="message-box <?php echo $status_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>

            <!-- Action Button -->
            <form action="" method="post">
                <button type="submit" name="return_home" value="1" class="btn-confirm">
                    Exit System <span class="material-icons" style="font-size:18px;">logout</span>
                </button>
            </form>

            <?php if ($status_type !== 'error'): ?>
                <a href="votinghistory.php?from=submission" class="btn-link">View My Voting History</a>
            <?php endif; ?>

        </div>
    </div>

</body>
</html>