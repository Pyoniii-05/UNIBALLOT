<?php
// ==========================================
// UNIVERSAL DATABASE CONNECTION FILE
// ==========================================

// 1. Set PHP Timezone
// This ensures all PHP date() functions use Manila time automatically.
date_default_timezone_set('Asia/Manila');

// 2. Database Credentials
// Change these settings when you upload to a live server.
$servername = "localhost";
$username   = "root";         // Default XAMPP/WAMP username
$password   = "";             // Default XAMPP/WAMP password (usually empty)
$dbname     = "uspelect";     // Your database name

// 3. Create Connection
// We use the object-oriented style ($conn)
$conn = new mysqli($servername, $username, $password, $dbname);

// 4. Check Connection
if ($conn->connect_error) {
    // Stop the script and show an error message if connection fails
    die("Database Connection Failed: " . $conn->connect_error);
}

// 5. Set Character Set
// This ensures special characters (ñ, emojis, etc.) save and display correctly.
$conn->set_charset("utf8mb4");

// 6. Set MySQL Timezone
// This ensures SQL queries like NOW() use Manila time (+8:00).
$conn->query("SET time_zone = '+08:00'");

?>