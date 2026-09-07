<div class="page-header">
  <div><h1>Unanswered questions</h1><p>Questions the assistant could not answer from your knowledge. Teach it the answer and it will know next time.</p></div>
</div>
<div class="flex gap-2 wrap items-center mb-4">
  <div class="segmented">
    <?php foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $s => $l): ?><a href="<?= e(url('/unanswered', array_merge($filters, ['status' => $s, 'page' => 1]))) ?>" class="<?= $status === $s ? 'active' : '' ?>" style="padding:7px 14px;border-radius:5px;font-weight:600;font-size:13px;color:<?= $status === $s ? 'var(--text)' : 'var(--text-3)' ?>;background:<?= $status === $s ? 'var(--surface)' : 'transparent' ?>"><?= $l ?> (<?= (int) ($counts[$s] ?? 0) ?>)</a><?php endforeach; ?>
  </div>
  <form method="get" class="flex gap-2 items-center"><input type="hidden" name="status" value="<?= e($status) ?>"><select class="form-select form-control-sm" name="agent" onchange="this.form.submit()"><option value="">All agents</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) ($filters['agent'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></form>
</div>
<?php if (!$items): ?>
  <div class="card"><div class="empty"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg></div><h3>Nothing here</h3><p><?= $status === 'open' ? 'Great news: the assistant answered everything it was asked.' : 'No ' . e($status) . ' questions.' ?></p></div></div>
<?php else: ?>
<div class="stack">
  <?php foreach ($items as $u): ?>
    <div class="card" x-data="{ teach: false }">
      <div class="card-body" style="padding:18px 22px">
        <div class="flex gap-3 items-center wrap">
          <div class="flex-1">
            <div class="font-semibold text-lg" style="font-size:15.5px"><?= e($u['question']) ?></div>
            <div class="text-sm text-muted mt-1"><?= e($u['agent_name']) ?> &middot; asked <?= (int) $u['occurrences'] ?> time<?= (int) $u['occurrences'] === 1 ? '' : 's' ?> &middot; last <?= e(time_ago($u['updated_at'])) ?><?php if ($u['conversation_id']): ?> &middot; <a href="<?= e(url('/conversations/' . $u['conversation_id'])) ?>">view conversation</a><?php endif; ?></div>
            <?php if ($u['answer_given']): ?><div class="text-sm text-muted mt-2" style="border-left:3px solid var(--border);padding-left:10px">Assistant replied: <?= e(str_limit($u['answer_given'], 220)) ?></div><?php endif; ?>
            <?php if ($u['status'] === 'resolved' && $u['resolution']): ?><div class="text-sm mt-2" style="border-left:3px solid var(--success);padding-left:10px"><strong>Learned answer:</strong> <?= e(str_limit($u['resolution'], 300)) ?></div><?php endif; ?>
          </div>
          <div class="flex gap-2">
            <?php if ($u['status'] === 'open'): ?>
              <button class="btn btn-primary btn-sm" @click="teach = !teach">Teach answer</button>
              <form method="post" action="<?= e(url('/unanswered/' . $u['id'] . '/ignore')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Ignore</button></form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/unanswered/' . $u['id'] . '/delete')) ?>" data-confirm="Delete this entry?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">Delete</button></form>
          </div>
        </div>
        <form method="post" action="<?= e(url('/unanswered/' . $u['id'] . '/resolve')) ?>" x-show="teach" x-cloak class="mt-4" style="background:var(--surface-2);border-radius:8px;padding:16px">
          <?= csrf_field() ?>
          <div class="form-group"><label class="form-label">Question (as the assistant should recognise it)</label><input class="form-control" name="question" value="<?= e($u['question']) ?>"></div>
          <div class="form-group"><label class="form-label">Correct answer</label><textarea class="form-control" name="answer" rows="4" placeholder="Write the answer the assistant should give from now on." required></textarea></div>
          <div class="flex gap-2"><button class="btn btn-primary btn-sm" type="submit">Save &amp; train</button><button class="btn btn-ghost btn-sm" type="button" @click="teach=false">Cancel</button></div>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($pages > 1): ?><div class="pagination"><?php for ($p = 1; $p <= $pages; $p++): ?><?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="' . e(url('/unanswered', array_merge($filters, ['status' => $status, 'page' => $p]))) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
