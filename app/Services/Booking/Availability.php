<?php
declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\DB;

/**
 * Works out which times an agent can actually be booked for, from the opening hours, the
 * appointments already taken and the busy blocks of any calendar the business linked.
 */
final class Availability
{
    /** Free start times for one day, as ['HH:MM' => ..., 'iso' => ...]. */
    public static function slotsForDay(array $agent, array $settings, string $date, int $durationMinutes, ?array $busyCache = null): array
    {
        $tz = self::zone($agent);
        try {
            $day = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        } catch (\Throwable) {
            return [];
        }
        if (in_array($day->format('Y-m-d'), $settings['closed_dates'], true)) {
            return [];
        }
        $weekday = (int) $day->format('N');
        $windows = $settings['hours'][$weekday] ?? [];
        if (!$windows) {
            return [];
        }
        $now = time();
        $earliest = $now + $settings['min_notice_hours'] * 3600;
        $latest = $now + $settings['max_advance_days'] * 86400;
        $duration = max(5, $durationMinutes) * 60;
        $buffer = $settings['buffer_minutes'] * 60;
        $interval = $settings['slot_interval'] * 60;

        $dayStart = $day->getTimestamp();
        $dayEnd = $dayStart + 86400 + 7200; // allow windows that run past midnight
        $taken = $busyCache ?? self::busyBlocks($agent, $dayStart - 7200, $dayEnd);

        $slots = [];
        foreach ($windows as $window) {
            $from = self::atTime($day, $window['from']);
            $to = self::atTime($day, $window['to']);
            if ($from === null || $to === null) {
                continue;
            }
            for ($t = $from; $t + $duration <= $to; $t += $interval) {
                if ($t < $earliest || $t > $latest) {
                    continue;
                }
                if (self::isFree($t, $t + $duration + $buffer, $taken, $settings['capacity'])) {
                    $slots[] = [
                        'time' => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('H:i'),
                        'iso' => (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('c'),
                        'timestamp' => $t,
                    ];
                }
                if (count($slots) >= 120) {
                    break 2;
                }
            }
        }
        return $slots;
    }

    /**
     * Scan forward for the next days that have any free slot.
     * Returns [['date' => 'Y-m-d', 'label' => 'Monday 8 September', 'slots' => [...]], ...]
     */
    public static function nextAvailable(array $agent, array $settings, int $durationMinutes, ?string $fromDate = null, int $maxDays = 5, int $slotsPerDay = 8): array
    {
        $tz = self::zone($agent);
        $start = null;
        if ($fromDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            try {
                $start = new \DateTimeImmutable($fromDate . ' 00:00:00', $tz);
            } catch (\Throwable) {
                $start = null;
            }
        }
        $today = new \DateTimeImmutable('now', $tz);
        if ($start === null || $start < $today->setTime(0, 0)) {
            $start = $today->setTime(0, 0);
        }
        // One busy lookup for the whole scan instead of one per day
        $horizon = min($settings['max_advance_days'], 90);
        $busy = self::busyBlocks($agent, $start->getTimestamp() - 7200, $start->getTimestamp() + ($horizon + 2) * 86400);

        $days = [];
        for ($i = 0; $i <= $horizon && count($days) < $maxDays; $i++) {
            $date = $start->modify('+' . $i . ' days');
            $slots = self::slotsForDay($agent, $settings, $date->format('Y-m-d'), $durationMinutes, $busy);
            if ($slots) {
                $days[] = [
                    'date' => $date->format('Y-m-d'),
                    'label' => $date->format('l j F'),
                    'slots' => array_slice($slots, 0, $slotsPerDay),
                    'total' => count($slots),
                ];
            }
        }
        return $days;
    }

    /** True when a specific start time is bookable for this duration. */
    public static function isSlotFree(array $agent, array $settings, int $startTs, int $durationMinutes): bool
    {
        $tz = self::zone($agent);
        $date = (new \DateTimeImmutable('@' . $startTs))->setTimezone($tz)->format('Y-m-d');
        foreach (self::slotsForDay($agent, $settings, $date, $durationMinutes) as $slot) {
            if ($slot['timestamp'] === $startTs) {
                return true;
            }
        }
        return false;
    }

    /** Appointments plus linked-calendar events as [[start, end], ...] epoch seconds. */
    public static function busyBlocks(array $agent, int $fromTs, int $toTs): array
    {
        $rows = DB::instance()->fetchAll(
            "SELECT starts_at, ends_at FROM appointments WHERE agent_id = ? AND status IN ('confirmed','pending') AND ends_at >= ? AND starts_at <= ?",
            [(int) $agent['id'], gmdate('Y-m-d H:i:s', $fromTs), gmdate('Y-m-d H:i:s', $toTs)]
        );
        $blocks = [];
        foreach ($rows as $row) {
            $blocks[] = [strtotime((string) $row['starts_at'] . ' UTC'), strtotime((string) $row['ends_at'] . ' UTC')];
        }
        foreach (Ics::busyForAgent((int) $agent['id'], $fromTs, $toTs) as $block) {
            $blocks[] = $block;
        }
        return $blocks;
    }

    /** Capacity-aware overlap test: a slot is free while fewer than `capacity` blocks cover it. */
    private static function isFree(int $start, int $end, array $blocks, int $capacity): bool
    {
        $overlaps = 0;
        foreach ($blocks as $block) {
            if ($block[0] < $end && $block[1] > $start) {
                $overlaps++;
                if ($overlaps >= $capacity) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function atTime(\DateTimeImmutable $day, string $hhmm): ?int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => '0']);
        if ($h === 24) {
            return $day->modify('+1 day')->setTime(0, 0)->getTimestamp();
        }
        return $day->setTime($h, $m)->getTimestamp();
    }

    public static function zone(array $agent): \DateTimeZone
    {
        $tz = trim((string) ($agent['timezone'] ?? '')) ?: 'UTC';
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Read a date/time the model produced. Accepts "2026-09-10 14:30", ISO 8601 and "2026-09-10T14:30".
     * Values without an offset are read in the agent's timezone, which is what the visitor meant.
     */
    public static function parseWhen(array $agent, string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value);
        try {
            $dt = $hasOffset
                ? new \DateTimeImmutable($value)
                : new \DateTimeImmutable($value, self::zone($agent));
        } catch (\Throwable) {
            return null;
        }
        return $dt->getTimestamp();
    }

    /** Format a timestamp for humans in the agent's timezone. */
    public static function label(array $agent, int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::zone($agent))->format('l j F Y \a\t H:i');
    }
}
