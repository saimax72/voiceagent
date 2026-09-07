<?php
/** @var array $appointment @var array $agent @var string $business @var string $when @var string $location @var string $message @var string $manageUrl @var string $icsUrl */
?>
<h2 style="margin:0 0 12px;font-size:22px;color:#141b25;">Your booking is confirmed</h2>
<p>Thanks <?= e($appointment['customer_name'] ?: 'there') ?>, your appointment with <strong><?= e($business) ?></strong> is booked.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border:1px solid #e6e8ee;border-radius:8px;margin:18px 0;">
  <?php
  $rows = ['When' => $when, 'Timezone' => $appointment['timezone'], 'Service' => $appointment['service'] . ' (' . (int) $appointment['duration_minutes'] . ' min)', 'Reference' => $appointment['reference']];
  if (trim((string) $location) !== '') {
      $rows['Location'] = $location;
  }
  foreach ($rows as $label => $value):
      if (trim((string) $value) === '') {
          continue;
      }
  ?>
  <tr><td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#667085;font-size:13px;width:100px;"><?= e($label) ?></td><td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;color:#141b25;"><?= e((string) $value) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php if (trim((string) $message) !== ''): ?>
  <p style="background:#eef3ff;border:1px solid #dbe5ff;border-radius:8px;padding:12px 14px;font-size:14px;color:#141b25;"><?= e($message) ?></p>
<?php endif; ?>
<p style="margin:24px 0;">
  <a href="<?= e($icsUrl) ?>" style="background:#0052fc;color:#fff;text-decoration:none;padding:12px 22px;border-radius:5px;font-weight:700;display:inline-block;">Add to calendar</a>
  <a href="<?= e($manageUrl) ?>" style="color:#0052fc;text-decoration:none;padding:12px 16px;font-weight:600;display:inline-block;">View or cancel</a>
</p>
<p style="font-size:13px;color:#667085;">Need to change something? Open <a href="<?= e($manageUrl) ?>" style="color:#0052fc;">your booking page</a> or reply to this email.</p>
