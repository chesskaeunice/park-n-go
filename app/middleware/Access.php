<?php
declare(strict_types=1);

namespace ParkNGo\Middleware;

use ParkNGo\Services\Auth;

final class Access
{
    public static function check(array $roles): ?array
    {
        $user = Auth::user();
        if (!$user) {
            flash('Please log in to access your account.');
            redirect('login');
        }
        if ($roles && !in_array($user['role'], $roles, true)) return ['Access denied', 'public/forbidden'];
        return null;
    }
}
