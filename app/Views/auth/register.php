<h1>Create your account</h1>
<p class="lead">Free to start. No credit card required.</p>
<form method="post" action="<?= e(url('/register')) ?>">
  <?= csrf_field() ?>
  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="name">Your name</label>
      <input class="form-control" id="name" name="name" value="<?= e(old('name')) ?>" required autofocus autocomplete="name">
    </div>
    <div class="form-group">
      <label class="form-label" for="company">Company <span class="optional">(optional)</span></label>
      <input class="form-control" id="company" name="company" value="<?= e(old('company')) ?>" autocomplete="organization">
    </div>
  </div>
  <div class="form-group">
    <label class="form-label" for="website">Website <span class="optional">(optional)</span></label>
    <input class="form-control" id="website" name="website" value="<?= e(old('website')) ?>" placeholder="https://www.example.com" autocomplete="url">
    <div class="form-hint">We will offer to scan it during setup so your assistant can learn your content.</div>
  </div>
  <div class="form-group">
    <label class="form-label" for="email">Work email</label>
    <input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email">
  </div>
  <div class="form-group">
    <label class="form-label" for="password">Password</label>
    <input class="form-control" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
    <div class="form-hint">At least 8 characters.</div>
  </div>
  <div class="form-group">
    <label class="checkbox"><input type="checkbox" name="terms" value="1" required> <span>I agree to the terms of service and privacy policy.</span></label>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
</form>
<div class="auth-footer">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></div>
