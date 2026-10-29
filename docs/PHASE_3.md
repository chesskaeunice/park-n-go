# Phase 3 — database parking inventory and interactive availability

Implemented after explicit user authorization. Light Midnight Electric and local image placeholders are preserved. Existing data, original migrations, environment settings, and account flows were retained; no database import/reset or new migration is required.

## Implemented

- Facility search uses PDO and MySQL, matching literal name/address/city text. Only active facilities appear publicly. Existing seeded facilities remain clearly identified as development examples.
- Each facility has its own detail route and active floor selector, ordered by configured display order. Empty facilities/floors have understandable empty states. Disabled facilities and mismatched/disabled floors return 404.
- Arrival date/time and a 1–24 hour window drive availability. PHP validates exact calendar/time values, rejects arrays, past dates (beyond a one-minute form-entry tolerance), and arrivals over one year ahead. User-facing times are Manila; query timestamps are UTC. A date requires a time and vice versa; both empty means now.
- A public GET-only JSON endpoint returns facility/floor labels, slot IDs/labels/positions/statuses, status counts, initial rate in integer centavos, UTC boundaries, and check timestamps. No account IDs, reservation IDs, QR tokens, or private transaction data are returned.
- Derived statuses use half-open overlap intervals (`start < other_end AND end > other_start`): inventory disabled/maintenance first, then active occupancy, overlapping confirmed reservations, and unexpired/unreleased overlapping holds. Cancelled/pending/expired/completed reservations do not independently reserve a slot. Actual cancellation/hold workflows belong to Phase 4.
- Active sessions conservatively block a slot through future windows until exit; their end time cannot be predicted. Occupancy requires an active session with no exit and entry before the requested end. No plate recognition or OCR.
- The map is server-rendered and works without JavaScript. JavaScript enhances it through Fetch, manual refresh, and a 45-second refresh while the document is visible. Loading disables stale controls; abort/sequence handling prevents older responses replacing newer requests. Errors clear selection and keep stale slots unselectable. Refreshed availability clears a selection that has become unavailable.
- Available slots show floor, facility, initial price, and status. **Selection creates no hold, booking, QR ticket, or payment.** Initial price is not a complete duration-based quotation; fee rules are implemented later.
- Slots can be laid out on up to 12 columns × 10 rows through admin forms. Invalid/overlapping legacy coordinates produce a clearly labeled list-layout fallback without dropping slot records or altering stored positions.
- Administrators can create/edit facilities and floors, activate/deactivate them, create/edit slots, and set available/maintenance/disabled inventory status. Floor/slot labels are unique in their parent; map coordinates are checked under the facility lock. Reparenting is rejected. No delete operations exist.
- Inventory writes lock facility → floor → slot as applicable and audit successful changes in the same transaction. Existing confirmed future reservations, live holds, or active sessions block slot edits and parent deactivation. Disabling a facility/floor hides its children publicly without deleting their history. Phase 4 booking code must participate in consistent locking and recheck parent activity; current indexes alone are not a booking-concurrency guarantee.
- All admin routes require the administrator role. Every POST uses the existing CSRF check, validation, native prepared statements, and admin throttling. Driver/attendant accounts cannot POST inventory changes. Account logout/recovery behavior remains intact.

## Created files

- `app/models/ParkingInventory.php` — inventory queries and derived availability.
- `app/services/Parking/AvailabilityWindow.php`, `InventoryManager.php` — validated time windows and transactional inventory mutations.
- `app/controllers/ParkingController.php` — public inventory and authorized admin page orchestration.
- `public/api.php` — read-only availability JSON resource and JSON errors.
- `app/views/public/facility.php`, `parking-search.php`, `invalid-request.php` — details/map, shared search form, validation feedback.
- `app/views/admin/inventory.php` — facility/floor/slot management screens.
- `tests/phase3.php` — guarded MySQL/HTTP regression suite.
- This document.

## Modified files

- `app/bootstrap.php`: existing URL/redirect helpers accept query parameters; security behavior preserved.
- `app/services/Audit.php`: optional entity type, retaining the existing user default.
- `routes/web.php`, `public/index.php`: public detail and role-protected inventory routes/dispatch.
- `app/views/public/facilities.php`: replaced hardcoded samples with database records.
- `app/views/admin/dashboard.php`, `app/views/driver/dashboard.php`: real inventory navigation and current scope.
- `app/views/public/home.php`, `about.php`, `how-it-works.php`, `app/views/layouts/main.php`: accurate Phase 3 copy and footer.
- `public/assets/js/app.js`: database map enhancement, request/error/refresh/selection handling; mobile menu retained.
- `public/assets/css/app.css`: responsive inventory forms, bounded grids, disabled status.
- `app/services/Api/README.md`, `app/services/Parking/README.md`, `README.md`, `docs/DEVELOPMENT_PLAN.md`: current service/phase documentation.

