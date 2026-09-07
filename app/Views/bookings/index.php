<?php
/** @var array $appointments @var array $agents @var array $filters @var array $counts */
use App\Services\Booking\Availability;

$q = static function (array $extra) use ($filters): string {
    return url('/bookings', array_merge(['status' => $filters['status'], 'agent' => $filters['agent'] ?: null, 'range' => $filters['range']], $extra));
};
?>
<div class="page-header">
  <div><h1>Bookings</h1><p>Appointments your assistants made, and the ones you added yourself.</p></div>
  <div class="actions">
    <span class="badge badge-primary badge-lg"><?= format_number($counts['upcoming']) ?> upcoming</span>
    <span class="badge badge-neutral badge-lg"><?= format_number($counts['today']) ?> today</span>
  </div>
</div>

<div class="flex gap-3 items-center wrap mb-4">
  <div class="segmented">
    <a href="<?= e($q(['range' => 'upcoming'])) ?>" class="<?= $filters['range'] !== 'past' ? 'active' : '' ?>" style="padding:7px 14px;border-radius:4px;font-weight:700;font-size:13px;color:<?= $filters['range'] !== 'past' ? 'var(--ink)' : 'var(--text-3)' ?>;background:<?= $filters['range'] !== 'past' ? 'var(--surface)' : 'transparent' ?>">Upcoming</a>
    <a href="<?= e($q(['range' => 'past'])) ?>" class="<?= $filters['range'] === 'past' ? 'active' : '' ?>" style="padding:7px 14px;border-radius:4px;font-weight:700;font-size:13px;color:<?= $filters['range'] === 'past' ? 'var(--ink)' : 'var(--text-3)' ?>;background:<?= $filters['range'] === 'past' ? 'var(--surface)' : 'transparent' ?>">Past</a>
  </div>
  <form method="get" action="<?= e(url('/bookings')) ?>" class="flex gap-2 items-center">
    <input type="hidden" name="range" value="<?= e($filters['range']) ?>">
    <select class="form-select form-control-sm" name="agent" style="width:auto" onchange="this.form.submit()">
      <option value="">All agents</option>
      <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $filters['agent'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select form-control-sm" name="status" style="width:auto" onchange="this.form.submit()">
      <option value="">Any status</option>
      <option value="confirmed" <?= $filters['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
      <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
    </select>
  </form>
</div>

<div class="card">
  <?php if (!$appointments): ?>
    <div class="empty">
      <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></div>
      <h3>No <?= $filters['range'] === 'past' ? 'past' : 'upcoming' ?> bookings</h3>
      <p>Turn on booking for an agent and it will offer real free times and take appointments during a conversation.</p>
      <?php if ($agents): ?><a class="btn btn-primary" href="<?= e(url('/agents/' . $agents[0]['id'] . '/booking')) ?>">Set up booking</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>When</th><th>Service</th><th>Customer</th><th>Agent</th><th>Status</th><th>Reference</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($appointments as $a):
          $agentRow = ['timezone' => $a['agent_timezone'] ?: $a['timezone']];
          $ts = strtotime((string) $a['starts_at'] . ' UTC');
          $answers = json_field($a['answers'] ?? null) ?: [];
      ?>
        <tr>
          <td><div class="cell-primary"><?= e(Availability::label($agentRow, $ts)) ?></div><div class="muted"><?= (int) $a['duration_minutes'] ?> min &middot; <?= e($a['timezone']) ?></div></td>
          <td><?= e($a['service']) ?><?php if ($answers): ?><div class="muted" style="max-width:280px"><?php $bits = []; foreach ($answers as $k => $v) { $bits[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $v; } echo e(mb_substr(implode(' · ', $bits), 0, 140)); ?></div><?php endif; ?></td>
          <td>
            <div class="cell-primary"><?= e($a['customer_name'] ?: 'Unnamed') ?></div>
            <div class="muted"><?php if ($a['customer_email']): ?><a href="mailto:<?= e($a['customer_email']) ?>"><?= e($a['customer_email']) ?></a><?php endif; ?><?= $a['customer_email'] && $a['customer_phone'] ? ' &middot; ' : '' ?><?= e($a['customer_phone'] ?? '') ?></div>
          </td>
          <td class="muted"><?= e($a['agent_name']) ?></td>
          <td><?= $a['status'] === 'cancelled' ? '<span class="badge badge-neutral">Cancelled</span>' : '<span class="badge badge-success"><span class="dot"></span>Confirmed</span>' ?></td>
          <td class="mono"><?= e($a['reference']) ?></td>
          <td class="actions">
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/booking/' . $a['manage_token'])) ?>" target="_blank" rel="noopener">View</a>
            <?php if ($a['status'] !== 'cancelled'): ?>
              <form method="post" action="<?= e(url('/bookings/' . $a['id'] . '/cancel')) ?>" style="display:inline" data-confirm="Cancel this booking?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Cancel</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
