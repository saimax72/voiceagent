<?= \App\Core\View::partial('partials/admin_nav', ['active' => 'plans']) ?>
<p class="text-muted mb-4">Limits: agents, messages per month, pages per agent, documents per agent, storage (MB), premium voice (1/0), remove branding (1/0), lead capture (1/0), analytics history (days). Use 0 for unlimited numeric limits. Stripe price IDs come from your Stripe dashboard (Products).</p>
<div class="stack">
<?php foreach ($plans as $p): ?>
  <div class="card" x-data="{open:false}">
    <div class="card-header" style="cursor:pointer" @click="open=!open"><div><h3><?= e($p['name']) ?> <span class="text-muted text-sm">(<?= e($p['plan_key']) ?>)</span></h3><div class="sub">$<?= number_format((float) $p['price_monthly'], 2) ?>/mo &middot; $<?= number_format((float) $p['price_yearly'], 2) ?>/yr &middot; <?= (int) $p['is_active'] ? 'active' : 'hidden' ?><?= (int) $p['is_featured'] ? ' &middot; featured' : '' ?></div></div><div class="actions"><span class="text-sm text-primary" x-text="open ? 'Close' : 'Edit'"></span></div></div>
    <form method="post" action="<?= e(url('/admin/plans/' . $p['id'])) ?>" class="card-body" x-show="open" x-cloak><?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Name</label><input class="form-control" name="name" value="<?= e($p['name']) ?>"></div>
        <div class="form-group"><label class="form-label">Description</label><input class="form-control" name="description" value="<?= e($p['description']) ?>"></div>
        <div class="form-group"><label class="form-label">Monthly price (USD)</label><input class="form-control" name="price_monthly" type="number" step="0.01" value="<?= e($p['price_monthly']) ?>"></div>
        <div class="form-group"><label class="form-label">Yearly price (USD)</label><input class="form-control" name="price_yearly" type="number" step="0.01" value="<?= e($p['price_yearly']) ?>"></div>
        <div class="form-group"><label class="form-label">Stripe price ID (monthly)</label><input class="form-control" name="stripe_price_monthly" value="<?= e($p['stripe_price_monthly']) ?>" placeholder="price_..."></div>
        <div class="form-group"><label class="form-label">Stripe price ID (yearly)</label><input class="form-control" name="stripe_price_yearly" value="<?= e($p['stripe_price_yearly']) ?>" placeholder="price_..."></div>
      </div>
      <h4 class="mb-2">Limits</h4>
      <div class="grid grid-3" style="gap:12px">
        <?php foreach ($limitKeys as $k): ?><div class="form-group"><label class="form-label"><?= e(ucfirst(str_replace('_', ' ', $k))) ?></label><input class="form-control form-control-sm" type="number" name="limit_<?= e($k) ?>" value="<?= (int) ($p['limits'][$k] ?? 0) ?>"></div><?php endforeach; ?>
      </div>
      <div class="form-group"><label class="form-label">Feature bullets (one per line)</label><textarea class="form-control" name="features" rows="5"><?= e(implode("\n", $p['features'])) ?></textarea></div>
      <div class="flex gap-4 wrap items-center">
        <label class="checkbox"><input type="checkbox" name="is_active" value="1" <?= (int) $p['is_active'] ? 'checked' : '' ?>> <span>Visible / selectable</span></label>
        <label class="checkbox"><input type="checkbox" name="is_featured" value="1" <?= (int) $p['is_featured'] ? 'checked' : '' ?>> <span>Highlight as popular</span></label>
        <label class="flex items-center gap-2 text-sm">Sort <input class="form-control form-control-sm" style="width:70px" type="number" name="sort_order" value="<?= (int) $p['sort_order'] ?>"></label>
        <button class="btn btn-primary ml-auto">Save plan</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>
