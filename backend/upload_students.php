<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['hod']);

header("Content-Type: application/json");

$department = current_department();

$raw = $_POST['students'] ?? '';
$students = json_decode($raw, true);

if (!is_array($students) || count($students) === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "No student rows received."
    ]);
    exit;
}

if (count($students) > 2000) {
    echo json_encode([
        "status" => "error",
        "message" => "Too many rows in one file (max 2000)."
    ]);
    exit;
}

$checkStmt = $conn->prepare(
    "SELECT usn FROM students WHERE usn = ?"
);

$insertStmt = $conn->prepare(
    "INSERT INTO students (usn, name, department, semester, password)
     VALUES (?, ?, ?, ?, ?)"
);

$inserted = 0;
$duplicates = 0;
$invalid = 0;
$details = [];

foreach ($students as $row) {

    $usn = strtoupper(trim((string)($row['usn'] ?? '')));
    $name = trim((string)($row['name'] ?? ''));
    $semesterRaw = trim((string)($row['semester'] ?? ''));
    $password = trim((string)($row['password'] ?? ''));

    if ($usn === '' && $name === '' && $semesterRaw === '' && $password === '') {
        continue;
    }

    $error = null;

    if ($usn === '') {
        $error = "USN is required";
    } elseif (!preg_match('/^[A-Z0-9]{5,20}$/', $usn)) {
        $error = "USN looks invalid";
    } elseif ($name === '') {
        $error = "Name is required";
    } elseif (
        $semesterRaw === '' ||
        !ctype_digit($semesterRaw) ||
        (int)$semesterRaw < 1 ||
        (int)$semesterRaw > MAX_SEMESTER
    ) {
        $error = "Semester must be between 1 and " . MAX_SEMESTER;
    } elseif ($password === '') {
        $error = "Password is required";
    }

    if ($error !== null) {
        $invalid++;
        $details[] = [
            "usn" => $usn,
            "name" => $name,
            "status" => "Skipped",
            "reason" => $error
        ];
        continue;
    }

    $semester = (int)$semesterRaw;

    $checkStmt->bind_param("s", $usn);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        $duplicates++;
        $details[] = [
            "usn" => $usn,
            "name" => $name,
            "status" => "Skipped",
            "reason" => "USN already exists"
        ];
        $checkStmt->free_result();
        continue;
    }

    $checkStmt->free_result();

    $insertStmt->bind_param(
        "sssis",
        $usn,
        $name,
        $department,
        $semester,
        $password
    );

    if ($insertStmt->execute()) {
        $inserted++;
        $details[] = [
            "usn" => $usn,
            "name" => $name,
            "status" => "Inserted",
            "reason" => ""
        ];
    } else {
        $invalid++;
        $details[] = [
            "usn" => $usn,
            "name" => $name,
            "status" => "Skipped",
            "reason" => "Database error"
        ];
    }
}

$checkStmt->close();
$insertStmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "inserted" => $inserted,
    "duplicates" => $duplicates,
    "invalid" => $invalid,
    "details" => $details
]);

?>