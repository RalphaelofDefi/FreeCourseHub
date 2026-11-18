<?php
declare(strict_types=1);

require_once __DIR__ . '/cron_common.php';

/**
 * External scheduler entry point (for hosts without native cron).
 * Call: https://your-site/cron/scheduler.php?key=CRON_SECRET
 * It only runs each job when its configured interval has elapsed.
 */

$lock = new JobLock('scheduler');
if (!$lock->acquire()) {
    cron_respond(['ok' => false, 'error' => 'The scheduler is already running.'], 409);
}

$results = [];
$hadError = false;

try {
    $force = cron_option('force') === '1';

    if (setting_bool('auto_collection_enabled', true)
        && ($force || cron_is_due('collection_last_run', max(1, (int) setting('collection_interval_hours', 6))))) {
        try {
            $engine = new CollectionEngine();
            $results['collection'] = $engine->run();
            $hadError = $hadError || !($results['collection']['ok'] ?? false);
        } catch (Throwable $e) {
            $hadError = true;
            $results['collection'] = ['ok' => false, 'error' => $e->getMessage()];
            app_log('cron', 'Scheduled collection failed: ' . $e->getMessage());
        }
    } else {
        $results['collection'] = ['skipped' => true];
    }

    if ($force || cron_is_due('verification_last_run', max(1, (int) setting('verification_interval_hours', 12)))) {
        $verificationLock = new JobLock('verification');
        if ($verificationLock->acquire()) {
            try {
                $verifier = new CourseVerifier();
                $results['verification'] = $verifier->runDue(30);
            } catch (Throwable $e) {
                $hadError = true;
                $results['verification'] = ['error' => $e->getMessage()];
                app_log('cron', 'Scheduled verification failed: ' . $e->getMessage());
            } finally {
                $verificationLock->release();
            }
        } else {
            $results['verification'] = ['skipped' => 'A verification job is already running.'];
        }
    } else {
        $results['verification'] = ['skipped' => true];
    }

    if ($force || cron_is_due('expiry_last_run', max(1, (int) setting('expiry_check_interval_hours', 6)))) {
        $expiryLock = new JobLock('expiry_check');
        if ($expiryLock->acquire()) {
            try {
                $stmt = db()->query(
                    "SELECT id FROM courses
                     WHERE expiry_date IS NOT NULL AND expiry_date < NOW()
                       AND verification_status NOT IN ('expired', 'unavailable')"
                );
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $update = db()->prepare(
                    "UPDATE courses SET verification_status = 'expired',
                        publication_status = IF(publication_status = 'published', 'unpublished', publication_status),
                        last_verified_at = NOW(), updated_at = NOW()
                     WHERE id = ?"
                );
                $log = db()->prepare(
                    "INSERT INTO verification_logs (course_id, verification_status, verification_details, checked_at)
                     VALUES (?, 'expired', 'Automatically expired by the scheduler.', NOW())"
                );
                foreach ($ids as $id) {
                    $update->execute([$id]);
                    $log->execute([$id]);
                }
                save_setting('expiry_last_run', date('Y-m-d H:i:s'));
                $results['expiry'] = ['expired' => count($ids)];
            } catch (Throwable $e) {
                $hadError = true;
                $results['expiry'] = ['error' => $e->getMessage()];
                app_log('cron', 'Scheduled expiry check failed: ' . $e->getMessage());
            } finally {
                $expiryLock->release();
            }
        } else {
            $results['expiry'] = ['skipped' => 'An expiry check is already running.'];
        }
    } else {
        $results['expiry'] = ['skipped' => true];
    }

    cron_respond(['ok' => !$hadError, 'run_at' => date('Y-m-d H:i:s'), 'results' => $results], $hadError ? 500 : 200);
} finally {
    $lock->release();
}
