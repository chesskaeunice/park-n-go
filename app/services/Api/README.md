# API boundary — prepared, provider communication pending

`Json.php` is an offline, tested JSON object codec. It rejects malformed/oversized/nonobject envelopes and preserves large identifiers as strings. It is not a REST endpoint or a PayMongo client.

Phase 3 uses the codec in `public/api.php` for a local, read-only parking availability JSON endpoint. This internal endpoint is not PayMongo integration or completed CIT 305 two-system evidence.

The active payment transport is Maya Sandbox in `app/services/Payments/MayaClient.php`, with fixed HTTPS origins, TLS verification, timeouts, safe HTTP error handling, server-side Basic authentication, and no secret logging. Transport errors must not turn into paid states. This API scaffold remains read-only and does not initiate payment network calls.
