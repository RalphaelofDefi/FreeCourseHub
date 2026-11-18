<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $sourceId = (int) ($_POST['source_id'] ?? 0);

    try {
        if ($action === 'toggle') {
            $stmt = db()->prepare('SELECT id, name, status FROM course_sources WHERE id = ? LIMIT 1');
            $stmt->execute([$sourceId]);
            $source = $stmt->fetch();
            if (!$source) {
                flash('error', 'Source not found.');
            } else {
                $next = $source['status'] === 'enabled' ? 'disabled' : 'enabled';
                db()->prepare('UPDATE course_sources SET status = ? WHERE id = ?')->execute([$next, $sourceId]);
                log_activity((int) Auth::user()['id'], 'admin.source_toggled', $source['name'] . ' ' . $next);
                flash('success', $source['name'] . ' is now ' . $next . '.');
            }
        } elseif ($action === 'collect' || $action === 'collect_more') {
            @set_time_limit(300);
            $deepRun = $action === 'collect_more';
            $engine = new CollectionEngine();
            $summary = $engine->run(
                $deepRun ? 6 : 2,
                $deepRun ? 30 : 15,
                $sourceId > 0 ? $sourceId : null,
                $deepRun ? 220 : 120
            );
            if (!($summary['ok'] ?? false)) {
                flash('error', $summary['error'] ?? 'Collection could not be started. Another job may be running.');
            } else {
                log_activity((int) Auth::user()['id'], 'admin.collection_triggered', 'Manual collection run (' . count($summary['sources']) . ' source(s))');
                $message = ($deepRun ? 'Deep collection finished' : 'Collection finished') . ': ' . $summary['new_courses'] . ' new, ' . $summary['duplicates'] . ' duplicate(s), ' . $summary['failed'] . ' failed, ' . ($summary['skipped'] ?? 0) . ' paid deal(s) skipped.';
                $hadFailure = false;
                foreach ($summary['sources'] as $sourceResult) {
                    if (($sourceResult['status'] ?? '') === 'failed') {
                        $hadFailure = true;
                        $message .= ' ' . $sourceResult['source'] . ' failed: ' . ($sourceResult['error'] ?? 'unknown error') . '.';
                    }
                }
                flash($hadFailure ? 'warning' : 'success', $message);
            }
        } else {
            flash('error', 'Unknown source action.');
        }
    } catch (Throwable $e) {
        app_log('admin', 'Source action failed: ' . $e->getMessage());
        flash('error', 'The source action could not be completed.');
    }
    redirect('admin/sources.php');
}

