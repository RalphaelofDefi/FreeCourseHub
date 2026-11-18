<?php
declare(strict_types=1);

require_once __DIR__ . '/cron_common.php';

$lock = new JobLock('expiry_check');
if (!$lock->acquire()) {
    cron_respond(['ok' => false, 'error' => 'An expiry check is already running.'], 409);
}

try {
    $stmt = db()->query(
        "SELECT id, title FROM courses
         WHERE expiry_date IS NOT NULL
           AND expiry_date < NOW()
           AND verification_status NOT IN ('expired', 'unavailable')"
    );
    $expiredCourses = $stmt->fetchAll();

    $update = db()->prepare(
        "UPDATE courses
         SET verification_status = 'expired',
             publication_status = IF(publication_status = 'published', 'unpublished', publication_status),
             verification_notes = CONCAT(COALESCE(CONCAT(verification_notes, ' '), ''), 'Automatically expired: the coupon expiry date has passed.'),
             last_verified_at = NOW(),
             updated_at = NOW()
         WHERE id = ?"
    );
    $log = db()->prepare(
        "INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at)
         VALUES (?, 'expired', 'Automatically expired: the coupon expiry date has passed.', NOW())"
    );

    db()->beginTransaction();
    foreach ($expiredCourses as $course) {
        $update->execute([$course['id']]);
        $log->execute([$course['id']]);
    }
    db()->commit();

    $count = count($expiredCourses);
    save_setting('expiry_last_run', date('Y-m-d H:i:s'));

    if ($count > 0) {
        log_activity(null, 'courses.expired', $count . ' course offers expired automatically.');
    }

    cron_respond(['ok' => true, 'expired' => $count]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    app_log('expiry', 'Expiry check failed: ' . $e->getMessage());
    cron_respond(['ok' => false, 'error' => $e->getMessage()], 500);
} finally {
    $lock->release();
}
