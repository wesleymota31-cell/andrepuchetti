<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

$externalConfig = dirname(__DIR__, 3) . '/agenda_config.php';

if (is_file($externalConfig)) {
    require $externalConfig;
} else {
    $host = getenv('AGENDA_DB_HOST') ?: 'localhost';
    $dbname = getenv('AGENDA_DB_NAME') ?: '';
    $username = getenv('AGENDA_DB_USER') ?: '';
    $password = getenv('AGENDA_DB_PASS') ?: '';
}

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    error_log('Erro na conexão MySQL: ' . $conn->connect_error);
    http_response_code(500);
    die('Erro interno. Tente novamente em instantes.');
}

$conn->set_charset('utf8mb4');
