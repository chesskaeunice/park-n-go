<?php
declare(strict_types=1);
namespace ParkNGo\Controllers;

use ParkNGo\Services\{Auth, Database, RateLimiter};
use ParkNGo\Services\Admin\{AdminAudit, AdminPermissions, OperationsMonitor, PermissionDenied, PricingService, ReconciliationService, RefundService, ReportExport, ReportService, SessionExceptionService};

/**
 * Phase 7 admin operations. Routes are admin-only (routes/web.php); sensitive actions additionally check an
 * explicit permission inside the service transaction. Hidden buttons are never the authorization.
 */
final class AdminController
{
    private array $user;
    private array $errors = [];

    public function handle(string $page): array
    {
        $this->user = Auth::user();
        $data = ['errors' => [], 'permissions' => AdminPermissions::forUser($this->id())];
        try {
            $data = $this->{str_replace('-', '', lcfirst(ucwords(substr($page, 6), '-')))}() + $data;
        } catch (PermissionDenied $error) {
            AdminAudit::record(null, $this->id(), 'permission.denied', 'route', null, null, $page . ':' . (is_string($_POST['action'] ?? null) ? mb_substr($_POST['action'], 0, 40) : 'view'), null, ['permission' => $error->permission], 'denied');
            http_response_code(403); $this->errors[] = $error->getMessage();
            $data = $this->fallback($page) + $data;
        }
        $data['errors'] = $this->errors;
        return $data;
    }

    private function id(): int { return (int) $this->user['id']; }
    private function post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
    private function action(): string { return is_string($_POST['action'] ?? null) ? $_POST['action'] : ''; }

