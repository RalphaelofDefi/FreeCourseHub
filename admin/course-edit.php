<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$courseId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) {
    flash('error', 'Course not found.');
    redirect('admin/courses.php');
}

$validVerification = ['pending', 'verified', 'questionable', 'expired', 'unavailable', 'failed'];
$validPublication = ['draft', 'published', 'unpublished', 'archived'];
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $thumbnail = trim((string) ($_POST['thumbnail_url'] ?? ''));
    $instructor = trim((string) ($_POST['instructor'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $ratingRaw = trim((string) ($_POST['rating'] ?? ''));
    $reviewCount = trim((string) ($_POST['review_count'] ?? ''));
    $studentCount = trim((string) ($_POST['student_count'] ?? ''));
    $language = trim((string) ($_POST['language'] ?? ''));
    $level = trim((string) ($_POST['level'] ?? ''));
    $duration = trim((string) ($_POST['duration'] ?? ''));
    $originalPrice = trim((string) ($_POST['original_price'] ?? ''));
    $couponPrice = trim((string) ($_POST['coupon_price'] ?? ''));
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'USD')));
    $udemyUrl = trim((string) ($_POST['udemy_url'] ?? ''));
    $couponUrl = trim((string) ($_POST['coupon_url'] ?? ''));
    $expiryInput = trim((string) ($_POST['expiry_date'] ?? ''));
    $verificationStatus = in_array($_POST['verification_status'] ?? '', $validVerification, true) ? (string) $_POST['verification_status'] : $course['verification_status'];
    $publicationStatus = in_array($_POST['publication_status'] ?? '', $validPublication, true) ? (string) $_POST['publication_status'] : $course['publication_status'];
    $notes = trim((string) ($_POST['verification_notes'] ?? ''));

    $errors = [];
    if (mb_strlen($title) < 3 || mb_strlen($title) > 255) {
        $errors[] = 'Title must be between 3 and 255 characters.';
    }
    if ($slug === '') {
        $slug = slugify($title);
    }
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
        $errors[] = 'Slug may only contain lowercase letters, numbers and hyphens.';
    }
    $stmt = db()->prepare('SELECT id FROM courses WHERE slug = ? AND id <> ? LIMIT 1');
    $stmt->execute([$slug, $courseId]);
    if ($stmt->fetchColumn()) {
        $errors[] = 'That slug is already used by another course.';
    }
    foreach (['thumbnail_url' => $thumbnail, 'udemy_url' => $udemyUrl, 'coupon_url' => $couponUrl] as $field => $urlValue) {
        if ($urlValue !== '' && !is_safe_url($urlValue)) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid http(s) URL.';
        }
    }
    if ($udemyUrl !== '' && canonical_udemy_key($udemyUrl) === null) {
        $errors[] = 'The Udemy URL must point to udemy.com/course/...';
    }
    if ($ratingRaw !== '' && (!is_numeric($ratingRaw) || (float) $ratingRaw < 0 || (float) $ratingRaw > 5)) {
        $errors[] = 'Rating must be between 0 and 5.';
    }
    if ($couponPrice !== '' && (!is_numeric($couponPrice) || (float) $couponPrice < 0)) {
        $errors[] = 'Coupon price must be a positive number or zero.';
    }
    if (course_has_strict_source($courseId) && ($couponPrice === '' || !is_numeric($couponPrice) || (float) $couponPrice > 0)) {
        $errors[] = 'This course comes from a strict $0.00 source. The coupon price must be confirmed as $0.00 before it can be saved or published.';
    }
    if ($originalPrice !== '' && (!is_numeric($originalPrice) || (float) $originalPrice < 0)) {
        $errors[] = 'Original price must be a positive number.';
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Currency must be a 3-letter code.';
    }
    $expiryDate = null;
    if ($expiryInput !== '') {
        $timestamp = strtotime($expiryInput);
        if ($timestamp === false) {
            $errors[] = 'Expiry date is not valid.';
        } else {
            $expiryDate = date('Y-m-d H:i:s', $timestamp);
        }
    }

    if ($errors === []) {
        $canonicalKey = $udemyUrl !== '' ? canonical_udemy_key($udemyUrl) : $course['canonical_key'];
        try {
            $stmt = db()->prepare(
                'UPDATE courses SET
                    title = ?, slug = ?, canonical_key = ?, description = ?, thumbnail_url = ?, instructor = ?,
                    category_id = ?, rating = ?, review_count = ?, student_count = ?, language = ?, level = ?, duration = ?,
                    original_price = ?, coupon_price = ?, currency = ?, udemy_url = ?, coupon_url = ?, expiry_date = ?,
                    verification_status = ?, publication_status = ?, verification_notes = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $title,
                $slug,
                $canonicalKey,
                $description !== '' ? $description : null,
                $thumbnail !== '' ? $thumbnail : null,
                $instructor !== '' ? $instructor : null,
                $categoryId > 0 ? $categoryId : null,
                $ratingRaw !== '' ? round((float) $ratingRaw, 2) : null,
                $reviewCount !== '' ? max(0, (int) $reviewCount) : null,
                $studentCount !== '' ? max(0, (int) $studentCount) : null,
                $language !== '' ? $language : null,
                $level !== '' ? $level : null,
                $duration !== '' ? $duration : null,
                $originalPrice !== '' ? round((float) $originalPrice, 2) : null,
                $couponPrice !== '' ? round((float) $couponPrice, 2) : null,
                $currency,
                $udemyUrl !== '' ? $udemyUrl : null,
                $couponUrl !== '' ? $couponUrl : null,
                $expiryDate,
                $verificationStatus,
                $publicationStatus,
                $notes !== '' ? $notes : null,
                $courseId,
            ]);
            log_activity((int) Auth::user()['id'], 'admin.course_edited', 'Edited course #' . $courseId . ' (' . $title . ')');
            flash('success', 'Course updated.');
            redirect('admin/course-edit.php?id=' . $courseId);
        } catch (PDOException $e) {
            app_log('admin', 'Course update failed: ' . $e->getMessage(), ['course' => $courseId]);
            $errors[] = (string) $e->getCode() === '23000'
                ? 'Another course already uses that Udemy URL or slug.'
                : 'The course could not be saved.';
        }
    }

    flash('error', implode(' ', $errors));
    old_inputs($_POST);
    redirect('admin/course-edit.php?id=' . $courseId);
}

