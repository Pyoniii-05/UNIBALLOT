<?php
// update_election_status.php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// Since this is in the parent folder, db_connect is likely in the same folder
require_once 'db_connect.php'; 

if (!isset($_SESSION['stu_no'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$student_no = $_SESSION['stu_no'];

// 1. Get Student Department for SC settings
$stmt = $conn->prepare("SELECT department FROM students WHERE stu_no = ?");
$stmt->bind_param("s", $student_no);
$stmt->execute();
$student_dept = $stmt->get_result()->fetch_assoc()['department'] ?? 'General';

/**
 * Logic to determine status based on DB settings and Time
 */
function determineStatus($row) {
    if (!$row) return 'closed';
    // If Manual Override is ON, use the status column exactly as it is
    if (isset($row['manual_override']) && $row['manual_override'] === 'yes') {
        return $row['status'];
    }
    // Otherwise, calculate based on time
    $now = time();
    $start = !empty($row['voting_start']) ? strtotime($row['voting_start']) : 0;
    $end = !empty($row['voting_end']) ? strtotime($row['voting_end']) : 0;

    if ($start === 0 || $end === 0) return 'closed';
    if ($now < $start) return 'upcoming';
    if ($now > $end) return 'closed';
    return 'ongoing';
}

// 2. Fetch USP Data (Global ID 1)
$usp = $conn->query("SELECT * FROM election_info WHERE id = 1")->fetch_assoc();
$usp_status = determineStatus($usp);

// 3. Fetch SC Data (Dept specific with fallback to Global ID 2)
$stmt = $conn->prepare("SELECT * FROM dept_settings WHERE department = ?");
$stmt->bind_param("s", $student_dept);
$stmt->execute();
$sc = $stmt->get_result()->fetch_assoc();
if (!$sc) {
    $sc = $conn->query("SELECT * FROM election_info WHERE id = 2")->fetch_assoc();
}
$sc_status = determineStatus($sc);

// 4. Update the Database Status automatically (if override is NO)
if ($usp['manual_override'] === 'no' && $usp_status !== $usp['status']) {
    $conn->query("UPDATE election_info SET status = '$usp_status' WHERE id = 1");
}

// 5. Compare with Session to detect changes
$current_state = $usp_status . "|" . $sc_status;
$session_state = $_SESSION['last_known_state'] ?? '';

$changed = false;
if ($current_state !== $session_state) {
    $_SESSION['last_known_state'] = $current_state;
    $changed = true;
}

echo json_encode([
    'status_changed' => $changed,
    'usp' => $usp_status,
    'sc' => $sc_status,
    'usp_title' => $usp['election_name'] ?? 'USP Global Election',
    'sc_title' => $sc['election_name'] ?? ($student_dept . ' Council'),
    'usp_start' => !empty($usp['voting_start']) ? date('M j, g:i A', strtotime($usp['voting_start'])) : 'TBA',
    'usp_end' => !empty($usp['voting_end']) ? date('M j, g:i A', strtotime($usp['voting_end'])) : 'TBA',
    'sc_start' => !empty($sc['voting_start']) ? date('M j, g:i A', strtotime($sc['voting_start'])) : 'TBA',
    'sc_end' => !empty($sc['voting_end']) ? date('M j, g:i A', strtotime($sc['voting_end'])) : 'TBA'
]);