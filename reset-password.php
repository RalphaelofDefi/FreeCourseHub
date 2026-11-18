<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

if (Auth::check()) {
    redirect('dashboard/');
}

$token = is_string($_POST['token'] ?? null) ? $_POST['token'] : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$token = trim($token);
$reset = null;
$tokenValid = false;

if ($token !== '') {
    $hash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= NOW() LIMIT 1'
    );
    $stmt->execute([$hash]);
    $reset = $stmt->fetch();
    $tokenValid = (bool) $reset;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$tokenValid) {
        flash('error', 'This password reset link is invalid or has expired.');
        redirect('forgot-password.php');
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if (strlen($password) < 8) {
        flash('error', 'Your new password must be at least 8 characters long.');
        redirect('reset-password.php?token=' . urlencode($token));
    }
    if ($password !== $confirm) {
        flash('error', 'The password confirmation does not match.');
        redirect('reset-password.php?token=' . urlencode($token));
    }

    $stmt = db()->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$reset['user_id']]);
    log_activity((int) $reset['user_id'], 'auth.password_reset', 'Password reset completed from ' . client_ip());
    flash('success', 'Your password has been updated. You can now log in with your new password.');
    redirect('login.php');
}

$pageTitle = 'Reset password';
$pageDescription = 'Choose a new password for your account.';
include __DIR__ . '/includes/header.php';
?>
<div class="auth-shell">
    <div class="auth-card">
        <h1>Reset your password</h1>
        <?php if (!$tokenValid): ?>
            <div class="alert alert-danger">This reset link is invalid, already used, or has expired.</div>
            <a class="btn btn-primary w-100 justify-content-center" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
        <?php else: ?>
            <p class="subtitle">Choose a strong new password for <?= e($reset['email']) ?>.</p>
            <form method="post" action="<?= e(url('reset-password.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="mb-3">
                    <label class="form-label" for="password">New password</label>
                    <div class="position-relative">
                        <input class="form-control pe-5" id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
                        <button class="btn btn-ghost btn-sm position-absolute top-50 end-0 translate-middle-y" type="button" data-toggle-password="password" aria-label="Show password"><i data-lucide="eye"></i></button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password_confirm">Confirm new password</label>
                    <input class="form-control" id="password_confirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
                </div>
                <button class="btn btn-primary w-100 justify-content-center" type="submit">Update password</button>
            </form>
        <?php endif; ?>
        <div class="auth-footer"><a href="<?= e(url('login.php')) ?>">Back to login</a></div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
