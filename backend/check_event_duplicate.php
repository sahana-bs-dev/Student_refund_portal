<?php

require_once "db.php";
require_once "auth_check.php";
require_once "permission_lib.php";

require_login(['student']);

mysqli_report(MYSQLI_REPORT_OFF);

header("Content-Type: application/json");

/* ===========================================================
   LIVE DUPLICATE CHECK (used by the permission form while the
   student is still typing). It only READS; nothing is saved.
   apply_permission.php repeats the same check on submit, so this
   never replaces it.
=========================================================== */

if ($_SERVER["REQUEST_METHOD"] != "POST") {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method"
    ]);

    exit;
}

$leader_usn = current_login_id();

$event_name = trim($_POST['event_name'] ?? '');
$event_date = trim($_POST['event_date'] ?? '');

$d = DateTime::createFromFormat('Y-m-d', $event_date);

if ($event_name === '' || !$d || $d->format('Y-m-d') !== $event_date) {

    echo json_encode(["status" => "success", "duplicate" => false]);
    exit;
}

/* ===========================================================
   WHO IS IN THE TEAM
   Entered member USNs are matched to real students (the leader
   is always included). Unknown USNs are ignored here; the submit
   step reports them.
=========================================================== */

$entered = $_POST['member_usn'] ?? [];

if (!is_array($entered)) {
    $entered = [];
}

$entered = array_values(array_unique(array_filter(array_map('trim', $entered), function ($u) {
    return $u !== '';
})));

$participants = [$leader_usn];

if (!empty($entered)) {

    $ph = implode(',', array_fill(0, count($entered), '?'));

    $stu = $conn->prepare("SELECT usn FROM students WHERE usn IN ($ph)");
    $stu->bind_param(str_repeat('s', count($entered)), ...$entered);
    $stu->execute();
    $res = $stu->get_result();

    while ($r = $res->fetch_assoc()) {
        $participants[] = $r['usn'];
    }

    $stu->close();
}

$participants = array_values(array_unique($participants));

$row = findEventConflict($conn, $event_name, $event_date, $participants, $leader_usn);

if (!$row) {

    echo json_encode(["status" => "success", "duplicate" => false]);
    exit;
}

$who = (strcasecmp($row['usn'], $leader_usn) === 0) ? "You are" : $row['usn'] . " is";

echo json_encode([
    "status" => "success",
    "duplicate" => true,
    "message" => $who . " already part of a request for this event (team leader "
                 . $row['leader_usn'] . "). A student can be in only one request per event."
]);

?>