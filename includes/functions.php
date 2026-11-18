<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $base = rtrim(APP_URL, '/');
    if ($path === '') {
        return $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token ??= $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}

function require_csrf(): void
{
    if (!verify_csrf()) {
        http_response_code(419);
        exit('Invalid or expired security token. Please reload the page and try again.');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function old_inputs(array $input): void
{
    unset($input['password'], $input['password_confirm'], $input['_token']);
    $_SESSION['_old'] = $input;
}

function clear_old_inputs(): void
{
    unset($_SESSION['_old']);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            app_log('settings', 'Unable to load settings: ' . $e->getMessage());
        }
    }
    if (!array_key_exists($key, $cache) || $cache[$key] === null || $cache[$key] === '') {
        return $default;
    }
    return $cache[$key];
}

function setting_bool(string $key, bool $default = false): bool
{
    $value = setting($key, null);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Master platform switch. When payments are disabled the whole platform is
 * free: members unlock courses without consuming their free allowance and
 * Paystack checkout is unavailable. When enabled, the existing free-allowance
 * and paid-unlock behaviour applies unchanged.
 */
function platform_payments_enabled(): bool
{
    return setting_bool('payments_enabled', true);
}

function platform_is_free(): bool
{
    return !platform_payments_enabled();
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([$key, $value]);
}

function format_money(float|string|null $amount, ?string $currency = null): string
{
    $currency ??= (string) setting('default_currency', 'NGN');
    $value = $amount === null || $amount === '' ? 0.0 : (float) $amount;
    return $currency . ' ' . number_format($value, 2);
}

function format_date(?string $datetime, string $format = 'M j, Y'): string
{
    if (!$datetime) {
        return '—';
    }
    $timestamp = strtotime($datetime);
    return $timestamp ? date($format, $timestamp) : '—';
}

function format_datetime(?string $datetime): string
{
    return format_date($datetime, 'M j, Y g:i A');
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '—';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'just now';
    }
    $units = [
        31536000 => 'year',
        2592000 => 'month',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
    ];
    foreach ($units as $seconds => $label) {
        if ($diff >= $seconds) {
            $count = (int) floor($diff / $seconds);
            return $count . ' ' . $label . ($count === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

function slugify(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? $value : 'course';
}

function instructor_display_name(?string $instructor): ?string
{
    $name = trim((string) $instructor);
    if ($name === '') {
        return null;
    }

    static $sourceKeys = null;
    if ($sourceKeys === null) {
        $sourceKeys = [];
        foreach (db()->query('SELECT name FROM course_sources') as $row) {
            $key = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower((string) $row['name']));
            if ($key !== '') {
                $sourceKeys[] = $key;
            }
        }
    }

    $key = preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($name));
    return $key !== '' && in_array($key, $sourceKeys, true) ? null : $name;
}

function course_display_title(string $title): string
{
    $clean = preg_replace('/^\s*\[\s*100%\s*Off\s*\]\s*/iu', '', $title) ?? $title;
    return trim($clean) !== '' ? trim($clean) : $title;
}

function excerpt(?string $text, int $length = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length - 1) . '…';
}

function availability_sql(string $alias = 'c'): string
{
    return $alias . ".publication_status = 'published' AND " . $alias . ".verification_status = 'verified' AND (" . $alias . ".expiry_date IS NULL OR " . $alias . ".expiry_date >= NOW())";
}

function course_is_available(array $course): bool
{
    if (($course['publication_status'] ?? '') !== 'published' || ($course['verification_status'] ?? '') !== 'verified') {
        return false;
    }
    if (!empty($course['expiry_date']) && strtotime((string) $course['expiry_date']) < time()) {
        return false;
    }
    return true;
}

function get_categories(bool $activeOnly = true): array
{
    static $cache = [];
    $key = $activeOnly ? 'active' : 'all';
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM courses co WHERE co.category_id = c.id AND ' . availability_sql('co') . ') AS course_count FROM categories c';
    if ($activeOnly) {
        $sql .= " WHERE c.status = 'active'";
    }
    $sql .= ' ORDER BY c.name ASC';
    try {
        $cache[$key] = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        app_log('categories', $e->getMessage());
        $cache[$key] = [];
    }
    return $cache[$key];
}

function find_course(string $identifier): ?array
{
    $sql = 'SELECT c.*, cat.name AS category_name, cat.slug AS category_slug
            FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id
            WHERE ' . (ctype_digit($identifier) ? 'c.id = ?' : 'c.slug = ?') . ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$identifier]);
    $course = $stmt->fetch();
    return $course ?: null;
}

function canonical_udemy_key(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!$parts || empty($parts['host'])) {
        return null;
    }
    $host = strtolower($parts['host']);
    if ($host !== 'udemy.com' && !str_ends_with($host, '.udemy.com')) {
        return null;
    }
    $path = $parts['path'] ?? '';
    if (preg_match('#/course/([a-z0-9\-_]+)#i', $path, $matches)) {
        return 'udemy:' . strtolower($matches[1]);
    }
    return 'udemy-url:' . strtolower($host . $path);
}

