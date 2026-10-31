<?php
declare(strict_types=1);

/* Admin operations view helpers. Escaped markup only; no inline styles or scripts (CSP). */

if (!function_exists('admin_time')) {
    /** UTC database time shown in Manila time. */
    function admin_time(?string $utc, string $format = 'M j, Y g:i A'): string
    {
        if ($utc === null || $utc === '') return '—';
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
    }

    function admin_facility_select(array $facilities, int $selected, bool $allowAll = true, string $name = 'facility_id'): string
    {
        $html = '<label><span>Facility</span><select name="' . e($name) . '">';
        if ($allowAll) $html .= '<option value="0"' . ($selected === 0 ? ' selected' : '') . '>All facilities</option>';
        foreach ($facilities as $facility) {
            $html .= '<option value="' . (int) $facility['id'] . '"' . ((int) $facility['id'] === $selected ? ' selected' : '') . '>' . e($facility['name']) . (!$facility['is_active'] ? ' (inactive)' : '') . '</option>';
        }
        return $html . '</select></label>';
    }

    function admin_pager(string $route, array $parameters, int $page, int $total, int $size): string
    {
        $pages = max(1, (int) ceil($total / $size));
        if ($pages <= 1) return '';
        $html = '<nav class="pager" aria-label="Pages">';
        $html .= $page > 1 ? '<a class="button secondary" href="' . e(url($route, $parameters + ['p' => $page - 1])) . '">' . ui_icon('chevron-left', 18) . 'Previous</a>' : '<span></span>';
        $html .= '<span class="small muted">Page ' . $page . ' of ' . $pages . ' · ' . number_format($total) . ' total</span>';
        $html .= $page < $pages ? '<a class="button secondary" href="' . e(url($route, $parameters + ['p' => $page + 1])) . '">Next' . ui_icon('chevron-right', 18) . '</a>' : '<span></span>';
        return $html . '</nav>';
    }

    function admin_errors(array $errors): string
    {
        if (!$errors) return '';
        $html = '<div class="form-errors" role="alert"><ul>';
        foreach ($errors as $error) $html .= '<li>' . e(ui_error_message($error)) . '</li>';
        return $html . '</ul></div>';
    }

    function admin_type(string $type): string
    {
        return \ParkNGo\Services\Admin\ReportService::PAYMENT_TYPES[$type] ?? ucwords(strtolower(str_replace('_', ' ', $type)));
    }
}
