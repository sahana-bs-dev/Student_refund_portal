<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "crypto.php";
require_once "auth_check.php";

require_login(['student']);

// Make mysqli return false on errors (same behaviour on every PHP version)
mysqli_report(MYSQLI_REPORT_OFF);

header("Content-Type: application/json");

/* ===========================================================
   FILE UPLOAD FUNCTION
=========================================================== */

function handleUpload($fieldName, &$error)
{
    if (
        !isset($_FILES[$fieldName]) ||
        $_FILES[$fieldName]['error'] != UPLOAD_ERR_OK
    ) {
        $error = "Please upload " . $fieldName;
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
        bin2hex(random_bytes(16)) .
        "." .
        $extension;

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
        "status"=>"error",
        "message"=>"Invalid Request"
    ]);

    exit;
}

/* ===========================================================
   STUDENT DETAILS
=========================================================== */

$usn = current_login_id();

$name =
$_SESSION['name']
??
"";

$studentStmt = $conn->prepare(
    "SELECT department, semester FROM students WHERE usn = ?"
);
$studentStmt->bind_param("s", $usn);
$studentStmt->execute();
$studentRow = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$studentRow) {

    echo json_encode([
        "status"=>"error",
        "message"=>"Student record not found"
    ]);

    exit;
}

$department = $studentRow['department'];
$semester = (string)$studentRow['semester'];

$academic_year = getCurrentAcademicYear();

$refund_type =
$_POST['refund_type']
??
'Individual';

$permission_request_id =
(int)($_POST['permission_request_id'] ?? 0);

$team_name =
trim($_POST['team_name'] ?? '');

$number_of_students =
(int)($_POST['number_of_students'] ?? 1);

$amount =
(float)($_POST['amount'] ?? 0);

/* ===========================================================
   VALIDATE PERMISSION REQUEST LINK
=========================================================== */

if ($permission_request_id <= 0) {

    echo json_encode([
        "status"=>"error",
        "message"=>"You cannot apply for a refund because no approved event permission was found."
    ]);

    exit;
}

/* Everything from here is one transaction. The permission row is
   locked so two refund submissions for the same event cannot both pass
   the duplicate check at the same moment. */
$conn->begin_transaction();

/* Only the team leader can apply, and only when ALL departments have
   approved the permission (overall status = Approved). */
$permStmt = $conn->prepare(
    "SELECT pr.id, pr.event_name, pr.team_type, pr.team_name,
            et.event_type_name AS event_type
     FROM permission_requests pr
     LEFT JOIN event_types et ON et.event_type_id = pr.event_type_id
     WHERE pr.id = ?
       AND pr.leader_usn = ?
       AND pr.hod_status = 'Approved'
     FOR UPDATE"
);
$permStmt->bind_param("is", $permission_request_id, $usn);
$permStmt->execute();
$permRow = $permStmt->get_result()->fetch_assoc();
$permStmt->close();

if (!$permRow) {

    echo json_encode([
        "status"=>"error",
        "message"=>"You cannot apply for a refund because no approved event permission was found."
    ]);

    exit;
}

$event_name = $permRow['event_name'];

/* ===========================================================
   PREVENT DUPLICATE REFUNDS ON THE SAME APPROVED PERMISSION
=========================================================== */

$dupStmt = $conn->prepare(
    "SELECT id FROM refund_requests
     WHERE permission_request_id = ?
       AND admin_status != 'Rejected'
       AND hod_status != 'Rejected'"
);
$dupStmt->bind_param("i", $permission_request_id);
$dupStmt->execute();
$dupRow = $dupStmt->get_result()->fetch_assoc();
$dupStmt->close();

if ($dupRow) {

    echo json_encode([
        "status"=>"error",
        "message"=>"A refund request has already been submitted for this event."
    ]);

    exit;
}

if($amount<=0){

    echo json_encode([
        "status"=>"error",
        "message"=>"Invalid refund amount"
    ]);

    exit;
}

$refund_reason = $event_type_name = $permRow['event_type'] . ' - ' . $permRow['event_name'];

/* ===========================================================
   TEAM COMES FROM THE APPROVED PERMISSION (NOT FROM THE FORM)
   The leader cannot add or remove students here.
=========================================================== */

$member_usns = "";
$members = [];

