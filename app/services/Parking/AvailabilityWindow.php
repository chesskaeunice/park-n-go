<?php
declare(strict_types=1);
namespace ParkNGo\Services\Parking;

final class AvailabilityWindow
{
    public readonly \DateTimeImmutable $start;
    public readonly \DateTimeImmutable $end;
    public readonly int $hours;
    public readonly bool $explicit;

    public function __construct(array $query)
    {
        foreach (['date', 'time', 'hours'] as $key) {
            if (isset($query[$key]) && !is_string($query[$key])) throw new \InvalidArgumentException('Use a valid date, time, and duration.');
        }
        $date = $query['date'] ?? ''; $time = $query['time'] ?? '';
        $hours = $query['hours'] ?? '1';
        if (!preg_match('/^([1-9]|1[0-9]|2[0-4])$/D', $hours)) throw new \InvalidArgumentException('Choose a duration between 1 and 24 hours.');
        $this->hours = (int) $hours;
        $this->explicit = $date !== '' || $time !== '';
        if (($date === '') !== ($time === '')) throw new \InvalidArgumentException('Choose both a date and an arrival time, or leave both empty to check now.');
        if ($this->explicit) {
            $text = $date . ' ' . $time;
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, new \DateTimeZone('Asia/Manila'));
            if (!$start || $start->format('Y-m-d H:i') !== $text) throw new \InvalidArgumentException('Enter a valid date and arrival time.');
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
            if ($start < $now->modify('-1 minute') || $start > $now->modify('+1 year')) throw new \InvalidArgumentException('Choose an arrival time from now up to one year ahead.');
        } else $start = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        $this->start = $start->setTimezone(new \DateTimeZone('UTC'));
        $this->end = $this->start->modify('+' . $this->hours . ' hours');
    }

    public function query(): array
    {
        $local = $this->start->setTimezone(new \DateTimeZone('Asia/Manila'));
        return ['date' => $this->explicit ? $local->format('Y-m-d') : '', 'time' => $this->explicit ? $local->format('H:i') : '', 'hours' => (string) $this->hours];
    }

    public function label(): string
    {
        return $this->start->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A') . ' · ' . $this->hours . ' hour' . ($this->hours === 1 ? '' : 's') . ' · Manila time';
    }
}