$sourceLinks = db()->prepare(
    'SELECT l.*, s.name AS source_name FROM course_source_links l
     JOIN course_sources s ON s.id = l.source_id WHERE l.course_id = ? ORDER BY l.discovered_at ASC'
);
$sourceLinks->execute([$courseId]);
$sourceLinks = $sourceLinks->fetchAll();

$logs = db()->prepare('SELECT * FROM verification_logs WHERE course_id = ? ORDER BY checked_at DESC LIMIT 15');
$logs->execute([$courseId]);
$logs = $logs->fetchAll();

$expiryValue = !empty($course['expiry_date']) ? date('Y-m-d\TH:i', strtotime((string) $course['expiry_date'])) : '';

$adminPage = 'courses';
$adminTitle = 'Edit course';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <div class="breadcrumb-custom mb-1"><a href="<?= e(url('admin/courses.php')) ?>">Courses</a><span>/</span><span>Edit</span></div>
        <h1 class="mb-1"><?= e(excerpt($course['title'], 80)) ?></h1>
        <p class="mb-0">Course #<?= (int) $course['id'] ?> · discovered <?= e(format_date($course['discovered_at'])) ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (course_is_available($course)): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('course.php?slug=' . urlencode((string) $course['slug']))) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i> View public page</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('admin/courses.php')) ?>">Back to courses</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <form method="post" action="<?= e(url('admin/course-edit.php?id=' . $courseId)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $courseId ?>">

            <div class="panel mb-4">
                <div class="panel-head"><h2>Course information</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="title">Title</label>
                            <input class="form-control" id="title" type="text" name="title" value="<?= e(old('title', (string) $course['title'])) ?>" required maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="slug">Slug</label>
                            <input class="form-control" id="slug" type="text" name="slug" value="<?= e(old('slug', (string) $course['slug'])) ?>" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="category_id">Category</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="0">Uncategorized</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>" <?= (int) old('category_id', (string) $course['category_id']) === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="5"><?= e(old('description', (string) $course['description'])) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="instructor">Instructor</label>
                            <input class="form-control" id="instructor" type="text" name="instructor" value="<?= e(old('instructor', (string) $course['instructor'])) ?>" maxlength="190">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="thumbnail_url">Thumbnail URL</label>
                            <input class="form-control" id="thumbnail_url" type="url" name="thumbnail_url" value="<?= e(old('thumbnail_url', (string) $course['thumbnail_url'])) ?>" maxlength="600">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="udemy_url">Udemy course URL</label>
                            <input class="form-control" id="udemy_url" type="url" name="udemy_url" value="<?= e(old('udemy_url', (string) $course['udemy_url'])) ?>" maxlength="600">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coupon_url">Coupon / enrollment URL</label>
                            <input class="form-control" id="coupon_url" type="url" name="coupon_url" value="<?= e(old('coupon_url', (string) $course['coupon_url'])) ?>" maxlength="600">
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head"><h2>Course metadata</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="rating">Rating (0–5)</label>
                            <input class="form-control" id="rating" type="number" step="0.01" min="0" max="5" name="rating" value="<?= e(old('rating', (string) $course['rating'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="review_count">Review count</label>
                            <input class="form-control" id="review_count" type="number" min="0" name="review_count" value="<?= e(old('review_count', (string) $course['review_count'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="student_count">Student count</label>
                            <input class="form-control" id="student_count" type="number" min="0" name="student_count" value="<?= e(old('student_count', (string) $course['student_count'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="language">Language</label>
                            <input class="form-control" id="language" type="text" name="language" value="<?= e(old('language', (string) $course['language'])) ?>" maxlength="80">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="level">Level</label>
                            <select class="form-select" id="level" name="level">
                                <?php $currentLevel = (string) old('level', (string) $course['level']); ?>
                                <?php foreach (['' => 'Not specified', 'All Levels' => 'All Levels', 'Beginner' => 'Beginner', 'Intermediate' => 'Intermediate', 'Expert' => 'Expert'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $currentLevel === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="duration">Duration</label>
                            <input class="form-control" id="duration" type="text" name="duration" value="<?= e(old('duration', (string) $course['duration'])) ?>" maxlength="80" placeholder="e.g. 4 hours">
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head"><h2>Pricing and availability</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="original_price">Original price</label>
                            <input class="form-control" id="original_price" type="number" step="0.01" min="0" name="original_price" value="<?= e(old('original_price', (string) $course['original_price'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="coupon_price">Coupon price</label>
                            <input class="form-control" id="coupon_price" type="number" step="0.01" min="0" name="coupon_price" value="<?= e(old('coupon_price', (string) $course['coupon_price'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="currency">Currency</label>
                            <input class="form-control" id="currency" type="text" name="currency" value="<?= e(old('currency', (string) $course['currency'])) ?>" maxlength="3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="expiry_date">Coupon expiry</label>
                            <input class="form-control" id="expiry_date" type="datetime-local" name="expiry_date" value="<?= e(old('expiry_date', $expiryValue)) ?>">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="small-muted">Leave expiry empty when the source does not state one. Never invent an expiry date.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head"><h2>Verification and publication</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="verification_status">Verification status</label>
                            <select class="form-select" id="verification_status" name="verification_status">
                                <?php foreach ($validVerification as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $course['verification_status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="publication_status">Publication status</label>
                            <select class="form-select" id="publication_status" name="publication_status">
                                <?php foreach ($validPublication as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $course['publication_status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="verification_notes">Verification notes</label>
                            <textarea class="form-control" id="verification_notes" name="verification_notes" rows="3"><?= e(old('verification_notes', (string) $course['verification_notes'])) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i data-lucide="save"></i> Save course</button>
                <a class="btn btn-outline-secondary" href="<?= e(url('admin/courses.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="panel mb-4">
            <div class="panel-head"><h2>Source links</h2></div>
            <div class="panel-body">
                <?php if ($sourceLinks): ?>
                    <ul class="detail-list mb-0">
                        <?php foreach ($sourceLinks as $link): ?>
                            <li>
                                <span class="label"><?= e($link['source_name']) ?></span>
                                <span class="value"><a href="<?= e($link['source_course_url']) ?>" target="_blank" rel="noopener nofollow">View original</a></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small-muted mb-0">No source associations recorded.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Verification history</h2></div>
            <div class="panel-body p-0">
                <?php if ($logs): ?>
                    <div class="table-responsive">
                        <table class="table-custom">
                            <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php $badge = verification_badge((string) $log['verification_status']); ?>
                                <tr>
                                    <td>
                                        <span class="badge-soft <?= e($badge[0]) ?>"><?= e($badge[1]) ?></span>
                                        <div class="small-muted mt-1"><?= e(format_datetime($log['checked_at'])) ?></div>
                                        <?php if (!empty($log['verification_details'])): ?>
                                            <div class="small-muted mt-1"><?= e(excerpt($log['verification_details'], 140)) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="panel-body small-muted">No verification runs recorded yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php clear_old_inputs(); require __DIR__ . '/includes/footer.php'; ?>
