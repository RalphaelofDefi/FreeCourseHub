<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $value = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', static function (array $m): string {
            return $_ENV[$m[1]] ?? (string) getenv($m[1]) ?: '';
        }, $value) ?? $value;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

load_env_file(BASE_PATH . '/.env');

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key, null);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

define('APP_NAME', (string) env('APP_NAME', 'FreeCourse Hub'));
define('APP_ENV', (string) env('APP_ENV', 'production'));
define('APP_DEBUG', env_bool('APP_DEBUG', false));
define('APP_URL', rtrim((string) env('APP_URL', $scheme . '://' . $host), '/'));
define('APP_TIMEZONE', (string) env('APP_TIMEZONE', 'Africa/Lagos'));
define('PAYSTACK_PUBLIC_KEY', (string) env('PAYSTACK_PUBLIC_KEY', ''));
define('PAYSTACK_SECRET_KEY', (string) env('PAYSTACK_SECRET_KEY', ''));
define('PAYSTACK_CALLBACK_URL', (string) env('PAYSTACK_CALLBACK_URL', APP_URL . '/payment-callback.php'));
define('CRON_SECRET', (string) env('CRON_SECRET', ''));
define('COLLECTION_MAX_PAGES', max(1, (int) env('COLLECTION_MAX_PAGES', 3)));
define('HTTP_TIMEOUT', max(5, (int) env('HTTP_TIMEOUT', 25)));

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

spl_autoload_register(static function (string $class): void {
    $class = ltrim(str_replace('FreeCourseHub\\', '', $class), '\\');
    $directories = [
        BASE_PATH . '/includes/',
        BASE_PATH . '/modules/',
        BASE_PATH . '/modules/sources/',
        BASE_PATH . '/modules/collection/',
        BASE_PATH . '/modules/verification/',
        BASE_PATH . '/modules/payments/',
    ];
    foreach ($directories as $directory) {
        $file = $directory . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

function app_log(string $channel, string $message, array $context = []): void
{
    $directory = BASE_PATH . '/storage/logs';
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $channel . '] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents($directory . '/app.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function storage_path(string $path = ''): string
{
    return BASE_PATH . '/storage' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
