<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

if (Auth::check()) {
    redirect('dashboard/');
}

$resetLink = null;
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $submitted = true;
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $limit = RateLimiter::check('forgot:' . client_ip() . ':' . $email, 3, 900);
    if (!$limit['allowed']) {
        flash('error', 'Too many reset requests. Please wait before trying again.');
        redirect('forgot-password.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Please enter a valid email address.');
        redirect('forgot-password.php');
    }

    $stmt = db()->prepare("SELECT id, full_name FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 3600);
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
        $stmt = db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $hash, $expires]);
        $link = url('reset-password.php?token=' . $token);

        $subject = 'Reset your ' . setting('site_name', APP_NAME) . ' password';
        $body = "Hello " . $user['full_name'] . ",\n\nUse this link to reset your password (valid for one hour):\n" . $link . "\n\nIf you did not request this, you can ignore this email.";
        $headers = "From: no-reply@" . (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost') . "\r\nContent-Type: text/plain; charset=UTF-8";
        $mailSent = @mail($email, $subject, $body, $headers);
        app_log('auth', 'Password reset requested', ['email' => $email, 'mail_sent' => $mailSent]);
        log_activity((int) $user['id'], 'auth.password_reset_requested', 'Reset requested from ' . client_ip());
        if (APP_DEBUG) {
            $resetLink = $link;
        }
    } else {
        app_log('auth', 'Password reset requested for unknown email', ['email' => $email]);
    }

    flash('info', 'If an account exists for that email address, a password reset link has been sent. The link expires in one hour.');
}

$pageTitle = 'Forgot password';
$pageDescription = 'Request a password reset link.';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="auth-card">
        <h1>Forgot your password?</h1>
        <p class="subtitle">Enter the email address linked to your account and we will send a reset link.</p>
        <?php if ($resetLink): ?>
            <div class="alert alert-info">
                <div class="fw-semibold mb-1">Development reset link</div>
                <a href="<?= e($resetLink) ?>" style="word-break:break-all"><?= e($resetLink) ?></a>
            </div>
        <?php endif; ?>
        <?php if (!$submitted): ?>
            <form method="post" action="<?= e(url('forgot-password.php')) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control" id="email" type="email" name="email" required maxlength="190" autocomplete="email">
                </div>
                <button class="btn btn-primary w-100 justify-content-center" type="submit">Send reset link</button>
            </form>
        <?php endif; ?>
        <div class="auth-footer"><a href="<?= e(url('login.php')) ?>">Back to login</a></div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
