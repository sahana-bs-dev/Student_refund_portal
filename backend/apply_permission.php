<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "auth_check.php";
require_once "permission_lib.php";

require_login(['student']);

// Make mysqli return false on errors (same behaviour on every PHP version)
mysqli_report(MYSQLI_REPORT_OFF);

header("Content-Type: application/json");

/* ===========================================================
   FILE UPLOAD FUNCTION
=========================================================== */

function handleUpload($fieldName, $required, &$error)
{
    if (
        !isset($_FILES[$fieldName]) ||
        $_FILES[$fieldName]['error'] == UPLOAD_ERR_NO_FILE
    ) {
        if ($required) {
            $error = "Please upload " . $fieldName;
        }
        return null;
    }

    if ($_FILES[$fieldName]['error'] != UPLOAD_ERR_OK) {
        $error = "Error uploading " . $fieldName;
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        $error = "$fieldName exceeds maximum upload size.";
        return null;
    }

    $extension = strtolower(
        pathinfo($file['name'], PATHINFO_EXTENSION)
    );

    if (!in_array($extension, ALLOWED_UPLOAD_EXT)) {
        $error = "Invalid file type for $fieldName";
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ALLOWED_UPLOAD_MIME)) {
        $error = "Invalid file content for $fieldName";
        return null;
    }

    $filename =
        bin2hex(random_bytes(16))
        . "."
        . $extension;

    return [
        "name" => $filename,
        "data" => file_get_contents($file['tmp_name']),
        "mime" => $mime
    ];
}

/* ===========================================================
   REQUEST CHECK
=========================================================== */

if ($_SERVER["REQUEST_METHOD"] != "POST") {

    echo json_encode([
        "status" => "error",
        "message" => "Invalid Request"
    ]);

    exit;
}

/* ===========================================================
   LOGGED IN STUDENT
=========================================================== */

$leader_usn = current_login_id();

$leaderStmt = $conn->prepare(
    "SELECT department, semester FROM students WHERE usn = ?"
);
$leaderStmt->bind_param("s", $leader_usn);
$leaderStmt->execute();
$leaderRow = $leaderStmt->get_result()->fetch_assoc();
$leaderStmt->close();

if (!$leaderRow) {

    echo json_encode([
        "status"=>"error",
        "message"=>"Student record not found"
    ]);

    exit;
}

$leader_department = $leaderRow['department'];
$leader_semester = (string)$leaderRow['semester'];

$academic_year = getCurrentAcademicYear();

/* ===========================================================
   EVENT DETAILS
=========================================================== */

$event_type_id =
(int)($_POST['event_type_id'] ?? 0);

$event_name =
trim($_POST['event_name'] ?? '');


$venue =
trim($_POST['venue'] ?? '');

$city =
trim($_POST['city'] ?? '');

$mode =
$_POST['mode']
??
'Offline';

$event_date =
trim($_POST['event_date'] ?? '');

$event_end_date =
trim($_POST['event_end_date'] ?? '');

$registration_deadline =
trim($_POST['registration_deadline'] ?? '');

$reason =
trim($_POST['reason'] ?? '');

$registration_fee =
(float)($_POST['registration_fee'] ?? 0);

/* ===========================================================
   TEAM DETAILS
=========================================================== */

$team_type_input =
$_POST['team_type']
??
'Individual';

$team_type =
($team_type_input == "Group")
?
"Team"
:
"Individual";

$team_name =
trim($_POST['team_name'] ?? '');

$team_size =
(int)($_POST['team_size'] ?? 1);

/* ===========================================================
   VALIDATION
=========================================================== */

if ($event_type_id <= 0) {

    echo json_encode([
        "status"=>"error",
        "message"=>"Please select event type"
    ]);

    exit;
}

$eventTypeCheck = $conn->prepare(
    "SELECT event_type_id FROM event_types WHERE event_type_id = ?"
);
$eventTypeCheck->bind_param("i", $event_type_id);
$eventTypeCheck->execute();

if ($eventTypeCheck->get_result()->num_rows === 0) {

    echo json_encode([
        "status"=>"error",
        "message"=>"Invalid event type selected"
    ]);

    exit;
}

$eventTypeCheck->close();

if ($event_name == "") {

    echo json_encode([
        "status"=>"error",
        "message"=>"Please enter event name"
    ]);

    exit;
}


if ($event_date == "" || $event_end_date == "") {

    echo json_encode([
        "status"=>"error",
        "message"=>"Please select event dates"
    ]);

    exit;
}

$today = date('Y-m-d');

if (strtotime($event_date) < strtotime($today)) {

    echo json_encode([
        "status"=>"error",
        "message"=>"Event date cannot be in the past"
    ]);

    exit;
}

if ($venue == "") {

    echo json_encode([
        "status"=>"error",
        "message"=>"Please enter venue"
    ]);

    exit;
}

