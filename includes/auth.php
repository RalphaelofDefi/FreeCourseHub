<?php
declare(strict_types=1);

class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function attempt(string $email, string $password): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string) $user['password'])) {
            return null;
        }
        if (($user['status'] ?? 'active') !== 'active') {
            return null;
        }
        return $user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['_last_activity'] = time();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$loaded = true;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return self::$user = null;
        }
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user || ($user['status'] ?? 'active') !== 'active') {
                return self::$user = null;
            }
            return self::$user = $user;
        } catch (Throwable $e) {
            app_log('auth', $e->getMessage());
            return self::$user = null;
        }
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && ($user['role'] ?? '') === 'admin';
    }

    public static function refresh(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    public static function requireLogin(string $redirectTo = ''): void
    {
        if (self::check()) {
            return;
        }
        $_SESSION['intended'] = $redirectTo !== '' ? $redirectTo : current_relative_path();
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }

    public static function requireAdmin(): void
    {
        if (self::check() && self::isAdmin()) {
            return;
        }
        $_SESSION['intended'] = current_relative_path();
        flash('error', 'Administrator access is required.');
        redirect('admin/login.php');
    }
}
