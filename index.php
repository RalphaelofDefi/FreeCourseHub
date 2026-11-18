<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

$siteName = (string) setting('site_name', APP_NAME);
$totalCourses = (int) db()->query('SELECT COUNT(*) FROM courses c WHERE ' . availability_sql('c'))->fetchColumn();
$totalCategories = (int) db()->query("SELECT COUNT(*) FROM categories WHERE status = 'active'")->fetchColumn();
$totalUnlocks = (int) db()->query('SELECT COUNT(*) FROM course_unlocks')->fetchColumn();

$recentCourses = db()->query(
    'SELECT c.*, cat.name AS category_name FROM courses c
     LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE ' . availability_sql('c') . '
     ORDER BY c.discovered_at DESC LIMIT 8'
)->fetchAll();

$popularCourses = db()->query(
    'SELECT c.*, cat.name AS category_name FROM courses c
     LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE ' . availability_sql('c') . '
     ORDER BY COALESCE(c.rating, 0) DESC, COALESCE(c.student_count, 0) DESC, c.discovered_at DESC
     LIMIT 4'
)->fetchAll();

$categorySections = [];
foreach (get_categories() as $category) {
    if ((int) $category['course_count'] < 1 || count($categorySections) >= 6) {
        continue;
    }
    $stmt = db()->prepare(
        'SELECT c.*, cat.name AS category_name FROM courses c
         LEFT JOIN categories cat ON cat.id = c.category_id
         WHERE c.category_id = ? AND ' . availability_sql('c') . '
         ORDER BY c.discovered_at DESC LIMIT 4'
    );
    $stmt->execute([$category['id']]);
    $courses = $stmt->fetchAll();
    if ($courses !== []) {
        $categorySections[] = ['category' => $category, 'courses' => $courses];
    }
}

$pageTitle = 'Discover verified free Udemy courses';
$pageDescription = (string) setting('site_description', 'Browse free Udemy courses collected from trusted sources and verified automatically.');
$activeNav = 'home';
include __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="container">
        <span class="hero-badge"><i data-lucide="sparkles" style="width:15px;height:15px"></i> Fresh free courses, verified automatically</span>
        <h1>Learn something new with <span class="accent-text">free Udemy courses</span></h1>
        <p class="lead">We collect, verify and organize free Udemy course offers from trusted sources so you can enrol without guessing which coupons still work.</p>
        <form class="hero-search" action="<?= e(url('courses.php')) ?>" method="get">
            <i data-lucide="search" style="width:20px;height:20px;align-self:center;margin-left:10px;color:var(--muted)"></i>
            <input class="form-control" type="search" name="q" placeholder="What do you want to learn today?" aria-label="Search courses">
            <button class="btn btn-primary" type="submit">Search Courses</button>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="<?= e(url('courses.php')) ?>"><i data-lucide="compass"></i> Browse Courses</a>
            <a class="btn btn-outline-secondary" href="<?= e(url('register.php')) ?>"><i data-lucide="user-plus"></i> Create Free Account</a>
        </div>
        <div class="hero-stats">
            <div class="hero-stat"><strong><?= number_format($totalCourses) ?></strong><span>Verified courses</span></div>
            <div class="hero-stat"><strong><?= number_format($totalCategories) ?></strong><span>Categories</span></div>
            <div class="hero-stat"><strong><?= number_format($totalUnlocks) ?></strong><span>Courses unlocked</span></div>
        </div>
    </div>
</section>

<?php if ($recentCourses): ?>
<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Recently added</h2>
                <p>Newly discovered free course offers from our sources.</p>
            </div>
            <a class="section-link" href="<?= e(url('courses.php?sort=newest')) ?>">View all <i data-lucide="arrow-right" style="width:16px;height:16px"></i></a>
        </div>
        <div class="course-grid">
            <?php foreach ($recentCourses as $course): ?>
                <?php include __DIR__ . '/includes/course_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($popularCourses): ?>
<section class="section" style="background:var(--bg-soft)">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>Popular right now</h2>
                <p>Highest rated courses among our verified collection.</p>
            </div>
            <a class="section-link" href="<?= e(url('courses.php?sort=rating')) ?>">Top rated <i data-lucide="arrow-right" style="width:16px;height:16px"></i></a>
        </div>
        <div class="course-grid">
            <?php foreach ($popularCourses as $course): ?>
                <?php include __DIR__ . '/includes/course_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php foreach ($categorySections as $section): ?>
<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <h2><?= e($section['category']['name']) ?></h2>
                <p><?= e(excerpt($section['category']['description'] ?? '', 110)) ?></p>
            </div>
            <a class="section-link" href="<?= e(url('category.php?slug=' . urlencode((string) $section['category']['slug']))) ?>">Explore <?= e($section['category']['name']) ?> <i data-lucide="arrow-right" style="width:16px;height:16px"></i></a>
        </div>
        <div class="course-grid">
            <?php foreach ($section['courses'] as $course): ?>
                <?php include __DIR__ . '/includes/course_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endforeach; ?>

<?php if (!$recentCourses): ?>
<section class="section">
    <div class="container">
        <div class="empty-state panel">
            <div class="icon"><i data-lucide="inbox"></i></div>
            <h3>No courses published yet</h3>
            <p>Course collection may not have run yet. Administrators can start a manual collection from the dashboard.</p>
            <?php if (Auth::isAdmin()): ?>
                <a class="btn btn-primary" href="<?= e(url('admin/sources.php')) ?>">Open source management</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
