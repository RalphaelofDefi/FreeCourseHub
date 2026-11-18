<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

function clean_get(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$q = clean_get('q');
$categorySlug = clean_get('category');
$level = clean_get('level');
$language = clean_get('language');
$sort = clean_get('sort');
$sort = in_array($sort, ['newest', 'rating', 'popular'], true) ? $sort : 'newest';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$where = [availability_sql('c')];
$params = [];

if ($q !== '') {
    $where[] = '(c.title LIKE ? OR c.description LIKE ? OR c.instructor LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($categorySlug !== '') {
    $where[] = 'cat.slug = ?';
    $params[] = $categorySlug;
}
if ($level !== '') {
    $where[] = 'c.level = ?';
    $params[] = $level;
}
if ($language !== '') {
    $where[] = 'c.language = ?';
    $params[] = $language;
}

$whereSql = implode(' AND ', $where);
$orderSql = match ($sort) {
    'rating' => 'COALESCE(c.rating, 0) DESC, COALESCE(c.student_count, 0) DESC, c.discovered_at DESC',
    'popular' => 'COALESCE(c.student_count, 0) DESC, COALESCE(c.rating, 0) DESC, c.discovered_at DESC',
    default => 'c.discovered_at DESC, c.id DESC',
};

$countStmt = db()->prepare('SELECT COUNT(*) FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id WHERE ' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pager = pagination($total, $perPage, $page);

$stmt = db()->prepare(
    'SELECT c.*, cat.name AS category_name, cat.slug AS category_slug
     FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE ' . $whereSql . '
     ORDER BY ' . $orderSql . '
     LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$levels = db()->query("SELECT DISTINCT level FROM courses c WHERE level IS NOT NULL AND level <> '' AND " . availability_sql('c') . ' ORDER BY level')->fetchAll(PDO::FETCH_COLUMN);
$languages = db()->query("SELECT DISTINCT language FROM courses c WHERE language IS NOT NULL AND language <> '' AND " . availability_sql('c') . ' ORDER BY language')->fetchAll(PDO::FETCH_COLUMN);
$categories = get_categories();

$pageTitle = 'Browse free courses';
$pageDescription = 'Search and filter all published free Udemy courses.';
$activeNav = 'courses';
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <div class="breadcrumb-custom"><a href="<?= e(url()) ?>">Home</a><span>/</span><span>Browse courses</span></div>
        <h1>Browse courses</h1>
        <p class="mb-0"><?= number_format($total) ?> verified course<?= $total === 1 ? '' : 's' ?> available<?= $q !== '' ? ' for "' . e($q) . '"' : '' ?>.</p>
    </div>
</div>

<div class="container py-4">
    <form class="filter-bar mb-4" method="get" action="<?= e(url('courses.php')) ?>">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4 col-md-6">
                <label class="form-label" for="filterQ">Search</label>
                <input class="form-control" id="filterQ" type="search" name="q" value="<?= e($q) ?>" placeholder="Course title, instructor...">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="filterCategory">Category</label>
                <select class="form-select" id="filterCategory" name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category['slug']) ?>" <?= $categorySlug === $category['slug'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filterLevel">Level</label>
                <select class="form-select" id="filterLevel" name="level">
                    <option value="">All levels</option>
                    <?php foreach ($levels as $item): ?>
                        <option value="<?= e($item) ?>" <?= $level === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filterLanguage">Language</label>
                <select class="form-select" id="filterLanguage" name="language">
                    <option value="">All languages</option>
                    <?php foreach ($languages as $item): ?>
                        <option value="<?= e($item) ?>" <?= $language === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label" for="filterSort">Sort by</label>
                <select class="form-select" id="filterSort" name="sort">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                    <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Highest rated</option>
                    <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most popular</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 justify-content-end mt-3">
                <a class="btn btn-ghost btn-sm" href="<?= e(url('courses.php')) ?>">Reset</a>
                <button class="btn btn-primary btn-sm" type="submit"><i data-lucide="filter"></i> Apply filters</button>
            </div>
        </div>
    </form>

    <?php if ($courses): ?>
        <div class="course-grid">
            <?php foreach ($courses as $course): ?>
                <?php include __DIR__ . '/includes/course_card.php'; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($pager['pages'] > 1): ?>
            <nav class="mt-4" aria-label="Course pagination">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                        <a class="page-link" href="<?= e(url('courses.php' . query_string_with(['page' => $pager['current'] - 1]))) ?>">Previous</a>
                    </li>
                    <?php
                    $start = max(1, $pager['current'] - 2);
                    $end = min($pager['pages'], $pager['current'] + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= $i === $pager['current'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('courses.php' . query_string_with(['page' => $i]))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                        <a class="page-link" href="<?= e(url('courses.php' . query_string_with(['page' => $pager['current'] + 1]))) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state panel">
            <div class="icon"><i data-lucide="search-x"></i></div>
            <h3>No courses match your filters</h3>
            <p>Try a different keyword or clear the filters to see everything available.</p>
            <a class="btn btn-primary" href="<?= e(url('courses.php')) ?>">Clear filters</a>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
