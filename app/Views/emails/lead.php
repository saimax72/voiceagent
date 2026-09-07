<h2 style="margin:0 0 12px;font-size:22px;color:#0f172a;">New lead from <?= e($agent_name) ?></h2>
<p>Your AI assistant just captured a new lead on your website.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border:1px solid #e6e8ee;border-radius:8px;margin:18px 0;">
  <?php foreach (['Name' => $lead['name'] ?? '', 'Email' => $lead['email'] ?? '', 'Phone' => $lead['phone'] ?? '', 'Message' => $lead['message'] ?? '', 'Page' => $lead['page_url'] ?? ''] as $label => $value): if ($value === '' || $value === null) continue; ?>
  <tr><td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;width:90px;"><?= e($label) ?></td><td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;"><?= nl2br(e($value)) ?></td></tr>
  <?php endforeach; ?>
</table>
<p style="margin:24px 0;"><a href="<?= e($link) ?>" style="background:#0052fc;color:#fff;text-decoration:none;padding:12px 22px;border-radius:5px;font-weight:700;display:inline-block;">View in dashboard</a></p>
