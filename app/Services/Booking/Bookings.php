<?php
declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Mailer;
use App\Services\Settings;
use App\Services\Usage;

/**
 * Creating, listing and cancelling appointments.
 */
final class Bookings
{
    /**
     * Book a slot. Returns ['ok' => bool, 'error' => string, 'appointment' => array|null].
     * Availability is re-checked here so two visitors cannot take the same slot.
     */
    public static function create(array $agent, ?array $conversation, array $data, string $source = 'chat'): array
    {
        $settings = BookingSettings::forAgent($agent);
        if ((int) ($agent['booking_enabled'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Booking is not enabled for this assistant.', 'appointment' => null];
        }
        $service = BookingSettings::findService($settings, (string) ($data['service'] ?? ''));
        if (!$service) {
            $names = implode(', ', array_column($settings['services'], 'name'));
            return ['ok' => false, 'error' => 'Unknown service. Offer one of these: ' . $names, 'appointment' => null];
        }
        $startTs = Availability::parseWhen($agent, (string) ($data['start'] ?? ''));
        if ($startTs === null) {
            return ['ok' => false, 'error' => 'The start time could not be understood. Use the format YYYY-MM-DD HH:MM.', 'appointment' => null];
        }
        $duration = (int) $service['minutes'];
        if (!Availability::isSlotFree($agent, $settings, $startTs, $duration)) {
            $alternatives = Availability::nextAvailable($agent, $settings, $duration, (new \DateTimeImmutable('@' . $startTs))->setTimezone(Availability::zone($agent))->format('Y-m-d'), 2, 5);
            $hint = '';
            foreach ($alternatives as $day) {
                $hint .= ' ' . $day['label'] . ': ' . implode(', ', array_column($day['slots'], 'time')) . '.';
            }
            return ['ok' => false, 'error' => 'That time is not available.' . ($hint !== '' ? ' Offer these instead:' . $hint : ' Ask the visitor for another day.'), 'appointment' => null];
        }

        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 160);
        $email = mb_substr(strtolower(trim((string) ($data['email'] ?? ''))), 0, 190);
        $phone = mb_substr(trim((string) ($data['phone'] ?? '')), 0, 60);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        if ($name === '') {
            return ['ok' => false, 'error' => 'A name is required. Ask the visitor for their name.', 'appointment' => null];
        }
        if ($settings['require_email'] && $email === '') {
            return ['ok' => false, 'error' => 'A valid email address is required to confirm the booking. Ask the visitor for it.', 'appointment' => null];
        }
        if ($settings['require_phone'] && $phone === '') {
            return ['ok' => false, 'error' => 'A phone number is required. Ask the visitor for it.', 'appointment' => null];
        }
        $answers = [];
        $rawAnswers = $data['answers'] ?? [];
        if (is_string($rawAnswers)) {
            $decoded = json_decode($rawAnswers, true);
            $rawAnswers = is_array($decoded) ? $decoded : [];
        }
        foreach ($settings['questions'] as $q) {
            $value = $rawAnswers[$q['key']] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $answers[$q['key']] = mb_substr(trim((string) $value), 0, 500);
            } elseif ($q['required']) {
                return ['ok' => false, 'error' => 'Still missing an answer for: ' . $q['label'] . '. Ask the visitor before booking.', 'appointment' => null];
            }
        }
        $notes = mb_substr(trim((string) ($data['notes'] ?? '')), 0, 1000);

        $db = DB::instance();
        $now = now();
        $reference = self::reference();
        $id = $db->insert('appointments', [
            'tenant_id' => (int) $agent['tenant_id'],
            'agent_id' => (int) $agent['id'],
            'conversation_id' => $conversation ? (int) $conversation['id'] : null,
            'reference' => $reference,
            'service' => $service['name'],
            'duration_minutes' => $duration,
            'starts_at' => gmdate('Y-m-d H:i:s', $startTs),
            'ends_at' => gmdate('Y-m-d H:i:s', $startTs + $duration * 60),
            'timezone' => (string) ($agent['timezone'] ?: 'UTC'),
            'customer_name' => $name,
            'customer_email' => $email ?: null,
            'customer_phone' => $phone ?: null,
            'answers' => $answers ? json_encode($answers, JSON_UNESCAPED_UNICODE) : null,
            'notes' => $notes ?: null,
            'status' => 'confirmed',
            'source' => $source,
            'manage_token' => bin2hex(random_bytes(24)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $appointment = $db->fetch('SELECT * FROM appointments WHERE id = ?', [$id]) ?: [];
        if ($conversation) {
            $db->update('conversations', ['has_lead' => 1, 'updated_at' => $now], 'id = :id', ['id' => (int) $conversation['id']]);
        }
        if (empty($conversation['is_test'])) {
            Usage::recordDaily((int) $agent['tenant_id'], (int) $agent['id'], ['bookings' => 1]);
        }
        self::notify($agent, $appointment, $settings);
        return ['ok' => true, 'error' => '', 'appointment' => $appointment];
    }

    public static function cancel(array $appointment, string $by = 'customer'): bool
    {
        if (($appointment['status'] ?? '') === 'cancelled') {
            return true;
        }
        DB::instance()->update('appointments', [
            'status' => 'cancelled',
            'notes' => trim((string) ($appointment['notes'] ?? '') . "\nCancelled by " . $by . ' on ' . now()),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $appointment['id']]);
        return true;
    }

    public static function findByReference(string $reference, ?int $agentId = null): ?array
    {
        $sql = 'SELECT * FROM appointments WHERE reference = ?';
        $params = [strtoupper(trim($reference))];
        if ($agentId !== null) {
            $sql .= ' AND agent_id = ?';
            $params[] = $agentId;
        }
        return DB::instance()->fetch($sql . ' LIMIT 1', $params);
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return DB::instance()->fetch('SELECT * FROM appointments WHERE manage_token = ? LIMIT 1', [$token]);
    }

    /** Upcoming appointments for an agent, used by the calendar feed. */
    public static function upcomingForAgent(int $agentId, int $days = 180): array
    {
        return DB::instance()->fetchAll(
            "SELECT * FROM appointments WHERE agent_id = ? AND status <> 'cancelled' AND starts_at >= ? ORDER BY starts_at ASC LIMIT 500",
            [$agentId, gmdate('Y-m-d H:i:s', time() - 86400 * 2)]
        );
    }

    /** A short, unambiguous reference (no easily confused characters). */
    private static function reference(): string
    {
        $alphabet = 'ACDEFGHJKLMNPQRTUVWXY34679';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!DB::instance()->fetch('SELECT id FROM appointments WHERE reference = ? LIMIT 1', [$code])) {
                return $code;
            }
        }
        return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    /** Email the business, and the visitor when an address was given. */
    private static function notify(array $agent, array $appointment, array $settings): void
    {
        if (!$appointment) {
            return;
        }
        try {
            $when = Availability::label($agent, strtotime((string) $appointment['starts_at'] . ' UTC'));
            $business = (string) ($agent['business_name'] ?: $agent['name']);
            $manageUrl = url('/booking/' . $appointment['manage_token']);
            $icsUrl = url('/booking/' . $appointment['manage_token'] . '.ics');

            $to = trim((string) ($agent['lead_notify_email'] ?? ''));
            if ($to === '') {
                $owner = DB::instance()->fetch('SELECT email FROM users WHERE tenant_id = ? ORDER BY id ASC LIMIT 1', [(int) $agent['tenant_id']]);
                $to = (string) ($owner['email'] ?? '');
            }
            if ($to !== '' && Settings::bool('lead_notifications')) {
                Mailer::send($to, 'New booking: ' . $appointment['service'] . ' - ' . $when, Mailer::render('booking_owner', [
                    'appointment' => $appointment,
                    'agent' => $agent,
                    'when' => $when,
                    'answers' => json_field($appointment['answers'] ?? null) ?: [],
                    'link' => url('/bookings'),
                    'icsUrl' => $icsUrl,
                ]));
            }
            if (!empty($appointment['customer_email'])) {
                Mailer::send((string) $appointment['customer_email'], 'Your booking with ' . $business . ' - ' . $when, Mailer::render('booking_customer', [
                    'appointment' => $appointment,
                    'agent' => $agent,
                    'business' => $business,
                    'when' => $when,
                    'location' => $settings['location'],
                    'message' => $settings['confirmation_message'],
                    'manageUrl' => $manageUrl,
                    'icsUrl' => $icsUrl,
                ]));
            }
        } catch (\Throwable $e) {
            Logger::exception($e);
        }
    }
}
