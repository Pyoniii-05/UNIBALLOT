<?php
session_start();

// 1. Clear Data
$_SESSION = array();

// 2. Kill Cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy Session
session_destroy();

// 4. Redirect
header("Location: index.php"); // Go back to main login
exit();
?>