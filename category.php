<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

$slug = is_string($_GET['slug'] ?? null) ? trim($_GET['slug']) : '';
$stmt = db()->prepare("SELECT * FROM categories WHERE slug = ? AND status = 'active' LIMIT 1");
$stmt->execute([$slug]);
$category = $stmt->fetch();

if (!$category) {
    http_response_code(404);
    $pageTitle = 'Category not found';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-5"><div class="empty-state panel"><div class="icon"><i data-lucide="layers"></i></div><h3>Category not found</h3><p>This category does not exist or is no longer active.</p><a class="btn btn-primary" href="' . e(url('categories.php')) . '">View categories</a></div></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$params = [$category['id']];
$countStmt = db()->prepare('SELECT COUNT(*) FROM courses c WHERE c.category_id = ? AND ' . availability_sql('c'));
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pager = pagination($total, $perPage, $page);

$stmt = db()->prepare(
    'SELECT c.*, cat.name AS category_name, cat.slug AS category_slug
     FROM courses c LEFT JOIN categories cat ON cat.id = c.category_id
     WHERE c.category_id = ? AND ' . availability_sql('c') . '
     ORDER BY c.discovered_at DESC LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$pageTitle = (string) $category['name'];
$pageDescription = $category['description'] ?: ('Free Udemy courses in ' . $category['name']);
$activeNav = 'categories';
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <div class="breadcrumb-custom"><a href="<?= e(url()) ?>">Home</a><span>/</span><a href="<?= e(url('categories.php')) ?>">Categories</a><span>/</span><span><?= e($category['name']) ?></span></div>
        <div class="d-flex align-items-center gap-3">
            <span class="category-icon"><i data-lucide="<?= e(category_icon((string) $category['slug'])) ?>"></i></span>
            <div>
                <h1 class="mb-1"><?= e($category['name']) ?></h1>
                <p class="mb-0"><?= e($category['description'] ?: 'Free courses in this category.') ?></p>
            </div>
        </div>
    </div>
</div>
<div class="container py-4">
    <p class="small-muted"><?= number_format($total) ?> available course<?= $total === 1 ? '' : 's' ?> in <?= e($category['name']) ?>.</p>
    <?php if ($courses): ?>
        <div class="course-grid">
            <?php foreach ($courses as $course): ?>
                <?php include __DIR__ . '/includes/course_card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php if ($pager['pages'] > 1): ?>
            <nav class="mt-4" aria-label="Category pagination">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?= $pager['has_prev'] ? '' : 'disabled' ?>">
                        <a class="page-link" href="<?= e(url('category.php' . query_string_with(['slug' => $category['slug'], 'page' => $pager['current'] - 1]))) ?>">Previous</a>
                    </li>
                    <?php
                    $start = max(1, $pager['current'] - 2);
                    $end = min($pager['pages'], $pager['current'] + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?= $i === $pager['current'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('category.php' . query_string_with(['slug' => $category['slug'], 'page' => $i]))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pager['has_next'] ? '' : 'disabled' ?>">
                        <a class="page-link" href="<?= e(url('category.php' . query_string_with(['slug' => $category['slug'], 'page' => $pager['current'] + 1]))) ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state panel">
            <div class="icon"><i data-lucide="inbox"></i></div>
            <h3>No courses in this category yet</h3>
            <p>Check back soon or explore other categories.</p>
            <a class="btn btn-primary" href="<?= e(url('categories.php')) ?>">Browse categories</a>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
