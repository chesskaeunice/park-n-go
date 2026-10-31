# Mobile app UI (sage palette)

Park n Go now looks and behaves like a phone app while keeping the same PHP backend, routes, security rules, and data. This redesign follows the Figma file *Park-N-Go Mobile App* (the 12 driver screens plus the Components page).

## What changed

| Area | Change |
| --- | --- |
| Look and feel | Phone-width app shell (centred on desktop), sticky app bar, rounded cards, pill buttons, Jost typeface, and the user's Park N Go logo. |
| Palette | Primary `#8B9A6E`, background `#FFFFFF`, surface `#EAE2D6`, neutral `#EEEEEE`, cream `#F7F2EB`. Three darker shades are derived for readable text: ink `#2F3327`, muted `#6B695E`, primary‑dark `#5E6B45`. All are CSS variables at the top of `public/assets/css/app.css`. |
| Navigation | Bottom tab bar per role: guest (Home, Explore, Guide, Account), driver (Home, Explore, Bookings, Profile), attendant (Home, Entry, Exit, Profile), admin (Home, Inventory, Explore, Profile). Detail screens use a back button. The driver tab bar keeps the `driver-bottom-nav` class that the tests check for. |
| Launch animation | On the first screen of each session, a sage launch screen animates the logo: the P rises, the arrow orbits into place, then "PARK N GO" spells out. Tap to skip. It is skipped when the device asks for reduced motion. |
| Onboarding tour | On first launch (guests and drivers), a four‑step swipeable tour explains how the app works: find your space, pick your exact slot, hold it and pay with Maya, scan in and out. Each step has an animated illustration. Skip, dots, arrow keys and swipe all work. |
| Parking map | Slots are drawn as parking stalls with top‑down cars, status colours, and a sticky "selected space" sheet. The live refresh logic is unchanged. |
| Driver home | Shows a greeting, a search box, the next pending or confirmed booking, shortcuts, and a facility carousel. Served by the new read‑only `DashboardController`. |
| Installable | `public/manifest.json`, app icons and a small `public/sw.js` let the app be added to a phone's home screen. The service worker caches **only** `assets/`. Pages, the availability API, payments and tickets always go to the network. |

## Replaying the animation and the tour

- **Tour:** Profile → *Replay the app tour*, How It Works → *Replay the app tour*, or add `&tour=1` to any URL.
- **Launch animation:** add `&splash=1` to any URL, or open the site in a new tab or window.
- **Reset everything:** clear the site data in the browser (the flags are `png-onboarded` in localStorage and `png-splash-seen` in sessionStorage).

## Files

New
- `app/views/partials/ui.php`: icon, peso, illustration, initials and status helpers.
- `app/views/partials/splash.php`, `onboarding.php`, `tour.php`: launch animation, tour overlay, tour content and art.
- `app/controllers/DashboardController.php`: read‑only data for the driver home.
- `public/assets/js/boot.js`: runs before first paint to decide whether to show the launch animation.
- `public/assets/fonts/*`: Jost and Montserrat (SIL Open Font License, see the OFL files).
- `public/assets/images/*`: logo, app icons, parking illustrations, car sprites and small glyphs (all local SVG/PNG).
- `public/manifest.json`, `public/sw.js`: installable‑app support.

Rewritten
- `app/views/layouts/main.php` and every view in `app/views/public`, `driver`, `attendant` and `admin`. Form field names, IDs, CSRF fields, routes and server messages are unchanged.
- `public/assets/css/app.css`, `public/assets/css/account.css`, `public/assets/js/app.js`.

Small edit
- `public/index.php`: the `dashboard` route now loads `DashboardController` (read‑only).

## Security notes

The Content‑Security‑Policy is unchanged (`script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:`). No inline scripts or style attributes are used, and fonts and images are self‑hosted. The launch animation and tour are progressive enhancements: without JavaScript they never appear and the app works as before.

## Phone testing with XAMPP

- On the same computer, `http://localhost/park-n-go/public/` gets everything, including install and the service worker.
- From a phone on the same Wi‑Fi, use `http://<your-PC-IP>/park-n-go/public/`. The full mobile UI, launch animation and tour work. Browsers only allow *installing* a site and service workers over HTTPS or localhost, so "Add to Home Screen" over plain HTTP gives a shortcut rather than a standalone app.

## Verification

- `php -l` passes for every PHP file.
- The project's own HTTP + MySQL tests were run before and after the redesign on MariaDB 10.11 with `php -S`. The results are identical: Phase 2 passes all 54 checks, Phase 4 all 57. Phase 3 stops at "cross-facility floor rejected" after 40 passes in both the original and the redesigned code, so that check was already failing before this change.
- A real end‑to‑end run (driver log in → facility → live map → select slot → review → start checkout hold → dashboard → bookings) completed with no browser errors.
