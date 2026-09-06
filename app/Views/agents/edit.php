<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'settings']) ?>
<?php $o = static fn(string $k) => old($k, $agent[$k] ?? ''); ?>
<form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/settings')) ?>" x-data="{ tab: location.hash.replace('#','') || 'basics', voice: <?= (int) $agent['voice_enabled'] ? 'true' : 'false' ?>, tts: '<?= e($agent['tts_provider']) ?>', lead: <?= (int) $agent['lead_capture_enabled'] ? 'true' : 'false' ?>, persona: '<?= e($agent['persona']) ?>' }">
  <?= csrf_field() ?>
  <div class="tabs">
    <button type="button" :class="{active: tab==='basics'}" @click="tab='basics'">Basics</button>
    <button type="button" :class="{active: tab==='personality'}" @click="tab='personality'">Personality</button>
    <button type="button" :class="{active: tab==='voice'}" @click="tab='voice'">Voice</button>
    <button type="button" :class="{active: tab==='messages'}" @click="tab='messages'">Messages</button>
    <button type="button" :class="{active: tab==='leads'}" @click="tab='leads'">Lead capture</button>
    <button type="button" :class="{active: tab==='security'}" @click="tab='security'">Security</button>
  </div>

  <div class="card" x-show="tab==='basics'">
    <div class="card-body">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Agent name</label><input class="form-control" name="name" value="<?= e($o('name')) ?>" required></div>
        <div class="form-group"><label class="form-label">Business name</label><input class="form-control" name="business_name" value="<?= e($o('business_name')) ?>" placeholder="Shown in the assistant's introduction"></div>
      </div>
      <div class="form-group"><label class="form-label">Website</label><input class="form-control" name="website_url" value="<?= e($o('website_url')) ?>" placeholder="https://www.example.com"><div class="form-hint">Used for context. Scan it from the Knowledge page to teach the assistant.</div></div>
      <div class="form-group"><label class="form-label">Internal description <span class="optional">(only you see this)</span></label><input class="form-control" name="description" value="<?= e($o('description')) ?>"></div>
    </div>
  </div>

  <div class="card" x-show="tab==='personality'" x-cloak>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Personality</label>
        <div class="grid grid-3" style="gap:10px">
          <?php foreach ($personas as $key => $p): ?>
            <label class="card" style="padding:12px 14px;cursor:pointer;box-shadow:none" :style="persona==='<?= e($key) ?>' ? 'border-color:var(--primary);background:var(--primary-50)' : ''"><div class="flex items-center gap-2"><input type="radio" name="persona" value="<?= e($key) ?>" x-model="persona" style="accent-color:var(--primary)"> <strong class="text-sm"><?= e($p['label']) ?></strong></div><div class="text-xs text-muted mt-1"><?= e($p['description']) ?></div></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Custom instructions</label>
        <textarea class="form-control" name="instructions" rows="7" placeholder="Example: Always mention that shipping is free above 50 EUR. Never discuss competitor products. If someone asks for a quote, collect their email and project details."><?= e($o('instructions')) ?></textarea>
        <div class="form-hint">Extra rules, tone guidance and things the assistant should always or never do. The assistant still only uses your approved knowledge for facts.</div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Answer length</label><select class="form-select" name="response_length"><?php foreach (['short' => 'Short (1-2 sentences)', 'medium' => 'Balanced', 'long' => 'Detailed'] as $k => $l): ?><option value="<?= $k ?>" <?= $o('response_length') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Language</label><select class="form-select" name="language"><?php foreach ($languages as $code => $label): ?><option value="<?= e($code) ?>" <?= $o('language') === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">AI model</label><select class="form-select" name="llm_model"><option value="">Platform default</option><?php foreach ($models as $id => $label): ?><option value="<?= e($id) ?>" <?= $o('llm_model') === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Reasoning effort</label><select class="form-select" name="effort"><?php foreach (['low' => 'Fast (recommended for chat)', 'medium' => 'Balanced', 'high' => 'Thorough (slower)'] as $k => $l): ?><option value="<?= $k ?>" <?= $o('effort') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><div class="form-hint">Higher effort lets the model think longer before answering.</div></div>
      </div>
    </div>
  </div>

  <div class="card" x-show="tab==='voice'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="switch"><input type="checkbox" name="voice_enabled" value="1" x-model="voice"><span class="track"></span><span class="switch-label">Enable voice conversations (microphone button and spoken replies)</span></label></div>
      <div x-show="voice">
        <?php if (!$premiumVoice): ?><div class="alert alert-info"><span>Premium neural voices and server-side transcription are included in paid plans. Your plan currently uses the free browser voice. <a href="<?= e(url('/billing')) ?>">Upgrade</a></span></div><?php endif; ?>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Voice engine</label>
            <select class="form-select" name="tts_provider" x-model="tts">
              <option value="auto">Automatic (best available)</option>
              <option value="openai" <?= $hasOpenAI ? '' : 'disabled' ?>>Premium neural voice (OpenAI)<?= $hasOpenAI ? '' : ' - not configured' ?></option>
              <option value="elevenlabs" <?= $hasElevenLabs ? '' : 'disabled' ?>>Premium neural voice (ElevenLabs)<?= $hasElevenLabs ? '' : ' - not configured' ?></option>
              <option value="browser">Browser voice (free, device dependent)</option>
            </select>
          </div>
          <div class="form-group" x-show="tts !== 'browser'"><label class="form-label">Voice</label>
            <select class="form-select" name="tts_voice">
              <optgroup label="OpenAI neural voices" x-show="tts !== 'elevenlabs'"><?php foreach ($voices['openai'] as $id => $label): ?><option value="<?= e($id) ?>" <?= $o('tts_voice') === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></optgroup>
              <optgroup label="ElevenLabs voices" x-show="tts === 'elevenlabs'"><?php foreach ($voices['elevenlabs'] as $id => $label): ?><option value="<?= e($id) ?>" <?= $o('tts_voice') === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></optgroup>
            </select>
            <div class="form-hint" x-show="tts === 'elevenlabs'">Using a custom ElevenLabs voice? Paste its voice ID in the field below.</div>
            <input class="form-control mt-2" x-show="tts === 'elevenlabs'" name="tts_voice_custom" placeholder="Custom ElevenLabs voice ID (optional)" oninput="if(this.value.trim()){this.form.tts_voice.value=this.value.trim()}">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Speaking speed: <span x-text="document.querySelector('[name=tts_speed]') ? '' : ''"></span></label><input type="range" name="tts_speed" min="0.7" max="1.4" step="0.05" value="<?= e($o('tts_speed') ?: '1') ?>" oninput="this.nextElementSibling.textContent = this.value + 'x'"><span class="text-sm text-muted"><?= e($o('tts_speed') ?: '1') ?>x</span></div>
          <div class="form-group"><label class="form-label">Speech recognition</label><select class="form-select" name="stt_provider"><option value="auto" <?= $o('stt_provider') === 'auto' ? 'selected' : '' ?>>Automatic (server transcription when available)</option><option value="browser" <?= $o('stt_provider') === 'browser' ? 'selected' : '' ?>>Browser only (free)</option></select><div class="form-hint">Server transcription works in every browser and supports 50+ languages. Browser recognition is free but not available in Firefox.</div></div>
        </div>
        <div class="form-group"><label class="switch"><input type="checkbox" name="auto_speak" value="1" <?= (int) $agent['auto_speak'] ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Read replies aloud while in voice mode</span></label></div>
      </div>
    </div>
  </div>

  <div class="card" x-show="tab==='messages'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Greeting</label><textarea class="form-control" name="greeting_message" rows="3"><?= e($o('greeting_message')) ?></textarea><div class="form-hint">First message shown (and spoken in voice mode) when the assistant opens.</div></div>
      <div class="form-group"><label class="form-label">Fallback message</label><textarea class="form-control" name="fallback_message" rows="3"><?= e($o('fallback_message')) ?></textarea><div class="form-hint">Shown when the AI service is unavailable.</div></div>
    </div>
  </div>

  <div class="card" x-show="tab==='leads'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="switch"><input type="checkbox" name="lead_capture_enabled" value="1" x-model="lead"><span class="track"></span><span class="switch-label">Collect contact details from interested visitors</span></label><div class="form-hint">When a visitor asks to be contacted, wants a quote or asks something the assistant cannot answer, it will naturally ask for their details and save them as a lead.</div></div>
      <div x-show="lead">
        <div class="form-group"><label class="form-label">Details to collect</label>
          <div class="flex gap-4 wrap">
            <?php foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'message' => 'Message'] as $f => $l): ?><label class="checkbox"><input type="checkbox" name="lead_fields[]" value="<?= $f ?>" <?= in_array($f, $leadFields, true) ? 'checked' : '' ?> <?= $f === 'name' ? 'disabled checked' : '' ?>> <span><?= $l ?></span></label><?php endforeach; ?>
            <input type="hidden" name="lead_fields[]" value="name">
          </div>
        </div>
        <div class="form-group"><label class="form-label">When and how to collect leads <span class="optional">(optional)</span></label><textarea class="form-control" name="lead_instructions" rows="3" placeholder="Example: Offer a free consultation call and collect the phone number when someone asks about pricing."><?= e($o('lead_instructions')) ?></textarea></div>
        <div class="form-group"><label class="form-label">Send new-lead notifications to</label><input class="form-control" type="email" name="lead_notify_email" value="<?= e($o('lead_notify_email')) ?>" placeholder="sales@example.com"><div class="form-hint">Leave empty to use your account email.</div></div>
      </div>
    </div>
  </div>

  <div class="card" x-show="tab==='security'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Allowed websites</label><textarea class="form-control" name="allowed_domains" rows="3" placeholder="example.com&#10;shop.example.com"><?= e($o('allowed_domains')) ?></textarea><div class="form-hint">One domain per line. When set, the widget only works on these domains (subdomains included). Leave empty to allow any website.</div></div>
      <div class="kv"><dt>Agent ID</dt><dd class="mono"><?= e($agent['public_id']) ?></dd><dt>Created</dt><dd><?= e(format_date($agent['created_at'], 'M j, Y H:i')) ?></dd></div>
    </div>
  </div>

  <div class="flex justify-between items-center mt-6" style="position:sticky;bottom:0;background:var(--bg);padding:14px 0">
    <a class="btn btn-ghost" href="<?= e(url('/agents/' . $agent['id'])) ?>">Back to overview</a>
    <button class="btn btn-primary btn-lg" type="submit">Save changes</button>
  </div>
</form>
