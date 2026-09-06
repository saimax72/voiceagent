<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'knowledge']) ?>
<div class="breadcrumb"><a href="<?= e(url('/agents/' . $agent['id'] . '/knowledge')) ?>">Knowledge</a> <span>/</span> <a href="<?= e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $source['id'])) ?>"><?= e($source['title']) ?></a> <span>/</span> <span><?= e(str_limit($doc['title'], 60)) ?></span></div>
<div class="grid grid-sidebar">
  <div class="card">
    <?php if ($editable): ?>
      <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/documents/' . $doc['id'])) ?>">
        <?= csrf_field() ?>
        <div class="card-header"><h3>Edit content</h3><div class="actions"><button class="btn btn-primary btn-sm" type="submit">Save &amp; re-train</button></div></div>
        <div class="card-body">
          <div class="form-group"><label class="form-label">Title</label><input class="form-control" name="title" value="<?= e($doc['title']) ?>"></div>
          <div class="form-group"><label class="form-label">Content</label><textarea class="form-control" name="content" rows="22" style="font-size:13.5px"><?= e($doc['content']) ?></textarea></div>
        </div>
      </form>
    <?php else: ?>
      <div class="card-header"><h3><?= e(str_limit($doc['title'], 90)) ?></h3></div>
      <div class="card-body"><div style="white-space:pre-wrap;font-size:13.5px;line-height:1.65;max-height:70vh;overflow:auto;color:var(--text-2)"><?= e($doc['content']) ?></div></div>
    <?php endif; ?>
  </div>
  <div class="card"><div class="card-header"><h3>Details</h3></div><div class="card-body">
    <dl class="kv">
      <dt>Status</dt><dd><?= e(ucfirst($doc['status'])) ?><?= (int) $doc['is_enabled'] ? '' : ' (disabled)' ?></dd>
      <?php if ($doc['url']): ?><dt>Source URL</dt><dd><a href="<?= e($doc['url']) ?>" target="_blank" rel="noopener"><?= e(str_limit($doc['url'], 40)) ?></a></dd><?php endif; ?>
      <dt>Length</dt><dd><?= format_number((int) $doc['char_count']) ?> characters</dd>
      <dt>Chunks</dt><dd><?= (int) $doc['chunk_count'] ?></dd>
      <dt>Updated</dt><dd><?= e(format_date($doc['updated_at'], 'M j, Y H:i')) ?></dd>
      <?php if ($doc['error_message']): ?><dt>Error</dt><dd class="text-danger"><?= e($doc['error_message']) ?></dd><?php endif; ?>
    </dl>
    <hr>
    <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/documents/' . $doc['id'] . '/toggle')) ?>" class="mb-2"><?= csrf_field() ?><button class="btn btn-secondary btn-sm btn-block"><?= (int) $doc['is_enabled'] ? 'Exclude from answers' : 'Include in answers' ?></button></form>
    <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/documents/' . $doc['id'] . '/delete')) ?>" data-confirm="Remove this page?"><?= csrf_field() ?><button class="btn btn-danger btn-sm btn-block">Remove page</button></form>
  </div></div>
</div>
