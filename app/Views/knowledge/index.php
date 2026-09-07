<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'knowledge']) ?>
<?php
$icons = [
    'website' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'url' => '<path d="M10 14a4 4 0 0 0 5.6 0l3-3a4 4 0 0 0-5.6-5.6L11.5 7"/><path d="M14 10a4 4 0 0 0-5.6 0l-3 3a4 4 0 0 0 5.6 5.6l1.5-1.5"/>',
    'file' => '<path d="M4 5a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M14 3v5h5"/>',
    'faq' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>',
    'text' => '<path d="M4 6h16M4 12h16M4 18h10"/>',
];
$badge = static fn(string $s) => match ($s) { 'ready' => '<span class="badge badge-success">Ready</span>', 'error' => '<span class="badge badge-danger">Error</span>', 'processing' => '<span class="badge badge-info"><span class="spinner" style="width:11px;height:11px;border-width:2px"></span>Processing</span>', default => '<span class="badge badge-warning">Queued</span>' };
?>
<?php if (!$embeddings): ?><div class="alert alert-info"><span>No embedding provider is configured, so retrieval uses keyword search only. <?= auth()->isSuperAdmin() ? 'Add an OpenAI or Voyage key in <a href="' . e(url('/admin/settings')) . '">Admin &rarr; Settings</a> for semantic search.' : 'Ask the platform administrator to enable semantic search.' ?></span></div><?php endif; ?>

