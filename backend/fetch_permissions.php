<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['student']);

header("Content-Type: application/json");

$usn = current_login_id();

$stmt = $conn->prepare(
    "SELECT pr.id,
     pr.leader_usn,
     pr.leader_department,
     pr.leader_semester,
     pr.event_type_id,
     pr.event_name,
     pr.event_date,
     pr.event_end_date,
     pr.registration_deadline,
     pr.venue,
     pr.city,
     pr.mode,
     pr.reason,
     pr.registration_fee,
     pr.team_type,
     pr.team_name,
     pr.team_size,
     ainv.filename AS invitation_file,
     ainv.mime_type AS invitation_file_mime,
     asup.filename AS supporting_file,
     asup.mime_type AS supporting_file_mime,
     pr.hod_status,
     pr.hod_remarks,
     pr.student_reply,
     pr.replied_at,
     pr.approved_at,
     pr.created_at,
     pr.academic_year,
     et.event_type_name AS event_type, s.name AS leader_name
     FROM permission_requests pr
     LEFT JOIN attachments ainv ON ainv.owner_type='permission_request' AND ainv.owner_id=pr.id AND ainv.file_role='invitation_file'
     LEFT JOIN attachments asup ON asup.owner_type='permission_request' AND asup.owner_id=pr.id AND asup.file_role='supporting_file'
     LEFT JOIN event_types et ON et.event_type_id = pr.event_type_id
     LEFT JOIN students s ON s.usn = pr.leader_usn
     WHERE pr.leader_usn = ?
        OR pr.id IN (SELECT permission_id FROM permission_members WHERE usn = ?)
     ORDER BY pr.id DESC"
);
$stmt->bind_param("ss", $usn, $usn);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];

/* my own department (to highlight "your department" in the status) */
$myDept = null;
$deptStmt = $conn->prepare("SELECT department FROM students WHERE usn = ?");
$deptStmt->bind_param("s", $usn);
$deptStmt->execute();
$deptRow = $deptStmt->get_result()->fetch_assoc();
$deptStmt->close();

if ($deptRow) {
    $myDept = $deptRow['department'];
}

while ($row = $result->fetch_assoc()) {
    $row['members'] = [];
    $row['dept_approvals'] = [];
    // the requests listed here are the student's own (leader) or the
    // team's (member); only the leader can reply or apply for a refund
    $row['is_leader'] = (strcasecmp($row['leader_usn'], $usn) === 0);
    $row['my_department'] = $myDept;
    $requests[] = $row;
}

$teamIds = [];

foreach ($requests as $r) {
    if ($r['team_type'] === 'Team') {
        $teamIds[] = (int)$r['id'];
    }
}

if (!empty($teamIds)) {

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $types = str_repeat('i', count($teamIds));

    $memberStmt = $conn->prepare(
        "SELECT pm.permission_id, pm.usn, s.name AS student_name,
                s.department, s.semester
         FROM permission_members pm
         JOIN students s ON s.usn = pm.usn
         WHERE pm.permission_id IN ($placeholders)"
    );

    $bindParams = [$types];
    foreach ($teamIds as $key => $value) {
        $bindParams[] = &$teamIds[$key];
    }
    call_user_func_array([$memberStmt, 'bind_param'], $bindParams);

    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();

    $membersByPermissionId = [];

    while ($m = $memberResult->fetch_assoc()) {
        $membersByPermissionId[$m['permission_id']][] = $m;
    }

    foreach ($requests as &$r) {
        if ($r['team_type'] === 'Team') {
            $r['members'] = $membersByPermissionId[$r['id']] ?? [];
        }
    }
    unset($r);
}

/* ===========================================================
   DEPARTMENT-WISE STATUS (inter-department events)
=========================================================== */

if (!empty($requests)) {

    $allIds = array_map(function ($r) { return (int)$r['id']; }, $requests);
    $aph = implode(',', array_fill(0, count($allIds), '?'));

    $apStmt = $conn->prepare(
        "SELECT permission_id, department, hod_status, hod_remarks, decided_at
         FROM permission_department_approvals
         WHERE permission_id IN ($aph)
         ORDER BY department"
    );
    $apStmt->bind_param(str_repeat('i', count($allIds)), ...$allIds);
    $apStmt->execute();
    $apResult = $apStmt->get_result();

    $approvalsByPermission = [];

    while ($a = $apResult->fetch_assoc()) {
        $approvalsByPermission[$a['permission_id']][] = [
            "department" => $a['department'],
            "hod_status" => $a['hod_status'],
            "hod_remarks" => $a['hod_remarks'],
            "decided_at" => $a['decided_at']
        ];
    }

    $apStmt->close();

    foreach ($requests as &$r) {
        $r['dept_approvals'] = $approvalsByPermission[$r['id']] ?? [];
    }
    unset($r);
}

echo json_encode([
    "status" => "success",
    "data" => $requests
]);

?>