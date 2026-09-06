<section class="section">
  <div class="container">
    <h2>Pricing</h2>
    <p class="sub">Every plan includes website training, text and voice conversations, lead capture and analytics.</p>
    <div class="pricing-grid">
      <?php foreach ($plans as $p): ?>
        <div class="price-card <?= (int) $p['is_featured'] ? 'featured' : '' ?>">
          <h3><?= e($p['name']) ?></h3>
          <div class="amount">$<?= number_format((float) $p['price_monthly'], 0) ?><small>/month</small></div>
          <div class="text-sm text-muted"><?= (float) $p['price_yearly'] > 0 ? 'or $' . number_format((float) $p['price_yearly'], 0) . '/year' : 'Free forever' ?></div>
          <ul><?php foreach ($p['features'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
          <a class="btn <?= (int) $p['is_featured'] ? 'btn-primary' : 'btn-secondary' ?> btn-block" href="<?= e(url('/register')) ?>"><?= (float) $p['price_monthly'] > 0 ? 'Start free trial' : 'Get started' ?></a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="faq mt-8" style="margin-top:60px">
      <h2 style="font-size:26px">Questions</h2>
      <details><summary>What counts as a message?</summary><p>Every answer the assistant gives, by text or by voice, counts as one message. The greeting and suggested questions are free.</p></details>
      <details><summary>Which languages are supported?</summary><p>The assistant understands and speaks 30+ languages. Set a fixed language or let it match the visitor automatically.</p></details>
      <details><summary>Does it work on WordPress, Shopify, Wix?</summary><p>Yes. The widget is a single script tag that works on any website builder or custom site, including WordPress, Shopify, Wix, Webflow and Squarespace.</p></details>
      <details><summary>Will it make things up?</summary><p>No. Answers are grounded in the content you approve. If the information is missing, the assistant says so, records the question for you and can offer to collect the visitor's contact details.</p></details>
      <details><summary>Can I cancel any time?</summary><p>Yes. Subscriptions are monthly or yearly and can be cancelled from the billing page at any time.</p></details>
    </div>
  </div>
</section>
