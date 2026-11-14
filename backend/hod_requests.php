<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "auth_check.php";

require_login(['hod']);

header("Content-Type: application/json");

$department = current_department();
$academic_year = getCurrentAcademicYear();

/* ===========================================================
   REQUESTS THAT INVOLVE THIS HOD'S DEPARTMENT
   hod_status / hod_remarks / student_reply here are THIS
   department's own values, not the overall team status.
=========================================================== */

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
     pda.hod_status,
     pda.hod_remarks,
     pda.student_reply,
     pda.replied_at,
     pda.decided_at AS approved_at,
     pr.hod_status AS overall_status,
     pr.created_at,
     pr.academic_year,
     s.name AS leader_name, et.event_type_name
     FROM permission_department_approvals pda
     JOIN permission_requests pr ON pr.id = pda.permission_id
     LEFT JOIN attachments ainv ON ainv.owner_type='permission_request' AND ainv.owner_id=pr.id AND ainv.file_role='invitation_file'
     LEFT JOIN attachments asup ON asup.owner_type='permission_request' AND asup.owner_id=pr.id AND asup.file_role='supporting_file'
     LEFT JOIN students s ON s.usn = pr.leader_usn
     LEFT JOIN event_types et ON et.event_type_id = pr.event_type_id
     WHERE LOWER(TRIM(pda.department)) = LOWER(TRIM(?))
     AND pr.academic_year = ?
     ORDER BY pr.id DESC"
);

$stmt->bind_param("ss", $department, $academic_year);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $row['members'] = [];
    $row['dept_progress'] = [];
    $requests[] = $row;
}

$stmt->close();

$allIds = [];
$teamIds = [];

foreach ($requests as $r) {

    $allIds[] = (int)$r['id'];

    if ($r['team_type'] === 'Team') {
        $teamIds[] = (int)$r['id'];
    }
}

/* ===========================================================
   MEMBERS OF THIS HOD'S DEPARTMENT ONLY
=========================================================== */

if (!empty($teamIds)) {

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $types = str_repeat('i', count($teamIds)) . 's';

    $memberStmt = $conn->prepare(
        "SELECT pm.permission_id, pm.usn, s.name AS student_name,
                s.department, s.semester
         FROM permission_members pm
         JOIN students s ON s.usn = pm.usn
         WHERE pm.permission_id IN ($placeholders)
           AND LOWER(TRIM(s.department)) = LOWER(TRIM(?))"
    );

    $params = array_merge($teamIds, [$department]);
    $memberStmt->bind_param($types, ...$params);

    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();

    $membersByPermissionId = [];

    while ($m = $memberResult->fetch_assoc()) {
        $membersByPermissionId[$m['permission_id']][] = $m;
    }

    $memberStmt->close();

    foreach ($requests as &$r) {
        if ($r['team_type'] === 'Team') {
            $r['members'] = $membersByPermissionId[$r['id']] ?? [];
        }
    }
    unset($r);
}

/* ===========================================================
   PROGRESS OF EVERY DEPARTMENT (for information)
=========================================================== */

if (!empty($allIds)) {

    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    $types = str_repeat('i', count($allIds));

    $progStmt = $conn->prepare(
        "SELECT permission_id, department, hod_status
         FROM permission_department_approvals
         WHERE permission_id IN ($placeholders)
         ORDER BY department"
    );

    $progStmt->bind_param($types, ...$allIds);
    $progStmt->execute();
    $progResult = $progStmt->get_result();

    $progressByPermissionId = [];

    while ($p = $progResult->fetch_assoc()) {
        $progressByPermissionId[$p['permission_id']][] = [
            "department" => $p['department'],
            "hod_status" => $p['hod_status']
        ];
    }

    $progStmt->close();

    foreach ($requests as &$r) {
        $r['dept_progress'] = $progressByPermissionId[$r['id']] ?? [];
    }
    unset($r);
}

echo json_encode([
    "status" => "success",
    "data" => $requests
]);

?>