<?php

require_once "db.php";
require_once "auth_check.php";
require_once "refund_lib.php";

require_login(['admin', 'hod']);

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $type = $_POST['type'] ?? '';

    if ($id === false || $id === null) {
        echo json_encode(["status" => "error", "message" => "Invalid request id"]);
        exit;
    }

    if (current_role() === 'admin' && $type !== 'admin') {
        echo json_encode(["status" => "error", "message" => "Not authorized"]);
        exit;
    }
    
    if (current_role() === 'hod' && $type !== 'hod') {
        echo json_encode(["status" => "error", "message" => "Not authorized"]);
        exit;
    }

    $check = $conn->prepare("SELECT * FROM refund_requests WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        echo json_encode(["status" => "error", "message" => "Request not found"]);
        exit;
    }

    // An HOD may restore a refund only when THEIR department has a share in it
    if (current_role() === 'hod') {

        $shareCheck = $conn->prepare(
            "SELECT id FROM refund_shares
             WHERE refund_id = ? AND LOWER(TRIM(department)) = LOWER(TRIM(?))"
        );
        $hodDept = current_department();
        $shareCheck->bind_param("is", $id, $hodDept);
        $shareCheck->execute();

        if ($shareCheck->get_result()->num_rows === 0) {
            echo json_encode(["status" => "error", "message" => "Not authorized"]);
            exit;
        }

        $shareCheck->close();
    }

    if ($row['admin_status'] == 'Paid') {
        echo json_encode([
            "status" => "error",
            "message" => "Payment completed requests cannot be restored"
        ]);
        exit;
    }

    if ($type == "admin") {
        $sql = "UPDATE refund_requests SET admin_archived = 0 WHERE id = ?";
    } elseif ($type == "hod") {
        // restore only THIS department's share; other departments keep theirs
        $sql = "UPDATE refund_shares SET hod_archived = 0
                WHERE refund_id = ? AND LOWER(TRIM(department)) = LOWER(TRIM(?))";
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid restore type"
        ]);
        exit;
    }

    $stmt = $conn->prepare($sql);

    if ($type == "hod") {
        $restoreDept = current_department();
        $stmt->bind_param("is", $id, $restoreDept);
    } else {
        $stmt->bind_param("i", $id);
    }

    if ($stmt->execute()) {

        if ($type == "hod") {
            syncRefundHodArchived($conn, (int)$id);
        }

        echo json_encode([
            "status" => "success",
            "message" => "Request restored successfully"
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Restore failed"
        ]);
    }
}
?>