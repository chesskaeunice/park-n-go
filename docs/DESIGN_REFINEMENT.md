# Light Midnight Electric design refinement

Completed 29 October 2026. Presentation-only refinement of the existing Phase 2 application; no development phase was advanced.

## Visual changes

- White main/card backgrounds, `#F8FAFC` secondary surfaces, `#0F172A` main text, `#64748B` secondary text, `#6366F1` indigo, `#38BDF8` cyan token, and `#E2E8F0` borders. A darker indigo is used for small links and button text contrast. No glowing effects, floating artwork, gradients, or decorative card shadows.
- Simple white navigation with the existing guest/account links, mobile menu, and driver-only mobile navigation. The logo is a plain text mark and initial; no new image asset was created.
- The homepage retains its hero, search, feature, and closing structure. The four requested feature summaries and four-step process explain the planned journey without claiming that booking, payments, or QR scanning are already enabled.
- Hero, facilities, and about sections have light-gray HTML placeholders with dashed borders and responsive aspect ratios. No stock photos, external image URLs, generated images, or image downloads were added. The existing parking-map car symbols remain because they communicate occupied slots.
- Search retains its server-side location filter. Optional date/time values travel through the same GET form and are restored in the facility form by JavaScript. A visible note explains that they are planning details, not operational availability filters.
- Forms, notices, errors, buttons, facility cards, maps, profiles, and all three role landing pages share the light theme. Shared table/ticket CSS prepares consistent styling without creating new routes or functionality.
- Footer now includes branding, existing navigation, copyright, and a clearly labeled contact-information placeholder. No unprovided email or telephone number was invented.

## Modified files

| File | Change |
| --- | --- |
| `public/assets/css/app.css` | Replaced the dark visual rules with the light responsive layout, shared components, map styling, and breakpoints. |
| `public/assets/css/account.css` | Light account forms, feedback messages, logout controls, and mobile driver navigation. |
| `public/assets/js/app.js` | Added restoration of optional planning date/time fields; existing menu/map logic preserved. |
| `app/views/layouts/main.php` | White browser theme color, simplified branding/navigation, footer links/contact placeholder/copyright. Authentication branches and logout CSRF form remain intact. |
| `app/views/public/home.php` | Simplified hero and HTML image placeholder, search fields, four feature summaries, four-step process, and cleaner calls to action. |
| `app/views/public/facilities.php` | Facility image placeholders, reduced decorations, and optional planning fields. Existing PHP sample data/filter logic and all map control IDs/data attributes remain intact. |
| `app/views/public/about.php` | About image placeholder and simpler content styling. |
| `app/views/public/account.php` | Removed decorative icon/arrows; existing form actions, field names, validation attributes, CSRF, and feedback preserved. |
| `app/views/public/how-it-works.php` | Removed the decorative arrow from the existing map link. |
| `app/views/driver/dashboard.php` | Simplified the two existing cards and links. |
| `app/views/driver/profile.php` | Simplified button/link text; form behavior preserved. |
| `app/views/attendant/dashboard.php` | Simplified link text. |
| `app/views/admin/dashboard.php` | Simplified link text. |
| `README.md`, `docs/DEVELOPMENT_PLAN.md` | Current theme and design-only scope notes. |

Added this document. Browser QA scripts, result logs, and screenshots are retained privately under ignored `storage/test-runtime/design/`; they are not website images or public application assets.

## Verification

- **77 public browser checks passed** in installed headless Chrome: Home, Facilities, Login, Registration, About, and How It Works at 320, 375, 768, 1024, and 1440 CSS pixels; white/light theme and no horizontal page overflow; menu open/Escape; location search; date/time carry-through; slot selection/clearing/floor switching; single-floor restriction; internal map scrolling; intact account controls; no JavaScript errors or externally requested website resources.
- **85 authenticated browser checks passed** against isolated development accounts: all three roles' dashboards and profiles at 320, 375, 768, 800, 1024, and 1440 pixels; mobile driver navigation; login/logout; and existing form controls.
- **2 final hero-spacing checks passed** at desktop/mobile widths; fresh screenshots were captured after correcting the line-break spacing. Total: **164 browser checks**.
- Desktop/mobile homepage, mobile login/facilities, and mobile role screenshots were captured; representative screenshots were inspected visually.
- The existing **54 MySQL/HTTP account regression checks passed** in a newly created, isolated test database on a separate temporary MariaDB instance. Registration, role restrictions, password reset, CSRF, and session rules still work.
- **36 PHP files passed lint** and the application JavaScript passed syntax validation.
- Hash verification confirmed **29 protected backend/configuration files unchanged**, including `.env`, front controller/bootstrap, routes, controllers, services, middleware, configuration, migrations, and seeders. Normal `parkngo_db` was only inspected read-only; no existing account or project record was edited/deleted by this work. Account-test writes were limited to the new isolated test database.

Browser mobile emulation is not a physical-device test. No new reservation, payment, QR, or operational dashboard feature was implemented or claimed. Existing CIT 305 preparation remains pending according to its documented phases.

## Review in XAMPP

Keep the existing Apache/MySQL setup and open `http://localhost/park-n-go/public/`. Use Ctrl+F5 if old CSS is cached. Check Home, Find Parking, About Us, Login/Registration, then log in with an existing account to inspect its role dashboard and profile. The design does not require migrations, new credentials, Composer, or any installation.

Try 375px and 1440px browser widths. On mobile, open the menu; on the facilities page, the parking map should scroll inside its own panel. Enter a location/date/time on Home and submit: matching sample facilities appear and the planning details remain visible. Booking and time-specific availability remain unavailable.

## Replacing image placeholders later

Keep the existing placeholder until the user supplies an actual local file. Replace the entire `.image-placeholder` element with an `<img>` using the same size class (`hero-image`, `facility-art`, or `about-image`), a local `assets/images/...` source, a meaningful `alt`, and matching width/height attributes. The shared CSS preserves the aspect ratio and applies `object-fit: cover`. Retain the HTML placeholder whenever no real file is available; do not add an empty/broken `src` or an Internet placeholder URL.

Future reservation/payment/QR pages will inherit these shared styles when their actual implementation phase is authorized. Phase 3 remains unstarted and requires user confirmation.