if (strtotime($event_end_date) < strtotime($event_date)) {

    echo json_encode([
        "status"=>"error",
        "message"=>"End date cannot be before start date"
    ]);

    exit;
}

if ($registration_deadline != "" && strtotime($registration_deadline) >= strtotime($event_date)) {

    echo json_encode([
        "status" => "error",
        "message" => "Registration deadline must be before the event start date."
    ]);

    exit;
}

if ($registration_deadline != "" && strtotime($registration_deadline) < strtotime($today)) {

    echo json_encode([
        "status" => "error",
        "message" => "Registration deadline cannot be in the past"
    ]);

    exit;
}

if (
    !in_array(
        $mode,
        ['Offline','Online','Hybrid'],
        true
    )
){
    $mode = "Offline";
}

if(empty($_POST['declaration'])){

    echo json_encode([
        "status"=>"error",
        "message"=>"Please accept declaration"
    ]);

    exit;
}

/* ===========================================================
   TEAM MEMBERS
=========================================================== */

$members = [];

if($team_type=="Team"){

    if($team_name==""){

        echo json_encode([
            "status"=>"error",
            "message"=>"Please enter team name"
        ]);

        exit;
    }

    if($team_size < 2){

        echo json_encode([
            "status"=>"error",
            "message"=>"Minimum 2 members required"
        ]);

        exit;
    }

    $memberUsns =
    $_POST['member_usn'] ?? [];

    foreach($memberUsns as $memberUsn){

        $memberUsn = trim($memberUsn);

        if($memberUsn == ""){
            continue;
        }

        if(strcasecmp($memberUsn, $leader_usn) == 0){
            echo json_encode([
                "status"=>"error",
                "message"=>$memberUsn." is the Team Leader and is already included. Please enter a different team member."
            ]);
            exit;
        }

        foreach($members as $existingUsn){
            if(strcasecmp($existingUsn, $memberUsn) == 0){
                echo json_encode([
                    "status"=>"error",
                    "message"=>$memberUsn." has been entered more than once. Please enter each team member only once."
                ]);
                exit;
            }
        }

        $members[] = $memberUsn;
    }

    if(count($members) < 1){

        echo json_encode([
            "status"=>"error",
            "message"=>"Please add at least one valid team member USN"
        ]);

        exit;
    }

    $placeholders = implode(',', array_fill(0, count($members), '?'));
    $types = str_repeat('s', count($members));

    $memberCheck = $conn->prepare(
        "SELECT usn FROM students WHERE usn IN ($placeholders)"
    );
    $memberCheck->bind_param($types, ...$members);
    $memberCheck->execute();

    $foundUsns = [];
    $checkResult = $memberCheck->get_result();
    while($row = $checkResult->fetch_assoc()){
        $foundUsns[] = $row['usn'];
    }
    $memberCheck->close();

    $invalidUsns = array_diff($members, $foundUsns);

    if(!empty($invalidUsns)){

        echo json_encode([
            "status"=>"error",
            "message"=>"Unknown USN(s): " . implode(', ', $invalidUsns)
        ]);

        exit;
    }

    $team_size = count($members) + 1; // + leader

}else{

    $team_name="";

    $team_size=1;
}

/* ===========================================================
   DUPLICATE CHECK: a student can be in only one active
   request per event
=========================================================== */

// Same event = same name + same date (spaces, case and symbols are
// ignored; the venue is NOT part of it). Stored as a fixed-length hash.
$event_key = md5(
    preg_replace('/[^a-z0-9]/', '', strtolower($event_name)) . '|' .
    $event_date
);

$participants = array_merge([$leader_usn], $members);

$conflict = findEventConflict($conn, $event_name, $event_date, $participants, $leader_usn);

if ($conflict) {

    $who = (strcasecmp($conflict['usn'], $leader_usn) === 0) ? "You are" : $conflict['usn'] . " is";

    echo json_encode([
        "status"  => "error",
        "message" => $who . " already part of a request for this event (team leader "
                     . $conflict['leader_usn'] . "). A student can be in only one request per event."
    ]);

    exit;
}

/* ===========================================================
   FILE UPLOADS
=========================================================== */

$error = null;

$invitation_file =
handleUpload(
    "invitation_file",
    true,
    $error
);

if($error){

    echo json_encode([
        "status"=>"error",
        "message"=>$error
    ]);

    exit;
}

$supporting_file =
handleUpload(
    "supporting_file",
    false,
    $error
);

if($error){

    echo json_encode([
        "status"=>"error",
        "message"=>$error
    ]);

    exit;
}

/* ===========================================================
   INSERT PERMISSION REQUEST
=========================================================== */

