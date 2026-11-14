<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "crypto.php";
require_once "auth_check.php";

require_login(['admin']);

header("Content-Type: application/json");

$academic_year = getCurrentAcademicYear();

$stmt = $conn->prepare(
    "SELECT r.id,
     r.usn,
     r.permission_request_id,
     r.name,
     r.department,
     r.semester,
     r.refund_type,
     r.event_name,
     r.team_name,
     r.refund_reason,
     r.amount,
     r.number_of_students,
     r.per_student_amount,
     r.refunded_amount,
     r.admin_remarks,
     ap.filename AS payment_proof,
     ap.mime_type AS payment_proof_mime,
     apt.filename AS participation_proof,
     apt.mime_type AS participation_proof_mime,
     abk.filename AS bank_proof,
     abk.mime_type AS bank_proof_mime,
     r.hod_status,
     r.hod_remarks,
     r.hod_archived,
     r.admin_status,
     r.admin_archived,
     r.created_at,
     r.academic_year,
     (SELECT COALESCE(SUM(x.share_amount), 0)
        FROM refund_shares x
       WHERE x.refund_id = r.id AND x.hod_status = 'Approved') AS payable_amount,
     s.name AS student_name,
     a.account_holder_name AS account_holder,
     a.account_number, a.account_iv, a.ifsc_code, a.ifsc_iv, a.bank_name, a.bank_iv
 FROM refund_requests r
LEFT JOIN attachments ap ON ap.owner_type='refund_request' AND ap.owner_id=r.id AND ap.file_role='payment_proof'
LEFT JOIN attachments apt ON apt.owner_type='refund_request' AND apt.owner_id=r.id AND apt.file_role='participation_proof'
LEFT JOIN attachments abk ON abk.owner_type='refund_request' AND abk.owner_id=r.id AND abk.file_role='bank_proof'
 LEFT JOIN students s ON r.usn = s.usn
 LEFT JOIN account_details a ON r.usn = a.usn
 WHERE r.hod_status = 'Approved'
 AND r.admin_archived = 0
 AND r.academic_year = ?
 ORDER BY r.id DESC"
);
$stmt->bind_param("s", $academic_year);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $row['account_number'] = decryptData($row['account_number'], $row['account_iv']);
    $row['ifsc_code'] = decryptData($row['ifsc_code'], $row['ifsc_iv']);
    $row['bank_name'] = decryptData($row['bank_name'], $row['bank_iv']);
    $row['members'] = [];
    $row['shares'] = [];

    // The admin pays only what the HODs approved. Show that as the
    // amount so the existing screen and its validation use it.
    $row['requested_amount'] = $row['amount'];
    $row['amount'] = number_format((float)$row['payable_amount'], 2, '.', '');

    $requests[] = $row;
}

/* ===========================================================
   ATTACH GROUP MEMBERS
   Same pattern as hod_refund_requests.php: refund_members only
   stores (refund_id, usn), so join students for the name.
=========================================================== */

$groupIds = [];

foreach ($requests as $r) {
    if ($r['refund_type'] === 'Group') {
        $groupIds[] = (int)$r['id'];
    }
}

if (!empty($groupIds)) {

    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $types = str_repeat('i', count($groupIds));

    $memberStmt = $conn->prepare(
        "SELECT rm.refund_id, rm.usn, s.name AS student_name,
                s.department, s.semester
         FROM refund_members rm
         JOIN students s ON s.usn = rm.usn
         WHERE rm.refund_id IN ($placeholders)"
    );

    $bindParams = [$types];
    foreach ($groupIds as $key => $value) {
        $bindParams[] = &$groupIds[$key];
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
   DEPARTMENT SHARES (HOD decision of each department) and
   keep only the students of APPROVED departments in the list
=========================================================== */

if (!empty($requests)) {

    $ids = array_map(function ($r) { return (int)$r['id']; }, $requests);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $shareStmt = $conn->prepare(
        "SELECT refund_id, department, number_of_students,
                share_amount, hod_status, hod_remarks
         FROM refund_shares
         WHERE refund_id IN ($ph)
         ORDER BY department"
    );
    $shareStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $shareStmt->execute();
    $shareResult = $shareStmt->get_result();

    $sharesByRefund = [];

    while ($sh = $shareResult->fetch_assoc()) {
        $sharesByRefund[$sh['refund_id']][] = $sh;
    }

    $shareStmt->close();

    foreach ($requests as &$r) {

        $shares = $sharesByRefund[$r['id']] ?? [];
        $r['shares'] = $shares;

        $approvedDepts = [];
        $approvedDeptNames = [];
        $approvedStudents = 0;

        foreach ($shares as $sh) {
            if ($sh['hod_status'] === 'Approved') {
                $approvedDepts[] = strtolower(trim($sh['department']));
                $approvedDeptNames[] = trim($sh['department']);
                $approvedStudents += (int)$sh['number_of_students'];
            }
        }

        // Department column: department(s) of the students who actually
        // receive the money (approved shares), not the team leader's.
        if (!empty($approvedDeptNames)) {
            $r['department'] = implode(', ', $approvedDeptNames);
        }

        $r['members'] = array_values(array_filter(
            $r['members'],
            function ($m) use ($approvedDepts) {
                return in_array(strtolower(trim($m['department'])), $approvedDepts, true);
            }
        ));

        if ($approvedStudents > 0) {
            $r['number_of_students'] = $approvedStudents;
        }
    }
    unset($r);
}

echo json_encode([
    "status" => "success",
    "data" => $requests
]);

?>