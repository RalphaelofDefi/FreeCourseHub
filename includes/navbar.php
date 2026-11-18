<?php
$currentUser = $currentUser ?? Auth::user();
$activeNav = $activeNav ?? '';
$siteName = $siteName ?? (string) setting('site_name', APP_NAME);
$siteLogo = $siteLogo ?? '';
$allowThemeSwitch = $allowThemeSwitch ?? setting_bool('allow_user_theme_switch', true);
$navSearch = $_GET['q'] ?? '';
?>
<nav class="navbar navbar-expand-lg site-nav py-2">
    <div class="container">
        <a class="navbar-brand" href="<?= e(url()) ?>">
            <?php if ($siteLogo !== ''): ?>
                <img class="brand-logo" src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>" onerror="this.remove()">
            <?php else: ?>
                <span class="brand-mark"><i data-lucide="book-open-check"></i></span>
            <?php endif; ?>
            <span><?= e($siteName) ?></span>
        </a>

        <div class="d-flex align-items-center gap-2 order-lg-3">
            <?php if ($allowThemeSwitch): ?>
                <button class="btn-icon" id="themeToggle" type="button" title="Toggle theme" aria-label="Toggle theme">
                    <i data-lucide="moon" data-theme-icon></i>
                </button>
            <?php endif; ?>
            <?php if ($currentUser): ?>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar"><?= e(initials((string) $currentUser['full_name'])) ?></span>
                        <span class="d-none d-sm-inline"><?= e(explode(' ', (string) $currentUser['full_name'])[0]) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(url('dashboard/')) ?>"><i data-lucide="layout-dashboard"></i> Dashboard</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('dashboard/courses.php')) ?>"><i data-lucide="bookmark"></i> My Courses</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('dashboard/payments.php')) ?>"><i data-lucide="receipt"></i> Payment History</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('dashboard/settings.php')) ?>"><i data-lucide="settings"></i> Account Settings</a></li>
                        <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= e(url('admin/')) ?>"><i data-lucide="shield-check"></i> Admin Dashboard</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item"><i data-lucide="log-out"></i> Log out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <a class="btn btn-ghost d-none d-sm-inline-flex" href="<?= e(url('login.php')) ?>">Login</a>
                <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Register</a>
            <?php endif; ?>
            <button class="navbar-toggler btn-icon border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <i data-lucide="menu"></i>
            </button>
        </div>

        <div class="collapse navbar-collapse order-lg-2" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'home' ? 'active' : '' ?>" href="<?= e(url()) ?>">Home</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'courses' ? 'active' : '' ?>" href="<?= e(url('courses.php')) ?>">Browse Courses</a></li>
                <li class="nav-item"><a class="nav-link <?= $activeNav === 'categories' ? 'active' : '' ?>" href="<?= e(url('categories.php')) ?>">Categories</a></li>
            </ul>
            <form class="nav-search d-flex" action="<?= e(url('courses.php')) ?>" method="get" role="search">
                <i data-lucide="search"></i>
                <input class="form-control" type="search" name="q" value="<?= e(is_string($navSearch) ? $navSearch : '') ?>" placeholder="Search courses..." aria-label="Search courses">
            </form>
        </div>
    </div>
</nav>
