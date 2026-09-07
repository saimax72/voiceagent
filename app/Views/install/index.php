<?php if ($onboarding): ?>
<div class="card mb-6" style="background:linear-gradient(135deg,#eef3ff,#f8f9fc)"><div class="card-body" style="padding:16px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
  <div class="steps"><?php foreach ([1 => 'Website', 2 => 'AI agent', 3 => 'Design', 4 => 'Test', 5 => 'Install'] as $n => $label): ?><div class="step <?= $n < 5 ? 'done' : 'active' ?>"><span class="n"><?= $n < 5 ? '&#10003;' : $n ?></span><?= $label ?></div><?php if ($n < 5): ?><span class="step-sep"></span><?php endif; ?><?php endforeach; ?></div>
  <form method="post" action="<?= e(url('/onboarding/complete')) ?>" class="ml-auto"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Finish setup &rarr;</button></form>
</div></div>
<?php endif; ?>
<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'install']) ?>

<div class="grid grid-sidebar">
  <div class="stack">
    <div class="card">
      <div class="card-header"><div><h3>Your embed code</h3><div class="sub">Paste this once, before the closing &lt;/body&gt; tag, on every page where the assistant should appear.</div></div></div>
      <div class="card-body">
        <div class="code-box"><pre id="embed-code"><?= e($snippet) ?></pre><button class="btn btn-secondary btn-sm copy" data-copy="#embed-code">Copy code</button></div>
        <?php if ($agent['status'] !== 'active'): ?><div class="alert alert-warning mt-4 mb-0"><span>This agent is paused. Enable it from the top of the page so the widget shows up on your website.</span></div><?php endif; ?>
        <p class="text-sm text-muted mt-4 mb-0"><?= !empty($agent['installed_at']) ? '<span class="text-success font-semibold">Detected on ' . e($agent['installed_domain']) . '</span> (' . e(time_ago($agent['installed_at'])) . '). The assistant is live.' : 'We have not seen the widget load from your website yet. Once installed, this page confirms it automatically.' ?></p>
      </div>
    </div>

    <div class="card" x-data="{ tab: 'html' }">
      <div class="card-header"><h3>Platform instructions</h3></div>
      <div class="tabs" style="padding:0 24px;margin-bottom:0">
        <?php foreach (['html' => 'HTML site', 'wordpress' => 'WordPress', 'shopify' => 'Shopify', 'wix' => 'Wix', 'squarespace' => 'Squarespace', 'webflow' => 'Webflow', 'gtm' => 'Tag Manager'] as $k => $l): ?><button type="button" :class="{active: tab==='<?= $k ?>'}" @click="tab='<?= $k ?>'"><?= $l ?></button><?php endforeach; ?>
      </div>
      <div class="card-body" style="line-height:1.7">
        <div x-show="tab==='html'"><ol style="margin:0;padding-left:20px"><li>Open the HTML file of your page (or your site template / footer include).</li><li>Paste the embed code just before the closing <code>&lt;/body&gt;</code> tag.</li><li>Save and upload. The floating assistant appears bottom-<?= e($agent['status'] === 'active' ? 'right' : 'right') ?> of the page.</li></ol></div>
        <div x-show="tab==='wordpress'" x-cloak><ol style="margin:0;padding-left:20px"><li>In WordPress go to <strong>Plugins &rarr; Add New</strong> and install <em>WPCode</em> (or any "Insert Headers and Footers" plugin).</li><li>Open <strong>Code Snippets &rarr; Header &amp; Footer</strong> and paste the embed code into the <strong>Footer</strong> box.</li><li>Save. Alternatively, paste it into <strong>Appearance &rarr; Theme File Editor &rarr; footer.php</strong> before <code>&lt;/body&gt;</code>, or into an HTML block in your Elementor / Divi footer template.</li></ol></div>
        <div x-show="tab==='shopify'" x-cloak><ol style="margin:0;padding-left:20px"><li>Go to <strong>Online Store &rarr; Themes &rarr; Edit code</strong>.</li><li>Open <code>layout/theme.liquid</code>.</li><li>Paste the embed code just before <code>&lt;/body&gt;</code> and save.</li></ol></div>
        <div x-show="tab==='wix'" x-cloak><ol style="margin:0;padding-left:20px"><li>Open your site dashboard and go to <strong>Settings &rarr; Custom Code</strong> (Advanced section).</li><li>Click <strong>Add Custom Code</strong>, paste the embed code, name it "Voice assistant".</li><li>Choose <strong>All pages</strong> and place it in the <strong>Body - end</strong>. Apply.</li></ol></div>
        <div x-show="tab==='squarespace'" x-cloak><ol style="margin:0;padding-left:20px"><li>Go to <strong>Settings &rarr; Advanced &rarr; Code Injection</strong>.</li><li>Paste the embed code into the <strong>Footer</strong> field and save.</li></ol></div>
        <div x-show="tab==='webflow'" x-cloak><ol style="margin:0;padding-left:20px"><li>Open <strong>Project settings &rarr; Custom code</strong>.</li><li>Paste the embed code into <strong>Footer code</strong>, save and publish.</li></ol></div>
        <div x-show="tab==='gtm'" x-cloak><ol style="margin:0;padding-left:20px"><li>In Google Tag Manager create a new <strong>Custom HTML</strong> tag and paste the embed code.</li><li>Set the trigger to <strong>All Pages</strong> and publish the container.</li></ol></div>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card"><div class="card-header"><h3>Optional: your own button</h3></div><div class="card-body text-sm">
      <p>Add <code>data-voiceagent-open</code> to any link or button on your page to open the assistant from it:</p>
      <pre style="font-size:12px;white-space:pre-wrap">&lt;a href="#" data-voiceagent-open&gt;Talk to us&lt;/a&gt;</pre>
      <p class="mt-3">Or control it from JavaScript:</p>
      <pre style="font-size:12px;white-space:pre-wrap">VoiceAgentWidget.open();
VoiceAgentWidget.voice();      // start voice mode
VoiceAgentWidget.send("Hi!");  // send a message</pre>
    </div></div>
    <div class="card"><div class="card-header"><h3>Standalone page</h3></div><div class="card-body text-sm">
      <p>Share a full-page version of the assistant by link or QR code, for example in email signatures or print material:</p>
      <div class="code-box"><pre id="embed-link" style="font-size:12px;white-space:pre-wrap;word-break:break-all"><?= e($embedUrl) ?></pre><button class="btn btn-secondary btn-sm copy" data-copy="#embed-link">Copy</button></div>
    </div></div>
    <div class="card"><div class="card-header"><h3>Security</h3></div><div class="card-body text-sm text-muted">
      <p>Restrict the widget to your own domains under <a href="<?= e(url('/agents/' . $agent['id'] . '/settings')) ?>#security">Personality &amp; voice &rarr; Security</a>. Visitors never see your API keys; every request goes through this platform.</p>
      <p class="mb-0">Works on any HTTPS site: HTML, WordPress, Shopify, Wix, Webflow, React, Next.js and more.</p>
    </div></div>
  </div>
</div>
