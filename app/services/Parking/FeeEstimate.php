<?php
declare(strict_types=1);
namespace ParkNGo\Services\Parking;

/** Integer-centavo estimate. Actual session billing is implemented in Phase 6. */
final class FeeEstimate
{
    public static function calculate(array $rate, int $minutes): array
    {
        if ($minutes < 1 || $minutes > 1440 || (int) $rate['initial_minutes'] < 1) throw new \InvalidArgumentException('Choose a valid parking duration and configured rate.');
        foreach (['initial_centavos','succeeding_hour_centavos','grace_minutes','additional_centavos'] as $key) {
            if (!isset($rate[$key]) || !is_numeric($rate[$key]) || (int) $rate[$key] < 0) throw new \InvalidArgumentException('Parking rates need an administrator review.');
        }
        $free = $minutes <= (int) $rate['grace_minutes'];
        $hours = $free ? 0 : intdiv(max(0, $minutes - (int) $rate['initial_minutes']) + 59, 60);
        $initial = $free ? 0 : (int) $rate['initial_centavos'];
        $succeeding = $hours * (int) $rate['succeeding_hour_centavos'];
        $subtotal = $initial + $succeeding;
        $cap = $rate['daily_max_centavos'] === null ? null : (int) $rate['daily_max_centavos'];
        if ($cap !== null && $cap < 0) throw new \InvalidArgumentException('The daily cap needs an administrator review.');
        $parking = $cap === null ? $subtotal : min($subtotal, $cap);
        $additional = $free ? 0 : (int) $rate['additional_centavos'];
        $total = $parking + $additional;
        if ($total > 4294967295) throw new \InvalidArgumentException('This estimate exceeds the supported range. Please contact the facility.');
        return ['minutes'=>$minutes,'grace_applied'=>$free,'initial_centavos'=>$initial,'succeeding_hours'=>$hours,
            'succeeding_centavos'=>$succeeding,'cap_reduction_centavos'=>$subtotal-$parking,'additional_centavos'=>$additional,
            'total_centavos'=>$total,'currency'=>'PHP'];
    }
}
