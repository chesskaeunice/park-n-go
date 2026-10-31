# Park n Go

**Your Space. Your Pace.** A native PHP smart-parking application, developed in eight phases. Phases 1–6 are implemented in local development: accounts/roles, database parking maps/inventory, reviewed fee estimates, reservation holds, Maya Sandbox checkout and server-side verification, QR tickets, parking sessions, scanning, and overtime foundations. Production payment deployment and final operational hardening are not complete.

The current interface is a **mobile app UI** in the sage palette (primary `#8B9A6E`, white background, surface `#EAE2D6`, neutral `#EEEEEE`). It has a phone-width app shell with bottom tabs, an animated launch screen, a four-step onboarding tour on first launch, a stall-style parking map, and installable-app support. See [docs/MOBILE_UI.md](docs/MOBILE_UI.md) for details, replay options (`&tour=1`, `&splash=1`), and the files changed. The earlier Light Midnight Electric notes remain in [docs/DESIGN_REFINEMENT.md](docs/DESIGN_REFINEMENT.md). All illustrations and fonts are local files; nothing is loaded from external sites. Phase 3 retains this theme and preserves existing data; see [Phase 3 implementation and testing](docs/PHASE_3.md).

## Run in XAMPP

Requires PHP 8.2+ (`pdo_mysql`, `mbstring`, `gd`), Apache, Composer, and MySQL/MariaDB from XAMPP. Composer supplies the local QR image generator.

1. Keep the project at `C:\xampp\htdocs\park-n-go`.
2. Start **Apache and MySQL** in XAMPP Control Panel.
3. A local `.env` was created from `.env.example`. Check its database credentials and `APP_URL`. If setting up a fresh checkout, copy `.env.example` to `.env` first. Default XAMPP configuration is host `127.0.0.1`, port `3306`, user `root`, empty password, database `parkngo_db`.
4. From the project directory, run `composer install`, `php database/migrate.php`, then `php database/seeders/development.php`. Migration creates the database and all tables. The local-only seeder prints randomly generated initial passwords for `driver@parkngo.test`, `attendant@parkngo.test`, and `admin@parkngo.test`. Save them privately. Rerunning the seeder preserves existing account passwords and roles.
5. Open `http://localhost/park-n-go/public/`. Register a driver or log in with a seeded account. Accounts use dedicated dashboards according to role.

The project's normal XAMPP database was not created automatically because the XAMPP MySQL service was stopped during implementation. Integration checks used a separate, private test instance on port 3308; the application `.env` remains configured for your normal XAMPP instance on port 3306.

The project-root `.htaccess` denies access outside `public/`, and `public/.htaccess` grants access to the public entry point. This requires Apache 2.4 with `AllowOverride All`. Do not remove the root protection to fix an Apache configuration issue.

For the recommended public-only document root, add this to `C:\xampp\apache\conf\extra\httpd-vhosts.conf` (keep any existing virtual hosts), add `127.0.0.1 parkngo.local` to your Windows hosts file with administrator access, and restart Apache:

```apache
<VirtualHost *:80>
    ServerName parkngo.local
    DocumentRoot "C:/xampp/htdocs/park-n-go/public"
    <Directory "C:/xampp/htdocs/park-n-go/public">
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
</VirtualHost>
```

Then set `APP_URL=http://parkngo.local` and visit `http://parkngo.local/`. The PHP development server is another option, run from the project directory:

```powershell
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080/` and set `APP_URL=http://127.0.0.1:8080` so password reset links point to the right server. Always use `-t public`; the development server does not enforce `.htaccess`.

## Phase 1 features and files

- `public/index.php`, `routes/web.php`, `app/bootstrap.php`, `config/app.php`: allowlisted routing, output escaping, security headers, and Manila timezone.
- `app/views/layouts/main.php`, `app/views/public/*.php`: responsive homepage, about, how it works, searchable sample facilities, account forms, and error pages.
- `public/assets/css/app.css`, `public/assets/css/account.css`, `public/assets/js/app.js`, `public/assets/images/mark.svg`: Light Midnight Electric styling, existing favicon, navigation, illustrative parking map, floor switching, and selection details.
- `.htaccess`, `public/.htaccess`, `.gitignore`, `.env.example`, `composer.json`: public-only access protection, ignored private runtime data, and configuration. Phase 2 loads `.env`; process environment variables take precedence.
- `docs/DEVELOPMENT_PLAN.md`: remaining phases and backend correctness requirements.
- Phase 2 files and implementation details are listed in [docs/PHASE_2.md](docs/PHASE_2.md).

## Phase 2 accounts and recovery

Registration always creates a driver. Login checks the current database role and account status. Protected driver, attendant, and admin pages deny other roles. Logout is a CSRF-protected POST. Name and optional phone number can be changed in Profile; email changes are not enabled pending a verified-address workflow.

For local password recovery, log out, open **Forgot password**, submit your email, and open the newest matching message in the private `storage/mail/` directory on disk. Open its link to choose a new password. `MAIL_TRANSPORT=log` writes local messages only; no external email is sent. Reset links expire after 30 minutes, are single-use, and invalidate other sessions on success. Keep the outbox private.

For real delivery, configure an actual MTA or XAMPP-compatible mail relay, set `MAIL_TRANSPORT=mail` and a verified `MAIL_FROM`, and test delivery end to end. That external infrastructure and inbox delivery have **not** been verified. Nonlocal environments require HTTPS and do not allow log transport. No payment or mail-service credentials are included.

