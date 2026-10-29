# Phase 2 — MySQL and secure accounts

Implemented on 29 October 2026. Account flows are functional; parking operations remain a preview.

## Implemented

- `.env` loading with process-environment precedence; PDO MySQL connection using native prepared statements and UTC database timestamps. Display timezone remains Asia/Manila.
- Versioned migration with checksums and a migration lock. `parkngo_db` is the default database. InnoDB tables cover every requested entity, plus migrations, password reset tokens, and shared rate-limit buckets. Money uses integer centavos; token hashes use a binary ASCII collation. Foreign keys, overlap-query indexes, and payment/event uniqueness constraints prepare the next phases.
- Local-only development seeder: three randomly passworded role accounts, three illustrative facilities, five floors, 60 slots, and sample rates. Existing accounts are preserved. No reservations, receipts, or successful payments are fabricated.
- Registration with validation, case-normalized unique emails, 12–72-byte password validation, `password_hash()` and `password_verify()`, and a fixed driver role.
- Login with generic invalid-credential messages, session ID rotation, optional password rehashing, and current account-state checks. No self-service staff registration.
- Cookie-only strict PHP sessions, HttpOnly, SameSite=Lax, Secure on HTTPS; 30-minute idle and eight-hour absolute expiry. Disabling an account or incrementing its session version invalidates access. HTTPS is required outside `APP_ENV=local`; TLS termination/proxy integration is not configured.
- CSRF on every POST; POST-only logout; role middleware checks every protected request. Staff roles have separate landing pages, not operational management screens yet.
- Atomic database throttles per IP and normalized email for login/recovery, and per IP for registration, reset, and profile updates. Attempts include successes within a fixed 15-minute window; HTTP 429 includes `Retry-After`. Client-supplied forwarding headers are not trusted.
- Profile name and optional phone editing for the logged-in user, with escaped output. Email/role updates are ignored. Driver mobile bottom navigation links to implemented pages only.
- Password recovery: generic public response, random 256-bit single-use token, hashed database storage, 30-minute expiry, invalidation of older tokens and other sessions, and serialized redemption. Token-bearing links are captured in the session and redirected to a clean URL before form rendering. Raw tokens appear only in the private recovery message and the initial link request; keep web access logs private as well.
- Local `.eml` outbox under `storage/mail/`. A PHP `mail()` adapter is available for a separately configured mail relay; delivery has not been tested. Production never uses the local log transport. Delivery errors go to private application logs without tokens and retain a generic public response.
- Audit events for registration, login outcomes, logout, profile updates, and recovery. Web exceptions display a generic 503 page; details and credentials are not rendered.

## Created or modified files

Created:

- `app/services/Environment.php`, `Database.php`, `Auth.php`, `RateLimiter.php`, `PasswordReset.php`, `Audit.php`.
- `app/controllers/AccountController.php`, `app/middleware/Access.php`.
- `database/migrate.php`, `database/migrations/001_initial.sql`, `database/seeders/development.php`.
- `app/views/driver/dashboard.php`, `profile.php`; `app/views/attendant/dashboard.php`; `app/views/admin/dashboard.php`.
- `app/views/public/forbidden.php`, `form-expired.php`, `method-not-allowed.php`, `unavailable.php`.
- `public/assets/css/account.css`, `tests/phase2.php`, `docs/PHASE_2.md`.
- Private `.env` and runtime `storage/mail/`, `storage/sessions/`, `storage/test-runtime/`, and isolated `storage/test-db/` directories (runtime contents are ignored).

Modified: `app/bootstrap.php`, `public/index.php`, `routes/web.php`, `app/views/public/account.php`, `about.php`, `how-it-works.php`, `app/views/layouts/main.php`, `.env.example`, `.gitignore`, `composer.json`, `README.md`, and `docs/DEVELOPMENT_PLAN.md`.

## Run with XAMPP

Start Apache and MySQL. Check `.env` (port 3306 normally). Run from the project root:

```powershell
php database/migrate.php
php database/seeders/development.php
```

