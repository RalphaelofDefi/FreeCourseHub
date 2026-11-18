<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = in_array($_GET['status'] ?? '', ['active', 'suspended'], true) ? (string) $_GET['status'] : '';
$roleFilter = in_array($_GET['role'] ?? '', ['user', 'admin'], true) ? (string) $_GET['role'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare('SELECT COUNT(*) FROM users WHERE ' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pager = pagination($total, $perPage, $page);

$stmt = db()->prepare(
    'SELECT u.*,
        (SELECT COUNT(*) FROM course_unlocks cu WHERE cu.user_id = u.id) AS unlock_count,
        (SELECT COUNT(*) FROM payments p WHERE p.user_id = u.id AND p.status = \'success\') AS payment_count
     FROM users u WHERE ' . $whereSql . '
     ORDER BY u.created_at DESC
     LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$adminPage = 'users';
$adminTitle = 'Users';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <h1>Users</h1>
        <p class="mb-0"><?= number_format($total) ?> account<?= $total === 1 ? '' : 's' ?> matching the current filters.</p>
    </div>
</div>

<div class="panel mb-4">
    <div class="panel-body">
        <form method="get" action="<?= e(url('admin/users.php')) ?>" class="row g-2 align-items-end">
            <div class="col-lg-5 col-md-6">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name or email">
            </div>
            <div class="col-lg-3 col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">Any status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                </select>
            </div>
            <div class="col-lg-3 col-md-3">
                <label class="form-label" for="role">Role</label>
                <select class="form-select" id="role" name="role">
                    <option value="">Any role</option>
                    <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>User</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrator</option>
                </select>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/users.php')) ?>">Reset</a>
                <button class="btn btn-primary btn-sm" type="submit">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-body p-0">
        <?php if ($users): ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Free unlocks</th>
                            <th>Unlocked</th>
                            <th>Payments</th>
                            <th>Joined</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <td>
                                <div class="text-strong"><?= e($row['full_name']) ?></div>
                                <div class="small-muted"><?= e($row['email']) ?></div>
                            </td>
                            <td><span class="badge-soft <?= $row['role'] === 'admin' ? 'accent' : 'muted' ?>"><?= e(ucfirst((string) $row['role'])) ?></span></td>
                            <td><span class="badge-soft <?= $row['status'] === 'active' ? 'success' : 'danger' ?>"><?= e(ucfirst((string) $row['status'])) ?></span></td>
                            <td><?= (int) $row['free_unlocks_remaining'] ?></td>
                            <td><?= number_format((int) $row['unlock_count']) ?></td>
                            <td><?= number_format((int) $row['payment_count']) ?></td>
                            <td class="small-muted"><?= e(format_date($row['created_at'])) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/user.php?id=' . (int) $row['id'])) ?>">Manage</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pager['pages'] > 1): ?>
                <div class="panel-body">
                    <nav aria-label="User pagination">
                        <ul class="pagination justify-content-center flex-wrap mb-0">
                            <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                                <a class="page-link" href="<?= e(url('admin/users.php' . query_string_with(['page' => $pager['current'] - 1]))) ?>">Previous</a>
                            </li>
                            <li class="page-item disabled"><span class="page-link">Page <?= $pager['current'] ?> of <?= $pager['pages'] ?></span></li>
                            <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                                <a class="page-link" href="<?= e(url('admin/users.php' . query_string_with(['page' => $pager['current'] + 1]))) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="icon"><i data-lucide="users"></i></div>
                <h3>No users found</h3>
                <p>Try a different search term.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