See [docs/PHASE_2.md](docs/PHASE_2.md) for historical account-test details. Maya Sandbox setup uses the `MAYA_*` values in `.env`; the secret key remains server-side. Payment return verification can run locally without a public webhook endpoint, while signed webhook support remains available for deployed environments. Phase 3 and Phase 4 historical records are documented in [docs/PHASE_3.md](docs/PHASE_3.md) and [docs/PHASE_4.md](docs/PHASE_4.md).

## Phase 3 parking inventory

Open **Find Parking**, search by name/address/city, and choose **View facility**. Select a floor, arrival date/time, and 1–24 hour duration, then check availability. Manila times are converted to UTC by PHP. Leaving both arrival fields empty checks now. The map uses MySQL records, updates via Fetch, and has a 45-second refresh while visible. Available slot selection only shows details; it never reserves or holds a space.

Administrators can open **My Dashboard → Facilities / Floors / Slots** to create, edit, disable, and re-enable inventory. All actions require an admin role and CSRF. Floors/slots stay attached to their original parent. Slot labels and map coordinates must be unique within a floor. Active commitments block disruptive inventory changes; records are never deleted. Newly created facilities have no price until a rate is configured in the database; rate management/payment workflows arrive later.

Phase 3 itself needed no migration; Phase 4 adds `002_reservation_checkout.sql`. This workspace's `parkngo_db` is already migrated. Other installations should run `php database/migrate.php`; do not drop/reimport the database. `php tests/phase3.php` runs inventory checks only against a matching isolated `_test` database and loopback server.

## Phase 4 reservations and holds

Log in as a driver, choose a facility/floor/time/slot, then open **Review reservation**. Inspect the PHP-calculated estimate and submit **Start checkout hold**. A ten-minute hold and **pending** reservation are saved atomically; no money is collected and entry is not confirmed. Repeated submissions reuse the same reservation. Open **My reservations** from the dashboard or **Bookings** in mobile navigation to inspect/cancel your records.

Expiry immediately stops blocking availability. Checkout/history/new hold activity also persists expiry; run `php database/expire-holds.php` every minute through Windows Task Scheduler for unattended cleanup. No scheduled task was installed automatically. `config/reservations.php` sets hold/review duration and maximum live checkouts; facility cancellation cutoffs come from `parking_rates` snapshots.

The additive migration was applied after isolated tests; original row snapshots and `.env` matched before/after. See [docs/PHASE_4.md](docs/PHASE_4.md) for all changed files, fee/cancellation semantics, setup, concurrency tests, and unfinished features. Confirmed paid/refunded transactions, QR tickets, and actual parking sessions are not simulated.

The Phase 2 continuation also fixes logout during database outages and checks session-start failures. `php database/check.php` inspects the configured schema without changing data. `php tests/phase2-preparation.php` runs offline JSON/configuration checks. Full account tests now preserve existing throttle records; use a fresh isolated `_test` database per run.

## Verify Phase 1

1. Open Home, Find parking, How it works, and About us. All navigation and call-to-action links lead to implemented pages.
2. Search for `Makati`; only Central Square appears. Search for `unknown` to see the empty state. Clear the search. Special characters in the query must appear as text.
3. Open a facility detail page, select an available slot, and inspect its floor/location/initial rate. Select another; only one stays selected. Clear selection. Choose another floor and submit to refresh. Floors and slots reflect database inventory; selection creates no hold or reservation.
4. At widths of 375, 768, and 1440 pixels, check stacking, mobile menu, touch targets, and horizontal map scrolling. Use keyboard Tab/Enter and Escape in the menu. Check reduced-motion settings and the skip link.
5. Account links now display functional registration, login, and password recovery forms. Follow the Phase 2 checks in `docs/PHASE_2.md`.
6. Open `index.php?page=missing`: a 404 page and HTTP 404 should be returned. `index.php?page[]=home` should also return 404 without a PHP warning.
7. Under Apache, requests to `/park-n-go/.env.example`, `/park-n-go/config/app.php`, and `/park-n-go/composer.json` must return 403. Directory listings must be disabled.
8. Lint PHP files from PowerShell:

```powershell
Get-ChildItem app,config,public,routes -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

## Phases 5 and 6

QR tickets are issued only after an authoritative verified Maya payment. The driver ticket page renders a local QR image from the opaque token and retains a manual fallback code. Authorized attendants can validate entry and exit; active parking sessions track purchased time, facility-specific overtime rates, duplicate scans, and ticket replay. Payment polling and signed Maya webhook fulfillment share the same idempotent service.

Run the deterministic payment and QR checks with a separate database, for example `parkngo_phase6_test`, using `php tests/phase6-flow.php`. Run the actual Maya retrieval smoke test only with an explicitly selected Sandbox attempt using `php tests/phase6-payment-retrieval.php`; it must not be used as proof of production readiness.

## Unfinished

Phases 7–8: broader operational dashboards, reports, receipts/refunds, actual overtime settlement, camera/physical-device validation, production HTTPS deployment, backup/restore evidence, monitoring, and final integration/deployment review. Maya remains Sandbox-only in this workspace. Physical-device testing, HTTPS deployment, and real email delivery still need verification. This remains a development preview.
