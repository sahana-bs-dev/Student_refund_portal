<?php

require_once "db.php";
require_once "crypto.php";
require_once "auth_check.php";
require_once "refund_lib.php";

require_login(['admin', 'hod']);

$type = $_GET['type'] ?? '';

if (current_role() === 'admin') {

    if ($type !== 'admin') {
        http_response_code(403);
        die("Not authorized");
    }

} elseif (current_role() === 'hod') {

    if ($type !== 'hod') {
        http_response_code(403);
        die("Not authorized");
    }

    $department = current_department();
}

$yearSemesters = [
    '1' => [1, 2],
    '2' => [3, 4],
    '3' => [5, 6],
    '4' => [7, 8]
];

$bankColumns = "a.account_holder_name AS account_holder,
                a.account_number, a.account_iv,
                a.ifsc_code, a.ifsc_iv,
                a.bank_name, a.bank_iv";

/* Every export row ends up in this shape:
   id, participants[], reason, program_start_date, amount,
   account_holder, account_number, account_iv, ifsc_code, ifsc_iv,
   bank_name, bank_iv */
$rows = [];

$exportYear = $_GET['year'] ?? 'all';

if ($exportYear !== 'all' && !isset($yearSemesters[$exportYear])) {
    http_response_code(400);
    die("Invalid year selected");
}

/* ============================================================
   ADMIN EXPORT
   One row per PAID refund (the admin pays the team leader once).
   Only students of departments whose HOD approved their share
   are listed, and the amount is what was actually paid.
   ============================================================ */

