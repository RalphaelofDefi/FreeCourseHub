<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$userId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    flash('error', 'User not found.');
    redirect('admin/users.php');
}

$adminId = (int) Auth::user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'suspend') {
            if ($userId === $adminId) {
                flash('error', 'You cannot suspend your own administrator account.');
            } else {
                db()->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?")->execute([$userId]);
                log_activity($adminId, 'admin.user_suspended', 'Suspended user #' . $userId);
                flash('success', 'Account suspended.');
            }
        } elseif ($action === 'activate') {
            db()->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$userId]);
            log_activity($adminId, 'admin.user_activated', 'Reactivated user #' . $userId);
            flash('success', 'Account reactivated.');
        } elseif ($action === 'allowance') {
            $allowance = filter_var($_POST['free_unlocks_remaining'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
            if ($allowance === false) {
                flash('error', 'Enter a whole number between 0 and 1000.');
            } else {
                db()->prepare('UPDATE users SET free_unlocks_remaining = ?, updated_at = NOW() WHERE id = ?')->execute([$allowance, $userId]);
                log_activity($adminId, 'admin.allowance_adjusted', 'Set free unlocks to ' . $allowance . ' for user #' . $userId);
                flash('success', 'Free unlock allowance updated.');
            }
        } else {
            flash('error', 'Unknown user action.');
        }
    } catch (Throwable $e) {
        app_log('admin', 'User action failed: ' . $e->getMessage());
        flash('error', 'The user action could not be completed.');
    }
    redirect('admin/user.php?id=' . $userId);
}

$stmt = db()->prepare(
    'SELECT u.*, c.title, c.slug, c.publication_status, c.verification_status, u.unlocked_at, u.unlock_type
     FROM course_unlocks u JOIN courses c ON c.id = u.course_id
     WHERE u.user_id = ? ORDER BY u.unlocked_at DESC LIMIT 25'
);
$stmt->execute([$userId]);
$unlocks = $stmt->fetchAll();

$stmt = db()->prepare('SELECT p.*, c.title AS course_title FROM payments p LEFT JOIN courses c ON c.id = p.course_id WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 25');
$stmt->execute([$userId]);
$payments = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM user_activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$userId]);
$activity = $stmt->fetchAll();

$adminPage = 'users';
$adminTitle = 'User profile';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <div class="breadcrumb-custom mb-1"><a href="<?= e(url('admin/users.php')) ?>">Users</a><span>/</span><span>Profile</span></div>
        <h1 class="mb-1"><?= e($user['full_name']) ?></h1>
        <p class="mb-0"><?= e($user['email']) ?> · joined <?= e(format_date($user['created_at'])) ?></p>
    </div>
    <span class="badge-soft <?= $user['status'] === 'active' ? 'success' : 'danger' ?>" style="align-self:center"><?= e(ucfirst((string) $user['status'])) ?></span>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="panel mb-4">
            <div class="panel-head"><h2>Account</h2></div>
            <div class="panel-body">
                <ul class="detail-list">
                    <li><span class="label">User ID</span><span class="value">#<?= (int) $user['id'] ?></span></li>
                    <li><span class="label">Role</span><span class="value"><?= e(ucfirst((string) $user['role'])) ?></span></li>
                    <li><span class="label">Status</span><span class="value"><?= e(ucfirst((string) $user['status'])) ?></span></li>
                    <li><span class="label">Free unlocks</span><span class="value"><?= (int) $user['free_unlocks_remaining'] ?></span></li>
                    <li><span class="label">Registered</span><span class="value"><?= e(format_datetime($user['created_at'])) ?></span></li>
                    <li><span class="label">Updated</span><span class="value"><?= e(format_datetime($user['updated_at'])) ?></span></li>
                </ul>

                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $userId ?>">
                    <input type="hidden" name="action" value="allowance">
                    <label class="form-label" for="free_unlocks_remaining">Adjust free unlocks</label>
                    <div class="d-flex gap-2">
                        <input class="form-control" id="free_unlocks_remaining" type="number" min="0" max="1000" name="free_unlocks_remaining" value="<?= (int) $user['free_unlocks_remaining'] ?>" required>
                        <button class="btn btn-primary" type="submit">Save</button>
                    </div>
                    <div class="small-muted mt-1">This changes this account only. Existing users are not reset when global settings change.</div>
                </form>

                <div class="divider"></div>
                <?php if ($user['status'] === 'active'): ?>
                    <form method="post" data-confirm="Suspend this account? The user will be signed out and unable to log in.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $userId ?>">
                        <input type="hidden" name="action" value="suspend">
                        <button class="btn btn-danger-soft w-100 justify-content-center" type="submit" <?= $userId === $adminId ? 'disabled' : '' ?>><i data-lucide="ban"></i> Suspend account</button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $userId ?>">
                        <input type="hidden" name="action" value="activate">
                        <button class="btn btn-success-soft w-100 justify-content-center" type="submit"><i data-lucide="check-circle-2"></i> Reactivate account</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="panel mb-4">
            <div class="panel-head"><h2>Unlocked courses</h2></div>
            <div class="panel-body p-0">
                <?php if ($unlocks): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead><tr><th>Course</th><th>Type</th><th>Status</th><th>Unlocked</th></tr></thead>
                            <tbody>
                            <?php foreach ($unlocks as $unlock): ?>
                                <tr>
                                    <td class="text-strong"><?= e(excerpt($unlock['title'], 55)) ?></td>
                                    <td><span class="badge-soft <?= $unlock['unlock_type'] === 'paid' ? 'accent' : 'muted' ?>"><?= e(ucfirst((string) $unlock['unlock_type'])) ?></span></td>
                                    <td class="small-muted"><?= e(ucfirst((string) $unlock['verification_status'])) ?></td>
                                    <td class="small-muted"><?= e(format_date($unlock['unlocked_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="panel-body small-muted">This user has not unlocked any courses.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel mb-4">
            <div class="panel-head"><h2>Payment history</h2></div>
            <div class="panel-body p-0">
                <?php if ($payments): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead><tr><th>Reference</th><th>Course</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="small-muted"><?= e($payment['transaction_reference']) ?></td>
                                    <td><?= e(excerpt($payment['course_title'] ?? 'Course removed', 40)) ?></td>
                                    <td><?= e(format_money((float) $payment['amount'], $payment['currency'])) ?></td>
                                    <td><span class="badge-soft <?= $payment['status'] === 'success' ? 'success' : ($payment['status'] === 'pending' ? 'warning' : 'danger') ?>"><?= e(ucfirst((string) $payment['status'])) ?></span></td>
                                    <td class="small-muted"><?= e(format_date($payment['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="panel-body small-muted">No payment records.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Recent activity</h2></div>
            <div class="panel-body">
                <?php if ($activity): ?>
                    <ul class="detail-list mb-0">
                        <?php foreach ($activity as $entry): ?>
                            <li>
                                <span class="label"><?= e(format_datetime($entry['created_at'])) ?></span>
                                <span class="value"><?= e($entry['activity']) ?><?= $entry['details'] ? ' — ' . e(excerpt($entry['details'], 90)) : '' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small-muted mb-0">No activity recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
