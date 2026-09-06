<div class="breadcrumb"><a href="<?= e(url('/agents')) ?>">Agents</a> <span>/</span> <span>New agent</span></div>
<div class="page-header"><div><h1>Create a new agent</h1><p>You can refine everything afterwards.</p></div></div>
<div class="card max-w-lg">
  <form method="post" action="<?= e(url('/agents')) ?>" class="card-body">
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Agent name</label><input class="form-control" name="name" value="<?= e(old('name', 'Assistant')) ?>" required autofocus></div>
      <div class="form-group"><label class="form-label">Business name <span class="optional">(optional)</span></label><input class="form-control" name="business_name" value="<?= e(old('business_name')) ?>"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Website to learn from <span class="optional">(optional)</span></label>
      <input class="form-control" name="website_url" value="<?= e(old('website_url')) ?>" placeholder="https://www.example.com">
      <label class="checkbox mt-2"><input type="checkbox" name="scan" value="1" checked> <span>Scan the website right away</span></label>
    </div>
    <div class="form-group">
      <label class="form-label">Personality</label>
      <div class="grid grid-3" style="gap:10px">
        <?php foreach ($personas as $key => $p): ?>
          <label class="card" style="padding:12px 14px;cursor:pointer;box-shadow:none"><div class="flex items-center gap-2"><input type="radio" name="persona" value="<?= e($key) ?>" <?= old('persona', 'friendly') === $key ? 'checked' : '' ?> style="accent-color:var(--primary)"> <strong class="text-sm"><?= e($p['label']) ?></strong></div><div class="text-xs text-muted mt-1"><?= e($p['description']) ?></div></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Language</label><select class="form-select" name="language"><?php foreach ($languages as $code => $label): ?><option value="<?= e($code) ?>" <?= old('language', 'auto') === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div class="flex justify-between items-center mt-4"><a class="btn btn-ghost" href="<?= e(url('/agents')) ?>">Cancel</a><button class="btn btn-primary btn-lg" type="submit">Create agent</button></div>
  </form>
</div>
