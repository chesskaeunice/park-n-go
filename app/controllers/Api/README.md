# JSON controllers and webhook ingress — pending Phase 6

Future controllers translate validated REST requests into service calls and JSON responses; they must not embed gateway logic or secrets in HTML/JavaScript. The signed PayMongo webhook handler will be an explicit POST-only route using the raw body, signature authentication, and the payments service. It will not use or start a driver session. No payment routes are installed in Phase 2, and this folder cannot accept payments or notifications.
