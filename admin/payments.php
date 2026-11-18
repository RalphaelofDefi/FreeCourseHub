<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

function payment_filters(): array
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $status = in_array($_GET['status'] ?? '', ['pending', 'success', 'failed', 'cancelled', 'abandoned'], true) ? (string) $_GET['status'] : '';
    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(p.transaction_reference LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($status !== '') {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    return [implode(' AND ', $where), $params, $q, $status];
}

[$whereSql, $params, $q, $statusFilter] = payment_filters();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$countStmt = db()->prepare('SELECT COUNT(*) FROM payments p JOIN users u ON u.id = p.user_id WHERE ' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pager = pagination($total, $perPage, $page);

$stmt = db()->prepare(
    'SELECT p.*, u.full_name, u.email, c.title AS course_title
     FROM payments p JOIN users u ON u.id = p.user_id LEFT JOIN courses c ON c.id = p.course_id
     WHERE ' . $whereSql . '
     ORDER BY p.created_at DESC
     LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$sumStmt = db()->prepare("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN users u ON u.id = p.user_id WHERE p.status = 'success' AND " . $whereSql);
$sumStmt->execute($params);
$filteredRevenue = (float) $sumStmt->fetchColumn();

$adminPage = 'payments';
$adminTitle = 'Payments';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <h1>Payments</h1>
        <p class="mb-0"><?= number_format($total) ?> transaction<?= $total === 1 ? '' : 's' ?>. Verified revenue in this view: <strong class="text-strong"><?= e(format_money($filteredRevenue, (string) setting('default_currency', 'NGN'))) ?></strong>.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(url('admin/payments-export.php' . query_string_with())) ?>"><i data-lucide="download"></i> Export CSV</a>
</div>

<div class="panel mb-4">
    <div class="panel-body">
        <form method="get" action="<?= e(url('admin/payments.php')) ?>" class="row g-2 align-items-end">
            <div class="col-lg-6 col-md-6">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Reference, email or name">
            </div>
            <div class="col-lg-3 col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Any status</option>
                    <?php foreach (['pending', 'success', 'failed', 'cancelled', 'abandoned'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/payments.php')) ?>">Reset</a>
                <button class="btn btn-primary btn-sm" type="submit">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-body p-0">
        <?php if ($payments): ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>User</th>
                            <th>Course</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="small-muted"><?= e($payment['transaction_reference']) ?></td>
                            <td>
                                <a class="text-strong" href="<?= e(url('admin/user.php?id=' . (int) $payment['user_id'])) ?>"><?= e($payment['full_name']) ?></a>
                                <div class="small-muted"><?= e($payment['email']) ?></div>
                            </td>
                            <td><?= e(excerpt($payment['course_title'] ?? 'Course removed', 42)) ?></td>
                            <td><?= e(number_format((float) $payment['amount'], 2)) ?></td>
                            <td><?= e($payment['currency']) ?></td>
                            <td><?= e(ucfirst((string) $payment['provider'])) ?></td>
                            <td><span class="badge-soft <?= $payment['status'] === 'success' ? 'success' : ($payment['status'] === 'pending' ? 'warning' : 'danger') ?>"><?= e(ucfirst((string) $payment['status'])) ?></span></td>
                            <td class="small-muted"><?= e(format_datetime($payment['created_at'])) ?></td>
                            <td class="small-muted"><?= $payment['verified_at'] ? e(format_datetime($payment['verified_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pager['pages'] > 1): ?>
                <div class="panel-body">
                    <nav aria-label="Payment pagination">
                        <ul class="pagination justify-content-center flex-wrap mb-0">
                            <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                                <a class="page-link" href="<?= e(url('admin/payments.php' . query_string_with(['page' => $pager['current'] - 1]))) ?>">Previous</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link">Page <?= $pager['current'] ?> of <?= $pager['pages'] ?></span></li>
                            <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                                <a class="page-link" href="<?= e(url('admin/payments.php' . query_string_with(['page' => $pager['current'] + 1]))) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="icon"><i data-lucide="receipt"></i></div>
                <h3>No payment records</h3>
                <p>Transactions will appear here once users start unlocking paid courses.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
