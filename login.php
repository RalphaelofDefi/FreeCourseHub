<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

if (Auth::check()) {
    redirect('dashboard/');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $redirect = is_string($_GET['redirect'] ?? null) ? safe_relative_path($_GET['redirect']) : '';
    if ($redirect !== '') {
        $_SESSION['intended'] = $redirect;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $limitKey = 'login:' . client_ip() . ':' . $email;
    $limit = RateLimiter::check($limitKey, 6, 600);

    if (!$limit['allowed']) {
        app_log('auth', 'Login rate limit hit', ['ip' => client_ip(), 'email' => $email]);
        flash('error', 'Too many login attempts. Please wait a few minutes before trying again.');
        redirect('login.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        flash('error', 'Please enter your email address and password.');
        old_inputs(['email' => $email]);
        redirect('login.php');
    }

    $user = Auth::attempt($email, $password);
    if (!$user) {
        app_log('auth', 'Failed login attempt', ['ip' => client_ip(), 'email' => $email]);
        log_activity(null, 'auth.failed', 'Failed login for ' . $email . ' from ' . client_ip());
        flash('error', 'Invalid credentials or your account is suspended.');
        old_inputs(['email' => $email]);
        redirect('login.php');
    }

    RateLimiter::clear($limitKey);
    Auth::login($user);
    clear_old_inputs();
    log_activity((int) $user['id'], 'auth.login', 'Logged in from ' . client_ip());
    flash('success', 'Welcome back, ' . $user['full_name'] . '.');
    redirect(Auth::isAdmin() ? intended_redirect('admin/') : intended_redirect('dashboard/'));
}

$pageTitle = 'Login';
$pageDescription = 'Log in to unlock free Udemy courses.';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="auth-card">
        <h1>Welcome back</h1>
        <p class="subtitle">Log in to unlock courses and manage your account.</p>
        <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
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
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="small-muted">Secure session enabled</span>
                <a class="small" href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a>
            </div>
            <button class="btn btn-primary w-100 justify-content-center" type="submit">Log in</button>
        </form>
        <div class="auth-footer">New here? <a href="<?= e(url('register.php')) ?>">Create a free account</a></div>
    </div>
</div>
<?php clear_old_inputs(); include __DIR__ . '/includes/footer.php'; ?>
