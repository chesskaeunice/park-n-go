<?php
declare(strict_types=1);

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
} catch (Throwable $error) {
    // Bootstrap can fail before layouts/helpers exist; do not expose paths or configuration.
    error_log('Park n Go startup failed: ' . get_class($error));
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit('Account services are temporarily unavailable. Please try again later.');
}
set_exception_handler(function (Throwable $error): void {
    error_log('Park n Go request failed: ' . get_class($error) . ' code ' . $error->getCode());
    http_response_code(503);
    $title = 'Service temporarily unavailable'; $view = 'public/unavailable'; $page = 'unavailable'; $currentUser = null;
    require BASE_PATH . '/app/views/layouts/main.php';
});
$routes = require BASE_PATH . '/routes/web.php';
$requestedPage = $_GET['page'] ?? 'home';
$page = is_string($requestedPage) ? $requestedPage : '';
if (!isset($routes[$page])) {
    http_response_code(404);
    $title = 'Page not found';
    $view = 'public/not-found';
} else {
    [$title, $view] = $routes[$page];
    $method = $_SERVER['REQUEST_METHOD'];
    $postRoutes = ['register', 'login', 'logout', 'forgot-password', 'reset-password', 'profile', 'admin-facilities', 'admin-floors', 'admin-slots', 'admin-kiosks', 'kiosk-activate', 'kiosk-verify', 'kiosk-exit', 'reservation-review', 'reservation-cancel', 'payment-start', 'payment-refresh', 'overtime-start', 'extension-start', 'entry-scan', 'exit-scan', 'admin-occupancy', 'admin-session-exception', 'admin-reconciliation', 'admin-refunds', 'admin-pricing', 'admin-permissions'];
    $adminPages = ['admin-dashboard', 'admin-occupancy', 'admin-session-exception', 'admin-reconciliation', 'admin-refunds', 'admin-pricing', 'admin-reports', 'admin-report-export', 'admin-audit', 'admin-permissions'];
    $postOnly = ['logout', 'reservation-cancel', 'payment-start', 'payment-refresh', 'overtime-start', 'extension-start'];
    if (!in_array($method, ['GET', 'POST'], true) || ($method === 'POST' && !in_array($page, $postRoutes, true)) || (in_array($page, $postOnly, true) && $method !== 'POST')) {
        http_response_code(405);
        header('Allow: ' . (in_array($page, $postOnly, true) ? 'POST' : (in_array($page, $postRoutes, true) ? 'GET, POST' : 'GET')));
        $title = 'Method not allowed'; $view = 'public/method-not-allowed';
    } elseif ($method === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf(), $_POST['csrf']))) {
        http_response_code(419); $title = 'Form expired'; $view = 'public/form-expired';
    } else {
        $routeRoles = $routes[$page][2] ?? [];
        if (isset($_COOKIE['parkngo_kiosk_session']) && array_intersect(['driver', 'attendant', 'admin'], $routeRoles)) {
            http_response_code(403); $title = 'Access denied'; $view = 'kiosk/denied'; $pageData = [];
            $denied = ['Access denied', 'kiosk/denied'];
        } else {
            $denied = isset($routes[$page][2]) ? \ParkNGo\Middleware\Access::check($routes[$page][2]) : null;
        }
        if ($denied) {
            http_response_code(403); [$title, $view] = $denied;
        } else {
            if (in_array($page, ['dashboard', 'facilities', 'facility', 'admin-facilities', 'admin-floors', 'admin-slots','reservation-review','reservation-checkout','reservation-cancel','reservations','payment-start','payment-refresh','payment-return','overtime-start','extension-start','ticket','entry-scan','exit-scan'], true)) {
                try {
                    // The driver home screen reads a few facilities and the next booking (read-only).
                    $controller = $page === 'dashboard' ? new \ParkNGo\Controllers\DashboardController() : (in_array($page,['reservation-review','reservation-checkout','reservation-cancel','reservations','payment-start','payment-refresh','payment-return','overtime-start','extension-start'],true) ? new \ParkNGo\Controllers\ReservationController() : (in_array($page,['ticket','entry-scan','exit-scan'],true) ? new \ParkNGo\Controllers\OperationsController() : new \ParkNGo\Controllers\ParkingController()));
                    $pageData = $controller->handle($page);
                }
                catch (OutOfBoundsException $error) { http_response_code(404); $title = 'Page not found'; $view = 'public/not-found'; }
                catch (InvalidArgumentException $error) { error_log('Request rejected: ' . $error->getMessage()); http_response_code(422); $title = 'Check your request'; $view = 'public/invalid-request'; $requestError = ui_error_message($error); }
            } elseif (in_array($page, $adminPages, true)) {
                try { $pageData = (new \ParkNGo\Controllers\AdminController())->handle($page); }
                catch (OutOfBoundsException $error) { http_response_code(404); $title = 'Page not found'; $view = 'public/not-found'; }
                catch (InvalidArgumentException $error) { error_log('Request rejected: ' . $error->getMessage()); http_response_code(422); $title = 'Check your request'; $view = 'public/invalid-request'; $requestError = ui_error_message($error); }
            } elseif (in_array($page, ['admin-kiosks', 'kiosk-activate', 'kiosk-home', 'kiosk-verify', 'kiosk-exit'], true)) {
                try {
                    $controller = new \ParkNGo\Controllers\KioskController();
                    $pageData = $controller->handle($page);
                }
                catch (OutOfBoundsException $error) { http_response_code(404); $title = 'Page not found'; $view = 'public/not-found'; }
                catch (InvalidArgumentException $error) { error_log('Request rejected: ' . $error->getMessage()); http_response_code(422); $title = 'Check your request'; $view = 'public/invalid-request'; $requestError = ui_error_message($error); }
            } else {
                $controller = new \ParkNGo\Controllers\AccountController(); $controller->handle($page);
                $errors = $controller->errors; $old = $controller->old;
            }
        }
    }
}
$currentUser = \ParkNGo\Services\Auth::user();
require BASE_PATH . '/app/views/layouts/main.php';
