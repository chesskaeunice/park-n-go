<?php
declare(strict_types=1);

namespace ParkNGo\Controllers;

use ParkNGo\Services\{Auth, Audit, KioskAuth, KioskService, RateLimiter};
use ParkNGo\Services\Parking\KioskExitService;

final class KioskController
{
    public function handle(string $page): array
    {
        return match ($page) {
            'admin-kiosks' => $this->admin(),
            'kiosk-activate' => $this->activate(),
            'kiosk-home' => $this->home(),
            'kiosk-verify' => $this->verify(),
            'kiosk-exit' => $this->confirmExit(),
            default => throw new \OutOfBoundsException('Kiosk page not found.'),
        };
    }

    private function admin(): array
    {
        $errors = [];
        $notice = $_SESSION['kiosk_activation_notice'] ?? null;
        unset($_SESSION['kiosk_activation_notice']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $adminId = (int) Auth::user()['id'];
                if (($_POST['action'] ?? '') === 'register') {
                    $created = KioskService::create($adminId, (int) ($_POST['facility_id'] ?? 0), (string) ($_POST['name'] ?? ''));
                    $_SESSION['kiosk_activation_notice'] = $created;
                    redirect('admin-kiosks');
                }
                if (($_POST['action'] ?? '') === 'revoke') {
                    KioskService::revoke($adminId, (int) ($_POST['kiosk_id'] ?? 0));
                    flash('Kiosk revoked.');
                    redirect('admin-kiosks');
                }
                throw new \InvalidArgumentException('Unsupported kiosk action.');
            } catch (\InvalidArgumentException|\OutOfBoundsException $error) {
                $errors[] = $error->getMessage();
                http_response_code(422);
            }
        }
        return ['errors' => $errors, 'notice' => $notice, 'facilities' => KioskService::facilities(), 'kiosks' => KioskService::listForAdmin()];
    }

    private function activate(): array
    {
        if (KioskAuth::current()) redirect('kiosk-home');
        $errors = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $identifier = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if (!RateLimiter::consume('kiosk-activation', $identifier, 5, 900)) {
                http_response_code(429);
                Audit::record('kiosk.activation_rate_limited');
                $errors[] = 'Activation is temporarily unavailable. Please try again later.';
            } else {
                try {
                    $activated = KioskService::activate((string) ($_POST['code'] ?? ''));
                    KioskAuth::establish($activated['id'], $activated['credential']);
                    redirect('kiosk-home');
                } catch (\InvalidArgumentException $error) {
                    Audit::record('kiosk.activation_failed');
                    $errors[] = 'Activation code is invalid or expired.';
                    http_response_code(422);
                }
            }
        }
        return ['errors' => $errors];
    }

    private function home(): array
    {
        $kiosk = KioskAuth::current();
        if (!$kiosk) {
            http_response_code(403);
            return ['authorized' => false, 'kiosk' => null, 'errors' => ['This kiosk is not authorized.'], 'verification' => null, 'exit' => null];
        }
        return ['authorized' => true, 'kiosk' => $kiosk, 'errors' => [], 'verification' => null, 'exit' => null];
    }

    private function verify(): array
    {
        $data = $this->home();
        if (!$data['authorized']) return $data;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!KioskExitService::allowed('kiosk-ticket', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                http_response_code(429); $data['errors'][] = 'Too many verification attempts. Please try again later.';
            } else {
                try { $data['verification'] = (new KioskExitService())->preview($data['kiosk'], (string) ($_POST['code'] ?? '')); }
                catch (\InvalidArgumentException $error) { http_response_code(422); $data['errors'][] = $error->getMessage(); }
            }
        }
        return $data;
    }

    private function confirmExit(): array
    {
        $data = $this->home();
        if (!$data['authorized']) return $data;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!KioskExitService::allowed('kiosk-exit', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                http_response_code(429); $data['errors'][] = 'Too many exit attempts. Please try again later.';
            } else {
                try { $data['exit'] = (new KioskExitService())->confirm($data['kiosk']); }
                catch (\InvalidArgumentException $error) { http_response_code(422); $data['errors'][] = $error->getMessage(); }
            }
        }
        return $data;
    }
}
