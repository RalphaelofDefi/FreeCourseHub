<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();

if (Auth::isAdmin()) {
    redirect('admin/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $limitKey = 'admin-login:' . client_ip() . ':' . $email;
    $limit = RateLimiter::check($limitKey, 5, 900);

    if (!$limit['allowed']) {
        app_log('auth', 'Admin login rate limit hit', ['ip' => client_ip(), 'email' => $email]);
        flash('error', 'Too many administrator login attempts. Please wait before trying again.');
        redirect('admin/login.php');
    }

    $user = filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '' ? Auth::attempt($email, $password) : null;
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        app_log('auth', 'Failed administrator login', ['ip' => client_ip(), 'email' => $email]);
        log_activity(null, 'auth.admin_failed', 'Failed admin login from ' . client_ip());
        flash('error', 'Invalid administrator credentials.');
        old_inputs(['email' => $email]);
        redirect('admin/login.php');
    }

    RateLimiter::clear($limitKey);
    Auth::login($user);
    clear_old_inputs();
    log_activity((int) $user['id'], 'auth.admin_login', 'Administrator signed in from ' . client_ip());
    flash('success', 'Welcome back, ' . $user['full_name'] . '.');
    redirect('admin/');
}

$siteName = (string) setting('site_name', APP_NAME);
$defaultTheme = in_array(setting('default_theme', 'light'), ['light', 'dark'], true) ? (string) setting('default_theme', 'light') : 'light';
?><!doctype html>
<html lang="en" data-theme="<?= e($defaultTheme) ?>" data-bs-theme="<?= e($defaultTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator login · <?= e($siteName) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <script>
        (function () {
            var theme = "<?= e($defaultTheme) ?>";
            try {
                var saved = localStorage.getItem("fch-theme");
                if (saved === "dark" || saved === "light") { theme = saved; }
            } catch (e) {}
            document.documentElement.setAttribute("data-theme", theme);
            document.documentElement.setAttribute("data-bs-theme", theme);
        })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<main class="auth-shell">
    <div class="auth-card">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="brand-mark"><i data-lucide="shield-check"></i></span>
            <span class="text-strong fw-bold"><?= e($siteName) ?></span>
        </div>
        <h1>Administrator login</h1>
        <p class="subtitle">Restricted area. Only administrator accounts can sign in here.</p>
        <?php include __DIR__ . '/../includes/flash.php'; ?>
        <form method="post" action="<?= e(url('admin/login.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190" autocomplete="email">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="position-relative">
                    <input class="form-control pe-5" id="password" type="password" name="password" required autocomplete="current-password">
                    <button class="btn btn-ghost btn-sm position-absolute top-50 end-0 translate-middle-y" type="button" data-toggle-password="password" aria-label="Show password"><i data-lucide="eye"></i></button>
                </div>
            </div>
            <button class="btn btn-primary w-100 justify-content-center" type="submit">Sign in to admin</button>
        </form>
        <div class="auth-footer"><a href="<?= e(url()) ?>">Back to website</a> · <a href="<?= e(url('login.php')) ?>">User login</a></div>
    </div>
</main>
<?php clear_old_inputs(); ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
