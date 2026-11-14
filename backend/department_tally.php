<?php

require_once "db.php";
require_once "auth_check.php";
require_once "refund_lib.php";

require_login(['admin']);

header("Content-Type: application/json");

/* ===========================================================
   DEPARTMENT-WISE REFUND TALLY

   Each refund is split by department shares: only departments
   whose HOD APPROVED their share receive money. If the admin paid
   the full approved total, each department gets its share; if less
   was paid, each approved department gets the same proportion.

   Example: Rs.1000 requested = CSE 500 + AIML 250 + AIDS 250. AIML is
   rejected and Rs.750 is paid -> CSE 500, AIDS 250, AIML 0.
=========================================================== */

$result = $conn->query(
    "SELECT id, department
     FROM refund_requests
     WHERE admin_status = 'Paid'"
);

$requests = [];
$ids = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
    $ids[] = (int) $row['id'];
}

$allocations = refundAllocations($conn, $ids);

$totals = [];   // department => ['paid_ids' => [...], 'total' => float]
$grand_total = 0.0;

foreach ($requests as $r) {

    $alloc = $allocations[(int) $r['id']] ?? null;

    if ($alloc === null) {
        continue;
    }

    $grand_total += $alloc['paid_total'];

    $credited = 0.0;

    foreach ($alloc['depts'] as $info) {

        if ($info['paid_part'] <= 0) {
            continue;
        }

        $key = trim((string) $info['department']) === '' ? 'Unknown' : trim($info['department']);

        if (!isset($totals[$key])) {
            $totals[$key] = ['paid_ids' => [], 'total' => 0.0];
        }

        $totals[$key]['total'] += $info['paid_part'];
        $totals[$key]['paid_ids'][$r['id']] = true;
        $credited += $info['paid_part'];
    }

    // safety net: a paid refund with no approved share (old data) is
    // credited to the leader's department so no money goes missing
    if ($credited <= 0) {

        $key = trim((string) $r['department']) === '' ? 'Unknown' : trim($r['department']);

        if (!isset($totals[$key])) {
            $totals[$key] = ['paid_ids' => [], 'total' => 0.0];
        }

        $totals[$key]['total'] += $alloc['paid_total'];
        $totals[$key]['paid_ids'][$r['id']] = true;
    }
}

$departments = [];

foreach ($totals as $dept => $info) {
    $departments[] = [
        "department" => $dept,
        "requests_paid" => count($info['paid_ids']),
        "total_refunded" => round($info['total'], 2)
    ];
}

usort($departments, function ($a, $b) {
    return $b['total_refunded'] <=> $a['total_refunded'];
});

echo json_encode([
    "status" => "success",
    "data" => $departments,
    "grand_total" => round($grand_total, 2),
    "grand_count" => count($requests)
]);

?>