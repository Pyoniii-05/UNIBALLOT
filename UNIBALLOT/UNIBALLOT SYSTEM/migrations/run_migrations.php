<?php
// Simple migration runner for local/dev use.
// Usage (CLI):
//   php run_migrations.php
// Or open in browser (not recommended on production servers).

require_once __DIR__ . '/../db_connect.php';

echo "Checking votes table for 'student_council' column...\n";

$col_check = $conn->query("SHOW COLUMNS FROM `votes` LIKE 'student_council'");
if ($col_check === false) {
    echo "Error checking columns: " . $conn->error . "\n";
    exit(1);
}

if ($col_check->num_rows > 0) {
    echo "Column 'student_council' already exists. No changes made.\n";
    exit(0);
}

// Read SQL file
$sql_file = __DIR__ . '/001_add_student_council.sql';
if (!file_exists($sql_file)) {
    echo "Migration SQL not found: $sql_file\n";
    exit(1);
}

$sql = file_get_contents($sql_file);
if ($sql === false) {
    echo "Failed to read SQL file.\n";
    exit(1);
}

if ($conn->query($sql) === TRUE) {
    echo "Migration applied: 'student_council' column added to votes table.\n";
    exit(0);
} else {
    echo "Migration failed: " . $conn->error . "\n";
    exit(1);
}
