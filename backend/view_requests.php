<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['student']);

header("Content-Type: application/json");

$usn = current_login_id();

$stmt = $conn->prepare(
    "SELECT refund_requests.id AS id,
     usn,
     permission_request_id,
     name,
     department,
     semester,
     refund_type,
     event_name,
     team_name,
     refund_reason,
     amount,
     number_of_students,
     per_student_amount,
     refunded_amount,
     admin_remarks,
     (SELECT GROUP_CONCAT(rm.usn ORDER BY rm.usn SEPARATOR ',')
     FROM refund_members rm WHERE rm.refund_id = refund_requests.id) AS member_usns,
     ap.filename AS payment_proof,
     ap.mime_type AS payment_proof_mime,
     apt.filename AS participation_proof,
     apt.mime_type AS participation_proof_mime,
     abk.filename AS bank_proof,
     abk.mime_type AS bank_proof_mime,
     hod_status,
     hod_remarks,
     hod_archived,
     admin_status,
     admin_archived,
     created_at,
     academic_year
     FROM refund_requests
     LEFT JOIN attachments ap ON ap.owner_type='refund_request' AND ap.owner_id=refund_requests.id AND ap.file_role='payment_proof'
     LEFT JOIN attachments apt ON apt.owner_type='refund_request' AND apt.owner_id=refund_requests.id AND apt.file_role='participation_proof'
     LEFT JOIN attachments abk ON abk.owner_type='refund_request' AND abk.owner_id=refund_requests.id AND abk.file_role='bank_proof'
     WHERE usn = ?
     ORDER BY refund_requests.id DESC"
);
$stmt->bind_param("s", $usn);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $row['members'] = [];

    // paid with a refunded amount -> per-student share is based on what was actually paid
    if ($row['admin_status'] === 'Paid' && $row['refunded_amount'] !== null) {
        $n = max((int)$row['number_of_students'], 1);
        $row['per_student_amount'] = number_format((float)$row['refunded_amount'] / $n, 2, '.', '');
    }

    $requests[] = $row;
}
$groupRefundIds = [];

foreach ($requests as $r) {
    if ($r['refund_type'] === 'Group') {
        $groupRefundIds[] = (int)$r['id'];
    }
}

if (!empty($groupRefundIds)) {

    $placeholders = implode(',', array_fill(0, count($groupRefundIds), '?'));
    $types = str_repeat('i', count($groupRefundIds));

    $memberStmt = $conn->prepare(
        "SELECT rm.refund_id, rm.usn, s.name AS student_name,
                s.department, s.semester
         FROM refund_members rm
         JOIN students s ON s.usn = rm.usn
         WHERE rm.refund_id IN ($placeholders)"
    );

    $bindParams = [$types];
    foreach ($groupRefundIds as $key => $value) {
        $bindParams[] = &$groupRefundIds[$key];
    }
    call_user_func_array([$memberStmt, 'bind_param'], $bindParams);

    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();

    $membersByRefundId = [];

    while ($m = $memberResult->fetch_assoc()) {
        $membersByRefundId[$m['refund_id']][] = $m;
    }

    foreach ($requests as &$r) {
        if ($r['refund_type'] === 'Group') {
            $r['members'] = $membersByRefundId[$r['id']] ?? [];
        }
    }
    unset($r);
}

/* ===========================================================
   DEPARTMENT-WISE STATUS OF EACH REFUND (one share per department)
=========================================================== */

if (!empty($requests)) {

    $allIds = array_map(function ($r) { return (int)$r['id']; }, $requests);
    $sph = implode(',', array_fill(0, count($allIds), '?'));

    $shareStmt = $conn->prepare(
        "SELECT refund_id, department, number_of_students,
                share_amount, hod_status, hod_remarks
         FROM refund_shares
         WHERE refund_id IN ($sph)
         ORDER BY department"
    );
    $shareStmt->bind_param(str_repeat('i', count($allIds)), ...$allIds);
    $shareStmt->execute();
    $shareResult = $shareStmt->get_result();

    $sharesByRefund = [];

    while ($sh = $shareResult->fetch_assoc()) {
        $sharesByRefund[$sh['refund_id']][] = $sh;
    }

    $shareStmt->close();

    foreach ($requests as &$r) {

        $shares = $sharesByRefund[$r['id']] ?? [];

        $r['dept_shares'] = $shares;
        $r['my_department'] = $r['department'];

        // when only some departments were paid, the per-student amount is
        // what was paid divided by the students of the APPROVED departments
        if ($r['admin_status'] === 'Paid' && $r['refunded_amount'] !== null) {

            $approvedStudents = 0;

            foreach ($shares as $sh) {
                if ($sh['hod_status'] === 'Approved') {
                    $approvedStudents += (int)$sh['number_of_students'];
                }
            }

            if ($approvedStudents > 0) {
                $r['per_student_amount'] =
                    number_format((float)$r['refunded_amount'] / $approvedStudents, 2, '.', '');
            }
        }
    }
    unset($r);
}

echo json_encode([
    "status" => "success",
    "data" => $requests
]);

?>