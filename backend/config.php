<?php

function load_env($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}
load_env(__DIR__ . '/.env');

// ---- Database ----
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'refund_portal');
define('DB_PORT', getenv('DB_PORT') ?: 3307);

// ---- Encryption ----
define('APP_SECRET_KEY', getenv('APP_SECRET_KEY') ?: '');

// ---- File uploads ----
define('MAX_UPLOAD_BYTES', 1 * 1024 * 1024); // 1 MB
define('ALLOWED_UPLOAD_EXT', ['jpg', 'jpeg', 'png', 'pdf']);
define('ALLOWED_UPLOAD_MIME', [
    'image/jpeg',
    'image/png',
    'application/pdf',
]);

define('MAX_SEMESTER', 8);