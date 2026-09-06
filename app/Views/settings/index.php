<div class="page-header"><div><h1>Settings</h1><p>Your profile and workspace preferences.</p></div></div>
<div class="grid grid-2">
  <div class="stack">
    <div class="card"><div class="card-header"><h3>Profile</h3></div>
      <form method="post" action="<?= e(url('/settings/profile')) ?>" class="card-body"><?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Name</label><input class="form-control" name="name" value="<?= e($user['name']) ?>" required></div>
        <div class="form-group"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= e($user['email']) ?>" required></div>
        <div class="form-group"><label class="form-label">Timezone</label><select class="form-select" name="timezone"><?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>" <?= ($user['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary">Save profile</button>
      </form>
    </div>
    <div class="card"><div class="card-header"><h3>Change password</h3></div>
      <form method="post" action="<?= e(url('/settings/password')) ?>" class="card-body"><?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">New password</label><input class="form-control" type="password" name="password" minlength="8" required autocomplete="new-password"></div>
          <div class="form-group"><label class="form-label">Confirm</label><input class="form-control" type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></div>
        </div>
        <button class="btn btn-primary">Update password</button>
      </form>
    </div>
  </div>
  <div class="stack">
    <div class="card"><div class="card-header"><h3>Workspace</h3></div>
      <form method="post" action="<?= e(url('/settings/workspace')) ?>" class="card-body"><?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Workspace / company name</label><input class="form-control" name="name" value="<?= e($tenant['name']) ?>" required></div>
        <div class="form-group"><label class="form-label">Notification email</label><input class="form-control" type="email" name="notification_email" value="<?= e($tenantSettings['notification_email'] ?? $user['email']) ?>"><div class="form-hint">Where new-lead alerts are sent unless an agent overrides it.</div></div>
        <div class="form-group"><label class="switch"><input type="checkbox" name="lead_emails" value="1" <?= empty($tenantSettings['lead_emails_disabled']) ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Email me when a new lead is captured</span></label></div>
        <button class="btn btn-primary">Save workspace</button>
      </form>
    </div>
    <?php if (auth()->isOwner() && !auth()->isSuperAdmin()): ?>
    <div class="card"><div class="card-header"><h3>Delete account</h3></div>
      <form method="post" action="<?= e(url('/settings/delete-account')) ?>" class="card-body" data-confirm="This permanently deletes your workspace, agents, knowledge, conversations and leads. Continue?"><?= csrf_field() ?>
        <p class="text-sm text-muted">Permanently delete your workspace and all data. This cannot be undone.</p>
        <div class="form-group"><label class="form-label">Confirm with your password</label><input class="form-control" type="password" name="password" required></div>
        <button class="btn btn-danger">Delete my account</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
