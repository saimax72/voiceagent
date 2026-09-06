<div class="card"><div class="card-body" style="padding:40px 32px">
  <div style="font-size:64px;font-weight:800;letter-spacing:-0.04em;background:linear-gradient(135deg,var(--primary),var(--accent));-webkit-background-clip:text;background-clip:text;color:transparent;line-height:1"><?= (int) $status ?></div>
  <h1 style="margin:14px 0 8px"><?= e($title) ?></h1>
  <?php if (!empty($message)): ?><p class="text-muted" style="white-space:pre-wrap;text-align:left;font-size:13px;overflow:auto;max-height:320px"><?= e($message) ?></p><?php else: ?><p class="text-muted">Something went wrong. Please try again or head back to safety.</p><?php endif; ?>
  <div class="flex gap-2 mt-4" style="justify-content:center">
    <a href="<?= e(url('/')) ?>" class="btn btn-secondary">Home</a>
    <a href="<?= e(url('/dashboard')) ?>" class="btn btn-primary">Dashboard</a>
  </div>
</div></div>
