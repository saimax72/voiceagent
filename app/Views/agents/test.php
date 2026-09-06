<?php if ($onboarding): ?><?= \App\Core\View::partial('partials/onboarding_steps', ['current' => 4, 'nextUrl' => url('/agents/' . $agent['id'] . '/install?onboarding=1'), 'nextLabel' => 'Continue to install']) ?><?php endif; ?>
<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'test']) ?>
<?php if (!$aiReady): ?><div class="alert alert-warning"><span>The AI provider is not configured yet, so the assistant cannot reply. <?= auth()->isSuperAdmin() ? 'Add an API key under <a href="' . e(url('/admin/settings')) . '">Admin &rarr; Settings</a>.' : 'Please contact the platform administrator.' ?></span></div><?php endif; ?>
<?php if ($knowledge['documents'] === 0): ?><div class="alert alert-info"><span>This agent has no knowledge yet, so it can only make small talk. <a href="<?= e(url('/agents/' . $agent['id'] . '/knowledge')) ?>">Add your website or documents</a> first.</span></div><?php endif; ?>
<div class="grid grid-sidebar">
  <div class="card" style="overflow:hidden">
    <iframe src="<?= e(url('/widget/preview/' . $agent['public_id'] . '?mode=test')) ?>" title="Assistant test" style="width:100%;height:720px;border:0;display:block" allow="microphone; autoplay"></iframe>
  </div>
  <div class="stack">
    <div class="card"><div class="card-header"><h3>Try asking</h3></div><div class="card-body">
      <ul style="margin:0;padding-left:18px;line-height:1.9;color:var(--text-2)">
        <li>What services do you offer?</li>
        <li>How can I get in touch with you?</li>
        <li>A question that is <em>not</em> on your website, to see how it handles unknowns</li>
        <li>"I'd like someone to contact me" to test lead capture</li>
        <li>Tap the headset icon for a hands-free voice conversation</li>
      </ul>
    </div></div>
    <div class="card"><div class="card-header"><h3>Good to know</h3></div><div class="card-body text-sm text-muted">
      <p>Test conversations are marked as tests and do not count towards your monthly usage. They appear in <a href="<?= e(url('/conversations?agent=' . $agent['id'] . '&test=1')) ?>">Conversations</a> with a "Test" badge.</p>
      <p class="mb-0">Questions the assistant could not answer are collected under <a href="<?= e(url('/unanswered?agent=' . $agent['id'])) ?>">Unanswered</a>, where you can teach it the right answer in one click.</p>
    </div></div>
  </div>
</div>
