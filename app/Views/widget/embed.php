<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($agent['name']) ?></title>
<style>
  html, body { margin: 0; height: 100%; font-family: Inter, system-ui, sans-serif; background: #f1f5f9; }
  .center { position: absolute; inset: 0; display: grid; place-items: center; color: #64748b; font-size: 15px; text-align: center; padding: 20px; }
</style>
</head>
<body>
<div class="center"><div><strong style="color:#0f172a;font-size:20px;display:block;margin-bottom:8px"><?= e($agent['name']) ?></strong>Click the assistant button to start talking.</div></div>
<script src="<?= e(base_url()) ?>/widget.js?v=<?= APP_VERSION ?>" data-agent-id="<?= e($agent['public_id']) ?>" data-auto-open="1"></script>
</body>
</html>
