<?php
require_once __DIR__ . "/config.php";

function encryptData($data) {
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, "AES-256-CBC", APP_SECRET_KEY, 0, $iv);

    return [
        "encrypted" => $encrypted,
        "iv" => base64_encode($iv)
    ];
}

function decryptData($encrypted, $iv) {
    if (empty($encrypted) || empty($iv)) return "";
    return openssl_decrypt($encrypted, "AES-256-CBC", APP_SECRET_KEY, 0, base64_decode($iv));
}
?>
