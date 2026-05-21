<?php
// approve_login.php
require_once 'db_connect.php';

$token = isset($_GET['t']) ? $_GET['t'] : '';
$stu_no = isset($_GET['u']) ? $_GET['u'] : '';

// Default state: Error
$status = 'error'; 
$title = 'Invalid Link';
$message = 'The verification link is invalid or has expired.';
$icon = 'error_outline';

if ($token && $stu_no) {
    // Verify token
    $stmt = $conn->prepare("SELECT * FROM voters WHERE stu_no = ? AND login_token = ?");
    $stmt->bind_param("ss", $stu_no, $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // ✅ APPROVED! Update DB so the device knows.
        $update = $conn->prepare("UPDATE voters SET verify_status = 'APPROVED' WHERE stu_no = ?");
        $update->bind_param("s", $stu_no);
        
        if($update->execute()) {
            $status = 'success';
            $title = 'Login Approved!';
            // Device-agnostic message
            $message = 'You have successfully verified your identity. You may now return to your device; it should log in automatically.';
            $icon = 'check_circle';
        } else {
            $title = 'Database Error';
            $message = 'An error occurred while updating your status. Please refresh and try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?></title>
    
    <!-- Fonts and Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <!-- Linked to your provided user.css -->
    <link rel="stylesheet" href="./styles/user.css">
</head>x    

<body class="flex-center">
    
    <!-- 
       Classes used from user.css:
       - card-box (Section 8)
       - icon-wrapper, success, error (Section 8)
       - message-box, success, error (Section 8)
    -->
    <div class="card-box">
        <!-- Icon: Success or Error -->
        <div class="icon-wrapper <?php echo $status; ?>">
            <span class="material-icons"><?php echo $icon; ?></span>
        </div>

        <!-- Title -->
        <h2><?php echo $title; ?></h2>

        <!-- Message Box -->
        <div class="message-box <?php echo $status; ?>">
            <?php echo $message; ?>
        </div>
    </div>

</body>
</html>