<?php
declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;

/**
 * Calendar interchange: builds .ics files for confirmed appointments and reads busy blocks out of a
 * calendar the business already uses (the secret ICS address of Google, Outlook, Apple, Fastmail, ...).
 */
final class Ics
{
    /** How long a fetched calendar is trusted before it is fetched again. */
    private const CACHE_SECONDS = 900;

    /** A single appointment as an .ics document the visitor can add to their own calendar. */
    public static function forAppointment(array $appointment, array $agent): string
    {
        $tz = (string) ($appointment['timezone'] ?: 'UTC');
        $start = new \DateTimeImmutable((string) $appointment['starts_at'], new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable((string) $appointment['ends_at'], new \DateTimeZone('UTC'));
        $title = (string) $appointment['service'] . ' - ' . (string) ($agent['business_name'] ?: $agent['name']);
        $settings = BookingSettings::forAgent($agent);
        $description = [];
        $description[] = 'Reference: ' . $appointment['reference'];
        if (!empty($appointment['customer_name'])) {
            $description[] = 'Booked for: ' . $appointment['customer_name'];
        }
        $answers = json_field($appointment['answers'] ?? null);
        if (is_array($answers)) {
            foreach ($answers as $k => $v) {
                if (is_scalar($v) && trim((string) $v) !== '') {
                    $description[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $v;
                }
            }
        }
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . self::esc(app_name()) . '//Booking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $appointment['reference'] . '@' . self::host(),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . self::esc($title),
            'DESCRIPTION:' . self::esc(implode("\n", $description)),
            'STATUS:' . ($appointment['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
        ];
        if ($settings['location'] !== '') {
            $lines[] = 'LOCATION:' . self::esc($settings['location']);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return self::fold($lines) . "\r\n";
    }

    /** Every upcoming appointment for an agent, as a calendar the owner can subscribe to. */
    public static function feedForAgent(array $agent, array $appointments): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . self::esc(app_name()) . '//Booking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::esc(($agent['business_name'] ?: $agent['name']) . ' bookings'),
            'X-PUBLISHED-TTL:PT15M',
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
        ];
        foreach ($appointments as $a) {
            $start = new \DateTimeImmutable((string) $a['starts_at'], new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable((string) $a['ends_at'], new \DateTimeZone('UTC'));
            $who = trim((string) ($a['customer_name'] ?? '')) ?: 'Visitor';
            $desc = ['Reference: ' . $a['reference'], 'Name: ' . $who];
            foreach (['customer_email' => 'Email', 'customer_phone' => 'Phone'] as $field => $label) {
                if (!empty($a[$field])) {
                    $desc[] = $label . ': ' . $a[$field];
                }
            }
            $answers = json_field($a['answers'] ?? null);
            if (is_array($answers)) {
                foreach ($answers as $k => $v) {
                    if (is_scalar($v) && trim((string) $v) !== '') {
                        $desc[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $v;
                    }
                }
            }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $a['reference'] . '@' . self::host();
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . self::esc($a['service'] . ' - ' . $who);
            $lines[] = 'DESCRIPTION:' . self::esc(implode("\n", $desc));
            $lines[] = 'STATUS:' . ($a['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return self::fold($lines) . "\r\n";
    }

    /**
     * Busy blocks from every calendar linked to an agent, as [[startTs, endTs], ...] in UTC epoch seconds.
     * Results are cached in the database so availability checks stay fast.
     */
    public static function busyForAgent(int $agentId, int $fromTs, int $toTs, bool $forceRefresh = false): array
    {
        $links = DB::instance()->fetchAll('SELECT * FROM calendar_links WHERE agent_id = ?', [$agentId]);
        $busy = [];
        foreach ($links as $link) {
            foreach (self::busyForLink($link, $forceRefresh) as $block) {
                if ($block[1] > $fromTs && $block[0] < $toTs) {
                    $busy[] = $block;
                }
            }
        }
        return $busy;
    }

    /** Busy blocks for one linked calendar, refreshing the cache when it is stale. */
    public static function busyForLink(array $link, bool $forceRefresh = false): array
    {
        $cached = json_field($link['busy_json'] ?? null);
        $age = !empty($link['last_synced_at']) ? time() - strtotime((string) $link['last_synced_at'] . ' UTC') : PHP_INT_MAX;
        if (!$forceRefresh && is_array($cached) && $age < self::CACHE_SECONDS) {
            return array_map(static fn($b) => [(int) $b[0], (int) $b[1]], $cached);
        }
        try {
            $blocks = self::fetchBusy((string) $link['ics_url']);
            DB::instance()->update('calendar_links', [
                'busy_json' => json_encode($blocks),
                'last_synced_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $link['id']]);
            return $blocks;
        } catch (\Throwable $e) {
            Logger::error('Calendar sync failed for link ' . $link['id'] . ': ' . $e->getMessage());
            DB::instance()->update('calendar_links', [
                'last_error' => mb_substr($e->getMessage(), 0, 250),
                'last_synced_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => (int) $link['id']]);
            // Keep using the previous snapshot rather than opening up slots that may be taken
            return is_array($cached) ? array_map(static fn($b) => [(int) $b[0], (int) $b[1]], $cached) : [];
        }
    }

    /** Download and parse a calendar, returning busy blocks for roughly the next year. */
    public static function fetchBusy(string $url): array
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://' . substr($url, 9);
        }
        if (!preg_match('~^https?://~i', $url)) {
            throw new \RuntimeException('The calendar address must start with https://');
        }
        $response = Http::get($url, ['timeout' => 20, 'max_bytes' => 4 * 1024 * 1024, 'ipv4' => true]);
        if (!$response->ok()) {
            throw new \RuntimeException('Could not read the calendar (HTTP ' . $response->status . ').');
        }
        $body = $response->body;
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('That address did not return a calendar file.');
        }
        return self::parseBusy($body, time() - 86400, time() + 370 * 86400);
    }

    /**
     * Parse VEVENTs into busy blocks. Handles plain events plus simple daily/weekly recurrence,
     * which covers the repeating blocks people actually put in a working calendar.
     */
    public static function parseBusy(string $ics, int $windowFrom, int $windowTo): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $ics) ?: [];
        // Unfold continuation lines (a leading space or tab continues the previous line)
        $unfolded = [];
        foreach ($lines as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $unfolded) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            } else {
                $unfolded[] = $line;
            }
        }
        $busy = [];
        $event = null;
        foreach ($unfolded as $line) {
            $upper = strtoupper($line);
            if ($upper === 'BEGIN:VEVENT') {
                $event = [];
                continue;
            }
            if ($upper === 'END:VEVENT') {
                if ($event !== null) {
                    foreach (self::expand($event, $windowFrom, $windowTo) as $block) {
                        $busy[] = $block;
                    }
                }
                $event = null;
                continue;
            }
            if ($event === null) {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $rawName = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);
            $params = explode(';', $rawName);
            $name = strtoupper(array_shift($params));
            $event[$name] = ['value' => $value, 'params' => $params];
        }
        usort($busy, static fn($a, $b) => $a[0] <=> $b[0]);
        return array_slice($busy, 0, 5000);
    }

    /** Turn one parsed VEVENT into busy blocks inside the window. */
    private static function expand(array $event, int $windowFrom, int $windowTo): array
    {
        if (isset($event['STATUS']) && strtoupper(trim($event['STATUS']['value'])) === 'CANCELLED') {
            return [];
        }
        if (isset($event['TRANSP']) && strtoupper(trim($event['TRANSP']['value'])) === 'TRANSPARENT') {
            return []; // marked "free" in the source calendar
        }
        if (!isset($event['DTSTART'])) {
            return [];
        }
        $start = self::parseDate($event['DTSTART']);
        if ($start === null) {
            return [];
        }
        $end = isset($event['DTEND']) ? self::parseDate($event['DTEND']) : null;
        if ($end === null && isset($event['DURATION'])) {
            $end = $start + self::parseDuration($event['DURATION']['value']);
        }
        if ($end === null) {
            $allDay = self::isAllDay($event['DTSTART']);
            $end = $start + ($allDay ? 86400 : 3600);
        }
        if ($end <= $start) {
            $end = $start + 1800;
        }
        $length = $end - $start;
        $blocks = [];
        $rrule = isset($event['RRULE']) ? self::parseRrule($event['RRULE']['value']) : null;
        if (!$rrule) {
            if ($end > $windowFrom && $start < $windowTo) {
                $blocks[] = [$start, $end];
            }
            return $blocks;
        }
        $freq = $rrule['FREQ'] ?? '';
        $interval = max(1, (int) ($rrule['INTERVAL'] ?? 1));
        $count = isset($rrule['COUNT']) ? (int) $rrule['COUNT'] : null;
        $until = isset($rrule['UNTIL']) ? self::parseDate(['value' => $rrule['UNTIL'], 'params' => []]) : null;
        $step = match ($freq) {
            'DAILY' => 86400 * $interval,
            'WEEKLY' => 604800 * $interval,
            default => 0,
        };
        if ($step === 0) {
            // Monthly/yearly recurrence is rare for busy blocks; treat it as a single event.
            if ($end > $windowFrom && $start < $windowTo) {
                $blocks[] = [$start, $end];
            }
            return $blocks;
        }
        $byDay = isset($rrule['BYDAY']) ? array_filter(array_map('trim', explode(',', $rrule['BYDAY']))) : [];
        $dayMap = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $occurrences = 0;
        for ($t = $start; $t < $windowTo && $occurrences < 400; $t += $step) {
            if ($until !== null && $t > $until) {
                break;
            }
            if ($count !== null && $occurrences >= $count) {
                break;
            }
            $occurrences++;
            $candidates = [$t];
            if ($freq === 'WEEKLY' && $byDay) {
                $candidates = [];
                $weekStart = $t - ((int) gmdate('N', $t) - 1) * 86400;
                foreach ($byDay as $code) {
                    $code = strtoupper(substr($code, -2));
                    if (!isset($dayMap[$code])) {
                        continue;
                    }
                    $candidate = $weekStart + ($dayMap[$code] - 1) * 86400;
                    $candidate += ($t - (int) (floor($t / 86400) * 86400)) - ($candidate - (int) (floor($candidate / 86400) * 86400));
                    $candidates[] = $candidate;
                }
            }
            foreach ($candidates as $c) {
                if ($c + $length > $windowFrom && $c < $windowTo) {
                    $blocks[] = [$c, $c + $length];
                }
            }
        }
        return $blocks;
    }

    private static function isAllDay(array $field): bool
    {
        foreach ($field['params'] as $p) {
            if (strtoupper(trim($p)) === 'VALUE=DATE') {
                return true;
            }
        }
        return (bool) preg_match('/^\d{8}$/', trim($field['value']));
    }

    /** ICS date-time to an epoch second. Floating times are read as UTC, which is the safe direction. */
    private static function parseDate(array $field): ?int
    {
        $value = trim($field['value']);
        $tzid = null;
        foreach ($field['params'] as $p) {
            if (stripos($p, 'TZID=') === 0) {
                $tzid = substr($p, 5);
            }
        }
        try {
            if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $m)) {
                return (new \DateTimeImmutable($m[1] . 'T' . $m[2] . 'Z'))->getTimestamp();
            }
            if (preg_match('/^(\d{8})T(\d{6})$/', $value, $m)) {
                $zone = new \DateTimeZone(self::safeZone($tzid));
                return (new \DateTimeImmutable($m[1] . 'T' . $m[2], $zone))->getTimestamp();
            }
            if (preg_match('/^(\d{8})$/', $value)) {
                $zone = new \DateTimeZone(self::safeZone($tzid));
                return (new \DateTimeImmutable($value . 'T000000', $zone))->getTimestamp();
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private static function safeZone(?string $tzid): string
    {
        $tzid = trim((string) $tzid);
        if ($tzid === '') {
            return 'UTC';
        }
        try {
            new \DateTimeZone($tzid);
            return $tzid;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private static function parseDuration(string $value): int
    {
        if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim($value), $m)) {
            return 3600;
        }
        $seconds = ((int) ($m[2] ?? 0)) * 604800 + ((int) ($m[3] ?? 0)) * 86400
            + ((int) ($m[4] ?? 0)) * 3600 + ((int) ($m[5] ?? 0)) * 60 + ((int) ($m[6] ?? 0));
        return $seconds > 0 ? $seconds : 3600;
    }

    private static function parseRrule(string $value): array
    {
        $out = [];
        foreach (explode(';', $value) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $out[strtoupper(trim($kv[0]))] = trim($kv[1]);
            }
        }
        return $out;
    }

    private static function esc(string $text): string
    {
        return str_replace(["\\", "\r\n", "\n", ';', ','], ['\\\\', '\\n', '\\n', '\\;', '\\,'], $text);
    }

    /** ICS lines must not exceed 75 octets; continuation lines start with a space. */
    private static function fold(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            while (strlen($line) > 73) {
                $out[] = substr($line, 0, 73);
                $line = ' ' . substr($line, 73);
            }
            $out[] = $line;
        }
        return implode("\r\n", $out);
    }

    private static function host(): string
    {
        $host = parse_url(base_url(), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'voiceagent';
    }
}
