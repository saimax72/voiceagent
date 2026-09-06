<?= \App\Core\View::partial('partials/admin_nav', ['active' => 'settings']) ?>
<?php
$v = static fn(string $k, string $d = '') => e((string) ($values[$k] ?? $d));
$secret = static function (string $k, string $label, string $hint = '') use ($values): string {
    $has = (string) ($values[$k] ?? '') !== '';
    return '<div class="form-group"><label class="form-label">' . e($label) . ($has ? ' <span class="badge badge-success" style="margin-left:6px">saved</span>' : '') . '</label><input class="form-control" type="password" name="' . e($k) . '" autocomplete="new-password" placeholder="' . ($has ? 'Leave blank to keep the saved key' : 'Paste key') . '">'
        . ($has ? '<label class="checkbox mt-2 text-sm"><input type="checkbox" name="clear_' . e($k) . '" value="1"> <span>Remove saved key</span></label>' : '')
        . ($hint !== '' ? '<div class="form-hint">' . $hint . '</div>' : '') . '</div>';
};
$testBtn = static fn(string $p, string $label) => '<button type="button" class="btn btn-secondary btn-sm" onclick="testProvider(this, \'' . $p . '\')">' . e($label) . '</button> <span class="text-sm" data-result="' . $p . '"></span>';
?>
<div class="tabs">
  <?php foreach (['general' => 'General', 'ai' => 'AI providers', 'voice' => 'Voice', 'documents' => 'Documents & crawling', 'billing' => 'Billing (Stripe)', 'mail' => 'Email'] as $k => $l): ?><a href="<?= e(url('/admin/settings?tab=' . $k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= $l ?></a><?php endforeach; ?>
