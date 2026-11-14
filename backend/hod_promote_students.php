<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "auth_check.php";

require_login(['hod']);

header("Content-Type: application/json");

$confirm = $_POST['confirm'] ?? '';

if ($confirm !== 'yes') {
    echo json_encode([
        "status" => "error",
        "message" => "Confirmation required."
    ]);
    exit;
}

$department = current_department();

if (!$department) {
    echo json_encode([
        "status" => "error",
        "message" => "Department not found in session. Please log in again."
    ]);
    exit;
}

$maxSem = MAX_SEMESTER;

$stmt1 = $conn->prepare(
    "UPDATE students SET status = 'graduated' WHERE semester >= ? AND status = 'active' AND department = ?"
);
$stmt1->bind_param("is", $maxSem, $department);

if (!$stmt1->execute()) {
    echo json_encode([
        "status" => "error",
        "message" => "Unable to graduate final-semester students."
    ]);
    exit;
}
$graduated = $stmt1->affected_rows;

$stmt2 = $conn->prepare(
    "UPDATE students SET semester = semester + 1 WHERE semester < ? AND status = 'active' AND department = ?"
);
$stmt2->bind_param("is", $maxSem, $department);

if (!$stmt2->execute()) {
    echo json_encode([
        "status" => "error",
        "message" => "Unable to promote students."
    ]);
    exit;
}
$promoted = $stmt2->affected_rows;

echo json_encode([
    "status" => "success",
    "message" => "Students in $department promoted to next semester.",
    "promoted" => $promoted,
    "graduated" => $graduated
]);

?>