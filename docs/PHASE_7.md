# Phase 7: admin operations, financial control, and reporting

Status (31 October 2026): functionally complete in local development, verified with deterministic tests against isolated `*_test` databases and a loopback fake Maya. Live Maya Sandbox verification (payments and refund retrieval) and deployment of migrations 006–008 to `parkngo_db` are still outstanding.

## Authorization

There is one `admin` role. Every admin can monitor operations, run reports and exports, annotate and recheck reconciliation cases, and request refunds. Four sensitive actions also need an explicit permission (`admin_permissions`, migration 008):

| Permission | Allows |
| --- | --- |
| `manage_pricing` | Scheduling new facility rates |
| `approve_refunds` | Starting review, approving or rejecting refunds, recording and checking Maya refund evidence |
| `active_session_exception` | Closing an active parking session outside the kiosk |
| `manage_permissions` | Granting and revoking these permissions for *other* admins |

- **Server-side checks:** permissions are checked on the server, inside the action's transaction, with the permission row share-locked. A revocation applies on the next request. Hidden buttons are only a convenience.
- **Denied attempts:** a denied attempt returns 403 and is written to the audit trail with outcome `denied`.
- **Bootstrap:** the first grant is made by an operator from the command line, and is audited with no web actor:
  ```
  php database/admin-permission.php grant admin@parkngo.test manage_permissions "Initial operator grant"
  php database/admin-permission.php list
  ```
- **Other protections (unchanged):** drivers, attendants, guests, and kiosk sessions are refused by the existing role and kiosk-cookie rules. CSRF is checked before any POST. Admin actions are rate limited.

## Features

- **Dashboard** (`admin-dashboard`): live, database-derived counts, filterable by facility. It reloads every 60 seconds while the page is visible and no field is being edited.
  - Facilities, slots, and available, occupied, reserved, and out-of-service slots.
  - Unpaid holds, upcoming confirmed reservations, active and overdue sessions.
  - Pending payments, open reconciliation cases, and refunds awaiting approval.
- **Occupancy** (`admin-occupancy`): every slot's derived state, using the same precedence as public availability (out of service > occupied > reserved > held > available). Each slot shows its active session, live holds, next confirmed booking, and last event.
  - **Corrections:** release stale holds (holds whose reservation is no longer pending or confirmed), or mark a slot maintenance, disabled, or available.
  - **Safeguards:** a correction needs a reason. It locks facility → floor → slot, re-derives the slot, and refuses if the records changed since the admin viewed them (fingerprint check).
  - **What it refuses:** taking a slot out of service while a booking or live checkout depends on it, or marking it available while a session is active.
- **Active-session exceptions** (`admin-session-exception`): three narrow categories, a 20-character reason, a facility confirmation, a preview, and an explicit confirmation tick.
  - Closing completes the session, reservation, and ticket, and releases the slot.
  - Payment records, including pending checkouts, are untouched. Any accrued overtime is stored as an unsettled obligation (`parking_session_exceptions`) and stays in the reconciliation queue.
  - It is not a refund and not a waiver.
- **Reconciliation** (`admin-reconciliation`): cases are derived from payment attempts and session exceptions.
  - **Case types:** late success, unfulfilled overtime or extension, payment after the hold ended, duplicate provider notification, provider/local mismatch, pending beyond its expected time, expired hold with an unresolved checkout, provider API error, manual investigation, missing provider reference, failed fulfilment, unsettled obligation.
  - **Allowed actions:** recheck with Maya (the same server-side retrieval and fulfilment path as polling), add notes, and mark a case reviewed. Admins can also recheck any payment by reference.
  - **Append-only history:** actions are stored in `reconciliation_actions`. A review is tied to the exact record state it saw, so the case reopens if the payment changes.
  - **No manual status changes:** no action marks a payment paid. A provider report that contradicts a verified payment is flagged and never reversed.
- **Refunds** (`admin-refunds`):
  - **Lifecycle:** `requested` → `under_review` → `approved` | `rejected` → `provider_submitted` → `provider_confirmed` | `provider_failed` | `reconciliation`.
  - **Eligible payments:** only provider-verified payments, including provider-paid late payments held in reconciliation.
  - **Balance protection:** approved and in-flight amounts are reserved against the payment, so refunds can never exceed it. There is one open request per payment, and requests are idempotent per form.
  - **Approval is not a refund.** Park n Go never submits refunds to Maya. Staff create the refund in Maya Manager and record its ID. Only a Maya retrieval whose ID, payment, amount, and currency match marks the refund confirmed, and the schema refuses a confirmed refund without that evidence.
  - **Nothing automatic:** no-shows, early exits, and late payments are never refunded automatically.
- **Pricing** (`admin-pricing`): changes are prospective. Each change inserts a new `parking_rates` version effective now or later (up to one year ahead, never backdated, always after the latest scheduled rate). Existing rows are never updated.
  - **What existing records keep:** each reservation keeps its `rate_id` and snapshot, so active sessions, extensions, overtime, settled intervals, and payment attempts are unchanged.
  - **Editable fields:** initial period and rate, succeeding/extension hour, overtime hour, daily maximum, additional charge, all entered as pesos and stored as integer centavos.
  - **Carried forward:** grace and cancellation cutoff.
  - **Concurrency:** a stale form is refused.
