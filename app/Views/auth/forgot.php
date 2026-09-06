<h1>Reset your password</h1>
<p class="lead">Enter your email and we will send you a reset link.</p>
<form method="post" action="<?= e(url('/forgot-password')) ?>">
  <?= csrf_field() ?>
  <div class="form-group">
    <label class="form-label" for="email">Email address</label>
    <input class="form-control" type="email" id="email" name="email" required autofocus>
  </div>
  <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link</button>
</form>
<div class="auth-footer"><a href="<?= e(url('/login')) ?>">Back to sign in</a></div>
