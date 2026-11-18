<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('FCHSESS');
    session_start();
}

// Apply the administrator-configured time zone once the database is reachable.
try {
    $configuredTimezone = setting('timezone', APP_TIMEZONE);
    if (is_string($configuredTimezone) && in_array($configuredTimezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($configuredTimezone);
    }
} catch (Throwable $e) {
    // Installation or database failure is handled elsewhere.
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

set_exception_handler(static function (Throwable $e): void {
    app_log('exception', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    $isDatabaseError = $e instanceof PDOException;
    if (APP_DEBUG) {
        echo '<h1>Application error</h1><pre>' . e($e->getMessage()) . "\n" . e($e->getFile()) . ':' . (int) $e->getLine() . '</pre>';
    } else {
        $errorTitle = $isDatabaseError ? 'Database unavailable' : 'Something went wrong';
        $errorMessage = $isDatabaseError
            ? 'The application could not connect to its database. Please check your configuration.'
            : 'We could not complete your request. Please try again later.';
        include BASE_PATH . '/includes/error_page.php';
    }
    exit;
});
