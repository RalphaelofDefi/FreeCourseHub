<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

if (Auth::check()) {
    redirect('dashboard/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $limit = RateLimiter::check('register:' . client_ip(), 6, 600);
    if (!$limit['allowed']) {
        flash('error', 'Too many registration attempts. Please wait a few minutes and try again.');
        redirect('register.php');
    }

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $errors = [];

    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
        $errors[] = 'Please enter your full name (2–150 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The password confirmation does not match.';
    }

    if ($errors === []) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'An account with this email address already exists.';
        }
    }

    if ($errors === []) {
        $allowance = setting_bool('free_access_enabled', true) ? max(0, (int) setting('free_unlock_allowance', 5)) : 0;
        try {
            $stmt = db()->prepare('INSERT INTO users (full_name, email, password, role, status, free_unlocks_remaining, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), 'user', 'active', $allowance]);
            $userId = (int) db()->lastInsertId();
            $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            Auth::login($user);
            RateLimiter::clear('register:' . client_ip());
            clear_old_inputs();
            log_activity($userId, 'account.created', 'Registered with email ' . $email);
            flash('success', 'Welcome to ' . setting('site_name', APP_NAME) . '! Your account is ready.');
            redirect(intended_redirect('dashboard/'));
        } catch (PDOException $e) {
            app_log('auth', 'Registration failed: ' . $e->getMessage());
            $errors[] = 'We could not create your account. Please try again.';
        }
    }

    flash('error', implode(' ', $errors));
    old_inputs(['full_name' => $fullName, 'email' => $email]);
    redirect('register.php');
}

$freeAllowance = (int) setting('free_unlock_allowance', 5);
$pageTitle = 'Create your account';
$pageDescription = 'Register for a free account and unlock free Udemy courses.';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="auth-card">
        <h1>Create your account</h1>
        <p class="subtitle">Join free and receive <?= $freeAllowance ?> course unlocks to get started.</p>
        <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="full_name">Full name</label>
                <input class="form-control" id="full_name" type="text" name="full_name" value="<?= e(old('full_name')) ?>" required maxlength="150" autocomplete="name">
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190" autocomplete="email">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="position-relative">
                    <input class="form-control pe-5" id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
                    <button class="btn btn-ghost btn-sm position-absolute top-50 end-0 translate-middle-y" type="button" data-toggle-password="password" aria-label="Show password"><i data-lucide="eye"></i></button>
                </div>
                <div class="small-muted mt-1">Use at least 8 characters.</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirm">Confirm password</label>
                <input class="form-control" id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
            </div>
            <button class="btn btn-primary w-100 justify-content-center" type="submit">Create account</button>
        </form>
        <div class="auth-footer">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></div>
    </div>
</div>
<?php clear_old_inputs(); include __DIR__ . '/includes/footer.php'; ?>
