<?php

/* ===========================================================
   Shared helpers for per-department refund handling
=========================================================== */

/**
 * Recalculate the overall HOD status of a refund request from its
 * department shares and save it on refund_requests
 * (hod_status / hod_remarks).
 *
 * Rules:
 *   any share Clarification Needed               -> Clarification Needed
 *   else any share still Pending                 -> Pending
 *   else (all decided) at least one Approved     -> Approved
 *   else (all decided, none approved)            -> Rejected
 *
 * "Approved" means the refund can go to the admin. The admin pays
 * only the sum of the APPROVED shares. A rejected department's share
 * is simply not paid; it does not hold back the others.
 *
 * When every share is rejected, every department's share is archived
 * (same as the old behaviour of a rejected refund) so the leader can
 * apply again.
 *
 * Returns the new overall status.
 */
function recalcRefundStatus(mysqli $conn, int $refund_id): string
{
    $stmt = $conn->prepare(
        "SELECT department, hod_status, hod_remarks
         FROM refund_shares
         WHERE refund_id = ?
         ORDER BY department"
    );
    $stmt->bind_param("i", $refund_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $statuses = [];
    $remarks  = [];

    while ($row = $res->fetch_assoc()) {

        $statuses[] = $row['hod_status'];

        if (trim((string)$row['hod_remarks']) !== '') {
            $remarks[] = $row['department'] . ': ' . trim($row['hod_remarks']);
        }
    }

    $stmt->close();

    if (in_array('Clarification Needed', $statuses, true)) {
        $overall = 'Clarification Needed';
    } elseif (in_array('Pending', $statuses, true) || empty($statuses)) {
        $overall = 'Pending';
    } elseif (in_array('Approved', $statuses, true)) {
        $overall = 'Approved';
    } else {
        $overall = 'Rejected';
    }

    $remarkText = empty($remarks) ? null : implode(' | ', $remarks);

    $upd = $conn->prepare(
        "UPDATE refund_requests
         SET hod_status = ?,
             hod_remarks = ?
         WHERE id = ?"
    );
    $upd->bind_param("ssi", $overall, $remarkText, $refund_id);
    $upd->execute();
    $upd->close();

    if ($overall === 'Rejected') {

        $arch = $conn->prepare(
            "UPDATE refund_shares SET hod_archived = 1 WHERE refund_id = ?"
        );
        $arch->bind_param("i", $refund_id);
        $arch->execute();
        $arch->close();
    }

    syncRefundHodArchived($conn, $refund_id);

    return $overall;
}

/**
 * refund_requests.hod_archived is now only a summary:
 * 1 when EVERY department has archived its share, otherwise 0.
 * (The real flag is per department: refund_shares.hod_archived.)
 */
function syncRefundHodArchived(mysqli $conn, int $refund_id): void
{
    $stmt = $conn->prepare(
        "UPDATE refund_requests r
         SET r.hod_archived = IF(
             (SELECT COUNT(*) FROM refund_shares WHERE refund_id = r.id) > 0
             AND (SELECT COUNT(*) FROM refund_shares
                   WHERE refund_id = r.id AND hod_archived = 0) = 0,
             1, 0)
         WHERE r.id = ?"
    );
    $stmt->bind_param("i", $refund_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Amount the admin can pay for a refund = sum of approved shares.
 */
function refundPayableAmount(mysqli $conn, int $refund_id): float
{
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(share_amount), 0) AS payable
         FROM refund_shares
         WHERE refund_id = ? AND hod_status = 'Approved'"
    );
    $stmt->bind_param("i", $refund_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return round((float)$row['payable'], 2);
}

/**
 * How the money of refunds is split between departments.
 *
 * Only APPROVED shares receive money. If the admin paid exactly the
 * approved total, each department gets its share; if less was paid,
 * every approved share gets the same proportion of what was paid.
 *
 * Returns, per refund id:
 *   [
 *     'paid_total' => what was (or will be) paid in total,
 *     'payable'    => sum of approved shares,
 *     'depts'      => [ lower-case department => [
 *                         'department' => 'CSE',
 *                         'students'   => number of students in the share,
 *                         'share'      => the share amount,
 *                         'status'     => HOD status of the share,
 *                         'paid_part'  => this department's part of paid_total
 *                      ] ]
 *   ]
 */
function refundAllocations(mysqli $conn, array $refundIds): array
{
    $refundIds = array_values(array_unique(array_map('intval', $refundIds)));

    if (empty($refundIds)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($refundIds), '?'));
    $types = str_repeat('i', count($refundIds));

    $stmt = $conn->prepare(
        "SELECT id, amount, refunded_amount, department
         FROM refund_requests WHERE id IN ($ph)"
    );
    $stmt->bind_param($types, ...$refundIds);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    $refundRows = [];

    while ($r = $res->fetch_assoc()) {
        $refundRows[(int)$r['id']] = $r;
    }

    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT refund_id, department, number_of_students, share_amount, hod_status
         FROM refund_shares
         WHERE refund_id IN ($ph)
         ORDER BY department"
    );
    $stmt->bind_param($types, ...$refundIds);
    $stmt->execute();
    $res = $stmt->get_result();

    $sharesByRefund = [];

    while ($s = $res->fetch_assoc()) {
        $sharesByRefund[(int)$s['refund_id']][] = $s;
    }

    $stmt->close();

    foreach ($refundRows as $id => $r) {

        $shares = $sharesByRefund[$id] ?? [];
        $payable = 0.0;

        foreach ($shares as $s) {
            if ($s['hod_status'] === 'Approved') {
                $payable += (float)$s['share_amount'];
            }
        }

        if ($r['refunded_amount'] !== null) {
            $paidTotal = (float)$r['refunded_amount'];
        } elseif ($payable > 0) {
            $paidTotal = $payable;
        } else {
            $paidTotal = (float)$r['amount'];
        }

        $depts = [];

        foreach ($shares as $s) {

            $share = (float)$s['share_amount'];
            $paidPart = 0.0;

            if ($s['hod_status'] === 'Approved' && $payable > 0) {
                $paidPart = (abs($paidTotal - $payable) < 0.005)
                    ? $share
                    : round($paidTotal * $share / $payable, 2);
            }

            $depts[strtolower(trim($s['department']))] = [
                'department' => $s['department'],
                'students'   => (int)$s['number_of_students'],
                'share'      => $share,
                'status'     => $s['hod_status'],
                'paid_part'  => $paidPart
            ];
        }

        $out[$id] = [
            'paid_total' => $paidTotal,
            'payable'    => round($payable, 2),
            'depts'      => $depts
        ];
    }

    return $out;
}