- **Reports** (`admin-reports`, exports via `admin-report-export`): reservations, occupancy, revenue, and reconciliation, filtered by facility and Manila date range (up to 366 days).
  - **One data source:** the screen, CSV, and PDF all come from `ReportService::build()`.
  - **Revenue definitions:**
    - Gross verified receipts = verified initial + verified overtime + verified extension payment attempts, each counted once. Ledger rows are not added again.
    - Net = gross − provider-confirmed refunds.
    - Pending, failed, expired, and cancelled payments are excluded. Provider-paid payments held in reconciliation are shown separately and not recognized.
  - **Date basis:** whole Asia/Manila days (UTC+08:00) converted to UTC bounds. Revenue is dated by `verified_at` and refunds by `provider_confirmed_at`. Live counts (active and overdue sessions, open cases) are snapshots at generation time.
  - **Exports:**
    - CSV neutralizes formula injection (`= + - @` tab, CR).
    - PDF is written by a small built-in generator (no new dependency) with a repeated table header and page numbers. It is capped at 2,000 rows, with a note to use CSV beyond that.
    - Exports contain no provider IDs or secrets and are audited.
- **Audit log** (`admin-audit`): `admin_audit_events` records actor, action, target, facility, reason, before and after JSON, outcome, correlation ID, and a hashed IP address. Triggers reject UPDATE and DELETE.
  - **Covered actions:** pricing, refunds, reconciliation actions, occupancy corrections, session exceptions, permission changes, denied attempts, report exports, kiosk registration, and kiosk revocation.
  - **Never recorded:** keys that look like secrets (tokens, passwords, hashes, redirect URLs, cookies) are stripped.

## Migration 008

`database/migrations/008_phase7_admin_operations.sql` is additive only:
- **New tables:** `admin_permissions`, `admin_audit_events`, `parking_session_exceptions`, `refund_requests`, `reconciliation_actions`.
- **Append-only triggers** on the audit and reconciliation tables.
- **New `parking_rates` columns:** nullable `created_by` and `change_reason`.
- **Indexes for reports:** `payment_attempts(local_status, verified_at)`, `reservations(created_at)`, `parking_sessions(exit_at)`.

Status and permission values use CHECK constraints, so they are enforced even though the local `sql_mode` is not strict. Migrations 001–007 are unchanged. The Phase 6 harness and migration test now count migration files instead of expecting exactly seven.

## Running the tests

```
php tests/run-phase7.php      # 9 Phase 7 suites
php tests/run-phase6.php      # 15 Phase 6 and earlier regression suites
```

Both harnesses use a fresh `*_test` database per suite, confirm the server is on the same database, refuse to run if ports 8091 or 8093 are busy, and stop only the processes they started.

| Suite | Coverage |
| --- | --- |
| `p7_permissions` | Ordinary admin access; each sensitive permission allowed and denied over HTTP; driver, attendant, guest, and kiosk denial; CSRF; session separation; self-grant refusal; immediate revocation; denied-attempt audit |
| `p7_occupancy` | Derived counts and states; hold and session protection; safe corrections with fingerprints; stale holds; HTTP PRG; session exceptions with obligations preserved |
| `p7_concurrency` | Separate processes: simultaneous corrections, correction vs. new booking, pricing, refund requests (duplicate and idempotent), approvals, exception closures |
| `p7_reconciliation` | Pending, failed, late success, mismatch (status and amount), duplicate callback, missing reference, failed fulfilment, review and reopen, outage, refused manipulation |
| `p7_refunds` | Request, review, approve, reject, duplicate, partial, over-refund, provider pending, confirmed, failed, amount mismatch, duplicate confirmation, permissions, audit order |
| `p7_pricing` | Valid prospective change; invalid, negative, and sub-centavo input; unauthorized; snapshots of sessions, payments, settlements, and extensions unchanged; scheduled rates; HTTP |
| `p7_reports` | Totals by type, Manila day boundaries, facility filters, refunds and net, exclusions, no double counting, CSV and PDF parity, formula injection, PDF pagination |
| `p7_browser` | Headless Chrome at 390px and 1280px: theme, metrics, correction, exception, refund lifecycle, pricing, export parity, hidden controls |
| `p7_migration` | Rerun no-op, constraints, append-only triggers, FK types, and a populated 007 → 008 upgrade with checksums |

## Deployment notes

- **Database not yet migrated:** `parkngo_db` is still at migration 005 and was not modified. Back up first, enable `STRICT_TRANS_TABLES`, then run `php database/migrate.php` (006–008).
- **Database account:** creating the triggers needs the TRIGGER privilege. If binary logging is enabled, it also needs SUPER or `log_bin_trust_function_creators`.
- **First grants:** grant permissions with `database/admin-permission.php` after migrating.
- **Live Maya:** the refund retrieval endpoint and its field names (`id`, `paymentId`, `status`, `amount`) follow Maya's Payments API but have only been exercised against the fake provider. Confirm them against the live Sandbox before relying on provider confirmation.
