<?php
declare(strict_types=1);

$adminPage = 'overview';
$adminTitle = 'Overview';
require_once __DIR__ . '/includes/header.php';

$pdo = db();

$stats = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'published' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE publication_status = 'published'")->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE verification_status = 'pending'")->fetchColumn(),
    'verified' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE verification_status = 'verified'")->fetchColumn(),
    'questionable' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE verification_status = 'questionable'")->fetchColumn(),
    'expired' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE verification_status IN ('expired', 'unavailable')")->fetchColumn(),
    'unlocks' => (int) $pdo->query('SELECT COUNT(*) FROM course_unlocks')->fetchColumn(),
    'payments' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'success'")->fetchColumn(),
    'revenue' => (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success'")->fetchColumn(),
];

$latestUsers = $pdo->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 5')->fetchAll();
$latestCourses = $pdo->query(
    'SELECT c.*, cat.name AS category_name FROM courses c
     LEFT JOIN categories cat ON cat.id = c.category_id
     ORDER BY c.discovered_at DESC LIMIT 6'
)->fetchAll();
$recentPayments = $pdo->query(
    'SELECT p.*, u.full_name, u.email, c.title AS course_title
     FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN courses c ON c.id = p.course_id
     ORDER BY p.created_at DESC LIMIT 6'
)->fetchAll();
$sourceHealth = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM course_source_links l WHERE l.source_id = s.id) AS course_count,
            (SELECT status FROM collection_logs cl WHERE cl.source_id = s.id ORDER BY cl.started_at DESC LIMIT 1) AS last_log_status,
            (SELECT started_at FROM collection_logs cl WHERE cl.source_id = s.id ORDER BY cl.started_at DESC LIMIT 1) AS last_log_at
     FROM course_sources s ORDER BY s.name ASC'
)->fetchAll();
?>
<div class="dashboard-head">
    <div>
        <h1>Platform overview</h1>
        <p class="mb-0">Collection, verification and payment activity at a glance.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/verification.php')) ?>"><i data-lucide="shield-check"></i> Review queue<?= $stats['questionable'] > 0 ? ' (' . $stats['questionable'] . ')' : '' ?></a>
        <form method="post" action="<?= e(url('admin/sources.php')) ?>" class="m-0" data-confirm="Fetch deeper listing pages from all enabled sources? This can take a minute or two.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="collect_more">
            <button class="btn btn-primary" type="submit"><i data-lucide="layers"></i> Fetch more courses</button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['users', 'users', 'Registered users', '#'],
        ['published', 'book-open-check', 'Published courses', 'courses.php?publication=published'],
        ['pending', 'hourglass', 'Pending verification', 'verification.php'],
        ['verified', 'check-circle-2', 'Verified courses', 'courses.php?verification=verified'],
        ['expired', 'calendar-x', 'Expired / unavailable', 'courses.php?verification=expired'],
        ['unlocks', 'unlock', 'Total unlocks', '#'],
    ];
    foreach ($cards as [$key, $icon, $label, $link]):
    ?>
        <div class="col-sm-6 col-xl-4 col-xxl-2">
            <a class="stat-card d-block text-decoration-none" href="<?= $link === '#' ? '#' : e(url('admin/' . $link)) ?>">
                <span class="stat-icon"><i data-lucide="<?= e($icon) ?>"></i></span>
                <div class="stat-value"><?= number_format($stats[$key]) ?></div>
                <div class="stat-label"><?= e($label) ?></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <span class="stat-icon"><i data-lucide="credit-card"></i></span>
            <div class="stat-value"><?= number_format($stats['payments']) ?></div>
            <div class="stat-label">Successful payments</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <span class="stat-icon"><i data-lucide="banknote"></i></span>
            <div class="stat-value" style="font-size:1.4rem"><?= e(format_money($stats['revenue'], (string) setting('default_currency', 'NGN'))) ?></div>
            <div class="stat-label">Total verified revenue</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <span class="stat-icon"><i data-lucide="alert-triangle"></i></span>
            <div class="stat-value"><?= number_format($stats['questionable']) ?></div>
            <div class="stat-label">Courses needing review</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="panel mb-4">
            <div class="panel-head">
                <h2>Latest collected courses</h2>
                <a class="section-link" href="<?= e(url('admin/courses.php')) ?>">All courses</a>
            </div>
            <div class="panel-body p-0">
                <?php if ($latestCourses): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead><tr><th>Course</th><th>Source status</th><th>Discovered</th></tr></thead>
                            <tbody>
                            <?php foreach ($latestCourses as $course): ?>
                                <?php $badge = verification_badge((string) $course['verification_status']); ?>
                                <tr>
                                    <td>
                                        <a class="text-strong" href="<?= e(url('admin/course-edit.php?id=' . (int) $course['id'])) ?>"><?= e(excerpt($course['title'], 60)) ?></a>
                                        <div class="small-muted"><?= e($course['category_name'] ?: 'Uncategorized') ?> · <?= e($course['publication_status']) ?></div>
                                    </td>
                                    <td><span class="badge-soft <?= e($badge[0]) ?>"><?= e($badge[1]) ?></span></td>
                                    <td class="small-muted"><?= e(time_ago($course['discovered_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="panel-body small-muted">No courses have been collected yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Recent payment activity</h2>
                <a class="section-link" href="<?= e(url('admin/payments.php')) ?>">Payment records</a>
            </div>
            <div class="panel-body p-0">
                <?php if ($recentPayments): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <thead><tr><th>User</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentPayments as $payment): ?>
                                <tr>
                                    <td>
                                        <div class="text-strong"><?= e($payment['full_name']) ?></div>
                                        <div class="small-muted"><?= e(excerpt($payment['course_title'] ?? 'Course removed', 40)) ?></div>
                                    </td>
                                    <td><?= e(format_money((float) $payment['amount'], $payment['currency'])) ?></td>
                                    <td>
                                        <span class="badge-soft <?= $payment['status'] === 'success' ? 'success' : ($payment['status'] === 'pending' ? 'warning' : 'danger') ?>"><?= e(ucfirst((string) $payment['status'])) ?></span>
                                    </td>
                                    <td class="small-muted"><?= e(format_date($payment['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="panel-body small-muted">No payment activity yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="panel mb-4">
            <div class="panel-head"><h2>Source health</h2></div>
            <div class="panel-body">
                <?php foreach ($sourceHealth as $source): ?>
                    <div class="d-flex justify-content-between align-items-start gap-2 py-2" style="border-bottom:1px solid var(--border)">
                        <div>
                            <div class="text-strong"><?= e($source['name']) ?></div>
                            <div class="small-muted">
                                <?= number_format((int) $source['course_count']) ?> linked courses ·
                                <?= $source['last_fetched_at'] ? 'fetched ' . e(time_ago($source['last_fetched_at'])) : 'never fetched' ?>
                            </div>
                        </div>
                        <span class="badge-soft <?= $source['status'] === 'enabled' ? 'success' : 'muted' ?>"><?= e(ucfirst((string) $source['status'])) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$sourceHealth): ?>
                    <p class="small-muted mb-0">No sources configured.</p>
                <?php endif; ?>
                <a class="btn btn-outline-secondary btn-sm w-100 justify-content-center mt-3" href="<?= e(url('admin/sources.php')) ?>">Manage sources</a>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Latest users</h2></div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <tbody>
                        <?php foreach ($latestUsers as $latestUser): ?>
                            <tr>
                                <td>
                                    <a class="text-strong" href="<?= e(url('admin/user.php?id=' . (int) $latestUser['id'])) ?>"><?= e($latestUser['full_name']) ?></a>
                                    <div class="small-muted"><?= e($latestUser['email']) ?></div>
                                </td>
                                <td class="text-end small-muted"><?= e(format_date($latestUser['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
