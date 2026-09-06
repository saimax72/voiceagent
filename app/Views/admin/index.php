<?= \App\Core\View::partial('partials/admin_nav', ['active' => 'index']) ?>
<?php if ($pending): ?><div class="alert alert-warning"><span>Database updates are pending (<?= count($pending) ?>).</span><form method="post" action="<?= e(url('/admin/system/migrate')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Run updates</button></form></div><?php endif; ?>
<?php if (!$llmConfigured): ?><div class="alert alert-error"><span>No AI provider is configured. Assistants cannot answer until you add an API key under <a href="<?= e(url('/admin/settings?tab=ai')) ?>">Settings &rarr; AI providers</a>.</span></div><?php endif; ?>
<div class="grid grid-4 mb-6">
  <div class="card stat"><div class="stat-label">Workspaces</div><div class="stat-value"><?= format_number($stats['tenants']) ?></div><div class="stat-delta">+<?= $stats['tenants_month'] ?> this month &middot; <?= $stats['paid'] ?> paid</div></div>
  <div class="card stat"><div class="stat-label">AI agents</div><div class="stat-value"><?= format_number($stats['agents']) ?></div></div>
  <div class="card stat"><div class="stat-label">Messages this month</div><div class="stat-value"><?= format_number($stats['messages_month']) ?></div><div class="stat-delta"><?= format_number($stats['conversations_month']) ?> conversations</div></div>
  <div class="card stat"><div class="stat-label">Leads this month</div><div class="stat-value"><?= format_number($stats['leads_month']) ?></div></div>
</div>
<div class="grid grid-sidebar">
  <div class="card"><div class="card-header"><h3>Newest workspaces</h3><div class="actions"><a class="text-sm" href="<?= e(url('/admin/tenants')) ?>">All workspaces</a></div></div>
    <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Workspace</th><th>Plan</th><th>Status</th><th>Joined</th></tr></thead><tbody>
      <?php foreach ($recentTenants as $t): ?><tr class="clickable" onclick="location.href='<?= e(url('/admin/tenants/' . $t['id'])) ?>'"><td><div class="cell-primary"><?= e($t['name']) ?></div><div class="muted"><?= e($t['email']) ?></div></td><td><span class="badge badge-neutral"><?= e($t['plan_key']) ?></span></td><td><?= e($t['status']) ?></td><td class="muted"><?= e(time_ago($t['created_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="stack">
    <div class="card"><div class="card-header"><h3>System health</h3></div><div class="card-body">
      <dl class="kv">
        <dt>Background worker</dt><dd><?= $workerAlive ? '<span class="badge badge-success">Running</span>' : '<span class="badge badge-warning">Not detected</span> <span class="text-xs text-muted">' . ($workerLast ? 'last seen ' . e(time_ago(gmdate('Y-m-d H:i:s', (int) $workerLast))) : 'set up the cron job') . '</span>' ?></dd>
        <dt>Jobs</dt><dd><?= $stats['jobs_queued'] ?> active &middot; <?= $stats['jobs_failed'] ?> failed (7d) &middot; <a href="<?= e(url('/admin/jobs')) ?>">view</a></dd>
        <dt>AI provider</dt><dd><?= $llmConfigured ? '<span class="badge badge-success">' . e($llmProvider) . '</span>' : '<span class="badge badge-danger">not configured</span>' ?></dd>
        <dt>Embeddings</dt><dd><?= $embeddings ? '<span class="badge badge-success">' . e($embeddings) . '</span>' : '<span class="badge badge-warning">keyword search only</span>' ?></dd>
        <dt>Uploaded files</dt><dd><?= e(human_filesize($diskUsed)) ?></dd>
        <dt>PHP</dt><dd><?= e(PHP_VERSION) ?> &middot; memory <?= e((string) ini_get('memory_limit')) ?> &middot; max exec <?= e((string) ini_get('max_execution_time')) ?>s &middot; upload <?= e((string) ini_get('upload_max_filesize')) ?></dd>
        <dt>Version</dt><dd>VoiceAgent <?= APP_VERSION ?></dd>
      </dl>
    </div></div>
  </div>
</div>
