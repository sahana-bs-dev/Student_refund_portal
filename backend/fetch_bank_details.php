<?php

require_once "db.php";
require_once "crypto.php";
require_once "auth_check.php";

require_login(['student']);

header("Content-Type: application/json");

$usn = current_login_id();

$stmt = $conn->prepare(
    "SELECT
        account_holder_name AS account_holder,
        account_number,
        account_iv,
        ifsc_code,
        ifsc_iv,
        bank_name,
        bank_iv
     FROM account_details
     WHERE usn = ?"
);

$stmt->bind_param("s", $usn);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    echo json_encode([
        "status" => "success",
        "data" => null
    ]);

    exit;
}

$row = $result->fetch_assoc();

$row['account_number'] = decryptData($row['account_number'], $row['account_iv']);
$row['ifsc_code'] = decryptData($row['ifsc_code'], $row['ifsc_iv']);
$row['bank_name'] = decryptData($row['bank_name'], $row['bank_iv']);

unset($row['account_iv'], $row['ifsc_iv'], $row['bank_iv']);

echo json_encode([
    "status" => "success",
    "data" => $row
]);

?>