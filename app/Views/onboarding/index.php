<?= \App\Core\View::partial('partials/onboarding_steps', ['current' => $step, 'nextUrl' => $step === 2 && $agent ? url('/agents/' . $agent['id'] . '/customize?onboarding=1') : null, 'nextLabel' => 'Skip to design']) ?>

<?php if ($step === 1): ?>
<div class="card max-w-lg" style="margin:0 auto">
  <div class="card-body" style="padding:36px">
    <div class="text-center mb-6">
      <div class="empty-icon" style="width:64px;height:64px;border-radius:20px;margin:0 auto 16px;background:var(--primary-50);color:var(--primary-600);display:grid;place-items:center"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div>
      <h1>What is your website?</h1>
      <p class="text-muted" style="max-width:460px;margin:8px auto 0">Your assistant will read your pages and learn your products, services, pricing and policies. You can add documents and FAQs later.</p>
    </div>
    <form method="post" action="<?= e(url('/onboarding/website')) ?>">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="website">Website address</label>
        <input class="form-control" style="padding:14px 16px;font-size:16px" id="website" name="website" value="<?= e(old('website', $website)) ?>" placeholder="https://www.yourcompany.com" autofocus>
        <div class="form-hint">We only read public pages of this website. Scanning usually takes 1-3 minutes.</div>
      </div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Scan my website &rarr;</button>
      <div class="text-center mt-3"><button class="btn btn-ghost btn-sm" type="submit" name="website" value="">I don't have a website yet</button></div>
    </form>
  </div>
</div>

<?php elseif ($step === 2 && $agent): ?>
<div class="grid grid-sidebar">
  <div class="card">
    <div class="card-header"><div><h3>Create your AI agent</h3><div class="sub">Give it a name, a personality and a voice. You can change everything later.</div></div></div>
    <form method="post" action="<?= e(url('/onboarding/agent')) ?>" class="card-body" x-data="{ tts: '<?= e($agent['tts_provider'] === 'auto' ? $ttsDefault : $agent['tts_provider']) ?>', voice: <?= (int) $agent['voice_enabled'] ? 'true' : 'false' ?> }">
      <?= csrf_field() ?>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Agent name</label><input class="form-control" name="name" value="<?= e(old('name', $agent['name'])) ?>" required></div>
        <div class="form-group"><label class="form-label">Business name</label><input class="form-control" name="business_name" value="<?= e(old('business_name', $agent['business_name'])) ?>" placeholder="Acme Inc."></div>
      </div>
      <div class="form-group">
        <label class="form-label">Personality</label>
        <div class="grid grid-3" style="gap:10px">
          <?php foreach ($personas as $key => $p): ?>
            <label class="card" style="padding:12px 14px;cursor:pointer;box-shadow:none"><div class="flex items-center gap-2"><input type="radio" name="persona" value="<?= e($key) ?>" <?= old('persona', $agent['persona']) === $key ? 'checked' : '' ?> style="accent-color:var(--primary)"> <strong class="text-sm"><?= e($p['label']) ?></strong></div><div class="text-xs text-muted mt-1"><?= e($p['description']) ?></div></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Language</label><select class="form-select" name="language"><?php foreach ($languages as $code => $label): ?><option value="<?= e($code) ?>" <?= old('language', $agent['language']) === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Voice conversations</label><label class="switch" style="margin-top:8px"><input type="checkbox" name="voice_enabled" value="1" x-model="voice"><span class="track"></span><span class="switch-label">Let visitors talk to the assistant</span></label></div>
      </div>
      <div x-show="voice" x-cloak>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Voice engine</label>
            <select class="form-select" name="tts_provider" x-model="tts">
              <option value="auto">Automatic (best available)</option>
              <option value="openai">Premium neural voice (OpenAI)</option>
              <option value="elevenlabs">Premium neural voice (ElevenLabs)</option>
              <option value="browser">Browser voice (free)</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Voice</label>
            <select class="form-select" name="tts_voice">
              <template x-if="tts === 'elevenlabs'"><optgroup label="ElevenLabs"><?php foreach ($voices['elevenlabs'] as $id => $label): ?><option value="<?= e($id) ?>" <?= $agent['tts_voice'] === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></optgroup></template>
              <template x-if="tts !== 'elevenlabs'"><optgroup label="Neural voices"><?php foreach ($voices['openai'] as $id => $label): ?><option value="<?= e($id) ?>" <?= $agent['tts_voice'] === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></optgroup></template>
            </select>
            <div class="form-hint" x-show="tts === 'browser'">The browser voice uses the visitor's device voices. Premium voices sound far more natural.</div>
          </div>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Greeting (spoken and shown when the assistant opens)</label><textarea class="form-control" name="greeting_message" rows="2"><?= e(old('greeting_message', $agent['greeting_message'])) ?></textarea></div>
      <div class="flex gap-2 justify-between items-center mt-4"><a class="btn btn-ghost" href="<?= e(url('/onboarding?step=1')) ?>">&larr; Back</a><button class="btn btn-primary btn-lg" type="submit">Continue to design &rarr;</button></div>
    </form>
  </div>
  <div class="stack">
    <div class="card" id="scan-card">
      <div class="card-header"><h3>Website scan</h3></div>
      <div class="card-body">
        <?php if ($job): ?>
          <div id="scan-status">
            <div class="flex items-center gap-2 mb-2"><span class="spinner" id="scan-spinner" <?= in_array($job['status'], ['completed', 'failed'], true) ? 'hidden' : '' ?>></span><strong id="scan-text"><?= e($job['progress_text'] ?: 'Starting...') ?></strong></div>
            <div class="progress striped"><span id="scan-bar" style="width:<?= (int) $job['progress'] ?>%"></span></div>
            <p class="text-sm text-muted mt-3" id="scan-hint">Scanning <strong><?= e($agent['website_url'] ?? '') ?></strong>. You can keep filling in the form while this runs.</p>
          </div>
          <?php \App\Core\View::start('scripts'); ?>
          <script>
            VA.pollJob(<?= (int) $job['id'] ?>, {
              onProgress: function (job) { document.getElementById('scan-bar').style.width = job.progress + '%'; document.getElementById('scan-text').textContent = job.progress_text || ''; },
              onDone: function (job) { document.getElementById('scan-spinner').hidden = true; document.getElementById('scan-hint').innerHTML = '<span class="text-success font-semibold">Done.</span> Your assistant has learned your website.'; document.querySelector('#scan-card .progress').classList.remove('striped'); },
              onError: function (job) { document.getElementById('scan-spinner').hidden = true; document.getElementById('scan-hint').innerHTML = '<span class="text-danger">The scan failed: ' + VA.escapeHtml(job.last_error || 'unknown error') + '</span>. You can retry from the Knowledge page.'; }
            });
          </script>
          <?php \App\Core\View::stop(); ?>
        <?php else: ?>
          <p class="text-muted mb-0">No website scan was started. You can add your website, documents and FAQs from the Knowledge page at any time.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="card"><div class="card-body"><h4 class="mb-2">Tip</h4><p class="text-sm text-muted mb-0">Pick the personality that matches your brand voice. The assistant only answers from your approved content, whatever the personality.</p></div></div>
  </div>
</div>
<?php endif; ?>
