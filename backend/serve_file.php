<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['student', 'hod', 'admin']);

$columnMap = [
    'payment_proof'       => ['owner_type' => 'refund_request',     'table' => 'refund_requests',     'usn_col' => 'usn',        'dept_col' => 'department'],
    'participation_proof' => ['owner_type' => 'refund_request',     'table' => 'refund_requests',     'usn_col' => 'usn',        'dept_col' => 'department'],
    'bank_proof'          => ['owner_type' => 'refund_request',     'table' => 'refund_requests',     'usn_col' => 'usn',        'dept_col' => 'department'],
    'invitation_file'     => ['owner_type' => 'permission_request', 'table' => 'permission_requests', 'usn_col' => 'leader_usn', 'dept_col' => 'leader_department'],
    'supporting_file'     => ['owner_type' => 'permission_request', 'table' => 'permission_requests', 'usn_col' => 'leader_usn', 'dept_col' => 'leader_department'],
];

$column = $_GET['column'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if (!isset($columnMap[$column]) || $id <= 0) {
    http_response_code(400);
    exit("Bad request");
}

$ownerType = $columnMap[$column]['owner_type'];
$table     = $columnMap[$column]['table'];
$usnCol    = $columnMap[$column]['usn_col'];
$deptCol   = $columnMap[$column]['dept_col'];

$ownerStmt = $conn->prepare(
    "SELECT `$usnCol` AS owner_usn, `$deptCol` AS owner_dept
     FROM `$table` WHERE id = ?"
);
$ownerStmt->bind_param("i", $id);
$ownerStmt->execute();
$ownerRow = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();

if (!$ownerRow) {
    http_response_code(404);
    exit("Not found");
}

$role = current_role();

if ($role === 'student' && $ownerRow['owner_usn'] !== current_login_id()) {
    http_response_code(403);
    exit("Not authorized");
}

/* An HOD may open a file when THEIR department is part of that request:
   - permission files: their department has an approval row for it
   - refund files:     their department has a refund share in it
   (so the AIML HOD can open a team request led by a CSE student). */
if ($role === 'hod') {

    if ($ownerType === 'permission_request') {
        $accessSql = "SELECT 1 FROM permission_department_approvals
                      WHERE permission_id = ?
                        AND LOWER(TRIM(department)) = LOWER(TRIM(?))
                      LIMIT 1";
    } else {
        $accessSql = "SELECT 1 FROM refund_shares
                      WHERE refund_id = ?
                        AND LOWER(TRIM(department)) = LOWER(TRIM(?))
                      LIMIT 1";
    }

    $hodDept = current_department();

    $accessStmt = $conn->prepare($accessSql);
    $accessStmt->bind_param("is", $id, $hodDept);
    $accessStmt->execute();
    $hodAllowed = $accessStmt->get_result()->num_rows > 0;
    $accessStmt->close();

    if (!$hodAllowed) {
        http_response_code(403);
        exit("Not authorized");
    }
}

$fileStmt = $conn->prepare(
    "SELECT file_data, mime_type
     FROM attachments
     WHERE owner_type = ? AND owner_id = ? AND file_role = ?"
);
$fileStmt->bind_param("sis", $ownerType, $id, $column);
$fileStmt->execute();
$fileRow = $fileStmt->get_result()->fetch_assoc();
$fileStmt->close();

if (!$fileRow || empty($fileRow['file_data'])) {
    http_response_code(404);
    exit("Not found");
}

// ---- Stream it back ----
$mime = $fileRow['mime_type'] ?: 'application/octet-stream';

header("Content-Type: " . $mime);
header("Content-Length: " . strlen($fileRow['file_data']));
header("Cache-Control: private, max-age=0, no-cache");

echo $fileRow['file_data'];
exit;