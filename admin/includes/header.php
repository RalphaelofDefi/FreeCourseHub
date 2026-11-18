<?php
declare(strict_types=1);

if (!defined('ADMIN_LAYOUT_LOADING')) {
    define('ADMIN_LAYOUT_LOADING', true);
}

require_once __DIR__ . '/../../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

$adminUser = Auth::user();
$adminPage = $adminPage ?? 'overview';
$adminTitle = $adminTitle ?? 'Admin';
$siteName = (string) setting('site_name', APP_NAME);
$siteLogo = trim((string) setting('site_logo', ''));
if ($siteLogo !== '' && !is_safe_url($siteLogo)) {
    $siteLogo = '';
}
$defaultTheme = in_array(setting('default_theme', 'light'), ['light', 'dark'], true) ? (string) setting('default_theme', 'light') : 'light';
$allowThemeSwitch = setting_bool('allow_user_theme_switch', true);
$adminNav = [
    'overview' => ['index.php', 'layout-dashboard', 'Overview'],
    'courses' => ['courses.php', 'book-open', 'Courses'],
    'verification' => ['verification.php', 'shield-check', 'Verification queue'],
    'sources' => ['sources.php', 'rss', 'Sources & collection'],
    'users' => ['users.php', 'users', 'Users'],
    'payments' => ['payments.php', 'credit-card', 'Payments'],
];
?><!doctype html>
<html lang="en" data-theme="<?= e($defaultTheme) ?>" data-bs-theme="<?= e($defaultTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($adminTitle) ?> · <?= e($siteName) ?> Admin</title>
    <meta name="app-url" content="<?= e(APP_URL) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="default-theme" content="<?= e($defaultTheme) ?>">
    <meta name="theme-switch" content="<?= e($allowThemeSwitch ? '1' : '0') ?>">
    <script>
        (function () {
            var fallback = "<?= e($defaultTheme) ?>";
            var allow = <?= $allowThemeSwitch ? 'true' : 'false' ?>;
            var theme = fallback;
            if (allow) {
                try {
                    var saved = localStorage.getItem("fch-theme");
                    if (saved === "dark" || saved === "light") { theme = saved; }
                } catch (e) {}
            }
            document.documentElement.setAttribute("data-theme", theme);
            document.documentElement.setAttribute("data-bs-theme", theme);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="brand" href="<?= e(url('admin/')) ?>">
            <?php if ($siteLogo !== ''): ?>
                <img class="brand-logo" src="<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>" onerror="this.remove()">
            <?php else: ?>
                <span class="brand-mark"><i data-lucide="shield-check"></i></span>
            <?php endif; ?>
            <span><?= e($siteName) ?></span>
        </a>
        <nav class="admin-nav">
            <div class="nav-section">Management</div>
            <?php foreach ($adminNav as $key => [$file, $icon, $label]): ?>
                <a class="<?= $adminPage === $key ? 'active' : '' ?>" href="<?= e(url('admin/' . $file)) ?>">
                    <i data-lucide="<?= e($icon) ?>" style="width:17px;height:17px"></i> <?= e($label) ?>
                </a>
            <?php endforeach; ?>
            <div class="nav-section">Configuration</div>
            <a class="<?= $adminPage === 'settings' ? 'active' : '' ?>" href="<?= e(url('admin/settings.php')) ?>">
                <i data-lucide="settings" style="width:17px;height:17px"></i> Settings
            </a>
            <a href="<?= e(url()) ?>" target="_blank" rel="noopener">
                <i data-lucide="external-link" style="width:17px;height:17px"></i> View website
            </a>
        </nav>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn-icon admin-menu-toggle" id="adminMenuToggle" type="button" aria-label="Toggle menu">
                    <i data-lucide="menu"></i>
                </button>
                <strong><?= e($adminTitle) ?></strong>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($allowThemeSwitch): ?>
                    <button class="btn-icon" id="themeToggle" type="button" title="Toggle theme" aria-label="Toggle theme">
                        <i data-lucide="moon" data-theme-icon></i>
                    </button>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar" style="width:28px;height:28px;font-size:0.7rem"><?= e(initials((string) $adminUser['full_name'])) ?></span>
                        <span class="d-none d-sm-inline"><?= e(explode(' ', (string) $adminUser['full_name'])[0]) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(url('dashboard/')) ?>"><i data-lucide="layout-dashboard"></i> My dashboard</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('dashboard/settings.php')) ?>"><i data-lucide="user"></i> Account settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('logout.php')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item"><i data-lucide="log-out"></i> Log out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <div class="admin-content">
            <?php include __DIR__ . '/../../includes/flash.php'; ?>
