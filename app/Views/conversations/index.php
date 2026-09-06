<div class="page-header">
  <div><h1>Conversations</h1><p><?= format_number($total) ?> conversation<?= $total === 1 ? '' : 's' ?></p></div>
  <div class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('/conversations/export/csv', $filters)) ?>">Export CSV</a></div>
</div>
<form method="get" class="card mb-4"><div class="card-body flex gap-2 wrap items-center" style="padding:14px 18px">
  <input class="form-control form-control-sm" style="max-width:220px" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search messages...">
  <select class="form-select form-control-sm" style="max-width:180px" name="agent"><option value="">All agents</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) ($filters['agent'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
  <select class="form-select form-control-sm" style="max-width:150px" name="channel"><option value="">Text &amp; voice</option><option value="voice" <?= ($filters['channel'] ?? '') === 'voice' ? 'selected' : '' ?>>Voice</option><option value="text" <?= ($filters['channel'] ?? '') === 'text' ? 'selected' : '' ?>>Text only</option></select>
  <label class="checkbox"><input type="checkbox" name="lead" value="1" <?= !empty($filters['lead']) ? 'checked' : '' ?>> <span>With lead</span></label>
  <label class="checkbox"><input type="checkbox" name="unanswered" value="1" <?= !empty($filters['unanswered']) ? 'checked' : '' ?>> <span>Unanswered</span></label>
  <label class="checkbox"><input type="checkbox" name="test" value="1" <?= !empty($filters['test']) ? 'checked' : '' ?>> <span>Test chats</span></label>
  <button class="btn btn-secondary btn-sm">Filter</button>
  <?php if ($filters): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/conversations')) ?>">Clear</a><?php endif; ?>
</div></form>
<div class="card">
  <?php if (!$conversations): ?><div class="empty"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-4.5A8 8 0 1 1 21 12z"/></svg></div><h3>No conversations found</h3><p>Conversations with visitors will appear here as soon as the widget is live.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Conversation</th><th>Agent</th><th>Type</th><th>Messages</th><th>Flags</th><th>Started</th></tr></thead>
    <tbody>
    <?php foreach ($conversations as $c): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('/conversations/' . $c['id'])) ?>'">
        <td><div class="cell-primary truncate" style="max-width:360px"><?= e($c['title'] ?: 'New conversation') ?></div><div class="muted"><?= e($c['device'] ?: '') ?><?= $c['page_url'] ? ' &middot; ' . e(str_limit((string) parse_url($c['page_url'], PHP_URL_PATH) ?: '/', 40)) : '' ?></div></td>
        <td><?= e($c['agent_name']) ?></td>
        <td><?= $c['channel'] === 'text' ? '<span class="badge badge-neutral">Text</span>' : '<span class="badge badge-primary">Voice</span>' ?></td>
        <td><?= (int) $c['message_count'] ?></td>
        <td><?php if ($c['has_lead']): ?><span class="badge badge-success">Lead</span> <?php endif; ?><?php if ($c['has_unanswered']): ?><span class="badge badge-warning">Unanswered</span> <?php endif; ?><?php if ($c['is_test']): ?><span class="badge badge-info">Test</span><?php endif; ?></td>
        <td class="muted nowrap"><?= e(time_ago($c['started_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</div>
<?php if ($pages > 1): ?><div class="pagination"><?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?><?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="' . e(url('/conversations', array_merge($filters, ['page' => $p]))) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
