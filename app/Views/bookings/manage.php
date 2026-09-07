<?php
/** @var array $appointment @var array $agent @var array $settings @var string $when @var array $answers @var array $questions */
$business = (string) ($agent['business_name'] ?: $agent['name']);
$cancelled = $appointment['status'] === 'cancelled';
$labels = [];
foreach ($questions as $q) {
    $labels[$q['key']] = $q['label'];
}
?>
<div style="max-width:520px;margin:0 auto;padding:40px 20px">
  <?php foreach (\App\Core\Session::flashMessages() as $flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : $flash['type']) ?>"><?= e($flash['message']) ?></div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card-body" style="padding:32px">
      <div class="text-center mb-6">
        <div class="empty-icon" style="width:56px;height:56px;margin:0 auto 14px;background:<?= $cancelled ? 'var(--surface-2)' : 'var(--primary-50)' ?>;color:<?= $cancelled ? 'var(--text-3)' : 'var(--primary)' ?>;border-radius:12px;display:grid;place-items:center">
          <?php if ($cancelled): ?>
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
          <?php else: ?>
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
          <?php endif; ?>
        </div>
        <h1 style="font-size:23px"><?= $cancelled ? 'Booking cancelled' : 'Your booking is confirmed' ?></h1>
        <p class="text-muted" style="margin-top:6px"><?= e($business) ?></p>
      </div>

      <dl class="kv" style="grid-template-columns:130px 1fr">
        <dt>When</dt><dd><?= e($when) ?></dd>
        <dt>Timezone</dt><dd><?= e($appointment['timezone']) ?></dd>
        <dt>Service</dt><dd><?= e($appointment['service']) ?> (<?= (int) $appointment['duration_minutes'] ?> min)</dd>
        <dt>Name</dt><dd><?= e($appointment['customer_name'] ?: '-') ?></dd>
        <?php if ($appointment['customer_email']): ?><dt>Email</dt><dd><?= e($appointment['customer_email']) ?></dd><?php endif; ?>
        <?php if ($appointment['customer_phone']): ?><dt>Phone</dt><dd><?= e($appointment['customer_phone']) ?></dd><?php endif; ?>
        <?php if ($settings['location'] !== ''): ?><dt>Location</dt><dd><?= e($settings['location']) ?></dd><?php endif; ?>
        <dt>Reference</dt><dd class="mono"><?= e($appointment['reference']) ?></dd>
        <?php foreach ($answers as $key => $value): ?>
          <dt><?= e($labels[$key] ?? ucfirst(str_replace('_', ' ', (string) $key))) ?></dt><dd><?= e((string) $value) ?></dd>
        <?php endforeach; ?>
      </dl>

      <?php if (!$cancelled && $settings['confirmation_message'] !== ''): ?>
        <div class="alert alert-info mt-6"><span><?= e($settings['confirmation_message']) ?></span></div>
      <?php endif; ?>

      <?php if (!$cancelled): ?>
        <div class="flex gap-2 mt-6 wrap">
          <a class="btn btn-primary" href="<?= e(url('/booking/' . $appointment['manage_token'] . '.ics')) ?>">Add to my calendar</a>
          <form method="post" action="<?= e(url('/booking/' . $appointment['manage_token'] . '/cancel')) ?>" data-confirm="Cancel this booking?"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Cancel booking</button></form>
        </div>
      <?php else: ?>
        <p class="text-muted text-sm mt-6 mb-0">This appointment is no longer booked. Get in touch with <?= e($business) ?> if you would like a new time.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($agent['website_url'])): ?>
    <div class="text-center mt-4"><a class="text-sm" href="<?= e($agent['website_url']) ?>">Back to <?= e($business) ?></a></div>
  <?php endif; ?>
</div>
