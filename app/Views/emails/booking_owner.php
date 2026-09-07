<?php
/** @var array $appointment @var array $agent @var string $when @var array $answers @var string $link @var string $icsUrl */
?>
<h2 style="margin:0 0 12px;font-size:22px;color:#141b25;">New booking from <?= e($agent['name']) ?></h2>
<p>Your assistant just booked an appointment during a conversation.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border:1px solid #e6e8ee;border-radius:8px;margin:18px 0;">
  <?php
  $rows = [
      'When' => $when . ' (' . $appointment['timezone'] . ')',
      'Service' => $appointment['service'] . ' (' . (int) $appointment['duration_minutes'] . ' min)',
      'Name' => $appointment['customer_name'] ?? '',
      'Email' => $appointment['customer_email'] ?? '',
      'Phone' => $appointment['customer_phone'] ?? '',
      'Reference' => $appointment['reference'],
      'Notes' => $appointment['notes'] ?? '',
  ];
  foreach ($answers as $key => $value) {
      $rows[ucfirst(str_replace('_', ' ', (string) $key))] = (string) $value;
  }
  foreach ($rows as $label => $value):
      if (trim((string) $value) === '') {
          continue;
      }
  ?>
  <tr><td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#667085;font-size:13px;width:110px;"><?= e($label) ?></td><td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;color:#141b25;"><?= e((string) $value) ?></td></tr>
  <?php endforeach; ?>
</table>
<p style="margin:24px 0;">
  <a href="<?= e($link) ?>" style="background:#0052fc;color:#fff;text-decoration:none;padding:12px 22px;border-radius:5px;font-weight:700;display:inline-block;">Open bookings</a>
  <a href="<?= e($icsUrl) ?>" style="color:#0052fc;text-decoration:none;padding:12px 16px;font-weight:600;display:inline-block;">Add to calendar</a>
</p>