$sources = db()->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM course_source_links l WHERE l.source_id = s.id) AS course_count,
            (SELECT COUNT(DISTINCT c.id) FROM course_source_links l JOIN courses c ON c.id = l.course_id
                WHERE l.source_id = s.id AND c.verification_status = \'verified\') AS verified_count,
            (SELECT COUNT(DISTINCT c.id) FROM course_source_links l JOIN courses c ON c.id = l.course_id
                WHERE l.source_id = s.id AND c.verification_status = \'failed\') AS rejected_count,
            (SELECT COUNT(DISTINCT c.id) FROM course_source_links l JOIN courses c ON c.id = l.course_id
                WHERE l.source_id = s.id AND c.verification_status IN (\'pending\', \'questionable\')) AS awaiting_count,
            (SELECT started_at FROM collection_logs cl WHERE cl.source_id = s.id ORDER BY cl.started_at DESC LIMIT 1) AS last_log_at,
            (SELECT status FROM collection_logs cl WHERE cl.source_id = s.id ORDER BY cl.started_at DESC LIMIT 1) AS last_log_status,
            (SELECT MAX(COALESCE(cl.completed_at, cl.started_at)) FROM collection_logs cl
                WHERE cl.source_id = s.id AND cl.status IN (\'completed\', \'partial\')) AS last_success_at,
            (SELECT cl.error_message FROM collection_logs cl
                WHERE cl.source_id = s.id AND cl.error_message IS NOT NULL AND cl.error_message <> \'\'
                ORDER BY cl.started_at DESC LIMIT 1) AS last_error
     FROM course_sources s ORDER BY s.name ASC'
)->fetchAll();

$logs = db()->query(
    'SELECT cl.*, s.name AS source_name FROM collection_logs cl
     LEFT JOIN course_sources s ON s.id = cl.source_id
     ORDER BY cl.started_at DESC LIMIT 15'
)->fetchAll();

$adminPage = 'sources';
$adminTitle = 'Sources & collection';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <h1>Sources & collection</h1>
        <p class="mb-0">Manual and scheduled collection use the same engine, with locking to prevent overlap.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" class="m-0" data-confirm="Run a quick collection for all enabled sources now?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="collect">
            <button class="btn btn-outline-secondary" type="submit"><i data-lucide="refresh-cw"></i> Collect latest</button>
        </form>
        <form method="post" class="m-0" data-confirm="Fetch deeper listing pages from all enabled sources? This can take a minute or two.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="collect_more">
            <button class="btn btn-primary" type="submit"><i data-lucide="layers"></i> Fetch more courses</button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($sources as $source): ?>
        <div class="col-lg-6">
            <div class="panel">
                <?php $isStrict = in_array((string) $source['adapter'], strict_price_adapters(), true); ?>
                <div class="panel-head">
                    <h2><?= e($source['name']) ?></h2>
                    <div class="d-flex gap-2">
                        <?php if ($isStrict): ?>
                            <span class="badge-soft warning" title="Only offers confirmed at $0.00 are published">Strict $0.00</span>
                        <?php endif; ?>
                        <span class="badge-soft <?= $source['status'] === 'enabled' ? 'success' : 'muted' ?>"><?= e(ucfirst((string) $source['status'])) ?></span>
                    </div>
                </div>
                <div class="panel-body">
                    <ul class="detail-list">
                        <li><span class="label">Base URL</span><span class="value"><a href="<?= e($source['base_url']) ?>" target="_blank" rel="noopener nofollow"><?= e($source['base_url']) ?></a></span></li>
                        <li><span class="label">Adapter</span><span class="value"><?= e($source['adapter']) ?></span></li>
                        <li><span class="label">Linked courses</span><span class="value"><?= number_format((int) $source['course_count']) ?></span></li>
                        <li><span class="label">Verified</span><span class="value"><?= number_format((int) $source['verified_count']) ?></span></li>
                        <li><span class="label">Rejected</span><span class="value"><?= number_format((int) $source['rejected_count']) ?></span></li>
                        <li><span class="label">Awaiting review</span><span class="value"><?= number_format((int) $source['awaiting_count']) ?></span></li>
                        <li><span class="label">Last fetched</span><span class="value"><?= $source['last_fetched_at'] ? e(format_datetime($source['last_fetched_at'])) : 'Never' ?></span></li>
                        <li><span class="label">Last successful collection</span><span class="value"><?= $source['last_success_at'] ? e(format_datetime($source['last_success_at'])) : 'Never' ?></span></li>
                        <li><span class="label">Last job</span><span class="value"><?= $source['last_log_status'] ? e(ucfirst((string) $source['last_log_status'])) . ' · ' . e(time_ago($source['last_log_at'])) : 'No runs yet' ?></span></li>
                        <?php if (!empty($source['last_error'])): ?>
                            <li><span class="label">Last error</span><span class="value text-danger"><?= e(excerpt((string) $source['last_error'], 180)) ?></span></li>
                        <?php endif; ?>
                    </ul>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <form method="post" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="source_id" value="<?= (int) $source['id'] ?>">
                            <input type="hidden" name="action" value="collect">
                            <button class="btn btn-sm btn-primary" type="submit" <?= $source['status'] !== 'enabled' ? 'disabled' : '' ?>><i data-lucide="download"></i> Collect now</button>
                        </form>
                        <form method="post" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="source_id" value="<?= (int) $source['id'] ?>">
                            <input type="hidden" name="action" value="toggle">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $source['status'] === 'enabled' ? 'Disable source' : 'Enable source' ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$sources): ?>
        <div class="col-12"><div class="panel"><div class="panel-body small-muted">No sources configured. Run the database seed to add the default sources.</div></div></div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head"><h2>Recent collection logs</h2></div>
    <div class="panel-body p-0">
        <?php if ($logs): ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Discovered</th>
                            <th>New</th>
                            <th>Duplicates</th>
                            <th>Failed</th>
                            <th>Started</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-strong"><?= e($log['source_name'] ?: 'All / removed source') ?></td>
                            <td><span class="badge-soft <?= $log['status'] === 'completed' ? 'success' : ($log['status'] === 'running' ? 'warning' : 'danger') ?>"><?= e(ucfirst((string) $log['status'])) ?></span></td>
                            <td><?= number_format((int) $log['discovered_count']) ?></td>
                            <td><?= number_format((int) $log['new_courses_count']) ?></td>
                            <td><?= number_format((int) $log['duplicate_count']) ?></td>
                            <td><?= number_format((int) $log['failed_count']) ?></td>
                            <td class="small-muted"><?= e(format_datetime($log['started_at'])) ?></td>
                            <td class="small-muted"><?= $log['completed_at'] ? e(format_datetime($log['completed_at'])) : '—' ?></td>
                        </tr>
                        <?php if (!empty($log['error_message'])): ?>
                            <tr>
                                <td colspan="8" class="small-muted" style="background:var(--surface-2)"><?= e(excerpt($log['error_message'], 400)) ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="icon"><i data-lucide="rss"></i></div>
                <h3>No collection runs yet</h3>
                <p>Start a manual collection or configure the scheduler.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