if ($permRow['team_type'] === 'Team') {

    $refund_type = "Group";
    $team_name = (string)$permRow['team_name'];

    $memStmt = $conn->prepare(
        "SELECT usn FROM permission_members WHERE permission_id = ?"
    );
    $memStmt->bind_param("i", $permission_request_id);
    $memStmt->execute();
    $memRes = $memStmt->get_result();

    while ($m = $memRes->fetch_assoc()) {
        $members[] = $m['usn'];
    }

    $memStmt->close();

    if (count($members) < 1) {

        echo json_encode([
            "status"=>"error",
            "message"=>"No team members found for this permission."
        ]);

        exit;
    }

    $number_of_students = count($members) + 1; // + leader

    $per_student_amount =
    round(
        $amount/$number_of_students,
        2
    );

    $member_usns =
    implode(",", $members);

} else {

    $refund_type = "Individual";
    $team_name = "";
    $number_of_students = 1;
    $per_student_amount = $amount;
}

/* ===========================================================
   BANK DETAILS
=========================================================== */

$account_holder =
trim($_POST['account_holder'] ?? '');

$account_number =
trim($_POST['account_number'] ?? '');

$ifsc_code =
strtoupper(trim($_POST['ifsc_code'] ?? ''));

$bank_name =
trim($_POST['bank_name'] ?? '');

if($bank_name=="Other"){

    $bank_name=
    trim($_POST['other_bank_name'] ?? '');
}

if(

$account_holder=="" ||

$account_number=="" ||

$ifsc_code=="" ||

$bank_name==""

){

    echo json_encode([

        "status"=>"error",

        "message"=>"All bank details are required"

    ]);

    exit;
}


/* ===========================================================
   BANK DETAILS FORMAT VALIDATION
=========================================================== */

if(!preg_match('/^[A-Za-z ]{3,50}$/', $account_holder)){

    echo json_encode([
        "status"=>"error",
        "message"=>"Account Holder Name should contain letters and spaces only."
    ]);

    exit;
}

if(!preg_match('/^[0-9]{9,18}$/', $account_number)){

    echo json_encode([
        "status"=>"error",
        "message"=>"Account Number must be numeric (9 to 18 digits)."
    ]);

    exit;
}

if(!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc_code)){

    echo json_encode([
        "status"=>"error",
        "message"=>"Enter a valid IFSC Code (e.g., CNRB0001234)."
    ]);

    exit;
}


/* ===========================================================
   ENCRYPT ACCOUNT NUMBER & IFSC CODE
=========================================================== */

$encrypted =
encryptData($account_number);

$account_number_encrypted =
$encrypted['encrypted'];

$account_iv =
$encrypted['iv'];

$encryptedIfsc =
encryptData($ifsc_code);

$ifsc_code_encrypted =
$encryptedIfsc['encrypted'];

$ifsc_iv =
$encryptedIfsc['iv'];

$encryptedBank =
encryptData($bank_name);

$bank_name_encrypted =
$encryptedBank['encrypted'];

$bank_iv =
$encryptedBank['iv'];

/* ===========================================================
   FILE UPLOADS
=========================================================== */

$error=null;

$payment_proof=
handleUpload(
"payment_proof",
$error
);

if($error){

    echo json_encode([
        "status"=>"error",
        "message"=>$error
    ]);

    exit;
}

if (
    isset($_FILES['participation_proof']) &&
    $_FILES['participation_proof']['error'] != UPLOAD_ERR_NO_FILE
) {

    $participation_proof=
handleUpload(
"participation_proof",
$error
);

    if($error){

        echo json_encode([
            "status"=>"error",
            "message"=>$error
        ]);

        exit;
    }

} else {

    $participation_proof = null;

}

