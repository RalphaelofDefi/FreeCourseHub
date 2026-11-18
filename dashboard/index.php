<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireLogin('dashboard/');

$user = Auth::user();

$stmt = db()->prepare('SELECT COUNT(*) FROM course_unlocks WHERE user_id = ?');
$stmt->execute([$user['id']]);
$totalUnlocked = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM course_unlocks WHERE user_id = ? AND unlock_type = 'paid'");
$stmt->execute([$user['id']]);
$paidUnlocks = (int) $stmt->fetchColumn();

$freeRemaining = (int) $user['free_unlocks_remaining'];
$allowance = max(1, (int) setting('free_unlock_allowance', 5));
$progress = max(0, min(100, ($freeRemaining / $allowance) * 100));
$platformFree = platform_is_free();

$stmt = db()->prepare(
    'SELECT u.*, c.title, c.slug, c.thumbnail_url, c.category_id, cat.name AS category_name,
            c.publication_status, c.verification_status, c.expiry_date
     FROM course_unlocks u
     JOIN courses c ON c.id = u.course_id
     LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE u.user_id = ?
     ORDER BY u.unlocked_at DESC LIMIT 5'
);
$stmt->execute([$user['id']]);
$recentUnlocks = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 4"
);
$stmt->execute([$user['id']]);
$recentPayments = $stmt->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = '';
include __DIR__ . '/../includes/header.php';
?>
<div class="container dashboard-shell">
    <div class="dashboard-head">
        <div>
            <h1>Welcome back, <?= e(explode(' ', (string) $user['full_name'])[0]) ?></h1>
            <p class="mb-0">Member since <?= e(format_date($user['created_at'])) ?>. Here is your account at a glance.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(url('courses.php')) ?>"><i data-lucide="compass"></i> Browse courses</a>
    </div>

    <?php $activeTab = 'overview'; include __DIR__ . '/includes/nav.php'; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon"><i data-lucide="book-open-check"></i></span>
                <div class="stat-value"><?= number_format($totalUnlocked) ?></div>
                <div class="stat-label">Courses unlocked</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon"><i data-lucide="gift"></i></span>
                <div class="stat-value" style="<?= $platformFree ? 'font-size:1.15rem' : '' ?>"><?= $platformFree ? 'Unlimited' : number_format($freeRemaining) ?></div>
                <div class="stat-label"><?= $platformFree ? 'Platform access' : 'Free unlocks remaining' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon"><i data-lucide="credit-card"></i></span>
                <div class="stat-value"><?= number_format($paidUnlocks) ?></div>
                <div class="stat-label">Paid unlocks</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <span class="stat-icon"><i data-lucide="calendar-days"></i></span>
                <div class="stat-value" style="font-size:1.15rem"><?= e(format_date($user['created_at'])) ?></div>
                <div class="stat-label">Registration date</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="panel mb-4">
                <div class="panel-head">
                    <h2><?= $platformFree ? 'Platform access' : 'Free unlock allowance' ?></h2>
                    <span class="badge-soft <?= $platformFree ? 'success' : ($freeRemaining > 0 ? 'accent' : 'warning') ?>">
                        <?= $platformFree ? 'Free platform' : ($freeRemaining > 0 ? 'Available' : 'Exhausted') ?>
                    </span>
                </div>
                <div class="panel-body">
                    <?php if ($platformFree): ?>
                        <p class="small-muted mb-0">Payments are turned off, so every available course can be unlocked at no cost and without using your free allowance. Previously unlocked courses remain available to you.</p>
                    <?php else: ?>
                        <div class="d-flex justify-content-between small-muted mb-2">
                            <span>Free unlocks remaining</span>
                            <strong class="text-strong"><?= $freeRemaining ?> / <?= $allowance ?></strong>
                        </div>
                        <div class="progress-thin mb-3"><div class="bar" style="width: <?= e((string) $progress) ?>%"></div></div>
                        <?php if ($freeRemaining > 0): ?>
                            <p class="small-muted mb-0">You can unlock <?= $freeRemaining ?> more course<?= $freeRemaining === 1 ? '' : 's' ?> at no cost. Each course only uses one unlock, even if you revisit it later.</p>
                        <?php else: ?>
                            <p class="small-muted mb-0">Your free allowance is exhausted. Additional courses can be unlocked for <strong class="text-strong"><?= e(format_money((float) setting('unlock_price', 0))) ?></strong> each. Previously unlocked courses remain available to you.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2>Recently unlocked</h2>
                    <a class="section-link" href="<?= e(url('dashboard/courses.php')) ?>">View all <i data-lucide="arrow-right" style="width:15px;height:15px"></i></a>
                </div>
                <div class="panel-body p-0">
                    <?php if ($recentUnlocks): ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead><tr><th>Course</th><th>Unlocked</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($recentUnlocks as $unlock): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="mini-thumb d-inline-flex align-items-center justify-content-center text-strong"><?= e(initials((string) $unlock['title'])) ?></span>
                                                <div>
                                                    <div class="text-strong"><?= e(excerpt($unlock['title'], 52)) ?></div>
                                                    <div class="small-muted"><?= e($unlock['category_name'] ?: 'Uncategorized') ?> · <?= e(ucfirst($unlock['unlock_type'])) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="small-muted"><?= e(format_date($unlock['unlocked_at'])) ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('dashboard/courses.php')) ?>">Open</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-4">
                            <div class="empty-state" style="padding:1rem">
                                <div class="icon"><i data-lucide="bookmark"></i></div>
                                <h3>No courses unlocked yet</h3>
                                <p>Browse verified free courses and your first unlock is on us.</p>
                                <a class="btn btn-primary" href="<?= e(url('courses.php')) ?>">Find a course</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="panel mb-4">
                <div class="panel-head"><h2>Account details</h2></div>
                <div class="panel-body">
                    <ul class="detail-list mb-0">
                        <li><span class="label">Full name</span><span class="value"><?= e($user['full_name']) ?></span></li>
                        <li><span class="label">Email</span><span class="value" style="word-break:break-all"><?= e($user['email']) ?></span></li>
                        <li><span class="label">Account status</span><span class="value"><?= e(ucfirst((string) $user['status'])) ?></span></li>
                        <li><span class="label">Member since</span><span class="value"><?= e(format_date($user['created_at'])) ?></span></li>
                    </ul>
                    <a class="btn btn-outline-secondary w-100 justify-content-center mt-3" href="<?= e(url('dashboard/settings.php')) ?>"><i data-lucide="settings"></i> Manage account</a>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2>Recent payments</h2>
                    <a class="section-link" href="<?= e(url('dashboard/payments.php')) ?>">History</a>
                </div>
                <div class="panel-body p-0">
                    <?php if ($recentPayments): ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <tbody>
                                <?php foreach ($recentPayments as $payment): ?>
                                    <tr>
                                        <td>
                                            <div class="text-strong"><?= e(format_money((float) $payment['amount'], $payment['currency'])) ?></div>
                                            <div class="small-muted"><?= e($payment['transaction_reference']) ?></div>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge-soft <?= $payment['status'] === 'success' ? 'success' : ($payment['status'] === 'pending' ? 'muted' : 'danger') ?>"><?= e(ucfirst((string) $payment['status'])) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="panel-body small-muted">No payments yet. Free unlocks are used first.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