if ($type == "admin") {

    $exportDepartment = trim($_GET['department'] ?? '');

    $conditions = ["r.admin_status = 'Paid'", "r.admin_archived = 0"];
    $bindTypes = "";
    $bindValues = [];

    // department filter: refunds in which that department has an approved share
    if ($exportDepartment !== '' && strtolower($exportDepartment) !== 'all') {

        $conditions[] =
            "EXISTS (SELECT 1 FROM refund_shares shd
                     WHERE shd.refund_id = r.id
                       AND shd.hod_status = 'Approved'
                       AND LOWER(TRIM(shd.department)) = LOWER(TRIM(?)))";

        $bindTypes .= "s";
        $bindValues[] = $exportDepartment;
    }

    if ($exportYear !== 'all') {

        $conditions[] = "CAST(TRIM(r.semester) AS UNSIGNED) IN (?, ?)";

        $bindTypes .= "ii";
        $bindValues[] = $yearSemesters[$exportYear][0];
        $bindValues[] = $yearSemesters[$exportYear][1];
    }

    $sql = "SELECT r.id, r.usn, s.name, r.refund_reason,
                   COALESCE(
                       r.refunded_amount,
                       NULLIF((SELECT SUM(x.share_amount) FROM refund_shares x
                                WHERE x.refund_id = r.id AND x.hod_status = 'Approved'), 0),
                       r.amount
                   ) AS amount,
                   (SELECT GROUP_CONCAT(rm.usn ORDER BY rm.usn SEPARATOR ',')
                      FROM refund_members rm
                      JOIN students ms ON ms.usn = rm.usn
                     WHERE rm.refund_id = r.id
                       AND EXISTS (SELECT 1 FROM refund_shares shm
                                    WHERE shm.refund_id = r.id
                                      AND shm.hod_status = 'Approved'
                                      AND LOWER(TRIM(shm.department)) = LOWER(TRIM(ms.department)))
                   ) AS member_usns,
                   pr.event_date AS program_start_date,
                   $bankColumns
            FROM refund_requests r
            JOIN students s ON r.usn = s.usn
            LEFT JOIN permission_requests pr ON pr.id = r.permission_request_id
            LEFT JOIN account_details a ON r.usn = a.usn
            WHERE " . implode(" AND ", $conditions) . "
            ORDER BY r.id";

    $stmt = $conn->prepare($sql);

    if ($bindTypes !== "") {
        $stmt->bind_param($bindTypes, ...$bindValues);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $raw = [];
    $allMemberUsns = [];

    while ($row = $result->fetch_assoc()) {

        $raw[] = $row;

        if (!empty($row['member_usns'])) {
            foreach (explode(',', $row['member_usns']) as $memberUsn) {
                $memberUsn = trim($memberUsn);
                if ($memberUsn !== '') {
                    $allMemberUsns[$memberUsn] = true;
                }
            }
        }
    }

    $stmt->close();

    $memberNames = [];

    if (!empty($allMemberUsns)) {

        $usns = array_keys($allMemberUsns);
        $placeholders = implode(',', array_fill(0, count($usns), '?'));

        $nameStmt = $conn->prepare(
            "SELECT usn, name FROM students WHERE usn IN ($placeholders)"
        );
        $nameStmt->bind_param(str_repeat('s', count($usns)), ...$usns);
        $nameStmt->execute();
        $nameResult = $nameStmt->get_result();

        while ($n = $nameResult->fetch_assoc()) {
            $memberNames[$n['usn']] = $n['name'];
        }

        $nameStmt->close();
    }

    foreach ($raw as $row) {

        $participants = [$row['name'] . ' (' . $row['usn'] . ')'];

        if (!empty($row['member_usns'])) {
            foreach (explode(',', $row['member_usns']) as $memberUsn) {
                $memberUsn = trim($memberUsn);
                if ($memberUsn === '') {
                    continue;
                }
                $participants[] = ($memberNames[$memberUsn] ?? $memberUsn) . ' (' . $memberUsn . ')';
            }
        }

        $row['participants'] = $participants;
        $row['reason'] = $row['refund_reason'];
        $rows[] = $row;
    }

/* ============================================================
   HOD EXPORT
   One row per refund share APPROVED by this HOD (and not yet
   archived). Only this department's students are listed and the
   amount is this department's part. Exporting archives only this
   department's share, so other departments still see theirs.
   ============================================================ */

} elseif ($type == "hod") {

    $stmt = $conn->prepare(
        "SELECT r.id, r.usn, ls.name AS leader_name,
                r.department AS leader_department,
                r.semester AS leader_semester,
                r.refund_reason, r.admin_status,
                sh.share_amount,
                pr.event_date AS program_start_date,
                $bankColumns
         FROM refund_shares sh
         JOIN refund_requests r ON r.id = sh.refund_id
         JOIN students ls ON ls.usn = r.usn
         LEFT JOIN permission_requests pr ON pr.id = r.permission_request_id
         LEFT JOIN account_details a ON r.usn = a.usn
         WHERE LOWER(TRIM(sh.department)) = LOWER(TRIM(?))
           AND sh.hod_status = 'Approved'
           AND sh.hod_archived = 0
         ORDER BY r.id"
    );
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $result = $stmt->get_result();

    $raw = [];
    $refundIds = [];

    while ($row = $result->fetch_assoc()) {
        $raw[] = $row;
        $refundIds[] = (int)$row['id'];
    }

    $stmt->close();

    $allocations = refundAllocations($conn, $refundIds);

    // this department's members in each refund
    $membersByRefund = [];

    if (!empty($refundIds)) {

        $ph = implode(',', array_fill(0, count($refundIds), '?'));

        $mStmt = $conn->prepare(
            "SELECT rm.refund_id, rm.usn, s.name AS student_name, s.semester
             FROM refund_members rm
             JOIN students s ON s.usn = rm.usn
             WHERE rm.refund_id IN ($ph)
               AND LOWER(TRIM(s.department)) = LOWER(TRIM(?))
             ORDER BY rm.usn"
        );
        $params = array_merge($refundIds, [$department]);
        $mStmt->bind_param(str_repeat('i', count($refundIds)) . 's', ...$params);
        $mStmt->execute();
        $mResult = $mStmt->get_result();

        while ($m = $mResult->fetch_assoc()) {
            $membersByRefund[$m['refund_id']][] = $m;
        }

        $mStmt->close();
    }

    $deptNorm = strtolower(trim($department));
    $semesterOf = function ($value) {
        return (int) preg_replace('/\D/', '', (string) $value);
    };

    foreach ($raw as $row) {

        $leaderInDept = (strtolower(trim($row['leader_department'])) === $deptNorm);
        $members = $membersByRefund[$row['id']] ?? [];

        $participants = [];
        $semesters = [];

        if ($leaderInDept) {
            $participants[] = $row['leader_name'] . ' (' . $row['usn'] . ')';
            $semesters[] = $semesterOf($row['leader_semester']);
        }

        foreach ($members as $m) {
            $participants[] = $m['student_name'] . ' (' . $m['usn'] . ')';
            $semesters[] = $semesterOf($m['semester']);
        }

        if (!$leaderInDept) {
            // the leader is the payee, so name them even though they belong
            // to another department
            $participants[] = 'Team Leader: ' . $row['leader_name'] . ' (' . $row['usn'] . ')';
        }

        // year filter: keep the row when any student of this department
        // is in one of that year's semesters
        if ($exportYear !== 'all'
            && empty(array_intersect($semesters, $yearSemesters[$exportYear]))) {
            continue;
        }

        // paid -> this department's part of what was paid, else its share
        $amount = (float)$row['share_amount'];

        if ($row['admin_status'] === 'Paid') {
            $amount = $allocations[(int)$row['id']]['depts'][$deptNorm]['paid_part'] ?? $amount;
        }

        $row['participants'] = $participants;
        $row['reason'] = $row['refund_reason'];
        $row['amount'] = number_format($amount, 2, '.', '');
        $rows[] = $row;
    }

} else {

    die("Invalid download type");

}

