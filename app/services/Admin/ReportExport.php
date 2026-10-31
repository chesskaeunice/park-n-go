<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

/**
 * CSV and PDF renderings of a ReportService::build() result. No external dependency: the PDF writer emits
 * standard Helvetica text pages (uncompressed, so exports stay inspectable) with a repeated table header,
 * page numbers, and the same formatted values as the screen.
 */
final class ReportExport
{
    public const PDF_ROW_LIMIT = 2000;

    /** Spreadsheet formula injection: text starting with = + - @ tab or CR is forced to plain text. */
    public static function csvCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    public static function csv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        $line = static function (array $cells) use ($handle): void { fputcsv($handle, $cells, ',', '"', ''); };
        $line([self::csvCell($report['title'])]);
        $line(['Generated', self::format($report['generated_at'], 'datetime', 'csv') . ' Asia/Manila']);
        $line(['Facility', self::csvCell($report['filters']['facility_name'])]);
        $line(['Period', ReportService::periodLabel($report['filters'])]);
        $line([]);
        foreach ($report['summary'] as [$label, $value, $kind]) $line([self::csvCell($label), self::format($value, $kind, 'csv')]);
        $line([]);
        $line(array_map(static fn (array $column): string => $column[1], $report['columns']));
        foreach ($report['rows'] as $row) {
            $line(array_map(static fn (array $column): string => $column[2] === 'text' ? self::csvCell((string) ($row[$column[0]] ?? '')) : self::format($row[$column[0]] ?? null, $column[2], 'csv'), $report['columns']));
        }
        rewind($handle); $csv = stream_get_contents($handle); fclose($handle);
        return "\xEF\xBB\xBF" . $csv;
    }

    private static function format(mixed $value, string $kind, string $target): string
    {
        return ReportService::format($value, $kind, $target);
    }

    public static function pdf(array $report): string
    {
        $truncated = count($report['rows']) > self::PDF_ROW_LIMIT;
        $rows = array_slice($report['rows'], 0, self::PDF_ROW_LIMIT);
        [$width, $height, $margin] = [842, 595, 36];
        $usable = $width - 2 * $margin;
        $weights = array_map(static fn (array $column): int => $column[2] === 'text' ? 2 : 1, $report['columns']);
        $unit = $usable / array_sum($weights);
        $widths = array_map(static fn (int $weight): float => $weight * $unit, $weights);
        $pages = []; $ops = ''; $y = 0.0;
        $text = static function (float $x, float $y, string $value, bool $bold = false, float $size = 8.5) use (&$ops): void {
            $ops .= sprintf("BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $y, self::pdfText($value));
        };
        $fit = static fn (string $value, float $columnWidth): string => mb_strlen($value) > ($max = max(4, (int) floor(($columnWidth - 6) / 4.4))) ? mb_substr($value, 0, $max - 1) . '…' : $value;
        $header = function () use (&$ops, &$y, $text, $widths, $report, $margin, $width, $fit): void {
            $x = $margin;
            foreach ($report['columns'] as $index => $column) { $text($x, $y, $fit($column[1], $widths[$index]), true); $x += $widths[$index]; }
            $ops .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $margin, $y - 4, $width - $margin, $y - 4);
            $y -= 16;
        };
        $newPage = function (bool $first) use (&$pages, &$ops, &$y, $text, $header, $report, $margin, $height, $truncated): void {
            if ($ops !== '') $pages[] = $ops;
            $ops = ''; $y = $height - $margin;
            $text($margin, $y, $report['title'], true, $first ? 15 : 10); $y -= $first ? 20 : 16;
            if ($first) {
                $text($margin, $y, 'Generated ' . ReportService::format($report['generated_at'], 'datetime', 'pdf') . ' (Asia/Manila)'); $y -= 12;
                $text($margin, $y, 'Facility: ' . $report['filters']['facility_name'] . '    Period: ' . ReportService::periodLabel($report['filters'])); $y -= 18;
                foreach ($report['summary'] as $index => [$label, $value, $kind]) {
                    $text($margin + ($index % 2) * 380, $y, $label . ': ' . ReportService::format($value, $kind, 'pdf'));
                    if ($index % 2 === 1 || $index === count($report['summary']) - 1) $y -= 12;
                }
                foreach ($report['notes'] as $note) { $text($margin, $y - 2, $note, false, 7); $y -= 10; }
                if ($truncated) { $text($margin, $y - 2, 'Only the first ' . self::PDF_ROW_LIMIT . ' of ' . count($report['rows']) . ' rows are included. Export CSV for the complete table.', true, 7.5); $y -= 10; }
                $y -= 12;
            }
            $header();
        };
        $newPage(true);
        foreach ($rows as $row) {
            if ($y < $margin + 20) $newPage(false);
            $x = $margin;
            foreach ($report['columns'] as $index => $column) { $text($x, $y, $fit(ReportService::format($row[$column[0]] ?? null, $column[2], 'pdf'), $widths[$index])); $x += $widths[$index]; }
            $y -= 12;
        }
        if (!$rows) { $text($margin, $y, 'No rows for these filters.'); }
        $pages[] = $ops;
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $number => $content) {
            $content .= sprintf("BT /F1 7.5 Tf %.2F %.2F Td (%s) Tj ET\n", $width - $margin - 60, 20, self::pdfText('Page ' . ($number + 1) . ' of ' . count($pages)));
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $contentId = count($objects);
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $width $height] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contentId 0 R >>";
            $kids[] = count($objects) . ' 0 R';
        }
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [];
        foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** WinAnsi text for the standard fonts, with PDF string delimiters escaped. */
    private static function pdfText(string $value): string
    {
        $value = str_replace('₱', 'PHP ', $value);
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        if ($encoded === false) $encoded = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        return strtr(preg_replace('/[\x00-\x1F]/', ' ', $encoded) ?? '', ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
