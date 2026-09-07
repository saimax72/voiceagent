<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Sign in') ?> - <?= e($app_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<style>
  body { background: var(--surface); }
  .auth-wrap { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); }
  .auth-side { position: relative; display: flex; flex-direction: column; padding: 56px 64px 0; background: linear-gradient(180deg, #ffffff 0%, #f1f4fc 100%); border-right: 1px solid var(--border); overflow: hidden; }
  .auth-copy { position: relative; z-index: 1; max-width: 540px; flex: 0 0 auto; }
  .auth-copy .brand-logo img { height: 34px; width: auto; display: block; }
  .auth-side h2 { font-size: 38px; line-height: 1.1; margin: 44px 0 16px; letter-spacing: -0.035em; color: var(--ink); }
  .auth-side p { color: var(--text-2); font-size: 16px; line-height: 1.65; margin: 0; max-width: 480px; }
  .auth-side ul { list-style: none; padding: 0; margin: 28px 0 0; display: flex; flex-direction: column; gap: 12px; }
  .auth-side li { display: flex; gap: 12px; align-items: center; font-size: 15px; color: var(--text); font-weight: 600; }
  .auth-side li span { width: 26px; height: 26px; border-radius: 5px; background: var(--primary-50); color: var(--primary); display: grid; place-items: center; font-size: 13px; font-weight: 800; flex-shrink: 0; }
  .auth-art { display: block; margin: 28px -64px 0; flex: 0 0 auto; align-self: flex-end; width: calc(100% + 128px); height: clamp(200px, 44vh, 480px); margin-top: auto; padding-top: 28px; object-fit: cover; object-position: 66% 60%; pointer-events: none; user-select: none; -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 30%); mask-image: linear-gradient(to bottom, transparent 0%, #000 30%); }
  .auth-main { display: flex; align-items: center; justify-content: center; padding: 48px 24px; }
  .auth-card { width: 100%; max-width: 440px; }
  .auth-card h1 { font-size: 28px; margin-bottom: 6px; letter-spacing: -0.03em; }
  .auth-card .lead { color: var(--text-3); margin-bottom: 26px; }
  .auth-brand { display: none; margin-bottom: 30px; }
  .auth-brand img { height: 30px; width: auto; display: block; }
  .auth-footer { margin-top: 22px; text-align: center; color: var(--text-3); font-size: 14px; }
  @media (max-width: 900px) { .auth-wrap { grid-template-columns: 1fr; } .auth-side { display: none; } .auth-brand { display: block; } }
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-side">
    <div class="auth-copy">
      <a class="brand-logo" href="<?= e(url('/')) ?>"><img src="<?= e(asset('assets/img/logo.png')) ?>" alt="<?= e($app_name) ?>"></a>
      <h2>Give your website a voice that answers every question.</h2>
      <p>Train an AI assistant on your website and documents in minutes. Visitors can talk or type, and the assistant answers only from your approved knowledge.</p>
      <ul>
        <li><span>&#10003;</span> Learns from your website, PDFs and FAQs</li>
        <li><span>&#10003;</span> Natural voice conversations with premium voices</li>
        <li><span>&#10003;</span> Captures leads and shows unanswered questions</li>
        <li><span>&#10003;</span> One line of code to install anywhere</li>
      </ul>
    </div>
    <img class="auth-art" src="<?= e(asset('assets/img/banners/banner-1.webp')) ?>" srcset="<?= e(asset('assets/img/banners/banner-1-sm.webp')) ?> 960w, <?= e(asset('assets/img/banners/banner-1.webp')) ?> 1920w" sizes="55vw" alt="" aria-hidden="true">
  </div>
  <div class="auth-main">
    <div class="auth-card fade-in">
      <a class="auth-brand" href="<?= e(url('/')) ?>"><img src="<?= e(asset('assets/img/logo.png')) ?>" alt="<?= e($app_name) ?>"></a>
      <?php foreach (\App\Core\Session::flashMessages() as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : $flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
</body>
</html>
