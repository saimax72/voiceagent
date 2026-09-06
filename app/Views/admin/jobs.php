<?= \App\Core\View::partial('partials/admin_nav', ['active' => 'jobs']) ?>
<div class="flex gap-2 wrap items-center mb-4">
  <div class="segmented"><a href="<?= e(url('/admin/jobs')) ?>" style="padding:7px 12px;font-size:13px;font-weight:600;color:<?= $status === '' ? 'var(--text)' : 'var(--text-3)' ?>">All</a><?php foreach (['queued', 'running', 'completed', 'failed', 'cancelled'] as $s): ?><a href="<?= e(url('/admin/jobs?status=' . $s)) ?>" style="padding:7px 12px;font-size:13px;font-weight:600;color:<?= $status === $s ? 'var(--text)' : 'var(--text-3)' ?>"><?= ucfirst($s) ?> (<?= (int) ($counts[$s] ?? 0) ?>)</a><?php endforeach; ?></div>
  <span class="ml-auto"><?= $workerAlive ? '<span class="badge badge-success">Worker running</span>' : '<span class="badge badge-warning">No cron worker detected</span>' ?></span>
  <form method="post" action="<?= e(url('/admin/jobs/run')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm">Run worker now</button></form>
</div>
<div class="card"><div class="table-wrap"><table class="table table-compact">
  <thead><tr><th>ID</th><th>Type</th><th>Workspace / agent</th><th>Status</th><th>Progress</th><th>Updated</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $j): ?>
    <tr>
      <td class="muted">#<?= (int) $j['id'] ?></td><td><?= e($j['type']) ?></td><td class="text-sm"><?= e($j['tenant_name'] ?? '-') ?><div class="muted"><?= e($j['agent_name'] ?? '') ?></div></td>
      <td><span class="badge badge-<?= match ($j['status']) { 'completed' => 'success', 'failed' => 'danger', 'running' => 'info', 'cancelled' => 'neutral', default => 'warning' } ?>"><?= e($j['status']) ?></span></td>
      <td class="text-sm" style="max-width:320px"><?= (int) $j['progress'] ?>% &middot; <?= e(str_limit((string) $j['progress_text'], 70)) ?><?php if ($j['last_error']): ?><div class="text-danger text-xs"><?= e(str_limit($j['last_error'], 120)) ?></div><?php endif; ?></td>
      <td class="muted nowrap"><?= e(time_ago($j['updated_at'])) ?></td>
      <td class="actions"><?php if (in_array($j['status'], ['failed', 'cancelled'], true)): ?><form method="post" action="<?= e(url('/admin/jobs/' . $j['id'] . '/retry')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Retry</button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$jobs): ?><tr><td colspan="7" class="text-center muted" style="padding:30px">No jobs.</td></tr><?php endif; ?>
  </tbody></table></div></div>
