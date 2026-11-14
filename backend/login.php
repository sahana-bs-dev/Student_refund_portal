<?php

require_once "db.php";
require_once "auth_check.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid Request"
    ]);
    exit;
}

$login_id = trim($_POST['login_id'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($login_id == "" || $password == "") {
    echo json_encode([
        "status" => "error",
        "message" => "Login ID and Password are required"
    ]);
    exit;
}

//
// STUDENT LOGIN
//
$stmt = $conn->prepare("SELECT * FROM students WHERE usn=? AND status='active'");
$stmt->bind_param("s", $login_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $student = $result->fetch_assoc();

    if ($student['password'] == $password) {

        $_SESSION['login_id'] = $student['usn'];
        $_SESSION['role'] = "student";
        $_SESSION['department'] = $student['department'];
        $_SESSION['name'] = $student['name'];
        $_SESSION['semester'] = $student['semester'];

        echo json_encode([
            "status" => "success",
            "role" => "student",
            "login_id" => $student['usn'],
            "department" => $student['department'],
            "name" => $student['name']
        ]);

        exit;
    }
}

//
// ADMIN LOGIN
//
$stmt = $conn->prepare("SELECT * FROM admin WHERE username=?");
$stmt->bind_param("s", $login_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $admin = $result->fetch_assoc();

    if ($admin['password'] == $password) {

        $_SESSION['login_id'] = $admin['username'];
        $_SESSION['role'] = "admin";

        echo json_encode([
            "status" => "success",
            "role" => "admin",
            "login_id" => $admin['username']
        ]);

        exit;
    }
}

//
// HOD LOGIN
//
$stmt = $conn->prepare("SELECT * FROM hods WHERE username=?");
$stmt->bind_param("s", $login_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $hod = $result->fetch_assoc();

    if ($hod['password'] == $password) {

        $_SESSION['login_id'] = $hod['username'];
        $_SESSION['role'] = "hod";
        $_SESSION['department'] = $hod['department'];
        $_SESSION['name'] = $hod['name'] ?? $hod['username'];

        echo json_encode([
            "status" => "success",
            "role" => "hod",
            "login_id" => $hod['username'],
            "department" => $hod['department'],
            "name" => $hod['name'] ?? $hod['username']
        ]);

        exit;
    }
}

echo json_encode([
    "status" => "error",
    "message" => "Invalid Login ID or Password"
]);

?>