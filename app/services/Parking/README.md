# Parking inventory services and future sessions

Phase 3 implements `AvailabilityWindow.php` (strict Manila arrival/duration validation and UTC boundaries) and `InventoryManager.php` (transactional, audited facility/floor/slot writes). `Models\ParkingInventory` supplies public inventory and read-only derived availability. Selection creates no booking or hold. See `docs/PHASE_3.md`.

Phase 4 adds `FeeEstimate.php`, `ReservationService.php`, and `ReservationConflict.php`: reviewed server estimates, idempotent pending reservations, temporary holds/expiry, owner-scoped history/cancellation, and locked conflict prevention. A mere map selection still creates no hold; only the protected checkout POST does. See `docs/PHASE_4.md`. There is no payment-confirmation shortcut.

Parking session workflows remain pending Phase 5:

Keep authoritative entry/exit timestamps, replay prevention, slot transitions, and role-authorized entry/exit operations here. Coordinate with QR eligibility and reservation services under database transactions. Exit requires the final fee and verified outstanding balance to satisfy the configured policy. Fee calculation/payment settlement is completed in Phase 6. No session operation is implemented by this directory.
