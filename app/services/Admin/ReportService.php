<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

use ParkNGo\Services\{Clock, Database};

/**
 * Operational reports. The on-screen table, CSV, and PDF all come from build(), so filters and figures match.
 * Date basis: whole calendar days in Asia/Manila (UTC+08:00, no daylight saving) converted to UTC bounds,
 * start inclusive and end exclusive. Revenue is dated by server verification time (payment_attempts.verified_at);
 * refunds by provider confirmation time. Gross verified receipts count each verified payment attempt exactly once.
 */
final class ReportService
{
    public const TYPES = ['reservations' => 'Reservations', 'occupancy' => 'Occupancy', 'revenue' => 'Revenue', 'reconciliation' => 'Reconciliation'];
    public const PAGE_SIZE = 50;
    public const PAYMENT_TYPES = ['INITIAL_RESERVATION' => 'Initial parking', 'OVERTIME_SETTLEMENT' => 'Overtime', 'PARKING_EXTENSION' => 'Extension', 'CONFIRMED_REFUND' => 'Confirmed refund'];
    private const MANILA = "'+08:00'";

    public static function filters(array $query, ?\DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now(); $manila = new \DateTimeZone('Asia/Manila');
        $today = $now->setTimezone($manila)->format('Y-m-d');
        $date = static function (string $key, string $default) use ($query, $manila): \DateTimeImmutable {
            $value = is_string($query[$key] ?? null) && $query[$key] !== '' ? $query[$key] : $default;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $manila);
            if (!$parsed || $parsed->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Choose valid report dates.');
            return $parsed;
        };
        $from = $date('from', (new \DateTimeImmutable($today, $manila))->modify('-29 days')->format('Y-m-d')); $to = $date('to', $today);
        if ($to < $from) throw new \InvalidArgumentException('The report end date must be on or after the start date.');
        if ($from->diff($to)->days > 366) throw new \InvalidArgumentException('Choose a report period of at most 366 days.');
        $facilityId = 0;
        if (isset($query['facility_id']) && $query['facility_id'] !== '' && $query['facility_id'] !== '0') {
            if (!is_string($query['facility_id']) || !preg_match('/^[1-9]\d{0,17}$/D', $query['facility_id'])) throw new \InvalidArgumentException('Choose a valid facility.');
            $facilityId = (int) $query['facility_id'];
        }
        $name = 'All facilities';
        if ($facilityId) {
            $find = Database::connection()->prepare('SELECT name FROM parking_facilities WHERE id=?'); $find->execute([$facilityId]);
            $name = $find->fetchColumn(); if ($name === false) throw new \OutOfBoundsException('Facility not found.');
        }
        $utc = new \DateTimeZone('UTC');
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'facility_id' => $facilityId, 'facility_name' => (string) $name,
            'start' => $from->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')];
    }

