<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireLogin('dashboard/payments.php');

$user = Auth::user();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$countStmt = db()->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$total = (int) $countStmt->fetchColumn();
$pager = pagination($total, $perPage, $page);

$stmt = db()->prepare(
    'SELECT p.*, c.title AS course_title, c.slug AS course_slug
     FROM payments p LEFT JOIN courses c ON c.id = p.course_id
     WHERE p.user_id = ?
     ORDER BY p.created_at DESC
     LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute([$user['id']]);
$payments = $stmt->fetchAll();

$pageTitle = 'Payment history';
$activeNav = '';
include __DIR__ . '/../includes/header.php';
?>
<div class="container dashboard-shell">
    <div class="dashboard-head">
        <div>
            <h1>Payment history</h1>
            <p class="mb-0">All transactions on your account. Access is only granted after server-side verification.</p>
        </div>
    </div>

    <?php $activeTab = 'payments'; include __DIR__ . '/includes/nav.php'; ?>

    <div class="panel">
        <div class="panel-head">
            <h2><?= number_format($total) ?> transaction<?= $total === 1 ? '' : 's' ?></h2>
        </div>
        <div class="panel-body p-0">
            <?php if ($payments): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Course</th>
                                <th>Amount</th>
                                <th>Currency</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><span class="small-muted"><?= e($payment['transaction_reference']) ?></span></td>
                                <td>
                                    <?php if ($payment['course_title']): ?>
                                        <a class="text-strong" href="<?= e(url('course.php?slug=' . urlencode((string) $payment['course_slug']))) ?>"><?= e(excerpt($payment['course_title'], 55)) ?></a>
                                    <?php else: ?>
                                        <span class="small-muted">Course removed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(number_format((float) $payment['amount'], 2)) ?></td>
                                <td><?= e($payment['currency']) ?></td>
                                <td class="small-muted"><?= e(format_datetime($payment['created_at'])) ?></td>
                                <td>
                                    <?php
                                    $statusClass = match ($payment['status']) {
                                        'success' => 'success',
                                        'pending' => 'warning',
                                        default => 'danger',
                                    };
                                    ?>
                                    <span class="badge-soft <?= $statusClass ?>"><?= e(ucfirst((string) $payment['status'])) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pager['pages'] > 1): ?>
                    <div class="panel-body">
                        <nav aria-label="Payment history pagination">
                            <ul class="pagination justify-content-center flex-wrap mb-0">
                                <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= e(url('dashboard/payments.php' . query_string_with(['page' => $pager['current'] - 1]))) ?>">Previous</a>
                                </li>
                                <?php for ($i = max(1, $pager['current'] - 2); $i <= min($pager['pages'], $pager['current'] + 2); $i++): ?>
                                    <li class="page-item <?= $i === $pager['current'] ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= e(url('dashboard/payments.php' . query_string_with(['page' => $i]))) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                                    <a class="page-link" href="<?= e(url('dashboard/payments.php' . query_string_with(['page' => $pager['current'] + 1]))) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon"><i data-lucide="receipt"></i></div>
                    <h3>No payments yet</h3>
                    <p>Your free unlocks are used before any payment is requested.</p>
                    <a class="btn btn-primary" href="<?= e(url('courses.php')) ?>">Browse courses</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
