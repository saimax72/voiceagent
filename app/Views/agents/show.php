<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'overview']) ?>

<?php
$checks = [
    ['Add knowledge', $knowledge['documents'] > 0, url('/agents/' . $agent['id'] . '/knowledge'), 'Scan your website or upload documents so the assistant can answer.'],
    ['Design the widget', true, url('/agents/' . $agent['id'] . '/customize'), 'Match colours, texts and the launcher icon to your brand.'],
    ['Test the assistant', $testConversations > 0 || $stats['conversations'] > 0, url('/agents/' . $agent['id'] . '/test'), 'Have a quick voice or text conversation before going live.'],
    ['Install on your website', !empty($agent['installed_at']), url('/agents/' . $agent['id'] . '/install'), 'Paste one line of code into your site.'],
];
?>
<div class="grid grid-4 mb-6">
  <div class="card stat"><div class="stat-label">Conversations (month)</div><div class="stat-value"><?= format_number($stats['conversations']) ?></div></div>
  <div class="card stat"><div class="stat-label">Messages (month)</div><div class="stat-value"><?= format_number($stats['messages']) ?></div><div class="stat-delta"><?= format_number($stats['voice']) ?> by voice</div></div>
  <div class="card stat"><div class="stat-label">Leads (all time)</div><div class="stat-value"><?= format_number($stats['leads']) ?></div></div>
  <div class="card stat"><div class="stat-label">Open unanswered</div><div class="stat-value"><?= format_number($stats['unanswered']) ?></div><div class="stat-delta"><a href="<?= e(url('/unanswered?agent=' . $agent['id'])) ?>">Teach the assistant</a></div></div>
</div>

<div class="grid grid-sidebar">
  <div class="stack">
    <div class="card">
      <div class="card-header"><div><h3>Setup checklist</h3><div class="sub">Everything needed to go live</div></div></div>
      <div class="card-body" style="padding-top:8px">
        <?php foreach ($checks as [$label, $done, $link, $hint]): ?>
          <a href="<?= e($link) ?>" class="flex items-center gap-3" style="padding:12px 0;border-bottom:1px solid var(--border);color:var(--text)">
            <span style="width:26px;height:26px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;<?= $done ? 'background:var(--success-50);color:var(--success)' : 'background:var(--surface-2);color:var(--text-4);border:1px solid var(--border)' ?>"><?= $done ? '&#10003;' : '' ?></span>
            <span class="flex-1"><strong><?= e($label) ?></strong><div class="text-sm text-muted"><?= e($hint) ?></div></span>
            <span class="text-muted">&rarr;</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div><h3>Knowledge</h3><div class="sub"><?= format_number($knowledge['documents']) ?> pages/documents, <?= format_number($knowledge['chunks']) ?> knowledge chunks<?= $agent['last_trained_at'] ? ', trained ' . e(time_ago($agent['last_trained_at'])) : '' ?></div></div><div class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('/agents/' . $agent['id'] . '/knowledge')) ?>">Manage</a></div></div>
      <?php if ($jobs): ?><div class="card-body" style="padding-bottom:0"><?php foreach ($jobs as $job): ?><div class="alert alert-info" style="margin-bottom:12px"><span class="spinner"></span><span><?= e($job['progress_text'] ?: 'Working...') ?> (<?= (int) $job['progress'] ?>%)</span></div><?php endforeach; ?></div><?php endif; ?>
      <?php if (!$sources): ?><div class="empty" style="padding:24px"><p class="mb-0">No knowledge sources yet.</p></div><?php else: ?>
      <div class="table-wrap"><table class="table table-compact"><tbody>
        <?php foreach ($sources as $s): $st = json_field($s['stats']); ?>
          <tr><td><div class="cell-primary truncate" style="max-width:300px"><?= e($s['title']) ?></div><div class="muted"><?= e(ucfirst($s['type'])) ?><?= isset($st['documents']) ? ' &middot; ' . (int) $st['documents'] . ' pages, ' . (int) $st['chunks'] . ' chunks' : '' ?></div></td>
          <td class="text-right"><?= $s['status'] === 'ready' ? '<span class="badge badge-success">Ready</span>' : ($s['status'] === 'error' ? '<span class="badge badge-danger">Error</span>' : '<span class="badge badge-warning">' . e(ucfirst($s['status'])) . '</span>') ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-header"><h3>Embed code</h3></div>
      <div class="card-body">
        <div class="code-box"><pre id="embed-mini" style="font-size:12px;white-space:pre-wrap;word-break:break-all">&lt;script src="<?= e(base_url()) ?>/widget.js" data-agent-id="<?= e($agent['public_id']) ?>"&gt;&lt;/script&gt;</pre><button class="btn btn-secondary btn-sm copy" data-copy="#embed-mini">Copy</button></div>
        <p class="text-sm text-muted mt-3 mb-0"><?= !empty($agent['installed_at']) ? 'Detected on <strong>' . e($agent['installed_domain']) . '</strong> ' . e(time_ago($agent['installed_at'])) . '.' : 'Not detected on any website yet.' ?> <a href="<?= e(url('/agents/' . $agent['id'] . '/install')) ?>">Installation guide</a></p>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3>Recent conversations</h3><div class="actions"><a class="text-sm" href="<?= e(url('/conversations?agent=' . $agent['id'])) ?>">View all</a></div></div>
      <?php if (!$recent): ?><div class="empty" style="padding:24px"><p class="mb-0">No conversations yet.</p></div><?php else: ?>
      <div class="table-wrap"><table class="table table-compact"><tbody>
        <?php foreach ($recent as $c): ?><tr class="clickable" onclick="location.href='<?= e(url('/conversations/' . $c['id'])) ?>'"><td><div class="cell-primary truncate" style="max-width:240px"><?= e($c['title'] ?: 'New conversation') ?></div><div class="muted"><?= e(time_ago($c['last_message_at'] ?: $c['started_at'])) ?> &middot; <?= (int) $c['message_count'] ?> messages</div></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><h3>Danger zone</h3></div>
      <div class="card-body flex gap-2 wrap">
        <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Duplicate</button></form>
        <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/delete')) ?>" data-confirm="Delete this agent and all of its knowledge, conversations and leads? This cannot be undone."><?= csrf_field() ?><button class="btn btn-danger btn-sm">Delete agent</button></form>
      </div>
    </div>
  </div>
</div>
