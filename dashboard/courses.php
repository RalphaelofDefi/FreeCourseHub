<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireLogin('dashboard/courses.php');

$user = Auth::user();
$stmt = db()->prepare(
    'SELECT u.id AS unlock_id, u.unlock_type, u.unlocked_at, c.*, cat.name AS category_name
     FROM course_unlocks u
     JOIN courses c ON c.id = u.course_id
     LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE u.user_id = ?
     ORDER BY u.unlocked_at DESC'
);
$stmt->execute([$user['id']]);
$unlocks = $stmt->fetchAll();

$pageTitle = 'My courses';
$activeNav = '';
include __DIR__ . '/../includes/header.php';
?>
<div class="container dashboard-shell">
    <div class="dashboard-head">
        <div>
            <h1>My courses</h1>
            <p class="mb-0">Every course you have unlocked stays available here, even if its offer later expires.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= e(url('courses.php')) ?>"><i data-lucide="search"></i> Find more courses</a>
    </div>

    <?php $activeTab = 'courses'; include __DIR__ . '/includes/nav.php'; ?>

    <?php if ($unlocks): ?>
        <div class="panel">
            <div class="panel-head">
                <h2><?= number_format(count($unlocks)) ?> unlocked course<?= count($unlocks) === 1 ? '' : 's' ?></h2>
            </div>
            <div class="panel-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Category</th>
                                <th>Unlock type</th>
                                <th>Unlocked</th>
                                <th>Offer</th>
                                <th class="text-end">Access</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unlocks as $course): ?>
                            <?php $available = course_is_available($course); ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="mini-thumb d-inline-flex align-items-center justify-content-center text-strong"><?= e(initials((string) $course['title'])) ?></span>
                                        <div style="min-width:180px">
                                            <a class="text-strong" href="<?= e(url('course.php?slug=' . urlencode((string) $course['slug']))) ?>"><?= e(excerpt($course['title'], 60)) ?></a>
                                            <div class="small-muted"><?= e($course['instructor'] ?: 'Instructor not specified') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= e($course['category_name'] ?: 'Uncategorized') ?></td>
                                <td><span class="badge-soft <?= $course['unlock_type'] === 'paid' ? 'accent' : 'muted' ?>"><?= e(ucfirst((string) $course['unlock_type'])) ?></span></td>
                                <td class="small-muted"><?= e(format_date($course['unlocked_at'])) ?></td>
                                <td>
                                    <?php if ($available): ?>
                                        <span class="badge-soft success">Available</span>
                                    <?php else: ?>
                                        <span class="badge-soft danger"><?= e(ucfirst((string) $course['verification_status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($available): ?>
                                        <a class="btn btn-sm btn-primary" href="<?= e(url('access-course.php?id=' . (int) $course['id'])) ?>"><i data-lucide="external-link"></i> Access</a>
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('course.php?slug=' . urlencode((string) $course['slug']))) ?>">Details</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="empty-state">
                <div class="icon"><i data-lucide="bookmark"></i></div>
                <h3>No unlocked courses yet</h3>
                <p>Unlock your first verified free course and it will appear here permanently.</p>
                <a class="btn btn-primary" href="<?= e(url('courses.php')) ?>">Browse courses</a>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
