# API boundary — prepared, provider communication pending

`Json.php` is an offline, tested JSON object codec. It rejects malformed/oversized/nonobject envelopes and preserves large identifiers as strings. It is not a REST endpoint or a PayMongo client.

Phase 3 uses the codec in `public/api.php` for a local, read-only parking availability JSON endpoint. This internal endpoint is not PayMongo integration or completed CIT 305 two-system evidence.

Phase 6 will add the PayMongo HTTPS transport here (fixed official origin, TLS verification, timeouts, safe HTTP error handling, server-side Basic authentication, and no secret logging). Transport errors must not turn into paid states. Use `config/payments.php` privately. No network call is enabled by the present scaffold.
