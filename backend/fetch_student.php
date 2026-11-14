<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['student', 'admin']);

header("Content-Type: application/json");

$usn = $_GET['usn'] ?? '';

if ($usn == "") {
    echo json_encode([
        "status" => "error",
        "message" => "USN is required"
    ]);
    exit;
}

$stmt = $conn->prepare("
SELECT
    usn,
    name,
    department,
    semester
FROM students
WHERE usn=?
");

$stmt->bind_param("s",$usn);

$stmt->execute();

$result=$stmt->get_result();

if($result->num_rows>0){

    echo json_encode([
        "status"=>"success",
        "data"=>$result->fetch_assoc()
    ]);

}else{

    echo json_encode([
        "status"=>"error",
        "message"=>"Student not found"
    ]);

}

?>