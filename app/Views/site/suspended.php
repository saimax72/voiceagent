<div class="card"><div class="card-body" style="padding:40px 32px">
  <h1 style="margin-bottom:10px">Workspace suspended</h1>
  <p class="text-muted">Your workspace has been suspended and the assistants are paused. This usually happens after a failed payment or a terms violation.</p>
  <div class="flex gap-2 mt-4" style="justify-content:center"><a class="btn btn-primary" href="<?= e(url('/billing')) ?>">Billing</a><?php if (setting('support_email')): ?><a class="btn btn-secondary" href="mailto:<?= e(setting('support_email')) ?>">Contact support</a><?php endif; ?>
    <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Sign out</button></form></div>
</div></div>
