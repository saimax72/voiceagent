<h1>Choose a new password</h1>
<p class="lead">for <strong><?= e($email) ?></strong></p>
<form method="post" action="<?= e(url('/reset-password')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="form-group">
    <label class="form-label" for="password">New password</label>
    <input class="form-control" type="password" id="password" name="password" required minlength="8" autofocus autocomplete="new-password">
  </div>
  <div class="form-group">
    <label class="form-label" for="password_confirmation">Confirm password</label>
    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password">
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Update password</button>
</form>
