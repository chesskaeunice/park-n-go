<?php
declare(strict_types=1);

/*
 * Onboarding tour content: the four steps of the Park n Go journey and their animated illustrations.
 * Used by the first-launch tour overlay (partials/onboarding.php) and the How It Works page.
 * Illustrations are inline SVG animated purely with CSS classes (no inline styles, CSP-safe).
 */

if (!function_exists('tour_steps')) {
    function tour_steps(): array
    {
        return [
            ['Find your space', 'Search a city or facility, set your arrival time and duration, and see which spaces are open on the live map.', 'search'],
            ['Pick your exact slot', 'Choose a floor, tap an available space, and review the estimated fee before you commit to anything.', 'car'],
            ['Hold it, then pay with Maya', 'Checkout holds your space for 10 minutes. Pay through Maya; your booking is confirmed only after the payment is verified.', 'wallet'],
            ['Scan in, scan out', 'Show your secure QR ticket to the attendant at entry and exit. Your parking session starts on a verified entry scan.', 'qr'],
        ];
    }

    function tour_art(int $step): string
    {
        $car = static fn(string $x, string $y, string $fill, string $roof, string $extra = ''): string =>
            '<g transform="translate(' . $x . ' ' . $y . ')' . $extra . '"><rect x="0" y="0" width="22" height="44" rx="7" fill="' . $fill . '"/><path d="M3 13q8-6 16 0l-1 4q-7-3-14 0z" fill="#2F3327"/><rect x="4.5" y="17.5" width="13" height="15" rx="3" fill="' . $roof . '"/><path d="M4 33q7 2 14 0l1 4q-8 3-16 0z" fill="#2F3327"/></g>';
        $svg = static fn(string $class, string $body): string =>
            '<svg class="tour-svg ' . $class . '" viewBox="0 0 280 220" role="presentation" aria-hidden="true" focusable="false">' . $body . '</svg>';

        return match ($step) {
            0 => $svg('tour-find',
                '<rect x="14" y="14" width="252" height="192" rx="28" fill="#F7F2EB"/>'
                . '<path d="M14 132H266M118 14V206M14 70 266 176" stroke="#EAE2D6" stroke-width="12" fill="none"/>'
                . '<g class="t-search"><rect x="34" y="30" width="212" height="40" rx="14" fill="#FFFFFF" stroke="#EAE2D6" stroke-width="2"/><circle cx="56" cy="49" r="7" fill="none" stroke="#6B695E" stroke-width="2.5"/><path d="m61 54 5 5" stroke="#6B695E" stroke-width="2.5" stroke-linecap="round"/><rect class="t-typing" x="74" y="44" width="110" height="10" rx="5" fill="#D6CDBE"/></g>'
                . '<circle class="t-pulse" cx="170" cy="116" r="16" fill="#8B9A6E"/>'
                . '<g class="t-pin"><path d="M170 84c-13 0-22 9-22 21 0 15 22 32 22 32s22-17 22-32c0-12-9-21-22-21z" fill="#8B9A6E" stroke="#FFFFFF" stroke-width="3"/><path d="M165 113V96h7c8 0 8 11 0 11h-7" fill="none" stroke="#FFFFFF" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></g>'
                . '<g class="t-mini"><path d="M84 120c-6 0-10 4-10 9 0 7 10 14 10 14s10-7 10-14c0-5-4-9-10-9z" fill="#A8A296"/><path d="M224 140c-6 0-10 4-10 9 0 7 10 14 10 14s10-7 10-14c0-5-4-9-10-9z" fill="#A8A296"/></g>'
                . '<g class="t-card"><rect x="34" y="152" width="212" height="42" rx="14" fill="#FFFFFF"/><rect x="42" y="159" width="28" height="28" rx="8" fill="#4B5145"/><rect x="48" y="162" width="1.6" height="22" fill="#F7F2EB"/><rect x="62" y="162" width="1.6" height="22" fill="#F7F2EB"/><rect x="78" y="163" width="90" height="8" rx="4" fill="#2F3327"/><rect x="78" y="176" width="60" height="7" rx="3.5" fill="#D6CDBE"/><rect x="190" y="164" width="46" height="18" rx="9" fill="#8B9A6E"/></g>'),
            1 => $svg('tour-slot',
                '<rect x="14" y="14" width="252" height="192" rx="28" fill="#4B5145"/>'
                . '<rect x="14" y="14" width="252" height="10" fill="#8B9A6E"/><rect x="14" y="196" width="252" height="10" fill="#8B9A6E"/>'
                . '<g stroke="#F7F2EB" stroke-width="3" stroke-linecap="round"><path d="M36 30v60M96 30v60M156 30v60M216 30v60M36 130v60M96 130v60M156 130v60M216 130v60"/></g>'
                . '<path d="M28 110h22M64 110h22M100 110h22M136 110h22M172 110h22M208 110h22M244 110h10" stroke="#F7F2EB" stroke-opacity=".5" stroke-width="3" stroke-linecap="round"/>'
                . $car('55', '38', '#E9E6DF', '#D2CEC4') . $car('175', '38', '#9A9C94', '#84867E') . $car('225', '38', '#3E4338', '#33372E', ' scale(.82)')
                . $car('55', '138', '#C9C3B6', '#B3AD9F') . $car('115', '138', '#E9E6DF', '#D2CEC4')
                . '<g class="t-spot"><rect x="102" y="34" width="48" height="52" rx="10" fill="#8B9A6E" fill-opacity=".28" stroke="#8B9A6E" stroke-width="3"/><text x="126" y="66" text-anchor="middle" font-size="16" font-weight="600" fill="#FFFFFF">A-05</text></g>'
                . '<g class="t-finger"><circle cx="138" cy="84" r="16" fill="#FFFFFF" fill-opacity=".25"/><circle cx="138" cy="84" r="7" fill="#FFFFFF"/></g>'
                . '<g class="t-check"><circle cx="150" cy="36" r="13" fill="#FFFFFF"/><path d="m144 36 4 4 8-8" fill="none" stroke="#5E6B45" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></g>'
                . '<g class="t-tag"><rect x="168" y="146" width="84" height="40" rx="12" fill="#FFFFFF"/><text x="210" y="171" text-anchor="middle" font-size="15" font-weight="600" fill="#5E6B45">₱110.00</text></g>'),
            2 => $svg('tour-pay',
                '<rect x="14" y="14" width="252" height="192" rx="28" fill="#F7F2EB"/>'
                . '<circle cx="98" cy="104" r="54" fill="#FFFFFF"/><circle cx="98" cy="104" r="44" fill="none" stroke="#EAE2D6" stroke-width="10"/>'
                . '<circle class="t-ring" cx="98" cy="104" r="44" fill="none" stroke="#8B9A6E" stroke-width="10" stroke-linecap="round" pathLength="100" transform="rotate(-90 98 104)"/>'
                . '<text x="98" y="108" text-anchor="middle" font-size="22" font-weight="600" fill="#2F3327">10:00</text><text x="98" y="126" text-anchor="middle" font-size="10" fill="#6B695E">hold</text>'
                . '<g class="t-wallet"><rect x="156" y="62" width="96" height="84" rx="16" fill="#FFFFFF"/><rect x="168" y="74" width="34" height="34" rx="10" fill="#8B9A6E"/><path d="M178 86h14v12h-14z" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linejoin="round"/><rect x="168" y="118" width="72" height="8" rx="4" fill="#2F3327"/><rect x="168" y="131" width="44" height="7" rx="3.5" fill="#D6CDBE"/></g>'
                . '<g class="t-verified"><rect x="128" y="166" width="124" height="28" rx="14" fill="#8B9A6E"/><path d="m142 180 4 4 8-8" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/><text x="200" y="185" text-anchor="middle" font-size="12" font-weight="600" fill="#FFFFFF">Payment verified</text></g>'),
            default => $svg('tour-scan',
                '<rect x="14" y="14" width="252" height="192" rx="28" fill="#F7F2EB"/>'
                . '<rect x="44" y="26" width="112" height="170" rx="20" fill="#2F3327"/><rect x="52" y="38" width="96" height="146" rx="14" fill="#FFFFFF"/>'
                . '<g fill="#2F3327"><rect x="64" y="56" width="22" height="22" rx="3"/><rect x="114" y="56" width="22" height="22" rx="3"/><rect x="64" y="106" width="22" height="22" rx="3"/><rect x="94" y="60" width="8" height="8"/><rect x="94" y="76" width="12" height="6"/><rect x="92" y="92" width="8" height="8"/><rect x="106" y="90" width="6" height="14"/><rect x="118" y="88" width="16" height="8"/><rect x="96" y="110" width="10" height="10"/><rect x="114" y="104" width="8" height="8"/><rect x="126" y="112" width="10" height="16"/><rect x="110" y="120" width="10" height="8"/></g>'
                . '<g fill="#FFFFFF"><rect x="69" y="61" width="12" height="12" rx="2"/><rect x="119" y="61" width="12" height="12" rx="2"/><rect x="69" y="111" width="12" height="12" rx="2"/></g>'
                . '<rect x="64" y="146" width="72" height="8" rx="4" fill="#D6CDBE"/><rect x="74" y="160" width="52" height="7" rx="3.5" fill="#EAE2D6"/>'
                . '<rect class="t-beam" x="56" y="52" width="88" height="4" rx="2" fill="#8B9A6E"/>'
                . '<rect x="186" y="150" width="14" height="46" rx="4" fill="#4B5145"/><g class="t-gate"><rect x="193" y="146" width="66" height="10" rx="5" fill="#8B9A6E"/><rect x="214" y="146" width="10" height="10" fill="#FFFFFF"/><rect x="236" y="146" width="10" height="10" fill="#FFFFFF"/></g>'
                . '<g class="t-ok"><circle cx="216" cy="76" r="26" fill="#8B9A6E"/><path d="m204 76 8 8 16-16" fill="none" stroke="#FFFFFF" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></g>'),
        };
    }
}
