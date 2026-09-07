<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;
use App\Services\Booking\Availability;
use App\Services\Booking\BookingSettings;
use App\Services\Booking\Bookings;
use App\Services\Booking\BookingTypes;
use App\Services\Booking\Ics;

/**
 * Appointments: the dashboard list, the per-agent booking setup, linked calendars,
 * the subscribable calendar feed and the public page where a visitor manages a booking.
 */
final class BookingController
{
    // ------------------------------------------------------------------ dashboard

    public function index(Request $request): Response
    {
        $tenantId = tenant_id();
        $db = DB::instance();
        $status = $request->string('status');
        $agentId = (int) $request->int('agent');
        $range = $request->string('range') ?: 'upcoming';

        $where = ['tenant_id = ?'];
        $params = [$tenantId];
        if (in_array($status, ['confirmed', 'cancelled'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($agentId > 0) {
            $where[] = 'agent_id = ?';
            $params[] = $agentId;
        }
        if ($range === 'past') {
            $where[] = 'starts_at < ?';
            $params[] = now();
            $order = 'starts_at DESC';
        } else {
            $where[] = 'starts_at >= ?';
            $params[] = gmdate('Y-m-d H:i:s', time() - 3600);
            $order = 'starts_at ASC';
        }
        $sql = 'SELECT a.*, ag.name AS agent_name, ag.timezone AS agent_timezone FROM appointments a '
            . 'INNER JOIN agents ag ON ag.id = a.agent_id WHERE ' . implode(' AND ', array_map(static fn($w) => 'a.' . $w, $where))
            . ' ORDER BY a.' . $order . ' LIMIT 200';
        $appointments = $db->fetchAll($sql, $params);

        return view('bookings/index', [
            'title' => 'Bookings',
            'subtitle' => 'Appointments made by your assistants.',
            'appointments' => $appointments,
            'agents' => Agents::forTenant($tenantId),
            'filters' => ['status' => $status, 'agent' => $agentId, 'range' => $range],
            'counts' => [
                'upcoming' => $db->count('appointments', "tenant_id = ? AND status = 'confirmed' AND starts_at >= ?", [$tenantId, now()]),
                'today' => $db->count('appointments', "tenant_id = ? AND status = 'confirmed' AND starts_at >= ? AND starts_at < ?", [$tenantId, gmdate('Y-m-d 00:00:00'), gmdate('Y-m-d 23:59:59')]),
            ],
        ], 'layouts/app');
    }

    public function cancel(Request $request, string $id): Response
    {
        $appointment = DB::instance()->fetch('SELECT * FROM appointments WHERE id = ? AND tenant_id = ?', [(int) $id, tenant_id()]);
        if (!$appointment) {
            abort(404, 'Booking not found.');
        }
        Bookings::cancel($appointment, 'the business');
        flash('success', 'Booking ' . $appointment['reference'] . ' was cancelled.');
        return redirect('/bookings');
    }

    // ------------------------------------------------------------------ per-agent setup

    public function edit(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        return view('bookings/settings', [
            'title' => 'Bookings - ' . $agent['name'],
            'agent' => $agent,
            'settings' => BookingSettings::forAgent($agent),
            'types' => BookingTypes::all(),
            'typeOptions' => BookingTypes::options(),
            'weekdays' => BookingSettings::WEEKDAYS,
            'calendars' => DB::instance()->fetchAll('SELECT * FROM calendar_links WHERE agent_id = ? ORDER BY id ASC', [(int) $agent['id']]),
            'feedUrl' => url('/calendar/' . $agent['public_id'] . '/' . self::feedToken($agent) . '.ics'),
            'upcoming' => Bookings::upcomingForAgent((int) $agent['id'], 30),
        ], 'layouts/app');
    }

    public function update(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $type = $request->string('booking_type');
        if (!isset(BookingTypes::TYPES[$type])) {
            $type = 'general';
        }
        $enabled = $request->boolean('booking_enabled');
        $current = BookingSettings::forAgent($agent);

        // Switching business type reseeds services and questions unless the owner edited them
        if ($type !== ($agent['booking_type'] ?? 'general') && $request->boolean('reset_preset')) {
            $preset = BookingTypes::get($type);
            $current['services'] = $preset['services'];
            $current['questions'] = $preset['questions'];
        } else {
            $current['services'] = BookingSettings::normaliseServices(self::rows($request->array('services')));
            $current['questions'] = BookingSettings::normaliseQuestions(self::questionRows($request->array('questions')));
        }

        $hours = [];
        $rawHours = $request->array('hours');
        for ($day = 1; $day <= 7; $day++) {
            $windows = [];
            foreach ((array) ($rawHours[$day] ?? []) as $w) {
                $from = trim((string) ($w['from'] ?? ''));
                $to = trim((string) ($w['to'] ?? ''));
                if ($from !== '' && $to !== '') {
                    $windows[] = ['from' => $from, 'to' => $to];
                }
            }
            $hours[$day] = $windows;
        }
        $current['hours'] = $hours;
        $current['slot_interval'] = (int) $request->int('slot_interval');
        $current['buffer_minutes'] = (int) $request->int('buffer_minutes');
        $current['min_notice_hours'] = (int) $request->int('min_notice_hours');
        $current['max_advance_days'] = (int) $request->int('max_advance_days');
        $current['capacity'] = (int) $request->int('capacity');
        $current['location'] = $request->string('location');
        $current['confirmation_message'] = $request->string('confirmation_message');
        $current['ask_notes'] = $request->boolean('ask_notes');
        $current['require_email'] = $request->boolean('require_email');
        $current['require_phone'] = $request->boolean('require_phone');
        $closed = preg_split('/[\s,]+/', $request->string('closed_dates')) ?: [];
        $current['closed_dates'] = array_values(array_filter($closed, static fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)));
        $current['feed_token'] = self::feedToken($agent);

        Agents::update((int) $agent['id'], [
            'booking_enabled' => $enabled ? 1 : 0,
            'booking_type' => $type,
            'booking_settings' => json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        flash('success', 'Booking settings saved.');
        return redirect('/agents/' . $agent['id'] . '/booking');
    }

    // ------------------------------------------------------------------ linked calendars

    public function addCalendar(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $url = trim($request->string('ics_url'));
        $name = mb_substr(trim($request->string('name')) ?: 'Calendar', 0, 120);
        if ($url === '') {
            flash('error', 'Paste the secret calendar address first.');
            return redirect('/agents/' . $agent['id'] . '/booking');
        }
        if (DB::instance()->count('calendar_links', 'agent_id = ?', [(int) $agent['id']]) >= 5) {
            flash('error', 'You can link up to 5 calendars per agent.');
            return redirect('/agents/' . $agent['id'] . '/booking');
        }
        try {
            $blocks = Ics::fetchBusy($url);
        } catch (\Throwable $e) {
            flash('error', 'That calendar could not be read: ' . $e->getMessage());
            return redirect('/agents/' . $agent['id'] . '/booking');
        }
        DB::instance()->insert('calendar_links', [
            'tenant_id' => (int) $agent['tenant_id'],
            'agent_id' => (int) $agent['id'],
            'name' => $name,
            'ics_url' => $url,
            'busy_json' => json_encode($blocks),
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        flash('success', 'Calendar linked. ' . count($blocks) . ' busy blocks found, and those times will no longer be offered.');
        return redirect('/agents/' . $agent['id'] . '/booking');
    }

    public function refreshCalendar(Request $request, string $id, string $linkId): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $link = DB::instance()->fetch('SELECT * FROM calendar_links WHERE id = ? AND agent_id = ?', [(int) $linkId, (int) $agent['id']]);
        if (!$link) {
            abort(404, 'Calendar not found.');
        }
        $blocks = Ics::busyForLink($link, true);
        $fresh = DB::instance()->fetch('SELECT last_error FROM calendar_links WHERE id = ?', [(int) $link['id']]);
        if (!empty($fresh['last_error'])) {
            flash('error', 'Calendar sync failed: ' . $fresh['last_error']);
        } else {
            flash('success', 'Calendar refreshed. ' . count($blocks) . ' busy blocks found.');
        }
        return redirect('/agents/' . $agent['id'] . '/booking');
    }

    public function deleteCalendar(Request $request, string $id, string $linkId): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        DB::instance()->query('DELETE FROM calendar_links WHERE id = ? AND agent_id = ?', [(int) $linkId, (int) $agent['id']]);
        flash('success', 'Calendar unlinked.');
        return redirect('/agents/' . $agent['id'] . '/booking');
    }

    // ------------------------------------------------------------------ public endpoints

    /** Calendar the business subscribes to in Google, Outlook or Apple Calendar. */
    public function feed(Request $request, string $publicId, string $token): Response
    {
        $agent = Agents::findByPublicId($publicId);
        if (!$agent) {
            abort(404);
        }
        $expected = self::feedToken($agent);
        if (!hash_equals($expected, $token)) {
            abort(404);
        }
        $ics = Ics::feedForAgent($agent, Bookings::upcomingForAgent((int) $agent['id']));
        return (new Response($ics, 200))->withHeaders([
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="bookings.ics"',
            'Cache-Control' => 'no-cache, max-age=0',
        ]);
    }

    /** The page a visitor lands on from the confirmation email. */
    public function manage(Request $request, string $token): Response
    {
        $appointment = Bookings::findByToken($token);
        if (!$appointment) {
            abort(404, 'This booking link is not valid.');
        }
        $agent = DB::instance()->fetch('SELECT * FROM agents WHERE id = ?', [(int) $appointment['agent_id']]);
        if (!$agent) {
            abort(404);
        }
        $settings = BookingSettings::forAgent($agent);
        return view('bookings/manage', [
            'title' => 'Your booking',
            'appointment' => $appointment,
            'agent' => $agent,
            'settings' => $settings,
            'when' => Availability::label($agent, strtotime((string) $appointment['starts_at'] . ' UTC')),
            'answers' => json_field($appointment['answers'] ?? null) ?: [],
            'questions' => $settings['questions'],
        ], 'layouts/minimal');
    }

    public function cancelPublic(Request $request, string $token): Response
    {
        $appointment = Bookings::findByToken($token);
        if (!$appointment) {
            abort(404, 'This booking link is not valid.');
        }
        Bookings::cancel($appointment, 'the visitor');
        flash('success', 'Your booking has been cancelled.');
        return redirect('/booking/' . $token);
    }

    /** The single appointment as a calendar file the visitor can open. */
    public function appointmentIcs(Request $request, string $token): Response
    {
        $appointment = Bookings::findByToken($token);
        if (!$appointment) {
            abort(404);
        }
        $agent = DB::instance()->fetch('SELECT * FROM agents WHERE id = ?', [(int) $appointment['agent_id']]);
        if (!$agent) {
            abort(404);
        }
        return (new Response(Ics::forAppointment($appointment, $agent), 200))->withHeaders([
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="booking-' . $appointment['reference'] . '.ics"',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** Stable per-agent token for the calendar feed, derived from the agent so it never needs storing. */
    private static function feedToken(array $agent): string
    {
        $secret = (string) \App\Core\App::config('app.key', '');
        return substr(hash_hmac('sha256', 'calendar-feed:' . $agent['public_id'], $secret), 0, 32);
    }

    /** Turn parallel form arrays (name[], minutes[]) into a list of rows. */
    private static function rows(array $input): array
    {
        $names = (array) ($input['name'] ?? []);
        $minutes = (array) ($input['minutes'] ?? []);
        $prices = (array) ($input['price'] ?? []);
        $out = [];
        foreach ($names as $i => $name) {
            $out[] = ['name' => (string) $name, 'minutes' => (int) ($minutes[$i] ?? 30), 'price' => (string) ($prices[$i] ?? '')];
        }
        return $out;
    }

    private static function questionRows(array $input): array
    {
        $labels = (array) ($input['label'] ?? []);
        $keys = (array) ($input['key'] ?? []);
        $types = (array) ($input['type'] ?? []);
        $options = (array) ($input['options'] ?? []);
        $required = (array) ($input['required'] ?? []);
        $out = [];
        foreach ($labels as $i => $label) {
            $out[] = [
                'key' => (string) ($keys[$i] ?? ''),
                'label' => (string) $label,
                'type' => (string) ($types[$i] ?? 'text'),
                'options' => array_filter(array_map('trim', explode(',', (string) ($options[$i] ?? '')))),
                'required' => !empty($required[$i]) && $required[$i] !== '0',
            ];
        }
        return $out;
    }
}
