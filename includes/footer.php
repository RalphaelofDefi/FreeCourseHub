<?php
$siteName = $siteName ?? (string) setting('site_name', APP_NAME);
$siteLogo = $siteLogo ?? trim((string) setting('site_logo', ''));
$siteDescription = (string) setting('site_description', 'Discover verified free Udemy courses curated from trusted sources.');
$footerCategories = array_slice(get_categories(), 0, 6);
$contactEmail = (string) setting('contact_email', '');
$footerUser = Auth::user();
?>
</main>
<footer class="site-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <a class="navbar-brand d-inline-flex align-items-center gap-2 mb-2" href="<?= e(url()) ?>">
                    <?php if ($siteLogo !== '' && is_safe_url($siteLogo)): ?>
                        <img class="brand-logo" src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>" onerror="this.remove()">
                    <?php else: ?>
                        <span class="brand-mark"><i data-lucide="book-open-check"></i></span>
                    <?php endif; ?>
                    <span class="fw-bold text-strong"><?= e($siteName) ?></span>
                </a>
                <p class="mt-2 mb-3" style="max-width:340px"><?= e(excerpt($siteDescription, 160)) ?></p>
                <?php if (setting_bool('allow_user_theme_switch', true)): ?>
                    <button class="btn btn-outline-secondary btn-sm" id="themeToggle" type="button">
                        <i data-lucide="moon" data-theme-icon></i> Toggle theme
                    </button>
                <?php endif; ?>
            </div>
            <div class="col-6 col-lg-2">
                <h5>Explore</h5>
                <div class="d-flex flex-column">
                    <a href="<?= e(url('courses.php')) ?>">All courses</a>
                    <a href="<?= e(url('courses.php?sort=newest')) ?>">Newest courses</a>
                    <a href="<?= e(url('courses.php?sort=rating')) ?>">Top rated</a>
                    <a href="<?= e(url('categories.php')) ?>">Categories</a>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <h5>Categories</h5>
                <div class="d-flex flex-column">
                    <?php foreach ($footerCategories as $category): ?>
                        <a href="<?= e(url('category.php?slug=' . urlencode((string) $category['slug']))) ?>"><?= e($category['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <h5>Account</h5>
                <div class="d-flex flex-column">
                    <?php if ($footerUser): ?>
                        <a href="<?= e(url('dashboard/')) ?>">My dashboard</a>
                        <a href="<?= e(url('dashboard/courses.php')) ?>">My courses</a>
                        <a href="<?= e(url('dashboard/payments.php')) ?>">Payment history</a>
                        <a href="<?= e(url('dashboard/settings.php')) ?>">Account settings</a>
                    <?php else: ?>
                        <a href="<?= e(url('login.php')) ?>">Login</a>
                        <a href="<?= e(url('register.php')) ?>">Create an account</a>
                        <a href="<?= e(url('forgot-password.php')) ?>">Forgot password</a>
                    <?php endif; ?>
                    <?php if ($contactEmail !== ''): ?>
                        <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> <?= e($siteName) ?>. All rights reserved.</span>
            <span>Course content remains the property of its respective owners.</span>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
