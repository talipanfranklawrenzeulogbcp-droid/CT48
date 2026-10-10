<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    require_once __DIR__.'/includes/config.php';
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    $pdo->query('SELECT 1')->fetchColumn();

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
        'database' => 'ok',
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('CT4 health check failed: '.get_class($e).': '.$e->getMessage());
    http_response_code(503);
    echo json_encode([
        'status' => 'degraded',
        'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
        'database' => 'unavailable',
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_SLASHES);
}
