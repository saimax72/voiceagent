<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Sign in') ?> - <?= e($app_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<style>
  body { background: radial-gradient(1200px 600px at 10% -10%, #eef2ff 0%, transparent 60%), radial-gradient(900px 500px at 110% 110%, #f5f3ff 0%, transparent 60%), var(--bg); }
  .auth-wrap { min-height: 100vh; display: grid; grid-template-columns: 1fr 1fr; }
  .auth-side { display: flex; flex-direction: column; justify-content: center; padding: 60px; background: linear-gradient(160deg, #1e1b4b 0%, #312e81 45%, #4c1d95 100%); color: #fff; position: relative; overflow: hidden; }
  .auth-side::after { content: ''; position: absolute; width: 520px; height: 520px; border-radius: 50%; background: radial-gradient(circle, rgba(139,92,246,.45), transparent 70%); right: -160px; bottom: -160px; }
  .auth-side h2 { font-size: 34px; line-height: 1.15; margin: 22px 0 14px; letter-spacing: -0.02em; max-width: 460px; }
  .auth-side p { color: rgba(255,255,255,.75); font-size: 16px; max-width: 440px; line-height: 1.6; }
  .auth-side ul { list-style: none; padding: 0; margin: 28px 0 0; display: flex; flex-direction: column; gap: 12px; }
  .auth-side li { display: flex; gap: 12px; align-items: center; font-size: 15px; color: rgba(255,255,255,.9); }
  .auth-side li span { width: 26px; height: 26px; border-radius: 8px; background: rgba(255,255,255,.14); display: grid; place-items: center; font-size: 13px; }
  .auth-main { display: flex; align-items: center; justify-content: center; padding: 40px 24px; }
  .auth-card { width: 100%; max-width: 440px; }
  .auth-card h1 { font-size: 26px; margin-bottom: 6px; }
  .auth-card .lead { color: var(--text-3); margin-bottom: 26px; }
  .auth-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 30px; font-weight: 700; font-size: 17px; color: var(--text); }
  .auth-footer { margin-top: 22px; text-align: center; color: var(--text-3); font-size: 14px; }
  @media (max-width: 900px) { .auth-wrap { grid-template-columns: 1fr; } .auth-side { display: none; } }
</style>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-side">
    <div style="position:relative;z-index:1">
      <div class="flex items-center gap-3"><div class="brand-mark"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 3v18M7 8v8M17 8v8M3 11v2M21 11v2"/></svg></div><strong style="font-size:18px"><?= e($app_name) ?></strong></div>
      <h2>Give your website a voice that answers every question.</h2>
      <p>Train an AI assistant on your website and documents in minutes. Visitors can talk or type, and the assistant answers only from your approved knowledge.</p>
      <ul>
        <li><span>&#10003;</span> Learns from your website, PDFs and FAQs</li>
        <li><span>&#10003;</span> Natural voice conversations with premium voices</li>
        <li><span>&#10003;</span> Captures leads and shows unanswered questions</li>
        <li><span>&#10003;</span> One line of code to install anywhere</li>
      </ul>
    </div>
  </div>
  <div class="auth-main">
    <div class="auth-card fade-in">
      <a class="auth-brand" href="<?= e(url('/')) ?>"><span class="brand-mark" style="width:32px;height:32px;border-radius:9px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 3v18M7 8v8M17 8v8M3 11v2M21 11v2"/></svg></span><?= e($app_name) ?></a>
      <?php foreach (\App\Core\Session::flashMessages() as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : $flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
</body>
</html>
