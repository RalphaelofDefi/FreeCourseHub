<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(Auth::check() ? 'dashboard/' : 'login.php');
}

require_csrf();
$user = Auth::user();
if ($user) {
    log_activity((int) $user['id'], 'auth.logout', 'Logged out');
}
Auth::logout();
redirect('login.php');