    /** $page null returns every row (exports); otherwise one screen page. */
    public function build(string $type, array $filters, ?int $page = 1): array
    {
        if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException('Choose a valid report.');
        $report = $this->{$type}($filters);
        $report += ['type' => $type, 'title' => self::TYPES[$type] . ' report', 'filters' => $filters, 'generated_at' => $filters['now'], 'notes' => []];
        $report['total_rows'] = count($report['rows']);
        $report['page'] = $page;
        if ($page !== null) $report['rows'] = array_slice($report['rows'], ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        return $report;
    }

    private function query(string $sql, array $params): array
    {
        $find = Database::connection()->prepare($sql); $find->execute($params);
        return $find->fetchAll();
    }

    private static function slotScope(string $floor = 'fl'): string { return " AND (?=0 OR {$floor}.facility_id=?)"; }

    private function reservations(array $f): array
    {
        $scope = self::slotScope(); $join = ' JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id';
        $day = static fn (string $column): string => "DATE(CONVERT_TZ($column,'+00:00'," . self::MANILA . "))";
        $part = static fn (string $kind, string $column, string $extra = '', string $from = 'reservations r') => "SELECT {$day($column)} AS d,'$kind' AS kind FROM $from$join WHERE $column>=? AND $column<?$extra$scope";
        $sql = 'SELECT d,SUM(kind=\'created\') AS created,SUM(kind=\'confirmed\') AS confirmed,SUM(kind=\'cancelled\') AS cancelled,SUM(kind=\'expired\') AS expired_holds,SUM(kind=\'no_show\') AS no_shows FROM ('
            . implode(' UNION ALL ', [$part('created', 'r.created_at'), $part('confirmed', 'r.confirmed_at'), $part('cancelled', 'r.cancelled_at'),
                $part('expired', 'h.expires_at', " AND r.status='expired'", 'reservation_holds h JOIN reservations r ON r.id=h.reservation_id'), $part('no_show', 'r.no_show_at')])
            . ') x GROUP BY d ORDER BY d';
        $params = [];
        for ($i = 0; $i < 5; $i++) array_push($params, $f['start'], $f['end'], $f['facility_id'], $f['facility_id']);
        $rows = array_map(static fn (array $row): array => ['date' => $row['d']] + array_map('intval', array_diff_key($row, ['d' => 1])), $this->query($sql, $params));
        $sum = static fn (string $key): int => array_sum(array_column($rows, $key));
        return [
            'summary' => [['Created reservations', $sum('created'), 'int'], ['Confirmed reservations', $sum('confirmed'), 'int'], ['Cancelled reservations', $sum('cancelled'), 'int'],
                ['Expired holds', $sum('expired_holds'), 'int'], ['Recorded no-shows', $sum('no_shows'), 'int']],
            'columns' => [['date', 'Date (Manila)', 'date'], ['created', 'Created', 'int'], ['confirmed', 'Confirmed', 'int'], ['cancelled', 'Cancelled', 'int'], ['expired_holds', 'Expired holds', 'int'], ['no_shows', 'No-shows', 'int']],
            'rows' => $rows,
            'notes' => ['Created, confirmed, cancelled, and no-show counts use each event timestamp. Expired holds use the hold expiry time of reservations that expired unpaid.',
                'No-shows are counted only when recorded by an entry scan after the entry window closed; unscanned no-shows are not represented in the data.'],
        ];
    }

    private function occupancy(array $f): array
    {
        $cap = min($f['end'], $f['now']); $window = max(0, strtotime($cap . ' UTC') - strtotime($f['start'] . ' UTC'));
        $rows = $this->query("SELECT fa.name AS facility,fl.label AS floor,s.label AS slot,COUNT(ps.id) AS sessions,
                COALESCE(SUM(GREATEST(0,TIMESTAMPDIFF(SECOND,GREATEST(ps.entry_at,?),LEAST(COALESCE(ps.exit_at,?),?)))),0) AS occupied_seconds
            FROM parking_slots s JOIN parking_floors fl ON fl.id=s.floor_id JOIN parking_facilities fa ON fa.id=fl.facility_id
            LEFT JOIN reservations r ON r.slot_id=s.id LEFT JOIN parking_sessions ps ON ps.reservation_id=r.id AND ps.entry_at<? AND COALESCE(ps.exit_at,?)>?
            WHERE s.status<>'disabled'" . self::slotScope() . ' GROUP BY s.id,fa.name,fl.label,s.label,fa.id,fl.sort_order,fl.id ORDER BY fa.name,fa.id,fl.sort_order,fl.id,s.label',
            [$f['start'], $f['now'], $cap, $cap, $f['now'], $f['start'], $f['facility_id'], $f['facility_id']]);
        foreach ($rows as &$row) {
            $row['sessions'] = (int) $row['sessions']; $row['occupied_seconds'] = (int) $row['occupied_seconds'];
            $row['utilization_bp'] = $window > 0 ? intdiv($row['occupied_seconds'] * 10000, $window) : 0;
        }
        unset($row);
        $count = fn (string $column): int => (int) $this->query("SELECT COUNT(*) AS c FROM parking_sessions ps JOIN reservations r ON r.id=ps.reservation_id JOIN parking_slots s ON s.id=r.slot_id JOIN parking_floors fl ON fl.id=s.floor_id WHERE ps.$column>=? AND ps.$column<?" . self::slotScope()
            . ($column === 'exit_at' ? " AND ps.status='completed'" : ''), [$f['start'], $f['end'], $f['facility_id'], $f['facility_id']])[0]['c'];
        $live = (new OperationsMonitor())->metrics($f['facility_id'], new \DateTimeImmutable($f['now'], new \DateTimeZone('UTC')));
        $occupied = array_sum(array_column($rows, 'occupied_seconds'));
        $exits = $count('exit_at');
        return [
            'summary' => [['Active sessions (now)', $live['active_sessions'], 'int'], ['Overdue sessions (now)', $live['overdue_sessions'], 'int'], ['Entries', $count('entry_at'), 'int'],
                ['Exits', $exits, 'int'], ['Completed sessions', $exits, 'int'], ['Occupied slot hours', $occupied, 'hours'],
                ['Facility utilization', $rows && $window > 0 ? intdiv($occupied * 10000, $window * count($rows)) : 0, 'percent']],
            'columns' => [['facility', 'Facility', 'text'], ['floor', 'Floor', 'text'], ['slot', 'Slot', 'text'], ['sessions', 'Sessions', 'int'], ['occupied_seconds', 'Occupied hours', 'hours'], ['utilization_bp', 'Utilization', 'percent']],
            'rows' => $rows,
            'notes' => ['Utilization is occupied slot time within the period (up to the generation time) divided by elapsed period time, for slots not currently disabled.',
                'Active and overdue sessions are a live snapshot at generation time; entries and exits use their timestamps within the period.'],
        ];
    }

    private function revenue(array $f): array
    {
        $scope = ' AND (?=0 OR af.id=?)'; $range = [$f['start'], $f['end'], $f['facility_id'], $f['facility_id']];
        $totals = array_fill_keys(['INITIAL_RESERVATION', 'OVERTIME_SETTLEMENT', 'PARKING_EXTENSION'], [0, 0]);
        foreach ($this->query("SELECT pa.payment_type,COUNT(*) AS n,SUM(pa.amount_centavos) AS total FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE pa.local_status='verified' AND pa.verified_at>=? AND pa.verified_at<?$scope GROUP BY pa.payment_type", $range) as $row) {
            $totals[$row['payment_type']] = [(int) $row['n'], (int) $row['total']];
        }
        $refunds = $this->query("SELECT COUNT(*) AS n,COALESCE(SUM(rr.amount_centavos),0) AS total FROM refund_requests rr JOIN payment_attempts pa ON pa.id=rr.payment_attempt_id " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE rr.status='provider_confirmed' AND rr.provider_confirmed_at>=? AND rr.provider_confirmed_at<?$scope", $range)[0];
        $held = $this->query("SELECT COUNT(*) AS n,COALESCE(SUM(pa.amount_centavos),0) AS total FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE pa.local_status='reconciliation' AND pa.provider_status IN ('PAYMENT_SUCCESS','CAPTURED') AND pa.created_at>=? AND pa.created_at<?$scope", $range)[0];
        $pending = $this->query("SELECT COUNT(*) AS n FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE pa.local_status IN " . OperationsMonitor::PENDING_ATTEMPT . " AND pa.created_at>=? AND pa.created_at<?$scope", $range)[0];
        $gross = array_sum(array_column($totals, 1));
        $rows = array_map(static fn (array $row): array => ['at' => $row['at'], 'reference' => $row['reference'], 'type' => self::PAYMENT_TYPES[$row['type']] ?? $row['type'], 'facility' => $row['facility'], 'gross_centavos' => (int) $row['gross_centavos'], 'refund_centavos' => (int) $row['refund_centavos']],
            $this->query("SELECT pa.verified_at AS at,pa.reference,pa.payment_type AS type,af.name AS facility,pa.amount_centavos AS gross_centavos,0 AS refund_centavos FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE pa.local_status='verified' AND pa.verified_at>=? AND pa.verified_at<?$scope
                UNION ALL SELECT rr.provider_confirmed_at,rr.reference,'CONFIRMED_REFUND',af.name,0,rr.amount_centavos FROM refund_requests rr JOIN payment_attempts pa ON pa.id=rr.payment_attempt_id " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE rr.status='provider_confirmed' AND rr.provider_confirmed_at>=? AND rr.provider_confirmed_at<?$scope
                ORDER BY at,reference", [...$range, ...$range]));
        return [
            'summary' => [['Verified initial parking payments', $totals['INITIAL_RESERVATION'][1], 'money'], ['Verified overtime settlements', $totals['OVERTIME_SETTLEMENT'][1], 'money'],
                ['Verified extension payments', $totals['PARKING_EXTENSION'][1], 'money'], ['Gross verified receipts', $gross, 'money'], ['Provider-confirmed refunds', (int) $refunds['total'], 'money'],
                ['Net verified receipts', $gross - (int) $refunds['total'], 'money'], ['Verified payments counted', array_sum(array_column($totals, 0)), 'int'],
                ['Provider-paid, held in reconciliation (not recognized)', (int) $held['total'], 'money'], ['Pending payments excluded', (int) $pending['n'], 'int']],
            'columns' => [['at', 'Verified / confirmed (Manila)', 'datetime'], ['reference', 'Reference', 'text'], ['type', 'Type', 'text'], ['facility', 'Facility', 'text'], ['gross_centavos', 'Gross', 'money'], ['refund_centavos', 'Refund', 'money']],
            'rows' => $rows,
            'notes' => ['Gross verified receipts = verified initial + verified overtime + verified extension payments, one payment attempt each (ledger rows are not added again).',
                'Net verified receipts = gross verified receipts − provider-confirmed refunds. Approved refunds awaiting Maya confirmation are not subtracted.',
                'Pending, failed, expired, and cancelled payments are excluded. Provider-paid payments held in reconciliation are shown separately and not recognized.'],
        ];
    }

    private function reconciliation(array $f): array
    {
        $scope = ' AND (?=0 OR af.id=?)'; $range = [$f['start'], $f['end'], $f['facility_id'], $f['facility_id']];
        $cases = (new ReconciliationService())->cases($f['facility_id'], false, new \DateTimeImmutable($f['now'], new \DateTimeZone('UTC')));
        $reviewed = (int) $this->query("SELECT COUNT(DISTINCT a.case_key) AS n FROM reconciliation_actions a LEFT JOIN payment_attempts pa ON pa.id=a.payment_attempt_id LEFT JOIN parking_session_exceptions e ON e.id=a.session_exception_id
            LEFT JOIN parking_sessions aps ON aps.id=COALESCE(pa.parking_session_id,e.parking_session_id) LEFT JOIN reservations ar ON ar.id=COALESCE(pa.reservation_id,aps.reservation_id) LEFT JOIN parking_slots ass ON ass.id=ar.slot_id LEFT JOIN parking_floors afl ON afl.id=ass.floor_id LEFT JOIN parking_facilities af ON af.id=afl.facility_id
            WHERE a.action='reviewed' AND a.created_at>=? AND a.created_at<?$scope", $range)[0]['n'];
        $count = fn (string $where): int => (int) $this->query("SELECT COUNT(*) AS n FROM payment_attempts pa " . OperationsMonitor::ATTEMPT_FACILITY . " WHERE $where AND pa.created_at>=? AND pa.created_at<?$scope", $range)[0]['n'];
        $mismatch = count(array_filter($cases, static fn (array $case): bool => array_intersect(['provider_mismatch', 'duplicate_provider'], $case['kinds']) !== []));
        $rows = array_map(static fn (array $case): array => ['updated_at' => $case['updated_at'], 'reference' => $case['reference'], 'kinds' => implode('; ', array_map(static fn (string $k): string => ReconciliationService::KINDS[$k], $case['kinds'])),
            'facility' => $case['facility_name'], 'amount_centavos' => $case['amount_centavos'] === null ? null : (int) $case['amount_centavos'], 'local_status' => $case['local_status'], 'provider_status' => (string) $case['provider_status']], $cases);
        return [
            'summary' => [['Open reconciliation cases (now)', count($cases), 'int'], ['Cases reviewed in period', $reviewed, 'int'],
                ['Payment failures', $count("pa.local_status IN ('failed','cancelled','expired')"), 'int'], ['Open provider/local mismatches', $mismatch, 'int'],
                ['Late payments', $count("pa.local_status='reconciliation' AND pa.reconciliation_reason LIKE '%no longer%'"), 'int']],
            'columns' => [['updated_at', 'Last update (Manila)', 'datetime'], ['reference', 'Reference', 'text'], ['kinds', 'Case', 'text'], ['facility', 'Facility', 'text'], ['amount_centavos', 'Amount', 'money'], ['local_status', 'Local status', 'text'], ['provider_status', 'Provider status', 'text']],
            'rows' => $rows,
            'notes' => ['Open cases are a live snapshot at generation time. Failures and late payments are dated by payment attempt creation time; reviews by review time.'],
        ];
    }

    /** Display formatting shared by every output. $target is screen, csv, or pdf. */
    public static function format(mixed $value, string $kind, string $target = 'screen'): string
    {
        if ($value === null) return $target === 'csv' ? '' : '—';
        return match ($kind) {
            'money' => $target === 'csv' ? number_format((int) $value / 100, 2, '.', '') : ($target === 'pdf' ? 'PHP ' : '₱') . number_format((int) $value / 100, 2),
            'int' => $target === 'csv' ? (string) (int) $value : number_format((int) $value),
            'hours' => number_format((int) $value / 3600, 2, '.', $target === 'csv' ? '' : ','),
            'percent' => number_format((int) $value / 100, 2) . '%',
            'datetime' => (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }

    public static function periodLabel(array $filters): string
    {
        return $filters['from'] . ' to ' . $filters['to'] . ' (Asia/Manila)';
    }
}
