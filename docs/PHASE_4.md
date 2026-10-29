# Phase 4 — reservations, temporary holds, and conflict prevention

Implemented after the user's Phase 4 authorization. Light Midnight Electric, image placeholders, existing accounts/inventory, and the PayMongo integration plan are preserved.

## What works

Drivers select a database-backed space, open a server-rendered review, inspect the estimated fee and arrival/departure, then submit **Start checkout hold**. The backend creates one pending reservation and a ten-minute hold under a database transaction. Drivers can inspect checkout status, refresh the server status, cancel eligible reservations, and browse paginated history. Account/role restrictions and CSRF protect every write.

**Pending is not confirmed.** This phase never creates a successful payment, receipt, QR ticket, or entry permission. No public action can set a reservation to confirmed. Checkout clearly explains that PayMongo hosted payment and authenticated verification arrive in Phase 6. Even a zero-fee estimate remains pending in this phase.

## Transactions and availability

- Creation locks the driver account, then facility → floor → slot. It rechecks driver status/role/session version, parent activity, slot state, arrival bounds, actual occupancy, confirmed reservations, and live holds. Current `FOR UPDATE` conflict reads prevent old repeatable-read snapshots from missing a newly committed hold.
- Overlap uses half-open intervals: `start < other_end AND end > other_start`. Touching windows can coexist; overlapping live holds/confirmed reservations cannot. An active parking session conservatively blocks future holds until exit.
- Inventory changes use the same facility/floor/slot locks. The Phase 3 commitment guard now uses current locking reads as well, so a concurrent admin disable cannot overlook a checkout committed while it waited.
- Uniquely keyed submissions prevent duplicate reservations. Each review receives a random server/session key; only its key and CSRF token are posted. Slot, user, date/time, rate, policy, and total come from server state. Repeated submissions reuse the original reservation without extending its hold. Reusing a service key for another selection is rejected.
- SQL deadlock/lock-timeout errors are retried at most twice after rollback; business conflicts are not silently retried. No network/provider operation happens inside these transactions.
- At most three live holds per driver by default. This count is serialized with the driver lock. Changing facility/floor/slot/rate while a review is open causes a fresh availability/rate check; changed quote data requires another review.
- Successful creation, cancellation, and automatic expiry generate reservation audit records. No existing record is deleted.

## Estimate and cancellation policy

All amounts are integer centavos. The quote uses the latest rate effective at arrival and stores its rate, location labels, price breakdown, and cancellation policy as a JSON snapshot. Later rate changes do not rewrite an existing reservation estimate.

For the supported 1–24 hour booking window:

1. If the entire stay is within the grace period, the estimate is zero, including no additional charge.
2. Otherwise charge the configured initial rate, then each started succeeding hour after the initial-minute allowance.
3. Apply the configured daily cap to those parking charges, if present.
4. Add the configured flat additional charge once, outside the cap.

Grace is a free-stay threshold; it is not subtracted from longer stays. Fee calculations reject values outside the supported database amount range. The current maximum estimate period is 24 hours, so multi-day allocation rules are not implied. Actual entry/exit billing and payment settlement remain Phase 6 work.

Unpaid pending holds may be cancelled immediately. Confirmed reservations require cancellation before the snapshotted arrival cutoff and no active session. Legacy confirmed records without a policy snapshot are blocked from self-service cancellation. Paid records require a verified refund workflow, which is not implemented yet; this phase refuses to cancel those rather than inventing a refund. Eligible cancellation releases the hold and revokes any existing ticket record; it does not create a QR ticket.

`config/reservations.php` sets the ten-minute hold, three-live-hold limit, and fifteen-minute review lifetime. The cancellation cutoff comes from `parking_rates.cancellation_cutoff_minutes` and is snapshotted. Administrative rate/policy screens remain a later phase; existing configured database values are honored.

## Expiry

Availability immediately ignores holds after their database expiry time, whether or not a cleanup task has run. Checkout/history access and new checkout attempts also expire relevant stale holds and pending reservations under locks. A CLI task provides periodic persisted-status cleanup:

```powershell
php database/expire-holds.php
```

Schedule it every minute in Windows Task Scheduler for unattended operation. No Windows task or background service was installed by this change. Each run processes up to 500 affected slots; repeated runs continue the batch. The task never confirms reservations or changes a payment to paid.

The browser countdown uses the server's expiry/current timestamp plus elapsed monotonic browser time; it is informational. At zero it asks for a server status refresh. JavaScript never decides payment, confirmation, cancellation, or slot ownership.

## Migration and XAMPP

`database/migrations/002_reservation_checkout.sql` adds a nullable, unique reservation request key and a hold-expiry index. The original migration is unchanged. Existing rows remain intact and existing reservations have a null request key. No table or data reset is needed.

The migration was tested on an isolated MariaDB instance, including rerun, then applied to this workspace's normal `parkngo_db`. Snapshots of all original table row contents/counts, excluding the newly added nullable field and migration-tracking entry, matched before/after. `.env` also matched. **The existing installation is already migrated.**

For another checkout/installation, start Apache and MySQL and run:

```powershell
php database/migrate.php
```

Fresh installations may run the existing local development seeder afterward. Do not drop/reimport existing databases. Then open `http://localhost/park-n-go/public/`; use Ctrl+F5 for cached styles/scripts.

## Manual feature checks

