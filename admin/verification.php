<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$statusFilter = in_array($_GET['status'] ?? '', ['questionable', 'pending', 'failed'], true) ? (string) $_GET['status'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $course = null;
    if (in_array($action, ['approve', 'reject', 'recheck'], true)) {
        $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
        $stmt->execute([$courseId]);
        $course = $stmt->fetch();
        if (!$course) {
            flash('error', 'Course not found.');
            redirect('admin/verification.php');
        }
    }

    try {
        if ($action === 'approve') {
            $gate = StrictPriceValidator::canManuallyPublish($course);
            if (!$gate['ok']) {
                flash('error', (string) $gate['message']);
                redirect('admin/verification.php');
            }
            db()->prepare("UPDATE courses SET verification_status = 'verified', publication_status = 'published', verification_notes = CONCAT(COALESCE(CONCAT(verification_notes, ' '), ''), 'Approved by administrator.'), last_verified_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$courseId]);
            db()->prepare("INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at) VALUES (?, 'verified', 'Approved by administrator.', NOW())")->execute([$courseId]);
            log_activity((int) Auth::user()['id'], 'admin.course_approved', 'Approved course #' . $courseId);
            flash('success', 'Course approved and published.');
        } elseif ($action === 'reject') {
            db()->prepare("UPDATE courses SET verification_status = 'failed', publication_status = 'unpublished', verification_notes = CONCAT(COALESCE(CONCAT(verification_notes, ' '), ''), 'Rejected by administrator.'), updated_at = NOW() WHERE id = ?")->execute([$courseId]);
            db()->prepare("INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at) VALUES (?, 'failed', 'Rejected by administrator.', NOW())")->execute([$courseId]);
            log_activity((int) Auth::user()['id'], 'admin.course_rejected', 'Rejected course #' . $courseId);
            flash('success', 'Course rejected.');
        } elseif ($action === 'approve_all') {
            $queueStatuses = $statusFilter === 'failed' ? [] : ($statusFilter !== '' ? [$statusFilter] : ['pending', 'questionable']);
            if ($queueStatuses === []) {
                flash('error', 'Failed courses cannot be bulk-approved. Recheck or approve them individually.');
                redirect('admin/verification.php');
            }
            $placeholders = implode(', ', array_fill(0, count($queueStatuses), '?'));
            $stmt = db()->prepare('SELECT * FROM courses WHERE verification_status IN (' . $placeholders . ')');
            $stmt->execute($queueStatuses);
            $courseIds = [];
            $blockedByStrictPrice = 0;
            foreach ($stmt->fetchAll() as $row) {
                if (!StrictPriceValidator::storedPriceAllowsPublication($row)) {
                    $blockedByStrictPrice++;
                    continue;
                }
                $courseIds[] = (int) $row['id'];
            }
            if ($courseIds === []) {
                flash('error', 'No courses could be bulk-approved. Strict $0.00 sources require a confirmed free price; recheck those offers individually.');
                redirect('admin/verification.php' . ($statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''));
            }

            $idPlaceholders = implode(', ', array_fill(0, count($courseIds), '?'));
            db()->beginTransaction();
            db()->prepare(
                "UPDATE courses
                 SET verification_status = 'verified', publication_status = 'published',
                     verification_notes = CONCAT(COALESCE(CONCAT(verification_notes, ' '), ''), 'Approved by administrator.'),
                     last_verified_at = NOW(), updated_at = NOW()
                 WHERE id IN (" . $idPlaceholders . ')'
            )->execute($courseIds);
            $logStmt = db()->prepare(
                "INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at)
                 VALUES (?, 'verified', 'Approved by administrator.', NOW())"
            );
            foreach ($courseIds as $id) {
                $logStmt->execute([$id]);
            }
            db()->commit();
            log_activity((int) Auth::user()['id'], 'admin.courses_bulk_approved', 'Approved ' . count($courseIds) . ' courses');
            $message = number_format(count($courseIds)) . ' course' . (count($courseIds) === 1 ? '' : 's') . ' approved and published.';
            if ($blockedByStrictPrice > 0) {
                $message .= ' ' . number_format($blockedByStrictPrice) . ' strict $0.00 offer' . ($blockedByStrictPrice === 1 ? ' was' : 's were') . ' skipped because a free price is not confirmed.';
            }
            flash('success', $message);
        } elseif ($action === 'toggle_approvals') {
            $enableApprovals = !setting_bool('approval_required', true);
            save_setting('approval_required', $enableApprovals ? '1' : '0');
            log_activity((int) Auth::user()['id'], 'admin.approvals_toggled', $enableApprovals ? 'Turned approvals on' : 'Turned approvals off');
            flash('success', $enableApprovals
                ? 'Approvals are on. Newly collected courses wait in this queue and are published after approval or verification.'
                : 'Approvals are off. Newly collected courses publish immediately; the automated verifier still unpublishes offers that fail checks.');
        } elseif ($action === 'recheck') {
            $verifier = new CourseVerifier();
            $result = $verifier->verifyCourse($courseId);
            log_activity((int) Auth::user()['id'], 'admin.course_rechecked', 'Rechecked course #' . $courseId . ' → ' . $result['status']);
            flash('info', 'Verification re-run complete. Result: ' . ucfirst((string) $result['status']) . '.');
        } else {
            flash('error', 'Unknown verification action.');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        app_log('admin', 'Verification action failed: ' . $e->getMessage(), ['course' => $courseId]);
        flash('error', 'The verification action could not be completed.');
    }
    redirect('admin/verification.php');
}

$approvalRequired = setting_bool('approval_required', true);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$where = ["c.verification_status IN ('pending', 'questionable')"];
$params = [];
if ($statusFilter !== '') {
    $where = ['c.verification_status = ?'];
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare('SELECT COUNT(*) FROM courses c WHERE ' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pager = pagination($total, $perPage, $page);

$stmt = db()->prepare(
    'SELECT c.*, cat.name AS category_name FROM courses c
     LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE ' . $whereSql . '
     ORDER BY c.discovered_at ASC LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$sourceStmt = db()->prepare('SELECT s.name, l.source_course_url FROM course_source_links l JOIN course_sources s ON s.id = l.source_id WHERE l.course_id = ?');

$adminPage = 'verification';
$adminTitle = 'Verification queue';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <h1>Verification queue</h1>
        <p class="mb-0"><?= number_format($total) ?> course<?= $total === 1 ? '' : 's' ?> awaiting review. Questionable offers are never published automatically.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-outline-secondary <?= $statusFilter === '' ? 'active' : '' ?>" href="<?= e(url('admin/verification.php')) ?>">All</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/verification.php?status=questionable')) ?>">Questionable</a>
        <a class="btn btn-outline-secondary" href="<?= e(url('admin/verification.php?status=pending')) ?>">Pending</a>
        <?php if ($statusFilter !== 'failed'): ?>
            <form method="post" class="m-0" data-confirm="Approve and publish all <?= number_format($total) ?> course<?= $total === 1 ? '' : 's' ?> in this queue? This bypasses automated re-verification.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve_all">
                <button class="btn btn-success-soft" type="submit" <?= $total > 0 ? '' : 'disabled' ?>><i data-lucide="check-check"></i> Approve all (<?= number_format($total) ?>)</button>
            </form>
        <?php endif; ?>
        <form method="post" class="m-0" <?= $approvalRequired ? 'data-confirm="Turn off approvals? Newly collected courses will be published automatically without review."' : '' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_approvals">
            <button class="btn <?= $approvalRequired ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit">
                <i data-lucide="<?= $approvalRequired ? 'toggle-left' : 'toggle-right' ?>"></i>
                <?= $approvalRequired ? 'Turn off approvals' : 'Turn on approvals' ?>
            </button>
        </form>
    </div>
</div>

<?php if (!$approvalRequired): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i data-lucide="shield-alert" style="width:18px;height:18px;flex:none;margin-top:2px"></i>
        <div><strong>Approvals are off.</strong> Newly collected courses are published immediately. The automated verifier still unpublishes offers that fail its checks, and questionable offers remain in this queue for review.</div>
    </div>
<?php endif; ?>

<?php if ($courses): ?>
    <?php foreach ($courses as $course): ?>
        <?php
        $sourceStmt->execute([$course['id']]);
        $links = $sourceStmt->fetchAll();
        $badge = verification_badge((string) $course['verification_status']);
        ?>
        <div class="panel mb-3">
            <div class="panel-body">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="d-flex gap-3">
                            <span class="mini-thumb d-inline-flex align-items-center justify-content-center text-strong" style="width:90px;height:60px;font-size:1.1rem"><?= e(initials((string) $course['title'])) ?></span>
                            <div>
                                <div class="d-flex flex-wrap gap-2 mb-1">
                                    <span class="badge-soft <?= e($badge[0]) ?>"><?= e($badge[1]) ?></span>
                                    <span class="badge-soft muted"><?= e($course['category_name'] ?: 'Uncategorized') ?></span>
                                    <?php if ($course['expiry_date']): ?>
                                        <span class="badge-soft <?= strtotime((string) $course['expiry_date']) < time() ? 'danger' : 'muted' ?>">Expires <?= e(format_date($course['expiry_date'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <h2 class="h6 mb-1"><a class="text-strong" href="<?= e(url('admin/course-edit.php?id=' . (int) $course['id'])) ?>"><?= e($course['title']) ?></a></h2>
                                <div class="small-muted mb-2">Discovered <?= e(format_date($course['discovered_at'])) ?> · <?= e($course['instructor'] ?: 'Instructor not specified') ?></div>
                                <div class="small-muted">
                                    <strong class="text-strong">Reason:</strong>
                                    <?= e($course['verification_notes'] ?: 'No verification notes recorded.') ?>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mt-2 small">
                                    <?php if ($course['udemy_url']): ?>
                                        <a href="<?= e($course['udemy_url']) ?>" target="_blank" rel="noopener nofollow"><i data-lucide="external-link" style="width:13px;height:13px"></i> Udemy course</a>
                                    <?php endif; ?>
                                    <?php if ($course['coupon_url']): ?>
                                        <a href="<?= e($course['coupon_url']) ?>" target="_blank" rel="noopener nofollow"><i data-lucide="ticket" style="width:13px;height:13px"></i> Coupon link</a>
                                    <?php endif; ?>
                                    <?php foreach ($links as $link): ?>
                                        <a href="<?= e($link['source_course_url']) ?>" target="_blank" rel="noopener nofollow"><?= e($link['name']) ?> listing</a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 d-flex align-items-start justify-content-lg-end">
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="btn btn-sm btn-success-soft" type="submit"><i data-lucide="check"></i> Approve & publish</button>
                            </form>
                            <form method="post" class="m-0" data-confirm="Reject this course? It will not be published.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button class="btn btn-sm btn-danger-soft" type="submit"><i data-lucide="x"></i> Reject</button>
                            </form>
                            <form method="post" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                <input type="hidden" name="action" value="recheck">
                                <button class="btn btn-sm btn-outline-secondary" type="submit"><i data-lucide="refresh-cw"></i> Recheck</button>
                            </form>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/course-edit.php?id=' . (int) $course['id'])) ?>">Edit</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($pager['pages'] > 1): ?>
        <nav aria-label="Verification queue pagination">
            <ul class="pagination justify-content-center flex-wrap mt-4">
                <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                    <a class="page-link" href="<?= e(url('admin/verification.php' . query_string_with(['page' => $pager['current'] - 1]))) ?>">Previous</a>
                </li>
                <li class="page-item disabled"><span class="page-link">Page <?= $pager['current'] ?> of <?= $pager['pages'] ?></span></li>
                <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                    <a class="page-link" href="<?= e(url('admin/verification.php' . query_string_with(['page' => $pager['current'] + 1]))) ?>">Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php else: ?>
    <div class="panel">
        <div class="empty-state">
            <div class="icon"><i data-lucide="check-circle-2"></i></div>
            <h3>Queue is clear</h3>
            <p>No courses are waiting for review. Newly collected offers appear here automatically.</p>
            <a class="btn btn-primary" href="<?= e(url('admin/sources.php')) ?>">Run a collection</a>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