    /** Re-renders the page's read-only view after a denied or failed action. */
    private function fallback(string $page): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return $this->{str_replace('-', '', lcfirst(ucwords(substr($page, 6), '-')))}();
    }

    /** Runs a state-changing action: PRG redirect on success, inline 422/409 errors otherwise. */
    private function attempt(callable $action, string $page, array $parameters = []): void
    {
        try {
            if (!RateLimiter::consume('admin-operations', (string) $this->id(), 120, 900)) { http_response_code(429); header('Retry-After: 900'); throw new \InvalidArgumentException('Too many admin actions. Try again in 15 minutes.'); }
            $message = $action();
            flash(is_string($message) ? $message : 'Saved.');
            redirect($page, $parameters);
        } catch (\InvalidArgumentException|\OutOfBoundsException $error) {
            if (http_response_code() !== 429) http_response_code(422);
            $this->errors[] = $error->getMessage();
        } catch (\PDOException $error) {
            // Deadlock or lock wait: nothing was committed; the admin can safely retry.
            if (!in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) throw $error;
            http_response_code(409); $this->errors[] = 'Another change to the same records was in progress. Nothing was saved; review and try again.';
        }
    }

    private function facilityFilter(): int
    {
        $id = ParkingController::id($_GET, 'facility_id');
        if ($id) { $find = Database::connection()->prepare('SELECT 1 FROM parking_facilities WHERE id=?'); $find->execute([$id]); if (!$find->fetchColumn()) throw new \OutOfBoundsException('Facility not found.'); }
        return $id;
    }

    private function pageNumber(): int
    {
        $page = ParkingController::id($_GET, 'p', 1);
        if ($page > 10000) throw new \InvalidArgumentException('Choose a valid page.');
        return $page;
    }

    private function dashboard(): array
    {
        $facilityId = $this->facilityFilter();
        $monitor = new OperationsMonitor();
        return ['facilityId' => $facilityId, 'facilities' => $monitor->facilities(), 'metrics' => $monitor->metrics($facilityId)];
    }

    private function occupancy(): array
    {
        $monitor = new OperationsMonitor(); $facilities = $monitor->facilities();
        $facilityId = $this->facilityFilter() ?: (int) ($facilities[0]['id'] ?? 0);
        if ($this->post()) {
            $this->attempt(fn (): string => ($monitor->correct($this->id(), ParkingController::id($_POST, 'slot_id'), $this->action(), input('expected'), input('reason')))
                ? 'Occupancy correction recorded with its before and after state.' : '', 'admin-occupancy', ['facility_id' => $facilityId]);
        }
        $state = is_string($_GET['state'] ?? null) && in_array($_GET['state'], ['available', 'occupied', 'reserved', 'held', 'maintenance', 'disabled', 'attention'], true) ? $_GET['state'] : '';
        $slots = $facilityId ? $monitor->slots($facilityId) : [];
        if ($state === 'attention') $slots = array_values(array_filter($slots, static fn (array $slot): bool => $slot['stale_holds'] || $slot['overdue']));
        elseif ($state !== '') $slots = array_values(array_filter($slots, static fn (array $slot): bool => $slot['state'] === $state));
        $page = $this->pageNumber(); $total = count($slots);
        return ['facilities' => $facilities, 'facilityId' => $facilityId, 'state' => $state, 'slots' => array_slice($slots, ($page - 1) * 25, 25), 'total' => $total, 'pageNumber' => $page,
            'exceptions' => (new SessionExceptionService())->recent($facilityId, 10)];
    }

    private function sessionException(): array
    {
        $service = new SessionExceptionService();
        $sessionId = ParkingController::id($_POST + $_GET, 'session_id');
        if (!$sessionId) throw new \OutOfBoundsException('Parking session not found.');
        $preview = null; $old = ['category' => input('category'), 'reason' => input('reason')];
        if ($this->post()) {
            $facilityId = ParkingController::id($_POST, 'facility_id');
            if ($this->action() === 'preview') {
                try {
                    $preview = $service->preview($this->id(), $sessionId, $facilityId, $old['category'], $old['reason']);
                    // The confirmation must carry exactly what was previewed.
                    $_SESSION['session_exception'] = ['session_id' => $sessionId, 'facility_id' => $facilityId, 'category' => $preview['category'], 'reason' => $preview['reason'], 'token' => $token = bin2hex(random_bytes(16)), 'at' => time()];
                    $preview['token'] = $token;
                } catch (\InvalidArgumentException|\OutOfBoundsException $error) { http_response_code(422); $this->errors[] = $error->getMessage(); }
            } elseif ($this->action() === 'confirm') {
                $pending = $_SESSION['session_exception'] ?? null;
                $valid = is_array($pending) && hash_equals((string) $pending['token'], input('token')) && (int) $pending['session_id'] === $sessionId && time() - (int) $pending['at'] <= 300 && input('confirm') === 'yes';
                unset($_SESSION['session_exception']);
                if (!$valid) { http_response_code(422); $this->errors[] = 'Preview the exception again and tick the confirmation before closing the session.'; }
                else $this->attempt(function () use ($service, $pending): string {
                    $result = $service->close($this->id(), (int) $pending['session_id'], (int) $pending['facility_id'], (string) $pending['category'], (string) $pending['reason']);
                    return $result['unsettled'] ? 'Session closed by exception. Unpaid overtime of ' . ($result['outstanding_centavos'] === null ? 'an unpriced duration' : '₱' . number_format($result['outstanding_centavos'] / 100, 2)) . ' remains recorded for reconciliation.' : 'Session closed by exception. No payment obligation was outstanding.';
                }, 'admin-occupancy', ['facility_id' => (int) $pending['facility_id']]);
            } else { http_response_code(422); $this->errors[] = 'Unsupported session exception step.'; }
        }
        return ['session' => $service->session($sessionId), 'preview' => $preview, 'old' => $old, 'categories' => SessionExceptionService::CATEGORIES];
    }

    private function reconciliation(): array
    {
        $service = new ReconciliationService(); $facilityId = $this->facilityFilter();
        $show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'open';
        if ($this->post()) {
            $this->attempt(fn (): string => $this->action() === 'recheck_reference' ? $service->recheckReference($this->id(), strtoupper(trim(input('payment'))))
                : $service->act($this->id(), $this->action(), input('case'), input('note'), input('expected')), 'admin-reconciliation', array_filter(['facility_id' => $facilityId, 'show' => $show === 'all' ? 'all' : null]));
        }
        $cases = $service->cases($facilityId, $show === 'all');
        $page = $this->pageNumber();
        return ['facilities' => (new OperationsMonitor())->facilities(), 'facilityId' => $facilityId, 'show' => $show, 'cases' => array_slice($cases, ($page - 1) * 20, 20), 'total' => count($cases), 'pageNumber' => $page];
    }

    private function refunds(): array
    {
        $service = new RefundService(); $facilityId = $this->facilityFilter();
        $status = is_string($_GET['status'] ?? null) && ($_GET['status'] === 'all' || isset(RefundService::LABELS[$_GET['status']])) ? $_GET['status'] : 'open';
        if ($this->post()) {
            $id = $this->action() === 'request' ? 0 : ParkingController::id($_POST, 'refund_id');
            $this->attempt(fn (): string => match ($this->action()) {
                'request' => ($r = $service->request($this->id(), input('payment'), input('amount'), input('reason'), input('request_key')))['duplicate'] ? 'This refund request was already submitted as ' . $r['reference'] . '.' : 'Refund ' . $r['reference'] . ' requested. It needs approval; no money has been returned.',
                'review' => 'Refund ' . $service->startReview($this->id(), $id)['reference'] . ' is under review.',
                'approve' => 'Refund ' . $service->decide($this->id(), $id, true, input('reason'))['reference'] . ' approved. Submit it in Maya Manager, then record the Maya refund ID; it is not complete until Maya confirms it.',
                'reject' => 'Refund ' . $service->decide($this->id(), $id, false, input('reason'))['reference'] . ' rejected.',
                'provider' => 'Maya refund ID recorded for ' . $service->recordProviderRefund($this->id(), $id, input('provider_refund_id'))['reference'] . '. Check Maya to confirm it.',
                'check' => $service->checkProvider($this->id(), $id)['message'],
                default => throw new \InvalidArgumentException('Unsupported refund action.'),
            }, 'admin-refunds', array_filter(['facility_id' => $facilityId ?: null, 'status' => $status === 'open' ? null : $status]));
        }
        $lookup = null; $reference = is_string($_GET['payment'] ?? null) ? strtoupper(trim($_GET['payment'])) : '';
        if ($reference !== '') {
            try {
                if (!preg_match('/^PNGO-[A-F0-9]{24}$/D', $reference)) throw new \InvalidArgumentException('Enter a valid payment reference (PNGO-…).');
                $lookup = $service->payment($reference);
            } catch (\InvalidArgumentException|\OutOfBoundsException $error) { $this->errors[] = $error->getMessage(); }
        }
        return ['facilities' => (new OperationsMonitor())->facilities(), 'facilityId' => $facilityId, 'status' => $status, 'list' => $service->list($facilityId, $status, $this->pageNumber()),
            'pageNumber' => $this->pageNumber(), 'lookup' => $lookup, 'paymentReference' => $reference, 'requestKey' => bin2hex(random_bytes(32))];
    }

    private function pricing(): array
    {
        $monitor = new OperationsMonitor(); $facilities = $monitor->facilities();
        $facilityId = $this->facilityFilter() ?: (int) ($facilities[0]['id'] ?? 0);
        $old = [];
        if ($this->post()) {
            $old = $_POST;
            $this->attempt(function () use ($facilityId): string {
                $rate = (new PricingService())->schedule($this->id(), $facilityId, $_POST, input('effective_from'), input('reason'), (int) input('expected_latest'));
                return 'New rate scheduled from ' . ReportService::format($rate['effective_from'], 'datetime') . ' Manila time. Existing reservations, sessions, and payments keep their original rates.';
            }, 'admin-pricing', ['facility_id' => $facilityId]);
        }
        return ['facilities' => $facilities, 'facilityId' => $facilityId, 'overview' => $facilityId ? (new PricingService())->overview($facilityId) : null, 'old' => $old];
    }

    private function reports(): array
    {
        $type = is_string($_GET['type'] ?? null) && isset(ReportService::TYPES[$_GET['type']]) ? $_GET['type'] : 'revenue';
        try { $filters = ReportService::filters($_GET); }
        catch (\InvalidArgumentException|\OutOfBoundsException $error) { http_response_code(422); $this->errors[] = $error->getMessage(); $filters = ReportService::filters([]); }
        return ['type' => $type, 'filters' => $filters, 'report' => (new ReportService())->build($type, $filters, $this->pageNumber()), 'facilities' => (new OperationsMonitor())->facilities()];
    }

    /** Streams CSV or PDF built from the same report data as the screen, then ends the request. */
    private function reportExport(): array
    {
        $type = is_string($_GET['type'] ?? null) && isset(ReportService::TYPES[$_GET['type']]) ? $_GET['type'] : throw new \InvalidArgumentException('Choose a valid report.');
        $format = in_array($_GET['format'] ?? '', ['csv', 'pdf'], true) ? $_GET['format'] : throw new \InvalidArgumentException('Choose CSV or PDF.');
        if (!RateLimiter::consume('admin-report-export', (string) $this->id(), 30, 900)) { http_response_code(429); header('Retry-After: 900'); throw new \InvalidArgumentException('Too many exports. Try again in 15 minutes.'); }
        $filters = ReportService::filters($_GET);
        $report = (new ReportService())->build($type, $filters, null);
        $body = $format === 'csv' ? ReportExport::csv($report) : ReportExport::pdf($report);
        AdminAudit::record(null, $this->id(), 'report.exported', 'report', null, $filters['facility_id'] ?: null, null, null, ['type' => $type, 'format' => $format, 'from' => $filters['from'], 'to' => $filters['to'], 'rows' => $report['total_rows']]);
        $name = 'parkngo-' . $type . '-' . $filters['from'] . '-to-' . $filters['to'] . ($filters['facility_id'] ? '-facility-' . $filters['facility_id'] : '') . '.' . $format;
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    private function audit(): array
    {
        $facilityId = $this->facilityFilter();
        $action = is_string($_GET['action'] ?? null) && preg_match('/^[a-z_]{1,40}$/D', $_GET['action']) ? $_GET['action'] : '';
        $page = $this->pageNumber();
        $where = '(?=0 OR a.facility_id=?) AND (?=\'\' OR a.action LIKE CONCAT(?,\'.%\'))'; $params = [$facilityId, $facilityId, $action, $action];
        $count = Database::connection()->prepare("SELECT COUNT(*) FROM admin_audit_events a WHERE $where"); $count->execute($params);
        $find = Database::connection()->prepare("SELECT a.*,u.name AS actor_name,f.name AS facility_name FROM admin_audit_events a LEFT JOIN users u ON u.id=a.actor_id LEFT JOIN parking_facilities f ON f.id=a.facility_id WHERE $where ORDER BY a.id DESC LIMIT 30 OFFSET " . ($page - 1) * 30);
        $find->execute($params);
        $groups = Database::connection()->query("SELECT DISTINCT SUBSTRING_INDEX(action,'.',1) FROM admin_audit_events ORDER BY 1")->fetchAll(\PDO::FETCH_COLUMN);
        return ['facilities' => (new OperationsMonitor())->facilities(), 'facilityId' => $facilityId, 'actionGroup' => $action, 'groups' => $groups, 'events' => $find->fetchAll(), 'total' => (int) $count->fetchColumn(), 'pageNumber' => $page];
    }

    private function permissions(): array
    {
        if ($this->post()) {
            $grant = $this->action() === 'grant';
            if (!$grant && $this->action() !== 'revoke') { http_response_code(422); $this->errors[] = 'Unsupported permission action.'; }
            else $this->attempt(function () use ($grant): string {
                AdminPermissions::change($this->id(), ParkingController::id($_POST, 'user_id'), input('permission'), $grant, input('reason'));
                return 'Permission ' . ($grant ? 'granted' : 'revoked') . '.';
            }, 'admin-permissions');
        }
        return ['admins' => AdminPermissions::admins(), 'labels' => AdminPermissions::ALL];
    }
}
