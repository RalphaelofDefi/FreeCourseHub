<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

$identifier = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : (is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
$course = $identifier !== '' ? find_course($identifier) : null;
if (!$course) {
    http_response_code(404);
    $pageTitle = 'Course not found';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5"><div class="empty-state panel"><div class="icon"><i data-lucide="search-x"></i></div><h3>Course not found</h3><p>The course you are looking for does not exist or has been removed.</p><a class="btn btn-primary" href="' . e(url('courses.php')) . '">Browse courses</a></div></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$available = course_is_available($course);
$currentUser = Auth::user();
$unlocked = false;
if ($currentUser) {
    $stmt = db()->prepare('SELECT id FROM course_unlocks WHERE user_id = ? AND course_id = ? LIMIT 1');
    $stmt->execute([$currentUser['id'], $course['id']]);
    $unlocked = (bool) $stmt->fetchColumn();
}
$freeRemaining = $currentUser ? (int) $currentUser['free_unlocks_remaining'] : 0;
$freeEnabled = setting_bool('free_access_enabled', true);
$paidEnabled = setting_bool('paid_access_enabled', true);
$platformFree = platform_is_free();
$unlockPrice = (float) setting('unlock_price', 0);
$currency = (string) setting('default_currency', 'NGN');
$hasRating = (float) ($course['rating'] ?? 0) > 0;

$displayTitle = course_display_title((string) $course['title']);
$instructor = instructor_display_name($course['instructor'] ?? null);
$pageTitle = $displayTitle;
$pageDescription = excerpt((string) ($course['description'] ?? $course['title']), 180);
$activeNav = 'courses';
include __DIR__ . '/includes/header.php';
?>
<section class="course-detail-hero">
    <div class="container">
        <div class="breadcrumb-custom">
            <a href="<?= e(url()) ?>">Home</a><span>/</span>
            <a href="<?= e(url('courses.php')) ?>">Courses</a><span>/</span>
            <?php if (!empty($course['category_name'])): ?>
                <a href="<?= e(url('category.php?slug=' . urlencode((string) $course['category_slug']))) ?>"><?= e($course['category_name']) ?></a><span>/</span>
            <?php endif; ?>
            <span><?= e(excerpt($displayTitle, 60)) ?></span>
        </div>
        <div class="row g-4 align-items-center">
            <div class="col-lg-7 course-detail">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if (!empty($course['category_name'])): ?>
                        <span class="badge-soft accent"><?= e($course['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($available): ?>
                        <span class="badge-soft success">Available</span>
                    <?php else: ?>
                        <span class="badge-soft danger"><?= e(ucfirst((string) $course['verification_status'])) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($course['level'])): ?>
                        <span class="badge-soft muted"><?= e($course['level']) ?></span>
                    <?php endif; ?>
                </div>
                <h1><span class="discount-tag">[100% Off]</span> <?= e($displayTitle) ?></h1>
                <div class="detail-stats mb-3">
                    <?php if ($instructor !== null): ?>
                        <span><i data-lucide="user" style="width:15px;height:15px"></i> <?= e($instructor) ?></span>
                    <?php endif; ?>
                    <?php if ($hasRating): ?>
                        <span class="course-rating"><i data-lucide="star" style="width:15px;height:15px;fill:currentColor"></i> <?= e(number_format((float) $course['rating'], 1)) ?><?= !empty($course['review_count']) ? ' (' . number_format((int) $course['review_count']) . ' reviews)' : '' ?></span>
                    <?php endif; ?>
                    <?php if (!empty($course['language'])): ?>
                        <span><i data-lucide="languages" style="width:15px;height:15px"></i> <?= e($course['language']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($course['duration'])): ?>
                        <span><i data-lucide="clock" style="width:15px;height:15px"></i> <?= e($course['duration']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="course-detail-thumb" style="position:relative">
                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:2.2rem;font-weight:800;color:var(--accent)"><?= e(initials((string) $course['title'])) ?></div>
                    <?php if (!empty($course['thumbnail_url'])): ?>
                        <img src="<?= e($course['thumbnail_url']) ?>" alt="<?= e($course['title']) ?>" onerror="this.remove()" style="position:relative;z-index:1">
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="unlock-box">
                    <div class="d-flex align-items-baseline gap-2 mb-1">
                        <span class="price">FREE</span>
                        <?php if (!empty($course['original_price']) && (float) $course['original_price'] > 0): ?>
                            <span class="course-old-price" style="font-size:0.9rem"><?= e(format_money((float) $course['original_price'], $course['currency'] ?? null)) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="mb-3">Enrollment link verified on <?= e(format_date($course['last_verified_at'] ?? $course['discovered_at'] ?? null)) ?>.</p>

                    <?php if (!$available): ?>
                        <div class="alert alert-warning mb-3"><i data-lucide="alert-triangle" style="width:16px;height:16px"></i> This offer is no longer available. Its record is preserved for your history.</div>
                        <a class="btn btn-outline-secondary w-100 justify-content-center" href="<?= e(url('courses.php')) ?>">Browse other courses</a>
                    <?php elseif ($unlocked): ?>
                        <div class="alert alert-success mb-3"><i data-lucide="check-circle-2" style="width:16px;height:16px"></i> You have unlocked this course. Access is available any time while the offer remains live.</div>
                        <a class="btn btn-primary btn-lg w-100 justify-content-center" href="<?= e(url('access-course.php?id=' . (int) $course['id'])) ?>"><i data-lucide="external-link"></i> Access Course</a>
                    <?php elseif (!$currentUser): ?>
                        <div class="alert alert-info mb-3"><i data-lucide="lock" style="width:16px;height:16px"></i> Create a free account to unlock this course.</div>
                        <a class="btn btn-primary btn-lg w-100 justify-content-center mb-2" href="<?= e(url('login.php?redirect=' . urlencode('course.php?slug=' . $course['slug']))) ?>">Login to Unlock</a>
                        <a class="btn btn-outline-secondary w-100 justify-content-center" href="<?= e(url('register.php')) ?>">Create Free Account</a>
                    <?php elseif ($platformFree): ?>
                        <div class="alert alert-success mb-3"><i data-lucide="gift" style="width:16px;height:16px"></i> This platform is currently in free mode. Every course is free to unlock for members.</div>
                        <button class="btn btn-primary btn-lg w-100 justify-content-center" type="button" data-unlock-course="<?= (int) $course['id'] ?>"><i data-lucide="unlock"></i> Unlock Course</button>
                    <?php elseif ($freeEnabled && $freeRemaining > 0): ?>
                        <div class="progress-thin mb-2"><div class="bar" style="width: <?= e((string) max(0, min(100, ($freeRemaining / max(1, (int) setting('free_unlock_allowance', 5))) * 100))) ?>%"></div></div>
                        <p class="small-muted mb-3">You have <strong class="text-strong"><?= $freeRemaining ?></strong> free unlock<?= $freeRemaining === 1 ? '' : 's' ?> remaining.</p>
                        <button class="btn btn-primary btn-lg w-100 justify-content-center" type="button" data-unlock-course="<?= (int) $course['id'] ?>"><i data-lucide="unlock"></i> Unlock Course</button>
                    <?php elseif ($paidEnabled): ?>
                        <div class="alert alert-warning mb-3"><i data-lucide="info" style="width:16px;height:16px"></i> Your free unlocks are exhausted. Unlock this course for <strong><?= e(format_money($unlockPrice, $currency)) ?></strong>.</div>
                        <button class="btn btn-primary btn-lg w-100 justify-content-center" type="button" data-unlock-course="<?= (int) $course['id'] ?>"><i data-lucide="credit-card"></i> Unlock for <?= e(format_money($unlockPrice, $currency)) ?></button>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0"><i data-lucide="alert-triangle" style="width:16px;height:16px"></i> Free unlocks are exhausted and paid unlocking is currently disabled. Please check back later.</div>
                    <?php endif; ?>

                    <div class="divider"></div>
                    <ul class="detail-list">
                        <li><span class="label">Level</span><span class="value"><?= e($course['level'] ?: 'Not specified') ?></span></li>
                        <li><span class="label">Language</span><span class="value"><?= e($course['language'] ?: 'Not specified') ?></span></li>
                        <li><span class="label">Students</span><span class="value"><?= $course['student_count'] !== null ? e(number_format((int) $course['student_count'])) : '—' ?></span></li>
                        <li><span class="label">Last verified</span><span class="value"><?= e(format_date($course['last_verified_at'] ?? null)) ?></span></li>
                        <li><span class="label">Offer expires</span><span class="value"><?= e($course['expiry_date'] ? format_date($course['expiry_date']) : 'Not specified') ?></span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="detail-panel">
                <h2 class="h5 mb-3">About this course</h2>
                <?php if (!empty($course['description'])): ?>
                    <p style="color:var(--text)"><?= nl2br(e($course['description'])) ?></p>
                <?php else: ?>
                    <p class="mb-0">No description was supplied by the source. Course information is shown exactly as collected.</p>
                <?php endif; ?>
            </div>
            <div class="detail-panel">
                <h2 class="h5 mb-3">Course details</h2>
                <div class="row g-3">
                    <div class="col-sm-6"><div class="small-muted">Instructor</div><div class="text-strong"><?= e($instructor ?? 'Not specified') ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Category</div><div class="text-strong"><?= e($course['category_name'] ?: 'Uncategorized') ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Course level</div><div class="text-strong"><?= e($course['level'] ?: 'Not specified') ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Language</div><div class="text-strong"><?= e($course['language'] ?: 'Not specified') ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Duration</div><div class="text-strong"><?= e($course['duration'] ?: 'Not specified') ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Original price</div><div class="text-strong"><?= !empty($course['original_price']) ? e(format_money((float) $course['original_price'], $course['currency'] ?? null)) : 'Not specified' ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Coupon price</div><div class="text-strong"><?= $course['coupon_price'] !== null ? e(format_money((float) $course['coupon_price'], $course['currency'] ?? null)) : 'Free' ?></div></div>
                    <div class="col-sm-6"><div class="small-muted">Discovered</div><div class="text-strong"><?= e(format_date($course['discovered_at'] ?? null)) ?></div></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="detail-panel">
                <h2 class="h5 mb-3">How unlocking works</h2>
                <?php if ($platformFree): ?>
                    <ul class="detail-list">
                        <li><span class="label">Platform mode</span><span class="value">Free</span></li>
                        <li><span class="label">Unlocks</span><span class="value">Unlimited</span></li>
                    </ul>
                    <p class="small-muted mt-3 mb-0">All available courses can be unlocked at no cost while the platform is in free mode. Unlocks remain linked to your account while the offer stays available.</p>
                <?php else: ?>
                    <ul class="detail-list">
                        <li><span class="label">Free allowance</span><span class="value"><?= e((string) setting('free_unlock_allowance', 5)) ?> unlocks</span></li>
                        <li><span class="label">Additional unlock</span><span class="value"><?= e(format_money($unlockPrice, $currency)) ?></span></li>
                        <li><span class="label">Payment provider</span><span class="value">Paystack</span></li>
                    </ul>
                    <p class="small-muted mt-3 mb-0">Unlocks are permanent for your account while the offer stays available. Payments are verified server-side before access is granted.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