<div class="grid grid-sidebar">
  <div class="stack">
    <div class="card">
      <div class="card-header"><div><h3>Knowledge sources</h3><div class="sub"><?= format_number($stats['documents']) ?> pages &middot; <?= format_number($stats['chunks']) ?> chunks &middot; <?= $agent['last_trained_at'] ? 'trained ' . e(time_ago($agent['last_trained_at'])) : 'not trained yet' ?></div></div>
        <div class="actions"><?php if ($stats['documents'] > 0): ?><form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/reindex')) ?>" data-confirm="Re-train the assistant on all sources? This re-processes every page."><?= csrf_field() ?><button class="btn btn-secondary btn-sm">Re-train all</button></form><?php endif; ?></div></div>
      <div class="card-body">
        <?php if (isset($jobs['agent'])): ?><div class="alert alert-info" data-job="<?= (int) $jobs['agent']['id'] ?>"><span class="spinner"></span><span class="job-text"><?= e($jobs['agent']['progress_text']) ?></span></div><?php endif; ?>
        <?php if (!$sources): ?>
          <div class="empty" style="padding:24px"><div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 5a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M14 3v5h5"/></svg></div><h3>Nothing learned yet</h3><p>Scan your website, upload documents or add FAQs using the panel on the right.</p></div>
        <?php else: ?>
        <div class="source-list">
          <?php foreach ($sources as $s): $st = json_field($s['stats']); $job = $jobs[(int) $s['id']] ?? null; ?>
            <div class="source-item" <?= $job ? 'data-job="' . (int) $job['id'] . '"' : '' ?>>
              <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$s['type']] ?? $icons['file'] ?></svg></div>
              <div class="info">
                <div class="title"><a href="<?= e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $s['id'])) ?>" style="color:inherit"><?= e($s['title']) ?></a></div>
                <div class="meta">
                  <?php if ($job): ?>
                    <span class="job-text"><?= e($job['progress_text']) ?></span>
                    <div class="progress striped mt-1" style="max-width:280px"><span class="job-bar" style="width:<?= (int) $job['progress'] ?>%"></span></div>
                  <?php elseif ($s['status'] === 'error'): ?>
                    <span class="text-danger"><?= e($s['error_message'] ?: 'Failed') ?></span>
                  <?php else: ?>
                    <?= e(ucfirst($s['type'])) ?><?= $s['url'] ? ' &middot; ' . e(str_limit($s['url'], 50)) : '' ?><?= $s['file_size'] ? ' &middot; ' . e(human_filesize((int) $s['file_size'])) : '' ?>
                    <?= isset($st['documents']) ? ' &middot; ' . (int) $st['documents'] . ' pages, ' . (int) $st['chunks'] . ' chunks' : '' ?><?= $s['last_synced_at'] ? ' &middot; synced ' . e(time_ago($s['last_synced_at'])) : '' ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <span class="job-badge"><?= $job ? $badge('processing') : $badge($s['status']) ?></span>
                <div class="dropdown" x-data="{o:false}" @click.outside="o=false">
                  <button class="btn btn-icon btn-ghost" @click="o=!o" aria-label="Actions"><svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></button>
                  <div class="dropdown-menu" x-show="o" x-cloak>
                    <a href="<?= e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $s['id'])) ?>">View pages</a>
                    <?php if (!$job): ?><form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $s['id'] . '/rescan')) ?>"><?= csrf_field() ?><button type="submit"><?= $s['type'] === 'website' ? 'Re-scan website' : 'Re-process' ?></button></form><?php endif; ?>
                    <?php if ($s['type'] === 'file'): ?><a href="<?= e(url('/files/documents/' . $s['id'])) ?>">Download file</a><?php endif; ?>
                    <hr>
                    <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $s['id'] . '/delete')) ?>" data-confirm="Remove this source and everything learned from it?"><?= csrf_field() ?><button type="submit" class="danger">Remove</button></form>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card" x-data="{ tab: 'website', faqs: [{q:'',a:''}] }">
    <div class="card-header"><h3>Add knowledge</h3></div>
    <div class="card-body">
      <div class="segmented mb-4" style="display:flex;flex-wrap:wrap">
        <button type="button" :class="{active: tab==='website'}" @click="tab='website'">Website</button>
        <button type="button" :class="{active: tab==='file'}" @click="tab='file'">File</button>
        <button type="button" :class="{active: tab==='faq'}" @click="tab='faq'">FAQ</button>
        <button type="button" :class="{active: tab==='text'}" @click="tab='text'">Text</button>
        <button type="button" :class="{active: tab==='url'}" @click="tab='url'">Page</button>
      </div>

      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/website')) ?>" x-show="tab==='website'">
        <?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Website address</label><input class="form-control" name="url" value="<?= e($agent['website_url'] ?? '') ?>" placeholder="https://www.example.com" required></div>
        <div class="form-group"><label class="form-label">Maximum pages</label><input class="form-control" type="number" name="max_pages" min="1" max="<?= (int) $limits['pages'] ?>" value="<?= (int) $defaultPages ?>"><div class="form-hint">Your plan includes up to <?= format_number($limits['pages']) ?> pages per agent. Sitemaps are used when available.</div></div>
        <div class="form-group"><label class="checkbox"><input type="checkbox" name="restrict_to_path" value="1"> <span>Only scan pages under the given path (e.g. /help/)</span></label></div>
        <div class="form-group"><input type="hidden" name="ignore_robots" value="0"><label class="checkbox"><input type="checkbox" name="ignore_robots" value="1" checked> <span>Include pages hidden from search engines (noindex / robots.txt)</span></label><div class="form-hint">Keep this on for your own website. Many sites under construction hide themselves from Google, which would otherwise leave the assistant with nothing to learn.</div></div>
        <button class="btn btn-primary btn-block" type="submit">Scan website</button>
      </form>

      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/upload')) ?>" enctype="multipart/form-data" x-show="tab==='file'" x-cloak>
        <?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Documents</label><input class="form-control" type="file" name="files[]" multiple accept=".<?= implode(',.', $extensions) ?>" required><div class="form-hint">PDF, Word, PowerPoint, text, Markdown, CSV or HTML. Max <?= e(human_filesize($maxUpload)) ?> each; select several files at once. <?= (int) $limits['documents_used'] ?>/<?= (int) $limits['documents'] ?> documents used.</div></div>
        <button class="btn btn-primary btn-block" type="submit">Upload &amp; train</button>
      </form>

      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/faq')) ?>" x-show="tab==='faq'" x-cloak>
        <?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Group title</label><input class="form-control" name="title" value="FAQ" required></div>
        <template x-for="(f, i) in faqs" :key="i">
          <div class="form-group" style="padding:12px;border:1px solid var(--border);border-radius:8px">
            <input class="form-control mb-2" name="question[]" placeholder="Question" x-model="f.q">
            <textarea class="form-control" name="answer[]" rows="3" placeholder="Answer" x-model="f.a"></textarea>
            <button type="button" class="btn btn-ghost btn-sm mt-2" x-show="faqs.length > 1" @click="faqs.splice(i,1)">Remove</button>
          </div>
        </template>
        <button type="button" class="btn btn-secondary btn-sm mb-3" @click="faqs.push({q:'',a:''})">+ Add another question</button>
        <button class="btn btn-primary btn-block" type="submit">Save FAQs</button>
      </form>

      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/text')) ?>" x-show="tab==='text'" x-cloak>
        <?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Title</label><input class="form-control" name="title" placeholder="Opening hours, Return policy, ..." required></div>
        <div class="form-group"><label class="form-label">Content</label><textarea class="form-control" name="content" rows="8" placeholder="Write or paste any information the assistant should know." required></textarea></div>
        <button class="btn btn-primary btn-block" type="submit">Save text</button>
      </form>

      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/url')) ?>" x-show="tab==='url'" x-cloak>
        <?= csrf_field() ?>
        <div class="form-group"><label class="form-label">Page address</label><input class="form-control" name="url" placeholder="https://www.example.com/pricing" required><div class="form-hint">Adds a single page (or an online PDF) without crawling the whole site.</div></div>
        <button class="btn btn-primary btn-block" type="submit">Add page</button>
      </form>
    </div>
  </div>
</div>

<?php \App\Core\View::start('scripts'); ?>
<script>
document.querySelectorAll('[data-job]').forEach(function (el) {
  var id = el.getAttribute('data-job');
  VA.pollJob(id, {
    onProgress: function (job) {
      var t = el.querySelector('.job-text'); if (t) t.textContent = job.progress_text || '';
      var b = el.querySelector('.job-bar'); if (b) b.style.width = job.progress + '%';
    },
    onDone: function () { location.reload(); },
    onError: function (job) { var t = el.querySelector('.job-text'); if (t) { t.textContent = job.last_error || 'Failed'; t.classList.add('text-danger'); } var bd = el.querySelector('.job-badge'); if (bd) bd.innerHTML = '<span class="badge badge-danger">Error</span>'; }
  });
});
</script>
<?php \App\Core\View::stop(); ?>
