<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'settings']) ?>
<?php
$o = static fn(string $k, $default = '') => old($k, $agent[$k] ?? $default);
$vs = $voiceSettings;
$ttsInitial = (string) ($agent['tts_provider'] ?? 'auto');
$extraJson = json_encode($extraLangs, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<style>
  .var-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  .var-chips button { font: inherit; font-size: 12px; font-family: var(--mono); background: var(--surface-2); border: 1px solid var(--border); border-radius: 5px; padding: 3px 8px; cursor: pointer; color: var(--text-2); }
  .var-chips button:hover { border-color: var(--primary); color: var(--primary-700); }
  .prompt-area { font-family: var(--mono); font-size: 13px; line-height: 1.55; min-height: 260px; }
  .lang-row { border: 1px solid var(--border); border-radius: 10px; padding: 14px; margin-bottom: 10px; }
  .slider-row { display: grid; grid-template-columns: 110px 1fr 46px; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 13px; }
</style>

<form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/settings')) ?>" x-data="agentSettings()" x-init="init()" @submit="$refs.tabField.value = tab">
  <?= csrf_field() ?>
  <input type="hidden" name="tab" x-ref="tabField">
  <div class="tabs">
    <?php foreach (['basics' => 'Basics', 'prompt' => 'Prompt & behaviour', 'message' => 'First message', 'voice' => 'Voice', 'languages' => 'Languages', 'model' => 'AI model', 'leads' => 'Lead capture', 'security' => 'Security'] as $k => $l): ?>
      <button type="button" :class="{active: tab==='<?= $k ?>'}" @click="tab='<?= $k ?>'"><?= $l ?></button>
    <?php endforeach; ?>
  </div>

  <!-- Basics -->
  <div class="card" x-show="tab==='basics'">
    <div class="card-body">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Agent name</label><input class="form-control" name="name" value="<?= e($o('name')) ?>" required></div>
        <div class="form-group"><label class="form-label">Business name</label><input class="form-control" name="business_name" value="<?= e($o('business_name')) ?>" placeholder="Shown in the assistant's introduction"></div>
      </div>
      <div class="form-group"><label class="form-label">Website</label><input class="form-control" name="website_url" value="<?= e($o('website_url')) ?>" placeholder="https://www.example.com"><div class="form-hint">Used for context. Scan it from the Knowledge page to teach the assistant.</div></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tags <span class="optional">(comma separated)</span></label><input class="form-control" name="tags" value="<?= e($o('tags')) ?>" placeholder="support, sales, main-site"><div class="form-hint">Organise your agents; tags are shown on the agents list.</div></div>
        <div class="form-group"><label class="form-label">Timezone</label><select class="form-select" name="timezone"><option value="">Platform default (<?= e(config('app.timezone', 'UTC')) ?>)</option><?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>" <?= $o('timezone') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select><div class="form-hint">Used for {{current_date}} and {{current_time}} in prompts, e.g. for opening hours.</div></div>
      </div>
      <div class="form-group"><label class="form-label">Internal description <span class="optional">(only you see this)</span></label><input class="form-control" name="description" value="<?= e($o('description')) ?>"></div>
    </div>
  </div>

  <!-- Prompt & behaviour -->
  <div class="card" x-show="tab==='prompt'" x-cloak>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">System prompt</label>
        <div class="segmented"><button type="button" :class="{active: promptMode==='guided'}" @click="promptMode='guided'">Guided (personality + instructions)</button><button type="button" :class="{active: promptMode==='custom'}" @click="promptMode='custom'">Custom system prompt</button></div>
        <input type="hidden" name="prompt_mode" :value="promptMode">
        <div class="form-hint">Either way, the assistant keeps the built-in safety rules: it answers only from your knowledge base, logs unanswered questions and captures leads.</div>
      </div>

      <div x-show="promptMode==='guided'">
        <div class="form-group">
          <label class="form-label">Personality</label>
          <div class="choice-grid">
            <?php foreach ($personas as $key => $p): ?>
              <label class="choice-card" :class="{on: persona==='<?= e($key) ?>'}"><input type="radio" name="persona" value="<?= e($key) ?>" x-model="persona"><span class="choice-check"></span><span class="choice-body"><span class="choice-title"><?= e($p['label']) ?></span><span class="choice-desc"><?= e($p['description']) ?></span></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Custom instructions</label>
          <textarea class="form-control" name="instructions" rows="6" x-ref="instructions" placeholder="Example: Always mention that shipping is free above 50 EUR. Never discuss competitor products. If someone asks for a quote, collect their email and project details."><?= e($o('instructions')) ?></textarea>
          <div class="var-chips"><span class="text-xs text-muted" style="align-self:center">Insert variable:</span><?php foreach ($variables as $var => $desc): ?><button type="button" title="<?= e($desc) ?>" @click="insertVar('instructions', '<?= e($var) ?>')">{{<?= e($var) ?>}}</button><?php endforeach; ?></div>
        </div>
      </div>

      <div x-show="promptMode==='custom'" x-cloak>
        <div class="form-group">
          <div class="flex items-center gap-2 mb-2 wrap">
            <label class="form-label" style="margin:0">Your system prompt</label>
            <div class="ml-auto flex gap-2 items-center wrap">
              <input class="form-control form-control-sm" style="width:260px" x-model="gen.brief" placeholder="Describe the business in one sentence (optional)">
              <button type="button" class="btn btn-secondary btn-sm" @click="generatePrompt()" :disabled="gen.loading"><span x-show="!gen.loading">&#10024; Generate with AI</span><span x-show="gen.loading">Writing...</span></button>
            </div>
          </div>
          <textarea class="form-control prompt-area" name="system_prompt" x-ref="system_prompt" x-model="systemPrompt" placeholder="You are {{agent_name}}, the assistant for {{business_name}}. You are friendly and enthusiastic and really want to help customers get the help they need. Answer in 3 to 6 sentences in most cases. Keep to the information on the website only."></textarea>
          <div class="var-chips"><span class="text-xs text-muted" style="align-self:center">Insert variable:</span><?php foreach ($variables as $var => $desc): ?><button type="button" title="<?= e($desc) ?>" @click="insertVar('system_prompt', '<?= e($var) ?>')">{{<?= e($var) ?>}}</button><?php endforeach; ?></div>
          <div class="form-hint">Write the whole persona and instructions yourself. Variables are replaced at runtime.</div>
        </div>
      </div>

      <div class="flex gap-2 items-center mb-4"><button type="button" class="btn btn-secondary btn-sm" @click="showPreview('text')">Preview final prompt</button><button type="button" class="btn btn-ghost btn-sm" @click="showPreview('voice')">Preview for voice</button><span class="text-xs text-muted">Shows exactly what the model receives, including the rules and a sample knowledge block.</span></div>

      <hr>
      <h4 class="mb-3">Reply behaviour per channel</h4>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Text chat replies</label><select class="form-select" name="response_length"><?php foreach ($responseLengths as $k => $l): ?><option value="<?= $k ?>" <?= $o('response_length', 'medium') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Voice replies (read aloud)</label><select class="form-select" name="voice_response_length"><?php foreach ($responseLengths as $k => $l): ?><option value="<?= $k ?>" <?= $o('voice_response_length', 'short') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><div class="form-hint">Spoken answers work best short; text answers can be longer.</div></div>
      </div>
    </div>
  </div>

  <!-- First message -->
  <div class="card" x-show="tab==='message'" x-cloak>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">First message</label>
        <textarea class="form-control" name="greeting_message" rows="3" x-ref="greeting_message"><?= e($o('greeting_message')) ?></textarea>
        <div class="var-chips"><span class="text-xs text-muted" style="align-self:center">Insert variable:</span><?php foreach (['agent_name', 'business_name', 'day_of_week', 'visitor_language'] as $var): ?><button type="button" @click="insertVar('greeting_message', '<?= e($var) ?>')">{{<?= e($var) ?>}}</button><?php endforeach; ?></div>
        <div class="form-hint">The first message the assistant says and shows when it opens. Leave it empty and the assistant waits for the visitor to start. Different languages can have their own first message under the Languages tab.</div>
      </div>
      <div class="form-group"><label class="switch"><input type="checkbox" name="interruptible" value="1" x-model="interruptible"><span class="track"></span><span class="switch-label">Interruptible</span></label><div class="form-hint">When on, visitors can simply start talking while the assistant is still speaking (or tap the microphone) and it stops at once to listen to the new question. When off, the assistant finishes speaking first.</div></div>
      <div class="form-group"><label class="form-label">Fallback message</label><textarea class="form-control" name="fallback_message" rows="3"><?= e($o('fallback_message')) ?></textarea><div class="form-hint">Shown when the AI service is unavailable.</div></div>
    </div>
  </div>

  <!-- Voice -->
  <div class="card" x-show="tab==='voice'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="switch"><input type="checkbox" name="voice_enabled" value="1" x-model="voice"><span class="track"></span><span class="switch-label">Enable voice conversations (microphone button and spoken replies)</span></label></div>
      <div x-show="voice">
        <?php if (!$premiumVoice): ?><div class="alert alert-info"><span>Premium neural voices and server-side transcription are included in paid plans. Your plan currently uses the free browser voice. <a href="<?= e(url('/billing')) ?>">Upgrade</a></span></div><?php endif; ?>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Voice engine</label>
            <select class="form-select" name="tts_provider" x-model="tts">
              <option value="auto">Automatic (best available)</option>
              <option value="openai" <?= $hasOpenAI ? '' : 'disabled' ?>>OpenAI neural voices<?= $hasOpenAI ? '' : ' - not configured' ?></option>
              <option value="elevenlabs" <?= $hasElevenLabs ? '' : 'disabled' ?>>ElevenLabs voices<?= $hasElevenLabs ? '' : ' - not configured' ?></option>
              <option value="fishaudio" <?= $hasFishAudio ? '' : 'disabled' ?>>Fish Audio voices<?= $hasFishAudio ? '' : ' - not configured' ?></option>
              <option value="browser">Browser voice (free, device dependent)</option>
            </select>
          </div>
          <div class="form-group" x-show="tts !== 'browser'"><label class="form-label">Primary voice</label>
            <div class="flex gap-2">
              <select class="form-select" name="tts_voice" x-ref="tts_voice" x-model="ttsVoice">
                <optgroup label="OpenAI neural voices" x-show="engine() !== 'elevenlabs'"><?php foreach ($voices['openai'] as $vid => $label): ?><option value="<?= e($vid) ?>"><?= e($label) ?></option><?php endforeach; ?></optgroup>
                <optgroup label="ElevenLabs voices" x-show="engine() === 'elevenlabs'"><?php foreach ($voices['elevenlabs'] as $vid => $label): ?><option value="<?= e($vid) ?>"><?= e($label) ?></option><?php endforeach; ?></optgroup>
              </select>
              <button type="button" class="btn btn-secondary" title="Play a sample" @click="playVoice(ttsVoice)" :disabled="playing"><span x-show="!playing">&#9654; Play</span><span x-show="playing">&#9632; Stop</span></button>
            </div>
            <input class="form-control mt-2" x-show="engine() === 'elevenlabs'" placeholder="Custom ElevenLabs voice ID (optional)" @input="if ($event.target.value.trim()) ttsVoice = $event.target.value.trim()">
            <div x-show="engine() === 'fishaudio'" x-cloak><input class="form-control" placeholder="Fish Audio reference ID (leave empty for the default voice)" x-model="ttsVoice"><div class="form-hint">Open a voice on <a href="https://fish.audio" target="_blank" rel="noopener">fish.audio</a> and copy the id from its address, or paste the id of a voice you cloned yourself.</div></div>
          </div>
        </div>

        <div x-show="engine() === 'elevenlabs'" x-cloak>
          <div class="form-group"><label class="form-label">ElevenLabs model</label><select class="form-select" name="elevenlabs_model"><option value="">Platform default</option><?php foreach ($elevenModels as $mid => $label): ?><option value="<?= e($mid) ?>" <?= ($vs['elevenlabs_model'] ?? '') === $mid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><div class="form-hint">Expressive (v3) adds emotionally intelligent speech and natural intonation at slightly higher latency.</div></div>
          <div class="slider-row"><span>Stability</span><input type="range" min="0" max="1" step="0.05" name="stability" x-model.number="stability"><span x-text="stability"></span></div>
          <div class="slider-row"><span>Similarity</span><input type="range" min="0" max="1" step="0.05" name="similarity" x-model.number="similarity"><span x-text="similarity"></span></div>
          <div class="slider-row"><span>Style</span><input type="range" min="0" max="1" step="0.05" name="style" x-model.number="style"><span x-text="style"></span></div>
          <div class="form-hint mb-3">Lower stability sounds more expressive, higher is more consistent. Style exaggeration makes the delivery more dramatic.</div>
        </div>
        <div x-show="engine() === 'fishaudio'" x-cloak>
          <div class="form-group"><label class="form-label">Fish Audio model</label><select class="form-select" name="fishaudio_model"><option value="">Platform default</option><?php foreach (\App\Services\AI\Speech::FISHAUDIO_MODELS as $mid => $label): ?><option value="<?= e($mid) ?>" <?= ($vs['fishaudio_model'] ?? '') === $mid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><div class="form-hint">S1 is the most expressive. Speech 1.6 is faster and cheaper.</div></div>
        </div>
        <div x-show="engine() === 'openai'" x-cloak>
          <div class="form-group"><label class="form-label">Speaking style instructions</label><input class="form-control" name="openai_instructions" value="<?= e($vs['openai_instructions'] ?? '') ?>" x-model="openaiInstructions" placeholder="Warm and calm, like a friendly receptionist. Slight smile in the voice."><div class="form-hint">Tell the voice how to sound: tone, emotion, pacing, accent.</div></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Speaking speed: <span x-text="speed + 'x'"></span></label><input type="range" name="tts_speed" min="0.7" max="1.4" step="0.05" x-model.number="speed"></div>
          <div class="form-group"><label class="form-label">Speech recognition</label><select class="form-select" name="stt_provider"><option value="auto" <?= $o('stt_provider') === 'auto' ? 'selected' : '' ?>>Automatic (server transcription when available)</option><option value="browser" <?= $o('stt_provider') === 'browser' ? 'selected' : '' ?>>Browser only (free)</option></select><div class="form-hint">Server transcription works in every browser and supports 50+ languages.</div></div>
        </div>
        <div class="form-group"><label class="form-label">Read replies aloud</label>
          <select class="form-select" name="speak_replies">
            <?php $speak = (string) ($agent['speak_replies'] ?? ((int) $agent['auto_speak'] ? 'voice' : 'never')); ?>
            <option value="voice" <?= $speak === 'voice' ? 'selected' : '' ?>>When the visitor talks or uses voice mode (recommended)</option>
            <option value="always" <?= $speak === 'always' ? 'selected' : '' ?>>Always, also for typed questions</option>
            <option value="never" <?= $speak === 'never' ? 'selected' : '' ?>>Never (visitors can still tap the speaker icon on a reply)</option>
          </select>
          <div class="form-hint">Every reply also gets a small speaker button so visitors can listen on demand. The greeting is spoken when the widget opens in voice mode.</div></div>
      </div>
    </div>
  </div>

  <!-- Languages -->
  <div class="card" x-show="tab==='languages'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Default language</label><select class="form-select" name="language" x-model="defaultLang"><?php foreach ($languages as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select><div class="form-hint">"Auto-detect" answers in whatever language the visitor uses. A fixed default keeps replies in that language unless the visitor uses one of the additional languages below.</div></div>
      <div x-show="defaultLang !== 'auto'">
        <label class="form-label">Additional languages</label>
        <template x-for="(l, i) in extraLangs" :key="l.code">
          <div class="lang-row">
            <input type="hidden" name="additional_languages[]" :value="l.code">
            <div class="flex items-center gap-2 mb-2"><strong x-text="l.name"></strong><button type="button" class="btn btn-ghost btn-sm ml-auto" @click="extraLangs.splice(i, 1)">Remove</button></div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">First message in this language <span class="optional">(optional)</span></label><textarea class="form-control" rows="2" :name="'lang_greeting[' + l.code + ']'" x-model="l.greeting" placeholder="Leave empty to use the default first message"></textarea></div>
              <div class="form-group" x-show="voice && tts !== 'browser'"><label class="form-label">Voice for this language <span class="optional">(optional)</span></label>
                <select class="form-select" :name="'lang_voice[' + l.code + ']'" x-model="l.voice">
                  <option value="">Same as primary voice</option>
                  <optgroup label="OpenAI" x-show="engine() !== 'elevenlabs'"><?php foreach ($voices['openai'] as $vid => $label): ?><option value="<?= e($vid) ?>"><?= e($label) ?></option><?php endforeach; ?></optgroup>
                  <optgroup label="ElevenLabs" x-show="engine() === 'elevenlabs'"><?php foreach ($voices['elevenlabs'] as $vid => $label): ?><option value="<?= e($vid) ?>"><?= e($label) ?></option><?php endforeach; ?></optgroup>
                </select>
              </div>
            </div>
          </div>
        </template>
        <div class="flex gap-2 items-center">
          <select class="form-select" style="max-width:280px" x-model="addLang"><option value="">Add a language...</option><?php foreach ($languages as $code => $label): if ($code === 'auto') continue; ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
          <button type="button" class="btn btn-secondary btn-sm" @click="addLanguage()" :disabled="!addLang">+ Add language</button>
        </div>
      </div>
    </div>
  </div>

  <!-- AI model -->
  <div class="card" x-show="tab==='model'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Provider and model</label>
        <select class="form-select" name="llm_choice">
          <option value="">Platform default (<?= e($providerLabels[$platformProvider] ?? $platformProvider) ?>)</option>
          <?php foreach ($modelGroups as $prov => $models): ?><optgroup label="<?= e($providerLabels[$prov] ?? $prov) ?>"><?php foreach ($models as $mid => $label): $val = $prov . '|' . $mid; ?><option value="<?= e($val) ?>" <?= (($agent['llm_provider'] ?? '') === $prov && ($agent['llm_model'] ?? '') === $mid) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
        </select>
        <div class="form-hint">Only providers configured by the platform administrator are listed. Claude Opus 5 gives the most accurate, natural answers; smaller models are faster and cheaper.</div>
      </div>
      <div class="form-group"><label class="form-label">Response speed</label><select class="form-select" name="effort"><?php foreach (['low' => 'Fast - no extended thinking (recommended for voice)', 'medium' => 'Balanced - brief thinking before answering', 'high' => 'Thorough - longer thinking, slowest'] as $k => $l): ?><option value="<?= $k ?>" <?= $o('effort') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><div class="form-hint">Fast answers start within about a second on Claude Sonnet 5 or Haiku 4.5. Opus 5 is the most capable but noticeably slower for spoken conversations.</div></div>
    </div>
  </div>

  <!-- Lead capture -->
  <div class="card" x-show="tab==='leads'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="switch"><input type="checkbox" name="lead_capture_enabled" value="1" x-model="lead"><span class="track"></span><span class="switch-label">Collect contact details from interested visitors</span></label><div class="form-hint">When a visitor asks to be contacted, wants a quote or asks something the assistant cannot answer, it naturally asks for their details and saves them as a lead.</div></div>
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

  <!-- Security -->
  <div class="card" x-show="tab==='security'" x-cloak>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Allowed websites</label><textarea class="form-control" name="allowed_domains" rows="3" placeholder="example.com&#10;shop.example.com"><?= e($o('allowed_domains')) ?></textarea><div class="form-hint">One domain per line. When set, the widget only works on these domains (subdomains included). Leave empty to allow any website.</div></div>
      <div class="kv"><dt>Agent ID</dt><dd class="mono"><?= e($agent['public_id']) ?></dd><dt>Created</dt><dd><?= e(format_date($agent['created_at'], 'M j, Y H:i')) ?></dd></div>
    </div>
  </div>

  <div class="flex justify-between items-center mt-6" style="position:sticky;bottom:0;background:var(--bg);padding:14px 0;z-index:5">
    <a class="btn btn-ghost" href="<?= e(url('/agents/' . $agent['id'])) ?>">Back to overview</a>
    <button class="btn btn-primary btn-lg" type="submit">Save changes</button>
  </div>

  <!-- Prompt preview modal -->
  <div class="modal-backdrop" x-show="preview.open" x-cloak @click.self="preview.open=false">
    <div class="modal modal-lg">
      <div class="modal-header"><h3>Final system prompt <span class="text-muted text-sm" x-text="'(' + preview.modality + ' channel)'"></span></h3><button type="button" class="btn btn-icon btn-ghost close" @click="preview.open=false">&times;</button></div>
      <div class="modal-body"><pre style="white-space:pre-wrap;max-height:60vh;font-size:12.5px" x-text="preview.loading ? 'Building preview...' : preview.text"></pre></div>
    </div>
  </div>
</form>

<?php \App\Core\View::start('scripts'); ?>
<script>
function agentSettings() {
  return {
    tab: (location.hash || '').replace('#', '') || 'basics',
    voice: <?= (int) $agent['voice_enabled'] ? 'true' : 'false' ?>,
    tts: '<?= e($ttsInitial) ?>',
    ttsVoice: <?= json_encode((string) $agent['tts_voice']) ?>,
    speed: <?= json_encode((float) ($agent['tts_speed'] ?: 1)) ?>,
    stability: <?= json_encode((float) ($vs['stability'] ?? 0.5)) ?>,
    similarity: <?= json_encode((float) ($vs['similarity'] ?? 0.75)) ?>,
    style: <?= json_encode((float) ($vs['style'] ?? 0)) ?>,
    openaiInstructions: <?= json_encode((string) ($vs['openai_instructions'] ?? '')) ?>,
    lead: <?= (int) $agent['lead_capture_enabled'] ? 'true' : 'false' ?>,
    persona: <?= json_encode((string) $agent['persona']) ?>,
    promptMode: <?= json_encode((string) ($agent['prompt_mode'] ?? 'guided')) ?>,
    systemPrompt: <?= json_encode((string) ($agent['system_prompt'] ?? '')) ?>,
    interruptible: <?= (int) ($agent['interruptible'] ?? 1) ? 'true' : 'false' ?>,
    defaultLang: <?= json_encode((string) $agent['language']) ?>,
    extraLangs: <?= $extraJson ?>,
    addLang: '',
    langNames: <?= json_encode(\App\Core\App::languages(), JSON_UNESCAPED_UNICODE) ?>,
    hasElevenLabs: <?= $hasElevenLabs ? 'true' : 'false' ?>,
    hasOpenAI: <?= $hasOpenAI ? 'true' : 'false' ?>,
    preview: { open: false, text: '', loading: false, modality: 'text' },
    gen: { brief: '', loading: false },
    playing: false, audio: null,
    init() { this.$nextTick(() => { this.$refs.tts_voice && (this.$refs.tts_voice.value = this.ttsVoice); }); },
    engine() { if (this.tts === 'elevenlabs') return 'elevenlabs'; if (this.tts === 'fishaudio') return 'fishaudio'; if (this.tts === 'openai') return 'openai'; if (this.tts === 'browser') return 'browser'; return this.hasOpenAI ? 'openai' : (this.hasElevenLabs ? 'elevenlabs' : 'browser'); },
    insertVar(ref, name) {
      const el = this.$refs[ref]; if (!el) return;
      const token = '{{' + name + '}}'; const start = el.selectionStart || el.value.length; const end = el.selectionEnd || start;
      el.value = el.value.slice(0, start) + token + el.value.slice(end);
      el.dispatchEvent(new Event('input')); el.focus(); el.selectionStart = el.selectionEnd = start + token.length;
    },
    addLanguage() {
      const code = this.addLang; if (!code || code === this.defaultLang || this.extraLangs.some(l => l.code === code)) { this.addLang = ''; return; }
      this.extraLangs.push({ code: code, name: this.langNames[code] || code, greeting: '', voice: '' }); this.addLang = '';
    },
    formData() { const fd = new FormData(this.$el); fd.set('prompt_mode', this.promptMode); return fd; },
    async showPreview(modality) {
      this.preview = { open: true, text: '', loading: true, modality: modality };
      const fd = this.formData(); fd.set('modality', modality);
      try { const r = await VA.api('/agents/<?= (int) $agent['id'] ?>/prompt-preview', { body: fd }); this.preview.text = r.prompt; }
      catch (e) { this.preview.text = 'Could not build the preview: ' + e.message; }
      this.preview.loading = false;
    },
    async generatePrompt() {
      this.gen.loading = true;
      const fd = new FormData(); fd.append('brief', this.gen.brief); ['name', 'business_name', 'website_url', 'persona'].forEach(k => { const el = this.$el.querySelector('[name=' + k + ']:checked, [name=' + k + ']'); if (el) fd.append(k, el.value); });
      try { const r = await VA.api('/agents/<?= (int) $agent['id'] ?>/generate-prompt', { body: fd }); this.systemPrompt = r.prompt; this.promptMode = 'custom'; VA.toast('Prompt drafted. Review and adjust it, then save.', 'success'); }
      catch (e) { VA.toast(e.message, 'error'); }
      this.gen.loading = false;
    },
    async playVoice(voice) {
      if (this.playing && this.audio) { this.audio.pause(); this.playing = false; return; }
      const engine = this.engine();
      if (engine === 'browser') { VA.toast('Browser voices are previewed on your website; pick a neural voice to hear a sample here.', 'error'); return; }
      this.playing = true;
      try {
        const res = await fetch(VA.base + '/agents/<?= (int) $agent['id'] ?>/voice-preview', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': VA.csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ provider: engine, voice: voice, speed: this.speed, elevenlabs_model: (this.$el.querySelector('[name=elevenlabs_model]') || {}).value || '', fishaudio_model: (this.$el.querySelector('[name=fishaudio_model]') || {}).value || '', stability: this.stability, similarity: this.similarity, style: this.style, openai_instructions: this.openaiInstructions }) });
        if (!res.ok) { const d = await res.json().catch(() => ({})); throw new Error(d.error || 'Preview failed'); }
        const blob = await res.blob(); const url = URL.createObjectURL(blob);
        this.audio = new Audio(url); this.audio.onended = () => { this.playing = false; URL.revokeObjectURL(url); }; this.audio.onerror = () => { this.playing = false; };
        await this.audio.play();
      } catch (e) { this.playing = false; VA.toast(e.message, 'error'); }
    }
  };
}
</script>
<?php \App\Core\View::stop(); ?>
