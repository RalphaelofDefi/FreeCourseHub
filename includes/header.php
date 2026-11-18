<?php
require_once __DIR__ . '/bootstrap.php';

$pageTitle = $pageTitle ?? APP_NAME;
$siteName = (string) setting('site_name', APP_NAME);
$siteLogo = trim((string) setting('site_logo', ''));
if ($siteLogo !== '' && !is_safe_url($siteLogo)) {
    $siteLogo = '';
}
$pageDescription = $pageDescription ?? (string) setting('site_description', 'Discover verified free Udemy courses.');
$bodyClass = $bodyClass ?? '';
$activeNav = $activeNav ?? '';
$defaultTheme = in_array(setting('default_theme', 'light'), ['light', 'dark'], true) ? (string) setting('default_theme', 'light') : 'light';
$allowThemeSwitch = setting_bool('allow_user_theme_switch', true);
$currentUser = Auth::user();
?><!doctype html>
<html lang="en" data-theme="<?= e($defaultTheme) ?>" data-bs-theme="<?= e($defaultTheme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>
    <meta name="description" content="<?= e(excerpt($pageDescription, 180)) ?>">
    <meta name="app-url" content="<?= e(APP_URL) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="default-theme" content="<?= e($defaultTheme) ?>">
    <meta name="theme-switch" content="<?= $allowThemeSwitch ? '1' : '0' ?>">
    <script>
        (function () {
            var fallback = "<?= e($defaultTheme) ?>";
            var allow = <?= $allowThemeSwitch ? 'true' : 'false' ?>;
            var theme = fallback;
            if (allow) {
                try {
                    var saved = localStorage.getItem("fch-theme");
                    if (saved === "dark" || saved === "light") {
                        theme = saved;
                    }
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
<body class="<?= e($bodyClass) ?>">
<?php include __DIR__ . '/navbar.php'; ?>
<main>
<?php include __DIR__ . '/flash.php'; ?>
