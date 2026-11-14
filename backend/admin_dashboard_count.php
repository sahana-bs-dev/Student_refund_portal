<?php

require_once "db.php";
require_once "auth_check.php";

require_login(['admin']);

header("Content-Type: application/json");


$stmt = $conn->prepare(

"
SELECT

COALESCE(
SUM(
admin_archived = 0
),
0
) AS total,


COALESCE(
SUM(
admin_status = 'Paid'
),
0
) AS paid,


COALESCE(
SUM(
admin_status = 'Rejected'
),
0
) AS rejected,


COALESCE(
SUM(
admin_status IS NULL
OR admin_status = ''
OR admin_status = 'Pending'
),
0
) AS pending


FROM refund_requests


WHERE hod_status = 'Approved'

"

);


$stmt->execute();


$result = $stmt->get_result();


$data = $result->fetch_assoc();



echo json_encode([

"status"=>"success",

"data"=>$data

]);


?>