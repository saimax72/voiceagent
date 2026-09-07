<?php
/** @var array $agent @var array $settings @var array $types @var array $typeOptions @var array $weekdays @var array $calendars @var string $feedUrl @var array $upcoming */
use App\Services\Booking\Availability;

$presets = [];
foreach ($types as $key => $type) {
    $presets[$key] = ['services' => $type['services'], 'questions' => $type['questions'], 'noun' => $type['noun'], 'description' => $type['description']];
}
$state = [
    'enabled' => (int) ($agent['booking_enabled'] ?? 0) === 1,
    'type' => (string) ($agent['booking_type'] ?? 'general'),
    'services' => $settings['services'],
    'questions' => $settings['questions'],
    'hours' => $settings['hours'],
    'presets' => $presets,
];
?>
<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'booking']) ?>
<style>
  .bk-row { display: grid; grid-template-columns: 1fr 110px 120px 36px; gap: 8px; align-items: center; margin-bottom: 8px; }
  .bk-qrow { display: grid; grid-template-columns: 1fr 130px 1fr 80px 36px; gap: 8px; align-items: center; margin-bottom: 8px; }
  .bk-hours { display: grid; grid-template-columns: 110px 1fr; gap: 10px; align-items: start; padding: 9px 0; border-bottom: 1px solid var(--border); }
  .bk-hours:last-child { border-bottom: 0; }
  .bk-window { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
  .bk-window input { width: 116px; }
  @media (max-width: 760px) { .bk-row, .bk-qrow, .bk-hours { grid-template-columns: 1fr; } }
</style>

<div class="grid grid-sidebar" x-data="bookingSetup(<?= e(json_encode($state)) ?>)">
  <div class="stack">
    <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/booking')) ?>" class="stack">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header"><div><h3>Appointment booking</h3><div class="sub">Let the assistant check real availability and book visitors in.</div></div></div>
        <div class="card-body">
          <div class="form-group"><label class="switch"><input type="checkbox" name="booking_enabled" value="1" x-model="enabled"><span class="track"></span><span class="switch-label">Take bookings in conversations</span></label>
            <div class="form-hint">The assistant gets three abilities: check availability, book, and cancel. It can only offer times that are genuinely free.</div></div>
          <div x-show="enabled" x-cloak>
            <div class="form-group mb-0">
              <label class="form-label">What kind of business is this?</label>
              <select class="form-select" name="booking_type" x-model="type" @change="applyPreset()">
                <?php foreach ($typeOptions as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
              </select>
              <div class="form-hint" x-text="presets[type] ? presets[type].description : ''"></div>
              <input type="hidden" name="reset_preset" :value="resetPreset ? 1 : 0">
            </div>
          </div>
        </div>
      </div>

      <div class="card" x-show="enabled" x-cloak>
        <div class="card-header"><div><h3>Services</h3><div class="sub">What visitors can book, and how long each takes.</div></div>
          <div class="actions"><button type="button" class="btn btn-secondary btn-sm" @click="services.push({name:'',minutes:30,price:''})">+ Add service</button></div></div>
        <div class="card-body">
          <div class="bk-row text-xs text-muted" style="margin-bottom:4px"><span>Name</span><span>Minutes</span><span>Price (optional)</span><span></span></div>
          <template x-for="(s, i) in services" :key="i">
            <div class="bk-row">
              <input class="form-control" :name="'services[name][' + i + ']'" x-model="s.name" placeholder="Swedish massage">
              <input class="form-control" type="number" min="5" max="600" step="5" :name="'services[minutes][' + i + ']'" x-model.number="s.minutes">
              <input class="form-control" :name="'services[price][' + i + ']'" x-model="s.price" placeholder="$80">
              <button type="button" class="btn btn-ghost btn-icon" @click="services.splice(i,1)" aria-label="Remove service">&times;</button>
            </div>
          </template>
          <div class="form-hint" x-show="!services.length">Add at least one service.</div>
        </div>
      </div>

      <div class="card" x-show="enabled" x-cloak>
        <div class="card-header"><div><h3>What to ask before booking</h3><div class="sub">Asked one at a time in conversation and saved with the appointment.</div></div>
          <div class="actions"><button type="button" class="btn btn-secondary btn-sm" @click="questions.push({key:'',label:'',type:'text',options:'',required:false})">+ Add question</button></div></div>
        <div class="card-body">
          <div class="bk-qrow text-xs text-muted" style="margin-bottom:4px"><span>Question</span><span>Answer type</span><span>Choices, comma separated</span><span>Required</span><span></span></div>
          <template x-for="(q, i) in questions" :key="i">
            <div class="bk-qrow">
              <input class="form-control" :name="'questions[label][' + i + ']'" x-model="q.label" placeholder="Is this your first visit?">
              <input type="hidden" :name="'questions[key][' + i + ']'" :value="q.key">
              <select class="form-select" :name="'questions[type][' + i + ']'" x-model="q.type">
                <option value="text">Short text</option>
                <option value="textarea">Long text</option>
                <option value="choice">Choice</option>
                <option value="yesno">Yes / no</option>
                <option value="phone">Phone</option>
                <option value="email">Email</option>
              </select>
              <input class="form-control" :name="'questions[options][' + i + ']'" x-model="q.options" :disabled="q.type !== 'choice'" placeholder="Light, Medium, Firm">
              <label class="checkbox" style="justify-content:center"><input type="checkbox" :name="'questions[required][' + i + ']'" value="1" x-model="q.required"><span class="text-xs">Yes</span></label>
              <button type="button" class="btn btn-ghost btn-icon" @click="questions.splice(i,1)" aria-label="Remove question">&times;</button>
            </div>
          </template>
          <div class="form-hint" x-show="!questions.length">No extra questions. The assistant still collects a name and contact details.</div>
        </div>
      </div>

      <div class="card" x-show="enabled" x-cloak>
        <div class="card-header"><div><h3>Opening hours</h3><div class="sub">In the agent timezone <strong><?= e($agent['timezone'] ?: 'UTC') ?></strong>, which you can change under Personality &amp; voice.</div></div></div>
        <div class="card-body">
          <?php foreach ($weekdays as $day => $label): ?>
            <div class="bk-hours">
              <div class="font-semibold"><?= e($label) ?></div>
              <div>
                <template x-for="(w, i) in hours[<?= $day ?>]" :key="i">
                  <div class="bk-window">
                    <input class="form-control form-control-sm" type="time" :name="'hours[<?= $day ?>][' + i + '][from]'" x-model="w.from">
                    <span class="text-muted text-sm">to</span>
                    <input class="form-control form-control-sm" type="time" :name="'hours[<?= $day ?>][' + i + '][to]'" x-model="w.to">
                    <button type="button" class="btn btn-ghost btn-icon" @click="hours[<?= $day ?>].splice(i,1)" aria-label="Remove hours">&times;</button>
                  </div>
                </template>
                <div class="flex gap-2 items-center">
                  <button type="button" class="btn btn-secondary btn-sm" @click="hours[<?= $day ?>].push({from:'09:00',to:'17:00'})">+ Add hours</button>
                  <span class="text-xs text-muted" x-show="!hours[<?= $day ?>].length">Closed</span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card" x-show="enabled" x-cloak>
        <div class="card-header"><h3>Booking rules</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Start times every</label>
              <select class="form-select" name="slot_interval">
                <?php foreach ([10, 15, 20, 30, 45, 60] as $m): ?><option value="<?= $m ?>" <?= (int) $settings['slot_interval'] === $m ? 'selected' : '' ?>><?= $m ?> minutes</option><?php endforeach; ?>
              </select><div class="form-hint">How the day is divided into offerable slots.</div></div>
            <div class="form-group"><label class="form-label">Gap after each appointment</label>
              <select class="form-select" name="buffer_minutes">
                <?php foreach ([0, 5, 10, 15, 20, 30, 45, 60] as $m): ?><option value="<?= $m ?>" <?= (int) $settings['buffer_minutes'] === $m ? 'selected' : '' ?>><?= $m === 0 ? 'No gap' : $m . ' minutes' ?></option><?php endforeach; ?>
              </select><div class="form-hint">Turnaround, cleaning or travel time.</div></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Earliest booking</label>
              <select class="form-select" name="min_notice_hours">
                <?php foreach ([0 => 'Any time from now', 1 => '1 hour ahead', 2 => '2 hours ahead', 4 => '4 hours ahead', 12 => '12 hours ahead', 24 => '1 day ahead', 48 => '2 days ahead'] as $h => $label): ?>
                  <option value="<?= $h ?>" <?= (int) $settings['min_notice_hours'] === $h ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="form-group"><label class="form-label">Book up to</label>
              <select class="form-select" name="max_advance_days">
                <?php foreach ([7, 14, 30, 60, 90, 180, 365] as $d): ?><option value="<?= $d ?>" <?= (int) $settings['max_advance_days'] === $d ? 'selected' : '' ?>><?= $d ?> days ahead</option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">How many appointments can overlap</label>
              <input class="form-control" type="number" name="capacity" min="1" max="50" value="<?= (int) $settings['capacity'] ?>">
              <div class="form-hint">The number of staff, rooms, chairs or tables you have.</div></div>
            <div class="form-group"><label class="form-label">Closed dates</label>
              <input class="form-control" name="closed_dates" value="<?= e(implode(', ', $settings['closed_dates'])) ?>" placeholder="2026-12-25, 2026-12-26">
              <div class="form-hint">Holidays, as YYYY-MM-DD separated by commas.</div></div>
          </div>
          <div class="form-group"><label class="form-label">Location shown in confirmations</label><input class="form-control" name="location" value="<?= e($settings['location']) ?>" placeholder="12 High Street, London"></div>
          <div class="form-group"><label class="form-label">Extra line in the confirmation email</label><textarea class="form-control" name="confirmation_message" rows="2" placeholder="Please arrive 10 minutes early."><?= e($settings['confirmation_message']) ?></textarea></div>
          <div class="form-group"><label class="switch"><input type="checkbox" name="require_email" value="1" <?= $settings['require_email'] ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Require an email address</span></label></div>
          <div class="form-group"><label class="switch"><input type="checkbox" name="require_phone" value="1" <?= $settings['require_phone'] ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Require a phone number</span></label></div>
          <div class="form-group mb-0"><label class="switch"><input type="checkbox" name="ask_notes" value="1" <?= $settings['ask_notes'] ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Let visitors add a note to the booking</span></label></div>
        </div>
      </div>

      <div class="card"><div class="card-footer flex gap-2 items-center">
        <button class="btn btn-primary" type="submit">Save booking settings</button>
        <a class="btn btn-ghost" href="<?= e(url('/bookings')) ?>">View all bookings</a>
      </div></div>
    </form>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-header"><h3>Your calendar</h3></div>
      <div class="card-body">
        <p class="text-sm text-muted">Subscribe to this address in Google, Outlook or Apple Calendar and every booking shows up there.</p>
        <div class="code-box"><pre style="white-space:pre-wrap;word-break:break-all;font-size:11px"><?= e($feedUrl) ?></pre><button class="btn btn-secondary btn-sm copy" type="button" data-copy="<?= e($feedUrl) ?>">Copy</button></div>
        <div class="form-hint mt-2">Keep it private. Anyone with this link can read your bookings.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div><h3>Check another calendar</h3><div class="sub">Times busy there are never offered.</div></div></div>
      <?php if ($calendars): ?>
        <div class="card-body" style="padding-bottom:0">
          <div class="source-list mb-4">
            <?php foreach ($calendars as $c): ?>
              <div class="source-item">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></div>
                <div class="info">
                  <div class="title"><?= e($c['name']) ?></div>
                  <div class="meta">
                    <?php if (!empty($c['last_error'])): ?><span class="text-danger"><?= e($c['last_error']) ?></span>
                    <?php else: ?><?= count(json_field($c['busy_json'] ?? null) ?: []) ?> busy blocks &middot; synced <?= $c['last_synced_at'] ? e(time_ago($c['last_synced_at'])) : 'never' ?><?php endif; ?>
                  </div>
                </div>
                <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/booking/calendars/' . $c['id'] . '/refresh')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Refresh</button></form>
                <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/booking/calendars/' . $c['id'] . '/delete')) ?>" data-confirm="Unlink this calendar?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Remove</button></form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/booking/calendars')) ?>" class="card-body">
        <?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Calendar name</label><input class="form-control" name="name" placeholder="Main calendar"></div>
        <div class="form-group"><label class="form-label">Secret calendar address (ICS)</label><input class="form-control" name="ics_url" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics"></div>
        <button class="btn btn-secondary btn-block" type="submit">Link calendar</button>
        <div class="form-hint mt-2">Google Calendar: Settings, pick the calendar, "Secret address in iCal format". Outlook: Settings, Calendar, Shared calendars, Publish. Apple Calendar: right click the calendar, Share, Public calendar.</div>
      </form>
    </div>

    <?php if ($upcoming): ?>
      <div class="card">
        <div class="card-header"><h3>Next bookings</h3></div>
        <div class="table-wrap"><table class="table table-compact"><tbody>
          <?php foreach (array_slice($upcoming, 0, 6) as $u): ?>
            <tr><td><div class="cell-primary"><?= e(Availability::label($agent, strtotime((string) $u['starts_at'] . ' UTC'))) ?></div><div class="muted"><?= e($u['service']) ?> &middot; <?= e($u['customer_name'] ?: 'Unnamed') ?></div></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php \App\Core\View::start('scripts'); ?>
<script>
function bookingSetup(initial) {
  return {
    enabled: initial.enabled,
    type: initial.type,
    resetPreset: false,
    presets: initial.presets || {},
    services: (initial.services || []).map(s => ({ name: s.name || '', minutes: s.minutes || 30, price: s.price || '' })),
    questions: (initial.questions || []).map(q => ({ key: q.key || '', label: q.label || '', type: q.type || 'text', options: (q.options || []).join(', '), required: !!q.required })),
    hours: (function (h) { const out = {}; for (let d = 1; d <= 7; d++) { out[d] = (h[d] || h[String(d)] || []).map(w => ({ from: w.from, to: w.to })); } return out; })(initial.hours || {}),
    applyPreset() {
      const p = this.presets[this.type];
      if (!p) { return; }
      this.resetPreset = false; // the fields below are posted, so the server keeps what is on screen
      this.services = p.services.map(s => ({ name: s.name, minutes: s.minutes, price: s.price || '' }));
      this.questions = p.questions.map(q => ({ key: q.key || '', label: q.label, type: q.type, options: (q.options || []).join(', '), required: !!q.required }));
    }
  };
}
</script>
<?php \App\Core\View::stop(); ?>
