<?php
declare(strict_types=1);

function config(string $key): mixed {
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config.php';
        if (!is_file($path)) throw new RuntimeException('config.php missing; copy config.example.php');
        $config = require $path;
    }
    if (!array_key_exists($key, $config)) throw new RuntimeException("Missing config key: $key");
    return $config[$key];
}

/** Like config(), but falls back to a default when an older config.php lacks the key. */
function config_or(string $key, mixed $default): mixed {
    try { return config($key); } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'Missing config key')) return $default;
        throw $e;
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', config('db_host'), config('db_name')),
            config('db_user'),
            config('db_pass'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function is_duplicate_key(PDOException $e): bool {
    return ($e->errorInfo[1] ?? null) === 1062;
}
