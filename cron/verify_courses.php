<?php
declare(strict_types=1);

require_once __DIR__ . '/cron_common.php';

$lock = new JobLock('verification');
if (!$lock->acquire()) {
    cron_respond(['ok' => false, 'error' => 'A verification job is already running.'], 409);
}

$limit = (int) cron_option('limit', '30');

try {
    $verifier = new CourseVerifier();
    $summary = $verifier->runDue(max(1, min(200, $limit)));
    cron_respond(['ok' => true, 'summary' => $summary]);
} catch (Throwable $e) {
    app_log('verification', 'Verification job failed: ' . $e->getMessage());
    cron_respond(['ok' => false, 'error' => $e->getMessage()], 500);
} finally {
    $lock->release();
}
