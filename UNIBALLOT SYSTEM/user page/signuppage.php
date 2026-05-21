<?php
// signuppage.php

// Connect to database
require_once '../db_connect.php';

$email = "";
$stu_no = "";
$lname = "";
$department = "";
$program = "";
$password = "";
$confirm_password = "";

$showSuccessModal = false;
$showErrorModal = false;
$errorMessage = "";

// Department → Programs
$departmentPrograms = [
    "CBA" => ["BSBA", "BSE", "BSOA"],
    "CAS" => ["BSP", "BSE"],
    "CCSE" => ["BSIS", "BSIT", "BSCE"],
    "COE" => ["BSCE"],
    "CTED" => ["BSE"],
    "CONAHS" => ["BSN"],
    "CTHM" => ["BSHM", "BSTM"],
    "CHK" => ["BSHK"],
    "COA" => ["BSA", "BSAIS", "BSMA"]
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $stu_no = $_POST['stunum'];

    // Format student number
    $stu_no = preg_replace('/[^0-9]/', '', $stu_no);
    if (strlen($stu_no) === 7) {
        $stu_no = substr($stu_no, 0, 2) . '-' . substr($stu_no, 2);
    } else {
        $showErrorModal = true;
        $errorMessage = "Invalid student number format!";
    }

    $lname = strtoupper($_POST['lastname']);
    $department = $_POST['department'];
    $program = $_POST['program'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm-password'];

    // Step 1: Check if student exists in master list
    $check_student = "SELECT * FROM students WHERE stu_no='$stu_no' AND lastname='$lname'";
    $student_result = $conn->query($check_student);

    if ($student_result && $student_result->num_rows == 0) {
        $showErrorModal = true;
        $errorMessage = "Student record not found. Please check your student number or last name.";
    } 
    else {
        // Fetch student info from DB
        $student_info = $student_result ? $student_result->fetch_assoc() : null;
        
        $dbDepartment = $student_info ? $student_info['department'] : $department; 
        $dbProgram = $student_info ? $student_info['program'] : $program;

        // Step 2: Password checks
        if ($password !== $confirm_password) {
            $showErrorModal = true;
            $errorMessage = "Passwords do not match!";
        }
        elseif (strlen($password) < 6) {
            $showErrorModal = true;
            $errorMessage = "Password must be at least 6 characters long!";
        }
        // Step 2.5: Department + Program validation
        elseif ($student_info && ($department !== $dbDepartment || $program !== $dbProgram)) {
            $showErrorModal = true;
            $errorMessage = "Your Department or Program does not match the school records!";
        }
        else {
            // Step 3: Check if already registered
            $check_voter = "SELECT * FROM voters WHERE email='$email' OR stu_no='$stu_no'";
            $voter_result = $conn->query($check_voter);

            if ($voter_result->num_rows > 0) {
                $showErrorModal = true;
                $errorMessage = "Email or Student Number already registered!";
            } else {
                // Step 4: Insert (UPDATED: Removed is_admin)
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $sql = "INSERT INTO voters (stu_no, lastname, email, department, program, password) 
                        VALUES ('$stu_no','$lname','$email', '$department', '$program', '$hashed_password')";

                if ($conn->query($sql) === TRUE) {
                    session_start();
                    $_SESSION['stu_no'] = $stu_no;
                    $_SESSION['lastname'] = $student_info['lastname'] ?? $lname;
                    $_SESSION['firstname'] = $student_info['firstname'] ?? 'Student';
                    $_SESSION['department'] = $department;
                    $_SESSION['program'] = $program;
                    
                    $showSuccessModal = true;
                } else {
                    $showErrorModal = true;
                    $errorMessage = "Database error: " . $conn->error;
                }
            }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - UniBallot</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../styles/user.css">
</head>
<body class="flex-center">

    <!-- SUCCESS MODAL -->
    <div class="modal-overlay <?php if ($showSuccessModal) echo 'active'; ?>" id="successModal">
        <div class="modal-box success">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">check_circle</span></div>
                <h2 class="modal-title">Success!</h2>
                <p class="modal-desc">Your account has been created successfully. Welcome aboard!</p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal confirm-success" onclick="redirectToConsent()">Continue</button>
            </div>
        </div>
    </div>

    <!-- ERROR MODAL -->
    <div class="modal-overlay <?php if ($showErrorModal) echo 'active'; ?>" id="errorModal">
        <div class="modal-box warning">
            <div class="modal-header">
                <div class="modal-icon-circle"><span class="material-icons">error_outline</span></div>
                <h2 class="modal-title">Registration Failed</h2>
                <p class="modal-desc"><?php echo $errorMessage; ?></p>
            </div>
            <div class="modal-actions">
                <button class="btn-modal confirm-danger" onclick="closeErrorModal()">Try Again</button>
            </div>
        </div>
    </div>

    <!-- MAIN FORM CARD -->
    <div class="signup-wrapper">
        <div class="signup-card">
            <div class="header-icon-circle">
                <span class="material-icons">person_add</span>
            </div>
            
            <h2 class="form-title">Create Account</h2>

            <form method="POST" action="" id="signupForm">
                <div class="input-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">mail</span>
                        <input class="form-control" id="email" name="email" type="email" placeholder="e.g. student@gmail.com" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="stunum">Student Number</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">badge</span>
                        <input class="form-control" id="stunum" name="stunum" type="text" placeholder="e.g. 00-000000" pattern="\d{2}-\d{5}" title="Format: 00-00000" value="<?php echo htmlspecialchars($stu_no); ?>" required>
                    </div>
                </div>

                <div class="input-group">
                    <label for="lastname">Last Name</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">person</span>
                        <input class="form-control" id="lastname" name="lastname" type="text" style="text-transform: uppercase;" placeholder="DELA CRUZ" value="<?php echo htmlspecialchars($lname); ?>" required>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="department">Department</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">apartment</span>
                        <select class="form-control" id="department" name="department" required onchange="updateProgramOptions()">
                            <option value="" disabled selected>Select Department</option>
                            <option value="CBA" <?php if ($department == 'CBA') echo 'selected'; ?>>College of Business Administration</option>
                            <option value="CAS" <?php if ($department == 'CAS') echo 'selected'; ?>>College of Arts and Sciences</option>
                            <option value="CCSE" <?php if ($department == 'CCSE') echo 'selected'; ?>>College of Computer Studies and Engineering</option>
                            <option value="COE" <?php if ($department == 'COE') echo 'selected'; ?>>College of Engineering</option>
                            <option value="CTED" <?php if ($department == 'CTED') echo 'selected'; ?>>College of Teacher Education</option>
                            <option value="CONAHS" <?php if ($department == 'CONAHS') echo 'selected'; ?>>College of Nursing and Allied Health Sciences</option>
                            <option value="CTHM" <?php if ($department == 'CTHM') echo 'selected'; ?>>College of Tourism and Hospitality Management</option>
                            <option value="CHK" <?php if ($department == 'CHK') echo 'selected'; ?>>College of Human Kinetics</option>
                            <option value="COA" <?php if ($department == 'COA') echo 'selected'; ?>>College of Accountancy</option>
                        </select>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="program">Program</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">school</span>
                        <select class="form-control" name="program" id="program" required>
                            <option value="" disabled selected>Select Program</option>
                            <!-- Dynamic Options -->
                        </select>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">lock</span>
                        <input class="form-control" name="password" type="password" placeholder="(min. 6 chars)" id="password" minlength="6" required>
                        <button type="button" class="password-toggle" id="togglePassword">
                            <span class="material-icons">visibility</span>
                        </button>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="confirm-password">Confirm Password</label>
                    <div class="input-wrapper">
                        <span class="material-icons field-icon">lock_open</span>
                        <input class="form-control" name="confirm-password" type="password" placeholder="Retype password" id="confirm-password" minlength="6" required>
                        <button type="button" class="password-toggle" id="toggleConfirmPassword">
                            <span class="material-icons">visibility</span>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-signup">Create Account</button>
            </form>
            
            <div class="login-links">
                <span>Already have an account? <a href="../index.php">Log In</a></span>
            </div>
        </div>
    </div>

    <script>
        // Department to program mapping
        const departmentPrograms = {
            "CBA": ["BSBA", "BSE", "BSOA"],
            "CAS": ["BSP", "BSE"],
            "CCSE": ["BSIS", "BSIT", "BSCE"],
            "COE": ["BSCE"],
            "CTED": ["BSE"],
            "CONAHS": ["BSN"],
            "CTHM": ["BSHM", "BSTM"],
            "CHK": ["BSHK"],
            "COA": ["BSA", "BSAIS", "BSMA"]
        };

        // Program full names
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
            const selectedDepartment = departmentSelect.value;
            
            // Clear existing options
            programSelect.innerHTML = '<option value="" disabled selected>Select Program</option>';
            
            // Add programs for selected department
            if (selectedDepartment && departmentPrograms[selectedDepartment]) {
                departmentPrograms[selectedDepartment].forEach(programCode => {
                    const option = document.createElement('option');
                    option.value = programCode;
                    option.textContent = programFullNames[programCode] || programCode;
                    
                    // Set selected if this was the previously selected program
                    if (programCode === '<?php echo $program; ?>') {
                        option.selected = true;
                    }
                    
                    programSelect.appendChild(option);
                });
            }
        }

        function redirectToConsent() {
            window.location.href = "consent.php";
        }

        function closeErrorModal() {
            document.getElementById('errorModal').classList.remove('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.getElementById('togglePassword');
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm-password');
            
            // Initialize program options based on selected department
            updateProgramOptions();
            
            // Helper function for toggling
            function setupToggle(btn, input) {
                btn.addEventListener('click', function() {
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    this.querySelector('.material-icons').textContent = type === 'password' ? 'visibility' : 'visibility_off';
                });
            }

            setupToggle(togglePassword, passwordInput);
            setupToggle(toggleConfirmPassword, confirmPasswordInput);
        });
    </script>
</body>
</html>