Visit `http://localhost/park-n-go/public/`. The migration creates `parkngo_db` if absent. Its database account needs schema privileges for installation; use a separate restricted account for web runtime before deployment. Save the seeder's random passwords privately. Migration reruns skip unchanged versions; the seeder preserves existing accounts. MySQL DDL implicitly commits: a partially failed migration needs inspection, not a destructive automatic retry.

## Manual feature checks

1. Register a driver; log out and back in. Invalid fields and duplicate email must yield helpful errors without a PHP trace.
2. Log in as each seeded role and inspect its own dashboard. Attempt another role's URL directly (`?page=admin-dashboard`, `?page=attendant-dashboard`, `?page=dashboard`): expect 403. Guests must redirect to login.
3. Edit your name and phone in Profile, save, and refresh. Role and email are not editable.
4. Log out and request a recovery link. Open the matching private `.eml` message, follow the link, and choose a new password. The previous password and previously used link must fail; other logged-in sessions must lose access.
5. Try a POST without a valid CSRF value: expect 419. GET logout returns 405. Repeated invalid logins produce 429 and a wait message.
6. Use a 375-pixel browser viewport: check labels, error messages, mobile header, and driver bottom navigation. Check keyboard use and actual HTTPS cookies before deployment.
7. Under Apache verify `.env`, `storage/mail/`, `storage/sessions/`, and `database/` cannot be fetched. The PHP dev server must always have `public/` as its document root.

## Automated integration checks

Use a **fresh dedicated database for each run** whose name ends in `_test`. The runner refuses other names, checks that the loopback HTTP server exposes a matching local-test database fingerprint before any mutation, and preserves every existing record, including throttle buckets. Use a private loopback development server; tests do not follow remote URLs. Database fingerprints are emitted only for local `_test` environments. Reusing a database immediately can hit previously accumulated throttles; choose a new name rather than deleting records.

For this workspace, a separate MariaDB data directory was initialized at `storage/test-db`, isolated from all existing XAMPP data. To reuse it, start this in its own terminal (omit installation if it already exists):

```powershell
# Only for a fresh test data directory:
# & C:\xampp\mysql\bin\mysql_install_db.exe --datadir=C:\xampp\htdocs\park-n-go\storage\test-db --port=3308
& C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\htdocs\park-n-go\storage\test-db\my.ini --bind-address=127.0.0.1 --port=3308 --console
```

In a second terminal:

```powershell
$env:APP_ENV='local'
$env:DB_PORT='3308'
$env:DB_DATABASE='parkngo_phase2_RUNID_test'
$env:APP_URL='http://127.0.0.1:8088'
$env:MAIL_TRANSPORT='log'
php database/migrate.php
php database/seeders/development.php
php -S 127.0.0.1:8088 -t public
```

In a third terminal set the same variables, then run:

```powershell
$env:APP_ENV='local'
$env:DB_PORT='3308'
$env:DB_DATABASE='parkngo_phase2_RUNID_test'
$env:APP_URL='http://127.0.0.1:8088'
$env:MAIL_TRANSPORT='log'
php tests/phase2.php
```

Replace `RUNID` with a new identifier for each run; use the exact same database name in the server and runner terminals. The test script needs PHP's cURL extension. Shut down the test instance cleanly with `C:\xampp\mysql\bin\mysqladmin.exe --host=127.0.0.1 --port=3308 --user=root shutdown` and stop the PHP test server with Ctrl+C when finished. These variables belong only in test terminals, not the normal application `.env`.

## Verification and limits

**Results: 54 integration checks passed; all 30 PHP files passed syntax checks; existing JavaScript passed `node --check`.** Real MariaDB 10.4.32 / PHP 8.2.12 checks cover schema creation, migration rerun, seeding and repeat seeding, actual HTTP registration/login/logout, password hashing and byte limits, mass-assignment rejection, all role boundaries, escaped profile output, CSRF, session rotation and both idle/absolute expiry, disabled-account revocation, recovery issuance/redemption/replay/expiry, session invalidation, rate limits, and persisted audit events. The temporary PHP and MariaDB test processes were stopped after validation. No normal XAMPP databases were modified.

