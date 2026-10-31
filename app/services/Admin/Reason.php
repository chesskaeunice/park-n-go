<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

/** Validation shared by sensitive admin actions: written reasons and peso amounts in integer centavos. */
final class Reason
{
    public static function require(string $reason, int $minimum = 10): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < $minimum || mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $reason)) {
            throw new \InvalidArgumentException('Enter a reason of ' . $minimum . '–1000 characters.');
        }
        return $reason;
    }

    /** Parses "1234", "1234.5" or "1234.50" pesos without floating point. Rejects negatives and sub-centavo precision. */
    public static function centavos(string $value, string $field, int $minimum = 1, int $maximum = 10000000): int
    {
        $value = trim(str_replace(',', '', $value));
        if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/D', $value, $match)) throw new \InvalidArgumentException('Enter the ' . $field . ' in pesos with at most two decimal places.');
        $centavos = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
        if ($centavos < $minimum || $centavos > $maximum) throw new \InvalidArgumentException('The ' . $field . ' must be between ₱' . number_format($minimum / 100, 2) . ' and ₱' . number_format($maximum / 100, 2) . '.');
        return $centavos;
    }
}
