<?php
declare(strict_types=1);

namespace App\Services\Booking;

/**
 * Per-agent booking configuration, merged with the defaults of the chosen business type.
 */
final class BookingSettings
{
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public static function defaults(string $type = 'general'): array
    {
        $preset = BookingTypes::get($type);
        return [
            'services' => $preset['services'],
            'questions' => $preset['questions'],
            'hours' => [
                1 => [['from' => '09:00', 'to' => '17:00']],
                2 => [['from' => '09:00', 'to' => '17:00']],
                3 => [['from' => '09:00', 'to' => '17:00']],
                4 => [['from' => '09:00', 'to' => '17:00']],
                5 => [['from' => '09:00', 'to' => '17:00']],
                6 => [],
                7 => [],
            ],
            'slot_interval' => 30,        // minutes between candidate start times
            'buffer_minutes' => 0,        // gap kept after each appointment
            'min_notice_hours' => 2,      // earliest a visitor can book from now
            'max_advance_days' => 60,     // furthest ahead a visitor can book
            'capacity' => 1,              // how many appointments can overlap
            'closed_dates' => [],         // ['2026-12-25', ...]
            'location' => '',
            'confirmation_message' => '',
            'ask_notes' => true,
            'require_email' => true,
            'require_phone' => false,
        ];
    }

    /** Merge the stored JSON with the defaults for the agent's booking type. */
    public static function forAgent(array $agent): array
    {
        $type = (string) ($agent['booking_type'] ?? 'general');
        $stored = json_field($agent['booking_settings'] ?? null);
        $settings = array_merge(self::defaults($type), is_array($stored) ? $stored : []);

        $settings['services'] = self::normaliseServices($settings['services'] ?? []);
        if (!$settings['services']) {
            $settings['services'] = self::normaliseServices(BookingTypes::get($type)['services']);
        }
        $settings['questions'] = self::normaliseQuestions($settings['questions'] ?? []);
        $settings['hours'] = self::normaliseHours($settings['hours'] ?? []);
        $settings['slot_interval'] = max(5, min(240, (int) $settings['slot_interval']));
        $settings['buffer_minutes'] = max(0, min(240, (int) $settings['buffer_minutes']));
        $settings['min_notice_hours'] = max(0, min(720, (int) $settings['min_notice_hours']));
        $settings['max_advance_days'] = max(1, min(365, (int) $settings['max_advance_days']));
        $settings['capacity'] = max(1, min(50, (int) $settings['capacity']));
        $settings['closed_dates'] = array_values(array_filter(array_map(
            static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? (string) $d : null,
            (array) ($settings['closed_dates'] ?? [])
        )));
        foreach (['location', 'confirmation_message'] as $k) {
            $settings[$k] = mb_substr(trim((string) ($settings[$k] ?? '')), 0, 500);
        }
        foreach (['ask_notes', 'require_email', 'require_phone'] as $k) {
            $settings[$k] = (bool) ($settings[$k] ?? false);
        }
        return $settings;
    }

