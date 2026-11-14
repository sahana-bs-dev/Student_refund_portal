<?php

require_once "auth_check.php";

require_login(); 

echo json_encode([
    "status" => "success",
    "login_id" => current_login_id(),
    "role" => current_role(),
    "department" => current_department(),
    "name" => $_SESSION['name'] ?? current_login_id()
]);
