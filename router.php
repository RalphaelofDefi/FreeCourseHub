<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in web server.
 *
 * Pxxl (and local `php -S`) uses this file so that sensitive paths normally
 * covered by .htaccess stay private when Apache is not in front of the app.
 * Apache/shared hosting ignores this file and keeps using .htaccess.
 */

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode((string) (parse_url($requestUri, PHP_URL_PATH) ?: '/'));
$path = str_replace('\\', '/', $path);
$path = preg_replace('#/+#', '/', $path) ?: '/';
$relative = trim($path, '/');
$segments = $relative === '' ? [] : explode('/', $relative);

$blockedRoots = [
    'config',
    'database',
    'includes',
    'modules',
    'storage',
    'vendor',
    'node_modules',
    '.git',
    '.github',
];

if ($segments !== [] && in_array(strtolower($segments[0]), $blockedRoots, true)) {
    deny_request();
}

foreach ($segments as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
        deny_request();
    }
}

$basename = strtolower($segments === [] ? '' : (string) end($segments));
if ($basename !== '' && (
    str_starts_with($basename, '.')
    || in_array($basename, ['router.php', 'prompt.txt'], true)
    || preg_match('/\.(env|sql|lock|log|md|toml)$/i', $basename) === 1
)) {
    deny_request();
}

$target = __DIR__ . ($relative === '' ? '' : '/' . $relative);

if ($relative === '' || is_dir($target)) {
    if ($relative === '' || is_file(rtrim($target, '/\\') . '/index.php')) {
        return false;
    }
    deny_request();
}

if (is_file($target)) {
    return false;
}

deny_request();

function deny_request(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Not found';
    exit;
}
