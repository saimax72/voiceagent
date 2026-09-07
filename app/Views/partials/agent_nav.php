<?php
/** @var array $agent @var string $active */
$base = '/agents/' . $agent['id'];
$items = [
    'overview' => ['', 'Overview', '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>'],
    'knowledge' => ['/knowledge', 'Knowledge', '<path d="M4 5a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M14 3v5h5M8 13h8M8 17h6"/>'],
    'settings' => ['/settings', 'Personality & voice', '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>'],
    'customize' => ['/customize', 'Widget design', '<path d="M12 3a9 9 0 1 0 0 18c1.2 0 2-.8 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1 .8-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-4-4-7-9-7z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="10.5" cy="7.5" r="1"/><circle cx="14.5" cy="7.5" r="1"/>'],
    'booking' => ['/booking', 'Bookings', '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/>'],
    'test' => ['/test', 'Test', '<path d="M5 3l14 9-14 9z"/>'],
    'install' => ['/install', 'Install', '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M14 4l-4 16"/>'],
];
?>
<div class="page-header" style="margin-top:6px">
  <div class="flex items-center gap-3">
    <span class="avatar avatar-lg"><?= e(initials($agent['name'])) ?></span>
    <div>
      <div class="breadcrumb"><a href="<?= e(url('/agents')) ?>">Agents</a> <span>/</span> <span><?= e($agent['name']) ?></span></div>
      <div class="flex items-center gap-2"><h1 style="font-size:22px"><?= e($agent['name']) ?></h1>
        <?= $agent['status'] === 'active' ? '<span class="badge badge-success"><span class="dot"></span>Active</span>' : '<span class="badge badge-neutral">Paused</span>' ?></div>
    </div>
  </div>
  <div class="actions">
    <form method="post" action="<?= e(url($base . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit"><?= $agent['status'] === 'active' ? 'Pause agent' : 'Enable agent' ?></button></form>
    <a class="btn btn-primary btn-sm" href="<?= e(url($base . '/test')) ?>">Test agent</a>
  </div>
</div>
<div class="subnav">
  <?php foreach ($items as $key => [$path, $label, $icon]): ?>
    <a href="<?= e(url($base . $path)) ?>" class="<?= $active === $key ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg><?= e($label) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(url('/conversations?agent=' . $agent['id'])) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-4.5A8 8 0 1 1 21 12z"/></svg>Conversations</a>
</div>