No browser automation, physical mobile/device test, actual HTTPS connection, or real mail inbox delivery was performed. Apache private-path HTTP checks subsequently passed during the CIT 305 review below. The normal configured XAMPP database remained unreachable, so application migrations still need to be run against the normal XAMPP instance. Booking/payment/QR concurrency tests belong to their implementation phases.

## CIT 305 continuation and regression results

The existing design, account flows, schema, users, and data were preserved. No Phase 3 work was started. Full review, two-system architecture, module contracts, PayMongo reference links, ten future integration test cases, and an evidence-status checklist are in [integration/CIT_305.md](integration/CIT_305.md).

Phase 2 fixes:

- `app/controllers/AccountController.php` and `routes/web.php`: valid CSRF-protected POST logout clears authentication before its best-effort database audit. Guests can also clear an expired session. A database outage cannot retain login credentials.
- `app/bootstrap.php`, `app/services/Auth.php`, and `public/index.php`: session directory/start/renewal errors are checked; startup fails closed with a generic 503 response and private logs.
- `tests/phase2.php`: no existing throttle records are deleted; reset-token expiry checks use the actual database hash rather than filename ordering.

Preparation files:

- `.env.example` and `config/payments.php`: optional empty PayMongo secret/webhook variables and disabled Phase 6 configuration. No current payment service can be enabled through these settings.
- `app/services/Api/Json.php`: strict offline JSON handling; `app/services/Api/README.md`, `Payments/README.md`, `Qr/README.md`, `Parking/README.md`, and `app/controllers/Api/README.md`: explicit future module responsibilities. No executable checkout/webhook/QR/session stubs were added.
- `database/check.php`: read-only CLI schema inspection; reports no credentials or account contents.
- `tests/phase2-preparation.php`, `tests/phase2-logout-outage.php`, `tests/fixtures/session-failure-router.php`: focused JSON/configuration, database-outage logout, and failed-session-storage verification.
- `docs/integration/CIT_305.md`, this file, `docs/DEVELOPMENT_PLAN.md`, and `README.md`: review, current results, updated phases, and testing instructions.

Latest verified results (29 October 2026):

- **54 real MySQL/HTTP account checks passed again** in a newly created isolated database.
- **15 offline JSON/configuration checks passed**, without any external API request.
- **3 database-outage checks passed**: protected access reports 503, logout still redirects successfully, and subsequent protected access redirects to login without requiring the database.
- A failing-session-handler fixture returned **HTTP 503 with only the expected generic message**, demonstrating the startup error boundary.
- **36 PHP files passed lint**; unchanged JavaScript passed syntax validation.
- Existing isolated database: all **18 expected tables present**; original migration checksum matches. Snapshots of all table row counts and contents were identical before/after this continuation.
- Apache: homepage and login returned 200; `.env`, `.env.example`, payment config, private mail/sessions, and database script paths returned **403**.
- Local test servers and isolated MariaDB were stopped after the checks. The existing Apache process was left running.

To reproduce the additional checks, run `php tests/phase2-preparation.php`. After the normal account tests, shut down **only the isolated test MariaDB** while retaining the matching test PHP server, then run `php tests/phase2-logout-outage.php` with the same test environment. For startup failure, use a separate terminal with `php -S 127.0.0.1:8089 -t public tests/fixtures/session-failure-router.php`, request `/index.php?page=login`, and expect HTTP 503 plus the generic unavailability text. Stop that fixture server afterward; it is never the normal app router.

These are local Phase 2 checks, not proof of PayMongo integration. Actual sandbox REST exchange, signed provider callbacks, receipts, and Postman/provider evidence remain pending Phase 6/8. Browser/mobile rendering and real email delivery still need manual verification.

## Remaining work

Phase 3 connects public facilities, floors, and maps to database inventory. Phases 4–8 add actual booking holds/conflict prevention, QR scanning, parking sessions, payments and receipts, operational admin/attendant screens, reports, and deployment checks. Email address verification/change, dedicated in-profile password change, and production mail delivery setup remain separate follow-up work; working password recovery already allows password replacement. No payment integration is claimed.

Security references used: [PHP session settings](https://www.php.net/manual/en/session.security.ini.php) and [PHP password hashing](https://www.php.net/manual/en/function.password-hash.php).
