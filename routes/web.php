<?php
declare(strict_types=1);

return [
    'home' => ['Find your space', 'public/home'],
    'facilities' => ['Explore parking', 'public/facilities'],
    'facility' => ['Parking facility', 'public/facility'],
    'admin-facilities' => ['Manage facilities', 'admin/inventory', ['admin']],
    'admin-floors' => ['Manage floors', 'admin/inventory', ['admin']],
    'admin-slots' => ['Manage slots', 'admin/inventory', ['admin']],
    'how-it-works' => ['How it works', 'public/how-it-works'],
    'about' => ['About Park n Go', 'public/about'],
    'login' => ['Account access', 'public/account'],
    'register' => ['Create an account', 'public/account'],
    'forgot-password' => ['Reset your password', 'public/account'],
    'reset-password' => ['Choose a new password', 'public/account'],
    'dashboard' => ['Driver dashboard', 'driver/dashboard', ['driver']],
    'reservation-review' => ['Review your reservation', 'driver/reservation-review', ['driver']],
    'reservation-checkout' => ['Reservation checkout', 'driver/reservation-checkout', ['driver']],
    'reservation-cancel' => ['Cancel reservation', 'driver/reservation-review', ['driver']],
    'reservations' => ['My reservations', 'driver/reservations', ['driver']],
    'attendant-dashboard' => ['Attendant dashboard', 'attendant/dashboard', ['attendant']],
    'admin-dashboard' => ['Administrator dashboard', 'admin/dashboard', ['admin']],
    'profile' => ['Profile settings', 'driver/profile', ['driver', 'attendant', 'admin']],
    // Logout must still clear a session when the database is unavailable. CSRF and POST are enforced centrally.
    'logout' => ['Log out', 'public/account'],
];
