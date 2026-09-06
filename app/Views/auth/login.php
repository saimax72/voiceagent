<h1>Welcome back</h1>
<p class="lead">Sign in to manage your AI agents.</p>
<form method="post" action="<?= e(url('/login')) ?>">
  <?= csrf_field() ?>
  <div class="form-group">
    <label class="form-label" for="email">Email address</label>
    <input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="email">
  </div>
  <div class="form-group">
    <div class="flex items-center justify-between"><label class="form-label" for="password" style="margin:0">Password</label><a href="<?= e(url('/forgot-password')) ?>" class="text-sm">Forgot password?</a></div>
    <input class="form-control mt-2" type="password" id="password" name="password" required autocomplete="current-password">
  </div>
  <div class="form-group">
    <label class="checkbox"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in for 30 days</span></label>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
</form>
<?php if (\App\Services\Settings::bool('registration_enabled')): ?>
<div class="auth-footer">New here? <a href="<?= e(url('/register')) ?>">Create a free account</a></div>
<?php endif; ?>
