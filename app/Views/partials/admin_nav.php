<div class="page-header"><div><h1>Platform admin</h1><p>Manage workspaces, plans, providers and background jobs.</p></div></div>
<div class="subnav">
  <?php foreach (['index' => ['', 'Overview'], 'tenants' => ['/tenants', 'Workspaces'], 'plans' => ['/plans', 'Plans'], 'settings' => ['/settings', 'Settings'], 'jobs' => ['/jobs', 'Jobs'], 'logs' => ['/logs', 'Logs']] as $key => [$path, $label]): ?>
    <a href="<?= e(url('/admin' . $path)) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
