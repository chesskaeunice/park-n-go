# QR tickets — pending Phase 5

Issue opaque, expiring, revocable tickets only after an authoritative reservation/payment eligibility check. Store token hashes, not personal details in QR payloads. Production issuance must remain unavailable while payment verification is pending; a pending or return-page-only payment cannot unlock a ticket.

Phase 5 can test issuance with explicitly isolated test fixtures, documented as fixtures, and implement the eligibility gate. A real prepaid end-to-end reservation stays blocked until Phase 6 supplies authenticated verification. No fake provider confirmation is allowed. No generator or scanner is implemented here yet.
