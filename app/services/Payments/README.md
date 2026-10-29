# Payments — pending Phase 6

Planned responsibilities: create/retrieve sandbox hosted checkout sessions via the API adapter; persist provider checkout and payment identifiers separately; validate amounts/currency, reservation ownership, and test mode; process authenticated callbacks with database transactions and event deduplication; reconcile uncertain outcomes; issue one receipt per verified payment. Keep browser return URLs read-only.

Webhook signature verification must use the original raw body **before** `Api\Json::decode`; never re-encode JSON to compute a signature. The webhook will have provider authentication instead of browser-session CSRF, with no global CSRF bypass. It is not registered as a route in Phase 2.

No executable payment processor or success simulation exists here. See `docs/integration/CIT_305.md` for the architecture and evidence plan.
