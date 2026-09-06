<?= \App\Core\View::partial('partials/agent_nav', ['agent' => $agent, 'active' => 'knowledge']) ?>
<div class="breadcrumb"><a href="<?= e(url('/agents/' . $agent['id'] . '/knowledge')) ?>">Knowledge</a> <span>/</span> <span><?= e($source['title']) ?></span></div>
<div class="page-header" style="margin-top:0">
  <div><h1 style="font-size:20px"><?= e($source['title']) ?></h1><p><?= e(ucfirst($source['type'])) ?><?= $source['url'] ? ' &middot; <a href="' . e($source['url']) . '" target="_blank" rel="noopener">' . e(str_limit($source['url'], 70)) . '</a>' : '' ?> &middot; <?= format_number($total) ?> pages<?= isset($sourceStats['pages_failed']) && $sourceStats['pages_failed'] ? ' &middot; ' . (int) $sourceStats['pages_failed'] . ' failed to load' : '' ?></p></div>
  <div class="actions">
    <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $source['id'] . '/rescan')) ?>"><?= csrf_field() ?><button class="btn btn-secondary btn-sm"><?= $source['type'] === 'website' ? 'Re-scan website' : 'Re-process' ?></button></form>
    <?php if ($source['type'] === 'faq'): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/agents/' . $agent['id'] . '/knowledge')) ?>#faq">Add questions</a><?php endif; ?>
  </div>
</div>
<?php if ($source['status'] === 'error'): ?><div class="alert alert-error"><span><?= e($source['error_message']) ?></span></div><?php endif; ?>
<div class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Page</th><th>Status</th><th>Size</th><th></th></tr></thead>
    <tbody>
    <?php if (!$documents): ?><tr><td colspan="4" class="text-center text-muted" style="padding:32px">No pages yet<?= $source['status'] === 'processing' || $source['status'] === 'pending' ? ' - processing is in progress' : '' ?>.</td></tr><?php endif; ?>
    <?php foreach ($documents as $d): ?>
      <tr style="<?= (int) $d['is_enabled'] ? '' : 'opacity:.55' ?>">
        <td><a class="cell-primary" href="<?= e(url('/agents/' . $agent['id'] . '/knowledge/documents/' . $d['id'])) ?>"><?= e(str_limit($d['title'], 80)) ?></a><?php if ($d['url']): ?><div class="muted truncate" style="max-width:420px"><?= e($d['url']) ?></div><?php endif; ?></td>
        <td><?= match ($d['status']) { 'indexed' => (int) $d['is_enabled'] ? '<span class="badge badge-success">Indexed</span>' : '<span class="badge badge-neutral">Disabled</span>', 'error' => '<span class="badge badge-danger" title="' . e($d['error_message']) . '">Error</span>', 'skipped' => '<span class="badge badge-neutral">Skipped</span>', default => '<span class="badge badge-warning">Pending</span>' } ?></td>
        <td class="muted"><?= format_number((int) $d['char_count']) ?> chars &middot; <?= (int) $d['chunk_count'] ?> chunks</td>
        <td class="actions">
          <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/documents/' . $d['id'] . '/toggle')) ?>" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= (int) $d['is_enabled'] ? 'Disable' : 'Enable' ?></button></form>
          <form method="post" action="<?= e(url('/agents/' . $agent['id'] . '/knowledge/documents/' . $d['id'] . '/delete')) ?>" style="display:inline" data-confirm="Remove this page from the knowledge base?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm text-danger">Remove</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if ($pages > 1): ?><div class="pagination"><?php for ($p = 1; $p <= $pages; $p++): ?><?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="' . e(url('/agents/' . $agent['id'] . '/knowledge/sources/' . $source['id'], ['page' => $p])) . '">' . $p . '</a>' ?><?php endfor; ?></div><?php endif; ?>
