<?php
require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

$categories = get_categories();
$pageTitle = 'Course categories';
$pageDescription = 'Explore free Udemy courses by category.';
$activeNav = 'categories';
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <div class="breadcrumb-custom"><a href="<?= e(url()) ?>">Home</a><span>/</span><span>Categories</span></div>
        <h1>Browse by category</h1>
        <p class="mb-0">Find free courses in the subject area you care about.</p>
    </div>
</div>
<div class="container py-4">
    <?php if ($categories): ?>
        <div class="row g-3">
            <?php foreach ($categories as $category): ?>
                <div class="col-md-6 col-lg-4">
                    <a class="category-card" href="<?= e(url('category.php?slug=' . urlencode((string) $category['slug']))) ?>">
                        <span class="category-icon"><i data-lucide="<?= e(category_icon((string) $category['slug'])) ?>"></i></span>
                        <span>
                            <h3><?= e($category['name']) ?></h3>
                            <p><?= e(excerpt($category['description'] ?? '', 90)) ?></p>
                            <span class="badge-soft muted mt-2"><?= (int) $category['course_count'] ?> course<?= (int) $category['course_count'] === 1 ? '' : 's' ?></span>
                        </span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state panel">
            <div class="icon"><i data-lucide="layers"></i></div>
            <h3>No categories available</h3>
            <p>Categories will appear here once they are created by an administrator.</p>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
