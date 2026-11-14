<?php

require_once "db.php";
require_once "auth_check.php";
require_once "refund_lib.php";

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

if (!in_array($action, ['approve', 'reject', 'clarify'], true)) {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid action"
    ]);

    exit;
}

/* ===========================================================
   FIND THIS HOD'S DEPARTMENT SHARE (row is locked so an admin
   payment and a HOD change cannot happen at the same time)
=========================================================== */

$conn->begin_transaction();

$check = $conn->prepare(
    "SELECT r.id, sh.hod_archived, r.admin_status,
            sh.id AS share_id, sh.hod_status AS share_status
     FROM refund_shares sh
     JOIN refund_requests r ON r.id = sh.refund_id
     WHERE sh.refund_id = ?
       AND LOWER(TRIM(sh.department)) = LOWER(TRIM(?))
     FOR UPDATE"
);
$check->bind_param("is", $id, $department);
$check->execute();
$checkRow = $check->get_result()->fetch_assoc();
$check->close();

if (!$checkRow) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Request not found"]);
    exit;
}

if ((int)$checkRow['hod_archived'] === 1) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "message" => "This request is locked — it has already been archived."
    ]);
    exit;
}

if ($checkRow['admin_status'] === 'Paid') {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "message" => "This request is locked — payment has already been made."
    ]);
    exit;
}

$wasAlreadyDecided = in_array($checkRow['share_status'], ['Approved', 'Rejected'], true);

if ($action == "approve") {
    $newStatus = 'Approved';
    $decided = 1;
} elseif ($action == "reject") {
    $newStatus = 'Rejected';
    $decided = 1;
} else {
    $newStatus = 'Clarification Needed';
    $decided = 0;
}

/* ===========================================================
   UPDATE ONLY THIS DEPARTMENT'S SHARE, THEN RECALCULATE
=========================================================== */

// A rejected share moves straight to this HOD's archive
$archiveNow = ($action === 'reject') ? 1 : 0;

$stmt = $conn->prepare(
    "UPDATE refund_shares
     SET hod_status = ?,
         hod_remarks = ?,
         decided_at = IF(? = 1, NOW(), NULL),
         hod_archived = ?
     WHERE id = ?"
);
$shareId = (int)$checkRow['share_id'];
$stmt->bind_param("ssiii", $newStatus, $remarks, $decided, $archiveNow, $shareId);

if (!$stmt->execute()) {

    $conn->rollback();

    echo json_encode([
        "status" => "error",
        "message" => "Database update failed"
    ]);

    exit;
}

$stmt->close();

$overall = recalcRefundStatus($conn, (int)$id);

$conn->commit();

echo json_encode([
    "status" => "success",
    "message" => $wasAlreadyDecided
        ? "Decision updated successfully."
        : "Refund request updated successfully",
    "overall_status" => $overall
]);

?>