/**
 * Refund shares of ONE department, for the HOD screens.
 *
 * $archived = 0 -> the HOD's active list
 * $archived = 1 -> the HOD's archive
 *
 * amount / number_of_students / hod_status / hod_remarks / hod_archived
 * are THIS department's own values. The first student of this
 * department is shown as the main student (usn / name); leader_usn and
 * leader_name always hold the real team leader.
 */
function fetchHodRefundShares(mysqli $conn, string $department, string $academic_year, int $archived): array
{
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
         r.amount AS total_amount,
         r.number_of_students AS total_students,
         sh.share_amount AS amount,
         sh.number_of_students AS number_of_students,
         r.per_student_amount,
         r.refunded_amount,
         r.admin_remarks,
         ap.filename AS payment_proof,
         ap.mime_type AS payment_proof_mime,
         apt.filename AS participation_proof,
         apt.mime_type AS participation_proof_mime,
         abk.filename AS bank_proof,
         abk.mime_type AS bank_proof_mime,
         sh.hod_status,
         sh.hod_remarks,
         r.hod_status AS overall_status,
         sh.hod_archived,
         r.admin_status,
         r.admin_archived,
         r.created_at,
         r.academic_year,
         pr.event_date AS program_start_date, et.event_type_name AS event_category
    FROM refund_shares sh
    JOIN refund_requests r ON r.id = sh.refund_id
    LEFT JOIN attachments ap ON ap.owner_type='refund_request' AND ap.owner_id=r.id AND ap.file_role='payment_proof'
    LEFT JOIN attachments apt ON apt.owner_type='refund_request' AND apt.owner_id=r.id AND apt.file_role='participation_proof'
    LEFT JOIN attachments abk ON abk.owner_type='refund_request' AND abk.owner_id=r.id AND abk.file_role='bank_proof'
         LEFT JOIN permission_requests pr ON pr.id = r.permission_request_id
         LEFT JOIN event_types et ON et.event_type_id = pr.event_type_id
         WHERE LOWER(TRIM(sh.department)) = LOWER(TRIM(?))
         AND sh.hod_archived = ?
         AND r.academic_year = ?
         ORDER BY r.id DESC"
    );

    $stmt->bind_param("sis", $department, $archived, $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();

    $requests = [];

    while ($row = $result->fetch_assoc()) {

        $row['members'] = [];
        $row['dept_progress'] = [];
        $row['leader_usn'] = $row['usn'];
        $row['leader_name'] = $row['name'];

        $requests[] = $row;
    }

    $stmt->close();

    if (empty($requests)) {
        return [];
    }

    $ids = array_map(function ($r) { return (int)$r['id']; }, $requests);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $allocations = refundAllocations($conn, $ids);
    $deptKey = strtolower(trim($department));

    /* students of THIS department in each refund (leader first) */

    $studentStmt = $conn->prepare(
        "SELECT x.refund_id, x.usn, s.name AS student_name,
                s.department, s.semester
         FROM (
             SELECT id AS refund_id, usn, 0 AS ord
               FROM refund_requests WHERE id IN ($ph)
             UNION ALL
             SELECT refund_id, usn, 1 AS ord
               FROM refund_members WHERE refund_id IN ($ph)
         ) x
         JOIN students s ON s.usn = x.usn
         WHERE LOWER(TRIM(s.department)) = LOWER(TRIM(?))
         ORDER BY x.refund_id, x.ord, x.usn"
    );

    $params = array_merge($ids, $ids, [$department]);
    $studentStmt->bind_param(str_repeat('i', count($ids) * 2) . 's', ...$params);
    $studentStmt->execute();
    $studentResult = $studentStmt->get_result();

    $studentsByRefund = [];

    while ($m = $studentResult->fetch_assoc()) {
        $studentsByRefund[$m['refund_id']][] = $m;
    }

    $studentStmt->close();

    /* progress of every department */

    $progStmt = $conn->prepare(
        "SELECT refund_id, department, hod_status
         FROM refund_shares
         WHERE refund_id IN ($ph)
         ORDER BY department"
    );

    $progStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $progStmt->execute();
    $progResult = $progStmt->get_result();

    $progressByRefund = [];

    while ($p = $progResult->fetch_assoc()) {
        $progressByRefund[$p['refund_id']][] = [
            "department" => $p['department'],
            "hod_status" => $p['hod_status']
        ];
    }

    $progStmt->close();

    foreach ($requests as &$r) {

        $students = $studentsByRefund[$r['id']] ?? [];

        if (!empty($students)) {

            $first = array_shift($students);

            $r['usn'] = $first['usn'];
            $r['name'] = $first['student_name'];
            $r['members'] = $students;
        }

        $r['dept_progress'] = $progressByRefund[$r['id']] ?? [];

        // paid: show this department's part of what was actually paid
        if ($r['admin_status'] === 'Paid' && $r['refunded_amount'] !== null) {

            $deptPaid = $allocations[$r['id']]['depts'][$deptKey]['paid_part'] ?? 0.0;
            $n = max((int)$r['number_of_students'], 1);

            $r['refunded_amount'] = number_format($deptPaid, 2, '.', '');
            $r['per_student_amount'] = number_format($deptPaid / $n, 2, '.', '');
        }
    }
    unset($r);

    return $requests;
}

?>