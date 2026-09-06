<div class="page-header">
  <div><h1>Leads</h1><p><?= format_number($total) ?> lead<?= $total === 1 ? '' : 's' ?> &middot; <?= (int) ($counts['new'] ?? 0) ?> new</p></div>
  <div class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('/leads/export', $filters)) ?>">Export CSV</a></div>
</div>
<form method="get" class="card mb-4"><div class="card-body flex gap-2 wrap items-center" style="padding:14px 18px">
  <input class="form-control form-control-sm" style="max-width:220px" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search name, email, phone...">
  <select class="form-select form-control-sm" style="max-width:170px" name="agent"><option value="">All agents</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) ($filters['agent'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
  <select class="form-select form-control-sm" style="max-width:150px" name="status"><option value="">All statuses</option><?php foreach (['new', 'contacted', 'qualified', 'closed', 'spam'] as $s): ?><option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?> (<?= (int) ($counts[$s] ?? 0) ?>)</option><?php endforeach; ?></select>
  <button class="btn btn-secondary btn-sm">Filter</button>
</div></form>
<div class="card">
  <?php if (!$leads): ?><div class="empty"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></div><h3>No leads yet</h3><p>When visitors ask to be contacted or leave their details, they appear here and you get an email.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Contact</th><th>Message</th><th>Agent</th><th>Status</th><th>Captured</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr x-data="{open:false}">
        <td><div class="cell-primary"><?= e($l['name'] ?: '(no name)') ?></div><div class="muted"><?= $l['email'] ? '<a href="mailto:' . e($l['email']) . '">' . e($l['email']) . '</a>' : '' ?><?= $l['email'] && $l['phone'] ? ' &middot; ' : '' ?><?= $l['phone'] ? '<a href="tel:' . e($l['phone']) . '">' . e($l['phone']) . '</a>' : '' ?></div></td>
        <td style="max-width:320px"><div class="text-sm"><?= e(str_limit($l['message'] ?: '-', 120)) ?></div><?php if ($l['notes']): ?><div class="muted text-xs mt-1">Note: <?= e(str_limit($l['notes'], 80)) ?></div><?php endif; ?></td>
        <td class="muted"><?= e($l['agent_name']) ?><?php if ($l['conversation_id']): ?><div><a class="text-xs" href="<?= e(url('/conversations/' . $l['conversation_id'])) ?>">View chat</a></div><?php endif; ?></td>
        <td>
          <form method="post" action="<?= e(url('/leads/' . $l['id'])) ?>"><?= csrf_field() ?>
            <select class="form-select form-control-sm" name="status" onchange="this.form.submit()" style="min-width:120px">
              <?php foreach (['new', 'contacted', 'qualified', 'closed', 'spam'] as $s): ?><option value="<?= $s ?>" <?= $l['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
            </select>
          </form>
        </td>
        <td class="muted nowrap"><?= e(time_ago($l['created_at'])) ?></td>
        <td class="actions">
          <button class="btn btn-ghost btn-sm" @click="open=!open">Notes</button>
          <form method="post" action="<?= e(url('/leads/' . $l['id'] . '/delete')) ?>" style="display:inline" data-confirm="Delete this lead?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">Delete</button></form>
          <div x-show="open" x-cloak class="mt-2" style="text-align:left">
            <form method="post" action="<?= e(url('/leads/' . $l['id'])) ?>"><?= csrf_field() ?><textarea class="form-control form-control-sm" name="notes" rows="3" placeholder="Internal notes..."><?= e($l['notes'] ?? '') ?></textarea><button class="btn btn-secondary btn-sm mt-2">Save note</button></form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</div>
<?php if ($pages > 1): ?><div class="pagination"><?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?><?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="' . e(url('/leads', array_merge($filters, ['page' => $p]))) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
