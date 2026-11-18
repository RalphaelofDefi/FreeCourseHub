<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

$reference = is_string($_GET['reference'] ?? null) ? trim($_GET['reference']) : '';
if ($reference === '' && is_string($_GET['trxref'] ?? null)) {
    $reference = trim($_GET['trxref']);
}

if (!Auth::check()) {
    if ($reference !== '') {
        $_SESSION['pending_payment_reference'] = $reference;
    }
    flash('info', 'Please log in to finish confirming your payment.');
    redirect('login.php?redirect=' . urlencode('payment-callback.php?reference=' . $reference));
}

if ($reference === '') {
    flash('error', 'No transaction reference was supplied by the payment provider.');
    redirect('dashboard/payments.php');
}

$service = new UnlockService();
$result = $service->verifyAndComplete($reference, (int) Auth::user()['id']);

if ($result['ok']) {
    unset($_SESSION['pending_payment_reference'], $_SESSION['pending_payment_course']);
    flash('success', $result['message']);
    if (!empty($result['course_id'])) {
        $course = $service->course((int) $result['course_id']);
        if ($course) {
            redirect('course.php?slug=' . urlencode((string) $course['slug']));
        }
    }
    redirect('dashboard/courses.php');
}

app_log('payments', 'Callback could not complete payment', ['reference' => $reference, 'message' => $result['message']]);
flash('error', $result['message']);
if (!empty($result['course_id'])) {
    $course = $service->course((int) $result['course_id']);
    if ($course) {
        redirect('course.php?slug=' . urlencode((string) $course['slug']));
    }
}
redirect('dashboard/payments.php');
