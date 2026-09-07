<?php
/**
 * Local end-to-end check of the booking engine: settings, availability, booking, double-booking
 * protection, calendar busy blocks, ICS output and cancellation. Not part of the deployed app.
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\DB;
use App\Services\Agents;
use App\Services\Booking\Availability;
use App\Services\Booking\BookingSettings;
use App\Services\Booking\Bookings;
use App\Services\Booking\BookingTypes;
use App\Services\Booking\Ics;
use App\Services\AI\PromptBuilder;


$fail = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$fail): void {
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  -> ' . $detail : '') . PHP_EOL;
};

$db = DB::instance();
$agent = $db->fetch('SELECT * FROM agents ORDER BY id ASC LIMIT 1');
if (!$agent) {
    exit("No agent to test with.\n");
}
echo "Agent: {$agent['name']} (tz " . ($agent['timezone'] ?: 'UTC') . ")\n\n";

// --- configure booking as a spa, open every day 09:00-17:00 -------------------
echo "Setting up booking\n";
$settings = BookingSettings::defaults('spa');
$settings['min_notice_hours'] = 0;
$settings['capacity'] = 1;
$settings['slot_interval'] = 30;
for ($d = 1; $d <= 7; $d++) {
    $settings['hours'][$d] = [['from' => '09:00', 'to' => '17:00']];
}
Agents::update((int) $agent['id'], [
    'booking_enabled' => 1,
    'booking_type' => 'spa',
    'booking_settings' => json_encode($settings),
]);
$agent = $db->fetch('SELECT * FROM agents WHERE id = ?', [(int) $agent['id']]);
$settings = BookingSettings::forAgent($agent);
$check('settings load with spa preset', count($settings['services']) >= 5 && count($settings['questions']) >= 3, count($settings['services']) . ' services, ' . count($settings['questions']) . ' questions');
$check('booking type noun', BookingTypes::noun('spa') === 'treatment');

// --- clean slate -------------------------------------------------------------
$db->query('DELETE FROM appointments WHERE agent_id = ?', [(int) $agent['id']]);
$db->query('DELETE FROM calendar_links WHERE agent_id = ?', [(int) $agent['id']]);

// --- availability ------------------------------------------------------------
echo "\nAvailability\n";
$service = $settings['services'][0];
$days = Availability::nextAvailable($agent, $settings, (int) $service['minutes']);
$check('free days found', count($days) > 0, count($days) . ' days');
if (!$days) {
    exit("\nCannot continue without availability.\n");
}
$first = $days[0];
echo "  first day: {$first['label']} ({$first['date']}) -> " . implode(', ', array_column($first['slots'], 'time')) . "\n";
$slot = $first['slots'][1] ?? $first['slots'][0];
$startStr = $first['date'] . ' ' . $slot['time'];

// --- book --------------------------------------------------------------------
echo "\nBooking\n";
$result = Bookings::create($agent, null, [
    'service' => $service['name'],
    'start' => $startStr,
    'name' => 'Test Visitor',
    'email' => 'visitor@example.com',
    'answers' => ['first_visit' => 'Yes', 'pressure' => 'Medium'],
], 'chat');
$check('booking created', $result['ok'], $result['error']);
$appointment = $result['appointment'] ?? null;
if (!$appointment) {
    exit("\nCannot continue without an appointment.\n");
}
echo "  reference {$appointment['reference']} at {$appointment['starts_at']} UTC\n";
$check('answers stored', str_contains((string) $appointment['answers'], 'first_visit'));
$check('duration from service', (int) $appointment['duration_minutes'] === (int) $service['minutes']);

// --- the same slot must now be gone -----------------------------------------
echo "\nDouble booking protection\n";
$again = Bookings::create($agent, null, [
    'service' => $service['name'], 'start' => $startStr, 'name' => 'Second Visitor', 'email' => 'second@example.com',
    'answers' => ['first_visit' => 'No'],
], 'chat');
$check('same slot refused', !$again['ok'], $again['ok'] ? 'it was accepted' : substr($again['error'], 0, 70));
$slotsNow = Availability::slotsForDay($agent, $settings, $first['date'], (int) $service['minutes']);
$times = array_column($slotsNow, 'time');
$check('slot removed from availability', !in_array($slot['time'], $times, true));

// --- required answers --------------------------------------------------------
echo "\nValidation\n";
$missing = Bookings::create($agent, null, [
    'service' => $service['name'], 'start' => $first['date'] . ' 15:00', 'name' => 'No Email',
], 'chat');
$check('missing email refused', !$missing['ok'], substr($missing['error'], 0, 60));
$badService = Bookings::create($agent, null, [
    'service' => 'Helicopter ride', 'start' => $first['date'] . ' 15:00', 'name' => 'X', 'email' => 'x@example.com',
], 'chat');
$check('unknown service refused', !$badService['ok'], substr($badService['error'], 0, 60));

// --- linked calendar busy blocks --------------------------------------------
echo "\nLinked calendar\n";
$tz = Availability::zone($agent);
$blockStart = new DateTimeImmutable($first['date'] . ' 11:00', $tz);
$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:busy1\r\nDTSTART:" . $blockStart->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
    . "\r\nDTEND:" . $blockStart->modify('+2 hours')->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z')
    . "\r\nSUMMARY:Staff meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$parsed = Ics::parseBusy($ics, time() - 86400, time() + 86400 * 30);
$check('ICS parsed', count($parsed) === 1, count($parsed) . ' blocks');
$db->insert('calendar_links', [
    'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'], 'name' => 'Test calendar',
    'ics_url' => 'https://example.com/test.ics', 'busy_json' => json_encode($parsed),
    'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$slotsWithBusy = array_column(Availability::slotsForDay($agent, $settings, $first['date'], (int) $service['minutes']), 'time');
$check('busy block hides 11:00', !in_array('11:00', $slotsWithBusy, true), implode(',', array_slice($slotsWithBusy, 0, 8)));
// 09:00-12:30 legitimately overlap either the existing 60-minute booking or the busy block,
// so the first genuinely free slot is after the block ends at 13:00.
$check('times after the block still free', in_array('13:00', $slotsWithBusy, true) && in_array('14:00', $slotsWithBusy, true));
$check('no slot overlaps the busy block', !array_intersect(['11:00', '11:30', '12:00', '12:30'], $slotsWithBusy));

// --- recurrence --------------------------------------------------------------
$weekly = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:r1\r\nDTSTART:" . gmdate('Ymd\THis\Z', time() + 3600)
    . "\r\nDTEND:" . gmdate('Ymd\THis\Z', time() + 7200) . "\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$rec = Ics::parseBusy($weekly, time() - 86400, time() + 86400 * 40);
$check('weekly recurrence expands', count($rec) === 4, count($rec) . ' occurrences');

// --- ICS output --------------------------------------------------------------
echo "\nCalendar output\n";
$single = Ics::forAppointment($appointment, $agent);
$check('appointment ICS well formed', str_contains($single, 'BEGIN:VEVENT') && str_contains($single, 'UID:' . $appointment['reference']));
$feed = Ics::feedForAgent($agent, Bookings::upcomingForAgent((int) $agent['id']));
$check('agent feed contains the booking', substr_count($feed, 'BEGIN:VEVENT') === 1);
$check('feed lines folded to 75 octets', max(array_map('strlen', explode("\r\n", $feed))) <= 75);

// --- tools exposed to the model ---------------------------------------------
echo "\nAgent tools\n";
$tools = array_column(PromptBuilder::tools($agent), 'name');
$check('booking tools registered', in_array('check_availability', $tools, true) && in_array('book_appointment', $tools, true) && in_array('cancel_appointment', $tools, true), implode(', ', $tools));
$prompt = PromptBuilder::system($agent, [], ['modality' => 'text']);
$check('prompt mentions the services', str_contains($prompt, $service['name']));
$check('prompt mentions opening hours', str_contains($prompt, '09:00-17:00'));

// --- cancel ------------------------------------------------------------------
echo "\nCancellation\n";
Bookings::cancel($appointment, 'the visitor');
$after = $db->fetch('SELECT * FROM appointments WHERE id = ?', [(int) $appointment['id']]);
$check('status cancelled', $after['status'] === 'cancelled');
$slotsAfter = array_column(Availability::slotsForDay($agent, $settings, $first['date'], (int) $service['minutes']), 'time');
$check('slot released again', in_array($slot['time'], $slotsAfter, true));
$byRef = Bookings::findByReference($appointment['reference'], (int) $agent['id']);
$check('lookup by reference', $byRef !== null && $byRef['id'] === $appointment['id']);
$byToken = Bookings::findByToken((string) $appointment['manage_token']);
$check('lookup by manage token', $byToken !== null);

// --- cleanup -----------------------------------------------------------------
$db->query('DELETE FROM calendar_links WHERE agent_id = ?', [(int) $agent['id']]);

echo "\n" . ($fail === 0 ? "All checks passed.\n" : "{$fail} check(s) failed.\n");
exit($fail === 0 ? 0 : 1);
