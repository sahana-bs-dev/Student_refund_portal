<?php

require_once "db.php";
require_once "auth_check.php";

require_login();

header("Content-Type: application/json");

$sql = "SELECT event_type_id, event_type_name
        FROM event_types
        WHERE status='Active'
        ORDER BY event_type_name";

$result = $conn->query($sql);

$types = [];

while($row = $result->fetch_assoc()){
    $types[] = $row;
}

echo json_encode([
    "status"=>"success",
    "data"=>$types
]);

?>