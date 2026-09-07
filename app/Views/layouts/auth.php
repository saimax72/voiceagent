<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Sign in') ?> - <?= e($app_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<style>
  body { background: #e8ecf4 radial-gradient(1200px 500px at 50% -180px, #f8fafd 0%, rgba(248,250,253,0) 70%) no-repeat; }
  .auth-shell { max-width: 1080px; margin: 0 auto; min-height: 100vh; background: var(--surface); box-shadow: 0 0 0 1px rgba(20,27,37,.06), 0 30px 90px rgba(20,27,37,.10); display: flex; flex-direction: column; }
  .auth-top { height: 72px; flex-shrink: 0; display: flex; align-items: center; gap: 16px; padding: 0 32px; border-bottom: 1px solid var(--border); }
  .auth-top .brand-logo img { height: 32px; width: auto; display: block; }
  .auth-top .home-link { margin-left: auto; display: inline-flex; align-items: center; gap: 7px; font-size: 14px; font-weight: 600; color: var(--text-2); }
  .auth-top .home-link:hover { color: var(--ink); }
  .auth-top .home-link svg { width: 16px; height: 16px; }
  .auth-main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 44px 24px 56px; }
  .auth-card { width: 100%; max-width: 420px; }
  .auth-card h1 { font-size: 27px; margin-bottom: 6px; letter-spacing: -0.03em; }
  .auth-card .lead { color: var(--text-3); margin-bottom: 26px; }
  .auth-points { list-style: none; padding: 0; margin: 30px 0 0; display: flex; flex-direction: column; gap: 9px; border-top: 1px solid var(--border); padding-top: 22px; }
  .auth-points li { display: flex; gap: 10px; align-items: center; font-size: 13.5px; color: var(--text-2); }
  .auth-points li span { width: 20px; height: 20px; border-radius: 5px; background: var(--primary-50); color: var(--primary); display: grid; place-items: center; font-size: 11px; font-weight: 800; flex-shrink: 0; }
  .auth-footer { margin-top: 22px; text-align: center; color: var(--text-3); font-size: 14px; }
  .auth-bottom { flex-shrink: 0; border-top: 1px solid var(--border); padding: 18px 32px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; font-size: 13px; color: var(--text-3); }
  .auth-bottom a { color: var(--text-3); }
  .auth-bottom a:hover { color: var(--ink); }
  .auth-bottom .links { margin-left: auto; display: flex; gap: 18px; }
  @media (max-width: 640px) {
    .auth-top { padding: 0 20px; height: 64px; }
    .auth-main { padding: 32px 20px 40px; }
    .auth-bottom { padding: 16px 20px; }
    .auth-bottom .links { margin-left: 0; width: 100%; }
  }
</style>
</head>
<body>
<div class="auth-shell">
  <header class="auth-top">
    <a class="brand-logo" href="<?= e(url('/')) ?>" aria-label="<?= e($app_name) ?> home"><img src="<?= e(asset('assets/img/logo.png')) ?>" alt="<?= e($app_name) ?>"></a>
    <a class="home-link" href="<?= e(url('/')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>Back to website</a>
  </header>
  <main class="auth-main">
    <div class="auth-card fade-in">
      <?php foreach (\App\Core\Session::flashMessages() as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : $flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
      <ul class="auth-points">
        <li><span>&#10003;</span> Learns from your website, PDFs and FAQs</li>
        <li><span>&#10003;</span> Natural voice conversations with premium voices</li>
        <li><span>&#10003;</span> Captures leads and books appointments</li>
      </ul>
    </div>
  </main>
  <footer class="auth-bottom">
    <span>&copy; <?= date('Y') ?> <?= e($app_name) ?></span>
    <span class="links">
      <a href="<?= e(url('/')) ?>">Home</a>
      <a href="<?= e(url('/pricing')) ?>">Pricing</a>
      <?php if (\App\Services\Settings::bool('registration_enabled')): ?><a href="<?= e(url('/register')) ?>">Create account</a><?php endif; ?>
    </span>
  </footer>
</div>
</body>
</html>
