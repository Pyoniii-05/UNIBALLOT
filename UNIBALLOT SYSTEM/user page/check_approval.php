<?php
// user page/check_approval.php
session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['temp_stu_no'])) {
    echo json_encode(['status' => 'error']);
    exit();
}

$stu_no = $_SESSION['temp_stu_no'];

// Check DB
$stmt = $conn->prepare("SELECT * FROM voters WHERE stu_no = ?");
$stmt->bind_param("s", $stu_no);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row && $row['verify_status'] === 'APPROVED') {
    
    // 1. GENERATE TRUSTED DEVICE TOKEN
    $device_token = bin2hex(random_bytes(32)); // Long random string

    // 2. SAVE COOKIE TO BROWSER (Expires in 30 Days)
    // Name: "uspelect_device", Value: $device_token, Time: 30 days, Path: "/"
    setcookie("uspelect_device", $device_token, time() + (86400 * 30), "/");

    // 3. SAVE TOKEN TO DATABASE
    // We also clear the verify status and login token here
    $update = $conn->prepare("UPDATE voters SET device_token = ?, verify_status = 'NONE', login_token = NULL WHERE stu_no = ?");
    $update->bind_param("ss", $device_token, $stu_no);
    $update->execute();

    // 4. LOG THEM IN
    $_SESSION['stu_no'] = $row['stu_no'];
    $_SESSION['email'] = $row['email'];
    $_SESSION['department'] = $row['department'];
    $_SESSION['program'] = $row['program'];
    $_SESSION['is_admin'] = 0;
    $_SESSION['fresh_login'] = true;
    
    unset($_SESSION['temp_stu_no']);

    $redirect = ($row['status'] === 'voted') ? 'votepage.php' : 'consent.php';
    echo json_encode(['status' => 'approved', 'redirect' => $redirect]);

} else {
    echo json_encode(['status' => 'pending']);
}
?>