function extract_udemy_id(string $url): ?string
{
    $key = canonical_udemy_key($url);
    if ($key !== null && str_starts_with($key, 'udemy:')) {
        return substr($key, 6);
    }
    return null;
}

function is_safe_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function log_activity(?int $userId, string $activity, string $details = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO user_activity_logs (user_id, activity, details, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$userId, $activity, $details]);
    } catch (Throwable $e) {
        app_log('activity', $e->getMessage());
    }
}

function source_names_for_course(int $courseId): array
{
    try {
        $stmt = db()->prepare('SELECT s.name FROM course_source_links l JOIN course_sources s ON s.id = l.source_id WHERE l.course_id = ?');
        $stmt->execute([$courseId]);
        return array_column($stmt->fetchAll(), 'name');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Adapters that are only allowed to publish offers whose current enrollment
 * price is exactly zero. Existing adapters are intentionally excluded so their
 * collection behaviour does not change.
 */
function strict_price_adapters(): array
{
    return ['UdemyFreeAdapter', 'RealDiscountAdapter'];
}

function course_strict_sources(int $courseId): array
{
    static $cache = [];
    if (array_key_exists($courseId, $cache)) {
        return $cache[$courseId];
    }

    $adapters = strict_price_adapters();
    if ($courseId <= 0 || $adapters === []) {
        return $cache[$courseId] = [];
    }

    try {
        $placeholders = implode(', ', array_fill(0, count($adapters), '?'));
        $stmt = db()->prepare(
            'SELECT s.*, l.source_course_url
             FROM course_source_links l
             JOIN course_sources s ON s.id = l.source_id
             WHERE l.course_id = ? AND s.adapter IN (' . $placeholders . ')
             ORDER BY s.id ASC'
        );
        $stmt->execute(array_merge([$courseId], $adapters));
        return $cache[$courseId] = $stmt->fetchAll();
    } catch (Throwable $e) {
        app_log('sources', 'Unable to load strict price sources: ' . $e->getMessage(), ['course' => $courseId]);
        return $cache[$courseId] = [];
    }
}

function course_has_strict_source(int $courseId): bool
{
    return course_strict_sources($courseId) !== [];
}

function strict_price_is_free(mixed $price): bool
{
    return $price !== null && $price !== '' && is_numeric($price) && (float) $price <= 0.0;
}

function pagination(int $total, int $perPage, int $currentPage): array
{
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $currentPage = max(1, min($currentPage, $pages));
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current' => $currentPage,
        'pages' => $pages,
        'offset' => ($currentPage - 1) * $perPage,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $pages,
    ];
}

function query_string_with(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    $query = http_build_query($params);
    return $query !== '' ? '?' . $query : '';
}

function verify_webhook_token(string $provided): bool
{
    return CRON_SECRET !== '' && hash_equals(CRON_SECRET, $provided);
}

function safe_relative_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^https?://#i', $path) || str_starts_with($path, '//') || str_contains($path, "\0")) {
        return '';
    }
    return ltrim($path, '/');
}

function current_relative_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $base = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $query = parse_url($uri, PHP_URL_QUERY);
    return ltrim($path, '/') . ($query ? '?' . $query : '');
}

function intended_redirect(string $fallback = 'dashboard/'): string
{
    $intended = $_SESSION['intended'] ?? '';
    unset($_SESSION['intended']);
    $safe = is_string($intended) ? safe_relative_path($intended) : '';
    return $safe !== '' ? $safe : $fallback;
}

function ensure_installed(): void
{
    static $installed = null;
    if ($installed === null) {
        $installed = db_installed();
    }
    if (!$installed) {
        redirect('install.php');
    }
}

function ensure_installed_api(): void
{
    if (!db_installed()) {
        json_response(['ok' => false, 'error' => 'The application is not installed yet.'], 503);
    }
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));
    if ($parts === []) {
        return 'U';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function category_icon(string $slug): string
{
    $icons = [
        'development' => 'code-2',
        'business' => 'briefcase',
        'design' => 'palette',
        'marketing' => 'megaphone',
        'it-software' => 'server',
        'personal-development' => 'sparkles',
        'photography' => 'camera',
        'music' => 'music',
        'health-fitness' => 'heart-pulse',
        'finance-accounting' => 'wallet',
        'office-productivity' => 'file-spreadsheet',
        'teaching-academics' => 'graduation-cap',
    ];
    return $icons[$slug] ?? 'layers';
}

function verification_badge(string $status): array
{
    return match ($status) {
        'verified' => ['success', 'Verified'],
        'pending' => ['muted', 'Pending'],
        'questionable' => ['warning', 'Questionable'],
        'expired' => ['danger', 'Expired'],
        'unavailable' => ['danger', 'Unavailable'],
        default => ['danger', 'Failed'],
    };
}
