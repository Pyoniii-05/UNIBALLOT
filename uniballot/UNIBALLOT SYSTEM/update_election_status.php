<?php
// update_election_status.php
session_start();
header('Content-Type: application/json');

// 1. SET TIMEZONE & DB CONNECTION
date_default_timezone_set('Asia/Manila');

$conn = new mysqli('localhost', 'root', '', 'uspelect');
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}
$conn->query("SET time_zone = '+08:00'");

// 2. FETCH ELECTION INFO
$sql = "SELECT * FROM election_info WHERE id = 1";
$result = $conn->query($sql);
$row = $result->fetch_assoc();

if (!$row) {
    echo json_encode(['error' => 'No election data']);
    exit();
}

$db_status = $row['status'];
$manual_override = $row['manual_override']; // 'yes' or 'no'
$start = strtotime($row['voting_start']);
$end = strtotime($row['voting_end']);
$current_time = time();

$new_status = $db_status; // Default to current DB status

// 3. LOGIC: ONLY CALCULATE TIME IF OVERRIDE IS OFF
if ($manual_override === 'no') {
    if ($current_time < $start) {
        $new_status = 'upcoming';
    } elseif ($current_time >= $start && $current_time <= $end) {
        $new_status = 'ongoing';
    } else {
        $new_status = 'closed';
    }

    // 4. UPDATE DB IF TIME CALCULATION DIFFERS FROM DB
    if ($new_status !== $db_status) {
        $update_sql = "UPDATE election_info SET status = ?, updated_at = NOW() WHERE id = 1";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("s", $new_status);
        $stmt->execute();
        $stmt->close();
        // Update variable for the next check
        $db_status = $new_status; 
    }
}

// 5. CHECK AGAINST CLIENT SESSION
// We compare the final status ($new_status) against what the user has in their session
$session_status = isset($_SESSION['current_election_status']) ? $_SESSION['current_election_status'] : '';

if ($new_status !== $session_status) {
    // STATUS CHANGED
    $_SESSION['current_election_status'] = $new_status; // Update session
    echo json_encode([
        'status_changed' => true,
        'old_status' => $session_status,
        'new_status' => $new_status
    ]);
} else {
    // NO CHANGE
    echo json_encode([
        'status_changed' => false,
        'current_status' => $new_status
    ]);
}

$conn->close();
?>