<?= \App\Core\View::partial('partials/admin_nav', ['active' => 'tenants']) ?>
<form method="get" class="flex gap-2 mb-4"><input class="form-control form-control-sm" style="max-width:300px" name="q" value="<?= e($q) ?>" placeholder="Search by name or email"><button class="btn btn-secondary btn-sm">Search</button></form>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Workspace</th><th>Plan</th><th>Status</th><th>Agents</th><th>Messages (month)</th><th>Joined</th></tr></thead>
  <tbody>
  <?php foreach ($tenants as $t): ?>
    <tr class="clickable" onclick="location.href='<?= e(url('/admin/tenants/' . $t['id'])) ?>'">
      <td><div class="cell-primary"><?= e($t['name']) ?></div><div class="muted"><?= e($t['email']) ?></div></td>
      <td><span class="badge badge-neutral"><?= e($t['plan_key']) ?></span><?= $t['trial_ends_at'] && strtotime($t['trial_ends_at'] . ' UTC') > time() ? ' <span class="badge badge-primary">trial</span>' : '' ?></td>
      <td><?= $t['status'] === 'active' ? '<span class="badge badge-success">active</span>' : '<span class="badge badge-danger">' . e($t['status']) . '</span>' ?></td>
      <td><?= (int) $t['agents'] ?></td><td><?= format_number((int) $t['messages']) ?></td><td class="muted"><?= e(time_ago($t['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php if ($pages > 1): ?><div class="pagination"><?php for ($p = 1; $p <= $pages; $p++): ?><?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="' . e(url('/admin/tenants', ['q' => $q, 'page' => $p])) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
