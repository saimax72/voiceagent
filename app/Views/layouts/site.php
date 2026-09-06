<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? $app_name) ?></title>
<meta name="description" content="Add an AI voice and chat assistant to your website in minutes. It learns your website and documents and answers visitors by voice or text.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="site">
<header class="site-header">
  <div class="container flex items-center gap-4">
    <a href="<?= e(url('/')) ?>" class="site-logo"><span class="brand-mark"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3v18M7 8v8M17 8v8M3 11v2M21 11v2"/></svg></span><?= e($app_name) ?></a>
    <nav class="site-nav ml-auto">
      <a href="<?= e(url('/#features')) ?>">Features</a>
      <a href="<?= e(url('/#how')) ?>">How it works</a>
      <a href="<?= e(url('/pricing')) ?>">Pricing</a>
      <?php if (auth()->check()): ?><a class="btn btn-primary btn-sm" href="<?= e(url('/dashboard')) ?>">Dashboard</a><?php else: ?><a href="<?= e(url('/login')) ?>">Sign in</a><a class="btn btn-primary btn-sm" href="<?= e(url('/register')) ?>">Start free</a><?php endif; ?>
    </nav>
  </div>
</header>
<?= $content ?>
<footer class="site-footer">
  <div class="container flex items-center gap-4 wrap">
    <span>&copy; <?= date('Y') ?> <?= e($app_name) ?>. AI voice &amp; chat agents for websites.</span>
    <span class="ml-auto flex gap-4"><a href="<?= e(url('/pricing')) ?>">Pricing</a><a href="<?= e(url('/bot')) ?>">Our crawler</a><a href="<?= e(url('/login')) ?>">Sign in</a></span>
  </div>
</footer>
<?php if (!empty($demoAgent)): ?><script src="<?= e(base_url()) ?>/widget.js?v=<?= APP_VERSION ?>" data-agent-id="<?= e($demoAgent) ?>"></script><?php endif; ?>
</body>
</html>
