<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['student']);

header("Content-Type: application/json");

$usn = current_login_id();

$stmt = $conn->prepare(
    "SELECT pr.id AS permission_id,
            pr.event_name,
            pr.leader_usn,
            s.name AS leader_name,
            et.event_type_name AS event_type,
            rr.id AS refund_id,
            rr.hod_status,
            rr.admin_status,
            rr.amount,
            rr.per_student_amount,
            rr.refunded_amount,
            rr.number_of_students,
            rr.created_at
     FROM permission_members pm
     JOIN permission_requests pr ON pr.id = pm.permission_id
     LEFT JOIN students s ON s.usn = pr.leader_usn
     LEFT JOIN event_types et ON et.event_type_id = pr.event_type_id
     LEFT JOIN refund_requests rr
            ON rr.permission_request_id = pr.id
           AND rr.admin_status != 'Rejected'
           AND rr.hod_status != 'Rejected'
     WHERE pm.usn = ?
     ORDER BY pr.id DESC"
);
$stmt->bind_param("s", $usn);
$stmt->execute();
$result = $stmt->get_result();

/* my own department, to mark "your department" and work out my share */
$myDept = null;
$dStmt = $conn->prepare("SELECT department FROM students WHERE usn = ?");
$dStmt->bind_param("s", $usn);
$dStmt->execute();
$dRow = $dStmt->get_result()->fetch_assoc();
$dStmt->close();

if ($dRow) {
    $myDept = $dRow['department'];
}

$events = [];
$eventMeta = [];



while ($row = $result->fetch_assoc()) {

    $refundApplied = $row['refund_id'] !== null;

    $hodStatus = $refundApplied ? ($row['hod_status'] ?? 'Pending') : null;
    $adminStatus = $refundApplied ? ($row['admin_status'] ?? 'Pending') : null;

    $displayStatus = "Not Yet Applied";

    if ($refundApplied) {
        if ($adminStatus === 'Rejected') $displayStatus = "Rejected by Admin";
        elseif ($adminStatus === 'Paid') $displayStatus = "Payment Completed";
        elseif ($hodStatus === 'Approved') $displayStatus = "Approved by HOD";
        elseif ($hodStatus === 'Rejected') $displayStatus = "Rejected by HOD";
        elseif ($hodStatus === 'Clarification Needed') $displayStatus = "Clarification Needed";
        else $displayStatus = "Pending";
    }

        $perStudent = $row['per_student_amount'];
        if ($adminStatus === 'Paid' && $row['refunded_amount'] !== null && (int)$row['number_of_students'] > 0) {
        $perStudent = number_format((float)$row['refunded_amount'] / (int)$row['number_of_students'], 2, '.', '');
        }
    $eventMeta[] = [
        "refund_id" => $row['refund_id'] !== null ? (int)$row['refund_id'] : null,
        "admin_status" => $adminStatus,
        "refunded" => $row['refunded_amount']
    ];

    $events[] = [
        "permission_id"      => (int)$row['permission_id'],
        "event_name"         => $row['event_name'],
        "event_type"         => $row['event_type'],
        "leader_usn"         => $row['leader_usn'],
        "leader_name"        => $row['leader_name'],
        "refund_applied"     => $refundApplied,
        "display_status"     => $displayStatus,
        "amount"             => $row['amount'],
        "per_student_amount" => $perStudent,
        "number_of_students" => $row['number_of_students'],
        "created_at"         => $row['created_at'],
    ];
}

/* ===========================================================
   DEPARTMENT-WISE STATUS + MY OWN DEPARTMENT'S SHARE
=========================================================== */

$refundIds = [];

foreach ($eventMeta as $m) {
    if ($m['refund_id'] !== null) {
        $refundIds[] = $m['refund_id'];
    }
}

$sharesByRefund = [];

if (!empty($refundIds)) {

    $refundIds = array_values(array_unique($refundIds));
    $sph = implode(',', array_fill(0, count($refundIds), '?'));

    $shareStmt = $conn->prepare(
        "SELECT refund_id, department, number_of_students,
                share_amount, hod_status, hod_remarks
         FROM refund_shares
         WHERE refund_id IN ($sph)
         ORDER BY department"
    );
    $shareStmt->bind_param(str_repeat('i', count($refundIds)), ...$refundIds);
    $shareStmt->execute();
    $shareResult = $shareStmt->get_result();

    while ($sh = $shareResult->fetch_assoc()) {
        $sharesByRefund[$sh['refund_id']][] = $sh;
    }

    $shareStmt->close();
}

foreach ($events as $i => &$ev) {

    $meta = $eventMeta[$i];
    $shares = ($meta['refund_id'] !== null) ? ($sharesByRefund[$meta['refund_id']] ?? []) : [];

    $ev['dept_shares'] = $shares;
    $ev['my_department'] = $myDept;
    $ev['admin_status'] = $meta['admin_status'];

    if (empty($shares)) {
        continue;
    }

    $payable = 0.0;

    foreach ($shares as $sh) {
        if ($sh['hod_status'] === 'Approved') {
            $payable += (float)$sh['share_amount'];
        }
    }

    foreach ($shares as $sh) {

        if (strcasecmp(trim($sh['department']), trim((string)$myDept)) !== 0) {
            continue;
        }

        $n = max((int)$sh['number_of_students'], 1);

        if ($sh['hod_status'] === 'Rejected') {

            // my department's share was rejected: nothing for me
            $ev['per_student_amount'] = null;

        } elseif ($sh['hod_status'] === 'Approved') {

            $mine = (float)$sh['share_amount'];

            if ($meta['admin_status'] === 'Paid' && $meta['refunded'] !== null && $payable > 0
                && abs((float)$meta['refunded'] - $payable) >= 0.005) {

                // paid less than approved: my part is in proportion
                $mine = round((float)$meta['refunded'] * $mine / $payable, 2);
            }

            $ev['per_student_amount'] = number_format($mine / $n, 2, '.', '');
        }
    }
}
unset($ev);

echo json_encode([
    "status" => "success",
    "data" => $events
]);

?>