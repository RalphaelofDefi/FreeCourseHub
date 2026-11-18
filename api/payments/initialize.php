<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
ensure_installed_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$user = Auth::user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please log in to continue.'], 401);
}
if (!verify_csrf()) {
    json_response(['ok' => false, 'error' => 'Your session expired. Please reload the page.'], 419);
}

$courseId = (int) ($_POST['course_id'] ?? 0);
if ($courseId <= 0) {
    json_response(['ok' => false, 'error' => 'Invalid course.'], 422);
}

try {
    $service = new UnlockService();
    $payment = $service->initializePayment($user, $courseId);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pending_payment_reference'] = $payment['reference'];
        $_SESSION['pending_payment_course'] = $courseId;
    }

    json_response([
        'ok' => true,
        'authorization_url' => $payment['authorization_url'],
        'reference' => $payment['reference'],
    ]);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    app_log('payments', 'Payment initialization error: ' . $e->getMessage(), ['course' => $courseId, 'user' => $user['id']]);
    json_response(['ok' => false, 'error' => 'We could not start the payment. Please try again later.'], 500);
}
