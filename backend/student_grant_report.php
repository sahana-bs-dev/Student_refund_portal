<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "auth_check.php";
require_once "refund_lib.php";

require_login(['hod']);

$academic_year = getCurrentAcademicYear();
$department = current_department();

$format = $_GET['format'] ?? '';

$semesterFilter = preg_replace('/\D/', '', $_GET['semester'] ?? '');

$yearFilter = $_GET['year'] ?? 'all';

$yearSemesters = [
    '1' => [1, 2],
    '2' => [3, 4],
    '3' => [5, 6],
    '4' => [7, 8]
];

if ($yearFilter !== 'all' && !isset($yearSemesters[$yearFilter])) {
    http_response_code(400);
    die("Invalid year selected");
}

/* ===========================================================
   PAID REFUNDS IN WHICH THIS HOD'S DEPARTMENT HAS AN APPROVED SHARE
   Each student of this department gets an equal part of the money
   that was actually paid for the department's share.
=========================================================== */

$stmt = $conn->prepare(
    "SELECT r.id, r.usn, r.name, r.department, r.refund_type,
            COALESCE(r.semester, s.semester) AS semester,
            r.refund_reason
     FROM refund_requests r
     LEFT JOIN students s ON s.usn = r.usn
     WHERE r.admin_status = 'Paid'
     AND r.academic_year = ?
     AND EXISTS (
            SELECT 1
            FROM refund_shares sh
            WHERE sh.refund_id = r.id
            AND sh.hod_status = 'Approved'
            AND LOWER(TRIM(sh.department)) = LOWER(TRIM(?))
     )
     ORDER BY r.name ASC, r.id ASC"
);
$stmt->bind_param("ss", $academic_year, $department);

$stmt->execute();
$result = $stmt->get_result();

$requests = [];
$ids = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
    $ids[] = (int) $row['id'];
}

$allocations = refundAllocations($conn, $ids);

/* members of THIS department in each refund */

$membersByRefundId = [];

if (!empty($ids)) {

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $memberStmt = $conn->prepare(
        "SELECT rm.refund_id, rm.usn, s.name AS student_name,
                s.department, s.semester
         FROM refund_members rm
         JOIN students s ON s.usn = rm.usn
         WHERE rm.refund_id IN ($placeholders)
           AND LOWER(TRIM(s.department)) = LOWER(TRIM(?))"
    );

    $params = array_merge($ids, [$department]);
    $memberStmt->bind_param(str_repeat('i', count($ids)) . 's', ...$params);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();

    while ($m = $memberResult->fetch_assoc()) {
        $membersByRefundId[$m['refund_id']][] = $m;
    }

    $memberStmt->close();
}

$entries = [];
$deptNorm = strtolower(trim($department));

foreach ($requests as $r) {

    $members = $membersByRefundId[$r['id']] ?? [];
    $leaderInDept = (strtolower(trim($r['department'])) === $deptNorm);

    $studentCount = count($members) + ($leaderInDept ? 1 : 0);

    if ($studentCount < 1) {
        continue;
    }

    $deptPaid = $allocations[(int) $r['id']]['depts'][$deptNorm]['paid_part'] ?? 0.0;

    $share = round($deptPaid / $studentCount, 2);

    if ($leaderInDept) {
        $entries[] = [
            'usn' => $r['usn'],
            'name' => $r['name'],
            'semester' => $r['semester'],
            'event' => $r['refund_reason'],
            'amount' => $share
        ];
    }

    foreach ($members as $m) {

        $entries[] = [
            'usn' => $m['usn'],
            'name' => $m['student_name'],
            // a member's own semester (the leader's may be different)
            'semester' => $m['semester'] ?? $r['semester'],
            'event' => $r['refund_reason'],
            'amount' => $share
        ];
    }
}

$byStudent = [];

foreach ($entries as $e) {

    $studentKey = $e['usn'];
    $semKey = (string) ($e['semester'] ?? '-');

    if (!isset($byStudent[$studentKey])) {
        $byStudent[$studentKey] = [
            'usn' => $e['usn'],
            'name' => $e['name'],
            'semesters' => [],
            'total' => 0.0
        ];
    }

    if (!isset($byStudent[$studentKey]['semesters'][$semKey])) {
        $byStudent[$studentKey]['semesters'][$semKey] = [
            'semester' => $semKey,
            'events' => [],
            'count' => 0,
            'total' => 0.0
        ];
    }

    $byStudent[$studentKey]['semesters'][$semKey]['events'][] =
        $e['event'] . ' - Rs.' . number_format($e['amount'], 2);
    $byStudent[$studentKey]['semesters'][$semKey]['count'] += 1;
    $byStudent[$studentKey]['semesters'][$semKey]['total'] += $e['amount'];
    $byStudent[$studentKey]['total'] += $e['amount'];
}

