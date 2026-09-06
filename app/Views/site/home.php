<section class="hero">
  <div class="container">
    <span class="eyebrow">&#10024; Voice + text AI assistant for any website</span>
    <h1>Let visitors <span>talk</span> to your website</h1>
    <p class="lead">Train an AI agent on your website, PDFs and FAQs in minutes. Visitors ask by voice or text and get accurate answers from your own content. Leads are captured automatically.</p>
    <div class="hero-actions"><a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>">Start free</a><a class="btn btn-secondary btn-lg" href="<?= e(url('/#how')) ?>">See how it works</a></div>
    <div class="hero-note">Free plan included &middot; No credit card &middot; One line of code to install</div>
    <div class="mock">
      <div class="lines"><div class="bar" style="width:40%;height:18px;background:#cbd5e1"></div><div class="bar" style="width:90%"></div><div class="bar" style="width:75%"></div><div class="bar" style="width:60%"></div><div class="bar" style="width:80%;margin-top:30px"></div><div class="bar" style="width:55%"></div></div>
      <div class="mock-widget">
        <div class="mh"><span>AI</span>Assistant <small style="font-weight:500;opacity:.85;margin-left:auto">&#9679; Online</small></div>
        <div class="mb"><div class="bub">Hi! I'm your virtual assistant. Ask me anything about our services, or tap the mic to talk.</div><div class="bub u">Do you offer weekend appointments?</div><div class="bub">Yes! We are open Saturdays from 9am to 2pm. Would you like me to arrange a call back to book one?</div></div>
        <div class="mf">Type or talk... <div class="orb"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></div></div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="features">
  <div class="container">
    <h2>Everything a website assistant should do</h2>
    <p class="sub">Built for businesses that want accurate answers, natural voice conversations and more leads, without a developer.</p>
    <div class="features">
      <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></div><h3>Learns your website</h3><p>Enter your URL and the assistant reads your pages, sitemaps and PDFs. Re-scan any time your content changes.</p></div>
      <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></div><h3>Real voice conversations</h3><p>Visitors tap the microphone and speak naturally. The assistant listens, thinks and answers out loud with premium neural voices.</p></div>
      <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg></div><h3>No made-up answers</h3><p>Responses come from your approved knowledge only. When something is unknown, the assistant says so and offers to take contact details.</p></div>
      <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></div><h3>Captures leads</h3><p>"I'd like someone to call me" turns into a lead with name, email, phone and message in your dashboard, plus an email alert.</p></div>
      <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a9 9 0 1 0 0 18c1.2 0 2-.8 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.2 0-1 .8-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-4-4-7-9-7z"/></svg></div><h3>Fully on-brand</h3><p>Colours, icon, avatar, fonts, texts, size and position. Live preview while you design, then paste one line of code.</p></div>
      <div class="feature"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/></svg></div><h3>Insights that teach</h3><p>See conversations and transcripts, unanswered questions, top topics and analytics. Teach missing answers in one click.</p></div>
    </div>
  </div>
</section>

<section class="section" id="how" style="background:var(--bg)">
  <div class="container">
    <h2>Live in four steps</h2>
    <p class="sub">The whole setup takes about ten minutes.</p>
    <div class="how">
      <div><h3>Enter your website</h3><p>We scan your pages and documents and build a private knowledge base.</p></div>
      <div><h3>Create the agent</h3><p>Pick a name, personality, language and a voice.</p></div>
      <div><h3>Design &amp; test</h3><p>Match your brand with the live customiser and try a conversation.</p></div>
      <div><h3>Paste one line</h3><p>Add the embed code to HTML, WordPress, Shopify, Wix or any site.</p></div>
    </div>
  </div>
</section>

<section class="section" id="pricing">
  <div class="container">
    <h2>Simple pricing</h2>
    <p class="sub">Start free, upgrade when your traffic grows.</p>
    <div class="pricing-grid">
      <?php foreach ($plans as $p): ?>
        <div class="price-card <?= (int) $p['is_featured'] ? 'featured' : '' ?>">
          <h3><?= e($p['name']) ?></h3>
          <div class="amount">$<?= number_format((float) $p['price_monthly'], 0) ?><small>/month</small></div>
          <div class="text-sm text-muted"><?= e($p['description']) ?></div>
          <ul><?php foreach ($p['features'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
          <a class="btn <?= (int) $p['is_featured'] ? 'btn-primary' : 'btn-secondary' ?> btn-block" href="<?= e(url('/register')) ?>"><?= (float) $p['price_monthly'] > 0 ? 'Start free trial' : 'Get started' ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="container"><div class="cta"><h2>Give your website a voice today</h2><p>Set up your first AI agent in minutes. No developer needed.</p><a class="btn btn-lg" style="background:#fff;color:#312e81" href="<?= e(url('/register')) ?>">Create your free account</a></div></div>
</section>
