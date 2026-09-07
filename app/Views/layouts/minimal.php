<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Error') ?> - <?= e($app_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<style>
  body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .box { max-width: 520px; width: 100%; padding: 24px; text-align: center; }
</style>
</head>
<body>
<div class="box"><?= $content ?></div>
</body>
</html>