No `.env`, original SQL migration, seeder, account controller, authentication service, payment configuration, or image file was changed.

## XAMPP and manual checks

1. Start the existing Apache and MySQL services. Open `http://localhost/park-n-go/public/`. Existing installations need no migration. Fresh installations use `php database/migrate.php`, then the local-only `php database/seeders/development.php`; do not reimport or drop existing data.
2. Open Find Parking and search for a seeded city. Follow View facility. Choose a floor and a valid arrival/time/duration. Submit Check availability. Check available, maintenance, and disabled slot behavior. There may be no held/reserved/occupied slots until their actual workflows exist; do not fabricate production activity to display those colors.
3. Select a space and clear it. Use Refresh availability. Confirm no reservation/hold/payment is created. Disable JavaScript and verify floor/time submission and available-space selection still render correct details through PHP.
4. Log in as an administrator. From its dashboard open Facilities, Floors, or Slots. Add a facility, add a floor beneath it, add uniquely labeled slots at different positions. Edit a label or set maintenance/disabled, then inspect the public map. A disabled facility/floor should be hidden. Save errors should remain readable, with submitted values preserved.
5. Try another role's account against inventory management URLs: expect 403. A guest redirects to login. A POST without CSRF returns 419. Derived occupied/reserved/held status cannot be manually assigned.
6. At 320/375px the page must not overflow; scroll only inside the map/table when needed. Image placeholders remain local HTML with no image fetches.

Rates for existing seeded facilities are read from `parking_rates`. Creating a facility does not invent a rate. Initial rate displays “Not configured” if none exists; rates/payment operations are outside Phase 3.

## Local JSON contract

```text
GET public/api.php?resource=availability&facility_id=1&floor_id=1&hours=2
GET public/api.php?resource=availability&facility_id=1&floor_id=1&date=YYYY-MM-DD&time=12:00&hours=2
```

Date must be valid for the current calendar/time bounds. Responses are JSON with a top-level `data` object on success or `error` string on failure. Statuses: 200 success, 404 unknown/unavailable resource, 405 unsupported method, 422 invalid window/identifier, 503 unavailable database. Responses use `Cache-Control: no-store`. No write endpoint or booking claim is included. This local PHP↔browser interface is **not** completed CIT 305 PayMongo REST integration; that remains Phase 6.

## Automated verification

Use the isolated instance/testing setup in `PHASE_2.md`, with a **new** database name ending in `_test` and the same process environment in both the PHP server and runner. Do not use the normal database for fixture tests. Example runner terminal, after the matching isolated server and seeded schema are ready:

```powershell
$env:DB_PORT='3308'
$env:DB_DATABASE='parkngo_phase3_UNIQUE_test'
$env:APP_ENV='local'
php tests/phase2.php
php tests/phase3.php
```

The Phase 3 runner confirms the loopback server's database fingerprint before writes. It creates uniquely named fixture accounts/inventory in that test database only, retains them, and deletes no existing records. Synthetic reservations/holds/sessions explicitly exercise availability; they do not represent working booking, QR entry/exit, or provider payment flows. No payment success is fabricated.

Verified results on 29 October 2026:

- **61 Phase 3 MySQL/HTTP checks passed**: admin inventory create/edit/disable/re-enable, unique labels/positions, validation, role/CSRF rejection, availability JSON and privacy, all six statuses, expired/released holds, cancelled reservations, touching time boundaries, timezone conversion, empty/unpriced inventory, parent scoping/visibility, no-JavaScript selection, stale selection conflicts, commitment guards, legacy coordinate fallback, no selection-created holds, and correct audit entity types.
- **54 existing account regression checks passed**.
- **48 headless Chrome checks passed**: database listing/detail map at 1440, 768, 375, and 320px; API-rendered slots; internal mobile map scrolling; selection/clear; floor changes; error recovery; a selected slot becoming disabled on the server; all three admin forms at each width; CSRF controls; no JavaScript runtime errors. Two additional desktop/mobile checks verified the final drive-lane typography correction.
- Desktop/mobile detail and mobile admin screenshots were captured and visually inspected. A small drive-lane typography issue found in inspection was corrected.
- **46 PHP files passed syntax checks** and JavaScript passed `node --check`.
- Existing application database was queried read-only for smoke checks; test writes stayed on a separate MariaDB instance/database. Original migrations and existing data were not reset.

Browser emulation is not a physical-device test. The map reports database state, not sensor-derived physical occupancy. Actual booking concurrency, QR camera flows, payments/webhooks, fee settlement, and external provider integration evidence are not yet implemented.

## Next phase

Phase 4 adds authoritative reservation creation, short-lived holds/expiry, estimated booking costs, cancellation policy, and simultaneous-booking conflict prevention. It must revalidate current facility/floor/slot status under database locks rather than trusting a prior availability response. This handoff completes Phase 3 only.
