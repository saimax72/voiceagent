<?php
/** @var string $content */
$user = current_user();
$tenant = current_tenant();
$plan = \App\Services\Plans::forTenant($tenant ?? []);
$usage = \App\Services\Usage::summary((int) ($tenant['id'] ?? 0));
$msgLimit = (int) ($plan['limits']['messages_per_month'] ?? 0);
$msgUsed = (int) ($usage['messages'] ?? 0);
$pct = $msgLimit > 0 ? min(100, (int) round($msgUsed / $msgLimit * 100)) : 0;
$openUnanswered = 0;
$newLeads = 0;
try {
    $openUnanswered = db()->count('unanswered_questions', 'tenant_id = ? AND status = ?', [(int) $tenant['id'], 'open']);
    $newLeads = db()->count('leads', 'tenant_id = ? AND status = ?', [(int) $tenant['id'], 'new']);
} catch (\Throwable) {
}
$path = \App\Core\App::request()->path();
$nav = static function (string $href, string $label, string $icon, bool $active, int $count = 0): string {
    $icons = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/>',
        'bot' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4m-4 4h8M9 14h.01M15 14h.01"/>',
        'chat' => '<path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-4.5A8 8 0 1 1 21 12z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.5a5 5 0 0 1 6 5"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>',
        'chart' => '<path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
    ];
    return '<a href="' . e(url($href)) . '" class="nav-link' . ($active ? ' active' : '') . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$icon] ?? '') . '</svg><span>' . e($label) . '</span>' . ($count > 0 ? '<span class="count">' . $count . '</span>' : '') . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Dashboard') ?> - <?= e($app_name) ?></title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<script>window.VA = { base: <?= json_encode(base_url()) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
<?= \App\Core\View::section('head') ?>
</head>
<body>
<div class="app" x-data="{ sidebar: false }">
  <div class="sidebar-overlay" :class="{ show: sidebar }" @click="sidebar = false"></div>
  <aside class="sidebar" :class="{ open: sidebar }">
    <div class="sidebar-brand">
      <a class="brand-logo" href="<?= e(url('/dashboard')) ?>"><img src="<?= e(asset('assets/img/logo-white.png')) ?>" alt="<?= e($app_name) ?>"></a>
      <?php if (!empty($tenant['name'])): ?><div class="brand-sub"><?= e($tenant['name']) ?></div><?php endif; ?>
    </div>
    <nav class="sidebar-nav">
      <?= $nav('/dashboard', 'Dashboard', 'home', $path === '/dashboard') ?>
      <?= $nav('/agents', 'AI Agents', 'bot', route_is('/agents')) ?>
      <?= $nav('/conversations', 'Conversations', 'chat', route_is('/conversations')) ?>
      <?= $nav('/leads', 'Leads', 'users', route_is('/leads'), $newLeads) ?>
      <?= $nav('/unanswered', 'Unanswered', 'help', route_is('/unanswered'), $openUnanswered) ?>
      <?= $nav('/analytics', 'Analytics', 'chart', route_is('/analytics')) ?>
      <div class="nav-section">Account</div>
      <?= $nav('/settings', 'Settings', 'settings', route_is('/settings')) ?>
      <?= $nav('/billing', 'Plan & billing', 'card', route_is('/billing')) ?>
      <?php if (auth()->isSuperAdmin()): ?>
        <div class="nav-section">Platform</div>
        <?= $nav('/admin', 'Admin panel', 'shield', route_is('/admin')) ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="plan-card">
        <div class="label"><?= $plan['is_trial'] ? 'Trial' : 'Current plan' ?></div>
        <div class="name"><?= e($plan['name']) ?><?php if ($plan['is_trial']): ?> <span class="text-muted text-xs">(trial)</span><?php endif; ?></div>
        <div class="meter<?= $pct >= 90 ? ' warn' : '' ?>"><span style="width: <?= $pct ?>%"></span></div>
        <div class="text-xs text-muted mt-2"><?= format_number($msgUsed) ?> / <?= $msgLimit > 0 ? format_number($msgLimit) : 'unlimited' ?> messages this month</div>
        <?php if (($plan['plan_key'] ?? '') === 'free' || $plan['is_trial']): ?>
          <a href="<?= e(url('/billing')) ?>" class="btn btn-primary btn-sm btn-block mt-3">Upgrade</a>
        <?php endif; ?>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="btn btn-icon btn-secondary menu-toggle" @click="sidebar = !sidebar" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <div class="topbar-title"><?= e($title ?? 'Dashboard') ?><?php if (!empty($subtitle)): ?><small><?= e($subtitle) ?></small><?php endif; ?></div>
      <div class="topbar-actions">
        <?php if (auth()->isImpersonating()): ?>
          <form method="post" action="<?= e(url('/admin/stop-impersonating')) ?>"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Stop impersonating</button></form>
        <?php endif; ?>
        <div class="dropdown" x-data="{ open: false }" @click.outside="open = false">
          <button class="btn btn-ghost" @click="open = !open" style="padding:6px 8px">
            <span class="avatar avatar-sm"><?= e(initials($user['name'] ?? 'U')) ?></span>
            <span class="font-semibold" style="max-width:140px" class="truncate"><?= e($user['name'] ?? '') ?></span>
            <svg class="icon-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="dropdown-menu" x-show="open" x-cloak x-transition>
            <a href="<?= e(url('/settings')) ?>">Profile & settings</a>
            <a href="<?= e(url('/billing')) ?>">Plan & billing</a>
            <hr>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button type="submit">Sign out</button></form>
          </div>
        </div>
      </div>
    </header>
    <main class="content<?= !empty($contentClass) ? ' ' . e($contentClass) : '' ?>">
      <?php if (!empty($user) && empty($user['email_verified_at']) && \App\Services\Settings::bool('email_verification_notice')): ?>
        <div class="alert alert-info"><span>Please verify your email address. Check your inbox for the confirmation link.</span>
          <form method="post" action="<?= e(url('/resend-verification')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-sm btn-secondary">Resend</button></form></div>
      <?php endif; ?>
      <?php foreach (\App\Core\Session::flashMessages() as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'error' : $flash['type']) ?> fade-in"><?= e($flash['message']) ?><button class="close" onclick="this.parentNode.remove()">&times;</button></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<div class="toast-stack" id="toasts"></div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script defer src="<?= e(asset('assets/js/vendor/alpine.min.js')) ?>"></script>
<?= \App\Core\View::section('scripts') ?>
</body>
</html>
