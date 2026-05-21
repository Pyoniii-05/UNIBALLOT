<?php
// consent.php

session_start();

// Database connection
require_once '../db_connect.php';

// Check if voter is logged in
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

$stu_no = $_SESSION['stu_no'];

// Check if voter has already voted
$sql = "SELECT status FROM voters WHERE stu_no = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $stu_no);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    // If voter has already voted, redirect to voting page (which handles the "already voted" view)
    if ($row['status'] === 'voted') {
        header("Location: votepage.php");
        exit();
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
    <title>Voter Consent - UniBallot Election</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body class="flex-center">

    <div class="card-wrapper">
        <div class="consent-card">
            <div class="header-icon-circle">
                <span class="material-icons">gavel</span>
            </div>
            
            <h1 class="card-title">Voter Consent</h1>
            <p class="card-desc">Please review the terms below to proceed.</p>
            
            <ul class="consent-list">
                <li>
                    <span class="material-icons list-icon">check_circle_outline</span>
                    <span>I understand the rules and terms of this election process.</span>
                </li>
                <li>
                    <span class="material-icons list-icon">check_circle_outline</span>
                    <span>I agree to cast only <strong>one vote</strong> in this election.</span>
                </li>
                <li>
                    <span class="material-icons list-icon">check_circle_outline</span>
                    <span>I confirm my identity as the registered student voter.</span>
                </li>
                <li>
                    <span class="material-icons list-icon">check_circle_outline</span>
                    <span>I understand my vote is final and cannot be changed.</span>
                </li>
            </ul>
            
            <div class="checkbox-container" id="checkboxBox">
                <input type="checkbox" id="consent-checkbox">
                <label for="consent-checkbox" class="checkbox-label">
                    I have read and understood the terms above.
                </label>
            </div>
            <br>
            <button id="agree-button" class="btn-confirm" disabled>I Agree & Continue</button>
        </div>
    </div>

    <script>
        sessionStorage.setItem('drawerOpen', '0');
        
        document.addEventListener('DOMContentLoaded', function() {
            const checkbox = document.getElementById('consent-checkbox');
            const checkboxBox = document.getElementById('checkboxBox');
            const button = document.getElementById('agree-button');
            
            // Function to toggle state
            function toggleState() {
                if (checkbox.checked) {
                    button.disabled = false;
                    checkboxBox.classList.add('checked');
                } else {
                    button.disabled = true;
                    checkboxBox.classList.remove('checked');
                }
            }

            // Click listener for the container (better UX)
            checkboxBox.addEventListener('click', function(e) {
                // Prevent double toggling if clicking directly on the input
                if (e.target !== checkbox) {
                    checkbox.checked = !checkbox.checked;
                    toggleState();
                }
            });

            // Change listener for the actual input
            checkbox.addEventListener('change', toggleState);
            
            // Handle button click
            button.addEventListener('click', function() {
                if (checkbox.checked) {
                    window.location.href = 'votepage.php';
                }
            });
        });
    </script>
</body>
</html>