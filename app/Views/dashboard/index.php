<?php
$max = 1;
foreach ($series as $d) { $max = max($max, (int) $d['messages']); }
?>
<?php if (!$aiReady && auth()->isSuperAdmin()): ?>
  <div class="alert alert-warning"><span>No AI provider key is configured yet, so assistants cannot reply. Add your Anthropic API key under <a href="<?= e(url('/admin/settings')) ?>">Admin &rarr; Settings</a>.</span></div>
<?php elseif (!$aiReady): ?>
  <div class="alert alert-warning"><span>The platform's AI provider is not configured yet. Assistants will start answering as soon as the administrator adds an API key.</span></div>
<?php endif; ?>

<div class="grid grid-4 mb-6">
  <div class="card stat"><div class="flex"><div><div class="stat-label">Conversations this month</div><div class="stat-value"><?= format_number($stats['conversations']) ?></div></div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-4.5A8 8 0 1 1 21 12z"/></svg></div></div></div>
  <div class="card stat"><div class="flex"><div><div class="stat-label">Messages answered</div><div class="stat-value"><?= format_number($stats['messages']) ?></div><div class="stat-delta"><?= format_number($stats['voice_messages']) ?> by voice</div></div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></div></div></div>
  <div class="card stat"><div class="flex"><div><div class="stat-label">Leads captured</div><div class="stat-value"><?= format_number($stats['leads']) ?></div><div class="stat-delta"><?= $stats['leads_new'] ?> new to follow up</div></div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></div></div></div>
  <div class="card stat"><div class="flex"><div><div class="stat-label">Unanswered questions</div><div class="stat-value"><?= format_number($stats['unanswered']) ?></div><div class="stat-delta"><a href="<?= e(url('/unanswered')) ?>">Review &amp; teach</a></div></div><div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></svg></div></div></div>
</div>

<div class="grid grid-sidebar">
  <div class="stack">
    <div class="card">
      <div class="card-header"><div><h3>Your AI agents</h3><div class="sub"><?= count($agents) ?> of <?= (int) $plan['limits']['agents'] ?> agents used</div></div><div class="actions"><a class="btn btn-primary btn-sm" href="<?= e(url('/agents/new')) ?>">+ New agent</a></div></div>
      <?php if (!$agents): ?>
        <div class="empty"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4m-4 4h8M9 14h.01M15 14h.01"/></svg></div><h3>Create your first agent</h3><p>Enter your website, and your assistant will learn your content in minutes.</p><a class="btn btn-primary" href="<?= e(url('/onboarding')) ?>">Start setup</a></div>
      <?php else: ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Agent</th><th>Status</th><th>Knowledge</th><th>Conversations</th><th>Leads</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($agents as $a): ?>
            <tr class="clickable" onclick="location.href='<?= e(url('/agents/' . $a['id'])) ?>'">
              <td><div class="flex items-center gap-3"><span class="avatar"><?= e(initials($a['name'])) ?></span><div><div class="cell-primary"><?= e($a['name']) ?></div><div class="muted"><?= e($a['website_url'] ?: 'No website') ?></div></div></div></td>
              <td><?= $a['status'] === 'active' ? '<span class="badge badge-success"><span class="dot"></span>Active</span>' : '<span class="badge badge-neutral">Paused</span>' ?></td>
              <td><?= (int) $a['documents_indexed'] ?> pages<?= $a['last_trained_at'] ? '<div class="muted">trained ' . e(time_ago($a['last_trained_at'])) . '</div>' : '<div class="muted text-warning">not trained yet</div>' ?></td>
              <td><?= format_number((int) $a['conversations_count']) ?></td>
              <td><?= format_number((int) $a['leads_count']) ?></td>
              <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('/agents/' . $a['id'] . '/install')) ?>" onclick="event.stopPropagation()">Install</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><div><h3>Activity, last 14 days</h3><div class="sub">Messages answered per day</div></div></div>
      <div class="card-body">
        <?php $count = count($series); $w = 100 / max(1, $count); ?>
        <svg viewBox="0 0 100 34" preserveAspectRatio="none" style="width:100%;height:120px;display:block" role="img" aria-label="Messages per day">
          <?php $i = 0; foreach ($series as $day => $d): $h = $max > 0 ? ($d['messages'] / $max) * 28 : 0; ?>
            <rect x="<?= $i * $w + $w * 0.15 ?>" y="<?= 32 - $h ?>" width="<?= $w * 0.7 ?>" height="<?= max(0.6, $h) ?>" rx="0.8" fill="<?= $d['messages'] > 0 ? '#5b5bd6' : '#e2e8f0' ?>"><title><?= e($day) ?>: <?= $d['messages'] ?> messages, <?= $d['conversations'] ?> conversations</title></rect>
          <?php $i++; endforeach; ?>
        </svg>
        <div class="flex justify-between text-xs text-muted mt-2"><span><?= e(format_date(array_key_first($series) . ' 00:00:00', 'M j')) ?></span><span>Today</span></div>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-header"><h3>Recent conversations</h3><div class="actions"><a href="<?= e(url('/conversations')) ?>" class="text-sm">View all</a></div></div>
      <?php if (!$recentConversations): ?><div class="empty" style="padding:28px"><p class="mb-0">No conversations yet. Install the widget to start talking to visitors.</p></div><?php else: ?>
      <div class="table-wrap"><table class="table table-compact"><tbody>
        <?php foreach ($recentConversations as $c): ?>
          <tr class="clickable" onclick="location.href='<?= e(url('/conversations/' . $c['id'])) ?>'">
            <td><div class="cell-primary truncate" style="max-width:220px"><?= e($c['title'] ?: 'New conversation') ?></div><div class="muted"><?= e($c['agent_name']) ?> &middot; <?= e(time_ago($c['last_message_at'] ?: $c['started_at'])) ?></div></td>
            <td class="text-right"><?php if ($c['channel'] !== 'text'): ?><span class="badge badge-primary">Voice</span><?php endif; ?><?php if ($c['has_lead']): ?><span class="badge badge-success">Lead</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><h3>Latest leads</h3><div class="actions"><a href="<?= e(url('/leads')) ?>" class="text-sm">View all</a></div></div>
      <?php if (!$recentLeads): ?><div class="empty" style="padding:28px"><p class="mb-0">Leads captured by your assistant will appear here.</p></div><?php else: ?>
      <div class="table-wrap"><table class="table table-compact"><tbody>
        <?php foreach ($recentLeads as $l): ?>
          <tr><td><div class="cell-primary"><?= e($l['name'] ?: ($l['email'] ?: $l['phone'])) ?></div><div class="muted"><?= e($l['email'] ?: $l['phone']) ?> &middot; <?= e(time_ago($l['created_at'])) ?></div></td><td class="text-right"><span class="badge badge-<?= $l['status'] === 'new' ? 'warning' : 'neutral' ?>"><?= e(ucfirst($l['status'])) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
