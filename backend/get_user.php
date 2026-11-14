<?php

require_once "db.php";
require_once "auth_check.php";

require_login();

header("Content-Type: application/json");

if (isset($_GET['login_id'])) {

    $login_id = $_GET['login_id'];

    if (!in_array(current_role(), ['admin', 'hod'], true)
        && $login_id !== current_login_id()) {

        http_response_code(403);

        echo json_encode([
            "status" => "error",
            "message" => "Not authorized"
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
        WHERE usn = ?
    ");

    $stmt->bind_param("s", $login_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $student = $result->fetch_assoc();

        echo json_encode([
            "status" => "success",
            "data" => [
                "usn" => $student["usn"],
                "name" => $student["name"],
                "department" => $student["department"],
                "semester" => $student["semester"]
            ]
        ]);

    } else {

        echo json_encode([
            "status" => "error",
            "message" => "Student not found"
        ]);
    }

} else {

    echo json_encode([
        "status" => "error",
        "message" => "Login ID required"
    ]);
}

?>