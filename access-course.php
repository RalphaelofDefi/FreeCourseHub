<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
ensure_installed();

$courseId = (int) ($_GET['id'] ?? 0);
$identifier = $courseId > 0 ? (string) $courseId : (is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '');
$course = $identifier !== '' ? find_course($identifier) : null;

if (!$course) {
    http_response_code(404);
    flash('error', 'That course could not be found.');
    redirect('courses.php');
}

if (!Auth::check()) {
    $target = 'access-course.php?id=' . (int) $course['id'];
    Auth::requireLogin($target);
}

if (!course_is_available($course)) {
    flash('error', 'This course offer is no longer available, so its enrollment link cannot be shared.');
    redirect('course.php?slug=' . urlencode((string) $course['slug']));
}

$strict = StrictPriceValidator::ensureFree($course);
if (!$strict['ok']) {
    flash('error', (string) $strict['message']);
    redirect('course.php?slug=' . urlencode((string) $course['slug']));
}

$service = new UnlockService();
$userId = (int) Auth::user()['id'];
if (!$service->hasUnlock($userId, (int) $course['id'])) {
    app_log('access', 'Blocked unauthorized course access', ['user' => $userId, 'course' => $course['id'], 'ip' => client_ip()]);
    flash('error', 'You need to unlock this course before accessing it.');
    redirect('course.php?slug=' . urlencode((string) $course['slug']));
}

$destination = $service->accessUrlForCourse($course);
if ($destination === null) {
    flash('error', 'This course does not have a valid enrollment link at the moment.');
    redirect('course.php?slug=' . urlencode((string) $course['slug']));
}

log_activity($userId, 'course.accessed', 'Accessed course #' . $course['id'] . ' (' . $course['title'] . ')');
header('Referrer-Policy: no-referrer');
header('Location: ' . $destination, true, 302);
exit;
