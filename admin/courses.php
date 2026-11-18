<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$validVerification = ['pending', 'verified', 'questionable', 'expired', 'unavailable', 'failed'];
$validPublication = ['draft', 'published', 'unpublished', 'archived'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    if (!$course) {
        flash('error', 'Course not found.');
        redirect('admin/courses.php');
    }

    try {
        switch ($action) {
            case 'publish':
                if ($course['verification_status'] !== 'verified' && $course['verification_status'] !== 'questionable') {
                    flash('error', 'Only verified or reviewed courses can be published.');
                    break;
                }
                if (!StrictPriceValidator::storedPriceAllowsPublication($course)) {
                    flash('error', 'This strict $0.00 offer cannot be published until a free price is confirmed.');
                    break;
                }
                db()->prepare("UPDATE courses SET publication_status = 'published', updated_at = NOW() WHERE id = ?")->execute([$courseId]);
                log_activity((int) Auth::user()['id'], 'admin.course_published', 'Published course #' . $courseId);
                flash('success', 'Course published.');
                break;
            case 'unpublish':
                db()->prepare("UPDATE courses SET publication_status = 'unpublished', updated_at = NOW() WHERE id = ?")->execute([$courseId]);
                log_activity((int) Auth::user()['id'], 'admin.course_unpublished', 'Unpublished course #' . $courseId);
                flash('success', 'Course unpublished.');
                break;
            case 'approve':
                $gate = StrictPriceValidator::canManuallyPublish($course);
                if (!$gate['ok']) {
                    flash('error', (string) $gate['message']);
                    break;
                }
                db()->prepare("UPDATE courses SET verification_status = 'verified', publication_status = 'published', verification_notes = CONCAT(COALESCE(CONCAT(verification_notes, ' '), ''), 'Approved by administrator.'), last_verified_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$courseId]);
                db()->prepare("INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at) VALUES (?, 'verified', 'Approved by administrator.', NOW())")->execute([$courseId]);
                log_activity((int) Auth::user()['id'], 'admin.course_approved', 'Approved course #' . $courseId);
                flash('success', 'Course approved and published.');
                break;
            case 'reject':
                db()->prepare("UPDATE courses SET verification_status = 'failed', publication_status = 'unpublished', verification_notes = CONCAT(COALESCE(CONCAT(verification_notes, ' '), ''), 'Rejected by administrator.'), updated_at = NOW() WHERE id = ?")->execute([$courseId]);
                db()->prepare("INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at) VALUES (?, 'failed', 'Rejected by administrator.', NOW())")->execute([$courseId]);
                log_activity((int) Auth::user()['id'], 'admin.course_rejected', 'Rejected course #' . $courseId);
                flash('success', 'Course rejected and removed from public listings.');
                break;
            case 'expire':
                db()->prepare("UPDATE courses SET verification_status = 'expired', publication_status = IF(publication_status = 'published', 'unpublished', publication_status), expiry_date = COALESCE(expiry_date, NOW()), updated_at = NOW() WHERE id = ?")->execute([$courseId]);
                db()->prepare("INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at) VALUES (?, 'expired', 'Marked expired by administrator.', NOW())")->execute([$courseId]);
                log_activity((int) Auth::user()['id'], 'admin.course_expired', 'Expired course #' . $courseId);
                flash('success', 'Course marked expired.');
                break;
            case 'delete':
                $stmt = db()->prepare('SELECT (SELECT COUNT(*) FROM course_unlocks WHERE course_id = ?) + (SELECT COUNT(*) FROM payments WHERE course_id = ?)');
                $stmt->execute([$courseId, $courseId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    flash('error', 'This course has unlock or payment history and cannot be deleted. Unpublish it instead.');
                    break;
                }
                db()->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
                log_activity((int) Auth::user()['id'], 'admin.course_deleted', 'Deleted course #' . $courseId . ' (' . $course['title'] . ')');
                flash('success', 'Course deleted.');
                break;
            default:
                flash('error', 'Unknown course action.');
        }
    } catch (Throwable $e) {
        app_log('admin', 'Course action failed: ' . $e->getMessage(), ['course' => $courseId, 'action' => $action]);
        flash('error', 'The action could not be completed.');
    }
    redirect('admin/courses.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$sourceFilter = (int) ($_GET['source'] ?? 0);
$categoryFilter = (int) ($_GET['category'] ?? 0);
$verificationFilter = in_array($_GET['verification'] ?? '', $validVerification, true) ? (string) $_GET['verification'] : '';
$publicationFilter = in_array($_GET['publication'] ?? '', $validPublication, true) ? (string) $_GET['publication'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(c.title LIKE ? OR c.instructor LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($sourceFilter > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM course_source_links l WHERE l.course_id = c.id AND l.source_id = ?)';
    $params[] = $sourceFilter;
}
if ($categoryFilter > 0) {
    $where[] = 'c.category_id = ?';
    $params[] = $categoryFilter;
}
if ($verificationFilter !== '') {
    if ($verificationFilter === 'expired') {
        $where[] = "c.verification_status IN ('expired', 'unavailable')";
    } else {
        $where[] = 'c.verification_status = ?';
        $params[] = $verificationFilter;
    }
}
if ($publicationFilter !== '') {
    $where[] = 'c.publication_status = ?';
    $params[] = $publicationFilter;
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
     ORDER BY c.discovered_at DESC, c.id DESC
     LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$sourceRows = db()->query('SELECT id, name FROM course_sources ORDER BY name')->fetchAll();
$categoryRows = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$adminPage = 'courses';
$adminTitle = 'Course management';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <h1>Courses</h1>
        <p class="mb-0"><?= number_format($total) ?> course<?= $total === 1 ? '' : 's' ?> matching the current filters.</p>
    </div>
</div>

<div class="panel mb-4">
    <div class="panel-body">
        <form method="get" action="<?= e(url('admin/courses.php')) ?>" class="row g-2 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Title or instructor">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="source">Source</label>
                <select class="form-select" id="source" name="source">
                    <option value="">All sources</option>
                    <?php foreach ($sourceRows as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= $sourceFilter === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="category">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categoryRows as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= $categoryFilter === (int) $row['id'] ? 'selected' : '' ?>><?= e($row['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="verification">Verification</label>
                <select class="form-select" id="verification" name="verification">
                    <option value="">Any status</option>
                    <?php foreach (['pending', 'verified', 'questionable', 'expired', 'failed'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $verificationFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="publication">Publication</label>
                <select class="form-select" id="publication" name="publication">
                    <option value="">Any publication</option>
                    <?php foreach ($validPublication as $status): ?>
                        <option value="<?= e($status) ?>" <?= $publicationFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/courses.php')) ?>">Reset</a>
                <button class="btn btn-primary btn-sm" type="submit"><i data-lucide="filter"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-body p-0">
        <?php if ($courses): ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Category</th>
                            <th>Verification</th>
                            <th>Publication</th>
                            <th>Discovered</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($courses as $course): ?>
                        <?php $badge = verification_badge((string) $course['verification_status']); ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="mini-thumb d-inline-flex align-items-center justify-content-center text-strong"><?= e(initials((string) $course['title'])) ?></span>
                                    <div style="min-width:180px">
                                        <a class="text-strong" href="<?= e(url('admin/course-edit.php?id=' . (int) $course['id'])) ?>"><?= e(excerpt($course['title'], 62)) ?></a>
                                        <div class="small-muted"><?= e($course['instructor'] ?: 'Instructor not specified') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($course['category_name'] ?: 'Uncategorized') ?></td>
                            <td><span class="badge-soft <?= e($badge[0]) ?>"><?= e($badge[1]) ?></span></td>
                            <td><span class="badge-soft <?= $course['publication_status'] === 'published' ? 'success' : 'muted' ?>"><?= e(ucfirst((string) $course['publication_status'])) ?></span></td>
                            <td class="small-muted"><?= e(format_date($course['discovered_at'])) ?></td>
                            <td class="text-end">
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/course-edit.php?id=' . (int) $course['id'])) ?>">Edit</a>
                                    <?php if ($course['publication_status'] !== 'published'): ?>
                                        <form method="post" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                            <input type="hidden" name="action" value="publish">
                                            <button class="btn btn-sm btn-success-soft" type="submit">Publish</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                            <input type="hidden" name="action" value="unpublish">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Unpublish</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($course['verification_status'] === 'questionable'): ?>
                                        <form method="post" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button class="btn btn-sm btn-success-soft" type="submit">Approve</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="m-0" data-confirm="Delete this course permanently? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="btn btn-sm btn-danger-soft" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pager['pages'] > 1): ?>
                <div class="panel-body">
                    <nav aria-label="Course pagination">
                        <ul class="pagination justify-content-center flex-wrap mb-0">
                            <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                                <a class="page-link" href="<?= e(url('admin/courses.php' . query_string_with(['page' => $pager['current'] - 1]))) ?>">Previous</a>
                            </li>
                            <?php for ($i = max(1, $pager['current'] - 2); $i <= min($pager['pages'], $pager['current'] + 2); $i++): ?>
                                <li class="page-item <?= $i === $pager['current'] ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(url('admin/courses.php' . query_string_with(['page' => $i]))) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                                <a class="page-link" href="<?= e(url('admin/courses.php' . query_string_with(['page' => $pager['current'] + 1]))) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="icon"><i data-lucide="search-x"></i></div>
                <h3>No courses found</h3>
                <p>Adjust the filters or run a collection from source management.</p>
                <a class="btn btn-primary" href="<?= e(url('admin/sources.php')) ?>">Manage sources</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
