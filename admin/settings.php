<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
ensure_installed();
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $siteDescription = trim((string) ($_POST['site_description'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $siteLogo = trim((string) ($_POST['site_logo'] ?? ''));
    $currency = strtoupper(trim((string) ($_POST['default_currency'] ?? 'NGN')));
    $timezone = trim((string) ($_POST['timezone'] ?? 'Africa/Lagos'));
    $freeAllowance = (int) ($_POST['free_unlock_allowance'] ?? 5);
    $unlockPrice = (float) ($_POST['unlock_price'] ?? 0);
    $collectionInterval = (int) ($_POST['collection_interval_hours'] ?? 6);
    $verificationInterval = (int) ($_POST['verification_interval_hours'] ?? 12);
    $expiryInterval = (int) ($_POST['expiry_check_interval_hours'] ?? 6);
    $defaultTheme = in_array($_POST['default_theme'] ?? '', ['light', 'dark'], true) ? (string) $_POST['default_theme'] : 'light';

    $errors = [];
    if (mb_strlen($siteName) < 2 || mb_strlen($siteName) > 120) {
        $errors[] = 'Website name must be between 2 and 120 characters.';
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email must be a valid email address.';
    }
    if ($siteLogo !== '' && !is_safe_url($siteLogo)) {
        $errors[] = 'Logo URL must be a valid http(s) URL (or leave it empty).';
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Default currency must be a 3-letter ISO code, for example NGN or USD.';
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = 'Select a valid time zone.';
    }
    if ($freeAllowance < 0 || $freeAllowance > 1000) {
        $errors[] = 'Free unlock allowance must be between 0 and 1000.';
    }
    if ($unlockPrice < 0 || $unlockPrice > 1000000) {
        $errors[] = 'Unlock price must be between 0 and 1,000,000.';
    }
    foreach ([$collectionInterval, $verificationInterval, $expiryInterval] as $interval) {
        if ($interval < 1 || $interval > 168) {
            $errors[] = 'Automation intervals must be between 1 and 168 hours.';
            break;
        }
    }

    if ($errors !== []) {
        flash('error', implode(' ', $errors));
    } else {
        try {
            $values = [
                'site_name' => $siteName,
                'site_description' => $siteDescription,
                'contact_email' => $contactEmail,
                'site_logo' => $siteLogo,
                'default_currency' => $currency,
                'timezone' => $timezone,
                'free_unlock_allowance' => (string) $freeAllowance,
                'unlock_price' => (string) round($unlockPrice, 2),
                'payments_enabled' => !empty($_POST['payments_enabled']) ? '1' : '0',
                'free_access_enabled' => !empty($_POST['free_access_enabled']) ? '1' : '0',
                'paid_access_enabled' => !empty($_POST['paid_access_enabled']) ? '1' : '0',
                'auto_collection_enabled' => !empty($_POST['auto_collection_enabled']) ? '1' : '0',
                'collection_interval_hours' => (string) $collectionInterval,
                'verification_interval_hours' => (string) $verificationInterval,
                'expiry_check_interval_hours' => (string) $expiryInterval,
                'default_theme' => $defaultTheme,
                'allow_user_theme_switch' => !empty($_POST['allow_user_theme_switch']) ? '1' : '0',
            ];
            db()->beginTransaction();
            foreach ($values as $key => $value) {
                save_setting($key, $value);
            }
            db()->commit();
            log_activity((int) Auth::user()['id'], 'admin.settings_updated', 'Updated system settings');
            flash('success', 'Settings saved. New registrations will receive the configured free allowance.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            app_log('admin', 'Settings save failed: ' . $e->getMessage());
            flash('error', 'Settings could not be saved.');
        }
    }
    redirect('admin/settings.php');
}

$logo = (string) setting('site_logo', '');
$paystackConfigured = trim((string) PAYSTACK_SECRET_KEY) !== '';
$cronConfigured = CRON_SECRET !== '';

$adminPage = 'settings';
$adminTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>
<div class="dashboard-head">
    <div>
        <h1>Settings</h1>
        <p class="mb-0">Configure the website, monetization, automation and theme defaults.</p>
    </div>
</div>

<form method="post" action="<?= e(url('admin/settings.php')) ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="panel mb-4">
                <div class="panel-head"><h2>General</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="site_name">Website name</label>
                            <input class="form-control" id="site_name" type="text" name="site_name" value="<?= e(setting('site_name', APP_NAME)) ?>" required maxlength="120">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="contact_email">Contact email</label>
                            <input class="form-control" id="contact_email" type="email" name="contact_email" value="<?= e(setting('contact_email', '')) ?>" maxlength="190">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="site_description">Website description</label>
                            <textarea class="form-control" id="site_description" name="site_description" rows="3" maxlength="500"><?= e(setting('site_description', '')) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="site_logo">Logo URL</label>
                            <input class="form-control" id="site_logo" type="url" name="site_logo" value="<?= e($logo) ?>" maxlength="600" placeholder="https://...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="default_currency">Default currency</label>
                            <input class="form-control" id="default_currency" type="text" name="default_currency" value="<?= e(setting('default_currency', 'NGN')) ?>" maxlength="3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="timezone">Time zone</label>
                            <select class="form-select" id="timezone" name="timezone">
                                <?php $currentTimezone = (string) setting('timezone', APP_TIMEZONE); ?>
                                <?php foreach (['Africa/Lagos', 'Africa/Accra', 'Africa/Nairobi', 'Africa/Johannesburg', 'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Kolkata', 'UTC'] as $zone): ?>
                                    <option value="<?= e($zone) ?>" <?= $currentTimezone === $zone ? 'selected' : '' ?>><?= e($zone) ?></option>
                                <?php endforeach; ?>
                                <?php if (!in_array($currentTimezone, ['Africa/Lagos', 'Africa/Accra', 'Africa/Nairobi', 'Africa/Johannesburg', 'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Kolkata', 'UTC'], true)): ?>
                                    <option value="<?= e($currentTimezone) ?>" selected><?= e($currentTimezone) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head">
                    <h2>Platform mode</h2>
                    <span class="badge-soft <?= platform_payments_enabled() ? 'accent' : 'success' ?>"><?= platform_payments_enabled() ? 'Paid platform' : 'Free platform' ?></span>
                </div>
                <div class="panel-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" id="payments_enabled" type="checkbox" name="payments_enabled" value="1" <?= platform_payments_enabled() ? 'checked' : '' ?>>
                        <label class="form-check-label" for="payments_enabled">Enable payments (paid platform)</label>
                    </div>
                    <p class="small-muted mt-2 mb-0">When this is off, the entire platform is free: logged-in members can unlock any available course without spending their free allowance, and Paystack checkout is disabled. Existing unlocks and payment history are preserved. When enabled, the free-allowance and paid-unlock settings below apply.</p>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head"><h2>Monetization</h2></div>
                <div class="panel-body">
                    <?php if (!platform_payments_enabled()): ?>
                        <div class="alert alert-info"><i data-lucide="info" style="width:16px;height:16px"></i> Payments are currently turned off. These settings are saved and will apply again when you re-enable payments.</div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="free_unlock_allowance">Free unlocks for new users</label>
                            <input class="form-control" id="free_unlock_allowance" type="number" min="0" max="1000" name="free_unlock_allowance" value="<?= e(setting('free_unlock_allowance', 5)) ?>" required>
                            <div class="small-muted mt-1">Applies to new registrations only. Existing balances are not reset.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="unlock_price">Price per additional course (<?= e(setting('default_currency', 'NGN')) ?>)</label>
                            <input class="form-control" id="unlock_price" type="number" step="0.01" min="0" name="unlock_price" value="<?= e(setting('unlock_price', 200)) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" id="free_access_enabled" type="checkbox" name="free_access_enabled" value="1" <?= setting_bool('free_access_enabled', true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="free_access_enabled">Enable free unlocks</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" id="paid_access_enabled" type="checkbox" name="paid_access_enabled" value="1" <?= setting_bool('paid_access_enabled', true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="paid_access_enabled">Enable paid unlocks</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head"><h2>Automation</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" id="auto_collection_enabled" type="checkbox" name="auto_collection_enabled" value="1" <?= setting_bool('auto_collection_enabled', true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="auto_collection_enabled">Enable automatic collection</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="collection_interval_hours">Collection interval (hours)</label>
                            <input class="form-control" id="collection_interval_hours" type="number" min="1" max="168" name="collection_interval_hours" value="<?= e(setting('collection_interval_hours', 6)) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="verification_interval_hours">Verification interval (hours)</label>
                            <input class="form-control" id="verification_interval_hours" type="number" min="1" max="168" name="verification_interval_hours" value="<?= e(setting('verification_interval_hours', 12)) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="expiry_check_interval_hours">Expiry check interval (hours)</label>
                            <input class="form-control" id="expiry_check_interval_hours" type="number" min="1" max="168" name="expiry_check_interval_hours" value="<?= e(setting('expiry_check_interval_hours', 6)) ?>" required>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <ul class="detail-list mb-0">
                        <li><span class="label">Collection last run</span><span class="value"><?= e(format_datetime(setting('collection_last_run', ''))) ?></span></li>
                        <li><span class="label">Verification last run</span><span class="value"><?= e(format_datetime(setting('verification_last_run', ''))) ?></span></li>
                        <li><span class="label">Expiry check last run</span><span class="value"><?= e(format_datetime(setting('expiry_last_run', ''))) ?></span></li>
                    </ul>
                </div>
            </div>

            <div class="panel mb-4">
                <div class="panel-head"><h2>Theme</h2></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="default_theme">Default theme</label>
                            <select class="form-select" id="default_theme" name="default_theme">
                                <option value="light" <?= setting('default_theme', 'light') === 'light' ? 'selected' : '' ?>>Light</option>
                                <option value="dark" <?= setting('default_theme', 'light') === 'dark' ? 'selected' : '' ?>>Dark</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" id="allow_user_theme_switch" type="checkbox" name="allow_user_theme_switch" value="1" <?= setting_bool('allow_user_theme_switch', true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="allow_user_theme_switch">Allow visitors to switch theme</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button class="btn btn-primary" type="submit"><i data-lucide="save"></i> Save settings</button>
        </div>

        <div class="col-lg-5">
            <div class="panel mb-4">
                <div class="panel-head"><h2>Paystack configuration</h2></div>
                <div class="panel-body">
                    <ul class="detail-list">
                        <li><span class="label">Secret key</span><span class="value"><?= $paystackConfigured ? '<span class="badge-soft success">Configured</span>' : '<span class="badge-soft danger">Missing</span>' ?></span></li>
                        <li><span class="label">Public key</span><span class="value"><?= PAYSTACK_PUBLIC_KEY !== '' ? '<span class="badge-soft success">Configured</span>' : '<span class="badge-soft muted">Not set</span>' ?></span></li>
                        <li><span class="label">Callback URL</span><span class="value small"><?= e(PAYSTACK_CALLBACK_URL) ?></span></li>
                        <li><span class="label">Webhook URL</span><span class="value small"><?= e(url('api/payments/webhook.php')) ?></span></li>
                    </ul>
                    <p class="small-muted mt-3 mb-0">Keys are read from <code>.env</code> and never shown or stored in the database. Use Paystack test keys during local development.</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head"><h2>Scheduler</h2></div>
                <div class="panel-body">
                    <ul class="detail-list">
                        <li><span class="label">CRON_SECRET</span><span class="value"><?= $cronConfigured ? '<span class="badge-soft success">Configured</span>' : '<span class="badge-soft danger">Missing</span>' ?></span></li>
                        <li><span class="label">Native cron</span><span class="value small">php <?= e(BASE_PATH) ?>/cron/fetch_courses.php</span></li>
                    </ul>
                    <p class="small-muted mt-3 mb-2">For hosts without native cron, schedule this URL:</p>
                    <div class="small" style="word-break:break-all;background:var(--surface-2);padding:0.7rem;border-radius:8px"><?= e(url('cron/scheduler.php')) ?>?key=YOUR_CRON_SECRET</div>
                    <p class="small-muted mt-2 mb-0">The scheduler only runs jobs whose configured intervals have elapsed. Requests without the correct secret are rejected.</p>
                </div>
            </div>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
