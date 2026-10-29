# Phase 1 verification

Verified on 29 October 2026 using XAMPP PHP 8.2.12 and a temporary PHP development server with `public/` as the document root.

- All 11 PHP files passed `php -l`.
- Public pages, three account availability pages, CSS, and JavaScript returned HTTP 200 with expected content.
- PHP facility search returned the matching facility; an unmatched query rendered the empty state.
- An HTML-containing query was escaped in output.
- Unknown routes and array-valued route parameters returned HTTP 404.
- Configuration and environment-example paths were inaccessible through the public-only development server (404).
- Content Security Policy was present in the HTTP response.
- The temporary PHP server was stopped after verification.

Apache at `http://localhost` was unreachable during verification. Apache `.htaccess` denial behavior therefore remains unverified; use the README's checks after starting Apache. No browser automation was available: rendered appearance, actual responsive behavior, keyboard interactions, and JavaScript map selection still require the documented manual browser checks. QR scanning, database concurrency, role authorization, and gateway callback tests belong to later phases and have not been run.
