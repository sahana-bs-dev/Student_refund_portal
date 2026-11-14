<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['student']);

header("Content-Type: application/json");

$usn = current_login_id();

$stmt = $conn->prepare(
    "SELECT pr.id,
            pr.event_name,
            pr.leader_department AS department,
            pr.leader_semester AS semester,
            pr.team_type,
            pr.team_name,
            pr.team_size,
            et.event_type_name AS event_type
     FROM permission_requests pr
     LEFT JOIN event_types et ON et.event_type_id = pr.event_type_id
     WHERE pr.leader_usn = ?
       AND pr.hod_status = 'Approved'
     ORDER BY pr.id DESC"
);
$stmt->bind_param("s", $usn);
$stmt->execute();
$result = $stmt->get_result();

$permissions = [];

while ($row = $result->fetch_assoc()) {
    $row['label'] = $row['event_type'] . ' - ' . $row['event_name'];
    $row['members'] = [];
    $permissions[] = $row;
}
$stmt->close();

$teamIds = [];
foreach ($permissions as $p) {
    if ($p['team_type'] === 'Team') {
        $teamIds[] = (int) $p['id'];
    }
}

if (!empty($teamIds)) {

    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $types = str_repeat('i', count($teamIds));

    $memberStmt = $conn->prepare(
        "SELECT pm.permission_id,
                pm.usn,
                s.name AS student_name,
                s.department
         FROM permission_members pm
         LEFT JOIN students s ON s.usn = pm.usn
         WHERE pm.permission_id IN ($placeholders)"
    );
    $memberStmt->bind_param($types, ...$teamIds);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();

    $membersByPermission = [];
    while ($m = $memberResult->fetch_assoc()) {
        $membersByPermission[$m['permission_id']][] = [
            'usn' => $m['usn'],
            'student_name' => $m['student_name'],
            'department' => $m['department']
        ];
    }
    $memberStmt->close();

    foreach ($permissions as &$p) {
        if (isset($membersByPermission[$p['id']])) {
            $p['members'] = $membersByPermission[$p['id']];
        }
    }
    unset($p);
}

echo json_encode([
    "status" => "success",
    "data" => $permissions
]);

?>