$insert = $conn->prepare(

"INSERT INTO permission_requests
(
    leader_usn,
    leader_department,
    leader_semester,
    event_type_id,
    event_name,
    event_date,
    event_end_date,
    registration_deadline,
    venue,
    city,
    mode,
    reason,
    registration_fee,
    team_type,
    team_size,
    team_name,
    academic_year,
    event_key,
    hod_status
)
VALUES
(
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending'
)"

);

if(!$insert){

    echo json_encode([
        "status"=>"error",
        "message"=>$conn->error
    ]);

    exit;
}

$registration_deadline = $registration_deadline === '' ? null : $registration_deadline;

$insert->bind_param(

"sssissssssssdsisss",

$leader_usn,
$leader_department,
$leader_semester,
$event_type_id,
$event_name,
$event_date,
$event_end_date,
$registration_deadline,
$venue,
$city,
$mode,
$reason,
$registration_fee,
$team_type,
$team_size,
$team_name,
$academic_year,
$event_key

);

$conn->begin_transaction();

if($insert->execute()){

    $permission_id = $insert->insert_id;

    /* ==========================================
       STORE UPLOADED FILES IN ATTACHMENTS
    ========================================== */

    $attachStmt = $conn->prepare(
        "INSERT INTO attachments
        (owner_type, owner_id, file_role, filename, mime_type, file_data)
        VALUES ('permission_request', ?, ?, ?, ?, ?)"
    );

    if ($invitation_file) {
        $role = 'invitation_file';
        $attachStmt->bind_param(
            "issss",
            $permission_id,
            $role,
            $invitation_file['name'],
            $invitation_file['mime'],
            $invitation_file['data']
        );
        $attachStmt->execute();
    }

    if ($supporting_file) {
        $role = 'supporting_file';
        $attachStmt->bind_param(
            "issss",
            $permission_id,
            $role,
            $supporting_file['name'],
            $supporting_file['mime'],
            $supporting_file['data']
        );
        $attachStmt->execute();
    }

    $attachStmt->close();

    /* ==========================================
       STORE TEAM MEMBERS
    ========================================== */

    if(
        $team_type == "Team"
        &&
        !empty($members)
    ){

        $memberStmt = $conn->prepare(

            "INSERT INTO permission_members
            (
                permission_id,
                usn
            )
            VALUES
            (
                ?,?
            )"

        );

        foreach($members as $memberUsn){

            $memberStmt->bind_param(

                "is",

                $permission_id,
                $memberUsn

            );

            $memberStmt->execute();

        }

        $memberStmt->close();
    }

    /* ==========================================
       ONE APPROVAL ROW PER DEPARTMENT
    ========================================== */

    $depts = [$leader_department];

    if (!empty($members)) {

        $dph = implode(',', array_fill(0, count($members), '?'));

        $dStmt = $conn->prepare(
            "SELECT DISTINCT department FROM students WHERE usn IN ($dph)"
        );
        $dStmt->bind_param(str_repeat('s', count($members)), ...$members);
        $dStmt->execute();
        $dRes = $dStmt->get_result();

        while ($d = $dRes->fetch_assoc()) {
            $depts[] = $d['department'];
        }

        $dStmt->close();

        $depts = array_values(array_unique($depts));
    }

    $apStmt = $conn->prepare(
        "INSERT INTO permission_department_approvals (permission_id, department)
         VALUES (?, ?)"
    );

    foreach ($depts as $dept) {

        $apStmt->bind_param("is", $permission_id, $dept);

        if (!$apStmt->execute()) {
            $conn->rollback();
            echo json_encode([
                "status"  => "error",
                "message" => "Could not save the request. Please try again."
            ]);
            exit;
        }
    }

    $apStmt->close();

    /* ==========================================
       CLAIM THE EVENT FOR EVERY PARTICIPANT
       (database-level guard, handles simultaneous submits)
    ========================================== */

    $epStmt = $conn->prepare(
        "INSERT INTO event_participants (event_key, usn, permission_id)
         VALUES (?, ?, ?)"
    );

    foreach ($participants as $pUsn) {

        $epStmt->bind_param("ssi", $event_key, $pUsn, $permission_id);

        if (!$epStmt->execute()) {

            $isDuplicate = ($epStmt->errno == 1062);
            $conn->rollback();

            echo json_encode([
                "status"  => "error",
                "message" => $isDuplicate
                    ? "One of the students is already part of a request for this event."
                    : "Could not save the request. Please try again."
            ]);

            exit;
        }
    }

    $epStmt->close();

    $conn->commit();

    echo json_encode([

        "status"=>"success",

        "message"=>"Permission Request Submitted Successfully. Waiting for HOD Approval."

    ]);

}else{

echo json_encode([

"status"=>"error",

"message"=>$insert->error,

"sql_error"=>$conn->error,

"errno"=>$conn->errno

]);

}

$insert->close();

$conn->close();

exit;