<?php
declare(strict_types=1);

require_once __DIR__ . '/cron_common.php';

$force = cron_option('force') === '1';

if (!$force && !setting_bool('auto_collection_enabled', true)) {
    cron_respond(['ok' => true, 'skipped' => true, 'reason' => 'Automatic collection is disabled in settings.']);
}

$maxPages = (int) cron_option('pages', (string) COLLECTION_MAX_PAGES);
$maxCourses = (int) cron_option('courses', '40');

$engine = new CollectionEngine();
$summary = $engine->run(max(1, min(10, $maxPages)), max(1, min(100, $maxCourses)));

app_log('cron', 'Collection job completed', [
    'new' => $summary['new_courses'] ?? 0,
    'duplicates' => $summary['duplicates'] ?? 0,
    'failed' => $summary['failed'] ?? 0,
    'skipped' => $summary['skipped'] ?? 0,
]);

cron_respond($summary, ($summary['ok'] ?? false) ? 200 : 500);
