
<?php

// get_student_data.php

// 1. Turn off error reporting for the output (prevents PHP warnings breaking JSON)
error_reporting(0); 
ini_set('display_errors', 0);

// 2. Set Header
header('Content-Type: application/json');

session_start();

// Check Admin
if (!isset($_SESSION['stu_no']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../db_connect.php';

$stu_no = $_GET['stu_no'] ?? '';

if (!empty($stu_no)) {
    $sql = "SELECT lastname, program, department FROM students WHERE stu_no = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $stu_no);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            echo json_encode([
                'exists' => true,
                'lastname' => $student['lastname'],
                'program' => $student['program'],
                'department' => $student['department']
            ]);
        } else {
            echo json_encode(['exists' => false]);
        }
        $stmt->close();
    } else {
        echo json_encode(['error' => 'Query failed']);
    }
} else {
    echo json_encode(['exists' => false]);
}

$conn->close();
?>