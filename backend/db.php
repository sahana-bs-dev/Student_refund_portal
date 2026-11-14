<?php
require_once __DIR__ . "/config.php";

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed"
    ]);
    exit;
}

$conn->set_charset("utf8mb4");
?>
