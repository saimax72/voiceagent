<?php
/** @var int $current */
$steps = [1 => 'Website', 2 => 'AI agent', 3 => 'Design', 4 => 'Test', 5 => 'Install'];
?>
<div class="card mb-6" style="background:linear-gradient(135deg,#eef3ff,#f8f9fc)">
  <div class="card-body" style="padding:16px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
    <div class="steps">
      <?php foreach ($steps as $n => $label): ?>
        <div class="step <?= $n < $current ? 'done' : ($n === $current ? 'active' : '') ?>"><span class="n"><?= $n < $current ? '&#10003;' : $n ?></span><?= e($label) ?></div>
        <?php if ($n < 5): ?><span class="step-sep"></span><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="ml-auto flex gap-2 items-center">
      <?php if (!empty($nextUrl)): ?><a class="btn btn-primary btn-sm" href="<?= e($nextUrl) ?>"><?= e($nextLabel ?? 'Continue') ?> &rarr;</a><?php endif; ?>
      <form method="post" action="<?= e(url('/onboarding/skip')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Skip setup</button></form>
    </div>
  </div>
</div>
