<?php

require_once "db.php";
require_once "auth_check.php";
require_once "permission_lib.php";

require_login(['hod']);

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
$action = $_POST['action'] ?? '';
$remarks = trim($_POST['remarks'] ?? '');
$department = current_department();

if ($id === false || $id === null) {
    echo json_encode(["status" => "error", "message" => "Invalid request id"]);
    exit;
}

/* ===========================================================
   FIND THIS HOD'S DEPARTMENT ROW FOR THE REQUEST
=========================================================== */

$check = $conn->prepare(
    "SELECT pda.id, pda.hod_status AS dept_status, pr.hod_status AS overall_status
     FROM permission_department_approvals pda
     JOIN permission_requests pr ON pr.id = pda.permission_id
     WHERE pda.permission_id = ?
       AND LOWER(TRIM(pda.department)) = LOWER(TRIM(?))"
);
$check->bind_param("is", $id, $department);
$check->execute();
$row = $check->get_result()->fetch_assoc();
$check->close();

if (!$row) {
    echo json_encode(["status" => "error", "message" => "Request not found"]);
    exit;
}

if ($row['overall_status'] === 'Rejected') {
    echo json_encode([
        "status" => "error",
        "message" => "This request has already been rejected."
    ]);
    exit;
}

if (!in_array($row['dept_status'], ['Pending', 'Clarification Needed'], true)) {
    echo json_encode([
        "status" => "error",
        "message" => "Your department has already decided on this request."
    ]);
    exit;
}

if ($action == "approve") {

    $newStatus = 'Approved';
    $decided = 1;

} elseif ($action == "reject") {

    $newStatus = 'Rejected';
    $decided = 1;

} elseif ($action == "clarify") {

    $newStatus = 'Clarification Needed';
    $decided = 0;

} else {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid action"
    ]);

    exit;
}

/* ===========================================================
   UPDATE ONLY THIS DEPARTMENT, THEN RECALCULATE OVERALL
=========================================================== */

$conn->begin_transaction();

$stmt = $conn->prepare(
    "UPDATE permission_department_approvals
     SET hod_status = ?,
         hod_remarks = ?,
         decided_at = IF(? = 1, NOW(), NULL)
     WHERE id = ?"
);
$deptRowId = (int)$row['id'];
$stmt->bind_param("ssii", $newStatus, $remarks, $decided, $deptRowId);

if (!$stmt->execute()) {

    $conn->rollback();

    echo json_encode([
        "status" => "error",
        "message" => "Database update failed"
    ]);

    exit;
}

$stmt->close();

$overall = recalcPermissionStatus($conn, (int)$id);

$conn->commit();

echo json_encode([
    "status" => "success",
    "message" => "Request updated successfully",
    "overall_status" => $overall
]);

?>