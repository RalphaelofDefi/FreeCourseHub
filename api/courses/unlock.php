<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
ensure_installed_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$user = Auth::user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please log in to unlock courses.'], 401);
}
if (($user['status'] ?? 'active') !== 'active') {
    json_response(['ok' => false, 'error' => 'Your account is suspended.'], 403);
}
if (!verify_csrf()) {
    json_response(['ok' => false, 'error' => 'Your session expired. Please reload the page.'], 419);
}

$courseId = (int) ($_POST['course_id'] ?? 0);
if ($courseId <= 0) {
    json_response(['ok' => false, 'error' => 'Invalid course.'], 422);
}

$service = new UnlockService();
$result = $service->unlockFree((int) $user['id'], $courseId);

switch ($result['status']) {
    case 'unlocked':
    case 'already_unlocked':
        $course = $service->course($courseId);
        $accessUrl = $course ? $service->accessUrlForCourse($course) : null;
        if ($accessUrl === null) {
            json_response(['ok' => false, 'error' => 'This course does not have a usable enrollment link.'], 409);
        }
        json_response([
            'ok' => true,
            'status' => 'unlocked',
            'access_url' => $accessUrl,
            'message' => $result['status'] === 'already_unlocked' ? 'You already have access.' : 'Course unlocked.',
        ]);

    case 'requires_payment':
        json_response([
            'ok' => true,
            'status' => 'requires_payment',
            'price' => (float) setting('unlock_price', 0),
            'currency' => (string) setting('default_currency', 'NGN'),
            'message' => 'Free unlocks exhausted. Payment is required.',
        ]);

    case 'locked':
        json_response(['ok' => false, 'error' => 'Free unlocks are exhausted and paid unlocking is currently disabled.'], 403);

    default:
        json_response(['ok' => false, 'error' => $result['message'] ?? 'Unable to unlock this course.'], 400);
}