$bank_proof=
handleUpload(
"bank_proof",
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
   SAVE / UPDATE BANK DETAILS
=========================================================== */

$check = $conn->prepare(
    "SELECT usn FROM account_details WHERE usn=?"
);

$check->bind_param("s", $usn);
$check->execute();

$result = $check->get_result();

if($result->num_rows==0){

   $insertAccount = $conn->prepare(

        "INSERT INTO account_details
(
    usn,
    account_holder_name,
    bank_name,
    bank_iv,
    account_number,
    ifsc_code,
    account_iv,
    ifsc_iv
)
VALUES
(
    ?,?,?,?,?,?,?,?
)"

    );

    $insertAccount->bind_param(
    "ssssssss",
    $usn,
    $account_holder,
    $bank_name_encrypted,
    $bank_iv,
    $account_number_encrypted,
    $ifsc_code_encrypted,
    $account_iv,
    $ifsc_iv
);


    if(!$insertAccount->execute()){

        echo json_encode([

            "status"=>"error",
            "message"=>"Unable to save bank details."

        ]);

        exit;
    }

}
else{

    $updateAccount = $conn->prepare(

        "UPDATE account_details
SET

account_holder_name=?,
bank_name=?,
bank_iv=?,
account_number=?,
ifsc_code=?,
account_iv=?,
ifsc_iv=?

WHERE usn=?"

    );

    $updateAccount->bind_param(
    "ssssssss",
    $account_holder,
    $bank_name_encrypted,
    $bank_iv,
    $account_number_encrypted,
    $ifsc_code_encrypted,
    $account_iv,
    $ifsc_iv,
    $usn
);

    if(!$updateAccount->execute()){

        echo json_encode([

            "status"=>"error",
            "message"=>"Unable to update bank details."

        ]);

        exit;
    }

}

/* ===========================================================
   INSERT REFUND REQUEST
=========================================================== */

$insert = $conn->prepare(

"INSERT INTO refund_requests
(

permission_request_id,
usn,
name,
refund_reason,
amount,
department,
semester,
refund_type,
event_name,
team_name,
number_of_students,
per_student_amount,
academic_year

)

VALUES
(

?,?,?,?,?,
?,?,?,?,?,
?,?,?

)"

);

$insert->bind_param(
"isssdsssssids",

$permission_request_id,
$usn,
$name,
$refund_reason,
$amount,
$department,
$semester,
$refund_type,
$event_name,
$team_name,
$number_of_students,
$per_student_amount,
$academic_year

);

if($insert->execute()){

    $refund_id = $insert->insert_id;

    /* ==========================================
       STORE UPLOADED FILES IN ATTACHMENTS
    ========================================== */

    $attachStmt = $conn->prepare(
        "INSERT INTO attachments
        (owner_type, owner_id, file_role, filename, mime_type, file_data)
        VALUES ('refund_request', ?, ?, ?, ?, ?)"
    );

    $role = 'payment_proof';
    $attachStmt->bind_param(
        "issss",
        $refund_id,
        $role,
        $payment_proof['name'],
        $payment_proof['mime'],
        $payment_proof['data']
    );
    $attachStmt->execute();

    if ($participation_proof) {
        $role = 'participation_proof';
        $attachStmt->bind_param(
            "issss",
            $refund_id,
            $role,
            $participation_proof['name'],
            $participation_proof['mime'],
            $participation_proof['data']
        );
        $attachStmt->execute();
    }

    $role = 'bank_proof';
    $attachStmt->bind_param(
        "issss",
        $refund_id,
        $role,
        $bank_proof['name'],
        $bank_proof['mime'],
        $bank_proof['data']
    );
    $attachStmt->execute();

    $attachStmt->close();

    /* =======================================================
       SAVE GROUP MEMBERS INTO refund_members TABLE
    ======================================================= */

    if($refund_type !== "Individual" && !empty($members)){

        $memberStmt = $conn->prepare(

            "INSERT INTO refund_members
            (refund_id, usn)
            VALUES (?,?)"

        );

        foreach($members as $memberUsn){

            $memberStmt->bind_param(

                "is",

                $refund_id,
                $memberUsn

            );

            $memberStmt->execute();
        }
    }

    /* =======================================================
       ONE REFUND SHARE PER DEPARTMENT
       (each department's HOD approves its own share)
    ======================================================= */

    $allUsns = array_merge([$usn], $members);
    $sph = implode(',', array_fill(0, count($allUsns), '?'));

    $deptStmt = $conn->prepare(
        "SELECT usn, department FROM students WHERE usn IN ($sph)"
    );
    $deptStmt->bind_param(str_repeat('s', count($allUsns)), ...$allUsns);
    $deptStmt->execute();
    $deptRes = $deptStmt->get_result();

    $deptCount = [];

    while ($d = $deptRes->fetch_assoc()) {
        $deptCount[$d['department']] = ($deptCount[$d['department']] ?? 0) + 1;
    }

    $deptStmt->close();

    $shareStmt = $conn->prepare(
        "INSERT INTO refund_shares
         (refund_id, department, number_of_students, share_amount)
         VALUES (?, ?, ?, ?)"
    );

    $remaining = round($amount, 2);
    $deptKeys = array_keys($deptCount);
    $lastIndex = count($deptKeys) - 1;

    foreach ($deptKeys as $i => $dept) {

        $cnt = $deptCount[$dept];

        if ($i === $lastIndex) {
            // last share takes the remainder so shares add up exactly
            $shareAmount = $remaining;
        } else {
            $shareAmount = round($per_student_amount * $cnt, 2);
            $remaining = round($remaining - $shareAmount, 2);
        }

        $shareStmt->bind_param("isid", $refund_id, $dept, $cnt, $shareAmount);

        if (!$shareStmt->execute()) {

            $conn->rollback();

            echo json_encode([
                "status"=>"error",
                "message"=>"Could not save the refund request. Please try again."
            ]);

            exit;
        }
    }

    $shareStmt->close();

    $conn->commit();

    echo json_encode([

        "status"=>"success",

        "message"=>"Refund request submitted successfully."

    ]);

}
else{

    $conn->rollback();

    echo json_encode([

        "status"=>"error",

        "message"=>$insert->error

    ]);

}

exit;