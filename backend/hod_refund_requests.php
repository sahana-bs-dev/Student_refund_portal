<?php

require_once "db.php";
require_once "academic_lib.php";
require_once "auth_check.php";
require_once "refund_lib.php";

require_login(['hod']);

header("Content-Type: application/json");

/* The HOD's ACTIVE refund shares (this department's part of each refund) */

echo json_encode([
    "status" => "success",
    "data" => fetchHodRefundShares(
        $conn,
        current_department(),
        getCurrentAcademicYear(),
        0
    )
]);

?>