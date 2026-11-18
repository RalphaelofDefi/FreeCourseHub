<?php
$errorTitle = $errorTitle ?? 'Something went wrong';
$errorMessage = $errorMessage ?? 'We could not complete your request.';
?><!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($errorTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<main class="error-shell">
    <div class="error-card">
        <span class="error-code">500</span>
        <h1><?= e($errorTitle) ?></h1>
        <p><?= e($errorMessage) ?></p>
        <a class="btn btn-primary" href="<?= e(url()) ?>">Back to home</a>
    </div>
</main>
</body>
</html>
