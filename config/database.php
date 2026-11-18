<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) env('DB_HOST', '127.0.0.1');
    $port = (string) env('DB_PORT', '3306');
    $database = (string) env('DB_DATABASE', 'freecourse_hub');
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);

    return $pdo;
}

function db_without_name(): PDO
{
    $host = (string) env('DB_HOST', '127.0.0.1');
    $port = (string) env('DB_PORT', '3306');
    $username = (string) env('DB_USERNAME', 'root');
    $password = (string) env('DB_PASSWORD', '');
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function db_installed(): bool
{
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
