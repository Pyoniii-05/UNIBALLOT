<?php
// user page/waiting_room.php
session_start();
if (!isset($_SESSION['temp_stu_no'])) {
    header("Location: ../index.php"); // Kick out if not in login process
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    
    <!-- Custom Styles -->
    <link href="../styles/user.css" rel="stylesheet">
    
    <style>
        /* specific styles for the spinner using your CSS variables */
        .spinner { 
            border: 4px solid var(--white-soft); 
            border-top: 4px solid var(--primary-green); 
            border-radius: 50%; 
            width: 50px; 
            height: 50px; 
            animation: spin 1s linear infinite; 
            margin: 30px auto 15px auto; 
        }
        
        .waiting-text {
            font-size: 13px; 
            font-weight: 600;
            color: var(--secondary-green);
            animation: fadeIn 1.5s infinite alternate;
        }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>

<body class="flex-center">

    <div class="card-box">
        <!-- Icon Wrapper from user.css -->
        <div class="icon-wrapper" style="background-color: var(--primary-green);">
            <span class="material-icons">mark_email_unread</span>
        </div>

        <h2>Check your Email</h2>
        
        <!-- Message box using user.css styles -->
        <div class="message-box">
            We sent a verification link to your email.<br>
            <strong>Please click the button on your phone to continue.</strong>
        </div>

        <div class="spinner"></div>
        <p class="waiting-text">Waiting for approval...</p>
        
        <!-- Optional: Link back to login if they are stuck -->
        <a href="../index.php" class="btn-link">Cancel and return to Login</a>
    </div>

    <script>
        // Check status every 2 seconds
        setInterval(function() {
            fetch('check_approval.php')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'approved') {
                    // SUCCESS! Redirect to the dashboard/consent page
                    window.location.href = data.redirect;
                }
            })
            .catch(error => console.error('Error fetching approval status:', error));
        }, 2000);
    </script>
</body>
</html>