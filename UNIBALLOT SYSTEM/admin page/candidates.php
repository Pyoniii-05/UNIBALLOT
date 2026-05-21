<?php
session_start();

// ==========================================
// 1. DATABASE CONNECTION (Moved Up for Security Check)
// ==========================================
require_once '../db_connect.php';
ensure_candidates_election_type_column($conn);

// ==========================================
// 2. LOGOUT LOGIC
// ==========================================
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header("Location: ../index.php");
    exit();
}

// ==========================================
// 3. STRICT SECURITY CHECK (Base on Tables)
// ==========================================

// Ensure session exists
if (!isset($_SESSION['stu_no'])) {
    header("Location: ../index.php");
    exit();
}

$student_no = $_SESSION['stu_no'];
$is_authorized = false;
$firstname = "Admin";
$lastname = "User";

// A. IF USER IS 'ORG' -> CHECK 'ADMINS' TABLE
if ($student_no === 'ORG') {
    $stmt = $conn->prepare("SELECT firstname, lastname FROM admins WHERE username = ?");
    $stmt->bind_param("s", $student_no);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $is_authorized = true;
        $firstname = $row['firstname'];
        $lastname = $row['lastname'];
    }
} 
// B. IF USER IS STUDENT -> CHECK 'VOTERS' TABLE FOR ADMIN PRIVILEGE
else {
    $stmt = $conn->prepare("SELECT firstname, lastname, is_admin FROM voters WHERE stu_no = ? AND is_admin = 1");
    $stmt->bind_param("s", $student_no);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $is_authorized = true;
        $firstname = $row['firstname'];
        $lastname = $row['lastname'];
    }
}

// If not authorized in either table, kick them out
if (!$is_authorized) {
    header("Location: ../index.php");
    exit();
}

// Generate initials for the sidebar
$initials = strtoupper(substr($firstname, 0, 1) . substr($lastname, 0, 1));


// Department → Programs mapping
$departmentPrograms = [
    "CBA" => ["BSBA", "BSE", "BSOA"],
    "CAS" => ["BSP", "BSE"],
    "CCST" => ["BSIS", "BSIT"],
    "COE" => ["BSCE"],
    "CTED" => ["BSE"],
    "CONAHS" => ["BSN"],
    "CTHM" => ["BSHM", "BSTM"],
    "CHK" => ["BSHK"],
    "COA" => ["BSA", "BSAIS", "BSMA"]
];

// Program full names mapping
$programFullNames = [
    "BSA" => "Bachelor of Science in Accountancy",
    "BSAIS" => "Bachelor of Science in Accounting Information System",
    "BSMA" => "Bachelor of Science in Management Accounting",
    "BSE" => "Bachelor of Science in Economics",
    "BSP" => "Bachelor of Science in Psychology",
    "BSBA" => "Bachelor of Science in Business Administration",
    "BSOA" => "Bachelor of Science in Office Administration",
    "BSIS" => "Bachelor of Science in Information Systems",
    "BSIT" => "Bachelor of Science in Information Technology",
    "BSCE" => "Bachelor of Science in Computer Engineering",
    "BSHM" => "Bachelor of Science in Hospitality Management",
    "BSTM" => "Bachelor of Science in Tourism Management",
    "BSN" => "Bachelor of Science in Nursing",
    "BSHK" => "Bachelor of Science in Human Kinetics"
];

// ==========================================
// HANDLE FORM SUBMISSIONS
// ==========================================