1. Log in as a driver. Find a facility, choose a floor, set a future arrival/date and duration, and check availability. Select a slot and choose **Review reservation**. Guests are asked to log in; after login, they can return through Explore and choose their space again.
2. Inspect the server-calculated amount, duration, location, and cancellation rules. Opening the review creates no hold.
3. Submit **Start checkout hold**. Checkout must show **Pending**, a hold deadline/countdown, and the explicit payment-unavailable message. The selected window should show held when checked from another browser/driver.
4. Submit the same review again or use browser Back/resubmit. It must return the same reservation; it must not create another hold or extend its deadline.
5. Open **My reservations** from the driver dashboard or **Bookings** in mobile navigation. Confirm the pending record belongs to the current account. Another driver's direct detail URL returns 404.
6. Cancel through the POST form. The record becomes cancelled, its hold is released, and the space becomes eligible again. GET requests cannot cancel. No receipt/QR is issued.
7. Let a hold expire, then refresh checkout/history or run the CLI cleanup. Its pending record becomes expired; other checkouts can use the space. Do not simulate a successful payment to bypass this behavior.
8. Try a staff account against review/history routes: expect 403. Missing CSRF on POST returns 419. Client-supplied amount, user ID, or confirmed status is ignored.

## Automated verification

Use a fresh `_test` database and matching isolated PHP server following the setup in `PHASE_2.md`. Both original and new migrations must be applied before testing. Do not run fixture suites against `parkngo_db`.

```powershell
$env:APP_ENV='local'
$env:DB_PORT='3308'
$env:DB_DATABASE='parkngo_phase4_UNIQUE_test'
php database/migrate.php
php database/seeders/development.php
# In a separate terminal with the same variables:
# php -S 127.0.0.1:8088 -t public
php tests/phase2.php
php tests/phase3.php
php tests/phase4.php
```

Use a fresh database name per complete run; the tests preserve existing throttle records. The Phase 4 runner validates the loopback server/database fingerprint before writes. It creates unique test-only accounts/inventory, retains them, and uses `tests/fixtures/reservation-worker.php` to launch separate PHP processes with independent PDO connections. File barriers synchronize their starts; tests do not rely on the single-threaded PHP web server for concurrency claims. Confirmed reservations and active sessions are explicitly isolated fixtures; no payment success is fabricated.

Verified on 29 October 2026:

- **57 Phase 4 checks passed**: fee/grace/cap/hour boundaries/overflow; stored snapshots and hold duration; idempotency; ownership; hold overlap; cancellation and expiry; stale rate and disabled inventory; hold caps; role/CSRF restrictions; amount/owner/status tampering; checkout and history; expired/unknown server-side quote keys; no partial write on conflict; saved cancellation cutoff and active-session guards; zero payment/QR/receipt creation.
- Concurrency checks include three independent overlapping races with exactly one winner each, simultaneous same-key requests returning one reservation, touching non-overlapping windows both succeeding, and booking versus admin disable producing only one valid winner.
- **54 account and 61 inventory regressions passed**.
- **23 headless Chrome checks passed**: driver navigation, selected window→review link, authoritative estimate and no overflow at 1440/768/375/320px, checkout countdown/unpaid messaging, history/table scrolling, cancellation, and server-rendered review with scripts disabled. Representative desktop/mobile screenshots were inspected.
- All **57 PHP files passed syntax checks** and the shared JavaScript passed `node --check`.
- The normal database's original rows and `.env` were preserved while applying the additive migration. Fixture writes stayed in the isolated test database. No real provider request was made.

Physical-device testing, verified refunds, PayMongo callbacks, QR generation/camera scans, actual parking fees, and final provider/Postman evidence are not covered by this phase.

## Created files

- `database/migrations/002_reservation_checkout.sql`, `database/expire-holds.php`.
- `config/reservations.php`.
- `app/services/Parking/FeeEstimate.php`, `ReservationConflict.php`, `ReservationService.php`.
- `app/controllers/ReservationController.php`.
- `app/views/driver/reservation-review.php`, `reservation-checkout.php`, `reservations.php`.
- `tests/phase4.php`, `tests/fixtures/reservation-worker.php`.
- This document. Private QA artifacts are in ignored `storage/test-runtime/phase4/`.

## Modified files

- `routes/web.php`, `public/index.php`: driver-only routes, POST-only cancellation, reservation dispatch.
- `app/services/Parking/InventoryManager.php`: current locking reads for commitment checks during concurrent booking.
- `app/views/public/facility.php`, `public/assets/js/app.js`: selected-slot review link and server-based hold countdown; existing map refresh behavior preserved.
- `app/views/driver/dashboard.php`, `app/views/layouts/main.php`: reservation history navigation, mobile Bookings, Phase 4 footer.
- `public/assets/css/app.css`: responsive review/checkout/details and four-item mobile navigation.
- `app/views/public/home.php`, `facilities.php`, `about.php`, `how-it-works.php`: accurate current-phase messaging.
- `README.md`, `docs/DEVELOPMENT_PLAN.md`, `docs/integration/CIT_305.md`, and service README contracts: current scope and handoff notes.

## What remains

Phase 5 implements secure QR generation, eligibility checks, attendant scanning, and parking sessions. Real prepaid ticket issuance must stay blocked until Phase 6 supplies authenticated PayMongo verification; isolated eligibility fixtures are not production payment success. Phase 6 completes hosted payment, confirmation, receipts/refunds and actual-time fees. Phase 7 expands operational dashboards and Phase 8 supplies final integration/security/Postman evidence. This task ends at Phase 4.
