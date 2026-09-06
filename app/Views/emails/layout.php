<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f4f5fa;font-family:Inter,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5fa;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:16px;border:1px solid #e6e8f0;">
<tr><td style="padding:28px 32px 8px;">
  <div style="display:inline-block;width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#5b5bd6,#8b5cf6);vertical-align:middle;"></div>
  <span style="display:inline-block;vertical-align:middle;margin-left:10px;font-weight:700;font-size:17px;"><?= e($app_name) ?></span>
</td></tr>
<tr><td style="padding:12px 32px 28px;font-size:15px;line-height:1.6;color:#334155;">
<?= $body ?>
</td></tr>
</table>
<p style="font-size:12px;color:#94a3b8;margin-top:18px;">Sent by <?= e($app_name) ?> &middot; <a href="<?= e(url('/')) ?>" style="color:#94a3b8;"><?= e(preg_replace('~^https?://~', '', url('/'))) ?></a></p>
</td></tr>
</table>
</body>
</html>