/* ============================================================
   EXCEL FILENAME
   ============================================================ */

$filenameSuffix = date('Y-m-d');

if (
    $type === "admin" &&
    !empty($exportDepartment) &&
    strtolower($exportDepartment) !== 'all'
) {
    $filenameSuffix .= "_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $exportDepartment);
}

if ($exportYear === 'all') {
    $filenameSuffix .= "_All_Years";
} else {
    $filenameSuffix .= "_Year_" . $exportYear;
}

header("Content-Type: application/vnd.ms-excel; charset=utf-8");

header(
    "Content-Disposition: attachment; filename=refund_requests_"
    . $filenameSuffix
    . ".xls"
);

/* ============================================================
   EXCEL CELL HELPER
   ============================================================ */

function excelCell($value, $forceText = false) {

    $value = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $style = $forceText
        ? " style=\"mso-number-format:'\\@';\""
        : "";

    return "<td$style>$value</td>";
}

/* ============================================================
   EXCEL TABLE
   ============================================================ */

echo "<html>
<head>
<meta charset=\"UTF-8\">
</head>
<body>\n";

echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"6\">\n";

echo "<colgroup>
        <col style=\"width:50px\">
        <col style=\"width:260px\">
        <col style=\"width:220px\">
        <col style=\"width:110px\">
        <col style=\"width:130px\">
        <col style=\"width:200px\">
        <col style=\"width:160px\">
        <col style=\"width:150px\">
        <col style=\"width:160px\">
      </colgroup>\n";

echo "<tr style=\"background:#2563eb;color:#ffffff;font-weight:bold;\">

        <th>S.No</th>
        <th>Participant Name and USN</th>
        <th>Event</th>
        <th>Event Date</th>
        <th>Amount Refunded</th>
        <th>Account Holder Name</th>
        <th>Account Number</th>
        <th>IFSC Code</th>
        <th>Bank</th>

      </tr>\n";

$sno = 1;
$ids = [];

foreach ($rows as $row) {

    $ids[] = (int)$row['id'];

    $accountNumber = (!empty($row['account_number']) && !empty($row['account_iv']))
        ? decryptData($row['account_number'], $row['account_iv']) : '';

    $ifscCode = (!empty($row['ifsc_code']) && !empty($row['ifsc_iv']))
        ? decryptData($row['ifsc_code'], $row['ifsc_iv']) : '';

    $bankName = (!empty($row['bank_name']) && !empty($row['bank_iv']))
        ? decryptData($row['bank_name'], $row['bank_iv']) : '';

    $participantsHtml = implode(
        "<br>",
        array_map(
            fn($p) => htmlspecialchars($p, ENT_QUOTES, 'UTF-8'),
            $row['participants']
        )
    );

    echo "<tr valign=\"top\">";

    echo "<td>" . $sno . "</td>";

    echo "<td>" . $participantsHtml . "</td>";

    echo excelCell($row['reason']);

    echo excelCell($row['program_start_date'] ?? '-', true);

    echo "<td>" . htmlspecialchars((string)$row['amount'], ENT_QUOTES, 'UTF-8') . "</td>";

    echo excelCell($row['account_holder']);

    echo excelCell($accountNumber, true);

    echo excelCell($ifscCode, true);

    echo excelCell($bankName);

    echo "</tr>\n";

    $sno++;
}

echo "</table>\n";

echo "</body></html>\n";

/* ============================================================
   ARCHIVE EXPORTED REQUESTS
   admin: the refund   |   hod: only THIS department's share
   ============================================================ */

if (!empty($ids)) {

    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $types = str_repeat("i", count($ids));

    if ($type == "admin") {

        $upd = $conn->prepare(
            "UPDATE refund_requests
             SET admin_archived = 1
             WHERE id IN ($placeholders)"
        );

        $upd->bind_param($types, ...$ids);
        $upd->execute();
    }

    if ($type == "hod") {

        $upd = $conn->prepare(
            "UPDATE refund_shares
             SET hod_archived = 1
             WHERE refund_id IN ($placeholders)
               AND LOWER(TRIM(department)) = LOWER(TRIM(?))"
        );

        $params = array_merge($ids, [$department]);
        $upd->bind_param($types . "s", ...$params);
        $upd->execute();

        foreach ($ids as $refundId) {
            syncRefundHodArchived($conn, $refundId);
        }
    }
}

$conn->close();

?>