</div>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="card max-w-lg"><?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
<div class="card-body">
<?php if ($tab === 'general'): ?>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Platform name</label><input class="form-control" name="app_name" value="<?= $v('app_name') ?>"></div>
    <div class="form-group"><label class="form-label">Support email</label><input class="form-control" type="email" name="support_email" value="<?= $v('support_email') ?>"></div>
  </div>
  <div class="form-group"><label class="switch"><input type="checkbox" name="registration_enabled" value="1" <?= ($values['registration_enabled'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Allow new sign-ups</span></label></div>
  <div class="form-group"><label class="switch"><input type="checkbox" name="lead_notifications" value="1" <?= ($values['lead_notifications'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Send lead notification emails</span></label></div>
  <div class="form-group"><label class="switch"><input type="checkbox" name="email_verification_notice" value="1" <?= ($values['email_verification_notice'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Show "verify your email" banner to unverified users</span></label></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Default plan for new sign-ups</label><select class="form-select" name="default_plan"><?php foreach ($plans as $p): ?><option value="<?= e($p['plan_key']) ?>" <?= ($values['default_plan'] ?? 'free') === $p['plan_key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Trial plan</label><select class="form-select" name="trial_plan"><option value="">No trial</option><?php foreach ($plans as $p): ?><option value="<?= e($p['plan_key']) ?>" <?= ($values['trial_plan'] ?? '') === $p['plan_key'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Trial length (days)</label><input class="form-control" type="number" name="trial_days" value="<?= $v('trial_days', '14') ?>"></div>
    <div class="form-group"><label class="form-label">Widget branding text</label><input class="form-control" name="widget_branding_text" value="<?= $v('widget_branding_text') ?>"></div>
  </div>
  <div class="form-group"><label class="form-label">Demo agent ID for the landing page <span class="optional">(optional)</span></label><input class="form-control mono" name="demo_agent_public_id" value="<?= $v('demo_agent_public_id') ?>" placeholder="32-character agent ID"><div class="form-hint">Shows that agent's widget on the public home page so visitors can try it.</div></div>
  <div class="form-group"><label class="form-label">Cron / worker</label><div class="text-sm"><?= $workerAlive ? '<span class="badge badge-success">Worker running</span>' : '<span class="badge badge-warning">No worker detected</span>' ?></div><div class="form-hint">Add a cron job every minute: <code>php <?= e(APP_ROOT) ?>/cron/worker.php</code><br>Or call this URL every minute: <code><?= e($cronUrl) ?></code></div></div>

<?php elseif ($tab === 'ai'): ?>
  <div class="form-group"><label class="form-label">Chat model provider</label>
    <select class="form-select" name="llm_provider"><option value="anthropic" <?= ($values['llm_provider'] ?? '') === 'anthropic' ? 'selected' : '' ?>>Anthropic Claude (recommended)</option><option value="openai" <?= ($values['llm_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option><option value="compatible" <?= ($values['llm_provider'] ?? '') === 'compatible' ? 'selected' : '' ?>>OpenAI-compatible endpoint (Groq, OpenRouter, Ollama, ...)</option></select></div>
  <div class="card" style="box-shadow:none;margin-bottom:16px"><div class="card-body">
    <h4 class="mb-3">Anthropic</h4>
    <?= $secret('anthropic_api_key', 'Anthropic API key', 'Get one at console.anthropic.com. Used for Claude responses and AI document reading.') ?>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Default model</label><select class="form-select" name="anthropic_model"><?php foreach ($anthropicModels as $id => $label): ?><option value="<?= e($id) ?>" <?= ($values['anthropic_model'] ?? '') === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?><?php if (!isset($anthropicModels[$values['anthropic_model'] ?? ''])): ?><option value="<?= $v('anthropic_model') ?>" selected><?= $v('anthropic_model') ?></option><?php endif; ?></select></div>
      <div class="form-group"><label class="form-label">Refusal fallbacks</label><label class="switch" style="margin-top:8px"><input type="checkbox" name="anthropic_fallbacks" value="1" <?= ($values['anthropic_fallbacks'] ?? '1') === '1' ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Server-side fallback on safety refusals</span></label></div>
    </div>
    <?= $testBtn('anthropic', 'Test Anthropic connection') ?>
  </div></div>
  <div class="card" style="box-shadow:none;margin-bottom:16px"><div class="card-body">
    <h4 class="mb-3">OpenAI</h4>
    <?= $secret('openai_api_key', 'OpenAI API key', 'Used for embeddings (semantic search), speech-to-text, premium voices and optionally chat.') ?>
    <div class="form-group"><label class="form-label">Chat model (when OpenAI is the chat provider)</label><input class="form-control" name="openai_model" value="<?= $v('openai_model') ?>"></div>
    <?= $testBtn('openai', 'Test OpenAI chat') ?>
  </div></div>
  <div class="card" style="box-shadow:none;margin-bottom:16px"><div class="card-body">
    <h4 class="mb-3">OpenAI-compatible endpoint</h4>
    <div class="form-row"><div class="form-group"><label class="form-label">Base URL</label><input class="form-control" name="compatible_base_url" value="<?= $v('compatible_base_url') ?>" placeholder="https://api.groq.com/openai/v1"></div><div class="form-group"><label class="form-label">Model</label><input class="form-control" name="compatible_model" value="<?= $v('compatible_model') ?>"></div></div>
    <?= $secret('compatible_api_key', 'API key') ?>
    <?= $testBtn('compatible', 'Test endpoint') ?>
  </div></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Default reasoning effort</label><select class="form-select" name="llm_effort"><?php foreach (['low' => 'Low (fastest, recommended)', 'medium' => 'Medium', 'high' => 'High'] as $k => $l): ?><option value="<?= $k ?>" <?= ($values['llm_effort'] ?? 'low') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Max reply tokens</label><input class="form-control" type="number" name="llm_max_tokens" value="<?= $v('llm_max_tokens', '1024') ?>"></div>
  </div>
  <hr>
  <h4 class="mb-3">Embeddings &amp; retrieval</h4>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Embedding provider</label><select class="form-select" name="embeddings_provider"><?php foreach (['auto' => 'Automatic (OpenAI, then Voyage)', 'openai' => 'OpenAI', 'voyage' => 'Voyage AI', 'none' => 'None (keyword search only)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($values['embeddings_provider'] ?? 'auto') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Vector dimensions</label><input class="form-control" type="number" name="embedding_dimensions" value="<?= $v('embedding_dimensions', '768') ?>"><div class="form-hint">Changing provider/model/dimensions requires re-training agents.</div></div>
    <div class="form-group"><label class="form-label">OpenAI embedding model</label><input class="form-control" name="openai_embedding_model" value="<?= $v('openai_embedding_model') ?>"></div>
    <div class="form-group"><label class="form-label">Voyage embedding model</label><input class="form-control" name="voyage_embedding_model" value="<?= $v('voyage_embedding_model') ?>"></div>
  </div>
  <?= $secret('voyage_api_key', 'Voyage AI API key', 'Alternative embedding provider (voyageai.com).') ?>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Chunks per answer (top K)</label><input class="form-control" type="number" name="retrieval_top_k" value="<?= $v('retrieval_top_k', '6') ?>"></div>
    <div class="form-group"><label class="form-label">Minimum relevance (0-1)</label><input class="form-control" type="number" step="0.05" name="retrieval_min_score" value="<?= $v('retrieval_min_score', '0.30') ?>"></div>
    <div class="form-group"><label class="form-label">Chunk size (characters)</label><input class="form-control" type="number" name="chunk_size" value="<?= $v('chunk_size', '1600') ?>"></div>
    <div class="form-group"><label class="form-label">Chunk overlap</label><input class="form-control" type="number" name="chunk_overlap" value="<?= $v('chunk_overlap', '200') ?>"></div>
  </div>
  <?= $testBtn('embeddings', 'Test embeddings') ?>

<?php elseif ($tab === 'voice'): ?>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Speech-to-text</label><select class="form-select" name="stt_provider"><option value="auto" <?= ($values['stt_provider'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Automatic (OpenAI when available)</option><option value="browser" <?= ($values['stt_provider'] ?? '') === 'browser' ? 'selected' : '' ?>>Browser only</option></select></div>
    <div class="form-group"><label class="form-label">OpenAI transcription model</label><input class="form-control" name="openai_stt_model" value="<?= $v('openai_stt_model') ?>"><div class="form-hint">gpt-4o-mini-transcribe, gpt-4o-transcribe or whisper-1</div></div>
    <div class="form-group"><label class="form-label">Text-to-speech default</label><select class="form-select" name="tts_provider"><?php foreach (['auto' => 'Automatic (OpenAI, then ElevenLabs)', 'openai' => 'OpenAI', 'elevenlabs' => 'ElevenLabs', 'browser' => 'Browser only'] as $k => $l): ?><option value="<?= $k ?>" <?= ($values['tts_provider'] ?? 'auto') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">OpenAI speech model</label><input class="form-control" name="openai_tts_model" value="<?= $v('openai_tts_model') ?>"><div class="form-hint">gpt-4o-mini-tts, tts-1 or tts-1-hd</div></div>
  </div>
  <?= $testBtn('openai_tts', 'Test OpenAI voice') ?>
  <hr>
  <?= $secret('elevenlabs_api_key', 'ElevenLabs API key', 'Premium voices from elevenlabs.io.') ?>
  <div class="form-group"><label class="form-label">ElevenLabs model</label><input class="form-control" name="elevenlabs_model" value="<?= $v('elevenlabs_model') ?>"><div class="form-hint">eleven_flash_v2_5 (fastest), eleven_turbo_v2_5 or eleven_multilingual_v2</div></div>
  <?= $testBtn('elevenlabs', 'Test ElevenLabs') ?>

<?php elseif ($tab === 'documents'): ?>
  <div class="form-group"><label class="form-label">PDF text extraction</label><select class="form-select" name="pdf_extraction"><?php foreach (['auto' => 'Built-in extraction, AI reading as fallback for scanned PDFs', 'native' => 'Built-in extraction only (no AI cost)', 'ai' => 'Always use Claude to read PDFs (best quality, uses tokens)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($values['pdf_extraction'] ?? 'auto') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Default max pages per scan</label><input class="form-control" type="number" name="crawler_max_pages_default" value="<?= $v('crawler_max_pages_default', '100') ?>"></div>
    <div class="form-group"><label class="form-label">Page fetch timeout (seconds)</label><input class="form-control" type="number" name="crawler_timeout" value="<?= $v('crawler_timeout', '15') ?>"></div>
  </div>
  <div class="form-group"><label class="form-label">Crawler user agent</label><input class="form-control" name="crawler_user_agent" value="<?= $v('crawler_user_agent') ?>"></div>

<?php elseif ($tab === 'billing'): ?>
  <div class="form-group"><label class="switch"><input type="checkbox" name="stripe_enabled" value="1" <?= ($values['stripe_enabled'] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span><span class="switch-label">Enable Stripe subscriptions</span></label></div>
  <div class="form-group"><label class="form-label">Publishable key</label><input class="form-control" name="stripe_publishable_key" value="<?= $v('stripe_publishable_key') ?>" placeholder="pk_..."></div>
  <?= $secret('stripe_secret_key', 'Secret key', 'sk_live_... or sk_test_...') ?>
  <?= $secret('stripe_webhook_secret', 'Webhook signing secret', 'whsec_... from the webhook endpoint in your Stripe dashboard.') ?>
  <div class="alert alert-info"><span>Create a webhook in Stripe pointing to <code><?= e($webhookUrl) ?></code> with the events <em>checkout.session.completed</em>, <em>customer.subscription.created</em>, <em>customer.subscription.updated</em>, <em>customer.subscription.deleted</em> and <em>invoice.payment_failed</em>. Then add each plan's Stripe price IDs under <a href="<?= e(url('/admin/plans')) ?>">Plans</a>.</span></div>

<?php elseif ($tab === 'mail'): ?>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Delivery method</label><select class="form-select" name="mail_driver"><option value="" <?= ($values['mail_driver'] ?? '') === '' ? 'selected' : '' ?>>Use config file default</option><option value="mail" <?= ($values['mail_driver'] ?? '') === 'mail' ? 'selected' : '' ?>>PHP mail() (Hostinger default)</option><option value="smtp" <?= ($values['mail_driver'] ?? '') === 'smtp' ? 'selected' : '' ?>>SMTP</option><option value="log" <?= ($values['mail_driver'] ?? '') === 'log' ? 'selected' : '' ?>>Log only (testing)</option></select></div>
    <div class="form-group"><label class="form-label">From email</label><input class="form-control" name="mail_from_email" value="<?= $v('mail_from_email') ?>"></div>
    <div class="form-group"><label class="form-label">From name</label><input class="form-control" name="mail_from_name" value="<?= $v('mail_from_name') ?>"></div>
  </div>
  <h4 class="mb-3">SMTP</h4>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Host</label><input class="form-control" name="smtp_host" value="<?= $v('smtp_host') ?>" placeholder="smtp.hostinger.com"></div>
    <div class="form-group"><label class="form-label">Port</label><input class="form-control" name="smtp_port" value="<?= $v('smtp_port', '587') ?>"></div>
    <div class="form-group"><label class="form-label">Encryption</label><select class="form-select" name="smtp_encryption"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($values['smtp_encryption'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label class="form-label">Username</label><input class="form-control" name="smtp_username" value="<?= $v('smtp_username') ?>"></div>
  </div>
  <?= $secret('smtp_password', 'SMTP password') ?>
  <?= $testBtn('mail', 'Send test email to me') ?>
<?php endif; ?>
</div>
<div class="card-footer flex justify-between items-center"><span class="text-sm text-muted">Secrets are encrypted at rest.</span><button class="btn btn-primary">Save settings</button></div>
</form>
<?php \App\Core\View::start('scripts'); ?>
<script>
async function testProvider(btn, provider) {
  var out = document.querySelector('[data-result="' + provider + '"]');
  btn.disabled = true; out.textContent = 'Testing...'; out.className = 'text-sm text-muted';
  try { var r = await VA.api('/admin/settings/test', { body: { provider: provider } }); out.textContent = r.message; out.className = 'text-sm ' + (r.ok ? 'text-success' : 'text-danger'); }
  catch (e) { out.textContent = e.message; out.className = 'text-sm text-danger'; }
  btn.disabled = false;
}
</script>
<?php \App\Core\View::stop(); ?>
