<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

function install_database_name(): string
{
    $name = (string) env('DB_DATABASE', 'freecourse_hub');
    return preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : 'freecourse_hub';
}

function install_execute_sql_file(string $path): int
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing SQL file: ' . basename($path));
    }
    $sql = (string) file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $executed = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        db()->exec($statement);
        $executed++;
    }
    return $executed;
}

function install_admin_exists(): bool
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$installed = db_installed();
$adminExists = $installed && install_admin_exists();
$errors = [];
$success = false;

if (!$adminExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
        $errors[] = 'Enter the administrator full name (2–150 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors[] = 'Enter a valid administrator email address.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'The administrator password must be at least 10 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The password confirmation does not match.';
    }

    if ($errors === []) {
        try {
            $database = install_database_name();

            // Managed databases (Pxxl) and panel-created databases
            // (InfinityFree, cPanel) already exist and usually disallow
            // CREATE DATABASE. Only try to create one when the configured
            // database cannot be opened at all.
            try {
                db();
            } catch (Throwable $connectionError) {
                $root = db_without_name();
                $root->exec('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                db();
            }

            install_execute_sql_file(BASE_PATH . '/database/schema.sql');
            install_execute_sql_file(BASE_PATH . '/database/seed.sql');

            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'A user with this email address already exists. Choose another email.';
            } else {
                $stmt = db()->prepare(
                    "INSERT INTO users (full_name, email, password, role, status, free_unlocks_remaining, created_at, updated_at)
                     VALUES (?, ?, ?, 'admin', 'active', 0, NOW(), NOW())"
                );
                $stmt->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $adminId = (int) db()->lastInsertId();
                log_activity($adminId, 'install.completed', 'Initial administrator account created');
                @file_put_contents(storage_path('installed.lock'), date('c'));
                flash('success', 'Installation complete. Log in with your administrator account.');
                redirect('admin/login.php');
            }
        } catch (Throwable $e) {
            app_log('install', 'Installation failed: ' . $e->getMessage());
            $errors[] = APP_DEBUG
                ? 'Installation failed: ' . $e->getMessage()
                : 'Installation failed. Verify your database environment variables, make sure the database exists, and confirm the user can create tables. Details were written to the application log.';
        }
    }
}

$pageTitle = $adminExists ? 'Already installed' : 'Install FreeCourse Hub';
?><!doctype html>
<html lang="en" data-theme="light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body>
<main class="auth-shell">
    <div class="auth-card">
        <h1><?= e($adminExists ? 'Installation complete' : 'Set up FreeCourse Hub') ?></h1>
        <?php if ($adminExists): ?>
            <p class="subtitle">This application is already installed and an administrator account exists. The installer is locked.</p>
            <a class="btn btn-primary w-100 justify-content-center" href="<?= e(url('admin/login.php')) ?>">Go to administrator login</a>
            <div class="auth-footer"><a href="<?= e(url()) ?>">Back to website</a></div>
        <?php else: ?>
            <p class="subtitle">This will create the database tables, seed categories and sources, and create your administrator account.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info small-muted">
                Database: <strong class="text-strong"><?= e(install_database_name()) ?></strong> on <?= e((string) env('DB_HOST', '127.0.0.1')) ?>.
                Verify these values in your <code>.env</code> file before continuing.
            </div>

            <form method="post" action="<?= e(url('install.php')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="full_name">Administrator full name</label>
                    <input class="form-control" id="full_name" type="text" name="full_name" value="<?= e((string) ($_POST['full_name'] ?? '')) ?>" required maxlength="150" autocomplete="name">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Administrator email</label>
                    <input class="form-control" id="email" type="email" name="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>" required maxlength="190" autocomplete="email">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" type="password" name="password" required minlength="10" autocomplete="new-password">
                    <div class="small-muted mt-1">At least 10 characters. This password is stored hashed.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password_confirm">Confirm password</label>
                    <input class="form-control" id="password_confirm" type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
                </div>
                <button class="btn btn-primary w-100 justify-content-center" type="submit">Run installation</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
