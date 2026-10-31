<?php
declare(strict_types=1);
namespace ParkNGo\Controllers;
use ParkNGo\Services\{Auth, RateLimiter};
use ParkNGo\Services\Parking\ParkingSessionService;
use ParkNGo\Services\Qr\TicketService;
final class OperationsController
{
    public function handle(string $page): array
    {
        $user = Auth::user(); $errors = []; $ticketData = null; $result = null;
        if ($page === 'ticket') {
            $ticketData = (new TicketService())->issue((int) $user['id'], ParkingController::id($_GET, 'id'));
            return compact('errors', 'ticketData', 'result');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $code = input('code');
            if (!RateLimiter::consume('parking-scan', (string) $user['id'], 30)) { http_response_code(429); $errors[] = 'Too many scan attempts. Try again later.'; }
            elseif ($code === '') { http_response_code(422); $errors[] = 'Enter a QR token or manual ticket code.'; }
            else {
                try { $result = (new ParkingSessionService())->{$page === 'entry-scan' ? 'entry' : 'exit'}($code, (int) $user['id']); }
                catch (\InvalidArgumentException $error) { http_response_code(422); $errors[] = $error->getMessage(); }
            }
        }
        return compact('errors', 'ticketData', 'result');
    }
}
