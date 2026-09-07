<div class="page-header"><div><h1>Plan &amp; billing</h1><p>Your subscription and usage.</p></div></div>
<div class="grid grid-sidebar mb-6">
  <div class="card"><div class="card-header"><div><h3>Current plan: <?= e($plan['name']) ?><?= $plan['is_trial'] ? ' <span class="badge badge-primary">Trial</span>' : '' ?></h3>
    <div class="sub"><?php if ($plan['is_trial']): ?>Your free trial of the <?= e($plan['name']) ?> plan ends <?= e(format_date($tenant['trial_ends_at'], 'M j, Y')) ?>. Afterwards you move to the Free plan unless you subscribe.<?php elseif (!empty($tenant['stripe_subscription_id'])): ?>Subscription <?= e($tenant['subscription_status'] ?? 'active') ?><?= $tenant['current_period_end'] ? ', renews ' . e(format_date($tenant['current_period_end'], 'M j, Y')) : '' ?> (<?= e($tenant['billing_cycle'] ?? 'monthly') ?>).<?php else: ?>You are on the <?= e($plan['name']) ?> plan.<?php endif; ?></div></div>
    <div class="actions"><?php if ($stripe && !empty($tenant['stripe_customer_id'])): ?><form method="post" action="<?= e(url('/billing/portal')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Manage billing</button></form><?php endif; ?></div></div>
    <div class="card-body">
      <?php foreach ($meters as [$label, $used, $limit]): $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0; ?>
        <div class="mb-3"><div class="flex justify-between text-sm mb-1"><span><?= e($label) ?></span><span class="text-muted"><?= format_number($used) ?> / <?= $limit > 0 ? format_number($limit) : 'unlimited' ?></span></div><div class="meter<?= $pct >= 90 ? ' warn' : '' ?>"><span style="width:<?= $pct ?>%"></span></div></div>
      <?php endforeach; ?>
      <?php if (($tenant['subscription_status'] ?? '') === 'past_due'): ?><div class="alert alert-warning mb-0"><span>Your last payment failed. Please update your payment method to keep premium features.</span></div><?php endif; ?>
    </div>
  </div>
  <div class="card"><div class="card-header"><h3>Messages, last 6 months</h3></div><div class="card-body">
    <?php $max = max(1, max($history)); ?>
    <svg viewBox="0 0 100 34" preserveAspectRatio="none" style="width:100%;height:110px;display:block"><?php $i = 0; $w = 100 / max(1, count($history)); foreach ($history as $period => $v): $h = $v / $max * 28; ?><rect x="<?= $i * $w + $w * .2 ?>" y="<?= 32 - $h ?>" width="<?= $w * .6 ?>" height="<?= max(.5, $h) ?>" rx=".8" fill="#0052fc"><title><?= e($period) ?>: <?= $v ?></title></rect><?php $i++; endforeach; ?></svg>
    <div class="flex justify-between text-xs text-muted mt-2"><?php foreach ($history as $period => $v): ?><span><?= e(date('M', strtotime($period . '-01'))) ?></span><?php endforeach; ?></div>
  </div></div>
</div>

<div x-data="{ cycle: 'monthly' }">
  <div class="flex items-center justify-between mb-4 wrap gap-3"><h2>Choose a plan</h2><div class="segmented"><button type="button" :class="{active: cycle==='monthly'}" @click="cycle='monthly'">Monthly</button><button type="button" :class="{active: cycle==='yearly'}" @click="cycle='yearly'">Yearly <span class="badge badge-success" style="margin-left:6px">2 months free</span></button></div></div>
  <div class="grid grid-4">
    <?php foreach ($plans as $p): $current = $p['plan_key'] === ($tenant['plan_key'] ?? 'free') && !$plan['is_trial']; ?>
      <div class="card" style="<?= (int) $p['is_featured'] ? 'border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-100)' : '' ?>;display:flex;flex-direction:column">
        <div class="card-body" style="flex:1">
          <div class="flex items-center justify-between"><h3><?= e($p['name']) ?></h3><?= (int) $p['is_featured'] ? '<span class="badge badge-primary">Popular</span>' : '' ?></div>
          <p class="text-sm text-muted mt-1" style="min-height:40px"><?= e($p['description']) ?></p>
          <div style="font-size:30px;font-weight:800;letter-spacing:-.02em"><span x-show="cycle==='monthly'">$<?= number_format((float) $p['price_monthly'], 0) ?></span><span x-show="cycle==='yearly'" x-cloak>$<?= number_format((float) $p['price_yearly'] / 12, 0) ?></span><span class="text-sm text-muted" style="font-weight:500">/month</span></div>
          <div class="text-xs text-muted" x-show="cycle==='yearly'" x-cloak>billed $<?= number_format((float) $p['price_yearly'], 0) ?> per year</div>
          <ul style="padding-left:18px;margin:14px 0 0;line-height:1.9;font-size:13.5px;color:var(--text-2)"><?php foreach ($p['features'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
        </div>
        <div class="card-footer">
          <?php if ($current): ?><button class="btn btn-secondary btn-block" disabled>Current plan</button>
          <?php elseif ((float) $p['price_monthly'] == 0): ?><span class="text-sm text-muted">Free forever</span>
          <?php elseif ($stripe): ?><form method="post" action="<?= e(url('/billing/checkout')) ?>"><?= csrf_field() ?><input type="hidden" name="plan" value="<?= e($p['plan_key']) ?>"><input type="hidden" name="cycle" :value="cycle"><button class="btn btn-primary btn-block"><?= (float) $p['price_monthly'] > (float) ($plans[$tenant['plan_key']]['price_monthly'] ?? 0) ? 'Upgrade' : 'Switch' ?></button></form>
          <?php else: ?><a class="btn btn-primary btn-block" href="mailto:<?= e($supportEmail) ?>?subject=Upgrade%20to%20<?= e($p['name']) ?>">Contact us to upgrade</a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