    public static function normaliseServices(array $services): array
    {
        $out = [];
        foreach ($services as $s) {
            if (!is_array($s)) {
                continue;
            }
            $name = mb_substr(trim((string) ($s['name'] ?? '')), 0, 120);
            if ($name === '') {
                continue;
            }
            $entry = [
                'name' => $name,
                'minutes' => max(5, min(600, (int) ($s['minutes'] ?? 30))),
            ];
            $price = trim((string) ($s['price'] ?? ''));
            if ($price !== '') {
                $entry['price'] = mb_substr($price, 0, 40);
            }
            $out[] = $entry;
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    public static function normaliseQuestions(array $questions): array
    {
        $types = ['text', 'textarea', 'choice', 'yesno', 'phone', 'email'];
        $out = [];
        foreach ($questions as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $label = mb_substr(trim((string) ($q['label'] ?? '')), 0, 200);
            if ($label === '') {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($q['key'] ?? '')));
            if ($key === '' || $key === null) {
                $key = 'q' . ($i + 1);
            }
            $type = in_array($q['type'] ?? '', $types, true) ? (string) $q['type'] : 'text';
            $entry = ['key' => mb_substr($key, 0, 40), 'label' => $label, 'type' => $type, 'required' => (bool) ($q['required'] ?? false)];
            if ($type === 'choice') {
                $options = array_values(array_filter(array_map(
                    static fn($o) => mb_substr(trim((string) $o), 0, 80),
                    (array) ($q['options'] ?? [])
                )));
                $entry['options'] = array_slice($options, 0, 20);
                if (!$entry['options']) {
                    $entry['type'] = 'text';
                    unset($entry['options']);
                }
            }
            $out[] = $entry;
            if (count($out) >= 20) {
                break;
            }
        }
        return $out;
    }

    /** Weekday => list of {from, to} windows in HH:MM, sorted and validated. */
    public static function normaliseHours(array $hours): array
    {
        $out = [];
        for ($day = 1; $day <= 7; $day++) {
            $windows = [];
            foreach ((array) ($hours[$day] ?? $hours[(string) $day] ?? []) as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $from = self::time((string) ($w['from'] ?? ''));
                $to = self::time((string) ($w['to'] ?? ''));
                if ($from === null || $to === null || $to <= $from) {
                    continue;
                }
                $windows[] = ['from' => $from, 'to' => $to];
            }
            usort($windows, static fn($a, $b) => strcmp($a['from'], $b['from']));
            $out[$day] = array_slice($windows, 0, 6);
        }
        return $out;
    }

    private static function time(string $value): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 24 || $min > 59 || ($h === 24 && $min > 0)) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $min);
    }

    /** Find a service by name (case-insensitive, tolerant of partial matches from the model). */
    public static function findService(array $settings, string $name): ?array
    {
        $name = trim(mb_strtolower($name));
        if ($name === '') {
            return $settings['services'][0] ?? null;
        }
        foreach ($settings['services'] as $s) {
            if (mb_strtolower($s['name']) === $name) {
                return $s;
            }
        }
        foreach ($settings['services'] as $s) {
            $sn = mb_strtolower($s['name']);
            if (str_contains($sn, $name) || str_contains($name, $sn)) {
                return $s;
            }
        }
        return null;
    }

    /** A compact description of the booking setup for the system prompt. */
    public static function summary(array $agent, array $settings): string
    {
        $noun = BookingTypes::noun((string) ($agent['booking_type'] ?? 'general'));
        $lines = [];
        $services = [];
        foreach ($settings['services'] as $s) {
            $services[] = $s['name'] . ' (' . $s['minutes'] . ' min' . (isset($s['price']) ? ', ' . $s['price'] : '') . ')';
        }
        $lines[] = 'Bookable services: ' . implode('; ', $services) . '.';
        $open = [];
        foreach (self::WEEKDAYS as $day => $label) {
            $windows = $settings['hours'][$day] ?? [];
            if ($windows) {
                $parts = array_map(static fn($w) => $w['from'] . '-' . $w['to'], $windows);
                $open[] = $label . ' ' . implode(', ', $parts);
            }
        }
        $lines[] = 'Opening hours (' . ($agent['timezone'] ?: 'UTC') . '): ' . ($open ? implode('; ', $open) : 'not set') . '.';
        if ($settings['location'] !== '') {
            $lines[] = 'Location: ' . $settings['location'];
        }
        $lines[] = 'Bookings can be made from ' . $settings['min_notice_hours'] . ' hours ahead up to ' . $settings['max_advance_days'] . ' days ahead.';
        if ($settings['questions']) {
            $qs = [];
            foreach ($settings['questions'] as $q) {
                $qs[] = $q['label'] . ($q['required'] ? ' (required)' : '') . ($q['type'] === 'choice' ? ' [' . implode(' / ', $q['options'] ?? []) . ']' : '');
            }
            $lines[] = 'Before booking a ' . $noun . ', ask these questions one at a time and pass the answers to book_appointment: ' . implode(' | ', $qs);
        }
        return implode("\n", $lines);
    }
}
