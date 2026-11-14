<?php

require_once "db.php";
require_once "auth_check.php";
require_once "permission_lib.php";

require_login(['student']);

mysqli_report(MYSQLI_REPORT_OFF);

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] != "POST") {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method"
    ]);

    exit;
}

$id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
$reply = trim($_POST['reply'] ?? '');
$usn = current_login_id();

if ($id === false || $id === null) {
    echo json_encode(["status" => "error", "message" => "Invalid request id"]);
    exit;
}

if ($reply === "") {
    echo json_encode(["status" => "error", "message" => "Please enter a reply"]);
    exit;
}

$check = $conn->prepare(
    "SELECT id FROM permission_requests
     WHERE id = ? AND leader_usn = ? AND hod_status = 'Clarification Needed'"
);
$check->bind_param("is", $id, $usn);
$check->execute();

if ($check->get_result()->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "This request is not awaiting your reply"
    ]);
    exit;
}

$check->close();

$conn->begin_transaction();

/* Keep the latest reply on the main request (shown to the student) */
$update = $conn->prepare(
    "UPDATE permission_requests
     SET student_reply = ?,
         replied_at = NOW()
     WHERE id = ?"
);
$update->bind_param("si", $reply, $id);

if (!$update->execute()) {

    $conn->rollback();

    echo json_encode([
        "status" => "error",
        "message" => "Database update failed"
    ]);

    exit;
}

$update->close();

/* Send the reply only to the departments that asked for clarification */
$dept = $conn->prepare(
    "UPDATE permission_department_approvals
     SET student_reply = ?,
         replied_at = NOW(),
         hod_status = 'Pending'
     WHERE permission_id = ?
       AND hod_status = 'Clarification Needed'"
);
$dept->bind_param("si", $reply, $id);

if (!$dept->execute()) {

    $conn->rollback();

    echo json_encode([
        "status" => "error",
        "message" => "Database update failed"
    ]);

    exit;
}

$dept->close();

recalcPermissionStatus($conn, (int)$id);

$conn->commit();

echo json_encode([
    "status" => "success",
    "message" => "Reply sent to HOD"
]);

?>