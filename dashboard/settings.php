<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireLogin('dashboard/settings.php');

$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $errors = [];

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            $errors[] = 'Please enter your full name (2–150 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors[] = 'Please enter a valid email address.';
        }
        $emailChanged = $email !== (string) $user['email'];
        if ($emailChanged && $currentPassword === '') {
            $errors[] = 'Enter your current password to change your email address.';
        }
        if ($currentPassword !== '' && !password_verify($currentPassword, (string) $user['password'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if ($errors === []) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'Another account already uses that email address.';
            }
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            old_inputs(['full_name' => $fullName, 'email' => $email]);
            redirect('dashboard/settings.php');
        }

        $stmt = db()->prepare('UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$fullName, $email, $user['id']]);
        Auth::refresh();
        log_activity((int) $user['id'], 'account.updated', 'Profile details updated');
        flash('success', 'Your account details were updated.');
        redirect('dashboard/settings.php');
    }

    if ($action === 'password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $errors = [];

        if (!password_verify($currentPassword, (string) $user['password'])) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'Your new password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirm) {
            $errors[] = 'The new password confirmation does not match.';
        }
        if ($newPassword !== '' && hash_equals($newPassword, $currentPassword)) {
            $errors[] = 'Choose a password different from your current one.';
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('dashboard/settings.php');
        }

        $stmt = db()->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        log_activity((int) $user['id'], 'account.password_changed', 'Password changed');
        flash('success', 'Your password has been changed.');
        redirect('dashboard/settings.php');
    }

    flash('error', 'Unknown account action.');
    redirect('dashboard/settings.php');
}

$pageTitle = 'Account settings';
$activeNav = '';
include __DIR__ . '/../includes/header.php';
?>
<div class="container dashboard-shell">
    <div class="dashboard-head">
        <div>
            <h1>Account settings</h1>
            <p class="mb-0">Keep your account details secure and up to date.</p>
        </div>
    </div>

    <?php $activeTab = 'settings'; include __DIR__ . '/includes/nav.php'; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="panel mb-4">
                <div class="panel-head"><h2>Profile details</h2></div>
                <div class="panel-body">
                    <form method="post" action="<?= e(url('dashboard/settings.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="profile">
                        <div class="mb-3">
                            <label class="form-label" for="full_name">Full name</label>
                            <input class="form-control" id="full_name" type="text" name="full_name" value="<?= e(old('full_name', (string) $user['full_name'])) ?>" required maxlength="150" autocomplete="name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="email">Email address</label>
                            <input class="form-control" id="email" type="email" name="email" value="<?= e(old('email', (string) $user['email'])) ?>" required maxlength="190" autocomplete="email">
                            <div class="small-muted mt-1">Changing your email requires your current password.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="profile_current_password">Current password</label>
                            <input class="form-control" id="profile_current_password" type="password" name="current_password" autocomplete="current-password">
                        </div>
                        <button class="btn btn-primary" type="submit">Save changes</button>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><h2>Change password</h2></div>
                <div class="panel-body">
                    <form method="post" action="<?= e(url('dashboard/settings.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="password">
                        <div class="mb-3">
                            <label class="form-label" for="current_password">Current password</label>
                            <input class="form-control" id="current_password" type="password" name="current_password" required autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_password">New password</label>
                            <div class="position-relative">
                                <input class="form-control pe-5" id="new_password" type="password" name="new_password" required minlength="8" autocomplete="new-password">
                                <button class="btn btn-ghost btn-sm position-absolute top-50 end-0 translate-middle-y" type="button" data-toggle-password="new_password" aria-label="Show password"><i data-lucide="eye"></i></button>
                            </div>
                            <div class="small-muted mt-1">Use at least 8 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_password_confirm">Confirm new password</label>
                            <input class="form-control" id="new_password_confirm" type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
                        </div>
                        <button class="btn btn-primary" type="submit">Update password</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="panel">
                <div class="panel-head"><h2>Account summary</h2></div>
                <div class="panel-body">
                    <ul class="detail-list">
                        <li><span class="label">Account ID</span><span class="value">#<?= (int) $user['id'] ?></span></li>
                        <li><span class="label">Role</span><span class="value"><?= e(ucfirst((string) $user['role'])) ?></span></li>
                        <li><span class="label">Status</span><span class="value"><?= e(ucfirst((string) $user['status'])) ?></span></li>
                        <li><span class="label">Free unlocks</span><span class="value"><?= (int) $user['free_unlocks_remaining'] ?> remaining</span></li>
                        <li><span class="label">Member since</span><span class="value"><?= e(format_date($user['created_at'])) ?></span></li>
                        <li><span class="label">Last updated</span><span class="value"><?= e(format_datetime($user['updated_at'])) ?></span></li>
                    </ul>
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <a class="btn btn-outline-secondary w-100 justify-content-center mt-2" href="<?= e(url('admin/')) ?>"><i data-lucide="shield-check"></i> Open admin dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php clear_old_inputs(); include __DIR__ . '/../includes/footer.php'; ?>
