<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Widget preview</title>
<style>
  html, body { margin: 0; height: 100%; font-family: Inter, system-ui, sans-serif; }
  body { background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); color: #94a3b8; overflow: hidden; }
  .fake-site { padding: 28px 32px; opacity: .8; max-width: 720px; }
  .bar { height: 12px; border-radius: 6px; background: #e2e8f0; margin: 10px 0; }
  .bar.w60 { width: 60%; } .bar.w40 { width: 40%; } .bar.w80 { width: 80%; } .bar.w30 { width: 30%; }
  .fake-nav { display: flex; gap: 18px; align-items: center; margin-bottom: 36px; }
  .fake-logo { width: 34px; height: 34px; border-radius: 9px; background: #cbd5e1; }
  .hero { height: 22px; width: 55%; border-radius: 8px; background: #cbd5e1; margin-bottom: 14px; }
  .hint { position: absolute; left: 50%; top: 45%; transform: translate(-50%, -50%); text-align: center; color: #64748b; font-size: 14px; max-width: 320px; line-height: 1.6; }
  .hint strong { display: block; color: #0f172a; font-size: 16px; margin-bottom: 6px; }
  .cards { display: flex; gap: 14px; margin-top: 30px; }
  .cards div { flex: 1; height: 110px; border-radius: 14px; background: #fff; border: 1px solid #e2e8f0; }
</style>
</head>
<body>
<div class="fake-site" aria-hidden="true">
  <div class="fake-nav"><div class="fake-logo"></div><div class="bar w30" style="margin:0"></div><div class="bar w30" style="margin:0"></div></div>
  <div class="hero"></div>
  <div class="bar w80"></div><div class="bar w60"></div><div class="bar w40"></div>
  <div class="cards"><div></div><div></div><div></div></div>
</div>
<div class="hint"><strong><?= $mode === 'test' ? 'Test your assistant' : 'Live preview' ?></strong><?= $mode === 'test' ? 'Click the assistant button to start a real conversation. Test conversations are marked and do not count towards your usage.' : 'This is how the assistant will look on your website. Changes on the left update instantly.' ?></div>
<script src="<?= e(base_url()) ?>/widget.js?v=<?= APP_VERSION ?>" data-agent-id="<?= e($agent['public_id']) ?>" data-preview="1" data-preview-token="<?= e($previewToken) ?>" data-mode="<?= e($mode) ?>"></script>
</body>
</html>
