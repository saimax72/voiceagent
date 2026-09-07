<?php if ($onboarding): ?><?= \App\Core\View::partial('partials/onboarding_steps', ['current' => 3, 'nextUrl' => url('/agents/' . $agent['id'] . '/test?onboarding=1'), 'nextLabel' => 'Continue to test']) ?><?php endif; ?>
<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'customize']) ?>
<style>
  .cz-layout { display: grid; grid-template-columns: 420px minmax(0, 1fr); gap: 20px; align-items: start; }
  .cz-panel { max-height: calc(100vh - 140px); overflow-y: auto; }
  .cz-preview { position: sticky; top: 84px; }
  .cz-preview iframe { width: 100%; height: calc(100vh - 170px); min-height: 620px; border: 0; border-radius: 12px; background: #f8fafc; }
  .cz-section { padding: 16px 20px; border-bottom: 1px solid var(--border); }
  .cz-section h4 { margin-bottom: 12px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); }
  .icon-pick { display: flex; gap: 8px; flex-wrap: wrap; }
  .icon-pick label { width: 46px; height: 46px; border: 1px solid var(--border); border-radius: 6px; display: grid; place-items: center; cursor: pointer; color: var(--text-2); }
  .icon-pick label.on { border-color: var(--primary); background: var(--primary-50); color: var(--primary-700); }
  .icon-pick svg { width: 22px; height: 22px; }
  .icon-pick input { display: none; }
  .thumb { width: 46px; height: 46px; border-radius: 6px; object-fit: cover; border: 1px solid var(--border); background: #fff; }
  @media (max-width: 1000px) { .cz-layout { grid-template-columns: 1fr; } .cz-panel { max-height: none; } .cz-preview { position: static; } .cz-preview iframe { height: 640px; } }
</style>
<div class="cz-layout" x-data="customizer(<?= e(json_encode($config)) ?>)" x-init="init()">
  <div class="card cz-panel">
    <div class="card-header" style="position:sticky;top:0;background:var(--surface);z-index:2">
      <div><h3>Widget design</h3><div class="sub">Changes preview instantly</div></div>
      <div class="actions"><span class="text-xs text-muted" x-text="status"></span><button class="btn btn-primary btn-sm" @click="save()" :disabled="saving">Save</button></div>
    </div>
    <div class="tabs" style="padding:0 20px;margin-bottom:0">
      <button type="button" :class="{active: tab==='launcher'}" @click="tab='launcher'">Launcher</button>
      <button type="button" :class="{active: tab==='colors'}" @click="tab='colors'">Colours</button>
      <button type="button" :class="{active: tab==='texts'}" @click="tab='texts'">Texts</button>
      <button type="button" :class="{active: tab==='behavior'}" @click="tab='behavior'">Behaviour</button>
    </div>

    <div x-show="tab==='launcher'">
      <div class="cz-section"><h4>Floating icon</h4>
        <div class="icon-pick mb-3">
          <?php foreach (['chat' => '<path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.5-4.5A8 8 0 1 1 21 12z"/>', 'mic' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8 21h8"/>', 'sparkle' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>', 'bot' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4m-4 4h8M9 14h.01M15 14h.01"/>'] as $k => $svg): ?>
            <label :class="{on: c.launcher_icon==='<?= $k ?>'}" title="<?= $k ?>"><input type="radio" value="<?= $k ?>" x-model="c.launcher_icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $svg ?></svg></label>
          <?php endforeach; ?>
          <label :class="{on: c.launcher_icon==='custom'}" title="Your own image" @click="c.launcher_icon='custom'"><template x-if="c.launcher_image"><img class="thumb" :src="imgUrl(c.launcher_image)" alt=""></template><template x-if="!c.launcher_image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg></template></label>
        </div>
        <div x-show="c.launcher_icon==='custom'" class="mb-3"><label class="btn btn-secondary btn-sm">Upload icon or logo <input type="file" accept="image/*" hidden @change="upload($event, 'launcher_image')"></label> <button class="btn btn-ghost btn-sm" x-show="c.launcher_image" @click="c.launcher_image=''">Remove</button><div class="form-hint">PNG, JPG, SVG or WebP up to 2 MB. Square images look best.</div></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Size: <span x-text="c.launcher_size + 'px'"></span></label><input type="range" min="44" max="90" x-model.number="c.launcher_size"></div>
          <div class="form-group"><label class="form-label">Shape</label><select class="form-select" x-model="c.launcher_shape"><option value="circle">Circle</option><option value="rounded">Rounded</option><option value="square">Square</option></select></div>
        </div>
        <div class="form-group"><label class="form-label">Label next to the icon <span class="optional">(optional)</span></label><input class="form-control" x-model="c.launcher_label" placeholder="Ask me anything"></div>
        <label class="switch"><input type="checkbox" x-model="c.launcher_pulse"><span class="track"></span><span class="switch-label">Pulse animation to attract attention</span></label>
      </div>
      <div class="cz-section"><h4>Position</h4>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Side</label><div class="segmented"><button type="button" :class="{active: c.position==='left'}" @click="c.position='left'">Left</button><button type="button" :class="{active: c.position==='right'}" @click="c.position='right'">Right</button></div></div>
          <div class="form-group"><label class="form-label">Distance from edge: <span x-text="c.offset_x + ' / ' + c.offset_y + 'px'"></span></label><input type="range" min="0" max="120" x-model.number="c.offset_x"><input type="range" min="0" max="120" x-model.number="c.offset_y"></div>
        </div>
      </div>
      <div class="cz-section"><h4>Popup window</h4>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Width: <span x-text="c.popup_width + 'px'"></span></label><input type="range" min="320" max="560" step="10" x-model.number="c.popup_width"></div>
          <div class="form-group"><label class="form-label">Height: <span x-text="c.popup_height + 'px'"></span></label><input type="range" min="420" max="860" step="10" x-model.number="c.popup_height"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Corner radius: <span x-text="c.border_radius + 'px'"></span></label><input type="range" min="0" max="40" x-model.number="c.border_radius"></div>
          <div class="form-group"><label class="form-label">Font</label><select class="form-select" x-model="c.font"><?php foreach ($fonts as $f): ?><option value="<?= e($f) ?>"><?= e($f) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="cz-section"><h4>Agent avatar</h4>
        <div class="form-group"><div class="segmented"><button type="button" :class="{active: c.avatar_style==='initials'}" @click="c.avatar_style='initials'">Initials</button><button type="button" :class="{active: c.avatar_style==='icon'}" @click="c.avatar_style='icon'">Robot icon</button><button type="button" :class="{active: c.avatar_style==='image'}" @click="c.avatar_style='image'">Photo / logo</button></div></div>
        <div x-show="c.avatar_style==='image'" class="flex items-center gap-3"><template x-if="c.avatar_image"><img class="thumb" :src="imgUrl(c.avatar_image)" alt=""></template><label class="btn btn-secondary btn-sm">Upload avatar <input type="file" accept="image/*" hidden @change="upload($event, 'avatar_image')"></label><button class="btn btn-ghost btn-sm" x-show="c.avatar_image" @click="c.avatar_image=''">Remove</button></div>
      </div>
    </div>

    <div x-show="tab==='colors'" x-cloak>
      <div class="cz-section"><h4>Quick themes</h4>
        <div class="flex gap-2 wrap">
          <template x-for="p in presets"><button type="button" class="btn btn-secondary btn-sm" @click="applyPreset(p)"><span style="width:14px;height:14px;border-radius:4px;display:inline-block" :style="'background:' + p.primary"></span><span x-text="p.name"></span></button></template>
        </div>
        <div class="form-group mt-3"><label class="form-label">Theme</label><div class="segmented"><button type="button" :class="{active: c.theme==='light'}" @click="setTheme('light')">Light</button><button type="button" :class="{active: c.theme==='dark'}" @click="setTheme('dark')">Dark</button></div></div>
      </div>
      <div class="cz-section"><h4>Colours</h4>
        <?php foreach (['primary_color' => 'Primary / accent', 'header_bg' => 'Header background', 'header_text' => 'Header text', 'bg_color' => 'Popup background', 'text_color' => 'Text colour', 'bot_bubble_bg' => 'Assistant bubble', 'bot_bubble_text' => 'Assistant bubble text', 'user_bubble_bg' => 'Visitor bubble', 'user_bubble_text' => 'Visitor bubble text', 'button_color' => 'Buttons & launcher', 'button_text_color' => 'Button text / icon'] as $key => $label): ?>
          <div class="form-group flex items-center justify-between"><label class="form-label" style="margin:0"><?= e($label) ?></label><div class="color-field"><input type="color" x-model="c.<?= $key ?>"><input class="form-control form-control-sm" x-model="c.<?= $key ?>" maxlength="7"></div></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div x-show="tab==='texts'" x-cloak>
      <div class="cz-section"><h4>Header</h4>
        <div class="form-group"><label class="form-label">Header title</label><input class="form-control" x-model="c.header_title"></div>
        <div class="form-group"><label class="form-label">Header subtitle</label><input class="form-control" x-model="c.header_subtitle"></div>
      </div>
      <div class="cz-section"><h4>Welcome screen</h4>
        <div class="form-group"><label class="form-label">Greeting headline</label><input class="form-control" x-model="c.greeting_text"></div>
        <div class="form-group"><label class="form-label">Welcome message</label><textarea class="form-control" rows="2" x-model="c.welcome_message"></textarea></div>
        <div class="form-group"><label class="form-label">"Ask me anything" label</label><input class="form-control" x-model="c.ask_me_text"></div>
        <div class="form-group"><label class="form-label">Suggested questions (one per line)</label><textarea class="form-control" rows="4" x-model="questionsText" @input="c.suggested_questions = questionsText.split('\n').map(s => s.trim()).filter(Boolean)"></textarea></div>
        <div class="form-hint">The spoken/written greeting message itself is set under Personality &amp; voice &rarr; Messages.</div>
      </div>
      <div class="cz-section"><h4>Input &amp; voice</h4>
        <div class="form-group"><label class="form-label">Input placeholder</label><input class="form-control" x-model="c.input_placeholder"></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Microphone text</label><input class="form-control" x-model="c.mic_text"></div>
          <div class="form-group"><label class="form-label">Listening text</label><input class="form-control" x-model="c.listening_text"></div>
          <div class="form-group"><label class="form-label">Thinking text</label><input class="form-control" x-model="c.thinking_text"></div>
          <div class="form-group"><label class="form-label">Speaking text</label><input class="form-control" x-model="c.speaking_text"></div>
        </div>
        <div class="form-group"><label class="form-label">Contact form title</label><input class="form-control" x-model="c.lead_form_title"></div>
      </div>
    </div>

    <div x-show="tab==='behavior'" x-cloak>
      <div class="cz-section"><h4>Behaviour</h4>
        <div class="form-group"><label class="switch"><input type="checkbox" x-model="c.voice_mode_default"><span class="track"></span><span class="switch-label">Open in voice mode by default</span></label></div>
        <div class="form-group"><label class="switch"><input type="checkbox" x-model="c.sound_effects"><span class="track"></span><span class="switch-label">Subtle sound effects when listening starts</span></label></div>
        <div class="form-group"><label class="switch"><input type="checkbox" x-model="c.show_lead_form"><span class="track"></span><span class="switch-label">Allow visitors to open the contact form</span></label></div>
        <div class="form-group"><label class="switch"><input type="checkbox" x-model="c.auto_open"><span class="track"></span><span class="switch-label">Open automatically once per visit</span></label>
          <div x-show="c.auto_open" class="mt-2"><label class="form-label">After <span x-text="c.auto_open_delay"></span> seconds</label><input type="range" min="1" max="60" x-model.number="c.auto_open_delay"></div></div>
        <div class="form-group"><label class="switch"><input type="checkbox" x-model="c.show_branding" <?= $canRemoveBranding ? '' : 'disabled' ?>><span class="track"></span><span class="switch-label">Show "Powered by" branding<?= $canRemoveBranding ? '' : ' (upgrade to remove)' ?></span></label></div>
      </div>
      <div class="cz-section">
        <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/customize/reset')) ?>" data-confirm="Reset the design to defaults?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Reset to defaults</button></form>
      </div>
    </div>
  </div>

  <div class="cz-preview">
    <div class="card" style="overflow:hidden;padding:8px"><iframe x-ref="frame" src="<?= e($previewUrl) ?>" title="Live preview" allow="microphone; autoplay"></iframe></div>
    <p class="text-xs text-muted mt-2 text-center">Live preview. The assistant behaves exactly as on your website.</p>
  </div>
</div>

<?php \App\Core\View::start('scripts'); ?>
<script>
function customizer(initial) {
  return {
    c: initial, tab: 'launcher', saving: false, status: '', questionsText: (initial.suggested_questions || []).join('\n'), ready: false,
    presets: [
      { name: 'Brand', primary: '#0052fc', header: '#141b25' }, { name: 'Ocean', primary: '#0ea5e9', header: '#0369a1' }, { name: 'Emerald', primary: '#10b981', header: '#047857' },
      { name: 'Sunset', primary: '#f97316', header: '#ea580c' }, { name: 'Rose', primary: '#f43f5e', header: '#be123c' }, { name: 'Indigo', primary: '#5b5bd6', header: '#312e81' }, { name: 'Graphite', primary: '#334155', header: '#1e293b' }
    ],
    init() {
      var self = this;
      window.addEventListener('message', function (ev) { if (ev.data && ev.data.type === 'va:ready') { self.ready = true; self.push(); setTimeout(function () { self.$refs.frame.contentWindow.postMessage({ type: 'va:open' }, '*'); }, 300); } });
      this.$watch('c', VA.debounce(function () { self.push(); self.status = 'Unsaved changes'; }, 120));
    },
    push() { try { this.$refs.frame.contentWindow.postMessage({ type: 'va:config', config: JSON.parse(JSON.stringify(this.c)) }, '*'); } catch (e) { /* ignore */ } },
    imgUrl(p) { return p && p.indexOf('http') === 0 ? p : VA.base + '/' + p; },
    setTheme(t) {
      this.c.theme = t;
      if (t === 'dark') { this.c.bg_color = '#0f172a'; this.c.text_color = '#e2e8f0'; this.c.bot_bubble_bg = '#1e293b'; this.c.bot_bubble_text = '#e2e8f0'; }
      else { this.c.bg_color = '#ffffff'; this.c.text_color = '#0f172a'; this.c.bot_bubble_bg = '#f1f5f9'; this.c.bot_bubble_text = '#0f172a'; }
    },
    applyPreset(p) { this.c.primary_color = p.primary; this.c.header_bg = p.header; this.c.user_bubble_bg = p.primary; this.c.button_color = p.primary; this.c.header_text = '#ffffff'; this.c.button_text_color = '#ffffff'; this.c.user_bubble_text = '#ffffff'; },
    async upload(ev, key) {
      var file = ev.target.files[0]; if (!file) return;
      var fd = new FormData(); fd.append('image', file);
      try { var res = await VA.api('/agents/<?= (int) $agent['id'] ?>/customize/upload', { body: fd }); this.c[key] = res.path; if (key === 'launcher_image') { this.c.launcher_icon = 'custom'; } if (key === 'avatar_image') { this.c.avatar_style = 'image'; } VA.toast('Image uploaded', 'success'); }
      catch (e) { VA.toast(e.message, 'error'); }
      ev.target.value = '';
    },
    async save() {
      this.saving = true;
      try { var res = await VA.api('/agents/<?= (int) $agent['id'] ?>/customize', { body: { config: this.c } }); this.c = Object.assign(this.c, res.config); this.status = 'Saved'; VA.toast('Design saved', 'success'); }
      catch (e) { VA.toast(e.message, 'error'); }
      this.saving = false;
    }
  };
}
</script>
<?php \App\Core\View::stop(); ?>