// Add new candidate
if (isset($_POST['add_candidate'])) {
    $firstname = strtoupper($conn->real_escape_string($_POST['firstname']));
    $lastname = strtoupper($conn->real_escape_string($_POST['lastname']));
    
    $position = $conn->real_escape_string($_POST['position']);
    $election_type = $conn->real_escape_string($_POST['election_type'] ?? 'USP');
    $party = $conn->real_escape_string($_POST['party']);
    $year_level = $conn->real_escape_string($_POST['year_level']);
    $department = $conn->real_escape_string($_POST['department']);
    $program = $conn->real_escape_string($_POST['program']);
    $message = $conn->real_escape_string($_POST['message']);
    
    // Handle file upload
    $photo_path = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "../assets/candidates/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $file_extension;
        $target_file = $target_dir . $filename;
        
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
            $photo_path = $filename;
        }
    }
    
    $sql = "INSERT INTO candidates (firstname, lastname, position, election_type, party, year_level, department, program, message, photo) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssss", $firstname, $lastname, $position, $election_type, $party, $year_level, $department, $program, $message, $photo_path);
    
    if ($stmt->execute()) {
        $_SESSION['toast_message'] = "Candidate added successfully!";
        $_SESSION['toast_type'] = "success";
    } else {
        $_SESSION['toast_message'] = "Error adding candidate: " . $conn->error;
        $_SESSION['toast_type'] = "error";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Update candidate
if (isset($_POST['update_candidate'])) {
    $id = $conn->real_escape_string($_POST['id']);
    $firstname = strtoupper($conn->real_escape_string($_POST['firstname']));
    $lastname = strtoupper($conn->real_escape_string($_POST['lastname']));
    
    $position = $conn->real_escape_string($_POST['position']);
    $election_type = $conn->real_escape_string($_POST['election_type'] ?? 'USP');
    $party = $conn->real_escape_string($_POST['party']);
    $year_level = $conn->real_escape_string($_POST['year_level']);
    $department = $conn->real_escape_string($_POST['department']);
    $program = $conn->real_escape_string($_POST['program']);
    $message = $conn->real_escape_string($_POST['message']);
    
    // Handle file upload
    $photo_path = $_POST['current_photo'];
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "../assets/candidates/";
        $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $file_extension;
        $target_file = $target_dir . $filename;
        
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
            if ($photo_path && file_exists($target_dir . $photo_path)) {
                unlink($target_dir . $photo_path);
            }
            $photo_path = $filename;
        }
    }
    
    $sql = "UPDATE candidates SET firstname=?, lastname=?, position=?, election_type=?, party=?, year_level=?, department=?, program=?, message=?, photo=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssssi", $firstname, $lastname, $position, $election_type, $party, $year_level, $department, $program, $message, $photo_path, $id);
    
    if ($stmt->execute()) {
        $_SESSION['toast_message'] = "Candidate updated successfully!";
        $_SESSION['toast_type'] = "success";
    } else {
        $_SESSION['toast_message'] = "Error updating candidate: " . $conn->error;
        $_SESSION['toast_type'] = "error";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Delete candidate
if (isset($_POST['delete_candidate'])) {
    $id = $conn->real_escape_string($_POST['id']);
    
    $sql = "SELECT photo FROM candidates WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidate = $result->fetch_assoc();
    
    if ($candidate['photo'] && file_exists("../assets/candidates/" . $candidate['photo'])) {
        unlink("../assets/candidates/" . $candidate['photo']);
    }
    
    $sql = "DELETE FROM candidates WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['toast_message'] = "Candidate deleted successfully!";
        $_SESSION['toast_type'] = "success";
    } else {
        $_SESSION['toast_message'] = "Error deleting candidate: " . $conn->error;
        $_SESSION['toast_type'] = "error";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch candidates
$candidates = [];
$sql = "SELECT * FROM candidates ORDER BY election_type, position, firstname, lastname ASC";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $candidates[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Candidates</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================
           VARIABLES & RESET
           ============================ */
        :root {
            --primary-green: #3b4d3b; 
            --secondary-green: #566b53; 
            --light-green: #7d9679; 
            --card-bg: #c4c2a5; 
            --overview-bg: #aabf9d;
            --text-dark: #121a1a; 
            --danger: #85211a; 
            --gold: #d4b200;
            --white-soft: #f0f0e6;
            --input-border: #c9c7ad;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: var(--light-green); color: var(--text-dark); min-height: 100vh; }

        /* ============================
           NAVBAR
           ============================ */
        .navbar { 
            background-color: var(--primary-green); 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25); 
            padding: 15px 30px; 
            display: flex; align-items: center; 
            position: sticky; top: 0; z-index: 100; 
        }
        .navbar .material-icons { color: #f2f2f2; margin-right: 15px; font-size: 28px; cursor: pointer; }
        .navbar h1 { font-size: 20px; font-weight: 700; color: #f2f2f2; letter-spacing: 0.5px; }

        /* ============================
           DRAWER
           ============================ */
        .drawer { 
            height: 100%; width: 280px; position: fixed; z-index: 200; 
            top: 0; left: -300px; 
            background-color: var(--white-soft); 
            transition: left 0.4s cubic-bezier(0.25, 0.8, 0.25, 1); 
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.3); 
        }
        .drawer.open { left: 0; }

        .drawer-header { 
            background-color: var(--primary-green); padding: 20px; 
            display: flex; justify-content: space-between; align-items: center; color: white; 
        }
        .drawer-profile { 
            padding: 25px 20px; 
            background: linear-gradient(to bottom, #e3e1c8, #d6d4ba);
            border-bottom: 1px solid #c9c7ad; 
            display: flex; align-items: center; gap: 15px;
        }
        .profile-avatar {
            width: 55px; height: 55px; border-radius: 50%;
            background: var(--primary-green); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 700;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15); border: 2px solid #fff;
            flex-shrink: 0; 
        }
        .profile-info h3 { font-size: 16px; font-weight: 800; margin-bottom: 2px; }
        .profile-info p { font-size: 13px; font-weight:600; color: #555; margin-bottom: 6px; }
        
        .admin-badge {
            background: linear-gradient(135deg, #FFD700, #FDB931); color: #5c4500;
            padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 800;
            display: inline-flex; text-transform: uppercase; letter-spacing: 0.5px;
        }

        .drawer-nav { padding: 15px 10px; }
        .nav-item { 
            display: flex; align-items: center; padding: 12px 20px; 
            color: var(--text-dark); text-decoration: none; 
            transition: all 0.2s; font-weight: 500; border-radius: 8px; margin-bottom: 5px;
        }
        .nav-item:hover { background-color: rgba(86, 107, 83, 0.15); color: var(--primary-green); }
        .nav-item.active { background-color: var(--primary-green); color: white; font-weight: 600; }
        .nav-item .material-icons { margin-right: 15px; font-size: 22px; color: inherit; }

        /* ============================
           MODALS & OVERLAYS
           ============================ */
        .overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(18, 26, 26, 0.6); backdrop-filter: blur(5px);
            z-index: 150; opacity: 0; visibility: hidden; 
            transition: opacity 0.3s ease, visibility 0.3s ease; 
        }
        .overlay.active { opacity: 1; visibility: visible; }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            z-index: 1000; opacity: 0; visibility: hidden;
            display: flex; justify-content: center; align-items: center;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(3px);
            transition: all 0.3s ease;
        }
        .modal-overlay.active { opacity: 1; visibility: visible; }

        .modal-box {
            background: #f0f0e6; width: 90%; max-width: 420px;
            border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.4);
            transform: scale(0.9) translateY(20px); opacity: 0;
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s ease;
            overflow: hidden; border: 1px solid rgba(255,255,255,0.5);
        }
        .modal-box.large { max-width: 800px; }
        .modal-overlay.active .modal-box { transform: scale(1) translateY(0); opacity: 1; }

        .modal-header { padding: 30px 20px 10px; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .modal-icon-circle {
            width: 70px; height: 70px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 15px; font-size: 32px;
        }
        .modal-box.info .modal-icon-circle { background-color: #e3e8e3; color: var(--primary-green); }
        .modal-box.danger .modal-icon-circle { background-color: #fde8e8; color: var(--danger); }

        .modal-title { font-size: 22px; font-weight: 800; color: var(--text-dark); margin-bottom: 10px; }
        .modal-desc { font-size: 15px; color: #666; line-height: 1.5; padding: 0 10px; margin-bottom: 20px; }
        .modal-actions { padding: 25px; display: flex; gap: 15px; justify-content: center;}
        
        .btn-modal {
            flex: 1; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 14px;
            cursor: pointer; border: none; transition: all 0.2s; text-transform: uppercase;
        }
        .btn-modal.cancel { background: #d6d4ba; color: #4a574a; }
        .btn-modal.cancel:hover { background: #c4c2a5; color: var(--text-dark); }
        .btn-modal.confirm-success { background: var(--primary-green); color: white; }
        .btn-modal.confirm-success:hover { background: var(--secondary-green); transform: translateY(-2px); }
        .btn-modal.confirm-danger { background: var(--danger); color: white; }
        .btn-modal.confirm-danger:hover { background: #6b1b15; transform: translateY(-2px); }

        /* ============================
           DASHBOARD & CONTENT
           ============================ */
        /* MATCHING DASHBOARD WIDTH */
        .container { 
            max-width: 1600px; 
            width: 95%; 
            margin: 30px auto; 
            padding: 20px; 
        }
        
        .dashboard-header { 
            background: var(--overview-bg); border-radius: 16px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15); 
            padding: 30px; margin-bottom: 30px; border: 1px solid rgba(0,0,0,0.05);
            display: flex; justify-content: space-between; align-items: center;
        }
        .dashboard-title { 
            font-size: 26px; font-weight: 800; color: var(--text-dark); 
            display: flex; align-items: center; gap: 12px; 
        }

        .content-card { 
            background: #c2caaeff; border-radius: 16px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1); 
            padding: 30px; margin-bottom: 30px; border: 1px solid #dcdac5;
        }
        .section-title { 
            font-size: 20px; font-weight: 800; color: var(--primary-green); margin-bottom: 25px; 
            padding-bottom: 15px; border-bottom: 2px solid #e3e1c8; 
            display: flex; align-items: center; gap: 10px; 
        }

        /* ============================
           FORMS & INPUTS
           ============================ */
        /* Adjusted grid for wider container */
        .form-row { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); 
            gap: 25px; 
            margin-bottom: 15px; 
        }
        
        .form-full { grid-column: 1 / -1; }
        .form-group { margin-bottom: 15px; }
        .form-group label { 
            display: block; margin-bottom: 8px; font-weight: 700; color: var(--text-dark); font-size: 14px; 
        }
        .form-control { 
            width: 100%; padding: 12px 15px; 
            border: 1px solid var(--input-border); border-radius: 8px; 
            font-size: 15px; background-color: #fff; color: #333;
            transition: all 0.3s ease;
        }
        .form-control:focus { 
            outline: none; border-color: var(--primary-green); 
            box-shadow: 0 0 0 3px rgba(59, 77, 59, 0.15); 
        }

        /* Profile Upload Specifics */
        .profile-upload-container {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            margin-bottom: 20px; 
            /* Spanning full width on mobile, but part of grid on desktop if inside grid container */
            grid-column: 1 / -1; 
        }
        .profile-preview-wrapper {
            position: relative; width: 120px; height: 120px; margin-bottom: 15px;
        }
        .preview-img {
            width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
            border: 4px solid var(--white-soft); 
            background-color: #e3e1c8;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        .placeholder-icon {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            font-size: 60px; color: var(--secondary-green); z-index: 1;
        }
        .preview-img[src] + .placeholder-icon { display: none; }
        
        .custom-file-upload {
            display: inline-flex; align-items: center; padding: 10px 20px;
            cursor: pointer; background-color:  #83816cff; color: #ebe9dcff;
            border: 1px solid var(--input-border); border-radius: 30px;
            font-size: 13px; font-weight: 700; transition: all 0.3s;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .custom-file-upload:hover { background-color: var(--primary-green); color: white; }
        #photo, #edit_photo { display: none; }

        .btn-submit {
            background-color: var(--primary-green); color: white;
            padding: 12px 30px; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 10px rgba(59, 77, 59, 0.3);
        }
        .btn-submit:hover { background-color: var(--secondary-green); transform: translateY(-2px); }

        /* ============================
           TABLE STYLES
           ============================ */
        .search-container { position: relative; margin-bottom: 20px; }
        .search-input { padding-left: 45px; background: #fff; }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #888; }
        .filter-select { border: 1px solid #ccc; border-radius: 12px; padding: 10px 14px; width: 220px; margin-left: 15px; background: #fff; font-size: 14px; color: #333; }

        .table-wrapper { 
            max-height: 500px; 
            overflow-y: auto; 
            overflow-x: auto; 
            border-radius: 12px; 
            border: 1px solid var(--input-border); 
            background: white;
        }
        /* Custom Scrollbar */
        .table-wrapper::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-wrapper::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .table-wrapper::-webkit-scrollbar-thumb { background: var(--secondary-green); border-radius: 4px; }
        .table-wrapper::-webkit-scrollbar-thumb:hover { background: var(--primary-green); }

        .candidates-table { width: 100%; border-collapse: collapse; }
        
        .candidates-table th { 
            background-color: var(--primary-green); color: white; 
            padding: 15px; text-align: left; font-weight: 600; font-size: 14px;
            position: sticky; top: 0; z-index: 10;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        }

        .candidates-table td { 
            padding: 15px; border-bottom: 1px solid #eee; color: #444; vertical-align: middle; 
        }
        .candidates-table tr:last-child td { border-bottom: none; }
        .candidates-table tr:hover { background-color: #f9f9f4; }
        
        .c-avatar { 
            width: 45px; height: 45px; border-radius: 50%; object-fit: cover; 
            border: 2px solid #e3e1c8; 
        }
        
        .action-btn { 
            padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; 
            border: none; cursor: pointer; margin-right: 5px; transition: all 0.2s;
        }
        .btn-edit { background: #e3e1c8; color: var(--primary-green); }
        .btn-edit:hover { background: var(--primary-green); color: white; }
        .btn-delete { background: #fde8e8; color: var(--danger); }
        .btn-delete:hover { background: var(--danger); color: white; }

        .pos-badge { 
            padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; 
            background: #eee; color: #555; display: inline-block;
        }
        .pos-pm { background: #3b4d3b; color: white; }
        .pos-epm { background: #566b53; color: white; }
        .pos-sg { background: #7d9679; color: white; }
        .pos-tr { background: #d4b200; color: white; }
        .pos-au { background: #85211a; color: white; }
        .pos-sc { background: #386b8f; color: white; }

        /* Toast */
        .custom-toast { 
            position: fixed; top: 30px; right: 30px; 
            background-color: #2b3636; color: #fff; 
            padding: 16px 24px; border-radius: 12px; 
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            z-index: 9999; transform: translateX(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .custom-toast.show { transform: translateX(0); }
        .custom-toast.success { border-left: 5px solid var(--primary-green); }
        .custom-toast.error { border-left: 5px solid var(--danger); }

        @media (max-width: 768px) { 
            .form-row { grid-template-columns: 1fr; }
            .container { padding: 15px; width: 100%; } 
            .dashboard-header { flex-direction: column; gap: 15px; text-align: center; }
            .action-btn { display: block; width: 100%; margin-bottom: 5px; text-align: center; }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <span class="material-icons" id="menuIcon">menu</span>
        <h1>ADMIN - MANAGE CANDIDATES</h1>
    </nav>

    <!-- DRAWER & OVERLAY -->
    <div class="overlay" id="drawerOverlay"></div>

    <div class="drawer" id="drawer">
        <div class="drawer-header">
            <h2>Admin Menu</h2>
            <span class="material-icons" id="closeIcon" style="cursor:pointer;">close</span>
        </div>
        
        <div class="drawer-profile">
            <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars($firstname . " " . $lastname); ?></h3>
                <p>ID: <?php echo htmlspecialchars($student_no); ?></p>
                <div class="admin-badge">Administrator</div>
            </div>
        </div>
        
        <div class="drawer-nav">
            <!-- Removed Back to Voting link -->
            <a href="dashboard.php" class="nav-item"><span class="material-icons">dashboard</span>Dashboard</a>
            <a href="candidates.php" class="nav-item active"><span class="material-icons">how_to_reg</span>Manage Candidates</a>
            <a href="voters.php" class="nav-item"><span class="material-icons">groups</span>Manage Voters</a>
            <a href="viewresult.php" class="nav-item"><span class="material-icons">bar_chart</span>View Results</a>
            <a href="electionsettings.php" class="nav-item"><span class="material-icons">settings</span>Settings</a>
            <a href="#" class="nav-item" id="logoutLink"><span class="material-icons">logout</span>Logout</a>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal-box info">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">logout</span></div>
                <h2 class="modal-title">Signing Out?</h2>
                <p class="modal-desc">You are about to end your session. Do you want to continue?</p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal cancel" id="cancelLogout">Cancel</button>
                <button class="btn-modal confirm-success" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="container">
        
        <!-- Toast Notification -->
        <?php 
        $just_updated = false;
        if (isset($_SESSION['toast_message'])): 
            $just_updated = true;
        ?>
            <div id="toastNotification" class="custom-toast show <?php echo $_SESSION['toast_type']; ?>">
                <span class="material-icons"><?php echo $_SESSION['toast_type'] === 'success' ? 'check_circle' : 'error'; ?></span>
                <span><?php echo htmlspecialchars($_SESSION['toast_message']); ?></span>
            </div>
            <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
        <?php endif; ?>

        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">
                <span class="material-icons" style="color: var(--primary-green); font-size: 32px;">groups</span>
                Candidate Management
            </h1>
            <div style="background: var(--white-soft); padding: 10px 20px; border-radius: 30px; font-weight: 700; color: var(--primary-green); box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                Total Candidates: <?php echo count($candidates); ?>
            </div>
        </div>

        <!-- Add Candidate Form -->
        <div class="content-card">
            <h2 class="section-title">
                <span class="material-icons">person_add</span> Add New Candidate
            </h2>
            
            <form action="" method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <!-- Profile Upload Section -->
                    <div class="profile-upload-container">
                        <div class="profile-preview-wrapper">
                            <img id="add-preview" class="preview-img" src="" alt="">
                            <span class="material-icons placeholder-icon" id="add-placeholder">person</span>
                        </div>
                        <label for="photo" class="custom-file-upload">
                            <span class="material-icons" style="margin-right: 5px;">photo_camera</span> 
                            Upload Photo
                        </label>
                        <input type="file" id="photo" name="photo" accept="image/*" onchange="previewImage(this, 'add-preview', 'add-placeholder')">
                    </div>

                    <!-- Input Fields Wrapper -->
                    <div style="grid-column: 1 / -1; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div class="form-group">
                            <label for="firstname">First Name</label>
                            <input type="text" id="firstname" name="firstname" class="form-control" required 
                                   oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label for="lastname">Last Name</label>
                            <input type="text" id="lastname" name="lastname" class="form-control" required
                                   oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="form-group">
                            <label for="position">Position</label>
                            <select id="position" name="position" class="form-control" required>
                                <option value="" disabled selected>Select Position</option>
                                <option value="Prime Minister">Prime Minister</option>
                                <option value="Executive Prime Minister">Executive Prime Minister</option>
                                <option value="Secretary General">Secretary General</option>
                                <option value="Treasurer">Treasurer</option>
                                <option value="Auditor">Auditor</option>
                                <option value="President">President</option>
                                <option value="Vice President">Vice President</option>
                                <option value="Secretary">Secretary</option>
                                <option value="Student Council">Student Council</option>
                                <option value="Representative 1">Representative 1</option>
                                <option value="Representative 2">Representative 2</option>
                                <option value="Representative 3">Representative 3</option>
                                <option value="Representative 4">Representative 4</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="election_type">Election Category</label>
                            <select id="election_type" name="election_type" class="form-control" required>
                                <option value="USP" selected>USP</option>
                                <option value="Student Council">Student Council</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="party">Party/Affiliation</label>
                            <input type="text" id="party" name="party" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="department">Department</label>
                            <select id="department" name="department" class="form-control" required onchange="updateProgramOptions()">
                                <option value="" disabled selected>Select Department</option>
                                <option value="CBA">College of Business Administration</option>
                                <option value="CAS">College of Arts and Sciences</option>
                                <option value="CCST">College of Computer Studies and Engineering</option>
                                <option value="CTED">College of Teacher Education</option>
                                <option value="CONAHS">College of Nursing and Allied Health Sciences</option>
                                <option value="CTHM">College of Tourism and Hospitality Management</option>
                                <option value="CHK">College of Human Kinetics</option>
                                <option value="COA">College of Accountancy</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="program">Program</label>
                            <select name="program" id="program" class="form-control" required>
                                <option value="" disabled selected>Select Program</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="year_level">Year Level</label>
                            <select id="year_level" name="year_level" class="form-control" required>
                                <option value="" disabled selected>Select Year Level</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="message">Campaign Message</label>
                            <textarea id="message" name="message" class="form-control" rows="3" placeholder="Enter candidate's message..." required></textarea>
                        </div>
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <button type="submit" name="add_candidate" class="btn-submit">
                        <span class="material-icons">add_circle</span> Add Candidate
                    </button>
                </div>
            </form>
        </div>

        <!-- Candidates List -->
        <div class="content-card">
            <h2 class="section-title">
                <span class="material-icons">list_alt</span> Registered Candidates
            </h2>

            <!-- Search -->
            <div class="search-container">
                <span class="material-icons search-icon">search</span>
                <input type="text" id="searchInput" class="form-control search-input" placeholder="Search candidates...">
                <select id="filterElectionType" class="form-control filter-select">
                    <option value="all">All Elections</option>
                    <option value="USP">USP</option>
                    <option value="Student Council">Student Council</option>
                </select>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <table class="candidates-table" id="candidatesTable">
                    <thead>
                        <tr>
                            <th width="80">Photo</th>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Election</th>
                            <th>Party</th>
                            <th>Program/Dept</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="candidatesTableBody">
                        <?php if (empty($candidates)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px;">No candidates found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($candidates as $candidate): ?>
                                <tr>
                                    <td>
                                        <?php if ($candidate['photo']): ?>
                                            <img src="../assets/candidates/<?php echo htmlspecialchars($candidate['photo']); ?>" class="c-avatar" alt="pic">
                                        <?php else: ?>
                                            <div class="c-avatar" style="background:#e3e1c8; display:flex; align-items:center; justify-content:center;">
                                                <span class="material-icons" style="font-size:24px; color:var(--primary-green);">person</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($candidate['firstname'] . ' ' . $candidate['lastname']); ?></strong>
                                    </td>
                                    <td>
                                        <?php 
                                        $p_cls = 'pos-badge';
                                        switch($candidate['position']) {
                                            case 'Prime Minister': $p_cls .= ' pos-pm'; break;
                                            case 'Executive Prime Minister': $p_cls .= ' pos-epm'; break;
                                            case 'Secretary General': $p_cls .= ' pos-sg'; break;
                                            case 'Treasurer': $p_cls .= ' pos-tr'; break;
                                            case 'Auditor': $p_cls .= ' pos-au'; break;
                                            case 'President':
                                            case 'Vice President':
                                            case 'Secretary':
                                            case 'Representative 1':
                                            case 'Representative 2':
                                            case 'Representative 3':
                                            case 'Representative 4':
                                                $p_cls .= ' pos-sc'; break;
                                        }
                                        ?>
                                        <span class="<?php echo $p_cls; ?>"><?php echo htmlspecialchars($candidate['position']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($candidate['election_type'] ?? 'USP'); ?></td>
                                    <td><?php echo htmlspecialchars($candidate['party']); ?></td>
                                    <td>
                                        <div style="font-size:12px; font-weight:700;"><?php echo htmlspecialchars($candidate['program']); ?></div>
                                        <div style="font-size:11px; color:#777;"><?php echo htmlspecialchars($candidate['department']); ?> - <?php echo htmlspecialchars($candidate['year_level']); ?></div>
                                    </td>
                                    <td>
                                        <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($candidate)); ?>)">Edit</button>
                                        <button class="action-btn btn-delete" onclick="openDeleteModal(<?php echo $candidate['id']; ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-box large">
            <div class="modal-header">
                <h2 class="modal-title">Edit Candidate</h2>
            </div>
            <div style="padding: 0 30px 20px; overflow-y:auto; max-height: 70vh;">
                <form action="" method="POST" enctype="multipart/form-data" id="editForm">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="current_photo" id="edit_current_photo">
                    
                    <div class="form-row">
                        <!-- Photo -->
                        <div class="profile-upload-container">
                            <div class="profile-preview-wrapper">
                                <img id="edit-preview" class="preview-img" src="" alt="">
                                <span class="material-icons placeholder-icon" id="edit-placeholder">person</span>
                            </div>
                            <label for="edit_photo" class="custom-file-upload">
                                <span class="material-icons" style="margin-right: 5px;">edit</span> Change Photo
                            </label>
                            <input type="file" id="edit_photo" name="photo" accept="image/*" onchange="previewImage(this, 'edit-preview', 'edit-placeholder')">
                        </div>
                        
                        <!-- Edit Inputs -->
                        <div style="grid-column: 1 / -1; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" id="edit_firstname" name="firstname" class="form-control" required
                                       oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" id="edit_lastname" name="lastname" class="form-control" required
                                       oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="form-group">
                                <label>Position</label>
                                <select id="edit_position" name="position" class="form-control" required>
                                    <option value="Prime Minister">Prime Minister</option>
                                    <option value="Executive Prime Minister">Executive Prime Minister</option>
                                    <option value="Secretary General">Secretary General</option>
                                    <option value="Treasurer">Treasurer</option>
                                    <option value="Auditor">Auditor</option>
                                    <option value="President">President</option>
                                    <option value="Vice President">Vice President</option>
                                    <option value="Secretary">Secretary</option>
                                    <option value="Student Council">Student Council</option>
                                    <option value="Representative 1">Representative 1</option>
                                    <option value="Representative 2">Representative 2</option>
                                    <option value="Representative 3">Representative 3</option>
                                    <option value="Representative 4">Representative 4</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Election Category</label>
                                <select id="edit_election_type" name="election_type" class="form-control" required>
                                    <option value="USP">USP</option>
                                    <option value="Student Council">Student Council</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Party</label>
                                <input type="text" id="edit_party" name="party" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select id="edit_department" name="department" class="form-control" required onchange="updateEditProgramOptions()">
                                    <option value="CBA">CBA</option>
                                    <option value="CAS">CAS</option>
                                    <option value="CCST">CCST</option>
                                    <option value="CTED">CTED</option>
                                    <option value="CONAHS">CONAHS</option>
                                    <option value="CTHM">CTHM</option>
                                    <option value="CHK">CHK</option>
                                    <option value="COA">COA</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Program</label>
                                <select id="edit_program" name="program" class="form-control" required>
                                    <option value="">Select Program</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Year Level</label>
                                <select id="edit_year_level" name="year_level" class="form-control" required>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                </select>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label>Campaign Message</label>
                                <textarea id="edit_message" name="message" class="form-control" rows="3" required></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn-modal cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="update_candidate" class="btn-modal confirm-success">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE MODAL -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box danger">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">warning</span></div>
                <h2 class="modal-title">Delete Candidate?</h2>
                <p class="modal-desc">This action will permanently remove this candidate from the database. This cannot be undone.</p>
            </div>
            <form method="POST" class="modal-actions" style="margin:0; width:100%;">
                <input type="hidden" name="id" id="delete_id">
                <button type="button" class="btn-modal cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" name="delete_candidate" class="btn-modal confirm-danger">Yes, Delete</button>
            </form>
        </div>
    </div>
    
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$just_updated): ?>
        <script src="../drawer-peek.js"></script>
    <?php endif; ?>
    
    <script>
        // Data Mapping
        const departmentPrograms = {
            "CBA": ["BSBA", "BSE", "BSOA"],
            "CAS": ["BSP", "BSE"],
            "CCST": ["BSIS", "BSIT", "BSCE"],
            "CTED": ["BSE"],
            "CONAHS": ["BSN"],
            "CTHM": ["BSHM", "BSTM"],
            "CHK": ["BSHK"],
            "COA": ["BSA", "BSAIS", "BSMA"]
        };
        const programFullNames = {
            "BSA": "Bachelor of Science in Accountancy",
            "BSAIS": "Bachelor of Science in Accounting Information System",
            "BSMA": "Bachelor of Science in Management Accounting",
            "BSE": "Bachelor of Science in Economics",
            "BSP": "Bachelor of Science in Psychology",
            "BSBA": "Bachelor of Science in Business Administration",
            "BSOA": "Bachelor of Science in Office Administration",
            "BSIS": "Bachelor of Science in Information Systems",
            "BSIT": "Bachelor of Science in Information Technology",
            "BSCE": "Bachelor of Science in Computer Engineering",
            "BSHM": "Bachelor of Science in Hospitality Management",
            "BSTM": "Bachelor of Science in Tourism Management",
            "BSN": "Bachelor of Science in Nursing",
            "BSHK": "Bachelor of Science in Human Kinetics"
        };

        function updateProgramOptions() {
            const departmentSelect = document.getElementById('department');
            const programSelect = document.getElementById('program');
            const selected = departmentSelect.value;
            programSelect.innerHTML = '<option value="" disabled selected>Select Program</option>';
            if (selected && departmentPrograms[selected]) {
                departmentPrograms[selected].forEach(code => {
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = programFullNames[code] || code;
                    programSelect.appendChild(opt);
                });
            }
        }

        function updateEditProgramOptions() {
            const departmentSelect = document.getElementById('edit_department');
            const programSelect = document.getElementById('edit_program');
            const selected = departmentSelect.value;
            programSelect.innerHTML = '<option value="" disabled selected>Select Program</option>';
            if (selected && departmentPrograms[selected]) {
                departmentPrograms[selected].forEach(code => {
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = programFullNames[code] || code;
                    programSelect.appendChild(opt);
                });
            }
        }

        function previewImage(input, previewId, placeholderId) {
            const preview = document.getElementById(previewId);
            const placeholder = document.getElementById(placeholderId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = "block";
                    if(placeholder) placeholder.style.display = "none";
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // ==========================================
            // PREVENT SCROLL RESET ON FORM SUBMIT
            // ==========================================
            // 1. Check if we have a saved scroll position and restore it
            const scrollPos = localStorage.getItem('candidates_scroll_pos');
            if (scrollPos) {
                window.scrollTo(0, parseInt(scrollPos));
                // Remove it so it doesn't affect future normal reloads
                localStorage.removeItem('candidates_scroll_pos');
            }

            // 2. Add listener to all forms to save scroll position when submitted
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    localStorage.setItem('candidates_scroll_pos', window.scrollY);
                });
            });
            // ==========================================


            // Navbar/Drawer
            const menuIcon = document.getElementById('menuIcon');
            const closeIcon = document.getElementById('closeIcon');
            const drawer = document.getElementById('drawer');
            const drawerOverlay = document.getElementById('drawerOverlay');

            function openDrawer() { drawer.classList.add('open'); drawerOverlay.classList.add('active'); }
            function closeDrawer() { drawer.classList.remove('open'); drawerOverlay.classList.remove('active'); }

            menuIcon.addEventListener('click', openDrawer);
            closeIcon.addEventListener('click', closeDrawer);
            drawerOverlay.addEventListener('click', closeDrawer);

            // Logout Modal
            document.getElementById('logoutLink').addEventListener('click', (e) => {
                e.preventDefault(); closeDrawer();
                document.getElementById('logoutModal').classList.add('active');
            });
            document.getElementById('cancelLogout').addEventListener('click', () => {
                document.getElementById('logoutModal').classList.remove('active');
            });
            document.getElementById('confirmLogout').addEventListener('click', () => {
                window.location.href = "?logout=true";
            });

            // Search & Election Type Filter
            const searchInput = document.getElementById('searchInput');
            const filterSelect = document.getElementById('filterElectionType');
            const tableBody = document.getElementById('candidatesTableBody');
            if (tableBody && searchInput && filterSelect) {
                const rows = Array.from(tableBody.querySelectorAll('tr'));
                const filterRows = () => {
                    const term = searchInput.value.toLowerCase().trim();
                    const filterValue = filterSelect.value;

                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        const electionType = row.cells[3] ? row.cells[3].textContent.trim() : '';
                        const matchesSearch = text.includes(term);
                        const matchesFilter = filterValue === 'all' || electionType === filterValue;
                        row.style.display = matchesSearch && matchesFilter ? '' : 'none';
                    });
                };

                searchInput.addEventListener('input', filterRows);
                filterSelect.addEventListener('change', filterRows);
            }

            // Toast Auto-dismiss
            const toast = document.getElementById('toastNotification');
            if (toast) {
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 400); 
                }, 4000);
            }
        });

        // Edit/Delete Logic
        function openEditModal(candidate) {
            document.getElementById('edit_id').value = candidate.id;
            document.getElementById('edit_current_photo').value = candidate.photo;
            document.getElementById('edit_firstname').value = candidate.firstname;
            document.getElementById('edit_lastname').value = candidate.lastname;
            document.getElementById('edit_position').value = candidate.position;
            document.getElementById('edit_party').value = candidate.party;
            document.getElementById('edit_election_type').value = candidate.election_type || 'USP';
            document.getElementById('edit_year_level').value = candidate.year_level;
            document.getElementById('edit_department').value = candidate.department;
            document.getElementById('edit_message').value = candidate.message;
            
            // Photo Preview
            const preview = document.getElementById('edit-preview');
            const placeholder = document.getElementById('edit-placeholder');
            if (candidate.photo) {
                preview.src = "../assets/candidates/" + candidate.photo;
                preview.style.display = "block";
                placeholder.style.display = "none";
            } else {
                preview.src = "";
                preview.style.display = "none";
                placeholder.style.display = "block";
            }
            
            updateEditProgramOptions();
            setTimeout(() => {
                document.getElementById('edit_program').value = candidate.program;
            }, 50);

            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function openDeleteModal(id) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
    </script>
</body>
</html>