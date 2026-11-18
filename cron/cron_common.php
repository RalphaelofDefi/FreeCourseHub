<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
        http_response_code(405);
        exit('Method not allowed');
    }
    $key = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
    if (!verify_webhook_token($key)) {
        http_response_code(403);
        app_log('cron', 'Rejected unauthorized cron request', ['ip' => client_ip()]);
        exit('Forbidden');
    }
}

@set_time_limit(300);
ignore_user_abort(true);

function cron_option(string $name, ?string $default = null): ?string
{
    if (PHP_SAPI === 'cli') {
        global $argv;
        foreach ((array) ($argv ?? []) as $argument) {
            if ($argument === '--' . $name) {
                return '1';
            }
            if (str_starts_with((string) $argument, '--' . $name . '=')) {
                return substr((string) $argument, strlen($name) + 3);
            }
        }
        return $default;
    }
    $value = $_GET[$name] ?? null;
    return is_string($value) ? $value : $default;
}

function cron_respond(array $payload, int $status = 200): void
{
    app_log('cron', 'Job finished', $payload);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit($status >= 400 ? 1 : 0);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function cron_is_due(string $lastRunSetting, int $intervalHours): bool
{
    $lastRun = trim((string) setting($lastRunSetting, ''));
    if ($lastRun === '') {
        return true;
    }
    $timestamp = strtotime($lastRun);
    return $timestamp === false || $timestamp < time() - max(1, $intervalHours) * 3600;
}
