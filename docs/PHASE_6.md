# Phase 6: parking operations, Exit Kiosk, overtime, and extensions

Status (31 October 2026): functionally complete in local development, verified with deterministic mocked-provider tests. Live Maya Sandbox verification of overtime and extension payments is still outstanding (see the end of this file).

## Business rules as implemented

- **Overtime** starts immediately after `paid_until`, with no initial grace. It is charged for the elapsed seconds at the session facility's `overtime_hour_centavos`, in integer centavos, rounding the total up once: `ceil(rate × seconds / 3600)`.
- **Settlement** applies only after Maya reports success and the server verifies it. A browser redirect is not proof. Verification starts a 15-minute exit grace at the server verification time. Duplicate webhooks or polls never renew it. Overtime that accrues after grace is billed from `grace_until`, so settled intervals never overlap.
- **Extensions** are sold in 1, 2, or 3-hour blocks, up to 6 hours per purchase, at the facility's hourly rate (`succeeding_hour_centavos`) on the reservation's rate row.
  - **Time base (approved 31 October 2026):** an extension starts at `max(paid_until, now)`. Within paid time it is contiguous. During settlement grace it starts at the server time and replaces the remaining grace, so settled overtime is never billed again.
  - **Capacity:** an extension may not overlap the slot's confirmed reservations, live holds, or pending initial checkouts. The buffer is 0 minutes, so ending exactly when another booking starts is allowed. Capacity is checked again at fulfilment.
  - **Overdue sessions** must settle overtime first. While an extension checkout is pending, a separate overtime checkout is refused, because the contiguous extension already covers that overtime.
- **Reconciliation:** a payment that succeeds after its checkout failed or expired, or that can no longer be applied safely, moves the attempt and its ledger row to `reconciliation`. This covers a session that has exited, a changed `paid_until`, an interval already covered, or a capacity conflict. No refunds or waivers are issued automatically.
- **Pending checkouts** expire after 10 minutes. The attempt and its ledger row expire together, either when the next checkout starts or through `database/expire-holds.php`. Polling a swept checkout still asks Maya once, so a late success is reconciled rather than lost.
- **Checkout creation failures** (Maya disabled, missing credentials, provider error) mark the attempt and ledger row `failed`, show a generic dashboard message, and allow a clean retry. An in-flight or orphaned attempt never causes a second Maya checkout.
- **Exit:** the same QR ticket stays valid for exit while its session is active, even past the entry-window expiry. Confirm Exit completes the session, reservation, and ticket, and releases the reservation's checkout hold in one transaction.

## Running the tests

`php tests/run-phase6.php [suite ...]` runs every suite against a **fresh** `parkngo_p6c_<suite>_<run>_test` database migrated from 001.

- **Same environment everywhere:** the PHP development server, the loopback fake Maya (`tests/fixtures/fake-maya.php`), and the test process share one environment. The harness confirms through the bootstrap's test-database header that the server is on the same `APP_ENV`/`DB_DATABASE`.
- **Process safety:** it stops only the processes it started, and refuses to run if ports 8091 or 8093 are already in use.
- **Controlled clock:** `tests/fixtures/clock-router.php` controls the server's clock for grace-boundary tests. `ParkNGo\Services\Clock` refuses freezing outside `APP_ENV=local` with a `*_test` database. The Maya API origin override (`MAYA_TEST_API_ORIGIN`) carries the same restriction and accepts only `http://127.0.0.1:<port>`.

| Suite | Coverage |
| --- | --- |
| `extensions` | Block pricing, combinations, invalid durations, facility rates, provider outcomes, duplicates, expiry, Maya failures, overdue rules, approved time base, capacity |
| `concurrency` | Separate processes: simultaneous extensions, holds, new reservations, expiry during verification, simultaneous callbacks |
| `grace` | Kiosk and driver HTTP at exact second boundaries with a controlled clock |
| `http_flow` | Reservation → Maya-verified payment → QR → entry → extension → kiosk exit, plus CSRF, ownership, kiosk, and replay denials |
| `browser` | Headless Chrome via DevTools: mobile dashboard controls, QR screenshot decode and upload, manual code, service-worker cache contents |
| `migration` | Migration rerun, checksums, ledger constraints |
| `regression`, `settlements`, `kiosk_*`, `phase2`–`phase4` | Earlier suites, each on its own fresh database |

Result on 31 October 2026: all 15 suites passed (477 checks). The normal `parkngo_db` was not modified.

## Deployment notes

- **Migrations:** `parkngo_db` is at migration 005. Migrations 006 and 007 were rehearsed on a copy of it. They applied cleanly, left existing row counts unchanged, and reran as a no-op. Back up first, then run `php database/migrate.php`.
- **Strict SQL mode:** the local MariaDB `sql_mode` is not strict, so an invalid ENUM value would be stored as `''`. CHECK and foreign-key constraints are enforced. Enable `STRICT_TRANS_TABLES` in deployment.
- **Expiry job:** schedule `php database/expire-holds.php` every minute. It also expires lapsed overtime and extension checkouts.

## Outstanding: live Maya Sandbox

- **Configuration:** the canonical project has no `.env`. Live checks need `MAYA_ENABLED=true`, `MAYA_MODE=sandbox`, and `MAYA_PUBLIC_KEY`/`MAYA_SECRET_KEY`.
- **Webhooks:** signed webhooks also need `MAYA_WEBHOOK_PUBLIC_KEY_1`/`_2` and a public HTTPS URL for `public/maya-webhook.php` (Maya source IPs are allowlisted).
- **Manual step:** a person must complete the Sandbox payment page.
- **To confirm live:** that Maya Checkout webhook `id` equals the `checkoutId` used for retrieval. A different `id` on an already verified attempt is flagged with `payment.duplicate_provider_payment_review`.
