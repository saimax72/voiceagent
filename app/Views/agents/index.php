<div class="page-header">
  <div><h1>AI Agents</h1><p>Each agent has its own knowledge, personality, voice and widget design.</p></div>
  <div class="actions"><?php if (count($agents) < $limit): ?><a class="btn btn-primary" href="<?= e(url('/agents/new')) ?>">+ New agent</a><?php else: ?><a class="btn btn-secondary" href="<?= e(url('/billing')) ?>">Upgrade for more agents</a><?php endif; ?></div>
</div>
<?php if (!$agents): ?>
  <div class="card"><div class="empty"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4m-4 4h8M9 14h.01M15 14h.01"/></svg></div><h3>No agents yet</h3><p>Create an agent, teach it your website and documents, then embed it on your site with one line of code.</p><a class="btn btn-primary" href="<?= e(url('/agents/new')) ?>">Create your first agent</a></div></div>
<?php else: ?>
<div class="grid grid-3">
  <?php foreach ($agents as $a): ?>
    <div class="card" style="display:flex;flex-direction:column">
      <div class="card-body" style="flex:1">
        <div class="flex items-center gap-3 mb-3">
          <span class="avatar avatar-lg"><?= e(initials($a['name'])) ?></span>
          <div class="flex-1"><a href="<?= e(url('/agents/' . $a['id'])) ?>" class="font-bold text-lg" style="color:var(--text)"><?= e($a['name']) ?></a><div class="text-sm text-muted truncate"><?= e($a['website_url'] ?: ($a['business_name'] ?: 'No website linked')) ?></div>
            <?php $tags = \App\Services\Agents::tags($a); if ($tags): ?><div class="flex gap-1 wrap mt-1"><?php foreach ($tags as $t): ?><span class="badge badge-neutral" style="padding:1px 8px"><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?></div>
          <?= $a['status'] === 'active' ? '<span class="badge badge-success"><span class="dot"></span>Active</span>' : '<span class="badge badge-neutral">Paused</span>' ?>
        </div>
        <div class="grid grid-3" style="gap:8px;text-align:center">
          <div style="background:var(--surface-2);border-radius:8px;padding:10px"><div class="font-bold text-lg"><?= format_number((int) $a['conversations_count']) ?></div><div class="text-xs text-muted">Chats</div></div>
          <div style="background:var(--surface-2);border-radius:8px;padding:10px"><div class="font-bold text-lg"><?= format_number((int) $a['leads_count']) ?></div><div class="text-xs text-muted">Leads</div></div>
          <div style="background:var(--surface-2);border-radius:8px;padding:10px"><div class="font-bold text-lg"><?= format_number((int) $a['documents_indexed']) ?></div><div class="text-xs text-muted">Pages</div></div>
        </div>
        <div class="text-xs text-muted mt-3"><?= $a['last_trained_at'] ? 'Trained ' . e(time_ago($a['last_trained_at'])) : '<span class="text-warning">Not trained yet - add knowledge</span>' ?></div>
      </div>
      <div class="card-footer flex gap-2 wrap">
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/agents/' . $a['id'] . '/knowledge')) ?>">Knowledge</a>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/agents/' . $a['id'] . '/customize')) ?>">Design</a>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('/agents/' . $a['id'] . '/test')) ?>">Test</a>
        <a class="btn btn-primary btn-sm ml-auto" href="<?= e(url('/agents/' . $a['id'] . '/install')) ?>">Install</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
