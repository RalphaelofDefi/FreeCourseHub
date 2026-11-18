# FreeCourse Hub

Automated Udemy free-course aggregator built with raw PHP 8, MySQL, Bootstrap 5 and the Paystack API. It collects offers from DiscUdemy, CouponsCorpion, Udemy Free Courses and Real.discount, verifies them, publishes safe records, and lets registered users unlock enrollment links using a free allowance or a verified Paystack payment.

No PHP framework is used. Dependencies are loaded from CDNs (Bootstrap, Lucide, Google Fonts); PHP has no Composer dependencies. A minimal `composer.json` exists only so Pxxl can detect the PHP runtime.

The project runs unchanged on Apache shared hosting via `.htaccess`. For Pxxl (and local `php -S`), the included `router.php` provides the same path protection when Apache is not available.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `dom`, `mbstring`, `openssl`
- MySQL 5.7+/MariaDB 10.3+
- Apache with `mod_rewrite`/`.htaccess` support (or PHP's built-in server for development)
- Outbound HTTPS access to DiscUdemy, CouponsCorpion, Udemy, Real.discount (`cdn.real.discount` for its public feed) and Paystack
- A Paystack account (test keys are fine for local development)

## Local installation

1. Copy the project into your web root, for example `C:\xampp\htdocs\projects\udemy`.
2. Create the environment file:

   ```bash
   cp .env.example .env
   ```

3. Edit `.env`:

   ```dotenv
   APP_NAME="FreeCourse Hub"
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost/projects/udemy
   APP_TIMEZONE=Africa/Lagos

   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=freecourse_hub
   DB_USERNAME=root
   DB_PASSWORD=

   PAYSTACK_PUBLIC_KEY=
   PAYSTACK_SECRET_KEY=
   PAYSTACK_CALLBACK_URL="${APP_URL}/payment-callback.php"

   CRON_SECRET=replace-with-a-long-random-string
   COLLECTION_MAX_PAGES=3
   HTTP_TIMEOUT=25
   ```

4. Make sure MySQL is running and the configured database exists. On shared and managed hosts, create it in the control panel first (for example `freecourse_hub`). The installer uses the existing database and only attempts to create it when it cannot connect and the database user has permission.
5. Open `http://localhost/projects/udemy/` in a browser. You will be redirected to `install.php`.
6. Enter the administrator name, email and a password of at least 10 characters. The installer imports `database/schema.sql` and `database/seed.sql`, then locks itself once an administrator exists.
7. Sign in at `/admin/login.php`.

If you prefer the CLI and have the `mysql` client, you can import manually instead:

```bash
mysql -u root -p -e "CREATE DATABASE freecourse_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p freecourse_hub < database/schema.sql
mysql -u root -p freecourse_hub < database/seed.sql
```

Then register an administrator manually (never commit a production password):

```bash
php -r 'require "config/app.php"; require "config/database.php"; $pdo = db(); $stmt = $pdo->prepare("INSERT INTO users (full_name,email,password,role,status,free_unlocks_remaining) VALUES (?,?,?,\"admin\",\"active\",0)"); $stmt->execute(["Admin","admin@example.com",password_hash("change-me-now", PASSWORD_DEFAULT)]);'
```

The installer is the recommended path.

### Development server

```bash
php -S localhost:8000
```

Set `APP_URL=http://localhost:8000` in `.env` when using the built-in server.

## How it works

### Collection

`modules/collection/CollectionEngine.php` loads every enabled source from `course_sources`, instantiates its adapter (`DiscUdemyAdapter`, `CouponScorpionAdapter`, `UdemyFreeAdapter`, `RealDiscountAdapter`), respects `robots.txt`, follows listing and detail pages, and normalizes extracted fields. The Udemy Free Courses adapter reads the public discovery API that backs `https://www.udemy.com/courses/free/` (`/api-2.0/discovery-units/all_courses/` with `price=price-free`) and rechecks prices through `/api-2.0/courses/{id-or-slug}/`; the Real.discount adapter reads its public JSON feed at `https://cdn.real.discount/api/courses`.

- Missing data is stored as `NULL` — nothing is invented.
- Duplicates are found by canonical Udemy course key first, normalized title second. Both source links are preserved on one course record.
- New courses are inserted as `pending` / `draft` and wait for verification.
- Every source run is recorded in `collection_logs` with counts and error messages.
- A 403 or other refusal is logged, never bypassed. The other source continues.
- Collection uses a file lock (`storage/locks`) so scheduled and manual runs cannot overlap.

#### Strict $0.00 sources

`UdemyFreeAdapter` and `RealDiscountAdapter` are **strict price sources**. Their courses are only accepted when the current enrollment price is exactly `$0.00`:

- A confirmed price above zero is skipped during collection and rejected during verification.
- An unknown or missing price is stored as `questionable` / `draft` and waits for administrator review; it is never published automatically.
- The verifier rechecks the live source price before publishing, and `UnlockService` / `access-course.php` recheck it again before unlocking or sharing the enrollment link. Recently verified free offers (15 minutes) reuse the stored result.
- Admin approval and editing reject strict-source courses unless a free price is confirmed. Bulk approval skips strict offers whose stored price is unknown or above zero.

Category assignment matches extracted category text to the seeded category list by name, slug or keyword. Categories are never invented automatically.

### Verification

`modules/verification/CourseVerifier.php` checks:

- required title, Udemy URL, coupon URL and a canonical Udemy course key
- expiry date not passed
- coupon price is known and zero
- strict price sources are rechecked live; paid → rejected, unknown → questionable, unavailable → unavailable, free → stored as `$0.00`
- Udemy destination responds (404/410 → unavailable; 401/403/429 → questionable for ordinary sources, never circumvented; 5xx/network → questionable). For strict sources a blocked Udemy page does not disqualify an offer whose free price was confirmed by the source; a 404/410 still marks it unavailable.
- coupon destination responds and resolves to Udemy where possible

Only fully verified offers are auto-published. Anything that cannot be established reliably becomes `questionable` and appears in the admin review queue. Expired/unavailable offers are unpublished but retained, together with unlock and payment history. Every run writes `verification_logs` and updates the course's notes.

The verification queue has **Approve all** (bulk approve and publish the courses currently in the queue or filter) and a **Turn off approvals** switch. With approvals off, newly collected offers are published immediately; the verifier still rechecks them on its next run and unpublishes anything that fails, while questionable offers continue to require review.

### Unlocking

`modules/payments/UnlockService.php` performs all unlock decisions server-side:

- Free unlock: DB transaction with `SELECT ... FOR UPDATE` on user and course; checks existing unlock, decrements the allowance once, inserts one unlock. The unique `(user_id, course_id)` constraint prevents doubles.
- Paid flow: creates a pending payment with a server-side generated reference and the current admin-configured price/currency, then initializes Paystack.
- Callback and webhook verify reference ownership, gateway status, exact amount in subunits, and currency before granting the unlock. Processing is idempotent.
- Before a strict-source course is unlocked (free or paid) or its enrollment link is shared, `StrictPriceValidator::ensureFree()` confirms the offer is still `$0.00`; paid, unavailable or unconfirmed offers are refused.
- `access-course.php` requires login, an available course, a confirmed free price for strict sources, and an unlock record before redirecting to the coupon or Udemy URL. Unauthorized visitors are sent back to the course page with a message.

Protected enrollment URLs are never rendered on public pages.

## Automation

Native cron (every six hours is the default; adjust intervals in Admin → Settings):

```cron
0 */6 * * * /usr/bin/php /path/to/project/cron/fetch_courses.php
15 */6 * * * /usr/bin/php /path/to/project/cron/verify_courses.php
30 */6 * * * /usr/bin/php /path/to/project/cron/check_expired_courses.php
```

Manual CLI runs:

```bash
php cron/fetch_courses.php --pages=3 --courses=40
php cron/fetch_courses.php --force        # ignores the automation toggle
php cron/verify_courses.php --limit=30
php cron/check_expired_courses.php
```

### Hosts without native cron (InfinityFree, etc.)

Use any external scheduler (cron-job.org, EasyCron, GitHub Actions, UptimeRobot) to call:

```
https://your-site.example/cron/scheduler.php?key=YOUR_CRON_SECRET
```

The scheduler runs collection, verification and expiry checks only when their configured intervals have elapsed. The `key` must equal `CRON_SECRET`; requests without it receive HTTP 403. Individual jobs also accept `?key=` for web invocation.

A successful run returns JSON. Failures are logged to `storage/logs/app.log`.

## Paystack setup

1. In the Paystack dashboard, use **test mode** while developing.
2. Add the secret key to `.env` as `PAYSTACK_SECRET_KEY` (and the public key as `PAYSTACK_PUBLIC_KEY`).
3. Set the callback URL. The application default is `${APP_URL}/payment-callback.php`; you can override it with `PAYSTACK_CALLBACK_URL`. Paystack also accepts the callback configured per transaction, which the app sends automatically.
4. Add the webhook URL: `https://your-site.example/api/payments/webhook.php`.
5. In Admin → Settings, set the free allowance (default 5) and price per additional course (default NGN 200).

Test card details and test transactions are available from Paystack's documentation. Never commit real keys; `.env` is ignored by Git and blocked by `.htaccess`.

The application verifies every transaction with `GET /transaction/verify/{reference}`. A redirect to the callback URL alone never unlocks anything. Webhook signatures (`x-paystack-signature`, HMAC-SHA512) must validate, and amounts/currencies must match the stored pending payment.

## Directory overview

```
admin/                 Admin dashboard (overview, courses, verification, sources, users, payments, settings)
api/courses/           AJAX unlock endpoint
api/payments/          Payment initialization + signed webhook
assets/                CSS design system, JavaScript, images
config/                Environment loader, DB connection, app constants
cron/                  Scheduled jobs and external scheduler endpoint
dashboard/             User dashboard (overview, courses, payments, settings)
database/              schema.sql, seed.sql
includes/              Bootstrap, auth, helpers, layout, rate limiter
modules/
  collection/          Collection engine
  payments/            Paystack client + unlock service
  sources/             DiscUdemy, CouponsCorpion, Udemy Free Courses and Real.discount adapters
  verification/        Verification engine
storage/               Logs, locks, caches (not web accessible)
install.php            One-time installer
access-course.php      Protected enrollment redirect
payment-callback.php   Paystack return handler
router.php             Path-protection router for PHP's built-in server (Pxxl/local)
pxxl.toml              Pxxl build and start configuration
composer.json          Minimal file used by Pxxl to detect PHP; no dependencies
```

## Security notes

- Passwords use `password_hash()` / `password_verify()`.
- All SQL uses PDO prepared statements.
- CSRF tokens are required for every state-changing form and AJAX call.
- Sessions are HTTP-only, SameSite=Lax, regenerated on login.
- Login, registration, password reset and payment initialization are rate limited.
- Output is escaped with `e()`; admin actions are role-checked server-side.
- `.env`, SQL files, logs, locks and internal directories are denied by `.htaccess` on Apache. On PHP's built-in server, `router.php` enforces the same rules.
- Admin passwords are never hardcoded; the installer creates the first admin.
- Price, currency and allowance always come from server-side settings.
- Payment status is only accepted from Paystack verification or a signature-valid webhook.

## Deployment notes

### Pxxl (pxxlspace)

Pxxl runs this project as a PHP Web Service with a managed MySQL database. The repository ships with `pxxl.toml`, `router.php` and a minimal `composer.json` (detection only, no dependencies).

1. Push the project to GitHub or GitLab, then create a Web Service project on Pxxl.
2. Keep the included `pxxl.toml`. It selects PHP 8.3, uses port `3000`, and starts `php -S 0.0.0.0:${PORT:-3000} router.php`. Pxxl injects `PORT` automatically; set the project port to `3000`.
3. Create a managed MySQL database, link it to the project, and add these environment variables in Pxxl as **runtime** variables (do not upload `.env`): `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, `APP_TIMEZONE`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_CALLBACK_URL`, `CRON_SECRET`, `COLLECTION_MAX_PAGES`, `HTTP_TIMEOUT`. No build-time variables are required because there are no Composer dependencies or build steps.
4. Redeploy, open `/install.php`, and create the administrator. The installer uses the existing managed database and no longer requires `CREATE DATABASE` permission.
5. Attach a persistent volume to `storage/` so logs, locks and caches survive redeploys. Keep one replica so PHP sessions and job locks stay consistent.
6. Pxxl cron jobs are HTTP requests with a maximum 30-second timeout. Point them at `https://your-domain/cron/scheduler.php?key=YOUR_CRON_SECRET` and keep the collection budget small (`COLLECTION_MAX_PAGES=1` or `HTTP_TIMEOUT` below 30). For larger runs, use the project terminal/CLI. The app still enforces its own time budget and file lock.
7. Confirm the runtime has `pdo_mysql`, `curl`, `dom`, `mbstring` and `openssl`. If an extension is missing, deploy with a Dockerfile based on an official PHP image that includes it.
8. `router.php` blocks `.env`, `config/`, `database/`, `includes/`, `modules/`, `storage/`, SQL/log/lock files and other internals when PHP's built-in server is used. For production traffic, a Dockerfile with Apache or nginx is recommended over the single-process built-in server.

### InfinityFree

- Upload the project to `htdocs`, keep `.htaccess`.
- Create the MySQL database in the control panel and set the host/user/password/database in `.env`. The installer uses the existing database, so no `CREATE DATABASE` privilege is required.
- Confirm PHP 8.x, cURL and PDO MySQL are available for your account.
- `.htaccess` continues to protect `.env`, `config/`, `database/`, `includes/`, `modules/` and `storage/`. `pxxl.toml`, `router.php` and `composer.json` are harmless on shared hosting and can be uploaded with the rest of the project.
- InfinityFree disallows some outbound hosts and does not provide native cron. Use the external scheduler endpoint above and expect source HTTP requests to fail if the host blocks them; failures are logged and do not break the public site.
- Set `APP_URL` to the full site URL (including subfolder if any) and `APP_DEBUG=false`.

### Standard cPanel hosting

- Upload files, create a database and user in cPanel, set `.env`.
- Add real cron entries pointing at `/usr/local/bin/php /home/USER/path/cron/fetch_courses.php`.
- Point the document root at the project root. If you must use `public_html`, all `.php` entry points remain compatible because includes use absolute paths; keep `.htaccess` in place.
- Enable HTTPS and set `APP_URL=https://...`.

### Migration

The application has no server-specific code. Copy the files, copy `.env` (or recreate it), export/import the MySQL database, then point cron/webhooks at the new domain. Keep `storage/` writable.

Existing installations upgrading to the strict free-course sources should run:

```bash
mysql -u root -p freecourse_hub < database/migrations/2026_11_13_add_free_course_sources.sql
```

The migration only inserts the `Udemy Free Courses` and `Real.discount` rows and is safe to run more than once. Fresh installs get them from `database/seed.sql`.

## Testing checklist

Authentication:

- register with a new email, reject duplicates, log in/out
- password reset link flow (in debug mode the link is shown)
- unauthorized visits to `dashboard/`, `access-course.php` and `admin/` are blocked

Collection:

- run `php cron/fetch_courses.php --pages=1 --courses=5`
- confirm `collection_logs`, new pending courses and duplicate detection across sources
- disable a source and confirm the other still collects
- confirm Real.discount ads and non-Udemy entries are ignored, and paid offers (`sale_price > 0`) are skipped
- confirm Udemy Free Courses access failures (for example an API 403/429) are logged gracefully without bypassing them
- confirm a strict source with an unknown price is stored as `questionable` / `draft`, never published
- confirm a strict source confirmed at `$0.00` can be unlocked and accessed; a later paid recheck blocks both

Verification:

- `php cron/verify_courses.php --limit=5`
- questionable courses appear in the admin queue and are not public
- expired coupons are unpublished and preserved

Monetization:

- new user gets the configured free allowance
- unlocking decrements exactly once and repeat visits do not charge
- with allowance exhausted, checkout initializes; a test Paystack payment creates one paid unlock
- a failed/cancelled payment does not unlock
- reusing a callback reference is idempotent and does not duplicate unlocks

Admin:

- course publish/unpublish/edit/approve/reject/expire/delete
- source enable/disable and manual collection
- user suspend/reactivate and allowance adjustment
- payment filter and CSV export
- settings validation (currency, timezone, intervals, amounts)

## Troubleshooting

- **“Database unavailable”** — check `.env` credentials and that MySQL is running.
- **Installer keeps redirecting** — `ensure_installed()` checks the `users` table; confirm the database was imported.
- **No courses appear publicly** — collection may not have run, or everything is awaiting verification. Check Admin → Verification and Sources.
- **Collection logs show 403/robots errors** — the source refused access. This is expected behavior; the system does not bypass restrictions.
- **Paystack initialization fails** — verify `PAYSTACK_SECRET_KEY`, the currency, and that `unlock_price` is greater than zero.
- **Cron does nothing** — check `CRON_SECRET`, job locks in `storage/locks`, and `storage/logs/app.log`.
- **Pxxl deploy fails or returns 502** — confirm `pxxl.toml` is present, PHP 8.3 is available, the start command binds to `0.0.0.0:$PORT`, and the managed database variables are set. Check the deployment and runtime logs.
- **Installer fails on managed/shared hosting** — create the database in the hosting panel first. The installer no longer requires `CREATE DATABASE`, but the database user must be able to create tables.
- **Internal files are downloadable on Pxxl** — make sure the start command includes `router.php`: `php -S 0.0.0.0:${PORT:-8080} router.php`.

## License

FreeCourse Hub is released under the [MIT License](LICENSE).

Copyright (c) 2026 RalphaelofDefi — <https://github.com/RalphaelofDefi>

## Attribution

The MIT License requires the copyright notice and permission notice in
[LICENSE](LICENSE) to be retained in all copies or substantial portions of the
Software. If you reuse, modify, or redistribute this project, keep the
[LICENSE](LICENSE) and [NOTICE](NOTICE) files and credit the original author:

- **RalphaelofDefi** — <https://github.com/RalphaelofDefi>
- **FreeCourse Hub** — <https://github.com/RalphaelofDefi/FreeCourseHub>

A visible credit in your project README or site footer is appreciated.
