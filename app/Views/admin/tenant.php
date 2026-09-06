<?= \App\Core\View::partial('partials/admin_nav', ['active' => 'tenants']) ?>
<div class="breadcrumb"><a href="<?= e(url('/admin/tenants')) ?>">Workspaces</a> <span>/</span> <span><?= e($tenant['name']) ?></span></div>
<div class="grid grid-sidebar">
  <div class="stack">
    <div class="card"><div class="card-header"><h3><?= e($tenant['name']) ?></h3><div class="actions"><form method="post" action="<?= e(url('/admin/tenants/' . $tenant['id'] . '/impersonate')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Sign in as owner</button></form></div></div>
      <div class="card-body"><dl class="kv">
        <dt>Slug</dt><dd><?= e($tenant['slug']) ?></dd><dt>Website</dt><dd><?= e($tenant['website_url'] ?: '-') ?></dd>
        <dt>Stripe customer</dt><dd class="mono"><?= e($tenant['stripe_customer_id'] ?: '-') ?></dd><dt>Subscription</dt><dd><?= e($tenant['subscription_status'] ?: '-') ?><?= $tenant['current_period_end'] ? ' until ' . e(format_date($tenant['current_period_end'])) : '' ?></dd>
        <dt>Joined</dt><dd><?= e(format_date($tenant['created_at'], 'M j, Y H:i')) ?></dd>
        <dt>Usage this month</dt><dd><?= format_number($usage['messages']) ?> messages &middot; <?= format_number($usage['voice_messages']) ?> voice &middot; <?= format_number($usage['tokens_input'] + $usage['tokens_output']) ?> tokens &middot; <?= format_number($usage['tts_characters']) ?> TTS chars &middot; <?= format_number($usage['embedding_tokens']) ?> embedding tokens</dd>
      </dl></div></div>
    <div class="card"><div class="card-header"><h3>Users</h3></div><div class="table-wrap"><table class="table table-compact"><tbody><?php foreach ($users as $u): ?><tr><td><div class="cell-primary"><?= e($u['name']) ?></div><div class="muted"><?= e($u['email']) ?></div></td><td><?= e($u['role']) ?><?= $u['is_super_admin'] ? ' <span class="badge badge-primary">super admin</span>' : '' ?></td><td class="muted"><?= $u['last_login_at'] ? 'seen ' . e(time_ago($u['last_login_at'])) : 'never signed in' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <div class="card"><div class="card-header"><h3>Agents</h3></div><div class="table-wrap"><table class="table table-compact"><tbody><?php foreach ($agents as $a): ?><tr><td class="cell-primary"><?= e($a['name']) ?></td><td><?= e($a['status']) ?></td><td class="muted"><?= (int) $a['conversations_count'] ?> conversations &middot; <?= (int) $a['leads_count'] ?> leads</td><td class="mono text-xs"><?= e($a['public_id']) ?></td></tr><?php endforeach; ?><?php if (!$agents): ?><tr><td class="muted">No agents</td></tr><?php endif; ?></tbody></table></div></div>
    <div class="card"><div class="card-header"><h3>Recent activity</h3></div><div class="table-wrap"><table class="table table-compact"><tbody><?php foreach ($activity as $l): ?><tr><td class="text-sm"><?= e($l['action']) ?></td><td class="muted text-xs"><?= e($l['ip']) ?></td><td class="muted text-xs"><?= e(time_ago($l['created_at'])) ?></td></tr><?php endforeach; ?><?php if (!$activity): ?><tr><td class="muted">No activity logged</td></tr><?php endif; ?></tbody></table></div></div>
  </div>
  <div class="stack">
    <div class="card"><div class="card-header"><h3>Plan &amp; status</h3></div>
      <form method="post" action="<?= e(url('/admin/tenants/' . $tenant['id'])) ?>" class="card-body"><?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Plan</label><select class="form-select" name="plan_key"><?php foreach ($plans as $p): ?><option value="<?= e($p['plan_key']) ?>" <?= $tenant['plan_key'] === $p['plan_key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?= $tenant['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= $tenant['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></div>
        <div class="form-group"><label class="form-label">Trial ends</label><input class="form-control" type="date" name="trial_ends_at" value="<?= e($tenant['trial_ends_at'] ? substr($tenant['trial_ends_at'], 0, 10) : '') ?>"><div class="form-hint">Leave empty for no trial.</div></div>
        <button class="btn btn-primary btn-block">Save</button>
      </form></div>
    <div class="card"><div class="card-body"><form method="post" action="<?= e(url('/admin/tenants/' . $tenant['id'] . '/delete')) ?>" data-confirm="Permanently delete this workspace and all of its data?"><?= csrf_field() ?><button class="btn btn-danger btn-block">Delete workspace</button></form></div></div>
  </div>
</div>
