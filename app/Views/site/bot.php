<section class="section"><div class="container" style="max-width:760px">
  <h2 style="text-align:left">About our website crawler</h2>
  <p style="color:var(--text-2);line-height:1.7"><?= e($app_name) ?> reads public web pages only when a customer explicitly asks us to train an assistant on their own website. The crawler identifies itself with the user agent <code>VoiceAgentBot</code>, respects <code>robots.txt</code> and <code>noindex</code> directives, fetches at a gentle pace and never submits forms or accesses private areas.</p>
  <p style="color:var(--text-2);line-height:1.7">If you believe your site is being scanned without permission, or you want to block the crawler, add the following to your robots.txt:</p>
  <pre>User-agent: VoiceAgentBot
Disallow: /</pre>
  <p style="color:var(--text-2);line-height:1.7;margin-top:16px">Questions? Contact <?= e(setting('support_email', 'the site administrator')) ?>.</p>
</div></section>