if ($semesterFilter !== '') {
    foreach ($byStudent as $key => $s) {
        $matching = array_filter($s['semesters'], function ($grp) use ($semesterFilter) {
            return preg_replace('/\D/', '', (string) $grp['semester']) === $semesterFilter;
        });

        if (empty($matching)) {
            unset($byStudent[$key]);
            continue;
        }

        $byStudent[$key]['semesters'] = $matching;
        $byStudent[$key]['total'] = array_sum(array_column($matching, 'total'));
    }
}

if ($yearFilter !== 'all') {

    $allowedSemesters = $yearSemesters[$yearFilter];

    foreach ($byStudent as $key => $s) {

        $matching = array_filter(
            $s['semesters'],
            function ($grp) use ($allowedSemesters) {

                $sem = (int) preg_replace(
                    '/\D/',
                    '',
                    (string) $grp['semester']
                );

                return in_array(
                    $sem,
                    $allowedSemesters,
                    true
                );
            }
        );

        if (empty($matching)) {
            unset($byStudent[$key]);
            continue;
        }

        $byStudent[$key]['semesters'] = $matching;

        $byStudent[$key]['total'] =
            array_sum(
                array_column(
                    $matching,
                    'total'
                )
            );
    }
}

foreach ($byStudent as $key => $s) {
    uasort($byStudent[$key]['semesters'], function ($a, $b) {
        return ((int) preg_replace('/\D/', '', (string) $a['semester']))
             - ((int) preg_replace('/\D/', '', (string) $b['semester']));
    });
    $byStudent[$key]['semesters'] = array_values($byStudent[$key]['semesters']);
}

uasort($byStudent, function ($a, $b) {
    return strnatcasecmp($a['usn'] ?? '', $b['usn'] ?? '');
});

$byStudent = array_values($byStudent);

if ($format === 'json') {

    header("Content-Type: application/json");

    $grandTotal = 0.0;
    foreach ($byStudent as $s) {
        $grandTotal += $s['total'];
    }

    echo json_encode([
        "status" => "success",
        "department" => $department,
        "academic_year" => $academic_year,
        "year" => $yearFilter,
        "data" => $byStudent,
        "grand_total" => $grandTotal
    ]);

    $conn->close();
    exit;
}

$filenameSuffix = preg_replace(
    '/[^a-zA-Z0-9_-]/',
    '',
    $academic_year
)
. "_"
. preg_replace(
    '/[^a-zA-Z0-9_-]/',
    '',
    $department
);

if ($yearFilter !== 'all') {
    $filenameSuffix .= "_Year_" . $yearFilter;
}

if ($semesterFilter !== '') {
    $filenameSuffix .= "_Sem" . $semesterFilter;
}

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=student_grant_report_" . $filenameSuffix . ".xls");

function reportCell($value, $forceText = false) {
    $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $style = $forceText ? " style=\"mso-number-format:'\\@';\"" : "";
    return "<td$style>$value</td>";
}

echo "<html><head><meta charset=\"UTF-8\"></head><body>\n";
echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"6\">\n";
echo "<colgroup>
        <col style=\"width:50px\">
        <col style=\"width:150px\">
        <col style=\"width:220px\">
        <col style=\"width:120px\">
        <col style=\"width:340px\">
        <col style=\"width:160px\">
      </colgroup>\n";

echo "<tr style=\"background:#2563eb;color:#ffffff;font-weight:bold;\">
        <th>S.No</th>
        <th>USN</th>
        <th>Student Name</th>
        <th>Semester</th>
        <th>Events Refunded</th>
        <th>Total Amount Granted</th>
      </tr>\n";

$sno = 1;
$grandTotal = 0.0;

foreach ($byStudent as $s) {

    $semesterLines = array_map(
        fn($grp) => "Sem " . htmlspecialchars($grp['semester'], ENT_QUOTES, 'UTF-8')
            . " - " . $grp['count'] . " req(s)",
        $s['semesters']
    );
    $semesterHtml = implode("<br>", $semesterLines);

    $eventBlocks = array_map(function ($grp) {
        $header = "<b>Sem " . htmlspecialchars($grp['semester'], ENT_QUOTES, 'UTF-8') . "</b>";
        $eventsHtml = implode("<br>", array_map(
            fn($ev) => htmlspecialchars($ev, ENT_QUOTES, 'UTF-8'),
            $grp['events']
        ));
        return $header . "<br>" . $eventsHtml;
    }, $s['semesters']);
    $eventsHtml = implode("<br><br>", $eventBlocks);

    echo "<tr valign=\"top\">";
    echo "<td>" . $sno . "</td>";
    echo reportCell($s['usn'], true);
    echo reportCell($s['name']);
    echo "<td>" . $semesterHtml . "</td>";
    echo "<td>" . $eventsHtml . "</td>";
    echo "<td>" . number_format($s['total'], 2) . "</td>";
    echo "</tr>\n";

    $grandTotal += $s['total'];
    $sno++;
}

echo "<tr style=\"background:#e5e9f2;font-weight:bold;\">
        <td colspan=\"5\" align=\"right\">Grand Total</td>
        <td>" . number_format($grandTotal, 2) . "</td>
      </tr>\n";

echo "</table>\n";
echo "</body></html>\n";

$conn->close();

?>