<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login($roles = []) {
    header("Content-Type: application/json");

    if (empty($_SESSION['role']) || empty($_SESSION['login_id'])) {
        http_response_code(401);
        echo json_encode([
            "status" => "error",
            "message" => "Not logged in"
        ]);
        exit;
    }

    if (!empty($roles) && !in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode([
            "status" => "error",
            "message" => "Not authorized for this action"
        ]);
        exit;
    }
}

function current_login_id() {
    return $_SESSION['login_id'] ?? null;
}

function current_role() {
    return $_SESSION['role'] ?? null;
}

function current_department() {
    return $_SESSION['department'] ?? null;
}


function current_name() {
    return $_SESSION['name'] ?? null;
}

?>
